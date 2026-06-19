use bson::{Bson, Document};
use bson::raw::{RawBsonRef, RawDocumentBuf};
use bson::spec::ElementType;
use ext_php_rs::boxed::ZBox;
use ext_php_rs::types::{ZendHashTable, Zval};
use ext_php_rs::zend::ClassEntry;
use ext_php_rs::convert::IntoZval;
use std::cell::Cell;

fn base64_encode(input: &[u8]) -> String {
    use base64::Engine;
    base64::engine::general_purpose::STANDARD.encode(input)
}

// Cache class entry pointers — they're stable for the lifetime of the PHP process.
// Cell is fine here because PHP extensions are single-threaded per-worker.
thread_local! {
    static CE_DOCUMENT: Cell<Option<&'static ClassEntry>> = Cell::new(None);
    static CE_OBJECTID: Cell<Option<&'static ClassEntry>> = Cell::new(None);
    static CE_UTCDATETIME: Cell<Option<&'static ClassEntry>> = Cell::new(None);
    static CE_REGEX: Cell<Option<&'static ClassEntry>> = Cell::new(None);
    static CE_BSONARRAY: Cell<Option<&'static ClassEntry>> = Cell::new(None);
    static CE_BSONDOCUMENT: Cell<Option<&'static ClassEntry>> = Cell::new(None);
    static CE_BINARY: Cell<Option<&'static ClassEntry>> = Cell::new(None);
    static CE_DECIMAL128: Cell<Option<&'static ClassEntry>> = Cell::new(None);
    static CE_TIMESTAMP: Cell<Option<&'static ClassEntry>> = Cell::new(None);
    static CE_MINKEY: Cell<Option<&'static ClassEntry>> = Cell::new(None);
    static CE_MAXKEY: Cell<Option<&'static ClassEntry>> = Cell::new(None);
    static CE_JAVASCRIPT: Cell<Option<&'static ClassEntry>> = Cell::new(None);
    // Tracks whether we've attempted lookup (so we don't retry on failure)
    static CE_DOCUMENT_TRIED: Cell<bool> = Cell::new(false);
    static CE_OBJECTID_TRIED: Cell<bool> = Cell::new(false);
    static CE_UTCDATETIME_TRIED: Cell<bool> = Cell::new(false);
    static CE_REGEX_TRIED: Cell<bool> = Cell::new(false);
    static CE_BSONARRAY_TRIED: Cell<bool> = Cell::new(false);
    static CE_BSONDOCUMENT_TRIED: Cell<bool> = Cell::new(false);
    static CE_BINARY_TRIED: Cell<bool> = Cell::new(false);
    static CE_DECIMAL128_TRIED: Cell<bool> = Cell::new(false);
    static CE_TIMESTAMP_TRIED: Cell<bool> = Cell::new(false);
    static CE_MINKEY_TRIED: Cell<bool> = Cell::new(false);
    static CE_MAXKEY_TRIED: Cell<bool> = Cell::new(false);
    static CE_JAVASCRIPT_TRIED: Cell<bool> = Cell::new(false);
}

fn get_ce_cached(
    cache: &'static std::thread::LocalKey<Cell<Option<&'static ClassEntry>>>,
    tried: &'static std::thread::LocalKey<Cell<bool>>,
    name: &str,
) -> Option<&'static ClassEntry> {
    cache.with(|c| {
        if let Some(ce) = c.get() {
            return Some(ce);
        }
        if tried.with(|t| t.get()) {
            return None;
        }
        tried.with(|t| t.set(true));
        if let Some(ce) = ClassEntry::try_find(name) {
            let ce_ref: &'static ClassEntry = unsafe { &*(ce as *const ClassEntry) };
            c.set(Some(ce_ref));
            Some(ce_ref)
        } else {
            None
        }
    })
}

fn get_ce_document() -> Option<&'static ClassEntry> {
    get_ce_cached(&CE_DOCUMENT, &CE_DOCUMENT_TRIED, "ZealPHP\\MongoDB\\Document")
}

fn get_ce_objectid() -> Option<&'static ClassEntry> {
    get_ce_cached(&CE_OBJECTID, &CE_OBJECTID_TRIED, "MongoDB\\BSON\\ObjectId")
}

