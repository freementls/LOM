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
| Tile + mmap sidecar (`ver≥0.2.44-sidemap`) | 100 MB | 4.4M | **~0.65–0.79 s** first; mmap reload **~57 ms** | — |
| Split sidecar (`.lomidx` + `.lomopens`) | 1 GB | 44.8M | **~6.9–8.6 s** first; mmap reload **~315 ms** (was ~1.14 s memcpy) | — |
| Tile + split sidecar (`0.2.44-sidemap`) | 20 GB | 882M | **~363 s** first (incl. 3.3 GiB `.lomidx` + 20 GiB `.lomopens`); mmap reload **~406 ms / ~6 MB** | — |
| Opens hard-link persist (`0.2.45-openlink`) | 20 GB | 882M | **~292 s** first (link `.lomopens`, still writes 3.3 GiB `.lomidx`); mmap reload **~261 ms / ~7 MB** | — |
| Fractal recipe (`0.2.46-fractal`) | 1 GB | 44.8M virt | **~1.4 s** first (full-tag census ~1.1 s); **26 MB** `.lomidx` (no `.lomopens`); reload **~241 ms / ~7 MB** | — |
| Named census (`0.2.47-namedcensus`) | 1 GB | 44.8M virt | **~0.47 s** first (census **~93 ms**); reload **~357 ms / ~6.5 MB** | — |
| Fractal recipe (`0.2.46-fractal`) | 20 GB | 882M virt | **~40 s** first (full-tag census ~39 s + persist ~23 ms); **26 MB** `.lomidx`; reload **~303 ms / ~6.5 MB** | — |
| Named + parallel census (`0.2.47-namedcensus`) | 20 GB | 882M virt | **~2.2 s** first (census **~1.8 s** + persist ~20 ms); **26 MB** `.lomidx`; reload **~324 ms / ~6.6 MB** | — |
| Lazy map + numeric tile (`0.2.49-lazymap`) | 1 GB | 44.8M virt | first **~1.1 s**; idle RSS **~6 MB**; reload **~0.07 ms / ~5 MB** | — |
| Lazy map + numeric tile (`0.2.49-lazymap`) | 20 GB | 882M virt | warm first **~4.5 s** (census **~2.7 s** + persist ~47 ms + unmap); peak **~20 GiB**; idle **~30 MB**; reload **~0.12 ms / ~5.5 MB** | — |
| Steady lazy (`0.2.50-steady`) | 1 GB | 44.8M virt | sidecar reload **~0.04 ms / ~4 MB**; `Entity_42` **~0.14 ms** | — |
| Steady lazy (`0.2.50-steady`) | 20 GB | 882M virt | warm first **~2.7 s** (census **~2.3 s** + persist ~19 ms); peak **~20 GiB**; sidecar reload **~0.02–0.05 ms / ~3 MB**; `Entity_42` **~0.13–0.24 ms** | — |
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

`ver≥0.2.43-fastpath`: `LOM_PARALLEL` defaults **on** (same HW gate). Tile census (default on ≥4 MB) on this fixture: 100 MB **~1.73 s serial → ~1.19 s parallel → ~0.65 s tile**.

Open counts match serial. Peak RSS during parallel is higher (worker open tables); idle after create stays droppable when file-backed.

## Writes (native)

Anti-thrash path (`ver≥0.2.15-tombaux`, measured 2026-09-04 on this host):

| Op | 100 MB | 1 GB | 20 GB |
|----|--------|------|-------|
| construct | ~0.85–1.1 s / ~230 MB (anon default &lt;512 MB) | sidecar reload **~0.04 ms / ~4 MB** (`0.2.50`) | warm first **~2.7 s** (`0.2.50`; census ~2.3 s) / peak **~20 GiB**; reload **~0.02–0.05 ms / ~3 MB** |
| path select | ~0 ms | ~0 ms | **~0.8 ms** (indexed; 1 hit) |
| `set` text | ~30 ms / ~388 MB | **~1–2 ms** warm / first cold **~1.5 s** (faults opens; RSS ~1.2 GB) | **~5 ms** after counts faulted opens (`0.2.44`); file-backed thrash **~29 ms / ~10 MB** |
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
| 100 MB | **~0.01 ms** (`0.2.43` fcache borrow; was ~3 ms) | ~2.9 s |
| 1 GB | **~6 ms** (was ~55 ms) | **~27 s** (was ~430 s) |

`ver≥0.2.43-fastpath` `region_zone_entity_stats` (3.37 M hits at 1 GB): **count ~150 ms**, get cold **~230 ms**, warm borrow **~6 ms**. 100 MB (334 k hits): count ~15 ms / cold ~20 ms / warm ~0.01 ms.

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

