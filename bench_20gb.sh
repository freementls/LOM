#!/usr/bin/env bash
# Full measured 20GB native profile — no extrapolation.
# Construct + queries (region, descendant, attr, text, indexed, regex, parent)
# + writes (set, new_, delete, re-read, validate).
#
# Usage:
#   ./bench_20gb.sh
#   LOM_20GB_FORCE=1 ./bench_20gb.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
OUT="$ROOT/.bench_out"
FIX1="$OUT/fixture_1GB.xml"
FIX20="$OUT/fixture_20GB.xml"
REPORT="$OUT/bench_20gb.txt"
mkdir -p "$OUT"
export LOM_OPEN_TMPDIR="${LOM_OPEN_TMPDIR:-/var/tmp}"
make -C "$ROOT/native" -j"$(nproc)" >/dev/null

mem_avail_kb() { awk '/MemAvailable:/ {print $2}' /proc/meminfo; }

if [[ ! -f "$FIX1" ]]; then
  php "$ROOT/gen_perf_fixture.php" 1GB "$FIX1"
fi

PROBE=/tmp/lom_rss20_gate
cat > /tmp/lom_rss20_gate.c <<'EOF'
#include "lom.h"
#include <stdio.h>
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
NEED_KB=$(( RSS1_KB * 8 + 4*1024*1024 ))
{
  echo "gate: 1GB construct_rss_kb=$RSS1_KB opens=$OPENS1 MemAvailable_kb=$AVAIL_KB need_kb~$NEED_KB"
} | tee "$REPORT"

if [[ "${LOM_20GB_FORCE:-0}" != "1" ]]; then
  if (( AVAIL_KB < 12*1024*1024 )); then
    echo "SKIP 20GB: MemAvailable < 12 GiB (have $((AVAIL_KB/1024)) MiB)." | tee -a "$REPORT"
    exit 2
  fi
  if (( NEED_KB > AVAIL_KB * 60 / 100 )); then
    echo "SKIP 20GB: projected need $((NEED_KB/1024)) MiB > 60% of available." | tee -a "$REPORT"
    exit 2
  fi
fi

if [[ ! -f "$FIX20" ]]; then
  echo "Generating 20GB fixture..." | tee -a "$REPORT"
  php "$ROOT/gen_perf_fixture.php" 20GB "$FIX20" 2>>"$REPORT"
fi
BYTES=$(stat -c%s "$FIX20")
echo "fixture_bytes=$BYTES open_tmpdir=$LOM_OPEN_TMPDIR" | tee -a "$REPORT"

cat > /tmp/lom_20gb_full.c <<'EOF'
#include "lom.h"
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>

static long rss_kb(void) {
	FILE *f = fopen("/proc/self/status", "r");
	char l[256]; long v = -1;
	while(fgets(l, sizeof l, f)) if(sscanf(l, "VmRSS: %ld", &v) == 1) break;
	fclose(f);
	return v;
}
static double ms(void) {
	struct timespec t; clock_gettime(CLOCK_MONOTONIC, &t);
	return t.tv_sec * 1e3 + t.tv_nsec / 1e6;
}
static void line(const char *k, double tms, const char *extra) {
	printf("%s_ms=%.1f", k, tms);
	if(extra && *extra) printf(" %s", extra);
	printf(" rss_MB=%.1f\n", rss_kb() / 1024.0);
	fflush(stdout);
}

