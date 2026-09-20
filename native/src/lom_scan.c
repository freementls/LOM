#include "lom.h"
#include "lom_fss.h"

#include <ctype.h>
#include <errno.h>
#include <fcntl.h>
#include <stdio.h>
#include <stdlib.h>
#include <stdint.h>
#include <string.h>
#include <strings.h>
#include <pthread.h>
#include <unistd.h>
#include <sys/mman.h>
#include <time.h>

const char *lom_version(void) {
	return "0.3.2-ois";
}

_Static_assert(sizeof(lom_open_row) == 24, "lom_open_row must stay 24 bytes");

static size_t mem_available_bytes(void);

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

/* Move anonymous open table to a file-backed map so MADV_DONTNEED can drop RSS
 * without zeroing content. No-op if already file-backed or heap. */
int lom_scan_opens_spill_to_file(lom_scan_result *r) {
	if(!r || !r->opens || !r->opens_is_mmap || r->opens_fd >= 0) return 0;
	size_t nbytes = r->open_count * sizeof(lom_open_row);
	if(nbytes == 0) nbytes = sizeof(lom_open_row);
	char tmpl[512];
	const char *dir = getenv("LOM_OPEN_TMPDIR");
	if(!dir || !dir[0]) dir = getenv("TMPDIR");
	if(!dir || !dir[0] || strcmp(dir, "/tmp") == 0) dir = "/var/tmp";
	snprintf(tmpl, sizeof(tmpl), "%s/lom_opens_XXXXXX", dir);
	int fd = mkstemp(tmpl);
	if(fd < 0) {
		snprintf(tmpl, sizeof(tmpl), "/tmp/lom_opens_XXXXXX");
		fd = mkstemp(tmpl);
	}
	if(fd < 0) return -1;
	unlink(tmpl);
	if(ftruncate(fd, (off_t)nbytes) != 0) { close(fd); return -1; }
	void *p = mmap(NULL, nbytes, PROT_READ | PROT_WRITE, MAP_SHARED, fd, 0);
	if(p == MAP_FAILED) { close(fd); return -1; }
	memcpy(p, r->opens, r->open_count * sizeof(lom_open_row));
	munmap(r->opens, r->opens_map_bytes ? r->opens_map_bytes : r->open_cap * sizeof(lom_open_row));
	r->opens = p;
	r->opens_fd = fd;
	r->opens_map_bytes = nbytes;
	r->open_cap = r->open_count;
	return 0;
}

