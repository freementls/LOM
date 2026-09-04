#include "lom.h"

#include <unistd.h>
#include <ctype.h>
#include <stdio.h>
#include <stdlib.h>
#include <stdint.h>
#include <limits.h>
#include <string.h>
#include <strings.h>
#include <pthread.h>
#include <fcntl.h>
#include <sys/stat.h>
#include <sys/mman.h>
#if defined(__GLIBC__)
#include <malloc.h>
#endif

#define PCRE2_CODE_UNIT_WIDTH 8
#include <pcre2.h>

#ifndef LOM_MAX_PIECES
#define LOM_MAX_PIECES 64
#endif

struct lom_doc {
	char *code;
	size_t code_len;
	size_t code_cap;
	int mmap_fd;
	int code_is_mmap; /* 1 => code from mmap; do not free() as malloc */
	lom_scan_result scan;
	/* per-name open indices (uint32, exact-sized — no per-row cap waste) */
	uint32_t **tag_rows;
	uint32_t *tag_row_counts;
	size_t tag_row_slots;
	/* CSR children: child_at[child_start[i] .. child_start[i+1]) */
	uint32_t *child_at;
	uint32_t *child_start; /* length aux_open_count + 1 */
	uint32_t *root_children;
	size_t root_child_count;
	size_t aux_open_count;
	/* per-attr-name open indices */
	uint32_t **attr_opens;
	uint32_t *attr_open_counts;
	size_t attr_slots;
	/* Accelerators */
	lom_fmem *fmem;
	lom_fcache *fcache;
	lom_fstr *fstr;
	size_t piece_starts[LOM_MAX_PIECES];
	size_t piece_count;
	uint8_t piece_dirty[LOM_MAX_PIECES];
	int use_fmem;
	int use_fcache;
	int use_fstr;
	int use_pieces;
	int use_parallel;
	lom_status status;
	char error[256];
	int aux_dirty; /* 1 => tag/CSR indexes stale; rebuild on next query */
};

static int env_flag_on(const char *name, int default_on) {
	const char *v = getenv(name);
	if(!v || !*v) return default_on;
	if(v[0] == '0' || v[0] == 'f' || v[0] == 'F' || v[0] == 'n' || v[0] == 'N' || v[0] == 'o') {
		if(strcasecmp(v, "off") == 0 || strcasecmp(v, "no") == 0 || strcasecmp(v, "false") == 0 || v[0] == '0')
			return 0;
	}
	return 1;
}

static void doc_init_accel(lom_doc *d) {
	d->use_fmem = env_flag_on("LOM_FMEM", 1);
	d->use_fcache = env_flag_on("LOM_FCACHE", 1);
	/* Fractal string signatures for cold substring/regex prune; default on. */
	d->use_fstr = env_flag_on("LOM_FSTR", 1);
	d->use_pieces = env_flag_on("LOM_PIECES", 1);
	/* Parallel tag-row build duplicates per-thread buffers; default off for RAM. */
	d->use_parallel = env_flag_on("LOM_PARALLEL", 0);
	d->mmap_fd = -1;
	d->code_is_mmap = 0;
	d->piece_count = 0;
	d->fstr = NULL;
	memset(d->piece_dirty, 0, sizeof(d->piece_dirty));
	d->aux_dirty = 0;
	if(d->use_fmem) {
		d->fmem = lom_fmem_create(2048);
	}
	if(d->use_fcache) {
		d->fcache = lom_fcache_create(512);
	}
}

static void doc_free_accel(lom_doc *d) {
	if(d->fmem) {
		lom_fmem_free(d->fmem);
		d->fmem = NULL;
	}
	if(d->fcache) {
		lom_fcache_free(d->fcache);
		d->fcache = NULL;
	}
	if(d->fstr) {
		lom_fstr_free(d->fstr);
		d->fstr = NULL;
	}
}

static void doc_rebuild_fstr(lom_doc *d) {
	if(d->fstr) {
		lom_fstr_free(d->fstr);
		d->fstr = NULL;
	}
	if(!d->use_fstr || !d->code || d->code_len < 4096) return;
	/* Signatures walk every byte and store per-span blooms — skip on huge docs. */
	if(d->code_len >= (size_t)256 * 1024 * 1024) return;
	d->fstr = lom_fstr_build(
		d->code, d->code_len,
		d->scan.opens, d->scan.open_count,
		d->root_children, d->root_child_count,
		d->child_at, d->child_start,
		0
	);
}

static void doc_rebuild_pieces(lom_doc *d) {
	d->piece_count = 0;
	if(!d->use_pieces || !d->code || d->code_len == 0) {
		d->piece_starts[0] = 0;
		d->piece_count = 1;
		return;
	}
	size_t target = d->code_len / 8;
	if(target < 256 * 1024) target = 256 * 1024;
	if(target > 8 * 1024 * 1024) target = 8 * 1024 * 1024;
	d->piece_count = lom_piece_boundaries(d->code, d->code_len, target, d->piece_starts, LOM_MAX_PIECES);
	if(d->piece_count == 0) {
		d->piece_starts[0] = 0;
		d->piece_count = 1;
	}
	memset(d->piece_dirty, 0, sizeof(d->piece_dirty));
}

static void doc_mark_dirty_at(lom_doc *d, size_t offset) {
	if(d->piece_count == 0) return;
	size_t pi = 0;
	for(size_t i = 1; i < d->piece_count; i++) {
		if(d->piece_starts[i] <= offset) pi = i;
		else break;
	}
	d->piece_dirty[pi] = 1;
	if(d->fcache) {
		lom_fcache_free(d->fcache);
		d->fcache = lom_fcache_create(512);
	}
}

static void doc_fmem_ingest_strings(lom_doc *d) {
	/* Query paths use scan name_ids / string_blob, not fmem lookups. Bulk-ingesting
	 * millions of unique attr values into a small hash table was O(n²) and dominated
	 * 1GB construct (~minutes). Keep fmem available for explicit callers; skip ingest. */
	(void)d;
}

static bool grow_cap(void **ptr, size_t elem, size_t *cap, size_t need);

/* Splice mutates the open table with realloc/memmove — copy mmap'd opens to heap first. */
static bool doc_opens_to_heap(lom_doc *d) {
	if(!d->scan.opens_is_mmap) return true;
	size_t n = d->scan.open_count;
	size_t bytes = (n ? n : 1) * sizeof(lom_open_row);
	lom_open_row *p = malloc(bytes);
	if(!p) return false;
	if(n) memcpy(p, d->scan.opens, n * sizeof(lom_open_row));
	munmap(d->scan.opens, d->scan.opens_map_bytes ? d->scan.opens_map_bytes : bytes);
	if(d->scan.opens_fd >= 0) close(d->scan.opens_fd);
	d->scan.opens = p;
	d->scan.open_cap = n ? n : 1;
	d->scan.opens_fd = -1;
	d->scan.opens_map_bytes = 0;
	d->scan.opens_is_mmap = 0;
	return true;
}

void lom_match_list_init(lom_match_list *m) {
	memset(m, 0, sizeof(*m));
}

void lom_match_list_free(lom_match_list *m) {
	if(!m) return;
	free(m->items);
	lom_match_list_init(m);
}

static bool grow_cap(void **ptr, size_t elem, size_t *cap, size_t need) {
	if(need <= *cap) return true;
	size_t ncap = *cap ? *cap : 64;
	while(ncap < need) ncap *= 2;
	void *p = realloc(*ptr, ncap * elem);
	if(!p) return false;
	*ptr = p;
	*cap = ncap;
	return true;
}

static bool match_push(lom_match_list *m, int64_t off, int64_t end) {
	if(!grow_cap((void **)&m->items, sizeof(lom_match), &m->cap, m->count + 1)) return false;
	m->items[m->count].offset = off;
	m->items[m->count].end_off = end;
	m->count++;
	return true;
}

static void doc_clear_aux(lom_doc *d) {
	if(d->tag_rows) {
		for(size_t i = 0; i < d->tag_row_slots; i++) free(d->tag_rows[i]);
	}
	free(d->tag_rows);
	free(d->tag_row_counts);
	d->tag_rows = NULL;
	d->tag_row_counts = NULL;
	d->tag_row_slots = 0;

	free(d->child_at);
	free(d->child_start);
	d->child_at = NULL;
	d->child_start = NULL;
	free(d->root_children);
	d->root_children = NULL;
	d->root_child_count = 0;
	d->aux_open_count = 0;

	if(d->attr_opens) {
		for(size_t i = 0; i < d->attr_slots; i++) free(d->attr_opens[i]);
	}
	free(d->attr_opens);
	free(d->attr_open_counts);
	d->attr_opens = NULL;
	d->attr_open_counts = NULL;
	d->attr_slots = 0;
}

/* Binary search on sorted opening offsets (scan.opens is in document order). */
static size_t find_open_index(const lom_doc *d, int64_t open_off) {
	size_t lo = 0, hi = d->scan.open_count;
	while(lo < hi) {
		size_t mid = lo + (hi - lo) / 2;
		int64_t v = d->scan.opens[mid].open_off;
		if(v < open_off) lo = mid + 1;
		else if(v > open_off) hi = mid;
		else return mid;
	}
	return (size_t)-1;
}

static void aux_opens_dontneed(lom_doc *d, size_t upto) {
	if(!d->scan.opens_is_mmap || !d->scan.opens || upto < 8192) return;
	size_t bytes = (upto * sizeof(lom_open_row)) & ~(size_t)4095;
	if(bytes >= 4096) (void)madvise(d->scan.opens, bytes, MADV_DONTNEED);
}

