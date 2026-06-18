mod async_ops;
mod async_store;
mod bson_convert;
mod change_stream;
mod gridfs;
mod coroutine;
mod cursor;
mod errconv;
mod ops;
mod ops_session;
mod pool;
mod session;

use ext_php_rs::prelude::*;
use ext_php_rs::types::{ZendHashTable, Zval};

#[php_function]
pub fn zealphp_mongodb_version() -> String {
    coroutine::init_runtime();
    env!("CARGO_PKG_VERSION").to_string()
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
                if let Some(v) = arr.get("arrayFilters") { if let Ok(f) = bson_convert::php_to_pipeline(v) { uo.array_filters = Some(f); } }
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
                if let Some(v) = arr.get("sort") { if let Ok(d) = bson_convert::php_to_doc(v) { fo.sort = Some(d); } }
                if let Some(v) = arr.get("arrayFilters") { if let Ok(f) = bson_convert::php_to_pipeline(v) { fo.array_filters = Some(f); } }
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
                if let Some(v) = arr.get("sort") { if let Ok(d) = bson_convert::php_to_doc(v) { fo.sort = Some(d); } }
                if let Some(v) = arr.get("projection") { if let Ok(d) = bson_convert::php_to_doc(v) { fo.projection = Some(d); } }
            }
        }
    }
    fo
}


/// Optional registered-session handle riding the op's options array as the
/// internal `__session` key (set by the PHP wrapper when the caller passes
/// `['session' => $session]`). Keying off the EXISTING opts array keeps every
/// function arity unchanged (the ext-php-rs trailing-Option<&Zval> by-ref bug
/// from issue #3 never enters the picture).
fn parse_session(opts: Option<&Zval>) -> PhpResult<Option<session::SharedSession>> {
    let sid = opts
        .and_then(|z| z.array())
        .and_then(|arr| arr.get("__session"))
        .and_then(|v| v.long());
    session::resolve(sid).map_err(PhpException::default)
}

// --- Sessions / transactions ---

#[php_function]
pub fn zealphp_mongodb_session_start(pool_id: i64, opts: Option<&Zval>) -> PhpResult<i64> {
    let causal = opts
        .and_then(|z| z.array())
        .and_then(|arr| arr.get("causalConsistency"))
        .and_then(|v| v.bool());
    session::start(pool_id as u64, causal)
        .map(|id| id as i64)
        .map_err(PhpException::default)
}

#[php_function]
pub fn zealphp_mongodb_session_start_transaction(session_id: i64, opts: Option<&Zval>) -> PhpResult<()> {
    let max_commit_time_ms = opts
        .and_then(|z| z.array())
        .and_then(|arr| arr.get("maxCommitTimeMS"))
        .and_then(|v| v.long())
        .map(|n| n as u64);
    session::start_transaction(session_id as u64, session::TxnOptions { max_commit_time_ms })
        .map_err(PhpException::default)
}

#[php_function]
pub fn zealphp_mongodb_session_commit_transaction(session_id: i64) -> PhpResult<()> {
    session::commit_transaction(session_id as u64).map_err(PhpException::default)
}

#[php_function]
pub fn zealphp_mongodb_session_abort_transaction(session_id: i64) -> PhpResult<()> {
    session::abort_transaction(session_id as u64).map_err(PhpException::default)
}

#[php_function]
pub fn zealphp_mongodb_session_end(session_id: i64) {
    session::end(session_id as u64);
}

#[php_function]
pub fn zealphp_mongodb_session_lsid(session_id: i64) -> PhpResult<Zval> {
    let doc = session::logical_session_id(session_id as u64).map_err(PhpException::default)?;
    Ok(bson_convert::doc_to_php(&doc))
}

#[php_function]
pub fn zealphp_mongodb_session_cluster_time(session_id: i64) -> PhpResult<Zval> {
    match session::cluster_time(session_id as u64).map_err(PhpException::default)? {
        Some(doc) => Ok(bson_convert::doc_to_php(&doc)),
        None => {
            let mut z = Zval::new();
            z.set_null();
            Ok(z)
        }
    }
}

#[php_function]
pub fn zealphp_mongodb_session_operation_time(session_id: i64) -> PhpResult<Zval> {
    match session::operation_time(session_id as u64).map_err(PhpException::default)? {
        Some((t, i)) => {
            let mut z = Zval::new();
            let mut ht = ZendHashTable::new();
            let mut tv = Zval::new();
            tv.set_long(t as i64);
            let _ = ht.insert("t", tv);
            let mut iv = Zval::new();
            iv.set_long(i as i64);
            let _ = ht.insert("i", iv);
            z.set_hashtable(ht);
            Ok(z)
        }
        None => {
            let mut z = Zval::new();
            z.set_null();
            Ok(z)
        }
    }
}


