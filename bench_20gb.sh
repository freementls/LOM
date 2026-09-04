#!/usr/bin/env bash
# Measured 20GB native profile — no extrapolation.
# Runs only when MemAvailable looks sufficient vs a fresh 1GB RSS sample.
#
# Usage:
#   ./bench_20gb.sh              # gate + run if OK
#   LOM_ALLOW_HUGE=1 ./bench_20gb.sh   # same (compat)
#   LOM_20GB_FORCE=1 ./bench_20gb.sh   # skip gate (dangerous)
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
OUT="$ROOT/.bench_out"
FIX1="$OUT/fixture_1GB.xml"
FIX20="$OUT/fixture_20GB.xml"
REPORT="$OUT/bench_20gb.txt"
mkdir -p "$OUT"
make -C "$ROOT/native" -j"$(nproc)" >/dev/null

mem_avail_kb() { awk '/MemAvailable:/ {print $2}' /proc/meminfo; }

if [[ ! -f "$FIX1" ]]; then
  php "$ROOT/gen_perf_fixture.php" 1GB "$FIX1"
fi

# Sample construct RSS on 1GB with the huge-doc path (mmap opens, no attrs).
PROBE=/tmp/lom_rss20_gate
cat > /tmp/lom_rss20_gate.c <<'EOF'
#include "lom.h"
#include <stdio.h>
#include <stdlib.h>
static long rss_kb(void){
  FILE*f=fopen("/proc/self/status","r"); char l[256]; long v=-1;
  while(fgets(l,sizeof l,f)) if(sscanf(l,"VmRSS: %ld",&v)==1) break;
  fclose(f); return v;
}
int main(int c,char**a){
  lom_doc*d=lom_doc_create_file(a[1]);
  if(!d||lom_doc_status(d)!=LOM_OK){ fprintf(stderr,"fail\n"); return 1; }
  printf("%ld %zu\n", rss_kb(), lom_doc_open_count(d));
  lom_doc_free(d); return 0;
}
EOF
cc -O2 -I"$ROOT/native/include" -o "$PROBE" /tmp/lom_rss20_gate.c \
  -L"$ROOT/native/lib" -Wl,-rpath,"$ROOT/native/lib" -llom -lm -lpthread -lpcre2-8 -ldl
read -r RSS1_KB OPENS1 < <("$PROBE" "$FIX1")
AVAIL_KB="$(mem_avail_kb)"
# Need headroom for ~20× opens + tag rows + OS; require 12 GiB free and
# projected construct ceiling under 60% of available (empirical gate, not a result).
NEED_KB=$(( RSS1_KB * 8 + 4*1024*1024 ))
echo "gate: 1GB construct_rss_kb=$RSS1_KB opens=$OPENS1 MemAvailable_kb=$AVAIL_KB need_kb~$NEED_KB" | tee "$REPORT"

if [[ "${LOM_20GB_FORCE:-0}" != "1" ]]; then
  if (( AVAIL_KB < 12*1024*1024 )); then
    echo "SKIP 20GB: MemAvailable < 12 GiB (have $((AVAIL_KB/1024)) MiB). Free RAM and re-run." | tee -a "$REPORT"
    exit 2
  fi
  if (( NEED_KB > AVAIL_KB * 60 / 100 )); then
    echo "SKIP 20GB: projected need $((NEED_KB/1024)) MiB > 60% of available $((AVAIL_KB/1024)) MiB." | tee -a "$REPORT"
    exit 2
  fi
fi

if [[ ! -f "$FIX20" ]]; then
  echo "Generating 20GB fixture (streaming write)..." | tee -a "$REPORT"
  php "$ROOT/gen_perf_fixture.php" 20GB "$FIX20" 2>>"$REPORT"
fi
BYTES=$(stat -c%s "$FIX20")
echo "fixture_bytes=$BYTES" | tee -a "$REPORT"

# Slim native profile: construct + region + descendant (attrs off automatically).
cat > /tmp/lom_20gb_run.c <<'EOF'
#include "lom.h"
#include <stdio.h>
#include <stdlib.h>
#include <time.h>
static long rss_kb(void){
  FILE*f=fopen("/proc/self/status","r"); char l[256]; long v=-1;
  while(fgets(l,sizeof l,f)) if(sscanf(l,"VmRSS: %ld",&v)==1) break;
  fclose(f); return v;
}
static double ms(void){struct timespec t;clock_gettime(CLOCK_MONOTONIC,&t);return t.tv_sec*1e3+t.tv_nsec/1e6;}
int main(int c,char**a){
  double t0=ms();
  lom_doc*d=lom_doc_create_file(a[1]);
  double t1=ms();
  if(!d||lom_doc_status(d)!=LOM_OK){
    fprintf(stderr,"construct fail: %s\n", d?lom_doc_error(d):"null");
    return 1;
  }
  size_t len=0; lom_doc_code(d,&len);
  printf("construct_ms=%.1f opens=%zu bytes=%zu rss_MB=%.1f\n",
    t1-t0, lom_doc_open_count(d), len, rss_kb()/1024.0);
  lom_match_list m; lom_match_list_init(&m);
  t0=ms(); lom_doc_get(d,"region",&m); t1=ms();
  printf("region_cold_ms=%.1f n=%zu rss_MB=%.1f\n", t1-t0, m.count, rss_kb()/1024.0);
  lom_match_list_free(&m); lom_match_list_init(&m);
  t0=ms(); lom_doc_get(d,"region",&m); t1=ms();
  printf("region_warm_ms=%.1f n=%zu rss_MB=%.1f\n", t1-t0, m.count, rss_kb()/1024.0);
  lom_match_list_free(&m); lom_match_list_init(&m);
  t0=ms(); lom_doc_get(d,"region_zone_entity_stats",&m); t1=ms();
  printf("descendant_cold_ms=%.1f n=%zu rss_MB=%.1f\n", t1-t0, m.count, rss_kb()/1024.0);
  lom_match_list_free(&m);
  lom_doc_free(d);
  printf("after_free_rss_MB=%.1f\n", rss_kb()/1024.0);
  return 0;
}
EOF
cc -O2 -I"$ROOT/native/include" -o /tmp/lom_20gb_run /tmp/lom_20gb_run.c \
  -L"$ROOT/native/lib" -Wl,-rpath,"$ROOT/native/lib" -llom -lm -lpthread -lpcre2-8 -ldl

echo "## measured 20GB" | tee -a "$REPORT"
/usr/bin/time -f 'wall_s=%e maxrss_kb=%M' /tmp/lom_20gb_run "$FIX20" 2>&1 | tee -a "$REPORT"
echo "Wrote $REPORT"
