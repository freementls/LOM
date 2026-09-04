#include "lom.h"
#include "lom_fss.h"

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

/* Selector regex ReDoS / abuse caps (PCRE2 match context). */
#ifndef LOM_REGEX_MATCH_LIMIT
#define LOM_REGEX_MATCH_LIMIT 100000u
#endif
#ifndef LOM_REGEX_DEPTH_LIMIT
#define LOM_REGEX_DEPTH_LIMIT 1000u
#endif
#ifndef LOM_REGEX_PATTERN_MAX
#define LOM_REGEX_PATTERN_MAX 512
#endif
/* Max tagless subject length for one regex compare (avoids huge heap copies). */
#ifndef LOM_TAGVALUE_REGEX_MAX
#define LOM_TAGVALUE_REGEX_MAX (256u * 1024u)
#endif

struct lom_doc {
	char *code;
	size_t code_len;
	size_t code_cap;
	int mmap_fd;
	int code_is_mmap; /* 1 => code from mmap; do not free() as malloc */
	/* Growth overlay: logical = base[0,ov_at) + ov_ins + base[ov_at+ov_remove, base_len).
	 * Avoids rewriting multi-GB mmap for tiny set/new_. code[] stays the base mapping. */
	int code_overlay;
	size_t code_base_len;
	size_t ov_at;
	size_t ov_remove;
	char *ov_ins;
	size_t ov_ins_len;
	size_t ov_ins_cap;
	/* Deferred open-offset shift (avoids faulting the whole open table on tiny edits). */
	int64_t off_bias_from;
	int64_t off_bias_delta;
	/* Deferred parent_idx shift after in-place open inserts (skip O(#opens) remap).
	 * Stored parent_idx on non-ABS rows is pre-bias; ABS rows store logical parents. */
	size_t idx_bias_from;
	size_t idx_bias_delta;
	/* New opens not yet merged into tag_rows (avoids O(#tag entries) remap on new_). */
	uint32_t *tag_pend_name;
	uint32_t *tag_pend_oi;
	size_t tag_pend_n;
	size_t tag_pend_cap;
	/* opens[0, open_sorted_n) are document-ordered; [open_sorted_n, open_count) are
	 * out-of-order appends from early new_ (avoids multi-GB memmove). */
	size_t open_sorted_n;
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
	/* Fractal string: enabled for lazy ensure; construct build only with LOM_FSTR=eager. */
	d->use_fstr = env_flag_on("LOM_FSTR", 1);
	d->use_pieces = env_flag_on("LOM_PIECES", 1);
	/* Parallel tag-row build duplicates per-thread buffers; default off for RAM. */
	d->use_parallel = env_flag_on("LOM_PARALLEL", 0);
	d->mmap_fd = -1;
	d->code_is_mmap = 0;
	d->code_overlay = 0;
	d->ov_ins = NULL;
	d->ov_ins_len = 0;
	d->ov_ins_cap = 0;
	d->ov_at = 0;
	d->ov_remove = 0;
	d->code_base_len = 0;
	d->off_bias_from = 0;
	d->off_bias_delta = 0;
	d->idx_bias_from = 0;
	d->idx_bias_delta = 0;
	d->tag_pend_name = NULL;
	d->tag_pend_oi = NULL;
	d->tag_pend_n = 0;
	d->tag_pend_cap = 0;
	d->open_sorted_n = 0;
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
	free(d->tag_pend_name);
	free(d->tag_pend_oi);
	d->tag_pend_name = NULL;
	d->tag_pend_oi = NULL;
	d->tag_pend_n = d->tag_pend_cap = 0;
}

static void doc_invalidate_fstr(lom_doc *d) {
	if(d->fstr) {
		lom_fstr_free(d->fstr);
		d->fstr = NULL;
	}
}

/* Fractal-string signatures help cold substring/regex prune, but eager build costs
 * ~1 s on 100 MB. Default: lazy (build on first ensure). LOM_FSTR=0 disables;
 * LOM_FSTR=eager builds during construct/reindex. */
static void doc_ensure_fstr(lom_doc *d) {
	if(d->fstr || !d->use_fstr || !d->code || d->code_len < 4096) return;
	if(d->code_overlay) return;
	if(d->code_len >= (size_t)256 * 1024 * 1024) return;
	d->fstr = lom_fstr_build(
		d->code, d->code_len,
		d->scan.opens, d->scan.open_count,
		d->root_children, d->root_child_count,
		d->child_at, d->child_start,
		0
	);
}

static int fstr_eager(void) {
	const char *e = getenv("LOM_FSTR");
	if(!e || !*e) return 0;
	return (e[0] == 'e' || e[0] == 'E') ||
	       (strcmp(e, "2") == 0) ||
	       (strcmp(e, "eager") == 0);
}

static void doc_rebuild_fstr(lom_doc *d) {
	doc_invalidate_fstr(d);
	if(fstr_eager()) doc_ensure_fstr(d);
}