fn get_ce_utcdatetime() -> Option<&'static ClassEntry> {
    get_ce_cached(&CE_UTCDATETIME, &CE_UTCDATETIME_TRIED, "MongoDB\\BSON\\UTCDateTime")
}

fn get_ce_regex() -> Option<&'static ClassEntry> {
    get_ce_cached(&CE_REGEX, &CE_REGEX_TRIED, "MongoDB\\BSON\\Regex")
}

fn get_ce_bsonarray() -> Option<&'static ClassEntry> {
    get_ce_cached(&CE_BSONARRAY, &CE_BSONARRAY_TRIED, "MongoDB\\Model\\BSONArray")
}

fn get_ce_bsondocument() -> Option<&'static ClassEntry> {
    get_ce_cached(&CE_BSONDOCUMENT, &CE_BSONDOCUMENT_TRIED, "MongoDB\\Model\\BSONDocument")
}

fn get_ce_binary() -> Option<&'static ClassEntry> {
    get_ce_cached(&CE_BINARY, &CE_BINARY_TRIED, "MongoDB\\BSON\\Binary")
}

fn get_ce_decimal128() -> Option<&'static ClassEntry> {
    get_ce_cached(&CE_DECIMAL128, &CE_DECIMAL128_TRIED, "MongoDB\\BSON\\Decimal128")
}

fn get_ce_timestamp() -> Option<&'static ClassEntry> {
    get_ce_cached(&CE_TIMESTAMP, &CE_TIMESTAMP_TRIED, "MongoDB\\BSON\\Timestamp")
}

fn get_ce_minkey() -> Option<&'static ClassEntry> {
    get_ce_cached(&CE_MINKEY, &CE_MINKEY_TRIED, "MongoDB\\BSON\\MinKey")
}

fn get_ce_maxkey() -> Option<&'static ClassEntry> {
    get_ce_cached(&CE_MAXKEY, &CE_MAXKEY_TRIED, "MongoDB\\BSON\\MaxKey")
}

fn get_ce_javascript() -> Option<&'static ClassEntry> {
    get_ce_cached(&CE_JAVASCRIPT, &CE_JAVASCRIPT_TRIED, "MongoDB\\BSON\\Javascript")
}

/// An empty BSON document must stay distinguishable from an empty array once it
/// reaches PHP — both would otherwise collapse to `[]`, and the PHP wrapper maps
/// `[]` (array_is_list) to BSONArray, losing the `{}` shape (#53). Returning a
/// real (empty) MongoDB\Model\BSONDocument here keeps the document shape; the
/// PHP wrapDoc() passes objects through untouched.
fn make_empty_bson_document() -> Option<Zval> {
    let ce = get_ce_bsondocument()?;
    let obj = ce.new();
    obj.try_call_method("__construct", Vec::<&dyn ext_php_rs::convert::IntoZvalDyn>::new()).ok()?;
    obj.into_zval(false).ok()
}

fn make_object_id(hex: &str) -> Option<Zval> {
    let ce = get_ce_objectid()?;
    let obj = ce.new();
    obj.try_call_method("__construct", vec![&hex as &dyn ext_php_rs::convert::IntoZvalDyn]).ok()?;
    obj.into_zval(false).ok()
}

fn make_utc_date_time(ms: i64) -> Option<Zval> {
    let ce = get_ce_utcdatetime()?;
    let obj = ce.new();
    obj.try_call_method("__construct", vec![&ms as &dyn ext_php_rs::convert::IntoZvalDyn]).ok()?;
    obj.into_zval(false).ok()
}

fn make_regex(pattern: &str, options: &str) -> Option<Zval> {
    let ce = get_ce_regex()?;
    let obj = ce.new();
    obj.try_call_method("__construct", vec![
        &pattern as &dyn ext_php_rs::convert::IntoZvalDyn,
        &options as &dyn ext_php_rs::convert::IntoZvalDyn,
    ]).ok()?;
    obj.into_zval(false).ok()
}

