//! Value marshaling between PHP and the guest via a neutral [`MiddleValue`]:
//!
//! ```text
//!   PHP Zval  <->  MiddleValue  <->  msgpack bytes  (the host_call wire form)
//! ```
//!
//! `MiddleValue` (de)serializes to **native** msgpack types (nil/bool/int/
//! float/str/bin/array/map) — not serde's tagged-enum form — so a guest-side
//! msgpack codec interoperates with it byte-for-byte. The guest's half of the
//! conversion lives inside each guest (the engine's values <-> msgpack), since
//! the guest is opaque WebAssembly to the host. Capabilities exchange data,
//! not functions: closures never cross the boundary.

use ext_php_rs::types::{ArrayKey, ZendHashTable, Zval};
use serde::de::{Deserialize, Deserializer, MapAccess, SeqAccess, Visitor};
use serde::ser::{Serialize, SerializeMap, SerializeSeq, Serializer};
use std::fmt;

/// The neutral, self-describing value that bridges PHP and the wire.
#[derive(Debug, Clone, PartialEq)]
pub enum MiddleValue {
    Null,
    Bool(bool),
    Int(i64),
    Float(f64),
    Str(String),
    Bytes(Vec<u8>),
    Array(Vec<MiddleValue>),
    /// Insertion-ordered string-keyed map (matches a PHP assoc array).
    Map(Vec<(String, MiddleValue)>),
}

impl MiddleValue {
    /// Encode to a msgpack byte payload (the host_call wire form).
    pub fn to_msgpack(&self) -> Result<Vec<u8>, rmp_serde::encode::Error> {
        rmp_serde::to_vec(self)
    }

    /// Decode a msgpack byte payload.
    pub fn from_msgpack(bytes: &[u8]) -> Result<Self, rmp_serde::decode::Error> {
        rmp_serde::from_slice(bytes)
    }
}

// ---------------------------------------------------------------------------
// native-msgpack serde
// ---------------------------------------------------------------------------

impl Serialize for MiddleValue {
    fn serialize<S: Serializer>(&self, s: S) -> Result<S::Ok, S::Error> {
        match self {
            MiddleValue::Null => s.serialize_unit(),
            MiddleValue::Bool(b) => s.serialize_bool(*b),
            MiddleValue::Int(i) => s.serialize_i64(*i),
            MiddleValue::Float(f) => s.serialize_f64(*f),
            MiddleValue::Str(v) => s.serialize_str(v),
            MiddleValue::Bytes(b) => s.serialize_bytes(b),
            MiddleValue::Array(items) => {
                let mut seq = s.serialize_seq(Some(items.len()))?;
                for it in items {
                    seq.serialize_element(it)?;
                }
                seq.end()
            }
            MiddleValue::Map(entries) => {
                let mut map = s.serialize_map(Some(entries.len()))?;
                for (k, v) in entries {
                    map.serialize_entry(k, v)?;
                }
                map.end()
            }
        }
    }
}

impl<'de> Deserialize<'de> for MiddleValue {
    fn deserialize<D: Deserializer<'de>>(d: D) -> Result<Self, D::Error> {
        struct V;
        impl<'de> Visitor<'de> for V {
            type Value = MiddleValue;
            fn expecting(&self, f: &mut fmt::Formatter) -> fmt::Result {
                f.write_str("a msgpack value")
            }
            fn visit_unit<E>(self) -> Result<MiddleValue, E> {
                Ok(MiddleValue::Null)
            }
            fn visit_none<E>(self) -> Result<MiddleValue, E> {
                Ok(MiddleValue::Null)
            }
            fn visit_bool<E>(self, v: bool) -> Result<MiddleValue, E> {
                Ok(MiddleValue::Bool(v))
            }
            fn visit_i64<E>(self, v: i64) -> Result<MiddleValue, E> {
                Ok(MiddleValue::Int(v))
            }
            fn visit_u64<E>(self, v: u64) -> Result<MiddleValue, E> {
                Ok(i64::try_from(v).map_or(MiddleValue::Float(v as f64), MiddleValue::Int))
            }
            fn visit_f64<E>(self, v: f64) -> Result<MiddleValue, E> {
                Ok(MiddleValue::Float(v))
            }
            fn visit_str<E>(self, v: &str) -> Result<MiddleValue, E> {
                Ok(MiddleValue::Str(v.to_owned()))
            }
            fn visit_string<E>(self, v: String) -> Result<MiddleValue, E> {
                Ok(MiddleValue::Str(v))
            }
            fn visit_bytes<E>(self, v: &[u8]) -> Result<MiddleValue, E> {
                Ok(MiddleValue::Bytes(v.to_owned()))
            }
            fn visit_byte_buf<E>(self, v: Vec<u8>) -> Result<MiddleValue, E> {
                Ok(MiddleValue::Bytes(v))
            }
            fn visit_seq<A: SeqAccess<'de>>(self, mut seq: A) -> Result<MiddleValue, A::Error> {
                let mut out = Vec::new();
                while let Some(it) = seq.next_element()? {
                    out.push(it);
                }
                Ok(MiddleValue::Array(out))
            }
            fn visit_map<A: MapAccess<'de>>(self, mut map: A) -> Result<MiddleValue, A::Error> {
                let mut out = Vec::new();
                while let Some((k, v)) = map.next_entry::<MapKey, MiddleValue>()? {
                    out.push((k.0, v));
                }
                Ok(MiddleValue::Map(out))
            }
        }
        d.deserialize_any(V)
    }
}

