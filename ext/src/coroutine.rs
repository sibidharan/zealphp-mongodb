use std::sync::{Arc, Mutex};
use tokio::runtime::Runtime;

lazy_static::lazy_static! {
    pub static ref RUNTIME: Runtime = Runtime::new()
        .expect("Failed to create tokio runtime");
}

pub fn get_cid() -> i64 {
    unsafe {
        let sym = libc::dlsym(
            libc::RTLD_DEFAULT,
            b"swoole_coroutine_get_current_id\0".as_ptr() as *const _,
        );
        if sym.is_null() { return -1; }
        let func: extern "C" fn() -> i64 = std::mem::transmute(sym);
        func()
    }
}

// BLOCKED: OpenSwoole exports C++ mangled yield/resume symbols only.
// Calling C++ member methods via dlsym is unsafe (segfaults).
// Needs ZealPHP to export zealphp_co_yield/zealphp_co_resume as extern "C".
// For now: always use synchronous block_on (still faster than ext-mongodb
// because Rust driver has built-in connection pooling + async TCP internally).

pub fn run_async<F, T, E>(future: F) -> Result<T, String>
where
    F: std::future::Future<Output = Result<T, E>> + Send + 'static,
    T: Send + 'static,
    E: std::fmt::Display + Send + 'static,
{
    // Always synchronous for now — block_on is safe outside tokio runtime
    RUNTIME.block_on(future).map_err(|e| e.to_string())
}