Eager fstr build cost ~1 s on 100 MB and was unused by query paths (literal-hit prune does not need the tree). Default is **lazy** (no construct cost); `LOM_FSTR=eager` restores old behavior. `0.3.0-general` no longer skips the tree on ≥256 MB files (piece/tile prune needs it). Aux tag-rows are count-then-allocate. ≥512 MB opens stay file-backed unless `MemAvailable` covers code + open-table + ~1 GiB (hardware-gated anon).

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
| Construct (tile + hard-link `.lomopens`, `0.2.45-openlink`) | **~292 s**; peak RSS **~30 GB** (file-backed dest + aux). Sidecar: 3.3 GiB `.lomidx` + 20 GiB `.lomopens` (was **~363 s** when the opens file was rewritten) |
| Construct (named + parallel census, `0.2.47-namedcensus`) | **~2.2 s** (census **~1.8 s** + persist ~20 ms); **26 MB** `.lomidx`; peak RSS **~20 GB** (XML mmap). Same virtual n=881 738 929. Was **~40 s** full-tag census (`0.2.46`) / **~292 s** wholesale (`0.2.45`) |
| Sidecar reload | **~0.02–0.05 ms** / **~3 MB** (`0.2.50` no XML mmap; was **~324 ms**) |
| `lom_doc_count("region")` | **~0.06 ms** n=1 657 404 (`0.2.46` recipe; was **~4.7 s**) |
| `lom_doc_count("region_zone_entity_stats")` | **~0.02 ms** n=66 296 160 (`0.2.50`; was **~32.5 s** cold) |
| Path select (warm indexes) | **~0.05 ms** / 1 hit (`0.2.50`; was **137 s** pre–span-walk) |
| Exact text `entity_meta_name=Entity_42` | **~0.13–0.24 ms** n=1 (`0.2.50`; was **~2.0 s** / ~6.7 s) |
| Exact regex `name=/^Entity_42$/` | **~0.05–0.11 ms** n=1 (`0.2.50`) |
| `set` / `new_` / post / `delete` | **~29 ms** / **~3 ms** / **~0 ms n=1** / **~0 ms** — RSS stays **~10 MB**. In-tile `new_` now merges fragment opens into the leftover prefix (was n=0 on recipe `get` after insert). |
| Peak RSS (this write thrash) | **~10 MB**; after free **~2 MB** |
| `lom_doc_checkpoint` dest rewrite (`0.3.2-ois`) | **35.0 s** / **21 474 844 693** bytes / **~31 MB** RSS (streamed `copy_file_range` + overlay; dest sidecar reload **~0.04 ms** n=1 657 404). 1 GB dest rewrite **1.18 s / 6 MB**. Source fixture left intact. |

Older query-suite numbers (cold/warm broad scans, pre–anti-thrash writes):

| Metric | Value |
|--------|-------|
| `region` cold / warm | **47.7 s** / **32 ms** (1.66 M hits) |
| Descendant cold / warm | **63.1 s** / **0.88 s** (66.3 M hits) |
| `entity@kind` cold / warm | **113 s** / **0.81 s** |
| Regex `name%=/Entity_1/` | **32.5 s** / **113 ms** (11.1 M hits) |
| Indexed path | **137 s** (1 hit) |
| Legacy writes (`ver=0.2.6-splice`) | set ~120 s / new_ ~722 s / delete ~432 s; peak write RSS ~34 GiB |

Path: file-backed opens (`LOM_OPEN_TMPDIR=/var/tmp`), attrs off, no CSR. `bench_20gb.sh` now always dest-checkpoints (scratch `fixture_20GB.xml.checkpoint.xml`, then unlinks). Recipe clustering is O(n log n) by tag hash: 1 GB first-construct sample **22 ms** (was **240 s** O(n²)); 20 GB sample **650 ms**.

`regex_selector_test.php`: 20/20. Native PCRE2: `make -C native test-regex`.

## Corpora (`0.3.2-ois`, `./bench_corpus_fetch.sh` + `./bench_corpus.sh`)

Regular 1 GB / 20 GB tiling numbers stay in the tables above. These rows are a **temp copy**: one-leaf `set`, WAL persist, then **`lom_doc_checkpoint` writes a new XML file**. Public samples live in `.bench_out/corpus/` (gitignored). Sources: UniProt REST (CC BY 4.0), MediaWiki Special:Export (CC BY-SA), Wikimedia Commons SVG, Maven Central POM, W3C news Atom, OSM API 0.6 (ODbL), NASA RSS.