// --- Change streams (watch) ---

/// db/col: empty string = unscoped ("" db -> client-level watch).
#[php_function]
pub fn zealphp_mongodb_watch(
    pool_id: i64,
    db: &str,
    col: &str,
    pipeline: &Zval,
    opts: Option<&Zval>,
) -> PhpResult<i64> {
    let pipeline_docs =
        bson_convert::php_to_pipeline(pipeline).map_err(PhpException::default)?;

    let mut watch_opts = change_stream::WatchOptions {
        full_document: None,
        resume_after: None,
        start_after: None,
        max_await_time_ms: None,
        batch_size: None,
    };
    if let Some(arr) = opts.and_then(|z| z.array()) {
        if let Some(v) = arr.get("fullDocument") {
            watch_opts.full_document = v.str().map(str::to_string);
        }
        if let Some(v) = arr.get("resumeAfter") {
            if let Ok(d) = bson_convert::php_to_doc(v) {
                watch_opts.resume_after = Some(d);
            }
        }
        if let Some(v) = arr.get("startAfter") {
            if let Ok(d) = bson_convert::php_to_doc(v) {
                watch_opts.start_after = Some(d);
            }
        }
        if let Some(v) = arr.get("maxAwaitTimeMS") {
            watch_opts.max_await_time_ms = v.long().map(|n| n as u64);
        }
        if let Some(v) = arr.get("batchSize") {
            watch_opts.batch_size = v.long().map(|n| n as u32);
        }
    }

    let db_opt = if db.is_empty() { None } else { Some(db) };
    let col_opt = if col.is_empty() { None } else { Some(col) };
    change_stream::watch(pool_id as u64, db_opt, col_opt, pipeline_docs, watch_opts)
        .map(|id| id as i64)
        .map_err(PhpException::default)
}

#[php_function]
pub fn zealphp_mongodb_change_stream_next(stream_id: i64, timeout_ms: i64) -> PhpResult<Zval> {
    let timeout = if timeout_ms < 0 { 0 } else { timeout_ms as u64 };
    match change_stream::next(stream_id as u64, timeout).map_err(PhpException::default)? {
        Some(doc) => Ok(bson_convert::doc_to_php(&doc)),
        None => {
            let mut z = Zval::new();
            z.set_null();
            Ok(z)
        }
    }
}

#[php_function]
pub fn zealphp_mongodb_change_stream_resume_token(stream_id: i64) -> PhpResult<Zval> {
    match change_stream::resume_token(stream_id as u64).map_err(PhpException::default)? {
        Some(doc) => Ok(bson_convert::doc_to_php(&doc)),
        None => {
            let mut z = Zval::new();
            z.set_null();
            Ok(z)
        }
    }
}

#[php_function]
pub fn zealphp_mongodb_change_stream_is_alive(stream_id: i64) -> PhpResult<bool> {
    change_stream::is_alive(stream_id as u64).map_err(PhpException::default)
}

#[php_function]
pub fn zealphp_mongodb_change_stream_close(stream_id: i64) {
    change_stream::kill(stream_id as u64);
}

// --- GridFS ---

/// Extract a BSON value sent from PHP as `['id' => <prepared value>]` —
/// rides php_to_doc so extended-JSON shapes ($oid, …) convert correctly.
fn zval_id_to_bson(id_wrapper: &Zval) -> PhpResult<bson::Bson> {
    let doc = bson_convert::php_to_doc(id_wrapper).map_err(PhpException::default)?;
    doc.get("id")
        .cloned()
        .ok_or_else(|| PhpException::default("missing id".to_string()))
}

