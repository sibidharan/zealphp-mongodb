use mongodb::Client;
use std::collections::HashMap;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::Mutex;

use crate::coroutine;

lazy_static::lazy_static! {
    static ref POOLS: Mutex<HashMap<u64, Client>> = Mutex::new(HashMap::new());
}

static NEXT_ID: AtomicU64 = AtomicU64::new(1);

pub fn connect(uri: &str) -> Result<u64, String> {
    let uri_owned = uri.to_string();
    let client = coroutine::RUNTIME
        .block_on(async move { Client::with_uri_str(&uri_owned).await })
        .map_err(|e| format!("Connection failed: {}", e))?;

    let id = NEXT_ID.fetch_add(1, Ordering::SeqCst);
    POOLS.lock().unwrap().insert(id, client);
    Ok(id)
}

pub fn get_client(pool_id: u64) -> Result<Client, String> {
    let pools = POOLS.lock().unwrap();
    pools
        .get(&pool_id)
        .cloned()
        .ok_or_else(|| format!("Invalid pool ID: {}", pool_id))
}

pub fn close(pool_id: u64) -> Result<(), String> {
    POOLS
        .lock()
        .unwrap()
        .remove(&pool_id)
        .map(|_| ())
        .ok_or_else(|| format!("Invalid pool ID: {}", pool_id))
}
