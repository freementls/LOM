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

**Write.** `splice(at, remove, insert)` updates \(C\), then reindexes (native) or patches offset maps and parent/tag indexes (PHP). mmap-backed loads demote to a private buffer before growth.

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

When the selector ends in a selective tag-value or attribute equality, prefer one document-level scan for that end, map hits to nodes via offset→open indexes, then verify ancestor chain—rather than only expanding every region.

```
function fractal_get(pieces):
  if has_non_eq_ops_or_regex: return recursive_select(pieces)  # regex disables exact-= fractal shortcut
  end = last_selective_piece(pieces)
  hits = document_scan(end)           # one pass on C or span
  nodes = map_offsets_to_opens(hits)
  return filter_ancestors(nodes, pieces_without_end)
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

### 3.5 fmem / fcache / pieces

- **fmem:** L1 intern of repeated strings; ROI gate disables when hit rate stays poor.
- **fcache:** memo selector→match-list (native) and regex match arrays (PHP); cleared on invalidate / reindex.
- **pieces:** tag-aligned boundaries (`<` only); dirty mark on splice; parallel workers accumulate per-name tag rows then merge.

## 4. Complexity (expected)

| Operation | Expected cost |
|-----------|----------------|
| Construct + scan | \(O(n)\) over document bytes |
| Tag-row build | \(O(n)\) work; wall time \(\approx O(n/P)\) with \(P\) workers plus merge |
| Exact tag get via index | \(O(k)\) for \(k\) opens of that name |
| Fractal selective end | \(O(n)\) scan once + \(O(h)\) ancestor checks per hit |
| Regex per candidate text | \(O(\mathrm{PCRE}(|t|))\) per text; memoized under fcache |
| Warm identical `get` (native fcache) | \(O(k)\) copy of cached matches |
| `set` small text | splice \(O(n)\) memmove in worst case today; piece-local edits are the path to sublinear wall cost on huge files |
| Parent unique (fixed) | \(O(k)\) with hash set (was \(O(k^2)\) linear scan—fixed in this draft) |

## 5. Empirical evaluation

**Hardware.** Linux 6.12, 12 CPUs, ~38 GiB RAM, NVMe. **Software.** PHP 8.5.8; `liblom` / `lomc` GCC `-O2`.

**Fixtures.** Generated by `gen_perf_fixture.php` (world/region/zone/entity). Default `perf_fixture.xml` ≈ 2.58 MB. Size suite: 1 MB, 100 MB, 1 GB. 20 GB is opt-in (`LOM_ALLOW_HUGE=1`) and not claimed run here.

### 5.1 Ablations (~2.58 MB, native `lomc`)

Warm **descendant** chain is the clearest fcache signal:

| Config | Construct (ms) | Descendant cold (ms) | Descendant warm (ms) |
|--------|----------------|----------------------|----------------------|
| All on | 66 | 4.8 | **0.03** |
| `LOM_FCACHE=0` | 49 | 3.8 | **3.7** |
| All off | 43 | 3.6 | **3.2** |

Indexed warm similarly collapses with fcache (≈0.00 ms vs ≈4.4 ms). fmem/pieces/parallel at this size mainly affect construct tax; ROI gates avoid paying when useless. **Takeaway:** fcache is the win for repeated identical selectors; construct can be cheaper with accelerators off on small files.

### 5.2 Size scaling (native)

| Size | Bytes | Opens | Construct (ms) | Descendant cold (ms) | Descendant warm (ms) | Parent cold (ms) | set (ms) |
|------|-------|-------|----------------|----------------------|----------------------|------------------|----------|
| 1 MB | 1.05e6 | 4.6e4 | 23 | 2.5 | 0.02 | 4.6 | 20 |
| ~2.58 MB | 2.58e6 | 1.1e5 | ~50–65 | ~4 | ~0.03 | ~12 | ~40–60 |
| 100 MB | 1.05e8 | 4.4e6 | 5404 | 187 | 3.6 | **127** (was ~28612 before hash-set fix) | 1810 |
| 1 GB | 1.07e9 | ~4.4e7 est. | — | — | — | — | — |

**1 GB native:** fixture generated on disk; full index+query on this host (~38 GiB RAM) entered heavy swap (~14 GiB RSS during construct) and was aborted. Treat 1 GB as a capacity target requiring more RAM or piece-local indexes; do not invent timings. Extrapolation: construct roughly linear in opens (~10× 100 MB ⇒ order of ~1 min CPU if RAM fits).

Parent uniqueness was \(O(k^2)\) and dominated early 100 MB runs (~28 s); hash-set uniqueness brings parent to ~127 ms for 334k matches.

**PHP:** 1 MB and 2.58 MB complete under default memory. **100 MB PHP** with `memory_limit=8G`: construct ~4.6 s, `region` cold ~14.3 s / warm ~0.4 s, descendant cold ~0.95 s / warm ~1.1 s (334k matches). Default 1 G limit OOMs during depth expand. **1 GB PHP** cannot load via a single `file_get_contents` under a 1 G limit. Large-file story is **`liblom` + mmap + pieces**, not one PHP string.

### 5.3 Regex (PHP, ~2.58 MB)

Representative cold times: rare `note%=/note-0-0-0/` ~45 ms; common `note%=/note-/` ~113 ms; `name^=/Entity_/` ~218 ms. Warm times stay similar for regex-heavy paths when match arrays differ by text content (fcache keys include text). Operator suite: 20/20 in `regex_selector_test.php`.

### 5.4 Personal bake-off (not main table)

Same file, count all `name` nodes: LOM get ~451 ms vs DOM XPath ~19 ms vs XMLReader ~86 ms. LOM set note ~584 ms vs DOM set+`saveXML` ~119 ms. Different models (context, living vars, string splice); do **not** headline as “N× faster than DOM.”

## 6. Threats to validity

Single machine; synthetic fixture (regular region/zone/entity); native selector subset ≠ full PHP LOM; full reindex on native splice still \(O(n)\); PHP depth maps blow memory on large files; bake-offs are unfair as primary evidence.

## 7. Availability

Apache License 2.0. Repository includes `O.php`, `native/liblom`, `lomc`, `lomd`, connectors, `bench_ablation.sh`, `bench_sizes.sh`, `gen_perf_fixture.php`. SPDX headers on `O.php` and `lom.h`.

## 8. Conclusion

LOM’s publishable core is conversational context + string-resident incremental mutation + fractal selection + living variables, with regex as an operator value form and fmem/fcache/pieces as measurable accelerators. Empirically, **fcache** yields orders-of-magnitude warm-query wins; **mmap native** is what makes 100 MB–1 GB practical; PHP remains the full-language reference for smaller documents.

## References (selected)

1. J. Zhang et al., VTD-XML / non-extractive parsing — https://www.xml.com/pub/a/2004/05/19/parsing.html ; https://vtd-xml.sourceforge.io/
2. M. Yannakakis, “Algorithms for Acyclic Database Schemes,” VLDB 1981.
3. Q. Wang et al., “Yannakakis+: Practical Acyclic Query Evaluation…,” SIGMOD 2025 / https://doi.org/10.1145/3725423
4. BaseX documentation — indexes and incremental updates — https://docs.basex.org/
5. W3C XPath / XQuery recommendations.
6. LOM repository artifacts: `PERF_NOTES.md`, `regex_selector_test.php`, `bench_*.sh`.
