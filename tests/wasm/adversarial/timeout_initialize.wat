;; A controllable reactor: each callback returns true to enter a pure Wasm
;; infinite loop, or false to continue. PHP may also delay inside a callback
;; to test elapsed host time without claiming that it can be preempted.
(module
  (import "host" "host_call" (func $host (param i32 i32 i32 i32) (result i64)))
  (memory (export "memory") 1)
  (data (i32.const 0) "initialize")
  (data (i32.const 16) "execute")
  (data (i32.const 32) "\90") ;; msgpack empty argument array
  (data (i32.const 48) "\2a") ;; msgpack result: 42
  (func (export "guest_alloc") (param i32) (result i32) i32.const 1024)
  (func $control (param $name i32) (param $len i32)
    (local $flag i32)
    local.get $name
    local.get $len
    i32.const 32
    i32.const 1
    call $host
    i64.const 32
    i64.shr_u
    i32.wrap_i64
    i32.load8_u
    local.tee $flag
    i32.const 195 ;; msgpack true
    i32.eq
    if
      loop $forever
        br $forever
      end
    end
    local.get $flag
    i32.const 2 ;; msgpack integer 2 requests an ordinary sandbox trap
    i32.eq
    if unreachable end)
  (func (export "_initialize")
    i32.const 0
    i32.const 10
    call $control)
  (func (export "eval") (export "check") (export "analyze")
    (param i32 i32) (result i64)
    i32.const 16
    i32.const 7
    call $control
    i64.const 206158430209)) ;; (48 << 32) | 1
