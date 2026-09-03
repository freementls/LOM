#!/usr/bin/env bash
# Native accelerator ablations on a fixture (default: perf_fixture.xml).
# Usage: ./bench_ablation.sh [fixture] [repeats]
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
FIX="${1:-$ROOT/perf_fixture.xml}"
REP="${2:-3}"
LOMC="$ROOT/native/bin/lomc"
if [[ ! -x "$LOMC" ]]; then
  make -C "$ROOT/native" -j4
fi
if [[ ! -f "$FIX" ]]; then
  echo "Missing fixture: $FIX" >&2
  exit 1
fi

run_one() {
  local label="$1"
  shift
  echo "=== $label ==="
  env "$@" "$LOMC" "$FIX" --repeat "$REP" 2>&1 | head -20
  echo
}

run_one "all on (default)" \
  LOM_FMEM=1 LOM_FCACHE=1 LOM_PIECES=1 LOM_PARALLEL=1

run_one "fcache off" \
  LOM_FMEM=1 LOM_FCACHE=0 LOM_PIECES=1 LOM_PARALLEL=1

run_one "fmem off" \
  LOM_FMEM=0 LOM_FCACHE=1 LOM_PIECES=1 LOM_PARALLEL=1

run_one "parallel off" \
  LOM_FMEM=1 LOM_FCACHE=1 LOM_PIECES=1 LOM_PARALLEL=0

run_one "pieces off" \
  LOM_FMEM=1 LOM_FCACHE=1 LOM_PIECES=0 LOM_PARALLEL=0

run_one "all off" \
  LOM_FMEM=0 LOM_FCACHE=0 LOM_PIECES=0 LOM_PARALLEL=0
