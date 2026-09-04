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
| File-backed opens, no attrs, no CSR (`ver≥0.2.34-aux1`, default ≥8 M opens) | 1 GB | 44.8M | **~180 MB** construct | **~2 MB** |
| Packed open rows 32 B (`ver≥0.2.36-pack32`, was 40 B) | 1 GB | 44.8M | **~180–190 MB** construct; broad `note` query RSS **~1.66 GB** (was ~2.0 GB) | **~3 MB** |
| Packed open rows 24 B (`ver≥0.2.37-pack24`, `node_end_rel` + rare >4 GiB overflow) | 1 GB | 44.8M | **~178 MB** construct; broad `note` RSS **~1.31 GB** | **~3 MB** |
| Anon opens default &lt;512 MB (`ver≥0.2.39-openpolicy`); lazy fstr (`ver≥0.2.42-easywin`) | 100 MB | 4.4M | **~1.0–1.2 s / ~230 MB** construct (was ~2.1 s with eager fstr) | **~2 MB** |
| lomc full run (legacy heap+attrs) | 1 GB | 44.8M | **~5.0 GB** peak | — |

PHP 100 MB (`memory_limit=512M`): construct lazy **~134 MB**; `region` via slim index **~402 MB**.

## Construct (native) — fixed superlinear fmem ingest

| Size | Before (bulk fmem ingest) | After (skip ingest + tight aux) |
|------|---------------------------|----------------------------------|
| 100 MB | ~3.5 s | **~1.1 s** |
| 1 GB | **~415 s** | **~10.5 s** |

Root cause: ingesting ~3.9M unique strings into a 2048-bucket fmem table was \(O(n^2)\); query paths never read fmem. Aux tag/attr indexes now size by max *name* id, not full string table.

### `LOM_PARALLEL` piece-local scan (opt-in, hardware-gated)

Depth-1 sibling ranges → per-piece scan → merge (name-only dedup). **Default off.** `LOM_PARALLEL=1` (or `auto`) enables only when **≥4 CPUs** and `MemAvailable` covers ~code + 2× open-table + 512 MB slack; otherwise serial. No ≥256 MB auto-serial ceiling (`ver≥0.2.41-parhw`). On this host (12 CPUs, ~15 GiB avail):

| Size | Serial | `LOM_PARALLEL=1` |
|------|--------|------------------|
| 100 MB | ~0.97 s | **~0.87 s** |
| 1 GB | ~7.3 s / ~195 MB | **~5.3 s / ~257 MB** |

Open counts match serial. Peak RSS during parallel is higher (worker open tables); idle after create stays droppable when file-backed.

## Writes (native)

Anti-thrash path (`ver≥0.2.15-tombaux`, measured 2026-09-04 on this host):

| Op | 100 MB | 1 GB | 20 GB |
|----|--------|------|-------|
| construct | ~0.85–1.1 s / ~230 MB (anon default &lt;512 MB) | **~5–7 s** serial / **~5.3 s** with `LOM_PARALLEL=1` (12 CPUs; default still serial) / ~180–260 MB | **~13.5 min / ~6 MB** |
| path select | ~0 ms | ~0 ms | **~2 ms / ~6 MB** |
| `set` text | ~30 ms / ~388 MB | **~1–2 ms** warm / first cold **~1.5 s** (faults opens; RSS ~1.2 GB) | **~29 ms / ~10 MB** |
| `new_` | **~33 ms / ~354 MB** | **~1–2 ms** | **~3 ms / ~10 MB** |
| post-write path query | ~0 ms n=1 | ~0 ms n=1 | **~0 ms n=1** |
| `delete` | **~0–30 ms** | **~0 ms** | **~0 ms / ~10 MB** |

