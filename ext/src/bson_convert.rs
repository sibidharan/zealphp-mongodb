use bson::{Bson, Document};
use ext_php_rs::types::{ZendHashTable, Zval};

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
        return Ok(Bson::Int64(i));
    }
    if let Some(f) = zval.double() {
        return Ok(Bson::Double(f));
    }
    if let Some(s) = zval.str() {
        return Ok(Bson::String(s.to_string()));
    }
    if let Some(arr) = zval.array() {
        let is_sequential = arr.iter().enumerate().all(|(i, (key, _))| {
            matches!(key, ext_php_rs::types::ArrayKey::Long(n) if n == i as i64)
        });
        if is_sequential {
            let mut bson_arr = Vec::new();
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
            let _ = zval.set_string(&oid.to_hex(), false);
        }
        Bson::DateTime(dt) => {
            zval.set_long(dt.timestamp_millis());
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
            let _ = zval.set_string(&String::from_utf8_lossy(&bin.bytes), false);
        }
        Bson::RegularExpression(re) => {
            let s = format!("/{}/{}", re.pattern, re.options);
            let _ = zval.set_string(&s, false);
        }
        Bson::Timestamp(ts) => {
            zval.set_long(ts.time as i64);
        }
        Bson::Decimal128(d) => {
            let _ = zval.set_string(&d.to_string(), false);
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
