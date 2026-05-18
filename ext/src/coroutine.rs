use tokio::runtime::Runtime;

lazy_static::lazy_static! {
    pub static ref RUNTIME: Runtime = Runtime::new()
        .expect("Failed to create tokio runtime");
}

extern "C" {
    fn zealphp_co_get_cid() -> i64;
}

pub fn get_cid() -> i64 {
    unsafe { zealphp_co_get_cid() }
}

/// Run an async future synchronously via block_on().
///
/// COROUTINE BRIDGE STATUS:
/// The eventfd/yield/resume approaches don't work because:
/// 1. C++ mangled symbols can't be called safely from Rust (ABI mismatch)
/// 2. swoole_event_defer requires std::function ABI compatibility
/// 3. HOOK_ALL hooks PHP stream functions, not raw libc::read()
///
/// SOLUTION: Build coroutine bridge into ZealPHP framework itself (same
/// compilation unit as OpenSwoole). ZealPHP exports zealphp_co_yield()
/// and zealphp_co_resume(cid) as extern "C" functions that this extension
/// calls via dlsym.
pub fn run_async<F, T, E>(future: F) -> Result<T, String>
where
    F: std::future::Future<Output = Result<T, E>> + Send + 'static,
    T: Send + 'static,
    E: std::fmt::Display + Send + 'static,
{
    RUNTIME.block_on(future).map_err(|e| e.to_string())
}
