# LOM

<img src="icons/lom-logo.svg" width="72" height="72" alt="LOM logo: a rainbow living field with a lock-on reticle"/>

Living Object Model — hierarchical XML document engine (PHP `O.php`) with a native C core and a Power Apps / Power BI HTTP service. The mark is the brief: **find the precise in the mutable**.

## Live demo

Edit the XML (same contents as `test.xml`), edit the LOM selector, and watch matches update.

```bash
php -S localhost:8765
# open http://localhost:8765/demo/
```

If this repo is already served over Apache, open [`demo/`](demo/).

## Quick paths

| Path | What |
|------|------|
| [`demo/`](demo/) | Live query playground (XML + selector → matches) |
| `O.php` | Original PHP API (unchanged for existing programs) |
| `native/` | C library (`liblom`), CLI (`lomc`), OData daemon (`lomd`) |
| `powerapps/` | Entity config, OpenAPI custom connector, setup guide |

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
