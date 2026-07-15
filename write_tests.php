<?php
include(__DIR__ . '/O.php');

$pass_count = 0;
$fail_count = 0;

function ok($label, $condition, $details = '') {
    global $pass_count, $fail_count;
    if($condition) {
        $pass_count++;
        echo "PASS: $label" . PHP_EOL;
    }
    else {
        $fail_count++;
        echo "FAIL: $label" . PHP_EOL;
        if($details !== '') {
            echo "  $details" . PHP_EOL;
        }
    }
}

function same($label, $actual, $expected) {
    $details = 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true);
    ok($label, $actual === $expected, $details);
}

function contains_text($haystack, $needle) {
    return strpos($haystack, $needle) !== false;
}

function new_lom() {
    return new O(__DIR__ . '/write_test.xml');
}

function count_tagged($O, $selector) {
    return count($O->get_tagged($selector));
}

echo "write_tests.php" . PHP_EOL;

// 1. Fixture sanity and encoded selector reads.
$O = new_lom();
same('fixture simple_write count', count_tagged($O, $O->enc('simple_write0') . '|' . $O->enc('simple_write19')), 2);
same('fixture alternating_write4 count', count_tagged($O, $O->enc('alternating_write4')), 1);
$O->validate();

// 2. Multi-insert into repeated targets, then descendant selection.
$O = new_lom();
for($counter = 0; $counter < 6; $counter++) {
    if($counter % 2 === 0) {
        $O->new_('<alternating_write' . $counter . '></alternating_write' . $counter . '>' . PHP_EOL);
    }
    else {
        $O->new_('<alternating_write' . $counter . '></alternating_write' . $counter . '>', $O->enc('simple_write' . $counter));
    }
}
same('alternating_write2 duplicated after mixed new_', count_tagged($O, $O->enc('alternating_write2')), 2);
same('alternating_write4 duplicated after mixed new_', count_tagged($O, $O->enc('alternating_write4')), 2);
$O->new_('<complex1>text1<complex2>text2</complex2><complex3>text3</complex3>text4</complex1>', $O->enc('alternating_write4'));
same('complex1 count after nested multi-write', count_tagged($O, 'complex1'), 2);
same('complex2 count after nested multi-write', count_tagged($O, 'complex2'), 2);
same('complex3 count after nested multi-write', count_tagged($O, 'complex3'), 2);
$O->validate();

// 3. Warm context, then set text on repeated targets.
$O = new_lom();
for($counter = 0; $counter < 6; $counter++) {
    if($counter % 2 === 0) {
        $O->new_('<alternating_write' . $counter . '></alternating_write' . $counter . '>' . PHP_EOL);
    }
    else {
        $O->new_('<alternating_write' . $counter . '></alternating_write' . $counter . '>', $O->enc('simple_write' . $counter));
    }
}
$aw2_before = $O->get_tagged($O->enc('alternating_write2'));
same('warm context alternating_write2 count', count($aw2_before), 2);
$O->__($O->enc('alternating_write2'), 'set text1');
$aw2_after = $O->get_tagged($O->enc('alternating_write2'));
same('alternating_write2 count after set', count($aw2_after), 2);
ok('alternating_write2 first contains set text1', contains_text($aw2_after[0][0], 'set text1'));
ok('alternating_write2 second contains set text1', contains_text($aw2_after[1][0], 'set text1'));
$O->validate();

// 4. Self-closing replacement should not eat following text.
$O = new_lom();
$O->new_('<complex1>some complex1 debug text</complex1>');
$O->__('complex1', '<selfclosing att="value" />');
$selfclosing = $O->get_tagged('selfclosing');
same('selfclosing replacement count', count($selfclosing), 1);
ok('selfclosing replacement exactness', $selfclosing && preg_match('/<selfclosing att="value"\s*\/>$/', $selfclosing[0][0]) === 1, isset($selfclosing[0][0]) ? $selfclosing[0][0] : 'missing selfclosing result');
$O->validate();

// 5. Nested replacement containing self-closing tag and mixed text.
$O = new_lom();
$O->new_('<complex3>some complex3 debug text</complex3>');
$O->__('complex3', '<a><b>1</b><c>2<d></d>3</c><M><selfclosing att="value" />umm</M></a>');
same('complex3 replaced with valid nested markup count', count_tagged($O, 'complex3'), 1);
same('nested d count after complex3 replace', count_tagged($O, 'd'), 1);
same('nested M count after complex3 replace', count_tagged($O, 'M'), 1);
$expanded_m = $O->get_tagged('M');
ok('mixed text preserved after self-closing in M', $expanded_m && contains_text($expanded_m[0][0], 'umm'), isset($expanded_m[0][0]) ? $expanded_m[0][0] : 'missing M result');
$O->validate();

// 6. EOF write then descendant selection.
$O = new_lom();
$O->new_('<eof_parent><eof_child>done</eof_child></eof_parent>');
same('eof_parent count', count_tagged($O, $O->enc('eof_parent')), 1);
same('eof_child count', count_tagged($O, $O->enc('eof_child')), 1);
$eof_desc = $O->get_tagged($O->enc('eof_parent') . '_' . $O->enc('eof_child'));
same('eof descendant selection count', count($eof_desc), 1);
ok('eof descendant text preserved', $eof_desc && contains_text($eof_desc[0][0], 'done'), isset($eof_desc[0][0]) ? $eof_desc[0][0] : 'missing eof descendant');
$O->validate();

