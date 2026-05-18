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
    "0.2.0".to_string()
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
    _opts: Option<&Zval>,
) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let filter_doc = bson_convert::php_to_doc(filter).map_err(|e| PhpException::default(e))?;
    let update_doc = bson_convert::php_to_doc(update).map_err(|e| PhpException::default(e))?;
    let result = ops::update_one(&client, db, col, filter_doc, update_doc)
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
    _opts: Option<&Zval>,
) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let filter_doc = bson_convert::php_to_doc(filter).map_err(|e| PhpException::default(e))?;
    let update_doc = bson_convert::php_to_doc(update).map_err(|e| PhpException::default(e))?;
    let result = ops::update_many(&client, db, col, filter_doc, update_doc)
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
    _opts: Option<&Zval>,
) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let filter_doc = bson_convert::php_to_doc(filter).map_err(|e| PhpException::default(e))?;
    let replacement_doc = bson_convert::php_to_doc(replacement).map_err(|e| PhpException::default(e))?;
    let result = ops::replace_one(&client, db, col, filter_doc, replacement_doc)
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
    _opts: Option<&Zval>,
) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let filter_doc = bson_convert::php_to_doc(filter).map_err(|e| PhpException::default(e))?;
    let update_doc = bson_convert::php_to_doc(update).map_err(|e| PhpException::default(e))?;
    let result = ops::find_one_and_update(&client, db, col, filter_doc, update_doc)
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
    _opts: Option<&Zval>,
) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let filter_doc = bson_convert::php_to_doc(filter).map_err(|e| PhpException::default(e))?;
    let replacement_doc = bson_convert::php_to_doc(replacement).map_err(|e| PhpException::default(e))?;
    let result = ops::find_one_and_replace(&client, db, col, filter_doc, replacement_doc)
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
            if op == "aggregate" {
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
        Some(json) => {
            let mut z = Zval::new();
            z.set_string(&json, false).ok();
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
