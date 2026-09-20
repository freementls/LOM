#include "lom.h"

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
#include <time.h>

static const char *PAPER_SET_SEL = "region[1]_zone[3]_entity[4]_meta_note";
static const char *PAPER_SET_TEXT = "changed-note";
static const char *PAPER_NEW_SEL = "region[1]_zone[3]_entity[4]_meta";
static const char *PAPER_NEW_FRAG = "<bonus><name>surge</name><value>99</value></bonus>";
/* Recipe docs do not expand the virtual open table on new_, so a freshly
 * inserted bonus tag is not addressable. Read the set() target instead. */
static const char *PAPER_POST_SEL = "region[1]_zone[3]_entity[4]_meta_note";
static const char *PAPER_INDEXED = "region[10]_zone[5]_entity[7]_stats";
static const char *PAPER_ENTITY42 = "entity_meta_name=Entity#underscore#42";

static double now_ms(void) {
	struct timespec ts;
	clock_gettime(CLOCK_MONOTONIC, &ts);
	return (double)ts.tv_sec * 1000.0 + (double)ts.tv_nsec / 1e6;
}

static void report(const char *label, double ms, const char *extra) {
	printf("%-42s: %10.2f ms", label, ms);
	if(extra && *extra) printf("  %s", extra);
	printf("\n");
}

static size_t file_bytes(const char *path) {
	struct stat st;
	if(stat(path, &st) == 0 && st.st_size > 0) return (size_t)st.st_size;
	return 0;
}

static void json_doc_fields(lom_doc *doc, double construct_ms, size_t bytes) {
	double census = 0, sample = 0, persist = 0;
	lom_doc_construct_phases(doc, &census, &sample, &persist);
	printf("\"version\":\"%s\",\"construct_ms\":%.3f,\"census_ms\":%.3f,"
		"\"sample_ms\":%.3f,\"persist_ms\":%.3f,\"opens\":%zu,"
		"\"sidecar\":%d,\"recipe\":%d,\"bytes\":%zu",
		lom_version(), construct_ms, census, sample, persist,
		lom_doc_open_count(doc), lom_doc_from_sidecar(doc),
		lom_doc_recipe_active(doc), bytes);
}

static void json_one(lom_doc *doc, double construct_ms, size_t bytes,
	const char *op, double query_ms, double warm_ms, double write_ms,
	size_t n, int st)
{
	printf("{\"ok\":true,\"op\":\"%s\",", op);
	json_doc_fields(doc, construct_ms, bytes);
	printf(",\"query_ms\":%.3f,\"warm_ms\":%.3f,\"write_ms\":%.3f,"
		"\"n\":%zu,\"st\":%d,\"mode\":\"%s\"}\n",
		query_ms, warm_ms, write_ms, n, st, op);
}

typedef struct {
	const char *name;
	double ms;
	size_t n;
	int st;
} suite_row;

static void json_suite(lom_doc *doc, double construct_ms, size_t bytes,
	suite_row *rows, size_t nrow)
{
	printf("{\"ok\":true,\"op\":\"suite\",");
	json_doc_fields(doc, construct_ms, bytes);
	printf(",\"query_ms\":0,\"warm_ms\":0,\"write_ms\":0,\"n\":%zu,\"st\":0,"
		"\"mode\":\"suite\",\"ops\":[", lom_doc_open_count(doc));
	for(size_t i = 0; i < nrow; i++) {
		if(i) printf(",");
		printf("{\"name\":\"%s\",\"ms\":%.3f,\"n\":%zu,\"st\":%d}",
			rows[i].name, rows[i].ms, rows[i].n, rows[i].st);
	}
	printf("]}\n");
}

static void add_row(suite_row *rows, size_t *n, size_t cap,
	const char *name, double ms, size_t count, int st)
{
	if(*n >= cap) return;
	rows[*n].name = name;
	rows[*n].ms = ms;
	rows[*n].n = count;
	rows[*n].st = st;
	(*n)++;
}

