#include "lom.h"

#include <unistd.h>
#include <ctype.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

struct lom_doc {
	char *code;
	size_t code_len;
	size_t code_cap;
	lom_scan_result scan;
	/* per-name sorted open indices into scan.opens */
	size_t **tag_rows; /* tag_rows[name_id] = array of open indices */
	size_t *tag_row_counts;
	size_t *tag_row_caps;
	size_t tag_row_slots; /* == string_count after rebuild */
	/* children: parallel arrays of child open indices per open row + root */
	size_t **children;
	size_t *child_counts;
	size_t *child_caps;
	size_t *root_children;
	size_t root_child_count;
	size_t root_child_cap;
	size_t aux_open_count; /* length of children[] parallel arrays */
	/* attr name_id -> list of open_offs that have the attribute */
	size_t **attr_opens;
	size_t *attr_open_counts;
	size_t *attr_open_caps;
	size_t attr_slots;
	lom_status status;
	char error[256];
};

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

static bool match_push(lom_match_list *m, int64_t off, int64_t end) {
	if(!grow_cap((void **)&m->items, sizeof(lom_match), &m->cap, m->count + 1)) return false;
	m->items[m->count].offset = off;
	m->items[m->count].end_off = end;
	m->count++;
	return true;
}

static void doc_clear_aux(lom_doc *d) {
	if(d->tag_rows) {
		for(size_t i = 0; i < d->tag_row_slots; i++) free(d->tag_rows[i]);
	}
	free(d->tag_rows);
	free(d->tag_row_counts);
	free(d->tag_row_caps);
	d->tag_rows = NULL;
	d->tag_row_counts = NULL;
	d->tag_row_caps = NULL;
	d->tag_row_slots = 0;

	if(d->children) {
		for(size_t i = 0; i < d->aux_open_count; i++) free(d->children[i]);
	}
	free(d->children);
	free(d->child_counts);
	free(d->child_caps);
	d->children = NULL;
	d->child_counts = NULL;
	d->child_caps = NULL;
	d->aux_open_count = 0;

	free(d->root_children);
	d->root_children = NULL;
	d->root_child_count = 0;
	d->root_child_cap = 0;

	if(d->attr_opens) {
		for(size_t i = 0; i < d->attr_slots; i++) free(d->attr_opens[i]);
	}
	free(d->attr_opens);
	free(d->attr_open_counts);
	free(d->attr_open_caps);
	d->attr_opens = NULL;
	d->attr_open_counts = NULL;
	d->attr_open_caps = NULL;
	d->attr_slots = 0;
}

/* Binary search on sorted opening offsets (scan.opens is in document order). */
static size_t find_open_index(const lom_doc *d, int64_t open_off) {
	size_t lo = 0, hi = d->scan.open_count;
	while(lo < hi) {
		size_t mid = lo + (hi - lo) / 2;
		int64_t v = d->scan.opens[mid].open_off;
		if(v < open_off) lo = mid + 1;
		else if(v > open_off) hi = mid;
		else return mid;
	}
	return (size_t)-1;
}