/// A map key coerced to a string (msgpack maps may key by non-string scalars).
struct MapKey(String);
impl<'de> Deserialize<'de> for MapKey {
    fn deserialize<D: Deserializer<'de>>(d: D) -> Result<Self, D::Error> {
        struct K;
        impl<'de> Visitor<'de> for K {
            type Value = MapKey;
            fn expecting(&self, f: &mut fmt::Formatter) -> fmt::Result {
                f.write_str("a map key")
            }
            fn visit_str<E>(self, v: &str) -> Result<MapKey, E> {
                Ok(MapKey(v.to_owned()))
            }
            fn visit_string<E>(self, v: String) -> Result<MapKey, E> {
                Ok(MapKey(v))
            }
            fn visit_i64<E>(self, v: i64) -> Result<MapKey, E> {
                Ok(MapKey(v.to_string()))
            }
            fn visit_u64<E>(self, v: u64) -> Result<MapKey, E> {
                Ok(MapKey(v.to_string()))
            }
        }
        d.deserialize_any(K)
    }
}

// ---------------------------------------------------------------------------
// PHP Zval <-> MiddleValue
// ---------------------------------------------------------------------------

/// Convert a PHP value into the neutral representation.
pub fn zval_to_middle(zv: &Zval) -> Result<MiddleValue, String> {
    if zv.is_null() {
        return Ok(MiddleValue::Null);
    }
    if zv.is_bool() {
        return Ok(MiddleValue::Bool(zv.bool().unwrap_or(false)));
    }
    if zv.is_long() {
        return Ok(MiddleValue::Int(zv.long().unwrap()));
    }
    if zv.is_double() {
        return Ok(MiddleValue::Float(zv.double().unwrap()));
    }
    if zv.is_string() {
        // PHP strings are byte strings. Preserve valid UTF-8 as a string;
        // anything else (binary data) crosses as bytes.
        let bytes = zv.zend_str().map(|zs| zs.as_bytes()).unwrap_or(&[]);
        return Ok(match std::str::from_utf8(bytes) {
            Ok(s) => MiddleValue::Str(s.to_owned()),
            Err(_) => MiddleValue::Bytes(bytes.to_owned()),
        });
    }
    if zv.is_array() {
        let ht = zv.array().unwrap();
        return hashtable_to_middle(ht);
    }
    Err(
        "unsupported PHP value type for marshaling (capabilities exchange scalars, arrays, and handles; use grant() for live objects)"
            .to_owned(),
    )
}

/// A PHP array becomes a [`MiddleValue::Array`] when its keys are the sequential
/// `0..n`, otherwise an insertion-ordered [`MiddleValue::Map`].
fn hashtable_to_middle(ht: &ZendHashTable) -> Result<MiddleValue, String> {
    if ht.has_sequential_keys() {
        let mut out = Vec::with_capacity(ht.len());
        for (_, v) in ht.iter() {
            out.push(zval_to_middle(v)?);
        }
        Ok(MiddleValue::Array(out))
    } else {
        let mut out = Vec::with_capacity(ht.len());
        for (k, v) in ht.iter() {
            let key = match k {
                ArrayKey::Long(i) => i.to_string(),
                ArrayKey::String(s) => s,
                ArrayKey::Str(s) => s.to_owned(),
                ArrayKey::ZendString(s) => s.try_into().unwrap_or_default(),
            };
            out.push((key, zval_to_middle(v)?));
        }
        Ok(MiddleValue::Map(out))
    }
}

/// Convert the neutral representation into a PHP value.
pub fn middle_to_zval(value: &MiddleValue) -> Result<Zval, String> {
    let mut zv = Zval::new();
    match value {
        MiddleValue::Null => zv.set_null(),
        MiddleValue::Bool(b) => zv.set_bool(*b),
        MiddleValue::Int(i) => zv.set_long(*i),
        MiddleValue::Float(f) => zv.set_double(*f),
        MiddleValue::Str(s) => zv
            .set_string(s, false)
            .map_err(|e| format!("string conversion failed: {e}"))?,
        MiddleValue::Bytes(b) => zv.set_binary(b.clone()),
        MiddleValue::Array(items) => {
            let mut ht = ZendHashTable::new();
            for it in items {
                ht.push(middle_to_zval(it)?)
                    .map_err(|e| format!("array push failed: {e}"))?;
            }
            zv.set_hashtable(ht);
        }
        MiddleValue::Map(entries) => {
            let mut ht = ZendHashTable::new();
            for (k, v) in entries {
                ht.insert(k.as_str(), middle_to_zval(v)?)
                    .map_err(|e| format!("map insert failed: {e}"))?;
            }
            zv.set_hashtable(ht);
        }
    }
    Ok(zv)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn roundtrip(v: MiddleValue) {
        let bytes = v.to_msgpack().expect("encode");
        let back = MiddleValue::from_msgpack(&bytes).expect("decode");
        assert_eq!(v, back);
    }

    #[test]
    fn msgpack_scalars() {
        roundtrip(MiddleValue::Null);
        roundtrip(MiddleValue::Bool(true));
        roundtrip(MiddleValue::Int(-42));
        roundtrip(MiddleValue::Int(1 << 40));
        roundtrip(MiddleValue::Float(3.5));
        roundtrip(MiddleValue::Str("héllo".to_owned()));
        roundtrip(MiddleValue::Bytes(vec![0, 1, 2, 255]));
    }

    #[test]
    fn msgpack_nested() {
        roundtrip(MiddleValue::Array(vec![
            MiddleValue::Int(1),
            MiddleValue::Str("two".into()),
            MiddleValue::Bool(false),
        ]));
        roundtrip(MiddleValue::Map(vec![
            ("a".into(), MiddleValue::Int(1)),
            (
                "nested".into(),
                MiddleValue::Array(vec![MiddleValue::Null, MiddleValue::Float(2.5)]),
            ),
        ]));
    }
}
