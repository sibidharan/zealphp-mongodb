mod async_ops;
mod async_store;
mod bson_convert;
mod coroutine;
mod cursor;
mod ops;
mod pool;

use ext_php_rs::prelude::*;
use ext_php_rs::types::{ZendHashTable, Zval};

#[php_function]
pub fn zealphp_mongodb_version() -> String {
    coroutine::init_runtime();
    "0.1.1".to_string()
}

#[php_function]
pub fn zealphp_mongodb_in_coroutine() -> bool {
    coroutine::get_cid() >= 0
}

#[php_function]
pub fn zealphp_mongodb_connect(uri: &str) -> PhpResult<i64> {
    pool::connect(uri)
        .map(|id| id as i64)
        .map_err(|e| PhpException::default(e))
}

#[php_function]
pub fn zealphp_mongodb_close(pool_id: i64) -> PhpResult<()> {
    pool::close(pool_id as u64).map_err(|e| PhpException::default(e))
}

#[php_function]
pub fn zealphp_mongodb_list_databases(pool_id: i64) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let names = ops::list_databases(&client).map_err(|e| PhpException::default(e))?;
    let mut zval = Zval::new();
    let mut ht = ZendHashTable::new();
    for (i, name) in names.iter().enumerate() {
        let mut v = Zval::new();
        let _ = v.set_string(name, false);
        let _ = ht.insert_at_index(i as u64, v);
    }
    zval.set_hashtable(ht);
    Ok(zval)
}

// --- Options parsing helpers ---

fn parse_find_options(opts: Option<&Zval>) -> mongodb::options::FindOptions {
    let mut fo = mongodb::options::FindOptions::default();
    if let Some(z) = opts {
        if !z.is_null() {
            if let Some(arr) = z.array() {
                if let Some(v) = arr.get("limit") { if let Some(n) = v.long() { fo.limit = Some(n); } }
                if let Some(v) = arr.get("skip") { if let Some(n) = v.long() { fo.skip = Some(n as u64); } }
                if let Some(v) = arr.get("sort") { if let Ok(d) = bson_convert::php_to_doc(v) { fo.sort = Some(d); } }
                if let Some(v) = arr.get("projection") { if let Ok(d) = bson_convert::php_to_doc(v) { fo.projection = Some(d); } }
            }
        }
    }
    fo
}

fn parse_find_one_options(opts: Option<&Zval>) -> mongodb::options::FindOneOptions {
    let mut fo = mongodb::options::FindOneOptions::default();
    if let Some(z) = opts {
        if !z.is_null() {
            if let Some(arr) = z.array() {
                if let Some(v) = arr.get("sort") { if let Ok(d) = bson_convert::php_to_doc(v) { fo.sort = Some(d); } }
                if let Some(v) = arr.get("projection") { if let Ok(d) = bson_convert::php_to_doc(v) { fo.projection = Some(d); } }
            }
        }
    }
    fo
}

fn parse_update_options(opts: Option<&Zval>) -> mongodb::options::UpdateOptions {
    let mut uo = mongodb::options::UpdateOptions::default();
    if let Some(z) = opts {
        if !z.is_null() {
            if let Some(arr) = z.array() {
                if let Some(v) = arr.get("upsert") { if let Some(b) = v.bool() { uo.upsert = Some(b); } }
            }
        }
    }
    uo
}

fn parse_replace_options(opts: Option<&Zval>) -> mongodb::options::ReplaceOptions {
    let mut ro = mongodb::options::ReplaceOptions::default();
    if let Some(z) = opts {
        if !z.is_null() {
            if let Some(arr) = z.array() {
                if let Some(v) = arr.get("upsert") { if let Some(b) = v.bool() { ro.upsert = Some(b); } }
            }
        }
    }
    ro
}

fn parse_find_one_and_update_options(opts: Option<&Zval>) -> mongodb::options::FindOneAndUpdateOptions {
    let mut fo = mongodb::options::FindOneAndUpdateOptions::default();
    if let Some(z) = opts {
        if !z.is_null() {
            if let Some(arr) = z.array() {
                if let Some(v) = arr.get("returnDocument") {
                    if let Some(n) = v.long() {
                        if n == 2 { fo.return_document = Some(mongodb::options::ReturnDocument::After); }
                    }
                }
                if let Some(v) = arr.get("projection") { if let Ok(d) = bson_convert::php_to_doc(v) { fo.projection = Some(d); } }
                if let Some(v) = arr.get("upsert") { if let Some(b) = v.bool() { fo.upsert = Some(b); } }
            }
        }
    }
    fo
}

