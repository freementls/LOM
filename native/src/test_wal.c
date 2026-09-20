/* SPDX-License-Identifier: Apache-2.0 */
#include "lom.h"
#include <stdio.h>
#include <string.h>
#include <unistd.h>
#include <fcntl.h>

int main(void) {
	const char *xml = "<root><note>abcd</note></root>";
	char path[] = "/tmp/lom_wal_XXXXXX.xml";
	int fd = mkstemps(path, 4);
	if(fd < 0) return 1;
	write(fd, xml, strlen(xml));
	close(fd);

	lom_doc *d = lom_doc_create_file(path);
	if(!d) return 1;
	if(lom_doc_set_inner_text(d, "note", "efgh") != LOM_OK) {
		fprintf(stderr, "FAIL same-size set\n");
		return 1;
	}
	if(lom_doc_wal_persist(d) != LOM_OK) {
		fprintf(stderr, "FAIL wal persist\n");
		return 1;
	}
	lom_doc_free(d);

	d = lom_doc_create_file(path);
	if(!d) return 1;
	lom_match_list m;
	lom_match_list_init(&m);
	if(lom_doc_get(d, "note", &m) != LOM_OK || m.count != 1) {
		fprintf(stderr, "FAIL reload get\n");
		return 1;
	}
	{
		const char *p = NULL;
		size_t n = 0;
		if(lom_doc_node_slice(d, m.items[0].offset, &p, &n) != LOM_OK || !p ||
		   !memmem(p, n, "efgh", 4)) {
			fprintf(stderr, "FAIL restart did not keep same-size set\n");
			lom_match_list_free(&m);
			return 1;
		}
	}
	lom_match_list_free(&m);
	if(lom_doc_checkpoint(d, path) != LOM_OK) {
		fprintf(stderr, "FAIL checkpoint\n");
		return 1;
	}
	lom_doc_free(d);
	unlink(path);
	char wal[512];
	snprintf(wal, sizeof(wal), "%s.lomwal", path);
	unlink(wal);

	/* Length-changing set on a large leaf — overlay must hold the pre-splice window. */
	{
		char big[65536];
		memset(big, 'A', sizeof(big) - 1);
		big[sizeof(big) - 1] = 0;
		char fat[70000];
		int nxml = snprintf(fat, sizeof(fat), "<root><page>%s</page></root>", big);
		char p2[] = "/tmp/lom_wal_big_XXXXXX.xml";
		int fd2 = mkstemps(p2, 4);
		if(fd2 < 0) return 1;
		write(fd2, fat, (size_t)nxml);
		close(fd2);
		d = lom_doc_create_file(p2);
		if(!d || lom_doc_set_inner_text(d, "page", "x") != LOM_OK) {
			fprintf(stderr, "FAIL large shrink set\n");
			return 1;
		}
		char out[768];
		snprintf(out, sizeof(out), "%s.written.xml", p2);
		if(lom_doc_checkpoint(d, out) != LOM_OK) {
			fprintf(stderr, "FAIL large shrink write\n");
			return 1;
		}
		lom_doc_free(d);
		d = lom_doc_create_file(out);
		size_t cn = 0;
		if(!d || lom_doc_count(d, "page", &cn) != LOM_OK || cn != 1) {
			fprintf(stderr, "FAIL large shrink reopen\n");
			return 1;
		}
		lom_doc_free(d);
		unlink(p2);
		unlink(out);
		char idx[768];
		snprintf(idx, sizeof(idx), "%s.lomidx", p2);
		unlink(idx);
		snprintf(idx, sizeof(idx), "%s.lomidx", out);
		unlink(idx);
	}

	/* Dest-only checkpoint must not drop the source sidecar / rewrite the source. */
	{
		const char *xml = "<root><note>keep</note></root>";
		char src[] = "/tmp/lom_wal_src_XXXXXX.xml";
		int fds = mkstemps(src, 4);
		if(fds < 0) return 1;
		write(fds, xml, strlen(xml));
		close(fds);
		d = lom_doc_create_file(src);
		if(!d || lom_doc_set_inner_text(d, "note", "kept!") != LOM_OK) {
			fprintf(stderr, "FAIL dest-only set\n");
			return 1;
		}
		char dest[768], sidx[768];
		snprintf(dest, sizeof(dest), "%s.dest.xml", src);
		snprintf(sidx, sizeof(sidx), "%s.lomidx", src);
		int had_sidecar = access(sidx, F_OK) == 0;
		if(lom_doc_checkpoint(d, dest) != LOM_OK) {
			fprintf(stderr, "FAIL dest-only checkpoint\n");
			return 1;
		}
		lom_doc_free(d);
		if(had_sidecar && access(sidx, F_OK) != 0) {
			fprintf(stderr, "FAIL dest-only dropped source sidecar\n");
			return 1;
		}
		char srcbuf[64] = {0};
		int rfd = open(src, O_RDONLY);
		if(rfd < 0 || read(rfd, srcbuf, sizeof(srcbuf) - 1) < 0) {
			fprintf(stderr, "FAIL dest-only reread source\n");
			return 1;
		}
		close(rfd);
		if(!strstr(srcbuf, "keep")) {
			fprintf(stderr, "FAIL dest-only mutated source: %s\n", srcbuf);
			return 1;
		}
		d = lom_doc_create_file(dest);
		lom_match_list m;
		lom_match_list_init(&m);
		if(!d || lom_doc_get(d, "note", &m) != LOM_OK || m.count != 1) {
			fprintf(stderr, "FAIL dest-only dest get\n");
			return 1;
		}
		{
			const char *p = NULL;
			size_t n = 0;
			if(lom_doc_node_slice(d, m.items[0].offset, &p, &n) != LOM_OK ||
			   !p || !memmem(p, n, "kept!", 5)) {
				fprintf(stderr, "FAIL dest-only dest missing edit\n");
				lom_match_list_free(&m);
				return 1;
			}
		}
		lom_match_list_free(&m);
		lom_doc_free(d);
		unlink(src);
		unlink(dest);
		unlink(sidx);
		char didx[768];
		snprintf(didx, sizeof(didx), "%s.lomidx", dest);
		unlink(didx);
	}
	printf("wal ok lom %s\n", lom_version());
	return 0;
}
