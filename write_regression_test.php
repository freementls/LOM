<?php
// Resolve paths robustly so the test can run from different CWDs.
$lom_root = __DIR__;
include $lom_root . '/O.php';

function ok($cond, $label) {
    print($label . ': ' . ($cond ? "OK" : "FAIL") . PHP_EOL);
    if(!$cond) {
        exit(1);
    }
}

/** True if alternating_writeN exists nested under simple_writeN (whitespace/attribute tolerant). */
function has_nested_alternating($code, $counter) {
    $sw = 'simple_write' . $counter;
    $aw = 'alternating_write' . $counter;
    // Allow whitespace variance in closing tags (e.g. `</tag>` vs `</tag >`).
    $pattern = '/<' . preg_quote($sw, '/') . '\b[^>]*>[\s\S]*?<' . preg_quote($aw, '/') . '\b[^>]*>\s*<\/\s*' . preg_quote($aw, '/') . '\s*>[\s\S]*?<\/\s*' . preg_quote($sw, '/') . '\s*>/i';
    return preg_match($pattern, $code) === 1;
}

$candidates = array();
$candidates[] = $lom_root . '/write_test.xml';
$cwd = getcwd();
if(is_string($cwd) && $cwd !== '') {
    $candidates[] = $cwd . '/write_test.xml';
}
if(class_exists('O')) {
    $oref = new ReflectionClass('O');
    $opath = $oref->getFileName();
    if(is_string($opath) && $opath !== '') {
        $candidates[] = dirname($opath) . '/write_test.xml';
    }
}
$fixture = false;
foreach($candidates as $candidate) {
    if(is_string($candidate) && file_exists($candidate) && is_readable($candidate)) {
        $fixture = $candidate;
        break;
    }
}
if($fixture === false) {
    foreach($candidates as $i => $candidate) {
        print("candidate[" . $i . "]=" . $candidate . " exists=" . (file_exists($candidate) ? 'true' : 'false') . " readable=" . (is_readable($candidate) ? 'true' : 'false') . "\n");
    }
    print("Unable to locate readable write_test.xml; aborting test.\n");
    exit(1);
}

$O = new O($fixture);
$O->debug(false);
$initial_code = $O->code();
if(strpos($initial_code, '<simple_write1>') === false) {
    print("Loaded fixture is missing required baseline tags (expected <simple_write1>): " . $fixture . PHP_EOL);
    exit(1);
}

for($counter = 0; $counter < 6; $counter++) {
    if($counter % 2 === 0) {
        $O->new_('<alternating_write' . $counter . '></alternating_write' . $counter . '>');
        ok(strpos($O->code(), '<alternating_write' . $counter . '></alternating_write' . $counter . '>') !== false, 'append alternating_write' . $counter);
    } else {
        $O->new_('<alternating_write' . $counter . '></alternating_write' . $counter . '>', $O->enc('simple_write' . $counter));
        ok(has_nested_alternating($O->code(), $counter), 'nested alternating_write' . $counter);
    }
}

$O->new_('<complex1>text1<complex2>text2</complex2><complex3>text3</complex3>text4</complex1>', $O->enc('alternating_write4'));
$ok_complex1 = preg_match('/<alternating_write4>\s*<complex1>text1<complex2>text2<\/complex2><complex3>text3<\/complex3>text4<\/complex1>\s*<\/alternating_write4>/s', $O->code()) === 1;
ok($ok_complex1, 'complex1 inserted into alternating_write4');

$O->context = array(); // clear so __() doesn't rely on stale query context
$O->__($O->enc('alternating_write2'), 'set text1');
$ok_set = preg_match('/<alternating_write2>\s*set text1\s*<\/alternating_write2>/s', $O->code()) === 1;
ok($ok_set, 'set alternating_write2 text');

$O->context = array();
$matches = $O->get_tagged($O->enc('complex2'));
$expected_complex2 = '<complex2>text2</complex2>';
$has_expected_complex2 = false;
foreach($matches as $m) {
    if(!isset($m[0]) || !is_string($m[0])) {
        continue;
    }
    if(trim($m[0]) === $expected_complex2) {
        $has_expected_complex2 = true;
        break;
    }
}
ok(count($matches) >= 1 && $has_expected_complex2, 'select complex2 after writes');

print("Regression sequence completed.\n");
?>
