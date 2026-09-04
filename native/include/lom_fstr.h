/* SPDX-License-Identifier: Apache-2.0
 * lom_fstr — fractal string: hierarchical, multi-scale view of a byte string.
 *
 * Instead of only a flat C[0..n), an fstr is a tree of spans aligned to
 * structural opens (and tag-aligned blocks inside large children). Each node
 * carries a content signature (byte presence + 3-gram bloom) so cold searches
 * can skip subtrees that cannot contain a needle without scanning them.
 *
 * Dimensions: (level, sibling index, byte offset). Fractal: the same
 * signature algebra works at document, element, and block scales.
 */
#ifndef LOM_FSTR_H
#define LOM_FSTR_H

#include "lom.h"

#include <stddef.h>
#include <stdint.h>
#include <sys/types.h>

#ifdef __cplusplus
extern "C" {
#endif

typedef struct lom_fstr lom_fstr;

typedef struct lom_fstr_node {
	int64_t start;
	int64_t end; /* exclusive */
	int32_t parent; /* -1 = root */
	uint32_t child_begin; /* index into fstr->child_at */
	uint32_t child_count;
	uint32_t open_idx; /* scan open index, or UINT32_MAX if synthetic */
	uint8_t presence[32]; /* 256-bit byte presence of this span */
	uint64_t bloom[4]; /* 256-bit 3-gram bloom */
	uint8_t has_bloom;
	uint8_t is_leaf;
} lom_fstr_node;

/* Build from document bytes + CSR open tree. block_bytes=0 => 65536. */
LOM_API lom_fstr *lom_fstr_build(
	const char *code,
	size_t code_len,
	const lom_open_row *opens,
	size_t open_count,
	const uint32_t *root_children,
	size_t root_child_count,
	const uint32_t *child_at,
	const uint32_t *child_start,
	size_t block_bytes
);

LOM_API void lom_fstr_free(lom_fstr *f);
LOM_API size_t lom_fstr_node_count(const lom_fstr *f);
LOM_API const lom_fstr_node *lom_fstr_get_node(const lom_fstr *f, uint32_t i);

/* 1 if every byte of needle is present in node's span signature. */
LOM_API int lom_fstr_may_contain(const lom_fstr *f, uint32_t node,
	const uint8_t *needle, size_t needle_len);

/*
 * Fill lo[i]..hi[i] with finest-level spans that may contain needle.
 * Returns count written (capped at cap). If needle empty, one span [0,n).
 */
LOM_API size_t lom_fstr_candidate_spans(const lom_fstr *f,
	const uint8_t *needle, size_t needle_len,
	int64_t *lo, int64_t *hi, size_t cap);

/* Cold exact find: prune then memmem/fss on survivors. -1 if missing. */
LOM_API ssize_t lom_fstr_find(const lom_fstr *f, const char *code, size_t code_len,
	const char *needle, size_t needle_len);

/* Extract a presence probe from a regex pattern (literal runs). Returns length. */
LOM_API size_t lom_fstr_regex_probe(const char *pattern, size_t plen,
	uint8_t *out, size_t out_cap);

#ifdef __cplusplus
}
#endif

#endif /* LOM_FSTR_H */