int main(int argc, char **argv) {
	const char *path = argv[1];
	double t0, t1;
	char extra[128];

	t0 = ms();
	lom_doc *d = lom_doc_create_file(path);
	t1 = ms();
	if(!d || lom_doc_status(d) != LOM_OK) {
		fprintf(stderr, "construct fail: %s\n", d ? lom_doc_error(d) : "null");
		return 1;
	}
	size_t len = 0; lom_doc_code(d, &len);
	snprintf(extra, sizeof(extra), "opens=%zu bytes=%zu", lom_doc_open_count(d), len);
	line("construct", t1 - t0, extra);

	struct { const char *key; const char *sel; int parent; } qs[] = {
		{"region_cold", "region", 0},
		{"region_warm", "region", 0},
		{"descendant_cold", "region_zone_entity_stats", 0},
		{"descendant_warm", "region_zone_entity_stats", 0},
		{"attr_kind_cold", "entity@kind", 0},
		{"attr_kind_warm", "entity@kind", 0},
		{"text_eq_cold", "name=/^Entity_42$/", 0},
		{"indexed_cold", "region[10]_zone[5]_entity[7]_stats", 0},
		{"regex_cold", "name%=/Entity_1/", 0},
		{"regex_warm", "name%=/Entity_1/", 0},
		{"parent_cold", "region_zone_entity_stats", 1},
	};
	for(size_t i = 0; i < sizeof(qs)/sizeof(qs[0]); i++) {
		lom_match_list m; lom_match_list_init(&m);
		t0 = ms();
		lom_status st = qs[i].parent
			? lom_doc_get_parent(d, qs[i].sel, &m)
			: lom_doc_get(d, qs[i].sel, &m);
		t1 = ms();
		snprintf(extra, sizeof(extra), "n=%zu st=%d", m.count, (int)st);
		line(qs[i].key, t1 - t0, extra);
		lom_match_list_free(&m);
	}

	const char *note = "region[1]_zone[3]_entity[4]_meta_note";
	const char *meta = "region[1]_zone[3]_entity[4]_meta";
	const char *bonus = "region[1]_zone[3]_entity[4]_meta_bonus_value";
	const char *del_sel = "region[1]_zone[3]_entity[4]_meta_bonus";

	lom_match_list m; lom_match_list_init(&m);
	t0 = ms(); lom_doc_get(d, note, &m); t1 = ms();
	snprintf(extra, sizeof(extra), "n=%zu", m.count);
	line("write_target_select", t1 - t0, extra);
	lom_match_list_free(&m);

	t0 = ms();
	lom_status wst = lom_doc_set_inner_text(d, note, "changed-note");
	t1 = ms();
	snprintf(extra, sizeof(extra), "st=%d", (int)wst);
	line("set_text", t1 - t0, extra);

	t0 = ms();
	wst = lom_doc_new_before_close(d, meta, "<bonus><name>surge</name><value>99</value></bonus>");
	t1 = ms();
	snprintf(extra, sizeof(extra), "st=%d", (int)wst);
	line("new_insert", t1 - t0, extra);

	lom_match_list_init(&m);
	t0 = ms(); lom_doc_get(d, bonus, &m); t1 = ms();
	snprintf(extra, sizeof(extra), "n=%zu", m.count);
	line("post_write_read", t1 - t0, extra);
	lom_match_list_free(&m);

	t0 = ms();
	wst = lom_doc_delete(d, del_sel);
	t1 = ms();
	snprintf(extra, sizeof(extra), "st=%d", (int)wst);
	line("delete", t1 - t0, extra);

	t0 = ms();
	int ok = lom_doc_brackets_balanced(d) ? 1 : 0;
	t1 = ms();
	snprintf(extra, sizeof(extra), "ok=%d", ok);
	line("validate", t1 - t0, extra);

	/* Save to a scratch path under .bench_out (not overwriting the fixture). */
	char savepath[512];
	snprintf(savepath, sizeof(savepath), "%s.bench_write_snip.xml", path);
	/* Only save a note that save works on mmap-private: write may be huge — skip full 20GB rewrite
	 * unless LOM_20GB_SAVE=1. */
	if(getenv("LOM_20GB_SAVE") && getenv("LOM_20GB_SAVE")[0] == '1') {
		t0 = ms();
		wst = lom_doc_save_file(d, savepath);
		t1 = ms();
		snprintf(extra, sizeof(extra), "st=%d path=%s", (int)wst, savepath);
		line("save", t1 - t0, extra);
	} else {
		printf("save_ms=skipped (set LOM_20GB_SAVE=1 to rewrite 20GB)\n");
	}

	lom_doc_free(d);
	printf("after_free_rss_MB=%.1f\n", rss_kb() / 1024.0);
	return 0;
}
EOF
cc -O2 -I"$ROOT/native/include" -o /tmp/lom_20gb_full /tmp/lom_20gb_full.c \
  -L"$ROOT/native/lib" -Wl,-rpath,"$ROOT/native/lib" -llom -lm -lpthread -lpcre2-8 -ldl

echo "## measured 20GB full suite" | tee -a "$REPORT"
/usr/bin/time -f 'wall_s=%e maxrss_kb=%M' /tmp/lom_20gb_full "$FIX20" 2>&1 | tee -a "$REPORT"
echo "Wrote $REPORT"