fn parse_find_one_and_replace_options(opts: Option<&Zval>) -> mongodb::options::FindOneAndReplaceOptions {
    let mut fo = mongodb::options::FindOneAndReplaceOptions::default();
    if let Some(z) = opts {
        if !z.is_null() {
            if let Some(arr) = z.array() {
                if let Some(v) = arr.get("returnDocument") {
                    if let Some(n) = v.long() {
                        if n == 2 { fo.return_document = Some(mongodb::options::ReturnDocument::After); }
                    }
                }
                if let Some(v) = arr.get("upsert") { if let Some(b) = v.bool() { fo.upsert = Some(b); } }
            }
        }
    }
    fo
}

// --- CRUD operations ---

#[php_function]
pub fn zealphp_mongodb_find_one(
    pool_id: i64,
    db: &str,
    col: &str,
    filter: &Zval,
    opts: Option<&Zval>,
) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let filter_doc = bson_convert::php_to_doc(filter).map_err(|e| PhpException::default(e))?;
    let fo = parse_find_one_options(opts);
    let result = ops::find_one_with_options(&client, db, col, filter_doc, fo)
        .map_err(|e| PhpException::default(e))?;
    match result {
        Some(doc) => Ok(bson_convert::doc_to_php(&doc)),
        None => {
            let mut z = Zval::new();
            z.set_null();
            Ok(z)
        }
    }
}

#[php_function]
pub fn zealphp_mongodb_find(
    pool_id: i64,
    db: &str,
    col: &str,
    filter: &Zval,
    opts: Option<&Zval>,
) -> PhpResult<i64> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let filter_doc = bson_convert::php_to_doc(filter).map_err(|e| PhpException::default(e))?;
    let fo = parse_find_options(opts);
    let mongo_cursor = ops::find_with_options(&client, db, col, filter_doc, fo)
        .map_err(|e| PhpException::default(e))?;
    let cursor_id = cursor::store_cursor(mongo_cursor);
    Ok(cursor_id as i64)
}

#[php_function]
pub fn zealphp_mongodb_insert_one(
    pool_id: i64,
    db: &str,
    col: &str,
    document: &Zval,
    _opts: Option<&Zval>,
) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let doc = bson_convert::php_to_doc(document).map_err(|e| PhpException::default(e))?;
    let result = ops::insert_one(&client, db, col, doc)
        .map_err(|e| PhpException::default(e))?;

    let mut zval = Zval::new();
    let mut ht = ZendHashTable::new();
    let _ = ht.insert("inserted_id", bson_convert::bson_to_zval(&bson::Bson::from(result.inserted_id)));
    let mut ack = Zval::new();
    ack.set_bool(true);
    let _ = ht.insert("acknowledged", ack);
    let mut count = Zval::new();
    count.set_long(1);
    let _ = ht.insert("inserted_count", count);
    zval.set_hashtable(ht);
    Ok(zval)
}

#[php_function]
pub fn zealphp_mongodb_update_one(
    pool_id: i64,
    db: &str,
    col: &str,
    filter: &Zval,
    update: &Zval,
    opts: Option<&Zval>,
) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let filter_doc = bson_convert::php_to_doc(filter).map_err(|e| PhpException::default(e))?;
    let update_doc = bson_convert::php_to_doc(update).map_err(|e| PhpException::default(e))?;
    let uo = parse_update_options(opts);
    let result = ops::update_one_with_options(&client, db, col, filter_doc, update_doc, uo)
        .map_err(|e| PhpException::default(e))?;
    Ok(update_result_to_zval(&result))
}

#[php_function]
pub fn zealphp_mongodb_update_many(
    pool_id: i64,
    db: &str,
    col: &str,
    filter: &Zval,
    update: &Zval,
    opts: Option<&Zval>,
) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let filter_doc = bson_convert::php_to_doc(filter).map_err(|e| PhpException::default(e))?;
    let update_doc = bson_convert::php_to_doc(update).map_err(|e| PhpException::default(e))?;
    let uo = parse_update_options(opts);
    let result = ops::update_many_with_options(&client, db, col, filter_doc, update_doc, uo)
        .map_err(|e| PhpException::default(e))?;
    Ok(update_result_to_zval(&result))
}