static void doc_rebuild_pieces(lom_doc *d) {
	d->piece_count = 0;
	if(!d->use_pieces || !d->code || d->code_len == 0 || d->code_overlay) {
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
	/* Invalidate memoized selectors; keep the table (fcache) for re-fill — avoids churn. */
	if(d->fcache) lom_fcache_clear(d->fcache);
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

static void shift_scan_offsets(lom_scan_result *s, int64_t from, int64_t delta);

static bool match_push(lom_match_list *m, int64_t off, int64_t end) {
	if(!grow_cap((void **)&m->items, sizeof(lom_match), &m->cap, m->count + 1)) return false;
	m->items[m->count].offset = off;
	m->items[m->count].end_off = end;
	m->count++;
	return true;
}

/* Pre-sized emit — skips grow_cap checks on the hot single-tag / merge paths. */
static bool match_push_fast(lom_match_list *m, int64_t off, int64_t end) {
	if(m->count >= m->cap) return match_push(m, off, end);
	m->items[m->count].offset = off;
	m->items[m->count].end_off = end;
	m->count++;
	return true;
}

static int64_t row_open_off(const lom_doc *d, const lom_open_row *r) {
	int64_t o = r->open_off;
	if(r->self_closing & LOM_OPEN_ABS) return o;
	if(d->off_bias_delta && o >= d->off_bias_from) o += d->off_bias_delta;
	return o;
}

static int64_t row_tag_end(const lom_doc *d, const lom_open_row *r) {
	int64_t raw = r->open_off + (int64_t)r->tag_end_rel;
	if(r->self_closing & LOM_OPEN_ABS) return raw;
	/* Edit inside the open tag: absolute tag_end shifts with byte bias. */
	if(d->off_bias_delta && raw >= d->off_bias_from) return raw + d->off_bias_delta;
	return raw;
}

static int64_t row_node_end(const lom_doc *d, const lom_open_row *r) {
	int64_t rel;
	if(r->self_closing & LOM_OPEN_NE_OVF) {
		uint32_t i = r->node_end_rel;
		if(i >= d->scan.ne_ovf_n) rel = 0;
		else rel = d->scan.ne_ovf_rel[i];
	} else {
		rel = (int64_t)r->node_end_rel;
	}
	int64_t raw = r->open_off + rel;
	if(r->self_closing & LOM_OPEN_ABS) return raw;
	/* Ancestors keep open_off before bias_from but their end crosses the edit —
	 * absolute ends still take the deferred byte bias (same as pre-pack24). */
	if(d->off_bias_delta && raw >= d->off_bias_from) return raw + d->off_bias_delta;
	return raw;
}

static int row_is_dead(const lom_open_row *r) {
	return r && (r->self_closing & LOM_OPEN_DEAD) != 0;
}

static int row_is_sc(const lom_open_row *r) {
	return r && (r->self_closing & LOM_OPEN_SC) != 0;
}

static int32_t row_parent(const lom_doc *d, const lom_open_row *r) {
	int32_t p = r->parent_idx;
	if(p < 0) return -1;
	if(r->self_closing & LOM_OPEN_ABS) return p;
	if(d->idx_bias_delta && (size_t)p >= d->idx_bias_from)
		return (int32_t)((size_t)p + d->idx_bias_delta);
	return p;
}

static size_t decode_open_idx(const lom_doc *d, uint32_t stored) {
	size_t oi = stored;
	if(d->idx_bias_delta && oi >= d->idx_bias_from)
		oi += d->idx_bias_delta;
	return oi;
}

/* Materialize deferred parent_idx bias (may fault open pages). */
static void doc_flush_idx_bias(lom_doc *d) {
	size_t from = d->idx_bias_from;
	size_t delta = d->idx_bias_delta;
	d->idx_bias_from = 0;
	d->idx_bias_delta = 0;
	if(delta) {
		for(size_t i = 0; i < d->scan.open_count; i++) {
			lom_open_row *r = &d->scan.opens[i];
			if(r->self_closing & LOM_OPEN_ABS) continue;
			int32_t p = r->parent_idx;
			if(p >= 0 && (size_t)p >= from)
				r->parent_idx = (int32_t)((size_t)p + delta);
		}
		if(d->tag_rows && d->tag_row_counts) {
			for(size_t nid = 0; nid < d->tag_row_slots; nid++) {
				uint32_t *rows = d->tag_rows[nid];
				uint32_t rc = d->tag_row_counts[nid];
				if(!rows) continue;
				for(uint32_t i = 0; i < rc; i++) {
					if(rows[i] >= (uint32_t)from)
						rows[i] = (uint32_t)(rows[i] + delta);
				}
			}
		}
	}
	/* Merge pending logical open indices into tag_rows. */
	for(size_t i = 0; i < d->tag_pend_n; i++) {
		uint32_t nid = d->tag_pend_name[i];
		uint32_t oi = d->tag_pend_oi[i];
		if(nid >= d->tag_row_slots) {
			size_t nslots = (size_t)nid + 1;
			uint32_t **nrows = calloc(nslots, sizeof(uint32_t *));
			uint32_t *ncounts = calloc(nslots, sizeof(uint32_t));
			if(!nrows || !ncounts) { free(nrows); free(ncounts); break; }
			memcpy(nrows, d->tag_rows, d->tag_row_slots * sizeof(uint32_t *));
			memcpy(ncounts, d->tag_row_counts, d->tag_row_slots * sizeof(uint32_t));
			free(d->tag_rows);
			free(d->tag_row_counts);
			d->tag_rows = nrows;
			d->tag_row_counts = ncounts;
			d->tag_row_slots = nslots;
		}
		uint32_t rc = d->tag_row_counts[nid];
		uint32_t *rows = realloc(d->tag_rows[nid], (rc + 1) * sizeof(uint32_t));
		if(!rows) break;
		d->tag_rows[nid] = rows;
		uint32_t pos = rc;
		while(pos > 0 && rows[pos - 1] > oi) {
			rows[pos] = rows[pos - 1];
			pos--;
		}
		rows[pos] = oi;
		d->tag_row_counts[nid] = rc + 1;
	}
	d->tag_pend_n = 0;
}

/* Materialize deferred open-offset bias into the open table (may fault pages). */
static void doc_flush_off_bias(lom_doc *d) {
	if(!d->off_bias_delta) return;
	doc_flush_idx_bias(d);
	int64_t from = d->off_bias_from;
	int64_t delta = d->off_bias_delta;
	d->off_bias_from = 0;
	d->off_bias_delta = 0;
	shift_scan_offsets(&d->scan, from, delta);
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
	d->tag_pend_n = 0;

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

/* Binary search on sorted opening offsets (scan.opens[0, open_sorted_n) is in document order). */
static size_t opens_sorted_count(const lom_doc *d) {
	if(d->open_sorted_n && d->open_sorted_n <= d->scan.open_count)
		return d->open_sorted_n;
	return d->scan.open_count;
}

static size_t find_open_index(const lom_doc *d, int64_t open_off) {
	size_t sorted_n = opens_sorted_count(d);
	size_t lo = 0, hi = sorted_n;
	while(lo < hi) {
		size_t mid = lo + (hi - lo) / 2;
		int64_t v = row_open_off(d, &d->scan.opens[mid]);
		if(v < open_off) lo = mid + 1;
		else if(v > open_off) hi = mid;
		else {
			if(!row_is_dead(&d->scan.opens[mid])) return mid;
			for(size_t j = mid; j > 0; j--) {
				if(row_open_off(d, &d->scan.opens[j - 1]) != open_off) break;
				if(!row_is_dead(&d->scan.opens[j - 1])) return j - 1;
			}
			for(size_t j = mid + 1; j < sorted_n; j++) {
				if(row_open_off(d, &d->scan.opens[j]) != open_off) break;
				if(!row_is_dead(&d->scan.opens[j])) return j;
			}
			return (size_t)-1;
		}
	}
	/* Out-of-order early inserts appended at the end. */
	for(size_t i = sorted_n; i < d->scan.open_count; i++) {
		if(row_is_dead(&d->scan.opens[i])) continue;
		if(row_open_off(d, &d->scan.opens[i]) == open_off) return i;
	}
	return (size_t)-1;
}

/* madvise/msync require page-aligned addr+len; row offsets usually are not.
 * do_sync also drops file page cache (post-write); scan eviction uses madvise only. */
static void opens_drop_rows(lom_scan_result *r, size_t row_lo, size_t row_hi, int do_sync) {
	/* Anonymous DONTNEED zeroes content — only safe on file-backed maps. */
	if(!r || !r->opens_is_mmap || r->opens_fd < 0 || !r->opens || row_lo >= row_hi) return;
	size_t ps = (size_t)sysconf(_SC_PAGESIZE);
	if(ps < 4096) ps = 4096;
	size_t map_bytes = r->opens_map_bytes ? r->opens_map_bytes
		: r->open_cap * sizeof(lom_open_row);
	size_t from = row_lo * sizeof(lom_open_row);
	size_t to = row_hi * sizeof(lom_open_row);
	if(to > map_bytes) to = map_bytes;
	size_t a0 = (from / ps) * ps;
	size_t a1 = ((to + ps - 1) / ps) * ps;
	if(a1 > map_bytes) a1 = map_bytes;
	if(a1 <= a0) return;
	char *base = (char *)r->opens;
	if(do_sync) (void)msync(base + a0, a1 - a0, MS_SYNC);
	(void)madvise(base + a0, a1 - a0, MADV_DONTNEED);
	if(do_sync && r->opens_fd >= 0)
		(void)posix_fadvise(r->opens_fd, (off_t)a0, (off_t)(a1 - a0), POSIX_FADV_DONTNEED);
}

/* During long open-table scans, optionally drop consumed pages (RSS vs latency).
 * Default off: merge/parent walks stay cache-friendly. Set LOM_SCAN_DROP=1, or
 * auto-on when open_count >= 100M (20GB-class tables). */
static int scan_drop_enabled(const lom_scan_result *r) {
	static int cached = -1;
	if(cached < 0) {
		const char *e = getenv("LOM_SCAN_DROP");
		if(e && (*e == '0' || *e == 'n' || *e == 'N' || *e == 'f' || *e == 'F')) cached = 0;
		else if(e && *e) cached = 1;
		else cached = 2; /* auto */
	}
	if(cached == 0) return 0;
	if(cached == 1) return 1;
	return r && r->open_count >= (size_t)100000000;
}

static void opens_scan_drop_behind(lom_scan_result *r, size_t *last_drop, size_t oi) {
	if(!r || !r->opens_is_mmap || r->opens_fd < 0 || !scan_drop_enabled(r)) return;
	if(oi < *last_drop + (size_t)65536) return;
	size_t drop_hi = oi > (size_t)65536 ? oi - (size_t)65536 : 0;
	if(drop_hi > *last_drop) {
		opens_drop_rows(r, *last_drop, drop_hi, 0);
		*last_drop = drop_hi;
	}
}

static bool doc_rebuild_aux(lom_doc *d) {
	doc_clear_aux(d);
	size_t n = d->scan.open_count;
	size_t nstr = d->scan.string_count;
	if(n > UINT32_MAX) return false;
	d->aux_open_count = n;
	/* Full CSR is ~8 bytes/open; keep for modest docs, skip on GB-class tables.
	 * Hot query paths (tag-row merge / parent_idx) do not need CSR. */
	int build_csr = env_flag_on("LOM_CSR", n < (size_t)8000000);

	/* Count-then-allocate tag rows (one realloc per name, no geometric grow). */
	uint32_t max_nid = 0;
	for(size_t i = 0; i < n; i++) {
		uint32_t nid = d->scan.opens[i].name_id;
		if(nid > max_nid) max_nid = nid;
	}
	size_t tag_slots = n ? ((size_t)max_nid + 1) : 0;
	if(nstr && tag_slots > nstr) tag_slots = nstr;
	d->tag_rows = NULL;
	d->tag_row_counts = NULL;
	d->tag_row_slots = 0;
	d->root_children = NULL;
	d->root_child_count = 0;

	if(tag_slots) {
		d->tag_rows = calloc(tag_slots, sizeof(uint32_t *));
		d->tag_row_counts = calloc(tag_slots, sizeof(uint32_t));
		if(!d->tag_rows || !d->tag_row_counts) return false;
		d->tag_row_slots = tag_slots;
		for(size_t i = 0; i < n; i++) {
			uint32_t nid = d->scan.opens[i].name_id;
			if((size_t)nid >= tag_slots) continue;
			d->tag_row_counts[nid]++;
		}
		for(size_t nid = 0; nid < tag_slots; nid++) {
			uint32_t c = d->tag_row_counts[nid];
			if(!c) continue;
			d->tag_rows[nid] = malloc((size_t)c * sizeof(uint32_t));
			if(!d->tag_rows[nid]) return false;
			d->tag_row_counts[nid] = 0; /* reuse as write cursor */
		}
	}

	size_t root_cap = 0;
	for(size_t i = 0; i < n; i++) {
		const lom_open_row *row = &d->scan.opens[i];
		uint32_t nid = row->name_id;
		if((size_t)nid < tag_slots && d->tag_rows[nid]) {
			d->tag_rows[nid][d->tag_row_counts[nid]++] = (uint32_t)i;
		}
		if(row_parent(d, row) < 0) {
			if(d->root_child_count >= root_cap) {
				size_t ncap = root_cap ? root_cap * 2 : 8;
				uint32_t *r = realloc(d->root_children, ncap * sizeof(uint32_t));
				if(!r) return false;
				d->root_children = r;
				root_cap = ncap;
			}
			d->root_children[d->root_child_count++] = (uint32_t)i;
		}
	}

	/* LOM_PARALLEL is honored in lom_scan_indexes (piece-local sibling scans +
	 * string-table merge). Shared-atomic CSR fill was tried earlier and lost;
	 * piece-local is opt-in + hardware-gated; on this host ~27% faster at 1 GB. */
	(void)d->use_parallel;

	if(build_csr) {
		uint32_t *counts = calloc(n ? n : 1, sizeof(uint32_t));
		if(!counts) return false;
		for(size_t i = 0; i < n; i++) {
			int32_t pi = row_parent(d, &d->scan.opens[i]);
			if(pi >= 0 && (size_t)pi < n) counts[(size_t)pi]++;
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
			int32_t parent = row_parent(d, &d->scan.opens[i]);
			if(parent < 0) continue;
			if((size_t)parent >= n) continue;
			uint32_t slot = d->child_start[(size_t)parent] + counts[(size_t)parent]++;
			d->child_at[slot] = (uint32_t)i;
		}
		free(counts);
	}

	/* Optional: spill anon → file then drop (LOM_OPEN_SPILL=1). Default anon
	 * keeps RAM-resident opens for speed. File-backed default drops here. */
	if(d->scan.opens_is_mmap && d->scan.opens_fd < 0) {
		const char *sp = getenv("LOM_OPEN_SPILL");
		if(sp && sp[0] && sp[0] != '0' && sp[0] != 'n' && sp[0] != 'N')
			(void)lom_scan_opens_spill_to_file(&d->scan);
	}
	if(d->scan.opens_is_mmap && d->scan.opens_fd >= 0 && d->scan.opens && d->scan.open_count) {
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
	if(d->ov_ins) {
		free(d->ov_ins);
		d->ov_ins = NULL;
		d->ov_ins_len = d->ov_ins_cap = 0;
		d->code_overlay = 0;
	}
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

static char doc_byte(const lom_doc *d, size_t i) {
	if(!d->code_overlay) return d->code[i];
	if(i < d->ov_at) return d->code[i];
	if(i < d->ov_at + d->ov_ins_len) return d->ov_ins[i - d->ov_at];
	return d->code[i - d->ov_ins_len + d->ov_remove];
}

/* Contiguous view of [off, off+len). Uses scratch when the range crosses the overlay seam. */
static const char *doc_span(const lom_doc *d, size_t off, size_t len, char *scratch, size_t scap) {
	if(len == 0) return "";
	if(!d->code_overlay) {
		if(off + len > d->code_len) return NULL;
		return d->code + off;
	}
	size_t end = off + len;
	if(end > d->code_len) return NULL;
	size_t mid0 = d->ov_at;
	size_t mid1 = d->ov_at + d->ov_ins_len;
	if(end <= mid0) return d->code + off;
	if(off >= mid1) return d->code + (off - d->ov_ins_len + d->ov_remove);
	if(off >= mid0 && end <= mid1) return d->ov_ins + (off - mid0);
	if(!scratch || scap < len) return NULL;
	for(size_t i = 0; i < len; i++) scratch[i] = doc_byte(d, off + i);
	return scratch;
}

static int doc_memcmp_at(const lom_doc *d, size_t off, const char *p, size_t n) {
	char scratch[4096];
	if(n <= sizeof(scratch)) {
		const char *s = doc_span(d, off, n, scratch, sizeof(scratch));
		if(!s) return 1;
		return memcmp(s, p, n);
	}
	for(size_t i = 0; i < n; i++) {
		unsigned char a = (unsigned char)doc_byte(d, off + i);
		unsigned char b = (unsigned char)p[i];
		if(a != b) return (int)a - (int)b;
	}
	return 0;
}

#ifndef LOM_OVERLAY_MIN
#define LOM_OVERLAY_MIN ((size_t)16 * 1024 * 1024)
#endif
#ifndef LOM_OVERLAY_WINDOW
#define LOM_OVERLAY_WINDOW ((size_t)2 * 1024 * 1024)
#endif

static void doc_overlay_choose_window(const lom_doc *d, size_t at, size_t remove_len,
	size_t *w0, size_t *w1) {
	size_t blen = d->code_overlay ? d->code_base_len : d->code_len;
	/* Fixed pad — keeps nearby set+new_ inside one heap window without scanning opens. */
	size_t lo = at > LOM_OVERLAY_WINDOW / 2 ? at - LOM_OVERLAY_WINDOW / 2 : 0;
	size_t hi = at + remove_len + LOM_OVERLAY_WINDOW / 2;
	if(hi > blen) hi = blen;
	*w0 = lo;
	*w1 = hi;
}

static bool doc_overlay_flatten(lom_doc *d);

static bool doc_overlay_apply(lom_doc *d, size_t at, size_t remove_len,
	const char *insert, size_t insert_len) {
	if(d->code_overlay) {
		size_t mid0 = d->ov_at;
		size_t mid1 = d->ov_at + d->ov_ins_len;
		if(at >= mid0 && at + remove_len <= mid1) {
			size_t loc = at - mid0;
			size_t new_len = d->ov_ins_len - remove_len + insert_len;
			if(new_len + 1 > d->ov_ins_cap) {
				size_t ncap = d->ov_ins_cap ? d->ov_ins_cap : 64;
				while(ncap < new_len + 1) ncap *= 2;
				char *nb = realloc(d->ov_ins, ncap);
				if(!nb) return false;
				d->ov_ins = nb;
				d->ov_ins_cap = ncap;
			}
			memmove(d->ov_ins + loc + insert_len, d->ov_ins + loc + remove_len,
				d->ov_ins_len - loc - remove_len);
			if(insert_len) memcpy(d->ov_ins + loc, insert, insert_len);
			d->ov_ins_len = new_len;
			d->code_len = d->code_base_len - d->ov_remove + d->ov_ins_len;
			return true;
		}
		/* Distant second edit: flatten once, then continue without overlay. */
		if(!doc_overlay_flatten(d)) return false;
		return false; /* signal caller to use normal path */
	}

	size_t w0, w1;
	doc_overlay_choose_window(d, at, remove_len, &w0, &w1);
	size_t wlen = w1 - w0;
	size_t new_wlen = wlen - remove_len + insert_len;
	size_t cap = new_wlen + 4096;
	char *buf = malloc(cap);
	if(!buf) return false;
	if(wlen) memcpy(buf, d->code + w0, wlen);
	size_t loc = at - w0;
	memmove(buf + loc + insert_len, buf + loc + remove_len, wlen - loc - remove_len);
	if(insert_len) memcpy(buf + loc, insert, insert_len);

	d->code_base_len = d->code_len;
	d->code_overlay = 1;
	d->ov_at = w0;
	d->ov_remove = wlen;
	d->ov_ins = buf;
	d->ov_ins_len = new_wlen;
	d->ov_ins_cap = cap;
	d->code_len = d->code_base_len - remove_len + insert_len;
	return true;
}

static bool doc_overlay_flatten(lom_doc *d) {
	/* Logical code layout is unchanged by flatten — keep deferred open/idx biases. */
	if(!d->code_overlay) return true;
	size_t need = d->code_len + 1;
	int prefer_file = need >= LOM_CODE_FILE_THRESHOLD;
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
	/* Assemble without thrashing: copy left, insert, right — no DONTNEED on live base. */
	if(d->ov_at) memcpy(nb, d->code, d->ov_at);
	if(d->ov_ins_len) memcpy(nb + d->ov_at, d->ov_ins, d->ov_ins_len);
	size_t right = d->code_base_len - (d->ov_at + d->ov_remove);
	if(right) memcpy(nb + d->ov_at + d->ov_ins_len, d->code + d->ov_at + d->ov_remove, right);
	nb[d->code_len] = 0;

	free(d->ov_ins);
	d->ov_ins = NULL;
	d->ov_ins_len = d->ov_ins_cap = 0;
	d->code_overlay = 0;
	d->ov_at = d->ov_remove = 0;

	if(d->code_is_mmap) {
		munmap(d->code, doc_code_map_bytes(d));
		if(d->mmap_fd >= 0) close(d->mmap_fd);
	} else {
		free(d->code);
	}
	d->code = nb;
	d->code_cap = need;
	d->code_is_mmap = is_mmap;
	d->mmap_fd = nfd;
	d->code_base_len = 0;
	return true;
}

/* Copy src→dst in chunks. Do NOT DONTNEED source mid-copy — that thrashs the working set. */
static void copy_bytes_chunked(char *dst, const char *src, size_t n, int src_is_mmap) {
	(void)src_is_mmap;
	const size_t CH = (size_t)8 * 1024 * 1024;
	size_t off = 0;
	while(off < n) {
		size_t m = n - off;
		if(m > CH) m = CH;
		memcpy(dst + off, src + off, m);
		off += m;
	}
}

/* One-pass prefix|insert|suffix into a new buffer (tempfile mmap when large).
 * Avoids the old growth path of full promote memcpy + second full memmove. */
static bool doc_code_rewrite_splice(lom_doc *d, size_t at, size_t remove_len,
	const char *insert, size_t insert_len) {
	if(d->code_overlay && !doc_overlay_flatten(d)) return false;
	if(d->off_bias_delta) doc_flush_off_bias(d);
	size_t new_len = d->code_len - remove_len + insert_len;
	size_t need = new_len + 1;
	int prefer_file = new_len >= LOM_CODE_FILE_THRESHOLD;

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

static int splice_wants_overlay(const lom_doc *d, size_t at, size_t remove_len, size_t insert_len) {
	(void)at;
	(void)remove_len;
	if(insert_len > (size_t)4 * 1024 * 1024) return 0;
	if(getenv("LOM_OVERLAY") && getenv("LOM_OVERLAY")[0] == '0') return 0;
	if(d->code_overlay) return 1;
	size_t new_len = d->code_len - remove_len + insert_len;
	if(new_len <= d->code_len) return 0; /* shrink/same: in-place */
	if(d->code_is_mmap) return 1;
	if(d->code_len >= LOM_OVERLAY_MIN) return 1;
	return 0;
}

/* Apply splice to document bytes: overlay (no full copy), in-place, or one-pass rewrite. */
static bool doc_code_splice_bytes(lom_doc *d, size_t at, size_t remove_len,
	const char *insert, size_t insert_len) {
	if(splice_wants_overlay(d, at, remove_len, insert_len)) {
		if(doc_overlay_apply(d, at, remove_len, insert, insert_len)) return true;
		/* fell through after flatten of incompatible second edit */
	}
	size_t new_len = d->code_len - remove_len + insert_len;
	int can_inplace = 0;
	if(!d->code_is_mmap && !d->code_overlay && new_len + 1 <= d->code_cap) can_inplace = 1;
	if(d->code_is_mmap && !d->code_overlay && new_len <= d->code_len) can_inplace = 1;

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
	if(d->code_overlay && !doc_overlay_flatten(d)) return LOM_ERR_NOMEM;
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
	d->open_sorted_n = d->scan.open_count;
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

/* After a local open-table splice: remap tag rows in place and drop CSR.
 * Avoids O(#opens) aux rebuild (the post-write RSS cliff on GB fixtures). */
/* Tombstone aux: drop CSR + pending only. Leave tag_rows pointing at DEAD opens —
 * readers skip row_is_dead. Compacting every tag row faults multi-GB indexes on 20GB. */
static bool doc_aux_patch_tombstones(lom_doc *d, size_t rm_lo, size_t rm_hi) {
	if(!d->tag_rows || !d->tag_row_counts || d->aux_dirty) return false;
	free(d->child_at);
	d->child_at = NULL;
	free(d->child_start);
	d->child_start = NULL;
	free(d->root_children);
	d->root_children = NULL;
	d->root_child_count = 0;

	/* Pending opens are logical indices — drop those in the tombstone span. */
	{
		size_t w = 0;
		for(size_t i = 0; i < d->tag_pend_n; i++) {
			uint32_t oi = d->tag_pend_oi[i];
			if(oi >= (uint32_t)rm_lo && oi < (uint32_t)rm_hi) continue;
			d->tag_pend_name[w] = d->tag_pend_name[i];
			d->tag_pend_oi[w] = oi;
			w++;
		}
		d->tag_pend_n = w;
	}
	d->aux_dirty = 0;
	return true;
}

static bool doc_aux_patch_open_splice(lom_doc *d, size_t insert_at, size_t n_rm,
	size_t n_new, const lom_open_row *new_rows) {
	if(!d->tag_rows || !d->tag_row_counts || d->aux_dirty) return false;
	size_t expect_old = d->scan.open_count + n_rm - n_new;
	if(d->aux_open_count != expect_old) return false;

	free(d->child_at);
	d->child_at = NULL;
	free(d->child_start);
	d->child_start = NULL;
	free(d->root_children);
	d->root_children = NULL;
	d->root_child_count = 0;

	/* Pure insert without shifting tag_rows: idx-bias hole or out-of-order append. */
	if(n_rm == 0 && n_new && new_rows &&
	   ((d->idx_bias_delta && d->idx_bias_from == insert_at) ||
	    (opens_sorted_count(d) < d->scan.open_count))) {
		size_t need = d->tag_pend_n + n_new;
		if(need > d->tag_pend_cap) {
			size_t ncap = d->tag_pend_cap ? d->tag_pend_cap * 2 : 8;
			while(ncap < need) ncap *= 2;
			uint32_t *nn = realloc(d->tag_pend_name, ncap * sizeof(uint32_t));
			uint32_t *no = realloc(d->tag_pend_oi, ncap * sizeof(uint32_t));
			if(!nn || !no) {
				if(nn && nn != d->tag_pend_name) d->tag_pend_name = nn;
				if(no && no != d->tag_pend_oi) d->tag_pend_oi = no;
				return false;
			}
			d->tag_pend_name = nn;
			d->tag_pend_oi = no;
			d->tag_pend_cap = ncap;
		}
		size_t oi0 = (opens_sorted_count(d) < d->scan.open_count)
			? (d->scan.open_count - n_new)
			: insert_at;
		for(size_t i = 0; i < n_new; i++) {
			d->tag_pend_name[d->tag_pend_n] = new_rows[i].name_id;
			d->tag_pend_oi[d->tag_pend_n] = (uint32_t)(oi0 + i);
			d->tag_pend_n++;
		}
		d->aux_open_count = d->scan.open_count;
		d->aux_dirty = 0;
		return true;
	}

	int64_t shift = (int64_t)n_new - (int64_t)n_rm;
	for(size_t nid = 0; nid < d->tag_row_slots; nid++) {
		uint32_t *rows = d->tag_rows[nid];
		uint32_t rc = d->tag_row_counts[nid];
		if(!rows || rc == 0) continue;
		uint32_t w = 0;
		for(uint32_t i = 0; i < rc; i++) {
			uint32_t oi = rows[i];
			if(n_rm && oi >= (uint32_t)insert_at && oi < (uint32_t)(insert_at + n_rm))
				continue;
			if(oi >= (uint32_t)(insert_at + n_rm))
				oi = (uint32_t)((int64_t)oi + shift);
			rows[w++] = oi;
		}
		d->tag_row_counts[nid] = w;
	}

	for(size_t i = 0; i < n_new; i++) {
		uint32_t nid = new_rows[i].name_id;
		uint32_t oi = (uint32_t)(insert_at + i);
		if(nid >= d->tag_row_slots) {
			size_t nslots = (size_t)nid + 1;
			uint32_t **nrows = calloc(nslots, sizeof(uint32_t *));
			uint32_t *ncounts = calloc(nslots, sizeof(uint32_t));
			if(!nrows || !ncounts) { free(nrows); free(ncounts); return false; }
			memcpy(nrows, d->tag_rows, d->tag_row_slots * sizeof(uint32_t *));
			memcpy(ncounts, d->tag_row_counts, d->tag_row_slots * sizeof(uint32_t));
			free(d->tag_rows);
			free(d->tag_row_counts);
			d->tag_rows = nrows;
			d->tag_row_counts = ncounts;
			d->tag_row_slots = nslots;
		}
		uint32_t rc = d->tag_row_counts[nid];
		uint32_t *rows = realloc(d->tag_rows[nid], (rc + 1) * sizeof(uint32_t));
		if(!rows) return false;
		d->tag_rows[nid] = rows;
		/* Insert oi in ascending open-index order. */
		uint32_t pos = rc;
		while(pos > 0 && rows[pos - 1] > oi) {
			rows[pos] = rows[pos - 1];
			pos--;
		}
		rows[pos] = oi;
		d->tag_row_counts[nid] = rc + 1;
	}

	/* Attr open index: cheap rebuild from attr rows (no open-table scan). */
	if(d->attr_opens) {
		for(size_t i = 0; i < d->attr_slots; i++) free(d->attr_opens[i]);
		free(d->attr_opens);
		free(d->attr_open_counts);
		d->attr_opens = NULL;
		d->attr_open_counts = NULL;
		d->attr_slots = 0;
	}
	if(d->scan.attr_count) {
		uint32_t max_an = 0;
		for(size_t i = 0; i < d->scan.attr_count; i++) {
			if(d->scan.attrs[i].name_id > max_an) max_an = d->scan.attrs[i].name_id;
		}
		d->attr_slots = (size_t)max_an + 1;
		d->attr_opens = calloc(d->attr_slots, sizeof(uint32_t *));
		d->attr_open_counts = calloc(d->attr_slots, sizeof(uint32_t));
		if(!d->attr_opens || !d->attr_open_counts) return false;
		for(size_t i = 0; i < d->scan.attr_count; i++) {
			uint32_t an = d->scan.attrs[i].name_id;
			if(an < d->attr_slots) d->attr_open_counts[an]++;
		}
		for(size_t an = 0; an < d->attr_slots; an++) {
			if(!d->attr_open_counts[an]) continue;
			d->attr_opens[an] = malloc(d->attr_open_counts[an] * sizeof(uint32_t));
			if(!d->attr_opens[an]) return false;
			d->attr_open_counts[an] = 0;
		}
		for(size_t i = 0; i < d->scan.attr_count; i++) {
			uint32_t an = d->scan.attrs[i].name_id;
			uint32_t oi = d->scan.attrs[i].open_idx;
			if(an >= d->attr_slots) continue;
			d->attr_opens[an][d->attr_open_counts[an]++] = oi;
		}
	}

	d->aux_open_count = d->scan.open_count;
	d->aux_dirty = 0;
	return true;
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
	lom_doc *d = (lom_doc *)doc;
	if(d->code_overlay && !doc_overlay_flatten(d)) return NULL;
	if(out_len) *out_len = d->code_len;
	return d->code;
}

size_t lom_doc_open_count(const lom_doc *doc) {
	return doc ? doc->scan.open_count : 0;
}

bool lom_doc_brackets_balanced(const lom_doc *doc) {
	if(!doc) return false;
	size_t lt = 0, gt = 0;
	for(size_t i = 0; i < doc->code_len; i++) {
		char c = doc_byte(doc, i);
		if(c == '<') lt++;
		else if(c == '>') gt++;
	}
	return lt == gt;
}

lom_status lom_doc_node_slice(const lom_doc *doc, int64_t open_off, const char **ptr, size_t *len) {
	if(!doc || !ptr || !len) return LOM_ERR_ARG;
	size_t idx = find_open_index(doc, open_off);
	if(idx == (size_t)-1) return LOM_ERR_NOTFOUND;
	int64_t end = row_node_end(doc, &doc->scan.opens[idx]);
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
	bool descendant; /* true => reached via __ from previous piece (not _) */
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
	int first = 1;
	while(*p) {
		int desc = 0;
		if(!first) {
			if(p[0] == '_' && p[1] == '_') { desc = 1; p += 2; }
			else if(p[0] == '_') { p += 1; }
			else break;
		}
		first = 0;
		while(*p == '_') p++; /* ignore stray underscores */
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
		out->pieces[out->count].descendant = desc != 0;
		out->count++;
	}
	return out->count > 0;
}

static bool tag_span_has_attr(const char *tag, size_t tlen, const sel_piece *piece) {
	if(!tag || tlen < 3 || piece->attr_len == 0) return false;
	/* Presence-only: one probe for ` attr=` (fixture tags always space-separated). */
	if(!piece->attr_eq && piece->attr_len + 2 < 120) {
		char probe[128];
		probe[0] = ' ';
		memcpy(probe + 1, piece->attr, piece->attr_len);
		probe[1 + piece->attr_len] = '=';
		/* Tiny open tags: libc memmem — lom_fss overhead dominates here. */
		if(memmem(tag, tlen, probe, piece->attr_len + 2)) return true;
		return false;
	}
	/* Jump via memmem on the attr name; verify whitespace before and '=' after. */
	const char *base = tag + 1;
	size_t left = tlen > 1 ? tlen - 1 : 0;
	while(left >= piece->attr_len) {
		const void *hit = lom_fss_memmem(base, left, piece->attr, piece->attr_len);
		if(!hit) return false;
		size_t i = (size_t)((const char *)hit - tag);
		unsigned char prev = (unsigned char)tag[i - 1];
		if(!(prev == ' ' || prev == '\t' || prev == '\n' || prev == '\r' || prev == '<')) {
			base = (const char *)hit + 1;
			left = tlen - (size_t)(base - tag);
			continue;
		}
		size_t j = i + piece->attr_len;
		while(j < tlen && (tag[j] == ' ' || tag[j] == '\t')) j++;
		if(j >= tlen || tag[j] != '=') {
			base = (const char *)hit + 1;
			left = tlen - (size_t)(base - tag);
			continue;
		}
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
		base = (const char *)hit + 1;
		left = tlen - (size_t)(base - tag);
	}
	return false;
}

static bool row_has_attr_oi(const lom_doc *d, size_t oi, const sel_piece *piece) {
	if(!piece || piece->attr_len == 0 || oi >= d->scan.open_count) return false;
	const lom_open_row *row = &d->scan.opens[oi];
	if(row_is_dead(row)) return false;
	int64_t open_off = row_open_off(d, row);
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
	int64_t tend = row_tag_end(d, row);
	if(tend < open_off) return false;
	size_t tlen = (size_t)(tend - open_off + 1);
	if(open_off < 0 || (size_t)open_off + tlen > d->code_len) return false;
	char scratch[8192];
	const char *span = doc_span(d, (size_t)open_off, tlen, scratch, sizeof(scratch));
	if(!span) {
		char *big = malloc(tlen);
		if(!big) return false;
		span = doc_span(d, (size_t)open_off, tlen, big, tlen);
		int ok = span && tag_span_has_attr(span, tlen, piece);
		free(big);
		return ok;
	}
	return tag_span_has_attr(span, tlen, piece);
}

/* Raw bytes between open-tag end and close-tag start (may include nested markup). */
static bool raw_inner_bounds(const lom_doc *d, size_t open_idx, size_t *start_out, size_t *len_out) {
	const lom_open_row *row = &d->scan.opens[open_idx];
	if(row_is_sc(row) || row_is_dead(row)) {
		*start_out = 0;
		*len_out = 0;
		return true;
	}
	int64_t start = row_tag_end(d, row) + 1;
	int64_t end = row_node_end(d, row);
	int64_t close_lt = end;
	while(close_lt > start && doc_byte(d, (size_t)close_lt) != '<') close_lt--;
	if(close_lt <= start || doc_byte(d, (size_t)close_lt) != '<') {
		*start_out = 0;
		*len_out = 0;
		return true;
	}
	*start_out = (size_t)start;
	*len_out = (size_t)(close_lt - start);
	return true;
}

/* Tagvalue subject: character data only (markup stripped). Prevents regex/literal
 * compares from matching nested tags / attribute structure inside an element. */
static bool tagvalue_text_eq(const lom_doc *d, size_t open_idx, const char *text, size_t tlen) {
	size_t start = 0, raw_len = 0;
	if(!raw_inner_bounds(d, open_idx, &start, &raw_len)) return false;
	size_t ti = 0;
	size_t p = 0;
	while(p < raw_len) {
		char c = doc_byte(d, start + p);
		if(c == '<') {
			while(p < raw_len && doc_byte(d, start + p) != '>') p++;
			if(p < raw_len) p++;
			continue;
		}
		if(ti >= tlen || text[ti] != c) return false;
		ti++;
		p++;
	}
	return ti == tlen;
}

/* Build tagless text into *heap (caller frees) or point at contiguous leaf span. */
static bool tagvalue_text_span(const lom_doc *d, size_t open_idx, const char **out, size_t *out_len, char **heap) {
	*heap = NULL;
	*out = "";
	*out_len = 0;
	size_t start = 0, raw_len = 0;
	if(!raw_inner_bounds(d, open_idx, &start, &raw_len)) return false;
	if(raw_len == 0) return true;

	bool has_lt = false;
	for(size_t i = 0; i < raw_len; i++) {
		if(doc_byte(d, start + i) == '<') { has_lt = true; break; }
	}
	if(!has_lt) {
		if(!d->code_overlay) {
			*out = d->code + start;
			*out_len = raw_len;
			return true;
		}
		if((size_t)start >= d->ov_at && start + raw_len <= d->ov_at + d->ov_ins_len) {
			*out = d->ov_ins + (start - d->ov_at);
			*out_len = raw_len;
			return true;
		}
		if(start + raw_len <= d->ov_at) {
			*out = d->code + start;
			*out_len = raw_len;
			return true;
		}
		if(start >= d->ov_at + d->ov_ins_len) {
			*out = d->code + (start - d->ov_ins_len + d->ov_remove);
			*out_len = raw_len;
			return true;
		}
		/* Crosses overlay seam — copy. */
	}

	size_t cap = raw_len < LOM_TAGVALUE_REGEX_MAX ? raw_len : LOM_TAGVALUE_REGEX_MAX;
	char *buf = malloc(cap ? cap : 1);
	if(!buf) return false;
	size_t n = 0;
	for(size_t p = 0; p < raw_len && n < cap; p++) {
		char c = doc_byte(d, start + p);
		if(c == '<') {
			while(p < raw_len && doc_byte(d, start + p) != '>') p++;
			/* for-loop will p++ past '>' when present */
			continue;
		}
		buf[n++] = c;
	}
	*heap = buf;
	*out = buf;
	*out_len = n;
	return true;
}

static bool inner_text_eq(const lom_doc *d, size_t open_idx, const char *text, size_t tlen) {
	return tagvalue_text_eq(d, open_idx, text, tlen);
}

static pcre2_match_context *sel_regex_match_ctx(void) {
	pcre2_match_context *ctx = pcre2_match_context_create(NULL);
	if(!ctx) return NULL;
	(void)pcre2_set_match_limit(ctx, LOM_REGEX_MATCH_LIMIT);
	(void)pcre2_set_depth_limit(ctx, LOM_REGEX_DEPTH_LIMIT);
	return ctx;
}

static pcre2_code *compile_sel_regex(const sel_regex_slot *slot) {
	if(slot->pattern_len == 0 || slot->pattern_len > LOM_REGEX_PATTERN_MAX) return NULL;
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

static bool regex_keeps(int rop, pcre2_code *re, pcre2_match_data *md, pcre2_match_context *mctx,
	const char *text, size_t tlen) {
	if(!re || !md) return false;
	if(rop == LOM_ROP_PCT || rop == LOM_ROP_NE) {
		int rc = pcre2_match(re, (PCRE2_SPTR)text, tlen, 0, 0, md, mctx);
		bool hit = (rc >= 0);
		return rop == LOM_ROP_NE ? !hit : hit;
	}
	PCRE2_SIZE off = 0;
	while(off <= tlen) {
		int rc = pcre2_match(re, (PCRE2_SPTR)text, tlen, off, 0, md, mctx);
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
	pcre2_code *re, pcre2_match_data *md, pcre2_match_context *mctx);
static bool ancestor_chain_ok_oi(const lom_doc *d, const sel_chain *chain, size_t leaf_oi, const int32_t *nids);
static void chain_resolve_nids(const lom_doc *d, const sel_chain *chain, int32_t *nids);

static bool piece_matches_open(const lom_doc *d, size_t oi, const sel_piece *piece, const sel_chain *chain,
	pcre2_code *re, pcre2_match_data *md, pcre2_match_context *mctx) {
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
			return regex_keeps(rop, re, md, mctx, val, vlen);
		}
		const char *text = NULL; size_t tlen = 0; char *heap = NULL;
		if(!tagvalue_text_span(d, oi, &text, &tlen, &heap)) return false;
		bool keep = regex_keeps(rop, re, md, mctx, text, tlen);
		free(heap);
		return keep;
	}
	if(piece->has_attr && !row_has_attr_oi(d, oi, piece)) return false;
	if(piece->has_text && !inner_text_eq(d, oi, piece->text, piece->text_len)) return false;
	return true;
}

/* Among doc-ordered tag-rows for nid: advance row cursor to covering open (amortised O(1)). */
static size_t tag_row_covering_hit_adv(const lom_doc *d, int32_t nid, int64_t hit_off, size_t *row_cursor) {
	if(nid < 0 || (size_t)nid >= d->tag_row_slots || !d->tag_rows[nid]) return (size_t)-1;
	uint32_t *rows = d->tag_rows[nid];
	size_t rc = d->tag_row_counts[nid];
	size_t c = *row_cursor;
	if(c >= rc) c = 0;
	while(c + 1 < rc) {
		size_t oi_n = decode_open_idx(d, rows[c + 1]);
		if(row_open_off(d, &d->scan.opens[oi_n]) <= hit_off) c++;
		else break;
	}
	*row_cursor = c;
	if(rc == 0) return (size_t)-1;
	size_t oi = decode_open_idx(d, rows[c]);
	const lom_open_row *row = &d->scan.opens[oi];
	if(row_is_dead(row)) return (size_t)-1;
	if(row_open_off(d, row) <= hit_off && hit_off <= row_node_end(d, row)) return oi;
	return (size_t)-1;
}

/* Huge docs skip fstr (≥256MB). Use document literal hits → covering tag opens, then
 * verify on tagvalue/attr only (never trust markup matches alone). */
static int pattern_exact_literal(const sel_regex_slot *slot, char *lit, size_t lit_cap, size_t *lit_n) {
	const char *p = slot->pattern;
	size_t n = slot->pattern_len;
	if(n >= 2 && p[0] == '^' && p[n - 1] == '$') {
		p++;
		n -= 2;
	} else if(slot->rop != LOM_ROP_EQ) {
		return 0;
	}
	if(n == 0 || n >= lit_cap) return 0;
	for(size_t i = 0; i < n; i++) {
		unsigned char c = (unsigned char)p[i];
		if(c == '\\' || c == '.' || c == '*' || c == '+' || c == '?' || c == '[' || c == '(' ||
		   c == ')' || c == '{' || c == '|' || c == '^' || c == '$')
			return 0;
	}
	memcpy(lit, p, n);
	lit[n] = 0;
	*lit_n = n;
	return 1;
}

static int query_regex_via_literal_hits(lom_doc *d, const sel_chain *chain, const sel_piece *piece,
	pcre2_code *re, pcre2_match_data *md, pcre2_match_context *mctx, lom_match_list *out) {
	if(d->code_overlay || !d->code || d->code_len == 0) return 0;
	if(piece->name_len == 1 && piece->name[0] == '*') return 0;
	const sel_regex_slot *slot = &chain->regexes[piece->regex_id];
	if(slot->rop == LOM_ROP_NE) return 0; /* need full scan to find non-matches */

	char exact[128];
	size_t exact_n = 0;
	uint8_t probe_buf[160];
	size_t pn = 0;
	const char *probe = NULL;
	int used_bracketed = 0;
	/* Exact leaf text (^lit$ / =): search ">lit<" so Entity_1 does not hit Entity_10. */
	if(pattern_exact_literal(slot, exact, sizeof(exact), &exact_n) && exact_n + 2 <= sizeof(probe_buf)) {
		probe_buf[0] = '>';
		memcpy(probe_buf + 1, exact, exact_n);
		probe_buf[1 + exact_n] = '<';
		pn = exact_n + 2;
		probe = (const char *)probe_buf;
		used_bracketed = 1;
	} else {
		/* Contains / prefix / … : bare literal probe only (bracketed would under-match). */
		pn = lom_fstr_regex_probe(slot->pattern, slot->pattern_len, probe_buf, sizeof(probe_buf));
		if(pn < 4) return 0;
		probe = (const char *)probe_buf;
	}

	int32_t nid = find_name_id(d, piece->name, piece->name_len);
	if(nid < 0) return 1; /* handled; no tags */

	size_t last_oi = (size_t)-1;
	const char *hay = d->code;
	int32_t anids[32];
	int have_anids = 0;
	if(chain->count >= 2 && chain->count <= 32) {
		chain_resolve_nids(d, chain, anids);
		have_anids = 1;
	}
	size_t hay_n = d->code_len;
	size_t pos = 0;
	size_t row_cursor = 0;
	size_t rc_hint = ((size_t)nid < d->tag_row_slots) ? d->tag_row_counts[nid] : 0;
	if(rc_hint > 1024 &&
	   !grow_cap((void **)&out->items, sizeof(lom_match), &out->cap, rc_hint / 4 + 64))
		return -1;
	while(pos + pn <= hay_n) {
		const void *hit = lom_fss_memmem(hay + pos, hay_n - pos, probe, pn);
		if(!hit) break;
		size_t hoff = (size_t)((const char *)hit - hay);
		/* For ">lit<" hits, point inside the text (skip '>'). */
		int64_t cover_off = (int64_t)(used_bracketed ? hoff + 1 : hoff);
		size_t oi = tag_row_covering_hit_adv(d, nid, cover_off, &row_cursor);
		pos = hoff + 1;
		if(oi == (size_t)-1 || oi == last_oi) continue;
		last_oi = oi;
		if(row_is_dead(&d->scan.opens[oi])) continue;
		if(!piece_matches_open(d, oi, piece, chain, re, md, mctx)) continue;
		if(chain->count >= 2 && !ancestor_chain_ok_oi(d, chain, oi, have_anids ? anids : NULL)) continue;
		const lom_open_row *row = &d->scan.opens[oi];
		if(!match_push(out, row_open_off(d, row), row_node_end(d, row))) return -1;
	}
	/* Pending appends: cheap to check fully. */
	for(size_t pi = 0; pi < d->tag_pend_n; pi++) {
		if(d->tag_pend_name[pi] != (uint32_t)nid) continue;
		size_t oi = d->tag_pend_oi[pi];
		if(oi == last_oi) continue;
		if(!piece_matches_open(d, oi, piece, chain, re, md, mctx)) continue;
		if(chain->count >= 2 && !ancestor_chain_ok_oi(d, chain, oi, have_anids ? anids : NULL)) continue;
		const lom_open_row *row = &d->scan.opens[oi];
		if(!match_push(out, row_open_off(d, row), row_node_end(d, row))) return -1;
	}
	return 1;
}

static lom_status query_regex_leaf(lom_doc *d, const sel_chain *chain, lom_match_list *out) {
	/* Keep growth overlay: tagvalue_text_span / doc_byte handle seams. */
	lom_match_list_free(out);
	lom_match_list_init(out);
	const sel_piece *piece = &chain->pieces[chain->count - 1];
	if(!piece->has_regex || piece->regex_id < 0) return LOM_ERR_PARSE;
	const sel_regex_slot *slot = &chain->regexes[piece->regex_id];
	pcre2_code *re = compile_sel_regex(slot);
	if(!re) return LOM_ERR_PARSE;
	pcre2_match_data *md = pcre2_match_data_create_from_pattern(re, NULL);
	if(!md) { pcre2_code_free(re); return LOM_ERR_NOMEM; }
	pcre2_match_context *mctx = sel_regex_match_ctx();
	if(!mctx) {
		pcre2_match_data_free(md);
		pcre2_code_free(re);
		return LOM_ERR_NOMEM;
	}

	int lit = query_regex_via_literal_hits(d, chain, piece, re, md, mctx, out);
	if(lit < 0) {
		pcre2_match_context_free(mctx);
		pcre2_match_data_free(md);
		pcre2_code_free(re);
		return LOM_ERR_NOMEM;
	}
	if(lit > 0) {
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
		pcre2_match_context_free(mctx);
		pcre2_match_data_free(md);
		pcre2_code_free(re);
		return LOM_OK;
	}

	int32_t nid = find_name_id(d, piece->name, piece->name_len);
	if(nid < 0 && !(piece->name_len == 1 && piece->name[0] == '*')) {
		pcre2_match_context_free(mctx);
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

	int32_t anids[32];
	int have_anids = 0;
	if(chain->count >= 2 && chain->count <= 32) {
		chain_resolve_nids(d, chain, anids);
		have_anids = 1;
	}

	/* Per-candidate only: never run selector regex against raw document markup.
	 * Tagvalue compares use tagless text; attrs use attribute values. */
	for(size_t i = 0; i < k; i++) {
		size_t oi = rows ? decode_open_idx(d, rows[i]) : i;
		if(row_is_dead(&d->scan.opens[oi])) continue;
		if(!piece_matches_open(d, oi, piece, chain, re, md, mctx)) continue;
		if(chain->count >= 2) {
			if(!ancestor_chain_ok_oi(d, chain, oi, have_anids ? anids : NULL)) continue;
		}
		const lom_open_row *row = &d->scan.opens[oi];
		if(!match_push(out, row_open_off(d, row), row_node_end(d, row))) {
			pcre2_match_context_free(mctx);
			pcre2_match_data_free(md);
			pcre2_code_free(re);
			return LOM_ERR_NOMEM;
		}
	}
	if(rows && nid >= 0) {
		for(size_t pi = 0; pi < d->tag_pend_n; pi++) {
			if(d->tag_pend_name[pi] != (uint32_t)nid) continue;
			size_t oi = d->tag_pend_oi[pi];
			if(!piece_matches_open(d, oi, piece, chain, re, md, mctx)) continue;
			if(chain->count >= 2 && !ancestor_chain_ok_oi(d, chain, oi, have_anids ? anids : NULL)) continue;
			const lom_open_row *row = &d->scan.opens[oi];
			if(!match_push(out, row_open_off(d, row), row_node_end(d, row))) {
				pcre2_match_context_free(mctx);
				pcre2_match_data_free(md);
				pcre2_code_free(re);
				return LOM_ERR_NOMEM;
			}
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
	pcre2_match_context_free(mctx);
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
		if(piece->has_attr && !row_has_attr_oi(d, oi, piece)) continue;
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

/* Descendants: matching opens in the parent's byte span (any depth).
 * Prefer leaf tag-rows (binary search + forward scan) so a large region does not
 * fault every intervening open; fall back to open-table walk for '*'. */
static void collect_named_descendants_span(lom_doc *d, size_t parent_i, const sel_piece *piece,
	size_t **out, size_t *out_n, size_t *out_cap) {
	*out_n = 0;
	size_t sorted_n = opens_sorted_count(d);
	if(parent_i >= d->scan.open_count) return;
	const lom_open_row *prow = &d->scan.opens[parent_i];
	int64_t pend = row_node_end(d, prow);
	int64_t poff = row_open_off(d, prow);
	size_t want_n = 0;
	int stop_early = 0;
	if(piece->index > 0) {
		want_n = (size_t)piece->index;
		stop_early = 1;
	}

	if(!(piece->name_len == 1 && piece->name[0] == '*')) {
		int32_t nid = find_name_id(d, piece->name, piece->name_len);
		if(nid >= 0 && (size_t)nid < d->tag_row_slots && d->tag_rows[nid]) {
			uint32_t *rows = d->tag_rows[nid];
			size_t rc = d->tag_row_counts[nid];
			/* First candidate with open_idx > parent_i (doc-order tag rows). */
			size_t lo = 0, hi = rc;
			while(lo < hi) {
				size_t mid = lo + ((hi - lo) >> 1);
				size_t oi = decode_open_idx(d, rows[mid]);
				if(oi <= parent_i) lo = mid + 1;
				else hi = mid;
			}
			size_t last_drop = parent_i + 1;
			for(size_t i = lo; i < rc; i++) {
				size_t oi = decode_open_idx(d, rows[i]);
				const lom_open_row *row = &d->scan.opens[oi];
				if(row_is_dead(row)) continue;
				int64_t ooff = row_open_off(d, row);
				if(ooff > pend) break;
				if(ooff <= poff) continue;
				if(piece->has_attr && !row_has_attr_oi(d, oi, piece)) continue;
				if(piece->has_text && !inner_text_eq(d, oi, piece->text, piece->text_len)) continue;
				if(!grow_cap((void **)out, sizeof(size_t), out_cap, *out_n + 1)) return;
				(*out)[(*out_n)++] = oi;
				opens_scan_drop_behind(&d->scan, &last_drop, oi);
				if(stop_early && *out_n >= want_n) return;
			}
		}
		if(nid >= 0) {
			for(size_t pi = 0; pi < d->tag_pend_n; pi++) {
				if(d->tag_pend_name[pi] != (uint32_t)nid) continue;
				size_t oi = d->tag_pend_oi[pi];
				const lom_open_row *row = &d->scan.opens[oi];
				if(row_is_dead(row)) continue;
				int64_t ooff = row_open_off(d, row);
				if(ooff <= poff || ooff > pend) continue;
				if(piece->has_attr && !row_has_attr_oi(d, oi, piece)) continue;
				if(piece->has_text && !inner_text_eq(d, oi, piece->text, piece->text_len)) continue;
				if(!grow_cap((void **)out, sizeof(size_t), out_cap, *out_n + 1)) return;
				(*out)[(*out_n)++] = oi;
				if(stop_early && *out_n >= want_n) return;
			}
		}
		return;
	}

	/* Wildcard '*': must walk opens in the span. */
	size_t walk_lo = parent_i < sorted_n ? parent_i + 1 : sorted_n;
	size_t last_drop = walk_lo;
	for(size_t oi = walk_lo; oi < sorted_n; oi++) {
		const lom_open_row *row = &d->scan.opens[oi];
		if(row_is_dead(row)) continue;
		if(row_open_off(d, row) > pend) break;
		if(piece->has_attr && !row_has_attr_oi(d, oi, piece)) continue;
		if(piece->has_text && !inner_text_eq(d, oi, piece->text, piece->text_len)) continue;
		if(!grow_cap((void **)out, sizeof(size_t), out_cap, *out_n + 1)) return;
		(*out)[(*out_n)++] = oi;
		opens_scan_drop_behind(&d->scan, &last_drop, oi);
		if(stop_early && *out_n >= want_n) return;
	}
	for(size_t oi = sorted_n; oi < d->scan.open_count; oi++) {
		const lom_open_row *row = &d->scan.opens[oi];
		if(row_is_dead(row)) continue;
		int64_t ooff = row_open_off(d, row);
		if(ooff <= poff || ooff > pend) continue;
		if(piece->has_attr && !row_has_attr_oi(d, oi, piece)) continue;
		if(piece->has_text && !inner_text_eq(d, oi, piece->text, piece->text_len)) continue;
		if(!grow_cap((void **)out, sizeof(size_t), out_cap, *out_n + 1)) return;
		(*out)[(*out_n)++] = oi;
		if(stop_early && *out_n >= want_n) return;
	}
}

/* No-CSR child expand: walk opens in document order within the parent's span only.
 * Avoids scanning every global tag-row (which faulted multi-GB open tables on 20GB). */
static void collect_named_children_span(lom_doc *d, size_t parent_i, const sel_piece *piece,
	size_t **out, size_t *out_n, size_t *out_cap) {
	*out_n = 0;
	size_t sorted_n = opens_sorted_count(d);
	if(parent_i >= d->scan.open_count) return;
	const lom_open_row *prow = &d->scan.opens[parent_i];
	int64_t pend = row_node_end(d, prow);
	int64_t poff = row_open_off(d, prow);
	size_t want_n = 0;
	int stop_early = 0;
	if(piece->index > 0) {
		want_n = (size_t)piece->index; /* collect up to n; caller picks */
		stop_early = 1;
	}
	size_t walk_lo = parent_i < sorted_n ? parent_i + 1 : sorted_n;
	size_t last_drop = walk_lo;
	for(size_t oi = walk_lo; oi < sorted_n; oi++) {
		const lom_open_row *row = &d->scan.opens[oi];
		if(row_is_dead(row)) continue;
		if(row_open_off(d, row) > pend) break;
		if(row_parent(d, row) != (int32_t)parent_i) {
			opens_scan_drop_behind(&d->scan, &last_drop, oi);
			continue;
		}
		if(piece->name_len == 1 && piece->name[0] == '*') {
			/* ok */
		} else if(!name_eq(d, row->name_id, piece->name, piece->name_len)) {
			opens_scan_drop_behind(&d->scan, &last_drop, oi);
			continue;
		}
		if(piece->has_attr && !row_has_attr_oi(d, oi, piece)) continue;
		if(piece->has_text && !inner_text_eq(d, oi, piece->text, piece->text_len)) continue;
		if(!grow_cap((void **)out, sizeof(size_t), out_cap, *out_n + 1)) return;
		(*out)[(*out_n)++] = oi;
		opens_scan_drop_behind(&d->scan, &last_drop, oi);
		if(stop_early && *out_n >= want_n) return;
	}
	/* Early new_ appends sit past open_sorted_n; pick up matching children by parent. */
	for(size_t oi = sorted_n; oi < d->scan.open_count; oi++) {
		const lom_open_row *row = &d->scan.opens[oi];
		if(row_is_dead(row)) continue;
		int64_t ooff = row_open_off(d, row);
		if(ooff <= poff || ooff > pend) continue;
		if(row_parent(d, row) != (int32_t)parent_i) continue;
		if(piece->name_len == 1 && piece->name[0] == '*') {
			/* ok */
		} else if(!name_eq(d, row->name_id, piece->name, piece->name_len)) {
			continue;
		}
		if(piece->has_attr && !row_has_attr_oi(d, oi, piece)) continue;
		if(piece->has_text && !inner_text_eq(d, oi, piece->text, piece->text_len)) continue;
		if(!grow_cap((void **)out, sizeof(size_t), out_cap, *out_n + 1)) return;
		(*out)[(*out_n)++] = oi;
		if(stop_early && *out_n >= want_n) return;
	}
}

/* When CSR is absent (huge docs), expand children via in-span walk (not global tag rows). */

static lom_status query_chain(lom_doc *d, const sel_chain *chain, lom_match_list *out) {
	lom_match_list_free(out);
	lom_match_list_init(out);

	/* Single tag / '*': emit matches in one pass (no oi staging array). */
	if(chain->count == 1) {
		const sel_piece *piece = &chain->pieces[0];
		if(piece->index <= 0 && !piece->has_text && !piece->has_regex) {
			size_t last_drop = 0;
			if(piece->name_len == 1 && piece->name[0] == '*') {
				size_t n = d->scan.open_count;
				if(!piece->has_attr) {
					if(n > 0 && !grow_cap((void **)&out->items, sizeof(lom_match), &out->cap, n))
						return LOM_ERR_NOMEM;
					/* Fresh docs: no bias / almost no DEAD — tight emit. */
					if(!d->off_bias_delta) {
						for(size_t i = 0; i < n; i++) {
							const lom_open_row *row = &d->scan.opens[i];
							if(row->self_closing & (LOM_OPEN_DEAD | LOM_OPEN_ABS | LOM_OPEN_NE_OVF)) {
								if(row_is_dead(row)) continue;
								if(!match_push_fast(out, row_open_off(d, row), row_node_end(d, row)))
									return LOM_ERR_NOMEM;
								continue;
							}
							out->items[out->count].offset = row->open_off;
							out->items[out->count].end_off = row->open_off + (int64_t)row->node_end_rel;
							out->count++;
						}
						return LOM_OK;
					}
				}
				for(size_t i = 0; i < n; i++) {
					const lom_open_row *row = &d->scan.opens[i];
					if(row_is_dead(row)) continue;
					if(piece->has_attr && !row_has_attr_oi(d, i, piece)) continue;
					if(!match_push_fast(out, row_open_off(d, row), row_node_end(d, row))) return LOM_ERR_NOMEM;
					opens_scan_drop_behind(&d->scan, &last_drop, i);
				}
				return LOM_OK;
			}
			int32_t nid = find_name_id(d, piece->name, piece->name_len);
			if(nid < 0) return LOM_OK;
			uint32_t *rows = d->tag_rows[nid];
			size_t rc = d->tag_row_counts[nid];
			if(rc > 0 && !piece->has_attr &&
			   !grow_cap((void **)&out->items, sizeof(lom_match), &out->cap, rc + d->tag_pend_n))
				return LOM_ERR_NOMEM;
			if(rc > 0 && piece->has_attr) {
				/* Presence usually matches most of the tag row; equality is sparser. */
				size_t hint = piece->attr_eq ? (rc / 4 + 64) : (rc + d->tag_pend_n);
				(void)grow_cap((void **)&out->items, sizeof(lom_match), &out->cap, hint);
			}
			int nobias = !d->off_bias_delta;
			for(size_t i = 0; i < rc; i++) {
				if(i + 16 < rc)
					__builtin_prefetch(&d->scan.opens[decode_open_idx(d, rows[i + 16])], 0, 1);
				size_t oi = decode_open_idx(d, rows[i]);
				const lom_open_row *row = &d->scan.opens[oi];
				if(row_is_dead(row)) continue;
				if(piece->has_attr) {
					if(!d->code_overlay && nobias &&
					   !(row->self_closing & (LOM_OPEN_ABS | LOM_OPEN_NE_OVF))) {
						int64_t ooff = row->open_off;
						size_t tlen = (size_t)row->tag_end_rel + 1;
						if((size_t)ooff + tlen <= d->code_len &&
						   !tag_span_has_attr(d->code + (size_t)ooff, tlen, piece))
							continue;
						if((size_t)ooff + tlen > d->code_len && !row_has_attr_oi(d, oi, piece))
							continue;
						if(!match_push_fast(out, ooff, ooff + (int64_t)row->node_end_rel))
							return LOM_ERR_NOMEM;
						opens_scan_drop_behind(&d->scan, &last_drop, oi);
						continue;
					}
					if(!row_has_attr_oi(d, oi, piece)) continue;
				}
				if(!match_push_fast(out, row_open_off(d, row), row_node_end(d, row))) return LOM_ERR_NOMEM;
				opens_scan_drop_behind(&d->scan, &last_drop, oi);
			}
			for(size_t pi = 0; pi < d->tag_pend_n; pi++) {
				if(d->tag_pend_name[pi] != (uint32_t)nid) continue;
				size_t oi = d->tag_pend_oi[pi];
				const lom_open_row *row = &d->scan.opens[oi];
				if(row_is_dead(row)) continue;
				if(piece->has_attr && !row_has_attr_oi(d, oi, piece)) continue;
				if(!match_push(out, row_open_off(d, row), row_node_end(d, row))) return LOM_ERR_NOMEM;
			}
			return LOM_OK;
		}
	}

	size_t *cur = NULL, cur_n = 0, cur_cap = 0;
	size_t *nxt = NULL, nxt_n = 0, nxt_cap = 0;

	for(size_t pi = 0; pi < chain->count; pi++) {
		const sel_piece *piece = &chain->pieces[pi];
		nxt_n = 0;
		if(pi == 0) {
			if(piece->index > 0) {
				size_t pick = (size_t)piece->index - 1;
				/* Document-order [n] on the first path step: n-th tag with this name.
				 * Do NOT scan every parent's children (that was O(#opens) and faulted
				 * the entire open table into RAM on large docs). Later steps use
				 * per-parent [n] via CSR / sibling groups. */
				int32_t nid = find_name_id(d, piece->name, piece->name_len);
				if(nid >= 0 && (size_t)nid < d->tag_row_slots) {
					uint32_t *rows = d->tag_rows[nid];
					size_t rc = d->tag_row_counts[nid];
					size_t total = rc + d->tag_pend_n; /* upper bound; pick in doc order approx */
					if(pick < rc) {
						size_t oi = decode_open_idx(d, rows[pick]);
						if(row_is_dead(&d->scan.opens[oi])) {
							/* skip — fall through without match */
						} else if(!(piece->has_attr && !row_has_attr_oi(d, oi, piece)) &&
						   !(piece->has_text && !inner_text_eq(d, oi, piece->text, piece->text_len))) {
							grow_cap((void **)&nxt, sizeof(size_t), &nxt_cap, 1);
							nxt[nxt_n++] = oi;
						}
					} else if(pick < total) {
						/* Rare: [n] past pre-bias rows into pending — linear scan pending. */
						size_t seen = rc;
						for(size_t pi = 0; pi < d->tag_pend_n; pi++) {
							if(d->tag_pend_name[pi] != (uint32_t)nid) continue;
							if(seen++ != pick) continue;
							size_t oi = d->tag_pend_oi[pi];
							grow_cap((void **)&nxt, sizeof(size_t), &nxt_cap, 1);
							nxt[nxt_n++] = oi;
							break;
						}
					}
					(void)total;
				}
			} else {
				int32_t nid = find_name_id(d, piece->name, piece->name_len);
				if(piece->name_len == 1 && piece->name[0] == '*') {
					for(size_t i = 0; i < d->scan.open_count; i++) {
						if(piece->has_attr && !row_has_attr_oi(d, i, piece)) continue;
						if(piece->has_text && !inner_text_eq(d, i, piece->text, piece->text_len)) continue;
						grow_cap((void **)&nxt, sizeof(size_t), &nxt_cap, nxt_n + 1);
						nxt[nxt_n++] = i;
					}
				} else if(nid < 0) {
					nxt_n = 0;
				} else {
					uint32_t *rows = d->tag_rows[nid];
					size_t rc = d->tag_row_counts[nid];
					if(rc > 0) grow_cap((void **)&nxt, sizeof(size_t), &nxt_cap, rc);
					for(size_t i = 0; i < rc; i++) {
						size_t oi = decode_open_idx(d, rows[i]);
						if(row_is_dead(&d->scan.opens[oi])) continue;
						if(piece->has_attr && !row_has_attr_oi(d, oi, piece)) continue;
						if(piece->has_text && !inner_text_eq(d, oi, piece->text, piece->text_len)) continue;
						grow_cap((void **)&nxt, sizeof(size_t), &nxt_cap, nxt_n + 1);
						nxt[nxt_n++] = oi;
					}
					for(size_t pi = 0; pi < d->tag_pend_n; pi++) {
						if(d->tag_pend_name[pi] != (uint32_t)nid) continue;
						size_t oi = d->tag_pend_oi[pi];
						if(row_is_dead(&d->scan.opens[oi])) continue;
						if(piece->has_attr && !row_has_attr_oi(d, oi, piece)) continue;
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
				if(piece->descendant) {
					collect_named_descendants_span(d, parent_i, piece, &tmp, &tmp_n, &tmp_cap);
				} else if(d->child_at && d->child_start) {
					const uint32_t *kids = NULL; size_t kn = 0;
					doc_child_range(d, parent_i, &kids, &kn);
					collect_named_children(d, kids, kn, piece, &tmp, &tmp_n, &tmp_cap);
				} else {
					collect_named_children_span(d, parent_i, piece, &tmp, &tmp_n, &tmp_cap);
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
		free(cur);
		cur = nxt; cur_n = nxt_n; cur_cap = nxt_cap;
		(void)cur_cap;
		nxt = NULL; nxt_n = 0; nxt_cap = 0;
		if(cur_n == 0) break;
	}

	/* Direct-chain without per-step indexing used parent-walk for unindexed multi-piece.
	   When first piece had no index and count>1, first branch used global tag list — that
	   matches PHP simple direct chain when no indices. When indices present, children walk matches indexed path. */

	if(cur_n > 0 && !grow_cap((void **)&out->items, sizeof(lom_match), &out->cap, cur_n)) {
		free(cur);
		return LOM_ERR_NOMEM;
	}
	size_t last_drop = 0;
	for(size_t i = 0; i < cur_n; i++) {
		const lom_open_row *row = &d->scan.opens[cur[i]];
		if(!match_push(out, row_open_off(d, row), row_node_end(d, row))) {
			free(cur);
			return LOM_ERR_NOMEM;
		}
		opens_scan_drop_behind(&d->scan, &last_drop, cur[i]);
	}
	free(cur);
	return LOM_OK;
}

/* Unindexed multi-piece: PHP walks from leaf candidates via parents. Prefer that when no indices. */
static bool chain_has_index(const sel_chain *c) {
	for(size_t i = 0; i < c->count; i++) if(c->pieces[i].index > 0) return true;
	return false;
}

static bool chain_has_descendant(const sel_chain *c) {
	for(size_t i = 0; i < c->count; i++) if(c->pieces[i].descendant) return true;
	return false;
}

static size_t tag_row_count_for(const lom_doc *d, const sel_piece *piece) {
	if(piece->name_len == 1 && piece->name[0] == '*') return d->scan.open_count;
	int32_t nid = find_name_id(d, piece->name, piece->name_len);
	if(nid < 0 || (size_t)nid >= d->tag_row_slots) return 0;
	return d->tag_row_counts[nid];
}

/* Walk from leaf open-index upward to match chain pieces[0..count-2].
 * nids[i] = resolved name_id for pieces[i], or -1 for '*'. Uses parent_idx only. */
static bool ancestor_chain_ok_oi(const lom_doc *d, const sel_chain *chain, size_t leaf_oi,
	const int32_t *nids) {
	if(leaf_oi >= d->scan.open_count) return false;
	size_t cidx = leaf_oi;
	for(ssize_t pi = (ssize_t)chain->count - 2; pi >= 0; pi--) {
		const sel_piece *pp = &chain->pieces[pi];
		const sel_piece *child_piece = &chain->pieces[pi + 1];
		int32_t want = nids ? nids[pi] : -2; /* -2 => resolve via name_eq */
		if(child_piece->descendant) {
			int32_t p = row_parent(d, &d->scan.opens[cidx]);
			int found = 0;
			while(p >= 0) {
				const lom_open_row *prow = &d->scan.opens[(size_t)p];
				int name_ok;
				if(want == -1) name_ok = 1; /* '*' */
				else if(want >= 0) name_ok = (prow->name_id == (uint32_t)want);
				else name_ok = name_eq(d, prow->name_id, pp->name, pp->name_len);
				if(name_ok &&
				   !(pp->has_attr && !row_has_attr_oi(d, (size_t)p, pp)) &&
				   !(pp->has_text && !inner_text_eq(d, (size_t)p, pp->text, pp->text_len))) {
					cidx = (size_t)p;
					found = 1;
					break;
				}
				p = row_parent(d, prow);
			}
			if(!found) return false;
		} else {
			int32_t pidx32 = row_parent(d, &d->scan.opens[cidx]);
			if(pidx32 < 0) return false;
			size_t pidx = (size_t)pidx32;
			if(want == -1) {
				/* '*' */
			} else if(want >= 0) {
				if(d->scan.opens[pidx].name_id != (uint32_t)want) return false;
			} else if(!name_eq(d, d->scan.opens[pidx].name_id, pp->name, pp->name_len)) {
				return false;
			}
			if(pp->has_attr && !row_has_attr_oi(d, pidx, pp)) return false;
			if(pp->has_text && !inner_text_eq(d, pidx, pp->text, pp->text_len)) return false;
			cidx = pidx;
		}
	}
	return true;
}

static void chain_resolve_nids(const lom_doc *d, const sel_chain *chain, int32_t *nids) {
	for(size_t i = 0; i < chain->count; i++) {
		const sel_piece *p = &chain->pieces[i];
		if(p->name_len == 1 && p->name[0] == '*') nids[i] = -1;
		else nids[i] = find_name_id(d, p->name, p->name_len);
	}
}

/* Pure child chain A_B_C_… (no __): parent_idx hops with pre-resolved name_ids. */
static lom_status query_pure_child_chain(lom_doc *d, const sel_chain *chain, lom_match_list *out) {
	lom_match_list_free(out);
	lom_match_list_init(out);
	if(chain->count < 2 || chain->count > 32) return LOM_ERR_ARG;
	int32_t nids[32];
	chain_resolve_nids(d, chain, nids);
	for(size_t i = 0; i < chain->count; i++) {
		if(nids[i] < 0) return LOM_ERR_ARG; /* '*' or missing */
		if(chain->pieces[i].descendant) return LOM_ERR_ARG;
	}
	const sel_piece *leaf = &chain->pieces[chain->count - 1];
	int32_t lnid = nids[chain->count - 1];
	if((size_t)lnid >= d->tag_row_slots) return LOM_OK;
	uint32_t *lrows = d->tag_rows[lnid];
	size_t lrc = d->tag_row_counts[lnid];
	if(lrc > 0 && !grow_cap((void **)&out->items, sizeof(lom_match), &out->cap, lrc))
		return LOM_ERR_NOMEM;
	size_t last_drop = 0;
	size_t hops = chain->count - 1;
	for(size_t li = 0; li < lrc; li++) {
		size_t loi = decode_open_idx(d, lrows[li]);
		const lom_open_row *lrow = &d->scan.opens[loi];
		if(row_is_dead(lrow)) continue;
		if(leaf->has_attr && !row_has_attr_oi(d, loi, leaf)) continue;
		if(leaf->has_text && !inner_text_eq(d, loi, leaf->text, leaf->text_len)) continue;
		size_t cidx = loi;
		int ok = 1;
		for(size_t h = 0; h < hops; h++) {
			ssize_t pi = (ssize_t)(chain->count - 2 - h);
			int32_t pidx32 = row_parent(d, &d->scan.opens[cidx]);
			if(pidx32 < 0) { ok = 0; break; }
			size_t pidx = (size_t)pidx32;
			if(d->scan.opens[pidx].name_id != (uint32_t)nids[pi]) { ok = 0; break; }
			const sel_piece *pp = &chain->pieces[pi];
			if(pp->has_attr && !row_has_attr_oi(d, pidx, pp)) { ok = 0; break; }
			if(pp->has_text && !inner_text_eq(d, pidx, pp->text, pp->text_len)) { ok = 0; break; }
			cidx = pidx;
		}
		if(!ok) {
			opens_scan_drop_behind(&d->scan, &last_drop, loi);
			continue;
		}
		if(!match_push(out, row_open_off(d, lrow), row_node_end(d, lrow))) return LOM_ERR_NOMEM;
		opens_scan_drop_behind(&d->scan, &last_drop, loi);
	}
	for(size_t pi = 0; pi < d->tag_pend_n; pi++) {
		if(d->tag_pend_name[pi] != (uint32_t)lnid) continue;
		size_t oi = d->tag_pend_oi[pi];
		const lom_open_row *row = &d->scan.opens[oi];
		if(row_is_dead(row)) continue;
		if(leaf->has_attr && !row_has_attr_oi(d, oi, leaf)) continue;
		if(leaf->has_text && !inner_text_eq(d, oi, leaf->text, leaf->text_len)) continue;
		if(!ancestor_chain_ok_oi(d, chain, oi, nids)) continue;
		if(!match_push(out, row_open_off(d, row), row_node_end(d, row))) return LOM_ERR_NOMEM;
	}
	return LOM_OK;
}

/* Three-piece A__B_C: leaf's parent is B, and that pair sits under some A (doc-order merge). */
static lom_status query_desc_child_merge(lom_doc *d, const sel_piece *root, const sel_piece *mid,
	const sel_piece *leaf, lom_match_list *out) {
	lom_match_list_free(out);
	lom_match_list_init(out);
	if((root->name_len == 1 && root->name[0] == '*') ||
	   (mid->name_len == 1 && mid->name[0] == '*') ||
	   (leaf->name_len == 1 && leaf->name[0] == '*'))
		return LOM_ERR_ARG;
	int32_t rnid = find_name_id(d, root->name, root->name_len);
	int32_t lnid = find_name_id(d, leaf->name, leaf->name_len);
	if(rnid < 0 || lnid < 0) return LOM_OK;
	if((size_t)rnid >= d->tag_row_slots || (size_t)lnid >= d->tag_row_slots) return LOM_OK;

	uint32_t *rrows = d->tag_rows[rnid];
	size_t rrc = d->tag_row_counts[rnid];
	uint32_t *lrows = d->tag_rows[lnid];
	size_t lrc = d->tag_row_counts[lnid];
	if(lrc > 0 && !grow_cap((void **)&out->items, sizeof(lom_match), &out->cap, lrc))
		return LOM_ERR_NOMEM;

	size_t ri = 0;
	int64_t r_off = -1, r_end = -1;
	int r_valid = 0;
	size_t last_drop = 0;

	for(size_t li = 0; li < lrc; li++) {
		size_t loi = decode_open_idx(d, lrows[li]);
		const lom_open_row *lrow = &d->scan.opens[loi];
		if(row_is_dead(lrow)) continue;
		int32_t pidx = row_parent(d, lrow);
		if(pidx < 0) continue;
		const lom_open_row *mrow = &d->scan.opens[(size_t)pidx];
		if(row_is_dead(mrow)) continue;
		if(!name_eq(d, mrow->name_id, mid->name, mid->name_len)) continue;
		if(mid->has_attr && !row_has_attr_oi(d, (size_t)pidx, mid)) continue;
		if(mid->has_text && !inner_text_eq(d, (size_t)pidx, mid->text, mid->text_len)) continue;
		int64_t loff = row_open_off(d, lrow);
		if(leaf->has_attr && !row_has_attr_oi(d, loi, leaf)) continue;
		if(leaf->has_text && !inner_text_eq(d, loi, leaf->text, leaf->text_len)) continue;

		while(ri < rrc) {
			if(!r_valid) {
				size_t roi = decode_open_idx(d, rrows[ri]);
				const lom_open_row *rrow = &d->scan.opens[roi];
				if(row_is_dead(rrow)) { ri++; continue; }
				r_off = row_open_off(d, rrow);
				if(root->has_attr && !row_has_attr_oi(d, roi, root)) { ri++; continue; }
				if(root->has_text && !inner_text_eq(d, roi, root->text, root->text_len)) { ri++; continue; }
				r_end = row_node_end(d, rrow);
				r_valid = 1;
			}
			if(r_end < loff) { ri++; r_valid = 0; continue; }
			break;
		}
		if(r_valid && r_off < loff && loff <= r_end) {
			if(!match_push(out, loff, row_node_end(d, lrow))) return LOM_ERR_NOMEM;
		}
		opens_scan_drop_behind(&d->scan, &last_drop, loi);
	}

	sel_chain ch;
	memset(&ch, 0, sizeof(ch));
	ch.count = 3;
	ch.pieces[0] = *root;
	ch.pieces[0].descendant = false;
	ch.pieces[1] = *mid;
	ch.pieces[1].descendant = true;
	ch.pieces[2] = *leaf;
	ch.pieces[2].descendant = false;
	for(size_t pi = 0; pi < d->tag_pend_n; pi++) {
		if(d->tag_pend_name[pi] != (uint32_t)lnid) continue;
		size_t oi = d->tag_pend_oi[pi];
		const lom_open_row *row = &d->scan.opens[oi];
		if(row_is_dead(row)) continue;
		if(leaf->has_attr && !row_has_attr_oi(d, oi, leaf)) continue;
		if(leaf->has_text && !inner_text_eq(d, oi, leaf->text, leaf->text_len)) continue;
		if(!ancestor_chain_ok_oi(d, &ch, oi, NULL)) continue;
		if(!match_push(out, row_open_off(d, row), row_node_end(d, row))) return LOM_ERR_NOMEM;
	}
	return LOM_OK;
}

/* Three-piece A__B__C: leaf under some B under some A (parent walk to B + A span merge). */
static lom_status query_desc_desc_merge(lom_doc *d, const sel_piece *root, const sel_piece *mid,
	const sel_piece *leaf, lom_match_list *out) {
	lom_match_list_free(out);
	lom_match_list_init(out);
	if((root->name_len == 1 && root->name[0] == '*') ||
	   (mid->name_len == 1 && mid->name[0] == '*') ||
	   (leaf->name_len == 1 && leaf->name[0] == '*'))
		return LOM_ERR_ARG;
	int32_t rnid = find_name_id(d, root->name, root->name_len);
	int32_t lnid = find_name_id(d, leaf->name, leaf->name_len);
	if(rnid < 0 || lnid < 0) return LOM_OK;
	if((size_t)rnid >= d->tag_row_slots || (size_t)lnid >= d->tag_row_slots) return LOM_OK;

	uint32_t *rrows = d->tag_rows[rnid];
	size_t rrc = d->tag_row_counts[rnid];
	uint32_t *lrows = d->tag_rows[lnid];
	size_t lrc = d->tag_row_counts[lnid];
	if(lrc > 0 && !grow_cap((void **)&out->items, sizeof(lom_match), &out->cap, lrc))
		return LOM_ERR_NOMEM;

	size_t ri = 0;
	int64_t r_off = -1, r_end = -1;
	int r_valid = 0;
	size_t last_drop = 0;

	for(size_t li = 0; li < lrc; li++) {
		size_t loi = decode_open_idx(d, lrows[li]);
		const lom_open_row *lrow = &d->scan.opens[loi];
		if(row_is_dead(lrow)) continue;
		int64_t loff = row_open_off(d, lrow);
		if(leaf->has_attr && !row_has_attr_oi(d, loi, leaf)) continue;
		if(leaf->has_text && !inner_text_eq(d, loi, leaf->text, leaf->text_len)) continue;

		/* Innermost mid-named ancestor. */
		int32_t p = row_parent(d, lrow);
		int found_mid = 0;
		int64_t mid_off = -1;
		while(p >= 0) {
			const lom_open_row *mrow = &d->scan.opens[(size_t)p];
			if(row_is_dead(mrow)) { p = row_parent(d, mrow); continue; }
			if(name_eq(d, mrow->name_id, mid->name, mid->name_len) &&
			   !(mid->has_attr && !row_has_attr_oi(d, (size_t)p, mid)) &&
			   !(mid->has_text && !inner_text_eq(d, (size_t)p, mid->text, mid->text_len))) {
				found_mid = 1;
				mid_off = row_open_off(d, mrow);
				break;
			}
			p = row_parent(d, mrow);
		}
		if(!found_mid) continue;

		while(ri < rrc) {
			if(!r_valid) {
				size_t roi = decode_open_idx(d, rrows[ri]);
				const lom_open_row *rrow = &d->scan.opens[roi];
				if(row_is_dead(rrow)) { ri++; continue; }
				r_off = row_open_off(d, rrow);
				if(root->has_attr && !row_has_attr_oi(d, roi, root)) { ri++; continue; }
				if(root->has_text && !inner_text_eq(d, roi, root->text, root->text_len)) { ri++; continue; }
				r_end = row_node_end(d, rrow);
				r_valid = 1;
			}
			if(r_end < loff) { ri++; r_valid = 0; continue; }
			break;
		}
		/* Root must contain both mid and leaf. */
		if(r_valid && r_off < mid_off && mid_off <= r_end && r_off < loff && loff <= r_end) {
			if(!match_push(out, loff, row_node_end(d, lrow))) return LOM_ERR_NOMEM;
		}
		opens_scan_drop_behind(&d->scan, &last_drop, loi);
	}

	sel_chain ch;
	memset(&ch, 0, sizeof(ch));
	ch.count = 3;
	ch.pieces[0] = *root;
	ch.pieces[0].descendant = false;
	ch.pieces[1] = *mid;
	ch.pieces[1].descendant = true;
	ch.pieces[2] = *leaf;
	ch.pieces[2].descendant = true;
	for(size_t pi = 0; pi < d->tag_pend_n; pi++) {
		if(d->tag_pend_name[pi] != (uint32_t)lnid) continue;
		size_t oi = d->tag_pend_oi[pi];
		const lom_open_row *row = &d->scan.opens[oi];
		if(row_is_dead(row)) continue;
		if(leaf->has_attr && !row_has_attr_oi(d, oi, leaf)) continue;
		if(leaf->has_text && !inner_text_eq(d, oi, leaf->text, leaf->text_len)) continue;
		if(!ancestor_chain_ok_oi(d, &ch, oi, NULL)) continue;
		if(!match_push(out, row_open_off(d, row), row_node_end(d, row))) return LOM_ERR_NOMEM;
	}
	return LOM_OK;
}

/* Two-piece A__B: merge ancestor + leaf tag rows in doc order (no parent-pointer walks).
 * O(nA + nL) sequential open faults — much lower RSS/latency than leaf parent-walk on huge docs. */
static lom_status query_descendant_merge(lom_doc *d, const sel_piece *anc, const sel_piece *leaf,
	lom_match_list *out) {
	lom_match_list_free(out);
	lom_match_list_init(out);
	int32_t anid = find_name_id(d, anc->name, anc->name_len);
	int32_t lnid = find_name_id(d, leaf->name, leaf->name_len);
	if(lnid < 0 || anid < 0) return LOM_OK;
	if((size_t)anid >= d->tag_row_slots || (size_t)lnid >= d->tag_row_slots) return LOM_OK;

	uint32_t *arows = d->tag_rows[anid];
	size_t arc = d->tag_row_counts[anid];
	uint32_t *lrows = d->tag_rows[lnid];
	size_t lrc = d->tag_row_counts[lnid];
	if(lrc > 0 && !grow_cap((void **)&out->items, sizeof(lom_match), &out->cap, lrc))
		return LOM_ERR_NOMEM;

	size_t ai = 0;
	int64_t a_off = -1, a_end = -1;
	int a_valid = 0;
	size_t last_drop = 0;

	for(size_t li = 0; li < lrc; li++) {
		size_t loi = decode_open_idx(d, lrows[li]);
		const lom_open_row *lrow = &d->scan.opens[loi];
		if(row_is_dead(lrow)) continue;
		int64_t loff = row_open_off(d, lrow);
		if(leaf->has_attr && !row_has_attr_oi(d, loi, leaf)) continue;
		if(leaf->has_text && !inner_text_eq(d, loi, leaf->text, leaf->text_len)) continue;

		/* Advance ancestors that end before this leaf. */
		while(ai < arc) {
			if(!a_valid) {
				size_t aoi = decode_open_idx(d, arows[ai]);
				const lom_open_row *arow = &d->scan.opens[aoi];
				if(row_is_dead(arow)) { ai++; continue; }
				a_off = row_open_off(d, arow);
				if(anc->has_attr && !row_has_attr_oi(d, aoi, anc)) { ai++; continue; }
				if(anc->has_text && !inner_text_eq(d, aoi, anc->text, anc->text_len)) { ai++; continue; }
				a_end = row_node_end(d, arow);
				a_valid = 1;
			}
			if(a_end < loff) { ai++; a_valid = 0; continue; }
			break;
		}
		if(a_valid && a_off < loff && loff <= a_end) {
			if(!match_push(out, loff, row_node_end(d, lrow))) return LOM_ERR_NOMEM;
		}
		(void)last_drop;
		/* drop-behind after match: sequential leaf scan, ancestors only move forward */
		opens_scan_drop_behind(&d->scan, &last_drop, loi);
	}

	/* Pending leaf appends: parent-walk containment. */
	sel_chain ch;
	memset(&ch, 0, sizeof(ch));
	ch.count = 2;
	ch.pieces[0] = *anc;
	ch.pieces[0].descendant = false;
	ch.pieces[1] = *leaf;
	ch.pieces[1].descendant = true;
	for(size_t pi = 0; pi < d->tag_pend_n; pi++) {
		if(d->tag_pend_name[pi] != (uint32_t)lnid) continue;
		size_t oi = d->tag_pend_oi[pi];
		const lom_open_row *row = &d->scan.opens[oi];
		if(row_is_dead(row)) continue;
		if(leaf->has_attr && !row_has_attr_oi(d, oi, leaf)) continue;
		if(leaf->has_text && !inner_text_eq(d, oi, leaf->text, leaf->text_len)) continue;
		if(!ancestor_chain_ok_oi(d, &ch, oi, NULL)) continue;
		if(!match_push(out, row_open_off(d, row), row_node_end(d, row))) return LOM_ERR_NOMEM;
	}
	return LOM_OK;
}

static lom_status query_direct_parent_walk(lom_doc *d, const sel_chain *chain, lom_match_list *out) {
	lom_match_list_free(out);
	lom_match_list_init(out);
	const sel_piece *last = &chain->pieces[chain->count - 1];
	int32_t nid = find_name_id(d, last->name, last->name_len);
	if(nid < 0) return LOM_OK;
	uint32_t *rows = d->tag_rows[nid];
	size_t rc = d->tag_row_counts[nid];
	size_t last_drop = 0;
	int32_t anids[32];
	int have_anids = (chain->count >= 2 && chain->count <= 32);
	if(have_anids) chain_resolve_nids(d, chain, anids);
	if(rc > 0 && !grow_cap((void **)&out->items, sizeof(lom_match), &out->cap, rc))
		return LOM_ERR_NOMEM;
	for(size_t i = 0; i < rc; i++) {
		size_t oi = decode_open_idx(d, rows[i]);
		const lom_open_row *row = &d->scan.opens[oi];
		if(row_is_dead(row)) continue;
		if(last->has_attr && !row_has_attr_oi(d, oi, last)) continue;
		if(last->has_text && !inner_text_eq(d, oi, last->text, last->text_len)) continue;
		if(!ancestor_chain_ok_oi(d, chain, oi, have_anids ? anids : NULL)) continue;
		if(!match_push(out, row_open_off(d, row), row_node_end(d, row))) return LOM_ERR_NOMEM;
		/* Forward-only drop: parents may re-fault; peak RSS still stays lower. */
		opens_scan_drop_behind(&d->scan, &last_drop, oi);
	}
	for(size_t pi = 0; pi < d->tag_pend_n; pi++) {
		if(d->tag_pend_name[pi] != (uint32_t)nid) continue;
		size_t oi = d->tag_pend_oi[pi];
		const lom_open_row *row = &d->scan.opens[oi];
		if(row_is_dead(row)) continue;
		if(last->has_attr && !row_has_attr_oi(d, oi, last)) continue;
		if(last->has_text && !inner_text_eq(d, oi, last->text, last->text_len)) continue;
		if(!ancestor_chain_ok_oi(d, chain, oi, have_anids ? anids : NULL)) continue;
		if(!match_push(out, row_open_off(d, row), row_node_end(d, row))) return LOM_ERR_NOMEM;
	}
	return LOM_OK;
}

/* Doc-ordered hits: advance cursor to rightmost open with open_off <= hit (O(1) amortized). */
static size_t open_start_tag_contains_adv(const lom_doc *d, int64_t hit_off, size_t *cursor) {
	size_t sorted_n = opens_sorted_count(d);
	size_t c = *cursor;
	if(c >= sorted_n) c = 0;
	while(c + 1 < sorted_n && row_open_off(d, &d->scan.opens[c + 1]) <= hit_off)
		c++;
	*cursor = c;
	if(sorted_n == 0) return (size_t)-1;
	const lom_open_row *row = &d->scan.opens[c];
	if(row_is_dead(row)) return (size_t)-1;
	if(row_open_off(d, row) <= hit_off && hit_off <= row_tag_end(d, row)) return c;
	return (size_t)-1;
}

/* No attr index: find ` attr=` / ` attr="val"` in document bytes, map to covering open tags. */
static lom_status query_attr_via_doc_hits(lom_doc *d, const sel_piece *piece, lom_match_list *out) {
	if(d->code_overlay || !d->code || d->code_len == 0) return LOM_ERR_ARG;
	if(piece->attr_len == 0 || piece->attr_len > 120) return LOM_ERR_ARG;

	char probes[3][160];
	size_t plen[3];
	int nprobes = 0;
	if(piece->attr_eq) {
		if(piece->attr_val_len > 120) return LOM_ERR_ARG;
		/* kind="npc" and kind='npc' */
		size_t n = 1 + piece->attr_len + 2 + piece->attr_val_len + 1;
		if(n >= sizeof(probes[0])) return LOM_ERR_ARG;
		for(int q = 0; q < 2; q++) {
			char qq = (q == 0) ? '"' : '\'';
			char *p = probes[nprobes];
			p[0] = ' ';
			memcpy(p + 1, piece->attr, piece->attr_len);
			p[1 + piece->attr_len] = '=';
			p[2 + piece->attr_len] = qq;
			memcpy(p + 3 + piece->attr_len, piece->attr_val, piece->attr_val_len);
			p[3 + piece->attr_len + piece->attr_val_len] = qq;
			plen[nprobes] = n;
			nprobes++;
		}
	} else {
		size_t n = 1 + piece->attr_len + 1;
		if(n >= sizeof(probes[0])) return LOM_ERR_ARG;
		probes[0][0] = ' ';
		memcpy(probes[0] + 1, piece->attr, piece->attr_len);
		probes[0][1 + piece->attr_len] = '=';
		plen[0] = n;
		nprobes = 1;
	}

	int wild = (piece->name_len == 1 && piece->name[0] == '*');
	int32_t tnid = -1;
	if(!wild) {
		tnid = find_name_id(d, piece->name, piece->name_len);
		if(tnid < 0) return LOM_OK;
	}

	size_t last_oi = (size_t)-1;
	const char *hay = d->code;
	size_t hay_n = d->code_len;
	size_t open_cursor = 0;
	/* Rough pre-size: common attrs appear on a few %% of opens. */
	if(wild && d->scan.open_count > 1024 &&
	   !grow_cap((void **)&out->items, sizeof(lom_match), &out->cap, d->scan.open_count / 8 + 64))
		return LOM_ERR_NOMEM;
	for(int pi = 0; pi < nprobes; pi++) {
		size_t pos = 0;
		size_t pn = plen[pi];
		open_cursor = 0;
		while(pos + pn <= hay_n) {
			const void *hit = lom_fss_memmem(hay + pos, hay_n - pos, probes[pi], pn);
			if(!hit) break;
			size_t hoff = (size_t)((const char *)hit - hay);
			pos = hoff + 1;
			size_t oi = open_start_tag_contains_adv(d, (int64_t)hoff, &open_cursor);
			if(oi == (size_t)-1 || oi == last_oi) continue;
			if(!wild && d->scan.opens[oi].name_id != (uint32_t)tnid) continue;
			last_oi = oi;
			/* Probe already found attr[=val] inside the covering open tag. */
			const lom_open_row *row = &d->scan.opens[oi];
			if(!match_push(out, row_open_off(d, row), row_node_end(d, row))) return LOM_ERR_NOMEM;
		}
	}
	return LOM_OK;
}

static lom_status query_structural(lom_doc *doc, const sel_chain *chain, lom_match_list *out) {
	if(chain->count == 1 && chain->pieces[0].has_attr && !chain->pieces[0].has_text) {
		lom_match_list_free(out);
		lom_match_list_init(out);
		const sel_piece *piece = &chain->pieces[0];
		int32_t anid = find_name_id(doc, piece->attr, piece->attr_len);
		int32_t tnid = find_name_id(doc, piece->name, piece->name_len);
		if(tnid < 0 && !(piece->name_len == 1 && piece->name[0] == '*')) return LOM_OK;
		/* [n]@attr: same as query_chain — n-th tag of this name, then attr check. */
		if(piece->index > 0) {
			return query_chain(doc, chain, out);
		}
		if(anid >= 0 && (size_t)anid < doc->attr_slots && doc->attr_opens && doc->attr_open_counts[anid]) {
			uint32_t *rows = doc->attr_opens[anid];
			size_t rc = doc->attr_open_counts[anid];
			int64_t last_off = -1;
			for(size_t i = 0; i < rc; i++) {
				size_t oi = rows[i];
				const lom_open_row *row = &doc->scan.opens[oi];
				int64_t ooff = row_open_off(doc, row);
				if(ooff == last_off) continue;
				last_off = ooff;
				if(!(piece->name_len == 1 && piece->name[0] == '*') && !name_eq(doc, row->name_id, piece->name, piece->name_len)) {
					continue;
				}
				if(piece->attr_eq && !row_has_attr_oi(doc, oi, piece)) continue;
				if(!match_push(out, ooff, row_node_end(doc, row))) return LOM_ERR_NOMEM;
			}
			return LOM_OK;
		}
		/* Huge-doc / no attr index:
		 * *@attr — document hits (not every open). Named@attr=rareVal — same when value is long.
		 * Named@attr presence / short values — tag-row walk (doc hits regress when dense). */
		if(!doc->code_overlay && piece->index <= 0 &&
		   ((piece->name_len == 1 && piece->name[0] == '*') ||
		    (piece->attr_eq && piece->attr_val_len >= 8))) {
			lom_status st = query_attr_via_doc_hits(doc, piece, out);
			if(st == LOM_OK) return LOM_OK;
			lom_match_list_free(out);
			lom_match_list_init(out);
		}
		return query_chain(doc, chain, out);
	}
	if(chain->count >= 2 && !chain_has_index(chain) && !chain->pieces[0].has_attr) {
		bool mid_text = false;
		for(size_t i = 0; i + 1 < chain->count; i++) if(chain->pieces[i].has_text) mid_text = true;
		if(!mid_text && !chain->pieces[chain->count - 1].has_attr) {
			/* Pure child chain A_B / A_B_C_…: parent_idx + name_id hops. */
			if(!chain_has_descendant(chain)) {
				int wild = 0;
				for(size_t i = 0; i < chain->count; i++) {
					if(chain->pieces[i].name_len == 1 && chain->pieces[i].name[0] == '*') {
						wild = 1;
						break;
					}
				}
				if(!wild) {
					lom_status st = query_pure_child_chain(doc, chain, out);
					if(st == LOM_OK) return st;
				}
			}
			/* Two-piece A__B: sequential ancestor/leaf merge. */
			if(chain->count == 2 && chain->pieces[1].descendant &&
			   !(chain->pieces[0].name_len == 1 && chain->pieces[0].name[0] == '*') &&
			   !(chain->pieces[1].name_len == 1 && chain->pieces[1].name[0] == '*')) {
				return query_descendant_merge(doc, &chain->pieces[0], &chain->pieces[1], out);
			}
			/* Three-piece A__B_C: descendant then child (region__meta_note). */
			if(chain->count == 3 && chain->pieces[1].descendant && !chain->pieces[2].descendant &&
			   !(chain->pieces[0].name_len == 1 && chain->pieces[0].name[0] == '*') &&
			   !(chain->pieces[1].name_len == 1 && chain->pieces[1].name[0] == '*') &&
			   !(chain->pieces[2].name_len == 1 && chain->pieces[2].name[0] == '*')) {
				lom_status st = query_desc_child_merge(doc, &chain->pieces[0], &chain->pieces[1],
					&chain->pieces[2], out);
				if(st == LOM_OK) return st;
			}
			/* Three-piece A__B__C: two descendant steps (region__meta__note). */
			if(chain->count == 3 && chain->pieces[1].descendant && chain->pieces[2].descendant &&
			   !(chain->pieces[0].name_len == 1 && chain->pieces[0].name[0] == '*') &&
			   !(chain->pieces[1].name_len == 1 && chain->pieces[1].name[0] == '*') &&
			   !(chain->pieces[2].name_len == 1 && chain->pieces[2].name[0] == '*')) {
				lom_status st = query_desc_desc_merge(doc, &chain->pieces[0], &chain->pieces[1],
					&chain->pieces[2], out);
				if(st == LOM_OK) return st;
			}
			/* Rare root + longer descendant chain: top-down tag-row spans. */
			if(chain_has_descendant(chain)) {
				size_t n0 = tag_row_count_for(doc, &chain->pieces[0]);
				size_t nL = tag_row_count_for(doc, &chain->pieces[chain->count - 1]);
				if(n0 > 0 && n0 <= 4096 && n0 * 64 < nL)
					return query_chain(doc, chain, out);
			}
			return query_direct_parent_walk(doc, chain, out);
		}
	}
	if(chain_has_index(chain)) {
		return query_chain(doc, chain, out);
	}
	if(chain->count == 1) {
		return query_chain(doc, chain, out);
	}
	return query_direct_parent_walk(doc, chain, out);
}

/* Regex on a path: resolve structure first when ancestors/indexes scope the leaf set
 * (e.g. region[1]__note%=/…), then verify tagvalue/attr regex only on survivors. */
static lom_status query_regex_scoped(lom_doc *doc, const sel_chain *chain, lom_match_list *out) {
	sel_chain st = *chain;
	sel_piece *leaf = &st.pieces[st.count - 1];
	int regex_id = leaf->regex_id;
	leaf->has_regex = false;
	leaf->regex_id = -1;

	lom_match_list base;
	lom_match_list_init(&base);
	lom_status st_s = query_structural(doc, &st, &base);
	if(st_s != LOM_OK) {
		lom_match_list_free(&base);
		return st_s;
	}

	lom_match_list_free(out);
	lom_match_list_init(out);
	if(base.count == 0) {
		lom_match_list_free(&base);
		return LOM_OK;
	}

	const sel_piece *piece = &chain->pieces[chain->count - 1];
	if(regex_id < 0 || (size_t)regex_id >= chain->regex_count) {
		lom_match_list_free(&base);
		return LOM_ERR_PARSE;
	}
	pcre2_code *re = compile_sel_regex(&chain->regexes[regex_id]);
	if(!re) { lom_match_list_free(&base); return LOM_ERR_PARSE; }
	pcre2_match_data *md = pcre2_match_data_create_from_pattern(re, NULL);
	if(!md) { pcre2_code_free(re); lom_match_list_free(&base); return LOM_ERR_NOMEM; }
	pcre2_match_context *mctx = sel_regex_match_ctx();
	if(!mctx) {
		pcre2_match_data_free(md);
		pcre2_code_free(re);
		lom_match_list_free(&base);
		return LOM_ERR_NOMEM;
	}

	if(!grow_cap((void **)&out->items, sizeof(lom_match), &out->cap, base.count)) {
		pcre2_match_context_free(mctx);
		pcre2_match_data_free(md);
		pcre2_code_free(re);
		lom_match_list_free(&base);
		return LOM_ERR_NOMEM;
	}
	for(size_t i = 0; i < base.count; i++) {
		size_t oi = find_open_index(doc, base.items[i].offset);
		if(oi == (size_t)-1) continue;
		if(!piece_matches_open(doc, oi, piece, chain, re, md, mctx)) continue;
		if(!match_push(out, base.items[i].offset, base.items[i].end_off)) {
			pcre2_match_context_free(mctx);
			pcre2_match_data_free(md);
			pcre2_code_free(re);
			lom_match_list_free(&base);
			return LOM_ERR_NOMEM;
		}
	}
	lom_match_list_free(&base);
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
	pcre2_match_context_free(mctx);
	pcre2_match_data_free(md);
	pcre2_code_free(re);
	return LOM_OK;
}

static lom_status lom_doc_get_uncached(lom_doc *doc, const char *selector, lom_match_list *out) {
	sel_chain chain;
	if(!parse_selector(selector, &chain)) return LOM_ERR_PARSE;

	if(chain.count >= 1 && chain.pieces[chain.count - 1].has_regex) {
		/* Indexed / rare-root paths: structure first (region[1]__note%=/… → 40, not all notes).
		 * Broad unindexed A__B%=/… keeps document literal scan on the leaf tag. */
		if(chain.count >= 2) {
			int scope = chain_has_index(&chain);
			if(!scope && chain.pieces[0].index <= 0) {
				size_t n0 = tag_row_count_for(doc, &chain.pieces[0]);
				size_t nL = tag_row_count_for(doc, &chain.pieces[chain.count - 1]);
				if(n0 > 0 && n0 <= 4096 && n0 * 64 < nL) scope = 1;
			}
			if(scope) return query_regex_scoped(doc, &chain, out);
		}
		return query_regex_leaf(doc, &chain, out);
	}

	return query_structural(doc, &chain, out);
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

/* Count live opens for a simple single-tag / '*' / tag@attr selector without building matches. */
static int count_simple_tag(lom_doc *d, const sel_piece *piece, size_t *out_n) {
	if(piece->index > 0 || piece->has_text || piece->has_regex)
		return 0;
	size_t n = 0;
	if(piece->name_len == 1 && piece->name[0] == '*') {
		if(piece->has_attr) return 0; /* *@attr uses doc-hit path via fallback */
		size_t oc = d->scan.open_count;
		for(size_t i = 0; i < oc; i++) {
			if(row_is_dead(&d->scan.opens[i])) continue;
			n++;
		}
		*out_n = n;
		return 1;
	}
	int32_t nid = find_name_id(d, piece->name, piece->name_len);
	if(nid < 0) {
		*out_n = 0;
		return 1;
	}
	uint32_t *rows = d->tag_rows[nid];
	size_t rc = d->tag_row_counts[nid];
	int nobias = !d->off_bias_delta;
	for(size_t i = 0; i < rc; i++) {
		size_t oi = decode_open_idx(d, rows[i]);
		if(oi >= d->scan.open_count) continue;
		const lom_open_row *row = &d->scan.opens[oi];
		if(row_is_dead(row)) continue;
		if(piece->has_attr) {
			if(!d->code_overlay && nobias &&
			   !(row->self_closing & (LOM_OPEN_ABS | LOM_OPEN_NE_OVF))) {
				int64_t ooff = row->open_off;
				size_t tlen = (size_t)row->tag_end_rel + 1;
				if((size_t)ooff + tlen <= d->code_len) {
					if(!tag_span_has_attr(d->code + (size_t)ooff, tlen, piece)) continue;
				} else if(!row_has_attr_oi(d, oi, piece)) {
					continue;
				}
			} else if(!row_has_attr_oi(d, oi, piece)) {
				continue;
			}
		}
		n++;
	}
	for(size_t pi = 0; pi < d->tag_pend_n; pi++) {
		if(d->tag_pend_name[pi] != (uint32_t)nid) continue;
		size_t oi = d->tag_pend_oi[pi];
		if(oi >= d->scan.open_count || row_is_dead(&d->scan.opens[oi])) continue;
		if(piece->has_attr && !row_has_attr_oi(d, oi, piece)) continue;
		n++;
	}
	*out_n = n;
	return 1;
}

lom_status lom_doc_count(lom_doc *doc, const char *selector, size_t *out_count) {
	if(!doc || !selector || !out_count) return LOM_ERR_ARG;
	*out_count = 0;
	if(doc->status != LOM_OK) return doc->status;
	lom_status est = doc_ensure_aux(doc);
	if(est != LOM_OK) return est;

	sel_chain chain;
	if(!parse_selector(selector, &chain)) return LOM_ERR_PARSE;
	if(chain.count == 1 && count_simple_tag(doc, &chain.pieces[0], out_count))
		return LOM_OK;

	lom_match_list m;
	lom_match_list_init(&m);
	lom_status st = lom_doc_get_uncached(doc, selector, &m);
	if(st == LOM_OK) *out_count = m.count;
	lom_match_list_free(&m);
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
		int32_t pidx32 = row_parent(doc, &doc->scan.opens[idx]);
		if(pidx32 < 0) continue;
		int64_t parent = row_open_off(doc, &doc->scan.opens[pidx32]);
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
		match_push(out, parent, row_node_end(doc, &doc->scan.opens[pidx32]));
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
	if(delta == 0 || !s->open_count) return;
	/* First open with open_off >= from (document order). */
	size_t lo = 0, hi = s->open_count;
	while(lo < hi) {
		size_t mid = lo + (hi - lo) / 2;
		if(s->opens[mid].open_off < from) lo = mid + 1;
		else hi = mid;
	}
	size_t first = lo;
	/* Ancestors that contain `from` need node_end/tag_end shifted — O(depth), not O(n). */
	if(first > 0) {
		int32_t p = (int32_t)(first - 1);
		int64_t pend = lom_open_node_end(s, &s->opens[p]);
		if(!(s->opens[p].open_off < from && pend >= from)) {
			p = -1;
			for(size_t j = first; j > 0; j--) {
				const lom_open_row *r = &s->opens[j - 1];
				int64_t nend = lom_open_node_end(s, r);
				if(r->open_off <= from && nend >= from) {
					p = (int32_t)(j - 1);
					break;
				}
				if(nend < from && r->open_off + 4096 < from) break;
			}
		}
		while(p >= 0) {
			lom_open_row *row = &s->opens[p];
			/* Insert inside this open tag → widen tag_end_rel. */
			if(row->open_off < from &&
			   row->open_off + (int64_t)row->tag_end_rel >= from) {
				uint32_t tr = (uint32_t)row->tag_end_rel + (uint32_t)delta;
				row->tag_end_rel = (tr > 65535u) ? (uint16_t)65535u : (uint16_t)tr;
			}
			int64_t nend = lom_open_node_end(s, row);
			if(nend >= from) (void)lom_open_set_node_end(s, row, nend + delta);
			p = row->parent_idx;
		}
	}
	for(size_t i = first; i < s->open_count; i++) {
		lom_open_row *row = &s->opens[i];
		row->open_off += delta;
		/* tag_end_rel / node_end_rel unchanged — absolute ends move with open_off. */
		/* Sync then drop rewritten pages — dirty MAP_SHARED pages otherwise stay pinned. */
		if(s->opens_is_mmap && i > first && (i & 65535u) == 65535u) {
			size_t chunk = i > 65535u ? i - 65535u : first;
			if(chunk < first) chunk = first;
			opens_drop_rows(s, chunk, i + 1, 0);
		}
	}
	if(s->opens_is_mmap && first < s->open_count)
		opens_drop_rows(s, first, s->open_count, 0);
	for(size_t i = 0; i < s->attr_count; i++) {
		if(s->attrs[i].open_off >= from) s->attrs[i].open_off += delta;
	}
}

/* True when remove/insert cannot change tag structure: no '<' and no open starts in the removed span. */
static int splice_is_structure_preserving(const lom_doc *d, size_t at, size_t remove_len,
	const char *insert, size_t insert_len) {
	if(span_has_lt(insert, insert_len)) return 0;
	if(remove_len) {
		char scratch[4096];
		const char *span = doc_span(d, at, remove_len, scratch, sizeof(scratch));
		if(span) {
			if(span_has_lt(span, remove_len)) return 0;
		} else {
			for(size_t i = 0; i < remove_len; i++) if(doc_byte(d, at + i) == '<') return 0;
		}
	}
	int64_t lo = (int64_t)at;
	int64_t hi = (int64_t)(at + remove_len);
	/* Binary search: any open starting inside [lo,hi)? */
	size_t L = 0, R = d->scan.open_count;
	while(L < R) {
		size_t mid = L + (R - L) / 2;
		if(d->scan.opens[mid].open_off < lo) L = mid + 1;
		else R = mid;
	}
	if(L < d->scan.open_count && d->scan.opens[L].open_off < hi) return 0;
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

/* Complete-subtree remove: deleting exactly one element's [open, node_end] span. */
static int splice_remove_is_complete(const lom_doc *d, int64_t lo, int64_t hi) {
	if(hi <= lo) return 0;
	size_t idx = find_open_index(d, lo);
	if(idx == (size_t)-1) return 0;
	const lom_open_row *row = &d->scan.opens[idx];
	if(row_is_dead(row)) return 0;
	if(row_open_off(d, row) != lo) return 0;
	if(row_node_end(d, row) != hi - 1) return 0;
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
	/* Head: rare parent≥insert_at. Tail: parents that also shift. */
	for(size_t i = 0; i < insert_at && i < s->open_count; i++) {
		int32_t p = s->opens[i].parent_idx;
		if(p >= 0 && (size_t)p >= insert_at)
			s->opens[i].parent_idx = (int32_t)((size_t)p + n_new);
	}
	for(size_t i = insert_at; i < s->open_count; i++) {
		int32_t p = s->opens[i].parent_idx;
		if(p >= 0 && (size_t)p >= insert_at)
			s->opens[i].parent_idx = (int32_t)((size_t)p + n_new);
	}
}

/* Markup splice: drop opens in the removed span, scan only the insert, merge rows, rebuild CSR. */
/* Grow open table; keep file-backed mmap when already mmap'd (no multi-GB heap promote). */
static bool doc_opens_ensure(lom_doc *d, size_t need) {
	lom_scan_result *r = &d->scan;
	if(need <= r->open_cap) return true;
	size_t ncap = r->open_cap ? r->open_cap : 64;
	while(ncap < need) ncap *= 2;
	size_t nbytes = ncap * sizeof(lom_open_row);
	if(r->opens_is_mmap && r->opens_fd >= 0) {
		if(ftruncate(r->opens_fd, (off_t)nbytes) != 0) return false;
		void *np = mremap(r->opens, r->opens_map_bytes ? r->opens_map_bytes : r->open_cap * sizeof(lom_open_row),
			nbytes, MREMAP_MAYMOVE);
		if(np == MAP_FAILED) return false;
		r->opens = np;
		r->opens_map_bytes = nbytes;
		r->open_cap = ncap;
		return true;
	}
	return grow_cap((void **)&r->opens, sizeof(lom_open_row), &r->open_cap, need);
}

#ifndef LOM_OPEN_APPEND_TAIL
#define LOM_OPEN_APPEND_TAIL ((size_t)64 * 1024 * 1024) /* append when memmove would touch ≥64MB */
#endif

/* In-place hole for n_new rows (keeps head pages cold vs full tempfile rewrite).
 * Early inserts (huge tail): append out-of-order at end — no memmove. */
static bool doc_opens_insert_inplace(lom_doc *d, size_t insert_at,
	const lom_open_row *new_rows, size_t n_new) {
	lom_scan_result *r = &d->scan;
	if(!n_new || !new_rows) return true;
	if(!doc_opens_ensure(d, r->open_count + n_new)) return false;

	size_t tail = r->open_count - insert_at;
	size_t tail_bytes = tail * sizeof(lom_open_row);
	if(tail_bytes >= LOM_OPEN_APPEND_TAIL && insert_at < r->open_count) {
		/* Keep [0, open_sorted_n) ordered; append new rows at end. No idx_bias. */
		if(!d->open_sorted_n || d->open_sorted_n > r->open_count)
			d->open_sorted_n = r->open_count;
		/* Rewire internal parent refs from insert_at-base to append-base. */
		size_t base = r->open_count;
		for(size_t i = 0; i < n_new; i++) {
			lom_open_row row = new_rows[i];
			if(row.parent_idx >= (int32_t)insert_at &&
			   row.parent_idx < (int32_t)(insert_at + n_new) &&
			   (row.self_closing & LOM_OPEN_ABS)) {
				row.parent_idx = (int32_t)(base + (size_t)(row.parent_idx - (int32_t)insert_at));
			}
			r->opens[base + i] = row;
		}
		r->open_count = base + n_new;
		return true;
	}

	/* Defer parent_idx bump — avoids faulting the whole table before memmove.
	 * ABS new rows already store logical parents; non-ABS keep pre-bias values. */
	if(d->idx_bias_delta && d->idx_bias_from != insert_at)
		doc_flush_idx_bias(d);
	if(!d->idx_bias_delta) d->idx_bias_from = insert_at;
	d->idx_bias_delta += n_new;
	if(tail) {
		memmove(r->opens + insert_at + n_new, r->opens + insert_at,
			tail * sizeof(lom_open_row));
	}
	memcpy(r->opens + insert_at, new_rows, n_new * sizeof(lom_open_row));
	r->open_count += n_new;
	d->open_sorted_n = r->open_count;
	/* Memmove dirtied [insert_at, open_count); drop those pages (head stays cold).
	 * MAP_SHARED MADV_DONTNEED writebacks dirty pages on this kernel — no MS_SYNC. */
	opens_drop_rows(r, insert_at, r->open_count, 0);
	return true;
}

/* One-pass opens rewrite: head | new_rows | tail — avoids in-place memmove of multi-GB tables. */
static bool doc_opens_rewrite_merge(lom_doc *d, size_t insert_at, size_t n_rm,
	const lom_open_row *new_rows, size_t n_new) {
	lom_scan_result *r = &d->scan;
	doc_flush_idx_bias(d);
	size_t new_count = r->open_count - n_rm + n_new;
	size_t ncap = r->open_cap ? r->open_cap : 64;
	while(ncap < (new_count ? new_count : 1)) ncap *= 2;
	size_t nbytes = ncap * sizeof(lom_open_row);

	char tmpl[512];
	snprintf(tmpl, sizeof(tmpl), "%s/lom_opens_XXXXXX", doc_code_tmpdir());
	int nfd = mkstemp(tmpl);
	if(nfd < 0) {
		snprintf(tmpl, sizeof(tmpl), "/var/tmp/lom_opens_XXXXXX");
		nfd = mkstemp(tmpl);
	}
	if(nfd < 0) return false;
	unlink(tmpl);
	if(ftruncate(nfd, (off_t)nbytes) != 0) {
		close(nfd);
		return false;
	}
	void *np = mmap(NULL, nbytes, PROT_READ | PROT_WRITE, MAP_SHARED, nfd, 0);
	if(np == MAP_FAILED) {
		close(nfd);
		return false;
	}
	lom_open_row *dst = (lom_open_row *)np;
	size_t o = 0;
	/* Materialize logical offsets into the new table, then drop deferred bias.
	 * New rows are stored as logical; head/tail must match or queries miss inserts. */
	for(size_t i = 0; i < insert_at; i++) {
		lom_open_row row = r->opens[i];
		int64_t ooff = row_open_off(d, &r->opens[i]);
		int64_t tend = row_tag_end(d, &r->opens[i]);
		int64_t nend = row_node_end(d, &r->opens[i]);
		row.open_off = ooff;
		row.self_closing = (uint8_t)(row.self_closing & (LOM_OPEN_SC | LOM_OPEN_DEAD));
		(void)lom_open_set_node_end(r, &row, nend);
		lom_open_set_tag_end(&row, tend);
		dst[o++] = row;
	}
	if(n_new && new_rows) {
		for(size_t i = 0; i < n_new; i++) {
			lom_open_row row = new_rows[i];
			/* Fragment rows are small; keep relative ends, drop ABS. */
			row.self_closing = (uint8_t)(row.self_closing & (LOM_OPEN_SC | LOM_OPEN_DEAD));
			dst[o++] = row;
		}
	}
	size_t tail_from = insert_at + n_rm;
	size_t tail_n = r->open_count - tail_from;
	for(size_t i = 0; i < tail_n; i++) {
		lom_open_row row = r->opens[tail_from + i];
		int64_t ooff = row_open_off(d, &r->opens[tail_from + i]);
		int64_t tend = row_tag_end(d, &r->opens[tail_from + i]);
		int64_t nend = row_node_end(d, &r->opens[tail_from + i]);
		row.open_off = ooff;
		row.self_closing = (uint8_t)(row.self_closing & (LOM_OPEN_SC | LOM_OPEN_DEAD));
		(void)lom_open_set_node_end(r, &row, nend);
		lom_open_set_tag_end(&row, tend);
		dst[o++] = row;
	}
	/* Attr open_off values must match materialized opens. */
	if(d->off_bias_delta) {
		int64_t bf = d->off_bias_from, bd = d->off_bias_delta;
		for(size_t i = 0; i < r->attr_count; i++) {
			if(r->attrs[i].open_off >= bf) r->attrs[i].open_off += bd;
		}
	}
	d->off_bias_from = 0;
	d->off_bias_delta = 0;

	if(r->opens_is_mmap) {
		munmap(r->opens, r->opens_map_bytes ? r->opens_map_bytes : r->open_cap * sizeof(lom_open_row));
		if(r->opens_fd >= 0) close(r->opens_fd);
	} else {
		free(r->opens);
	}
	r->opens = dst;
	r->opens_fd = nfd;
	r->opens_map_bytes = nbytes;
	r->opens_is_mmap = 1;
	r->open_cap = ncap;
	r->open_count = new_count;
	opens_drop_rows(r, 0, new_count, 0);
	return true;
}

static lom_status doc_splice_local_structure(lom_doc *d, size_t at, size_t remove_len,
	const char *insert, size_t insert_len) {
	/* Do not flush deferred byte bias here — that would fault the whole open table. */
	if(!d->scan.opens_is_mmap && !doc_opens_to_heap(d)) return LOM_ERR_NOMEM;
	int64_t lo = (int64_t)at;
	int64_t hi = (int64_t)(at + remove_len);
	int64_t raw_lo = lo;
	if(d->off_bias_delta) {
		if(lo >= d->off_bias_from + d->off_bias_delta) raw_lo = lo - d->off_bias_delta;
		else if(lo >= d->off_bias_from) raw_lo = d->off_bias_from;
	}
	if(remove_len && !splice_remove_is_complete(d, lo, hi)) return LOM_ERR_PARSE;

	int32_t parent_of_frag = -1;
	{
		/* Innermost open containing splice point `at` (logical). */
		size_t sorted_n = opens_sorted_count(d);
		size_t i = 0, h = sorted_n;
		while(i < h) {
			size_t mid = i + (h - i) / 2;
			if(row_open_off(d, &d->scan.opens[mid]) <= (int64_t)at) i = mid + 1;
			else h = mid;
		}
		for(ssize_t j = (ssize_t)i - 1; j >= 0; j--) {
			const lom_open_row *r = &d->scan.opens[j];
			if(row_is_dead(r)) continue;
			if(row_open_off(d, r) <= (int64_t)at && row_node_end(d, r) >= (int64_t)at) {
				parent_of_frag = (int32_t)j;
				break;
			}
		}
		/* Also consider appended opens that may contain `at` (rare). */
		for(size_t j = sorted_n; j < d->scan.open_count; j++) {
			const lom_open_row *r = &d->scan.opens[j];
			if(row_is_dead(r)) continue;
			if(row_open_off(d, r) <= (int64_t)at && row_node_end(d, r) >= (int64_t)at) {
				if(parent_of_frag < 0 ||
				   row_open_off(d, r) >= row_open_off(d, &d->scan.opens[parent_of_frag]))
					parent_of_frag = (int32_t)j;
			}
		}
	}

	size_t sorted_n = opens_sorted_count(d);
	size_t insert_at = 0, h2 = sorted_n;
	while(insert_at < h2) {
		size_t mid = insert_at + (h2 - insert_at) / 2;
		if(row_open_off(d, &d->scan.opens[mid]) < (int64_t)at) insert_at = mid + 1;
		else h2 = mid;
	}
	size_t rm_lo = insert_at, rm_hi = insert_at;
	int found_rm = 0;
	while(rm_hi < sorted_n && row_open_off(d, &d->scan.opens[rm_hi]) < (int64_t)(at + remove_len)) {
		found_rm = 1;
		rm_hi++;
	}
	/* Appended early inserts live past open_sorted_n. */
	if(!found_rm && sorted_n < d->scan.open_count) {
		rm_lo = (size_t)-1;
		rm_hi = 0;
		for(size_t i = sorted_n; i < d->scan.open_count; i++) {
			int64_t o = row_open_off(d, &d->scan.opens[i]);
			if(o < (int64_t)at || o >= (int64_t)(at + remove_len)) continue;
			if(rm_lo == (size_t)-1) rm_lo = i;
			if(i + 1 > rm_hi) rm_hi = i + 1;
			found_rm = 1;
		}
		/* Require a dense [rm_lo, rm_hi) of in-range opens (typical fragment append). */
		if(found_rm) {
			for(size_t i = rm_lo; i < rm_hi; i++) {
				int64_t o = row_open_off(d, &d->scan.opens[i]);
				if(o < (int64_t)at || o >= (int64_t)(at + remove_len)) {
					found_rm = 0;
					break;
				}
			}
		}
	} else if(found_rm) {
		rm_lo = insert_at;
	}
	size_t n_rm = found_rm ? (rm_hi - rm_lo) : 0;

	/* Large-doc delete of a complete subtree: tombstone opens + byte bias.
	 * Avoids rewriting / faulting the entire open table (was ~10s on 1GB). */
	if(n_rm && !insert_len && d->scan.open_count >= (size_t)1000000) {
		for(size_t i = rm_lo; i < rm_hi; i++)
			d->scan.opens[i].self_closing = LOM_OPEN_DEAD;
		if(!doc_code_splice_bytes(d, at, remove_len, "", 0)) return LOM_ERR_NOMEM;
		int64_t delta = -(int64_t)remove_len;
		if(delta && d->scan.opens_is_mmap) {
			/* Compose with existing byte bias — never full flush on tombstone delete. */
			if(d->off_bias_delta && d->off_bias_from != (int64_t)at) {
				int64_t T = d->off_bias_from;
				int64_t raw_at = (int64_t)at;
				if(raw_at >= T + d->off_bias_delta) raw_at -= d->off_bias_delta;
				else if(raw_at >= T) raw_at = T;
				size_t a = 0, h = d->scan.open_count;
				while(a < h) {
					size_t mid = a + (h - a) / 2;
					if(d->scan.opens[mid].open_off < T) a = mid + 1;
					else h = mid;
				}
				size_t b = a, h3 = d->scan.open_count;
				while(b < h3) {
					size_t mid = b + (h3 - b) / 2;
					if(d->scan.opens[mid].open_off < raw_at) b = mid + 1;
					else h3 = mid;
				}
				for(size_t i = a; i < b; i++) {
					if(row_is_dead(&d->scan.opens[i])) continue;
					if(d->scan.opens[i].self_closing & LOM_OPEN_ABS) continue;
					d->scan.opens[i].open_off -= delta;
					/* node_end_rel tracks open_off — no absolute bump. */
				}
			}
			if(!d->off_bias_delta) d->off_bias_from = (int64_t)at;
			d->off_bias_delta += delta;
		} else if(delta) {
			if(d->off_bias_delta) doc_flush_off_bias(d);
			shift_scan_offsets(&d->scan, (int64_t)at, delta);
		}
		if(!doc_aux_patch_tombstones(d, rm_lo, rm_hi))
			d->aux_dirty = 1;
		doc_rebuild_pieces(d);
		if(d->fcache) lom_fcache_clear(d->fcache);
		d->status = LOM_OK;
		d->error[0] = 0;
		return LOM_OK;
	}

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

	int64_t delta = (int64_t)insert_len - (int64_t)remove_len;
	if(d->scan.opens_is_mmap && d->scan.open_count >= (size_t)1000000) {
		if(d->off_bias_delta && delta) {
			/* Compose with existing bias: opens in [bias_from, raw_lo) must not get the new delta.
			 * Adjust their stored raw offsets so logical stays correct after bias_delta += delta. */
			int64_t T = d->off_bias_from;
			size_t sorted_n = opens_sorted_count(d);
			size_t a = 0, h = sorted_n;
			while(a < h) {
				size_t mid = a + (h - a) / 2;
				if(d->scan.opens[mid].open_off < T) a = mid + 1;
				else h = mid;
			}
			size_t b = a, h3 = sorted_n;
			while(b < h3) {
				size_t mid = b + (h3 - b) / 2;
				if(d->scan.opens[mid].open_off < raw_lo) b = mid + 1;
				else h3 = mid;
			}
			for(size_t i = a; i < b; i++) {
				d->scan.opens[i].open_off -= delta;
				/* node_end_rel tracks open_off — no absolute bump. */
			}
			/* Appended rows store ABS logical offs — leave them alone. */
		}
		if(!d->off_bias_delta) d->off_bias_from = (int64_t)at;
		d->off_bias_delta += delta;
		/* Parent extents grow via bias on node_end/tag_end (>= at); no stored bump. */
	} else {
		if(d->off_bias_delta) doc_flush_off_bias(d);
		else doc_flush_idx_bias(d);
		shift_scan_offsets(&d->scan, (int64_t)at, delta);
	}

	/* Merge fragment opens */
	size_t n_new = frag.open_count;
	lom_open_row *new_rows = NULL;
	if(n_new || n_rm) {
		if(n_new) {
			new_rows = calloc(n_new, sizeof(lom_open_row));
			if(!new_rows) {
				lom_scan_result_free(&frag);
				return LOM_ERR_NOMEM;
			}
			for(size_t i = 0; i < n_new; i++) {
				lom_open_row row = frag.opens[i];
				/* Absolute logical offsets (ABS): valid under active byte bias. */
				row.open_off = (int64_t)at + row.open_off;
				/* tag_end_rel / node_end_rel unchanged; absolute ends = open_off + rel. */
				row.self_closing = (uint8_t)((row.self_closing & LOM_OPEN_SC) | LOM_OPEN_ABS);
				const char *nm = lom_scan_string(&frag, row.name_id);
				uint32_t nid;
				if(!scan_string_intern(&d->scan, nm, strlen(nm), &nid)) {
					free(new_rows);
					lom_scan_result_free(&frag);
					return LOM_ERR_NOMEM;
				}
				row.name_id = nid;
				if(row.parent_idx < 0) row.parent_idx = parent_of_frag;
				else row.parent_idx = (int32_t)(insert_at + (size_t)row.parent_idx);
				new_rows[i] = row;
			}
		}
		if(d->scan.open_count >= (size_t)1000000 && n_new && !n_rm) {
			/* Pure insert: in-place hole or early-append — no full opens tempfile rewrite. */
			if(!doc_opens_insert_inplace(d, insert_at, new_rows, n_new)) {
				free(new_rows);
				lom_scan_result_free(&frag);
				return LOM_ERR_NOMEM;
			}
		} else if(d->scan.opens_is_mmap && d->scan.open_count >= (size_t)1000000) {
			/* Remap parents for the index splice before rewriting the table. */
			doc_flush_idx_bias(d);
			d->open_sorted_n = d->scan.open_count; /* rewrite produces fully sorted */
			if(n_rm) remap_parent_after_remove(&d->scan, rm_lo, rm_hi);
			if(n_new) remap_parent_after_insert(&d->scan, insert_at, n_new);
			if(!doc_opens_rewrite_merge(d, insert_at, n_rm, new_rows, n_new)) {
				free(new_rows);
				lom_scan_result_free(&frag);
				return LOM_ERR_NOMEM;
			}
			d->open_sorted_n = d->scan.open_count;
		} else {
			doc_flush_idx_bias(d);
			if(n_rm) {
				size_t tail = d->scan.open_count - rm_hi;
				if(tail) memmove(d->scan.opens + rm_lo, d->scan.opens + rm_hi, tail * sizeof(lom_open_row));
				d->scan.open_count -= n_rm;
				remap_parent_after_remove(&d->scan, rm_lo, rm_hi);
			}
			if(n_new) {
				if(!doc_opens_ensure(d, d->scan.open_count + n_new)) {
					free(new_rows);
					lom_scan_result_free(&frag);
					return LOM_ERR_NOMEM;
				}
				remap_parent_after_insert(&d->scan, insert_at, n_new);
				memmove(d->scan.opens + insert_at + n_new, d->scan.opens + insert_at,
					(d->scan.open_count - insert_at) * sizeof(lom_open_row));
				memcpy(d->scan.opens + insert_at, new_rows, n_new * sizeof(lom_open_row));
				d->scan.open_count += n_new;
			}
			d->open_sorted_n = d->scan.open_count;
		}
		int appended = opens_sorted_count(d) < d->scan.open_count;
		for(size_t i = 0; i < d->scan.attr_count; i++) {
			uint32_t oi = d->scan.attrs[i].open_idx;
			if(n_rm && oi >= (uint32_t)rm_hi) d->scan.attrs[i].open_idx = oi - (uint32_t)n_rm;
			else if(n_rm && oi >= (uint32_t)rm_lo) { /* dropped */ }
			if(n_new && !appended && d->scan.attrs[i].open_idx >= (uint32_t)insert_at)
				d->scan.attrs[i].open_idx += (uint32_t)n_new;
		}
	}

	if(frag.attr_count) {
		if(!grow_cap((void **)&d->scan.attrs, sizeof(lom_attr_row), &d->scan.attr_cap, d->scan.attr_count + frag.attr_count)) {
			free(new_rows);
			lom_scan_result_free(&frag);
			return LOM_ERR_NOMEM;
		}
		int appended = opens_sorted_count(d) < d->scan.open_count;
		size_t attr_base = (appended && n_new) ? (d->scan.open_count - n_new) : insert_at;
		for(size_t i = 0; i < frag.attr_count; i++) {
			lom_attr_row a = frag.attrs[i];
			a.open_off += (int64_t)at;
			a.open_idx = (uint32_t)(attr_base + (size_t)a.open_idx);
			const char *an = lom_scan_string(&frag, a.name_id);
			const char *av = lom_scan_string(&frag, a.value_id);
			uint32_t nid, vid;
			if(!scan_string_intern(&d->scan, an, strlen(an), &nid) ||
			   !scan_string_intern(&d->scan, av, strlen(av), &vid)) {
				free(new_rows);
				lom_scan_result_free(&frag);
				return LOM_ERR_NOMEM;
			}
			a.name_id = nid;
			a.value_id = vid;
			d->scan.attrs[d->scan.attr_count++] = a;
		}
	}
	lom_scan_result_free(&frag);

	if(n_new || n_rm) {
		if(!doc_aux_patch_open_splice(d, insert_at, n_rm, n_new, new_rows))
			d->aux_dirty = 1;
	}
	free(new_rows);
	doc_rebuild_pieces(d);
	if(d->fcache) lom_fcache_clear(d->fcache);
	d->status = LOM_OK;
	d->error[0] = 0;
	return LOM_OK;
}

static lom_status doc_splice(lom_doc *d, size_t at, size_t remove_len, const char *insert, size_t insert_len) {
	int want_ov = splice_wants_overlay(d, at, remove_len, insert_len);
	/* Overlay keeps the base mapping read-only — skip MAP_PRIVATE COW of the whole file. */
	if(!want_ov && !doc_ensure_writable(d)) return LOM_ERR_NOMEM;
	if(at > d->code_len || remove_len > d->code_len - at) return LOM_ERR_ARG;
	doc_mark_dirty_at(d, at);

	if(splice_is_structure_preserving(d, at, remove_len, insert, insert_len)) {
		if(!doc_code_splice_bytes(d, at, remove_len, insert, insert_len)) return LOM_ERR_NOMEM;
		int64_t delta = (int64_t)insert_len - (int64_t)remove_len;
		/* Large mmap open tables: defer the O(#opens) rewrite — only patch ancestors
		 * and keep a byte bias for the rest (queries use row_open_off). Overlay optional. */
		if(delta == 0) {
			/* Same-size text: open offsets unchanged. */
		} else if(d->scan.opens_is_mmap && d->scan.open_count >= (size_t)1000000) {
			if(d->off_bias_delta && d->off_bias_from != (int64_t)at)
				doc_flush_off_bias(d);
			/* Bias alone updates logical tag_end/node_end for any stored offset >= at.
			 * Do not also bump ancestor rows — that double-counts with row_* helpers. */
			if(!d->off_bias_delta) d->off_bias_from = (int64_t)at;
			d->off_bias_delta += delta;
		} else {
			if(d->off_bias_delta) doc_flush_off_bias(d);
			else doc_flush_idx_bias(d);
			shift_scan_offsets(&d->scan, (int64_t)at, delta);
		}
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
	size_t start = (size_t)row_tag_end(d, row) + 1;
	size_t end = (size_t)row_node_end(d, row);
	size_t cl = end;
	while(cl > start && doc_byte(d, cl) != '<') cl--;
	if(cl <= start || doc_byte(d, cl) != '<') return false;
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
		if(row_is_dead(row)) continue;
		size_t start = (size_t)row_open_off(doc, row);
		size_t end = (size_t)row_node_end(doc, row);
		size_t len = end - start + 1;
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
		char scratch[4096];
		const char *span = doc_span(doc, is, il, scratch, sizeof(scratch));
		if(!span) {
			for(size_t i = 0; i < il; i++) buf[i] = doc_byte(doc, is + i);
		} else {
			memcpy(buf, span, il);
		}
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
	size_t start = (size_t)row_open_off(doc, row);
	size_t end = (size_t)row_tag_end(doc, row);
	size_t tag_len = end - start + 1;
	char *tag = malloc(tag_len + 1);
	if(!tag) return LOM_ERR_NOMEM;
	const char *span = doc_span(doc, start, tag_len, tag, tag_len);
	if(!span) {
		for(size_t i = 0; i < tag_len; i++) tag[i] = doc_byte(doc, start + i);
	} else if(span != tag) {
		memcpy(tag, span, tag_len);
	}
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
	lom_doc *d = (lom_doc *)doc;
	if(d->code_overlay && !doc_overlay_flatten(d)) return LOM_ERR_NOMEM;
	char tmp[1024];
	snprintf(tmp, sizeof(tmp), "%s.tmp.%d", path, (int)getpid());
	FILE *f = fopen(tmp, "wb");
	if(!f) return LOM_ERR_PARSE;
	size_t n = fwrite(d->code, 1, d->code_len, f);
	fclose(f);
	if(n != d->code_len) { remove(tmp); return LOM_ERR_PARSE; }
	if(rename(tmp, path) != 0) { remove(tmp); return LOM_ERR_PARSE; }
	return LOM_OK;
}

lom_status lom_doc_delete_offset(lom_doc *doc, int64_t open_off) {
	if(!doc) return LOM_ERR_ARG;
	size_t idx = find_open_index(doc, open_off);
	if(idx == (size_t)-1) return LOM_ERR_NOTFOUND;
	const lom_open_row *row = &doc->scan.opens[idx];
	int64_t ooff = row_open_off(doc, row);
	int64_t nend = row_node_end(doc, row);
	return doc_splice(doc, (size_t)ooff, (size_t)(nend - ooff + 1), "", 0);
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
