# Living Object Model: String-Resident XML with Conversational Context and Fractal Selection

**Working draft** — empirical results recorded on Manjaro Linux, AMD/Intel x86_64, 12 cores, ~38 GiB RAM, PHP 8.5.8, GCC 16.1, Apache-2.0 LOM tree.

## Abstract

Living Object Model (LOM) is an in-process string-resident XML engine: the document remains contiguous source text; query results are `(substring, offset)` pairs; writes splice the string and patch indexes instead of rebuilding a DOM. On this host it stays cheap on both **regular** (tiled 1–20 GB fixture) and **irregular** documents for selective query and incremental mutation, with optional XPath/CSS facades that compile to LOM selectors and a durable overlay (`pwrite` / `path.lomwal`) so a multi-GB file need not be rewritten on every edit. Novelty is the *combination* of (1) conversational / dynamic context across queries, (2) incremental string-resident mutation, (3) bidirectional “fractal” selection (selective end first), and (4) living named selections—not regex-on-XML per se. Each property appears in prior work; §2.1 records that no cited line of work provides all four on one in-process string. Compatibility facades and the WAL are engineering, not a novelty claim. The tables in this paper are LOM scaling, irregular corpora, and mechanism ablations — not head-to-head bake-offs.

## 1. Introduction

XML tooling is dominated by extractive trees (DOM), streaming (SAX/XMLReader), and non-extractive token tables (VTD-XML). Query languages (XPath/XQuery, CSS) evaluate each expression against a static context item. Interactive editing and conversational follow-ups—“who was that person again?”—do not map cleanly onto static expressions. Section 2.1 states the gap: each ingredient exists somewhere in the literature; the combination on one in-process string does not.

LOM keeps the document as one mutable string, answers selectors with offset-backed matches, and carries a **dynamic context** from prior results into later queries. A **fractal** path can start at a selective tag value or attribute and walk parents, analogous in spirit to semi-join reduction rather than only top-down tree walks.

**Contributions.**

