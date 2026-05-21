use mongodb::options::ClientOptions;
use mongodb::Client;
use std::collections::HashMap;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::RwLock;
use std::time::Duration;

use crate::coroutine;

struct PoolEntry {
    client: Client,
    async_client: Option<Client>,
    uri: String,
}

lazy_static::lazy_static! {
    static ref POOLS: RwLock<HashMap<u64, PoolEntry>> = RwLock::new(HashMap::new());
}

static NEXT_ID: AtomicU64 = AtomicU64::new(1);

fn apply_pool_limits(opts: &mut ClientOptions) {
    opts.max_pool_size = Some(opts.max_pool_size.unwrap_or(10).min(10));
    opts.min_pool_size = Some(0);
    opts.max_idle_time = Some(Duration::from_secs(30));
    opts.heartbeat_freq = Some(Duration::from_secs(30));
    opts.server_selection_timeout = Some(Duration::from_secs(5));
    opts.connect_timeout = Some(Duration::from_secs(5));
}

pub fn connect(uri: &str) -> Result<u64, String> {
    let uri_owned = uri.to_string();
    let client = coroutine::runtime()
        .block_on(async move {
            let mut opts = ClientOptions::parse(&uri_owned).await
                .map_err(|e| format!("Parse failed: {}", e))?;
            apply_pool_limits(&mut opts);
            Client::with_options(opts)
                .map_err(|e| format!("Connection failed: {}", e))
        })?;

    let id = NEXT_ID.fetch_add(1, Ordering::Relaxed);
    POOLS.write().unwrap().insert(id, PoolEntry {
        client,
        async_client: None,
        uri: uri.to_string(),
    });
    Ok(id)
}

pub fn get_client(pool_id: u64) -> Result<Client, String> {
    let pools = POOLS.read().unwrap();
    pools
        .get(&pool_id)
        .map(|e| e.client.clone())
        .ok_or_else(|| format!("Invalid pool ID: {}", pool_id))
}

pub fn get_async_client(pool_id: u64) -> Result<Client, String> {
    let mut pools = POOLS.write().unwrap();
    let entry = pools
        .get_mut(&pool_id)
        .ok_or_else(|| format!("Invalid pool ID: {}", pool_id))?;

    if entry.async_client.is_none() {
        let uri = entry.uri.clone();
        let client = coroutine::async_runtime()
            .block_on(async move {
                let mut opts = ClientOptions::parse(&uri).await
                    .map_err(|e| format!("Parse failed: {}", e))?;
                apply_pool_limits(&mut opts);
                Client::with_options(opts)
                    .map_err(|e| format!("Async connect failed: {}", e))
            })?;
        entry.async_client = Some(client);
    }

    Ok(entry.async_client.as_ref().unwrap().clone())
}

pub fn close(pool_id: u64) -> Result<(), String> {
    POOLS
        .write()
        .unwrap()
        .remove(&pool_id)
        .map(|_| ())
        .ok_or_else(|| format!("Invalid pool ID: {}", pool_id))
}
