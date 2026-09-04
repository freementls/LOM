/* SPDX-License-Identifier: Apache-2.0
 * fcache — content-keyed computation memo for LOM (compiled patterns, spans).
 */
#ifndef LOM_FCACHE_H
#define LOM_FCACHE_H

#include <stddef.h>
#include <stdint.h>
#include <stdbool.h>

#ifdef __cplusplus
extern "C" {
#endif

typedef struct lom_fcache lom_fcache;

lom_fcache *lom_fcache_create(size_t bucket_hint);
void lom_fcache_free(lom_fcache *c);

/* Store/lookup an opaque blob keyed by (key, key_len). Value is copied. */
bool lom_fcache_put(lom_fcache *c, const void *key, size_t key_len, const void *val, size_t val_len);
/* Returns pointer owned by cache, or NULL. Sets *out_len when found. */
const void *lom_fcache_get(lom_fcache *c, const void *key, size_t key_len, size_t *out_len);

size_t lom_fcache_hits(const lom_fcache *c);
size_t lom_fcache_misses(const lom_fcache *c);
bool lom_fcache_roi_ok(const lom_fcache *c, double min_hit_rate, size_t min_samples);

/* Drop all entries; keep the bucket table for reuse after writes. */
void lom_fcache_clear(lom_fcache *c);

/* Tag-aligned piece split: fill starts[] with offsets of top-level '<' tags
 * roughly every target_piece_bytes. starts[0]=0; returns count (max max_pieces). */
size_t lom_piece_boundaries(const char *code, size_t code_len, size_t target_piece_bytes,
	size_t *starts, size_t max_pieces);

#ifdef __cplusplus
}
#endif

#endif