#[php_function]
pub fn zealphp_mongodb_delete_one(
    pool_id: i64,
    db: &str,
    col: &str,
    filter: &Zval,
    _opts: Option<&Zval>,
) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let filter_doc = bson_convert::php_to_doc(filter).map_err(|e| PhpException::default(e))?;
    let result = ops::delete_one(&client, db, col, filter_doc)
        .map_err(|e| PhpException::default(e))?;
    Ok(delete_result_to_zval(&result))
}

#[php_function]
pub fn zealphp_mongodb_delete_many(
    pool_id: i64,
    db: &str,
    col: &str,
    filter: &Zval,
    _opts: Option<&Zval>,
) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let filter_doc = bson_convert::php_to_doc(filter).map_err(|e| PhpException::default(e))?;
    let result = ops::delete_many(&client, db, col, filter_doc)
        .map_err(|e| PhpException::default(e))?;
    Ok(delete_result_to_zval(&result))
}

#[php_function]
pub fn zealphp_mongodb_replace_one(
    pool_id: i64,
    db: &str,
    col: &str,
    filter: &Zval,
    replacement: &Zval,
    opts: Option<&Zval>,
) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let filter_doc = bson_convert::php_to_doc(filter).map_err(|e| PhpException::default(e))?;
    let replacement_doc = bson_convert::php_to_doc(replacement).map_err(|e| PhpException::default(e))?;
    let ro = parse_replace_options(opts);
    let result = ops::replace_one_with_options(&client, db, col, filter_doc, replacement_doc, ro)
        .map_err(|e| PhpException::default(e))?;
    Ok(update_result_to_zval(&result))
}

#[php_function]
pub fn zealphp_mongodb_count_documents(
    pool_id: i64,
    db: &str,
    col: &str,
    filter: &Zval,
    _opts: Option<&Zval>,
) -> PhpResult<i64> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let filter_doc = bson_convert::php_to_doc(filter).map_err(|e| PhpException::default(e))?;
    let count = ops::count_documents(&client, db, col, filter_doc)
        .map_err(|e| PhpException::default(e))?;
    Ok(count as i64)
}

#[php_function]
pub fn zealphp_mongodb_aggregate(
    pool_id: i64,
    db: &str,
    col: &str,
    pipeline: &Zval,
    _opts: Option<&Zval>,
) -> PhpResult<i64> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let pipeline_docs =
        bson_convert::php_to_pipeline(pipeline).map_err(|e| PhpException::default(e))?;
    let mongo_cursor = ops::aggregate(&client, db, col, pipeline_docs)
        .map_err(|e| PhpException::default(e))?;
    let cursor_id = cursor::store_cursor(mongo_cursor);
    Ok(cursor_id as i64)
}

#[php_function]
pub fn zealphp_mongodb_distinct(
    pool_id: i64,
    db: &str,
    col: &str,
    field_name: &str,
    filter: &Zval,
    _opts: Option<&Zval>,
) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let filter_doc = bson_convert::php_to_doc(filter).map_err(|e| PhpException::default(e))?;
    let values = ops::distinct(&client, db, col, field_name, filter_doc)
        .map_err(|e| PhpException::default(e))?;

    let mut zval = Zval::new();
    let mut ht = ZendHashTable::new();
    for (i, val) in values.iter().enumerate() {
        let _ = ht.insert_at_index(i as u64, bson_convert::bson_to_zval(val));
    }
    zval.set_hashtable(ht);
    Ok(zval)
}

#[php_function]
pub fn zealphp_mongodb_find_one_and_update(
    pool_id: i64,
    db: &str,
    col: &str,
    filter: &Zval,
    update: &Zval,
    opts: Option<&Zval>,
) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let filter_doc = bson_convert::php_to_doc(filter).map_err(|e| PhpException::default(e))?;
    let update_doc = bson_convert::php_to_doc(update).map_err(|e| PhpException::default(e))?;
    let fo = parse_find_one_and_update_options(opts);
    let result = ops::find_one_and_update_with_options(&client, db, col, filter_doc, update_doc, fo)
        .map_err(|e| PhpException::default(e))?;
    match result {
        Some(doc) => Ok(bson_convert::doc_to_php(&doc)),
        None => {
            let mut z = Zval::new();
            z.set_null();
            Ok(z)
        }
    }
}

