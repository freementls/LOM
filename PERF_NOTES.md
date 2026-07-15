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

## Native C accelerator + Power Apps service (2026-07)

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


