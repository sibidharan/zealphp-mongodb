use tokio::runtime::Runtime;
use std::sync::Once;
use bson::Document;

use crate::async_store;

static mut RUNTIME_PTR: *const Runtime = std::ptr::null();
static INIT: Once = Once::new();

pub fn init_runtime() {
    INIT.call_once(|| {
        let rt = Box::new(Runtime::new().expect("Failed to create tokio runtime"));
        unsafe { RUNTIME_PTR = Box::into_raw(rt); }
    });
}

pub fn runtime() -> &'static Runtime {
    unsafe {
        if RUNTIME_PTR.is_null() { init_runtime(); }
        &*RUNTIME_PTR
    }
}

extern "C" { fn zealphp_co_get_cid() -> i64; }

pub fn get_cid() -> i64 {
    unsafe { zealphp_co_get_cid() }
}

pub fn run_sync<F, T, E>(future: F) -> Result<T, String>
where
    F: std::future::Future<Output = Result<T, E>> + Send + 'static,
    T: Send + 'static,
    E: std::fmt::Display + Send + 'static,
{
    runtime().block_on(future).map_err(|e| e.to_string())
}

pub fn create_eventfd() -> i32 {
    unsafe { libc::eventfd(0, libc::EFD_NONBLOCK | libc::EFD_CLOEXEC) }
}

pub fn signal_eventfd(fd: i32) {
    unsafe {
        let val: u64 = 1;
        libc::write(fd, &val as *const u64 as *const libc::c_void, 8);
    }
}

/// Spawn an async find_one on tokio. Stores result as BSON Document (Send-safe).
/// Signals eventfd when done.
pub fn spawn_find_one(
    client: mongodb::Client,
    db: String,
    col: String,
    filter: Document,
    task_id: u64,
    efd: i32,
) {
    runtime().spawn(async move {
        let collection = client.database(&db).collection::<Document>(&col);
        let result = collection.find_one(filter).await.ok().flatten();
        async_store::store_result(task_id, result);
        signal_eventfd(efd);
    });
}
