;; Adversarial guest: eval() calls host_call with a NEGATIVE name_len (-1).
;;
;; A negative i32 length sign-extends to a ~16 EiB usize on the host; before the
;; range_in_bounds() check (src/lib.rs) the host would `vec![0u8; len]` and abort
;; the whole PHP process on capacity overflow. The host must reject this as a
;; catchable trap instead. Loaded directly as WAT text (Wasmtime parses it).
(module
  (import "host" "host_call" (func $hc (param i32 i32 i32 i32) (result i64)))
  (memory (export "memory") 1)
  (func (export "guest_alloc") (param i32) (result i32)
    i32.const 1024)
  (func (export "eval") (param i32 i32) (result i64)
    (drop (call $hc (i32.const 0) (i32.const -1) (i32.const 0) (i32.const 0)))
    i64.const 0))
