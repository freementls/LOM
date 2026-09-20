#!/usr/bin/env bash
# Download public real-world XML into .bench_out/corpus/ (gitignored).
# Sources are cited in docs/RESULTS.md. Failures are skipped so CI stays offline-safe.
set -u
ROOT="$(cd "$(dirname "$0")" && pwd)"
DEST="$ROOT/.bench_out/corpus"
mkdir -p "$DEST"
UA="LOM-corpus/0.3.2 (+https://github.com; research bench)"

fetch() {
  local out="$1" url="$2"
  shift 2
  if [[ -f "$out" && -s "$out" ]]; then
    echo "have $(basename "$out") ($(wc -c < "$out") bytes)"
    return 0
  fi
  echo "GET $url"
  if curl -fsSL --retry 2 --max-time 90 -A "$UA" "$@" -o "$out.tmp" "$url"; then
    mv "$out.tmp" "$out"
    echo "  -> $(basename "$out") ($(wc -c < "$out") bytes)"
    return 0
  fi
  rm -f "$out.tmp"
  echo "  skip $(basename "$out")"
  return 1
}

# UniProt: one reviewed protein + a 50-entry human slice (CC BY 4.0).
fetch "$DEST/uniprot_p53.xml" \
  "https://rest.uniprot.org/uniprotkb/P04637.xml"
fetch "$DEST/uniprot_human50.xml" \
  "https://rest.uniprot.org/uniprotkb/search?query=reviewed:true+AND+organism_id:9606&format=xml&size=50"

# MediaWiki export of a few well-known pages (CC BY-SA).
fetch "$DEST/wikipedia_export.xml" \
  "https://en.wikipedia.org/w/index.php?title=Special:Export&pages=XML%0AXPath%0ADocument_Object_Model%0ACSS&action=submit&curonly=1&templates=1"

# Wikimedia Commons SVG (public domain / CC).
fetch "$DEST/ghostscript_tiger.svg" \
  "https://upload.wikimedia.org/wikipedia/commons/f/fd/Ghostscript_Tiger.svg"

# Maven POM (Apache-2.0 artifact metadata).
fetch "$DEST/commons_lang3.pom" \
  "https://repo1.maven.org/maven2/org/apache/commons/commons-lang3/3.17.0/commons-lang3-3.17.0.pom"

# W3C news Atom (public).
fetch "$DEST/w3c_blog.atom" \
  "https://www.w3.org/news/feed/"

# OpenStreetMap API map call — small central-London bbox (ODbL).
fetch "$DEST/osm_london.osm" \
  "https://api.openstreetmap.org/api/0.6/map?bbox=-0.128,51.507,-0.126,51.508"

# NASA RSS (public domain US gov).
fetch "$DEST/nasa.rss" \
  "https://www.nasa.gov/rss/dyn/breaking_news.rss"

# Local SOAP envelope (public W3C shape; no remote host required).
if [[ ! -s "$DEST/soap_envelope.xml" ]]; then
  cat > "$DEST/soap_envelope.xml" <<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
               xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <soap:Header>
    <auth xmlns="urn:example:auth"><token>demo</token></auth>
  </soap:Header>
  <soap:Body>
    <GetQuote xmlns="urn:example:stock">
      <symbol>IBM</symbol>
      <symbol>MSFT</symbol>
    </GetQuote>
  </soap:Body>
</soap:Envelope>
XML
  echo "wrote soap_envelope.xml"
fi

echo "corpus dir: $DEST"
ls -la "$DEST"
