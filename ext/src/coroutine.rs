use std::sync::{Arc, Mutex};
use tokio::runtime::Runtime;

lazy_static::lazy_static! {
    pub static ref RUNTIME: Runtime = Runtime::new()
        .expect("Failed to create tokio runtime");
}

/// Check if OpenSwoole coroutine API is available (via dlsym)
pub fn get_cid() -> i64 {
    unsafe {
        let sym = libc::dlsym(
            libc::RTLD_DEFAULT,
            b"swoole_coroutine_get_cid\0".as_ptr() as *const _,
        );
        if sym.is_null() {
            return -1;
        }
        let func: extern "C" fn() -> i64 = std::mem::transmute(sym);
        func()
    }
}

fn do_yield() {
    unsafe {
        let sym = libc::dlsym(
            libc::RTLD_DEFAULT,
            b"swoole_coroutine_yield\0".as_ptr() as *const _,
        );
        if !sym.is_null() {
            let func: extern "C" fn() -> bool = std::mem::transmute(sym);
            func();
        }
    }
}

fn do_resume(cid: i64) {
    unsafe {
        let sym = libc::dlsym(
            libc::RTLD_DEFAULT,
            b"swoole_coroutine_resume\0".as_ptr() as *const _,
        );
        if !sym.is_null() {
            let func: extern "C" fn(i64) -> bool = std::mem::transmute(sym);
            func(cid);
        }
    }
}

/// Run an async future. Yields the OpenSwoole coroutine while waiting.
/// Falls back to synchronous block_on() when not in a coroutine.
pub fn run_async<F, T, E>(future: F) -> Result<T, String>
where
    F: std::future::Future<Output = Result<T, E>> + Send + 'static,
    T: Send + 'static,
    E: std::fmt::Display + Send + 'static,
{
    let cid = get_cid();

    if cid < 0 {
        return RUNTIME.block_on(future).map_err(|e| e.to_string());
    }

    let result: Arc<Mutex<Option<Result<T, String>>>> = Arc::new(Mutex::new(None));
    let slot = result.clone();

    RUNTIME.spawn(async move {
        let res = future.await.map_err(|e| e.to_string());
        *slot.lock().unwrap() = Some(res);
        do_resume(cid);
    });

    do_yield();

    let ret = result
        .lock()
        .unwrap()
        .take()
        .unwrap_or_else(|| Err("Coroutine resumed without result".to_string()));
    ret
}
