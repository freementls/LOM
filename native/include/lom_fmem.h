/* SPDX-License-Identifier: Apache-2.0
 * fmem — content-deduplicated in-RAM string intern for LOM (L1 whole-object).
 * ROI gate: if hit rate is poor, callers can skip interning (store raw).
 */
#ifndef LOM_FMEM_H
#define LOM_FMEM_H

#include <stddef.h>
#include <stdint.h>
#include <stdbool.h>

#ifdef __cplusplus
extern "C" {
#endif

typedef struct lom_fmem lom_fmem;

lom_fmem *lom_fmem_create(size_t bucket_hint);
void lom_fmem_free(lom_fmem *m);

/* Intern a buffer; returned pointer is owned by the table until lom_fmem_free.
 * Identical content returns the same pointer. len==0 returns static "". */
const char *lom_fmem_intern(lom_fmem *m, const char *bytes, size_t len);

/* Zero-copy intern: hash lookup only; on miss store a view into caller's bytes
 * (no malloc copy). bytes must outlive the table (e.g. mmap document / string_blob). */
const char *lom_fmem_intern_view(lom_fmem *m, const char *bytes, size_t len);

size_t lom_fmem_entries(const lom_fmem *m);
size_t lom_fmem_bytes_stored(const lom_fmem *m);
size_t lom_fmem_hits(const lom_fmem *m);
size_t lom_fmem_misses(const lom_fmem *m);

/* True when hit/(hit+miss) is below floor after min_samples — skip interning. */
bool lom_fmem_roi_ok(const lom_fmem *m, double min_hit_rate, size_t min_samples);

#ifdef __cplusplus
}
#endif

#endif