#[php_function]
pub fn zealphp_mongodb_find_one_and_delete(
    pool_id: i64,
    db: &str,
    col: &str,
    filter: &Zval,
    _opts: Option<&Zval>,
) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let filter_doc = bson_convert::php_to_doc(filter).map_err(|e| PhpException::default(e))?;
    let result = ops::find_one_and_delete(&client, db, col, filter_doc)
        .map_err(|e| PhpException::default(e))?;
    match result {
        Some(doc) => Ok(bson_convert::doc_to_php(&doc)),
        None => {
            let mut z = Zval::new();
            z.set_null();
            Ok(z)
        }
    }
}

#[php_function]
pub fn zealphp_mongodb_find_one_and_replace(
    pool_id: i64,
    db: &str,
    col: &str,
    filter: &Zval,
    replacement: &Zval,
    opts: Option<&Zval>,
) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let filter_doc = bson_convert::php_to_doc(filter).map_err(|e| PhpException::default(e))?;
    let replacement_doc = bson_convert::php_to_doc(replacement).map_err(|e| PhpException::default(e))?;
    let fo = parse_find_one_and_replace_options(opts);
    let result = ops::find_one_and_replace_with_options(&client, db, col, filter_doc, replacement_doc, fo)
        .map_err(|e| PhpException::default(e))?;
    match result {
        Some(doc) => Ok(bson_convert::doc_to_php(&doc)),
        None => {
            let mut z = Zval::new();
            z.set_null();
            Ok(z)
        }
    }
}

#[php_function]
pub fn zealphp_mongodb_create_index(
    pool_id: i64,
    db: &str,
    col: &str,
    keys: &Zval,
    opts: Option<&Zval>,
) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let keys_doc = bson_convert::php_to_doc(keys).map_err(|e| PhpException::default(e))?;
    let opts_doc = match opts {
        Some(z) if !z.is_null() => Some(bson_convert::php_to_doc(z).map_err(|e| PhpException::default(e))?),
        _ => None,
    };
    let name = ops::create_index(&client, db, col, keys_doc, opts_doc)
        .map_err(|e| PhpException::default(e))?;
    let mut z = Zval::new();
    let _ = z.set_string(&name, false);
    Ok(z)
}

// --- Cursor operations ---

#[php_function]
pub fn zealphp_mongodb_cursor_next(cursor_id: i64) -> PhpResult<Zval> {
    let result = cursor::next_doc(cursor_id as u64).map_err(|e| PhpException::default(e))?;
    match result {
        Some(doc) => Ok(bson_convert::doc_to_php(&doc)),
        None => {
            let mut z = Zval::new();
            z.set_null();
            Ok(z)
        }
    }
}

#[php_function]
pub fn zealphp_mongodb_cursor_close(cursor_id: i64) -> PhpResult<()> {
    cursor::remove(cursor_id as u64).map_err(|e| PhpException::default(e))
}

#[php_function]
pub fn zealphp_mongodb_cursor_to_array(cursor_id: i64) -> PhpResult<Zval> {
    let docs = cursor::drain_to_vec(cursor_id as u64).map_err(|e| PhpException::default(e))?;
    let mut zval = Zval::new();
    let mut ht = ZendHashTable::new();
    for (i, doc) in docs.iter().enumerate() {
        let _ = ht.insert_at_index(i as u64, bson_convert::doc_to_php(doc));
    }
    zval.set_hashtable(ht);
    Ok(zval)
}