static bool doc_rebuild_aux(lom_doc *d) {
	doc_clear_aux(d);
	size_t n = d->scan.open_count;
	size_t nstr = d->scan.string_count;
	if(n > UINT32_MAX) return false;
	d->aux_open_count = n;
	/* Full CSR is ~8 bytes/open; on huge docs prefer tag-row + parent filter. */
	int build_csr = env_flag_on("LOM_CSR", n < (size_t)100000000);

	/* Only index name_ids that appear on opens — not every interned attr value. */
	uint32_t max_tag_nid = 0;
	for(size_t i = 0; i < n; i++) {
		uint32_t nid = d->scan.opens[i].name_id;
		if(nid > max_tag_nid) max_tag_nid = nid;
		if((i & 65535u) == 65535u) aux_opens_dontneed(d, i > 8192 ? i - 8192 : 0);
	}
	size_t tag_slots = (size_t)max_tag_nid + 1;
	if(tag_slots > nstr) tag_slots = nstr;
	d->tag_row_slots = tag_slots;
	d->tag_rows = calloc(tag_slots ? tag_slots : 1, sizeof(uint32_t *));
	d->tag_row_counts = calloc(tag_slots ? tag_slots : 1, sizeof(uint32_t));
	if(!d->tag_rows || !d->tag_row_counts) return false;

	for(size_t i = 0; i < n; i++) {
		uint32_t nid = d->scan.opens[i].name_id;
		if(nid < tag_slots) d->tag_row_counts[nid]++;
		if((i & 65535u) == 65535u) aux_opens_dontneed(d, i > 8192 ? i - 8192 : 0);
	}
	for(size_t nid = 0; nid < tag_slots; nid++) {
		if(d->tag_row_counts[nid] == 0) continue;
		d->tag_rows[nid] = malloc(d->tag_row_counts[nid] * sizeof(uint32_t));
		if(!d->tag_rows[nid]) return false;
		d->tag_row_counts[nid] = 0;
	}
	for(size_t i = 0; i < n; i++) {
		uint32_t nid = d->scan.opens[i].name_id;
		if(nid >= tag_slots) continue;
		d->tag_rows[nid][d->tag_row_counts[nid]++] = (uint32_t)i;
		if((i & 65535u) == 65535u) aux_opens_dontneed(d, i > 8192 ? i - 8192 : 0);
	}

	/* LOM_PARALLEL is honored in lom_scan_indexes (piece-local sibling scans +
	 * string-table merge). Shared-atomic CSR fill was tried earlier and lost;
	 * piece-local scan is correct but has not beaten serial construct on this
	 * host (range-find + merge tax) — left opt-in for further tuning. */
	(void)d->use_parallel;

	size_t root_n = 0;
	for(size_t i = 0; i < n; i++) {
		if(d->scan.opens[i].parent_idx < 0) root_n++;
		if((i & 65535u) == 65535u) aux_opens_dontneed(d, i > 8192 ? i - 8192 : 0);
	}
	d->root_children = root_n ? malloc(root_n * sizeof(uint32_t)) : NULL;
	if(root_n && !d->root_children) return false;
	d->root_child_count = 0;
	for(size_t i = 0; i < n; i++) {
		if(d->scan.opens[i].parent_idx < 0)
			d->root_children[d->root_child_count++] = (uint32_t)i;
		if((i & 65535u) == 65535u) aux_opens_dontneed(d, i > 8192 ? i - 8192 : 0);
	}

	if(build_csr) {
		uint32_t *counts = calloc(n ? n : 1, sizeof(uint32_t));
		if(!counts) return false;
		for(size_t i = 0; i < n; i++) {
			int32_t pi = d->scan.opens[i].parent_idx;
			if(pi >= 0 && (size_t)pi < n) counts[(size_t)pi]++;
			if((i & 65535u) == 65535u) aux_opens_dontneed(d, i > 8192 ? i - 8192 : 0);
		}
		d->child_start = malloc((n + 1) * sizeof(uint32_t));
		if(!d->child_start) { free(counts); return false; }
		uint32_t run = 0;
		for(size_t i = 0; i < n; i++) {
			d->child_start[i] = run;
			run += counts[i];
			counts[i] = 0;
		}
		d->child_start[n] = run;
		d->child_at = run ? malloc(run * sizeof(uint32_t)) : NULL;
		if(run && !d->child_at) { free(counts); return false; }
		for(size_t i = 0; i < n; i++) {
			int32_t parent = d->scan.opens[i].parent_idx;
			if(parent < 0) continue;
			if((size_t)parent >= n) continue;
			uint32_t slot = d->child_start[(size_t)parent] + counts[(size_t)parent]++;
			d->child_at[slot] = (uint32_t)i;
			if((i & 65535u) == 65535u) aux_opens_dontneed(d, i > 8192 ? i - 8192 : 0);
		}
		free(counts);
	}

	/* Drop open-table pages after indexes are built — queries fault what they need. */
	if(d->scan.opens_is_mmap && d->scan.opens && d->scan.open_count) {
		size_t bytes = d->scan.open_count * sizeof(lom_open_row);
		(void)madvise(d->scan.opens, bytes, MADV_DONTNEED);
	}

	uint32_t max_attr_nid = 0;
	for(size_t i = 0; i < d->scan.attr_count; i++) {
		uint32_t anid = d->scan.attrs[i].name_id;
		if(anid > max_attr_nid) max_attr_nid = anid;
	}
	size_t attr_slots = d->scan.attr_count ? ((size_t)max_attr_nid + 1) : 0;
	if(attr_slots > nstr) attr_slots = nstr;
	d->attr_slots = attr_slots;
	d->attr_opens = calloc(attr_slots ? attr_slots : 1, sizeof(uint32_t *));
	d->attr_open_counts = calloc(attr_slots ? attr_slots : 1, sizeof(uint32_t));
	if(!d->attr_opens || !d->attr_open_counts) return false;
	for(size_t i = 0; i < d->scan.attr_count; i++) {
		uint32_t anid = d->scan.attrs[i].name_id;
		if(anid < attr_slots) d->attr_open_counts[anid]++;
	}
	for(size_t nid = 0; nid < attr_slots; nid++) {
		if(d->attr_open_counts[nid] == 0) continue;
		d->attr_opens[nid] = malloc(d->attr_open_counts[nid] * sizeof(uint32_t));
		if(!d->attr_opens[nid]) return false;
		d->attr_open_counts[nid] = 0;
	}
	for(size_t i = 0; i < d->scan.attr_count; i++) {
		uint32_t anid = d->scan.attrs[i].name_id;
		if(anid >= attr_slots) continue;
		size_t oi = (size_t)d->scan.attrs[i].open_idx;
		if(oi >= n || d->scan.opens[oi].open_off != d->scan.attrs[i].open_off) {
			oi = find_open_index(d, d->scan.attrs[i].open_off);
			if(oi == (size_t)-1) continue;
		}
		d->attr_opens[anid][d->attr_open_counts[anid]++] = (uint32_t)oi;
	}
	return true;
}

static bool doc_ensure_writable(lom_doc *d) {
	if(!d->code_is_mmap) return true;
	/* MAP_PRIVATE COW — avoids an immediate 20GB malloc; growth uses one-pass rewrite. */
	if(d->mmap_fd >= 0) {
		void *p = mmap(NULL, d->code_len ? d->code_len : 1,
			PROT_READ | PROT_WRITE, MAP_PRIVATE, d->mmap_fd, 0);
		if(p != MAP_FAILED) {
			munmap(d->code, d->code_len ? d->code_len : 1);
			d->code = p;
			d->code_cap = d->code_len; /* no trailing NUL room until heap promote */
			return true;
		}
	}
	char *nb = malloc(d->code_len + 1);
	if(!nb) return false;
	if(d->code_len) memcpy(nb, d->code, d->code_len);
	nb[d->code_len] = 0;
	munmap(d->code, d->code_len ? d->code_len : 1);
	if(d->mmap_fd >= 0) {
		close(d->mmap_fd);
		d->mmap_fd = -1;
	}
	d->code = nb;
	d->code_cap = d->code_len + 1;
	d->code_is_mmap = 0;
	return true;
}

#ifndef LOM_CODE_FILE_THRESHOLD
/* Tempfile mmap for multi-GB rewrites; mid-size growth stays a one-pass heap copy. */
#define LOM_CODE_FILE_THRESHOLD ((size_t)256 * 1024 * 1024)
#endif

static const char *doc_code_tmpdir(void) {
	const char *dir = getenv("LOM_OPEN_TMPDIR");
	/* /tmp is often small tmpfs — prefer /var/tmp for multi-GB rewrites. */
	if(!dir || !dir[0] || strcmp(dir, "/tmp") == 0) return "/var/tmp";
	return dir;
}

static size_t doc_code_map_bytes(const lom_doc *d) {
	if(!d->code) return 0;
	if(d->code_is_mmap) {
		if(d->code_cap) return d->code_cap;
		return d->code_len ? d->code_len : 1;
	}
	return d->code_cap;
}

static void doc_code_release(lom_doc *d) {
	if(!d->code) return;
	if(d->code_is_mmap) {
		munmap(d->code, doc_code_map_bytes(d));
		if(d->mmap_fd >= 0) close(d->mmap_fd);
		d->mmap_fd = -1;
		d->code_is_mmap = 0;
	} else {
		free(d->code);
	}
	d->code = NULL;
	d->code_cap = 0;
}

/* Copy src→dst in chunks; optionally drop already-copied source pages (mmap). */
static void copy_bytes_chunked(char *dst, const char *src, size_t n, int src_is_mmap) {
	const size_t CH = (size_t)8 * 1024 * 1024;
	size_t off = 0;
	while(off < n) {
		size_t m = n - off;
		if(m > CH) m = CH;
		memcpy(dst + off, src + off, m);
		off += m;
		if(src_is_mmap && off >= CH) {
			size_t drop = (off - (off % 4096));
			if(drop >= CH)
				(void)madvise((void *)src, drop - (CH / 2), MADV_DONTNEED);
		}
	}
}

/* One-pass prefix|insert|suffix into a new buffer (tempfile mmap when large).
 * Avoids the old growth path of full promote memcpy + second full memmove. */
static bool doc_code_rewrite_splice(lom_doc *d, size_t at, size_t remove_len,
	const char *insert, size_t insert_len) {
	size_t new_len = d->code_len - remove_len + insert_len;
	size_t need = new_len + 1;
	int prefer_file = new_len >= LOM_CODE_FILE_THRESHOLD;

	if(d->scan.opens_is_mmap && d->scan.opens && d->scan.open_count) {
		size_t ob = d->scan.open_count * sizeof(lom_open_row);
		(void)madvise(d->scan.opens, ob, MADV_DONTNEED);
	}

	char *nb = NULL;
	int nfd = -1;
	int is_mmap = 0;

	if(prefer_file) {
		char tmpl[512];
		snprintf(tmpl, sizeof(tmpl), "%s/lom_codeXXXXXX", doc_code_tmpdir());
		nfd = mkstemp(tmpl);
		if(nfd < 0) {
			snprintf(tmpl, sizeof(tmpl), "/var/tmp/lom_codeXXXXXX");
			nfd = mkstemp(tmpl);
		}
		if(nfd >= 0) {
			unlink(tmpl);
			if(ftruncate(nfd, (off_t)need) == 0) {
				void *p = mmap(NULL, need, PROT_READ | PROT_WRITE, MAP_SHARED, nfd, 0);
				if(p != MAP_FAILED) {
					nb = (char *)p;
					is_mmap = 1;
				}
			}
			if(!nb) {
				close(nfd);
				nfd = -1;
			}
		}
	}
	if(!nb) {
		nb = malloc(need);
		if(!nb) return false;
	}

	int src_mmap = d->code_is_mmap;
	if(at) copy_bytes_chunked(nb, d->code, at, src_mmap);
	if(insert_len) memcpy(nb + at, insert, insert_len);
	size_t tail = d->code_len - at - remove_len;
	if(tail) copy_bytes_chunked(nb + at + insert_len, d->code + at + remove_len, tail, src_mmap);
	nb[new_len] = 0;

	doc_code_release(d);
	d->code = nb;
	d->code_len = new_len;
	d->code_cap = need;
	d->code_is_mmap = is_mmap;
	d->mmap_fd = nfd;
	return true;
}

/* Apply splice to document bytes: in-place when capacity allows, else one-pass rewrite. */
static bool doc_code_splice_bytes(lom_doc *d, size_t at, size_t remove_len,
	const char *insert, size_t insert_len) {
	size_t new_len = d->code_len - remove_len + insert_len;
	int can_inplace = 0;
	if(!d->code_is_mmap && new_len + 1 <= d->code_cap) can_inplace = 1;
	/* Same-size/shrink on MAP_PRIVATE stays in the existing mapping. */
	if(d->code_is_mmap && new_len <= d->code_len) can_inplace = 1;

	if(can_inplace) {
		memmove(d->code + at + insert_len, d->code + at + remove_len,
			d->code_len - (at + remove_len));
		if(insert_len) memcpy(d->code + at, insert, insert_len);
		d->code_len = new_len;
		if(!d->code_is_mmap && d->code_len < d->code_cap) d->code[d->code_len] = 0;
		return true;
	}
	return doc_code_rewrite_splice(d, at, remove_len, insert, insert_len);
}

static lom_status doc_reindex(lom_doc *d) {
	int capture_attrs = env_flag_on("LOM_ATTRS", d->code_len < (size_t)512 * 1024 * 1024);
	lom_status st = lom_scan_indexes(d->code, d->code_len, &d->scan, capture_attrs != 0);
	if(st != LOM_OK) {
		d->status = st;
		snprintf(d->error, sizeof(d->error), "%s", d->scan.error);
		return st;
	}
	if(!doc_rebuild_aux(d)) {
		d->status = LOM_ERR_NOMEM;
		snprintf(d->error, sizeof(d->error), "rebuild aux failed");
		return LOM_ERR_NOMEM;
	}
	doc_rebuild_pieces(d);
	doc_rebuild_fstr(d);
	doc_fmem_ingest_strings(d);
	if(d->use_fcache) {
		if(d->fcache) lom_fcache_free(d->fcache);
		d->fcache = lom_fcache_create(512);
	}
	d->aux_dirty = 0;
	d->status = LOM_OK;
	d->error[0] = 0;
	return LOM_OK;
}

