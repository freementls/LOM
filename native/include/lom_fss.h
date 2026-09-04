/*
 * lom_fss.h — optional libfss find for large-document scans.
 *
 * Enabled when LOM_FSS_SCAN=1 (or LOM_FSS=1) and haystack length >= 4096.
 * Uses dlopen so LOM builds without a hard link dependency on libfss.
 * Tiny needles / small docs keep libc memmem.
 */
#ifndef LOM_FSS_H
#define LOM_FSS_H

#include <dlfcn.h>
#include <stdlib.h>
#include <string.h>
#include <sys/types.h>

typedef ssize_t (*lom_fss_find_fn)(const unsigned char *, size_t,
                                   const unsigned char *, size_t);

static inline int lom_fss_scan_wanted(size_t n) {
	static int cached = -1;
	if (cached >= 0) return cached && n >= 4096;
	const char *v = getenv("LOM_FSS_SCAN");
	if (!v || !v[0]) v = getenv("LOM_FSS");
	if (!v || !v[0]) {
		cached = 0; /* default OFF until KEEP */
		return 0;
	}
	if (v[0] == '0' || v[0] == 'f' || v[0] == 'F' || v[0] == 'n' || v[0] == 'N')
		cached = 0;
	else
		cached = 1;
	return cached && n >= 4096;
}

static inline lom_fss_find_fn lom_fss_find_resolve(void) {
	static lom_fss_find_fn fn;
	static int tried;
	if (tried) return fn;
	tried = 1;
	const char *path = getenv("LOM_FSS_LIB");
	if (!path || !path[0]) path = "/srv/http/fractal_substring/libfss.so";
	void *h = dlopen(path, RTLD_LAZY);
	if (!h) return NULL;
	fn = (lom_fss_find_fn)dlsym(h, "fss_find");
	return fn;
}

/* Drop-in for memmem on (hay,hay_n,needle,needle_n). */
static inline const void *lom_fss_memmem(const void *haystack, size_t haylen,
                                        const void *needle, size_t needlelen) {
	if (!lom_fss_scan_wanted(haylen))
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
