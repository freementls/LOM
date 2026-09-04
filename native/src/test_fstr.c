/* SPDX-License-Identifier: Apache-2.0 — fractal string smoke test */
#include "lom.h"
#include "lom_fstr.h"

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

static int fail(const char *msg) {
	fprintf(stderr, "FAIL: %s\n", msg);
	return 1;
}

int main(void) {
	const char *xml =
		"<root>"
		"<a>alpha unique-needle-xyz</a>"
		"<b>bravo only-in-b</b>"
		"<c>charlie</c>"
		"</root>";
	size_t n = strlen(xml);
	lom_scan_result scan;
	lom_scan_result_init(&scan);
	if(lom_scan_indexes(xml, n, &scan, true) != LOM_OK)
		return fail("scan");

	/* Build a minimal CSR: root children = opens with parent_idx < 0 */
	size_t root_n = 0;
	for(size_t i = 0; i < scan.open_count; i++)
		if(scan.opens[i].parent_idx < 0) root_n++;
	uint32_t *root_ch = malloc(root_n * sizeof(uint32_t));
	size_t ri = 0;
	for(size_t i = 0; i < scan.open_count; i++)
		if(scan.opens[i].parent_idx < 0) root_ch[ri++] = (uint32_t)i;

	lom_fstr *f = lom_fstr_build(xml, n, scan.opens, scan.open_count,
		root_ch, root_n, NULL, NULL, 32);
	if(!f) return fail("build");
	if(lom_fstr_node_count(f) < 2) return fail("node count");

	const char *needle = "unique-needle-xyz";
	ssize_t off = lom_fstr_find(f, xml, n, needle, strlen(needle));
	if(off < 0) return fail("find miss");
	if(strncmp(xml + off, needle, strlen(needle)) != 0) return fail("find content");

	const char *missing = "zzz-not-present-qqq";
	if(lom_fstr_find(f, xml, n, missing, strlen(missing)) >= 0)
		return fail("false positive find");

	int64_t lo[32], hi[32];
	size_t ns = lom_fstr_candidate_spans(f, (const uint8_t *)"only-in-b", 9, lo, hi, 32);
	if(ns == 0) return fail("prune empty for present");
	ns = lom_fstr_candidate_spans(f, (const uint8_t *)"zzz-not-present-qqq", 19, lo, hi, 32);
	if(ns != 0) return fail("prune should reject missing");

	uint8_t probe[64];
	size_t pn = lom_fstr_regex_probe("foo.*unique-needle-xyz.*bar", 27, probe, sizeof(probe));
	if(pn < 10) return fail("regex probe");

	/* Doc path: LOM_FSTR on, cold regex */
	setenv("LOM_FSTR", "1", 1);
	lom_doc *doc = lom_doc_create(xml, n);
	if(!doc || lom_doc_status(doc) != LOM_OK) return fail("doc create");
	lom_match_list m;
	lom_match_list_init(&m);
	if(lom_doc_get(doc, "a%=/unique-needle/", &m) != LOM_OK) return fail("regex get");
	if(m.count != 1) {
		fprintf(stderr, "FAIL: regex count got %zu\n", m.count);
		return 1;
	}
	lom_match_list_free(&m);
	lom_doc_free(doc);

	lom_fstr_free(f);
	free(root_ch);
	lom_scan_result_free(&scan);
	printf("fstr ok (nodes prune + find + regex)\n");
	return 0;
}
