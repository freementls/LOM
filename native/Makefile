CC ?= gcc
CFLAGS ?= -O2 -g -fPIC -Wall -Wextra -std=c11 -D_GNU_SOURCE
CPPFLAGS += -Iinclude
LDFLAGS_SO = -shared
LDLIBS = -lm -lpthread -lpcre2-8 -ldl

LIB = lib/liblom.so
LOMC = bin/lomc
LOMD = bin/lomd
OBJS = src/lom_scan.o src/lom_doc.o src/lom_fmem.o src/lom_fcache.o src/lom_fstr.o src/lom_compat.o
BENCH_CORPUS = bin/bench_corpus

.PHONY: all clean test-scan php-ext test-fmem test-regex test-fstr test-fastpath \
	test-irregular test-compat test-wal test-all bench-corpus ci

all: $(LIB) $(LOMC) $(LOMD)

test-regex: $(LIB)
	$(CC) $(CFLAGS) $(CPPFLAGS) -o bin/test_regex src/test_regex.c -Llib -Wl,-rpath,'$$ORIGIN/../lib' -llom $(LDLIBS)
	./bin/test_regex

test-fstr: $(LIB)
	$(CC) $(CFLAGS) $(CPPFLAGS) -o bin/test_fstr src/test_fstr.c -Llib -Wl,-rpath,'$$ORIGIN/../lib' -llom $(LDLIBS)
	./bin/test_fstr

test-fmem: $(LIB)
	$(CC) $(CFLAGS) $(CPPFLAGS) -o bin/test_fmem_fcache src/test_fmem_fcache.c -Llib -Wl,-rpath,'$$ORIGIN/../lib' -llom $(LDLIBS)
	./bin/test_fmem_fcache

test-fastpath: $(LIB)
	$(CC) $(CFLAGS) $(CPPFLAGS) -o bin/test_fastpath src/test_fastpath.c -Llib -Wl,-rpath,'$$ORIGIN/../lib' -llom $(LDLIBS)
	./bin/test_fastpath

test-irregular: $(LIB)
	$(CC) $(CFLAGS) $(CPPFLAGS) -o bin/test_irregular src/test_irregular.c -Llib -Wl,-rpath,'$$ORIGIN/../lib' -llom $(LDLIBS)
	./bin/test_irregular

test-compat: $(LIB)
	$(CC) $(CFLAGS) $(CPPFLAGS) -o bin/test_compat src/test_compat.c -Llib -Wl,-rpath,'$$ORIGIN/../lib' -llom $(LDLIBS)
	./bin/test_compat

test-wal: $(LIB)
	$(CC) $(CFLAGS) $(CPPFLAGS) -o bin/test_wal src/test_wal.c -Llib -Wl,-rpath,'$$ORIGIN/../lib' -llom $(LDLIBS)
	./bin/test_wal

test-all: test-regex test-fstr test-fmem test-fastpath test-irregular test-compat test-wal

ci: test-all
	test -f tests/xpath_subset.txt

$(BENCH_CORPUS): src/bench_corpus.o $(LIB) | lib
	$(CC) -o $@ src/bench_corpus.o -Llib -Wl,-rpath,'$$ORIGIN/../lib' -llom $(LDLIBS)

bench-corpus: $(BENCH_CORPUS)
	@echo "run ../bench_corpus.sh from the repo root"

php-ext: $(LIB)
	cd php_ext && phpize
	cd php_ext && ./configure --enable-lom-accel \
		CPPFLAGS="-I$(CURDIR)/include" \
		LDFLAGS="-L$(CURDIR)/lib -Wl,-rpath,$(CURDIR)/lib"
	$(MAKE) -C php_ext
	@echo "Built php_ext/modules/lom_accel.so"

lib:
	mkdir -p lib bin

$(LIB): $(OBJS) | lib
	$(CC) $(LDFLAGS_SO) -o $@ $(OBJS) $(LDLIBS)

$(LOMC): src/lomc.o $(LIB) | lib
	$(CC) -o $@ src/lomc.o -Llib -Wl,-rpath,'$$ORIGIN/../lib' -llom $(LDLIBS)

$(LOMD): src/lomd.o $(LIB) | lib
	$(CC) -o $@ src/lomd.o -Llib -Wl,-rpath,'$$ORIGIN/../lib' -llom -lpthread $(LDLIBS)

src/%.o: src/%.c
	$(CC) $(CFLAGS) $(CPPFLAGS) -c -o $@ $<

src/lom_scan.o src/lom_doc.o src/lom_compat.o src/bench_corpus.o: include/lom.h
src/lom_fmem.o: include/lom_fmem.h
src/lom_fcache.o: include/lom_fcache.h
src/lom_fstr.o: include/lom_fstr.h

clean:
	rm -f src/*.o $(LIB) $(LOMC) $(LOMD) $(BENCH_CORPUS) \
		bin/test_regex bin/test_fstr bin/test_fmem_fcache bin/test_fastpath \
		bin/test_irregular bin/test_compat bin/test_wal

test-scan: $(LOMC)
	./bin/lomc ../perf_fixture.xml --repeat 5
	./bin/lomc ../test.xml --repeat 3