#[php_function]
pub fn zealphp_mongodb_cursor_next_async(cursor_id: i64) -> PhpResult<Zval> {
    let cursor_arc = {
        let cursors = cursor::get_store().read().unwrap();
        cursors
            .get(&(cursor_id as u64))
            .cloned()
            .ok_or_else(|| PhpException::default(format!("Invalid cursor ID: {}", cursor_id)))?
    };

    let task_id = async_store::new_task_id();
    let efd = coroutine::create_eventfd();
    if efd < 0 {
        return Err(PhpException::default("Failed to create eventfd".to_string()));
    }

    coroutine::spawn_batch_task(
        async move {
            use futures::StreamExt;
            let mut cur = cursor_arc.lock().await;
            match cur.next().await {
                Some(Ok(doc)) => async_store::BatchResult {
                    docs: vec![doc], exhausted: false, cursor_id: None, error: None,
                },
                Some(Err(e)) => async_store::BatchResult {
                    docs: Vec::new(), exhausted: true, cursor_id: None, error: Some(e.to_string()),
                },
                None => async_store::BatchResult {
                    docs: Vec::new(), exhausted: true, cursor_id: None, error: None,
                },
            }
        },
        task_id,
        efd,
    );

    let mut result = Zval::new();
    let mut ht = ZendHashTable::new();
    let mut efd_zval = Zval::new();
    efd_zval.set_long(efd as i64);
    let _ = ht.insert("efd", efd_zval);
    let mut tid_zval = Zval::new();
    tid_zval.set_long(task_id as i64);
    let _ = ht.insert("task_id", tid_zval);
    result.set_hashtable(ht);
    Ok(result)
}

#[php_function]
pub fn zealphp_mongodb_cursor_next_batch_async(cursor_id: i64, batch_size: i64) -> PhpResult<Zval> {
    let cursor_arc = {
        let cursors = cursor::get_store().read().unwrap();
        cursors
            .get(&(cursor_id as u64))
            .cloned()
            .ok_or_else(|| PhpException::default(format!("Invalid cursor ID: {}", cursor_id)))?
    };

    let batch = (batch_size.max(1) as usize).min(1000);
    let task_id = async_store::new_task_id();
    let efd = coroutine::create_eventfd();
    if efd < 0 {
        return Err(PhpException::default("Failed to create eventfd".to_string()));
    }

    coroutine::spawn_batch_task(
        async move {
            use futures::StreamExt;
            let mut cur = cursor_arc.lock().await;
            let mut docs = Vec::with_capacity(batch);
            let mut exhausted = false;
            for _ in 0..batch {
                match cur.next().await {
                    Some(Ok(doc)) => docs.push(doc),
                    Some(Err(e)) => {
                        return async_store::BatchResult {
                            docs: Vec::new(), exhausted: true, cursor_id: None,
                            error: Some(e.to_string()),
                        };
                    }
                    None => { exhausted = true; break; }
                }
            }
            async_store::BatchResult { docs, exhausted, cursor_id: None, error: None }
        },
        task_id,
        efd,
    );

    let mut result = Zval::new();
    let mut ht = ZendHashTable::new();
    let mut efd_zval = Zval::new();
    efd_zval.set_long(efd as i64);
    let _ = ht.insert("efd", efd_zval);
    let mut tid_zval = Zval::new();
    tid_zval.set_long(task_id as i64);
    let _ = ht.insert("task_id", tid_zval);
    result.set_hashtable(ht);
    Ok(result)
}

#[php_function]
pub fn zealphp_mongodb_batch_result(task_id: i64) -> PhpResult<Zval> {
    let batch = async_store::take_batch(task_id as u64)
        .ok_or_else(|| PhpException::default(format!("No batch result for task {}", task_id)))?;

    if let Some(err) = batch.error {
        return Err(PhpException::default(format!("Cursor error: {}", err)));
    }

    let mut zval = Zval::new();
    let mut ht = ZendHashTable::new();

    let mut docs_ht = ZendHashTable::new();
    for (i, doc) in batch.docs.iter().enumerate() {
        let _ = docs_ht.insert_at_index(i as u64, bson_convert::doc_to_php(doc));
    }
    let mut docs_zval = Zval::new();
    docs_zval.set_hashtable(docs_ht);
    let _ = ht.insert("docs", docs_zval);

    let mut ex = Zval::new();
    ex.set_bool(batch.exhausted);
    let _ = ht.insert("exhausted", ex);

    if let Some(cid) = batch.cursor_id {
        let mut cid_zval = Zval::new();
        cid_zval.set_long(cid as i64);
        let _ = ht.insert("cursor_id", cid_zval);
    }

    zval.set_hashtable(ht);
    Ok(zval)
}

