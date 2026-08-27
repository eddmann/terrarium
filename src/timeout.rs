//! Per-operation monotonic deadlines and an owned, promptly cancellable timer.

use ext_php_rs::{convert::FromZval, flags::DataType, types::Zval};
use std::sync::mpsc::{self, RecvTimeoutError, Sender};
use std::thread::{self, JoinHandle};
use std::time::{Duration, Instant};
use wasmtime::Engine;

/// Preserve invalid non-null arguments until method validation. ext-php-rs's
/// Option<i64> conversion otherwise treats a failed conversion as None, silently
/// selecting the (possibly unbounded) constructor default. TYPE keeps PHP's
/// reflected signature ?int; only an actual null or omission means default.
pub struct TimeoutMs(Option<i64>);

impl<'a> FromZval<'a> for TimeoutMs {
    const TYPE: DataType = DataType::Long;

    fn from_zval(value: &'a Zval) -> Option<Self> {
        if value.is_null() {
            None
        } else {
            Some(Self(value.long()))
        }
    }
}

impl TimeoutMs {
    pub(crate) fn value(self) -> Result<i64, String> {
        self.0
            .ok_or_else(|| "timeoutMs must be an integer or null".to_owned())
    }
}

#[derive(Clone, Copy)]
pub(crate) enum CallTimeout {
    /// Constructor defaults retain the historical exemption for guest setup.
    Legacy(u64),
    /// Explicit overrides start at method entry, including guest setup.
    Explicit(Option<Instant>),
}

impl CallTimeout {
    pub(crate) fn new(timeout_ms: Option<i64>, default_ms: u64) -> Result<Self, String> {
        match timeout_ms {
            None => Ok(Self::Legacy(default_ms)),
            Some(ms) if ms < 0 => Err("timeoutMs must be non-negative or null".to_owned()),
            Some(ms) => Ok(Self::Explicit(deadline_after(ms as u64)?)),
        }
    }

    pub(crate) fn setup_deadline(self) -> Option<Instant> {
        match self {
            Self::Legacy(_) => None,
            Self::Explicit(deadline) => deadline,
        }
    }
}

pub(crate) fn deadline_after(ms: u64) -> Result<Option<Instant>, String> {
    if ms == 0 {
        return Ok(None);
    }
    Instant::now()
        .checked_add(Duration::from_millis(ms))
        .map(Some)
        .ok_or_else(|| "timeoutMs exceeds the supported monotonic clock range".to_owned())
}

pub(crate) fn expired(deadline: Option<Instant>) -> bool {
    deadline.is_some_and(|deadline| Instant::now() >= deadline)
}

pub(crate) struct EpochTimer {
    cancel: Sender<()>,
    worker: Option<JoinHandle<()>>,
}

impl EpochTimer {
    pub(crate) fn start(engine: Engine, deadline: Instant) -> std::io::Result<Self> {
        Self::with_tick(deadline, move || engine.increment_epoch())
    }

    fn with_tick(
        deadline: Instant,
        mut tick: impl FnMut() + Send + 'static,
    ) -> std::io::Result<Self> {
        let (cancel, receiver) = mpsc::channel();
        let worker = thread::Builder::new()
            .name("terrarium-timeout".to_owned())
            .spawn(move || {
                if !matches!(
                    receiver.recv_timeout(deadline.saturating_duration_since(Instant::now())),
                    Err(RecvTimeoutError::Timeout)
                ) {
                    return;
                }
                loop {
                    // Keep ticking until cancellation: a store can be rearmed
                    // concurrently with a tick, and nested isolated operations
                    // may acknowledge another store's tick before their own.
                    tick();
                    if !matches!(
                        receiver.recv_timeout(Duration::from_millis(1)),
                        Err(RecvTimeoutError::Timeout)
                    ) {
                        return;
                    }
                }
            })?;
        Ok(Self {
            cancel,
            worker: Some(worker),
        })
    }
}

impl Drop for EpochTimer {
    fn drop(&mut self) {
        let _ = self.cancel.send(());
        if let Some(worker) = self.worker.take() {
            // Also joins during unwinding. No thread may outlive its operation
            // and increment this engine's epoch during a subsequent call.
            let _ = worker.join();
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::atomic::{AtomicUsize, Ordering};
    use std::sync::Arc;

    #[test]
    fn timeout_overrides_do_not_mutate_the_default() {
        assert!(matches!(
            CallTimeout::new(None, 25),
            Ok(CallTimeout::Legacy(25))
        ));
        assert!(matches!(
            CallTimeout::new(Some(0), 25),
            Ok(CallTimeout::Explicit(None))
        ));
        assert!(matches!(
            CallTimeout::new(Some(10), 0),
            Ok(CallTimeout::Explicit(Some(_)))
        ));
        assert!(CallTimeout::new(Some(-1), 25).is_err());
    }

    #[test]
    fn cancellation_wakes_a_timer_without_waiting_for_its_deadline() {
        let (sent, received) = mpsc::channel();
        let timer = EpochTimer::with_tick(Instant::now() + Duration::from_secs(60), move || {
            let _ = sent.send(());
        })
        .unwrap();
        drop(timer);
        // Disconnection proves the worker exited and dropped its closure.
        assert!(matches!(received.recv(), Err(mpsc::RecvError)));
    }

    #[test]
    fn expired_timers_repeat_and_stop_before_drop_returns() {
        let count = Arc::new(AtomicUsize::new(0));
        let ticks = Arc::clone(&count);
        let (sent, received) = mpsc::channel();
        let timer = EpochTimer::with_tick(Instant::now(), move || {
            ticks.fetch_add(1, Ordering::SeqCst);
            let _ = sent.send(());
        })
        .unwrap();
        received.recv_timeout(Duration::from_secs(5)).unwrap();
        received.recv_timeout(Duration::from_secs(5)).unwrap();
        drop(timer);
        let final_count = count.load(Ordering::SeqCst);
        assert!(final_count >= 2);
        // Drain the channel; disconnection proves there can be no late tick.
        for _ in received {}
        assert_eq!(count.load(Ordering::SeqCst), final_count);
    }

    #[test]
    fn unwinding_also_cancels_and_joins() {
        let (sent, received) = mpsc::channel();
        let result = std::panic::catch_unwind(|| {
            let _timer =
                EpochTimer::with_tick(Instant::now() + Duration::from_secs(60), move || {
                    let _ = sent.send(());
                })
                .unwrap();
            panic!("operation failed");
        });
        assert!(result.is_err());
        assert!(received.recv().is_err());
    }
}
