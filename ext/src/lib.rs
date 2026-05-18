mod bson_convert;
mod coroutine;
mod pool;

use ext_php_rs::prelude::*;

#[php_function]
pub fn zealphp_mongodb_version() -> String {
    "0.1.0".to_string()
}

#[php_function]
pub fn zealphp_mongodb_in_coroutine() -> bool {
    coroutine::get_cid() >= 0
}

#[php_function]
pub fn zealphp_mongodb_connect(uri: &str) -> PhpResult<i64> {
    pool::connect(uri)
        .map(|id| id as i64)
        .map_err(|e| PhpException::default(e))
}

#[php_function]
pub fn zealphp_mongodb_close(pool_id: i64) -> PhpResult<()> {
    pool::close(pool_id as u64).map_err(|e| PhpException::default(e))
}

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module
}
