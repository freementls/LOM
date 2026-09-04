/* SPDX-License-Identifier: Apache-2.0 — fractal string primitive */
#include "lom_fstr.h"
#include "lom_fss.h"

#include <ctype.h>
#include <limits.h>
#include <stdlib.h>
#include <string.h>

struct lom_fstr {
	lom_fstr_node *nodes;
	size_t node_count;
	size_t node_cap;
	uint32_t *child_at;
	size_t child_count;
	size_t child_cap;
	size_t code_len;
	size_t block_bytes;
};

static int presence_has(const uint8_t *p, unsigned char c) {
	return (p[c >> 3] >> (c & 7)) & 1;
}

static void presence_set(uint8_t *p, unsigned char c) {
	p[c >> 3] |= (uint8_t)(1u << (c & 7));
}

static void presence_or(uint8_t *dst, const uint8_t *src) {
	for(int i = 0; i < 32; i++) dst[i] |= src[i];
}

static uint64_t gram3_hash(uint8_t a, uint8_t b, uint8_t c) {
	uint64_t x = ((uint64_t)a << 16) | ((uint64_t)b << 8) | (uint64_t)c;
	x ^= x >> 33;
	x *= 0xff51afd7ed558ccdULL;
	x ^= x >> 33;
	return x;
}

static void bloom_add(uint64_t *bloom4, uint64_t h) {
	for(int k = 0; k < 4; k++) {
		uint64_t hh = h + (uint64_t)k * 0x9e3779b97f4a7c15ULL;
		unsigned bit = (unsigned)(hh & 255u);
		bloom4[bit >> 6] |= 1ull << (bit & 63);
	}
}

static int bloom_may(const uint64_t *bloom4, uint64_t h) {
	for(int k = 0; k < 4; k++) {
		uint64_t hh = h + (uint64_t)k * 0x9e3779b97f4a7c15ULL;
		unsigned bit = (unsigned)(hh & 255u);
		if(!(bloom4[bit >> 6] & (1ull << (bit & 63)))) return 0;
	}
	return 1;
}

static int grow_u32(uint32_t **p, size_t *cap, size_t need) {
	if(need <= *cap) return 1;
	size_t ncap = *cap ? *cap : 64;
	while(ncap < need) ncap *= 2;
	uint32_t *np = realloc(*p, ncap * sizeof(uint32_t));
	if(!np) return 0;
	*p = np;
	*cap = ncap;
	return 1;
}

static int grow_nodes(lom_fstr *f, size_t need) {
	if(need <= f->node_cap) return 1;
	size_t ncap = f->node_cap ? f->node_cap : 64;
	while(ncap < need) ncap *= 2;
	lom_fstr_node *np = realloc(f->nodes, ncap * sizeof(lom_fstr_node));
	if(!np) return 0;
	f->nodes = np;
	f->node_cap = ncap;
	return 1;
}

static uint32_t add_node(lom_fstr *f, int64_t start, int64_t end, int32_t parent, uint32_t open_idx) {
	if(!grow_nodes(f, f->node_count + 1)) return UINT32_MAX;
	uint32_t id = (uint32_t)f->node_count++;
	lom_fstr_node *n = &f->nodes[id];
	memset(n, 0, sizeof(*n));
	n->start = start;
	n->end = end;
	n->parent = parent;
	n->open_idx = open_idx;
	n->is_leaf = 1;
	return id;
}

static void fill_leaf_sig(lom_fstr_node *n, const char *code, size_t code_len) {
	if(n->start < 0 || n->end <= n->start) return;
	size_t lo = (size_t)n->start;
	size_t hi = (size_t)n->end;
	if(hi > code_len) hi = code_len;
	if(lo >= hi) return;
	for(size_t i = lo; i < hi; i++) presence_set(n->presence, (unsigned char)code[i]);
	if(hi - lo >= 3) {
		n->has_bloom = 1;
		for(size_t i = lo; i + 2 < hi; i++) {
			bloom_add(n->bloom, gram3_hash((uint8_t)code[i], (uint8_t)code[i + 1], (uint8_t)code[i + 2]));
		}
	}
}

static void rollup_sig(lom_fstr *f, uint32_t id) {
	lom_fstr_node *n = &f->nodes[id];
	if(n->child_count == 0) return;
	n->is_leaf = 0;
	memset(n->presence, 0, sizeof(n->presence));
	memset(n->bloom, 0, sizeof(n->bloom));
	n->has_bloom = 1;
	for(uint32_t c = 0; c < n->child_count; c++) {
		uint32_t ci = f->child_at[n->child_begin + c];
		presence_or(n->presence, f->nodes[ci].presence);
		if(f->nodes[ci].has_bloom) {
			for(int k = 0; k < 4; k++) n->bloom[k] |= f->nodes[ci].bloom[k];
		} else {
			n->has_bloom = 0;
		}
	}
}

