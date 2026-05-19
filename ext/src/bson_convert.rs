use bson::{Bson, Document};
use ext_php_rs::types::{ZendHashTable, Zval};

fn base64_decode(input: &str) -> Vec<u8> {
    use base64::Engine;
    base64::engine::general_purpose::STANDARD.decode(input).unwrap_or_default()
}

fn base64_encode(input: &[u8]) -> String {
    use base64::Engine;
    base64::engine::general_purpose::STANDARD.encode(input)
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

fn try_extended_json(ht: &ZendHashTable) -> Result<Option<Bson>, String> {
    if let Some(oid_val) = ht.get("$oid") {
        if let Some(hex) = oid_val.str() {
            let oid = bson::oid::ObjectId::parse_str(hex).map_err(|e| e.to_string())?;
            return Ok(Some(Bson::ObjectId(oid)));
        }
    }

    if let Some(date_val) = ht.get("$date") {
        if let Some(inner) = date_val.array() {
            if let Some(num_long) = inner.get("$numberLong") {
                if let Some(ms_str) = num_long.str() {
                    let ms: i64 = ms_str.parse().map_err(|e: std::num::ParseIntError| e.to_string())?;
                    return Ok(Some(Bson::DateTime(bson::DateTime::from_millis(ms))));
                }
            }
        }
        if let Some(ms) = date_val.long() {
            return Ok(Some(Bson::DateTime(bson::DateTime::from_millis(ms))));
        }
    }

    if let Some(regex_val) = ht.get("$regularExpression") {
        if let Some(inner) = regex_val.array() {
            let pattern = inner.get("pattern").and_then(|v| v.str()).unwrap_or("");
            let options = inner.get("options").and_then(|v| v.str()).unwrap_or("");
            return Ok(Some(Bson::RegularExpression(bson::Regex { pattern: pattern.to_string(), options: options.to_string() })));
        }
    }

    // Binary: {"$binary": {"base64": "AAEC", "subType": "00"}}
    if let Some(bin_val) = ht.get("$binary") {
        if let Some(inner) = bin_val.array() {
            let b64 = inner.get("base64").and_then(|v| v.str()).unwrap_or("");
            let sub_type_str = inner.get("subType").and_then(|v| v.str()).unwrap_or("00");
            let sub_type_u8 = u8::from_str_radix(sub_type_str, 16).unwrap_or(0);
            let bytes = base64_decode(b64);
            return Ok(Some(Bson::Binary(bson::Binary {
                subtype: bson::spec::BinarySubtype::from(sub_type_u8),
                bytes,
            })));
        }
    }

    // Decimal128: {"$numberDecimal": "3.14"}
    if let Some(dec_val) = ht.get("$numberDecimal") {
        if let Some(s) = dec_val.str() {
            let d128 = s.parse::<bson::Decimal128>().unwrap_or_else(|_| "0".parse::<bson::Decimal128>().unwrap());
            return Ok(Some(Bson::Decimal128(d128)));
        }
    }

    // Timestamp: {"$timestamp": {"t": 123, "i": 1}}
    if let Some(ts_val) = ht.get("$timestamp") {
        if let Some(inner) = ts_val.array() {
            let t = inner.get("t").and_then(|v| v.long()).unwrap_or(0) as u32;
            let i = inner.get("i").and_then(|v| v.long()).unwrap_or(0) as u32;
            return Ok(Some(Bson::Timestamp(bson::Timestamp { time: t, increment: i })));
        }
    }

    // MinKey: {"$minKey": 1}
    if ht.get("$minKey").is_some() {
        return Ok(Some(Bson::MinKey));
    }

    // MaxKey: {"$maxKey": 1}
    if ht.get("$maxKey").is_some() {
        return Ok(Some(Bson::MaxKey));
    }

    // Javascript with scope: {"$code": "...", "$scope": {...}}
    // Javascript without scope: {"$code": "..."}
    if let Some(code_val) = ht.get("$code") {
        if let Some(code_str) = code_val.str() {
            if let Some(scope_val) = ht.get("$scope") {
                if let Some(scope_arr) = scope_val.array() {
                    let scope_doc = hash_table_to_doc(scope_arr)?;
                    return Ok(Some(Bson::JavaScriptCodeWithScope(bson::JavaScriptCodeWithScope {
                        code: code_str.to_string(),
                        scope: scope_doc,
                    })));
                }
            }
            return Ok(Some(Bson::JavaScriptCode(code_str.to_string())));
        }
    }

    Ok(None)
}

fn zval_to_bson(zval: &Zval) -> Result<Bson, String> {
    if zval.is_null() {
        return Ok(Bson::Null);
    }
    if let Some(b) = zval.bool() {
        return Ok(Bson::Boolean(b));
    }
    if let Some(i) = zval.long() {
        return Ok(Bson::Int64(i));
    }
    if let Some(f) = zval.double() {
        return Ok(Bson::Double(f));
    }
    if let Some(s) = zval.str() {
        return Ok(Bson::String(s.to_string()));
    }
    if let Some(arr) = zval.array() {
        if let Some(bson_type) = try_extended_json(arr)? {
            return Ok(bson_type);
        }

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
    let mut zval = Zval::new();
    let mut ht = ZendHashTable::new();
    for (key, val) in doc.iter() {
        let php_val = bson_to_zval(val);
        let _ = ht.insert(key, php_val);
    }
    zval.set_hashtable(ht);
    zval
}

pub fn bson_to_zval(bson: &Bson) -> Zval {
    let mut zval = Zval::new();
    match bson {
        Bson::Null => {
            zval.set_null();
        }
        Bson::Boolean(b) => {
            zval.set_bool(*b);
        }
        Bson::Int32(i) => {
            zval.set_long(*i as i64);
        }
        Bson::Int64(i) => {
            zval.set_long(*i);
        }
        Bson::Double(f) => {
            zval.set_double(*f);
        }
        Bson::String(s) => {
            let _ = zval.set_string(s, false);
        }
        Bson::ObjectId(oid) => {
            let mut ht = ZendHashTable::new();
            let mut oid_zval = Zval::new();
            let _ = oid_zval.set_string(&oid.to_hex(), false);
            let _ = ht.insert("$oid", oid_zval);
            zval.set_hashtable(ht);
        }
        Bson::DateTime(dt) => {
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
        Bson::Document(doc) => {
            return doc_to_php(doc);
        }
        Bson::Array(arr) => {
            let mut ht = ZendHashTable::new();
            for (i, val) in arr.iter().enumerate() {
                let _ = ht.insert_at_index(i as u64, bson_to_zval(val));
            }
            zval.set_hashtable(ht);
        }
        Bson::Binary(bin) => {
            // Return extended JSON: {"$binary": {"base64": ..., "subType": ...}}
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
            let mut ht = ZendHashTable::new();
            let mut d_zval = Zval::new();
            let _ = d_zval.set_string(&d.to_string(), false);
            let _ = ht.insert("$numberDecimal", d_zval);
            zval.set_hashtable(ht);
        }
        Bson::JavaScriptCode(code) => {
            let mut ht = ZendHashTable::new();
            let mut code_zval = Zval::new();
            let _ = code_zval.set_string(code, false);
            let _ = ht.insert("$code", code_zval);
            zval.set_hashtable(ht);
        }
        Bson::JavaScriptCodeWithScope(jsc) => {
            // Return extended JSON: {"$code": ..., "$scope": {...}}
            let mut ht = ZendHashTable::new();
            let mut code_zval = Zval::new();
            let _ = code_zval.set_string(&jsc.code, false);
            let _ = ht.insert("$code", code_zval);
            let scope_zval = doc_to_php(&jsc.scope);
            let _ = ht.insert("$scope", scope_zval);
            zval.set_hashtable(ht);
        }
        Bson::MinKey => {
            // Return extended JSON: {"$minKey": 1}
            let mut ht = ZendHashTable::new();
            let mut one = Zval::new();
            one.set_long(1);
            let _ = ht.insert("$minKey", one);
            zval.set_hashtable(ht);
        }
        Bson::MaxKey => {
            // Return extended JSON: {"$maxKey": 1}
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
