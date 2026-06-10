use mongodb::ClientSession;
use std::collections::HashMap;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{Arc, RwLock};
use tokio::sync::Mutex as TokioMutex;

use crate::coroutine;
use crate::pool;

/// Server-backed ClientSession registry — same shape as the cursor registry.
/// A session lives here from `start()` until `end()` (or process exit); ops
/// borrow it via the TokioMutex for the duration of one server round-trip.
pub type SharedSession = Arc<TokioMutex<ClientSession>>;

lazy_static::lazy_static! {
    static ref SESSIONS: RwLock<HashMap<u64, SharedSession>> = RwLock::new(HashMap::new());
}

static NEXT_SESSION_ID: AtomicU64 = AtomicU64::new(1);

pub fn start(pool_id: u64, causal_consistency: Option<bool>) -> Result<u64, String> {
    let client = pool::get_client(pool_id)?;
    let session = coroutine::run_sync(async move {
        let mut action = client.start_session();
        if let Some(cc) = causal_consistency {
            action = action.causal_consistency(cc);
        }
        action.await
    })?;

    let id = NEXT_SESSION_ID.fetch_add(1, Ordering::Relaxed);
    SESSIONS
        .write()
        .unwrap()
        .insert(id, Arc::new(TokioMutex::new(session)));
    Ok(id)
}

pub fn get(session_id: u64) -> Result<SharedSession, String> {
    SESSIONS
        .read()
        .unwrap()
        .get(&session_id)
        .cloned()
        .ok_or_else(|| format!("Invalid session ID: {} (already ended?)", session_id))
}

/// Resolve an optional PHP-side session id into the shared handle ops thread
/// through to the driver. `None` stays `None` (non-transactional op).
pub fn resolve(session_id: Option<i64>) -> Result<Option<SharedSession>, String> {
    match session_id {
        Some(sid) if sid > 0 => get(sid as u64).map(Some),
        Some(sid) => Err(format!("Invalid session ID: {}", sid)),
        None => Ok(None),
    }
}

pub struct TxnOptions {
    pub max_commit_time_ms: Option<u64>,
}

pub fn start_transaction(session_id: u64, opts: TxnOptions) -> Result<(), String> {
    let session = get(session_id)?;
    coroutine::run_sync(async move {
        let mut guard = session.lock().await;
        let mut action = guard.start_transaction();
        if let Some(ms) = opts.max_commit_time_ms {
            action = action.max_commit_time(std::time::Duration::from_millis(ms));
        }
        action.await
    })
}

pub fn commit_transaction(session_id: u64) -> Result<(), String> {
    let session = get(session_id)?;
    coroutine::run_sync(async move {
        let mut guard = session.lock().await;
        guard.commit_transaction().await
    })
}

pub fn abort_transaction(session_id: u64) -> Result<(), String> {
    let session = get(session_id)?;
    coroutine::run_sync(async move {
        let mut guard = session.lock().await;
        guard.abort_transaction().await
    })
}

/// Logical session id document ({"id": Binary(UUID)}), like the C driver's
/// Session::getLogicalSessionId().
pub fn logical_session_id(session_id: u64) -> Result<bson::Document, String> {
    let session = get(session_id)?;
    let doc = coroutine::run_sync(async move {
        let guard = session.lock().await;
        Ok::<bson::Document, mongodb::error::Error>(guard.id().clone())
    })?;
    Ok(doc)
}

/// Cluster time document as last observed by this session (or None).
pub fn cluster_time(session_id: u64) -> Result<Option<bson::Document>, String> {
    let session = get(session_id)?;
    coroutine::run_sync(async move {
        let guard = session.lock().await;
        Ok::<Option<bson::Document>, mongodb::error::Error>(
            guard.cluster_time().map(|ct| bson::to_document(ct).unwrap_or_default()),
        )
    })
}

/// Operation time (timestamp) as last observed by this session (or None).
pub fn operation_time(session_id: u64) -> Result<Option<(u32, u32)>, String> {
    let session = get(session_id)?;
    coroutine::run_sync(async move {
        let guard = session.lock().await;
        Ok::<Option<(u32, u32)>, mongodb::error::Error>(
            guard.operation_time().map(|ts| (ts.time, ts.increment)),
        )
    })
}

/// Drop the session — the driver ends it server-side on drop.
pub fn end(session_id: u64) {
    SESSIONS.write().unwrap().remove(&session_id);
}
