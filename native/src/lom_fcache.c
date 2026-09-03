/* SPDX-License-Identifier: Apache-2.0 */
#include "lom_fcache.h"

#include <stdlib.h>
#include <string.h>

typedef struct lom_fcache_entry {
	uint64_t hash;
	void *key;
	size_t key_len;
	void *val;
	size_t val_len;
	struct lom_fcache_entry *next;
} lom_fcache_entry;

struct lom_fcache {
	lom_fcache_entry **buckets;
	size_t nbuckets;
	size_t hits;
	size_t misses;
};

static uint64_t fnv1a64(const unsigned char *p, size_t n) {
	uint64_t h = 14695981039346656037ull;
	for(size_t i = 0; i < n; i++) {
		h ^= p[i];
		h *= 1099511628211ull;
	}
	return h;
}

lom_fcache *lom_fcache_create(size_t bucket_hint) {
	lom_fcache *c = calloc(1, sizeof(*c));
	if(!c) return NULL;
	c->nbuckets = bucket_hint ? bucket_hint : 256;
	c->buckets = calloc(c->nbuckets, sizeof(lom_fcache_entry *));
	if(!c->buckets) {
		free(c);
		return NULL;
	}
	return c;
}

void lom_fcache_free(lom_fcache *c) {
	if(!c) return;
	for(size_t i = 0; i < c->nbuckets; i++) {
		lom_fcache_entry *e = c->buckets[i];
		while(e) {
			lom_fcache_entry *n = e->next;
			free(e->key);
			free(e->val);
			free(e);
			e = n;
		}
	}
	free(c->buckets);
	free(c);
}

bool lom_fcache_put(lom_fcache *c, const void *key, size_t key_len, const void *val, size_t val_len) {
	if(!c || !key || !val) return false;
	uint64_t h = fnv1a64(key, key_len);
	size_t bi = (size_t)(h % c->nbuckets);
	for(lom_fcache_entry *e = c->buckets[bi]; e; e = e->next) {
		if(e->hash == h && e->key_len == key_len && memcmp(e->key, key, key_len) == 0) {
			void *nv = malloc(val_len);
			if(!nv) return false;
			memcpy(nv, val, val_len);
			free(e->val);
			e->val = nv;
			e->val_len = val_len;
			return true;
		}
	}
	lom_fcache_entry *ne = calloc(1, sizeof(*ne));
	if(!ne) return false;
	ne->key = malloc(key_len);
	ne->val = malloc(val_len);
	if(!ne->key || !ne->val) {
		free(ne->key);
		free(ne->val);
		free(ne);
		return false;
	}
	memcpy(ne->key, key, key_len);
	memcpy(ne->val, val, val_len);
	ne->key_len = key_len;
	ne->val_len = val_len;
	ne->hash = h;
	ne->next = c->buckets[bi];
	c->buckets[bi] = ne;
	return true;
}

const void *lom_fcache_get(lom_fcache *c, const void *key, size_t key_len, size_t *out_len) {
	if(!c || !key) return NULL;
	uint64_t h = fnv1a64(key, key_len);
	size_t bi = (size_t)(h % c->nbuckets);
	for(lom_fcache_entry *e = c->buckets[bi]; e; e = e->next) {
		if(e->hash == h && e->key_len == key_len && memcmp(e->key, key, key_len) == 0) {
			c->hits++;
			if(out_len) *out_len = e->val_len;
			return e->val;
		}
	}
	c->misses++;
	return NULL;
}

size_t lom_fcache_hits(const lom_fcache *c) { return c ? c->hits : 0; }
size_t lom_fcache_misses(const lom_fcache *c) { return c ? c->misses : 0; }

bool lom_fcache_roi_ok(const lom_fcache *c, double min_hit_rate, size_t min_samples) {
	if(!c) return false;
	size_t t = c->hits + c->misses;
	if(t < min_samples) return true;
	return ((double)c->hits / (double)t) >= min_hit_rate;
}

size_t lom_piece_boundaries(const char *code, size_t code_len, size_t target_piece_bytes,
	size_t *starts, size_t max_pieces) {
	if(!code || !starts || max_pieces == 0) return 0;
	starts[0] = 0;
	size_t count = 1;
	if(target_piece_bytes == 0) target_piece_bytes = 1024 * 1024;
	size_t next_cut = target_piece_bytes;
	for(size_t i = 1; i < code_len && count < max_pieces; i++) {
		if(i < next_cut) continue;
		/* Split only at '<' so tags never straddle pieces. */
		if(code[i] == '<') {
			starts[count++] = i;
			next_cut = i + target_piece_bytes;
		}
	}
	return count;
}
