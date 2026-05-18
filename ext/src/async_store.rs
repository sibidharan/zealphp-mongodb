use std::collections::HashMap;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::Mutex;
use bson::Document;

lazy_static::lazy_static! {
    static ref RESULTS: Mutex<HashMap<u64, Option<Document>>> = Mutex::new(HashMap::new());
}

static NEXT_TASK_ID: AtomicU64 = AtomicU64::new(1);

pub fn new_task_id() -> u64 {
    NEXT_TASK_ID.fetch_add(1, Ordering::SeqCst)
}

pub fn store_result(task_id: u64, result: Option<Document>) {
    RESULTS.lock().unwrap().insert(task_id, result);
}

pub fn take_result(task_id: u64) -> Option<Option<Document>> {
    RESULTS.lock().unwrap().remove(&task_id)
}
