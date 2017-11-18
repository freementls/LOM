<?php

include('O.php');
$O = new O('test.xml');
print('should be false since underscore is a special character in the query string: ');var_dump($O->_('big_container'));
print('so to find a tag with an underscore in it, that part of the query string must be encoded (should be the big_container LOM_array): ');var_dump($O->_($O->enc('big_container')));
print('all persons (should be an array of the 5 persons): ');var_dump($O->_('person'));
print('all persons named sally (should be an array of the 3 sallys) (. indicates that you are interested in a tag other than the last): ');var_dump($O->_('.person_name=sally'));
print('lastname of a parent with an invalid query string since the value of the name tag is unspecified (should be false) (. indicates that you are interested in a tag other than the last): ');var_dump($O->_('lastname', '.person_name='));
print('all things with a lastname (should be an array of the 5 persons since we\'re being less specific and so have to go to a broader context) (* means any tag): ');var_dump($O->_('.*_lastname'));
print('all lastnames (should be an array of the 3 sallys\' lastnames since they were last mentioned): ');var_dump($O->_('lastname'));
print('all things with a lastname in the big_container (should be an array of the 5 persons): ');var_dump($O->_('.*_lastname', $O->_($O->enc('big_container'))));
print('tag2 with name offspring (should be tag2 array that has the offspring deep one) (__ means offspring instead of _ meaning child): ');var_dump($O->_('.tag2__name'));
print('lastname of second sally (should be mott): ');var_dump($O->_('lastname', $O->_('.person_name=sally')[1]));
print('third person (should be array of sally supado): ');var_dump($O->_('person[2]'));
print('hobby (should be world domination by contextual querying since third person was last mentioned): ');var_dump($O->_('hobby'));
print('sallys aged 16 (should be an array of 2 sallys): ');var_dump($O->_('.person@age=16_name=sally'));
print('hobby of person aged 16 with blue eyes (should be skiing): ');var_dump($O->_('person@age=16@' . $O->enc('eye_color') . '=blue_hobby'));
print('lastname (should be array of kellerman and mott by contextual querying since these are the persons with lastnames last mentioned): ');var_dump($O->_('lastname'));
print('person with name tom and lastname blabbo (should be an array of tom blabbo): ');$person = $O->_('.person_name=tom&lastname=blabbo');var_dump($person);
print('person with name sally and lastname blabbo (should be false): ');var_dump($O->_('.person_name=sally&lastname=blabbo'));
print('age of $person variable (should be 999): ');var_dump($O->get_attribute('age', $person));
print('hobby of $person variable (should be sleeping): ');var_dump($O->_('hobby', $person));
print('setting hobby to waking up (should say waking up): ');var_dump($O->set('hobby', 'waking up'));
print('hobby (should be waking up): ');var_dump($O->_('hobby'));
// reset the change after testing
$O->set('hobby', 'sleeping');
print('persons named mike or sally (there should be 4): ');var_dump($O->_('.person_name=mike|.person_name=sally'));
//$O->save_LOM_to_file('test.xml');

?>