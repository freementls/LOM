<?php
/**
 * Generate a LOM perf fixture of approximately the requested size.
 *
 * Usage:
 *   php gen_perf_fixture.php [target_bytes] [out_path]
 *   php gen_perf_fixture.php 1048576 /tmp/lom_1mb.xml
 *   php gen_perf_fixture.php 20GB /tmp/lom_20gb.xml   # requires huge disk/RAM
 *
 * Structure mirrors perf_fixture.xml: world/region/zone/entity/...
 */
$target = isset($argv[1]) ? $argv[1] : '2MB';
$out = isset($argv[2]) ? $argv[2] : (__DIR__ . '/perf_fixture_gen.xml');

function parse_size($s) {
	$s = trim((string)$s);
	if(is_numeric($s)) {
		return (int)$s;
	}
	if(preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*(B|KB|MB|GB)$/i', $s, $m)) {
		$n = (float)$m[1];
		$u = strtoupper($m[2]);
		$mul = array('B' => 1, 'KB' => 1024, 'MB' => 1048576, 'GB' => 1073741824);
		return (int)round($n * $mul[$u]);
	}
	fwrite(STDERR, "Bad size: $s\n");
	exit(1);
}

$target_bytes = parse_size($target);
if($target_bytes < 1024) {
	fwrite(STDERR, "Target too small\n");
	exit(1);
}

$regions = max(1, (int)ceil($target_bytes / 50000));
$zones_per = 5;
$entities_per = 8;

$fh = fopen($out, 'wb');
if(!$fh) {
	fwrite(STDERR, "Cannot write $out\n");
	exit(1);
}
fwrite($fh, '<?xml version="1.0" encoding="UTF-8"?><world>');
$bytes = 39;
$ri = 0;
while($bytes < $target_bytes) {
	$chunk = '<region id="r' . $ri . '" climate="c' . ($ri % 7) . '" active="' . ($ri % 2) . '"><name>Region_' . $ri . '</name>';
	for($z = 0; $z < $zones_per; $z++) {
		$chunk .= '<zone id="z' . $ri . '_' . $z . '" tier="' . ($z % 4) . '" biome="b' . ($z % 5) . '"><name>Zone_' . $ri . '_' . $z . '</name>';
		for($e = 0; $e < $entities_per; $e++) {
			$eid = $ri * $zones_per * $entities_per + $z * $entities_per + $e;
			$kind = ($e % 3 === 0) ? 'npc' : (($e % 3 === 1) ? 'mob' : 'item');
			$hobby = ($eid % 5 === 0) ? 'skiing' : (($eid % 5 === 1) ? 'swimming' : (($eid % 5 === 2) ? 'fishing' : (($eid % 5 === 3) ? 'sleeping' : 'world domination')));
			$chunk .= '<entity id="e' . $ri . '_' . $z . '_' . $e . '" kind="' . $kind . '" state="s' . ($e % 4) . '" age="' . (10 + ($eid % 90)) . '">'
				. '<meta><name>Entity_' . $eid . '</name><note>note-' . $ri . '-' . $z . '-' . $e . '</note><hobby>' . $hobby . '</hobby></meta>'
				. '<stats><hp>' . (100 + $e) . '</hp><mp>' . (50 + $z) . '</mp><speed>' . (5 + ($e % 3)) . '</speed></stats>'
				. '<inventory><item slot="0" rarity="0"><name>Item_' . $eid . '_0</name><value>1</value></item></inventory>'
				. '</entity>';
		}
		$chunk .= '</zone>';
	}
	$chunk .= '</region>';
	fwrite($fh, $chunk);
	$bytes += strlen($chunk);
	$ri++;
	if(($ri % 100) === 0) {
		fwrite(STDERR, "\rregions=$ri bytes=$bytes");
	}
}
fwrite($fh, '</world>');
fclose($fh);
$final = filesize($out);
fwrite(STDERR, "\nWrote $out ($final bytes, $ri regions)\n");
echo $out . "\n";
