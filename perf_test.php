<?php
require_once __DIR__ . '/O.php';
putenv('LOM_PROFILE=1');

function t() { return microtime(true); }
function ms($s,$e){ return round(($e-$s)*1000,2); }
function report($label,$start,$end,$extra='') {
    echo str_pad($label, 42) . ': ' . str_pad(ms($start,$end), 10, ' ', STR_PAD_LEFT) . " ms";
    if($extra !== '') echo "  " . $extra;
    echo PHP_EOL;
}

$fixture = getenv('LOM_PERF_FIXTURE');
if($fixture === false || $fixture === '') {
	$fixture = __DIR__ . '/perf_fixture.xml';
}
if(!file_exists($fixture)) {
	fwrite(STDERR, "Missing fixture: $fixture\nGenerate with: php gen_perf_fixture.php 2MB perf_fixture.xml\n");
	exit(1);
}

$start = t();
$O = new O($fixture);
$end = t();
report('construct + validate + depth map', $start, $end, 'bytes=' . strlen($O->code) . ' file_bytes=' . filesize($fixture));

// Selectors match perf_fixture.xml: <world><region>…<zone>…<entity kind="…"><meta><name>…</name>…<stats>…
$chain_stats = 'region_zone_entity_stats';
$chain_meta = 'region[1]_zone[3]_entity[4]_meta';
$chain_note = 'region[1]_zone[3]_entity[4]_meta_note';
$chain_bonus_value = 'region[1]_zone[3]_entity[4]_meta_bonus_value';
$indexed_stats = 'region[10]_zone[5]_entity[7]_stats';

$cases = array(
	array('top-level repeated tag', function() use ($O) { return $O->get_tagged('region'); }),
	array('descendant chain', function() use ($O, $chain_stats) { return $O->get_tagged($chain_stats); }),
	// Contextual by design: this runs right after descendant chain warmup.
	array('attribute existence', function() use ($O) { return $O->get_tagged('entity@kind'); }),
	array('attribute value subtag combo', function() use ($O) { return $O->get_tagged('entity_meta_name=Entity_42'); }),
	array('indexed tagname selector', function() use ($O, $indexed_stats) { return $O->get_tagged($indexed_stats); }),
	array('parent reads', function() use ($O, $chain_stats) { return $O->get_tagged_parent($chain_stats); }),
	array('regex %= note rare', function() use ($O) { return $O->get_tagged('note%=/note-0-0-0/', false, false, true); }),
	array('regex ^= Entity', function() use ($O) { return $O->get_tagged('name^=/Entity_/', false, false, true); }),
	array('regex %= note common', function() use ($O) { return $O->get_tagged('note%=/note-/', false, false, true); }),
);

foreach($cases as $case) {
    list($label, $fn) = $case;
    $start = t();
    $r1 = $fn();
    $mid = t();
    $r2 = $fn();
    $end = t();
    report($label . ' (cold)', $start, $mid, 'matches=' . count($r1));
    report($label . ' (warm)', $mid, $end, 'matches=' . count($r2));
}

$start = t();
$targets = $O->get_tagged($chain_note);
$mid = t();
$O->__($chain_note, 'changed-note');
$end = t();
report('warm up write target select', $start, $mid, 'matches=' . count($targets));
report('set() after context warmup', $mid, $end);

$start = t();
$O->new_('<bonus><name>surge</name><value>99</value></bonus>', $chain_meta);
$end = t();
report('new_() nested insert', $start, $end);

$start = t();
$check = $O->get_tagged($chain_bonus_value);
$end = t();
report('post-write descendant read', $start, $end, 'matches=' . count($check));

$start = t();
$valid = $O->validate();
$end = t();
report('validate() after writes', $start, $end, 'result=' . ($valid ? 'true' : 'false'));

$O->dump_total_time_taken();
$O->dump_profile_report(25);
