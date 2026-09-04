# Benchmark results snapshot

Machine: Linux 6.12 Manjaro, 12 CPUs, ~38 GiB RAM, PHP 8.5.8, GCC 16.1.

Full narrative: [`docs/paper/lom_living_object_model.md`](docs/paper/lom_living_object_model.md).

## RAM (primary focus)

| Config | File | Opens | RSS | After free |
|--------|------|-------|-----|------------|
| Before CSR (per-node children mallocs) | 100 MB | 4.4M | **~1424 MB** load | ~1 GB stuck |
| After CSR + scan trim + view-fmem | 100 MB | 4.4M | **~385 MB** load | **~2 MB** |
| lomc full run | 100 MB | 4.4M | **~528 MB** peak | — |
| After CSR (heap opens) | 1 GB | 44.8M | **~3905 MB** load | **~2 MB** |
| File-backed opens, no attrs, no CSR | 1 GB | 44.8M | **~180 MB** construct | **~2 MB** |
| lomc full run (legacy heap+attrs) | 1 GB | 44.8M | **~5.0 GB** peak | — |

PHP 100 MB (`memory_limit=512M`): construct lazy **~134 MB**; `region` via slim index **~402 MB**.

## Construct (native) — fixed superlinear fmem ingest

| Size | Before (bulk fmem ingest) | After (skip ingest + tight aux) |
|------|---------------------------|----------------------------------|
| 100 MB | ~3.5 s | **~1.1 s** |
| 1 GB | **~415 s** | **~10.5 s** |

Root cause: ingesting ~3.9M unique strings into a 2048-bucket fmem table was \(O(n^2)\); query paths never read fmem. Aux tag/attr indexes now size by max *name* id, not full string table.

### `LOM_PARALLEL` piece-local scan (opt-in)

Depth-1 sibling ranges → per-piece scan → merge that **dedups only tag/attr names** and appends attr values. Open counts match serial. On this host: **~parity at 100 MB** (noise), still **slower at 1 GB** (extra range pass + merge); auto-serial below 4 MB and at ≥256 MB. Default **off**.

## Writes (native)

| Op | 100 MB | 1 GB | 20 GB |
|----|--------|------|-------|
| `set` text | ~1.2 s | ~4.0 s | **~120 s** |
| `new_` markup (local merge) | ~367 ms | ~4.6 s | **~722 s** (was ~973 s) |
| `delete` | ~0.4 s | ~3.7 s | **~432 s** |

Same-size/shrink `set` on mmap stays MAP_PRIVATE in-place. Growth / `new_` use a **one-pass** prefix|insert|suffix rewrite (heap below 256 MB; tempfile mmap under `LOM_OPEN_TMPDIR` / `/var/tmp` above) instead of full promote + second `memmove`. After a large tempfile rewrite, the next query/`delete` can pay cold page faults (post-write read ~884 s in the one-pass remeasure vs ~247 s on the prior heap-resident path).

## Queries (native)

| Size | Descendant warm | Full `lomc` wall |
|------|-----------------|------------------|
| 100 MB | ~3 ms | ~2.9 s |
| 1 GB | ~55 ms | **~27 s** (was ~430 s) |

## Parallel ablation (honest)

`LOM_PARALLEL=1` atomic CSR workers: slower (contention). Piece-local scan (name-only merge): correct; ~parity at 100 MB; slower at 1 GB — see § above. Default off.

## Fractal string (`lom_fstr`, `LOM_FSTR`)

Hierarchical spans (document → root children → tag-aligned blocks) with per-node byte-presence + 3-gram bloom signatures. Cold exact find / regex-with-literal-probe prune impossible subtrees before `memmem`/PCRE. Built on construct for docs ≥4 KiB (default on; `LOM_FSTR=0` to disable). Helps selective cold scans; not a substitute for tag/CSR indexes.

## C# connectors

`connectors/csharp/Lom.Native` now exposes `Get`; demos:

```bash
make -C native
cd connectors/csharp
LD_LIBRARY_PATH=../../native/lib dotnet run --project Lom.Native.Demo -- ../../test.xml person
```


| Query | Time (indexes warm) | Hits |
|-------|---------------------|------|
| `name^=/Entity_1/` (old select fallback) | ~480 ms | 3024 |
| `name^=/Entity_1/` (indexed + doc-level) | **~19–28 ms** cold / **~8 ms** warm | 3024 |
| `note%=/note-0-0-0/` (doc-level rare) | **~5.5 ms** | 1 |

Warm path seeds the exact-selector LOM cache when the indexed fast path runs (avoids rebuilding node strings from offset pairs).

## 20 GB (measured)

| Metric | Value |
|--------|-------|
| Opens | 881 738 929 |
| Construct | **~12.4 min**; RSS after index **~6 MB** |
| `region` cold / warm | **47.7 s** / **32 ms** (1.66 M hits) |
| Descendant cold / warm | **63.1 s** / **0.88 s** (66.3 M hits) |
| `entity@kind` cold / warm | **113 s** / **0.81 s** |
| Regex `name%=/Entity_1/` | **32.5 s** / **113 ms** (11.1 M hits) |
| Indexed path | **137 s** (1 hit) |
| `set` / `new_` / `delete` / `validate` | **~120 s** / **722 s** / **432 s** / **95 s** |
| Peak RSS | **~24.6 GiB** queries; **~34 GiB** writes; after free **~2 MB** |

Path: file-backed opens (`/var/tmp`), attrs off, no CSR; `./bench_20gb.sh`. One-pass write remeasure: `.bench_out/bench_20gb.txt` (`ver=0.2.6-splice`).

`regex_selector_test.php`: 20/20. Native PCRE2: `make -C native test-regex`.

## Commands

```bash
./bench_ablation.sh
./native/bin/lomc .bench_out/fixture_100MB.xml
./native/bin/lomc .bench_out/fixture_1GB.xml
./bench_20gb.sh
```