#[php_function]
pub fn zealphp_mongodb_find_cursor_async(
    pool_id: i64,
    db: &str,
    col: &str,
    filter: &Zval,
    opts: Option<&Zval>,
) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let filter_doc = bson_convert::php_to_doc(filter).map_err(|e| PhpException::default(e))?;
    let find_opts = parse_find_options(opts);

    let task_id = async_store::new_task_id();
    let efd = coroutine::create_eventfd();
    if efd < 0 {
        return Err(PhpException::default("Failed to create eventfd".to_string()));
    }

    let db_s = db.to_string();
    let col_s = col.to_string();

    coroutine::spawn_batch_task(
        async move {
            use futures::StreamExt;
            let collection = client.database(&db_s).collection::<bson::Document>(&col_s);
            match collection.find(filter_doc).with_options(find_opts).await {
                Ok(mut cursor) => {
                    let mut docs = Vec::with_capacity(100);
                    let mut exhausted = false;
                    for _ in 0..100 {
                        match cursor.next().await {
                            Some(Ok(doc)) => docs.push(doc),
                            Some(Err(e)) => {
                                return async_store::BatchResult {
                                    docs: Vec::new(), exhausted: true, cursor_id: None,
                                    error: Some(e.to_string()),
                                };
                            }
                            None => { exhausted = true; break; }
                        }
                    }
                    let cursor_id = if exhausted { None } else { Some(cursor::store_cursor(cursor)) };
                    async_store::BatchResult { docs, exhausted, cursor_id, error: None }
                }
                Err(e) => async_store::BatchResult {
                    docs: Vec::new(), exhausted: true, cursor_id: None,
                    error: Some(e.to_string()),
                },
            }
        },
        task_id,
        efd,
    );

    let mut result = Zval::new();
    let mut ht = ZendHashTable::new();
    let mut efd_zval = Zval::new();
    efd_zval.set_long(efd as i64);
    let _ = ht.insert("efd", efd_zval);
    let mut tid_zval = Zval::new();
    tid_zval.set_long(task_id as i64);
    let _ = ht.insert("task_id", tid_zval);
    result.set_hashtable(ht);
    Ok(result)
}

#[php_function]
pub fn zealphp_mongodb_aggregate_cursor_async(
    pool_id: i64,
    db: &str,
    col: &str,
    pipeline: &Zval,
) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let pipeline_docs = bson_convert::php_to_pipeline(pipeline).map_err(|e| PhpException::default(e))?;

    let task_id = async_store::new_task_id();
    let efd = coroutine::create_eventfd();
    if efd < 0 {
        return Err(PhpException::default("Failed to create eventfd".to_string()));
    }

    let db_s = db.to_string();
    let col_s = col.to_string();

    coroutine::spawn_batch_task(
        async move {
            use futures::StreamExt;
            let collection = client.database(&db_s).collection::<bson::Document>(&col_s);
            match collection.aggregate(pipeline_docs).await {
                Ok(mut cursor) => {
                    let mut docs = Vec::with_capacity(100);
                    let mut exhausted = false;
                    for _ in 0..100 {
                        match cursor.next().await {
                            Some(Ok(doc)) => docs.push(doc),
                            Some(Err(e)) => {
                                return async_store::BatchResult {
                                    docs: Vec::new(), exhausted: true, cursor_id: None,
                                    error: Some(e.to_string()),
                                };
                            }
                            None => { exhausted = true; break; }
                        }
                    }
                    let cursor_id = if exhausted { None } else { Some(cursor::store_cursor(cursor)) };
                    async_store::BatchResult { docs, exhausted, cursor_id, error: None }
                }
                Err(e) => async_store::BatchResult {
                    docs: Vec::new(), exhausted: true, cursor_id: None,
                    error: Some(e.to_string()),
                },
            }
        },
        task_id,
        efd,
    );

    let mut result = Zval::new();
    let mut ht = ZendHashTable::new();
    let mut efd_zval = Zval::new();
    efd_zval.set_long(efd as i64);
    let _ = ht.insert("efd", efd_zval);
    let mut tid_zval = Zval::new();
    tid_zval.set_long(task_id as i64);
    let _ = ht.insert("task_id", tid_zval);
    result.set_hashtable(ht);
    Ok(result)
}

// --- New collection/database operations ---