static bool doc_rebuild_aux(lom_doc *d) {
	doc_clear_aux(d);
	size_t n = d->scan.open_count;
	size_t nstr = d->scan.string_count;
	d->tag_row_slots = nstr;
	d->tag_rows = calloc(nstr, sizeof(size_t *));
	d->tag_row_counts = calloc(nstr, sizeof(size_t));
	d->tag_row_caps = calloc(nstr, sizeof(size_t));
	d->children = calloc(n ? n : 1, sizeof(size_t *));
	d->child_counts = calloc(n ? n : 1, sizeof(size_t));
	d->child_caps = calloc(n ? n : 1, sizeof(size_t));
	d->aux_open_count = n;
	if(!d->tag_rows || !d->tag_row_counts || !d->tag_row_caps || !d->children || !d->child_counts || !d->child_caps) {
		return false;
	}
	for(size_t i = 0; i < n; i++) {
		uint32_t nid = d->scan.opens[i].name_id;
		if(nid >= nstr) continue;
		if(!grow_cap((void **)&d->tag_rows[nid], sizeof(size_t), &d->tag_row_caps[nid], d->tag_row_counts[nid] + 1)) {
			return false;
		}
		d->tag_rows[nid][d->tag_row_counts[nid]++] = i;

		int64_t parent = d->scan.opens[i].parent_off;
		if(parent < 0) {
			if(!grow_cap((void **)&d->root_children, sizeof(size_t), &d->root_child_cap, d->root_child_count + 1)) {
				return false;
			}
			d->root_children[d->root_child_count++] = i;
		} else {
			size_t pi = find_open_index(d, parent);
			if(pi == (size_t)-1) continue;
			if(!grow_cap((void **)&d->children[pi], sizeof(size_t), &d->child_caps[pi], d->child_counts[pi] + 1)) {
				return false;
			}
			d->children[pi][d->child_counts[pi]++] = i;
		}
	}
	d->attr_slots = nstr;
	d->attr_opens = calloc(nstr, sizeof(size_t *));
	d->attr_open_counts = calloc(nstr, sizeof(size_t));
	d->attr_open_caps = calloc(nstr, sizeof(size_t));
	if(!d->attr_opens || !d->attr_open_counts || !d->attr_open_caps) return false;
	for(size_t i = 0; i < d->scan.attr_count; i++) {
		uint32_t anid = d->scan.attrs[i].name_id;
		if(anid >= nstr) continue;
		size_t oi = find_open_index(d, d->scan.attrs[i].open_off);
		if(oi == (size_t)-1) continue;
		/* dedupe consecutive duplicates for same open+name is rare; allow dups and unique later if needed */
		if(!grow_cap((void **)&d->attr_opens[anid], sizeof(size_t), &d->attr_open_caps[anid], d->attr_open_counts[anid] + 1)) {
			return false;
		}
		d->attr_opens[anid][d->attr_open_counts[anid]++] = oi;
	}
	return true;
}

static lom_status doc_reindex(lom_doc *d) {
	lom_status st = lom_scan_indexes(d->code, d->code_len, &d->scan, true);
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
	d->status = LOM_OK;
	d->error[0] = 0;
	return LOM_OK;
}

lom_doc *lom_doc_create(const char *code, size_t code_len) {
	lom_doc *d = calloc(1, sizeof(lom_doc));
	if(!d) return NULL;
	lom_scan_result_init(&d->scan);
	d->code_cap = code_len + 1;
	d->code = malloc(d->code_cap);
	if(!d->code) { free(d); return NULL; }
	if(code_len) memcpy(d->code, code, code_len);
	d->code[code_len] = 0;
	d->code_len = code_len;
	if(doc_reindex(d) != LOM_OK) {
		/* still return doc with error status */
	}
	return d;
}

lom_doc *lom_doc_create_file(const char *path) {
	FILE *f = fopen(path, "rb");
	if(!f) return NULL;
	if(fseek(f, 0, SEEK_END) != 0) { fclose(f); return NULL; }
	long sz = ftell(f);
	if(sz < 0) { fclose(f); return NULL; }
	rewind(f);
	char *buf = malloc((size_t)sz + 1);
	if(!buf) { fclose(f); return NULL; }
	size_t n = fread(buf, 1, (size_t)sz, f);
	fclose(f);
	buf[n] = 0;
	lom_doc *d = lom_doc_create(buf, n);
	free(buf);
	return d;
}

void lom_doc_free(lom_doc *doc) {
	if(!doc) return;
	doc_clear_aux(doc);
	lom_scan_result_free(&doc->scan);
	free(doc->code);
	free(doc);
}

lom_status lom_doc_status(const lom_doc *doc) {
	return doc ? doc->status : LOM_ERR_ARG;
}

const char *lom_doc_error(const lom_doc *doc) {
	return doc ? doc->error : "null doc";
}

const char *lom_doc_code(const lom_doc *doc, size_t *out_len) {
	if(!doc) return NULL;
	if(out_len) *out_len = doc->code_len;
	return doc->code;
}