Notes:
- `madvise`/`msync` on open-table ranges must be **page-aligned** (`lom_open_row` is **24 B** as of `0.2.37-pack24`; `tag_end`/`node_end` stored as relatives; spans >4 GiB use a rare overflow table).
- Pure inserts defer `parent_idx` / tag-row remaps (`idx_bias` + `tag_pend`).
- When the opens-table tail to shift would be ≥64 MB, `new_` **appends** rows out of document order (`open_sorted_n`) instead of `memmove`.
- Tombstone `delete` leaves tag-rows pointing at `LOM_OPEN_DEAD` (readers skip); compacting every tag row on 20 GB was ~10 s / ~3 GB RSS.

Legacy table (pre–overlay/tombstone/append):

| Op | 100 MB | 1 GB | 20 GB |
|----|--------|------|-------|
| `set` text | ~1.2 s | ~4.0 s | **~120 s** |
| `new_` markup | ~367 ms | ~4.6 s | **~722 s** |
| `delete` | ~0.4 s | ~3.7 s | **~432 s** |

Growth `set` uses a **code overlay** plus deferred open-offset bias. Pure `new_` inserts in-place or appends. Complete-subtree `delete` tombstones opens. No-CSR child steps use an **in-span** walk.

## Queries (native)

| Size | Descendant warm | Full `lomc` wall |
|------|-----------------|------------------|
| 100 MB | ~3 ms | ~2.9 s |
| 1 GB | ~55 ms | **~27 s** (was ~430 s) |

Broad / descendant (1 GB, `ver≥0.2.37-pack24`, measured 2026-09-04):

| Selector | Before | After |
|----------|--------|-------|
| `region[1]__note` | — | **~0 ms** n=40 |
| `region__note` | ~805 ms / ~2.3 GB (parent-walk) | **~150–230 ms** n=3.37M / ~1.3–1.4 GB |
| `region_zone` | ~196 ms | **~25–45 ms** |
| `note` | staging oi array + emit | **~180–230 ms** n=3.37M / **~1.31 GB** (was ~2.0 GB @40 B rows) |
| `entity@kind` / `*@id` | ~6 s (`*@id`) | **~350–500 ms** (`0.2.38-attrfast`) |
| `*` | ~1.1 s / ~4 GB | **~0.7–1.3 s** get; **`lom_doc_count("*")` ~0.15 s / ~1.2 GB** (`0.2.40-count`) |
| `region_zone_entity_meta_note` | ~640 ms | **~250–420 ms** |
| `name%=/Entity_1/` | ~1 s+ | **~380–600 ms** n=1.11M |

`A__B` uses a sequential ancestor/leaf tag-row merge (no parent-pointer walks). Pure `_` chains (`A_B_C_…`, `ver≥0.2.29-nidwalk`) walk `parent_idx` with pre-resolved `name_id` compares. `A__B_C` / `A__B__C` merge the same way (e.g. `region__meta_note`, `region__meta__note`). Indexed `A[n]__B` uses tag-row span collect under the chosen parent. Regex leaf paths also use resolved name_ids on ancestor checks. Optional `LOM_SCAN_DROP=1` (or auto at ≥100M opens) progressively `madvise(DONTNEED)`s consumed open pages — scan eviction does **not** `posix_fadvise` the opens file (that flushed page cache and multi-second regressions).

Regex on a path (`ver≥0.2.24-regscope`): when the selector has ancestor indexes (or a rare root), **structure first** then tagvalue regex — so `region[1]__note%=/note/` is **n=40 / ~0 ms**, not every note in the doc (~3 s). Broad unindexed `note%=/…` still uses document literal hits.

