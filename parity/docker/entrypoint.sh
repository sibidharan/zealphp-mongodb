#!/usr/bin/env bash
# Boots BOTH runtimes, waits for readiness, runs the parity diff and
# (optionally, SOAK=1) the RSS-bounded memory soaks. Exit code = verdict.
set -u
export PARITY_MONGODB_URI="${PARITY_MONGODB_URI:-mongodb://mongo:27017/?replicaSet=rs0&directConnection=true}"
EXT_DIR=$(php-config --extension-dir)

echo "── C path: Apache + mod_php + ext-mongodb $(php -r 'echo phpversion("mongodb");') on :8089"
# Apache needs the env var in ITS environment for PassEnv to forward it.
echo "export PARITY_MONGODB_URI=\"$PARITY_MONGODB_URI\"" >> /etc/apache2/envvars
apache2ctl start 2>&1 | grep -v AH00558 || true

echo "── Rust path: ZealPHP + OpenSwoole + zealphp_mongodb on :8090"
php -n \
    -d extension_dir="$EXT_DIR" \
    -d extension=sockets.so \
    -d extension=openswoole.so \
    -d extension=zealphp.so \
    -d extension=/repo/ext/target/release/libzealphp_mongodb.so \
    /repo/parity/app.php > /tmp/zeal.log 2>&1 &
ZEAL_PID=$!

# Readiness: both paths answer the cheap reset op.
for i in $(seq 1 30); do
  a=$(curl -s -m 5 -o /dev/null -w '%{http_code}' "http://127.0.0.1:8089/api.php?op=reset" || true)
  z=$(curl -s -m 5 -o /dev/null -w '%{http_code}' "http://127.0.0.1:8090/api?op=reset" || true)
  [ "$a" = 200 ] && [ "$z" = 200 ] && break
  [ "$i" = 30 ] && { echo "FATAL: paths not ready (apache=$a zeal=$z)"; tail -20 /tmp/zeal.log; exit 2; }
  sleep 2
done

echo
echo "════ PARITY: every op group, C vs Rust, deep-diffed ════"
php /repo/parity/scripts/run-parity.php http://127.0.0.1:8089/api.php http://127.0.0.1:8090/api
PARITY_RC=$?

SOAK_RC=0
if [ "${SOAK:-0}" = "1" ]; then
  N="${SOAK_N:-3000}"
  echo; echo "════ MEMORY SOAK: Apache/C path ($N mixed-op requests) ════"
  bash /repo/parity/scripts/soak.sh http://127.0.0.1:8089/api.php 'apache2' "$N" || SOAK_RC=1
  echo; echo "════ MEMORY SOAK: ZealPHP/Rust path ($N mixed-op requests) ════"
  bash /repo/parity/scripts/soak.sh http://127.0.0.1:8090/api 'parity/app[.]php' "$N" || SOAK_RC=1
fi

kill "$ZEAL_PID" 2>/dev/null
[ "$PARITY_RC" = 0 ] && [ "$SOAK_RC" = 0 ] && echo "VERDICT: FULL PARITY ✓" || echo "VERDICT: DIVERGENCE ✗"
exit $(( PARITY_RC + SOAK_RC ))
