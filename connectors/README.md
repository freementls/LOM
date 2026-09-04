# LOM connectors

LOM is usable from other languages without rewriting [`O.php`](../O.php). Two contracts:

1. **In-process C ABI** — [`native/include/lom.h`](../native/include/lom.h) / `liblom.so`  
   `lom_doc_create`, `lom_doc_get`, `lom_doc_set_inner_text`, `lom_doc_new_before_close`, `lom_doc_delete`, `lom_doc_save_file`. Results are offset pairs, not a DOM.

2. **HTTP / OData** — [`lomd`](../native/src/lomd.c) + [`powerapps/openapi.json`](../powerapps/openapi.json)  
   API key header `X-Api-Key`, `$filter` / `$top` / `$skip`. Any language with an HTTP client can connect.

Selector strings are UTF-8. Child axis is `_` / `__`. Regex values (`hobby%=/s.*/i`) work in PHP and in native `liblom` (PCRE2; same operator table: `=` `%=` `^=` `$=` `~=` `!=`).

License: **Apache-2.0** — you may publish connectors without asking.

## Languages

| Language | Package / folder | Binding |
|----------|------------------|---------|
| **C# / .NET** | [`csharp/`](csharp/) | P/Invoke `Lom.Native` + `HttpClient` `Lom.OData` (first-class) |
| **Python** | [`python/`](python/) | `ctypes` + `httpx`/`urllib` |
| **TypeScript** | [`typescript/`](typescript/) | fetch client for OpenAPI |
| **Java** | [`java/`](java/) | JNA `LomNative.get` + `LomODataClient` |
| **Go** | [`go/`](go/) | cgo `lom.OpenFile` / `Get` + `ODataClient` |

PHP and C remain the cores. Additional languages only need the ABI or OpenAPI file.

## C# quick start

```bash
make -C native
# Native (in-process):
cd connectors/csharp && dotnet run --project Lom.Native.Demo -- ../../test.xml 'person'
# OData (against lomd):
./native/bin/lomd --file powerapps/demo.xml --entities powerapps/entities.conf --port 8080 --api-key 'dev'
cd connectors/csharp && dotnet run --project Lom.OData.Demo -- http://127.0.0.1:8080 dev
```

## Go / Python / Java (in-process Get)

```bash
make -C native
# Go
cd connectors/go && CGO_ENABLED=1 go test ./lom -count=1
# Python
python3 -c "from python.lom import LomNative; ..."  # see connectors/python/lom.py
# Java: add jna on classpath, jna.library.path=native/lib, call lom.LomNative
```
