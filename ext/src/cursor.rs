use bson::Document;
use futures::StreamExt;
use mongodb::Cursor;
use std::collections::HashMap;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::Arc;
use std::sync::Mutex;
use tokio::sync::Mutex as TokioMutex;

use crate::coroutine;

type SharedCursor = Arc<TokioMutex<Cursor<Document>>>;

lazy_static::lazy_static! {
    static ref CURSORS: Mutex<HashMap<u64, SharedCursor>> = Mutex::new(HashMap::new());
}

static NEXT_CURSOR_ID: AtomicU64 = AtomicU64::new(1);

pub fn get_store() -> &'static Mutex<HashMap<u64, SharedCursor>> {
    &CURSORS
}

pub fn store_cursor(cursor: Cursor<Document>) -> u64 {
    let id = NEXT_CURSOR_ID.fetch_add(1, Ordering::SeqCst);
    CURSORS
        .lock()
        .unwrap()
        .insert(id, Arc::new(TokioMutex::new(cursor)));
    id
}

pub fn next_doc(cursor_id: u64) -> Result<Option<Document>, String> {
    let cursor_arc = {
        let cursors = CURSORS.lock().unwrap();
        cursors
            .get(&cursor_id)
            .cloned()
            .ok_or_else(|| format!("Invalid cursor ID: {}", cursor_id))?
    };

    coroutine::run_sync(async move {
        let mut cursor = cursor_arc.lock().await;
        match cursor.next().await {
            Some(Ok(doc)) => Ok(Some(doc)),
            Some(Err(e)) => Err(e),
            None => Ok(None),
        }
    })
}

pub fn remove(cursor_id: u64) -> Result<(), String> {
    CURSORS
        .lock()
        .unwrap()
        .remove(&cursor_id)
        .map(|_| ())
        .ok_or_else(|| format!("Invalid cursor ID: {}", cursor_id))
}
