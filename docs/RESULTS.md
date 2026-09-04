# Benchmark results snapshot

Machine: Linux 6.12 Manjaro, 12 CPUs, ~38 GiB RAM, PHP 8.5.8, GCC 16.1.

Full narrative: [`docs/paper/lom_living_object_model.md`](docs/paper/lom_living_object_model.md).

## RAM (primary focus)

| Config | File | Opens | RSS | After free |
|--------|------|-------|-----|------------|
| Before CSR (per-node children mallocs) | 100 MB | 4.4M | **~1424 MB** load | ~1 GB stuck |
| After CSR + scan trim + view-fmem | 100 MB | 4.4M | **~385 MB** load | **~2 MB** |
| + `parent_idx` + incr. splice (lomc full run) | 100 MB | 4.4M | **~528 MB** peak | — |
| After CSR | 1 GB | 44.8M | **~3905 MB** load | **~2 MB** |
| lomc full run (queries+writes) | 1 GB | 44.8M | **~5.0 GB** peak | — |

PHP 100 MB (`memory_limit=512M`): construct lazy **~134 MB**; `region` via slim index **~402 MB**. Full PHP parent-hash indexes skipped on large files unless `LOM_PHP_HEAVY_INDEX=1` (prefer `liblom`).

## Writes (native)

| Op | 2.58 MB | 100 MB | 1 GB |
|----|---------|--------|------|
| `set` text (offset shift, no rescan) | ~6 ms | **~275 ms** | **~2.0 s** |
| `new_` markup (local open merge; CSR deferred) | ~7 ms | **~377 ms** | **~2.5 s** |
| Next `get` after `new_` (pays CSR rebuild) | ~10 ms | ~505 ms | ~3.9 s |

Earlier full-reindex `set`/`new_` on 100 MB were ~1.8 s / ~1.2 s+.

## Queries (native)

| Size | Construct | Descendant cold | Descendant warm | Peak RSS |
|------|-----------|-----------------|-----------------|----------|
| ~2.58 MB | ~29 ms | ~2.8 ms | **~0.03 ms** | — |
| 100 MB | ~3.5 s | ~180 ms | **~3 ms** | ~528 MB |
| 1 GB | **~415 s** | ~1.45 s | **~41 ms** | **~5.0 GB** |

1 GB construct is worse than linear in opens (memory pressure / intern); still completes without swap thrash that previously aborted at ~14 GB RSS.

## Ablations (~2.58 MB)

- Descendant warm: **0.03 ms** with fcache vs **~3.7 ms** with `LOM_FCACHE=0`.

## Commands

```bash
./bench_ablation.sh
./native/bin/lomc .bench_out/fixture_100MB.xml
./native/bin/lomc .bench_out/fixture_1GB.xml
php -d memory_limit=512M -r 'require "O.php"; $O=new O(".bench_out/fixture_100MB.xml"); var_dump(count($O->get_tagged("region")));'
```
