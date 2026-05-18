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
    // Eagerly init tokio runtime BEFORE HOOK_ALL is enabled
    coroutine::init_runtime();
    "0.1.0".to_string()
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

// --- CRUD operations ---

#[php_function]
pub fn zealphp_mongodb_find_one(
    pool_id: i64,
    db: &str,
    col: &str,
    filter: &Zval,
    _opts: Option<&Zval>,
) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let filter_doc = bson_convert::php_to_doc(filter).map_err(|e| PhpException::default(e))?;

    let result = ops::find_one(&client, db, col, filter_doc)
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
    _opts: Option<&Zval>,
) -> PhpResult<i64> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let filter_doc = bson_convert::php_to_doc(filter).map_err(|e| PhpException::default(e))?;

    let mongo_cursor = ops::find(&client, db, col, filter_doc)
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
    let _ = ht.insert(
        "inserted_id",
        bson_convert::bson_to_zval(&bson::Bson::from(result.inserted_id)),
    );
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
    _opts: Option<&Zval>,
) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let filter_doc = bson_convert::php_to_doc(filter).map_err(|e| PhpException::default(e))?;
    let update_doc = bson_convert::php_to_doc(update).map_err(|e| PhpException::default(e))?;

    let result = ops::update_one(&client, db, col, filter_doc, update_doc)
        .map_err(|e| PhpException::default(e))?;

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
    Ok(zval)
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

    let mut zval = Zval::new();
    let mut ht = ZendHashTable::new();
    let mut deleted = Zval::new();
    deleted.set_long(result.deleted_count as i64);
    let _ = ht.insert("deleted_count", deleted);
    let mut ack = Zval::new();
    ack.set_bool(true);
    let _ = ht.insert("acknowledged", ack);
    zval.set_hashtable(ht);
    Ok(zval)
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

// --- Cursor operations ---

#[php_function]
pub fn zealphp_mongodb_cursor_next(cursor_id: i64) -> PhpResult<Zval> {
    let result = cursor::next_doc(cursor_id as u64)
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
pub fn zealphp_mongodb_cursor_close(cursor_id: i64) -> PhpResult<()> {
    cursor::remove(cursor_id as u64).map_err(|e| PhpException::default(e))
}

// --- Test function ---
#[php_function]
pub fn zealphp_mongodb_test_new() -> String {
    "test_new_works".to_string()
}

// --- Async API (eventfd-based) ---

#[php_function]
pub fn zealphp_mongodb_find_one_async(
    pool_id: i64,
    db: &str,
    col: &str,
    filter: &Zval,
) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let filter_doc = bson_convert::php_to_doc(filter).map_err(|e| PhpException::default(e))?;

    let task_id = async_store::new_task_id();
    let efd = coroutine::create_eventfd();
    if efd < 0 {
        return Err(PhpException::default("Failed to create eventfd".to_string()));
    }

    coroutine::spawn_find_one(client, db.to_string(), col.to_string(), filter_doc, task_id, efd);

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
pub fn zealphp_mongodb_get_result(task_id: i64) -> PhpResult<Zval> {
    match async_store::take_result(task_id as u64) {
        Some(Some(doc)) => Ok(bson_convert::doc_to_php(&doc)),
        Some(None) => {
            let mut z = Zval::new();
            z.set_null();
            Ok(z)
        }
        None => {
            let mut z = Zval::new();
            z.set_null();
            Ok(z)
        }
    }
}

#[php_function]
pub fn zealphp_mongodb_close_eventfd(fd: i64) -> PhpResult<()> {
    unsafe { libc::close(fd as i32); }
    Ok(())
}

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module
}