Attr presence without attr index (`ver≥0.2.25-attroi` / `0.2.26-attrscan` / `0.2.29-nidwalk` / `0.2.30-hitcur` / `0.2.38-attrfast`): parse open-tag bytes via known open index (presence uses a single ` attr=` `memmem` on the mmap'd tag). `*@attr` / regex literal hits map document hits with a **monotonic open/tag-row cursor**. 1 GB: `*@id` **~0.35–0.5 s**; `entity@kind` **~0.35–0.55 s**; `*` get **~0.7–1.3 s**; **`lom_doc_count("*")` ~0.15 s / ~1.2 GB** (`0.2.40-count`, no match-list alloc); `name%=/Entity_1/` **~0.4–0.6 s**; `entity[1]@kind` **n=1**.

## Parallel ablation (honest)

`LOM_PARALLEL` piece-local scan: **opt-in**, hardware-gated (≥4 CPUs + RAM); default off. 1 GB on this host: **~7.3 s → ~5.3 s** when enabled (`0.2.41-parhw`).

### Lazy fractal string (`LOM_FSTR`, `ver≥0.2.42-easywin`)

Eager fstr build cost ~1 s on 100 MB and was unused by query paths (literal-hit prune does not need the tree). Default is **lazy** (no construct cost); `LOM_FSTR=eager` restores old behavior; ≥256 MB still skips. Aux tag-rows are count-then-allocate. ≥512 MB opens stay file-backed unless `MemAvailable` covers code + open-table + ~1 GiB (hardware-gated anon).

## Fractal string (`lom_fstr`, `LOM_FSTR`)

Hierarchical spans (document → root children → tag-aligned blocks) with per-node byte-presence + 3-gram bloom signatures. Cold exact find / regex-with-literal-probe prune impossible subtrees before `memmem`/PCRE. **Lazy by default** (`ver≥0.2.42`); opt-in eager with `LOM_FSTR=eager`. Helps selective cold scans when built; not a substitute for tag/CSR indexes.

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

Regex on large docs where fstr is skipped (≥256 MB), `ver≥0.2.23-litscan`: document `lom_fss_memmem` of a literal probe → covering tag-row opens → **tagvalue/attr verify only**. Exact `^lit$` / `=` uses `>lit<` so `Entity_1` does not drag in `Entity_10`.

| Selector (1 GB) | Before (per-tag PCRE) | After (literal hits) |
|-----------------|----------------------|----------------------|
| `note%=/note-0-0-0/` | ~500 ms n=1 | **~200–300 ms** n=1 |
| `name%=/^Entity_1$/` | ~1 s+ n=1 | **~190 ms** n=1 |
| `name%=/Entity_1/` | ~1.0 s n=1.11M | **~0.5–0.8 s** n=1.11M |
| same on 100 MB | — | **~19–54 ms** |

## 20 GB (measured)

| Metric | Value |
|--------|-------|
| Opens | 881 738 929 |
| Construct | **~13.5 min**; RSS after index **~6 MB** (`ver≥0.2.15-tombaux`) |
| Path select (warm indexes) | **~2 ms** / ~6 MB |
| `set` / `new_` / post / `delete` | **~29 ms** / **~3 ms** / **~0 ms n=1** / **~0 ms** — RSS stays **~10 MB** |
| Peak RSS (this write thrash) | **~10 MB**; after free **~2 MB** |

Older query-suite numbers (cold/warm broad scans, pre–anti-thrash writes):

| Metric | Value |
|--------|-------|
| `region` cold / warm | **47.7 s** / **32 ms** (1.66 M hits) |
| Descendant cold / warm | **63.1 s** / **0.88 s** (66.3 M hits) |
| `entity@kind` cold / warm | **113 s** / **0.81 s** |
| Regex `name%=/Entity_1/` | **32.5 s** / **113 ms** (11.1 M hits) |
| Indexed path | **137 s** (1 hit) |
| Legacy writes (`ver=0.2.6-splice`) | set ~120 s / new_ ~722 s / delete ~432 s; peak write RSS ~34 GiB |

Path: file-backed opens (`LOM_OPEN_TMPDIR=/var/tmp`), attrs off, no CSR.

`regex_selector_test.php`: 20/20. Native PCRE2: `make -C native test-regex`.

## Commands

```bash
./bench_ablation.sh
./native/bin/lomc .bench_out/fixture_100MB.xml
./native/bin/lomc .bench_out/fixture_1GB.xml
./bench_20gb.sh
```