// Read-side constructors for the remaining BSON value types. Producing real
// MongoDB\BSON\* objects (instead of {$binary:…}/{$numberDecimal:…} arrays) is
// the other half of the #45 fix: a genuine BSON value comes back as an object,
// so a literal user document keyed `$binary` — which is read back as a plain
// array — is no longer reconstructed into a BSON value by the PHP wrapDoc().
fn make_binary(bytes: &[u8], subtype: u8) -> Option<Zval> {
    let ce = get_ce_binary()?;
    let obj = ce.new();
    let mut data_z = Zval::new();
    data_z.set_zend_string(ext_php_rs::types::ZendStr::new(bytes, false));
    let st = subtype as i64;
    obj.try_call_method("__construct", vec![
        &data_z as &dyn ext_php_rs::convert::IntoZvalDyn,
        &st as &dyn ext_php_rs::convert::IntoZvalDyn,
    ]).ok()?;
    obj.into_zval(false).ok()
}

fn make_decimal128(value: &str) -> Option<Zval> {
    let ce = get_ce_decimal128()?;
    let obj = ce.new();
    obj.try_call_method("__construct", vec![&value as &dyn ext_php_rs::convert::IntoZvalDyn]).ok()?;
    obj.into_zval(false).ok()
}

fn make_timestamp(increment: i64, timestamp: i64) -> Option<Zval> {
    let ce = get_ce_timestamp()?;
    let obj = ce.new();
    obj.try_call_method("__construct", vec![
        &increment as &dyn ext_php_rs::convert::IntoZvalDyn,
        &timestamp as &dyn ext_php_rs::convert::IntoZvalDyn,
    ]).ok()?;
    obj.into_zval(false).ok()
}

fn make_javascript(code: &str) -> Option<Zval> {
    let ce = get_ce_javascript()?;
    let obj = ce.new();
    obj.try_call_method("__construct", vec![&code as &dyn ext_php_rs::convert::IntoZvalDyn]).ok()?;
    obj.into_zval(false).ok()
}

fn make_minkey() -> Option<Zval> {
    let ce = get_ce_minkey()?;
    let obj = ce.new();
    obj.try_call_method("__construct", Vec::<&dyn ext_php_rs::convert::IntoZvalDyn>::new()).ok()?;
    obj.into_zval(false).ok()
}

fn make_maxkey() -> Option<Zval> {
    let ce = get_ce_maxkey()?;
    let obj = ce.new();
    obj.try_call_method("__construct", Vec::<&dyn ext_php_rs::convert::IntoZvalDyn>::new()).ok()?;
    obj.into_zval(false).ok()
}

fn wrap_as_document(ht: ZBox<ZendHashTable>) -> Zval {
    // An empty document round-trips as an empty array otherwise, which the PHP
    // wrapper would treat as a BSONArray (#53). Emit a real empty BSONDocument
    // so the `{}` shape survives. Falls back to the plain array if the class
    // isn't loaded.
    if ht.len() == 0 {
        if let Some(z) = make_empty_bson_document() {
            return z;
        }
    }

    let mut zval = Zval::new();
    zval.set_hashtable(ht);
    zval
}

fn wrap_as_bson_array(ht: ZBox<ZendHashTable>) -> Zval {
    let mut zval = Zval::new();
    zval.set_hashtable(ht);
    zval
}

pub fn php_to_doc(zval: &Zval) -> Result<Document, String> {
    match zval.array() {
        Some(arr) => hash_table_to_doc(arr),
        None => Err("Expected array for BSON document".into()),
    }
}

fn hash_table_to_doc(ht: &ZendHashTable) -> Result<Document, String> {
    let mut doc = Document::new();
    for (key, val) in ht.iter() {
        let key_str = match key {
            ext_php_rs::types::ArrayKey::Long(n) => n.to_string(),
            ext_php_rs::types::ArrayKey::String(s) => s.to_string(),
        };
        let bson_val = zval_to_bson(val)?;
        doc.insert(key_str, bson_val);
    }
    Ok(doc)
}

