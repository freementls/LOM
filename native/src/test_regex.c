/* SPDX-License-Identifier: Apache-2.0 — native /pattern/flags smoke */
#include "lom.h"
#include <stdio.h>
#include <string.h>

static int expect(lom_doc *d, const char *sel, size_t want) {
	lom_match_list m;
	lom_match_list_init(&m);
	lom_status st = lom_doc_get(d, sel, &m);
	int ok = (st == LOM_OK && m.count == want);
	printf("%s  sel=%s matches=%zu want=%zu st=%d\n", ok ? "PASS" : "FAIL", sel, m.count, want, (int)st);
	lom_match_list_free(&m);
	return ok ? 0 : 1;
}

int main(void) {
	const char *xml =
		"<big><person age=\"16\"><name>sally</name><hobby>skiing</hobby></person>"
		"<person age=\"21\"><name>bob</name><hobby>swimming</hobby></person>"
		"<person age=\"18\"><name>sam</name><hobby>sleeping</hobby></person></big>";
	lom_doc *d = lom_doc_create(xml, strlen(xml));
	if(!d || lom_doc_status(d) != LOM_OK) {
		fprintf(stderr, "create failed: %s\n", d ? lom_doc_error(d) : "null");
		return 1;
	}
	int fails = 0;
	fails += expect(d, "hobby%=/s\\w+/", 3);
	fails += expect(d, "hobby^=/s/", 3);
	fails += expect(d, "hobby$=/ing/", 3);
	fails += expect(d, "hobby!=/sleep/", 2);
	fails += expect(d, "name%=/^sal/i", 1);
	fails += expect(d, "name=/^sally$/i", 1);
	fails += expect(d, "name%=/Entity_1/", 0);
	fails += expect(d, "person@age%=/1[68]/", 2);
	fails += expect(d, "person_name%=/sa/", 2);
	lom_doc_free(d);

	const char *fx =
		"<w><e><name>Entity_10</name></e><e><name>Entity_20</name></e>"
		"<e><name>Other</name></e></w>";
	d = lom_doc_create(fx, strlen(fx));
	fails += expect(d, "name%=/Entity_1/", 1);
	fails += expect(d, "name=Entity#underscore#10", 1);
	fails += expect(d, "name=Entity_10", 0); /* raw _ splits the path */
	lom_doc_free(d);
	printf("%s (%d fails)\n", fails ? "SOME FAILED" : "all ok", fails);
	return fails ? 1 : 0;
}
