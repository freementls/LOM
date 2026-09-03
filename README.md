# LOM

<img src="icons/lom-logo.svg" width="72" height="72" alt="LOM logo: a rainbow living field with a lock-on reticle"/>

Living Object Model — hierarchical XML document engine (PHP `O.php`) with a native C core and a Power Apps / Power BI HTTP service. The mark is the brief: **find the precise in the mutable**.

**License:** [Apache License 2.0](LICENSE).

Selectors use `_` / `__` for child / descendant. Comparison values may be PCRE literals: `hobby%=/s.*/i` (see `regex_selector_test.php`). Multi-language connectors: [`connectors/`](connectors/).

## Live demo

**[https://freement.cloud/LOM/demo/](https://freement.cloud/LOM/demo/)** — edit the XML (same contents as `test.xml`), edit the LOM selector, and watch matches update. Writes (`set` / `new_` / `delete`) apply only when you press the button.

Source for that page: [`demo/`](demo/). Locally: `php -S localhost:8765` then http://localhost:8765/demo/.

## Quick paths

| Path | What |
|------|------|
| [Live demo](https://freement.cloud/LOM/demo/) | Query playground on freement.cloud (XML + selector → matches) |
| `O.php` | Original PHP API (unchanged for existing programs) |
| `native/` | C library (`liblom`), CLI (`lomc`), OData daemon (`lomd`) |
| `powerapps/` | Entity config, OpenAPI custom connector, setup guide |
| `connectors/` | C#, Python, TypeScript, Java, Go clients (ABI + OData) |
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

Set `LOM_ACCEL=0` to force pure PHP. Set `LOM_NATIVE=1` to route simple get/set/new_ through `lom_doc`.