fn zval_to_bson(zval: &Zval) -> Result<Bson, String> {
    if zval.is_null() {
        return Ok(Bson::Null);
    }
    if let Some(b) = zval.bool() {
        return Ok(Bson::Boolean(b));
    }
    if let Some(i) = zval.long() {
        // Match the official PHP driver: a value that fits in 32 bits is stored
        // as BSON int32, otherwise int64. Storing every int as int64 broke
        // `$type:'int'` queries, schema validators and $sum/$count parity (#44).
        // The read side maps both Int32 and Int64 back to a PHP int, so this
        // round-trips losslessly.
        if i >= i32::MIN as i64 && i <= i32::MAX as i64 {
            return Ok(Bson::Int32(i as i32));
        }

        return Ok(Bson::Int64(i));
    }
    if let Some(f) = zval.double() {
        return Ok(Bson::Double(f));
    }
    if let Some(s) = zval.str() {
        return Ok(Bson::String(s.to_string()));
    }
    if let Some(obj) = zval.object() {
        if let Some(ce) = get_ce_objectid() {
            if obj.instance_of(ce) {
                if let Ok(result) = obj.try_call_method("__toString", vec![]) {
                    if let Some(hex) = result.str() {
                        let oid = bson::oid::ObjectId::parse_str(hex).map_err(|e| e.to_string())?;
                        return Ok(Bson::ObjectId(oid));
                    }
                }
            }
        }
        if let Some(ce) = get_ce_utcdatetime() {
            if obj.instance_of(ce) {
                if let Ok(result) = obj.try_call_method("__toString", vec![]) {
                    if let Some(ms_str) = result.str() {
                        let ms: i64 = ms_str.parse().map_err(|e: std::num::ParseIntError| e.to_string())?;
                        return Ok(Bson::DateTime(bson::DateTime::from_millis(ms)));
                    }
                }
            }
        }
        if let Some(ce) = get_ce_regex() {
            if obj.instance_of(ce) {
                let pattern = obj.try_call_method("getPattern", vec![]).ok().and_then(|v| v.str().map(|s| s.to_string())).unwrap_or_default();
                let flags = obj.try_call_method("getFlags", vec![]).ok().and_then(|v| v.str().map(|s| s.to_string())).unwrap_or_default();
                return Ok(Bson::RegularExpression(bson::Regex { pattern, options: flags }));
            }
        }
        // The remaining BSON value types are encoded straight from their PHP
        // objects (#45). Doing this — rather than letting the PHP layer flatten
        // them to {$binary:…}/{$numberDecimal:…} arrays the ext reconstructs —
        // is what makes a *literal* user document keyed `$binary`/`$numberDecimal`
        // unambiguous: only a real MongoDB\BSON\* object becomes a BSON type; a
        // plain array stays a sub-document.
        if let Some(ce) = get_ce_binary() {
            if obj.instance_of(ce) {
                let bytes = obj
                    .try_call_method("getData", vec![])
                    .ok()
                    .and_then(|z| z.zend_str().map(|zs| zs.as_bytes().to_vec()))
                    .unwrap_or_default();
                let subtype = obj.try_call_method("getType", vec![]).ok().and_then(|z| z.long()).unwrap_or(0) as u8;
                return Ok(Bson::Binary(bson::Binary { subtype: bson::spec::BinarySubtype::from(subtype), bytes }));
            }
        }
        if let Some(ce) = get_ce_decimal128() {
            if obj.instance_of(ce) {
                if let Ok(result) = obj.try_call_method("__toString", vec![]) {
                    if let Some(s) = result.str() {
                        let d = s.parse::<bson::Decimal128>().unwrap_or_else(|_| "0".parse::<bson::Decimal128>().unwrap());
                        return Ok(Bson::Decimal128(d));
                    }
                }
            }
        }
        if let Some(ce) = get_ce_timestamp() {
            if obj.instance_of(ce) {
                let t = obj.try_call_method("getTimestamp", vec![]).ok().and_then(|z| z.long()).unwrap_or(0) as u32;
                let i = obj.try_call_method("getIncrement", vec![]).ok().and_then(|z| z.long()).unwrap_or(0) as u32;
                return Ok(Bson::Timestamp(bson::Timestamp { time: t, increment: i }));
            }
        }
        if let Some(ce) = get_ce_javascript() {
            if obj.instance_of(ce) {
                let code = obj.try_call_method("getCode", vec![]).ok().and_then(|v| v.str().map(|s| s.to_string())).unwrap_or_default();
                if let Some(scope_z) = obj.try_call_method("getScope", vec![]).ok() {
                    if let Some(scope_arr) = scope_z.array() {
                        let scope = hash_table_to_doc(scope_arr)?;
                        return Ok(Bson::JavaScriptCodeWithScope(bson::JavaScriptCodeWithScope { code, scope }));
                    }
                }
                return Ok(Bson::JavaScriptCode(code));
            }
        }
        if let Some(ce) = get_ce_minkey() {
            if obj.instance_of(ce) {
                return Ok(Bson::MinKey);
            }
        }
        if let Some(ce) = get_ce_maxkey() {
            if obj.instance_of(ce) {
                return Ok(Bson::MaxKey);
            }
        }
        // Generic PHP object (e.g. stdClass) → BSON Document
        if let Ok(props) = obj.get_properties() {
            return Ok(Bson::Document(hash_table_to_doc(props)?));
        }
        return Ok(Bson::Document(Document::new()));
    }
    if let Some(arr) = zval.array() {
        // NB: a plain PHP array is NEVER reinterpreted as a BSON type from its
        // shape — that was the extended-JSON sentinel conflation (#45). BSON
        // values arrive as real MongoDB\BSON\* objects (handled above); a plain
        // array — even one keyed `$oid`/`$binary` — is stored verbatim.
        let is_sequential = arr.iter().enumerate().all(|(i, (key, _))| {
            matches!(key, ext_php_rs::types::ArrayKey::Long(n) if n == i as i64)
        });
        if is_sequential {
            let mut bson_arr = Vec::with_capacity(arr.len());
            for (_, val) in arr.iter() {
                bson_arr.push(zval_to_bson(val)?);
            }
            return Ok(Bson::Array(bson_arr));
        } else {
            return Ok(Bson::Document(hash_table_to_doc(arr)?));
        }
    }
    Err("Unsupported PHP type for BSON conversion".into())
}

