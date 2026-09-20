/* SPDX-License-Identifier: Apache-2.0 */
#include "lom.h"
#include <stdio.h>
#include <string.h>
#include <stdlib.h>

static int fails;

static void expect_sel(const char *xp, const char *want) {
	char out[256];
	lom_status st = lom_xpath_to_lom(xp, out, sizeof(out));
	if(st != LOM_OK || strcmp(out, want) != 0) {
		fprintf(stderr, "FAIL xpath '%s' -> '%s' want '%s' st=%d\n", xp, out, want, (int)st);
		fails++;
	}
}

static void load_subset_file(void) {
	const char *paths[] = {
		"tests/xpath_subset.txt",
		"native/tests/xpath_subset.txt",
		"../native/tests/xpath_subset.txt",
		NULL
	};
	FILE *f = NULL;
	for(int i = 0; paths[i]; i++) {
		f = fopen(paths[i], "r");
		if(f) break;
	}
	if(!f) return;
	char line[512];
	while(fgets(line, sizeof line, f)) {
		if(line[0] == '#' || line[0] == '\n') continue;
		char *tab = strchr(line, '\t');
		if(!tab) continue;
		*tab = 0;
		char *want = tab + 1;
		want[strcspn(want, "\r\n")] = 0;
		if(want[0] == 0) {
			char out[256];
			lom_status st = lom_xpath_to_lom(line, out, sizeof(out));
			if(st == LOM_OK) {
				fprintf(stderr, "FAIL xpath '%s' compiled to '%s' (want PARSE)\n", line, out);
				fails++;
			}
			continue;
		}
		expect_sel(line, want);
	}
	fclose(f);
}

int main(void) {
	expect_sel("//item", "item");
	expect_sel("/root/item", "root_item");
	expect_sel("//item[@id='i1']", "item@id=i1");
	expect_sel("//item[2]", "item[1]");
	expect_sel("//item/name", "item_name");
	load_subset_file();

	char css[256];
	if(lom_css_to_lom("item[id=i1]", css, sizeof(css)) != LOM_OK || strcmp(css, "item@id=i1") != 0) {
		fprintf(stderr, "FAIL css %s\n", css);
		fails++;
	}
	if(lom_css_to_lom("root item:nth-child(2)", css, sizeof(css)) != LOM_OK) {
		fprintf(stderr, "FAIL css nth\n");
		fails++;
	}

	if(lom_css_to_lom("item:last-of-type", css, sizeof(css)) != LOM_OK || strcmp(css, "item[$]") != 0) {
		fprintf(stderr, "FAIL css last-of-type %s\n", css);
		fails++;
	}

	const char *xml = "<root><item id=\"i1\"><name>A</name><value>10</value></item><item id=\"i2\"><name>B</name><value>30</value></item></root>";
	lom_doc *d = lom_doc_create(xml, strlen(xml));
	lom_match_list m;
	lom_match_list_init(&m);
	if(lom_doc_get_xpath(d, "//item[@id='i2']", &m) != LOM_OK || m.count != 1) {
		fprintf(stderr, "FAIL get_xpath n=%zu\n", m.count);
		fails++;
	}
	lom_match_list_free(&m);
	double sum = 0, avg = 0;
	if(lom_doc_sum(d, "value", &sum) != LOM_OK || sum != 40) {
		fprintf(stderr, "FAIL sum %g\n", sum);
		fails++;
	}
	if(lom_doc_average(d, "value", &avg) != LOM_OK || avg != 20) {
		fprintf(stderr, "FAIL avg %g\n", avg);
		fails++;
	}
	size_t cn = 0;
	if(lom_doc_count(d, "#item", &cn) != LOM_OK || cn != 2) {
		fprintf(stderr, "FAIL #item n=%zu\n", cn);
		fails++;
	}
	if(lom_doc_count(d, "name'item", &cn) != LOM_OK || cn != 2) {
		fprintf(stderr, "FAIL name'item n=%zu\n", cn);
		fails++;
	}
	if(lom_doc_count(d, "item[$]", &cn) != LOM_OK || cn != 1) {
		fprintf(stderr, "FAIL item[$] n=%zu\n", cn);
		fails++;
	}
	if(lom_doc_count(d, "name=Entity{underscore}10", &cn) != LOM_OK) {
		fprintf(stderr, "FAIL brace escape parse\n");
		fails++;
	}
	lom_doc_free(d);

	if(fails) return 1;
	printf("compat ok lom %s\n", lom_version());
	return 0;
}
