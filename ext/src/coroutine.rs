use tokio::runtime::Runtime;
use std::sync::Once;

// Create runtime eagerly during module init, BEFORE HOOK_ALL is enabled.
// lazy_static would create on first access (during request = after HOOK_ALL).
static mut RUNTIME_PTR: *const Runtime = std::ptr::null();
static INIT: Once = Once::new();

pub fn init_runtime() {
    INIT.call_once(|| {
        let rt = Box::new(Runtime::new().expect("Failed to create tokio runtime"));
        unsafe {
            RUNTIME_PTR = Box::into_raw(rt);
        }
    });
}

pub fn runtime() -> &'static Runtime {
    unsafe {
        if RUNTIME_PTR.is_null() {
            init_runtime();
        }
        &*RUNTIME_PTR
    }
}

extern "C" {
    fn zealphp_co_get_cid() -> i64;
}

pub fn get_cid() -> i64 {
    unsafe { zealphp_co_get_cid() }
}

pub fn run_async<F, T, E>(future: F) -> Result<T, String>
where
    F: std::future::Future<Output = Result<T, E>> + Send + 'static,
    T: Send + 'static,
    E: std::fmt::Display + Send + 'static,
{
    runtime().block_on(future).map_err(|e| e.to_string())
}
