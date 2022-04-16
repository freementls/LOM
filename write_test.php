<?php

include('O.php');
$O = new O('write_test.xml');
// a bunch of write stuff, like writing one hundred times, or in the same place, or nested one hundred times; make it bullet proof then merge into main test file

$O->debug();
/*$counter = 0;
while($counter < 1) {
	$O->new_('<simple_write></simple_write>
');
	$counter++;
}*/
/*$counter = 0;
//$string = '';
while($counter < 100) {
	//$string .= 'aaaaabbbbb';
	$O->new_('<a>1</a>
');
	$counter++;
}*/
//$O->new_('<taggo>' . $string . '</taggo>');
/*
$counter = 0;
while($counter < 5) {
	$O->new_('<simple_write' . $counter . '></simple_write' . $counter . '>
');
	$counter++;
}*/
/*$counter = 0;
$last_new = false;
while($counter < 5) {
	$last_new = $O->new_('<nested_write' . $counter . '></nested_write' . $counter . '>', $last_new);
	$counter++;
}
// also test unnumbered nested writes once nested ones are working
$counter = 0;
$last_new = $O->enc('simple_write2');
while($counter < 5) {
	$last_new = $O->new_('<nested_write></nested_write>', $last_new);
	$counter++;
}*/
$counter = 0;
while($counter < 6) {
	if($counter % 2 === 0) {
		$O->new_('<alternating_write' . $counter . '></alternating_write' . $counter . '>
');
	} else {
		$O->new_('<alternating_write' . $counter . '></alternating_write' . $counter . '>', $O->enc('simple_write' . $counter));
	}
	$counter++;
}
// test writing out of nowhere into a nested tag
/*$O->new_('<into_the_nest1></into_the_nest1>', $O->enc('nested_write3'));
$into_the_nest2 = $O->new_('<into_the_nest2>some text</into_the_nest2>', $O->enc('nested_write2'));
print('$into_the_nest2: ');var_dump($into_the_nest2);
$O->new_('<into_the_nest3>some more text</into_the_nest3>', $into_the_nest2[0][1] + 20); // write right into the middle of the text
*/
// + check for places where $offset_depths instead of defaulting to $this->offset_depths when expand() is called
// - do a simple check that lazy and greedy do what they should (probably grab whitespace at the end?) in expand(). no longer used
$O->new_('<complex1>text1<complex2>text2</complex2><complex3>text3</complex3>text4</complex1>', $O->enc('alternating_write4'));
//$O->new_('<alternating_write2>some debug text</alternating_write2>'); // debug
$O->__($O->enc('alternating_write2'), 'set text1');
/*$O->__($O->enc('alternating_write2'), 'set text2');
$O->__($O->enc('alternating_write2'), 'set text222');
$O->__($O->enc('alternating_write2'), 'set te2');
$O->__($O->enc('alternating_write2'), 'set text3');
//print('$O->offset_depths(): ');var_dump($O->offset_depths());
$O->__('complex2', 'new complex2');
//print('$O->offset_depths(): ');var_dump($O->offset_depths());
$O->__('complex3', '<nesty testy="yep">nesty text</nesty>');
//print('$O->offset_depths(): ');var_dump($O->offset_depths());*/

$O->new_('<complex1>some complex1 debug text</complex1>'); // debug
$O->__('complex1', '<selfclosing att="value" />');
$O->new_('<complex2>some complex2 debug text</complex2>'); // debug
$O->new_('<complex3>some complex3 debug text</complex3>'); // debug
$O->__('complex3', '<a><b>1</b><c>2<d></d>3</c><M><selfclosing att="value" />umm</M>');
// some new text tests, complex news, text in the middle of other text, new tags with text, also changing text, changing attributes, adding attributes, self-closing tags
$O->new_('<bubba>aaa</bubba>');
$O->__('bubba', 'bbb');
$O->__('bubba', 'ccc<ill>ddd<healthy>eee</healthy></ill>fff<orange />ggg<grapes /><uppity><rouge type="color">hhh<red></red>iii</rouge>jjj</uppity>');
$O->__('grapes', '<cherries>five</cherries>');
$O->set_attribute('type', 'farbe', 'rouge');
$O->new_attribute('emotion', 'true', 'uppity');
$O->set_variable('my_orange', 'orange');
print('my_orange: ');var_dump($O->get_variable('my_orange'));
$O->set_attribute('taste', 'sweet', 'orange');
$O->set_attribute('color', 'oran ge', 'orange');
print('my_orange: ');var_dump($O->get_variable('my_orange'));

$O->set_variable('my_tricky', 'healthy|' . $O->enc('alternating_write2'));
print('my_tricky1: ');var_dump($O->get_variable('my_tricky'));
$O->__('healthy', 'very healthy');
print('my_tricky2: ');var_dump($O->get_variable('my_tricky'));
$O->__($O->enc('alternating_write2'), 'tricksome write');
print('my_tricky3: ');var_dump($O->get_variable('my_tricky'));

$O->set_variable('my_easy', $O->enc('alternating_write0'));
print('my_easy1: ');var_dump($O->get_variable('my_easy'));
$O->set_attribute('how', 'very', $O->enc('alternating_write0'));
print('my_easy2: ');var_dump($O->get_variable('my_easy'));
$O->set_attribute('now', 'brown cow', $O->enc('alternating_write0'));
print('my_easy3: ');var_dump($O->get_variable('my_easy'));
$O->set_attribute('how', 'kinda', $O->enc('alternating_write0'));
print('my_easy4: ');var_dump($O->get_variable('my_easy'));
$O->__($O->enc('alternating_write0'), 'easy mode');
print('my_easy5: ');var_dump($O->get_variable('my_easy'));

print('my_orange, my_tricky, my_easy: ');var_dump($O->get_variable('my_orange'), $O->get_variable('my_tricky'), $O->get_variable('my_easy'));
$O->clear_variable(array('my_orange', 'my_tricky', 'my_easy'));
print('my_orange, my_tricky, my_easy: ');var_dump($O->get_variable('my_orange'), $O->get_variable('my_tricky'), $O->get_variable('my_easy'));

// living variables useful, dddalthy, clean up debug

//print('$O->offset_depths(): ');var_dump($O->offset_depths());
//$b = $O->get_tagged('b');
//print('$O->offset_depths(): ');var_dump($O->offset_depths());
//print('$b: ');var_dump($b);
//$O->replace($b[0][0], '<newb>one</newb>', $b[0][1]); // replace isn't meant to be used externally
// + proper context updating for new_() not written yet!
// parent_node only obsolete??
// this->variables... test it
// check that context is properly updated for this like set_attribute
// add/change mixed content
// comment some debug stuff out
/* try all
context structure
	0 => selector
	1 => parent
	2 => matches array
	3 => offset depths
	*/

print('$O->code(): ');$O->var_dump_full($O->code());
print('$O->context(): ');$O->var_dump_full($O->context());
$O->_('bubba');
print('$O->code() 1: ');$O->var_dump_full($O->code());
$O->_('ill');
print('$O->code() 2: ');$O->var_dump_full($O->code());
$O->_('healthy');
print('$O->code() 3: ');$O->var_dump_full($O->code());
$O->delete('healthy');
print('$O->code() 4: ');$O->var_dump_full($O->code());
print('$O->context(): ');$O->var_dump_full($O->context());
$O->__('ill', '<unwell>contextually</unwell>');
print('$O->context(): ');$O->var_dump_full($O->context());
$O->set_attribute('newatt', 'newval', 'selfclosing');
print('$O->code() 5: ');$O->var_dump_full($O->code());
$O->validate();
//$O->save_LOM_to_file('write_test.xml');
$O->dump_total_time_taken();

?>