#[php_function]
pub fn zealphp_mongodb_gridfs_upload(
    pool_id: i64,
    db: &str,
    bucket: &str,
    filename: &str,
    data: &Zval,
    opts: Option<&Zval>,
) -> PhpResult<Zval> {
    let bytes = data
        .zend_str()
        .map(|zs| zs.as_bytes().to_vec())
        .ok_or_else(|| PhpException::default("data must be a string".to_string()))?;

    let mut metadata = None;
    let mut chunk_size = None;
    let mut preset_id = None;
    if let Some(z) = opts {
        if !z.is_null() {
            // Whole-array conversion so extended-JSON values ($oid in _id,
            // typed metadata fields) come through as proper Bson.
            let opts_doc = bson_convert::php_to_doc(z).map_err(PhpException::default)?;
            if let Some(bson::Bson::Document(meta)) = opts_doc.get("metadata") {
                metadata = Some(meta.clone());
            }
            if let Some(n) = opts_doc.get("chunkSizeBytes").and_then(bson::Bson::as_i64) {
                chunk_size = Some(n as u32);
            } else if let Some(n) = opts_doc.get("chunkSizeBytes").and_then(bson::Bson::as_i32) {
                chunk_size = Some(n as u32);
            }
            preset_id = opts_doc.get("_id").cloned();
        }
    }

    let bucket_opt = if bucket.is_empty() { None } else { Some(bucket) };
    let id = gridfs::upload(pool_id as u64, db, bucket_opt, filename, bytes, metadata, chunk_size, preset_id)
        .map_err(PhpException::default)?;
    Ok(bson_convert::bson_to_zval(&id))
}

#[php_function]
pub fn zealphp_mongodb_gridfs_download(
    pool_id: i64,
    db: &str,
    bucket: &str,
    id_wrapper: &Zval,
) -> PhpResult<Zval> {
    let id = zval_id_to_bson(id_wrapper)?;
    let bucket_opt = if bucket.is_empty() { None } else { Some(bucket) };
    let bytes = gridfs::download(pool_id as u64, db, bucket_opt, id).map_err(PhpException::default)?;
    let mut z = Zval::new();
    z.set_zend_string(ext_php_rs::types::ZendStr::new(&bytes, false));
    Ok(z)
}

#[php_function]
pub fn zealphp_mongodb_gridfs_download_by_name(
    pool_id: i64,
    db: &str,
    bucket: &str,
    filename: &str,
    revision: i64,
) -> PhpResult<Zval> {
    let rev = if revision == i64::MIN { None } else { Some(revision as i32) };
    let bucket_opt = if bucket.is_empty() { None } else { Some(bucket) };
    let bytes = gridfs::download_by_name(pool_id as u64, db, bucket_opt, filename, rev)
        .map_err(PhpException::default)?;
    let mut z = Zval::new();
    z.set_zend_string(ext_php_rs::types::ZendStr::new(&bytes, false));
    Ok(z)
}

#[php_function]
pub fn zealphp_mongodb_gridfs_delete(
    pool_id: i64,
    db: &str,
    bucket: &str,
    id_wrapper: &Zval,
) -> PhpResult<()> {
    let id = zval_id_to_bson(id_wrapper)?;
    let bucket_opt = if bucket.is_empty() { None } else { Some(bucket) };
    gridfs::delete(pool_id as u64, db, bucket_opt, id).map_err(PhpException::default)
}

#[php_function]
pub fn zealphp_mongodb_gridfs_rename(
    pool_id: i64,
    db: &str,
    bucket: &str,
    id_wrapper: &Zval,
    new_filename: &str,
) -> PhpResult<()> {
    let id = zval_id_to_bson(id_wrapper)?;
    let bucket_opt = if bucket.is_empty() { None } else { Some(bucket) };
    gridfs::rename(pool_id as u64, db, bucket_opt, id, new_filename).map_err(PhpException::default)
}

#[php_function]
pub fn zealphp_mongodb_gridfs_drop(pool_id: i64, db: &str, bucket: &str) -> PhpResult<()> {
    let bucket_opt = if bucket.is_empty() { None } else { Some(bucket) };
    gridfs::drop(pool_id as u64, db, bucket_opt).map_err(PhpException::default)
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
    let result = match parse_session(opts)? {
        Some(sess) => ops_session::find_one(&client, db, col, filter_doc, fo, sess),
        None => ops::find_one_with_options(&client, db, col, filter_doc, fo),
    }
    .map_err(|e| PhpException::default(e))?;
    match result {
        Some(doc) => Ok(bson_convert::raw_doc_to_php(&doc)),
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
    opts: Option<&Zval>,
) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let doc = bson_convert::php_to_doc(document).map_err(|e| PhpException::default(e))?;
    let result = match parse_session(opts)? {
        Some(sess) => ops_session::insert_one(&client, db, col, doc, sess),
        None => ops::insert_one(&client, db, col, doc),
    }
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
    let result = match parse_session(opts)? {
        Some(sess) => ops_session::update_one(&client, db, col, filter_doc, update_doc, uo, sess),
        None => ops::update_one_with_options(&client, db, col, filter_doc, update_doc, uo),
    }
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
    let result = match parse_session(opts)? {
        Some(sess) => ops_session::update_many(&client, db, col, filter_doc, update_doc, uo, sess),
        None => ops::update_many_with_options(&client, db, col, filter_doc, update_doc, uo),
    }
    .map_err(|e| PhpException::default(e))?;
    Ok(update_result_to_zval(&result))
}