pub fn doc_to_php(doc: &Document) -> Zval {
    let mut ht = ZendHashTable::new();
    for (key, val) in doc.iter() {
        let php_val = bson_to_zval(val);
        let _ = ht.insert(key, php_val);
    }
    wrap_as_document(ht)
}

pub fn bson_to_zval(bson: &Bson) -> Zval {
    let mut zval = Zval::new();
    match bson {
        Bson::Null => { zval.set_null(); }
        Bson::Boolean(b) => { zval.set_bool(*b); }
        Bson::Int32(i) => { zval.set_long(*i as i64); }
        Bson::Int64(i) => { zval.set_long(*i); }
        Bson::Double(f) => { zval.set_double(*f); }
        Bson::String(s) => { let _ = zval.set_string(s, false); }
        Bson::ObjectId(oid) => {
            if let Some(z) = make_object_id(&oid.to_hex()) { return z; }
            let mut ht = ZendHashTable::new();
            let mut oid_zval = Zval::new();
            let _ = oid_zval.set_string(&oid.to_hex(), false);
            let _ = ht.insert("$oid", oid_zval);
            zval.set_hashtable(ht);
        }
        Bson::DateTime(dt) => {
            if let Some(z) = make_utc_date_time(dt.timestamp_millis()) { return z; }
            let mut outer = ZendHashTable::new();
            let mut inner = ZendHashTable::new();
            let ms_str = dt.timestamp_millis().to_string();
            let mut ms_zval = Zval::new();
            let _ = ms_zval.set_string(&ms_str, false);
            let _ = inner.insert("$numberLong", ms_zval);
            let mut inner_zval = Zval::new();
            inner_zval.set_hashtable(inner);
            let _ = outer.insert("$date", inner_zval);
            zval.set_hashtable(outer);
        }
        Bson::Document(doc) => { return doc_to_php(doc); }
        Bson::Array(arr) => {
            let mut ht = ZendHashTable::new();
            for (i, val) in arr.iter().enumerate() {
                let _ = ht.insert_at_index(i as u64, bson_to_zval(val));
            }
            return wrap_as_bson_array(ht);
        }
        Bson::Binary(bin) => {
            if let Some(z) = make_binary(&bin.bytes, u8::from(bin.subtype)) { return z; }
            let mut outer = ZendHashTable::new();
            let mut inner = ZendHashTable::new();
            let b64 = base64_encode(&bin.bytes);
            let sub_type = format!("{:02x}", u8::from(bin.subtype));
            let mut b64_zval = Zval::new();
            let _ = b64_zval.set_string(&b64, false);
            let _ = inner.insert("base64", b64_zval);
            let mut st_zval = Zval::new();
            let _ = st_zval.set_string(&sub_type, false);
            let _ = inner.insert("subType", st_zval);
            let mut inner_zval = Zval::new();
            inner_zval.set_hashtable(inner);
            let _ = outer.insert("$binary", inner_zval);
            zval.set_hashtable(outer);
        }
        Bson::RegularExpression(re) => {
            if let Some(z) = make_regex(&re.pattern, &re.options) { return z; }
            let mut outer = ZendHashTable::new();
            let mut inner = ZendHashTable::new();
            let mut p_zval = Zval::new();
            let _ = p_zval.set_string(&re.pattern, false);
            let _ = inner.insert("pattern", p_zval);
            let mut o_zval = Zval::new();
            let _ = o_zval.set_string(&re.options, false);
            let _ = inner.insert("options", o_zval);
            let mut inner_zval = Zval::new();
            inner_zval.set_hashtable(inner);
            let _ = outer.insert("$regularExpression", inner_zval);
            zval.set_hashtable(outer);
        }
        Bson::Timestamp(ts) => {
            if let Some(z) = make_timestamp(ts.increment as i64, ts.time as i64) { return z; }
            let mut outer = ZendHashTable::new();
            let mut inner = ZendHashTable::new();
            let mut t_zval = Zval::new();
            t_zval.set_long(ts.time as i64);
            let _ = inner.insert("t", t_zval);
            let mut i_zval = Zval::new();
            i_zval.set_long(ts.increment as i64);
            let _ = inner.insert("i", i_zval);
            let mut inner_zval = Zval::new();
            inner_zval.set_hashtable(inner);
            let _ = outer.insert("$timestamp", inner_zval);
            zval.set_hashtable(outer);
        }
        Bson::Decimal128(d) => {
            if let Some(z) = make_decimal128(&d.to_string()) { return z; }
            let mut ht = ZendHashTable::new();
            let mut d_zval = Zval::new();
            let _ = d_zval.set_string(&d.to_string(), false);
            let _ = ht.insert("$numberDecimal", d_zval);
            zval.set_hashtable(ht);
        }
        Bson::JavaScriptCode(code) => {
            if let Some(z) = make_javascript(code) { return z; }
            let mut ht = ZendHashTable::new();
            let mut code_zval = Zval::new();
            let _ = code_zval.set_string(code, false);
            let _ = ht.insert("$code", code_zval);
            zval.set_hashtable(ht);
        }
        Bson::JavaScriptCodeWithScope(jsc) => {
            let mut ht = ZendHashTable::new();
            let mut code_zval = Zval::new();
            let _ = code_zval.set_string(&jsc.code, false);
            let _ = ht.insert("$code", code_zval);
            let scope_zval = doc_to_php(&jsc.scope);
            let _ = ht.insert("$scope", scope_zval);
            zval.set_hashtable(ht);
        }
        Bson::MinKey => {
            if let Some(z) = make_minkey() { return z; }
            let mut ht = ZendHashTable::new();
            let mut one = Zval::new();
            one.set_long(1);
            let _ = ht.insert("$minKey", one);
            zval.set_hashtable(ht);
        }
        Bson::MaxKey => {
            if let Some(z) = make_maxkey() { return z; }
            let mut ht = ZendHashTable::new();
            let mut one = Zval::new();
            one.set_long(1);
            let _ = ht.insert("$maxKey", one);
            zval.set_hashtable(ht);
        }
        _ => { zval.set_null(); }
    }
    zval
}

