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
	size_t piece_starts[LOM_MAX_PIECES];
	size_t piece_count;
	uint8_t piece_dirty[LOM_MAX_PIECES];
	int use_fmem;
	int use_fcache;
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
	d->use_pieces = env_flag_on("LOM_PIECES", 1);
	/* Parallel tag-row build duplicates per-thread buffers; default off for RAM. */
	d->use_parallel = env_flag_on("LOM_PARALLEL", 0);
	d->mmap_fd = -1;
	d->code_is_mmap = 0;
	d->piece_count = 0;
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

static bool doc_rebuild_aux(lom_doc *d) {
	doc_clear_aux(d);
	size_t n = d->scan.open_count;
	size_t nstr = d->scan.string_count;
	if(n > UINT32_MAX) return false;
	d->aux_open_count = n;

	/* Only index name_ids that appear on opens — not every interned attr value. */
	uint32_t max_tag_nid = 0;
	for(size_t i = 0; i < n; i++) {
		uint32_t nid = d->scan.opens[i].name_id;
		if(nid > max_tag_nid) max_tag_nid = nid;
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
	}

	/* LOM_PARALLEL atomic shared-count workers were measured slower on 100MB–1GB
	 * (cache-line contention). Fill must stay ordered for sibling [n]. Reserved for
	 * future piece-local scan merge. */
	(void)d->use_parallel;

	uint32_t *counts = calloc(n ? n : 1, sizeof(uint32_t));
	if(!counts) return false;
	size_t root_n = 0;
	for(size_t i = 0; i < n; i++) {
		if(d->scan.opens[i].parent_idx < 0) root_n++;
		else {
			int32_t pi = d->scan.opens[i].parent_idx;
			if(pi >= 0 && (size_t)pi < n) counts[(size_t)pi]++;
		}
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
	d->root_children = root_n ? malloc(root_n * sizeof(uint32_t)) : NULL;
	if(root_n && !d->root_children) { free(counts); return false; }
	d->root_child_count = 0;
	for(size_t i = 0; i < n; i++) {
		int32_t parent = d->scan.opens[i].parent_idx;
		if(parent < 0) {
			d->root_children[d->root_child_count++] = (uint32_t)i;
			continue;
		}
		if((size_t)parent >= n) continue;
		uint32_t slot = d->child_start[(size_t)parent] + counts[(size_t)parent]++;
		d->child_at[slot] = (uint32_t)i;
	}
	free(counts);

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
	char *nb = malloc(d->code_len + 1);
	if(!nb) return false;
	if(d->code_len) memcpy(nb, d->code, d->code_len);
	nb[d->code_len] = 0;
	munmap(d->code, d->code_len);
	if(d->mmap_fd >= 0) {
		close(d->mmap_fd);
		d->mmap_fd = -1;
	}
	d->code = nb;
	d->code_cap = d->code_len + 1;
	d->code_is_mmap = 0;
	return true;
}

static lom_status doc_reindex(lom_doc *d) {
	lom_status st = lom_scan_indexes(d->code, d->code_len, &d->scan, true);
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
	if(doc->code_is_mmap) {
		if(doc->code && doc->code_len) munmap(doc->code, doc->code_len);
		if(doc->mmap_fd >= 0) close(doc->mmap_fd);
	} else {
		free(doc->code);
	}
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
} sel_piece;

typedef struct {
	sel_piece pieces[32];
	size_t count;
} sel_chain;

static bool parse_piece(const char *s, size_t n, sel_piece *p) {
	memset(p, 0, sizeof(*p));
	size_t i = 0;
	while(i < n && (isalnum((unsigned char)s[i]) || s[i] == '_' || s[i] == '-' || s[i] == ':' || s[i] == '*')) {
		if(p->name_len + 1 >= sizeof(p->name)) return false;
		p->name[p->name_len++] = s[i++];
	}
	if(p->name_len == 0) return false;
	if(i < n && s[i] == '[') {
		i++;
		int idx = 0;
		if(i >= n || !isdigit((unsigned char)s[i])) return false;
		while(i < n && isdigit((unsigned char)s[i])) {
			idx = idx * 10 + (s[i] - '0');
			i++;
		}
		if(i >= n || s[i] != ']') return false;
		i++;
		p->index = idx;
	}
	if(i < n && s[i] == '@') {
		i++;
		p->has_attr = true;
		while(i < n && s[i] != '=' && (isalnum((unsigned char)s[i]) || s[i] == '_' || s[i] == '-' || s[i] == ':')) {
			if(p->attr_len + 1 >= sizeof(p->attr)) return false;
			p->attr[p->attr_len++] = s[i++];
		}
		if(p->attr_len == 0) return false;
		if(i < n && s[i] == '=') {
			i++;
			p->attr_eq = true;
			while(i < n) {
				if(p->attr_val_len + 1 >= sizeof(p->attr_val)) return false;
				p->attr_val[p->attr_val_len++] = s[i++];
			}
		}
	} else if(i < n && s[i] == '=') {
		i++;
		p->has_text = true;
		while(i < n) {
			if(p->text_len + 1 >= sizeof(p->text)) return false;
			p->text[p->text_len++] = s[i++];
		}
	}
	if(i != n) return false;
	return true;
}

static bool parse_selector(const char *sel, sel_chain *out) {
	/* Child axis is '_' / '__' (same as PHP). '/' is reserved for regex literals in PHP; native ignores regex. */
	memset(out, 0, sizeof(*out));
	if(!sel || !*sel) return false;
	const char *p = sel;
	while(*p) {
		while(*p == '_') p++; /* skip leading underscores (direct-scope marker) */
		if(!*p) break;
		const char *start = p;
		while(*p && *p != '_') p++;
		size_t n = (size_t)(p - start);
		if(n == 0) continue;
		if(out->count >= 32) return false;
		if(!parse_piece(start, n, &out->pieces[out->count])) return false;
		out->count++;
		/* '__' = descendant (same piece chain for native subset); '_' = child — both advance */
		if(*p == '_' && *(p + 1) == '_') p += 2;
		else if(*p == '_') p++;
	}
	return out->count > 0;
}

static bool row_has_attr(const lom_doc *d, int64_t open_off, const sel_piece *piece) {
	for(size_t i = 0; i < d->scan.attr_count; i++) {
		const lom_attr_row *a = &d->scan.attrs[i];
		if(a->open_off != open_off) continue;
		if(!name_eq(d, a->name_id, piece->attr, piece->attr_len)) continue;
		if(!piece->attr_eq) return true;
		return name_eq(d, a->value_id, piece->attr_val, piece->attr_val_len);
	}
	return false;
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
	uint32_t a = d->child_start[oi];
	uint32_t b = d->child_start[oi + 1];
	*kids = d->child_at ? d->child_at + a : NULL;
	*kn = (size_t)(b - a);
}

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
				/* PHP: for each parent group including all root+nested groups with that tag index — 
				   actually for index on first piece without walking parents: pick from each children list.
				   Match PHP: iterate all parent_children_index entries. */
				/* Root children + every node's children as separate groups. */
				collect_named_children(d, d->root_children, d->root_child_count, piece, &nxt, &nxt_n, &nxt_cap);
				size_t pick = (size_t)piece->index - 1;
				size_t *picked = NULL; size_t picked_n = 0, picked_cap = 0;
				if(pick < nxt_n) {
					grow_cap((void **)&picked, sizeof(size_t), &picked_cap, 1);
					picked[picked_n++] = nxt[pick];
				}
				/* also other parent groups in document order */
				for(size_t oi = 0; oi < d->scan.open_count; oi++) {
					size_t tmp_n = 0, tmp_cap = 0; size_t *tmp = NULL;
					const uint32_t *kids = NULL; size_t kn = 0;
					doc_child_range(d, oi, &kids, &kn);
					collect_named_children(d, kids, kn, piece, &tmp, &tmp_n, &tmp_cap);
					if(pick < tmp_n) {
						grow_cap((void **)&picked, sizeof(size_t), &picked_cap, picked_n + 1);
						picked[picked_n++] = tmp[pick];
					}
					free(tmp);
				}
				free(nxt);
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

	if(chain.count == 1 && chain.pieces[0].has_attr && !chain.pieces[0].has_text) {
		lom_match_list_free(out);
		lom_match_list_init(out);
		const sel_piece *piece = &chain.pieces[0];
		int32_t anid = find_name_id(doc, piece->attr, piece->attr_len);
		int32_t tnid = find_name_id(doc, piece->name, piece->name_len);
		if(anid < 0 || (tnid < 0 && !(piece->name_len == 1 && piece->name[0] == '*'))) return LOM_OK;
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

	/* Mutate document bytes */
	size_t new_len = d->code_len - remove_len + insert_len;
	if(new_len + 1 > d->code_cap) {
		size_t ncap = d->code_cap ? d->code_cap : 64;
		while(ncap < new_len + 1) ncap *= 2;
		char *nb = realloc(d->code, ncap);
		if(!nb) { lom_scan_result_free(&frag); return LOM_ERR_NOMEM; }
		d->code = nb;
		d->code_cap = ncap;
	}
	memmove(d->code + at + insert_len, d->code + at + remove_len, d->code_len - (at + remove_len));
	if(insert_len) memcpy(d->code + at, insert, insert_len);
	d->code_len = new_len;
	d->code[d->code_len] = 0;

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
		size_t new_len = d->code_len - remove_len + insert_len;
		if(new_len + 1 > d->code_cap) {
			size_t ncap = d->code_cap ? d->code_cap : 64;
			while(ncap < new_len + 1) ncap *= 2;
			char *nb = realloc(d->code, ncap);
			if(!nb) return LOM_ERR_NOMEM;
			d->code = nb;
			d->code_cap = ncap;
		}
		memmove(d->code + at + insert_len, d->code + at + remove_len, d->code_len - (at + remove_len));
		if(insert_len) memcpy(d->code + at, insert, insert_len);
		d->code_len = new_len;
		d->code[d->code_len] = 0;
		shift_scan_offsets(&d->scan, (int64_t)at, (int64_t)insert_len - (int64_t)remove_len);
		doc_rebuild_pieces(d);
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

	size_t new_len = d->code_len - remove_len + insert_len;
	if(new_len + 1 > d->code_cap) {
		size_t ncap = d->code_cap ? d->code_cap : 64;
		while(ncap < new_len + 1) ncap *= 2;
		char *nb = realloc(d->code, ncap);
		if(!nb) return LOM_ERR_NOMEM;
		d->code = nb;
		d->code_cap = ncap;
	}
	memmove(d->code + at + insert_len, d->code + at + remove_len, d->code_len - (at + remove_len));
	if(insert_len) memcpy(d->code + at, insert, insert_len);
	d->code_len = new_len;
	d->code[d->code_len] = 0;
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
