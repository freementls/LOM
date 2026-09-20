# Performance Notes

This document tracks durable performance improvements, how they were validated, and how to continue tuning safely.

## Scope

Primary tuning focus in this cycle:

- `get()` and selector fastpaths
- `set()` write path via `replace()`
- parent-index patching after writes

Secondary focus:

- benchmark repeatability (median/p90) to control noise

## Durable Changes Kept

### `O.php` - `get()` / selector fastpaths

- Added earlier handling for simple attribute selector shapes (`tag@attr`) via fast attribute path.
- Fixed simple attribute selector parsing fallback so selectors like `entity@kind` resolve correctly.
- Reduced overhead in direct-chain fastpath result assembly/dedupe.

Why:

- Reduce `get()` path overhead.
- Avoid unnecessary context probing / expensive fallback behavior where fastpaths are valid.

### `O.php` - `replace()` (write hot path)

- Made depth handling lazy/derived where possible when parent indexes are available.
- Avoided unconditional expensive depth work on every replace.
- Reused precomputed lengths (`old_len`, `new_len`, `span_len`) across hot replace logic.
- Cached opening-tag-span intersection result per replace call to avoid duplicate scans.

Why:

- `set()` spends most time through `replace()`.
- Eliminating repeated per-write work yields direct improvements in `set()` and write-heavy flows.

### `O.php` - parent-index patching

- In `patch_parent_indexes_from_edit`, batched tag-index removals by tagname and filtered each affected tag index once.

Why:

- Previous behavior removed one opening at a time and repeatedly scanned the same tag index.
- Batch removal lowers repeated scan churn during edit patching.

### `O.php` - utility helpers

- Added `merge_two_sorted_numeric()` for sorted-run merge operations used by patch bookkeeping.
- Added batched tag-index removal helper for parent-index patching.

## Benchmark Harness Added

### `perf_repeat.php`

Repeated full benchmark runner over `perf_test.php`.

- Reports `min / median / p90 / max` for:
  - total seconds
  - set-after-warmup
  - new_-nested-insert
  - top-level-cold
  - descendant-warm

Usage:

- `php perf_repeat.php`
- `php perf_repeat.php 11`

### `set_perf_repeat.php`

Repeated focused `set()` benchmark for lower-noise write-path signal.

Usage:

- `php set_perf_repeat.php`
- `php set_perf_repeat.php 11`

## Validation Protocol (Used For Every Kept Change)

1. Run focused perf:
   - `php set_perf_repeat.php <n>`
2. Run mixed perf:
   - `php perf_repeat.php <n>`
3. Run regressions:
   - `php indexed_selector_regression_test.php`
   - `php write_regression_test.php`
4. Keep only if:
   - medians improve or remain acceptable
   - regressions pass
5. Revert immediately on median regression even if correctness tests pass

## Notes on Noise

- Single-run `perf_test.php` is too noisy for reliable decisions.
- Median + p90 across repeated runs is the primary gate.
- Full-suite and focused-suite may diverge; prioritize the target workload but keep mixed-suite sanity.

## Suggested Next Targets

If further tuning is needed:

- `patch_parent_suffix_remap` internals (small reversible edits only)
- `replace_patch_parent_indexes` branch-local churn
- `new_()` internals with strict median gate and immediate revert on mixed regression

## Regex selectors + large fixtures (2026-09)

