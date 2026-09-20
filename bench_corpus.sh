#!/usr/bin/env bash
# Phase 0 — regular + irregular corpus clocks (not a substitute for bench_20gb.sh).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
OUT="$ROOT/.bench_out"
REPORT="$OUT/bench_corpus.txt"
mkdir -p "$OUT"
export LOM_OPEN_TMPDIR="${LOM_OPEN_TMPDIR:-/var/tmp}"
export LOM_SIDECAR="${LOM_SIDECAR:-1}"
make -C "$ROOT/native" -j"$(nproc)" bin/bench_corpus >/dev/null
if [[ "${LOM_CORPUS_FETCH:-1}" != "0" ]]; then
  bash "$ROOT/bench_corpus_fetch.sh" || true
fi

IRREG="$OUT/irregular_mix.xml"
if [[ ! -f "$IRREG" ]]; then
  php -r '
    $n = 4000;
    echo "<world>\n";
    for ($i = 0; $i < $n; $i++) {
      if ($i % 5 === 0) echo "<aside id=\"a$i\"><blurb>x$i</blurb></aside>\n";
      else echo "<item id=\"i$i\"><name>N$i</name><value>".($i%50)."</value></item>\n";
    }
    echo "</world>\n";
  ' > "$IRREG"
fi

HDR="$OUT/header_sections.xml"
if [[ ! -f "$HDR" ]]; then
  cat > "$HDR" <<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE root [ <!ELEMENT root ANY> ]>
<!-- comment with < and > -->
<?xml-stylesheet type="text/xsl" href="style.xsl"?>
<root>
  <item id="i1"><name>Alpha</name><value>1</value></item>
  <item id="i2"><name>Beta</name><value>2</value></item>
  <raw><![CDATA[ if (a < b && c > d) ]]></raw>
</root>
XML
fi

{
  echo "## corpus $(date -Iseconds)"
  ARGS=(test.xml "$ROOT/test.xml" person header "$HDR" item irregular_mix "$IRREG" item)
  CORPUS="$OUT/corpus"
  add_if() {
    local lab="$1" path="$2" sel="$3"
    if [[ -s "$path" ]]; then
      ARGS+=("$lab" "$path" "$sel")
    fi
  }
  add_if uniprot_p53 "$CORPUS/uniprot_p53.xml" entry
  add_if uniprot_human50 "$CORPUS/uniprot_human50.xml" entry
  add_if wikipedia_export "$CORPUS/wikipedia_export.xml" page
  add_if tiger_svg "$CORPUS/ghostscript_tiger.svg" path
  add_if maven_pom "$CORPUS/commons_lang3.pom" dependency
  add_if w3c_atom "$CORPUS/w3c_blog.atom" title
  add_if osm_london "$CORPUS/osm_london.osm" node
  add_if nasa_rss "$CORPUS/nasa.rss" item
  add_if soap_envelope "$CORPUS/soap_envelope.xml" symbol
  if [[ -n "${LOM_CORPUS_1GB:-}" && -f "$ROOT/.bench_out/fixture_1GB.xml" ]]; then
    ARGS+=(fixture_1GB "$ROOT/.bench_out/fixture_1GB.xml" region)
  fi
  if [[ -n "${LOM_CORPUS_20GB:-}" && -f "$ROOT/.bench_out/fixture_20GB.xml" ]]; then
    ARGS+=(fixture_20GB "$ROOT/.bench_out/fixture_20GB.xml" region)
  fi
  "$ROOT/native/bin/bench_corpus" "${ARGS[@]}"
} | tee "$REPORT"
echo "wrote $REPORT"
