# Living Object Model: String-Resident XML with Conversational Context and Fractal Selection

**Working draft** — empirical results recorded on Manjaro Linux, AMD/Intel x86_64, 12 cores, ~38 GiB RAM, PHP 8.5.8, GCC 16.1, Apache-2.0 LOM tree.

## Abstract

Living Object Model (LOM) is a string-resident XML engine: the document remains contiguous source text; query results are `(substring, offset)` pairs; writes splice the string and patch indexes instead of rebuilding a DOM. This paper states what is novel, what is not, and reports scaling and ablation measurements. Novelty is the *combination* of (1) conversational / dynamic context across queries, (2) incremental string-resident mutation, (3) bidirectional “fractal” selection (selective end first), and (4) living named selections—not regex-on-XML per se. Accelerators adapted from fractal-zip work—**fmem** (content intern with ROI gate), **fcache** (memo), and tag-aligned **pieces** with optional parallel index workers—are evaluated via ablations. Head-to-head bake-offs against DOM/XPath are reported only as personal baselines; the paper’s main tables are LOM scaling and mechanism ablations.

## 1. Introduction

XML tooling is dominated by extractive trees (DOM), streaming (SAX/XMLReader), and non-extractive token tables (VTD-XML). Query languages (XPath/XQuery, CSS) evaluate each expression against a static context item. Interactive editing and conversational follow-ups—“who was that person again?”—do not map cleanly onto static expressions.

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

**What we do not claim.** Regex on XML; inventing PCRE; that LOM is universally faster than DOM+XPath on identical tasks.

## 3. Model

### 3.1 String-resident document

Let the document be byte string \(C[0..n)\). Opening tags are indexed as rows \((o, e, p, n_{\mathrm{end}}, \mathrm{name\_id}, \ldots)\). A match is \((o, n_{\mathrm{end}})\); materializing text is a slice of \(C\) (interned when fmem ROI allows).

**Write.** `splice(at, remove, insert)` updates \(C\). Native path: (1) *structure-preserving* edits (no `<`, no open starts in the remove span) only shift open/attr byte offsets; (2) complete-subtree removes / markup inserts scan only the insert fragment, merge open rows with remapped `parent_idx`, and mark CSR dirty for lazy rebuild on the next query; (3) otherwise full `lom_scan_indexes` + CSR rebuild. PHP patches offset maps and parent/tag indexes. mmap-backed loads demote to a private buffer before growth. Open rows store `parent_idx` (int32 into `opens[]`) instead of parent byte offset.

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
- **fcache:** memo selector→match-list (native) and regex match arrays (PHP); cleared on invalidate / reindex.
- **Native regex:** `liblom` compiles `/pattern/flags` with PCRE2 after comparison ops (same operator table as PHP). Patterns are extracted before `_` axis splits so underscores inside patterns stay intact. Selective document-level mapping is used when the leaf tag index is large enough.
- **fstr (fractal string):** multi-scale view of \(C\) as a tree of spans (document → root children → tag-aligned blocks). Each node stores a 256-bit byte-presence mask and a 3-gram bloom. Cold `find` / regex-with-literal-probe walks only candidate spans (`LOM_FSTR`, default on for docs ≥4 KiB). Complements fractal *selection* (selective leaf first) with fractal *content geometry*.
- **pieces:** tag-aligned boundaries (`<` only); dirty mark on splice.
- **parallel (`LOM_PARALLEL`):** default off. Piece-local sibling scan merges with name-only dedup (attr values appended). Correct vs serial; ~parity at 100 MB on this host; still slower at 1 GB — auto-serial outside 4 MB–256 MB. Shared-mutex intern and full-string rehash merges were worse. Not a claimed win.

## 4. Complexity (expected)

