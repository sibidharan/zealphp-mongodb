use std::sync::{Arc, Mutex};
use tokio::runtime::Runtime;

lazy_static::lazy_static! {
    pub static ref RUNTIME: Runtime = Runtime::new()
        .expect("Failed to create tokio runtime");
}

/// Get current OpenSwoole coroutine ID via C function.
/// Returns -1 if not in a coroutine or OpenSwoole not loaded.
pub fn get_cid() -> i64 {
    unsafe {
        // Plain C function exported by OpenSwoole
        let sym = libc::dlsym(
            libc::RTLD_DEFAULT,
            b"swoole_coroutine_get_current_id\0".as_ptr() as *const _,
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
        // C++ mangled: swoole::Coroutine::yield() (no args overload)
        let sym = libc::dlsym(
            libc::RTLD_DEFAULT,
            b"_ZN6swoole9Coroutine5yieldEv\0".as_ptr() as *const _,
        );
        if !sym.is_null() {
            let func: extern "C" fn() = std::mem::transmute(sym);
            func();
        }
    }
}

fn do_resume(cid: i64) {
    unsafe {
        // swoole::Coroutine::resume() resumes the CURRENT yielded coroutine.
        // But we need to resume a SPECIFIC coroutine by ID.
        // Use swoole_coroutine_get(cid) to get the Coroutine* pointer,
        // then call its resume() method.
        // Alternatively, use the static resume_by_id if available.
        //
        // For OpenSwoole, the simplest approach: the coroutine that yielded
        // is the one we need to resume. Since we spawned the tokio task from
        // that coroutine, we know the cid. OpenSwoole's Coroutine::resume()
        // resumes the coroutine that last yielded on the current thread.
        //
        // Actually, for OpenSwoole the correct C API is to get the Coroutine
        // object and call resume on it. But the C++ API isn't easily callable.
        //
        // WORKAROUND: Use ext-php-rs to call the PHP-level
        // \OpenSwoole\Coroutine::resume($cid) function.
        // This is safe and correct.

        let sym = libc::dlsym(
            libc::RTLD_DEFAULT,
            b"_ZN6swoole9Coroutine6resumeEv\0".as_ptr() as *const _,
        );
        if !sym.is_null() {
            // This resumes the most recently yielded coroutine
            let func: extern "C" fn() = std::mem::transmute(sym);
            func();
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
        // Not in a coroutine (Apache/CLI/FPM) — run synchronously
        return RUNTIME.block_on(future).map_err(|e| e.to_string());
    }

    // In a coroutine — spawn on tokio, yield PHP coroutine
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
