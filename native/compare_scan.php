<?php
/**
 * Compare pure-PHP vs lom_accel parent index scan on a fixture.
 * Usage: php -d extension=... native/compare_scan.php [file]
 */
require_once __DIR__ . '/../O.php';

$file = $argv[1] ?? (__DIR__ . '/../test.xml');
$code = file_get_contents($file);

function capture_indexes(O $O) {
	return array(
		'opens' => $O->opening_tag_offsets,
		'tag_end' => $O->tag_end_offsets,
		'parent' => $O->parent_offsets,
		'node_end' => $O->node_end_offsets,
		'names' => $O->opening_tag_names,
		'tag_index' => $O->tag_index,
		'sc' => $O->self_closing_open_count_in_index,
		'attrs' => $O->attribute_full_index_ready,
		'attr_index' => $O->attribute_index,
	);
}

putenv('LOM_ACCEL=0');
$php = new O($code);
$t0 = microtime(true);
$php->build_parent_indexes();
$t1 = microtime(true);
$a = capture_indexes($php);
$php_ms = ($t1 - $t0) * 1000;

if(!function_exists('lom_accel_scan')) {
	fwrite(STDERR, "lom_accel not loaded; only timed pure PHP: " . round($php_ms, 3) . " ms opens=" . count($a['opens']) . "\n");
	exit(0);
}

putenv('LOM_ACCEL=1');
// reset static cache via fresh request semantics: new class methods already cached from before —
// lom_accel_enabled caches LOM_ACCEL=0. Force by calling scan directly.
$native = new O($code);
$t0 = microtime(true);
$scan = lom_accel_scan($native->code, true);
$ok = is_array($scan);
if($ok) {
	$native->opening_tag_offsets = $scan['opening_tag_offsets'];
	$native->opening_tag_offsets_count = sizeof($native->opening_tag_offsets);
	$native->tag_end_offsets = $scan['tag_end_offsets'];
	$native->parent_offsets = $scan['parent_offsets'];
	foreach($native->parent_offsets as $k => $v) {
		if($v === null) $native->parent_offsets[$k] = false;
	}
	$native->node_end_offsets = $scan['node_end_offsets'];
	$native->opening_tag_names = $scan['opening_tag_names'];
	$native->tag_index = $scan['tag_index'];
	$native->self_closing_open_count_in_index = (int)$scan['self_closing_open_count'];
	$native->attribute_index = $scan['attribute_index'];
	$native->attribute_value_index = $scan['attribute_value_index'];
	$native->attribute_full_index_ready = !empty($scan['attribute_full_index_ready']);
	$native->parent_indexes_ready = true;
}
$t1 = microtime(true);
$b = capture_indexes($native);
$nat_ms = ($t1 - $t0) * 1000;

$eq = function($x, $y) {
	if($x === $y) return true;
	if(!is_array($x) || !is_array($y)) return false;
	if(count($x) !== count($y)) return false;
	ksort($x, SORT_NUMERIC);
	ksort($y, SORT_NUMERIC);
	return $x == $y;
};
$diffs = array();
if(!$eq($a['opens'], $b['opens'])) $diffs[] = 'opens';
if(!$eq($a['tag_end'], $b['tag_end'])) $diffs[] = 'tag_end';
if(!$eq($a['parent'], $b['parent'])) $diffs[] = 'parent';
if(!$eq($a['node_end'], $b['node_end'])) $diffs[] = 'node_end';
if(!$eq($a['names'], $b['names'])) $diffs[] = 'names';
if($a['tag_index'] != $b['tag_index']) $diffs[] = 'tag_index';
if($a['sc'] !== $b['sc']) $diffs[] = 'self_closing';

echo "file=$file bytes=" . strlen($code) . "\n";
echo "php_ms=" . round($php_ms, 3) . " native_ms=" . round($nat_ms, 3) . " opens=" . count($a['opens']) . "\n";
if($diffs) {
	echo "MISMATCH: " . implode(',', $diffs) . "\n";
	foreach($diffs as $d) {
		if($d === 'opens') {
			echo "  php_count=" . count($a['opens']) . " nat_count=" . count($b['opens']) . "\n";
		}
		if($d === 'parent') {
			$n = 0;
			foreach($a['parent'] as $k => $v) {
				$bv = $b['parent'][$k] ?? 'MISSING';
				if($v !== $bv && $n < 5) {
					echo "  parent[$k] php="; var_export($v); echo " nat="; var_export($bv); echo "\n";
					$n++;
				}
			}
		}
		if($d === 'names') {
			$n = 0;
			foreach($a['names'] as $k => $v) {
				$bv = $b['names'][$k] ?? 'MISSING';
				if($v !== $bv && $n < 5) {
					echo "  names[$k] php="; var_export($v); echo " nat="; var_export($bv); echo "\n";
					$n++;
				}
			}
		}
	}
	exit(1);
}
echo "MATCH\n";