static size_t next_tag_aligned(const char *code, size_t code_len, size_t from, size_t target) {
	if(target >= code_len) return code_len;
	if(target <= from) return from;
	size_t i = target;
	while(i < code_len && code[i] != '<') i++;
	if(i >= code_len) return code_len;
	return i;
}

lom_fstr *lom_fstr_build(
	const char *code,
	size_t code_len,
	const lom_open_row *opens,
	size_t open_count,
	const uint32_t *root_children,
	size_t root_child_count,
	const uint32_t *child_at,
	const uint32_t *child_start,
	size_t block_bytes
) {
	(void)child_at;
	(void)child_start;
	(void)open_count;
	if(!code) return NULL;
	lom_fstr *f = calloc(1, sizeof(*f));
	if(!f) return NULL;
	f->code_len = code_len;
	f->block_bytes = block_bytes ? block_bytes : (size_t)65536;

	uint32_t root = add_node(f, 0, (int64_t)code_len, -1, UINT32_MAX);
	if(root == UINT32_MAX) { lom_fstr_free(f); return NULL; }

	/* Level-1: each root child element (or whole doc as one leaf if none). */
	if(root_child_count == 0 || !root_children || !opens) {
		f->nodes[root].is_leaf = 1;
		fill_leaf_sig(&f->nodes[root], code, code_len);
		return f;
	}

	/* Pass 1: L1 children of root (contiguous in child_at). */
	if(!grow_u32(&f->child_at, &f->child_cap, root_child_count)) {
		lom_fstr_free(f);
		return NULL;
	}
	f->nodes[root].child_begin = (uint32_t)f->child_count;
	f->nodes[root].child_count = 0;

	for(size_t i = 0; i < root_child_count; i++) {
		uint32_t oi = root_children[i];
		const lom_open_row *row = &opens[oi];
		int64_t a = row->open_off;
		int64_t b = row->node_end_off + 1;
		if(b < a) b = a;
		if((size_t)b > code_len) b = (int64_t)code_len;

		uint32_t nid = add_node(f, a, b, (int32_t)root, oi);
		if(nid == UINT32_MAX) { lom_fstr_free(f); return NULL; }
		f->child_at[f->child_count++] = nid;
		f->nodes[root].child_count++;
	}

	/* Pass 2: subdivide large L1 spans into tag-aligned blocks (appended after L1). */
	for(uint32_t c = 0; c < f->nodes[root].child_count; c++) {
		uint32_t nid = f->child_at[f->nodes[root].child_begin + c];
		lom_fstr_node *n = &f->nodes[nid];
		size_t span = (size_t)(n->end - n->start);
		if(span <= f->block_bytes * 2) {
			fill_leaf_sig(n, code, code_len);
			continue;
		}
		size_t nblocks = (span + f->block_bytes - 1) / f->block_bytes;
		if(!grow_u32(&f->child_at, &f->child_cap, f->child_count + nblocks + 8)) {
			lom_fstr_free(f);
			return NULL;
		}
		n->child_begin = (uint32_t)f->child_count;
		n->child_count = 0;
		size_t cur = (size_t)n->start;
		size_t bend = (size_t)n->end;
		while(cur < bend) {
			size_t target = cur + f->block_bytes;
			size_t nxt = next_tag_aligned(code, bend, cur + 1, target);
			if(nxt <= cur) nxt = bend;
			uint32_t bid = add_node(f, (int64_t)cur, (int64_t)nxt, (int32_t)nid, UINT32_MAX);
			if(bid == UINT32_MAX) { lom_fstr_free(f); return NULL; }
			f->child_at[f->child_count++] = bid;
			n->child_count++;
			fill_leaf_sig(&f->nodes[bid], code, code_len);
			cur = nxt;
		}
		rollup_sig(f, nid);
	}
	rollup_sig(f, root);
	return f;
}

void lom_fstr_free(lom_fstr *f) {
	if(!f) return;
	free(f->nodes);
	free(f->child_at);
	free(f);
}

size_t lom_fstr_node_count(const lom_fstr *f) {
	return f ? f->node_count : 0;
}

const lom_fstr_node *lom_fstr_get_node(const lom_fstr *f, uint32_t i) {
	if(!f || i >= f->node_count) return NULL;
	return &f->nodes[i];
}

