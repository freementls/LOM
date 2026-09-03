# LOM native core (`liblom` + `lom_accel` + `lomc` + `lomd`)

1. **`O.php`** stays the public PHP API.
2. **`liblom`** — C document engine (get/set/new_/indexes).
3. **`lomd`** — persistent OData/HTTP service for Power Apps / Power BI.

## Build

```bash
make -C native
```

Native child selectors use **`_`** (`region_zone_entity`) for direct children and **`__`** for descendants. Tag names containing `_` must be encoded. `/` is reserved for regex values in the PHP API.

## Accelerators (fmem / fcache / pieces)

`lom_doc_create_file` prefers **mmap**. Tag-row index build can use **parallel workers**. Env toggles (default on):

| Variable | Effect |
|---|---|
| `LOM_FMEM=0` | Disable string intern table |
| `LOM_FCACHE=0` | Disable selector result memo |
| `LOM_PIECES=0` | Disable tag-aligned piece split |
| `LOM_PARALLEL=0` | Disable pthread tag-row workers |

Ablations: `../bench_ablation.sh [fixture]`.

## Power Apps

See [`powerapps/README.md`](../powerapps/README.md). Short version:

```bash
./native/bin/lomd \
  --file powerapps/demo.xml \
  --entities powerapps/entities.conf \
  --port 8080 --api-key 'dev-secret'
```

Import `powerapps/openapi.json` as a custom connector. Call **ListPeople** with `$filter`/`$top` so filtering stays on the server — that is how you avoid the Power Apps 500/2000 client row limit.