#[php_function]
pub fn zealphp_mongodb_insert_many(
    pool_id: i64, db: &str, col: &str, documents: &Zval, _opts: Option<&Zval>,
) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let docs_arr = documents.array().ok_or_else(|| PhpException::default("Expected array of documents".to_string()))?;
    let mut docs = Vec::new();
    for (_, val) in docs_arr.iter() {
        docs.push(bson_convert::php_to_doc(val).map_err(|e| PhpException::default(e))?);
    }
    let result = ops::insert_many(&client, db, col, docs).map_err(|e| PhpException::default(e))?;

    let mut zval = Zval::new();
    let mut ht = ZendHashTable::new();
    let mut ids_ht = ZendHashTable::new();
    for (i, (_k, id)) in result.inserted_ids.iter().enumerate() {
        let _ = ids_ht.insert_at_index(i as u64, bson_convert::bson_to_zval(id));
    }
    let mut ids_z = Zval::new();
    ids_z.set_hashtable(ids_ht);
    let _ = ht.insert("inserted_ids", ids_z);
    let mut count = Zval::new();
    count.set_long(result.inserted_ids.len() as i64);
    let _ = ht.insert("inserted_count", count);
    let mut ack = Zval::new();
    ack.set_bool(true);
    let _ = ht.insert("acknowledged", ack);
    zval.set_hashtable(ht);
    Ok(zval)
}

#[php_function]
pub fn zealphp_mongodb_estimated_document_count(pool_id: i64, db: &str, col: &str) -> PhpResult<i64> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let count = ops::estimated_document_count(&client, db, col).map_err(|e| PhpException::default(e))?;
    Ok(count as i64)
}

#[php_function]
pub fn zealphp_mongodb_drop_collection(pool_id: i64, db: &str, col: &str) -> PhpResult<()> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    ops::drop_collection(&client, db, col).map_err(|e| PhpException::default(e))
}

#[php_function]
pub fn zealphp_mongodb_list_indexes(pool_id: i64, db: &str, col: &str) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let indexes = ops::list_indexes(&client, db, col).map_err(|e| PhpException::default(e))?;
    let mut zval = Zval::new();
    let mut ht = ZendHashTable::new();
    for (i, doc) in indexes.iter().enumerate() {
        let _ = ht.insert_at_index(i as u64, bson_convert::doc_to_php(doc));
    }
    zval.set_hashtable(ht);
    Ok(zval)
}

#[php_function]
pub fn zealphp_mongodb_drop_index(pool_id: i64, db: &str, col: &str, index_name: &str) -> PhpResult<()> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    ops::drop_index(&client, db, col, index_name).map_err(|e| PhpException::default(e))
}

#[php_function]
pub fn zealphp_mongodb_drop_indexes(pool_id: i64, db: &str, col: &str) -> PhpResult<()> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    ops::drop_indexes(&client, db, col).map_err(|e| PhpException::default(e))
}

#[php_function]
pub fn zealphp_mongodb_run_command(pool_id: i64, db: &str, command: &Zval) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let cmd = bson_convert::php_to_doc(command).map_err(|e| PhpException::default(e))?;
    let result = ops::run_command(&client, db, cmd).map_err(|e| PhpException::default(e))?;
    Ok(bson_convert::doc_to_php(&result))
}

#[php_function]
pub fn zealphp_mongodb_create_collection(pool_id: i64, db: &str, name: &str) -> PhpResult<()> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    ops::create_collection(&client, db, name).map_err(|e| PhpException::default(e))
}

#[php_function]
pub fn zealphp_mongodb_drop_database(pool_id: i64, db: &str) -> PhpResult<()> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    ops::drop_database(&client, db).map_err(|e| PhpException::default(e))
}

#[php_function]
pub fn zealphp_mongodb_list_collection_names(pool_id: i64, db: &str) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let names = ops::list_collection_names(&client, db).map_err(|e| PhpException::default(e))?;
    let mut zval = Zval::new();
    let mut ht = ZendHashTable::new();
    for (i, name) in names.iter().enumerate() {
        let mut v = Zval::new();
        let _ = v.set_string(name, false);
        let _ = ht.insert_at_index(i as u64, v);
    }
    zval.set_hashtable(ht);
    Ok(zval)
}

// --- CENTRALIZED ASYNC API ---

