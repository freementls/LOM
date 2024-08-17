<?php

include('O.php');
$O = new O('test.xml');

print('selecting using tagname index (should be ' . htmlentities('<a id="8"><b><c>tagvalue3</c></b></a>') . '): ');var_dump($O->get_tagged('.a[8]_b_c'));
print('selecting using tagvalue index (should be ' . htmlentities('<a id="5"><b><c att2="attvalue4">tagvalue2</c></b></a>') . '): ');var_dump($O->get_tagged('.a_b_c=tagvalue2[2]'));
print('selecting using attributes index (should be ' . htmlentities('<a id="6"><b><c att2="attvalue5">tagvalue3</c></b></a>') . '): ');var_dump($O->get_tagged('.a_b_c@att2[1]', $O->enc('big_container')));
//$O->save_LOM_to_file('test.xml');
$O->dump_total_time_taken();

?>
