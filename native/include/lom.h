/* LOM native core — shared by PHP extension and standalone C tooling.
 * SPDX-License-Identifier: Apache-2.0
 */
#ifndef LOM_H
#define LOM_H

#include <stddef.h>
#include <stdint.h>
#include <stdbool.h>

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
	int64_t tag_end_off;
	int64_t parent_off;
	int64_t node_end_off;
	uint32_t name_id;
	uint8_t self_closing;
} lom_open_row;

typedef struct lom_attr_row {
	int64_t open_off;
	uint32_t name_id;
	uint32_t value_id;
} lom_attr_row;

typedef struct lom_scan_result {
	lom_open_row *opens;
	size_t open_count;
	size_t open_cap;

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
} lom_scan_result;

typedef struct lom_match {
	int64_t offset;
	int64_t end_off; /* inclusive end of node span */
} lom_match;

typedef struct lom_match_list {
	lom_match *items;
	size_t count;
	size_t cap;
} lom_match_list;

typedef struct lom_doc lom_doc;

LOM_API void lom_scan_result_init(lom_scan_result *r);
LOM_API void lom_scan_result_free(lom_scan_result *r);
LOM_API const char *lom_scan_string(const lom_scan_result *r, uint32_t id);
LOM_API lom_status lom_scan_indexes(const char *code, size_t code_len, lom_scan_result *out, bool capture_attributes);

LOM_API lom_doc *lom_doc_create(const char *code, size_t code_len);
LOM_API lom_doc *lom_doc_create_file(const char *path);
LOM_API void lom_doc_free(lom_doc *doc);
LOM_API lom_status lom_doc_status(const lom_doc *doc);
LOM_API const char *lom_doc_error(const lom_doc *doc);
LOM_API const char *lom_doc_code(const lom_doc *doc, size_t *out_len);
LOM_API size_t lom_doc_open_count(const lom_doc *doc);
LOM_API bool lom_doc_brackets_balanced(const lom_doc *doc);

LOM_API void lom_match_list_init(lom_match_list *m);
LOM_API void lom_match_list_free(lom_match_list *m);

/* Selector subset: tag chains with optional [n], tag@attr, tag@attr=val,
   and tag chains ending in =text (last tag's inner text). Use '_' between
   child steps ('__' for descendant). Tag names containing '_' must be
   encoded by the caller. '/' is reserved for regex values in the PHP API. */
LOM_API lom_status lom_doc_get(lom_doc *doc, const char *selector, lom_match_list *out);
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
LOM_API lom_status lom_doc_delete_offset(lom_doc *doc, int64_t open_off);
LOM_API lom_status lom_doc_set_inner_text_offset(lom_doc *doc, int64_t open_off, const char *text);
LOM_API lom_status lom_doc_set_child_text_offset(lom_doc *doc, int64_t open_off, const char *child_tag, const char *text);

LOM_API const char *lom_version(void);

/* Optional accelerators — see lom_fmem.h / lom_fcache.h */
#include "lom_fmem.h"
#include "lom_fcache.h"

#ifdef __cplusplus
}
#endif

#endif /* LOM_H */
