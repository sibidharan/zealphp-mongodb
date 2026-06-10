//! Server change streams (`watch`) — registry pattern like cursors/sessions.
//!
//! Polling model: `next(stream_id, timeout_ms)` loops the driver's
//! non-blocking `next_if_any()` until an event arrives or the deadline
//! passes. `next_if_any` never long-polls, so the call is cancel-safe and
//! bounded — the PHP `ChangeStream` iterator maps its `maxAwaitTimeMS`
//! semantics (mongo-php-library parity) onto the timeout.

use bson::Document;
use mongodb::change_stream::event::ChangeStreamEvent;
use mongodb::change_stream::ChangeStream;
use std::collections::HashMap;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{Arc, RwLock};
use std::time::{Duration, Instant};
use tokio::sync::Mutex as TokioMutex;

use crate::coroutine;
use crate::pool;

type SharedStream = Arc<TokioMutex<ChangeStream<ChangeStreamEvent<Document>>>>;

lazy_static::lazy_static! {
    static ref STREAMS: RwLock<HashMap<u64, SharedStream>> = RwLock::new(HashMap::new());
}

static NEXT_STREAM_ID: AtomicU64 = AtomicU64::new(1);

pub struct WatchOptions {
    pub full_document: Option<String>,
    pub resume_after: Option<Document>,
    pub start_after: Option<Document>,
    pub max_await_time_ms: Option<u64>,
    pub batch_size: Option<u32>,
}

/// Open a change stream at client (db=None), database (col=None) or
/// collection scope and register it. Requires a replica set / sharded
/// cluster — the server refuses change streams on standalones.
pub fn watch(
    pool_id: u64,
    db: Option<&str>,
    col: Option<&str>,
    pipeline: Vec<Document>,
    opts: WatchOptions,
) -> Result<u64, String> {
    let client = pool::get_client(pool_id)?;

    let mut cs_opts = mongodb::options::ChangeStreamOptions::default();
    if let Some(fd) = opts.full_document.as_deref() {
        cs_opts.full_document = Some(match fd {
            "updateLookup" => mongodb::options::FullDocumentType::UpdateLookup,
            "whenAvailable" => mongodb::options::FullDocumentType::WhenAvailable,
            "required" => mongodb::options::FullDocumentType::Required,
            other => mongodb::options::FullDocumentType::Other(other.to_string()),
        });
    }
    if let Some(doc) = opts.resume_after {
        let token: mongodb::change_stream::event::ResumeToken =
            bson::from_bson(bson::Bson::Document(doc)).map_err(|e| e.to_string())?;
        cs_opts.resume_after = Some(token);
    }
    if let Some(doc) = opts.start_after {
        let token: mongodb::change_stream::event::ResumeToken =
            bson::from_bson(bson::Bson::Document(doc)).map_err(|e| e.to_string())?;
        cs_opts.start_after = Some(token);
    }
    if let Some(ms) = opts.max_await_time_ms {
        cs_opts.max_await_time = Some(Duration::from_millis(ms));
    }
    if let Some(bs) = opts.batch_size {
        cs_opts.batch_size = Some(bs);
    }

    let db_owned = db.map(str::to_string);
    let col_owned = col.map(str::to_string);

    let stream = coroutine::run_sync(async move {
        match (db_owned, col_owned) {
            (Some(d), Some(c)) => {
                let coll = client.database(&d).collection::<Document>(&c);
                coll.watch().pipeline(pipeline).with_options(cs_opts).await
            }
            (Some(d), None) => {
                client.database(&d).watch().pipeline(pipeline).with_options(cs_opts).await
            }
            _ => client.watch().pipeline(pipeline).with_options(cs_opts).await,
        }
    })?;

    let id = NEXT_STREAM_ID.fetch_add(1, Ordering::Relaxed);
    STREAMS
        .write()
        .unwrap()
        .insert(id, Arc::new(TokioMutex::new(stream)));
    Ok(id)
}

fn get(stream_id: u64) -> Result<SharedStream, String> {
    STREAMS
        .read()
        .unwrap()
        .get(&stream_id)
        .cloned()
        .ok_or_else(|| format!("Invalid change stream ID: {} (closed?)", stream_id))
}

/// Wait up to `timeout_ms` for the next event. Returns the event document
/// (serialized ChangeStreamEvent) or None on timeout.
pub fn next(stream_id: u64, timeout_ms: u64) -> Result<Option<Document>, String> {
    let stream = get(stream_id)?;
    coroutine::run_sync(async move {
        let mut guard = stream.lock().await;
        let deadline = Instant::now() + Duration::from_millis(timeout_ms);
        loop {
            if let Some(event) = guard.next_if_any().await? {
                let doc = bson::to_document(&event).map_err(mongodb::error::Error::custom)?;
                return Ok::<_, mongodb::error::Error>(Some(doc));
            }
            if Instant::now() >= deadline {
                return Ok(None);
            }
            tokio::time::sleep(Duration::from_millis(25)).await;
        }
    })
}

/// The stream's current resume token (None before the first batch).
pub fn resume_token(stream_id: u64) -> Result<Option<Document>, String> {
    let stream = get(stream_id)?;
    coroutine::run_sync(async move {
        let guard = stream.lock().await;
        let token = guard.resume_token().map(|t| {
            match bson::to_bson(&t) {
                Ok(bson::Bson::Document(doc)) => doc,
                _ => Document::new(),
            }
        });
        Ok::<_, mongodb::error::Error>(token)
    })
}

pub fn is_alive(stream_id: u64) -> Result<bool, String> {
    let stream = get(stream_id)?;
    coroutine::run_sync(async move {
        let guard = stream.lock().await;
        Ok::<_, mongodb::error::Error>(guard.is_alive())
    })
}

pub fn kill(stream_id: u64) {
    STREAMS.write().unwrap().remove(&stream_id);
}
