<?php

include('O.php');
$O = new O('test.xml');
print('_ is a special character in the query string so searching for a tagname with an underscore in it will create a conflict (should be 0 matches): ');var_dump($O->_('big_container'));
print('to find a tag with an underscore in its name, that part of the query string must be encoded (should be an array with 1 match for the big_container): ');var_dump($O->_($O->enc('big_container')));
print('all persons (should be an array of the 5 persons): ');var_dump($O->_('person'));
print('using a dot in the query string indicates that you are interested in a tag other than the last tag; all persons named sally (should be an array of the 3 sallys): ');var_dump($O->_('.person_name=sally'));
print('lastnames (should be an array of the 3 sallys\' lastnames since they were last mentioned): ');var_dump($O->_('lastname'));
print('lastname of a person with a name of zero length (should be 0 matches): ');var_dump($O->_('lastname', '.person_name='));
print('__ in the query string instead of _ means offspring instead of child; tag2 with name offspring (should be an array with 1 match for tag2 that has the offspring deep one): ');var_dump($O->_('.tag2__name'));
print('* is a special character in the query string meaning any tag; all things with a lastname (should be an array of the 5 persons since we\'re being less specific and so have to go to a broader context): ');var_dump($O->_('.*_lastname'));
print('all things with a lastname in the big_container (should be an array of the 5 persons): ');var_dump($O->_('.*_lastname', $O->_($O->enc('big_container'))));
print('| in the query string acts as a logical or to put more than one query string together; persons named mike or sally (should be an array with 4 matches): ');var_dump($O->_('.person_name=mike|.person_name=sally'));
print('lastname of second sally (should be mott): ');var_dump($O->_('lastname', $O->_('.person_name=sally')[1]));
print('third person (should be an array with 1 match for sally supado): ');var_dump($O->_('person[2]', $O->enc('big_container'))); // still may be getting too many results when querying within the second sally from the previous line (would should be getting 0)
print('hobby (should be world domination by contextual querying since third person was last mentioned): ');var_dump($O->_('hobby'));
print('@ in the query string refers to an attribute; sallys aged 16 (should be an array of 2 sallys): ');var_dump($O->_('.person@age=16_name=sally'));
print('persons with an age attribute but without the attribute value specified (should be an array of 2 sallys and tom blabbo): ');var_dump($O->_('person@age', $O->enc('big_container')));
print('hobby of person aged 16 with blue eyes (should be skiing): ');var_dump($O->_('person@age=16@' . $O->enc('eye_color') . '=blue_hobby'));
print('lastname (should be array of kellerman and mott and blabbo by contextual querying since these are the persons with lastnames last mentioned): ');var_dump($O->_('lastname'));
print('saving person with name sally and lastname mott to a living variable named live_var (should be true): ');var_dump($O->set_variable('live_var', $O->_('.person_name=sally&lastname=mott')));
print('hobby of live_var (should be swimming): ');var_dump($O->_('hobby', $O->get_variable('live_var')));
print('& in the query string acts as a logical and to select by more than one tag; person with name tom and lastname blabbo (should be an array with 1 match for tom blabbo): ');$person = $O->_('.person_name=tom&lastname=blabbo');var_dump($person);
print('person with name sally and lastname blabbo (should be 0 matches): ');var_dump($O->_('.person_name=sally&lastname=blabbo'));
print('age of $person variable (should be 999): ');var_dump($O->get_attribute('age', $person));
print('hobby of $person variable (should be sleeping): ');var_dump($O->_('hobby', $person));
print('setting hobby to waking up (should be true): ');var_dump($O->set('hobby', 'waking up'));
print('hobby (should be waking up): ');var_dump($O->_('hobby'));
// reset the change after testing
//$O->set('hobby', 'sleeping');
print('setting hobby of person with lastname mott to breathing (should be an array with 1 match for sally mott): ');var_dump($O->set('hobby', 'breathing', '.person_lastname=mott'));
print('hobby of person with name sally and lastname kellerman (should be skiing): ');var_dump($O->_('hobby', '.person_name=sally&lastname=kellerman'));
print('hobby of live_var (should be breathing): ');var_dump($O->_('hobby', $O->get_variable('live_var')));
print('adding a new person (should be an array with 1 match for santa klaus): ');var_dump($O->new_('<person age="33" beard_color="white"><name>santa</name><lastname>klaus</lastname><hobby>making presents</hobby></person>', $O->enc('big_container')));
print('deleting a person (should be true): ');var_dump($O->delete('.person_name=santa'));
print('selecting using tagname index (should be ' . htmlentities('<a id="8"><b><c>tagvalue3</c></b></a>') . '): ');var_dump($O->get_tagged('.a[8]_b_c'));
print('selecting using tagvalue index (should be ' . htmlentities('<a id="5"><b><c att2="attvalue4">tagvalue2</c></b></a>') . '): ');var_dump($O->get_tagged('.a_b_c=tagvalue2[2]'));
print('selecting using attributes index (should be ' . htmlentities('<a id="6"><b><c att2="attvalue5">tagvalue3</c></b></a>') . '): ');var_dump($O->get_tagged('.a_b_c@att2[1]', $O->enc('big_container')));
print('selecting using an attributes index that is too high (should be 0 matches): ');var_dump($O->get_tagged('.a_b_c@att1=attvalue1[6]'));
//$O->save_LOM_to_file('test.xml');
$O->dump_total_time_taken();

?>