| Operation | Expected cost |
|-----------|----------------|
| Construct + scan | \(O(n)\) over document bytes |
| Tag-row build | \(O(n)\) work; wall time \(\approx O(n/P)\) with \(P\) workers plus merge |
| Exact tag get via index | \(O(k)\) for \(k\) opens of that name |
| Fractal selective end | \(O(n)\) scan once + \(O(h)\) ancestor checks per hit |
| Regex per candidate text | \(O(\mathrm{PCRE}(|t|))\) per text; memoized under fcache |
| Warm identical `get` (native fcache) | \(O(k)\) copy of cached matches |
| `set` small text (no `<`) | \(O(n)\) memmove + \(O(\#opens)\) offset shift; no rescan |
| `new_` / markup splice | scan insert only + merge open rows; CSR/tag-rows rebuilt lazily on next query |
| `set` / splice that cuts mid-node | \(O(n)\) full rescan fallback |
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

| Size | Bytes | Opens | Construct (ms) | Descendant cold (ms) | Descendant warm (ms) | set text (ms) | new_ (ms) |
|------|-------|-------|----------------|----------------------|----------------------|---------------|-----------|
| ~2.58 MB | 2.58e6 | 1.1e5 | ~30 | ~3 | ~0.03 | ~6 | ~7 |
| 100 MB | 1.05e8 | 4.4e6 | **~1100** | ~180 | ~3 | ~296 | ~387 |
| 1 GB | 1.07e9 | 44.8M | **~10500** | ~1650 | **~55** | ~2548 | ~3439 |
| 20 GB | 2.15e10 | 882M | **~740000** | **~63100** | **~880** | **~137000–212000** | **~973000** |

**RAM.** Pre-CSR 100 MB RSS ~1424 MB with ~1 GB stuck after free; CSR + exact-sized indexes → ~385 MB load / ~2 MB after free. Peak `lomc` 100 MB ~528 MB. At 1 GB, heap opens+attrs ~3.9 GB load; file-backed opens + no attrs + no CSR → **~180 MB** construct RSS. **20 GB measured:** construct leaves ~6 MB resident; query peak ~24.6 GiB; write peak ~34.3 GiB; free → ~2 MB (§5.4).

**Construct.** An earlier bulk **fmem ingest** of every interned string into a fixed 2048-bucket table made 1 GB construct ~415 s (superlinear). Queries never consulted fmem; ingest is skipped. Tag/attr aux vectors are sized by max *name* id rather than the full string table (attr values dominate `string_count`). Scan alone was already ~6 s on 1 GB; full construct is now ~10.5 s.

**Writes.** Structure-preserving `set` shifts offsets only. Markup `new_` merges open rows from a fragment scan and defers CSR rebuild to the next query.

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

Gated runs on this host (`./bench_20gb.sh` + write continuation; file-backed opens on `/var/tmp`, attrs off, CSR omitted above ~100 M opens):

| Metric | Value |
|--------|-------|
| Bytes / opens | 21 474 844 641 / **881 738 929** |
| Construct | **~742–734 s**; RSS after index **~6 MB** |
| `region` cold / warm | **47.7 s** / **32 ms** (1 657 404 hits) |
| Descendant cold / warm | **63.1 s** / **0.88 s** (66 296 160 hits) |
| `entity@kind` cold / warm | **113 s** / **0.81 s** (66 296 160; open-tag parse, no attr index) |
| `name=/^Entity_42$/` | **68.7 s** (1 hit) |
| Indexed `region[10]_zone[5]_entity[7]_stats` | **137 s** (1 hit; no-CSR sibling groups) |
| `name%=/Entity_1/` cold / warm | **32.5 s** / **113 ms** (11 111 111 hits) |
| Parent of descendant | **65.1 s** (66 296 160) |
| `set` small text | **137–212 s** (st=0; promotes mmap→heap + memmove) |
| `new_` nested insert | **973 s** (st=0) |
| Post-write read / `delete` / `validate` | **247 s** (n=1) / **287 s** / **89 s** (ok) |
| Peak RSS | **~24.6 GiB** (query pass); **~34.3 GiB** (write pass) |
| After `lom_doc_free` | **~2 MB** |

Gate samples 1 GB construct RSS and requires ≥12 GiB `MemAvailable`. Open tables use tempfile `mmap` + `MADV_DONTNEED`. Child axis without CSR uses tag-row sibling groups / per-parent `[n]`. Attr filters without an attr index parse open-tag bytes. Full 20 GB file rewrite (`LOM_20GB_SAVE=1`) was not run.

### 5.5 Personal bake-off (not main table)

Same file, count all `name` nodes: LOM get ~451 ms vs DOM XPath ~19 ms vs XMLReader ~86 ms. LOM set note ~584 ms vs DOM set+`saveXML` ~119 ms. Different models (context, living vars, string splice); do **not** headline as “N× faster than DOM.”

## 6. Threats to validity

Single machine; synthetic fixture (regular region/zone/entity); native selector subset ≠ full PHP LOM; mid-node splices still full-reindex; PHP depth maps blow memory on large files without slim/liblom paths; bake-offs are unfair as primary evidence.

## 7. Availability and reproducibility

Apache License 2.0. Source: repository artifacts include `O.php`, `native/liblom`, `lomc`, `lomd`, language connectors (Python/C#/Go/Java), `bench_ablation.sh`, `bench_sizes.sh`, `bench_20gb.sh`, `gen_perf_fixture.php`, and `docs/RESULTS.md`. SPDX headers on `O.php` and `lom.h`. Timing tables in this draft are reproducible with the bench scripts on comparable hardware; **20 GB figures are included only when actually measured** by `bench_20gb.sh`.

## 8. Conclusion

LOM’s publishable core is conversational context + string-resident incremental mutation + fractal selection + living variables, with regex as an operator value form and fmem/fcache/pieces as measurable accelerators. Empirically, **fcache** yields warm-query wins; **CSR + packed opens** cut RAM sharply; **skipping unused fmem bulk ingest** made 1 GB construct practical (~10 s vs ~7 min); **incremental splice** keeps writes usable at GB scale; PHP remains the full-language reference for smaller documents.

## References (selected)

1. J. Zhang et al., VTD-XML / non-extractive parsing — https://www.xml.com/pub/a/2004/05/19/parsing.html ; https://vtd-xml.sourceforge.io/
2. M. Yannakakis, “Algorithms for Acyclic Database Schemes,” VLDB 1981.
3. Q. Wang et al., “Yannakakis+: Practical Acyclic Query Evaluation…,” SIGMOD 2025 / https://doi.org/10.1145/3725423
4. BaseX documentation — indexes and incremental updates — https://docs.basex.org/
5. W3C XPath / XQuery recommendations.
6. LOM repository artifacts: `PERF_NOTES.md`, `regex_selector_test.php`, `bench_*.sh`.
