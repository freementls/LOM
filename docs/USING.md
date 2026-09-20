# Using LOM

LOM is an in-process string-resident XML engine. The document stays bytes. Internally, hits are open indices (`ois`, 4 B each). Public `get` and PHP `get_tagged` materialize offset pairs at the API edge. Selectors are the native language. XPath and CSS are optional facades that compile down to LOM.

## When LOM is the right tool

- You already have a document and want to **poke it**: selective `get` / `count`, same-size `set`, insert, delete.
- The file is large (MB–GB) and you want **reload** and **incremental mutation** without rebuilding a DOM.
- You want conversational context (`.child` after a prior `get`) or living named selections (`$var`).

## When it is not

- **Creating** a new multi-GB file from scratch — use a streaming writer (`XMLWriter`, a SAX pipeline). Then open the result in LOM if you need to live-edit it.
- A **multi-document DBMS** — BaseX / eXist stay in that lane. LOM is one process + an optional daemon (`lomd`).
- Files with **no repeated shape**. Construct falls back to wholesale scan. That is expected; general-purpose means degrading, not pretending every file is tiled.

## Selector primer

| Syntax | Meaning |
|--------|---------|
| `item` | every `item` element |
| `item_name` | child axis (`_`) |
| `item__value` | descendant axis (`__`) |
| `name'item` | parent axis (`'`); `name'` is the parent of each `name` |
| `name"region` | ancestor axis (`"`) |
| `item[1]` | 0-based same-name sibling |
| `item[$]` | last same-name sibling under each parent |
| `item@id` / `item@id=i1` | attribute present / equals |
| `name=Alpha` / `value>15` | inner text, including numeric compare |
| `name%=/al/i` | regex after a comparison op (PCRE2) |
| `item\|raw` / `name&name` | union / intersection |
| `.name` | last successful `get` as context |
| `$items` | living variable (`lom_doc_var_set`) |
| `#item` | sugar for `count(item)` — a number, not a match list |

Tag names that contain `_`, `/`, `'`, or `"` must be encoded (`{underscore}`, `{forwardslash}`, `{apostrophe}`, `{quote}`). `#underscore#` still reads. `/` after an operator starts a regex literal, not a child axis.

`count($sel)`, `sum($sel)`, and `average($sel)` each return one number. `get("#item")` and `get("count(item)")` take that path; they do not return matches. Empty `average` does not divide by zero.

## Facades (optional)

```c
char sel[256];
lom_xpath_to_lom("//item[@id='i1']", sel, sizeof sel);  /* item@id=i1 */
lom_xpath_to_lom("//item/..", sel, sizeof sel);         /* item' */
lom_xpath_to_lom("count(//item)", sel, sizeof sel);     /* #item */
lom_css_to_lom("item:nth-child(2)", sel, sizeof sel);   /* item[1] */
lom_css_to_lom("item:last-of-type", sel, sizeof sel);   /* item[$] */
```

PHP: `$o->xpath('//item[@id="i1"]')`, `$o->css('item[id=i1]')`, `$o->count('item')`, `$o->get_ois('item')`, `$o->sum('value')`, `$o->average('value')`. `..` / `parent::` compile to `'`; `ancestor::tag` to `"tag"`; `x:html` stays `x:html`. Unsupported axes, `position()`, nested functions, and `:last-child` return an error — write LOM, or stay in the documented subset (`native/tests/xpath_subset.txt`).

## Durable writes

Same-size text (`insert_len == remove_len`) is `pwrite`d at the open offset; the sidecar stays valid.

Length-changing edits append to `path.lomwal` (`lom_doc_wal_persist`). Reload = mmap XML + apply WAL + `.lomidx`. This is how a multi-GB file survives process restart **without** a full rewrite.

`lom_doc_checkpoint(doc, path)` rewrites the XML **once**, drops the WAL, and writes a fresh sidecar. That is the honest “I need one canonical file” clock — measure it; do not claim it is 1 ms.

`lomd` persists mutations via WAL/`pwrite`, not a full `save` per POST/PATCH/DELETE.

## Compact / save

| API | What it does |
|-----|----------------|
| `lom_doc_wal_persist` | journal overlay; cheap |
| `lom_doc_save_file` | stream to dest (`copy_file_range` + overlay seam; no 21 GiB flatten) |
| `lom_doc_checkpoint` | save; in-place also drops WAL + refresh sidecar |

Prefer `count` / `get_ois` for broad cardinality and for any internal walk. `get` of a high-hit tag materializes offset pairs — that is a different product from an XPath node-set. PHP `$o->get_ois($sel)` and `$o->count($sel)` are the node-set analogues when `lom_accel` is loaded.

A length-changing or markup edit inside a recipe tile flattens that tile into leftover opens. Other tiles stay virtual. `item[n]` still means the n-th `item` in document order.

Literal `<` / `>` in text does not reject construct. Native scan is the parser; PHP’s old angle-count check is a flag, not a fatal.

## Quick start

```bash
make -C native
./native/bin/lomc test.xml --op count --selector item
# daemon (one file, or --root DIR with POST /lom/query {"file":"a.xml"})
./native/bin/lomd --file test.xml --entities powerapps/entities.conf --port 8080 --api-key dev
# ./native/bin/lomd --root ./data --entities powerapps/entities.conf --port 8080 --api-key dev
curl -s -H 'X-Api-Key: dev' -d '{"selector":"item"}' http://127.0.0.1:8080/lom/query
```

Python:

```python
from lom import LomNative
with LomNative("test.xml") as d:
    print(d.count("person"), d.get_ois("person")[:2], d.get("person")[:2])
    d.set("name", "Ada")
    d.wal_persist()
```

Corpus clocks (regular + irregular + public XML): `./bench_corpus_fetch.sh` then `./bench_corpus.sh`. That fetch writes UniProt / Wikipedia / SVG / OSM / RSS / Maven samples into `.bench_out/corpus/` (gitignored) and the bench **rewrites** a temp copy (`lom_doc_checkpoint`). 20 GB tiling fixture: `./bench_20gb.sh`. Lab-only compares (not paper tables): `php bench_vs_tools.php` (DOM/XPath/XMLReader), `./bench_vs_basex.sh` (BaseX; fetches the standalone zip into `.bench_out/`).