pub fn raw_doc_to_php(raw: &RawDocumentBuf) -> Zval {
    let mut ht = ZendHashTable::with_capacity(8);
    for result in raw.iter() {
        if let Ok((key, val)) = result {
            let _ = ht.insert(key, raw_bson_to_zval(val));
        }
    }
    wrap_as_document(ht)
}

fn raw_subdoc_to_php(raw: &bson::raw::RawDocument) -> Zval {
    let mut ht = ZendHashTable::with_capacity(8);
    for result in raw.iter() {
        if let Ok((key, val)) = result {
            let _ = ht.insert(key, raw_bson_to_zval(val));
        }
    }
    wrap_as_document(ht)
}

fn raw_bson_to_zval(val: RawBsonRef<'_>) -> Zval {
    let mut zval = Zval::new();
    match val.element_type() {
        ElementType::Null | ElementType::Undefined => {
            zval.set_null();
        }
        ElementType::Boolean => {
            if let RawBsonRef::Boolean(b) = val { zval.set_bool(b); }
        }
        ElementType::Int32 => {
            if let RawBsonRef::Int32(i) = val { zval.set_long(i as i64); }
        }
        ElementType::Int64 => {
            if let RawBsonRef::Int64(i) = val { zval.set_long(i); }
        }
        ElementType::Double => {
            if let RawBsonRef::Double(f) = val { zval.set_double(f); }
        }
        ElementType::String => {
            if let RawBsonRef::String(s) = val { let _ = zval.set_string(s, false); }
        }
        ElementType::ObjectId => {
            if let RawBsonRef::ObjectId(oid) = val {
                if let Some(z) = make_object_id(&oid.to_hex()) { return z; }
                let mut ht = ZendHashTable::new();
                let mut oid_zval = Zval::new();
                let _ = oid_zval.set_string(&oid.to_hex(), false);
                let _ = ht.insert("$oid", oid_zval);
                zval.set_hashtable(ht);
            }
        }
        ElementType::DateTime => {
            if let RawBsonRef::DateTime(dt) = val {
                if let Some(z) = make_utc_date_time(dt.timestamp_millis()) { return z; }
                let mut outer = ZendHashTable::new();
                let mut inner = ZendHashTable::new();
                let ms_str = dt.timestamp_millis().to_string();
                let mut ms_zval = Zval::new();
                let _ = ms_zval.set_string(&ms_str, false);
                let _ = inner.insert("$numberLong", ms_zval);
                let mut inner_zval = Zval::new();
                inner_zval.set_hashtable(inner);
                let _ = outer.insert("$date", inner_zval);
                zval.set_hashtable(outer);
            }
        }
        ElementType::EmbeddedDocument => {
            if let RawBsonRef::Document(doc) = val {
                return raw_subdoc_to_php(doc);
            }
        }
        ElementType::Array => {
            if let RawBsonRef::Array(arr) = val {
                let mut ht = ZendHashTable::new();
                for (i, result) in arr.into_iter().enumerate() {
                    if let Ok(v) = result {
                        let _ = ht.insert_at_index(i as u64, raw_bson_to_zval(v));
                    }
                }
                return wrap_as_bson_array(ht);
            }
        }
        ElementType::Binary => {
            if let RawBsonRef::Binary(bin) = val {
                if let Some(z) = make_binary(bin.bytes, u8::from(bin.subtype)) { return z; }
                let mut outer = ZendHashTable::new();
                let mut inner = ZendHashTable::new();
                let b64 = base64_encode(bin.bytes);
                let sub_type = format!("{:02x}", u8::from(bin.subtype));
                let mut b64_zval = Zval::new();
                let _ = b64_zval.set_string(&b64, false);
                let _ = inner.insert("base64", b64_zval);
                let mut st_zval = Zval::new();
                let _ = st_zval.set_string(&sub_type, false);
                let _ = inner.insert("subType", st_zval);
                let mut inner_zval = Zval::new();
                inner_zval.set_hashtable(inner);
                let _ = outer.insert("$binary", inner_zval);
                zval.set_hashtable(outer);
            }
        }
        ElementType::RegularExpression => {
            if let RawBsonRef::RegularExpression(re) = val {
                if let Some(z) = make_regex(re.pattern, re.options) { return z; }
                let mut outer = ZendHashTable::new();
                let mut inner = ZendHashTable::new();
                let mut p_zval = Zval::new();
                let _ = p_zval.set_string(re.pattern, false);
                let _ = inner.insert("pattern", p_zval);
                let mut o_zval = Zval::new();
                let _ = o_zval.set_string(re.options, false);
                let _ = inner.insert("options", o_zval);
                let mut inner_zval = Zval::new();
                inner_zval.set_hashtable(inner);
                let _ = outer.insert("$regularExpression", inner_zval);
                zval.set_hashtable(outer);
            }
        }
        ElementType::Timestamp => {
            if let RawBsonRef::Timestamp(ts) = val {
                if let Some(z) = make_timestamp(ts.increment as i64, ts.time as i64) { return z; }
                let mut outer = ZendHashTable::new();
                let mut inner = ZendHashTable::new();
                let mut t_zval = Zval::new();
                t_zval.set_long(ts.time as i64);
                let _ = inner.insert("t", t_zval);
                let mut i_zval = Zval::new();
                i_zval.set_long(ts.increment as i64);
                let _ = inner.insert("i", i_zval);
                let mut inner_zval = Zval::new();
                inner_zval.set_hashtable(inner);
                let _ = outer.insert("$timestamp", inner_zval);
                zval.set_hashtable(outer);
            }
        }
        ElementType::Decimal128 => {
            if let RawBsonRef::Decimal128(d) = val {
                if let Some(z) = make_decimal128(&d.to_string()) { return z; }
                let mut ht = ZendHashTable::new();
                let mut d_zval = Zval::new();
                let _ = d_zval.set_string(&d.to_string(), false);
                let _ = ht.insert("$numberDecimal", d_zval);
                zval.set_hashtable(ht);
            }
        }
        ElementType::JavaScriptCode => {
            if let RawBsonRef::JavaScriptCode(code) = val {
                if let Some(z) = make_javascript(code) { return z; }
                let mut ht = ZendHashTable::new();
                let mut code_zval = Zval::new();
                let _ = code_zval.set_string(code, false);
                let _ = ht.insert("$code", code_zval);
                zval.set_hashtable(ht);
            }
        }
        ElementType::JavaScriptCodeWithScope => {
            if let RawBsonRef::JavaScriptCodeWithScope(jsc) = val {
                let mut ht = ZendHashTable::new();
                let mut code_zval = Zval::new();
                let _ = code_zval.set_string(jsc.code, false);
                let _ = ht.insert("$code", code_zval);
                let scope_zval = raw_subdoc_to_php(jsc.scope);
                let _ = ht.insert("$scope", scope_zval);
                zval.set_hashtable(ht);
            }
        }
        ElementType::MinKey => {
            if let Some(z) = make_minkey() { return z; }
            let mut ht = ZendHashTable::new();
            let mut one = Zval::new();
            one.set_long(1);
            let _ = ht.insert("$minKey", one);
            zval.set_hashtable(ht);
        }
        ElementType::MaxKey => {
            if let Some(z) = make_maxkey() { return z; }
            let mut ht = ZendHashTable::new();
            let mut one = Zval::new();
            one.set_long(1);
            let _ = ht.insert("$maxKey", one);
            zval.set_hashtable(ht);
        }
        _ => {
            zval.set_null();
        }
    }
    zval
}