#[php_function]
pub fn zealphp_mongodb_delete_one(
    pool_id: i64,
    db: &str,
    col: &str,
    filter: &Zval,
    opts: Option<&Zval>,
) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let filter_doc = bson_convert::php_to_doc(filter).map_err(|e| PhpException::default(e))?;
    let result = match parse_session(opts)? {
        Some(sess) => ops_session::delete_one(&client, db, col, filter_doc, sess),
        None => ops::delete_one(&client, db, col, filter_doc),
    }
    .map_err(|e| PhpException::default(e))?;
    Ok(delete_result_to_zval(&result))
}

#[php_function]
pub fn zealphp_mongodb_delete_many(
    pool_id: i64,
    db: &str,
    col: &str,
    filter: &Zval,
    opts: Option<&Zval>,
) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let filter_doc = bson_convert::php_to_doc(filter).map_err(|e| PhpException::default(e))?;
    let result = match parse_session(opts)? {
        Some(sess) => ops_session::delete_many(&client, db, col, filter_doc, sess),
        None => ops::delete_many(&client, db, col, filter_doc),
    }
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
    let result = match parse_session(opts)? {
        Some(sess) => ops_session::replace_one(&client, db, col, filter_doc, replacement_doc, ro, sess),
        None => ops::replace_one_with_options(&client, db, col, filter_doc, replacement_doc, ro),
    }
    .map_err(|e| PhpException::default(e))?;
    Ok(update_result_to_zval(&result))
}

#[php_function]
pub fn zealphp_mongodb_count_documents(
    pool_id: i64,
    db: &str,
    col: &str,
    filter: &Zval,
    opts: Option<&Zval>,
) -> PhpResult<i64> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let filter_doc = bson_convert::php_to_doc(filter).map_err(|e| PhpException::default(e))?;
    let count = match parse_session(opts)? {
        Some(sess) => ops_session::count_documents(&client, db, col, filter_doc, sess),
        None => ops::count_documents(&client, db, col, filter_doc),
    }
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
    let cursor_id = cursor::store_doc_cursor(mongo_cursor);
    Ok(cursor_id as i64)
}

#[php_function]
pub fn zealphp_mongodb_distinct(
    pool_id: i64,
    db: &str,
    col: &str,
    field_name: &str,
    filter: &Zval,
    opts: Option<&Zval>,
) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let filter_doc = bson_convert::php_to_doc(filter).map_err(|e| PhpException::default(e))?;
    let values = match parse_session(opts)? {
        Some(sess) => ops_session::distinct(&client, db, col, field_name, filter_doc, sess),
        None => ops::distinct(&client, db, col, field_name, filter_doc),
    }
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
    let result = match parse_session(opts)? {
        Some(sess) => ops_session::find_one_and_update(&client, db, col, filter_doc, update_doc, fo, sess),
        None => ops::find_one_and_update_with_options(&client, db, col, filter_doc, update_doc, fo),
    }
    .map_err(|e| PhpException::default(e))?;
    match result {
        Some(doc) => Ok(bson_convert::raw_doc_to_php(&doc)),
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
    opts: Option<&Zval>,
) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let filter_doc = bson_convert::php_to_doc(filter).map_err(|e| PhpException::default(e))?;
    let result = match parse_session(opts)? {
        Some(sess) => ops_session::find_one_and_delete(&client, db, col, filter_doc, sess),
        None => ops::find_one_and_delete(&client, db, col, filter_doc),
    }
    .map_err(|e| PhpException::default(e))?;
    match result {
        Some(doc) => Ok(bson_convert::raw_doc_to_php(&doc)),
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
    let result = match parse_session(opts)? {
        Some(sess) => ops_session::find_one_and_replace(&client, db, col, filter_doc, replacement_doc, fo, sess),
        None => ops::find_one_and_replace_with_options(&client, db, col, filter_doc, replacement_doc, fo),
    }
    .map_err(|e| PhpException::default(e))?;
    match result {
        Some(doc) => Ok(bson_convert::raw_doc_to_php(&doc)),
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
        Some(doc) => Ok(bson_convert::raw_doc_to_php(&doc)),
        None => {
            let mut z = Zval::new();
            z.set_null();
            Ok(z)
        }
    }
}