static void bench_get_ois(lom_doc *doc, const char *sel,
	const char *cold_name, const char *warm_name,
	const char *cold_label, const char *warm_label,
	suite_row *rows, size_t *nrow, size_t cap, int json)
{
	char extra[128];
	lom_oi_list ois;
	lom_oi_list_init(&ois);
	double t0 = now_ms();
	lom_status st = lom_doc_get_ois(doc, sel, &ois);
	double t1 = now_ms();
	add_row(rows, nrow, cap, cold_name, t1 - t0, ois.count, (int)st);
	if(!json) {
		snprintf(extra, sizeof(extra), "n=%zu st=%d", ois.count, (int)st);
		report(cold_label, t1 - t0, extra);
	}
	lom_oi_list_free(&ois);
	lom_oi_list_init(&ois);
	t0 = now_ms();
	st = lom_doc_get_ois(doc, sel, &ois);
	t1 = now_ms();
	add_row(rows, nrow, cap, warm_name, t1 - t0, ois.count, (int)st);
	if(!json) {
		snprintf(extra, sizeof(extra), "n=%zu", ois.count);
		report(warm_label, t1 - t0, extra);
	}
	lom_oi_list_free(&ois);
}

static void run_paper_suite(lom_doc *doc, double construct_ms, size_t bytes, int json) {
	suite_row rows[24];
	size_t nrow = 0;
	char extra[128];
	double t0, t1;
	size_t n = 0;
	lom_status st;

	add_row(rows, &nrow, 24, "construct", construct_ms, lom_doc_open_count(doc), 0);

	t0 = now_ms();
	st = lom_doc_count(doc, "region_zone_entity_stats", &n);
	t1 = now_ms();
	add_row(rows, &nrow, 24, "descendant_count", t1 - t0, n, (int)st);
	if(!json) {
		snprintf(extra, sizeof(extra), "n=%zu st=%d", n, (int)st);
		report("descendant count (no match list)", t1 - t0, extra);
	}

	t0 = now_ms();
	st = lom_doc_count(doc, "region", &n);
	t1 = now_ms();
	add_row(rows, &nrow, 24, "region_count", t1 - t0, n, (int)st);
	if(!json) {
		snprintf(extra, sizeof(extra), "n=%zu st=%d", n, (int)st);
		report("region count", t1 - t0, extra);
	}

	{
		lom_match_list m;
		lom_match_list_init(&m);
		t0 = now_ms();
		st = lom_doc_get(doc, PAPER_INDEXED, &m);
		t1 = now_ms();
		add_row(rows, &nrow, 24, "indexed_get_cold", t1 - t0, m.count, (int)st);
		if(!json) {
			snprintf(extra, sizeof(extra), "n=%zu st=%d", m.count, (int)st);
			report("indexed get (cold)", t1 - t0, extra);
		}
		lom_match_list_free(&m);
		lom_match_list_init(&m);
		t0 = now_ms();
		st = lom_doc_get(doc, PAPER_INDEXED, &m);
		t1 = now_ms();
		add_row(rows, &nrow, 24, "indexed_get_warm", t1 - t0, m.count, (int)st);
		if(!json) {
			snprintf(extra, sizeof(extra), "n=%zu", m.count);
			report("indexed get (warm)", t1 - t0, extra);
		}
		lom_match_list_free(&m);
	}

	bench_get_ois(doc, PAPER_INDEXED,
		"indexed_ois_cold", "indexed_ois_warm",
		"indexed ois (cold)", "indexed ois (warm)",
		rows, &nrow, 24, json);

	{
		lom_match_list m;
		lom_match_list_init(&m);
		t0 = now_ms();
		st = lom_doc_get(doc, PAPER_ENTITY42, &m);
		t1 = now_ms();
		add_row(rows, &nrow, 24, "entity_42_get_cold", t1 - t0, m.count, (int)st);
		if(!json) {
			snprintf(extra, sizeof(extra), "n=%zu st=%d", m.count, (int)st);
			report("Entity_42 get (cold)", t1 - t0, extra);
		}
		lom_match_list_free(&m);
		lom_match_list_init(&m);
		t0 = now_ms();
		st = lom_doc_get(doc, PAPER_ENTITY42, &m);
		t1 = now_ms();
		add_row(rows, &nrow, 24, "entity_42_get_warm", t1 - t0, m.count, (int)st);
		if(!json) {
			snprintf(extra, sizeof(extra), "n=%zu", m.count);
			report("Entity_42 get (warm)", t1 - t0, extra);
		}
		lom_match_list_free(&m);
	}

	bench_get_ois(doc, PAPER_ENTITY42,
		"entity_42_ois_cold", "entity_42_ois_warm",
		"Entity_42 ois (cold)", "Entity_42 ois (warm)",
		rows, &nrow, 24, json);

	t0 = now_ms();
	st = lom_doc_count(doc, "entity@kind", &n);
	t1 = now_ms();
	add_row(rows, &nrow, 24, "entity_kind_count", t1 - t0, n, (int)st);
	if(!json) {
		snprintf(extra, sizeof(extra), "n=%zu st=%d", n, (int)st);
		report("entity@kind count", t1 - t0, extra);
	}

	{
		lom_oi_list ois;
		lom_oi_list_init(&ois);
		t0 = now_ms();
		lom_doc_get_ois(doc, PAPER_SET_SEL, &ois);
		t1 = now_ms();
		if(!json) {
			snprintf(extra, sizeof(extra), "n=%zu", ois.count);
			report("warm up write target select", t1 - t0, extra);
		}
		lom_oi_list_free(&ois);
	}

	t0 = now_ms();
	st = lom_doc_set_inner_text(doc, PAPER_SET_SEL, PAPER_SET_TEXT);
	t1 = now_ms();
	add_row(rows, &nrow, 24, "set", t1 - t0, 0, (int)st);
	if(!json) {
		snprintf(extra, sizeof(extra), "st=%d", (int)st);
		report("set() after context warmup", t1 - t0, extra);
	}

	t0 = now_ms();
	st = lom_doc_new_before_close(doc, PAPER_NEW_SEL, PAPER_NEW_FRAG);
	t1 = now_ms();
	add_row(rows, &nrow, 24, "new_", t1 - t0, 0, (int)st);
	if(!json) {
		snprintf(extra, sizeof(extra), "st=%d", (int)st);
		report("new_() nested insert", t1 - t0, extra);
	}

	{
		lom_match_list m;
		lom_match_list_init(&m);
		t0 = now_ms();
		st = lom_doc_get(doc, PAPER_POST_SEL, &m);
		t1 = now_ms();
		add_row(rows, &nrow, 24, "post_write_get", t1 - t0, m.count, (int)st);
		if(!json) {
			snprintf(extra, sizeof(extra), "matches=%zu", m.count);
			report("post-write descendant read", t1 - t0, extra);
		}
		lom_match_list_free(&m);
	}

	t0 = now_ms();
	bool ok = lom_doc_brackets_balanced(doc);
	t1 = now_ms();
	add_row(rows, &nrow, 24, "validate", t1 - t0, ok ? 1 : 0, ok ? 0 : 1);
	if(!json) {
		snprintf(extra, sizeof(extra), "result=%s", ok ? "true" : "false");
		report("validate() after writes", t1 - t0, extra);
	}

	if(json) json_suite(doc, construct_ms, bytes, rows, nrow);
}

