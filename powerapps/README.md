# LOM ↔ Power Apps / Power BI

## What this solves

Power Apps’ **500 / 2000 row limit** only hits when a formula is **non-delegable** and the app pulls a chunk of the table to filter locally. This stack avoids that by:

1. Running **`$filter` / `$top` / `$skip` on the server** (liblom indexes)
2. Returning **one page** of rows plus **`@odata.nextLink`**
3. Exposing **`ListPeople`-style actions** in the custom connector (not “load whole table then `Filter()`”)

If you bind a gallery to `ListPeople({ '$filter': "lastname eq 'mott'", '$top': 50 })`, Power Apps never downloads 2,000 unrelated rows.

## Run the daemon

```bash
make -C native
# working copy of demo data (IDs will be assigned on first boot)
cp test.xml powerapps/demo.xml

./native/bin/lomd \
  --file /srv/http/LOM/powerapps/demo.xml \
  --entities /srv/http/LOM/powerapps/entities.conf \
  --port 8080 \
  --api-key 'dev-secret' \
  --base-url 'http://127.0.0.1:8080/odata'
```

## Smoke tests

```bash
curl -s -H 'X-Api-Key: dev-secret' 'http://127.0.0.1:8080/health'
curl -s -H 'X-Api-Key: dev-secret' \
  'http://127.0.0.1:8080/api/ListPeople?$filter=lastname%20eq%20%27mott%27&$top=50&$count=true'
curl -s -H 'X-Api-Key: dev-secret' \
  'http://127.0.0.1:8080/odata/People?$filter=age%20gt%2016&$top=2'
```

## Entity config

`powerapps/entities.conf`:

```
People|person|big_container|lom_id|@age,@eye_color,name,lastname,hobby
```

`Name|row_selector|parent_for_insert|id_attr|columns`

- `@age` = XML attribute  
- `name` = child tag text  
- On boot, missing IDs get `lom_id="lom-N"` and the file is saved

## Power Apps custom connector

1. Power Apps → **Custom connectors** → **Import OpenAPI** → `powerapps/openapi.json`
2. Set host to your `lomd` URL (HTTPS + gateway if on-prem)
3. Auth: API key header `X-Api-Key`
4. In the app, call **`ListPeople`** with filter/top — do **not** use `Filter(People, …)` on a huge imported set

### Gallery pattern (correct)

```
ClearCollect(
  colPage,
  LOM.ListPeople({ '$filter': "startswith(name,'s')", '$top': 50 }).value
)
```

Or page with nextLink when present.

### Anti-pattern (hits 500/2000)

```
Filter(People, name = "sally")   // non-delegable custom connector table
```

## Power BI

- **Get data → OData feed** → `http://host:8080/odata`
- Use Import + scheduled refresh (gateway if on-prem)
- Prefer filtered queries / Power Query steps that fold to `$filter` where possible

## Security note

`--api-key` is required for anything beyond `/health` and `/openapi.json`. Put lomd behind TLS (nginx/caddy) in production; the built-in listener is plain HTTP for local/dev.
