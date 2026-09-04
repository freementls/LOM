#include "lom.h"
#include "lom_fss.h"

#include <ctype.h>
#include <errno.h>
#include <fcntl.h>
#include <stdio.h>
#include <stdlib.h>
#include <stdint.h>
#include <string.h>
#include <pthread.h>
#include <unistd.h>
#include <sys/mman.h>

const char *lom_version(void) {
	return "0.2.5-enc";
}

void lom_scan_result_init(lom_scan_result *r) {
	memset(r, 0, sizeof(*r));
	r->status = LOM_OK;
	r->opens_fd = -1;
}

static void opens_release(lom_scan_result *r) {
	if(!r) return;
	if(r->opens_is_mmap && r->opens) {
		munmap(r->opens, r->opens_map_bytes ? r->opens_map_bytes : r->open_cap * sizeof(lom_open_row));
	} else {
		free(r->opens);
	}
	if(r->opens_fd >= 0) {
		close(r->opens_fd);
	}
	r->opens = NULL;
	r->open_count = 0;
	r->open_cap = 0;
	r->opens_fd = -1;
	r->opens_map_bytes = 0;
	r->opens_is_mmap = 0;
}

void lom_scan_result_free(lom_scan_result *r) {
	if(!r) return;
	opens_release(r);
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

/* Prefer file-backed opens once the document is large enough that a heap
 * open table would dominate RSS. LOM_OPEN_MMAP=0 disables; =1 forces. */
static int opens_mmap_wanted(size_t code_span) {
	const char *v = getenv("LOM_OPEN_MMAP");
	if(v && v[0]) {
		if(v[0] == '0' || v[0] == 'f' || v[0] == 'F' || v[0] == 'n' || v[0] == 'N')
			return 0;
		return 1;
	}
	return code_span >= (size_t)512 * 1024 * 1024;
}

static bool opens_ensure(lom_scan_result *r, size_t need, size_t code_span_hint) {
	if(need <= r->open_cap) return true;
	size_t ncap = r->open_cap ? r->open_cap : 64;
	while(ncap < need) ncap *= 2;
	size_t nbytes = ncap * sizeof(lom_open_row);

	if(r->opens_is_mmap || (!r->opens && opens_mmap_wanted(code_span_hint))) {
		if(!r->opens_is_mmap) {
			char tmpl[512];
			const char *dir = getenv("LOM_OPEN_TMPDIR");
			if(!dir || !dir[0]) dir = getenv("TMPDIR");
			/* /tmp is often small tmpfs — prefer /var/tmp for multi-GB open tables. */
			if(!dir || !dir[0] || strcmp(dir, "/tmp") == 0) dir = "/var/tmp";
			snprintf(tmpl, sizeof(tmpl), "%s/lom_opens_XXXXXX", dir);
			int fd = mkstemp(tmpl);
			if(fd < 0) {
				snprintf(tmpl, sizeof(tmpl), "/tmp/lom_opens_XXXXXX");
				fd = mkstemp(tmpl);
			}
			if(fd < 0) return false;
			unlink(tmpl);
			if(ftruncate(fd, (off_t)nbytes) != 0) {
				close(fd);
				return false;
			}
			void *p = mmap(NULL, nbytes, PROT_READ | PROT_WRITE, MAP_SHARED, fd, 0);
			if(p == MAP_FAILED) {
				close(fd);
				return false;
			}
			r->opens = p;
			r->opens_fd = fd;
			r->opens_map_bytes = nbytes;
			r->opens_is_mmap = 1;
			r->open_cap = ncap;
			return true;
		}
		if(ftruncate(r->opens_fd, (off_t)nbytes) != 0) return false;
#ifdef MREMAP_MAYMOVE
		void *np = mremap(r->opens, r->opens_map_bytes, nbytes, MREMAP_MAYMOVE);
		if(np == MAP_FAILED) return false;
#else
		void *np = mmap(NULL, nbytes, PROT_READ | PROT_WRITE, MAP_SHARED, r->opens_fd, 0);
		if(np == MAP_FAILED) return false;
		munmap(r->opens, r->opens_map_bytes);
#endif
		r->opens = np;
		r->opens_map_bytes = nbytes;
		r->open_cap = ncap;
		return true;
	}

	lom_open_row *p = realloc(r->opens, nbytes);
	if(!p) return false;
	r->opens = p;
	r->open_cap = ncap;
	return true;
}

static void opens_dontneed_prefix(lom_scan_result *r, size_t live_lo) {
	if(!r || !r->opens_is_mmap || !r->opens || live_lo < 4096) return;
	size_t bytes = (live_lo * sizeof(lom_open_row)) & ~(size_t)4095;
	if(bytes < 4096) return;
	(void)madvise(r->opens, bytes, MADV_DONTNEED);
}

static void code_dontneed_prefix(const char *code, size_t code_is_mmap, size_t live_lo) {
	if(!code || !code_is_mmap || live_lo < (size_t)1024 * 1024) return;
	size_t bytes = live_lo & ~(size_t)((1u << 20) - 1); /* 1MiB align */
	if(bytes < (size_t)1024 * 1024) return;
	(void)madvise((void *)(uintptr_t)code, bytes, MADV_DONTNEED);
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
	if(est_opens > 0 && !opens_ensure(out, out->open_count + est_opens, span)) {
		intern_free(&intern);
		out->status = LOM_ERR_NOMEM;
		return LOM_ERR_NOMEM;
	}
	size_t est_attrs = capture_attributes ? (est_opens / 2 + 64) : 0;
	if(est_attrs && !grow_cap((void **)&out->attrs, sizeof(lom_attr_row), &out->attr_cap, out->attr_count + est_attrs)) {
		intern_free(&intern);
		out->status = LOM_ERR_NOMEM;
		return LOM_ERR_NOMEM;
	}

	uint32_t empty_id = 0;
	size_t *ostack = NULL;
	size_t ostack_n = 0, ostack_cap = 0;
	size_t offset = lo;
	size_t last_open_advise = 0;
	size_t last_code_advise = lo;
	/* Hint: caller may pass mmap'd code; DONTNEED is safe on file-backed maps. */
	int code_may_dontneed = (span >= (size_t)64 * 1024 * 1024);

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

		if(!opens_ensure(out, out->open_count + 1, span)) {
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

		/* Drop completed open-table / document prefixes to keep RSS low on huge files. */
		if(out->opens_is_mmap && out->open_count - last_open_advise > 65536) {
			size_t live = out->open_count;
			for(size_t s = 0; s < ostack_n; s++) {
				if(ostack[s] < live) live = ostack[s];
			}
			if(live > last_open_advise + 4096) {
				opens_dontneed_prefix(out, live);
				last_open_advise = live;
			}
		}
		if(code_may_dontneed && offset - last_code_advise > (size_t)8 * 1024 * 1024) {
			code_dontneed_prefix(code, 1, offset > (size_t)2 * 1024 * 1024 ? offset - (size_t)2 * 1024 * 1024 : 0);
			last_code_advise = offset;
		}
	}

	while(ostack_n > 0) {
		size_t oi = ostack[--ostack_n];
		out->opens[oi].node_end_off = (int64_t)(hi ? hi - 1 : 0);
	}

	if(out->opens_is_mmap && out->open_count > 0) {
		opens_dontneed_prefix(out, out->open_count);
	}
	if(code_may_dontneed && hi > lo) {
		code_dontneed_prefix(code, 1, hi);
	}

	intern_free(&intern);
	free(ostack);
	out->status = LOM_OK;
	return LOM_OK;
}

static void scan_trim(lom_scan_result *out) {
	if(out->open_count && out->open_cap > out->open_count) {
		if(out->opens_is_mmap) {
			size_t nbytes = out->open_count * sizeof(lom_open_row);
			if(nbytes == 0) nbytes = sizeof(lom_open_row);
			if(ftruncate(out->opens_fd, (off_t)nbytes) == 0) {
#ifdef MREMAP_MAYMOVE
				void *np = mremap(out->opens, out->opens_map_bytes, nbytes, MREMAP_MAYMOVE);
				if(np != MAP_FAILED) {
					out->opens = np;
					out->opens_map_bytes = nbytes;
					out->open_cap = out->open_count;
				}
#else
				out->opens_map_bytes = nbytes;
				out->open_cap = out->open_count;
#endif
			}
		} else {
			lom_open_row *slim = realloc(out->opens, out->open_count * sizeof(lom_open_row));
			if(slim) { out->opens = slim; out->open_cap = out->open_count; }
		}
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

/* Dedup only tag/attr *names* (tiny); append attr values (partition-local, no rehash). */
static bool merge_piece(lom_scan_result *dst, lom_scan_result *src, int32_t root_parent, lom_intern *intern) {
	if(src->status != LOM_OK) return false;
	size_t nstr = src->string_count ? src->string_count : 1;
	uint32_t *idmap = malloc(nstr * sizeof(uint32_t));
	if(!idmap) return false;
	for(size_t i = 0; i < nstr; i++) idmap[i] = UINT32_MAX;
	idmap[0] = 0;

	for(size_t i = 0; i < src->open_count; i++) {
		uint32_t lid = src->opens[i].name_id;
		if(lid >= nstr) { free(idmap); return false; }
		if(idmap[lid] != UINT32_MAX) continue;
		const char *s = lom_scan_string(src, lid);
		size_t n = strlen(s);
		uint32_t gid;
		if(!intern_get(intern, dst, s, n, &gid)) { free(idmap); return false; }
		idmap[lid] = gid;
	}
	for(size_t i = 0; i < src->attr_count; i++) {
		uint32_t lid = src->attrs[i].name_id;
		if(lid >= nstr) { free(idmap); return false; }
		if(idmap[lid] != UINT32_MAX) continue;
		const char *s = lom_scan_string(src, lid);
		size_t n = strlen(s);
		uint32_t gid;
		if(!intern_get(intern, dst, s, n, &gid)) { free(idmap); return false; }
		idmap[lid] = gid;
	}
	for(size_t i = 0; i < src->attr_count; i++) {
		uint32_t lid = src->attrs[i].value_id;
		if(lid >= nstr) { free(idmap); return false; }
		if(idmap[lid] != UINT32_MAX) continue;
		const char *s = lom_scan_string(src, lid);
		size_t n = strlen(s);
		uint32_t gid;
		if(!string_push(dst, s, n, &gid)) { free(idmap); return false; }
		idmap[lid] = gid;
	}

	size_t open_base = dst->open_count;
	if(!opens_ensure(dst, open_base + src->open_count, 0)) {
		free(idmap); return false;
	}
	for(size_t i = 0; i < src->open_count; i++) {
		lom_open_row row = src->opens[i];
		if(idmap[row.name_id] == UINT32_MAX) { free(idmap); return false; }
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
		if(idmap[a.name_id] == UINT32_MAX || idmap[a.value_id] == UINT32_MAX) { free(idmap); return false; }
		a.name_id = idmap[a.name_id];
		a.value_id = idmap[a.value_id];
		a.open_idx = (uint32_t)((size_t)a.open_idx + open_base);
		dst->attrs[dst->attr_count++] = a;
	}
	dst->self_closing_count += src->self_closing_count;
	free(idmap);
	return true;
}

static lom_byte_range *collect_ranges(const char *code, size_t code_len, int *inject_root, size_t *out_n, size_t *root_end) {
	*inject_root = 0;
	*out_n = 0;
	/* Prefer one fill pass: estimate capacity from size, collect depth-1 (single-root docs). */
	size_t cap = code_len / 8192 + 256;
	if(cap < 64) cap = 64;
	lom_byte_range *ranges = malloc(cap * sizeof(lom_byte_range));
	if(!ranges) return NULL;

	size_t n1 = find_sibling_ranges(code, code_len, 1, ranges, cap, root_end);
	while(n1 >= cap) {
		cap = n1 + 1024;
		lom_byte_range *nr = realloc(ranges, cap * sizeof(lom_byte_range));
		if(!nr) { free(ranges); return NULL; }
		ranges = nr;
		n1 = find_sibling_ranges(code, code_len, 1, ranges, cap, root_end);
	}
	if(n1 >= 2) {
		*inject_root = 1;
		*out_n = n1;
		return ranges;
	}

	size_t n0 = find_sibling_ranges(code, code_len, 0, ranges, cap, root_end);
	while(n0 >= cap) {
		cap = n0 + 1024;
		lom_byte_range *nr = realloc(ranges, cap * sizeof(lom_byte_range));
		if(!nr) { free(ranges); return NULL; }
		ranges = nr;
		n0 = find_sibling_ranges(code, code_len, 0, ranges, cap, root_end);
	}
	*out_n = n0;
	return ranges;
}

static lom_status scan_parallel(const char *code, size_t code_len, lom_scan_result *out, bool capture_attributes) {
	enum { MAX_JOBS = 8 };
	/* Name-only dedup merge reaches ~parity at ~100MB; at ≥256MB still loses to serial. */
	if(code_len < (4u << 20) || code_len >= (256u << 20)) {
		lom_status st = scan_bytes(code, 0, code_len, out, capture_attributes, true);
		if(st == LOM_OK) scan_trim(out);
		return st;
	}
	size_t root_end = code_len ? code_len - 1 : 0;
	int inject_root = 0;
	size_t n = 0;
	lom_byte_range *ranges = collect_ranges(code, code_len, &inject_root, &n, &root_end);
	if(!ranges || n < 2) {
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
		if(!opens_ensure(out, 1, 0)) {
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

