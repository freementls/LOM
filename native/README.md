# LOM native core (`liblom` + `lom_accel` + `lomc` + `lomd`)

1. **`O.php`** stays the public PHP API.
2. **`liblom`** — C document engine (get/set/new_/indexes).
3. **`lomd`** — persistent OData/HTTP service for Power Apps / Power BI.

## Build

```bash
make -C native
```

Native child selectors use **`_`** (`region_zone_entity`) for direct children and **`__`** for descendants. Tag names or values containing `_` must use `#underscore#` (PHP `enc()` / `query_encode()`). `/` is reserved for regex values in the PHP API.

## Security boundaries (selectors / regex)

- **Regex only on tagvalues and attribute values** (`tag%=/…/`, `tag@attr%=/…/`), never on tag names or axes. That keeps patterns inside value space and avoids “breaking out” into structure.
- **Tagvalue ≠ structure.** Element-text compares use **tagless** character data (markup stripped). `person%=/<\//` does not match nested tags; use `person_name=…` for children.
- **Attribute values** match only attribute strings, never raw open-tag markup.
- **No document-wide regex** over source bytes. Per-candidate subjects only. Flags: `imsux`. Pattern ≤512. PCRE match/depth limits (ReDoS).
- **Path metacharacters:** `_` / `__` split axes — encode with `#underscore#` / `enc()` (like SQL bind parameters).
- **`lomd` writes** XML-escape JSON fields before splice.

### Database-attack analogues

| DB / web attack | LOM analogue | Mitigation |
|---|---|---|
| SQL injection | Selector metacharacters (`_`, `/…/`, ops) in untrusted input | Encode values; allowlist selectors; regex only after comparison ops |
| UNION / tautology | `\|` branches, `*=/./` over-broad matches | App-level allowlists; avoid concatenating user text into axes |
| Second-order injection | Stored text with `_` / `<` later used as a selector | Encode when building selectors from stored values |
| ReDoS | Catastrophic regex | Match/recursion limits; pattern length cap |
| XXE | External entity expansion | LOM is string/index based — no XML entity resolver in the native path |
| Mass assignment | `lomd` POST/PATCH column map | Entity config defines columns; values escaped |
| Auth bypass | Open `lomd` | Require `--api-key` in production |

## Accelerators (fmem / fcache / pieces / fstr)

`lom_doc_create_file` prefers **mmap**. Aux indexes use **CSR children** + exact-sized `uint32` tag/attr rows (large RAM win). Env toggles:

| Variable | Default | Effect |
|---|---|---|
| `LOM_FMEM=0` | on | Disable string table (views into `string_blob`, no duplicate copies) |
| `LOM_FCACHE=0` | on | Disable selector result memo |
| `LOM_PIECES=0` | on | Disable tag-aligned piece split |
| `LOM_FSTR=0` | lazy (≥4 KiB, off ≥256 MB) | Fractal-string signatures. Default **lazy** (no construct cost); `LOM_FSTR=eager` builds on create; `0` disables. |
| `LOM_OPEN_MMAP` | auto | `0`=heap, `1`=file-backed, `a`=anonymous. Default: anon &lt;512 MB; ≥512 MB file-backed **unless** `MemAvailable` covers code + open-table + ~1 GiB (then anon for faster construct). |
| `LOM_OPEN_SPILL=1` | off | After index build, copy anon opens to a tempfile map then DONTNEED. |
| `LOM_OPEN_TMPDIR` | `/var/tmp` | Dir for open-table + large code-rewrite tempfiles (avoid `/tmp` tmpfs) |
| `LOM_ATTRS=1` | off ≥512 MB | Force attribute capture on huge docs |
| `LOM_CSR=0` | on (&lt;8 M opens) | Skip CSR; child axis uses in-span walk. GB-class docs default off. |
| `LOM_PARALLEL` | **off** | Opt-in piece-local sibling scan (`1`/`auto`). Hardware-gated: ≥4 CPUs and enough `MemAvailable` for ~2× open-table + code; else serial. Never the unset default. |
| `LOM_SCAN_DROP=1` | auto ≥100M opens | During huge tag scans, `madvise(DONTNEED)` consumed open pages (lower RSS, higher latency). |
| `LOM_FSS_SCAN` / `LOM_FSS` | **on** (n≥4 KiB) | Optional [libfss](https://github.com/freementls/fractal_substring) `memmem` for large scans (`LOM_FSS_LIB` overrides path). Set `0` to force libc. |

Large-doc edits: growth uses overlay + deferred open-offset bias; pure `new_` inserts open rows in-place when the tail is small, otherwise **appends** out-of-order (`open_sorted_n`, ≥64 MB tail) to skip multi-GB `memmove`; defers parent/tag-index remaps (`idx_bias` / `tag_pend`); page-aligned `MADV_DONTNEED` on dirty open spans; complete-subtree `delete` tombstones opens (`LOM_OPEN_DEAD`) without compacting tag-rows (readers skip dead). Open rows are **24 B** (`tag_end_rel` / `node_end_rel`; rare >4 GiB spans use `ne_ovf_rel`). Prefer `lom_doc_count()` over `lom_doc_get()` when only cardinality is needed (`*` on 1 GB: ~0.15 s / ~1.2 GB vs ~1.3 s / ~2.6 GB).

Ablations: `../bench_ablation.sh [fixture]`.

PHP large files: lazy packed depths (`lom_packed_depths.php`), slim tag index; set `LOM_PHP_HEAVY_INDEX=1` only if you need full PHP parent maps.
## Power Apps

See [`powerapps/README.md`](../powerapps/README.md). Short version:

```bash
./native/bin/lomd \
  --file powerapps/demo.xml \
  --entities powerapps/entities.conf \
  --port 8080 --api-key 'dev-secret'
```

Import `powerapps/openapi.json` as a custom connector. Call **ListPeople** with `$filter`/`$top` so filtering stays on the server — that is how you avoid the Power Apps 500/2000 client row limit.