- PHP: `/pattern/flags` after any comparison operator; `/` is no longer a child-path alias.
- `php regex_selector_test.php` — operator × attribute coverage.
- `php gen_perf_fixture.php [size] [out]` and `./bench_large.sh 100MB` — opt-in large profiles (`LOM_ALLOW_HUGE=1` for ≥10GB).
- `php bench_vs_tools.php` — lab LOM vs DOM/XPath/XMLReader (not a paper table).
- `./bench_vs_basex.sh` — lab BaseX on the same corpus (fetches `.bench_out/basex`; not a paper table).
- Native: `lom_fmem` / `lom_fcache` + tag-aligned `lom_piece_boundaries` (`make -C native test-fmem`).
- `ver≥0.2.43-fastpath`: zero-copy fcache borrow; `lom_doc_count` / `lom_doc_get_ois` for huge descendant; `.lomidx` sidecar; `LOM_PARALLEL` default on; tile census (`LOM_TILE`). `make -C native test-fastpath`.
- `ver≥0.2.44-sidemap`: sidecar is mmap'd (no memcpy). Opens &gt;512 MiB go to `*.lomopens` so 20 GB can persist. 100 MB reload **~57 ms**; 1 GB **~315 ms**; **20 GB first construct ~363 s** (tile + 23 GiB sidecar write), reload **~0.4 s / ~6 MB**, descendant count **~32.5 s** (n=66.3 M).
- `ver≥0.2.45-openlink`: split persist hard-links the live file-backed opens fd (`linkat` / `/proc/self/fd`) instead of rewriting `.lomopens`. `lom_doc_from_sidecar()`, `lomc --construct-only`. 1 GB construct **~7.2–7.7 s**, reload **~250–321 ms / ~7 MB**. 20 GB first **~292 s** (was ~363 s with a 20 GiB opens copy), reload **~261 ms / ~7 MB** (`sidecar=1`).
- `ver≥0.2.46-fractal`: fractal construct persists a recipe (prefix + one tile template + depth-1 ranges) instead of 882 M open rows. `LOM_FRACTAL=0` keeps wholesale tile replay. 1 GB first **~1.4 s** / reload **~241 ms**. 20 GB first **~40 s** (census ~39 s, persist ~23 ms, **26 MB** `.lomidx`) / reload **~303 ms / ~6.5 MB**. Descendant count **~0.002 ms** (n=66.3 M; was ~32.5 s). Non-tiling docs stay on the 0.2.45 path.
- `ver≥0.2.47-namedcensus`: depth-1 census is `memmem` of `<name` / `</name>` (skip `find_tag_close` on inner tags), parallel over byte slices without the 2×-open HW gate. 1 GB first **~0.47 s** (census **~93 ms**). 20 GB first **~2.2 s** (census **~1.8 s**). Same recipe counts (1.66 M / 66.3 M).
- `ver≥0.2.48-parlit`: exact text / `>lit<` regex probes run `memmem` in parallel (≥64 MiB). 20 GB `entity_meta_name=Entity_42` **~2.0 s** (was ~6.7 s). After recipe construct, `madvise(DONTNEED)` drops the XML so idle RSS stays sidecar-sized until a byte query faults pages.
- `ver≥0.2.49-lazymap`: sidecar reload keeps the XML **unmapped** (fd only). Tile instantiate / exact `Name_N` text and `>lit<` regex `pread` one ~13 KiB range (stride from tile 0/1). 1 GB reload **~0.07 ms**; 20 GB reload **~0.12 ms / ~5.5 MB** (was ~324 ms). 20 GB `Entity_42` **~0.3 ms** (was ~2.0 s). After recipe construct the 21 GiB map is `munmap`'d; idle RSS **~30 MB**.
- `ver≥0.2.50-steady`: keep the XML mapped after first construct (no `munmap`/`DONTNEED` tax — warm 20 GB create **~2.7 s**). Reload still lazy. First write maps RW once. `Name_N` stride is cached; 4 parked tiles. `lom_doc_save_file` maps before write. `lomc --sel` / `--count` / `--json` for the local large demo.


- `liblom` + `lomc`: full in-process get/set/new_
- `lomd`: persistent OData/API daemon for Power Apps / Power BI
  - `$filter` / `$top` / `$skip` / `@odata.nextLink` server-side
  - `/api/List*` actions avoid Power Apps 500/2000 client row limit
  - stable `lom_id` keys, API-key auth, write mutex + atomic save
  - OpenAPI: `powerapps/openapi.json` — see `powerapps/README.md`

```bash
./native/bin/lomd --file powerapps/demo.xml --entities powerapps/entities.conf \
  --port 8080 --api-key 'dev-secret'
```


