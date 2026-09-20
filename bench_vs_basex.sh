#!/usr/bin/env bash
# Lab-only: BaseX on the same corpus as bench_corpus.sh / bench_vs_tools.php.
# Not a paper table. Needs Java. Fetches BaseX zip into .bench_out/ if missing.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
OUT="$ROOT/.bench_out"
REPORT="$OUT/bench_basex.txt"
BX_HOME="$OUT/basex"
export BASEXHOME="${BASEXHOME:-$OUT/basex_home}"
mkdir -p "$OUT" "$BASEXHOME"

if [[ ! -x "$BX_HOME/bin/basex" ]]; then
  ZIP="$OUT/BaseX-latest.zip"
  echo "fetching BaseX into $OUT ..."
  curl -fsSL -o "$ZIP" https://files.basex.org/releases/BaseX-latest.zip
  unzip -q -o "$ZIP" -d "$OUT"
fi
BX="$BX_HOME/bin/basex"
if [[ ! -x "$BX" ]]; then
  echo "BaseX CLI missing at $BX (need Java + unzip)" >&2
  exit 1
fi

if [[ "${LOM_CORPUS_FETCH:-1}" != "0" ]]; then
  bash "$ROOT/bench_corpus_fetch.sh" || true
fi

row() {
  local lab="$1" path="$2" count_ln="$3" set_ln="$4"
  if [[ ! -s "$path" ]]; then
    printf '%-18s  MISSING %s\n' "$lab" "$path"
    return
  fi
  local tmp out
  tmp="$(mktemp --suffix=".xml")"
  out="$(mktemp --suffix=".written.xml")"
  cp -f "$path" "$tmp"
  # In-query clocks (ns → ms). JVM startup is outside these numbers.
  # count: //*:local-name. set: first *:$set_ln text, then file:write.
  local q
  q=$(cat <<XQ
let \$f := "$tmp"
let \$out := "$out"
let \$t0 := prof:current-ns()
let \$d := doc(\$f)
let \$t1 := prof:current-ns()
let \$n := count(\$d//*:$count_ln)
let \$t2 := prof:current-ns()
let \$_ := (
  copy \$c := \$d
  modify
    if (exists(\$c//*:$set_ln)) then
      replace value of node (\$c//*:$set_ln)[1] with "x"
    else ()
  return file:write(\$out, \$c)
)
let \$t3 := prof:current-ns()
return string-join((
  xs:string(file:size(\$f)),
  xs:string((\$t1 - \$t0) div 1000000),
  xs:string(\$n),
  xs:string((\$t2 - \$t1) div 1000000),
  xs:string((\$t3 - \$t2) div 1000000),
  xs:string(file:size(\$out))
), " ")
XQ
)
  local line
  line="$("$BX" -q "$q" 2>/dev/null || true)"
  rm -f "$tmp" "$out"
  if [[ -z "$line" ]]; then
    printf '%-18s  FAIL query\n' "$lab"
    return
  fi
  local bytes doc_ms n count_ms write_ms wbytes
  read -r bytes doc_ms n count_ms write_ms wbytes <<<"$line"
  printf '%-18s  bytes=%s  doc=%.3fms  count=%.3fms n=%s  set+write=%.3fms written=%s  sel=%s/%s\n' \
    "$lab" "$bytes" "$doc_ms" "$count_ms" "$n" "$write_ms" "$wbytes" "$count_ln" "$set_ln"
}

{
  echo "## basex $($BX -q 'db:system()/general/version' 2>/dev/null || echo '?') $(date -Iseconds)"
  echo "# in-query prof:current-ns (JVM startup excluded). Lab only — not a paper table."
  row test.xml "$ROOT/test.xml" person name
  [[ -f "$OUT/header_sections.xml" ]] && row header "$OUT/header_sections.xml" item name
  [[ -f "$OUT/irregular_mix.xml" ]] && row irregular_mix "$OUT/irregular_mix.xml" item name
  row uniprot_p53 "$OUT/corpus/uniprot_p53.xml" entry accession
  row uniprot_human50 "$OUT/corpus/uniprot_human50.xml" entry accession
  row wikipedia_export "$OUT/corpus/wikipedia_export.xml" page title
  row tiger_svg "$OUT/corpus/ghostscript_tiger.svg" path path
  row maven_pom "$OUT/corpus/commons_lang3.pom" dependency artifactId
  row w3c_atom "$OUT/corpus/w3c_blog.atom" title title
  row osm_london "$OUT/corpus/osm_london.osm" node tag
  row nasa_rss "$OUT/corpus/nasa.rss" item title
  row soap_envelope "$OUT/corpus/soap_envelope.xml" symbol symbol
} | tee "$REPORT"
echo "wrote $REPORT"
