# Benchmark results snapshot

Machine: Linux 6.12 Manjaro, 12 CPUs, ~38 GiB RAM, PHP 8.5.8, GCC 16.1.

Full narrative: [`docs/paper/lom_living_object_model.md`](docs/paper/lom_living_object_model.md).

## Ablations (`./bench_ablation.sh`, ~2.58 MB)

- Descendant warm: **0.03 ms** with fcache vs **~3.7 ms** with `LOM_FCACHE=0`.
- Indexed warm: **~0 ms** vs **~4.4 ms** without fcache.

## Native size scaling

| Size | Construct | Desc cold | Desc warm | Parent cold | set |
|------|-----------|-----------|-----------|-------------|-----|
| 1 MB | 23 ms | 2.5 ms | 0.02 ms | 4.6 ms | 20 ms |
| 100 MB | 5404 ms | 187 ms | 3.6 ms | 127 ms | 1810 ms |
| 1 GB | aborted (swap thrash ~14 GiB RSS) | — | — | — | — |

## PHP

- ~2.58 MB `perf_test.php`: construct ~130 ms; regex rare ~45 ms; set ~341 ms.
- 100 MB @ 8 G limit: construct ~4.6 s; region cold ~14 s / warm ~0.4 s.
- 100 MB @ 1 G limit: OOM on construct.

## Personal bake-off (not paper main table)

Same ~2.58 MB file, count `name`: LOM ~451 ms, XPath ~19 ms, XMLReader ~86 ms.

## Commands

```bash
./bench_ablation.sh
./bench_sizes.sh                 # 1MB/100MB/1GB; skip huge
LOM_ALLOW_HUGE=1 ./bench_large.sh 20GB
php regex_selector_test.php
make -C native && ./native/bin/lomc perf_fixture.xml
```