static lom_status doc_ensure_aux(lom_doc *d) {
	if(!d->aux_dirty) return LOM_OK;
	if(!doc_rebuild_aux(d)) {
		d->status = LOM_ERR_NOMEM;
		snprintf(d->error, sizeof(d->error), "rebuild aux failed");
		return LOM_ERR_NOMEM;
	}
	doc_fmem_ingest_strings(d);
	doc_rebuild_fstr(d);
	d->aux_dirty = 0;
	return LOM_OK;
}

lom_doc *lom_doc_create(const char *code, size_t code_len) {
	lom_doc *d = calloc(1, sizeof(lom_doc));
	if(!d) return NULL;
	lom_scan_result_init(&d->scan);
	doc_init_accel(d);
	d->code_cap = code_len + 1;
	d->code = malloc(d->code_cap);
	if(!d->code) { doc_free_accel(d); free(d); return NULL; }
	if(code_len) memcpy(d->code, code, code_len);
	d->code[code_len] = 0;
	d->code_len = code_len;
	if(doc_reindex(d) != LOM_OK) {
		/* still return doc with error status */
	}
	return d;
}

lom_doc *lom_doc_create_file(const char *path) {
	/* Prefer mmap so large files are not fully memcpy'd. */
	int fd = open(path, O_RDONLY);
	if(fd < 0) return NULL;
	struct stat st;
	if(fstat(fd, &st) != 0 || st.st_size < 0) {
		close(fd);
		return NULL;
	}
	size_t n = (size_t)st.st_size;
	if(n == 0) {
		close(fd);
		return lom_doc_create("", 0);
	}
	void *map = mmap(NULL, n, PROT_READ, MAP_PRIVATE, fd, 0);
	if(map == MAP_FAILED) {
		/* Fallback to fread */
		FILE *f = fdopen(fd, "rb");
		if(!f) { close(fd); return NULL; }
		char *buf = malloc(n + 1);
		if(!buf) { fclose(f); return NULL; }
		size_t got = fread(buf, 1, n, f);
		fclose(f);
		buf[got] = 0;
		lom_doc *d = lom_doc_create(buf, got);
		free(buf);
		return d;
	}
	lom_doc *d = calloc(1, sizeof(lom_doc));
	if(!d) {
		munmap(map, n);
		close(fd);
		return NULL;
	}
	lom_scan_result_init(&d->scan);
	doc_init_accel(d);
	d->code = (char *)map;
	d->code_len = n;
	d->code_cap = n;
	d->code_is_mmap = 1;
	d->mmap_fd = fd;
	/* Scanner expects a trailing NUL for some C-string helpers; MAP_PRIVATE copy-on-write
	 * cannot extend. Reindex uses explicit lengths — ensure last byte reads are bounded. */
	if(doc_reindex(d) != LOM_OK) {
		/* keep doc */
	}
	return d;
}

void lom_doc_free(lom_doc *doc) {
	if(!doc) return;
	doc_clear_aux(doc);
	lom_scan_result_free(&doc->scan);
	doc_free_accel(doc);
	doc_code_release(doc);
	free(doc);
#if defined(__GLIBC__)
	malloc_trim(0);
#endif
}

lom_status lom_doc_status(const lom_doc *doc) {
	return doc ? doc->status : LOM_ERR_ARG;
}

const char *lom_doc_error(const lom_doc *doc) {
	return doc ? doc->error : "null doc";
}

const char *lom_doc_code(const lom_doc *doc, size_t *out_len) {
	if(!doc) return NULL;
	if(out_len) *out_len = doc->code_len;
	return doc->code;
}

size_t lom_doc_open_count(const lom_doc *doc) {
	return doc ? doc->scan.open_count : 0;
}

bool lom_doc_brackets_balanced(const lom_doc *doc) {
	if(!doc) return false;
	size_t lt = 0, gt = 0;
	for(size_t i = 0; i < doc->code_len; i++) {
		if(doc->code[i] == '<') lt++;
		else if(doc->code[i] == '>') gt++;
	}
	return lt == gt;
}

lom_status lom_doc_node_slice(const lom_doc *doc, int64_t open_off, const char **ptr, size_t *len) {
	if(!doc || !ptr || !len) return LOM_ERR_ARG;
	size_t idx = find_open_index(doc, open_off);
	if(idx == (size_t)-1) return LOM_ERR_NOTFOUND;
	int64_t end = doc->scan.opens[idx].node_end_off;
	if(end < open_off) return LOM_ERR_PARSE;
	*ptr = doc->code + open_off;
	*len = (size_t)(end - open_off + 1);
	return LOM_OK;
}

static bool name_eq(const lom_doc *d, uint32_t id, const char *name, size_t nlen) {
	const char *s = lom_scan_string(&d->scan, id);
	return strncmp(s, name, nlen) == 0 && s[nlen] == '\0';
}

static int32_t find_name_id(const lom_doc *d, const char *name, size_t nlen) {
	for(size_t i = 0; i < d->scan.string_count; i++) {
		if(name_eq(d, (uint32_t)i, name, nlen)) return (int32_t)i;
	}
	return -1;
}

enum {
	LOM_ROP_NONE = 0,
	LOM_ROP_EQ,
	LOM_ROP_PCT,
	LOM_ROP_CARET,
	LOM_ROP_DOLLAR,
	LOM_ROP_TILDE,
	LOM_ROP_NE
};

typedef struct {
	int rop;
	char pattern[512];
	size_t pattern_len;
	char flags[8];
	size_t flags_len;
} sel_regex_slot;

typedef struct {
	char name[128];
	size_t name_len;
	int index; /* 1-based, 0 = none */
	char attr[128];
	size_t attr_len;
	char attr_val[256];
	size_t attr_val_len;
	bool has_attr;
	bool attr_eq;
	char text[256];
	size_t text_len;
	bool has_text;
	int rop;
	int regex_id; /* -1 none; else index into sel_chain.regexes */
	bool has_regex;
} sel_piece;

typedef struct {
	sel_piece pieces[32];
	size_t count;
	sel_regex_slot regexes[16];
	size_t regex_count;
} sel_chain;

static bool append_ch(char *buf, size_t *len, size_t cap, char c) {
	if(*len + 1 >= cap) return false;
	buf[(*len)++] = c;
	return true;
}

static bool parse_rop_at(const char *s, size_t *i, int *rop) {
	if(s[*i] == '!' && s[*i + 1] == '=') { *rop = LOM_ROP_NE; *i += 2; return true; }
	if(s[*i] == '%' && s[*i + 1] == '=') { *rop = LOM_ROP_PCT; *i += 2; return true; }
	if(s[*i] == '^' && s[*i + 1] == '=') { *rop = LOM_ROP_CARET; *i += 2; return true; }
	if(s[*i] == '$' && s[*i + 1] == '=') { *rop = LOM_ROP_DOLLAR; *i += 2; return true; }
	if(s[*i] == '~' && s[*i + 1] == '=') { *rop = LOM_ROP_TILDE; *i += 2; return true; }
	if(s[*i] == '=') { *rop = LOM_ROP_EQ; (*i)++; return true; }
	return false;
}

/* Rewrite /pattern/flags after comparison ops into #lomregex#N# so '_' in patterns
 * does not split child axes. */
static bool extract_selector_regexes(const char *sel, char *out, size_t out_cap, sel_chain *chain) {
	size_t oi = 0;
	size_t i = 0;
	chain->regex_count = 0;
	while(sel[i]) {
		size_t save = i;
		int rop = LOM_ROP_NONE;
		if(parse_rop_at(sel, &i, &rop) && sel[i] == '/') {
			i++; /* past opening / */
			sel_regex_slot *slot = &chain->regexes[chain->regex_count];
			memset(slot, 0, sizeof(*slot));
			slot->rop = rop;
			while(sel[i]) {
				if(sel[i] == '\\' && sel[i + 1]) {
					if(sel[i + 1] == '/') {
						if(!append_ch(slot->pattern, &slot->pattern_len, sizeof(slot->pattern), '/')) return false;
						i += 2;
						continue;
					}
					if(!append_ch(slot->pattern, &slot->pattern_len, sizeof(slot->pattern), '\\')) return false;
					if(!append_ch(slot->pattern, &slot->pattern_len, sizeof(slot->pattern), sel[i + 1])) return false;
					i += 2;
					continue;
				}
				if(sel[i] == '/') break;
				if(!append_ch(slot->pattern, &slot->pattern_len, sizeof(slot->pattern), sel[i])) return false;
				i++;
			}
			if(sel[i] != '/') return false;
			i++;
			while(sel[i] == 'i' || sel[i] == 'm' || sel[i] == 's' || sel[i] == 'u' || sel[i] == 'x') {
				if(!append_ch(slot->flags, &slot->flags_len, sizeof(slot->flags), sel[i])) return false;
				i++;
			}
			if(chain->regex_count >= 16) return false;
			char tok[32];
			int tn = snprintf(tok, sizeof(tok), "#lomregex#%zu#", chain->regex_count);
			if(tn < 0 || (size_t)tn >= sizeof(tok)) return false;
			/* emit op + token (op already consumed from input; re-emit as = placeholder + id) */
			const char *opsp = "=";
			if(rop == LOM_ROP_NE) opsp = "!=";
			else if(rop == LOM_ROP_PCT) opsp = "%=";
			else if(rop == LOM_ROP_CARET) opsp = "^=";
			else if(rop == LOM_ROP_DOLLAR) opsp = "$=";
			else if(rop == LOM_ROP_TILDE) opsp = "~=";
			for(const char *q = opsp; *q; q++) {
				if(oi + 1 >= out_cap) return false;
				out[oi++] = *q;
			}
			for(int k = 0; k < tn; k++) {
				if(oi + 1 >= out_cap) return false;
				out[oi++] = tok[k];
			}
			chain->regex_count++;
			continue;
		}
		i = save;
		if(oi + 1 >= out_cap) return false;
		out[oi++] = sel[i++];
	}
	if(oi >= out_cap) return false;
	out[oi] = 0;
	return true;
}

static bool parse_index_at(const char *s, size_t n, size_t *i, int *out_idx) {
	if(*i >= n || s[*i] != '[') return false;
	size_t j = *i + 1;
	int idx = 0;
	if(j >= n || !isdigit((unsigned char)s[j])) return false;
	while(j < n && isdigit((unsigned char)s[j])) {
		idx = idx * 10 + (s[j] - '0');
		j++;
	}
	if(j >= n || s[j] != ']') return false;
	*i = j + 1;
	*out_idx = idx;
	return true;
}

static bool parse_lomregex_token(const char *s, size_t n, size_t *i, int *id) {
	const char *prefix = "#lomregex#";
	size_t plen = 10;
	if(*i + plen > n || memcmp(s + *i, prefix, plen) != 0) return false;
	size_t j = *i + plen;
	int v = 0;
	if(j >= n || !isdigit((unsigned char)s[j])) return false;
	while(j < n && isdigit((unsigned char)s[j])) {
		v = v * 10 + (s[j] - '0');
		j++;
	}
	if(j >= n || s[j] != '#') return false;
	*i = j + 1;
	*id = v;
	return true;
}

