#include "lom.h"

#include <ctype.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

const char *lom_version(void) {
	return "0.2.0-full";
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
		r->attrs[r->attr_count].name_id = nid;
		r->attrs[r->attr_count].value_id = vid;
		r->attr_count++;
	}
	return true;
}

lom_status lom_scan_indexes(
	const char *code,
	size_t code_len,
	lom_scan_result *out,
	bool capture_attributes
) {
	if(!code || !out) return LOM_ERR_ARG;
	lom_scan_result_free(out);
	lom_scan_result_init(out);

	lom_intern intern;
	if(!intern_init(&intern, 1024)) {
		out->status = LOM_ERR_NOMEM;
		snprintf(out->error, sizeof(out->error), "intern alloc failed");
		return LOM_ERR_NOMEM;
	}

	/* Ensure empty tagname id 0 exists for nameless edge cases. */
	uint32_t empty_id;
	if(!string_push(out, "", 0, &empty_id)) {
		intern_free(&intern);
		out->status = LOM_ERR_NOMEM;
		return LOM_ERR_NOMEM;
	}

	size_t *ostack = NULL;
	size_t ostack_n = 0, ostack_cap = 0;
	size_t offset = 0;

	while(offset < code_len) {
		const char *p = (const char *)memchr(code + offset, '<', code_len - offset);
		if(!p) break;
		offset = (size_t)(p - code);
		if(offset + 1 >= code_len) break;
		char next = code[offset + 1];

		if(next == '!') {
			if(offset + 4 <= code_len && memcmp(code + offset, "<!--", 4) == 0) {
				const char *end = (const char *)memmem(code + offset + 4, code_len - (offset + 4), "-->", 3);
				if(!end) break;
				offset = (size_t)(end - code) + 3;
				continue;
			}
			if(offset + 9 <= code_len && memcmp(code + offset, "<![CDATA[", 9) == 0) {
				const char *end = (const char *)memmem(code + offset + 9, code_len - (offset + 9), "]]>", 3);
				if(!end) break;
				offset = (size_t)(end - code) + 3;
				continue;
			}
			if(is_doctype_open(code, offset, code_len)) {
				size_t end = find_tag_close(code, code_len, offset);
				if(end == (size_t)-1) break;
				offset = end + 1;
				continue;
			}
		}
		if(next == '?') {
			const char *end = (const char *)memmem(code + offset + 2, code_len - (offset + 2), "?>", 2);
			if(!end) break;
			offset = (size_t)(end - code) + 2;
			continue;
		}
		if(next == '%') {
			const char *end = (const char *)memmem(code + offset + 2, code_len - (offset + 2), "%>", 2);
			if(!end) break;
			offset = (size_t)(end - code) + 2;
			continue;
		}

		size_t tag_end = find_tag_close(code, code_len, offset);
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
		row->parent_off = (ostack_n > 0) ? out->opens[ostack[ostack_n - 1]].open_off : (int64_t)-1;
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
				if(!scan_attrs_in_tag(out, &intern, code, offset, tag_end, name_start + name_len)) {
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
		out->opens[oi].node_end_off = (int64_t)(code_len ? code_len - 1 : 0);
	}

	intern_free(&intern);
	free(ostack);
	out->status = LOM_OK;
	return LOM_OK;
}
