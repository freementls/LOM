/* SPDX-License-Identifier: Apache-2.0 — irregular XML + native language parity */
#include "lom.h"
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>

static int fails;

static void expect_n(lom_doc *d, const char *sel, size_t want, const char *lab) {
	size_t n = 0;
	lom_status st = lom_doc_count(d, sel, &n);
	if(st != LOM_OK || n != want) {
		fprintf(stderr, "FAIL %s st=%d n=%zu want=%zu\n", lab, (int)st, n, want);
		fails++;
	}
}

int main(void) {
	const char *xml =
		"<?xml version=\"1.0\"?>\n"
		"<!DOCTYPE root [ <!ELEMENT root ANY> ]>\n"
		"<!-- comment <notag> -->\n"
		"<?pi data?>\n"
		"<root>\n"
		"  <item id=\"i1\"><name>Alpha</name><value>10</value></item>\n"
		"  <item id=\"i2\"><name>Beta</name><value>20</value></item>\n"
		"  <raw><![CDATA[ if (a < b) ]]></raw>\n"
		"</root>\n";
	char path[] = "/tmp/lom_irreg_XXXXXX.xml";
	int fd = mkstemps(path, 4);
	if(fd < 0) { perror("mkstemps"); return 1; }
	write(fd, xml, strlen(xml));
	close(fd);

	lom_doc *d = lom_doc_create_file(path);
	if(!d || lom_doc_status(d) != LOM_OK) {
		fprintf(stderr, "FAIL create irregular\n");
		return 1;
	}
	expect_n(d, "item", 2, "item");
	expect_n(d, "name", 2, "name");
	expect_n(d, "item@id=i1", 1, "attr");
	expect_n(d, "value>15", 1, "numeric");
	expect_n(d, "item|raw", 3, "or");
	expect_n(d, "name&name", 2, "and");
	expect_n(d, "name'item", 2, "parent");
	expect_n(d, "name\"root", 1, "ancestor");
	expect_n(d, "item[$]", 1, "last-of-name");
	expect_n(d, "#item", 2, "count-sugar");
	expect_n(d, "name=Entity{underscore}10", 0, "brace-escape");
	expect_n(d, "name=Entity#underscore#10", 0, "hash-escape");
	{
		double sum = 0, avg = 0;
		if(lom_doc_sum(d, "value", &sum) != LOM_OK || sum != 30) {
			fprintf(stderr, "FAIL sum %g\n", sum);
			fails++;
		}
		if(lom_doc_average(d, "value", &avg) != LOM_OK || avg != 15) {
			fprintf(stderr, "FAIL avg %g\n", avg);
			fails++;
		}
		lom_match_list mnum;
		lom_match_list_init(&mnum);
		if(lom_doc_get(d, "#item", &mnum) == LOM_OK) {
			fprintf(stderr, "FAIL get stuffed a count into matches\n");
			fails++;
		}
		lom_match_list_free(&mnum);
	}

	lom_match_list m;
	lom_match_list_init(&m);
	if(lom_doc_get(d, "item", &m) != LOM_OK || m.count != 2) {
		fprintf(stderr, "FAIL context seed\n");
		fails++;
	}
	lom_match_list_free(&m);
	lom_match_list_init(&m);
	if(lom_doc_get(d, ".name", &m) != LOM_OK || m.count != 2) {
		fprintf(stderr, "FAIL context-relative n=%zu\n", m.count);
		fails++;
	}
	lom_match_list_free(&m);

	if(lom_doc_var_set(d, "items", "item") != LOM_OK) {
		fprintf(stderr, "FAIL var set\n");
		fails++;
	}
	lom_match_list_init(&m);
	if(lom_doc_var_get(d, "items", &m) != LOM_OK || m.count != 2) {
		fprintf(stderr, "FAIL var get n=%zu\n", m.count);
		fails++;
	}
	lom_match_list_free(&m);

	if(lom_doc_set_inner_text(d, "raw", "<notag>z</notag>") != LOM_OK) {
		fprintf(stderr, "FAIL piece splice\n");
		fails++;
	} else {
		expect_n(d, "notag", 1, "piece-local");
	}

	{
		lom_oi_list ois;
		lom_oi_list_init(&ois);
		if(lom_doc_get_ois(d, "item", &ois) != LOM_OK || !ois.count) {
			fprintf(stderr, "FAIL item ois\n");
			fails++;
		} else {
			lom_match one;
			if(lom_doc_oi_match(d, ois.items[0], &one) != LOM_OK ||
			   lom_doc_set_inner_text_offset(d, one.offset, "z") != LOM_OK) {
				fprintf(stderr, "FAIL inner-span set\n");
				fails++;
			} else {
				expect_n(d, "name", 1, "inner-span-kids");
				expect_n(d, "item", 2, "inner-span-parent");
			}
		}
		lom_oi_list_free(&ois);
	}

	lom_doc_free(d);
	unlink(path);

	/* Mixed leftover siblings (≥64 KiB): second recipe class, not prefix dump. */
	{
		setenv("LOM_FRACTAL", "1", 1);
		setenv("LOM_TILE", "1", 1);
		char mixp[] = "/tmp/lom_mixk_XXXXXX.xml";
		int mfd = mkstemps(mixp, 4);
		if(mfd < 0) { perror("mkstemps mix"); return 1; }
		const char *pre = "<?xml version=\"1.0\"?><root>";
		const char *post = "</root>";
		write(mfd, pre, strlen(pre));
		char pad[9000];
		memset(pad, 'x', sizeof(pad));
		for(int i = 0; i < 8; i++) {
			if(i % 2 == 0) {
				dprintf(mfd, "<item id=\"i%d\"><name>N%d</name><value>1</value><!--", i, i);
				write(mfd, pad, sizeof(pad));
				dprintf(mfd, "--></item>");
			} else {
				dprintf(mfd, "<aside id=\"a%d\"><!--", i);
				write(mfd, pad, sizeof(pad));
				dprintf(mfd, "--></aside>");
			}
		}
		write(mfd, post, strlen(post));
		close(mfd);
		d = lom_doc_create_file(mixp);
		if(!d || lom_doc_status(d) != LOM_OK || !lom_doc_recipe_active(d)) {
			fprintf(stderr, "FAIL mix recipe active=%d st=%d\n",
				d ? lom_doc_recipe_active(d) : -1,
				d ? (int)lom_doc_status(d) : -1);
			fails++;
		} else {
			int kc = lom_doc_recipe_class_count(d);
			if(kc < 2) {
				fprintf(stderr, "FAIL mix classes=%d want>=2\n", kc);
				fails++;
			}
			expect_n(d, "item", 4, "mix-item");
			expect_n(d, "aside", 4, "mix-aside");
			expect_n(d, "name", 4, "mix-name");
			if(lom_doc_set_inner_text(d, "item[1]__name", "ZZ") != LOM_OK ||
			   lom_doc_set_inner_text(d, "item[1]__name", "YY") != LOM_OK ||
			   lom_doc_set_inner_text(d, "item[1]__name", "XX") != LOM_OK) {
				fprintf(stderr, "FAIL mix hot-slot set\n");
				fails++;
			} else {
				expect_n(d, "name=XX", 1, "mix-hot-slot");
			}
			if(lom_doc_new_before_close(d, "item[1]", "<extra>x</extra>") != LOM_OK) {
				fprintf(stderr, "FAIL mix in-tile new_\n");
				fails++;
			} else {
				expect_n(d, "extra", 1, "mix-intile-extra");
				expect_n(d, "item", 4, "mix-intile-item");
				expect_n(d, "name", 4, "mix-intile-name");
				expect_n(d, "name=N6", 1, "mix-later-tile");
			}
			if(lom_doc_delete(d, "item[2]__name") != LOM_OK) {
				fprintf(stderr, "FAIL mix del in-tile name\n");
				fails++;
			} else {
				expect_n(d, "name", 3, "mix-del-name");
				expect_n(d, "item", 4, "mix-del-item");
			}
			if(lom_doc_set_inner_text(d, "item[3]", "plain") != LOM_OK) {
				fprintf(stderr, "FAIL mix inner replace\n");
				fails++;
			} else {
				expect_n(d, "name", 2, "mix-inner-name");
				expect_n(d, "item", 4, "mix-inner-item");
				expect_n(d, "name=N6", 1, "mix-inner-later");
			}
			const char *frag = "<aside id=\"new\"><!--yyyyyyyy--></aside>";
			(void)lom_doc_new_before_close(d, "root", frag);
		}
		lom_doc_free(d);
		char idx[512];
		snprintf(idx, sizeof(idx), "%s.lomidx", mixp);
		unlink(mixp);
		unlink(idx);
	}

	/* Depth-2 nested tiles: two wrappers, repeating inner items. */
	{
		char nestp[] = "/tmp/lom_nest_XXXXXX.xml";
		int nfd = mkstemps(nestp, 4);
		if(nfd < 0) { perror("mkstemps nest"); return 1; }
		write(nfd, "<?xml version=\"1.0\"?><root>", 27);
		char pad[9000];
		memset(pad, 'y', sizeof(pad));
		for(int s = 0; s < 2; s++) {
			dprintf(nfd, "<sec id=\"s%d\">", s);
			for(int i = 0; i < 4; i++) {
				dprintf(nfd, "<item><name>I%d</name><!--", s * 4 + i);
				write(nfd, pad, sizeof(pad));
				dprintf(nfd, "--></item>");
			}
			dprintf(nfd, "</sec>");
		}
		write(nfd, "</root>", 7);
		close(nfd);
		d = lom_doc_create_file(nestp);
		if(!d || lom_doc_status(d) != LOM_OK) {
			fprintf(stderr, "FAIL nest create\n");
			fails++;
		} else {
			expect_n(d, "item", 8, "nest-item");
			expect_n(d, "sec", 2, "nest-sec");
			if(!lom_doc_recipe_active(d)) {
				fprintf(stderr, "FAIL nest recipe (depth-2 fallback)\n");
				fails++;
			}
		}
		lom_doc_free(d);
		char idx[512];
		snprintf(idx, sizeof(idx), "%s.lomidx", nestp);
		unlink(nestp);
		unlink(idx);
	}

	if(fails) {
		fprintf(stderr, "test_irregular: %d fails\n", fails);
		return 1;
	}
	printf("irregular ok lom %s\n", lom_version());
	return 0;
}