static bool parse_piece(const char *s, size_t n, sel_piece *p, const sel_chain *chain) {
	memset(p, 0, sizeof(*p));
	p->regex_id = -1;
	size_t i = 0;
	while(i < n && (isalnum((unsigned char)s[i]) || s[i] == '_' || s[i] == '-' || s[i] == ':' || s[i] == '*')) {
		if(p->name_len + 1 >= sizeof(p->name)) return false;
		p->name[p->name_len++] = s[i++];
	}
	if(p->name_len == 0) return false;
	if(i < n && s[i] == '[') {
		if(!parse_index_at(s, n, &i, &p->index)) return false;
	}
	if(i < n && s[i] == '@') {
		i++;
		p->has_attr = true;
		while(i < n && s[i] != '=' && s[i] != '!' && s[i] != '%' && s[i] != '^' && s[i] != '$' && s[i] != '~' &&
			(isalnum((unsigned char)s[i]) || s[i] == '_' || s[i] == '-' || s[i] == ':')) {
			if(p->attr_len + 1 >= sizeof(p->attr)) return false;
			p->attr[p->attr_len++] = s[i++];
		}
		if(p->attr_len == 0) return false;
		if(i < n) {
			int rop = LOM_ROP_NONE;
			if(!parse_rop_at(s, &i, &rop)) return false;
			p->attr_eq = true;
			p->rop = rop;
			int rid = -1;
			if(parse_lomregex_token(s, n, &i, &rid)) {
				if(rid < 0 || (size_t)rid >= chain->regex_count) return false;
				p->has_regex = true;
				p->regex_id = rid;
			} else if(rop == LOM_ROP_EQ) {
				while(i < n) {
					if(p->attr_val_len + 1 >= sizeof(p->attr_val)) return false;
					p->attr_val[p->attr_val_len++] = s[i++];
				}
			} else {
				return false;
			}
		}
	} else if(i < n) {
		int rop = LOM_ROP_NONE;
		if(!parse_rop_at(s, &i, &rop)) return false;
		p->rop = rop;
		int rid = -1;
		if(parse_lomregex_token(s, n, &i, &rid)) {
			if(rid < 0 || (size_t)rid >= chain->regex_count) return false;
			p->has_regex = true;
			p->regex_id = rid;
		} else if(rop == LOM_ROP_EQ) {
			p->has_text = true;
			while(i < n) {
				if(p->text_len + 1 >= sizeof(p->text)) return false;
				p->text[p->text_len++] = s[i++];
			}
		} else {
			return false;
		}
	}
	if(i < n && s[i] == '[') {
		if(!parse_index_at(s, n, &i, &p->index)) return false;
	}
	if(i != n) return false;
	return true;
}

static bool parse_selector(const char *sel, sel_chain *out) {
	memset(out, 0, sizeof(*out));
	if(!sel || !*sel) return false;
	char rewritten[2048];
	if(!extract_selector_regexes(sel, rewritten, sizeof(rewritten), out)) return false;
	/* Match PHP: #underscore# (etc.) must survive '_' path splits. */
	{
		char tmp[2048];
		size_t wi = 0;
		for(size_t i = 0; rewritten[i] && wi + 1 < sizeof(tmp);) {
			if(strncmp(rewritten + i, "#underscore#", 12) == 0) {
				tmp[wi++] = '\x1E'; i += 12;
			} else if(strncmp(rewritten + i, "#forwardslash#", 14) == 0) {
				tmp[wi++] = '\x1F'; i += 14;
			} else {
				tmp[wi++] = rewritten[i++];
			}
		}
		tmp[wi] = 0;
		memcpy(rewritten, tmp, wi + 1);
	}
	const char *p = rewritten;
	while(*p) {
		while(*p == '_') p++;
		if(!*p) break;
		const char *start = p;
		while(*p && *p != '_') p++;
		size_t n = (size_t)(p - start);
		if(n == 0) continue;
		if(out->count >= 32) return false;
		char piecebuf[512];
		if(n >= sizeof(piecebuf)) return false;
		memcpy(piecebuf, start, n);
		piecebuf[n] = 0;
		for(size_t i = 0; i < n; i++) {
			if(piecebuf[i] == '\x1E') piecebuf[i] = '_';
			else if(piecebuf[i] == '\x1F') piecebuf[i] = '/';
		}
		if(!parse_piece(piecebuf, n, &out->pieces[out->count], out)) return false;
		out->count++;
		if(*p == '_' && *(p + 1) == '_') p += 2;
		else if(*p == '_') p++;
	}
	return out->count > 0;
}

static bool tag_span_has_attr(const char *tag, size_t tlen, const sel_piece *piece) {
	if(!tag || tlen < 3 || piece->attr_len == 0) return false;
	/* Scan for attr_name then = within the open tag (no nested tags). */
	for(size_t i = 1; i + piece->attr_len < tlen; i++) {
		if(memcmp(tag + i, piece->attr, piece->attr_len) != 0) continue;
		unsigned char prev = (unsigned char)tag[i - 1];
		if(!(prev == ' ' || prev == '\t' || prev == '\n' || prev == '\r' || prev == '<'))
			continue;
		size_t j = i + piece->attr_len;
		while(j < tlen && (tag[j] == ' ' || tag[j] == '\t')) j++;
		if(j >= tlen || tag[j] != '=') continue;
		if(!piece->attr_eq) return true;
		j++;
		while(j < tlen && (tag[j] == ' ' || tag[j] == '\t')) j++;
		if(j >= tlen) return false;
		char q = tag[j];
		const char *val;
		size_t vlen;
		if(q == '"' || q == '\'') {
			j++;
			size_t start = j;
			while(j < tlen && tag[j] != q) j++;
			val = tag + start;
			vlen = j - start;
		} else {
			size_t start = j;
			while(j < tlen && tag[j] != ' ' && tag[j] != '\t' && tag[j] != '/' && tag[j] != '>') j++;
			val = tag + start;
			vlen = j - start;
		}
		if(vlen == piece->attr_val_len && memcmp(val, piece->attr_val, vlen) == 0) return true;
	}
	return false;
}

static bool row_has_attr(const lom_doc *d, int64_t open_off, const sel_piece *piece) {
	if(d->scan.attr_count) {
		for(size_t i = 0; i < d->scan.attr_count; i++) {
			const lom_attr_row *a = &d->scan.attrs[i];
			if(a->open_off != open_off) continue;
			if(!name_eq(d, a->name_id, piece->attr, piece->attr_len)) continue;
			if(!piece->attr_eq) return true;
			return name_eq(d, a->value_id, piece->attr_val, piece->attr_val_len);
		}
		return false;
	}
	/* No attr index (huge-doc path): parse the open tag bytes. */
	size_t oi = find_open_index(d, open_off);
	if(oi == (size_t)-1) return false;
	const lom_open_row *row = &d->scan.opens[oi];
	if(row->tag_end_off < row->open_off) return false;
	size_t tlen = (size_t)(row->tag_end_off - row->open_off + 1);
	if(row->open_off < 0 || (size_t)row->open_off + tlen > d->code_len) return false;
	return tag_span_has_attr(d->code + row->open_off, tlen, piece);
}

static bool inner_text_eq(const lom_doc *d, size_t open_idx, const char *text, size_t tlen) {
	const lom_open_row *row = &d->scan.opens[open_idx];
	int64_t start = row->tag_end_off + 1;
	int64_t end = row->node_end_off;
	/* find closing '<' */
	int64_t close_lt = end;
	while(close_lt > start && d->code[close_lt] != '<') close_lt--;
	if(close_lt <= start || d->code[close_lt] != '<') return false;
	size_t len = (size_t)(close_lt - start);
	if(len != tlen) return false;
	return memcmp(d->code + start, text, tlen) == 0;
}


static bool inner_text_span(const lom_doc *d, size_t open_idx, const char **out, size_t *out_len) {
	const lom_open_row *row = &d->scan.opens[open_idx];
	if(row->self_closing) {
		*out = "";
		*out_len = 0;
		return true;
	}
	int64_t start = row->tag_end_off + 1;
	int64_t end = row->node_end_off;
	int64_t close_lt = end;
	while(close_lt > start && d->code[close_lt] != '<') close_lt--;
	if(close_lt <= start || d->code[close_lt] != '<') {
		*out = "";
		*out_len = 0;
		return true;
	}
	*out = d->code + start;
	*out_len = (size_t)(close_lt - start);
	return true;
}

static pcre2_code *compile_sel_regex(const sel_regex_slot *slot) {
	uint32_t options = 0;
	for(size_t i = 0; i < slot->flags_len; i++) {
		char f = slot->flags[i];
		if(f == 'i') options |= PCRE2_CASELESS;
		else if(f == 'm') options |= PCRE2_MULTILINE;
		else if(f == 's') options |= PCRE2_DOTALL;
		else if(f == 'u') options |= PCRE2_UTF;
		else if(f == 'x') options |= PCRE2_EXTENDED;
	}
	int errcode = 0;
	PCRE2_SIZE erroff = 0;
	return pcre2_compile((PCRE2_SPTR)slot->pattern, slot->pattern_len, options, &errcode, &erroff, NULL);
}

static bool regex_keeps(int rop, pcre2_code *re, pcre2_match_data *md, const char *text, size_t tlen) {
	if(!re || !md) return false;
	if(rop == LOM_ROP_PCT || rop == LOM_ROP_NE) {
		int rc = pcre2_match(re, (PCRE2_SPTR)text, tlen, 0, 0, md, NULL);
		bool hit = (rc >= 0);
		return rop == LOM_ROP_NE ? !hit : hit;
	}
	PCRE2_SIZE off = 0;
	while(off <= tlen) {
		int rc = pcre2_match(re, (PCRE2_SPTR)text, tlen, off, 0, md, NULL);
		if(rc < 0) break;
		PCRE2_SIZE *ov = pcre2_get_ovector_pointer(md);
		PCRE2_SIZE ms = ov[0], me = ov[1];
		if(rop == LOM_ROP_EQ && ms == 0 && me == tlen) return true;
		if(rop == LOM_ROP_CARET && ms == 0) return true;
		if(rop == LOM_ROP_DOLLAR && me == tlen) return true;
		if(rop == LOM_ROP_TILDE) {
			bool left = (ms == 0) || isspace((unsigned char)text[ms - 1]);
			bool right = (me == tlen) || isspace((unsigned char)text[me]);
			if(left && right) return true;
		}
		if(me == ms) off = me + 1;
		else off = me;
		if(off == 0) break;
	}
	return false;
}

static bool row_attr_value(const lom_doc *d, int64_t open_off, const char *aname, size_t anlen, const char **val, size_t *vlen) {
	for(size_t i = 0; i < d->scan.attr_count; i++) {
		const lom_attr_row *a = &d->scan.attrs[i];
		if(a->open_off != open_off) continue;
		if(!name_eq(d, a->name_id, aname, anlen)) continue;
		const char *s = lom_scan_string(&d->scan, a->value_id);
		if(!s) return false;
		*val = s;
		*vlen = strlen(s);
		return true;
	}
	return false;
}

static bool piece_matches_open(const lom_doc *d, size_t oi, const sel_piece *piece, const sel_chain *chain,
	pcre2_code *re, pcre2_match_data *md) {
	const lom_open_row *row = &d->scan.opens[oi];
	if(piece->has_regex) {
		if(piece->regex_id < 0 || (size_t)piece->regex_id >= chain->regex_count) return false;
		const sel_regex_slot *slot = &chain->regexes[piece->regex_id];
		int rop = piece->rop ? piece->rop : slot->rop;
		if(piece->has_attr) {
			const char *val = NULL; size_t vlen = 0;
			if(!row_attr_value(d, row->open_off, piece->attr, piece->attr_len, &val, &vlen)) {
				return rop == LOM_ROP_NE;
			}
			return regex_keeps(rop, re, md, val, vlen);
		}
		const char *text = NULL; size_t tlen = 0;
		inner_text_span(d, oi, &text, &tlen);
		return regex_keeps(rop, re, md, text, tlen);
	}
	if(piece->has_attr && !row_has_attr(d, row->open_off, piece)) return false;
	if(piece->has_text && !inner_text_eq(d, oi, piece->text, piece->text_len)) return false;
	return true;
}

