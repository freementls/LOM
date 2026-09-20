/* SPDX-License-Identifier: Apache-2.0
 * Phase 0 corpus clocks: construct, reload, count, set, save/WAL, RSS.
 * Mutates a temp copy — never writes WAL/pwrite onto the source file.
 */
#include "lom.h"
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
#include <time.h>
#include <unistd.h>
#include <fcntl.h>
#include <errno.h>

static double now_ms(void) {
	struct timespec ts;
	clock_gettime(CLOCK_MONOTONIC, &ts);
	return ts.tv_sec * 1000.0 + ts.tv_nsec / 1e6;
}

static long rss_kb(void) {
	FILE *f = fopen("/proc/self/status", "r");
	if(!f) return -1;
	char l[256];
	long v = -1;
	while(fgets(l, sizeof l, f))
		if(sscanf(l, "VmRSS: %ld", &v) == 1) break;
	fclose(f);
	return v;
}

static int copy_file(const char *src, const char *dst) {
	int in = open(src, O_RDONLY);
	if(in < 0) return -1;
	int out = open(dst, O_WRONLY | O_CREAT | O_TRUNC, 0644);
	if(out < 0) { close(in); return -1; }
	char buf[1 << 16];
	ssize_t n;
	while((n = read(in, buf, sizeof buf)) > 0) {
		if(write(out, buf, (size_t)n) != n) { close(in); close(out); return -1; }
	}
	close(in);
	close(out);
	return n < 0 ? -1 : 0;
}

static void unlink_sidecars(const char *path) {
	char extra[1024];
	snprintf(extra, sizeof extra, "%s.lomwal", path);
	unlink(extra);
	snprintf(extra, sizeof extra, "%s.lomidx", path);
	unlink(extra);
	snprintf(extra, sizeof extra, "%s.lomopens", path);
	unlink(extra);
}

static void row(const char *file, const char *label, const char *sel) {
	struct stat st;
	if(stat(file, &st) != 0) {
		printf("%-22s  MISSING %s\n", label, file);
		return;
	}
	char tmp[] = "/tmp/lom_corpus_XXXXXX.xml";
	int tfd = mkstemps(tmp, 4);
	if(tfd < 0) { perror("mkstemps"); return; }
	close(tfd);
	if(copy_file(file, tmp) != 0) {
		printf("%-22s  FAIL copy %s\n", label, strerror(errno));
		unlink(tmp);
		return;
	}

	double t0 = now_ms();
	lom_doc *d = lom_doc_create_file(tmp);
	double construct = now_ms() - t0;
	if(!d || lom_doc_status(d) != LOM_OK) {
		printf("%-22s  FAIL construct\n", label);
		if(d) lom_doc_free(d);
		unlink_sidecars(tmp);
		unlink(tmp);
		return;
	}
	long rss = rss_kb();
	size_t n = 0;
	t0 = now_ms();
	(void)lom_doc_count(d, sel && *sel ? sel : "*", &n);
	double count_ms = now_ms() - t0;

	/* Prefer a short leaf (name/title/value) over replacing a container's inner XML. */
	const char *set_sel = "name";
	size_t nset = 0;
	if(lom_doc_count(d, set_sel, &nset) != LOM_OK || nset == 0) {
		set_sel = "title";
		if(lom_doc_count(d, set_sel, &nset) != LOM_OK || nset == 0) {
			set_sel = "value";
			if(lom_doc_count(d, set_sel, &nset) != LOM_OK || nset == 0)
				set_sel = sel && *sel ? sel : "name";
		}
	}
	lom_oi_list ois;
	lom_oi_list_init(&ois);
	lom_status gst = lom_doc_get_ois(d, set_sel, &ois);
	t0 = now_ms();
	lom_status sst = LOM_ERR_NOTFOUND;
	if(gst == LOM_OK && ois.count) {
		lom_match one;
		if(lom_doc_oi_match(d, ois.items[0], &one) == LOM_OK)
			sst = lom_doc_set_inner_text_offset(d, one.offset, "x");
	}
	double set_ms = now_ms() - t0;
	lom_oi_list_free(&ois);
	t0 = now_ms();
	(void)lom_doc_wal_persist(d);
	double wal_ms = now_ms() - t0;

	char written[512];
	snprintf(written, sizeof written, "%s.written.xml", tmp);
	t0 = now_ms();
	lom_status ck = lom_doc_checkpoint(d, written);
	double write_ms = now_ms() - t0;
	struct stat wst;
	long long wbytes = (stat(written, &wst) == 0) ? (long long)wst.st_size : -1;

	printf("%-22s  bytes=%lld opens=%zu recipe=%d classes=%d  "
		"construct=%.3fms count=%.3fms n=%zu set=%s %.3fms st=%d wal=%.3fms "
		"write_xml=%.3fms wst=%d written=%lld rss_kb=%ld sidecar=%d\n",
		label, (long long)st.st_size, lom_doc_open_count(d),
		lom_doc_recipe_active(d), lom_doc_recipe_class_count(d),
		construct, count_ms, n, set_sel, set_ms, (int)sst, wal_ms,
		write_ms, (int)ck, wbytes, rss,
		lom_doc_from_sidecar(d));
	lom_doc_free(d);

	t0 = now_ms();
	d = lom_doc_create_file(tmp);
	double reload = now_ms() - t0;
	if(d) {
		printf("%-22s  reload=%.3fms sidecar=%d recipe=%d rss_kb=%ld\n",
			label, reload, lom_doc_from_sidecar(d), lom_doc_recipe_active(d), rss_kb());
		lom_doc_free(d);
	}
	unlink_sidecars(tmp);
	unlink(tmp);
	unlink_sidecars(written);
	unlink(written);
}

int main(int argc, char **argv) {
	printf("lom %s\n", lom_version());
	if(argc < 3) {
		fprintf(stderr, "usage: bench_corpus LABEL path [selector] [LABEL path sel]...\n");
		return 2;
	}
	for(int i = 1; i + 1 < argc; ) {
		const char *lab = argv[i++];
		const char *path = argv[i++];
		const char *sel = "*";
		if(i < argc && argv[i][0] != '/' && strchr(argv[i], '/') == NULL && strchr(argv[i], '.') == NULL)
			sel = argv[i++];
		else if(i < argc && argv[i][0] != '-' && access(argv[i], F_OK) != 0 && strchr(argv[i], '/') == NULL)
			sel = argv[i++];
		row(path, lab, sel);
	}
	return 0;
}
