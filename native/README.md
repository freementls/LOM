# LOM native core (`liblom` + `lom_accel` + `lomc` + `lomd`)

1. **`O.php`** stays the public PHP API.
2. **`liblom`** — C document engine (get/set/new_/indexes).
3. **`lomd`** — persistent OData/HTTP service for Power Apps / Power BI.

## Build

```bash
make -C native
```

Native child selectors use **`/`** (`region/zone/entity`) so `_` is allowed in tag names (`big_container`).

## Power Apps

See [`powerapps/README.md`](../powerapps/README.md). Short version:

```bash
./native/bin/lomd \
  --file powerapps/demo.xml \
  --entities powerapps/entities.conf \
  --port 8080 --api-key 'dev-secret'
```

Import `powerapps/openapi.json` as a custom connector. Call **ListPeople** with `$filter`/`$top` so filtering stays on the server — that is how you avoid the Power Apps 500/2000 client row limit.