static lom_status query_regex_leaf(lom_doc *d, const sel_chain *chain, lom_match_list *out) {
	lom_match_list_free(out);
	lom_match_list_init(out);
	const sel_piece *piece = &chain->pieces[chain->count - 1];
	if(!piece->has_regex || piece->regex_id < 0) return LOM_ERR_PARSE;
	const sel_regex_slot *slot = &chain->regexes[piece->regex_id];
	pcre2_code *re = compile_sel_regex(slot);
	if(!re) return LOM_ERR_PARSE;
	pcre2_match_data *md = pcre2_match_data_create_from_pattern(re, NULL);
	if(!md) { pcre2_code_free(re); return LOM_ERR_NOMEM; }

	int32_t nid = find_name_id(d, piece->name, piece->name_len);
	if(nid < 0 && !(piece->name_len == 1 && piece->name[0] == '*')) {
		pcre2_match_data_free(md);
		pcre2_code_free(re);
		return LOM_OK;
	}

	size_t k = 0;
	uint32_t *rows = NULL;
	if(piece->name_len == 1 && piece->name[0] == '*') {
		k = d->scan.open_count;
	} else {
		rows = d->tag_rows[nid];
		k = d->tag_row_counts[nid];
	}

	/* Document-level map when selective enough and leaf is text regex (not attr). */
	if(!piece->has_attr && k >= 256 && d->code_len > 0) {
		size_t hit_cap = 1024, hit_n = 0;
		PCRE2_SIZE *hits = malloc(hit_cap * sizeof(PCRE2_SIZE));
		if(!hits) { pcre2_match_data_free(md); pcre2_code_free(re); return LOM_ERR_NOMEM; }
		/* Cold prune: fractal string + literal probe → scan candidate spans only. */
		uint8_t probe[128];
		size_t probe_n = lom_fstr_regex_probe(slot->pattern, slot->pattern_len, probe, sizeof(probe));
		int64_t span_lo[256], span_hi[256];
		size_t nspans = 0;
		if(d->fstr && probe_n >= 3) {
			nspans = lom_fstr_candidate_spans(d->fstr, probe, probe_n, span_lo, span_hi, 256);
			if(nspans == 0) {
				free(hits);
				pcre2_match_data_free(md);
				pcre2_code_free(re);
				return LOM_OK; /* probe impossible in doc */
			}
		}
		if(nspans == 0 || nspans >= 256) {
			span_lo[0] = 0;
			span_hi[0] = (int64_t)d->code_len;
			nspans = 1;
		}
		int abort_selective = 0;
		for(size_t si = 0; si < nspans && !abort_selective; si++) {
			if(span_lo[si] < 0 || span_hi[si] <= span_lo[si]) continue;
			size_t slo = (size_t)span_lo[si];
			size_t shi = (size_t)span_hi[si];
			if(shi > d->code_len) shi = d->code_len;
			if(slo >= shi) continue;
			size_t pad = probe_n > 1 ? probe_n - 1 : 0;
			size_t sa = slo > pad ? slo - pad : 0;
			PCRE2_SIZE off = (PCRE2_SIZE)sa;
			PCRE2_SIZE lim = (PCRE2_SIZE)shi;
			while(off <= lim) {
				int rc = pcre2_match(re, (PCRE2_SPTR)d->code, lim, off, 0, md, NULL);
				if(rc < 0) break;
				PCRE2_SIZE *ov = pcre2_get_ovector_pointer(md);
				if(hit_n + 1 > hit_cap) {
					hit_cap *= 2;
					PCRE2_SIZE *nh = realloc(hits, hit_cap * sizeof(PCRE2_SIZE));
					if(!nh) { free(hits); pcre2_match_data_free(md); pcre2_code_free(re); return LOM_ERR_NOMEM; }
					hits = nh;
				}
				hits[hit_n++] = ov[0];
				PCRE2_SIZE me = ov[1];
				off = (me == ov[0]) ? me + 1 : me;
				if(off == 0) break;
				if(hit_n > (k / 4) && hit_n > 512) {
					hit_n = 0;
					abort_selective = 1;
					break;
				}
			}
		}
		if(hit_n > 0) {
			uint8_t *seen = calloc(d->scan.open_count ? d->scan.open_count : 1, 1);
			if(!seen) { free(hits); pcre2_match_data_free(md); pcre2_code_free(re); return LOM_ERR_NOMEM; }
			for(size_t h = 0; h < hit_n; h++) {
				int64_t pos = (int64_t)hits[h];
				/* rightmost open of this name covering pos */
				size_t best = (size_t)-1;
				if(rows) {
					size_t lo = 0, hi = k;
					while(lo < hi) {
						size_t mid = (lo + hi) / 2;
						if(d->scan.opens[rows[mid]].open_off <= pos) lo = mid + 1;
						else hi = mid;
					}
					for(ssize_t i = (ssize_t)lo - 1; i >= 0; i--) {
						size_t oi = rows[i];
						const lom_open_row *row = &d->scan.opens[oi];
						if(row->open_off > pos) continue;
						if(row->node_end_off < pos) continue;
						best = oi;
						break;
					}
				}
				if(best == (size_t)-1 || seen[best]) continue;
				if(!piece_matches_open(d, best, piece, chain, re, md)) continue;
				seen[best] = 1;
				const lom_open_row *row = &d->scan.opens[best];
				if(!match_push(out, row->open_off, row->node_end_off)) {
					free(seen); free(hits); pcre2_match_data_free(md); pcre2_code_free(re);
					return LOM_ERR_NOMEM;
				}
			}
			free(seen);
			free(hits);
			/* apply [n] */
			if(piece->index > 0 && out->count > 0) {
				size_t pick = (size_t)piece->index - 1;
				if(pick >= out->count) {
					lom_match_list_free(out);
					lom_match_list_init(out);
				} else {
					lom_match keep = out->items[pick];
					lom_match_list_free(out);
					lom_match_list_init(out);
					match_push(out, keep.offset, keep.end_off);
				}
			}
			pcre2_match_data_free(md);
			pcre2_code_free(re);
			return LOM_OK;
		}
		free(hits);
		/* fall through to per-candidate */
	}

	for(size_t i = 0; i < k; i++) {
		size_t oi = rows ? rows[i] : i;
		if(!piece_matches_open(d, oi, piece, chain, re, md)) continue;
		if(chain->count >= 2) {
			int64_t cur = d->scan.opens[oi].open_off;
			bool ok = true;
			for(ssize_t pi = (ssize_t)chain->count - 2; pi >= 0; pi--) {
				size_t cidx = find_open_index(d, cur);
				if(cidx == (size_t)-1) { ok = false; break; }
				int32_t pidx32 = d->scan.opens[cidx].parent_idx;
				if(pidx32 < 0) { ok = false; break; }
				size_t pidx = (size_t)pidx32;
				const sel_piece *pp = &chain->pieces[pi];
				if(!name_eq(d, d->scan.opens[pidx].name_id, pp->name, pp->name_len)) { ok = false; break; }
				if(pp->has_attr && !row_has_attr(d, d->scan.opens[pidx].open_off, pp)) { ok = false; break; }
				if(pp->has_text && !inner_text_eq(d, pidx, pp->text, pp->text_len)) { ok = false; break; }
				cur = d->scan.opens[pidx].open_off;
			}
			if(!ok) continue;
		}
		const lom_open_row *row = &d->scan.opens[oi];
		if(!match_push(out, row->open_off, row->node_end_off)) {
			pcre2_match_data_free(md);
			pcre2_code_free(re);
			return LOM_ERR_NOMEM;
		}
	}
	if(piece->index > 0 && out->count > 0) {
		size_t pick = (size_t)piece->index - 1;
		if(pick >= out->count) {
			lom_match_list_free(out);
			lom_match_list_init(out);
		} else {
			lom_match keep = out->items[pick];
			lom_match_list_free(out);
			lom_match_list_init(out);
			match_push(out, keep.offset, keep.end_off);
		}
	}
	pcre2_match_data_free(md);
	pcre2_code_free(re);
	return LOM_OK;
}

static void collect_named_children(const lom_doc *d, const uint32_t *kids, size_t kn, const sel_piece *piece, size_t **out, size_t *out_n, size_t *out_cap) {
	*out_n = 0;
	for(size_t i = 0; i < kn; i++) {
		size_t oi = kids[i];
		const lom_open_row *row = &d->scan.opens[oi];
		if(piece->name_len == 1 && piece->name[0] == '*') {
			/* ok */
		} else if(!name_eq(d, row->name_id, piece->name, piece->name_len)) {
			continue;
		}
		if(piece->has_attr && !row_has_attr(d, row->open_off, piece)) continue;
		if(piece->has_text && !inner_text_eq(d, oi, piece->text, piece->text_len)) continue;
		if(!grow_cap((void **)out, sizeof(size_t), out_cap, *out_n + 1)) return;
		(*out)[(*out_n)++] = oi;
	}
}

static void doc_child_range(const lom_doc *d, size_t oi, const uint32_t **kids, size_t *kn) {
	if(!d->child_start || !d->child_at) {
		*kids = NULL;
		*kn = 0;
		return;
	}
	uint32_t a = d->child_start[oi];
	uint32_t b = d->child_start[oi + 1];
	*kids = d->child_at + a;
	*kn = (size_t)(b - a);
}

/* When CSR is absent (huge docs), expand children via tag-row ∩ parent filter. */

