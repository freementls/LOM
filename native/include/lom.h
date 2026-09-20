/* LOM native core — shared by PHP extension and standalone C tooling.
 * SPDX-License-Identifier: Apache-2.0
 */
#ifndef LOM_H
#define LOM_H

#include <stddef.h>
#include <stdint.h>
#include <stdbool.h>
#include <stdlib.h>

#ifdef __cplusplus
extern "C" {
#endif

#define LOM_API __attribute__((visibility("default")))

typedef enum {
	LOM_OK = 0,
	LOM_ERR_ARG = 1,
	LOM_ERR_NOMEM = 2,
	LOM_ERR_PARSE = 3,
	LOM_ERR_NOTFOUND = 4
} lom_status;

typedef struct lom_open_row {
	int64_t open_off;
	uint32_t node_end_rel; /* node_end - open_off; if NE_OVF, index into scan.ne_ovf_rel[] */
	uint16_t tag_end_rel; /* tag_end - open_off (open tags are short) */
	uint8_t self_closing; /* flags: SC=1, DEAD=2, ABS=4, NE_OVF=8 */
	uint8_t _pad;
	int32_t parent_idx; /* -1 = root; index into opens[] */
	uint32_t name_id;
} lom_open_row; /* 24 bytes */

#define LOM_OPEN_SC     1u
#define LOM_OPEN_DEAD   2u
#define LOM_OPEN_ABS    4u
#define LOM_OPEN_NE_OVF 8u /* node_end_rel indexes ne_ovf_rel (span >4GiB) */

static inline int64_t lom_open_tag_end(const lom_open_row *r) {
	return r->open_off + (int64_t)r->tag_end_rel;
}

static inline void lom_open_set_tag_end(lom_open_row *r, int64_t tag_end) {
	int64_t rel = tag_end - r->open_off;
	if(rel < 0) rel = 0;
	if(rel > 65535) rel = 65535;
	r->tag_end_rel = (uint16_t)rel;
}

typedef struct lom_attr_row {
	int64_t open_off;
	uint32_t open_idx; /* index into opens[] at scan time */
	uint32_t name_id;
	uint32_t value_id;
} lom_attr_row;

typedef struct lom_scan_result {
	lom_open_row *opens;
	size_t open_count;
	size_t open_cap;
	/* File-backed opens (large docs): pages can be dropped via MADV_DONTNEED. */
	int opens_fd; /* -1 = heap */
	size_t opens_map_bytes;
	int opens_is_mmap;

	/* Rare node spans >4GiB: row.node_end_rel indexes this table when NE_OVF. */
	int64_t *ne_ovf_rel;
	size_t ne_ovf_n;
	size_t ne_ovf_cap;

	lom_attr_row *attrs;
	size_t attr_count;
	size_t attr_cap;

	char *string_blob;
	size_t string_blob_len;
	size_t string_blob_cap;
	size_t *string_offs;
	size_t string_count;
	size_t string_cap;

	size_t self_closing_count;
	lom_status status;
	char error[256];

	/* Fractal recipe: prefix rows live in opens[0, open_count). Virtual
	 * open_count is prefix + tile_n * stride (see lom_doc_open_count).
	 * k-class: leftover siblings that also tile get their own template. */
#define LOM_RECIPE_KMAX 8
	lom_open_row *recipe_tmpl; /* concatenated class templates */
	size_t recipe_tmpl_n;      /* concat length (k=1: one template) */
	size_t recipe_stride;      /* voi: prefix + tile * stride + local */
	size_t recipe_k;
	size_t recipe_k_tmpl_n[LOM_RECIPE_KMAX];
	size_t recipe_k_tmpl_off[LOM_RECIPE_KMAX];
	size_t recipe_k_tile_n[LOM_RECIPE_KMAX];
	uint8_t *recipe_tile_cls; /* tile_n bytes; NULL => all class 0 */
	uint64_t *recipe_starts;
	uint64_t *recipe_ends;
	size_t recipe_tile_n;
	int32_t recipe_root_parent;
	int recipe_owned; /* 1 => free tmpl/starts/ends/tile_cls */
	int recipe_class_n; /* recipe templates used (k), else census unique tags */
	double phase_census_ms;
	double phase_sample_ms;
} lom_scan_result;

static inline int64_t lom_open_node_end(const lom_scan_result *s, const lom_open_row *r) {
	if(r->self_closing & LOM_OPEN_NE_OVF) {
		uint32_t i = r->node_end_rel;
		if(!s || i >= s->ne_ovf_n) return r->open_off;
		return r->open_off + s->ne_ovf_rel[i];
	}
	return r->open_off + (int64_t)r->node_end_rel;
}

static inline int lom_open_set_node_end(lom_scan_result *s, lom_open_row *r, int64_t node_end) {
	int64_t rel = node_end - r->open_off;
	if(rel < 0) rel = 0;
	if(r->self_closing & LOM_OPEN_NE_OVF) {
		if(s && r->node_end_rel < s->ne_ovf_n) {
			s->ne_ovf_rel[r->node_end_rel] = rel;
			return 0;
		}
	}
	if((uint64_t)rel <= (uint64_t)UINT32_MAX) {
		r->self_closing = (uint8_t)(r->self_closing & ~LOM_OPEN_NE_OVF);
		r->node_end_rel = (uint32_t)rel;
		return 0;
	}
	if(!s) return -1;
	if(s->ne_ovf_n >= s->ne_ovf_cap) {
		size_t ncap = s->ne_ovf_cap ? s->ne_ovf_cap * 2 : 4;
		int64_t *p = (int64_t *)realloc(s->ne_ovf_rel, ncap * sizeof(int64_t));
		if(!p) return -1;
		s->ne_ovf_rel = p;
		s->ne_ovf_cap = ncap;
	}
	uint32_t idx = (uint32_t)s->ne_ovf_n++;
	s->ne_ovf_rel[idx] = rel;
	r->self_closing = (uint8_t)(r->self_closing | LOM_OPEN_NE_OVF);
	r->node_end_rel = idx;
	return 0;
}

typedef struct lom_match {
	int64_t offset;
	int64_t end_off; /* inclusive end of node span */
} lom_match;

typedef struct lom_match_list {
	lom_match *items;
	size_t count;
	size_t cap;
	int borrowed; /* 1 => items owned by fcache hold; do not free() */
	void *hold;   /* lom_fcache_hold* when borrowed */
} lom_match_list;

/* Open-index results — 4 B/hit, no offset materialization. */
typedef struct lom_oi_list {
	uint32_t *items;
	size_t count;
	size_t cap;
} lom_oi_list;

typedef struct lom_doc lom_doc;

LOM_API void lom_scan_result_init(lom_scan_result *r);
LOM_API void lom_scan_result_free(lom_scan_result *r);
LOM_API const char *lom_scan_string(const lom_scan_result *r, uint32_t id);
LOM_API lom_status lom_scan_indexes(const char *code, size_t code_len, lom_scan_result *out, bool capture_attributes);
/* After anon-mmap scan: copy opens to a tempfile map so pages can be dropped. */
LOM_API int lom_scan_opens_spill_to_file(lom_scan_result *r);

LOM_API lom_doc *lom_doc_create(const char *code, size_t code_len);
LOM_API lom_doc *lom_doc_create_file(const char *path);
LOM_API void lom_doc_free(lom_doc *doc);
LOM_API lom_status lom_doc_status(const lom_doc *doc);
LOM_API const char *lom_doc_error(const lom_doc *doc);
LOM_API const char *lom_doc_code(const lom_doc *doc, size_t *out_len);
LOM_API size_t lom_doc_open_count(const lom_doc *doc);
/* 1 if create_file loaded path.lomidx (mmap sidecar), else 0. */
LOM_API int lom_doc_from_sidecar(const lom_doc *doc);
/* 1 if this doc uses a fractal tile recipe (no wholesale open table). */
LOM_API int lom_doc_recipe_active(const lom_doc *doc);
/* Recipe templates in use (k-class); 1 = single template. */
LOM_API int lom_doc_recipe_class_count(const lom_doc *doc);
/* Construct phase clocks in milliseconds (0 if not measured). */
LOM_API void lom_doc_construct_phases(const lom_doc *doc,
	double *census_ms, double *sample_ms, double *persist_ms);
LOM_API bool lom_doc_brackets_balanced(const lom_doc *doc);

LOM_API void lom_match_list_init(lom_match_list *m);
LOM_API void lom_match_list_free(lom_match_list *m);
LOM_API void lom_oi_list_init(lom_oi_list *m);
LOM_API void lom_oi_list_free(lom_oi_list *m);

/* Selector subset: tag chains with optional [n], tag@attr, tag@attr=val,
   tag chains ending in =text, and comparison ops with /pattern/flags
   (PCRE2; '=' means full-span match). Use '_' between child steps
   ('__' for descendant, '\'' parent, '"' ancestor). [$] is last
   same-name sibling. Leading #sel is count sugar (not a match list).
   Tag names containing '_' must be encoded ({underscore} or #underscore#).
   '/' after a comparison op starts a regex literal. */
LOM_API lom_status lom_doc_get(lom_doc *doc, const char *selector, lom_match_list *out);
/* Match count without allocating offset pairs — prefer this for broad `$count` /
 * cardinality checks (e.g. `*` / `note` on GB docs). */
LOM_API lom_status lom_doc_count(lom_doc *doc, const char *selector, size_t *out_count);
/* Sum / mean of tagless inner text. Empty average is LOM_ERR_NOTFOUND. */
LOM_API lom_status lom_doc_sum(lom_doc *doc, const char *selector, double *out);
LOM_API lom_status lom_doc_average(lom_doc *doc, const char *selector, double *out);
/* Internal query currency: open indices (uint32). Writes, vars, context, unions,
 * and count use this. lom_doc_get() materializes offset pairs from these ois. */
LOM_API lom_status lom_doc_get_ois(lom_doc *doc, const char *selector, lom_oi_list *out);
/* Resolve one open index to a match (applies overlay / offset bias). */
LOM_API lom_status lom_doc_oi_match(const lom_doc *doc, uint32_t oi, lom_match *out);
LOM_API lom_status lom_doc_get_parent(lom_doc *doc, const char *selector, lom_match_list *out);
LOM_API lom_status lom_doc_node_slice(const lom_doc *doc, int64_t open_off, const char **ptr, size_t *len);

LOM_API lom_status lom_doc_set_inner_text(lom_doc *doc, const char *selector, const char *text);
LOM_API lom_status lom_doc_new_before_close(lom_doc *doc, const char *parent_selector, const char *fragment);
LOM_API lom_status lom_doc_delete(lom_doc *doc, const char *selector);

/* Row helpers for tabular / OData projection */
LOM_API lom_status lom_doc_get_attr(const lom_doc *doc, int64_t open_off, const char *attr, char *buf, size_t buflen);
LOM_API lom_status lom_doc_child_text(const lom_doc *doc, int64_t open_off, const char *child_tag, char *buf, size_t buflen);
LOM_API lom_status lom_doc_set_attr(lom_doc *doc, int64_t open_off, const char *attr, const char *value);
LOM_API lom_status lom_doc_ensure_ids(lom_doc *doc, const char *row_selector, const char *id_attr);
LOM_API lom_status lom_doc_save_file(const lom_doc *doc, const char *path);
/* Persist in-memory overlay / same-size edits beside the XML (path.lomwal). */
LOM_API lom_status lom_doc_wal_persist(lom_doc *doc);
LOM_API lom_status lom_doc_wal_load(lom_doc *doc);
/* Flatten overlay, rewrite XML once, drop WAL, refresh sidecar. */
LOM_API lom_status lom_doc_checkpoint(lom_doc *doc, const char *path);
LOM_API int lom_doc_wal_pending(const lom_doc *doc);

/* Conversational context + living named selections (native). */
LOM_API void lom_doc_clear_context(lom_doc *doc);
LOM_API lom_status lom_doc_var_set(lom_doc *doc, const char *name, const char *selector);
LOM_API lom_status lom_doc_var_get(lom_doc *doc, const char *name, lom_match_list *out);

/* Compatibility facades — compile a subset to a LOM selector. */
LOM_API lom_status lom_xpath_to_lom(const char *xpath, char *out, size_t out_cap);
LOM_API lom_status lom_css_to_lom(const char *css, char *out, size_t out_cap);
LOM_API lom_status lom_doc_get_xpath(lom_doc *doc, const char *xpath, lom_match_list *out);
LOM_API lom_status lom_doc_get_css(lom_doc *doc, const char *css, lom_match_list *out);
LOM_API lom_status lom_doc_delete_offset(lom_doc *doc, int64_t open_off);
LOM_API lom_status lom_doc_set_inner_text_offset(lom_doc *doc, int64_t open_off, const char *text);
LOM_API lom_status lom_doc_set_child_text_offset(lom_doc *doc, int64_t open_off, const char *child_tag, const char *text);

LOM_API const char *lom_version(void);

/* Optional accelerators — see lom_fmem.h / lom_fcache.h / lom_fstr.h */
#include "lom_fmem.h"
#include "lom_fcache.h"
#include "lom_fstr.h"

#ifdef __cplusplus
}
#endif

#endif /* LOM_H */
