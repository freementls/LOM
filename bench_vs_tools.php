<?php
/**
 * Personal bake-off: LOM vs DOMDocument+XPath vs XMLReader on the same file/task.
 * Not for the research paper's main table — keep for local comparison.
 *
 * Usage:
 *   php bench_vs_tools.php [fixture]
 *   LOM_PERF_FIXTURE=... php bench_vs_tools.php
 */
require_once __DIR__ . '/O.php';

$fixture = getenv('LOM_PERF_FIXTURE');
if($fixture === false || $fixture === '') {
	$fixture = isset($argv[1]) ? $argv[1] : (__DIR__ . '/perf_fixture.xml');
}
if(!file_exists($fixture)) {
	fwrite(STDERR, "Missing $fixture\n");
	exit(1);
}

function ms($s) { return round($s * 1000, 2); }
function report($label, $seconds, $extra = '') {
	echo str_pad($label, 48) . ': ' . str_pad(ms($seconds), 10, ' ', STR_PAD_LEFT) . " ms";
	if($extra !== '') echo "  $extra";
	echo PHP_EOL;
}

$xml = file_get_contents($fixture);
$size = strlen($xml);
echo "Fixture: $fixture ($size bytes)\n";
echo "Task A: count all <name> text nodes (same result expected)\n";

// LOM
$t0 = microtime(true);
$O = new O($fixture, false);
$t1 = microtime(true);
$names = $O->get_tagged('name');
$t2 = microtime(true);
report('LOM construct', $t1 - $t0, 'opens≈');
report('LOM get name', $t2 - $t1, 'matches=' . count($names));

// DOMDocument + XPath
$t0 = microtime(true);
$dom = new DOMDocument();
@$dom->loadXML($xml);
$t1 = microtime(true);
$xp = new DOMXPath($dom);
$nodes = $xp->query('//name');
$t2 = microtime(true);
report('DOM loadXML', $t1 - $t0);
report('XPath //name', $t2 - $t1, 'matches=' . $nodes->length);

// XMLReader scan
$t0 = microtime(true);
$reader = new XMLReader();
$reader->open($fixture);
$count = 0;
while($reader->read()) {
	if($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'name') {
		$count++;
	}
}
$reader->close();
$t1 = microtime(true);
report('XMLReader count name', $t1 - $t0, 'matches=' . $count);

echo "\nTask B: set one text node (LOM only has in-place string write; DOM rebuilds)\n";
$t0 = microtime(true);
$O2 = new O($fixture, false);
$O2->set('region[1]_zone[1]_entity[1]_meta_note', 'bench-note');
$t1 = microtime(true);
report('LOM set note', $t1 - $t0);

$t0 = microtime(true);
$dom2 = new DOMDocument();
@$dom2->loadXML($xml);
$xp2 = new DOMXPath($dom2);
$n = $xp2->query('//region[1]/zone[1]/entity[1]/meta/note')->item(0);
if($n) {
	$n->nodeValue = 'bench-note';
}
$out = $dom2->saveXML();
$t1 = microtime(true);
report('DOM set+saveXML', $t1 - $t0, 'out_bytes=' . strlen($out));

echo "\nNote: conversational context / living variables have no XPath equivalent.\n";