void lom_scan_result_free(lom_scan_result *r) {
	if(!r) return;
	opens_release(r);
	free(r->ne_ovf_rel);
	free(r->attrs);
	free(r->string_blob);
	free(r->string_offs);
	if(r->recipe_owned) {
		free(r->recipe_tmpl);
		free(r->recipe_starts);
		free(r->recipe_ends);
		free(r->recipe_tile_cls);
	}
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

/* Prefer mmap'd opens for medium+ docs.
 * LOM_OPEN_MMAP=0 → heap realloc; =1 → force file-backed; =a → force anon.
 * Default: anon while the open table is likely <~512 MB (faster construct);
 * file-backed at GB-class so idle RSS can drop after index build. */
static int opens_mmap_wanted(size_t code_span) {
	const char *v = getenv("LOM_OPEN_MMAP");
	if(v && v[0]) {
		if(v[0] == '0' || v[0] == 'f' || v[0] == 'F' || v[0] == 'n' || v[0] == 'N')
			return 0;
		return 1;
	}
	return code_span >= (size_t)64 * 1024 * 1024;
}

static int opens_file_backed(size_t code_span) {
	const char *v = getenv("LOM_OPEN_MMAP");
	if(v && v[0]) {
		if(v[0] == 'a' || v[0] == 'A') return 0;
		if(v[0] == '1' || v[0] == 'y' || v[0] == 'Y') return 1;
	}
	/* Mid-size: anon (faster construct). GB-class: file-backed for droppable RSS,
	 * unless MemAvailable comfortably covers code + open table + 1 GiB slack. */
	if(code_span < (size_t)512 * 1024 * 1024) return 0;
	size_t open_est = (code_span / 24 + 64) * sizeof(lom_open_row);
	size_t need = code_span + open_est + ((size_t)1 << 30);
	size_t avail = mem_available_bytes();
	if(avail && avail >= need + open_est) return 0; /* hardware-gated anon */
	return 1;
}

static bool opens_ensure(lom_scan_result *r, size_t need, size_t code_span_hint) {
	if(need <= r->open_cap) return true;
	size_t ncap = r->open_cap ? r->open_cap : 64;
	while(ncap < need) ncap *= 2;
	size_t nbytes = ncap * sizeof(lom_open_row);

	if(r->opens_is_mmap || (!r->opens && opens_mmap_wanted(code_span_hint))) {
		if(!r->opens_is_mmap) {
			int file_backed = opens_file_backed(code_span_hint);
			int fd = -1;
			void *p = MAP_FAILED;
			if(file_backed) {
				char tmpl[512];
				const char *dir = getenv("LOM_OPEN_TMPDIR");
				if(!dir || !dir[0]) dir = getenv("TMPDIR");
				/* /tmp is often small tmpfs — prefer /var/tmp for multi-GB open tables. */
				if(!dir || !dir[0] || strcmp(dir, "/tmp") == 0) dir = "/var/tmp";
				snprintf(tmpl, sizeof(tmpl), "%s/lom_opens_XXXXXX", dir);
				fd = mkstemp(tmpl);
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
				p = mmap(NULL, nbytes, PROT_READ | PROT_WRITE, MAP_SHARED, fd, 0);
				if(p == MAP_FAILED) {
					close(fd);
					return false;
				}
			} else {
				p = mmap(NULL, nbytes, PROT_READ | PROT_WRITE,
					MAP_PRIVATE | MAP_ANONYMOUS, -1, 0);
				if(p == MAP_FAILED) return false;
			}
			(void)madvise(p, nbytes, MADV_SEQUENTIAL);
#ifdef MADV_HUGEPAGE
			(void)madvise(p, nbytes, MADV_HUGEPAGE);
#endif
			r->opens = p;
			r->opens_fd = fd;
			r->opens_map_bytes = nbytes;
			r->opens_is_mmap = 1;
			r->open_cap = ncap;
			return true;
		}
		if(r->opens_fd >= 0 && ftruncate(r->opens_fd, (off_t)nbytes) != 0)
			return false;
#ifdef MREMAP_MAYMOVE
		void *np = mremap(r->opens, r->opens_map_bytes, nbytes, MREMAP_MAYMOVE);
		if(np == MAP_FAILED) return false;
#else
		void *np = MAP_FAILED;
		if(r->opens_fd >= 0)
			np = mmap(NULL, nbytes, PROT_READ | PROT_WRITE, MAP_SHARED, r->opens_fd, 0);
		else
			np = mmap(NULL, nbytes, PROT_READ | PROT_WRITE, MAP_PRIVATE | MAP_ANONYMOUS, -1, 0);
		if(np == MAP_FAILED) return false;
		munmap(r->opens, r->opens_map_bytes);
#endif
		(void)madvise(np, nbytes, MADV_SEQUENTIAL);
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
	/* Anonymous pages are destroyed by DONTNEED (zero-fill on fault). File-backed only. */
	if(!r || !r->opens_is_mmap || r->opens_fd < 0 || !r->opens || live_lo < 4096) return;
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

static bool intern_insert_id(lom_intern *t, const lom_scan_result *r, uint32_t id) {
	const char *s = lom_scan_string(r, id);
	size_t n = strlen(s);
	if(t->used * 2 >= t->cap) {
		size_t ncap = t->cap * 2;
		uint32_t *nh = calloc(ncap, sizeof(uint32_t));
		uint32_t *nid = calloc(ncap, sizeof(uint32_t));
		if(!nh || !nid) { free(nh); free(nid); return false; }
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
	while(t->keys_hash[i]) i = (i + 1) % t->cap;
	t->keys_hash[i] = h;
	t->ids[i] = id;
	t->used++;
	return true;
}

static bool intern_seed(lom_intern *t, const lom_scan_result *r) {
	for(size_t s = 0; s < r->string_count; s++) {
		if(!intern_insert_id(t, r, (uint32_t)s)) return false;
	}
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
	/* Unquoted: look for '>'. Quoted attr values: memchr to closing quote. */
	for(size_t offset = tag_offset + 1; offset < length; offset++) {
		char c = code[offset];
		if(c == '>') return offset;
		if(c == '"' || c == '\'') {
			const char *end = (const char *)memchr(code + offset + 1, c, length - (offset + 1));
			if(!end) return (size_t)-1;
			offset = (size_t)(end - code); /* for-loop ++ lands after quote */
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

static lom_status scan_bytes_ex(
	const char *code,
	size_t lo,
	size_t hi,
	lom_scan_result *out,
	bool capture_attributes,
	bool reset_out,
	const uint32_t *fixed_nids,
	size_t fixed_n,
	int32_t root_parent,
	lom_intern *shared
);

static lom_status scan_bytes(
	const char *code,
	size_t lo,
	size_t hi,
	lom_scan_result *out,
	bool capture_attributes,
	bool reset_out
) {
	return scan_bytes_ex(code, lo, hi, out, capture_attributes, reset_out, NULL, 0, -1, NULL);
}

static lom_status scan_bytes_ex(
	const char *code,
	size_t lo,
	size_t hi,
	lom_scan_result *out,
	bool capture_attributes,
	bool reset_out,
	const uint32_t *fixed_nids,
	size_t fixed_n,
	int32_t root_parent,
	lom_intern *shared
) {
	if(!code || !out) return LOM_ERR_ARG;
	if(lo > hi) return LOM_ERR_ARG;
	if(reset_out) {
		lom_scan_result_free(out);
		lom_scan_result_init(out);
	}

	lom_intern local;
	lom_intern *ip = shared;
	int own_intern = 0;
	int use_intern = !fixed_nids || capture_attributes;
	if(use_intern) {
		if(!ip) {
			memset(&local, 0, sizeof(local));
			ip = &local;
			own_intern = 1;
		}
		if(!ip->keys_hash) {
			if(!intern_init(ip, 1024 + out->string_count * 2)) {
				out->status = LOM_ERR_NOMEM;
				snprintf(out->error, sizeof(out->error), "intern alloc failed");
				return LOM_ERR_NOMEM;
			}
			if(out->string_count && !intern_seed(ip, out)) {
				if(own_intern) intern_free(ip);
				out->status = LOM_ERR_NOMEM;
				return LOM_ERR_NOMEM;
			}
		}
	} else {
		memset(&local, 0, sizeof(local));
		if(!ip) ip = &local;
	}
	size_t tmpl_k = 0;

	if(out->string_count == 0) {
		uint32_t empty_id;
		if(!string_push(out, "", 0, &empty_id)) {
			if(own_intern) intern_free(ip);
			out->status = LOM_ERR_NOMEM;
			return LOM_ERR_NOMEM;
		}
	}

	size_t span = (hi > lo) ? (hi - lo) : 0;
	size_t est_opens = span / 24 + 64;
	if(est_opens > 0 && !opens_ensure(out, out->open_count + est_opens, span)) {
		if(own_intern) intern_free(ip);
		out->status = LOM_ERR_NOMEM;
		return LOM_ERR_NOMEM;
	}
	if(span >= (size_t)4 * 1024 * 1024)
		(void)madvise((void *)(uintptr_t)(code + lo), span, MADV_SEQUENTIAL);
	size_t est_attrs = capture_attributes ? (est_opens / 2 + 64) : 0;
	if(est_attrs && !grow_cap((void **)&out->attrs, sizeof(lom_attr_row), &out->attr_cap, out->attr_count + est_attrs)) {
		if(own_intern) intern_free(ip);
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

		if(next == '/') {
			/* Closing tags have no attributes — memchr straight to '>'. */
			if(offset + 2 >= hi) break;
			const char *gt = (const char *)memchr(code + offset + 2, '>', hi - (offset + 2));
			if(!gt) {
				if(own_intern) intern_free(ip);
				free(ostack);
				out->status = LOM_ERR_PARSE;
				snprintf(out->error, sizeof(out->error), "unclosed tag at %zu", offset);
				return LOM_ERR_PARSE;
			}
			size_t tag_end = (size_t)(gt - code);
			if(ostack_n > 0) {
				size_t oi = ostack[--ostack_n];
				(void)lom_open_set_node_end(out, &out->opens[oi], (int64_t)tag_end);
			}
			offset = tag_end + 1;
			continue;
		}

		size_t tag_end = find_tag_close(code, hi, offset);
		if(tag_end == (size_t)-1) {
			if(own_intern) intern_free(ip);
			free(ostack);
			out->status = LOM_ERR_PARSE;
			snprintf(out->error, sizeof(out->error), "unclosed tag at %zu", offset);
			return LOM_ERR_PARSE;
		}

		if(out->open_count >= out->open_cap && !opens_ensure(out, out->open_count + 1, span)) {
			if(own_intern) intern_free(ip);
			free(ostack);
			out->status = LOM_ERR_NOMEM;
			return LOM_ERR_NOMEM;
		}
		size_t oi = out->open_count++;
		lom_open_row *row = &out->opens[oi];
		row->open_off = (int64_t)offset;
		lom_open_set_tag_end(row, (int64_t)tag_end);
		row->parent_idx = (ostack_n > 0) ? (int32_t)ostack[ostack_n - 1] : root_parent;
		row->node_end_rel = 0;
		row->self_closing = 0;
		row->name_id = empty_id;
		row->_pad = 0;

		size_t name_start = 0;
		size_t name_len = extract_tagname(code, offset, tag_end, &name_start);
		if(fixed_nids) {
			if(tmpl_k >= fixed_n) {
				if(own_intern) intern_free(ip);
				free(ostack);
				out->status = LOM_ERR_PARSE;
				snprintf(out->error, sizeof(out->error), "tile template overrun at %zu", offset);
				return LOM_ERR_PARSE;
			}
			row->name_id = fixed_nids[tmpl_k++];
		} else if(name_len > 0) {
			uint32_t nid;
			if(!intern_get(ip, out, code + name_start, name_len, &nid)) {
				if(own_intern) intern_free(ip);
				free(ostack);
				out->status = LOM_ERR_NOMEM;
				return LOM_ERR_NOMEM;
			}
			row->name_id = nid;
			if(capture_attributes) {
				if(!scan_attrs_in_tag(out, ip, code, offset, (uint32_t)oi, tag_end, name_start + name_len)) {
					if(own_intern) intern_free(ip);
					free(ostack);
					out->status = LOM_ERR_NOMEM;
					return LOM_ERR_NOMEM;
				}
			}
		}

		if(tag_is_self_closing(code, offset, tag_end)) {
			row->self_closing = LOM_OPEN_SC;
			(void)lom_open_set_node_end(out, row, (int64_t)tag_end);
			out->self_closing_count++;
		} else {
			if(!grow_cap((void **)&ostack, sizeof(size_t), &ostack_cap, ostack_n + 1)) {
				if(own_intern) intern_free(ip);
				free(ostack);
				out->status = LOM_ERR_NOMEM;
				return LOM_ERR_NOMEM;
			}
			ostack[ostack_n++] = oi;
		}
		offset = tag_end + 1;

		/* Drop completed open-table / document prefixes to keep RSS low on huge files. */
		if(out->opens_is_mmap && out->open_count >= (size_t)100000000 &&
		   out->open_count - last_open_advise > 65536) {
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
		(void)lom_open_set_node_end(out, &out->opens[oi], (int64_t)(hi ? hi - 1 : 0));
	}

	/* Keep opens warm into aux index build when the table fits comfortably.
	 * Huge tables (≥100M opens) still drop so construct RSS stays low. */
	if(out->opens_is_mmap && out->open_count > 0 &&
	   out->open_count >= (size_t)100000000) {
		opens_dontneed_prefix(out, out->open_count);
	}
	if(code_may_dontneed && hi > lo) {
		code_dontneed_prefix(code, 1, hi);
	}

	if(own_intern) intern_free(ip);
	free(ostack);
	if(fixed_nids && tmpl_k != fixed_n) {
		out->status = LOM_ERR_PARSE;
		snprintf(out->error, sizeof(out->error), "tile template short (%zu/%zu)", tmpl_k, fixed_n);
		return LOM_ERR_PARSE;
	}
	out->status = LOM_OK;
	return LOM_OK;
}

static void scan_trim(lom_scan_result *out) {
	if(out->open_count && out->open_cap > out->open_count) {
		if(out->opens_is_mmap) {
			size_t nbytes = out->open_count * sizeof(lom_open_row);
			if(nbytes == 0) nbytes = sizeof(lom_open_row);
			int ok = 1;
			if(out->opens_fd >= 0)
				ok = (ftruncate(out->opens_fd, (off_t)nbytes) == 0);
			if(ok) {
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
static bool env_parallel_requested(void) {
	const char *e = getenv("LOM_PARALLEL");
	if(!e || !*e) return true; /* default on; hardware gate may still force serial */
	if(e[0] == '0' && e[1] == 0) return false;
	if(e[0] == 'f' || e[0] == 'F' || e[0] == 'n' || e[0] == 'N') return false;
	if(strcasecmp(e, "off") == 0 || strcasecmp(e, "no") == 0 || strcasecmp(e, "false") == 0)
		return false;
	return true;
}

static bool env_tile_requested(void) {
	const char *e = getenv("LOM_TILE");
	if(!e || !*e) return true;
	if(e[0] == '0' && e[1] == 0) return false;
	if(e[0] == 'f' || e[0] == 'F' || e[0] == 'n' || e[0] == 'N') return false;
	if(strcasecmp(e, "off") == 0 || strcasecmp(e, "no") == 0 || strcasecmp(e, "false") == 0)
		return false;
	return true;
}

/* Default on. LOM_FRACTAL=0 keeps wholesale tile replay (emit every row). */
static bool env_fractal_requested(void) {
	const char *e = getenv("LOM_FRACTAL");
	if(!e || !*e) return true;
	if(e[0] == '0' && e[1] == 0) return false;
	if(e[0] == 'f' || e[0] == 'F' || e[0] == 'n' || e[0] == 'N') return false;
	if(strcasecmp(e, "off") == 0 || strcasecmp(e, "no") == 0 || strcasecmp(e, "false") == 0)
		return false;
	return true;
}

static double scan_now_ms(void) {
	struct timespec ts;
	clock_gettime(CLOCK_MONOTONIC, &ts);
	return ts.tv_sec * 1000.0 + ts.tv_nsec / 1e6;
}

/* True if the span has an element open (not a closer, comment, PI, or doctype). */
static int span_has_element_open(const char *code, size_t lo, size_t hi) {
	if(!code || lo + 1 >= hi) return 0;
	size_t i = lo;
	while(i + 1 < hi) {
		const char *p = (const char *)memchr(code + i, '<', hi - i);
		if(!p) return 0;
		i = (size_t)(p - code);
		if(i + 1 >= hi) return 0;
		char n = code[i + 1];
		if(n != '/' && n != '!' && n != '?' && n != '%') return 1;
		i++;
	}
	return 0;
}

static lom_status scan_leftover_span(const char *code, size_t lo, size_t hi,
	lom_scan_result *out, lom_intern *shared) {
	if(!span_has_element_open(code, lo, hi)) return LOM_OK;
	return scan_bytes_ex(code, lo, hi, out, false, false, NULL, 0, -1, shared);
}

static size_t mem_available_bytes(void) {
	FILE *f = fopen("/proc/meminfo", "r");
	if(!f) return 0;
	char line[256];
	size_t avail_kb = 0, total_kb = 0;
	while(fgets(line, sizeof line, f)) {
		unsigned long v = 0;
		if(sscanf(line, "MemAvailable: %lu", &v) == 1) avail_kb = (size_t)v;
		else if(sscanf(line, "MemTotal: %lu", &v) == 1) total_kb = (size_t)v;
	}
	fclose(f);
	if(avail_kb) return avail_kb * 1024ull;
	return (total_kb / 2) * 1024ull; /* crude fallback */
}

/* Parallel is opt-in only. Hardware gate: enough CPUs + RAM for workers + merge.
 * Peak ≈ shared code + ~2× open-table (workers then dest) + slack. */
static int parallel_hw_ok(size_t code_len, long *out_ncpu) {
	long ncpu = sysconf(_SC_NPROCESSORS_ONLN);
	if(ncpu < 1) ncpu = 1;
	if(out_ncpu) *out_ncpu = ncpu;
	if(ncpu < 4) return 0;
	size_t open_est = (code_len / 24 + 64) * sizeof(lom_open_row);
	size_t need = code_len + 2 * open_est + ((size_t)512 << 20);
	size_t avail = mem_available_bytes();
	if(avail && avail < need) return 0;
	return 1;
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

static int name_open_at(const char *code, size_t off, size_t len, const char *name, size_t nlen) {
	if(off + 1 + nlen >= len || code[off] != '<') return 0;
	if(memcmp(code + off + 1, name, nlen) != 0) return 0;
	unsigned char c = (unsigned char)code[off + 1 + nlen];
	return c == '>' || c == '/' || c == ' ' || c == '\t' || c == '\n' || c == '\r';
}

static int name_close_at(const char *code, size_t off, size_t len, const char *name, size_t nlen) {
	if(off + 2 + nlen >= len || code[off] != '<' || code[off + 1] != '/') return 0;
	if(memcmp(code + off + 2, name, nlen) != 0) return 0;
	unsigned char c = (unsigned char)code[off + 2 + nlen];
	return c == '>' || c == ' ' || c == '\t' || c == '\n' || c == '\r';
}

/* Matching close for `<name…>`, nest-aware. Avoids parsing inner tags. */
static size_t find_named_end(const char *code, size_t len, size_t open_off,
	const char *name, size_t nlen) {
	size_t tag_end = find_tag_close(code, len, open_off);
	if(tag_end == (size_t)-1) return (size_t)-1;
	if(tag_is_self_closing(code, open_off, tag_end)) return tag_end + 1;
	char op[80], cl[80];
	if(nlen + 3 >= sizeof(op)) return (size_t)-1;
	op[0] = '<';
	memcpy(op + 1, name, nlen);
	cl[0] = '<';
	cl[1] = '/';
	memcpy(cl + 2, name, nlen);
	size_t opl = nlen + 1, cll = nlen + 2;
	int nest = 1;
	size_t pos = tag_end + 1;
	while(nest > 0 && pos < len) {
		const void *hc = lom_fss_memmem(code + pos, len - pos, cl, cll);
		if(!hc) return (size_t)-1;
		size_t oc = (size_t)((const char *)hc - code);
		const void *ho = lom_fss_memmem(code + pos, oc - pos, op, opl);
		if(ho) {
			size_t oo = (size_t)((const char *)ho - code);
			if(name_open_at(code, oo, len, name, nlen)) {
				nest++;
				size_t t2 = find_tag_close(code, len, oo);
				pos = (t2 == (size_t)-1) ? oo + 1 : t2 + 1;
				continue;
			}
		}
		if(!name_close_at(code, oc, len, name, nlen)) {
			pos = oc + 1;
			continue;
		}
		nest--;
		size_t t2 = find_tag_close(code, len, oc);
		if(t2 == (size_t)-1) return (size_t)-1;
		if(nest == 0) return t2 + 1;
		pos = t2 + 1;
	}
	return (size_t)-1;
}

typedef struct {
	const char *code;
	size_t code_len, lo, hi;
	const char *name;
	size_t nlen;
	lom_byte_range *ranges;
	size_t n, cap;
	int err;
} named_job;

static void *named_job_main(void *arg) {
	named_job *j = (named_job *)arg;
	char op[80];
	if(j->nlen + 2 >= sizeof(op)) { j->err = 1; return NULL; }
	op[0] = '<';
	memcpy(op + 1, j->name, j->nlen);
	size_t opl = j->nlen + 1;
	size_t search = j->lo;
	size_t hay_end = j->hi + opl;
	if(hay_end > j->code_len) hay_end = j->code_len;
	while(search < j->hi && search + opl <= hay_end) {
		const void *h = lom_fss_memmem(j->code + search, hay_end - search, op, opl);
		if(!h) break;
		size_t off = (size_t)((const char *)h - j->code);
		if(off >= j->hi) break;
		search = off + 1;
		if(!name_open_at(j->code, off, j->code_len, j->name, j->nlen)) continue;
		size_t end = find_named_end(j->code, j->code_len, off, j->name, j->nlen);
		if(end == (size_t)-1) { j->err = 1; return NULL; }
		if(j->n >= j->cap) {
			size_t ncap = j->cap ? j->cap * 2 : 256;
			lom_byte_range *nr = realloc(j->ranges, ncap * sizeof(*nr));
			if(!nr) { j->err = 1; return NULL; }
			j->ranges = nr;
			j->cap = ncap;
		}
		j->ranges[j->n].start = off;
		j->ranges[j->n].end = end;
		j->n++;
	}
	return NULL;
}

static long census_workers(size_t code_len) {
	if(code_len < ((size_t)64 << 20) || !env_parallel_requested()) return 1;
	long n = sysconf(_SC_NPROCESSORS_ONLN);
	if(n < 2) return 1;
	if(n > 16) n = 16;
	return n;
}

static void named_root_end(const char *code, size_t code_len, size_t after, size_t *root_end) {
	if(!root_end) return;
	size_t p = after;
	while(p + 1 < code_len) {
		const char *c = (const char *)memchr(code + p, '<', code_len - p);
		if(!c) break;
		size_t o = (size_t)(c - code);
		if(code[o + 1] == '/') {
			size_t te = find_tag_close(code, code_len, o);
			if(te != (size_t)-1) *root_end = te;
			return;
		}
		p = o + 1;
	}
}

/* Depth-1 siblings that share one tag name: memmem `<name` / `</name>` instead of
 * find_tag_close on every inner tag. Parallel over byte slices (no 2×-open budget). */
static lom_byte_range *collect_named_siblings(const char *code, size_t code_len,
	size_t first_start, const char *name, size_t nlen, size_t *out_n, size_t *root_end) {
	if(!name || nlen == 0 || first_start >= code_len) return NULL;
	long jobs = census_workers(code_len);
	size_t span = code_len - first_start;
	if(jobs > 1 && span / (size_t)jobs < ((size_t)32 << 20)) jobs = 1;
	if(jobs < 2) {
		named_job j;
		memset(&j, 0, sizeof(j));
		j.code = code;
		j.code_len = code_len;
		j.lo = first_start;
		j.hi = code_len;
		j.name = name;
		j.nlen = nlen;
		named_job_main(&j);
		if(j.err || !j.n) { free(j.ranges); return NULL; }
		named_root_end(code, code_len, j.ranges[j.n - 1].end, root_end);
		*out_n = j.n;
		return j.ranges;
	}
	named_job *js = calloc((size_t)jobs, sizeof(named_job));
	pthread_t *th = calloc((size_t)jobs, sizeof(pthread_t));
	if(!js || !th) { free(js); free(th); return NULL; }
	size_t chunk = span / (size_t)jobs;
	int spawned = 0;
	for(long i = 0; i < jobs; i++) {
		js[i].code = code;
		js[i].code_len = code_len;
		js[i].lo = first_start + (size_t)i * chunk;
		js[i].hi = (i + 1 == jobs) ? code_len : first_start + (size_t)(i + 1) * chunk;
		js[i].name = name;
		js[i].nlen = nlen;
		if(pthread_create(&th[i], NULL, named_job_main, &js[i]) != 0) {
			for(long k = 0; k < i; k++) pthread_join(th[k], NULL);
			for(long k = 0; k < i; k++) free(js[k].ranges);
			free(js); free(th);
			return NULL;
		}
		spawned++;
	}
	for(long i = 0; i < spawned; i++) pthread_join(th[i], NULL);
	size_t total = 0;
	int bad = 0;
	for(long i = 0; i < jobs; i++) {
		if(js[i].err) bad = 1;
		total += js[i].n;
	}
	lom_byte_range *ranges = NULL;
	if(!bad && total) {
		ranges = malloc(total * sizeof(*ranges));
		if(ranges) {
			size_t w = 0;
			for(long i = 0; i < jobs; i++) {
				if(js[i].n)
					memcpy(ranges + w, js[i].ranges, js[i].n * sizeof(*ranges));
				w += js[i].n;
			}
			for(size_t i = 1; i < total; i++) {
				if(ranges[i].start < ranges[i - 1].end || ranges[i].end <= ranges[i].start) {
					free(ranges);
					ranges = NULL;
					break;
				}
			}
			if(ranges) {
				named_root_end(code, code_len, ranges[total - 1].end, root_end);
				*out_n = total;
			}
		}
	}
	for(long i = 0; i < jobs; i++) free(js[i].ranges);
	free(js);
	free(th);
	return ranges;
}

/* One-pass grow — a count-then-fill pair would scan 20 GB twice. max_n=0 → unlimited. */
static lom_byte_range *collect_ranges_depth(const char *code, size_t code_len, int want_depth,
	size_t *out_n, size_t *root_end, size_t max_n) {
	size_t cap = 256, n = 0;
	if(!max_n) max_n = (size_t)-1;
	lom_byte_range *ranges = malloc(cap * sizeof(*ranges));
	if(!ranges) return NULL;
	size_t dummy_end = 0;
	size_t *re = root_end ? root_end : &dummy_end;
	if(root_end) *root_end = code_len ? code_len - 1 : 0;
	int depth = 0;
	size_t offset = 0, cur_start = 0;
	int in_wanted = 0;
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
				if(n >= cap) {
					size_t ncap = cap * 2;
					lom_byte_range *nr = realloc(ranges, ncap * sizeof(*ranges));
					if(!nr) { free(ranges); return NULL; }
					ranges = nr;
					cap = ncap;
				}
				ranges[n].start = cur_start;
				ranges[n].end = tag_end + 1;
				n++;
				in_wanted = 0;
				if(n >= max_n) { *out_n = n; return ranges; }
			}
			if(want_depth == 1 && depth == 0) *re = tag_end;
			offset = tag_end + 1;
			continue;
		}
		int self = tag_is_self_closing(code, offset, tag_end);
		if(depth == want_depth) {
			cur_start = offset;
			if(self) {
				if(n >= cap) {
					size_t ncap = cap * 2;
					lom_byte_range *nr = realloc(ranges, ncap * sizeof(*ranges));
					if(!nr) { free(ranges); return NULL; }
					ranges = nr;
					cap = ncap;
				}
				ranges[n].start = cur_start;
				ranges[n].end = tag_end + 1;
				n++;
				if(n >= max_n) { *out_n = n; return ranges; }
			} else {
				in_wanted = 1;
			}
		}
		if(!self) depth++;
		offset = tag_end + 1;
	}
	*out_n = n;
	return ranges;
}

static lom_byte_range *collect_ranges(const char *code, size_t code_len, int *inject_root, size_t *out_n, size_t *root_end) {
	*inject_root = 0;
	*out_n = 0;
	/* Probe two depth-1 siblings. Same tag name → named/parallel census of the rest. */
	if(code_len >= ((size_t)4 << 20)) {
		size_t probe_n = 0, probe_re = code_len ? code_len - 1 : 0;
		lom_byte_range *probe = collect_ranges_depth(code, code_len, 1, &probe_n, &probe_re, 2);
		if(probe && probe_n >= 2) {
			size_t te = find_tag_close(code, code_len, probe[0].start);
			size_t ns = 0;
			size_t nlen = (te != (size_t)-1) ? extract_tagname(code, probe[0].start, te, &ns) : 0;
			char name[64];
			if(nlen && nlen < sizeof(name) &&
			   name_open_at(code, probe[1].start, code_len, code + ns, nlen)) {
				memcpy(name, code + ns, nlen);
				name[nlen] = 0;
				size_t first = probe[0].start;
				free(probe);
				lom_byte_range *fast = collect_named_siblings(code, code_len, first, name, nlen, out_n, root_end);
				if(fast && *out_n >= 2) {
					*inject_root = 1;
					return fast;
				}
				free(fast);
			} else {
				free(probe);
			}
		} else {
			free(probe);
		}
	}
	lom_byte_range *ranges = collect_ranges_depth(code, code_len, 1, out_n, root_end, 0);
	if(ranges && *out_n >= 2) {
		*inject_root = 1;
		return ranges;
	}
	free(ranges);
	ranges = collect_ranges_depth(code, code_len, 0, out_n, root_end, 0);
	if(!ranges || *out_n == 0) {
		free(ranges);
		*out_n = 0;
		return NULL;
	}
	return ranges;
}

static lom_status scan_parallel(const char *code, size_t code_len, lom_scan_result *out, bool capture_attributes) {
	enum { MAX_JOBS = 16 };
	long ncpu = 1;
	/* 1 MiB: OSM / UniProt×50 / Wikipedia sit under the old 4 MiB serial gate. */
	if(code_len < ((size_t)1 << 20) || !parallel_hw_ok(code_len, &ncpu)) {
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

	size_t total_opens = 0;
	for(size_t i = 0; i < jobs; i++) {
		if(js[i].st != LOM_OK) {
			for(size_t k = 0; k < jobs; k++) lom_scan_result_free(&js[k].local);
			free(js); free(th);
			lom_status st = scan_bytes(code, 0, code_len, out, capture_attributes, true);
			if(st == LOM_OK) scan_trim(out);
			return st;
		}
		total_opens += js[i].local.open_count;
	}

	lom_scan_result_free(out);
	lom_scan_result_init(out);
	/* Pre-size dest with the full document span so GB merges use mmap, not heap realloc. */
	if(!opens_ensure(out, (inject_root ? 1 : 0) + total_opens + 64, code_len)) {
		for(size_t i = 0; i < jobs; i++) lom_scan_result_free(&js[i].local);
		free(js); free(th);
		return LOM_ERR_NOMEM;
	}
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
		(void)lom_open_set_node_end(out, &root, (int64_t)root_end);
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
		if(!merge_piece(out, &js[i].local, root_parent, &intern)) {
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

/* Census + replay: if depth-1 siblings share a name_id sequence, scan the first
 * fully and apply that template (skip intern) on the rest. Exact-byte twins
 * memcpy open rows and patch offsets. */
static int recipe_append_template(lom_scan_result *dest, lom_scan_result *frag, size_t *off_out) {
	if(!dest || !frag || !frag->open_count) return -1;
	size_t off = dest->recipe_tmpl_n;
	lom_open_row *tmpl = realloc(dest->recipe_tmpl,
		(off + frag->open_count) * sizeof(lom_open_row));
	if(!tmpl) return -1;
	dest->recipe_tmpl = tmpl;
	for(size_t i = 0; i < frag->open_count; i++) {
		tmpl[off + i] = frag->opens[i];
		const char *nm = lom_scan_string(frag, frag->opens[i].name_id);
		uint32_t gid = 0;
		size_t nlen = strlen(nm);
		int found = 0;
		for(size_t s = 0; s < dest->string_count; s++) {
			const char *ds = lom_scan_string(dest, (uint32_t)s);
			if(strcmp(ds, nm) == 0) { gid = (uint32_t)s; found = 1; break; }
		}
		if(!found) {
			if(!string_push(dest, nm, nlen, &gid)) return -1;
		}
		tmpl[off + i].name_id = gid;
	}
	dest->recipe_tmpl_n = off + frag->open_count;
	dest->recipe_owned = 1;
	if(off_out) *off_out = off;
	return 0;
}

static int range_tag_eq(const char *code, size_t code_len, size_t a, size_t b) {
	if(!code || a >= code_len || b >= code_len || code[a] != '<' || code[b] != '<') return 0;
	size_t i = 1, j = 1;
	for(;;) {
		char ca = (a + i < code_len) ? code[a + i] : 0;
		char cb = (b + j < code_len) ? code[b + j] : 0;
		int enda = (!ca || ca == ' ' || ca == '\t' || ca == '\n' || ca == '\r' || ca == '>' || ca == '/');
		int endb = (!cb || cb == ' ' || cb == '\t' || cb == '\n' || cb == '\r' || cb == '>' || cb == '/');
		if(enda || endb) return enda && endb;
		if(ca != cb) return 0;
		i++;
		j++;
		if(i > 256 || j > 256) return 0;
	}
}

static uint32_t range_tag_hash(const char *code, size_t code_len, size_t at) {
	if(!code || at >= code_len || code[at] != '<') return 0;
	uint32_t h = 2166136261u;
	for(size_t i = 1; i <= 256 && at + i < code_len; i++) {
		char c = code[at + i];
		if(!c || c == ' ' || c == '\t' || c == '\n' || c == '\r' || c == '>' || c == '/') break;
		h ^= (unsigned char)c;
		h *= 16777619u;
	}
	return h;
}

typedef struct { uint32_t h; uint32_t i; } range_hash_idx;

static int cmp_range_hash_idx(const void *a, const void *b) {
	const range_hash_idx *x = (const range_hash_idx *)a;
	const range_hash_idx *y = (const range_hash_idx *)b;
	if(x->h < y->h) return -1;
	if(x->h > y->h) return 1;
	if(x->i < y->i) return -1;
	if(x->i > y->i) return 1;
	return 0;
}

/* O(n log n) cluster by opening-tag hash. Fills cl_of[-1 leftover, 0..k-1 classes]. */
static int classify_ranges(const char *code, size_t code_len, const lom_byte_range *ranges,
	size_t n, int8_t *cl_of, size_t *best_n, int *unique) {
	if(!n || !ranges || !cl_of) return 0;
	memset(cl_of, -1, n);
	range_hash_idx *xs = malloc(n * sizeof(*xs));
	int32_t *gid = malloc(n * sizeof(*gid));
	if(!xs || !gid) {
		free(xs);
		free(gid);
		return 0;
	}
	for(size_t i = 0; i < n; i++) {
		xs[i].h = range_tag_hash(code, code_len, ranges[i].start);
		xs[i].i = (uint32_t)i;
		gid[i] = -1;
	}
	qsort(xs, n, sizeof(*xs), cmp_range_hash_idx);

	size_t gcap = 16;
	uint32_t *gcount = malloc(gcap * sizeof(uint32_t));
	if(!gcount) {
		free(xs);
		free(gid);
		return 0;
	}
	int ng = 0;
	size_t bn = 0;
	size_t r = 0;
	while(r < n) {
		size_t e = r + 1;
		while(e < n && xs[e].h == xs[r].h) e++;
		for(size_t p = r; p < e; p++) {
			size_t seed = xs[p].i;
			if(gid[seed] >= 0) continue;
			if((size_t)ng >= gcap) {
				size_t ncap = gcap * 2;
				uint32_t *nb = realloc(gcount, ncap * sizeof(uint32_t));
				if(!nb) {
					free(gcount);
					free(xs);
					free(gid);
					return 0;
				}
				gcount = nb;
				gcap = ncap;
			}
			int myg = ng++;
			gid[seed] = myg;
			uint32_t c = 1;
			for(size_t q = p + 1; q < e; q++) {
				size_t j = xs[q].i;
				if(gid[j] >= 0) continue;
				if(!range_tag_eq(code, code_len, ranges[seed].start, ranges[j].start)) continue;
				gid[j] = myg;
				c++;
			}
			gcount[myg] = c;
			if(c > bn) bn = c;
		}
		r = e;
	}

	int kpick = 0;
	while(kpick < LOM_RECIPE_KMAX) {
		int best = -1;
		uint32_t bc = 0;
		for(int g = 0; g < ng; g++) {
			if(gcount[g] > bc) { bc = gcount[g]; best = g; }
		}
		if(best < 0 || bc < 4) break;
		for(size_t i = 0; i < n; i++) {
			if(gid[i] == best) cl_of[i] = (int8_t)kpick;
		}
		gcount[best] = 0;
		kpick++;
	}
	free(gcount);
	free(xs);
	free(gid);
	if(best_n) *best_n = bn;
	if(unique) *unique = ng > 0 ? ng : 1;
	return kpick;
}

static lom_status scan_tiled(const char *code, size_t code_len, lom_scan_result *out,
	bool capture_attributes) {
	/* 64 KiB: medium irregular files can still have a repeating sibling run. */
	if(code_len < ((size_t)64 << 10)) return LOM_ERR_ARG;
	size_t root_end = code_len ? code_len - 1 : 0;
	int inject_root = 0;
	size_t n = 0;
	double t_c0 = scan_now_ms();
	lom_byte_range *ranges = collect_ranges(code, code_len, &inject_root, &n, &root_end);
	double t_c1 = scan_now_ms();
	if(getenv("LOM_DEBUG_TILE"))
		fprintf(stderr, "scan_tiled census n=%zu inject=%d len=%zu\n", n, inject_root, code_len);
	if(!ranges || n < 4) {
		free(ranges);
		size_t n2 = 0, re2 = root_end;
		lom_byte_range *d2 = collect_ranges_depth(code, code_len, 2, &n2, &re2, 0);
		if(!d2 || n2 < 4) {
			free(d2);
			return LOM_ERR_ARG;
		}
		ranges = d2;
		n = n2;
		root_end = re2;
	}
	out->phase_census_ms = t_c1 - t_c0;

	int8_t *cl_of = malloc(n);
	if(!cl_of) { free(ranges); return LOM_ERR_NOMEM; }
	size_t best_n = 0;
	int unique_tags = 0;
	int kpick = classify_ranges(code, code_len, ranges, n, cl_of, &best_n, &unique_tags);
	out->recipe_class_n = unique_tags > 0 ? unique_tags : 1;
	if(best_n < 4 || kpick < 1) {
		/* Nested regularity: depth-1 wrappers may not tile; try depth-2. */
		size_t n2 = 0, re2 = root_end;
		lom_byte_range *d2 = collect_ranges_depth(code, code_len, 2, &n2, &re2, 0);
		size_t bn2 = 0;
		int u2 = 0;
		int k2 = 0;
		int8_t *cl2 = NULL;
		if(d2 && n2 >= 4) {
			cl2 = malloc(n2);
			if(cl2) k2 = classify_ranges(code, code_len, d2, n2, cl2, &bn2, &u2);
		}
		if(bn2 >= 4 && k2 >= 1) {
			free(cl_of);
			free(ranges);
			ranges = d2;
			cl_of = cl2;
			n = n2;
			best_n = bn2;
			unique_tags = u2;
			kpick = k2;
			root_end = re2;
			out->recipe_class_n = unique_tags > 0 ? unique_tags : 1;
		} else {
			free(cl2);
			free(d2);
			free(cl_of);
			free(ranges);
			return LOM_ERR_ARG;
		}
	}

	size_t i0 = (size_t)-1, i1 = (size_t)-1;
	for(size_t i = 0; i < n; i++) {
		if(cl_of[i] != 0) continue;
		if(i0 == (size_t)-1) i0 = i;
		else { i1 = i; break; }
	}
	if(i0 == (size_t)-1 || i1 == (size_t)-1) {
		free(cl_of);
		free(ranges);
		return LOM_ERR_ARG;
	}
	/* Inner regularity that covers little of the file is a full leftover
	 * census plus recipe bookkeeping — wholesale is cheaper (UniProt-class). */
	{
		size_t covered = 0;
		for(size_t i = 0; i < n; i++) {
			if(cl_of[i] < 0 || cl_of[i] >= kpick) continue;
			if(ranges[i].end > ranges[i].start)
				covered += ranges[i].end - ranges[i].start;
		}
		if(code_len >= ((size_t)64 << 10) && covered * 2 < code_len) {
			free(cl_of);
			free(ranges);
			return LOM_ERR_ARG;
		}
	}
	/* Seed siblings with very different spans will not share a template
	 * (OSM empty node vs tagged node). Skip the two sample scans. */
	{
		size_t la = ranges[i0].end - ranges[i0].start;
		size_t lb = ranges[i1].end - ranges[i1].start;
		size_t slo = la < lb ? la : lb, shi = la < lb ? lb : la;
		if(slo && shi > slo * 2) {
			free(cl_of);
			free(ranges);
			return LOM_ERR_ARG;
		}
	}

	lom_scan_result a, b;
	lom_scan_result_init(&a);
	lom_scan_result_init(&b);
	if(scan_bytes(code, ranges[i0].start, ranges[i0].end, &a, false, true) != LOM_OK ||
	   scan_bytes(code, ranges[i1].start, ranges[i1].end, &b, false, true) != LOM_OK ||
	   a.open_count < 1 || a.open_count != b.open_count) {
		lom_scan_result_free(&a);
		lom_scan_result_free(&b);
		free(cl_of);
		free(ranges);
		return LOM_ERR_ARG;
	}
	int same_names = 1;
	for(size_t i = 0; i < a.open_count; i++) {
		const char *na = lom_scan_string(&a, a.opens[i].name_id);
		const char *nb = lom_scan_string(&b, b.opens[i].name_id);
		if(strcmp(na, nb) != 0) { same_names = 0; break; }
	}
	if(getenv("LOM_DEBUG_TILE"))
		fprintf(stderr, "scan_tiled sample a=%zu b=%zu same=%d fractal=%d kpick=%d classes=%d\n",
			a.open_count, b.open_count, same_names, env_fractal_requested() ? 1 : 0,
			kpick, out->recipe_class_n);
	lom_scan_result_free(&b);
	if(!same_names) {
		lom_scan_result_free(&a);
		free(cl_of);
		free(ranges);
		return LOM_ERR_ARG;
	}
	out->phase_sample_ms = scan_now_ms() - t_c1;
	(void)capture_attributes; /* attrs read from bytes on demand; do not kill recipe */

	/* Fractal: k templates + leftover census, not recipe XOR wholesale. */
	if(env_fractal_requested() && a.open_count >= 1) {
		size_t first_tile = (size_t)-1;
		for(size_t i = 0; i < n; i++) {
			if(cl_of[i] >= 0) { first_tile = ranges[i].start; break; }
		}
		lom_scan_result_free(out);
		lom_scan_result_init(out);
		out->phase_census_ms = t_c1 - t_c0;
		out->phase_sample_ms = scan_now_ms() - t_c1;
		if(first_tile && first_tile != (size_t)-1) {
			lom_status pst = scan_bytes(code, 0, first_tile, out, false, true);
			if(pst != LOM_OK) {
				lom_scan_result_free(&a);
				free(cl_of);
				free(ranges);
				return pst;
			}
			out->phase_census_ms = t_c1 - t_c0;
			out->phase_sample_ms = scan_now_ms() - t_c1;
		} else {
			uint32_t empty_id;
			if(!string_push(out, "", 0, &empty_id)) {
				lom_scan_result_free(&a);
				free(cl_of);
				free(ranges);
				return LOM_ERR_NOMEM;
			}
		}

		int cmap[LOM_RECIPE_KMAX];
		for(int c = 0; c < LOM_RECIPE_KMAX; c++) cmap[c] = -1;
		size_t stride = 0;
		int nk = 0;
		for(int c = 0; c < kpick && nk < LOM_RECIPE_KMAX; c++) {
			lom_scan_result frag;
			lom_scan_result_init(&frag);
			if(c == 0) {
				frag = a;
				lom_scan_result_init(&a);
			} else {
				size_t f0 = (size_t)-1, f1 = (size_t)-1;
				for(size_t i = 0; i < n; i++) {
					if(cl_of[i] != (int8_t)c) continue;
					if(f0 == (size_t)-1) f0 = i;
					else { f1 = i; break; }
				}
				if(f0 == (size_t)-1 || f1 == (size_t)-1) {
					for(size_t i = 0; i < n; i++)
						if(cl_of[i] == (int8_t)c) cl_of[i] = -1;
					continue;
				}
				lom_scan_result fb;
				lom_scan_result_init(&fb);
				if(scan_bytes(code, ranges[f0].start, ranges[f0].end, &frag, false, true) != LOM_OK ||
				   scan_bytes(code, ranges[f1].start, ranges[f1].end, &fb, false, true) != LOM_OK ||
				   frag.open_count < 1 || frag.open_count != fb.open_count) {
					lom_scan_result_free(&frag);
					lom_scan_result_free(&fb);
					for(size_t i = 0; i < n; i++)
						if(cl_of[i] == (int8_t)c) cl_of[i] = -1;
					continue;
				}
				int ok = 1;
				for(size_t i = 0; i < frag.open_count; i++) {
					const char *na = lom_scan_string(&frag, frag.opens[i].name_id);
					const char *nb = lom_scan_string(&fb, fb.opens[i].name_id);
					if(strcmp(na, nb) != 0) { ok = 0; break; }
				}
				lom_scan_result_free(&fb);
				if(!ok) {
					lom_scan_result_free(&frag);
					for(size_t i = 0; i < n; i++)
						if(cl_of[i] == (int8_t)c) cl_of[i] = -1;
					continue;
				}
			}
			size_t off = 0;
			if(recipe_append_template(out, &frag, &off) != 0) {
				lom_scan_result_free(&frag);
				lom_scan_result_free(&a);
				free(cl_of);
				free(ranges);
				return LOM_ERR_NOMEM;
			}
			out->recipe_k_tmpl_n[nk] = frag.open_count;
			out->recipe_k_tmpl_off[nk] = off;
			if(frag.open_count > stride) stride = frag.open_count;
			cmap[c] = nk;
			nk++;
			lom_scan_result_free(&frag);
		}
		if(nk < 1) {
			lom_scan_result_free(&a);
			free(cl_of);
			free(ranges);
			return LOM_ERR_ARG;
		}

		size_t ntiles = 0;
		for(size_t i = 0; i < n; i++) {
			int c = cl_of[i];
			if(c >= 0 && c < LOM_RECIPE_KMAX && cmap[c] >= 0) ntiles++;
		}
		out->recipe_starts = malloc(ntiles * sizeof(uint64_t));
		out->recipe_ends = malloc(ntiles * sizeof(uint64_t));
		if(!out->recipe_starts || !out->recipe_ends) {
			lom_scan_result_free(&a);
			free(cl_of);
			free(ranges);
			return LOM_ERR_NOMEM;
		}
		uint8_t *tcls = NULL;
		if(nk > 1) {
			tcls = malloc(ntiles);
			if(!tcls) {
				lom_scan_result_free(&a);
				free(cl_of);
				free(ranges);
				return LOM_ERR_NOMEM;
			}
		}
		size_t ti = 0;
		for(size_t i = 0; i < n; i++) {
			int c = cl_of[i];
			if(c < 0 || c >= LOM_RECIPE_KMAX || cmap[c] < 0) continue;
			int id = cmap[c];
			out->recipe_starts[ti] = ranges[i].start;
			out->recipe_ends[ti] = ranges[i].end;
			if(tcls) tcls[ti] = (uint8_t)id;
			out->recipe_k_tile_n[id]++;
			ti++;
		}
		out->recipe_tile_n = ti;
		out->recipe_tile_cls = tcls;
		out->recipe_k = (size_t)nk;
		out->recipe_stride = stride;
		out->recipe_class_n = nk;
		out->recipe_root_parent = out->open_count ? (int32_t)(out->open_count - 1) : -1;
		out->recipe_owned = 1;
		if(out->open_count && out->opens[0].parent_idx < 0)
			(void)lom_open_set_node_end(out, &out->opens[0], (int64_t)root_end);
		/* Gaps between tiles (wrappers at nested depth, leftover siblings).
		 * Reuse intern: reseeding on every whitespace gap was O(tiles × names). */
		{
			lom_intern leftover_intern;
			memset(&leftover_intern, 0, sizeof(leftover_intern));
			size_t cursor = (first_tile == (size_t)-1) ? 0 : first_tile;
			for(size_t t = 0; t < out->recipe_tile_n; t++) {
				size_t ts = (size_t)out->recipe_starts[t];
				if(ts > cursor)
					(void)scan_leftover_span(code, cursor, ts, out, &leftover_intern);
				if(out->recipe_ends[t] > cursor)
					cursor = (size_t)out->recipe_ends[t];
			}
			if(cursor < code_len)
				(void)scan_leftover_span(code, cursor, code_len, out, &leftover_intern);
			intern_free(&leftover_intern);
		}
		lom_scan_result_free(&a);
		free(cl_of);
		free(ranges);
		scan_trim(out);
		out->status = LOM_OK;
		return LOM_OK;
	}

	lom_byte_range *leftover_after = NULL;
	size_t leftover_n = 0;
	{
		size_t maj = 0, left = 0;
		for(size_t i = 0; i < n; i++) {
			if(cl_of[i] == 0) maj++;
			else left++;
		}
		lom_byte_range *chosen = malloc(maj * sizeof(lom_byte_range));
		if(!chosen) {
			lom_scan_result_free(&a);
			free(cl_of);
			free(ranges);
			return LOM_ERR_NOMEM;
		}
		if(left) {
			leftover_after = malloc(left * sizeof(lom_byte_range));
			if(!leftover_after) {
				free(chosen);
				lom_scan_result_free(&a);
				free(cl_of);
				free(ranges);
				return LOM_ERR_NOMEM;
			}
		}
		size_t ci = 0, li = 0;
		for(size_t i = 0; i < n; i++) {
			if(cl_of[i] == 0) chosen[ci++] = ranges[i];
			else if(leftover_after) leftover_after[li++] = ranges[i];
		}
		leftover_n = li;
		free(ranges);
		free(cl_of);
		cl_of = NULL;
		ranges = chosen;
		n = ci;
	}

	/* Prefix + first sibling in one interned scan. Pre-size dest from the
	 * full document so GB-class tile replay stays file-backed (the prefix
	 * span alone would pick anon and mremap would keep it). */
	{
		int saved_classes = out->recipe_class_n;
		lom_scan_result_free(out);
		lom_scan_result_init(out);
		out->recipe_class_n = saved_classes;
	}
	size_t est_all = n * a.open_count + 1024;
	if(est_all < (code_len / 24) + 64) est_all = code_len / 24 + 64;
	if(!opens_ensure(out, est_all, code_len)) {
		lom_scan_result_free(&a);
		free(ranges);
		free(leftover_after);
		return LOM_ERR_NOMEM;
	}
	lom_status st = scan_bytes(code, 0, ranges[0].end, out, capture_attributes, false);
	if(st != LOM_OK) {
		lom_scan_result_free(&a);
		free(ranges);
		free(leftover_after);
		return st;
	}

	size_t tmpl_n = a.open_count;
	uint32_t *nids = malloc(tmpl_n * sizeof(uint32_t));
	if(!nids) {
		lom_scan_result_free(&a);
		free(ranges);
		free(leftover_after);
		return LOM_ERR_NOMEM;
	}
	/* name_ids from dest (global intern), not fragment-local `a`. */
	size_t first_oi = (size_t)-1;
	for(size_t i = 0; i < out->open_count; i++) {
		if((size_t)out->opens[i].open_off >= ranges[0].start) {
			first_oi = i;
			break;
		}
	}
	if(first_oi == (size_t)-1 || out->open_count - first_oi != tmpl_n) {
		free(nids);
		lom_scan_result_free(&a);
		free(ranges);
		free(leftover_after);
		return LOM_ERR_ARG;
	}
	for(size_t i = 0; i < tmpl_n; i++)
		nids[i] = out->opens[first_oi + i].name_id;

	int exact_ok = !capture_attributes;
	for(size_t i = 0; i < tmpl_n; i++) {
		if(out->opens[first_oi + i].self_closing & LOM_OPEN_NE_OVF) {
			exact_ok = 0;
			break;
		}
	}

	size_t first_len = ranges[0].end - ranges[0].start;
	int32_t root_parent = inject_root ? 0 : -1;

	for(size_t ri = 1; ri < n; ri++) {
		size_t lo = ranges[ri].start, hi = ranges[ri].end;
		size_t blen = hi - lo;
		int did = 0;
		if(exact_ok && blen == first_len &&
		   memcmp(code + ranges[0].start, code + lo, blen) == 0) {
			if(!opens_ensure(out, out->open_count + tmpl_n, code_len)) {
				free(nids);
				lom_scan_result_free(&a);
				free(ranges);
				free(leftover_after);
				return LOM_ERR_NOMEM;
			}
			int64_t dlt = (int64_t)lo - (int64_t)ranges[0].start;
			size_t idx_delta = out->open_count - first_oi;
			for(size_t i = 0; i < tmpl_n; i++) {
				lom_open_row row = out->opens[first_oi + i];
				row.open_off += dlt;
				if(row.parent_idx < (int32_t)first_oi) row.parent_idx = root_parent;
				else row.parent_idx = (int32_t)((size_t)row.parent_idx + idx_delta);
				out->opens[out->open_count++] = row;
			}
			did = 1;
		}
		if(!did) {
			st = scan_bytes_ex(code, lo, hi, out, capture_attributes, false, nids, tmpl_n, root_parent, NULL);
			if(st != LOM_OK) {
				/* Last sibling may be truncated — fall back to a full interned scan. */
				st = scan_bytes(code, lo, hi, out, capture_attributes, false);
				if(st != LOM_OK) {
					free(nids);
					lom_scan_result_free(&a);
					free(ranges);
					free(leftover_after);
					return st;
				}
			}
		}
	}

	if(ranges[n - 1].end < code_len) {
		/* Closers only — fix root span below. */
		(void)scan_bytes(code, ranges[n - 1].end, code_len, out, false, false);
	}
	if(out->open_count && out->opens[0].parent_idx < 0)
		(void)lom_open_set_node_end(out, &out->opens[0], (int64_t)root_end);

	if(leftover_after) {
		lom_intern leftover_intern;
		memset(&leftover_intern, 0, sizeof(leftover_intern));
		for(size_t i = 0; i < leftover_n; i++) {
			(void)scan_leftover_span(code, leftover_after[i].start, leftover_after[i].end,
				out, &leftover_intern);
		}
		intern_free(&leftover_intern);
		free(leftover_after);
	}
	free(nids);
	lom_scan_result_free(&a);
	free(ranges);
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
	if(env_tile_requested()) {
		lom_status ts = scan_tiled(code, code_len, out, capture_attributes);
		if(ts == LOM_OK) return ts;
	}
	/* Default on. LOM_PARALLEL=0 disables; hardware gate may still force serial. */
	if(env_parallel_requested()) {
		return scan_parallel(code, code_len, out, capture_attributes);
	}
	lom_status st = scan_bytes(code, 0, code_len, out, capture_attributes, true);
	if(st == LOM_OK) scan_trim(out);
	return st;
}

