CC ?= gcc
CFLAGS ?= -O2 -g -fPIC -Wall -Wextra -std=c11 -D_GNU_SOURCE
CPPFLAGS += -Iinclude
LDFLAGS_SO = -shared
LDLIBS = -lm

LIB = lib/liblom.so
LOMC = bin/lomc
LOMD = bin/lomd
OBJS = src/lom_scan.o src/lom_doc.o src/lom_fmem.o src/lom_fcache.o

.PHONY: all clean test-scan php-ext test-fmem

all: $(LIB) $(LOMC) $(LOMD)

test-fmem: $(LIB)
	$(CC) $(CFLAGS) $(CPPFLAGS) -o bin/test_fmem_fcache src/test_fmem_fcache.c -Llib -Wl,-rpath,'$$ORIGIN/../lib' -llom $(LDLIBS)
	./bin/test_fmem_fcache

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

src/lom_scan.o src/lom_doc.o: include/lom.h
src/lom_fmem.o: include/lom_fmem.h
src/lom_fcache.o: include/lom_fcache.h

clean:
	rm -f src/*.o $(LIB) $(LOMC) $(LOMD)

test-scan: $(LOMC)
	./bin/lomc ../perf_fixture.xml --repeat 5
	./bin/lomc ../test.xml --repeat 3
