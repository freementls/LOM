# LOM connectors

LOM is usable from other languages without rewriting [`O.php`](../O.php). Two contracts:

1. **In-process C ABI** — [`native/include/lom.h`](../native/include/lom.h) / `liblom.so`  
   Core: `lom_doc_create_file`, `lom_doc_get`, `lom_doc_count`, `lom_doc_set_inner_text`, `lom_doc_new_before_close`, `lom_doc_delete`, `lom_doc_save_file`, `lom_doc_node_slice`. Results are offset pairs (or counts), not a DOM.

2. **HTTP / OData** — [`lomd`](../native/src/lomd.c) + [`powerapps/openapi.json`](../powerapps/openapi.json)  
   API key header `X-Api-Key`, `$filter` / `$top` / `$skip`. Any language with an HTTP client can connect.

Selector strings are UTF-8. Child axis is `_` / `__`. Regex values (`hobby%=/s.*/i`) work in PHP and in native `liblom` (PCRE2; same operator table: `=` `%=` `^=` `$=` `~=` `!=`). Prefer `lom_doc_count` / `Count` for cardinality on broad selectors.

License: **Apache-2.0** — you may publish connectors without asking.

## Build native first

```bash
make -C native
export LD_LIBRARY_PATH="$PWD/native/lib${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
# optional: LOM_OPEN_TMPDIR=/var/tmp
```

## Languages

| Language | Package / folder | Binding |
|----------|------------------|---------|
| **C# / .NET** | [`csharp/`](csharp/) | P/Invoke `Lom.Native` + `HttpClient` `Lom.OData` (first-class) |
| **Python** | [`python/`](python/) | `ctypes` + `urllib` |
| **TypeScript** | [`typescript/`](typescript/) | fetch client for OpenAPI |
| **Java** | [`java/`](java/) | JNA `LomNative` + `LomODataClient` |
| **Go** | [`go/`](go/) | cgo `lom.OpenFile` / `Get` / `Count` + `ODataClient` |

PHP and C remain the cores. Additional languages only need the ABI or OpenAPI file.

## C# quick start

```bash
make -C native
cd connectors/csharp
LD_LIBRARY_PATH=../../native/lib dotnet run --project Lom.Native.Demo -- ../../test.xml 'person'
# Writes:
#   doc.Set / doc.New / doc.Delete / doc.Save / doc.Count
# OData (against lomd):
../../native/bin/lomd --file ../../powerapps/demo.xml --entities ../../powerapps/entities.conf --port 8080 --api-key 'dev' &
dotnet run --project Lom.OData.Demo -- http://127.0.0.1:8080 dev
```

## Go / Python / Java (in-process)

```bash
make -C native
export LD_LIBRARY_PATH="$PWD/native/lib"

# Go
cd connectors/go && CGO_ENABLED=1 go test ./lom -count=1

# Python
python3 - <<'PY'
from python.lom import LomNative
with LomNative("test.xml") as d:
    print(LomNative.version(), d.count("person"), d.get("person")[:2])
PY

# Java: add jna on classpath, jna.library.path=native/lib, call lom.LomNative
```

Run from repo root for the Python snippet (`PYTHONPATH=connectors`).
