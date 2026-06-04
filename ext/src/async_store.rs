use bson::Bson;
use bson::raw::RawDocumentBuf;
use std::collections::HashMap;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::Mutex;
use std::time::{Duration, Instant};

// TTL after which a stored result is considered abandoned and may be evicted.
// PHP callers must drain results within this window via zealphp_mongodb_async_result()
// or zealphp_mongodb_batch_result(); otherwise the data leaks until eviction.
//
// In production we observed `DashboardPrefetcher` spawning 15+ exec_async() tasks per
// /dashboard render and never calling async_result() on the returned task_ids — each
// `find` result holding many MB of documents. Without TTL eviction the global
// HashMap grew unboundedly (5–13 GB after a few minutes of sustained traffic).
//
// 60 s comfortably covers any reasonable PHP request timeout. Make it tunable via
// env var `ZEALPHP_MONGODB_RESULT_TTL_SECS` for self-hosted setups that need longer.
const DEFAULT_RESULT_TTL_SECS: u64 = 60;

fn result_ttl() -> Duration {
    let secs = std::env::var("ZEALPHP_MONGODB_RESULT_TTL_SECS")
        .ok()
        .and_then(|s| s.parse::<u64>().ok())
        .unwrap_or(DEFAULT_RESULT_TTL_SECS);
    Duration::from_secs(secs)
}

lazy_static::lazy_static! {
    static ref RESULTS: Mutex<HashMap<u64, (Instant, AsyncResult)>> = Mutex::new(HashMap::new());
    static ref BATCH_RESULTS: Mutex<HashMap<u64, (Instant, BatchResult)>> = Mutex::new(HashMap::new());
}

static NEXT_TASK_ID: AtomicU64 = AtomicU64::new(1);

pub enum AsyncResult {
    Doc(Option<RawDocumentBuf>),
    Scalar(RawDocumentBuf),
    Values(Vec<Bson>),
    Docs(Vec<RawDocumentBuf>),
    Error(String),
}

pub struct BatchResult {
    pub docs: Vec<RawDocumentBuf>,
    pub exhausted: bool,
    pub cursor_id: Option<u64>,
    pub error: Option<String>,
}

pub fn new_task_id() -> u64 {
    NEXT_TASK_ID.fetch_add(1, Ordering::Relaxed)
}

/// Evict entries from the given map that are older than the TTL.
/// Called opportunistically from store_*() — keeps the map bounded without a
/// background timer thread. Worst case: an abandoned result is reclaimed within
/// `TTL + (1 future store call)` time.
fn evict_stale<T>(map: &mut HashMap<u64, (Instant, T)>, ttl: Duration) {
    let now = Instant::now();
    map.retain(|_, (inserted, _)| now.duration_since(*inserted) < ttl);
}

pub fn store_result(task_id: u64, result: AsyncResult) {
    let ttl = result_ttl();
    let mut map = RESULTS.lock().unwrap();
    evict_stale(&mut map, ttl);
    map.insert(task_id, (Instant::now(), result));
}

pub fn take_result(task_id: u64) -> Option<AsyncResult> {
    RESULTS.lock().unwrap().remove(&task_id).map(|(_, r)| r)
}

pub fn store_batch(task_id: u64, result: BatchResult) {
    let ttl = result_ttl();
    let mut map = BATCH_RESULTS.lock().unwrap();
    evict_stale(&mut map, ttl);
    map.insert(task_id, (Instant::now(), result));
}

pub fn take_batch(task_id: u64) -> Option<BatchResult> {
    BATCH_RESULTS.lock().unwrap().remove(&task_id).map(|(_, r)| r)
}

/// Diagnostics: current size of the pending-results map. Exposed via
/// `zealphp_mongodb_pending_results()` for monitoring. Callers can use this to
/// detect drain misuse (a healthy app's pending count stays near 0; an app that
/// forgets to drain shows monotonically growing pending count between requests).
pub fn pending_count() -> (usize, usize) {
    (
        RESULTS.lock().unwrap().len(),
        BATCH_RESULTS.lock().unwrap().len(),
    )
}