int main(int argc, char **argv) {
	int construct_only = 0;
	int count_only = 0;
	int json = 0;
	int warm = 0;
	int parent = 0;
	const char *op = NULL;
	const char *sel = NULL;
	const char *write = NULL;
	const char *path = NULL;

	/* lomc never saves. Keep on-disk sidecar so paper writes cannot
	 * force a 20 GB reconstruct. */
	setenv("LOM_SIDECAR_KEEP", "1", 1);

	for(int i = 1; i < argc; i++) {
		if(strcmp(argv[i], "--construct-only") == 0) construct_only = 1;
		else if(strcmp(argv[i], "--count") == 0) count_only = 1;
		else if(strcmp(argv[i], "--json") == 0) json = 1;
		else if(strcmp(argv[i], "--warm") == 0) warm = 1;
		else if(strcmp(argv[i], "--parent") == 0) parent = 1;
		else if(strcmp(argv[i], "--op") == 0 && i + 1 < argc) op = argv[++i];
		else if(strncmp(argv[i], "--op=", 5) == 0) op = argv[i] + 5;
		else if(strcmp(argv[i], "--sel") == 0 && i + 1 < argc) sel = argv[++i];
		else if(strncmp(argv[i], "--sel=", 6) == 0) sel = argv[i] + 6;
		else if(strcmp(argv[i], "--write") == 0 && i + 1 < argc) write = argv[++i];
		else if(strncmp(argv[i], "--write=", 8) == 0) write = argv[i] + 8;
		else path = argv[i];
	}
	if(!path) path = "../perf_fixture.xml";

	if(!op) {
		if(construct_only && !sel) op = "construct";
		else if(count_only) op = "count";
		else if(warm) op = "warm";
		else if(parent) op = "parent";
		else if(sel) op = "get";
		else op = "legacy";
	}

	if(!json) printf("lomc %s  file=%s\n", lom_version(), path);

	double t0 = now_ms();
	lom_doc *doc = lom_doc_create_file(path);
	double t1 = now_ms();
	if(!doc) {
		fprintf(stderr, "failed to load %s\n", path);
		return 1;
	}
	if(lom_doc_status(doc) != LOM_OK) {
		fprintf(stderr, "index error: %s\n", lom_doc_error(doc));
		lom_doc_free(doc);
		return 1;
	}
	size_t bytes = file_bytes(path);
	char extra[128];
	snprintf(extra, sizeof(extra), "bytes=%zu opens=%zu sidecar=%d recipe=%d",
		bytes, lom_doc_open_count(doc), lom_doc_from_sidecar(doc),
		lom_doc_recipe_active(doc));
	double construct_ms = t1 - t0;
	if(!json) report("construct + index (C)", construct_ms, extra);
	{
		double c = 0, s = 0, p = 0;
		lom_doc_construct_phases(doc, &c, &s, &p);
		if((c > 0 || s > 0 || p > 0) && !json) {
			snprintf(extra, sizeof(extra), "census=%.1f sample=%.1f persist=%.1f", c, s, p);
			report("construct phases", 0, extra);
		}
	}

	if(strcmp(op, "construct") == 0) {
		if(json) json_one(doc, construct_ms, bytes, "construct", 0, 0, 0,
			lom_doc_open_count(doc), 0);
		lom_doc_free(doc);
		return 0;
	}

	if(strcmp(op, "suite") == 0) {
		run_paper_suite(doc, construct_ms, bytes, json);
		lom_doc_free(doc);
		return 0;
	}

	if(strcmp(op, "legacy") == 0) {
		/* Original lomc default: broader cold/warm gets, then paper writes. */
		{
			size_t n = 0;
			t0 = now_ms();
			lom_status cst = lom_doc_count(doc, "region_zone_entity_stats", &n);
			t1 = now_ms();
			snprintf(extra, sizeof(extra), "n=%zu st=%d", n, (int)cst);
			report("descendant count (no match list)", t1 - t0, extra);

			lom_oi_list ois;
			lom_oi_list_init(&ois);
			t0 = now_ms();
			cst = lom_doc_get_ois(doc, "region_zone_entity_stats", &ois);
			t1 = now_ms();
			snprintf(extra, sizeof(extra), "n=%zu st=%d", ois.count, (int)cst);
			report("descendant open-index list", t1 - t0, extra);
			lom_oi_list_free(&ois);
		}

		struct {
			const char *label;
			const char *sel;
			int parent;
		} cases[] = {
			{"top-level repeated tag", "region", 0},
			{"descendant chain", "region_zone_entity_stats", 0},
			{"attribute existence", "entity@kind", 0},
			{"attribute value subtag combo", "entity_meta_name=Entity#underscore#42", 0},
			{"indexed tagname selector", "region[10]_zone[5]_entity[7]_stats", 0},
			{"parent reads", "region_zone_entity_stats", 1},
		};

		for(size_t i = 0; i < sizeof(cases)/sizeof(cases[0]); i++) {
			lom_match_list m;
			lom_match_list_init(&m);
			t0 = now_ms();
			lom_status st = cases[i].parent
				? lom_doc_get_parent(doc, cases[i].sel, &m)
				: lom_doc_get(doc, cases[i].sel, &m);
			t1 = now_ms();
			char e1[64];
			snprintf(e1, sizeof(e1), "matches=%zu st=%d", m.count, (int)st);
			char lab[64];
			snprintf(lab, sizeof(lab), "%s (cold)", cases[i].label);
			report(lab, t1 - t0, e1);

			lom_match_list_free(&m);
			lom_match_list_init(&m);
			t0 = now_ms();
			st = cases[i].parent
				? lom_doc_get_parent(doc, cases[i].sel, &m)
				: lom_doc_get(doc, cases[i].sel, &m);
			t1 = now_ms();
			snprintf(e1, sizeof(e1), "matches=%zu", m.count);
			snprintf(lab, sizeof(lab), "%s (warm)", cases[i].label);
			report(lab, t1 - t0, e1);
			lom_match_list_free(&m);
		}

		{
			lom_oi_list ois;
			lom_oi_list_init(&ois);
			t0 = now_ms();
			lom_doc_get_ois(doc, PAPER_SET_SEL, &ois);
			t1 = now_ms();
			snprintf(extra, sizeof(extra), "n=%zu", ois.count);
			report("warm up write target select", t1 - t0, extra);
			lom_oi_list_free(&ois);

			t0 = now_ms();
			lom_status wst = lom_doc_set_inner_text(doc, PAPER_SET_SEL, PAPER_SET_TEXT);
			t1 = now_ms();
			snprintf(extra, sizeof(extra), "st=%d", (int)wst);
			report("set() after context warmup", t1 - t0, extra);

			t0 = now_ms();
			wst = lom_doc_new_before_close(doc, PAPER_NEW_SEL, PAPER_NEW_FRAG);
			t1 = now_ms();
			snprintf(extra, sizeof(extra), "st=%d", (int)wst);
			report("new_() nested insert", t1 - t0, extra);

			lom_match_list m;
			lom_match_list_init(&m);
			t0 = now_ms();
			lom_doc_get(doc, PAPER_POST_SEL, &m);
			t1 = now_ms();
			snprintf(extra, sizeof(extra), "matches=%zu", m.count);
			report("post-write descendant read", t1 - t0, extra);
			lom_match_list_free(&m);

			t0 = now_ms();
			bool ok = lom_doc_brackets_balanced(doc);
			t1 = now_ms();
			snprintf(extra, sizeof(extra), "result=%s", ok ? "true" : "false");
			report("validate() after writes", t1 - t0, extra);
		}
		lom_doc_free(doc);
		return 0;
	}

	if(strcmp(op, "validate") == 0) {
		t0 = now_ms();
		bool ok = lom_doc_brackets_balanced(doc);
		t1 = now_ms();
		if(json) json_one(doc, construct_ms, bytes, "validate", t1 - t0, 0, 0,
			ok ? 1 : 0, ok ? 0 : 1);
		else {
			snprintf(extra, sizeof(extra), "result=%s", ok ? "true" : "false");
			report("validate()", t1 - t0, extra);
		}
		lom_doc_free(doc);
		return 0;
	}

	if(strcmp(op, "set") == 0 || strcmp(op, "new_") == 0 || strcmp(op, "delete") == 0) {
		if(!sel) sel = (strcmp(op, "new_") == 0) ? PAPER_NEW_SEL : PAPER_SET_SEL;
		if(!write) write = (strcmp(op, "new_") == 0) ? PAPER_NEW_FRAG : PAPER_SET_TEXT;
		{
			lom_oi_list ois;
			lom_oi_list_init(&ois);
			lom_doc_get_ois(doc, sel, &ois);
			lom_oi_list_free(&ois);
		}
		lom_status wst;
		t0 = now_ms();
		if(strcmp(op, "set") == 0)
			wst = lom_doc_set_inner_text(doc, sel, write);
		else if(strcmp(op, "new_") == 0)
			wst = lom_doc_new_before_close(doc, sel, write);
		else
			wst = lom_doc_delete(doc, sel);
		t1 = now_ms();
		if(json) json_one(doc, construct_ms, bytes, op, 0, 0, t1 - t0, 0, (int)wst);
		else {
			snprintf(extra, sizeof(extra), "st=%d sel=%s", (int)wst, sel);
			report(op, t1 - t0, extra);
		}
		lom_doc_free(doc);
		return wst == LOM_OK ? 0 : 2;
	}

	if(!sel) {
		fprintf(stderr, "missing --sel for op=%s\n", op);
		lom_doc_free(doc);
		return 1;
	}

	size_t n = 0;
	lom_status st;
	double qms = 0, wms = 0;

	if(strcmp(op, "count") == 0) {
		t0 = now_ms();
		st = lom_doc_count(doc, sel, &n);
		t1 = now_ms();
		qms = t1 - t0;
		if(!json) {
			snprintf(extra, sizeof(extra), "n=%zu st=%d sel=%s", n, (int)st, sel);
			report("count", qms, extra);
		}
	} else if(strcmp(op, "ois") == 0) {
		lom_oi_list ois;
		lom_oi_list_init(&ois);
		t0 = now_ms();
		st = lom_doc_get_ois(doc, sel, &ois);
		t1 = now_ms();
		qms = t1 - t0;
		n = ois.count;
		if(!json) {
			snprintf(extra, sizeof(extra), "n=%zu st=%d sel=%s", n, (int)st, sel);
			report("ois", qms, extra);
		}
		lom_oi_list_free(&ois);
	} else if(strcmp(op, "parent") == 0) {
		lom_match_list m;
		lom_match_list_init(&m);
		t0 = now_ms();
		st = lom_doc_get_parent(doc, sel, &m);
		t1 = now_ms();
		qms = t1 - t0;
		n = m.count;
		if(!json) {
			snprintf(extra, sizeof(extra), "n=%zu st=%d sel=%s", n, (int)st, sel);
			report("parent", qms, extra);
		}
		lom_match_list_free(&m);
	} else if(strcmp(op, "warm") == 0) {
		lom_match_list m;
		lom_match_list_init(&m);
		t0 = now_ms();
		st = lom_doc_get(doc, sel, &m);
		t1 = now_ms();
		qms = t1 - t0;
		n = m.count;
		lom_match_list_free(&m);
		lom_match_list_init(&m);
		t0 = now_ms();
		st = lom_doc_get(doc, sel, &m);
		t1 = now_ms();
		wms = t1 - t0;
		n = m.count;
		if(!json) {
			snprintf(extra, sizeof(extra), "n=%zu st=%d sel=%s", n, (int)st, sel);
			report("get cold", qms, extra);
			report("get warm", wms, extra);
		}
		lom_match_list_free(&m);
	} else {
		/* get */
		lom_match_list m;
		lom_match_list_init(&m);
		t0 = now_ms();
		st = lom_doc_get(doc, sel, &m);
		t1 = now_ms();
		qms = t1 - t0;
		n = m.count;
		if(!json) {
			snprintf(extra, sizeof(extra), "n=%zu st=%d sel=%s", n, (int)st, sel);
			report("get", qms, extra);
			if(m.count && m.count <= 8) {
				for(size_t i = 0; i < m.count; i++)
					printf("  hit[%zu] off=%lld end=%lld\n", i,
						(long long)m.items[i].offset, (long long)m.items[i].end_off);
			}
		}
		lom_match_list_free(&m);
	}

	if(json) json_one(doc, construct_ms, bytes, op, qms, wms, 0, n, (int)st);
	lom_doc_free(doc);
	return 0;
}
