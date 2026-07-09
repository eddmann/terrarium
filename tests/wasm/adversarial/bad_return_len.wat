;; Adversarial guest: eval() returns a packed (ptr=0, len=0xFFFFFFFF).
;;
;; The host unpacks that as an ~4 GiB result length and, before the
;; range_in_bounds() check (src/lib.rs), would `vec![0u8; len]` to read it back.
;; The host must reject an out-of-bounds (ptr, len) as a catchable exception.
(module
  (memory (export "memory") 1)
  (func (export "guest_alloc") (param i32) (result i32)
    i32.const 1024)
  (func (export "eval") (param i32 i32) (result i64)
    i64.const 0xFFFFFFFF))
