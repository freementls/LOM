#!/usr/bin/env bash
# Size-scaling suite for the paper (1MB, 100MB, 1GB; 20GB opt-in).
# Usage: ./bench_sizes.sh
#        LOM_ALLOW_HUGE=1 ./bench_sizes.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
mkdir -p "$ROOT/.bench_out"
REPORT="$ROOT/.bench_out/size_results.txt"
: > "$REPORT"

run_size() {
  local size="$1"
  local out="$ROOT/.bench_out/fixture_${size}.xml"
  echo "---- size=$size ----" | tee -a "$REPORT"
  if [[ ! -f "$out" ]]; then
    php "$ROOT/gen_perf_fixture.php" "$size" "$out" 2>>"$REPORT"
  else
    echo "reusing $out ($(stat -c%s "$out") bytes)" | tee -a "$REPORT"
  fi
  echo "## PHP perf_test" | tee -a "$REPORT"
  LOM_PERF_FIXTURE="$out" php "$ROOT/perf_test.php" >>"$REPORT" 2>&1 || true
  # Print a short summary to the console
  grep -E '^(construct|top-level|descendant|regex|set\(|new_|validate)' "$REPORT" | tail -20
  echo "## native lomc" | tee -a "$REPORT"
  "$ROOT/native/bin/lomc" "$out" --repeat 3 >>"$REPORT" 2>&1
  tail -20 "$REPORT"
  echo | tee -a "$REPORT"
}

make -C "$ROOT/native" -j4 >/dev/null
run_size 1MB
run_size 100MB
run_size 1GB
if [[ "${LOM_ALLOW_HUGE:-0}" == "1" ]]; then
  run_size 20GB
else
  echo "Skipping 20GB (set LOM_ALLOW_HUGE=1 to enable)." | tee -a "$REPORT"
fi
echo "Wrote $REPORT"