| File | Bytes | Opens | recipe | Construct | Count (sel) | Set | Write XML | RSS | Reload |
|------|-------|-------|--------|-----------|-------------|-----|-----------|-----|--------|
| `test.xml` | 1.3 KB | 66 | 0 | 0.11 ms | 0.014 ms n=5 `person` | 0.006 ms | **0.06 ms** | 2.8 MB | 0.06 ms |
| header-sections | 338 B | 8 | 0 | 0.06 ms | 0.003 ms n=2 `item` | 0.003 ms | **0.04 ms** | 3.0 MB | 0.05 ms |
| irregular mix (generated) | 227 KB | 12.0 K | **1** | **0.90 ms** (was 83 ms) | 0.003 ms n=3200 `item` | 0.04 ms | **0.17 ms** | 3.4 MB | 0.06 ms (sidecar) |
| UniProt P04637 | 844 KB | 17.1 K | **1** | **3.2 ms** (was 83 ms) | 0.014 ms n=1 `entry` | **0.02 ms** | **0.49 ms** | 4.1 MB | 3.0 ms |
| UniProt human×50 | 2.08 MB | 43.2 K | 0 | **10 ms** (parallel ≥1 MiB) | 0.004 ms n=50 `entry` | 0.14 ms | **0.84 ms** | 12 MB | 0.14 ms |
| Wikipedia export (XML/XPath/DOM/CSS + templates) | 1.99 MB | 4.4 K | 0 | **2.4 ms** | 0.005 ms n=254 `page` | **0.05 ms** `title` | **0.93 ms** | 5.2 MB | 0.08 ms |
| Ghostscript Tiger SVG | 69 KB | 482 | **1** | 0.40 ms | 0.003 ms n=240 `path` | — | **0.06 ms** | 3.1 MB | 0.02 ms |
| commons-lang3 POM | 31 KB | 663 | 0 | 0.21 ms | 0.002 ms n=8 `dependency` | 0.015 ms | **0.14 ms** | 3.1 MB | 0.21 ms |
| W3C news Atom | 45 KB | 234 | 0 | 0.10 ms | 0.002 ms n=26 `title` | 0.018 ms | **0.09 ms** | 3.1 MB | 0.11 ms |
| OSM London bbox | 2.23 MB | 43.7 K | 0 | **12 ms** (was 28 ms) | 0.007 ms n=1226 `node` | **0.03 ms** `node` (was 19 ms / 1.1 ms) | **1.0 ms** | 19 MB | 0.09 ms |
| NASA RSS | 140 KB | 119 | 0 | 0.23 ms | 0.002 ms n=10 `item` | 0.24 ms | **0.10 ms** | 3.2 MB | 0.20 ms |
| SOAP envelope | 432 B | 8 | 0 | 0.05 ms | 0.002 ms n=2 `symbol` | 0.002 ms | **0.05 ms** | 3.1 MB | 0.05 ms |

`write_xml` is a real rewrite (`checkpoint` → new `.xml` on disk). Same-size leaves stay WAL/`pwrite`. A length-changing set of a large inner (a Wikipedia `page`, a UniProt `entry`) used to SIGSEGV in the overlay window — the pre-splice copy was larger than the allocated buffer; that is fixed and covered by `test-wal`.

Wholesale remains the fallback when tiles cover less than half the file, or when seed siblings differ in size by more than 2× (OSM empty `node` vs tagged `node`). Parallel wholesale now starts at **1 MiB** (was 4 MiB): OSM construct **12 ms**, UniProt×50 **10 ms**. Inner-text replace of a parent tombstones child opens and applies a byte bias (no 43 K-row memmove) — OSM `set` of a `node` is **0.03 ms** (was 19 ms reindex / 1.1 ms memmove). MB-class overlay pad is 64 KiB. Broad `get` materializes offset pairs — use `count` / `get_ois` when that is enough. Lab compares (not paper): `php bench_vs_tools.php`, `./bench_vs_basex.sh`.

## Lab compares (not paper)

Same corpus, this host. LOM numbers from `./bench_corpus.sh`. DOM/XPath from `php bench_vs_tools.php` (libxml). BaseX from `./bench_vs_basex.sh` (standalone **12.x**, `doc()` + `count(//*:name)` + `copy/modify` + `file:write`; in-query `prof:current-ns`, JVM startup excluded). `doc()` looks lazy — parse is inside `count`. Use `count` and `set+write`, not `doc=0.02 ms`, as construct.

| File | LOM construct / count / set+checkpoint | BaseX count / set+write | DOM loadXML+XPath / set+saveXML |
|------|----------------------------------------|-------------------------|----------------------------------|
| UniProt P53 | 2.4 / 0.008 n=1 / 0.33 ms | 0.09 n=1 / **242 ms** | 15+12 / 26 ms |
| UniProt ×50 | 10 / 0.004 n=50 / 0.98 ms | 0.05 n=50 / **346 ms** | 37+22 / 70 ms |
| Wikipedia | 2.3 / 0.004 n=254 / 0.71 ms | 0.05 n=254 / **153 ms** | 5.9+8.5 / 19 ms |
| OSM London | 12 / 0.007 n=1226 / 1.0 ms | 0.07 n=1226 / **293 ms** | 39+22 / 103 ms |
| irregular mix | 0.76 / 0.003 n=3200 / 0.16 ms | 0.04 n=3200 / **110 ms** | — |

Where LOM still loses the *shape* of XPath: materializing a large node-set as offset pairs after the tree already exists. Prefer `count` / `get_ois`. `get` now fills wholesale matches from the open table (and recipe tile geometry) without `oi_match` / per-tile instantiate. In-tile length change or markup flattens that tile into leftover; indexed `tag[n]` merges leftover roots with remaining tiles.

## Commands

```bash
./bench_corpus_fetch.sh
./bench_corpus.sh
php bench_vs_tools.php
./bench_vs_basex.sh
./bench_ablation.sh
./native/bin/lomc .bench_out/fixture_100MB.xml
./native/bin/lomc .bench_out/fixture_1GB.xml
./bench_20gb.sh
make -C native test-all
```