// 7. Variable tracking across attribute and text writes.
$O = new_lom();
$O->new_('<orange></orange>');
$O->set_variable('my_orange', 'orange');
$before_var = $O->get_variable('my_orange');
$O->set_attribute('taste', 'sweet', 'orange');
$mid_var = $O->get_variable('my_orange');
$O->__('orange', 'citrus');
$after_var = $O->get_variable('my_orange');
ok('variable exists before writes', $before_var !== false, var_export($before_var, true));
ok('variable survives set_attribute', $mid_var !== false, var_export($mid_var, true));
ok('variable survives set text', $after_var !== false, var_export($after_var, true));
$orange = $O->get_tagged('orange');
ok('orange contains citrus after set', $orange && contains_text($orange[0][0], 'citrus'), isset($orange[0][0]) ? $orange[0][0] : 'missing orange result');
$O->validate();

// 8. Delete child after context warmup, then rewrite parent.
$O = new_lom();
$O->new_('<bubba>aaa</bubba>');
$O->__('bubba', 'ccc<ill>ddd<healthy>eee</healthy></ill>fff<orange />ggg<grapes /><uppity><rouge type="color">hhh<red></red>iii</rouge>jjj</uppity>');
same('healthy count before delete', count_tagged($O, 'healthy'), 1);
$ill_warm = $O->get_tagged('ill');
same('ill count before delete', count($ill_warm), 1);
$O->delete('healthy');
same('healthy count after delete', count_tagged($O, 'healthy'), 0);
$O->__('ill', '<unwell>contextually</unwell>');
same('unwell count after parent rewrite', count_tagged($O, 'unwell'), 1);
$O->validate();

// 9. Repeated writes into same selector should preserve exact count.
$O = new_lom();
$O->new_('<repeat_target></repeat_target>');
$O->new_('<inner>1</inner>', $O->enc('repeat_target'));
$O->new_('<inner>2</inner>', $O->enc('repeat_target'));
$O->new_('<inner>3</inner>', $O->enc('repeat_target'));
same('repeat_target count', count_tagged($O, $O->enc('repeat_target')), 1);
same('inner count after repeated writes to same target', count_tagged($O, 'inner'), 3);
$O->validate();

// 10. Underscore-containing tag names must work with enc during writes and reads.
$O = new_lom();
$O->new_('<name_with_underscore><child_node>ok</child_node></name_with_underscore>');
same('encoded underscore parent count', count_tagged($O, $O->enc('name_with_underscore')), 1);
same('encoded underscore child count', count_tagged($O, $O->enc('name_with_underscore') . '_' . $O->enc('child_node')), 1);
$O->__($O->enc('child_node'), 'still ok');
$child = $O->get_tagged($O->enc('child_node'));
ok('encoded child text after set', $child && contains_text($child[0][0], 'still ok'), isset($child[0][0]) ? $child[0][0] : 'missing child_node result');
$O->validate();

// 11. Direct-chain selectors where a tag-only piece is followed by an attribute-filtered piece
//     must resolve through the fractal path. Previously tag_match passed the entire
//     comparison-operator sub-array to O::compare(), which returned false for every attribute
//     equality test, so chains like chainroot_chaingroup@name=alpha silently matched zero nodes.
$O = new_lom();
$O->new_('<chainroot></chainroot>');
$O->new_('<chaingroup name="alpha"><chaininner name="left"></chaininner><chaininner name="right"></chaininner></chaingroup>', 'chainroot');
$O->new_('<chaingroup name="beta"><chaininner name="left"></chaininner></chaingroup>', 'chainroot');
same('direct-chain tag-then-attr root_group@name=alpha', count_tagged($O, 'chainroot_chaingroup@name=alpha'), 1);
same('direct-chain tag-then-attr root_group@name=beta', count_tagged($O, 'chainroot_chaingroup@name=beta'), 1);
same('direct-chain tag-then-attr root_group@name=missing', count_tagged($O, 'chainroot_chaingroup@name=missing'), 0);
same('direct-chain three pieces hits left in alpha', count_tagged($O, 'chainroot_chaingroup@name=alpha_chaininner@name=left'), 1);
same('direct-chain three pieces hits right in alpha', count_tagged($O, 'chainroot_chaingroup@name=alpha_chaininner@name=right'), 1);
same('direct-chain three pieces hits left in beta', count_tagged($O, 'chainroot_chaingroup@name=beta_chaininner@name=left'), 1);
same('direct-chain three pieces misses right in beta', count_tagged($O, 'chainroot_chaingroup@name=beta_chaininner@name=right'), 0);
$O->validate();

echo PHP_EOL . 'Summary: ' . $pass_count . ' passed, ' . $fail_count . ' failed.' . PHP_EOL;
if($fail_count > 0) {
    exit(1);
}
?>
