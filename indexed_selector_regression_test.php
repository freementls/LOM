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

// Indexed first piece + unindexed descendants (0-based).
assert_texts($O, 'group[0]_item_name', array('a1', 'a2', 'a3'), 'group[0]_item_name');
assert_texts($O, 'group[1]_item_name', array('b1', 'b2', 'b3'), 'group[1]_item_name');

// Indexed middle piece should be per-parent sibling index.
assert_texts($O, 'group_item[1]_name', array('a2', 'b2'), 'group_item[1]_name per-parent');
assert_texts($O, 'group_item[2]_name', array('a3', 'b3'), 'group_item[2]_name per-parent');
assert_texts($O, 'group_item[3]_name', array(), 'group_item[3]_name out of range');

// Indexed deeper piece should still be per selected parent.
assert_texts($O, 'group_item_leaf[1]_v', array('l32', 'l232'), 'group_item_leaf[1]_v per-parent');
assert_texts($O, 'group_item[2]_leaf[1]_v', array('l32', 'l232'), 'group_item[2]_leaf[1]_v');
assert_texts($O, 'group[1]_item[2]_leaf[1]_v', array('l232'), 'group[1]_item[2]_leaf[1]_v');

// Wildcard + index combinations.
assert_texts($O, '*_item[0]_name', array('a1', 'b1'), '*_item[0]_name');
assert_texts($O, '*_item[1]_name', array('a2', 'b2'), '*_item[1]_name');
assert_texts($O, '*_item[2]_name', array('a3', 'b3'), '*_item[2]_name');
assert_texts($O, '*_item[2]_leaf[1]_v', array('l32', 'l232'), '*_item[2]_leaf[1]_v');

// Index ranges and lists (always 0-based).
assert_texts($O, 'item[0-1]_name', array('a1', 'a2', 'b1', 'b2'), 'item[0-1]_name range per-parent');
assert_texts($O, 'item[0,2]_name', array('a1', 'a3', 'b1', 'b3'), 'item[0,2]_name list per-parent');
assert_texts($O, 'item[3-0]_name', array('a1', 'a2', 'a3', 'b1', 'b2', 'b3'), 'item[3-0]_name reversed range');
assert_texts($O, 'item[0]&item[1]_name', array(), 'item[0]&item[1] different indices is empty AND');
assert_texts($O, 'item[0]&item[0]_name', array('a1', 'b1'), 'item[0]&item[0] same index AND');
assert_texts($O, 'item[0]_name|item[1]_name', array('a1', 'a2', 'b1', 'b2'), 'item[0]_name|item[1]_name union');
assert_texts($O, 'group[0]_item[0-1]_name', array('a1', 'a2'), 'group[0]_item[0-1]_name');
assert_texts($O, 'group[0-1]_item[0]_name', array('a1', 'b1'), 'group[0-1]_item[0]_name');
assert_texts($O, 'group[0]&group[1]_item[0]_name', array(), 'group[0]&group[1] different indices is empty AND');
assert_texts($O, 'item[0]_name', array('a1', 'b1'), 'item[0]_name first per-parent');
assert_texts($O, 'item[0-2]_name', array('a1', 'a2', 'a3', 'b1', 'b2', 'b3'), 'item[0-2]_name range');
assert_texts($O, 'item[0-1,2]_name', array('a1', 'a2', 'a3', 'b1', 'b2', 'b3'), 'item[0-1,2]_name mixed set');

// Existing sibling AND (different tagnames) must stay intersection, not a set union.
$people = '<root><person><name>tom</name><lastname>blabbo</lastname></person><person><name>sally</name><lastname>blabbo</lastname></person><person><name>tom</name><lastname>mott</lastname></person></root>';
$P = new O($people);
$P->debug(false);
assert_texts($P, '.person_name=tom&lastname=blabbo', array('tomblabbo'), 'sibling AND still intersects');
assert_texts($P, '.person_name=sally&lastname=blabbo', array('sallyblabbo'), 'sibling AND sally blabbo');
assert_texts($P, '.person_name=sally&lastname=mott', array(), 'sibling AND empty when no pair');