/// True when the Zval is a non-empty PHP list (sequential 0..n integer keys).
fn zval_is_list(zval: &Zval) -> bool {
    match zval.array() {
        Some(arr) => {
            arr.len() > 0
                && arr.iter().enumerate().all(|(i, (key, _))| {
                    matches!(key, ext_php_rs::types::ArrayKey::Long(n) if n == i as i64)
                })
        }
        None => false,
    }
}

/// An update is either update operators (a document like `{$set: …}`) or an
/// aggregation pipeline (a LIST of stage documents, MongoDB 4.2+). The PHP layer
/// passes both as arrays, so a pipeline must be detected by its list shape and
/// sent as UpdateModifications::Pipeline — otherwise it was serialized as a
/// document with "0"/"1" keys and the server rejected it (#46).
pub fn php_to_update_modifications(
    zval: &Zval,
) -> Result<mongodb::options::UpdateModifications, String> {
    if zval_is_list(zval) {
        Ok(mongodb::options::UpdateModifications::Pipeline(php_to_pipeline(zval)?))
    } else {
        Ok(mongodb::options::UpdateModifications::Document(php_to_doc(zval)?))
    }
}

pub fn php_to_pipeline(zval: &Zval) -> Result<Vec<Document>, String> {
    match zval.array() {
        Some(arr) => {
            let mut pipeline = Vec::new();
            for (_, val) in arr.iter() {
                pipeline.push(php_to_doc(val)?);
            }
            Ok(pipeline)
        }
        None => Err("Pipeline must be an array".into()),
    }
}
