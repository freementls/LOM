#!/usr/bin/env bash
# Commit gate: native tests + irregular + WAL restart + facade subset.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
make -C "$ROOT/native" test-all
# xpath_subset.txt is exercised by test_compat; keep the file in tree.
test -f "$ROOT/native/tests/xpath_subset.txt"
echo "ci ok lom $($ROOT/native/bin/lomc --help >/dev/null; "$ROOT/native/bin/test_compat" | tail -1)"