function ids_from_selector($O, $selector) {
	$matches = $O->get_tagged($selector, false, true, true);
	$out = array();
	foreach($matches as $m) {
		if(!is_array($m) || !isset($m[0])) {
			continue;
		}
		if(preg_match('/id="([^"]+)"/', $m[0], $mm)) {
			$out[] = $mm[1];
		}
	}
	return $out;
}

$demo_xml = file_get_contents(__DIR__ . '/test.xml');
$D = new O($demo_xml);
$D->debug(false);
$ids = ids_from_selector($D, 'a[0-3]');
ok($ids === array('0', '1', '2', '3'), 'a[0-3] first four a nodes');
if($ids !== array('0', '1', '2', '3')) {
	print('a[0-3] ids: ' . json_encode($ids) . PHP_EOL);
}
$ids = ids_from_selector($D, 'a[0]&a[1]');
ok($ids === array(), 'a[0]&a[1] empty AND of different indices');
if($ids !== array()) {
	print('a[0]&a[1] ids: ' . json_encode($ids) . PHP_EOL);
}
$ids = ids_from_selector($D, 'a[0]|a[1]');
ok($ids === array('0', '1'), 'a[0]|a[1] union of first two a nodes');
if($ids !== array('0', '1')) {
	print('a[0]|a[1] ids: ' . json_encode($ids) . PHP_EOL);
}
$ids = ids_from_selector($D, 'a[0,2,4]');
ok($ids === array('0', '2', '4'), 'a[0,2,4] list of a nodes');
if($ids !== array('0', '2', '4')) {
	print('a[0,2,4] ids: ' . json_encode($ids) . PHP_EOL);
}
ok(sizeof($D->get_tagged('a[0]')) === 1, 'a[0] still one match');
$ids = ids_from_selector($D, 'a[1]');
ok($ids === array('1'), 'a[1] is second a (0-based)');
if($ids !== array('1')) {
	print('a[1] ids: ' . json_encode($ids) . PHP_EOL);
}
$ids = ids_from_selector($D, 'a[0-2,4-6,8]');
ok($ids === array('0', '1', '2', '4', '5', '6', '8'), 'a[0-2,4-6,8] standard set notation');
if($ids !== array('0', '1', '2', '4', '5', '6', '8')) {
	print('a[0-2,4-6,8] ids: ' . json_encode($ids) . PHP_EOL);
}
$ids = ids_from_selector($D, '[0-2,4-6,8]');
$ids_star = ids_from_selector($D, '*[0-2,4-6,8]');
ok($ids === $ids_star && sizeof($ids) > 0, 'bare [0-2,4-6,8] is implicit *');
$ids = ids_from_selector($D, 'a[0]');
ok($ids === array('0'), 'a[0] first a');
if($ids !== array('0')) {
	print('a[0] ids: ' . json_encode($ids) . PHP_EOL);
}

// Attribute value uses = ; [n] on attributes is an ordinal index.
$ids = ids_from_selector($D, 'a@id=1');
ok($ids === array('1'), 'a@id=1 is id value 1');
if($ids !== array('1')) {
	print('a@id=1 ids: ' . json_encode($ids) . PHP_EOL);
}
$ids = ids_from_selector($D, 'a@id[0]');
ok($ids === array('0'), 'a@id[0] first a that has id');
if($ids !== array('0')) {
	print('a@id[0] ids: ' . json_encode($ids) . PHP_EOL);
}
$ids = ids_from_selector($D, 'a@id[1]');
ok($ids === array('1'), 'a@id[1] second a that has id');
if($ids !== array('1')) {
	print('a@id[1] ids: ' . json_encode($ids) . PHP_EOL);
}
$ids = ids_from_selector($D, '@id[1]');
ok($ids === array('1'), '@id[1] second node with id attribute');
if($ids !== array('1')) {
	print('@id[1] ids: ' . json_encode($ids) . PHP_EOL);
}
$ids = ids_from_selector($D, 'a@id[0-2,4-6,8]');
ok($ids === array('0', '1', '2', '4', '5', '6', '8'), 'a@id[0-2,4-6,8] ordinal set among a@id');
if($ids !== array('0', '1', '2', '4', '5', '6', '8')) {
	print('a@id[0-2,4-6,8] ids: ' . json_encode($ids) . PHP_EOL);
}
ok(sizeof($D->get_tagged('person@age[0]')) === 1, 'person@age[0] first person with age');
ok(sizeof($D->get_tagged('person@age=16')) === 2, 'person@age=16 both age=16 persons');