static lom_status query_chain(lom_doc *d, const sel_chain *chain, lom_match_list *out) {
	lom_match_list_free(out);
	lom_match_list_init(out);
	size_t *cur = NULL, cur_n = 0, cur_cap = 0;
	size_t *nxt = NULL, nxt_n = 0, nxt_cap = 0;

	for(size_t pi = 0; pi < chain->count; pi++) {
		const sel_piece *piece = &chain->pieces[pi];
		nxt_n = 0;
		if(pi == 0) {
			if(piece->index > 0) {
				size_t pick = (size_t)piece->index - 1;
				size_t *picked = NULL; size_t picked_n = 0, picked_cap = 0;
				if(d->child_at && d->child_start) {
					/* Root children + every node's children as separate groups. */
					size_t tmp_n = 0, tmp_cap = 0; size_t *tmp = NULL;
					collect_named_children(d, d->root_children, d->root_child_count, piece, &tmp, &tmp_n, &tmp_cap);
					if(pick < tmp_n) {
						grow_cap((void **)&picked, sizeof(size_t), &picked_cap, 1);
						picked[picked_n++] = tmp[pick];
					}
					free(tmp);
					for(size_t oi = 0; oi < d->scan.open_count; oi++) {
						tmp_n = 0; tmp_cap = 0; tmp = NULL;
						const uint32_t *kids = NULL; size_t kn = 0;
						doc_child_range(d, oi, &kids, &kn);
						collect_named_children(d, kids, kn, piece, &tmp, &tmp_n, &tmp_cap);
						if(pick < tmp_n) {
							grow_cap((void **)&picked, sizeof(size_t), &picked_cap, picked_n + 1);
							picked[picked_n++] = tmp[pick];
						}
						free(tmp);
					}
				} else {
					/* No CSR: sibling groups = runs of tag-rows sharing parent_idx. */
					int32_t nid = find_name_id(d, piece->name, piece->name_len);
					if(nid >= 0) {
						uint32_t *rows = d->tag_rows[nid];
						size_t rc = d->tag_row_counts[nid];
						size_t i = 0;
						while(i < rc) {
							int32_t p = d->scan.opens[rows[i]].parent_idx;
							size_t j = i + 1;
							while(j < rc && d->scan.opens[rows[j]].parent_idx == p) j++;
							if(pick < j - i) {
								size_t oi = rows[i + pick];
								if(!(piece->has_attr && !row_has_attr(d, d->scan.opens[oi].open_off, piece)) &&
								   !(piece->has_text && !inner_text_eq(d, oi, piece->text, piece->text_len))) {
									grow_cap((void **)&picked, sizeof(size_t), &picked_cap, picked_n + 1);
									picked[picked_n++] = oi;
								}
							}
							i = j;
						}
					}
				}
				nxt = picked; nxt_n = picked_n; nxt_cap = picked_cap;
			} else {
				int32_t nid = find_name_id(d, piece->name, piece->name_len);
				if(piece->name_len == 1 && piece->name[0] == '*') {
					for(size_t i = 0; i < d->scan.open_count; i++) {
						if(piece->has_attr && !row_has_attr(d, d->scan.opens[i].open_off, piece)) continue;
						if(piece->has_text && !inner_text_eq(d, i, piece->text, piece->text_len)) continue;
						grow_cap((void **)&nxt, sizeof(size_t), &nxt_cap, nxt_n + 1);
						nxt[nxt_n++] = i;
					}
				} else if(nid < 0) {
					nxt_n = 0;
				} else {
					uint32_t *rows = d->tag_rows[nid];
					size_t rc = d->tag_row_counts[nid];
					for(size_t i = 0; i < rc; i++) {
						size_t oi = rows[i];
						if(piece->has_attr && !row_has_attr(d, d->scan.opens[oi].open_off, piece)) continue;
						if(piece->has_text && !inner_text_eq(d, oi, piece->text, piece->text_len)) continue;
						grow_cap((void **)&nxt, sizeof(size_t), &nxt_cap, nxt_n + 1);
						nxt[nxt_n++] = oi;
					}
				}
			}
		} else {
			if(d->child_at && d->child_start) {
				for(size_t ci = 0; ci < cur_n; ci++) {
					size_t parent_i = cur[ci];
					size_t tmp_n = 0, tmp_cap = 0; size_t *tmp = NULL;
					const uint32_t *kids = NULL; size_t kn = 0;
					doc_child_range(d, parent_i, &kids, &kn);
					collect_named_children(d, kids, kn, piece, &tmp, &tmp_n, &tmp_cap);
					if(piece->index > 0) {
						size_t pick = (size_t)piece->index - 1;
						if(pick < tmp_n) {
							grow_cap((void **)&nxt, sizeof(size_t), &nxt_cap, nxt_n + 1);
							nxt[nxt_n++] = tmp[pick];
						}
					} else {
						for(size_t t = 0; t < tmp_n; t++) {
							grow_cap((void **)&nxt, sizeof(size_t), &nxt_cap, nxt_n + 1);
							nxt[nxt_n++] = tmp[t];
						}
					}
					free(tmp);
				}
			} else {
				/* No CSR: per-parent expand via tag rows (correct [n] within each parent). */
				int32_t nid = find_name_id(d, piece->name, piece->name_len);
				int is_star = (piece->name_len == 1 && piece->name[0] == '*');
				if(nid < 0 && !is_star) {
					/* no matches */
				} else {
					uint32_t *rows = is_star ? NULL : d->tag_rows[nid];
					size_t rc = is_star ? d->scan.open_count : d->tag_row_counts[nid];
					for(size_t ci = 0; ci < cur_n; ci++) {
						size_t parent_i = cur[ci];
						size_t tmp_n = 0, tmp_cap = 0; size_t *tmp = NULL;
						for(size_t i = 0; i < rc; i++) {
							size_t oi = rows ? rows[i] : i;
							if(d->scan.opens[oi].parent_idx != (int32_t)parent_i) continue;
							if(piece->has_attr && !row_has_attr(d, d->scan.opens[oi].open_off, piece)) continue;
							if(piece->has_text && !inner_text_eq(d, oi, piece->text, piece->text_len)) continue;
							if(!grow_cap((void **)&tmp, sizeof(size_t), &tmp_cap, tmp_n + 1)) {
								free(tmp); free(cur); free(nxt); return LOM_ERR_NOMEM;
							}
							tmp[tmp_n++] = oi;
						}
						if(piece->index > 0) {
							size_t pick = (size_t)piece->index - 1;
							if(pick < tmp_n) {
								grow_cap((void **)&nxt, sizeof(size_t), &nxt_cap, nxt_n + 1);
								nxt[nxt_n++] = tmp[pick];
							}
						} else {
							for(size_t t = 0; t < tmp_n; t++) {
								grow_cap((void **)&nxt, sizeof(size_t), &nxt_cap, nxt_n + 1);
								nxt[nxt_n++] = tmp[t];
							}
						}
						free(tmp);
					}
				}
			}
		}
		free(cur);
		cur = nxt; cur_n = nxt_n; cur_cap = nxt_cap;
		nxt = NULL; nxt_n = 0; nxt_cap = 0;
		if(cur_n == 0) break;
	}

	/* Direct-chain without per-step indexing used parent-walk for unindexed multi-piece.
	   When first piece had no index and count>1, first branch used global tag list — that
	   matches PHP simple direct chain when no indices. When indices present, children walk matches indexed path. */

	for(size_t i = 0; i < cur_n; i++) {
		const lom_open_row *row = &d->scan.opens[cur[i]];
		if(!match_push(out, row->open_off, row->node_end_off)) {
			free(cur);
			return LOM_ERR_NOMEM;
		}
	}
	free(cur);
	return LOM_OK;
}

/* Unindexed multi-piece: PHP walks from leaf candidates via parents. Prefer that when no indices. */
static bool chain_has_index(const sel_chain *c) {
	for(size_t i = 0; i < c->count; i++) if(c->pieces[i].index > 0) return true;
	return false;
}

static lom_status query_direct_parent_walk(lom_doc *d, const sel_chain *chain, lom_match_list *out) {
	lom_match_list_free(out);
	lom_match_list_init(out);
	const sel_piece *last = &chain->pieces[chain->count - 1];
	int32_t nid = find_name_id(d, last->name, last->name_len);
	if(nid < 0) return LOM_OK;
	uint32_t *rows = d->tag_rows[nid];
	size_t rc = d->tag_row_counts[nid];
	for(size_t i = 0; i < rc; i++) {
		size_t oi = rows[i];
		const lom_open_row *row = &d->scan.opens[oi];
		if(last->has_attr && !row_has_attr(d, row->open_off, last)) continue;
		if(last->has_text && !inner_text_eq(d, oi, last->text, last->text_len)) continue;
		int64_t cur = row->open_off;
		bool ok = true;
		for(ssize_t pi = (ssize_t)chain->count - 2; pi >= 0; pi--) {
			size_t cidx = find_open_index(d, cur);
			if(cidx == (size_t)-1) { ok = false; break; }
			int32_t pidx32 = d->scan.opens[cidx].parent_idx;
			if(pidx32 < 0) { ok = false; break; }
			size_t pidx = (size_t)pidx32;
			const sel_piece *pp = &chain->pieces[pi];
			if(!name_eq(d, d->scan.opens[pidx].name_id, pp->name, pp->name_len)) {
				ok = false;
				break;
			}
			if(pp->has_attr && !row_has_attr(d, d->scan.opens[pidx].open_off, pp)) { ok = false; break; }
			cur = d->scan.opens[pidx].open_off;
		}
		if(ok) {
			if(!match_push(out, row->open_off, row->node_end_off)) return LOM_ERR_NOMEM;
		}
	}
	return LOM_OK;
}

static lom_status lom_doc_get_uncached(lom_doc *doc, const char *selector, lom_match_list *out) {
	sel_chain chain;
	if(!parse_selector(selector, &chain)) return LOM_ERR_PARSE;

	if(chain.count >= 1 && chain.pieces[chain.count - 1].has_regex) {
		return query_regex_leaf(doc, &chain, out);
	}

	if(chain.count == 1 && chain.pieces[0].has_attr && !chain.pieces[0].has_text) {
		lom_match_list_free(out);
		lom_match_list_init(out);
		const sel_piece *piece = &chain.pieces[0];
		int32_t anid = find_name_id(doc, piece->attr, piece->attr_len);
		int32_t tnid = find_name_id(doc, piece->name, piece->name_len);
		if(tnid < 0 && !(piece->name_len == 1 && piece->name[0] == '*')) return LOM_OK;
		if(anid >= 0 && (size_t)anid < doc->attr_slots && doc->attr_opens && doc->attr_open_counts[anid]) {
			uint32_t *rows = doc->attr_opens[anid];
			size_t rc = doc->attr_open_counts[anid];
			int64_t last_off = -1;
			for(size_t i = 0; i < rc; i++) {
				size_t oi = rows[i];
				const lom_open_row *row = &doc->scan.opens[oi];
				if(row->open_off == last_off) continue;
				last_off = row->open_off;
				if(!(piece->name_len == 1 && piece->name[0] == '*') && !name_eq(doc, row->name_id, piece->name, piece->name_len)) {
					continue;
				}
				if(piece->attr_eq && !row_has_attr(doc, row->open_off, piece)) continue;
				if(!match_push(out, row->open_off, row->node_end_off)) return LOM_ERR_NOMEM;
			}
			return LOM_OK;
		}
		/* Huge-doc / no attr index: filter tag opens by parsing open-tag bytes. */
		return query_chain(doc, &chain, out);
	}
	if(chain.count >= 2 && !chain_has_index(&chain) && !chain.pieces[0].has_attr) {
		bool mid_text = false;
		for(size_t i = 0; i + 1 < chain.count; i++) if(chain.pieces[i].has_text) mid_text = true;
		if(!mid_text && !chain.pieces[chain.count - 1].has_attr) {
			return query_direct_parent_walk(doc, &chain, out);
		}
	}
	if(chain_has_index(&chain)) {
		return query_chain(doc, &chain, out);
	}
	if(chain.count == 1) {
		return query_chain(doc, &chain, out);
	}
	return query_direct_parent_walk(doc, &chain, out);
}

lom_status lom_doc_get(lom_doc *doc, const char *selector, lom_match_list *out) {
	if(!doc || !selector || !out) return LOM_ERR_ARG;
	if(doc->status != LOM_OK) return doc->status;
	lom_status est = doc_ensure_aux(doc);
	if(est != LOM_OK) return est;

	size_t key_len = strlen(selector);
	if(doc->fcache && doc->use_fcache && lom_fcache_roi_ok(doc->fcache, 0.05, 32)) {
		size_t vlen = 0;
		const void *v = lom_fcache_get(doc->fcache, selector, key_len, &vlen);
		if(v && vlen % sizeof(lom_match) == 0) {
			lom_match_list_free(out);
			lom_match_list_init(out);
			size_t n = vlen / sizeof(lom_match);
			const lom_match *ms = (const lom_match *)v;
			for(size_t i = 0; i < n; i++) {
				if(!match_push(out, ms[i].offset, ms[i].end_off)) return LOM_ERR_NOMEM;
			}
			return LOM_OK;
		}
	}

	lom_status st = lom_doc_get_uncached(doc, selector, out);
	if(st == LOM_OK && doc->fcache && doc->use_fcache && out->count > 0 && out->items) {
		(void)lom_fcache_put(doc->fcache, selector, key_len, out->items, out->count * sizeof(lom_match));
	}
	return st;
}

lom_status lom_doc_get_parent(lom_doc *doc, const char *selector, lom_match_list *out) {
	lom_match_list tmp;
	lom_match_list_init(&tmp);
	lom_status st = lom_doc_get(doc, selector, &tmp);
	if(st != LOM_OK) { lom_match_list_free(&tmp); return st; }
	lom_match_list_free(out);
	lom_match_list_init(out);
	/* Open-addressing set of parent offsets — O(n) instead of O(n²) linear scan. */
	size_t nb = 1024;
	while(nb < tmp.count * 2 + 16) nb *= 2;
	int64_t *seen = calloc(nb, sizeof(int64_t));
	uint8_t *used = calloc(nb, 1);
	if(!seen || !used) {
		free(seen);
		free(used);
		lom_match_list_free(&tmp);
		return LOM_ERR_NOMEM;
	}
	for(size_t i = 0; i < tmp.count; i++) {
		size_t idx = find_open_index(doc, tmp.items[i].offset);
		if(idx == (size_t)-1) continue;
		int32_t pidx32 = doc->scan.opens[idx].parent_idx;
		if(pidx32 < 0) continue;
		int64_t parent = doc->scan.opens[pidx32].open_off;
		uint64_t h = (uint64_t)parent;
		size_t slot = (size_t)(h % nb);
		int found = 0;
		for(size_t probe = 0; probe < nb; probe++) {
			size_t j = (slot + probe) % nb;
			if(!used[j]) {
				used[j] = 1;
				seen[j] = parent;
				break;
			}
			if(seen[j] == parent) { found = 1; break; }
		}
		if(found) continue;
		match_push(out, parent, doc->scan.opens[pidx32].node_end_off);
	}
	free(seen);
	free(used);
	lom_match_list_free(&tmp);
	return LOM_OK;
}

static int span_has_lt(const char *p, size_t n) {
	for(size_t i = 0; i < n; i++) if(p[i] == '<') return 1;
	return 0;
}

