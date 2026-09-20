/* SPDX-License-Identifier: Apache-2.0 — sidecar, count/ois, fcache view, tile census */
#include "lom.h"
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <sys/stat.h>

static int fails;

static void expect_n(lom_doc *d, const char *sel, size_t want, const char *tag) {
	size_t n = 0;
	lom_status st = lom_doc_count(d, sel, &n);
	if(st != LOM_OK || n != want) {
		fprintf(stderr, "FAIL %s count sel=%s n=%zu want=%zu st=%d\n", tag, sel, n, want, (int)st);
		fails++;
		return;
	}
	lom_match_list m;
	lom_match_list_init(&m);
	st = lom_doc_get(d, sel, &m);
	if(st != LOM_OK || m.count != want) {
		fprintf(stderr, "FAIL %s get sel=%s n=%zu want=%zu st=%d\n", tag, sel, m.count, want, (int)st);
		fails++;
	}
	lom_match_list_free(&m);
	lom_oi_list ois;
	lom_oi_list_init(&ois);
	st = lom_doc_get_ois(d, sel, &ois);
	if(st != LOM_OK || ois.count != want) {
		fprintf(stderr, "FAIL %s ois sel=%s n=%zu want=%zu st=%d\n", tag, sel, ois.count, want, (int)st);
		fails++;
	} else if(ois.count) {
		lom_match mm;
		if(lom_doc_oi_match(d, ois.items[0], &mm) != LOM_OK)
			fails++, fprintf(stderr, "FAIL %s oi_match\n", tag);
	}
	lom_oi_list_free(&ois);
}

