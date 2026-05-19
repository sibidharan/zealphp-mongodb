use std::collections::HashMap;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::Mutex;

/// Generic result store — holds serialized results as bytes.
/// Results are stored as JSON strings for thread-safety (no Zval across threads).
lazy_static::lazy_static! {
    static ref RESULTS: Mutex<HashMap<u64, String>> = Mutex::new(HashMap::new());
}

static NEXT_TASK_ID: AtomicU64 = AtomicU64::new(1);

pub fn new_task_id() -> u64 {
    NEXT_TASK_ID.fetch_add(1, Ordering::SeqCst)
}

pub fn store_result(task_id: u64, json: String) {
    RESULTS.lock().unwrap().insert(task_id, json);
}

pub fn take_result(task_id: u64) -> Option<String> {
    RESULTS.lock().unwrap().remove(&task_id)
}