assert_texts($D, 'name=sally[0-2]', array('sally', 'sally', 'sally'), 'name=sally[0-2] tagvalue index range');
assert_texts($D, 'name=sally[0]', array('sally'), 'name=sally[0] first sally');
assert_texts($D, 'name=sally[1]', array('sally'), 'name=sally[1] second sally');

function lastnames_from_selector($O, $selector) {
	$matches = $O->get_tagged($selector, false, true, true);
	$out = array();
	foreach($matches as $m) {
		if(!is_array($m) || !isset($m[0])) {
			continue;
		}
		if(preg_match('/<lastname>([^<]*)<\/lastname>/', $m[0], $mm)) {
			$out[] = $mm[1];
		}
	}
	return $out;
}

$ln = lastnames_from_selector($D, '.person_name=sally[0]');
ok($ln === array('kellerman'), '.person_name=sally[0] first sally person');
if($ln !== array('kellerman')) {
	print('.person_name=sally[0] lastnames: ' . json_encode($ln) . PHP_EOL);
}
$ln = lastnames_from_selector($D, '.person_name=sally[1]');
ok($ln === array('mott'), '.person_name=sally[1] second sally person');
if($ln !== array('mott')) {
	print('.person_name=sally[1] lastnames: ' . json_encode($ln) . PHP_EOL);
}
$ln = lastnames_from_selector($D, '.person_name=sally[0-1]');
ok($ln === array('kellerman', 'mott'), '.person_name=sally[0-1] first two sally persons');
if($ln !== array('kellerman', 'mott')) {
	print('.person_name=sally[0-1] lastnames: ' . json_encode($ln) . PHP_EOL);
}
$ln = lastnames_from_selector($D, 'person@age=16[0]');
ok($ln === array('kellerman'), 'person@age=16[0] first age-16 person');
if($ln !== array('kellerman')) {
	print('person@age=16[0] lastnames: ' . json_encode($ln) . PHP_EOL);
}
$ln = lastnames_from_selector($D, 'person@age=16[1]');
ok($ln === array('mott'), 'person@age=16[1] second age-16 person');
if($ln !== array('mott')) {
	print('person@age=16[1] lastnames: ' . json_encode($ln) . PHP_EOL);
}

// Combined tagvalue + attribute value on the same piece.
$combo_xml = '<root><c att2="v4">tv1</c><c>tv2</c><c att2="v5">tv1</c><c att2="v4">tv1</c></root>';
$C = new O($combo_xml);
$C->debug(false);
$cm = $C->get_tagged('c=tv1@att2=v4', false, true, true);
ok(is_array($cm) && sizeof($cm) === 2, 'c=tv1@att2=v4 both matching nodes');
if(!(is_array($cm) && sizeof($cm) === 2)) {
	print('c=tv1@att2=v4 count: ' . (is_array($cm) ? sizeof($cm) : -1) . PHP_EOL);
}
$cm = $C->get_tagged('c=tv1@att2=v4[0]', false, true, true);
ok(is_array($cm) && sizeof($cm) === 1 && strpos($cm[0][0], 'att2="v4"') !== false, 'c=tv1@att2=v4[0] first combo match');
$cm = $C->get_tagged('c=tv1@att2=v4[1]', false, true, true);
ok(is_array($cm) && sizeof($cm) === 1 && strpos($cm[0][0], 'att2="v4"') !== false, 'c=tv1@att2=v4[1] second combo match');
$cm = $C->get_tagged('c=tv1@att2=v5', false, true, true);
ok(is_array($cm) && sizeof($cm) === 1, 'c=tv1@att2=v5 single combo match');

$spec = $D->parse_index_spec('0-2,4-6,8');
ok(is_array($spec) && isset($spec[0]) && isset($spec[2]) && isset($spec[4]) && isset($spec[6]) && isset($spec[8]) && !isset($spec[3]) && !isset($spec[7]), 'parse_index_spec 0-2,4-6,8');

if($FAILURES > 0) {
	print("Indexed selector regression sequence completed with " . $FAILURES . " failure(s)." . PHP_EOL);
	exit(1);
}
print("Indexed selector regression sequence completed." . PHP_EOL);
?>
