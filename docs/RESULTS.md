# Benchmark results snapshot

Machine: Linux 6.12 Manjaro, 12 CPUs, ~38 GiB RAM, PHP 8.5.8, GCC 16.1.

Full narrative: [`docs/paper/lom_living_object_model.md`](docs/paper/lom_living_object_model.md).

## RAM (primary focus)

| Config | File | Opens | RSS | After free |
|--------|------|-------|-----|------------|
| Before CSR (per-node children mallocs) | 100 MB | 4.4M | **~1424 MB** load | ~1 GB stuck |
| After CSR + scan trim + view-fmem | 100 MB | 4.4M | **~385 MB** load | **~2 MB** |
| + `parent_idx` + incr. text splice (lomc full run) | 100 MB | 4.4M | **~528 MB** peak | — |
| After CSR | 1 GB | 44.8M | **~3905 MB** load | **~2 MB** |

PHP 100 MB (`memory_limit=512M`): construct lazy **~134 MB**; `region` via slim index **~402 MB**. Full PHP parent-hash indexes skipped on large files unless `LOM_PHP_HEAVY_INDEX=1` (prefer `liblom`).

## Writes (native, 100 MB)

| Op | Before (full reindex) | After structure-preserving splice |
|----|----------------------|-------------------------------------|
| `set` text (no `<`) | ~1810 ms | **~228 ms** (memmove + offset shift) |
| `new_` nested insert | ~full reindex | still full reindex (~1.2 s) |

## Ablations (~2.58 MB)

- Descendant warm: **0.03 ms** with fcache vs **~3.7 ms** with `LOM_FCACHE=0`.
- `set` text: **~7.5 ms** with incremental splice (was tens of ms + full rescan).

## Commands

```bash
./bench_ablation.sh
./native/bin/lomc .bench_out/fixture_100MB.xml
php -d memory_limit=512M -r 'require "O.php"; $O=new O(".bench_out/fixture_100MB.xml"); var_dump(count($O->get_tagged("region")));'
```