static void shift_scan_offsets(lom_scan_result *s, int64_t from, int64_t delta) {
	if(delta == 0) return;
	for(size_t i = 0; i < s->open_count; i++) {
		lom_open_row *row = &s->opens[i];
		if(row->open_off >= from) row->open_off += delta;
		if(row->tag_end_off >= from) row->tag_end_off += delta;
		if(row->node_end_off >= from) row->node_end_off += delta;
	}
	for(size_t i = 0; i < s->attr_count; i++) {
		if(s->attrs[i].open_off >= from) s->attrs[i].open_off += delta;
	}
}

/* True when remove/insert cannot change tag structure: no '<' and no open starts in the removed span. */
static int splice_is_structure_preserving(const lom_doc *d, size_t at, size_t remove_len,
	const char *insert, size_t insert_len) {
	if(span_has_lt(insert, insert_len)) return 0;
	if(remove_len && span_has_lt(d->code + at, remove_len)) return 0;
	int64_t lo = (int64_t)at;
	int64_t hi = (int64_t)(at + remove_len);
	for(size_t i = 0; i < d->scan.open_count; i++) {
		int64_t o = d->scan.opens[i].open_off;
		if(o >= lo && o < hi) return 0;
	}
	return 1;
}

static bool scan_string_intern(lom_scan_result *r, const char *s, size_t n, uint32_t *out_id) {
	for(size_t i = 0; i < r->string_count; i++) {
		const char *e = lom_scan_string(r, (uint32_t)i);
		if(strncmp(e, s, n) == 0 && e[n] == '\0') {
			*out_id = (uint32_t)i;
			return true;
		}
	}
	if(!grow_cap((void **)&r->string_offs, sizeof(size_t), &r->string_cap, r->string_count + 1)) return false;
	if(!grow_cap((void **)&r->string_blob, 1, &r->string_blob_cap, r->string_blob_len + n + 1)) return false;
	size_t off = r->string_blob_len;
	memcpy(r->string_blob + off, s, n);
	r->string_blob[off + n] = 0;
	r->string_blob_len = off + n + 1;
	r->string_offs[r->string_count] = off;
	*out_id = (uint32_t)r->string_count;
	r->string_count++;
	return true;
}

/* Complete-subtree remove: every open in [lo,hi) ends before hi; no open is cut mid-node. */
static int splice_remove_is_complete(const lom_doc *d, int64_t lo, int64_t hi) {
	for(size_t i = 0; i < d->scan.open_count; i++) {
		const lom_open_row *row = &d->scan.opens[i];
		if(row->open_off >= lo && row->open_off < hi) {
			if(row->node_end_off >= hi) return 0;
		} else if(row->open_off < lo) {
			if(row->node_end_off >= lo && row->node_end_off < hi) return 0;
			if(row->tag_end_off >= lo && row->tag_end_off < hi) return 0;
		}
	}
	return 1;
}

static void remap_parent_after_remove(lom_scan_result *s, size_t rm_lo, size_t rm_hi) {
	size_t n_rm = rm_hi - rm_lo;
	if(n_rm == 0) return;
	for(size_t i = 0; i < s->open_count; i++) {
		int32_t p = s->opens[i].parent_idx;
		if(p < 0) continue;
		if((size_t)p >= rm_hi) s->opens[i].parent_idx = (int32_t)((size_t)p - n_rm);
		else if((size_t)p >= rm_lo) s->opens[i].parent_idx = -1; /* should not happen for complete remove */
	}
}

static void remap_parent_after_insert(lom_scan_result *s, size_t insert_at, size_t n_new) {
	if(n_new == 0) return;
	for(size_t i = 0; i < s->open_count; i++) {
		int32_t p = s->opens[i].parent_idx;
		if(p >= 0 && (size_t)p >= insert_at) s->opens[i].parent_idx = (int32_t)((size_t)p + n_new);
	}
}

/* Markup splice: drop opens in the removed span, scan only the insert, merge rows, rebuild CSR. */
static lom_status doc_splice_local_structure(lom_doc *d, size_t at, size_t remove_len,
	const char *insert, size_t insert_len) {
	if(!doc_opens_to_heap(d)) return LOM_ERR_NOMEM;
	int64_t lo = (int64_t)at;
	int64_t hi = (int64_t)(at + remove_len);
	if(remove_len && !splice_remove_is_complete(d, lo, hi)) return LOM_ERR_PARSE;

	int32_t parent_of_frag = -1;
	for(size_t i = 0; i < d->scan.open_count; i++) {
		const lom_open_row *row = &d->scan.opens[i];
		if(row->open_off < lo && row->node_end_off >= hi)
			parent_of_frag = (int32_t)i; /* document order ⇒ deepest enclosing */
	}

	size_t rm_lo = 0, rm_hi = 0;
	int found_rm = 0;
	for(size_t i = 0; i < d->scan.open_count; i++) {
		int64_t o = d->scan.opens[i].open_off;
		if(o >= lo && o < hi) {
			if(!found_rm) { rm_lo = i; found_rm = 1; }
			rm_hi = i + 1;
		}
	}
	size_t insert_at;
	if(found_rm) {
		insert_at = rm_lo;
	} else {
		insert_at = d->scan.open_count;
		for(size_t i = 0; i < d->scan.open_count; i++) {
			if(d->scan.opens[i].open_off >= lo) { insert_at = i; break; }
		}
		rm_lo = rm_hi = insert_at;
	}
	size_t n_rm = found_rm ? (rm_hi - rm_lo) : 0;

	lom_scan_result frag;
	lom_scan_result_init(&frag);
	if(insert_len) {
		lom_status st = lom_scan_indexes(insert, insert_len, &frag, true);
		if(st != LOM_OK) {
			lom_scan_result_free(&frag);
			return st;
		}
	}

	if(parent_of_frag >= 0 && found_rm && (size_t)parent_of_frag >= rm_lo && (size_t)parent_of_frag < rm_hi) {
		lom_scan_result_free(&frag);
		return LOM_ERR_PARSE;
	}
	if(parent_of_frag >= 0 && found_rm && (size_t)parent_of_frag >= rm_hi)
		parent_of_frag -= (int32_t)n_rm;

	/* Mutate document bytes (one-pass rewrite on growth; in-place on shrink/capacity). */
	if(!doc_code_splice_bytes(d, at, remove_len, insert, insert_len)) {
		lom_scan_result_free(&frag);
		return LOM_ERR_NOMEM;
	}

	/* Drop removed opens */
	if(n_rm) {
		size_t tail = d->scan.open_count - rm_hi;
		if(tail) memmove(d->scan.opens + rm_lo, d->scan.opens + rm_hi, tail * sizeof(lom_open_row));
		d->scan.open_count -= n_rm;
		remap_parent_after_remove(&d->scan, rm_lo, rm_hi);
		for(size_t i = 0; i < d->scan.attr_count; i++) {
			uint32_t oi = d->scan.attrs[i].open_idx;
			if(oi >= (uint32_t)rm_hi) d->scan.attrs[i].open_idx = oi - (uint32_t)n_rm;
		}
	}

	/* Drop attrs whose open lived in the removed span (old coords) */
	if(remove_len) {
		size_t w = 0;
		for(size_t i = 0; i < d->scan.attr_count; i++) {
			int64_t ao = d->scan.attrs[i].open_off;
			if(ao >= lo && ao < hi) continue;
			d->scan.attrs[w++] = d->scan.attrs[i];
		}
		d->scan.attr_count = w;
	}

	int64_t delta = (int64_t)insert_len - (int64_t)remove_len;
	shift_scan_offsets(&d->scan, (int64_t)at, delta);

	/* Merge fragment opens */
	size_t n_new = frag.open_count;
	if(n_new) {
		if(!grow_cap((void **)&d->scan.opens, sizeof(lom_open_row), &d->scan.open_cap, d->scan.open_count + n_new)) {
			lom_scan_result_free(&frag);
			return LOM_ERR_NOMEM;
		}
		remap_parent_after_insert(&d->scan, insert_at, n_new);
		for(size_t i = 0; i < d->scan.attr_count; i++) {
			if(d->scan.attrs[i].open_idx >= (uint32_t)insert_at)
				d->scan.attrs[i].open_idx += (uint32_t)n_new;
		}
		memmove(d->scan.opens + insert_at + n_new, d->scan.opens + insert_at,
			(d->scan.open_count - insert_at) * sizeof(lom_open_row));
		for(size_t i = 0; i < n_new; i++) {
			lom_open_row row = frag.opens[i];
			row.open_off += (int64_t)at;
			row.tag_end_off += (int64_t)at;
			row.node_end_off += (int64_t)at;
			const char *nm = lom_scan_string(&frag, row.name_id);
			uint32_t nid;
			if(!scan_string_intern(&d->scan, nm, strlen(nm), &nid)) {
				lom_scan_result_free(&frag);
				return LOM_ERR_NOMEM;
			}
			row.name_id = nid;
			if(row.parent_idx < 0) row.parent_idx = parent_of_frag;
			else row.parent_idx = (int32_t)(insert_at + (size_t)row.parent_idx);
			d->scan.opens[insert_at + i] = row;
		}
		d->scan.open_count += n_new;
	}

	if(frag.attr_count) {
		if(!grow_cap((void **)&d->scan.attrs, sizeof(lom_attr_row), &d->scan.attr_cap, d->scan.attr_count + frag.attr_count)) {
			lom_scan_result_free(&frag);
			return LOM_ERR_NOMEM;
		}
		for(size_t i = 0; i < frag.attr_count; i++) {
			lom_attr_row a = frag.attrs[i];
			a.open_off += (int64_t)at;
			a.open_idx = (uint32_t)(insert_at + (size_t)a.open_idx);
			const char *an = lom_scan_string(&frag, a.name_id);
			const char *av = lom_scan_string(&frag, a.value_id);
			uint32_t nid, vid;
			if(!scan_string_intern(&d->scan, an, strlen(an), &nid) ||
			   !scan_string_intern(&d->scan, av, strlen(av), &vid)) {
				lom_scan_result_free(&frag);
				return LOM_ERR_NOMEM;
			}
			a.name_id = nid;
			a.value_id = vid;
			d->scan.attrs[d->scan.attr_count++] = a;
		}
	}
	lom_scan_result_free(&frag);

	/* Defer CSR/tag-row rebuild until the next query — write path stays near memmove cost. */
	d->aux_dirty = 1;
	doc_rebuild_pieces(d);
	if(d->use_fcache) {
		if(d->fcache) lom_fcache_free(d->fcache);
		d->fcache = lom_fcache_create(512);
	}
	d->status = LOM_OK;
	d->error[0] = 0;
	return LOM_OK;
}

static lom_status doc_splice(lom_doc *d, size_t at, size_t remove_len, const char *insert, size_t insert_len) {
	if(!doc_ensure_writable(d)) return LOM_ERR_NOMEM;
	if(at > d->code_len || remove_len > d->code_len - at) return LOM_ERR_ARG;
	doc_mark_dirty_at(d, at);

	if(splice_is_structure_preserving(d, at, remove_len, insert, insert_len)) {
		if(!doc_code_splice_bytes(d, at, remove_len, insert, insert_len)) return LOM_ERR_NOMEM;
		shift_scan_offsets(&d->scan, (int64_t)at, (int64_t)insert_len - (int64_t)remove_len);
		doc_rebuild_pieces(d);
		doc_rebuild_fstr(d);
		d->status = LOM_OK;
		d->error[0] = 0;
		return LOM_OK;
	}

	/* Local structure merge: rescan only the insert; drop complete opens in the remove span. */
	if(!remove_len || splice_remove_is_complete(d, (int64_t)at, (int64_t)(at + remove_len))) {
		lom_status st = doc_splice_local_structure(d, at, remove_len, insert, insert_len);
		if(st == LOM_OK || st == LOM_ERR_NOMEM) return st;
		/* LOM_ERR_PARSE ⇒ fall through to full reindex */
	}

	if(!doc_code_splice_bytes(d, at, remove_len, insert, insert_len)) return LOM_ERR_NOMEM;
	return doc_reindex(d);
}