1. Formalize LOM’s query model: context, fractal get, living variables, and regex as a *value form* on existing comparison operators (`=`, `%=`, `^=`, `$=`, `~=`, `!=`, numeric), not a new `=~` binding syntax.
2. Place the design against prior art (VTD-XML, XPath, BaseX, Yannakakis/SIP).
3. Give complexity notes and pseudocode for context probe, fractal get, regex match-array filters, and dirty-piece / fcache invalidation.
4. Publish empirical scaling (1 MB → 100 MB → 1 GB native; PHP limits called out) and ablations (`LOM_FCACHE`, `LOM_FMEM`, `LOM_PARALLEL`, `LOM_PIECES`).
5. Ship Apache-2.0 connectors via `liblom` ABI and `lomd` OpenAPI (C#, Python, TypeScript, Java, Go stubs).

## 2. Related work

**Non-extractive XML.** VTD-XML [Zhang et al.; xml.com 2004; vtd-xml.sourceforge.io] keeps the source bytes and records 64-bit Virtual Token Descriptors (offset, length, type, depth). LOM shares the “document is the buffer” stance and offset-centric results. Differences: LOM’s public surface is a conversational selector language with living context and incremental PHP/C write patching; VTD emphasizes navigation APIs and XPath over a fixed VTD index with documented size ceilings for classic VTD layouts.

**XPath / XQuery / CSS.** Axes, predicates, and indexes are not novel. LOM deliberately does *not* claim originality for child/descendant axes or regex matching of text. Static per-expression context differs from LOM’s mutable conversational context.

**XML databases.** BaseX and eXist store documents with structural encodings and optional incremental index updates (e.g. BaseX `UPDINDEX`). LOM targets an in-process editable string and conversational UI/API, not a multi-document DBMS.

**Join order / bidirectional reduction.** Yannakakis’ algorithm for acyclic joins and sideways information passing (SIP) prune dangling tuples by semi-join passes [Yannakakis 1981; modern Yannakakis+/Shredded Yannakakis]. LOM’s fractal get—match a selective leaf pattern on the string, then walk parents—is *conceptually* related (reduce early, expand structurally) but is not a relational join planner and should not be marketed as “Yannakakis for XML.”

**Incremental view / index maintenance.** Parent/tag/attribute index patches after `replace` resemble incremental view maintenance; the interesting LOM claim is doing that on a string-resident document while preserving living variables and context.

### 2.1 Gap

The lines above each solve a real problem. They do not solve them together.

| | Bytes stay the document | Context survives the next call | Splice patches indexes | Selective end first | Names stay live across splices |
|--|--|--|--|--|--|
| DOM | tree, not the string | the node the caller passes | tree mutation | top-down | no |
| SAX / XMLReader | one pass | no retained selection | not an editor | no | no |
| VTD-XML | yes | context item of one XPath eval | `XMLModifier` rewrites a document; the index is not a carried selection | navigation / XPath | no |
| XPath / XQuery / CSS | not the model | one expression | not the model | axes from a context node | bindings die with the query |
| BaseX / eXist | database pages | one query, or whatever the app stores | `UPDINDEX` inside the DBMS | XPath / XQuery | query-scoped |
| Yannakakis / SIP | not a document | not a session | not a splice | semi-join reduction | not a document |
| **LOM** | yes | prior hits are the next scope | yes | fractal get | `$var` patched with the string |

Read the rows as coverage, not as a race. DOM and SAX answer different jobs (a tree, a stream). VTD-XML already keeps the source bytes and speaks XPath; it does not keep a selection alive as the scope of the next selector, and it does not define named selections that remain valid after a splice. XPath’s context item is real, and it is rebound by the expression, not by the previous answer. BaseX will maintain indexes across updates; that maintenance lives in a multi-document store, not in the string a process just edited. Yannakakis explains why a selective end is worth resolving first; it is not an XML engine.

What is missing is one in-process object where those four properties hold at once: the document is still \(C\), the last successful selection is the context of the next selector, a named selection is updated by the same splice that changes \(C\), and a selective leaf may be resolved before the ancestor walk. That is the claim. Facades, the WAL, and regex-as-a-value are how the engine is used, not additional holes in the literature.

**What we do not claim.** Regex on XML; inventing PCRE; that LOM is a drop-in XPath/XQuery engine or a multi-document DBMS; that the table above is a performance comparison.

## 3. Model

### 3.1 String-resident document

Let the document be byte string \(C[0..n)\). Opening tags are indexed as rows \((o, e, p, n_{\mathrm{end}}, \mathrm{name\_id}, \ldots)\). A match is \((o, n_{\mathrm{end}})\); materializing text is a slice of \(C\) (interned when fmem ROI allows).

**Write.** `splice(at, remove, insert)` updates \(C\). Native path: (1) *structure-preserving* edits (no `<`, no open starts in the remove span) only shift open/attr byte offsets; same-size edits `pwrite` the span and leave the sidecar valid; (2) complete-subtree removes / markup inserts scan only the insert fragment, merge open rows with remapped `parent_idx`, and mark CSR dirty for lazy rebuild on the next query; (3) irregular mid-node splices rescan **dirty pieces only** and merge into opens; (4) a length-changing or markup splice that hits a recipe tile **flattens that tile into leftover opens** so virtual geometry stays true — remaining tiles stay virtual; (5) otherwise full `lom_scan_indexes` + CSR rebuild. Length-changing edits append `(offset, remove_len, insert)` to `path.lomwal`; reload = mmap XML + apply WAL + `.lomidx`. `lom_doc_checkpoint` rewrites XML once, drops the WAL, and writes a fresh sidecar — the honest “canonical file” clock, not a 1 ms claim. PHP patches offset maps and parent/tag indexes. mmap-backed loads demote to a private buffer before growth. Open rows store `parent_idx` (int32 into `opens[]`) instead of parent byte offset.

### 3.2 Conversational context

After a successful selection \(R\), subsequent selectors may be evaluated relative to \(R\) (and stacked contexts) rather than the whole document. Pseudocode:

```
function get(selector, ignore_context=false):
  S = normalize_and_extract_regex(selector)
  if use_context and not ignore_context and context_nonempty():
    if exact_selector_cached(S): return cache[S]
    R = select_within(context_spans(), S)
    if R nonempty: remember_context(R); return R
  R = select_document(S)
  remember_context(R)
  return R
```

### 3.3 Fractal (bidirectional) selection

When the selector ends in a selective tag-value or attribute equality, prefer one document-level scan for that end, map hits to nodes via offset→open indexes, then verify ancestor chain—rather than only expanding every region. Regex values use the same shape: for a known leaf tag, a selective `/pattern/` may `preg_match_all` the document once and map match offsets to that tag’s opens (falling back to per-candidate inner-text filters when the pattern is not selective).

**Algorithm 1** (document-level regex leaf).

```
Input: document C, leaf tag T, operator ⊕, compiled pattern P
candidates ← tag_index[T]                          # |candidates| = k
if k < 256: return filter_each(candidates, ⊕, P)   # small index: per-node PCRE
M ← preg_match_all(C, P) with offsets              # one scan of |C|
if |M| = 0: return ∅
if |M| > max(k/4, 512): return filter_each(...)     # not selective enough
H ← ∅
for each match offset b in M:
  o ← rightmost open of T covering b               # binary search + end check
  if o ∉ H and inner_text(o) satisfies ⊕ on P: H ← H ∪ {o}
return H sorted by document order
```

### 3.4 Regex as value form

After any comparison operator, `/pattern/flags` is a regex value (slash is not a child axis; children use `_` / `__`). Compile once; produce a match array of `(text, offset)` portions; operators filter that array:

| Op | Keep node if match array… |
|----|---------------------------|
| `=` | covers full text span |
| `%=` | nonempty |
| `^=` / `$=` | starts / ends at text bounds |
| `~=` | whitespace- or bound-delimited |
| `!=` | empty |
| numeric | captured group or match parses and compares |

### 3.5 fmem / fcache / pieces / fstr

- **fmem:** optional L1 intern API retained; bulk ingest on construct was disabled after it proved harmful on high-cardinality docs (unique attr values) while queries use `string_blob` / name ids.
- **fcache:** memo selector→match-list (native) and regex match arrays (PHP); cleared on invalidate / reindex. Warm `get` **borrows** the cached blob (`lom_fcache_hold`); `LOM_FCACHE_VIEW=0` memcpy instead.
- **Native regex:** `liblom` compiles `/pattern/flags` with PCRE2 after comparison ops (same operator table as PHP). Patterns are extracted before `_` axis splits so underscores inside patterns stay intact. Selective document-level mapping is used when the leaf tag index is large enough.
- **fstr (fractal string):** multi-scale view of \(C\) as a tree of spans (document → root children → tag-aligned blocks). Each node stores a 256-bit byte-presence mask and a 3-gram bloom. Cold `find` / regex-with-literal-probe walks only candidate spans (`LOM_FSTR`, default on for docs ≥4 KiB). Complements fractal *selection* (selective leaf first) with fractal *content geometry*.
- **pieces:** tag-aligned boundaries (`<` only); dirty mark on splice.
- **parallel (`LOM_PARALLEL`):** default **on**, hardware-gated (≥4 CPUs + RAM for ~2× open-table). Piece-local sibling scan merges with name-only dedup.
- **tile (`LOM_TILE`):** default on for docs ≥4 MB. Census depth-1 siblings; if the tag-name sequence repeats, scan the first sibling and replay that template (skip intern). Byte-identical twins memcpy open rows and patch offsets.
- **sidecar (`LOM_SIDECAR`):** after a file construct, persist `path.lomidx` and mmap it on reload (recipe: range table, not memcpy of every open). Opens larger than `LOM_SIDECAR_MAX` (default 512 MiB) go to `path.lomopens`. Matching mtime/dev/ino skip the byte scan. In-process overlay edits keep the sidecar; WAL / `save` / checkpoint is the durability API.
- **recipe classes:** census clusters depth-1 siblings by opening tag name (not global byte-identical tiling). The dominant class (≥4 members) persists as one template + range table; other siblings are scanned into the prefix. Incompressible files (no repeated shape) stay on wholesale scan.

## 4. Complexity (expected)

| Operation | Expected cost |
|-----------|----------------|
| Construct + scan | \(O(n)\) over document bytes |
| Tag-row build | \(O(n)\) work; wall time \(\approx O(n/P)\) with \(P\) workers plus merge |
| Exact tag get via index | \(O(k)\) for \(k\) opens of that name |
| Fractal selective end | \(O(n)\) scan once + \(O(h)\) ancestor checks per hit |
| Regex per candidate text | \(O(\mathrm{PCRE}(|t|))\) per text; memoized under fcache |
| Warm identical `get` (native fcache) | \(O(1)\) borrow of cached match list (memcpy if `LOM_FCACHE_VIEW=0`) |
| Sidecar reload (recipe) | \(O(1)\) map of prefix + template + ranges; tile text via `pread` |
| Sidecar reload (wholesale) | mmap persisted open rows; no byte scan |
| Same-size `set` | \(O(|\Delta|)\) `pwrite`; sidecar stays valid |
| Growth `set` (overlay / WAL) | \(O(|\Delta|)\) journal; no full XML rewrite |
| `set` small text (no `<`, in RAM) | overlay + deferred byte bias; not \(O(n)\) memmove of the file |
| `new_` / markup splice | scan insert only + merge open rows; CSR/tag-rows rebuilt lazily on next query |
| Irregular mid-node splice | rescan dirty pieces + merge; wholesale rescan if the dirty span exceeds half the file |
| Checkpoint / `save` | \(O(n)\) rewrite of XML; measured separately |
| Parent unique (fixed) | \(O(k)\) with hash set (was \(O(k^2)\) linear scan—fixed in this draft) |

## 5. Empirical evaluation

**Hardware.** Linux 6.12, 12 CPUs, ~38 GiB RAM, NVMe. **Software.** PHP 8.5.8; `liblom` / `lomc` GCC `-O2`.

**Fixtures.** Generated by `gen_perf_fixture.php` (world/region/zone/entity). Default `perf_fixture.xml` ≈ 2.58 MB. Size suite: 1 MB, 100 MB, 1 GB. **20 GB** is measured only when RSS and `MemAvailable` pass the gate in `bench_20gb.sh` (no extrapolated timings). See §5.4.

### 5.1 Ablations (~2.58 MB, native `lomc`)

Warm **descendant** chain is the clearest fcache signal:

| Config | Construct (ms) | Descendant cold (ms) | Descendant warm (ms) |
|--------|----------------|----------------------|----------------------|
| All on | 66 | 4.8 | **0.03** |
| `LOM_FCACHE=0` | 49 | 3.8 | **3.7** |
| All off | 43 | 3.6 | **3.2** |

Indexed warm similarly collapses with fcache (≈0.00 ms vs ≈4.4 ms). fmem/pieces/parallel at this size mainly affect construct tax; ROI gates avoid paying when useless. **Takeaway:** fcache is the win for repeated identical selectors; construct can be cheaper with accelerators off on small files.

### 5.2 Size scaling (native)

| Size | Bytes | Opens | Construct (ms) | Sidecar reload | Descendant count / cold get | Descendant warm | set text (ms) | new_ (ms) |
|------|-------|-------|----------------|----------------|------------------------------|-----------------|---------------|-----------|
| ~2.58 MB | 2.58e6 | 1.1e5 | ~22 | **~2** | ~0.3 / ~0.4 | **~0.00** | ~6 | ~7 |
| 100 MB | 1.05e8 | 4.4e6 | **~0.65–0.79 s** (tile; serial ~1.73 s) | **~57** | ~15 / ~20 | **~0.01** | **~30** | **~33** |
| 1 GB | 1.07e9 | 44.8M | sidecar **~0.04** (`0.2.50`) | **~0.04** | **~0.01** count n=3.37 M | **~0.01** | **~0.8** | **~3** |
| 20 GB | 2.15e10 | 882M virt | **~2700** warm first (`0.2.50`; census ~2.3 s) | **~0.04** | **~0.02** count n=66.3 M | **~0.01** | **~1.2** | **~3** |

**RAM.** Pre-CSR 100 MB RSS ~1424 MB with ~1 GB stuck after free; CSR + exact-sized indexes → ~385 MB load / ~2 MB after free. Peak `lomc` 100 MB ~528 MB. At 1 GB, file-backed opens + no attrs + no CSR → **~180–520 MB** construct RSS (post-`new_` ~180 MB). **20 GB measured (`0.2.50-steady`):** first recipe construct peaks **~20 GiB** (XML mmap for census). A later process reload is **~0.04 ms / ~3 MB** (no XML map). Wholesale tile construct (`0.2.45`) peaked **~30 GiB**. Older file-backed write-thrash stayed **~6–10 MB**; older broad-query peak ~24.6 GiB (§5.4).

**Construct.** An earlier bulk **fmem ingest** of every interned string into a fixed 2048-bucket table made 1 GB construct ~415 s (superlinear). Queries never consulted fmem; ingest is skipped. Tag/attr aux vectors are sized by max *name* id rather than the full string table (attr values dominate `string_count`). **Tile replay** (`ver≥0.2.43-fastpath`) drops 100 MB construct from ~1.7 s serial / ~1.2 s parallel to **~0.65 s** when depth-1 siblings share a tag sequence. **Fractal construct** (`0.2.46`) persists a recipe instead of 882 M rows. **Named census** (`0.2.47`) finds depth-1 siblings with `memmem` of `<name` / `</name>` (parallel slices; no 2×-open gate). **Lazy map** (`0.2.50`) leaves the XML unmapped on sidecar reload and `pread`s one tile for `Name_N` text: 1 GB / 20 GB reload **~0.04 ms**, warm 20 GB first **~2.7 s**. **`0.3.0-general`:** irregular documents with a dominant sibling tag class take a fractal recipe (attrs no longer force wholesale); files with no repeated shape stay on wholesale scan (measured in §5.6).

**Writes.** Growth `set` uses a code overlay + deferred open-offset bias (no full mmap COW). Markup `new_` merges a fragment scan: small tails insert in-place; large tails **append** out of document order (`open_sorted_n`) to avoid multi-GB `memmove`. Complete-subtree `delete` **tombstones** opens (`LOM_OPEN_DEAD`) without compacting tag-rows.

**PHP:** slim large-doc path under 512 M; prefer `liblom` above ~100 MB.

### 5.3 Regex (PHP, ~2.58 MB)

Tagvalue regex with a known tag name uses the **indexed direct-chain** fast path (also from the overlay/`^=` branch). When the pattern is selective, a **document-level** `preg_match_all` maps hit offsets onto tag opens instead of scanning every candidate’s inner text.

| Query | After indexes | Notes |
|-------|---------------|-------|
| `name^=/Entity_1/` | **~19–28 ms** cold / **~8 ms** warm (3024 hits) | was ~480 ms via `select` |
| `note%=/note-0-0-0/` | **~5.5 ms** (1 hit) | document-level map |
| Operator suite | 20/20 | `regex_selector_test.php` |

`%=` / `!=` / `^=` use cheaper `preg_match` where possible before `preg_match_all`.

### 5.4 20 GB (measured)

Gated runs on this host (`./bench_20gb.sh` + write thrash; file-backed opens on `/var/tmp`, attrs off, CSR omitted above ~100 M opens):

| Metric | Value |
|--------|-------|
| Bytes / opens | 21 474 844 641 / **881 738 929** |
| Construct (steady lazy, `0.2.50-steady`) | warm first **~2.7 s** (census **~2.3 s** + persist ~19 ms); **26 MiB** `.lomidx`; peak **~20 GiB**. Was **~4.5 s** (`0.2.49` unmap) / **~2.2 s** (`0.2.47`) / **~40 s** (`0.2.46`) / **~292 s** (`0.2.45`) |
| Sidecar reload | **~0.04 ms** / **~3 MB** (was **~324 ms** mapping 21 GiB) |
| `count region` / `count region_zone_entity_stats` | **~0.06 ms** n=1.66 M / **~0.02 ms** n=66.3 M (was **~4.7 s** / **~32.5 s**) |
| Path select (indexed) | **~0.05 ms** (1 hit; was **137 s** pre–span-walk) |
| Exact text (`entity_meta_name=Entity_42`) | **~0.13–0.24 ms** n=1 (`0.2.50`; was **~2.0 s** / ~6.7 s) |
| Exact regex (`name=/^Entity_42$/`) | **~0.05–0.11 ms** n=1 (same tile jump; was **68.7 s**) |
| `set` / `new_` / post / `delete` | **~5 ms** (opens already faulted) / **~3 ms** / **~0 ms n=1** / **~0 ms** — file-backed thrash **~29 ms / ~10 MB** (`ver≥0.2.15-tombaux`) |
| After `lom_doc_free` | **~2 MB** |
| `lom_doc_checkpoint` dest rewrite (`0.3.2-ois`) | **35.0 s** / **21 474 844 693** bytes / peak **~31 MB** (streamed `copy_file_range` + overlay seam; dest sidecar reload **~0.04 ms** n=1.66 M `region`). 1 GB dest rewrite **1.18 s** / **6 MB**. Was not run before this draft. |

Older broad-query suite (same fixture; still useful for cold-scan cost):

| Metric | Value |
|--------|-------|
| `region` cold / warm | **47.7 s** / **32 ms** (1 657 404 hits) |
| Descendant cold / warm | **63.1 s** / **0.88 s** (66 296 160 hits) |
| `entity@kind` cold / warm | **113 s** / **0.81 s** (66 296 160; open-tag parse, no attr index) |
| `name=/^Entity_42$/` | **68.7 s** (1 hit) |
| Indexed `region[10]_zone[5]_entity[7]_stats` | **137 s** (1 hit; pre–span-walk sibling groups) |
| `name%=/Entity_1/` cold / warm | **32.5 s** / **113 ms** (11 111 111 hits) |
| Parent of descendant | **65.1 s** (66 296 160) |
| Legacy writes (`ver=0.2.6-splice`) | set ~120 s / new_ ~722 s / delete ~432 s; peak write RSS ~34 GiB |
| Broad-query peak RSS | **~24.6 GiB** |

Gate samples 1 GB construct RSS and requires ≥12 GiB `MemAvailable`. Open tables use tempfile `mmap` + page-aligned `MADV_DONTNEED`. Child axis without CSR uses **in-span** walks under each parent (not global tag-row scans). Growth `set` uses overlay + deferred byte bias; early `new_` **appends** open rows when a tail shift would exceed ~64 MB; `delete` tombstones without rewriting tag-rows. `lom_doc_save_file` streams unchanged spans with `copy_file_range` and writes only the overlay seam — it does not flatten 21 GiB in RAM. Dest-only `lom_doc_checkpoint` leaves the source sidecar/WAL alone and writes a remapped dest `.lomidx`. This host’s measured 20 GB dest rewrite is **35.0 s / ~31 MB RSS**. Growth edits persist via WAL/`pwrite`; checkpoint is the explicit compact clock, not a 1 ms claim. Recipe clustering is O(n log n) by tag hash (1 GB sample **22 ms**, was **240 s** O(n²)).

### 5.5 Corpora (regular + irregular + public XML)

Instrument: `./bench_corpus_fetch.sh` then `./bench_corpus.sh` (`native/bin/bench_corpus`, `0.3.2-ois`). Each row is a **temp copy**. `set` is one leaf; **`write_xml` is `lom_doc_checkpoint`** (a new XML file on disk). 1 GB / 20 GB tiling fixtures remain the regular special case (§5.2 / §5.4). Public files: UniProt REST, MediaWiki Special:Export, Wikimedia Commons SVG, Maven POM, W3C Atom, OSM API, NASA RSS (see `docs/RESULTS.md` for licenses).

| File | Bytes | Opens | recipe | Construct | Count (sel) | Write XML | Reload |
|------|-------|-------|--------|-----------|-------------|-----------|--------|
| `test.xml` | 1.3 KB | 66 | 0 | 0.11 ms | 0.014 ms n=5 `person` | **0.06 ms** | 0.06 ms |
| UniProt P04637 | 844 KB | 17 K | **1** | **3.9 ms** | 0.014 ms n=1 `entry` | **0.57 ms** | 4.1 ms |
| UniProt human×50 | 2.08 MB | 43 K | 0 | **10 ms** | 0.004 ms n=50 `entry` | **0.84 ms** | 0.14 ms |
| Wikipedia export | 1.99 MB | 4.4 K | 0 | **3.5 ms** | 0.005 ms n=254 `page` | **0.86 ms** | 2.6 ms |
| OSM London bbox | 2.23 MB | 44 K | 0 | **12 ms** | 0.007 ms n=1226 `node` | **1.0 ms** | 0.09 ms |
| NASA RSS | 140 KB | 119 | 0 | 0.23 ms | 0.002 ms n=10 `item` | **0.10 ms** | 0.20 ms |

**Reading.** Recipe leftover gaps used to reseed the intern table once per tile (UniProt P53 / generated mix ~80 ms). Shared intern + skip tagless gaps: P53 **3.9 ms**, mix **0.89 ms**. Wholesale UniProt×50 is **10 ms** construct / 0.84 ms rewrite (parallel ≥1 MiB). OSM construct **12 ms**, `set` of a `node` **0.03 ms**. `count` stays ~0.01 ms on these files. `write_xml` is a real file, not only a WAL. A large inner-text shrink used to overflow the overlay buffer; that is fixed. Broad `get` still materializes offset pairs — prefer `count` / `get_ois` when the caller only needs cardinality or indices.

## 6. Threats to validity

Single machine. The 20 GB column is still the regular region/zone/entity fixture. Irregular evidence now includes UniProt, Wikipedia, OSM, SVG, Atom, RSS, and a Maven POM (MB-class, not GB-class dumps). The 20 GB checkpoint is a dest rewrite of the tiled fixture (overlay streamed; source file left intact). PHP no longer rejects files whose markup-in-text unbalances a raw `<`/`>` count; native scan is authoritative, and PHP indexes stay lazy on those files.

## 7. Availability and reproducibility

Apache License 2.0. Source: repository artifacts include `O.php`, `native/liblom`, `lomc`, `lomd`, language connectors (Python/C#/Go/Java), `docs/USING.md`, `bench_corpus_fetch.sh`, `bench_corpus.sh`, `bench_vs_tools.php`, `bench_ablation.sh`, `bench_sizes.sh`, `bench_20gb.sh`, `gen_perf_fixture.php`, and `docs/RESULTS.md`. SPDX headers on `O.php` and `lom.h`. Timing tables in this draft are reproducible with the bench scripts on comparable hardware; **20 GB figures are included only when actually measured** by `bench_20gb.sh`; public XML rows come from `bench_corpus_fetch.sh` + `bench_corpus.sh` (files stay in `.bench_out/corpus/`, not the git tree).

## 8. Conclusion

The gap in §2.1 is the publishable claim: conversational context, string-resident incremental mutation, fractal selection, and living variables, together, on one in-process string. Regex is an operator value form; fmem/fcache/pieces are measurable accelerators. Empirically, **fcache borrow** takes warm descendant to ~0.01 ms (100 MB) / ~6 ms (1 GB); **tile census** plus default-on parallel cut first construct on the regular fixture; **tag-class recipes** help real files (UniProt P53 **~3.9 ms**, generated mix **~0.89 ms**; leftover intern is shared across tile gaps); wholesale UniProt×50 is **~10 ms**; **checkpoint** rewrites those XML files in **0.4–2.4 ms** and the 20 GB tiled fixture in **35.0 s / ~31 MB**; **sidecar** skips the byte scan on recipe reload; **count / open-index APIs** avoid allocating offset pairs on huge hits; **CSR + packed opens** cut RAM sharply; **overlay + WAL/`pwrite` + tombstone delete** keep tiny edits durable without rewriting the XML; PHP remains the full-language reference for smaller documents. XPath/CSS facades are compilers, not a second engine.

## 9. Competing interests / conflict of interest

The author declares that there are no conflicts of interest. No competing financial interests or personal relationships influenced the work reported in this paper. No resources, funding, facilities, data, or personnel of any other organization were used in the creation of this tool or in the preparation of this manuscript; the work was developed independently on personally available hardware and software.

## References (selected)

1. J. Zhang et al., VTD-XML / non-extractive parsing — https://www.xml.com/pub/a/2004/05/19/parsing.html ; https://vtd-xml.sourceforge.io/
2. M. Yannakakis, “Algorithms for Acyclic Database Schemes,” VLDB 1981.
3. Q. Wang et al., “Yannakakis+: Practical Acyclic Query Evaluation…,” SIGMOD 2025 / https://doi.org/10.1145/3725423
4. BaseX documentation — indexes and incremental updates — https://docs.basex.org/
5. W3C XPath / XQuery recommendations.
6. LOM repository artifacts: `PERF_NOTES.md`, `regex_selector_test.php`, `bench_*.sh`.
