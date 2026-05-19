use bson::{Bson, Document};
use std::collections::HashMap;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::Mutex;

lazy_static::lazy_static! {
    static ref RESULTS: Mutex<HashMap<u64, AsyncResult>> = Mutex::new(HashMap::new());
    static ref BATCH_RESULTS: Mutex<HashMap<u64, BatchResult>> = Mutex::new(HashMap::new());
}

static NEXT_TASK_ID: AtomicU64 = AtomicU64::new(1);

pub enum AsyncResult {
    Doc(Option<Document>),
    Scalar(Document),
    Values(Vec<Bson>),
    Docs(Vec<Document>),
    Error(String),
}

pub struct BatchResult {
    pub docs: Vec<Document>,
    pub exhausted: bool,
    pub cursor_id: Option<u64>,
    pub error: Option<String>,
}

pub fn new_task_id() -> u64 {
    NEXT_TASK_ID.fetch_add(1, Ordering::Relaxed)
}

pub fn store_result(task_id: u64, result: AsyncResult) {
    RESULTS.lock().unwrap().insert(task_id, result);
}

pub fn take_result(task_id: u64) -> Option<AsyncResult> {
    RESULTS.lock().unwrap().remove(&task_id)
}

pub fn store_batch(task_id: u64, result: BatchResult) {
    BATCH_RESULTS.lock().unwrap().insert(task_id, result);
}

pub fn take_batch(task_id: u64) -> Option<BatchResult> {
    BATCH_RESULTS.lock().unwrap().remove(&task_id)
}
