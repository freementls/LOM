#!/usr/bin/env bash
# Full measured 20GB native profile — no extrapolation.
# Construct + queries (region, descendant, attr, text, indexed, regex)
# + writes (set, new_, delete, validate) + dest lom_doc_checkpoint
# (scratch fixture_20GB.xml.checkpoint.xml, then unlink; source fixture intact).
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
export LOM_SIDECAR="${LOM_SIDECAR:-1}"
export LOM_TILE="${LOM_TILE:-1}"
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

# Construct-only gates: recipe sidecar, no wholesale 21 GiB open table.
{
  echo "## construct-only gates"
  rm -f "$FIX1.lomidx" "$FIX1.lomopens" "$FIX20.lomidx" "$FIX20.lomopens"
  echo "--- 1GB ---"
  /usr/bin/time -f 'wall_s=%e maxrss_kb=%M' "$ROOT/native/bin/lomc" --construct-only "$FIX1"
  echo "--- 20GB ---"
  /usr/bin/time -f 'wall_s=%e maxrss_kb=%M' "$ROOT/native/bin/lomc" --construct-only "$FIX20"
} 2>&1 | tee -a "$REPORT"

cat > /tmp/lom_20gb_full.c <<'EOF'
#include "lom.h"
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
#include <time.h>
#include <unistd.h>

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
	size_t len = 0;
	{ struct stat st; if(stat(path, &st) == 0 && st.st_size > 0) len = (size_t)st.st_size; }
	snprintf(extra, sizeof(extra), "opens=%zu bytes=%zu sidecar=%d recipe=%d",
		lom_doc_open_count(d), len, lom_doc_from_sidecar(d), lom_doc_recipe_active(d));
	line("construct", t1 - t0, extra);

	/* Huge selectors stay count-only — a full get of tens of millions of
	 * matches would allocate offset pairs and blow RSS. */
	struct { const char *key; const char *sel; } counts[] = {
		{"region_cold", "region"},
		{"region_warm", "region"},
		{"descendant_cold", "region_zone_entity_stats"},
		{"descendant_warm", "region_zone_entity_stats"},
		{"attr_kind_cold", "entity@kind"},
		{"attr_kind_warm", "entity@kind"},
	};
	for(size_t i = 0; i < sizeof(counts)/sizeof(counts[0]); i++) {
		size_t n = 0;
		t0 = ms();
		lom_status st = lom_doc_count(d, counts[i].sel, &n);
		t1 = ms();
		snprintf(extra, sizeof(extra), "n=%zu st=%d", n, (int)st);
		line(counts[i].key, t1 - t0, extra);
	}

	struct { const char *key; const char *sel; } gets[] = {
		{"text_eq_cold", "entity_meta_name=Entity#underscore#42"},
		{"text_re_cold", "name=/^Entity_42$/"},
		{"indexed_cold", "region[10]_zone[5]_entity[7]_stats"},
	};
	for(size_t i = 0; i < sizeof(gets)/sizeof(gets[0]); i++) {
		lom_match_list m; lom_match_list_init(&m);
		t0 = ms();
		lom_status st = lom_doc_get(d, gets[i].sel, &m);
		t1 = ms();
		snprintf(extra, sizeof(extra), "n=%zu st=%d", m.count, (int)st);
		line(gets[i].key, t1 - t0, extra);
		lom_match_list_free(&m);
	}

	lom_doc_free(d);
	printf("after_free_rss_MB=%.1f\n", rss_kb() / 1024.0);

	/* Sidecar reload before any write (set/new_/delete unlink .lomidx). */
	t0 = ms();
	d = lom_doc_create_file(path);
	t1 = ms();
	if(!d || lom_doc_status(d) != LOM_OK) {
		fprintf(stderr, "reload fail: %s\n", d ? lom_doc_error(d) : "null");
		return 1;
	}
	snprintf(extra, sizeof(extra), "opens=%zu", lom_doc_open_count(d));
	line("sidecar_reload", t1 - t0, extra);
	{
		size_t n = 0;
		t0 = ms();
		lom_doc_count(d, "region_zone_entity_stats", &n);
		t1 = ms();
		snprintf(extra, sizeof(extra), "n=%zu", n);
		line("reload_descendant_count", t1 - t0, extra);
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

	/* Canonical rewrite of the edited doc (overlay streamed; does not flatten 21 GiB in RAM). */
	char savepath[512];
	snprintf(savepath, sizeof(savepath), "%s.checkpoint.xml", path);
	unlink(savepath);
	t0 = ms();
	wst = lom_doc_checkpoint(d, savepath);
	t1 = ms();
	{
		struct stat st;
		long long wbytes = (stat(savepath, &st) == 0) ? (long long)st.st_size : -1;
		snprintf(extra, sizeof(extra), "st=%d written=%lld", (int)wst, wbytes);
		line("checkpoint", t1 - t0, extra);
	}
	lom_doc_free(d);
	d = NULL;
	if(wst == LOM_OK) {
		char idxp[640];
		snprintf(idxp, sizeof idxp, "%s.lomidx", savepath);
		if(access(idxp, F_OK) == 0) {
			t0 = ms();
			lom_doc *ck = lom_doc_create_file(savepath);
			t1 = ms();
			size_t n = 0;
			double t2 = ms();
			if(ck) lom_doc_count(ck, "region", &n);
			double t3 = ms();
			snprintf(extra, sizeof(extra), "n=%zu recipe=%d sidecar=%d construct_ms=%.1f",
				n, ck ? lom_doc_recipe_active(ck) : -1,
				ck ? lom_doc_from_sidecar(ck) : -1, t1 - t0);
			line("checkpoint_reload", t3 - t2, extra);
			if(ck) lom_doc_free(ck);
		} else {
			printf("checkpoint_reload_ms=skipped (no dest sidecar)\n");
			fflush(stdout);
		}
	}
	{
		char extra_path[640];
		snprintf(extra_path, sizeof extra_path, "%s.lomidx", savepath);
		unlink(extra_path);
		snprintf(extra_path, sizeof extra_path, "%s.lomopens", savepath);
		unlink(extra_path);
		snprintf(extra_path, sizeof extra_path, "%s.lomwal", savepath);
		unlink(extra_path);
		unlink(savepath);
	}

	lom_doc_free(d);
	printf("after_reload_free_rss_MB=%.1f\n", rss_kb() / 1024.0);
	return 0;
}
EOF
cc -O2 -I"$ROOT/native/include" -o /tmp/lom_20gb_full /tmp/lom_20gb_full.c \
  -L"$ROOT/native/lib" -Wl,-rpath,"$ROOT/native/lib" -llom -lm -lpthread -lpcre2-8 -ldl

echo "## measured 20GB full suite" | tee -a "$REPORT"
/usr/bin/time -f 'wall_s=%e maxrss_kb=%M' /tmp/lom_20gb_full "$FIX20" 2>&1 | tee -a "$REPORT"
echo "Wrote $REPORT"
