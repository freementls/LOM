# LOM

<img src="icons/lom-logo.svg" width="72" height="72" alt="LOM logo: a rainbow living field with a lock-on reticle"/>

Living Object Model — hierarchical XML document engine (PHP `O.php`) with a native C core and a Power Apps / Power BI HTTP service. The mark is the brief: **find the precise in the mutable**.

**License:** [Apache License 2.0](LICENSE).

**Start here:** [`docs/USING.md`](docs/USING.md) — selector primer, when LOM wins (live document, selective poke), when to stream/XMLWriter, how to compact.

Selectors use `_` / `__` for child / descendant. Comparison values may be PCRE literals: `hobby%=/s.*/i` (see `regex_selector_test.php`). Multi-language connectors: [`connectors/`](connectors/).

Large-document scans optionally use sibling [fractal_substring](https://github.com/freementls/fractal_substring) (`libfss`; `LOM_FSS_SCAN=0` to disable).

**Research draft:** [`docs/paper/lom_living_object_model.md`](docs/paper/lom_living_object_model.md) (includes [§9 Competing interests](docs/paper/lom_living_object_model.md#9-competing-interests--conflict-of-interest)) · **Numbers:** [`docs/RESULTS.md`](docs/RESULTS.md) (native 100 MB construct ~0.65 s; 20 GB recipe construct ~2.7 s / reload ~0.04 ms; irregular mix recipe construct ~1.3 ms; connectors under [`connectors/`](connectors/)).

## Live demo

**[https://freement.cloud/LOM/demo/](https://freement.cloud/LOM/demo/)** — edit the XML (same contents as `test.xml`), edit the LOM selector, and watch matches update. Writes (`set` / `new_` / `delete`) apply only when you press the button.

**[https://freement.cloud/LOM/lab/](https://freement.cloud/LOM/lab/)** — app lab: PHP / JS / `$XML*` on the left, running app on the right. Writes go through AJAP (AJAX to PHP) and live-update the nowdocs.

Source for those pages: [`demo/`](demo/), [`lab/`](lab/). Locally: `php -S localhost:8765` then http://localhost:8765/demo/ or http://localhost:8765/lab/. A localhost-only 1 GB / 20 GB playground lives at [`demo/large/`](demo/large/) (Apache `Require local`; not linked from the public demo).

## Quick paths

| Path | What |
|------|------|
| [Live demo](https://freement.cloud/LOM/demo/) | Query playground on freement.cloud (XML + selector → matches) |
| [App lab](https://freement.cloud/LOM/lab/) | Two-pane app workshop (code + rendered app, `$XML*` live data) |
| `O.php` | Original PHP API (unchanged for existing programs) |
| `native/` | C library (`liblom`), CLI (`lomc`), OData daemon (`lomd`) |
| `powerapps/` | Entity config, OpenAPI custom connector, setup guide |
| `connectors/` | C#, Python, TypeScript, Java, Go clients (ABI + OData) |
| [`docs/USING.md`](docs/USING.md) | Selector primer, WAL/compact, when not to use LOM |
| `docs/paper/` | Working research paper draft |
| `docs/RESULTS.md` | Benchmark snapshot |
| `LICENSE` | Apache-2.0 |

## Power Apps

1. Build: `make -C native`
2. Run:

```bash
cp test.xml powerapps/demo.xml   # or your document
./native/bin/lomd \
  --file powerapps/demo.xml \
  --entities powerapps/entities.conf \
  --port 8080 --api-key 'your-secret'
```

3. Import `powerapps/openapi.json` as a **custom connector**.
4. Call **`ListPeople`** with `$filter` / `$top` / `$skip` so filtering stays on the server — this avoids Power Apps’ 500/2000 client row limit.

Details: [powerapps/README.md](powerapps/README.md).

## PHP

```bash
php test.php
# optional C accelerator:
make -C native php-ext
php -d extension=$PWD/native/php_ext/modules/lom_accel.so …
```

Set `LOM_ACCEL=0` to force pure PHP. File-backed docs default to `liblom` when the accelerator is loaded (`LOM_NATIVE=0` to force PHP). `O::xpath()` / `O::css()` compile a documented subset to LOM selectors.