size_t lom_doc_open_count(const lom_doc *doc) {
	return doc ? doc->scan.open_count : 0;
}

bool lom_doc_brackets_balanced(const lom_doc *doc) {
	if(!doc) return false;
	size_t lt = 0, gt = 0;
	for(size_t i = 0; i < doc->code_len; i++) {
		if(doc->code[i] == '<') lt++;
		else if(doc->code[i] == '>') gt++;
	}
	return lt == gt;
}

lom_status lom_doc_node_slice(const lom_doc *doc, int64_t open_off, const char **ptr, size_t *len) {
	if(!doc || !ptr || !len) return LOM_ERR_ARG;
	size_t idx = find_open_index(doc, open_off);
	if(idx == (size_t)-1) return LOM_ERR_NOTFOUND;
	int64_t end = doc->scan.opens[idx].node_end_off;
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
} sel_piece;

typedef struct {
	sel_piece pieces[32];
	size_t count;
} sel_chain;

static bool parse_piece(const char *s, size_t n, sel_piece *p) {
	memset(p, 0, sizeof(*p));
	size_t i = 0;
	while(i < n && (isalnum((unsigned char)s[i]) || s[i] == '_' || s[i] == '-' || s[i] == ':' || s[i] == '*')) {
		if(p->name_len + 1 >= sizeof(p->name)) return false;
		p->name[p->name_len++] = s[i++];
	}
	if(p->name_len == 0) return false;
	if(i < n && s[i] == '[') {
		i++;
		int idx = 0;
		if(i >= n || !isdigit((unsigned char)s[i])) return false;
		while(i < n && isdigit((unsigned char)s[i])) {
			idx = idx * 10 + (s[i] - '0');
			i++;
		}
		if(i >= n || s[i] != ']') return false;
		i++;
		p->index = idx;
	}
	if(i < n && s[i] == '@') {
		i++;
		p->has_attr = true;
		while(i < n && s[i] != '=' && (isalnum((unsigned char)s[i]) || s[i] == '_' || s[i] == '-' || s[i] == ':')) {
			if(p->attr_len + 1 >= sizeof(p->attr)) return false;
			p->attr[p->attr_len++] = s[i++];
		}
		if(p->attr_len == 0) return false;
		if(i < n && s[i] == '=') {
			i++;
			p->attr_eq = true;
			while(i < n) {
				if(p->attr_val_len + 1 >= sizeof(p->attr_val)) return false;
				p->attr_val[p->attr_val_len++] = s[i++];
			}
		}
	} else if(i < n && s[i] == '=') {
		i++;
		p->has_text = true;
		while(i < n) {
			if(p->text_len + 1 >= sizeof(p->text)) return false;
			p->text[p->text_len++] = s[i++];
		}
	}
	if(i != n) return false;
	return true;
}

static bool parse_selector(const char *sel, sel_chain *out) {
	memset(out, 0, sizeof(*out));
	if(!sel || !*sel) return false;
	const char *p = sel;
	while(*p) {
		const char *start = p;
		while(*p && *p != '/') p++;
		size_t n = (size_t)(p - start);
		if(out->count >= 32) return false;
		if(!parse_piece(start, n, &out->pieces[out->count])) return false;
		out->count++;
		if(*p == '/') p++;
	}
	return out->count > 0;
}

static bool row_has_attr(const lom_doc *d, int64_t open_off, const sel_piece *piece) {
	for(size_t i = 0; i < d->scan.attr_count; i++) {
		const lom_attr_row *a = &d->scan.attrs[i];
		if(a->open_off != open_off) continue;
		if(!name_eq(d, a->name_id, piece->attr, piece->attr_len)) continue;
		if(!piece->attr_eq) return true;
		return name_eq(d, a->value_id, piece->attr_val, piece->attr_val_len);
	}
	return false;
}

