# Benchmark results snapshot

Machine: Linux 6.12 Manjaro, 12 CPUs, ~38 GiB RAM, PHP 8.5.8, GCC 16.1.

Full narrative: [`docs/paper/lom_living_object_model.md`](docs/paper/lom_living_object_model.md).

## RAM (primary focus)

| Config | File | Opens | RSS | After free |
|--------|------|-------|-----|------------|
| Before CSR (per-node children mallocs) | 100 MB | 4.4M | **~1424 MB** load | ~1 GB stuck |
| After CSR + scan trim + view-fmem | 100 MB | 4.4M | **~385 MB** load | **~2 MB** |
| lomc full run | 100 MB | 4.4M | **~528 MB** peak | — |
| After CSR | 1 GB | 44.8M | **~3905 MB** load | **~2 MB** |
| lomc full run | 1 GB | 44.8M | **~5.0 GB** peak | — |

PHP 100 MB (`memory_limit=512M`): construct lazy **~134 MB**; `region` via slim index **~402 MB**.

## Construct (native) — fixed superlinear fmem ingest

| Size | Before (bulk fmem ingest) | After (skip ingest + tight aux) |
|------|---------------------------|----------------------------------|
| 100 MB | ~3.5 s | **~1.1 s** |
| 1 GB | **~415 s** | **~10.5 s** |

Root cause: ingesting ~3.9M unique strings into a 2048-bucket fmem table was \(O(n^2)\); query paths never read fmem. Aux tag/attr indexes now size by max *name* id, not full string table.

## Writes (native)

| Op | 100 MB | 1 GB |
|----|--------|------|
| `set` text | ~296 ms | ~2.5 s |
| `new_` markup (local merge) | ~387 ms | ~3.4 s |

## Queries (native)

| Size | Descendant warm | Full `lomc` wall |
|------|-----------------|------------------|
| 100 MB | ~3 ms | ~2.9 s |
| 1 GB | ~55 ms | **~27 s** (was ~430 s) |

## Parallel ablation (honest miss)

`LOM_PARALLEL=1` atomic shared-count CSR workers: **slower** than serial on 100 MB and 1 GB (contention). Default remains off. Piece-local parallel *scan* is still future work.

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
| `name^=/Entity_1/` (indexed + doc-level) | **~28 ms** | 3024 |
| `note%=/note-0-0-0/` (doc-level rare) | **~5.5 ms** | 1 |

`regex_selector_test.php`: 20/20.

## Commands

```bash
./bench_ablation.sh
./native/bin/lomc .bench_out/fixture_100MB.xml
./native/bin/lomc .bench_out/fixture_1GB.xml
```
