#!/usr/bin/env bash
# Memory soak: hammer one path with a mixed op workload while sampling the
# RSS of its worker processes. Verdict = bounded vs growing.
#
#   soak.sh <base-url> <process-pattern> [iterations] [sample-every]
#   soak.sh http://127.0.0.1:8089/api.php 'apache2'       3000
#   soak.sh http://127.0.0.1:8090/api     'parity/app[.]php' 3000
set -u
BASE="$1"; PAT="$2"; N="${3:-3000}"; EVERY="${4:-300}"
OPS=(crud query aggregate types bulk txn_commit txn_abort gridfs)

rss_kb() { ps -eo rss,args | grep -E "$PAT" | grep -v grep | awk '{s+=$1} END {print s+0}'; }

curl -s -o /dev/null "$BASE?op=reset"
declare -a SAMPLES=()
for ((i=1; i<=N; i++)); do
  op=${OPS[$((i % ${#OPS[@]}))]}
  code=$(curl -s -o /dev/null -w '%{http_code}' -m 30 "$BASE?op=$op")
  [ "$code" != 200 ] && { echo "FAIL iter=$i op=$op http=$code"; exit 1; }
  if (( i % EVERY == 0 )); then
    r=$(rss_kb); SAMPLES+=("$r")
    awk -v i="$i" -v r="$r" 'BEGIN { printf "iter=%-5d rss=%6.1fMB\n", i, r/1024 }'
  fi
done

FIRST=${SAMPLES[1]:-${SAMPLES[0]}}   # skip warmup sample
LAST=${SAMPLES[-1]}
GROW=$(( (LAST - FIRST) / 1024 ))
echo "RSS growth after warmup: ${GROW} MB over $N requests"
if (( GROW < 16 )); then echo "VERDICT: BOUNDED ✓"; else echo "VERDICT: GROWING ✗"; exit 1; fi
