/**
 * C++ bridge for OpenSwoole coroutine yield/resume.
 *
 * Uses eventfd as a thread-safe signaling mechanism:
 * 1. PHP coroutine creates an eventfd, registers it with OpenSwoole's event loop
 * 2. PHP coroutine yields
 * 3. Tokio thread writes to eventfd when query completes
 * 4. OpenSwoole's event loop wakes up, reads the eventfd, resumes the coroutine
 *
 * This avoids calling any C++ methods via dlsym.
 */
#include <dlfcn.h>
#include <cstdint>
#include <sys/eventfd.h>
#include <unistd.h>

extern "C" {

int64_t zealphp_co_get_cid() {
    typedef int64_t (*fn_t)();
    static fn_t fn = nullptr;
    if (!fn) fn = (fn_t)dlsym(RTLD_DEFAULT, "swoole_coroutine_get_current_id");
    return fn ? fn() : -1;
}

/**
 * Create an eventfd for coroutine signaling.
 * Returns the fd (>= 0) or -1 on error.
 */
int zealphp_co_create_eventfd() {
    return eventfd(0, EFD_NONBLOCK | EFD_CLOEXEC);
}

/**
 * Signal the eventfd from any thread (write uint64 = 1).
 * This wakes up whoever is waiting on this fd.
 */
void zealphp_co_signal_eventfd(int fd) {
    uint64_t val = 1;
    write(fd, &val, sizeof(val));
}

/**
 * Close the eventfd.
 */
void zealphp_co_close_eventfd(int fd) {
    close(fd);
}

// yield/resume via C++ mangled symbols — kept as fallback
void zealphp_co_yield() {
    typedef void (*fn_t)();
    static fn_t fn = nullptr;
    if (!fn) fn = (fn_t)dlsym(RTLD_DEFAULT, "_ZN6swoole9Coroutine5yieldEv");
    if (fn) fn();
}

void zealphp_co_resume(int64_t cid) {
    // Get Coroutine* by ID
    typedef void* (*get_fn)(int64_t);
    static get_fn get_co = nullptr;
    if (!get_co) get_co = (get_fn)dlsym(RTLD_DEFAULT, "_Z20swoole_coroutine_getl");
    if (!get_co) return;
    void* co = get_co(cid);
    if (!co) return;

    // co->resume() — Itanium ABI: this = first arg
    typedef void (*resume_fn)(void*);
    static resume_fn rfn = nullptr;
    if (!rfn) rfn = (resume_fn)dlsym(RTLD_DEFAULT, "_ZN6swoole9Coroutine6resumeEv");
    if (rfn) rfn(co);
}

} // extern "C"