#[php_function]
pub fn zealphp_mongodb_exec_async(
    pool_id: i64,
    db: &str,
    col: &str,
    op: &str,
    filter_or_doc: &Zval,
    update_or_pipeline: Option<&Zval>,
) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let filter_doc = if !filter_or_doc.is_null() {
        Some(bson_convert::php_to_doc(filter_or_doc).map_err(|e| PhpException::default(e))?)
    } else {
        None
    };
    let update_docs = match update_or_pipeline {
        Some(z) if !z.is_null() => {
            if op == "aggregate" || op == "aggregate_cursor" {
                Some(bson_convert::php_to_pipeline(z).map_err(|e| PhpException::default(e))?)
            } else {
                Some(vec![bson_convert::php_to_doc(z).map_err(|e| PhpException::default(e))?])
            }
        }
        _ => None,
    };

    let task_id = async_store::new_task_id();
    let efd = coroutine::create_eventfd();
    if efd < 0 {
        return Err(PhpException::default("Failed to create eventfd".to_string()));
    }

    let db_s = db.to_string();
    let col_s = col.to_string();
    let op_s = op.to_string();

    coroutine::spawn_task(
        async move {
            async_ops::exec_async(client, db_s, col_s, op_s, filter_doc, update_docs).await
        },
        task_id,
        efd,
    );

    let mut result = Zval::new();
    let mut ht = ZendHashTable::new();
    let mut efd_zval = Zval::new();
    efd_zval.set_long(efd as i64);
    let _ = ht.insert("efd", efd_zval);
    let mut tid_zval = Zval::new();
    tid_zval.set_long(task_id as i64);
    let _ = ht.insert("task_id", tid_zval);
    result.set_hashtable(ht);
    Ok(result)
}

#[php_function]
pub fn zealphp_mongodb_async_result(task_id: i64) -> PhpResult<Zval> {
    match async_store::take_result(task_id as u64) {
        Some(result) => match result {
            async_store::AsyncResult::Doc(Some(doc)) => Ok(bson_convert::doc_to_php(&doc)),
            async_store::AsyncResult::Doc(None) => {
                let mut z = Zval::new();
                z.set_null();
                Ok(z)
            }
            async_store::AsyncResult::Scalar(doc) => Ok(bson_convert::doc_to_php(&doc)),
            async_store::AsyncResult::Values(vals) => {
                let mut zval = Zval::new();
                let mut ht = ZendHashTable::new();
                for (i, val) in vals.iter().enumerate() {
                    let _ = ht.insert_at_index(i as u64, bson_convert::bson_to_zval(val));
                }
                zval.set_hashtable(ht);
                Ok(zval)
            }
            async_store::AsyncResult::Docs(docs) => {
                let mut zval = Zval::new();
                let mut ht = ZendHashTable::new();
                for (i, doc) in docs.iter().enumerate() {
                    let _ = ht.insert_at_index(i as u64, bson_convert::doc_to_php(doc));
                }
                zval.set_hashtable(ht);
                Ok(zval)
            }
            async_store::AsyncResult::Error(msg) => {
                Err(PhpException::default(msg))
            }
        },
        None => {
            let mut z = Zval::new();
            z.set_null();
            Ok(z)
        }
    }
}

#[php_function]
pub fn zealphp_mongodb_close_efd(fd: i64) -> PhpResult<()> {
    unsafe { libc::close(fd as i32); }
    Ok(())
}

// --- Helper functions ---

fn update_result_to_zval(result: &mongodb::results::UpdateResult) -> Zval {
    let mut zval = Zval::new();
    let mut ht = ZendHashTable::new();
    let mut matched = Zval::new();
    matched.set_long(result.matched_count as i64);
    let _ = ht.insert("matched_count", matched);
    let mut modified = Zval::new();
    modified.set_long(result.modified_count as i64);
    let _ = ht.insert("modified_count", modified);
    let mut ack = Zval::new();
    ack.set_bool(true);
    let _ = ht.insert("acknowledged", ack);
    zval.set_hashtable(ht);
    zval
}

fn delete_result_to_zval(result: &mongodb::results::DeleteResult) -> Zval {
    let mut zval = Zval::new();
    let mut ht = ZendHashTable::new();
    let mut deleted = Zval::new();
    deleted.set_long(result.deleted_count as i64);
    let _ = ht.insert("deleted_count", deleted);
    let mut ack = Zval::new();
    ack.set_bool(true);
    let _ = ht.insert("acknowledged", ack);
    zval.set_hashtable(ht);
    zval
}

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module
}
