#include "lom.h"
#include "lom_fss.h"

#include <ctype.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <pthread.h>
#include <unistd.h>

const char *lom_version(void) {
	return "0.2.2-pscan";
}

void lom_scan_result_init(lom_scan_result *r) {
	memset(r, 0, sizeof(*r));
	r->status = LOM_OK;
}

void lom_scan_result_free(lom_scan_result *r) {
	if(!r) return;
	free(r->opens);
	free(r->attrs);
	free(r->string_blob);
	free(r->string_offs);
	lom_scan_result_init(r);
}

const char *lom_scan_string(const lom_scan_result *r, uint32_t id) {
	if(!r || id >= r->string_count) return "";
	return r->string_blob + r->string_offs[id];
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

typedef struct {
	uint32_t *keys_hash;
	uint32_t *ids;
	size_t cap;
	size_t used;
} lom_intern;

static uint32_t hash_bytes(const char *s, size_t n) {
	uint32_t h = 2166136261u;
	for(size_t i = 0; i < n; i++) {
		h ^= (unsigned char)s[i];
		h *= 16777619u;
	}
	return h ? h : 1u;
}

static bool intern_init(lom_intern *t, size_t cap) {
	memset(t, 0, sizeof(*t));
	t->cap = cap < 64 ? 64 : cap;
	t->keys_hash = calloc(t->cap, sizeof(uint32_t));
	t->ids = calloc(t->cap, sizeof(uint32_t));
	return t->keys_hash && t->ids;
}

static void intern_free(lom_intern *t) {
	free(t->keys_hash);
	free(t->ids);
	memset(t, 0, sizeof(*t));
}

static bool string_push(lom_scan_result *r, const char *s, size_t n, uint32_t *out_id) {
	if(!grow_cap((void **)&r->string_offs, sizeof(size_t), &r->string_cap, r->string_count + 1)) {
		return false;
	}
	if(!grow_cap((void **)&r->string_blob, 1, &r->string_blob_cap, r->string_blob_len + n + 1)) {
		return false;
	}
	size_t off = r->string_blob_len;
	memcpy(r->string_blob + off, s, n);
	r->string_blob[off + n] = '\0';
	r->string_blob_len = off + n + 1;
	r->string_offs[r->string_count] = off;
	*out_id = (uint32_t)r->string_count;
	r->string_count++;
	return true;
}

static bool intern_get(lom_intern *t, lom_scan_result *r, const char *s, size_t n, uint32_t *out_id) {
	if(t->used * 2 >= t->cap) {
		size_t ncap = t->cap * 2;
		uint32_t *nh = calloc(ncap, sizeof(uint32_t));
		uint32_t *nid = calloc(ncap, sizeof(uint32_t));
		if(!nh || !nid) {
			free(nh);
			free(nid);
			return false;
		}
		for(size_t i = 0; i < t->cap; i++) {
			if(!t->keys_hash[i]) continue;
			uint32_t h = t->keys_hash[i];
			size_t j = h % ncap;
			while(nh[j]) j = (j + 1) % ncap;
			nh[j] = h;
			nid[j] = t->ids[i];
		}
		free(t->keys_hash);
		free(t->ids);
		t->keys_hash = nh;
		t->ids = nid;
		t->cap = ncap;
	}
	uint32_t h = hash_bytes(s, n);
	size_t i = h % t->cap;
	while(t->keys_hash[i]) {
		uint32_t id = t->ids[i];
		const char *exist = lom_scan_string(r, id);
		if(t->keys_hash[i] == h && strncmp(exist, s, n) == 0 && exist[n] == '\0') {
			*out_id = id;
			return true;
		}
		i = (i + 1) % t->cap;
	}
	uint32_t nid;
	if(!string_push(r, s, n, &nid)) return false;
	t->keys_hash[i] = h;
	t->ids[i] = nid;
	t->used++;
	*out_id = nid;
	return true;
}

static size_t find_tag_close(const char *code, size_t length, size_t tag_offset) {
	bool in_q = false;
	char q = 0;
	for(size_t offset = tag_offset + 1; offset < length; offset++) {
		char c = code[offset];
		if(in_q) {
			if(c == q) in_q = false;
		} else if(c == '"' || c == '\'') {
			in_q = true;
			q = c;
		} else if(c == '>') {
			return offset;
		}
	}
	return (size_t)-1;
}

static bool is_name_char(unsigned char c) {
	return isalnum(c) || c == '_' || c == '-' || c == ':';
}

static size_t extract_tagname(const char *code, size_t tag_offset, size_t tag_end, size_t *name_start) {
	size_t start = tag_offset + 1;
	if(code[start] == '/') start++;
	size_t position = start;
	while(position <= tag_end && is_name_char((unsigned char)code[position])) {
		position++;
	}
	if(position <= start) return 0;
	*name_start = start;
	return position - start;
}

static bool tag_is_self_closing(const char *code, size_t tag_offset, size_t tag_end) {
	if(tag_end == 0) return false;
	size_t position = tag_end - 1;
	while(position > tag_offset && isspace((unsigned char)code[position])) {
		position--;
	}
	return position > tag_offset && code[position] == '/';
}

static bool is_doctype_open(const char *code, size_t offset, size_t code_len) {
	if(offset + 9 > code_len) return false;
	const char *lit = "<!DOCTYPE";
	for(int i = 0; i < 9; i++) {
		char a = code[offset + (size_t)i];
		if(a >= 'a' && a <= 'z') a = (char)(a - 32);
		if(a != lit[i]) return false;
	}
	return true;
}

static bool scan_attrs_in_tag(
	lom_scan_result *r,
	lom_intern *intern,
	const char *code,
	size_t open_off,
	uint32_t open_idx,
	size_t tag_end,
	size_t after_name
) {
	size_t offset = after_name;
	while(offset < tag_end) {
		while(offset < tag_end && isspace((unsigned char)code[offset])) offset++;
		if(offset >= tag_end) break;
		if(code[offset] == '/' || code[offset] == '>') break;
		size_t an_start = offset;
		while(offset < tag_end && is_name_char((unsigned char)code[offset])) offset++;
		size_t an_len = offset - an_start;
		if(an_len == 0) break;
		while(offset < tag_end && isspace((unsigned char)code[offset])) offset++;
		if(offset >= tag_end || code[offset] != '=') {
			continue;
		}
		offset++;
		while(offset < tag_end && isspace((unsigned char)code[offset])) offset++;
		if(offset >= tag_end) break;
		size_t av_start = 0, av_len = 0;
		if(code[offset] == '"' || code[offset] == '\'') {
			char q = code[offset++];
			av_start = offset;
			while(offset < tag_end && code[offset] != q) offset++;
			av_len = offset - av_start;
			if(offset < tag_end) offset++;
		} else {
			av_start = offset;
			while(offset < tag_end && !isspace((unsigned char)code[offset]) && code[offset] != '>' && code[offset] != '/') {
				offset++;
			}
			av_len = offset - av_start;
		}
		while(av_len && isspace((unsigned char)code[av_start])) {
			av_start++;
			av_len--;
		}
		while(av_len && isspace((unsigned char)code[av_start + av_len - 1])) {
			av_len--;
		}
		if(av_len == 0) continue;

		uint32_t nid, vid;
		if(!intern_get(intern, r, code + an_start, an_len, &nid)) return false;
		if(!intern_get(intern, r, code + av_start, av_len, &vid)) return false;
		if(!grow_cap((void **)&r->attrs, sizeof(lom_attr_row), &r->attr_cap, r->attr_count + 1)) {
			return false;
		}
		r->attrs[r->attr_count].open_off = (int64_t)open_off;
		r->attrs[r->attr_count].open_idx = open_idx;
		r->attrs[r->attr_count].name_id = nid;
		r->attrs[r->attr_count].value_id = vid;
		r->attr_count++;
	}
	return true;
}

static lom_status scan_bytes(
	const char *code,
	size_t lo,
	size_t hi,
	lom_scan_result *out,
	bool capture_attributes,
	bool reset_out
) {
	if(!code || !out) return LOM_ERR_ARG;
	if(lo > hi) return LOM_ERR_ARG;
	if(reset_out) {
		lom_scan_result_free(out);
		lom_scan_result_init(out);
	}

	lom_intern intern;
	if(!intern_init(&intern, 1024)) {
		out->status = LOM_ERR_NOMEM;
		snprintf(out->error, sizeof(out->error), "intern alloc failed");
		return LOM_ERR_NOMEM;
	}

	if(out->string_count == 0) {
		uint32_t empty_id;
		if(!string_push(out, "", 0, &empty_id)) {
			intern_free(&intern);
			out->status = LOM_ERR_NOMEM;
			return LOM_ERR_NOMEM;
		}
	}

	size_t span = (hi > lo) ? (hi - lo) : 0;
	size_t est_opens = span / 24 + 64;
	if(est_opens > 0 && !grow_cap((void **)&out->opens, sizeof(lom_open_row), &out->open_cap, out->open_count + est_opens)) {
		intern_free(&intern);
		out->status = LOM_ERR_NOMEM;
		return LOM_ERR_NOMEM;
	}
	size_t est_attrs = est_opens / 2 + 64;
	if(!grow_cap((void **)&out->attrs, sizeof(lom_attr_row), &out->attr_cap, out->attr_count + est_attrs)) {
		intern_free(&intern);
		out->status = LOM_ERR_NOMEM;
		return LOM_ERR_NOMEM;
	}

	uint32_t empty_id = 0;
	size_t *ostack = NULL;
	size_t ostack_n = 0, ostack_cap = 0;
	size_t offset = lo;

	while(offset < hi) {
		const char *p = (const char *)memchr(code + offset, '<', hi - offset);
		if(!p) break;
		offset = (size_t)(p - code);
		if(offset + 1 >= hi) break;
		char next = code[offset + 1];

		if(next == '!') {
			if(offset + 4 <= hi && memcmp(code + offset, "<!--", 4) == 0) {
				const char *end = (const char *)lom_fss_memmem(code + offset + 4, hi - (offset + 4), "-->", 3);
				if(!end) break;
				offset = (size_t)(end - code) + 3;
				continue;
			}
			if(offset + 9 <= hi && memcmp(code + offset, "<![CDATA[", 9) == 0) {
				const char *end = (const char *)lom_fss_memmem(code + offset + 9, hi - (offset + 9), "]]>", 3);
				if(!end) break;
				offset = (size_t)(end - code) + 3;
				continue;
			}
			if(is_doctype_open(code, offset, hi)) {
				size_t end = find_tag_close(code, hi, offset);
				if(end == (size_t)-1) break;
				offset = end + 1;
				continue;
			}
		}
		if(next == '?') {
			const char *end = (const char *)lom_fss_memmem(code + offset + 2, hi - (offset + 2), "?>", 2);
			if(!end) break;
			offset = (size_t)(end - code) + 2;
			continue;
		}
		if(next == '%') {
			const char *end = (const char *)lom_fss_memmem(code + offset + 2, hi - (offset + 2), "%>", 2);
			if(!end) break;
			offset = (size_t)(end - code) + 2;
			continue;
		}

		size_t tag_end = find_tag_close(code, hi, offset);
		if(tag_end == (size_t)-1) {
			intern_free(&intern);
			free(ostack);
			out->status = LOM_ERR_PARSE;
			snprintf(out->error, sizeof(out->error), "unclosed tag at %zu", offset);
			return LOM_ERR_PARSE;
		}

		if(next == '/') {
			if(ostack_n > 0) {
				size_t oi = ostack[--ostack_n];
				out->opens[oi].node_end_off = (int64_t)tag_end;
			}
			offset = tag_end + 1;
			continue;
		}

		if(!grow_cap((void **)&out->opens, sizeof(lom_open_row), &out->open_cap, out->open_count + 1)) {
			intern_free(&intern);
			free(ostack);
			out->status = LOM_ERR_NOMEM;
			return LOM_ERR_NOMEM;
		}
		size_t oi = out->open_count++;
		lom_open_row *row = &out->opens[oi];
		row->open_off = (int64_t)offset;
		row->tag_end_off = (int64_t)tag_end;
		row->parent_idx = (ostack_n > 0) ? (int32_t)ostack[ostack_n - 1] : -1;
		row->node_end_off = -1;
		row->self_closing = 0;
		row->name_id = empty_id;

		size_t name_start = 0;
		size_t name_len = extract_tagname(code, offset, tag_end, &name_start);
		if(name_len > 0) {
			uint32_t nid;
			if(!intern_get(&intern, out, code + name_start, name_len, &nid)) {
				intern_free(&intern);
				free(ostack);
				out->status = LOM_ERR_NOMEM;
				return LOM_ERR_NOMEM;
			}
			row->name_id = nid;
			if(capture_attributes) {
				if(!scan_attrs_in_tag(out, &intern, code, offset, (uint32_t)oi, tag_end, name_start + name_len)) {
					intern_free(&intern);
					free(ostack);
					out->status = LOM_ERR_NOMEM;
					return LOM_ERR_NOMEM;
				}
			}
		}

		if(tag_is_self_closing(code, offset, tag_end)) {
			row->self_closing = 1;
			row->node_end_off = (int64_t)tag_end;
			out->self_closing_count++;
		} else {
			if(!grow_cap((void **)&ostack, sizeof(size_t), &ostack_cap, ostack_n + 1)) {
				intern_free(&intern);
				free(ostack);
				out->status = LOM_ERR_NOMEM;
				return LOM_ERR_NOMEM;
			}
			ostack[ostack_n++] = oi;
		}
		offset = tag_end + 1;
	}

	while(ostack_n > 0) {
		size_t oi = ostack[--ostack_n];
		out->opens[oi].node_end_off = (int64_t)(hi ? hi - 1 : 0);
	}

	intern_free(&intern);
	free(ostack);
	out->status = LOM_OK;
	return LOM_OK;
}

static void scan_trim(lom_scan_result *out) {
	if(out->open_count && out->open_cap > out->open_count) {
		lom_open_row *slim = realloc(out->opens, out->open_count * sizeof(lom_open_row));
		if(slim) { out->opens = slim; out->open_cap = out->open_count; }
	}
	if(out->attr_count && out->attr_cap > out->attr_count) {
		lom_attr_row *slim = realloc(out->attrs, out->attr_count * sizeof(lom_attr_row));
		if(slim) { out->attrs = slim; out->attr_cap = out->attr_count; }
	}
	if(out->string_count && out->string_cap > out->string_count) {
		size_t *slim = realloc(out->string_offs, out->string_count * sizeof(size_t));
		if(slim) { out->string_offs = slim; out->string_cap = out->string_count; }
	}
	if(out->string_blob_len && out->string_blob_cap > out->string_blob_len) {
		char *slim = realloc(out->string_blob, out->string_blob_len);
		if(slim) { out->string_blob = slim; out->string_blob_cap = out->string_blob_len; }
	}
}

typedef struct { size_t start; size_t end; } lom_byte_range;

/* Record complete elements that open when stack depth == want_depth. */
static size_t find_sibling_ranges(const char *code, size_t code_len, int want_depth,
	lom_byte_range *out, size_t max_out, size_t *root_end_out) {
	size_t count = 0;
	int depth = 0;
	size_t offset = 0;
	size_t cur_start = 0;
	int in_wanted = 0;
	if(root_end_out) *root_end_out = code_len ? code_len - 1 : 0;
	/* out == NULL => count only (max_out ignored). */

	while(offset < code_len) {
		const char *p = (const char *)memchr(code + offset, '<', code_len - offset);
		if(!p) break;
		offset = (size_t)(p - code);
		if(offset + 1 >= code_len) break;
		char next = code[offset + 1];
		if(next == '!' || next == '?' || next == '%') {
			size_t tag_end;
			if(next == '!' && offset + 4 <= code_len && memcmp(code + offset, "<!--", 4) == 0) {
				const char *end = (const char *)lom_fss_memmem(code + offset + 4, code_len - (offset + 4), "-->", 3);
				if(!end) break;
				offset = (size_t)(end - code) + 3;
				continue;
			}
			if(next == '!' && offset + 9 <= code_len && memcmp(code + offset, "<![CDATA[", 9) == 0) {
				const char *end = (const char *)lom_fss_memmem(code + offset + 9, code_len - (offset + 9), "]]>", 3);
				if(!end) break;
				offset = (size_t)(end - code) + 3;
				continue;
			}
			tag_end = find_tag_close(code, code_len, offset);
			if(tag_end == (size_t)-1) break;
			if(next == '?') {
				const char *end = (const char *)lom_fss_memmem(code + offset + 2, code_len - (offset + 2), "?>", 2);
				if(!end) break;
				offset = (size_t)(end - code) + 2;
				continue;
			}
			if(next == '%') {
				const char *end = (const char *)lom_fss_memmem(code + offset + 2, code_len - (offset + 2), "%>", 2);
				if(!end) break;
				offset = (size_t)(end - code) + 2;
				continue;
			}
			offset = tag_end + 1;
			continue;
		}
		size_t tag_end = find_tag_close(code, code_len, offset);
		if(tag_end == (size_t)-1) break;
		if(next == '/') {
			depth--;
			if(in_wanted && depth == want_depth) {
				if(out && count < max_out) {
					out[count].start = cur_start;
					out[count].end = tag_end + 1;
				}
				count++;
				in_wanted = 0;
			}
			if(want_depth == 1 && depth == 0 && root_end_out) {
				*root_end_out = tag_end;
			}
			offset = tag_end + 1;
			continue;
		}
		int self = tag_is_self_closing(code, offset, tag_end);
		if(depth == want_depth) {
			cur_start = offset;
			if(self) {
				if(out && count < max_out) {
					out[count].start = cur_start;
					out[count].end = tag_end + 1;
				}
				count++;
			} else {
				in_wanted = 1;
			}
		}
		if(!self) depth++;
		offset = tag_end + 1;
	}
	return count;
}

static bool env_parallel_on(void) {
	const char *e = getenv("LOM_PARALLEL");
	if(!e || !*e) return false;
	return !(e[0] == '0' && e[1] == 0);
}

typedef struct {
	const char *code;
	size_t lo, hi;
	bool capture;
	lom_scan_result local;
	lom_status st;
} scan_job;

static void *scan_job_main(void *arg) {
	scan_job *j = (scan_job *)arg;
	lom_scan_result_init(&j->local);
	j->st = scan_bytes(j->code, j->lo, j->hi, &j->local, j->capture, true);
	return NULL;
}

static bool merge_piece(lom_scan_result *dst, lom_scan_result *src, int32_t root_parent, lom_intern *intern) {
	if(src->status != LOM_OK) return false;
	uint32_t *idmap = calloc(src->string_count ? src->string_count : 1, sizeof(uint32_t));
	if(!idmap) return false;
	for(size_t i = 0; i < src->string_count; i++) {
		const char *s = lom_scan_string(src, (uint32_t)i);
		size_t n = strlen(s);
		uint32_t gid;
		if(i == 0 && n == 0) { idmap[i] = 0; continue; }
		if(!intern_get(intern, dst, s, n, &gid)) { free(idmap); return false; }
		idmap[i] = gid;
	}
	size_t open_base = dst->open_count;
	if(!grow_cap((void **)&dst->opens, sizeof(lom_open_row), &dst->open_cap, open_base + src->open_count)) {
		free(idmap); return false;
	}
	for(size_t i = 0; i < src->open_count; i++) {
		lom_open_row row = src->opens[i];
		row.name_id = idmap[row.name_id];
		if(row.parent_idx < 0) row.parent_idx = root_parent;
		else row.parent_idx = (int32_t)((size_t)row.parent_idx + open_base);
		dst->opens[dst->open_count++] = row;
	}
	if(!grow_cap((void **)&dst->attrs, sizeof(lom_attr_row), &dst->attr_cap, dst->attr_count + src->attr_count)) {
		free(idmap); return false;
	}
	for(size_t i = 0; i < src->attr_count; i++) {
		lom_attr_row a = src->attrs[i];
		a.name_id = idmap[a.name_id];
		a.value_id = idmap[a.value_id];
		a.open_idx = (uint32_t)((size_t)a.open_idx + open_base);
		dst->attrs[dst->attr_count++] = a;
	}
	dst->self_closing_count += src->self_closing_count;
	free(idmap);
	return true;
}

static lom_status scan_parallel(const char *code, size_t code_len, lom_scan_result *out, bool capture_attributes) {
	enum { MAX_JOBS = 4 };
	/* Piece-local + string merge helps mid-size; at ~1GB merge tax dominates — stay serial. */
	if(code_len >= (256u << 20)) {
		lom_status st = scan_bytes(code, 0, code_len, out, capture_attributes, true);
		if(st == LOM_OK) scan_trim(out);
		return st;
	}
	size_t root_end = code_len ? code_len - 1 : 0;
	size_t n0 = find_sibling_ranges(code, code_len, 0, NULL, 0, &root_end);
	int want_depth = 0;
	int32_t inject_root = 0;
	size_t n = n0;
	if(n0 == 1) {
		size_t n1 = find_sibling_ranges(code, code_len, 1, NULL, 0, &root_end);
		if(n1 >= 2) { n = n1; want_depth = 1; inject_root = 1; }
	}
	if(n < 2 || code_len < (4u << 20)) {
		lom_status st = scan_bytes(code, 0, code_len, out, capture_attributes, true);
		if(st == LOM_OK) scan_trim(out);
		return st;
	}

	lom_byte_range *ranges = calloc(n, sizeof(lom_byte_range));
	if(!ranges) {
		lom_status st = scan_bytes(code, 0, code_len, out, capture_attributes, true);
		if(st == LOM_OK) scan_trim(out);
		return st;
	}
	if(find_sibling_ranges(code, code_len, want_depth, ranges, n, &root_end) != n) {
		free(ranges);
		lom_status st = scan_bytes(code, 0, code_len, out, capture_attributes, true);
		if(st == LOM_OK) scan_trim(out);
		return st;
	}

	long ncpu = sysconf(_SC_NPROCESSORS_ONLN);
	if(ncpu < 1) ncpu = 1;
	if(ncpu > MAX_JOBS) ncpu = MAX_JOBS;
	size_t jobs = (size_t)ncpu;
	if(jobs > n) jobs = n;
	if(jobs < 2) {
		free(ranges);
		lom_status st = scan_bytes(code, 0, code_len, out, capture_attributes, true);
		if(st == LOM_OK) scan_trim(out);
		return st;
	}

	size_t total_bytes = ranges[n - 1].end - ranges[0].start;
	size_t target = total_bytes / jobs;
	size_t bounds[MAX_JOBS + 1];
	bounds[0] = 0;
	size_t j = 1, acc = 0;
	for(size_t i = 0; i < n && j < jobs; i++) {
		acc += ranges[i].end - ranges[i].start;
		if(acc >= target && (n - i) >= (jobs - j + 1)) {
			bounds[j++] = i + 1;
			acc = 0;
		}
	}
	bounds[j] = n;
	jobs = j;

	scan_job *js = calloc(jobs, sizeof(scan_job));
	pthread_t *th = calloc(jobs, sizeof(pthread_t));
	if(!js || !th) { free(ranges); free(js); free(th); return LOM_ERR_NOMEM; }

	for(size_t i = 0; i < jobs; i++) {
		size_t a = bounds[i], b = bounds[i + 1];
		js[i].code = code;
		js[i].lo = ranges[a].start;
		js[i].hi = ranges[b - 1].end;
		js[i].capture = capture_attributes;
		if(pthread_create(&th[i], NULL, scan_job_main, &js[i]) != 0) {
			for(size_t k = 0; k < i; k++) pthread_join(th[k], NULL);
			for(size_t k = 0; k < i; k++) lom_scan_result_free(&js[k].local);
			free(ranges); free(js); free(th);
			lom_status st = scan_bytes(code, 0, code_len, out, capture_attributes, true);
			if(st == LOM_OK) scan_trim(out);
			return st;
		}
	}
	for(size_t i = 0; i < jobs; i++) pthread_join(th[i], NULL);
	size_t first_child_start = ranges[0].start;
	free(ranges);

	lom_scan_result_free(out);
	lom_scan_result_init(out);
	lom_intern intern;
	if(!intern_init(&intern, 4096)) {
		for(size_t i = 0; i < jobs; i++) lom_scan_result_free(&js[i].local);
		free(js); free(th);
		return LOM_ERR_NOMEM;
	}
	uint32_t empty_id;
	if(!string_push(out, "", 0, &empty_id)) {
		intern_free(&intern);
		for(size_t i = 0; i < jobs; i++) lom_scan_result_free(&js[i].local);
		free(js); free(th);
		return LOM_ERR_NOMEM;
	}

	int32_t root_parent = -1;
	if(inject_root) {
		lom_scan_result pref;
		lom_scan_result_init(&pref);
		lom_status pst = scan_bytes(code, 0, first_child_start, &pref, capture_attributes, true);
		if(pst != LOM_OK || pref.open_count < 1) {
			lom_scan_result_free(&pref); intern_free(&intern);
			for(size_t i = 0; i < jobs; i++) lom_scan_result_free(&js[i].local);
			free(js); free(th);
			lom_status st = scan_bytes(code, 0, code_len, out, capture_attributes, true);
			if(st == LOM_OK) scan_trim(out);
			return st;
		}
		lom_open_row root = pref.opens[0];
		const char *rn = lom_scan_string(&pref, root.name_id);
		uint32_t rid;
		if(!intern_get(&intern, out, rn, strlen(rn), &rid)) {
			lom_scan_result_free(&pref); intern_free(&intern);
			for(size_t i = 0; i < jobs; i++) lom_scan_result_free(&js[i].local);
			free(js); free(th); return LOM_ERR_NOMEM;
		}
		root.name_id = rid;
		root.parent_idx = -1;
		root.node_end_off = (int64_t)root_end;
		if(!grow_cap((void **)&out->opens, sizeof(lom_open_row), &out->open_cap, 1)) {
			lom_scan_result_free(&pref); intern_free(&intern);
			for(size_t i = 0; i < jobs; i++) lom_scan_result_free(&js[i].local);
			free(js); free(th); return LOM_ERR_NOMEM;
		}
		out->opens[out->open_count++] = root;
		for(size_t ai = 0; ai < pref.attr_count; ai++) {
			if(pref.attrs[ai].open_idx != 0) continue;
			lom_attr_row a = pref.attrs[ai];
			const char *an = lom_scan_string(&pref, a.name_id);
			const char *av = lom_scan_string(&pref, a.value_id);
			uint32_t nid, vid;
			if(!intern_get(&intern, out, an, strlen(an), &nid)) break;
			if(!intern_get(&intern, out, av, strlen(av), &vid)) break;
			a.name_id = nid; a.value_id = vid; a.open_idx = 0;
			if(!grow_cap((void **)&out->attrs, sizeof(lom_attr_row), &out->attr_cap, out->attr_count + 1)) break;
			out->attrs[out->attr_count++] = a;
		}
		lom_scan_result_free(&pref);
		root_parent = 0;
	}

	for(size_t i = 0; i < jobs; i++) {
		if(js[i].st != LOM_OK || !merge_piece(out, &js[i].local, root_parent, &intern)) {
			intern_free(&intern);
			for(size_t k = 0; k < jobs; k++) lom_scan_result_free(&js[k].local);
			free(js); free(th);
			lom_status st = scan_bytes(code, 0, code_len, out, capture_attributes, true);
			if(st == LOM_OK) scan_trim(out);
			return st;
		}
		lom_scan_result_free(&js[i].local);
	}
	intern_free(&intern);
	free(js); free(th);
	scan_trim(out);
	out->status = LOM_OK;
	return LOM_OK;
}

lom_status lom_scan_indexes(
	const char *code,
	size_t code_len,
	lom_scan_result *out,
	bool capture_attributes
) {
	if(!code || !out) return LOM_ERR_ARG;
	if(env_parallel_on()) {
		return scan_parallel(code, code_len, out, capture_attributes);
	}
	lom_status st = scan_bytes(code, 0, code_len, out, capture_attributes, true);
	if(st == LOM_OK) scan_trim(out);
	return st;
}

