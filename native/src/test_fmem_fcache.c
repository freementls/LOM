/* SPDX-License-Identifier: Apache-2.0 — smoke test for fmem / fcache / pieces */
#include "lom.h"
#include <stdio.h>
#include <string.h>

int main(void) {
	lom_fmem *m = lom_fmem_create(64);
	const char *a = lom_fmem_intern(m, "hello-world-buffer-xxxxx", 23);
	const char *b = lom_fmem_intern(m, "hello-world-buffer-xxxxx", 23);
	if(a != b) {
		fprintf(stderr, "fmem intern pointer mismatch\n");
		return 1;
	}
	if(lom_fmem_hits(m) < 1) {
		fprintf(stderr, "fmem expected a hit\n");
		return 1;
	}
	lom_fmem_free(m);

	lom_fcache *c = lom_fcache_create(32);
	const char key[] = "pat\0flags";
	const char val[] = "/ski.*/i";
	lom_fcache_put(c, key, sizeof(key), val, sizeof(val));
	size_t olen = 0;
	const void *got = lom_fcache_get(c, key, sizeof(key), &olen);
	if(!got || olen != sizeof(val) || memcmp(got, val, olen) != 0) {
		fprintf(stderr, "fcache miss\n");
		return 1;
	}
	lom_fcache_free(c);

	const char *xml = "<a/><b/><c/><d/><e/>";
	size_t starts[8];
	size_t n = lom_piece_boundaries(xml, strlen(xml), 4, starts, 8);
	if(n < 2 || starts[0] != 0) {
		fprintf(stderr, "piece boundaries failed n=%zu\n", n);
		return 1;
	}
	printf("fmem/fcache/pieces ok (pieces=%zu)\n", n);
	return 0;
}
