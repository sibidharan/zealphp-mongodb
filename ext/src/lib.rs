use ext_php_rs::prelude::*;

#[php_function]
pub fn zealphp_mongodb_version() -> String {
    "0.1.0".to_string()
}

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module
}
