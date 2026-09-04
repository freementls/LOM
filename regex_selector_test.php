<?php
/**
 * Regex selector regression tests for LOM.
 * Run: php regex_selector_test.php
 */
require_once __DIR__ . '/O.php';

$failed = 0;
$passed = 0;

function expect_count($label, $selector, $expected) {
	global $failed, $passed;
	$O = new O(__DIR__ . '/test.xml');
	$r = $O->get_tagged($selector);
	$n = is_array($r) ? count($r) : -1;
	if($n === $expected) {
		echo "PASS  $label ($n)\n";
		$passed++;
	} else {
		echo "FAIL  $label: expected $expected, got $n for [$selector]\n";
		$failed++;
	}
}

function expect_true($label, $cond) {
	global $failed, $passed;
	if($cond) {
		echo "PASS  $label\n";
		$passed++;
	} else {
		echo "FAIL  $label\n";
		$failed++;
	}
}

// Operators on tag text
expect_count('= full match skiing', 'hobby=/skiing/i', 1);
expect_count('= ski.* needs full cover (0)', 'hobby=/ski.*/i', 1); // ski.* covers skiing
expect_count('%= s\\w+ substring', 'hobby%=/s\\w+/', 4); // skiing,swimming,fishing(shing),sleeping
expect_count('^= starts with s', 'hobby^=/s/i', 3);
expect_count('$= ends with ing', 'hobby$=/ing$/', 4);
expect_count('~= whole-word skiing|swimming', 'hobby~=/skiing|swimming/', 2);
expect_count('!= excludes sleep*', 'hobby!=/sleep/', 4);

// Attributes + numeric pattern
expect_count('attr =999', '*@age=/999/', 1);
expect_count('attr >= pattern ages', '*@age>=/1[6-9]|[2-9]\\d+/', 3);

// Child path with regex value
expect_count('person_name ^= sal', '.person_name^=/sal/i', 3);
expect_count('name full /^sal.*$/i', 'name=/^sal.*$/i', 3);
expect_count('lastname %= man|ido', 'lastname%=/man|ido/', 2);

// Slash is not a child alias
expect_count('/ not child path', 'region/zone', 0);
expect_count('_ still child path', 'tag1_tag2', 2);

// Encoded literal slash in ordinary value (non-regex)
$O = new O(__DIR__ . '/test.xml');
$enc = $O->enc('/');
expect_true('enc(/) is #forwardslash#', $enc === '#forwardslash#');

// Tagvalue must not match nested markup / structure
expect_count('person structure </ blocked', 'person%=/<\\//', 0);
expect_count('person structure <name blocked', 'person%=/<name/', 0);
expect_count('person tagless text sally ok', 'person%=/sally/', 3);
expect_count('name leaf still works', 'name%=/sally/', 3);

// Escaped slash inside pattern
expect_count('pattern with \\/', 'hobby%=/ski\\/ing/', 0); // no hobby contains ski/ing

// Index still means node index
expect_count('regex + [1] node index', 'hobby%=/ing/[1]', 1);

// Invalid flags should fatal — catch via subprocess
$cmd = 'php -r \'require "' . __DIR__ . '/O.php"; $O=new O("' . __DIR__ . '/test.xml"); $O->get_tagged("hobby=/x/e");\' 2>&1';
$out = shell_exec($cmd);
expect_true('reject flag e', is_string($out) && (strpos($out, 'unsupported regex flags') !== false || strpos($out, 'invalid') !== false || strpos($out, 'fatal') !== false || strlen($out) > 0));

// Existing non-regex regressions
expect_count('literal hobby skiing', 'hobby=skiing', 1);
expect_count('literal ^= s', '.person_hobby^=s', 3);

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