static bool find_inner_range(const lom_doc *d, size_t open_idx, size_t *inner_start, size_t *inner_len, size_t *close_lt) {
	const lom_open_row *row = &d->scan.opens[open_idx];
	size_t start = (size_t)row->tag_end_off + 1;
	size_t end = (size_t)row->node_end_off;
	size_t cl = end;
	while(cl > start && d->code[cl] != '<') cl--;
	if(cl <= start || d->code[cl] != '<') return false;
	*inner_start = start;
	*inner_len = cl - start;
	*close_lt = cl;
	return true;
}

lom_status lom_doc_set_inner_text(lom_doc *doc, const char *selector, const char *text) {
	if(!doc || !selector || !text) return LOM_ERR_ARG;
	lom_match_list m;
	lom_match_list_init(&m);
	lom_status st = lom_doc_get(doc, selector, &m);
	if(st != LOM_OK) { lom_match_list_free(&m); return st; }
	if(m.count == 0) { lom_match_list_free(&m); return LOM_ERR_NOTFOUND; }
	/* apply from highest offset to lowest */
	for(ssize_t i = (ssize_t)m.count - 1; i >= 0; i--) {
		size_t idx = find_open_index(doc, m.items[i].offset);
		if(idx == (size_t)-1) continue;
		size_t is, il, cl;
		if(!find_inner_range(doc, idx, &is, &il, &cl)) continue;
		st = doc_splice(doc, is, il, text, strlen(text));
		if(st != LOM_OK) { lom_match_list_free(&m); return st; }
		/* after splice, lower offsets still valid; higher already done */
	}
	lom_match_list_free(&m);
	return LOM_OK;
}

lom_status lom_doc_new_before_close(lom_doc *doc, const char *parent_selector, const char *fragment) {
	if(!doc || !parent_selector || !fragment) return LOM_ERR_ARG;
	lom_match_list m;
	lom_match_list_init(&m);
	lom_status st = lom_doc_get(doc, parent_selector, &m);
	if(st != LOM_OK) { lom_match_list_free(&m); return st; }
	if(m.count == 0) { lom_match_list_free(&m); return LOM_ERR_NOTFOUND; }
	size_t frag_len = strlen(fragment);
	for(ssize_t i = (ssize_t)m.count - 1; i >= 0; i--) {
		size_t idx = find_open_index(doc, m.items[i].offset);
		if(idx == (size_t)-1) continue;
		size_t is, il, cl;
		if(!find_inner_range(doc, idx, &is, &il, &cl)) continue;
		st = doc_splice(doc, cl, 0, fragment, frag_len);
		if(st != LOM_OK) { lom_match_list_free(&m); return st; }
	}
	lom_match_list_free(&m);
	return LOM_OK;
}

lom_status lom_doc_delete(lom_doc *doc, const char *selector) {
	if(!doc || !selector) return LOM_ERR_ARG;
	lom_match_list m;
	lom_match_list_init(&m);
	lom_status st = lom_doc_get(doc, selector, &m);
	if(st != LOM_OK) { lom_match_list_free(&m); return st; }
	for(ssize_t i = (ssize_t)m.count - 1; i >= 0; i--) {
		size_t idx = find_open_index(doc, m.items[i].offset);
		if(idx == (size_t)-1) continue;
		const lom_open_row *row = &doc->scan.opens[idx];
		size_t start = (size_t)row->open_off;
		size_t len = (size_t)(row->node_end_off - row->open_off + 1);
		st = doc_splice(doc, start, len, "", 0);
		if(st != LOM_OK) { lom_match_list_free(&m); return st; }
	}
	lom_match_list_free(&m);
	return LOM_OK;
}

lom_status lom_doc_get_attr(const lom_doc *doc, int64_t open_off, const char *attr, char *buf, size_t buflen) {
	if(!doc || !attr || !buf || buflen == 0) return LOM_ERR_ARG;
	buf[0] = 0;
	for(size_t i = 0; i < doc->scan.attr_count; i++) {
		const lom_attr_row *a = &doc->scan.attrs[i];
		if(a->open_off != open_off) continue;
		const char *an = lom_scan_string(&doc->scan, a->name_id);
		if(strcmp(an, attr) != 0) continue;
		const char *av = lom_scan_string(&doc->scan, a->value_id);
		snprintf(buf, buflen, "%s", av);
		return LOM_OK;
	}
	return LOM_ERR_NOTFOUND;
}

lom_status lom_doc_child_text(const lom_doc *doc, int64_t open_off, const char *child_tag, char *buf, size_t buflen) {
	if(!doc || !child_tag || !buf || buflen == 0) return LOM_ERR_ARG;
	buf[0] = 0;
	lom_status est = doc_ensure_aux((lom_doc *)doc);
	if(est != LOM_OK) return est;
	size_t pidx = find_open_index(doc, open_off);
	if(pidx == (size_t)-1) return LOM_ERR_NOTFOUND;
	const uint32_t *kids = NULL; size_t kn = 0;
	doc_child_range(doc, pidx, &kids, &kn);
	for(size_t i = 0; i < kn; i++) {
		size_t ci = kids[i];
		const char *nm = lom_scan_string(&doc->scan, doc->scan.opens[ci].name_id);
		if(strcmp(nm, child_tag) != 0) continue;
		size_t is, il, cl;
		if(!find_inner_range(doc, ci, &is, &il, &cl)) return LOM_ERR_PARSE;
		if(il >= buflen) il = buflen - 1;
		memcpy(buf, doc->code + is, il);
		buf[il] = 0;
		return LOM_OK;
	}
	return LOM_ERR_NOTFOUND;
}

lom_status lom_doc_set_attr(lom_doc *doc, int64_t open_off, const char *attr, const char *value) {
	if(!doc || !attr || !value) return LOM_ERR_ARG;
	size_t idx = find_open_index(doc, open_off);
	if(idx == (size_t)-1) return LOM_ERR_NOTFOUND;
	const lom_open_row *row = &doc->scan.opens[idx];
	size_t start = (size_t)row->open_off;
	size_t end = (size_t)row->tag_end_off;
	size_t tag_len = end - start + 1;
	char *tag = malloc(tag_len + 1);
	if(!tag) return LOM_ERR_NOMEM;
	memcpy(tag, doc->code + start, tag_len);
	tag[tag_len] = 0;
	char pattern[160];
	snprintf(pattern, sizeof(pattern), "%s=\"", attr);
	char *hit = strstr(tag, pattern);
	char *new_tag = NULL;
	if(hit) {
		char *vstart = hit + strlen(pattern);
		char *vend = strchr(vstart, '"');
		if(!vend) { free(tag); return LOM_ERR_PARSE; }
		size_t prefix = (size_t)(vstart - tag);
		size_t suffix = tag_len - (size_t)(vend - tag);
		size_t nlen = prefix + strlen(value) + suffix;
		new_tag = malloc(nlen + 1);
		if(!new_tag) { free(tag); return LOM_ERR_NOMEM; }
		memcpy(new_tag, tag, prefix);
		memcpy(new_tag + prefix, value, strlen(value));
		memcpy(new_tag + prefix + strlen(value), vend, suffix);
		new_tag[nlen] = 0;
	} else {
		size_t ins = tag_len - 1;
		if(ins > 0 && tag[ins - 1] == '/') ins--;
		size_t nlen = tag_len + 1 + strlen(attr) + 2 + strlen(value) + 1;
		new_tag = malloc(nlen + 1);
		if(!new_tag) { free(tag); return LOM_ERR_NOMEM; }
		memcpy(new_tag, tag, ins);
		int w = snprintf(new_tag + ins, nlen - ins + 1, " %s=\"%s\"", attr, value);
		memcpy(new_tag + ins + w, tag + ins, tag_len - ins);
		new_tag[ins + w + (tag_len - ins)] = 0;
	}
	free(tag);
	lom_status st = doc_splice(doc, start, tag_len, new_tag, strlen(new_tag));
	free(new_tag);
	return st;
}

lom_status lom_doc_ensure_ids(lom_doc *doc, const char *row_selector, const char *id_attr) {
	if(!doc || !row_selector || !id_attr) return LOM_ERR_ARG;
	lom_match_list m;
	lom_match_list_init(&m);
	lom_status st = lom_doc_get(doc, row_selector, &m);
	if(st != LOM_OK) { lom_match_list_free(&m); return st; }
	char buf[128];
	for(ssize_t pass = (ssize_t)m.count - 1; pass >= 0; pass--) {
		lom_match_list m2;
		lom_match_list_init(&m2);
		st = lom_doc_get(doc, row_selector, &m2);
		if(st != LOM_OK) { lom_match_list_free(&m2); lom_match_list_free(&m); return st; }
		if((size_t)pass >= m2.count) { lom_match_list_free(&m2); continue; }
		int64_t off = m2.items[pass].offset;
		if(lom_doc_get_attr(doc, off, id_attr, buf, sizeof(buf)) == LOM_OK && buf[0]) {
			lom_match_list_free(&m2);
			continue;
		}
		char idv[64];
		snprintf(idv, sizeof(idv), "lom-%zu", (size_t)pass + 1);
		st = lom_doc_set_attr(doc, off, id_attr, idv);
		lom_match_list_free(&m2);
		if(st != LOM_OK) { lom_match_list_free(&m); return st; }
	}
	lom_match_list_free(&m);
	return LOM_OK;
}

lom_status lom_doc_save_file(const lom_doc *doc, const char *path) {
	if(!doc || !path) return LOM_ERR_ARG;
	char tmp[1024];
	snprintf(tmp, sizeof(tmp), "%s.tmp.%d", path, (int)getpid());
	FILE *f = fopen(tmp, "wb");
	if(!f) return LOM_ERR_PARSE;
	size_t n = fwrite(doc->code, 1, doc->code_len, f);
	fclose(f);
	if(n != doc->code_len) { remove(tmp); return LOM_ERR_PARSE; }
	if(rename(tmp, path) != 0) { remove(tmp); return LOM_ERR_PARSE; }
	return LOM_OK;
}

lom_status lom_doc_delete_offset(lom_doc *doc, int64_t open_off) {
	if(!doc) return LOM_ERR_ARG;
	size_t idx = find_open_index(doc, open_off);
	if(idx == (size_t)-1) return LOM_ERR_NOTFOUND;
	const lom_open_row *row = &doc->scan.opens[idx];
	return doc_splice(doc, (size_t)row->open_off, (size_t)(row->node_end_off - row->open_off + 1), "", 0);
}

lom_status lom_doc_set_inner_text_offset(lom_doc *doc, int64_t open_off, const char *text) {
	if(!doc || !text) return LOM_ERR_ARG;
	size_t idx = find_open_index(doc, open_off);
	if(idx == (size_t)-1) return LOM_ERR_NOTFOUND;
	size_t is, il, cl;
	if(!find_inner_range(doc, idx, &is, &il, &cl)) return LOM_ERR_PARSE;
	return doc_splice(doc, is, il, text, strlen(text));
}

lom_status lom_doc_set_child_text_offset(lom_doc *doc, int64_t open_off, const char *child_tag, const char *text) {
	if(!doc || !child_tag || !text) return LOM_ERR_ARG;
	lom_status est = doc_ensure_aux(doc);
	if(est != LOM_OK) return est;
	size_t pidx = find_open_index(doc, open_off);
	if(pidx == (size_t)-1) return LOM_ERR_NOTFOUND;
	const uint32_t *kids = NULL; size_t kn = 0;
	doc_child_range(doc, pidx, &kids, &kn);
	for(size_t i = 0; i < kn; i++) {
		size_t ci = kids[i];
		const char *nm = lom_scan_string(&doc->scan, doc->scan.opens[ci].name_id);
		if(strcmp(nm, child_tag) != 0) continue;
		return lom_doc_set_inner_text_offset(doc, doc->scan.opens[ci].open_off, text);
	}
	char frag[512];
	snprintf(frag, sizeof(frag), "<%s>%s</%s>", child_tag, text, child_tag);
	size_t is, il, cl;
	if(!find_inner_range(doc, pidx, &is, &il, &cl)) return LOM_ERR_PARSE;
	return doc_splice(doc, cl, 0, frag, strlen(frag));
}