#[php_function]
pub fn zealphp_mongodb_cursor_close(cursor_id: i64) {
    cursor::remove(cursor_id as u64);
}

#[php_function]
pub fn zealphp_mongodb_find_all(
    pool_id: i64,
    db: &str,
    col: &str,
    filter: &Zval,
    opts: Option<&Zval>,
) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let filter_doc = bson_convert::php_to_doc(filter).map_err(|e| PhpException::default(e))?;
    let fo = parse_find_options(opts);

    let docs: Vec<bson::raw::RawDocumentBuf> = match parse_session(opts)? {
        Some(sess) => ops_session::find_all(&client, db, col, filter_doc, fo, sess),
        None => {
            let collection = client.database(db).collection::<bson::raw::RawDocumentBuf>(col);
            coroutine::run_sync(async move {
                use futures::TryStreamExt;
                let cursor = collection.find(filter_doc).with_options(fo).await?;
                cursor.try_collect().await
            })
        }
    }
    .map_err(|e| PhpException::default(e))?;

    let mut zval = Zval::new();
    let mut ht = ZendHashTable::with_capacity(docs.len() as u32);
    for (i, doc) in docs.iter().enumerate() {
        let _ = ht.insert_at_index(i as u64, bson_convert::raw_doc_to_php(doc));
    }
    zval.set_hashtable(ht);
    Ok(zval)
}

#[php_function]
pub fn zealphp_mongodb_aggregate_all(
    pool_id: i64,
    db: &str,
    col: &str,
    pipeline: &Zval,
    opts: Option<&Zval>,
) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let pipeline_docs =
        bson_convert::php_to_pipeline(pipeline).map_err(|e| PhpException::default(e))?;

    let docs: Vec<bson::Document> = match parse_session(opts)? {
        Some(sess) => ops_session::aggregate_all(&client, db, col, pipeline_docs, sess),
        None => {
            let collection = client.database(db).collection::<bson::Document>(col);
            coroutine::run_sync(async move {
                use futures::TryStreamExt;
                let cursor = collection.aggregate(pipeline_docs).await?;
                cursor.try_collect().await
            })
        }
    }
    .map_err(|e| PhpException::default(e))?;

    let mut zval = Zval::new();
    let mut ht = ZendHashTable::with_capacity(docs.len() as u32);
    for (i, doc) in docs.iter().enumerate() {
        let _ = ht.insert_at_index(i as u64, bson_convert::doc_to_php(doc));
    }
    zval.set_hashtable(ht);
    Ok(zval)
}