int lom_fstr_may_contain(const lom_fstr *f, uint32_t node, const uint8_t *needle, size_t needle_len) {
	if(!f || node >= f->node_count) return 0;
	if(!needle || needle_len == 0) return 1;
	const lom_fstr_node *n = &f->nodes[node];
	for(size_t i = 0; i < needle_len; i++) {
		if(!presence_has(n->presence, needle[i])) return 0;
	}
	if(n->has_bloom && needle_len >= 3) {
		for(size_t i = 0; i + 2 < needle_len; i++) {
			if(!bloom_may(n->bloom, gram3_hash(needle[i], needle[i + 1], needle[i + 2])))
				return 0;
		}
	}
	return 1;
}

static size_t collect_leaves(const lom_fstr *f, uint32_t node, const uint8_t *nd, size_t m,
	int64_t *lo, int64_t *hi, size_t cap, size_t written) {
	if(!lom_fstr_may_contain(f, node, nd, m)) return written;
	const lom_fstr_node *n = &f->nodes[node];
	if(n->is_leaf || n->child_count == 0) {
		if(written < cap) {
			lo[written] = n->start;
			hi[written] = n->end;
		}
		return written + 1;
	}
	for(uint32_t c = 0; c < n->child_count; c++) {
		uint32_t ci = f->child_at[n->child_begin + c];
		written = collect_leaves(f, ci, nd, m, lo, hi, cap, written);
	}
	return written;
}

size_t lom_fstr_candidate_spans(const lom_fstr *f, const uint8_t *needle, size_t needle_len,
	int64_t *lo, int64_t *hi, size_t cap) {
	if(!f || !lo || !hi || cap == 0) return 0;
	if(!needle || needle_len == 0) {
		lo[0] = 0;
		hi[0] = (int64_t)f->code_len;
		return 1;
	}
	if(!lom_fstr_may_contain(f, 0, needle, needle_len)) return 0;
	size_t n = collect_leaves(f, 0, needle, needle_len, lo, hi, cap, 0);
	return n > cap ? cap : n;
}

ssize_t lom_fstr_find(const lom_fstr *f, const char *code, size_t code_len,
	const char *needle, size_t needle_len) {
	if(!code || !needle) return -1;
	if(needle_len == 0) return 0;
	if(!f) {
		const void *p = lom_fss_memmem(code, code_len, needle, needle_len);
		return p ? (ssize_t)((const char *)p - code) : (ssize_t)-1;
	}
	int64_t los[256], his[256];
	size_t ns = lom_fstr_candidate_spans(f, (const uint8_t *)needle, needle_len, los, his, 256);
	if(ns == 0) return -1;
	/* If too many survivors, fall back to one full scan (still correct). */
	if(ns >= 256) {
		const void *p = lom_fss_memmem(code, code_len, needle, needle_len);
		return p ? (ssize_t)((const char *)p - code) : (ssize_t)-1;
	}
	ssize_t best = -1;
	for(size_t i = 0; i < ns; i++) {
		if(los[i] < 0 || his[i] <= los[i]) continue;
		size_t a = (size_t)los[i];
		size_t b = (size_t)his[i];
		if(b > code_len) b = code_len;
		if(a >= b || b - a < needle_len) continue;
		/* Overlap previous byte so matches straddling block edges are found. */
		size_t pad = needle_len > 1 ? needle_len - 1 : 0;
		size_t sa = a > pad ? a - pad : 0;
		const void *p = lom_fss_memmem(code + sa, b - sa, needle, needle_len);
		if(!p) continue;
		ssize_t off = (ssize_t)((const char *)p - code);
		if(best < 0 || off < best) best = off;
	}
	return best;
}

size_t lom_fstr_regex_probe(const char *pattern, size_t plen, uint8_t *out, size_t out_cap) {
	if(!pattern || !out || out_cap == 0 || plen == 0) return 0;
	size_t best_i = 0, best_n = 0;
	size_t i = 0;
	while(i < plen) {
		if(pattern[i] == '\\' && i + 1 < plen) {
			i += 2;
			continue;
		}
		unsigned char c = (unsigned char)pattern[i];
		if(isalnum(c) || c == '_' || c == '-' || c == ':' || c == ' ') {
			size_t j = i;
			while(j < plen) {
				unsigned char d = (unsigned char)pattern[j];
				if(d == '\\') break;
				if(!(isalnum(d) || d == '_' || d == '-' || d == ':' || d == ' ')) break;
				j++;
			}
			if(j - i > best_n) {
				best_n = j - i;
				best_i = i;
			}
			i = j;
			continue;
		}
		i++;
	}
	if(best_n < 3) return 0;
	if(best_n > out_cap) best_n = out_cap;
	memcpy(out, pattern + best_i, best_n);
	return best_n;
}
