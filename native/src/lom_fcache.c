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
	size_t refs; /* 1 = linked in a bucket; +1 per live hold */
	int linked;
	struct lom_fcache_entry *next;
} lom_fcache_entry;

struct lom_fcache_hold {
	lom_fcache_entry *e;
};

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

static void entry_release(lom_fcache_entry *e) {
	if(!e) return;
	if(e->refs > 0) e->refs--;
	if(e->refs || e->linked) return;
	free(e->key);
	free(e->val);
	free(e);
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
	lom_fcache_clear(c);
	free(c->buckets);
	free(c);
}

void lom_fcache_clear(lom_fcache *c) {
	if(!c) return;
	for(size_t i = 0; i < c->nbuckets; i++) {
		lom_fcache_entry *e = c->buckets[i];
		while(e) {
			lom_fcache_entry *n = e->next;
			e->next = NULL;
			e->linked = 0;
			entry_release(e);
			e = n;
		}
		c->buckets[i] = NULL;
	}
	c->hits = 0;
	c->misses = 0;
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
	ne->refs = 1;
	ne->linked = 1;
	ne->next = c->buckets[bi];
	c->buckets[bi] = ne;
	return true;
}

static lom_fcache_entry *find_entry(lom_fcache *c, const void *key, size_t key_len, int hit_count) {
	if(!c || !key) return NULL;
	uint64_t h = fnv1a64(key, key_len);
	size_t bi = (size_t)(h % c->nbuckets);
	for(lom_fcache_entry *e = c->buckets[bi]; e; e = e->next) {
		if(e->hash == h && e->key_len == key_len && memcmp(e->key, key, key_len) == 0) {
			if(hit_count) c->hits++;
			return e;
		}
	}
	if(hit_count) c->misses++;
	return NULL;
}

const void *lom_fcache_get(lom_fcache *c, const void *key, size_t key_len, size_t *out_len) {
	lom_fcache_entry *e = find_entry(c, key, key_len, 1);
	if(!e) return NULL;
	if(out_len) *out_len = e->val_len;
	return e->val;
}

const void *lom_fcache_borrow(lom_fcache *c, const void *key, size_t key_len, size_t *out_len,
	lom_fcache_hold **out_hold) {
	if(out_hold) *out_hold = NULL;
	lom_fcache_entry *e = find_entry(c, key, key_len, 1);
	if(!e) return NULL;
	lom_fcache_hold *h = malloc(sizeof(*h));
	if(!h) return NULL;
	e->refs++;
	h->e = e;
	if(out_len) *out_len = e->val_len;
	if(out_hold) *out_hold = h;
	else {
		entry_release(e);
		free(h);
	}
	return e->val;
}

void lom_fcache_hold_release(lom_fcache_hold *h) {
	if(!h) return;
	entry_release(h->e);
	free(h);
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
