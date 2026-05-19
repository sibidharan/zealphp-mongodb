use bson::Document;
use futures::StreamExt;
use mongodb::Cursor;
use std::collections::HashMap;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::Arc;
use std::sync::RwLock;
use tokio::sync::Mutex as TokioMutex;

use crate::coroutine;

type SharedCursor = Arc<TokioMutex<Cursor<Document>>>;

lazy_static::lazy_static! {
    static ref CURSORS: RwLock<HashMap<u64, SharedCursor>> = RwLock::new(HashMap::new());
}

static NEXT_CURSOR_ID: AtomicU64 = AtomicU64::new(1);

pub fn get_store() -> &'static RwLock<HashMap<u64, SharedCursor>> {
    &CURSORS
}

pub fn store_cursor(cursor: Cursor<Document>) -> u64 {
    let id = NEXT_CURSOR_ID.fetch_add(1, Ordering::SeqCst);
    CURSORS
        .write()
        .unwrap()
        .insert(id, Arc::new(TokioMutex::new(cursor)));
    id
}

pub fn next_doc(cursor_id: u64) -> Result<Option<Document>, String> {
    let cursor_arc = {
        let cursors = CURSORS.read().unwrap();
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

pub fn drain_to_vec(cursor_id: u64) -> Result<Vec<Document>, String> {
    let cursor_arc = {
        CURSORS
            .write()
            .unwrap()
            .remove(&cursor_id)
            .ok_or_else(|| format!("Invalid cursor ID: {}", cursor_id))?
    };

    coroutine::run_sync(async move {
        let mut cursor = cursor_arc.lock().await;
        let mut docs = Vec::new();
        while let Some(result) = cursor.next().await {
            match result {
                Ok(doc) => docs.push(doc),
                Err(e) => return Err(e),
            }
        }
        Ok(docs)
    })
}

pub fn remove(cursor_id: u64) {
    CURSORS.write().unwrap().remove(&cursor_id);
}