int main(void) {
	const char *xml =
		"<world>"
		"<region><zone><entity><stats/><meta><name>A</name></meta></entity></zone></region>"
		"<region><zone><entity><stats/><meta><name>B</name></meta></entity></zone></region>"
		"<region><zone><entity><stats/><meta><name>C</name></meta></entity></zone></region>"
		"<region><zone><entity><stats/><meta><name>D</name></meta></entity></zone></region>"
		"</world>";
	lom_doc *d = lom_doc_create(xml, strlen(xml));
	if(!d || lom_doc_status(d) != LOM_OK) {
		fprintf(stderr, "create failed\n");
		return 1;
	}
	expect_n(d, "region", 4, "mem");
	expect_n(d, "region_zone_entity_stats", 4, "mem-chain");
	expect_n(d, "region__stats", 4, "mem-desc");
	/* Warm get should hit fcache (borrow or memcpy). */
	expect_n(d, "region_zone_entity_stats", 4, "mem-warm");
	lom_doc_free(d);

	char tmpl[] = "/tmp/lom_fastpath_XXXXXX.xml";
	int fd = mkstemps(tmpl, 4);
	if(fd < 0) {
		perror("mkstemps");
		return 1;
	}
	if(write(fd, xml, strlen(xml)) != (ssize_t)strlen(xml)) {
		perror("write");
		close(fd);
		unlink(tmpl);
		return 1;
	}
	close(fd);

	setenv("LOM_SIDECAR", "1", 1);
	d = lom_doc_create_file(tmpl);
	if(!d || lom_doc_status(d) != LOM_OK) {
		fprintf(stderr, "file create failed\n");
		unlink(tmpl);
		return 1;
	}
	expect_n(d, "region_zone_entity_stats", 4, "file1");
	lom_doc_free(d);

	char idx[512];
	snprintf(idx, sizeof(idx), "%s.lomidx", tmpl);
	if(access(idx, R_OK) != 0) {
		fprintf(stderr, "FAIL sidecar not written: %s\n", idx);
		fails++;
	}

	d = lom_doc_create_file(tmpl);
	if(!d || lom_doc_status(d) != LOM_OK) {
		fprintf(stderr, "sidecar reload failed\n");
		fails++;
	} else {
		expect_n(d, "region", 4, "sidecar");
		expect_n(d, "region_zone_entity_stats", 4, "sidecar-chain");
		lom_doc_set_inner_text(d, "region[1]__name", "Z");
		lom_doc_free(d);
		if(access(idx, R_OK) != 0) {
			fprintf(stderr, "FAIL sidecar dropped on in-process write\n");
			fails++;
		}
	}
	unlink(tmpl);
	unlink(idx);

	/* Split sidecar + file-backed opens: persist should hard-link the map. */
	{
		char tmpl2[] = "/tmp/lom_fastpath2_XXXXXX.xml";
		int fd2 = mkstemps(tmpl2, 4);
		if(fd2 < 0) { perror("mkstemps2"); return 1; }
		if(write(fd2, xml, strlen(xml)) != (ssize_t)strlen(xml)) {
			perror("write2"); close(fd2); unlink(tmpl2); return 1;
		}
		close(fd2);
		char idx2[512], op2[512];
		snprintf(idx2, sizeof(idx2), "%s.lomidx", tmpl2);
		snprintf(op2, sizeof(op2), "%s.lomopens", tmpl2);
		setenv("LOM_SIDECAR", "1", 1);
		setenv("LOM_SIDECAR_MAX", "1", 1);
		setenv("LOM_OPEN_MMAP", "1", 1);
		d = lom_doc_create_file(tmpl2);
		if(!d || lom_doc_status(d) != LOM_OK || lom_doc_from_sidecar(d)) {
			fprintf(stderr, "FAIL split first create\n");
			fails++;
		}
		lom_doc_free(d);
		if(access(idx2, R_OK) != 0 || access(op2, R_OK) != 0) {
			fprintf(stderr, "FAIL split sidecar files missing\n");
			fails++;
		}
		d = lom_doc_create_file(tmpl2);
		if(!d || !lom_doc_from_sidecar(d)) {
			fprintf(stderr, "FAIL split sidecar reload sidecar=%d\n",
				d ? lom_doc_from_sidecar(d) : -1);
			fails++;
		} else {
			expect_n(d, "region", 4, "split-sidecar");
		}
		lom_doc_free(d);
		unlink(tmpl2);
		unlink(idx2);
		unlink(op2);
	}

	/* ≥4 MB, 4 unique-id siblings: fractal recipe (no wholesale opens). */
	{
		unsetenv("LOM_SIDECAR_MAX");
		unsetenv("LOM_OPEN_MMAP");
		setenv("LOM_SIDECAR", "1", 1);
		setenv("LOM_FRACTAL", "1", 1);
		setenv("LOM_TILE", "1", 1);
		char tmpl3[] = "/tmp/lom_recipe_XXXXXX.xml";
		int fd3 = mkstemps(tmpl3, 4);
		if(fd3 < 0) { perror("mkstemps3"); return 1; }
		const char *pre = "<?xml version=\"1.0\"?><world>";
		const char *post = "</world>";
		if(write(fd3, pre, strlen(pre)) < 0) { close(fd3); unlink(tmpl3); return 1; }
		char *pad = malloc(1100000);
		if(!pad) { close(fd3); unlink(tmpl3); return 1; }
		for(int r = 0; r < 4; r++) {
			memset(pad, (char)('A' + r), 1100000);
			dprintf(fd3,
				"<region id=\"r%d\"><name>R%d</name><zone><entity>"
				"<stats/><meta><note>", r, r);
			if(write(fd3, pad, 1100000) != 1100000) {
				free(pad); close(fd3); unlink(tmpl3); return 1;
			}
			const char *tail = "</note></meta></entity></zone></region>";
			if(write(fd3, tail, strlen(tail)) < 0) {
				free(pad); close(fd3); unlink(tmpl3); return 1;
			}
		}
		free(pad);
		if(write(fd3, post, strlen(post)) < 0) { close(fd3); unlink(tmpl3); return 1; }
		close(fd3);
		char idx3[512];
		snprintf(idx3, sizeof(idx3), "%s.lomidx", tmpl3);
		unlink(idx3);
		d = lom_doc_create_file(tmpl3);
		if(!d || lom_doc_status(d) != LOM_OK || !lom_doc_recipe_active(d)) {
			fprintf(stderr, "FAIL recipe first create recipe=%d st=%d\n",
				d ? lom_doc_recipe_active(d) : -1,
				d ? (int)lom_doc_status(d) : -1);
			fails++;
		} else {
			expect_n(d, "region", 4, "recipe-region");
			expect_n(d, "region_zone_entity_stats", 4, "recipe-chain");
			expect_n(d, "name'region", 4, "recipe-parent");
			expect_n(d, "stats\"region", 4, "recipe-ancestor");
			expect_n(d, "region[$]", 1, "recipe-last");
			expect_n(d, "region[2]_zone_entity_stats", 1, "recipe-indexed");
			expect_n(d, "name=R2", 1, "recipe-text-jump");
			expect_n(d, "name=/^R2$/", 1, "recipe-regex-jump");
			expect_n(d, "name=R0", 1, "recipe-text-r0");
			expect_n(d, "name=R3", 1, "recipe-text-r3");
			if(lom_doc_open_count(d) < 20) {
				fprintf(stderr, "FAIL recipe virt opens %zu\n", lom_doc_open_count(d));
				fails++;
			}
		}
		lom_doc_free(d);
		d = lom_doc_create_file(tmpl3);
		if(!d || !lom_doc_from_sidecar(d) || !lom_doc_recipe_active(d)) {
			fprintf(stderr, "FAIL recipe sidecar reload sc=%d rec=%d\n",
				d ? lom_doc_from_sidecar(d) : -1,
				d ? lom_doc_recipe_active(d) : -1);
			fails++;
		} else {
			expect_n(d, "region", 4, "recipe-reload");
			expect_n(d, "name=R2", 1, "recipe-reload-text");
			char saved[] = "/tmp/lom_recipe_save_XXXXXX.xml";
			int sfd = mkstemps(saved, 4);
			if(sfd >= 0) {
				close(sfd);
				unlink(saved);
				if(lom_doc_save_file(d, saved) != LOM_OK) {
					fprintf(stderr, "FAIL recipe save after reload\n");
					fails++;
				} else {
					struct stat a, b;
					if(stat(tmpl3, &a) != 0 || stat(saved, &b) != 0 || a.st_size != b.st_size) {
						fprintf(stderr, "FAIL recipe save size\n");
						fails++;
					}
				}
				unlink(saved);
			}
			if(lom_doc_new_before_close(d, "region[1]_zone_entity_meta",
				"<bonus><name>surge</name><value>99</value></bonus>") != LOM_OK) {
				fprintf(stderr, "FAIL recipe new_ bonus\n");
				fails++;
			} else {
				expect_n(d, "bonus", 1, "recipe-new-bonus");
				expect_n(d, "region[1]_zone_entity_meta_bonus_value", 1, "recipe-new-path");
				if(lom_doc_delete(d, "region[1]_zone_entity_meta_bonus") != LOM_OK)
					fails++, fprintf(stderr, "FAIL recipe del bonus\n");
				else
					expect_n(d, "bonus", 0, "recipe-del-bonus");
			}
			lom_doc_set_inner_text(d, "region[1]__name", "Z");
		}
		lom_doc_free(d);
		unlink(tmpl3);
		unlink(idx3);
		char op3[512];
		snprintf(op3, sizeof(op3), "%s.lomopens", tmpl3);
		unlink(op3);
	}

	printf("%s (%d fails) lom %s\n", fails ? "SOME FAILED" : "fastpath ok", fails, lom_version());
	return fails ? 1 : 0;
}
