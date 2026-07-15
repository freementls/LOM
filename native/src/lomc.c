#define _GNU_SOURCE
#include "lom.h"

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>

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

int main(int argc, char **argv) {
	const char *path = argc > 1 ? argv[1] : "../perf_fixture.xml";
	printf("lomc %s  file=%s\n", lom_version(), path);

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
	size_t bytes = 0;
	lom_doc_code(doc, &bytes);
	char extra[128];
	snprintf(extra, sizeof(extra), "bytes=%zu opens=%zu", bytes, lom_doc_open_count(doc));
	report("construct + index (C)", t1 - t0, extra);

	struct {
		const char *label;
		const char *sel;
		int parent;
	} cases[] = {
		{"top-level repeated tag", "region", 0},
		{"descendant chain", "region/zone/entity/stats", 0},
		{"attribute existence", "entity@kind", 0},
		{"attribute value subtag combo", "entity/meta/name=Entity_42", 0},
		{"indexed tagname selector", "region[10]/zone[5]/entity[7]/stats", 0},
		{"parent reads", "region/zone/entity/stats", 1},
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

	const char *chain_note = "region[1]/zone[3]/entity[4]/meta/note";
	const char *chain_meta = "region[1]/zone[3]/entity[4]/meta";
	const char *chain_bonus = "region[1]/zone[3]/entity[4]/meta/bonus/value";

	lom_match_list m;
	lom_match_list_init(&m);
	t0 = now_ms();
	lom_doc_get(doc, chain_note, &m);
	t1 = now_ms();
	snprintf(extra, sizeof(extra), "matches=%zu", m.count);
	report("warm up write target select", t1 - t0, extra);
	lom_match_list_free(&m);

	t0 = now_ms();
	lom_status wst = lom_doc_set_inner_text(doc, chain_note, "changed-note");
	t1 = now_ms();
	snprintf(extra, sizeof(extra), "st=%d", (int)wst);
	report("set() after context warmup", t1 - t0, extra);

	t0 = now_ms();
	wst = lom_doc_new_before_close(doc, chain_meta, "<bonus><name>surge</name><value>99</value></bonus>");
	t1 = now_ms();
	snprintf(extra, sizeof(extra), "st=%d", (int)wst);
	report("new_() nested insert", t1 - t0, extra);

	lom_match_list_init(&m);
	t0 = now_ms();
	lom_doc_get(doc, chain_bonus, &m);
	t1 = now_ms();
	snprintf(extra, sizeof(extra), "matches=%zu", m.count);
	report("post-write descendant read", t1 - t0, extra);
	lom_match_list_free(&m);

	t0 = now_ms();
	bool ok = lom_doc_brackets_balanced(doc);
	t1 = now_ms();
	snprintf(extra, sizeof(extra), "result=%s", ok ? "true" : "false");
	report("validate() after writes", t1 - t0, extra);

	lom_doc_free(doc);
	return 0;
}
