<?php
include(__DIR__ . '/O.php');

function ok($cond, $label) {
	global $FAILURES;
	print($label . ': ' . ($cond ? "OK" : "FAIL") . PHP_EOL);
	if(!$cond) {
		$FAILURES++;
	}
}

function texts_from_selector($O, $selector) {
	$matches = $O->get_tagged($selector);
	$out = array();
	foreach($matches as $m) {
		if(!is_array($m) || !isset($m[0])) {
			continue;
		}
		$out[] = trim($O->tagless($m[0]));
	}
	return $out;
}

function assert_texts($O, $selector, $expected, $label) {
	$actual = texts_from_selector($O, $selector);
	$pass = ($actual === $expected);
	if(!$pass) {
		print($label . ' expected: ' . json_encode($expected) . PHP_EOL);
		print($label . ' actual  : ' . json_encode($actual) . PHP_EOL);
	}
	ok($pass, $label);
}

$xml = '<root>'
	. '<group id="g1">'
	. '<item><name>a1</name></item>'
	. '<item><name>a2</name><leaf><v>l21</v></leaf></item>'
	. '<item><name>a3</name><leaf><v>l31</v></leaf><leaf><v>l32</v></leaf></item>'
	. '</group>'
	. '<group id="g2">'
	. '<item><name>b1</name></item>'
	. '<item><name>b2</name><leaf><v>l221</v></leaf></item>'
	. '<item><name>b3</name><leaf><v>l231</v></leaf><leaf><v>l232</v></leaf></item>'
	. '</group>'
	. '</root>';

$O = new O($xml);
$O->debug(false);
$FAILURES = 0;

// Baseline chain behavior without indices.
assert_texts($O, 'group_item_name', array('a1', 'a2', 'a3', 'b1', 'b2', 'b3'), 'group_item_name all names');

// Indexed first piece + unindexed descendants.
assert_texts($O, 'group[1]_item_name', array('a1', 'a2', 'a3'), 'group[1]_item_name');
assert_texts($O, 'group[2]_item_name', array('b1', 'b2', 'b3'), 'group[2]_item_name');

// Indexed middle piece should be per-parent sibling index.
assert_texts($O, 'group_item[2]_name', array('a2', 'b2'), 'group_item[2]_name per-parent');
assert_texts($O, 'group_item[3]_name', array('a3', 'b3'), 'group_item[3]_name per-parent');
assert_texts($O, 'group_item[4]_name', array(), 'group_item[4]_name out of range');

// Indexed deeper piece should still be per selected parent.
assert_texts($O, 'group_item_leaf[2]_v', array('l32', 'l232'), 'group_item_leaf[2]_v per-parent');
assert_texts($O, 'group_item[3]_leaf[2]_v', array('l32', 'l232'), 'group_item[3]_leaf[2]_v');
assert_texts($O, 'group[2]_item[3]_leaf[2]_v', array('l232'), 'group[2]_item[3]_leaf[2]_v');

// Wildcard + index combinations.
assert_texts($O, '*_item[1]_name', array('a1', 'b1'), '*_item[1]_name');
assert_texts($O, '*_item[2]_name', array('a2', 'b2'), '*_item[2]_name');
assert_texts($O, '*_item[3]_name', array('a3', 'b3'), '*_item[3]_name');
assert_texts($O, '*_item[3]_leaf[2]_v', array('l32', 'l232'), '*_item[3]_leaf[2]_v');

if($FAILURES > 0) {
	print("Indexed selector regression sequence completed with " . $FAILURES . " failure(s)." . PHP_EOL);
	exit(1);
}
print("Indexed selector regression sequence completed." . PHP_EOL);
?>