#[php_function]
pub fn zealphp_mongodb_cursor_to_array(cursor_id: i64) -> PhpResult<Zval> {
    let docs = cursor::drain_to_vec(cursor_id as u64).map_err(|e| PhpException::default(e))?;
    let mut zval = Zval::new();
    let mut ht = ZendHashTable::with_capacity(docs.len() as u32);
    for (i, doc) in docs.iter().enumerate() {
        let _ = ht.insert_at_index(i as u64, bson_convert::raw_doc_to_php(doc));
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
            let mut cur = cursor_arc.lock().await;
            match cur.next_raw().await {
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
            let mut cur = cursor_arc.lock().await;
            let mut docs = Vec::with_capacity(batch);
            let mut exhausted = false;
            for _ in 0..batch {
                match cur.next_raw().await {
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
        let _ = docs_ht.insert_at_index(i as u64, bson_convert::raw_doc_to_php(doc));
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
    let client = pool::get_async_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
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
            let collection = client.database(&db_s).collection::<bson::raw::RawDocumentBuf>(&col_s);
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
    let client = pool::get_async_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
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
                            Some(Ok(doc)) => match bson::raw::RawDocumentBuf::from_document(&doc) {
                                Ok(raw) => docs.push(raw),
                                Err(e) => {
                                    return async_store::BatchResult {
                                        docs: Vec::new(), exhausted: true, cursor_id: None,
                                        error: Some(e.to_string()),
                                    };
                                }
                            },
                            Some(Err(e)) => {
                                return async_store::BatchResult {
                                    docs: Vec::new(), exhausted: true, cursor_id: None,
                                    error: Some(e.to_string()),
                                };
                            }
                            None => { exhausted = true; break; }
                        }
                    }
                    let cursor_id = if exhausted { None } else { Some(cursor::store_doc_cursor(cursor)) };
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
    pool_id: i64, db: &str, col: &str, documents: &Zval, opts: Option<&Zval>,
) -> PhpResult<Zval> {
    let client = pool::get_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let docs_arr = documents.array().ok_or_else(|| PhpException::default("Expected array of documents".to_string()))?;
    let mut docs = Vec::new();
    for (_, val) in docs_arr.iter() {
        docs.push(bson_convert::php_to_doc(val).map_err(|e| PhpException::default(e))?);
    }
    let result = match parse_session(opts)? {
        Some(sess) => ops_session::insert_many(&client, db, col, docs, sess),
        None => ops::insert_many(&client, db, col, docs),
    }
    .map_err(|e| PhpException::default(e))?;

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

// fix(by-ref): change update_or_pipeline from Option<&Zval> to &Zval to avoid
// the ext-php-rs-derive macro bug at function.rs:312-317 (v0.10.2) which
// unconditionally marks Option<&T> as PHP pass-by-reference (the comment at
// line 299 said it only meant Option<&mut T>). Plain &Zval is NOT marked
// by-ref by the same macro. Callers must now always pass arg #6 — pass `null`
// when not applicable. AsyncBridge already does this, and the labs
// DashboardPrefetcher passes 6 args at every call site.
#[php_function]
pub fn zealphp_mongodb_exec_async(
    pool_id: i64,
    db: &str,
    col: &str,
    op: &str,
    filter_or_doc: &Zval,
    update_or_pipeline: &Zval,
) -> PhpResult<Zval> {
    let client = pool::get_async_client(pool_id as u64).map_err(|e| PhpException::default(e))?;
    let filter_doc = if !filter_or_doc.is_null() {
        Some(bson_convert::php_to_doc(filter_or_doc).map_err(|e| PhpException::default(e))?)
    } else {
        None
    };
    let update_docs = if !update_or_pipeline.is_null() {
        if op == "aggregate" || op == "aggregate_cursor" {
            Some(bson_convert::php_to_pipeline(update_or_pipeline).map_err(|e| PhpException::default(e))?)
        } else {
            Some(vec![bson_convert::php_to_doc(update_or_pipeline).map_err(|e| PhpException::default(e))?])
        }
    } else {
        None
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
            async_store::AsyncResult::Doc(Some(doc)) => Ok(bson_convert::raw_doc_to_php(&doc)),
            async_store::AsyncResult::Doc(None) => {
                let mut z = Zval::new();
                z.set_null();
                Ok(z)
            }
            async_store::AsyncResult::Scalar(doc) => Ok(bson_convert::raw_doc_to_php(&doc)),
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
                    let _ = ht.insert_at_index(i as u64, bson_convert::raw_doc_to_php(doc));
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

/// Return [pending_async_results, pending_batch_results].
///
/// In a healthy app where every `zealphp_mongodb_exec_async()` call is paired
/// with `zealphp_mongodb_async_result()`, both numbers stay near zero. A
/// monotonically growing count indicates a leak: the caller is spawning tasks
/// and discarding their task_id without ever draining the result.
///
/// Stored results auto-expire after `ZEALPHP_MONGODB_RESULT_TTL_SECS` (default
/// 60 s) — this counter shows the *current* in-window pending count. Useful for
/// dashboards / Prometheus exporters.
#[php_function]
pub fn zealphp_mongodb_pending_results() -> PhpResult<Zval> {
    let (results, batches) = async_store::pending_count();
    let mut zval = Zval::new();
    let mut ht = ZendHashTable::new();
    let mut r = Zval::new();
    r.set_long(results as i64);
    let _ = ht.insert("results", r);
    let mut b = Zval::new();
    b.set_long(batches as i64);
    let _ = ht.insert("batches", b);
    zval.set_hashtable(ht);
    Ok(zval)
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

    // Surface the upsert outcome (#8): the official driver reports
    // getUpsertedCount()===1 and getUpsertedId()===<_id> after an upsert.
    let mut upserted_count = Zval::new();
    upserted_count.set_long(if result.upserted_id.is_some() { 1 } else { 0 });
    let _ = ht.insert("upserted_count", upserted_count);
    if let Some(id) = &result.upserted_id {
        let _ = ht.insert("upserted_id", bson_convert::bson_to_zval(id));
    }

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
