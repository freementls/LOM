<?php
/**
 * Personal bake-off: LOM vs DOMDocument+XPath vs XMLReader on the same file/task.
 * Not for the research paper's main table — keep for local comparison.
 *
 * Usage:
 *   php bench_vs_tools.php [fixture ...]
 *   LOM_PERF_FIXTURE=... php bench_vs_tools.php
 */
require_once __DIR__ . '/O.php';

$files = array();
if(isset($argv) && count($argv) > 1) {
	for($i = 1; $i < count($argv); $i++) {
		if(is_file($argv[$i])) {
			$files[] = $argv[$i];
		}
	}
}
if(!$files) {
	$fixture = getenv('LOM_PERF_FIXTURE');
	if($fixture === false || $fixture === '') {
		$fixture = __DIR__ . '/perf_fixture.xml';
	}
	if(is_file($fixture)) {
		$files[] = $fixture;
	}
	$corpus = __DIR__ . '/.bench_out/corpus';
	if(is_dir($corpus)) {
		foreach(scandir($corpus) as $name) {
			if($name[0] === '.') {
				continue;
			}
			$p = $corpus . '/' . $name;
			if(is_file($p)) {
				$files[] = $p;
			}
		}
	}
	foreach(array(__DIR__ . '/test.xml') as $p) {
		if(is_file($p)) {
			$files[] = $p;
		}
	}
}
$files = array_values(array_unique($files));
if(!$files) {
	fwrite(STDERR, "No fixtures. Pass paths or run ./bench_corpus_fetch.sh\n");
	exit(1);
}

function ms($s) { return round($s * 1000, 2); }
function report($label, $seconds, $extra = '') {
	echo str_pad($label, 48) . ': ' . str_pad(ms($seconds), 10, ' ', STR_PAD_LEFT) . " ms";
	if($extra !== '') echo "  $extra";
	echo PHP_EOL;
}

function guess_tag($path) {
	$base = strtolower(basename($path));
	/* count_sel, xpath, set_sel — set a small leaf, not the whole record. */
	if(strpos($base, 'uniprot') !== false) return array('entry', '//*[local-name()="entry"]', 'accession');
	if(strpos($base, 'wiki') !== false) return array('page', '//*[local-name()="page"]', 'title');
	if(strpos($base, 'tiger') !== false || substr($base, -4) === '.svg') return array('path', '//*[local-name()="path"]', 'path');
	if(substr($base, -4) === '.pom') return array('dependency', '//*[local-name()="dependency"]', 'artifactId');
	if(strpos($base, 'atom') !== false) return array('title', '//*[local-name()="title"]', 'title');
	if(strpos($base, 'osm') !== false) return array('node', '//*[local-name()="node"]', 'tag');
	if(strpos($base, 'rss') !== false) return array('item', '//item', 'title');
	if(strpos($base, 'soap') !== false) return array('symbol', '//*[local-name()="symbol"]', 'symbol');
	return array('name', '//name', 'name');
}

foreach($files as $fixture) {
	$size = filesize($fixture);
	list($lom_sel, $xp_sel, $set_sel) = guess_tag($fixture);
	echo "\n======== " . $fixture . " ($size bytes)  sel=$lom_sel ========\n";
	echo "Task A: cardinality of <$lom_sel> (LOM count / XPath length / XMLReader)\n";

	$t0 = microtime(true);
	$O = new O($fixture, false);
	$t1 = microtime(true);
	$n = $O->count($lom_sel);
	$t2 = microtime(true);
	report('LOM construct', $t1 - $t0);
	report('LOM count ' . $lom_sel, $t2 - $t1, 'n=' . $n);

	$xml = file_get_contents($fixture);
	$t0 = microtime(true);
	$dom = new DOMDocument();
	@$dom->loadXML($xml);
	$t1 = microtime(true);
	$xp = new DOMXPath($dom);
	$nodes = @$xp->query($xp_sel);
	$t2 = microtime(true);
	$xpn = $nodes ? $nodes->length : -1;
	report('DOM loadXML', $t1 - $t0);
	report('XPath ' . $xp_sel, $t2 - $t1, 'n=' . $xpn);

	$t0 = microtime(true);
	$reader = new XMLReader();
	$reader->open($fixture);
	$count = 0;
	$local = $lom_sel;
	while($reader->read()) {
		if($reader->nodeType === XMLReader::ELEMENT && $reader->localName === $local) {
			$count++;
		}
	}
	$reader->close();
	$t1 = microtime(true);
	report('XMLReader count ' . $local, $t1 - $t0, 'n=' . $count);

	echo "Task B: one-leaf set + write XML (LOM checkpoint vs DOM saveXML)\n";
	$t0 = microtime(true);
	$O2 = new O($fixture, false);
	$c1 = microtime(true);
	$O2->set($set_sel, 'x');
	$c2 = microtime(true);
	$out_path = sys_get_temp_dir() . '/lom_vs_' . basename($fixture);
	if($O2->native_doc && function_exists('lom_doc_checkpoint')) {
		lom_doc_checkpoint($O2->native_doc, $out_path);
	} else {
		file_put_contents($out_path, $O2->code);
	}
	$t1 = microtime(true);
	$wsize = is_file($out_path) ? filesize($out_path) : 0;
	report('LOM construct', $c1 - $t0);
	report('LOM set ' . $set_sel, $c2 - $c1);
	report('LOM write XML', $t1 - $c2, 'out_bytes=' . $wsize);
	@unlink($out_path);
	@unlink($out_path . '.lomidx');
	@unlink($out_path . '.lomwal');

	$t0 = microtime(true);
	$dom2 = new DOMDocument();
	@$dom2->loadXML($xml);
	$xp2 = new DOMXPath($dom2);
	$n0 = @$xp2->query('//*[local-name()="' . $set_sel . '"] | //' . $set_sel);
	if($n0 && $n0->length) {
		$n0->item(0)->nodeValue = 'x';
	}
	$out = $dom2->saveXML();
	$t1 = microtime(true);
	report('DOM load+set+saveXML', $t1 - $t0, 'out_bytes=' . strlen($out));
}

echo "\nNote: conversational context / living variables have no XPath equivalent.\n";
echo "Count vs node-set length is the fair cardinality task. get_tagged copies text.\n";
