#!/usr/bin/env bash
# Opt-in large-file profile. Does NOT generate 20GB by default.
# Usage:
#   ./bench_large.sh 100MB
#   ./bench_large.sh 1GB
#   LOM_ALLOW_HUGE=1 ./bench_large.sh 20GB   # requires ~20GB+ RAM
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
SIZE="${1:-100MB}"
case "$SIZE" in
  *GB)
    GB_NUM="${SIZE%GB}"
    if [[ "${LOM_ALLOW_HUGE:-0}" != "1" ]] && (( GB_NUM >= 10 )); then
      echo "Refusing ${SIZE} without LOM_ALLOW_HUGE=1 (needs huge RAM)." >&2
      exit 1
    fi
    ;;
esac
OUT="${ROOT}/.perf_fixture_${SIZE}.xml"
echo "Generating $OUT (~$SIZE)..."
php "$ROOT/gen_perf_fixture.php" "$SIZE" "$OUT"
echo "Running perf_test..."
LOM_PERF_FIXTURE="$OUT" php "$ROOT/perf_test.php"
echo "Running bench_vs_tools (personal bake-off)..."
LOM_PERF_FIXTURE="$OUT" php "$ROOT/bench_vs_tools.php"
