.PHONY: build install test clean

EXTENSION_DIR := $(shell php-config --extension-dir)

build:
	cd ext && cargo build --release

install: build
	cp ext/target/release/libzealphp_mongodb.so $(EXTENSION_DIR)/zealphp_mongodb.so
	@echo "extension=zealphp_mongodb.so" > /etc/php/8.3/cli/conf.d/40-zealphp-mongodb.ini
	@echo "Installed. Verify: php -m | grep zealphp"

test-ext:
	php -d extension=ext/target/release/libzealphp_mongodb.so -r 'echo zealphp_mongodb_version();'

clean:
	cd ext && cargo clean
