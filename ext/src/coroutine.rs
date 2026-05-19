use tokio::runtime::Runtime;
use std::sync::Once;

use crate::async_store;

static mut SYNC_RUNTIME_PTR: *const Runtime = std::ptr::null();
static mut ASYNC_RUNTIME_PTR: *const Runtime = std::ptr::null();
static SYNC_INIT: Once = Once::new();
static ASYNC_INIT: Once = Once::new();

pub fn init_runtime() {
    init_sync_runtime();
}

fn init_sync_runtime() {
    SYNC_INIT.call_once(|| {
        let rt = Box::new(
            tokio::runtime::Builder::new_current_thread()
                .enable_all()
                .build()
                .expect("Failed to create sync runtime"),
        );
        unsafe { SYNC_RUNTIME_PTR = Box::into_raw(rt); }
    });
}

fn init_async_runtime() {
    ASYNC_INIT.call_once(|| {
        let rt = Box::new(
            tokio::runtime::Builder::new_multi_thread()
                .worker_threads(1)
                .enable_all()
                .build()
                .expect("Failed to create async runtime"),
        );
        unsafe { ASYNC_RUNTIME_PTR = Box::into_raw(rt); }
    });
}

pub fn runtime() -> &'static Runtime {
    unsafe {
        if SYNC_RUNTIME_PTR.is_null() { init_sync_runtime(); }
        &*SYNC_RUNTIME_PTR
    }
}

pub fn async_runtime() -> &'static Runtime {
    unsafe {
        if ASYNC_RUNTIME_PTR.is_null() { init_async_runtime(); }
        &*ASYNC_RUNTIME_PTR
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

pub fn spawn_task<F>(future: F, task_id: u64, efd: i32)
where
    F: std::future::Future<Output = async_store::AsyncResult> + Send + 'static,
{
    async_runtime().spawn(async move {
        let result = future.await;
        async_store::store_result(task_id, result);
        signal_eventfd(efd);
    });
}

pub fn spawn_batch_task<F>(future: F, task_id: u64, efd: i32)
where
    F: std::future::Future<Output = async_store::BatchResult> + Send + 'static,
{
    async_runtime().spawn(async move {
        let result = future.await;
        async_store::store_batch(task_id, result);
        signal_eventfd(efd);
    });
}