static bool inner_text_eq(const lom_doc *d, size_t open_idx, const char *text, size_t tlen) {
	const lom_open_row *row = &d->scan.opens[open_idx];
	int64_t start = row->tag_end_off + 1;
	int64_t end = row->node_end_off;
	/* find closing '<' */
	int64_t close_lt = end;
	while(close_lt > start && d->code[close_lt] != '<') close_lt--;
	if(close_lt <= start || d->code[close_lt] != '<') return false;
	size_t len = (size_t)(close_lt - start);
	if(len != tlen) return false;
	return memcmp(d->code + start, text, tlen) == 0;
}

static void collect_named_children(const lom_doc *d, const size_t *kids, size_t kn, const sel_piece *piece, size_t **out, size_t *out_n, size_t *out_cap) {
	*out_n = 0;
	for(size_t i = 0; i < kn; i++) {
		size_t oi = kids[i];
		const lom_open_row *row = &d->scan.opens[oi];
		if(piece->name_len == 1 && piece->name[0] == '*') {
			/* ok */
		} else if(!name_eq(d, row->name_id, piece->name, piece->name_len)) {
			continue;
		}
		if(piece->has_attr && !row_has_attr(d, row->open_off, piece)) continue;
		if(piece->has_text && !inner_text_eq(d, oi, piece->text, piece->text_len)) continue;
		if(!grow_cap((void **)out, sizeof(size_t), out_cap, *out_n + 1)) return;
		(*out)[(*out_n)++] = oi;
	}
}

