/*
 * lom_fss.h — optional libfss find for large-document scans.
 *
 * Default ON when haystack ≥ 4 KiB and libfss.so loads. Short closers use
 * memchr dual/triple scan (beats memmem on large docs; measured ~2–30× on
 * enwik8 `-->` / `?>` misses). Longer needles use AVX2 first-byte filter.
 * Disable: LOM_FSS_SCAN=0 or LOM_FSS=0.
 * Library path: LOM_FSS_LIB, else soname / install paths / sibling checkout.
 */
#ifndef LOM_FSS_H
#define LOM_FSS_H

#include <dlfcn.h>
#include <limits.h>
#include <stdlib.h>
#include <string.h>
#include <sys/types.h>
#include <unistd.h>

typedef ssize_t (*lom_fss_find_fn)(const unsigned char *, size_t,
                                   const unsigned char *, size_t);

static inline int lom_fss_scan_wanted(size_t n) {
	static int cached = -1;
	if (cached >= 0) return cached && n >= 4096;
	const char *v = getenv("LOM_FSS_SCAN");
	if (!v || !v[0]) v = getenv("LOM_FSS");
	if (v && v[0] &&
	    (v[0] == '0' || v[0] == 'f' || v[0] == 'F' || v[0] == 'n' ||
	     v[0] == 'N')) {
		cached = 0;
		return 0;
	}
	cached = 1; /* default ON */
	return cached && n >= 4096;
}

static inline lom_fss_find_fn lom_fss_find_resolve(void) {
	static lom_fss_find_fn fn;
	static int tried;
	if (tried) return fn;
	tried = 1;

	void *h = NULL;
	const char *env = getenv("LOM_FSS_LIB");
	if (env && env[0]) {
		h = dlopen(env, RTLD_LAZY);
	}

	if (!h) {
		static const char *cands[] = {
			"libfss.so",
			"/usr/local/lib/libfss.so",
			"/usr/lib/libfss.so",
			"/srv/http/fractal_substring/libfss.so",
			NULL
		};
		for (int i = 0; cands[i]; i++) {
			h = dlopen(cands[i], RTLD_LAZY);
			if (h) {
				break;
			}
		}
	}

	/* Sibling checkout: <parent>/LOM/native/bin/<exe> → <parent>/fractal_substring */
	if (!h) {
		char self[PATH_MAX];
		ssize_t n = readlink("/proc/self/exe", self, sizeof(self) - 1);
		if (n > 0) {
			self[n] = '\0';
			/* Strip bin/, native/, LOM/ */
			for (int up = 0; up < 3; up++) {
				char *slash = strrchr(self, '/');
				if (!slash || slash == self) {
					self[0] = '\0';
					break;
				}
				*slash = '\0';
			}
			if (self[0]) {
				char *slash = strrchr(self, '/');
				if (slash && slash != self) {
					char buf[PATH_MAX];
					size_t parent_len = (size_t)(slash - self);
					static const char suffix[] = "/fractal_substring/libfss.so";
					if (parent_len + sizeof(suffix) <= sizeof(buf)) {
						memcpy(buf, self, parent_len);
						memcpy(buf + parent_len, suffix, sizeof(suffix));
						h = dlopen(buf, RTLD_LAZY);
					}
				}
			}
		}
	}

	if (!h) {
		return NULL;
	}
	fn = (lom_fss_find_fn)dlsym(h, "fss_find");
	return fn;
}

/* Drop-in for memmem on (hay,hay_n,needle,needle_n). */
static inline const void *lom_fss_memmem(const void *haystack, size_t haylen,
                                        const void *needle, size_t needlelen) {
	if (!lom_fss_scan_wanted(haylen) || needlelen == 0)
		return memmem(haystack, haylen, needle, needlelen);
	lom_fss_find_fn fn = lom_fss_find_resolve();
	if (!fn) return memmem(haystack, haylen, needle, needlelen);
	ssize_t off =
	    fn((const unsigned char *)haystack, haylen,
	       (const unsigned char *)needle, needlelen);
	if (off < 0) return NULL;
	return (const unsigned char *)haystack + off;
}

#endif /* LOM_FSS_H */
