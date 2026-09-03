/* SPDX-License-Identifier: Apache-2.0 */
#include "lom_fmem.h"

#include <stdlib.h>
#include <string.h>

typedef struct lom_fmem_entry {
	uint64_t hash;
	char *bytes;
	size_t len;
	int owned; /* 1 => free bytes on destroy; 0 => view into foreign buffer */
	struct lom_fmem_entry *next;
} lom_fmem_entry;

struct lom_fmem {
	lom_fmem_entry **buckets;
	size_t nbuckets;
	size_t entries;
	size_t bytes_stored;
	size_t hits;
	size_t misses;
};

static uint64_t fnv1a64(const char *p, size_t n) {
	uint64_t h = 14695981039346656037ull;
	for(size_t i = 0; i < n; i++) {
		h ^= (unsigned char)p[i];
		h *= 1099511628211ull;
	}
	return h;
}

lom_fmem *lom_fmem_create(size_t bucket_hint) {
	lom_fmem *m = calloc(1, sizeof(*m));
	if(!m) return NULL;
	m->nbuckets = bucket_hint ? bucket_hint : 1024;
	m->buckets = calloc(m->nbuckets, sizeof(lom_fmem_entry *));
	if(!m->buckets) {
		free(m);
		return NULL;
	}
	return m;
}

void lom_fmem_free(lom_fmem *m) {
	if(!m) return;
	for(size_t i = 0; i < m->nbuckets; i++) {
		lom_fmem_entry *e = m->buckets[i];
		while(e) {
			lom_fmem_entry *n = e->next;
			if(e->owned) free(e->bytes);
			free(e);
			e = n;
		}
	}
	free(m->buckets);
	free(m);
}

const char *lom_fmem_intern(lom_fmem *m, const char *bytes, size_t len) {
	static const char empty[] = "";
	if(!m) return bytes;
	if(!bytes || len == 0) return empty;
	uint64_t h = fnv1a64(bytes, len);
	size_t bi = (size_t)(h % m->nbuckets);
	for(lom_fmem_entry *e = m->buckets[bi]; e; e = e->next) {
		if(e->hash == h && e->len == len && memcmp(e->bytes, bytes, len) == 0) {
			m->hits++;
			return e->bytes;
		}
	}
	m->misses++;
	lom_fmem_entry *ne = calloc(1, sizeof(*ne));
	if(!ne) return bytes;
	ne->bytes = malloc(len + 1);
	if(!ne->bytes) {
		free(ne);
		return bytes;
	}
	memcpy(ne->bytes, bytes, len);
	ne->bytes[len] = 0;
	ne->len = len;
	ne->hash = h;
	ne->owned = 1;
	ne->next = m->buckets[bi];
	m->buckets[bi] = ne;
	m->entries++;
	m->bytes_stored += len;
	return ne->bytes;
}

const char *lom_fmem_intern_view(lom_fmem *m, const char *bytes, size_t len) {
	static const char empty[] = "";
	if(!m) return bytes;
	if(!bytes || len == 0) return empty;
	uint64_t h = fnv1a64(bytes, len);
	size_t bi = (size_t)(h % m->nbuckets);
	for(lom_fmem_entry *e = m->buckets[bi]; e; e = e->next) {
		if(e->hash == h && e->len == len && memcmp(e->bytes, bytes, len) == 0) {
			m->hits++;
			return e->bytes;
		}
	}
	m->misses++;
	lom_fmem_entry *ne = calloc(1, sizeof(*ne));
	if(!ne) return bytes;
	ne->bytes = (char *)bytes; /* view */
	ne->len = len;
	ne->hash = h;
	ne->owned = 0;
	ne->next = m->buckets[bi];
	m->buckets[bi] = ne;
	m->entries++;
	/* bytes_stored unchanged — no owned copy */
	return ne->bytes;
}

size_t lom_fmem_entries(const lom_fmem *m) { return m ? m->entries : 0; }
size_t lom_fmem_bytes_stored(const lom_fmem *m) { return m ? m->bytes_stored : 0; }
size_t lom_fmem_hits(const lom_fmem *m) { return m ? m->hits : 0; }
size_t lom_fmem_misses(const lom_fmem *m) { return m ? m->misses : 0; }

bool lom_fmem_roi_ok(const lom_fmem *m, double min_hit_rate, size_t min_samples) {
	if(!m) return false;
	size_t t = m->hits + m->misses;
	if(t < min_samples) return true; /* still learning */
	return ((double)m->hits / (double)t) >= min_hit_rate;
}
