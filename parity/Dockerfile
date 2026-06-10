# Dual-runtime parity rig — ONE image containing BOTH stacks:
#   C path   : Apache + mod_php + pecl ext-mongodb + mongodb/mongodb  → :8089
#   Rust path: ZealPHP + OpenSwoole + zealphp_mongodb.so + zealphp/mongodb → :8090
# Build context = the repo root (see parity/docker-compose.yml).
FROM php:8.4-apache AS rig

RUN apt-get update -qq \
    && apt-get install -y -qq --no-install-recommends \
        git unzip curl ca-certificates procps \
        build-essential autoconf pkg-config \
        libssl-dev libcurl4-openssl-dev zlib1g-dev \
        clang libclang-dev \
    && rm -rf /var/lib/apt/lists/*

# C driver (Apache path) — loaded globally via conf.d
RUN docker-php-ext-install -j"$(nproc)" sockets \
    && pecl install mongodb </dev/null \
    && docker-php-ext-enable mongodb

# OpenSwoole — built but NOT enabled globally; the ZealPHP process loads it
# explicitly so the Apache path stays a faithful classic mod_php stack.
RUN pecl install openswoole </dev/null

# Rust toolchain → build the zealphp_mongodb extension from this repo
RUN curl -sSf https://sh.rustup.rs | sh -s -- -y --profile minimal
ENV PATH="/root/.cargo/bin:${PATH}"
COPY ext /repo/ext
RUN cd /repo/ext && cargo build --release && rm -rf target/release/deps target/release/build

# Composer + pie
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && curl -sSL -o /usr/local/bin/pie https://github.com/php/pie/releases/latest/download/pie.phar \
    && chmod +x /usr/local/bin/pie

# ext-zealphp (the ZealPHP framework's runtime dep) via pie — then unload it
# globally; the ZealPHP process loads it explicitly (same reason as openswoole).
RUN pie install zealphp/ext </dev/null \
    && rm -f /usr/local/etc/php/conf.d/docker-php-ext-zealphp.ini

# The library under test + the parity app
COPY php /repo/php
COPY stubs /repo/stubs
COPY composer.json /repo/composer.json
COPY parity /repo/parity
RUN cd /repo/parity && composer install --ignore-platform-req=ext-openswoole --no-interaction --quiet

# Apache: C path on :8089, docroot = the parity app
RUN sed -i 's/^Listen 80$/Listen 8089/' /etc/apache2/ports.conf
COPY parity/docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf

COPY parity/docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 8089 8090
ENTRYPOINT ["/entrypoint.sh"]
