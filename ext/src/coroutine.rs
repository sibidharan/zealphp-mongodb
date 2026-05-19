use tokio::runtime::Runtime;
use std::sync::Once;

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

pub fn get_cid() -> i64 {
    unsafe {
        let sym = libc::dlsym(libc::RTLD_DEFAULT, b"swoole_coroutine_get_current_id\0".as_ptr() as *const _);
        if sym.is_null() { return -1; }
        let func: extern "C" fn() -> i64 = std::mem::transmute(sym);
        func()
    }
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

fn signal_eventfd(fd: i32) {
    unsafe {
        let val: u64 = 1;
        libc::write(fd, &val as *const u64 as *const libc::c_void, 8);
    }
}

/// Spawn a generic async task. The future must produce a JSON string result.
/// Returns (eventfd, task_id). Signals eventfd when done.
pub fn spawn_task<F>(future: F, task_id: u64, efd: i32)
where
    F: std::future::Future<Output = String> + Send + 'static,
{
    runtime().spawn(async move {
        let json = future.await;
        async_store::store_result(task_id, json);
        signal_eventfd(efd);
    });
}

pub fn spawn_batch_task<F>(future: F, task_id: u64, efd: i32)
where
    F: std::future::Future<Output = async_store::BatchResult> + Send + 'static,
{
    runtime().spawn(async move {
        let result = future.await;
        async_store::store_batch(task_id, result);
        signal_eventfd(efd);
    });
}