static lom_status query_chain(lom_doc *d, const sel_chain *chain, lom_match_list *out) {
	lom_match_list_free(out);
	lom_match_list_init(out);
	size_t *cur = NULL, cur_n = 0, cur_cap = 0;
	size_t *nxt = NULL, nxt_n = 0, nxt_cap = 0;

	for(size_t pi = 0; pi < chain->count; pi++) {
		const sel_piece *piece = &chain->pieces[pi];
		nxt_n = 0;
		if(pi == 0) {
			if(piece->index > 0) {
				/* PHP: for each parent group including all root+nested groups with that tag index — 
				   actually for index on first piece without walking parents: pick from each children list.
				   Match PHP: iterate all parent_children_index entries. */
				/* Root children + every node's children as separate groups. */
				collect_named_children(d, d->root_children, d->root_child_count, piece, &nxt, &nxt_n, &nxt_cap);
				size_t pick = (size_t)piece->index - 1;
				size_t *picked = NULL; size_t picked_n = 0, picked_cap = 0;
				if(pick < nxt_n) {
					grow_cap((void **)&picked, sizeof(size_t), &picked_cap, 1);
					picked[picked_n++] = nxt[pick];
				}
				/* also other parent groups in document order */
				for(size_t oi = 0; oi < d->scan.open_count; oi++) {
					size_t tmp_n = 0, tmp_cap = 0; size_t *tmp = NULL;
					collect_named_children(d, d->children[oi], d->child_counts[oi], piece, &tmp, &tmp_n, &tmp_cap);
					if(pick < tmp_n) {
						grow_cap((void **)&picked, sizeof(size_t), &picked_cap, picked_n + 1);
						picked[picked_n++] = tmp[pick];
					}
					free(tmp);
				}
				free(nxt);
				nxt = picked; nxt_n = picked_n; nxt_cap = picked_cap;
			} else {
				int32_t nid = find_name_id(d, piece->name, piece->name_len);
				if(piece->name_len == 1 && piece->name[0] == '*') {
					for(size_t i = 0; i < d->scan.open_count; i++) {
						if(piece->has_attr && !row_has_attr(d, d->scan.opens[i].open_off, piece)) continue;
						if(piece->has_text && !inner_text_eq(d, i, piece->text, piece->text_len)) continue;
						grow_cap((void **)&nxt, sizeof(size_t), &nxt_cap, nxt_n + 1);
						nxt[nxt_n++] = i;
					}
				} else if(nid < 0) {
					nxt_n = 0;
				} else {
					size_t *rows = d->tag_rows[nid];
					size_t rc = d->tag_row_counts[nid];
					for(size_t i = 0; i < rc; i++) {
						size_t oi = rows[i];
						if(piece->has_attr && !row_has_attr(d, d->scan.opens[oi].open_off, piece)) continue;
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
				collect_named_children(d, d->children[parent_i], d->child_counts[parent_i], piece, &tmp, &tmp_n, &tmp_cap);
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
		nxt = NULL; nxt_n = 0; nxt_cap = 0;
		if(cur_n == 0) break;
	}

	/* Direct-chain without per-step indexing used parent-walk for unindexed multi-piece.
	   When first piece had no index and count>1, first branch used global tag list — that
	   matches PHP simple direct chain when no indices. When indices present, children walk matches indexed path. */

	for(size_t i = 0; i < cur_n; i++) {
		const lom_open_row *row = &d->scan.opens[cur[i]];
		if(!match_push(out, row->open_off, row->node_end_off)) {
			free(cur);
			return LOM_ERR_NOMEM;
		}
	}
	free(cur);
	return LOM_OK;
}

/* Unindexed multi-piece: PHP walks from leaf candidates via parents. Prefer that when no indices. */
static bool chain_has_index(const sel_chain *c) {
	for(size_t i = 0; i < c->count; i++) if(c->pieces[i].index > 0) return true;
	return false;
}

static lom_status query_direct_parent_walk(lom_doc *d, const sel_chain *chain, lom_match_list *out) {
	lom_match_list_free(out);
	lom_match_list_init(out);
	const sel_piece *last = &chain->pieces[chain->count - 1];
	int32_t nid = find_name_id(d, last->name, last->name_len);
	if(nid < 0) return LOM_OK;
	size_t *rows = d->tag_rows[nid];
	size_t rc = d->tag_row_counts[nid];
	for(size_t i = 0; i < rc; i++) {
		size_t oi = rows[i];
		const lom_open_row *row = &d->scan.opens[oi];
		if(last->has_attr && !row_has_attr(d, row->open_off, last)) continue;
		if(last->has_text && !inner_text_eq(d, oi, last->text, last->text_len)) continue;
		int64_t cur = row->open_off;
		bool ok = true;
		for(ssize_t pi = (ssize_t)chain->count - 2; pi >= 0; pi--) {
			size_t cidx = find_open_index(d, cur);
			if(cidx == (size_t)-1) { ok = false; break; }
			int64_t parent = d->scan.opens[cidx].parent_off;
			if(parent < 0) { ok = false; break; }
			size_t pidx = find_open_index(d, parent);
			if(pidx == (size_t)-1) { ok = false; break; }
			const sel_piece *pp = &chain->pieces[pi];
			if(!name_eq(d, d->scan.opens[pidx].name_id, pp->name, pp->name_len)) {
				ok = false;
				break;
			}
			if(pp->has_attr && !row_has_attr(d, parent, pp)) { ok = false; break; }
			cur = parent;
		}
		if(ok) {
			if(!match_push(out, row->open_off, row->node_end_off)) return LOM_ERR_NOMEM;
		}
	}
	return LOM_OK;
}

lom_status lom_doc_get(lom_doc *doc, const char *selector, lom_match_list *out) {
	if(!doc || !selector || !out) return LOM_ERR_ARG;
	if(doc->status != LOM_OK) return doc->status;
	sel_chain chain;
	if(!parse_selector(selector, &chain)) return LOM_ERR_PARSE;

	if(chain.count == 1 && chain.pieces[0].has_attr && !chain.pieces[0].has_text) {
		lom_match_list_free(out);
		lom_match_list_init(out);
		const sel_piece *piece = &chain.pieces[0];
		int32_t anid = find_name_id(doc, piece->attr, piece->attr_len);
		int32_t tnid = find_name_id(doc, piece->name, piece->name_len);
		if(anid < 0 || (tnid < 0 && !(piece->name_len == 1 && piece->name[0] == '*'))) return LOM_OK;
		size_t *rows = doc->attr_opens[anid];
		size_t rc = doc->attr_open_counts[anid];
		int64_t last_off = -1;
		for(size_t i = 0; i < rc; i++) {
			size_t oi = rows[i];
			const lom_open_row *row = &doc->scan.opens[oi];
			if(row->open_off == last_off) continue;
			last_off = row->open_off;
			if(!(piece->name_len == 1 && piece->name[0] == '*') && !name_eq(doc, row->name_id, piece->name, piece->name_len)) {
				continue;
			}
			if(piece->attr_eq && !row_has_attr(doc, row->open_off, piece)) continue;
			if(!match_push(out, row->open_off, row->node_end_off)) return LOM_ERR_NOMEM;
		}
		return LOM_OK;
	}
	if(chain.count >= 2 && !chain_has_index(&chain) && !chain.pieces[0].has_attr) {
		/* Check if any piece has text on non-last — unusual */
		bool mid_text = false;
		for(size_t i = 0; i + 1 < chain.count; i++) if(chain.pieces[i].has_text) mid_text = true;
		if(!mid_text && !chain.pieces[chain.count - 1].has_attr) {
			/* For chains ending with =text, parent-walk still works */
			return query_direct_parent_walk(doc, &chain, out);
		}
	}
	if(chain_has_index(&chain)) {
		return query_chain(doc, &chain, out);
	}
	if(chain.count == 1) {
		return query_chain(doc, &chain, out);
	}
	return query_direct_parent_walk(doc, &chain, out);
}

lom_status lom_doc_get_parent(lom_doc *doc, const char *selector, lom_match_list *out) {
	lom_match_list tmp;
	lom_match_list_init(&tmp);
	lom_status st = lom_doc_get(doc, selector, &tmp);
	if(st != LOM_OK) { lom_match_list_free(&tmp); return st; }
	lom_match_list_free(out);
	lom_match_list_init(out);
	/* unique parents */
	int64_t *seen = NULL; size_t seen_n = 0, seen_cap = 0;
	for(size_t i = 0; i < tmp.count; i++) {
		size_t idx = find_open_index(doc, tmp.items[i].offset);
		if(idx == (size_t)-1) continue;
		int64_t parent = doc->scan.opens[idx].parent_off;
		if(parent < 0) continue;
		bool dup = false;
		for(size_t s = 0; s < seen_n; s++) if(seen[s] == parent) { dup = true; break; }
		if(dup) continue;
		grow_cap((void **)&seen, sizeof(int64_t), &seen_cap, seen_n + 1);
		seen[seen_n++] = parent;
		size_t pidx = find_open_index(doc, parent);
		if(pidx == (size_t)-1) continue;
		match_push(out, parent, doc->scan.opens[pidx].node_end_off);
	}
	free(seen);
	lom_match_list_free(&tmp);
	return LOM_OK;
}

static lom_status splice(lom_doc *d, size_t at, size_t remove_len, const char *insert, size_t insert_len) {
	size_t new_len = d->code_len - remove_len + insert_len;
	if(new_len + 1 > d->code_cap) {
		size_t ncap = d->code_cap ? d->code_cap : 64;
		while(ncap < new_len + 1) ncap *= 2;
		char *nb = realloc(d->code, ncap);
		if(!nb) return LOM_ERR_NOMEM;
		d->code = nb;
		d->code_cap = ncap;
	}
	memmove(d->code + at + insert_len, d->code + at + remove_len, d->code_len - (at + remove_len));
	if(insert_len) memcpy(d->code + at, insert, insert_len);
	d->code_len = new_len;
	d->code[d->code_len] = 0;
	return doc_reindex(d);
}

static bool find_inner_range(const lom_doc *d, size_t open_idx, size_t *inner_start, size_t *inner_len, size_t *close_lt) {
	const lom_open_row *row = &d->scan.opens[open_idx];
	size_t start = (size_t)row->tag_end_off + 1;
	size_t end = (size_t)row->node_end_off;
	size_t cl = end;
	while(cl > start && d->code[cl] != '<') cl--;
	if(cl <= start || d->code[cl] != '<') return false;
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
		st = splice(doc, is, il, text, strlen(text));
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
		st = splice(doc, cl, 0, fragment, frag_len);
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
		size_t start = (size_t)row->open_off;
		size_t len = (size_t)(row->node_end_off - row->open_off + 1);
		st = splice(doc, start, len, "", 0);
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
	size_t pidx = find_open_index(doc, open_off);
	if(pidx == (size_t)-1) return LOM_ERR_NOTFOUND;
	for(size_t i = 0; i < doc->child_counts[pidx]; i++) {
		size_t ci = doc->children[pidx][i];
		const char *nm = lom_scan_string(&doc->scan, doc->scan.opens[ci].name_id);
		if(strcmp(nm, child_tag) != 0) continue;
		size_t is, il, cl;
		if(!find_inner_range(doc, ci, &is, &il, &cl)) return LOM_ERR_PARSE;
		if(il >= buflen) il = buflen - 1;
		memcpy(buf, doc->code + is, il);
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
	size_t start = (size_t)row->open_off;
	size_t end = (size_t)row->tag_end_off;
	size_t tag_len = end - start + 1;
	char *tag = malloc(tag_len + 1);
	if(!tag) return LOM_ERR_NOMEM;
	memcpy(tag, doc->code + start, tag_len);
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
	lom_status st = splice(doc, start, tag_len, new_tag, strlen(new_tag));
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
	char tmp[1024];
	snprintf(tmp, sizeof(tmp), "%s.tmp.%d", path, (int)getpid());
	FILE *f = fopen(tmp, "wb");
	if(!f) return LOM_ERR_PARSE;
	size_t n = fwrite(doc->code, 1, doc->code_len, f);
	fclose(f);
	if(n != doc->code_len) { remove(tmp); return LOM_ERR_PARSE; }
	if(rename(tmp, path) != 0) { remove(tmp); return LOM_ERR_PARSE; }
	return LOM_OK;
}

lom_status lom_doc_delete_offset(lom_doc *doc, int64_t open_off) {
	if(!doc) return LOM_ERR_ARG;
	size_t idx = find_open_index(doc, open_off);
	if(idx == (size_t)-1) return LOM_ERR_NOTFOUND;
	const lom_open_row *row = &doc->scan.opens[idx];
	return splice(doc, (size_t)row->open_off, (size_t)(row->node_end_off - row->open_off + 1), "", 0);
}

lom_status lom_doc_set_inner_text_offset(lom_doc *doc, int64_t open_off, const char *text) {
	if(!doc || !text) return LOM_ERR_ARG;
	size_t idx = find_open_index(doc, open_off);
	if(idx == (size_t)-1) return LOM_ERR_NOTFOUND;
	size_t is, il, cl;
	if(!find_inner_range(doc, idx, &is, &il, &cl)) return LOM_ERR_PARSE;
	return splice(doc, is, il, text, strlen(text));
}

lom_status lom_doc_set_child_text_offset(lom_doc *doc, int64_t open_off, const char *child_tag, const char *text) {
	if(!doc || !child_tag || !text) return LOM_ERR_ARG;
	size_t pidx = find_open_index(doc, open_off);
	if(pidx == (size_t)-1) return LOM_ERR_NOTFOUND;
	for(size_t i = 0; i < doc->child_counts[pidx]; i++) {
		size_t ci = doc->children[pidx][i];
		const char *nm = lom_scan_string(&doc->scan, doc->scan.opens[ci].name_id);
		if(strcmp(nm, child_tag) != 0) continue;
		return lom_doc_set_inner_text_offset(doc, doc->scan.opens[ci].open_off, text);
	}
	char frag[512];
	snprintf(frag, sizeof(frag), "<%s>%s</%s>", child_tag, text, child_tag);
	size_t is, il, cl;
	if(!find_inner_range(doc, pidx, &is, &il, &cl)) return LOM_ERR_PARSE;
	return splice(doc, cl, 0, frag, strlen(frag));
}
