fn main() {
    // Compile the C++ coroutine bridge shim
    cc::Build::new()
        .cpp(true)
        .file("src/co_bridge.cpp")
        .flag("-std=c++17")
        .compile("co_bridge");

    // Link against dl for dlsym
    println!("cargo:rustc-link-lib=dl");
}
