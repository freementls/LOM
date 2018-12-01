<?php

// LOM: Logical Object Model or Living Object Model if you're feeling more facetious

// want a command to do, for instance, this:
//preg_match('/<items>(.*?)<\/items>/is', $player_string, $items_matches);
//$items_string = $items_matches[0];

// ...... combining tidyer_DOM, DOM, OM, XPath, preg ......

// maybe a short description of what a logical object is; such an object is defined when what it is is separated from what it is not; this is how logic works. on its own this may not be very interesting but when such logical 
// objects can interact it becomes interesting. the facility of this code comes from the basic list of operations used which allows a shorthand for these. also worth mentioning that logical objects are hierarchically structured
// so that they are still caught in the matrix, but this is nothing new for language or computers.

// it was noticed during writing this code that it has a similar goal to autocomplete in a browser's address bar, with the exception that the address bar only does "explicit" queries and not variable ones

// worth noting that XML files being processed by LOM shouldn't have "true" or "false" in tags since LOM is unable to discern these properly when tagvalues in in queries as cast as strings?
// also, if looking in the proper place for something that doesn't exist and not finding it then brings us to an overbroad context. I guess it's up to the user to manage this
// need some way for the code to detect when it's going overbroad. like "there's nothing here but if there was a I would have detected it, based on how tags are structured in a broader context"
// or if there's a partial result then take the ultimate result even if it's empty

// could keep "living" variables that change when the LOM changes

class O {

function __construct($file_to_parse, $use_context = true, $array_blocks = false, $array_inline = false) {	
	$this->O_initial_time = O::getmicrotime();
	define('DS', DIRECTORY_SEPARATOR);
	$this->var_display_max_depth = 6;
	$this->var_display_max_children = 8;
	ini_set('xdebug.var_display_max_depth', $this->var_display_max_depth);
	ini_set('xdebug.var_display_max_children', $this->var_display_max_children);
	$this->tagname_regex = '[\w\-:]+';
	$this->attributename_regex = '[\w\-:]+';
	//$this->LOM = array();
	$this->context = array();
	$this->variables = array();
	$this->use_context = $use_context;
	// documentation: context entries have the format: [0] = selector, [1] = parent_node, [2] = start indices, [3] = selector results
	// selector results are kept up to date while parent nodes are not for the reason that parent nodes are static arrays used outside of the object while selector results are dynamic to provide contextual results
	// these classifications may apply to HTML but not to the XML we are using
	if($array_blocks === false) {
		$this->array_blocks = array();
	} else {
		$this->array_blocks = $array_blocks;
	}
	$this->array_in_between = array();
	if($array_inline === false) {
		$this->array_inline = array();
	} else {
		$this->array_inline = $array_inline;
	}
	// not for pretty printing; for syntax
	$this->array_self_closing = array();
	//$files_to_parse = func_get_args();
	$this->array_delayed_delete = array();
	$this->array_delayed_new = array();
	$this->file = $file_to_parse;
	if(file_exists($file_to_parse)) {
		$this->code = file_get_contents($file_to_parse);
	} else {
		$this->code = $file_to_parse;
	}
	//$this->LOM = O::generate_LOM($this->code); // only generate_LOM as needed; it's possible that only simple preg operations on $this->code are needed
	//print('$this->code in __construct: ');var_dump($this->code);
	if(strpos($this->code, '/>') !== false) { // self-closing tag
		$this->must_check_for_self_closing = true;
		//print('$this->code: ');O::var_dump_full($this->code);
		//O::fatal_error('found self-closing but code to handle this is not written yet.');
	}
	if(strpos($this->code, '<![CDATA[') !== false) { // non-parsed character data
		$this->must_check_for_non_parsed_character_data = true;
		//print('$this->code: ');O::var_dump_full($this->code);
		//O::fatal_error('found non-parsed character data but code to handle this is not written yet.');
	}
	if(strpos($this->code, '<!--') !== false) { // comment
		$this->must_check_for_comment = true;
		//print('$this->code: ');O::var_dump_full($this->code);
		//O::fatal_error('found comment but code to handle this is not written yet.');
	}
	if(strpos($this->code, '<?') !== false) { // programming instruction
		$this->must_check_for_programming_instruction = true;
		//print('$this->code: ');O::var_dump_full($this->code);
		//O::fatal_error('found programming instruction but code to handle this is not written yet.');
	}
	if(strpos($this->code, '<%') !== false) { // ASP
		$this->must_check_for_ASP = true;
		//print('$this->code: ');O::var_dump_full($this->code);
		//O::fatal_error('found ASP but code to handle this is not written yet.');
	}
	//print('$this->must_check_for_self_closing, $this->must_check_for_non_parsed_character_data, $this->must_check_for_comment, $this->must_check_for_programming_instruction, $this->must_check_for_ASP: ');var_dump($this->must_check_for_self_closing, $this->must_check_for_non_parsed_character_data, $this->must_check_for_comment, $this->must_check_for_programming_instruction, $this->must_check_for_ASP);
	//$this->zero_offsets = array();
	O::set_offset_depths();
}

function set_offset_depths() {
	//print('$this->code: ');var_dump($this->code);
	$depth = 0;
	$this->offset_depths = array();
	/*preg_match_all('/</', $this->code, $matches, PREG_OFFSET_CAPTURE);
	foreach($matches[0] as $index => $value) {
		$this->offset_depths[$value[1]] = $depth;
		if($this->code[$value[1] + 1] === '/') { // closing tag
			$depth--;
		} else { // opening tag
			$depth++;
		}
	}*/
	// string-based instead of preg code is something like ~15% faster
	$position = -1;
	while(($position = strpos($this->code, '<', $position + 1)) !== false) {
		//print('$position: ');var_dump($position);
		$this->offset_depths[$position] = $depth;
		if($this->code[$position + 1] === '/') { // closing tag
			//print('closing tag at position: ' . $position . '<br>');
			$depth--;
		} elseif($this->must_check_for_self_closing && $this->code[strpos($this->code, '>', $position + 1) - 1] === '/') { // self-closing tag
			//print('self-closing tag at position: ' . $position . '<br>');
		} elseif($this->must_check_for_non_parsed_character_data && substr($this->code, $position + 1, 8) === '![CDATA[') { // non-parsed character data
			//print('non-parsed character data at position: ' . $position . '<br>');
		} elseif($this->must_check_for_comment && substr($this->code, $position + 1, 3) === '!--') { // comment
			//print('comment at position: ' . $position . '<br>');
		} elseif($this->must_check_for_programming_instruction && $this->code[$position + 1] === '?') { // programming instruction
			//print('programming instruction at position: ' . $position . '<br>');
		} elseif($this->must_check_for_ASP && $this->code[$position + 1] === '%') { // ASP
			//print('ASP at position: ' . $position . '<br>');
		} else { // opening tag
			//print('opening tag at position: ' . $position . '<br>');
			$depth++;
		}
	}
	//print('$this->offset_depths at the end of set_offset_depths: ');O::var_dump_full($this->offset_depths);exit(0);
}

function get_offset_depths_of_selector_matches($selector_matches) {
	$offset_depths = array();
	foreach($selector_matches as $index => $value) {
		$offset_depths[] = O::get_offset_depths($value[0], $value[1], O::depth($value[1]));
	}
	return $offset_depths;
}

function get_offset_depths($code = false, $offset_to_add = 0, $depth_to_add = 0) {
	if($code === false) {
		$code = $this->code;
	}
	if($code === $this->code) {
		return false;
	}
	$depth = 0;
	$offset_depths = array();
	$position = -1;
	while(($position = strpos($code, '<', $position + 1)) !== false) {
		//print('$position, $offset_to_add, $depth, $depth_to_add in get_offset_depths loop: ');var_dump($position, $offset_to_add, $depth, $depth_to_add);
		$offset_depths[$position + $offset_to_add] = $depth + $depth_to_add;
		if($code[$position + 1] === '/') { // closing tag
			$depth--;
		} elseif($this->must_check_for_self_closing && ($closing_angle_bracket_position = strpos($code, '>', $position + 1)) && $code[$closing_angle_bracket_position - 1] === '/') { // self-closing tag
		
		} elseif($this->must_check_for_non_parsed_character_data && substr($code, $position + 1, 8) === '![CDATA[') { // non-parsed character data
		
		} elseif($this->must_check_for_comment && substr($code, $position + 1, 3) === '!--') { // comment
		
		} elseif($this->must_check_for_programming_instruction && $code[$position + 1] === '?') { // programming instruction
		
		} elseif($this->must_check_for_ASP && $code[$position + 1] === '%') { // ASP
			
		} else { // opening tag
			$depth++;
		}
		//print('$code, strpos($code, \'<\', $position + 1) in get_offset_depths loop: ');var_dump($code, strpos($code, '<', $position + 1));
	}
	//print('$code, $offset_to_add, $depth_to_add, $offset_depths in get_offset_depths: ');var_dump($code, $offset_to_add, $depth_to_add, $offset_depths);
	return $offset_depths;
}

function adjust_offset_depths($offset, $offset_adjust) {
	if($offset_adjust == 0) {
		return true;
	}
	//print('$offset, $offset_adjust in adjust_offset_depths: ');var_dump($offset, $offset_adjust);
	//print('$this->offset_depths before adjustment in adjust_offset_depths: ');O::var_dump_full($this->offset_depths);
	//if($offset_adjust > 0) {
		//print('key($this->offset_depths) before end: ');var_dump(key($this->offset_depths));
		$depth = end($this->offset_depths);
		//print('key($this->offset_depths) after end: ');var_dump(key($this->offset_depths));
		$prev_result = true;
		while($prev_result !== false) {
			//print('$prev_result: ');var_dump($prev_result);
			if(key($this->offset_depths) >= $offset) {
				//print('her278487<br>');
				$this->offset_depths[key($this->offset_depths) + $offset_adjust] = current($this->offset_depths);
				unset($this->offset_depths[key($this->offset_depths)]);
			} else {
				//print('her278488<br>');
				break;
			}
			//print('her278489<br>');
			$prev_result = prev($this->offset_depths);
			//print('$prev_result2: ');var_dump($prev_result);
		}
		foreach($this->context as $context_index => $context_value) {
			foreach($this->context[$context_index][3] as $context3_index => $context3_value) {
				if($context3_value === false) { // false here means use $this->offset_depths
					continue;
				}
				/*$depth = end($this->context[$context_index][3][$context3_index]);
				if($offset > key($this->context[$context_index][3][$context3_index])) { // this context entry is unaffected
					continue 2; // assumes that the context3 entries are in offset order
				}
				$prev_result = true;
				while($prev_result !== false) {
					if(key($this->context[$context_index][3][$context3_index]) >= $offset) {
						$this->context[$context_index][3][$context3_index][key($this->context[$context_index][3][$context3_index]) + $offset_adjust] = current($this->context[$context_index][3][$context3_index]);
						unset($this->context[$context_index][3][$context3_index][key($this->context[$context_index][3][$context3_index])]);
					} else {
						break;
					}
					$prev_result = prev($this->context[$context_index][3][$context3_index]);
				}
				ksort($this->context[$context_index][3][$context3_index]);*/
				// making a working array maybe something like 20% faster
				$context_depth_array = $this->context[$context_index][3][$context3_index];
				$depth = end($context_depth_array);
				if($offset > key($context_depth_array)) { // this context entry is unaffected
					continue 2; // assumes that the context3 entries are in offset order
				}
				$prev_result = true;
				while($prev_result !== false) {
					if(key($context_depth_array) >= $offset) {
						$context_depth_array[key($context_depth_array) + $offset_adjust] = current($context_depth_array);
						unset($context_depth_array[key($context_depth_array)]);
					} else {
						break;
					}
					$prev_result = prev($context_depth_array);
				}
				ksort($context_depth_array);
				$this->context[$context_index][3][$context3_index] = $context_depth_array;
			}
		}
	/*} else {
		foreach($this->offset_depths as $depth_offset => $depth) {
			if($depth_offset >= $offset) {
				$this->offset_depths[$depth_offset + $offset_adjust] = $depth;
				unset($this->offset_depths[$depth_offset]);
			}
		}
		foreach($this->context as $context_index => $context_value) {
			$depth = end($this->context[$context_index][3]);
			if($offset > key($this->context[$context_index][3])) { // this context entry is unaffected
				continue;
			}
		}
	}*/
	ksort($this->offset_depths);
	// debug
	/*$last_offset = 0;
	foreach($this->offset_depths as $offset => $depth) {
		print($offset . ': ' . htmlentities(substr($this->code, $last_offset, $offset - $last_offset)) . '<br>');
		if($this->code[$offset] !== '<') {
			print('$this->code, $this->offset_depths, $offset, $depth: ');O::var_dump_full($this->code, $this->offset_depths, $offset, $depth);
			O::fatal_error('$this->offset_depths should never point to anything other than &lt;');
		}
		$last_offset = $offset;
	}*/
	//print('$this->offset_depths after adjustment in adjust_offset_depths: ');O::var_dump_full($this->offset_depths);
	return true;
}

function _($selector, $matching_array = false) { // alias
	return O::get($selector, $matching_array);
}

function g($selector, $matching_array = false) { // alias
	return O::get($selector, $matching_array);
}

function g_($selector, $matching_array = false) { // alias
	return O::get($selector, $matching_array);
}

function __($selector, $new_value = 0, $parent_node = false) { // alias
	return O::set($selector, $new_value, $parent_node);
}

function s($selector, $new_value = 0, $parent_node = false) { // alias
	return O::set($selector, $new_value, $parent_node);
}

function s_($selector, $new_value = 0, $parent_node = false) { // alias
	return O::set($selector, $new_value, $parent_node);
}

function get_tagged($selector, $matching_array = false, $add_to_context = true, $ignore_context = false, $parent_node_only = false) {
	// cleverly, when get is used and returns a non-tagged result we still put the tagged result into the context (if applicable) (which possesses more general usefulness)
	//print('$selector, $matching_array, $add_to_context, $ignore_context, $parent_node_only in get_tagged: ');var_dump($selector, $matching_array, $add_to_context, $ignore_context, $parent_node_only);
	//return O::preg_select($selector);
	return O::get($selector, $matching_array, $add_to_context, $ignore_context, $parent_node_only, true);
}

function context_array($LOM_array = false) {
	if($LOM_array === false || (is_array($LOM_array) && sizeof($LOM_array) === 0)) {
		return false;
	}
	if(!is_array($LOM_array)) {
		print('$LOM_array, $this->context:');var_dump($LOM_array, $this->context);
		O::fatal_error('function context_array does not handle non-arrays yet.');
	} elseif(!is_array($LOM_array[0])) {
		//print('$LOM_array: ');var_dump($LOM_array);
		//O::fatal_error('function context_array does not handle single string-offset pairs yet.');
		return array(array($LOM_array[1], strlen($LOM_array[0])));
	}
	$context_array = array();
	foreach($LOM_array as $index => $value) {
		//$context_array[] = array($value[1], $value[1] + strlen($value[0]));
		$context_array[] = array($value[1], strlen($value[0]));
	}
	return $context_array;
}

function LOM_array($context_array = false) {
	if($context_array === false || (is_array($context_array) && sizeof($context_array) === 0)) {
		return array();
	}
	if(!is_array($context_array)) {
		print('$context_array :');var_dump($context_array);
		O::fatal_error('function LOM_array does not handle non-arrays yet.');
	} elseif(!is_array($context_array[0])) {
		//print('$context_array :');var_dump($context_array);
		//O::fatal_error('function LOM_array does not handle single string-offset pairs yet.');
		return array(array(substr($this->code, $context_array[0], $context_array[1]), $context_array[0]));
	}
	$LOM_array = array();
	foreach($context_array as $index => $value) {
		//$LOM_array[] = array(substr($this->code, $value[0], $value[1] - $value[0]), $value[0]);
		$LOM_array[] = array(substr($this->code, $value[0], $value[1]), $value[0]);
	}
	return $LOM_array;
}

function delete_context() {
	$this->context = array();
	//O::set_offset_depths();
}

function reset_context() {
	$this->context = array();
	//O::set_offset_depths();
}

function context_reset() {
	$this->context = array();
	//O::set_offset_depths();
}

function new_context() {
	$this->context = array();
	//O::set_offset_depths();
}

function get($selector, $matching_array = false, $add_to_context = true, $ignore_context = false, $parent_node_only = false, $tagged_result = false) {
	//O::warning_once('need to garbage-collect the context to have good performance. use test.xml to test and garbage-collect according to scope of queries');
	//print('$selector, $matching_array, $add_to_context at start of get: ');var_dump($selector, $matching_array, $add_to_context);
	$this->offsets_from_get = false;
	if(is_array($matching_array) && !O::all_entries_are_arrays($matching_array)) {
		$matching_array = array($matching_array);
	} elseif(is_string($matching_array) && strpos(O::query_decode($matching_array), '<') !== false) {
		$add_to_context = false;
		$ignore_context = true;
		$parent_node_only = true;
		//$this->code = O::code_from_LOM();
		if(strpos($this->code, $matching_array) !== false) {
			$matching_array = array(array($matching_array, strpos($this->code, $matching_array)));
		} else {
			$matching_array = array(array($matching_array, 0));
		}
	} elseif(is_string($matching_array)) {
		$matching_array = O::get($matching_array, false, $add_to_context, $ignore_context); // not sure if we should force whether to add to context
	}
	if(is_array($matching_array) && sizeof($matching_array) === 0) {
		return array();
	}
	//print('$matching_array, sizeof($matching_array) in get: ');var_dump($matching_array, sizeof($matching_array));
	//$used_context = false;
	//print('here374859---0000<br>');
	//print('$selector, $matching_array before selector type determination in get: ');var_dump($selector, $matching_array);
	//print('$selector at start of get: ');var_dump($selector);
	if(is_numeric($selector)) { // treat it as an offset
		//print('is_numeric($selector) in get<br>');
		$selector = (int)$selector;
	//	if($this->LOM[$selector][0] === 0) { // assume that if it's text we want the text value
			//$selector_matches = array($matching_array[$selector][1]);
			//$selector_matches = $this->LOM[$selector][1];
	//		$matching_array = false;
			//$selector_matches = $this->LOM[$selector];
			$expanded_LOM = O::expand($this->code, $selector, false, false, 'greedy');
			$selector_matches = $expanded_LOM[1];
			//$this->offsets_from_get = array($this->LOM[$selector][1]);
			//$this->offsets_from_get = array($expanded_LOM[1][1]);
	//		return $selector_matches;
	//		$this->offsets_from_get = array(O::offset_from_LOM_index($selector));
	//		return $selector_matches;
	//	} else {
	//		//$selector_matches = array($this->LOM[$selector]);
	//		//$selector_matches = $this->LOM[$selector];
	//		$offset = O::offset_from_LOM_index($selector);
	//		$substr = substr($this->code, $offset);
	//		//print('$selector, $substr, $offset: ');var_dump($selector, $substr, $offset);
	//		$matching_array = false;
	//		$selector_matches = O::get_tag_string($substr, O::tagname($substr));
	//		$this->offsets_from_get = array($offset);
	//		return $selector_matches;
	//	}
		//$add_to_context = false;
	} elseif(is_string($selector)) { // do XPath-type processing
		//print('is_string($selector) in get<br>');
		$normalized_selector = $selector;
		$normalized_selector = str_replace('\\', '_', $normalized_selector);
		$normalized_selector = str_replace('/', '_', $normalized_selector);
		//print('$normalized_selector: ');var_dump($normalized_selector);
		//print('here26344<br>');
		$selector_matches = array();
		// check the context first
		if($this->use_context && !$ignore_context) {
			$context_counter = sizeof($this->context) - 1;
			//print('$this->context at the start of is_string($selector) in get: ');O::var_dump_full($this->context);
			while($context_counter > -1 && sizeof($selector_matches) === 0 && !is_string($selector_matches)) {
				//print('looking in context ($context_counter is ' . $context_counter . ') for selector_matches<br>');
				//print('$normalized_selector, $this->context[$context_counter][0], $matching_array: ');var_dump($normalized_selector, $this->context[$context_counter][0], $matching_array);
				$matching_array_context_array = O::context_array($matching_array);
				if($normalized_selector === $this->context[$context_counter][0] && ($matching_array === false || $matching_array_context_array === $this->context[$context_counter][1])) {
					//print('found a match by same selector and $this->context[$context_counter][1]<br>');
					//print('found a match from context $this->context[$context_counter][0], $this->context[$context_counter][1], $this->context[$context_counter][2], $this->context[$context_counter][3]: ');var_dump($this->context[$context_counter][0], $this->context[$context_counter][1], $this->context[$context_counter][2], $this->context[$context_counter][3]);
					//print('$this->context overview: ');O::var_dump_short($this->context);
					//if(is_array($this->context[$context_counter][2])) {
					//} else {
					//	$this->offsets_from_get = array($this->context[$context_counter][2]);
					//}
					//print('$this->context[$context_counter][3]: ');var_dump($this->context[$context_counter][3]);
					//$this->offsets_from_get = array();
					//foreach($this->context[$context_counter][2] as $index => $value) {						
					//	$this->offsets_from_get[] = $value[0];
					//}
					$selector_matches = O::LOM_array($this->context[$context_counter][2]);
					$add_to_context = false;
					break;
					//return O::LOM_array($this->context[$context_counter][2]);
					/*if($tagged_result) {
						$context_result_is_tagged = false;
						if(is_string($this->context[$context_counter][3])) {
							if(strpos($this->context[$context_counter][3], '<') !== false) {
								$context_result_is_tagged = true;
							}
						} elseif(!is_array($this->context[$context_counter][3][0])) {
							if(strpos($this->context[$context_counter][3][0], '<') !== false) {
								$context_result_is_tagged = true;
							}
						} else {
							if(strpos($this->context[$context_counter][3][0][0], '<') !== false) {
								$context_result_is_tagged = true;
							}
						}
						if(!$context_result_is_tagged) {
							//print(!$context_result_is_tagged, );
							return O::get(O::LOM_index_from_offset($this->context[$context_counter][2][0]) - 1, $matching_array, $add_to_context, $ignore_context, $parent_node_only, $tagged_result);
						}
					}
					return $this->context[$context_counter][3];*/
				} elseif(is_array($matching_array) && sizeof($matching_array) > 0) { // don't look for anything other than exact matches in the context if a matching_array has been provided
					//print('skipping this context entry since matching array was provided but not matched<br>');
					//print('O::context_array($matching_array), $this->context[$context_counter][2]: ');var_dump(O::context_array($matching_array), $this->context[$context_counter][2]);
					if($matching_array_context_array === $this->context[$context_counter][2]) {
						//print('found $offset_depths for this matching array from context<br>');
						$offset_depths = $this->context[$context_counter][3];
						break;
					}
					if(sizeof($matching_array_context_array) === 1) {
						foreach($this->context[$context_counter][2] as $context_index2 => $context_value2) {
							if($matching_array_context_array[0] === $this->context[$context_counter][2][$context_index2]) {
								//print('found $offset_depths for this matching array from an element in context<br>');
								$offset_depths = array($this->context[$context_counter][3][$context_index2]);
								break;
							}
						}
					}
				} /*elseif(!is_array($this->context[$context_counter][3])) { // skip context entries with only a single value
					print('here26349<br>');
				} */elseif($matching_array === false) {
					//print('$matching_array === false<br>');
					// need to only look in the context here if the selector is a subset of the selector in the context but how can this be known without knowing the format of the XML? could get away with it if it is assumed that tags do not contain themselves?
					// this (unusually) falls into the category of grammar rather than syntax as computers are mostly concerned with but seems to make sense given the desire to query using imprecise statements that is the purpose of this code
					/*$context_selector_is_too_specific = false;
					O::parse_selector_string($normalized_selector);
					$selector_piece_sets = $this->selector_piece_sets;
					//$first_selector_tag = $selector_piece_sets[0][0];
					//$cleaned_first_selector_tag = O::clean_selector_tag_for_context_comparison($first_selector_tag);
					O::parse_selector_string($this->context[$context_counter][0]);
					$context_selector_piece_sets = $this->selector_piece_sets;
					$first_context_selector_tag = $context_selector_piece_sets[0][0];
					$cleaned_first_context_selector_tag = O::clean_selector_tag_for_context_comparison($first_context_selector_tag);
					if($cleaned_first_selector_tag === '*' && $cleaned_first_context_selector_tag !== '*') { // too specific by wildcard use
						$context_selector_is_too_specific = true;
					}
					
					// $this->selector_scope_sets
					$get_selected_selector_piece = true;
					$selected_selector_piece = -1;
					foreach($selector_piece_sets[0] as $piece_index => $value) { // not handling |
						if($get_selected_selector_piece) {
							if($piece_index === sizeof($selector_piece_sets[0]) - 1) {
								$selected_selector_piece = $piece_index;
								$get_selected_selector_piece = false;
							} elseif(strpos($value, '.') !== false) {
								$selected_selector_piece = $piece_index;
								$get_selected_selector_piece = false;
							}
						}
						$selector_tag = $selector_piece_sets[0][$piece_index];
						$context_selector_tag = $context_selector_piece_sets[0][$context_piece_index];
						$context_piece_index++;
					}
					// ugh
					$unselected_first_selector_tag = str_replace('.', '', $first_selector_tag); // isn't there some $this-> variable for the seletcted piece? not at this level of scrutiny... difficult to elegantly avoid this paradox
					$selected_context_selector_piece = -1;
					foreach($selector_piece_sets[0] as $index => $value) { // not handling |
						if($index === sizeof($selector_piece_sets[0]) - 1) {
							$selected_context_selector_piece = $index;
							break;
						} elseif(strpos($value, '.') !== false) {
							$selected_context_selector_piece = $index;
							break;
						}
					}
					$unselected_first_context_selector_tag = str_replace('.', '', $first_context_selector_tag);
					if($cleaned_first_selector_tag === '*' && $cleaned_first_context_selector_tag !== '*') { // too specific by selected piece
						$context_selector_is_too_specific = true;
						break;
					}
					//print('$normalized_selector, $first_selector_tag, $cleaned_first_selector_tag, $first_context_selector_tag, $cleaned_first_context_selector_tag: ');var_dump($normalized_selector, $first_selector_tag, $cleaned_first_selector_tag, $first_context_selector_tag, $cleaned_first_context_selector_tag);
					//print('$this->context: ');var_dump($this->context);
					//if($cleaned_first_selector_tag === $cleaned_first_context_selector_tag || $cleaned_first_selector_tag === '*' || $cleaned_first_context_selector_tag === '*') {
					//if($cleaned_first_selector_tag === '*' || $cleaned_first_context_selector_tag === '*' || 
					//($cleaned_first_selector_tag === $cleaned_first_context_selector_tag && (strpos($first_selector_tag, $first_context_selector_tag) !== 0 && strpos($first_context_selector_tag, $first_selector_tag) !== 0))) {
					if(($cleaned_first_selector_tag === '*' && $cleaned_first_context_selector_tag !== '*') || 
					($cleaned_first_selector_tag === $cleaned_first_context_selector_tag && 
						(sizeof($selector_piece_sets) < sizeof($context_selector_piece_sets) || 
						$selected_selector_piece < $selected_context_selector_piece || 
						(strpos($unselected_first_context_selector_tag, $unselected_first_selector_tag) === 0 && strlen($unselected_first_context_selector_tag) > strlen($unselected_first_selector_tag))))) {
					if($context_selector_is_too_specific) {
						print('context selector is too specific than selector so we go to a broader context<br>');
					//} elseif(is_array($this->context[$context_counter][2]) && O::all_entries_are_arrays($this->context[$context_counter][2])) {
					} else {*/
						//print('using context entry to search for match<br>');
						//print('$this->context[$context_counter][3]: ');var_dump($this->context[$context_counter][3]);
						//print('O::LOM_array($this->context[$context_counter][2]): ');var_dump(O::LOM_array($this->context[$context_counter][2]));
						$selector_matches = O::select($normalized_selector, O::LOM_array($this->context[$context_counter][2]), $this->context[$context_counter][3]);
						//print('$selector_matches when using context entry: ');var_dump($selector_matches);
						if(is_array($selector_matches) && sizeof($selector_matches) > 0) {
							$matching_array = O::LOM_array($this->context[$context_counter][2]);
							$offset_depths = $this->context[$context_counter][3];
							break;
						}
						//print('found a match by doing a query in $this->context[$context_counter][3]<br>');
						// breaks things...
						/*$overscoped = false;
						foreach($matching_array as $first_index => $first_value) { break; }
						foreach($matching_array as $last_index => $last_value) {  }
						foreach($this->context[$context_counter][3] as $index => $value) {
							if($index < $first_index || $index > $last_index) {
								$overscoped = true;
								break 2;
							}
						}
						if(!$overscoped) {
							$selector_matches = O::select($normalized_selector, $this->context[$context_counter][3]);
						}*/
					//}
				}
				$context_counter--;
			}
		}
		// ???
		//if(is_array($matching_array) && sizeof($matching_array) === 0) {
		//	O::fatal_error('how is an empty $matching_array getting here??');
		//	return array();
		//}
		// if nothing's found in the context, then we have to do a fresh check
		//print('finished looking through context<br>');
		if(is_array($selector_matches) && sizeof($selector_matches) === 0) {
			//print('nothing was found from context<br>');
			// and garbage-collect the context
			if($matching_array === false) {
				//print('getting selector_matches by doing a straight query (on the whole code) instead of using context<br>');
				//$selector_matches = O::select($normalized_selector, array(array($this->code, 0)));
				$selector_matches = O::select($normalized_selector, array(array($this->code, 0)));
				if(is_array($selector_matches) && sizeof($selector_matches) > 0) {
					// if a raw query on the whole code is done then the context is completely reset
					//print('reseting context<br>');
					$this->context = array();
				}
			} else {
				//print('getting selector_matches by looking in matching_array (not the whole code)<br>');
				//print('$offset_depths: ');var_dump($offset_depths);exit(0);
				if($offset_depths === NULL) {
					//O::fatal_error('$offset_depths === NULL when getting selector_matches by looking in matching_array (not the whole code)');
					$offset_depths = O::get_offset_depths_of_selector_matches($matching_array);
				}
				$selector_matches = O::select($normalized_selector, $matching_array, $offset_depths);
				if(is_array($selector_matches) && sizeof($selector_matches) > 0) {
					//print('checking to see if we need to cull obsolete context entries after successful matching_array search<br>');
					// go in reverse order and see which context entries the one that will be created includes and makes obsolete
					//$first_entry = $matching_array[0][0];
					$first_offset = $matching_array[0][1];
					//foreach($matching_array as $last_index => $last_value) {  }
					//$last_offset = $last_value[1];
					//$last_entry = $last_value[0];
					$last_entry = $matching_array[sizeof($matching_array) - 1][0];
					$last_offset = $matching_array[sizeof($matching_array) - 1][1];
					$context_counter = sizeof($this->context) - 1;
					//$culled_context_entry = false;
					while($context_counter > -1) {
						if($this->context[$context_counter][1] === false) {
							break;
						//} elseif($this->context[$context_counter][1][0][1] >= $first_offset && $this->context[$context_counter][1][sizeof($this->context[$context_counter][1]) - 1][1] <= $last_offset + strlen($last_entry)) {
						} elseif($this->context[$context_counter][1][0][0] >= $first_offset && $this->context[$context_counter][1][sizeof($this->context[$context_counter][1]) - 1][0] <= $last_offset + strlen($last_entry)) {
							//print('context entry at ($context_counter is ' . $context_counter . ') is obsolete<br>');
							unset($this->context[$context_counter]);
							//$culled_context_entry = true;
						}
						$context_counter--;
					}
					//if($culled_context_entry) {
						$this->context = array_values($this->context);
					//}
				}
			}
		} else {
			//print('something was found from context<br>');
			//$culled_context_entry = false;
			$context_counter++;
			$pre_cull_context_size = sizeof($this->context);
			while($context_counter < $pre_cull_context_size) {
				//print('culling an obsolete context entry after successful context search<br>');
				unset($this->context[$context_counter]);
				//$culled_context_entry = true;
				$context_counter++;
			}
			//if($culled_context_entry) {
				$this->context = array_values($this->context);
			//}
			//O::good_message('used the context instead of querying the whole LOM');
			//$used_context = true;
		}
		//print('$this->context at the end of is_string($selector) in get: ');O::var_dump_full($this->context);
	} elseif(is_array($selector)) { // recurse??
		//print('$selector is_array($selector) in get: ');var_dump($selector);exit(0);
		//$selector_matches = array();
		//foreach($selector as $index => $value) {
		//	$matches = O::get($value, $matching_array);
		//	$selector_matches = array_merge($selector_matches, $matches);
		//}
		if(O::all_entries_are_arrays($selector)) {
			$selector_matches = $selector;
		} else {
			$selector_matches = array($selector);
		}
	} else {
		print('$selector: ');var_dump($selector);
		O::var_dump_full($this->code);
		O::fatal_error('Unknown selector type in get');
	}
	//print('here374859---0042<br>');
	//print('$selector_matches, $this->context mid get: ');var_dump($selector_matches, $this->context);
	//print('$selector, $selector_matches mid get: ');var_dump($selector, $selector_matches);
	//print('$selector_matches mid get: ');var_dump($selector_matches);
	
	/*
	
	if(sizeof($selector_matches) === 0 || (sizeof($selector_matches) === 1 && ($selector_matches[0] === NULL || $selector_matches[0] === false))) {
	//if(sizeof($selector_matches) === 0 && ($selector_matches[0] === NULL || $selector_matches[0] === false)) {
		//print('here374859---0044<br>');
		//print('$selector_matches: ');var_dump($selector_matches);
		//return false;
		// debateable whether it's better to return false or an empty array when nothing is found; coder expectation doens't really exist so ease of checking against a boolean and having different data type based on success will prevail, I guess
		//$selector_matches = false;
		// turns out an empty array still evaluates to false in an if statement so we're good? nvm
		$selector_matches = array();
		//$selector_matches = '';
		$add_to_context = false;
	}
	//if($add_to_context) {
		//print('$selector_matches in add_to_context: ');var_dump($selector_matches);
		//print('here374859---0045<br>');
		//if(sizeof($selector_matches) > 0) {
			//print('here374859---0047<br>');
			//$text_only_value = false;
			//$text_only_index = false;
			// debug
			//if(!is_array($selector_matches[0])) {
				//print('here374859---0048<br>');
				if(is_int($selector)) {
					//print('here374859---0049<br>');
					$new_start_indices = array($selector);
				} elseif($used_context) {
					//print('here374859---0050<br>');
					$new_start_indices = $this->context[$context_counter + 1][2];
				//} else {
				//	print('$selector, $selector_matches, $this->context: ');var_dump($selector, $selector_matches, $this->context);
				//	O::fatal_error('!is_array($selector_matches[0])');
				//}
			} else {
				// this seems wonky
				//print('here374859---0051<br>');
				$start_offsets = array();
				$new_start_indices = array();
				$new_selector_matches = array();
				$did_a_text_only_value = false;
				foreach($selector_matches as $index => $value) {
					//print('here374859---0052<br>');
					$value_counter = 0;
					foreach($value as $value_index => $value_value) {
						//print('here374859---0053<br>');
						if($value_counter === 0) {
							//print('here374859---0054<br>');
							$start_offsets[] = $value_index;
						}
						if($value_counter === 1) {
							//print('here374859---0055<br>');
							$text_only_index = $value_index;
							$text_only_value = $value_value[1];
						}
						$value_counter++;
					}
					if($value_counter === 3 && strlen(trim($text_only_value)) > 0) { // making the assumption that existing tags with nothing in them should only be populated with tags rather than raw text
					//if($value_counter === 3) {
						//print('here374859---0056<br>');
						$new_start_indices[] = $text_only_index;
						$new_selector_matches[$text_only_index] = $text_only_value;
						$did_a_text_only_value = true;
					}
				}
				//print('$new_selector_matches1: ');var_dump($new_selector_matches);
				if(!$did_a_text_only_value) {
					//print('here374859---0057<br>');
					$new_start_indices = $start_offsets;
					$new_selector_matches = $selector_matches;
				}
				// if the selection resolves to a single value then that is what's desired rather than the array of thar single value
				if(sizeof($new_selector_matches) === 1 && (is_string($new_selector_matches[$text_only_index]) || is_int($new_selector_matches[$text_only_index]) || is_float($new_selector_matches[$text_only_index]))) {
				//if(sizeof($new_selector_matches) === 1) {
					//print('here374859---0058<br>');
					$new_start_indices = $text_only_index;
					$new_selector_matches = $text_only_value;
				}
			}
		//}
		//print('$new_selector_matches2: ');var_dump($new_selector_matches);
		// debug
		if(sizeof($new_start_indices) !== sizeof($new_selector_matches)) {
			print('$selector, $matching_array, $new_start_indices, $new_selector_matches, sizeof($new_start_indices), sizeof($new_selector_matches): ');var_dump($selector, $matching_array, $new_start_indices, $new_selector_matches, sizeof($new_start_indices), sizeof($new_selector_matches));
			O::fatal_error('sizeof($new_start_indices) !== sizeof($new_selector_matches)');
		}
		
		*/
		
		$this->offsets_from_get = array();
		foreach($selector_matches as $index => $value) {						
			$this->offsets_from_get[] = $value[1];
		}
		//print('$normalized_selector, $matching_array, $selector_matches before adding to context: ');var_dump($normalized_selector, $matching_array, $selector_matches);
		//if(sizeof($selector_matches) > 0 && $add_to_context && $this->use_context && !$ignore_context && !$used_context) { // non-results (tested by sizeof) are not added to the context because the code could be updated and the context using this selector wouldn't "know". sizeof(empty string) returns 1 wierdly but conveniently
		if(sizeof($selector_matches) > 0 && $add_to_context && $this->use_context && !$ignore_context) { // non-results (tested by sizeof) are not added to the context because the code could be updated and the context using this selector wouldn't "know". sizeof(empty string) returns 1 wierdly but conveniently
			//$this->context[] = array($normalized_selector, O::context_array($matching_array), O::context_array($selector_matches));
			$offset_depths_of_selector_matches = O::get_offset_depths_of_selector_matches($selector_matches);
			$all_text = true;
			foreach($offset_depths_of_selector_matches as $offset_depths) {
				if(sizeof($offset_depths) === 2) {
					
				} else {
					$all_text = false;
					break;
				}
			}
			if($all_text) {
				
			} else {
				$this->context[] = array($normalized_selector, O::context_array($matching_array), O::context_array($selector_matches), $offset_depths_of_selector_matches);
			}
			//print('$this->context[sizeof($this->context) - 1] after adding to context: ');var_dump($this->context[sizeof($this->context) - 1]);
		}
		if($tagged_result === true) {
			return $selector_matches;
		}
		//print('$selector_matches before potentially providing text-only results: ');var_dump($selector_matches);
		//$start_offsets = $start_offsets;
		//$tagged_selector_matches = $selector_matches;
		//$offsets = array();
		//$start_offsets = array();
		//$new_selector_matches = array();
		
		// this could probably be optimized by using the offset_depths to figure out whether it's just text
		// if every match is text in a single tag then assume we want to return the text (see above)
		$all_texts_in_single_tags = true;
		$text_onlys = array();
		//$text_only_offsets = array();
		foreach($selector_matches as $index => $value) {
			$string_of_match = trim($value[0]);
			//print('$string_of_match, substr_count($string_of_match, \'<\'), substr_count($string_of_match, \'>\'): ');var_dump($string_of_match, substr_count($string_of_match, '<'), substr_count($string_of_match, '>'));
			if($string_of_match[0] === '<' && $string_of_match[strlen($value[0]) - 1] === '>' && substr_count($string_of_match, '<') === 2 && substr_count($string_of_match, '>') === 2) {
				
			} else {
				$all_texts_in_single_tags = false;
				break;
			}
			$text_onlys[] = O::tagless($string_of_match);
			//$text_only_offsets[] = $value[1] + (strpos($string_of_match, '>') + 1);
			//$start_offsets[] = $value[1];
		}
		//print('$all_texts_in_single_tags: ');var_dump($all_texts_in_single_tags);
		/*if($tagged_result !== true && $all_texts_in_single_tags) {
			if(sizeof($selector_matches) > 0) {
				foreach($selector_matches as $index => $value) {
					//$start_offsets[] = O::LOM_index_from_offset($value[1]);
					//$start_offsets[] = $value[1];
					$new_selector_matches[] = $text_onlys[$index];
					$start_offsets[] = $text_only_offsets[$index];
				}
			} else {
				foreach($selector_matches as $index => $value) {
					//$start_offsets[] = $value[1];
					$new_selector_matches = $text_onlys[$index];
					$start_offsets[] = $text_only_offsets[$index];
				}
			}
			// if there is only a single text-only result then return it as a string
			if(sizeof($new_selector_matches) === 1) {
				$new_selector_matches = $new_selector_matches[0];
			}
		} else {
			if(sizeof($selector_matches) > 0) {
				foreach($selector_matches as $index => $value) {
					//$start_offsets[] = O::LOM_index_from_offset($value[1]);
					$new_selector_matches[] = array($value[0], $value[1]);
					$start_offsets[] = $value[1];
				}
			} else {
				foreach($selector_matches as $index => $value) {
					$new_selector_matches[] = array($value[0], $value[1]);
					$start_offsets[] = $value[1];
				}
			}
		}
		$selector_matches = $new_selector_matches;
		print('$start_offsets: ');var_dump($start_offsets);
		if(is_array($start_offsets)) {
			$this->offsets_from_get = $start_offsets;
		} else {
			$this->offsets_from_get = array($start_offsets);
		}*/
		
		// if there is only a single text-only result then return it as a string
		if($all_texts_in_single_tags) {
			//$offsets = $text_only_offsets;
			$selector_matches = $text_onlys;
			if(sizeof($selector_matches) === 1) {
				$selector_matches = $selector_matches[0];
			}
		}/* else {
			$offsets = $start_offsets;
		}*/
		//print('$normalized_selector, $matching_array, $start_offsets, $new_selector_matches, $selector_matches: ');var_dump($normalized_selector, $matching_array, $start_offsets, $new_selector_matches, $selector_matches);
		
		//print('sizeof($this->context) - 1, $this->context[sizeof($this->context) - 1]: ');var_dump(sizeof($this->context) - 1, $this->context[sizeof($this->context) - 1]);
		// debug
		//if($normalized_selector === 'items') {
		//	print('items $this->context: ');O::var_dump_full($this->context);exit(0);
		//}
	//}
	//print('$selector_matches, $this->context at end of get: ');var_dump($selector_matches, $this->context);
	//print('$selector_matches at end of get: ');var_dump($selector_matches);
	//print('$this->context overview: ');O::var_dump_full($this->context);
	return $selector_matches;
}

function tagless($variable) {
	if(is_array($variable)) {
		if(O::all_entries_are_arrays($variable)) {
			$tagless_array = array();
			foreach($variable as $index => $value) {
				$tagless_array[] = O::tagless($value[0]);
			}
			if(sizeof($tagless_array) === 1) {
				return $tagless_array[0];
			}
			return $tagless_array;
		} else {
			return O::tagless($variable[0]);
		}
		//O::fatal_error('tagless() expects string input');
	}
	return preg_replace('/<[^<>]*>/is', '', $variable);
}

function tagvalue($string) {
	if(is_array($string)) {
		O::fatal_error('tagvalue() expects string input');
	}
	return O::preg_replace_first('/<[^<>]+>/is', '', O::preg_replace_last('/<[^<>]+>/is', '', $string));
}

function preg_replace_first($search, $replace, $subject) {
	return preg_replace($search, $replace, $subject, 1);
}

function preg_replace_last($search, $replace, $subject) {
	//print("preg_replace_last subject: ");var_dump($subject);
	// we can't just reverse everything like in str_replace_last since the regular expressions operators have a predefined orientation (left-to-right)
	preg_match_all($search, $subject, $matches, PREG_OFFSET_CAPTURE);
	if(sizeof($matches[0]) === 0) {
		return $subject;
	}
	$last_offset = $matches[0][sizeof($matches[0]) - 1][1];
	$substr = substr($subject, $last_offset);
	$substr = preg_replace($search, $replace, $substr);
	//print("preg_replace_last replaced: ");var_dump(substr($subject, 0, $last_offset) . $substr);
	return substr($subject, 0, $last_offset) . $substr;
}

function clean_selector_tag_for_context_comparison($string) {
	$string = str_replace('.', '', $string);
	$square_bracket_position = strpos($string, '[');
	if($square_bracket_position !== false) {
		$string = substr($string, 0, $square_bracket_position);
	}
	$ampersand_position = strpos($string, '&');
	if($ampersand_position !== false) {
		$string = substr($string, 0, $ampersand_position);
	}
	$at_position = strpos($string, '@');
	if($at_position !== false) {
		$string = substr($string, 0, $at_position);
	}
	$equals_position = strpos($string, '=');
	if($equals_position !== false) {
		$string = substr($string, 0, $equals_position);
	}
	return $string;
}

function get_LOM_index($selector, $matching_array = false, $add_to_context = true, $ignore_context = false, $parent_node_only = false) { // alias
	O::fatal_error('get_LOM_index is probably obsolete');
	$LOM_indices = O::get_LOM_indices($selector, $matching_array, $add_to_context, $ignore_context, $parent_node_only);
	//if(is_string($LOM_indices)) {
	if(is_numeric($LOM_indices)) {
		return (int)$LOM_indices;
	} elseif(sizeof($LOM_indices) === 1) {
		return (int)$LOM_indices[0];
	} else {
		print('$LOM_indices: ');var_dump($LOM_indices);
		O::fatal_error('not sure how get_LOM_index should interpret this result of get_LOM_indices');
	}
}

function get_LOM_indices($selector, $matching_array = false, $add_to_context = true, $ignore_context = false, $parent_node_only = false) {
	O::fatal_error('get_LOM_indices is probably obsolete');
	if(is_array($selector) && !O::all_entries_are_arrays($selector)) {
		return array(O::LOM_index_from_offset($selector[1]));
	}
	$LOM_indices = array();
	foreach(O::get_offsets($selector, $matching_array, $add_to_context, $ignore_context, $parent_node_only) as $offset) {
		$LOM_index = O::LOM_index_from_offset($offset);
		print('$offset, $LOM_index: ');var_dump($offset, $LOM_index);
		if($LOM_index === false) { // debug
			print('$offset, $LOM_index: ');var_dump($offset, $LOM_index);
			O::var_dump_full($this->LOM);
			O::fatal_error('$LOM_index === false in get_LOM_indices');
		}
		$LOM_indices[] = $LOM_index;
	}
	return $LOM_indices;
}

function get_opening_LOM_indices($selector, $matching_array = false, $add_to_context = true, $ignore_context = false, $parent_node_only = false) {
	O::fatal_error('get_opening_LOM_indices is probably obsolete');
	// is this over complicated? can we just get LOM indices and then if the LOM index equates to a text node subtract 1? (simplifying assumption)
	/*$selector_matches = O::get($selector, $matching_array, $add_to_context, $ignore_context, $parent_node_only);
	$first_entry = false;
	$first_entry_is_tagless = false;
	if(is_array($selector_matches)) {
		if(is_array($selector_matches[0])) {
			$offsets = array();
			foreach($selector_matches as $index => $value) {
				if($first_entry === false) {
					$first_entry = $value[0];
				}
				$offsets[] = $value[1];
			}
		} else {
			$first_entry = $selector_matches[0];
			$offsets = $this->offsets_from_get;
		}
	} else {
		$first_entry = $selector_matches;
		$offsets = $this->offsets_from_get;
	}
	if($first_entry[0] !== '<') {
		$first_entry_is_tagless = true;
	}
	$opening_LOM_indices = array();
	foreach($offsets as $offset) {
		$opening_LOM_indices[] = O::LOM_index_from_offset($offset);
	}
	if($first_entry_is_tagless) {
		foreach($opening_LOM_indices as $index => $value) {
			$opening_LOM_indices[$index]--;
		}
	}*/
	$opening_LOM_indices = array();
	foreach(O::get_offsets($selector, $matching_array, $add_to_context, $ignore_context, $parent_node_only) as $offset) {
		$LOM_index = O::LOM_index_from_offset($offset);
		if($this->LOM[$LOM_index][0] === 0) { // text node
			if($this->LOM[$LOM_index + 1][0] === 1 && $this->LOM[$LOM_index + 1][1][2] === 0) { // next node is opening tag
				$opening_LOM_indices[] = $LOM_index + 1;
			} else {
				$opening_LOM_indices[] = $LOM_index - 1;
			}
		} else {
			$opening_LOM_indices[] = $LOM_index;
		}
	}
	return $opening_LOM_indices;
}

function get_closing_LOM_indices($selector, $matching_array = false, $add_to_context = true, $ignore_context = false, $parent_node_only = false) {
	O::fatal_error('get_closing_LOM_indices is probably obsolete');
	$closing_LOM_indices = array();
	foreach(O::get_closing_offsets($selector, $matching_array, $add_to_context, $ignore_context, $parent_node_only) as $offset) {
		$closing_LOM_indices[] = O::closing_LOM_index_from_offset($offset);
	}
	return $closing_LOM_indices;
}

/*function get_offset($selector, $matching_array = false, $add_to_context = true, $ignore_context = false, $parent_node_only = false) { // alias
	return O::get_index($selector, $matching_array, $add_to_context, $ignore_context, $parent_node_only);
}

function get_index($selector, $matching_array = false, $add_to_context = true, $ignore_context = false, $parent_node_only = false) { // alias
	$indices = O::get_offsets($selector, $matching_array, $add_to_context, $ignore_context, $parent_node_only);
	//if(is_string($indices)) {
	if(is_numeric($indices)) {
		return (int)$indices;
	} elseif(sizeof($indices) === 1) {
		return (int)$indices[0];
	} else {
		print('$indices: ');var_dump($indices);
		O::fatal_error('not sure how get_index should interpret this result of get_offsets');
	}
}*/

function get_closing_offsets($selector, $matching_array = false, $add_to_context = true, $ignore_context = false, $parent_node_only = false) {
	O::fatal_error('get_closing_offsets is not written');
	/*O::get($selector, $matching_array, $add_to_context, $ignore_context, $parent_node_only);
	foreach($this->offsets_from_get as $index_from_get) {
		
	}
	return ;*/
}

function get_offsets($selector, $matching_array = false, $add_to_context = true, $ignore_context = false, $parent_node_only = false) {
	//print('$selector, $matching_array in get_offsets: ');var_dump($selector, $matching_array);
	if(is_array($selector) && !O::all_entries_are_arrays($selector)) {
		return array($selector[1]);
	}
	//get($selector, $matching_array = false, $add_to_context = true, $ignore_context = false)
	O::get($selector, $matching_array, $add_to_context, $ignore_context, $parent_node_only);
	return $this->offsets_from_get;
	/*
	if($matching_array === false) {
		$matching_array = array($this->LOM);
	}
	//print('$selector, $matching_array at the start of get_offsets: ');var_dump($selector, $matching_array);
	$index_matches = array();
	if(is_numeric($selector)) { // treat it as an offset
		$selector = (int)$selector;
		$index_matches[] = $selector;
	} elseif(is_string($selector)) {
		$normalized_selector = $selector;
		$normalized_selector = str_replace('\\', '_', $normalized_selector);
		$normalized_selector = str_replace('/', '_', $normalized_selector);
		$selector_matches = array();
		//print('$selector_matches in get_offsets0: ');var_dump($selector_matches);
		if($this->use_context && !$ignore_context) {
			$context_counter = sizeof($this->context) - 1;
			//print('here249702667-9<br>');
			while($context_counter > -1 && sizeof($selector_matches) === 0 && !is_string($selector_matches)) {
				//print('here249702668-0<br>');
				//print('getting selector_matches from context in get_offsets<br>');
				//print('$context_counter: ');var_dump($context_counter);
				//print('$this->context: ');O::var_dump_full($this->context);
				if($normalized_selector === $this->context[$context_counter][0] && $matching_array === $this->context[$context_counter][1]) {
					//print('here249702668-1<br>');
					if(is_array($this->context[$context_counter][2])) {
						//print('here249702668-1.1<br>');
						return $this->context[$context_counter][2];
					} else {
						//print('here249702668-1.2<br>');
						return array($this->context[$context_counter][2]);
					}
				} elseif(!is_array($this->context[$context_counter][3])) { // skip context entries with only a single value
					//print('here249702668-2<br>');
				} else {
					//print('here249702668-3<br>');
					$first_selector_tag = O::parse_selector_string($normalized_selector)[0][0];
					$cleaned_first_selector_tag = O::clean_selector_tag_for_context_comparison($first_selector_tag);
					$first_context_selector_tag = O::parse_selector_string($this->context[$context_counter][0])[0][0];
					$cleaned_first_context_selector_tag = O::clean_selector_tag_for_context_comparison($first_context_selector_tag);
					//print('$first_selector_tag, $cleaned_first_selector_tag, $first_context_selector_tag, $cleaned_first_context_selector_tag: ');var_dump($first_selector_tag, $cleaned_first_selector_tag, $first_context_selector_tag, $cleaned_first_context_selector_tag);
					//print('$this->context: ');var_dump($this->context);
					if($cleaned_first_selector_tag === $cleaned_first_context_selector_tag || $cleaned_first_selector_tag === '*' || $cleaned_first_context_selector_tag === '*') {
						//print('here249702668-3.1<br>');
					} else {
						//print('here249702668-3.2<br>');
						$selector_matches = O::select($normalized_selector, $this->context[$context_counter][3]);
						// guard against overscoping
						// breaks things...
						//$overscoped = false;
						//foreach($matching_array as $first_index => $first_value) { break; }
						//foreach($matching_array as $last_index => $last_value) {  }
						//foreach($this->context[$context_counter][3] as $index => $value) {
						//	if($index < $first_index || $index > $last_index) {
						//		$overscoped = true;
						//		break 2;
						//	}
						//}
						//if(!$overscoped) {
						//	$selector_matches = O::select($normalized_selector, $this->context[$context_counter][3]);
						//}
					}
					//$selector_matches = O::select($normalized_selector, $this->context[$context_counter][3]);
				}
				$context_counter--;
			}
		}
		//print('here249702668-4<br>');
		//print('$selector_matches in get_offsets1: ');var_dump($selector_matches);
		if(sizeof($selector_matches) === 0) {
			//print('here249702668-5<br>');
			//print('getting selector_matches from $this->LOM in get_offsets<br>');
			// ??
			$selector_matches = O::select($normalized_selector, $matching_array);
			//foreach($matching_array as $first_index => $first_value) { break; }
			//if(is_array($matching_array[$first_index])) {
			//	print('here249702668-5.1<br>');
			//	$selector_matches = O::select($normalized_selector, array($matching_array));
			//} else {
			//	print('here249702668-5.2<br>');
			//	$selector_matches = O::select($normalized_selector, $matching_array);
			//}
		}
		//print('here249702668-6<br>');
		//print('$selector_matches in get_offsets2: ');var_dump($selector_matches);
		if(sizeof($selector_matches) === 1 && (strpos($normalized_selector, '_') !== false || $matching_array !== false || sizeof($selector_matches[0] === 3))) {
			//print('here249702668-7<br>');
			$value_counter = 0;
			$text_only_value = false;
			$text_only_index = false;
			foreach($selector_matches[0] as $value_index => $value_value) {
				//print('here249702668-8<br>');
				if($value_counter === 1) {
					$text_only_value = $value_value[1];
					$text_only_index = $value_index;
				}
				$value_counter++;
			}
			if($value_counter === 3 && strlen(trim($text_only_value)) > 0) { // making the assumption that existing tags with nothing in them should only be populated with tags rather than raw text
			//if($value_counter === 3) {
				//print('here249702668-9<br>');
				return array($text_only_index);
			}
		}
		//print('$selector_matches in get_offsets3: ');var_dump($selector_matches);
		foreach($selector_matches as $selector_match_index => $selector_match_value) {
			if(sizeof($selector_match_value) === 3) {
				$counter = 0;
				foreach($selector_match_value as $index => $value) {
					if($counter === 1) {
						$index_matches[] = $index;
						break;
					}
					$counter++;
				}
			}
		}
	} elseif(is_array($selector)) { // recurse??
		//O::fatal_error('is_array($selector) in get_offsets');
		$index_matches = array();
		foreach($selector as $index => $value) {
			$matches = O::get_offsets($value, $matching_array);
			$index_matches = array_merge($index_matches, $matches);
		}
	} else {
		print('$selector: ');var_dump($selector);
		O::fatal_error('Unknown selector type in get_offsets');
	}
	//print('$matching_array at the end of get_offsets: ');var_dump($matching_array);
	//foreach($matching_array as $first_index => $first_value) { break; }
	//foreach($matching_array as $last_index => $last_value) {  }
	//foreach($index_matches as $index_index => $index) {
	//	if($index < $first_index || $index > $last_index) {
	//		O::warning('should never be matching indices outside of the parent_node.....');
	//		unset($index_matches[$index_index]);
	//	}
	//}
	//sort($index_matches);
	return $index_matches;*/
}

function get_attributes($matching_array) {
	O::fatal_error('get_attributes is probably obsolete');
	if(!is_array($matching_array)) {
		//$first_index = $matching_array;
		//$attributes_array = $this->LOM[$first_index][1][1];
		$attributes_array = O::get_tag_attributes($matching_array);
	} else {
		//foreach($matching_array as $first_index => $first_value) { break; } 
		//if(is_array($first_value)) {
		//	foreach($first_value as $first_index => $first_value) { break; } 
		//}
		//$attributes_array = $matching_array[$first_index][1][1];
		$attributes_array = array();
		if(is_array($matching_array[0])) {
			//$attributes_array = $this->LOM[O::opening_LOM_index_from_offset($matching_array[0][1])][1][1];
			foreach($matching_array as $index => $value) {
				$attributes_array[] = O::get_tag_attributes($matching_array[$index][0]);
			}
		} else {
			//$attributes_array = $this->LOM[O::opening_LOM_index_from_offset($matching_array[1])][1][1];
			//foreach($matching_array as $index => $value) {
				$attributes_array[] = O::get_tag_attributes($matching_array[0]);
			//}
		}
	}
	return $attributes_array;
}

function has_attribute($attribute_name, $selector) {
	if((!is_string($attribute_name) && is_string($selector)) || (is_string($attribute_name) && is_string($selector) && strpos($attribute_name, $selector) !== false)) { // swap them
		$temp_selector = $selector;
		$selector = $attribute_name;
		$attribute_name = $temp_selector;
	}
	if(is_numeric($selector)) {
		$selector = (int)$selector;
		$expanded_LOM = O::expand($this->code, $selector, false, false, 'lazy');
		$opening_tag_string = $expanded_LOM[0][0];
	} elseif(is_string($selector)) {
		if(strpos($selector, '>') === false) {
			return O::get_attribute_value($attribute_name, O::get_tagged($selector));
		} else {
			$opening_tag_string = substr($selector, 0, strpos($selector, '>'));
		}
	} else {
		if(is_array($selector[0])) {
			$opening_tag_string = substr($selector[0][0], 0, strpos($selector[0][0], '>'));
		} else {
			$opening_tag_string = substr($selector[0], 0, strpos($selector[0], '>'));
		}
	}
	return(preg_match('/ ' . $attribute_name . '="([^"]+)"/', $opening_tag_string, $matches));
}

function get_attribute_value($attribute_name, $selector) {
	if((!is_string($attribute_name) && is_string($selector)) || (is_string($attribute_name) && is_string($selector) && strpos($attribute_name, $selector) !== false)) { // swap them
		$temp_selector = $selector;
		$selector = $attribute_name;
		$attribute_name = $temp_selector;
	}
	if(is_numeric($selector)) {
		$selector = (int)$selector;
		$expanded_LOM = O::expand($this->code, $selector, false, false, 'lazy');
		$opening_tag_string = $expanded_LOM[0][0];
	} elseif(is_string($selector)) {
		if(strpos($selector, '>') === false) {
			return O::get_attribute_value($attribute_name, O::get_tagged($selector));
		} else {
			$opening_tag_string = substr($selector, 0, strpos($selector, '>'));
		}
	} else {
		if(is_array($selector[0])) {
			$opening_tag_string = substr($selector[0][0], 0, strpos($selector[0][0], '>'));
		} else {
			$opening_tag_string = substr($selector[0], 0, strpos($selector[0], '>'));
		}
	}
	preg_match('/ ' . $attribute_name . '="([^"]+)"/', $opening_tag_string, $matches);
	return $matches[1];
	/*
	//print('$attribute_name, $matching_array in get_attribute_value: ');var_dump($attribute_name, $matching_array);
	if(!is_array($matching_array)) { // assume it's an index
		$attributes_array = O::get_tag_attributes($matching_array);
	} else {
		if(is_array($matching_array[0])) {
			foreach($matching_array as $index => $value) {
				$attributes_array = O::get_tag_attributes($matching_array[$index][0]);
			}
		} else {
			$attributes_array = O::get_tag_attributes($matching_array[0]);
		}
	}
	//print('$attribute_name, $attributes_array in get_attribute_value: ');var_dump($attribute_name, $attributes_array);
	return $attributes_array[$attribute_name];*/
}

function get_attribute($attribute_name, $selector) { // alias
	return O::get_attribute_value($attribute_name, $selector);
}

function get_attr($attribute_name, $selector) { // alias
	return O::get_attribute_value($attribute_name, $selector);
}

function get_att($attribute_name, $selector) { // alias
	return O::get_attribute_value($attribute_name, $selector);
}

function _attribute($attribute_name, $selector) { // alias
	return O::get_attribute_value($attribute_name, $selector);
}

function _attr($attribute_name, $selector) { // alias
	return O::get_attribute_value($attribute_name, $selector);
}

function _att($attribute_name, $selector) { // alias
	return O::get_attribute_value($attribute_name, $selector);
}

function __att($attribute_name, $new_value, $selector) { // alias
	return O::set_attribute($attribute_name, $new_value, $selector);
}

function __attr($attribute_name, $new_value, $selector) { // alias
	return O::set_attribute($attribute_name, $new_value, $selector);
}

function __attribute($attribute_name, $new_value, $selector) { // alias
	return O::set_attribute($attribute_name, $new_value, $selector);
}

function set_att($attribute_name, $new_value, $selector) { // alias
	return O::set_attribute($attribute_name, $new_value, $selector);
}

function set_attr($attribute_name, $new_value, $selector) { // alias
	return O::set_attribute($attribute_name, $new_value, $selector);
}

function add_attribute($attribute_name, $new_value, $selector) {
	return O::set_attribute($attribute_name, $new_value, $selector);
}

function set_attribute($attribute_name, $new_value, $selector) {
	$attribute_name = (string)$attribute_name;
	$new_value = (string)$new_value;
	//print('$attribute_name, $new_value, $selector before swapping in set_attribute: ');var_dump($attribute_name, $new_value, $selector);
	if(is_array($attribute_name) && !is_array($new_value) && !is_array($selector)) { // swap them
		$temp_selector = $selector;
		$selector = $attribute_name;
		$attribute_name = $temp_selector;
	}
	if(is_array($new_value) && !is_array($attribute_name) && !is_array($selector)) { // swap them
		$temp_selector = $selector;
		$selector = $new_value;
		$new_value = $temp_selector;
	}
	//print('is_array($selector[0]), strpos($selector[0], $new_value), strpos($selector[0], $attribute_name), strpos($selector[0][0], $new_value), strpos($selector[0][0], $attribute_name), strpos(\'<folder name="Race" modified="1516310318" timesaccessed="not here yet"></folder>\', 71216) mid swapping: ');var_dump(is_array($selector[0]), strpos($selector[0], $new_value), strpos($selector[0], $attribute_name), strpos($selector[0][0], $new_value), strpos($selector[0][0], $attribute_name), strpos('<folder name="Race" modified="1516310318" timesaccessed="not here yet"></folder>', 71216));
	/*if(!is_numeric($new_value) && (!is_array($selector[0]) && strpos($selector[0], $new_value) !== false && strpos($selector[0], $attribute_name) === false) || (is_array($selector[0]) && strpos($selector[0][0], $new_value) !== false && strpos($selector[0][0], $attribute_name) === false)) { // swap them
		$temp_new_value = $new_value;
		$new_value = $attribute_name;
		$attribute_name = $temp_new_value;
	}*/
	$selector_matches = O::get_tagged($selector);
	//print('$attribute_name, $new_value, $selector, $selector_matches after swapping in set_attribute: ');var_dump($attribute_name, $new_value, $selector, $selector_matches);
	foreach($selector_matches as $index => $value) {
		$offset = $value[1];
		$tagname = O::tagname($value[0]);
		$this->code = O::set_tag_attribute($this->code, $attribute_name, $new_value, $tagname, $offset);
		if(O::has_attribute($attribute_name, $value[0])) {
			$offset_adjust = strlen($new_value) - O::get_attribute($attribute_name, $value[0]);
		} else {
			$new_attribute_string = ' ' . $attribute_name . '="' . $new_value . '"';
			$offset_adjust = strlen($new_attribute_string);
		}
		//print('$attribute_name, $new_value, $offset, $offset_adjust, $tagname, $value[0] in set_attribute: ');var_dump($attribute_name, $new_value, $offset, $offset_adjust, $tagname, $value[0]);
		//print('before adjust_offset_depths in set_attribute<br>');
		O::adjust_offset_depths($offset + 1, $offset_adjust); // +1 since we're dealing with attributes after the start of the tag
		$selector_matches[$index][0] = O::set_tag_attribute($selector_matches[$index][0], $attribute_name, $new_value, $tagname, $offset, $this->context[sizeof($this->context) - 1][3][$index]); // assumes that get_tagged above put the offest_depths we want into the context
		foreach($selector_matches as $index2 => $value2) {
			if($selector_matches[$index2][1] > $offset) { // > instead of >= since we're dealing with attributes after the start of the tag
				$selector_matches[$index2][1] += $offset_adjust;
			}
		}
		//print('here376561<br>');
		if($this->use_context) {
			foreach($this->context as $context_index => $context_value) {
				if($context_value[1] !== false) {
					foreach($context_value[1] as $context1_index => $context1_value) {
						if($context1_value[0] <= $offset && $context1_value[0] + $context1_value[1] > $offset) {
							$this->context[$context_index][1][$context1_index][1] += $offset_adjust;
						} elseif($context1_value[0] > $offset) { // > instead of >= since we're dealing with attributes after the start of the tag
							$this->context[$context_index][1][$context1_index][0] += $offset_adjust;
						}
						//if($context1_value[1] >= $offset) {
						//	$this->context[$context_index][1][$context1_index][1] += $offset_adjust;
						//}
					}
				}
				foreach($context_value[2] as $context2_index => $context2_value) {
					if($context2_value[0] <= $offset && $context2_value[0] + $context2_value[1] > $offset) {
						$this->context[$context_index][2][$context2_index][1] += $offset_adjust;
					} elseif($context2_value[0] > $offset) { // > instead of >= since we're dealing with attributes after the start of the tag
						$this->context[$context_index][2][$context2_index][0] += $offset_adjust;
					}
					//if($context2_value[1] >= $offset) {
					//	$this->context[$context_index][2][$context2_index][1] += $offset_adjust;
					//}
				}
			}
		}
		//print('$this->context in set_attribute: ');var_dump($this->context);
	}
	//print('$this->code, $selector_matches at the end of set_attribute: ');var_dump($this->code, $selector_matches);
	if(sizeof($selector_matches) === 1) { // questionable
		return $selector_matches[0];
	} else {
		return $selector_matches;
	}
}

function set_tag_attribute($code, $attribute_name, $attribute_value, $tagname = false, $offset = 0, $offset_to_add = 0, $offset_depths = false) {
	//print('$code, $attribute_name, $attribute_value, $offset, $tagname: ');var_dump($code, $attribute_name, $attribute_value, $offset, $tagname);
	if($code[$offset] !== '<') {
		print('$code, $attribute_name, $attribute_value, $offset, $tagname: ');var_dump($code, $attribute_name, $attribute_value, $offset, $tagname);
		O::fatal_error('set_tag_attribute was unable to find the tag to set the attribute of.');
	}
	if($tagname === false) {
		O::fatal_error('please provide set_tag_attribute with a tagname.');
	}
	if($offset_depths === false) {
		$offset_depths = $this->offset_depths;
	}
	//print('$code, $attribute_name, $attribute_value, $offset, $tagname in set_tag_attribute: ');var_dump($code, $attribute_name, $attribute_value, $offset, $tagname);
	if($offset === 0) {
		$initial_opening_tag_string = $opening_tag_string = substr($code, 0, strpos($code, '>') + 1);
	} else {
		$expanded_LOM = O::expand($code, $offset, $offset_to_add, $offset_depths, 'lazy');
		//print('$expanded_LOM in set_tag_attribute: ');var_dump($expanded_LOM);
		$initial_opening_tag_string = $opening_tag_string = $expanded_LOM[1][0];
	}
	$initial_opening_tag_string = $opening_tag_string = substr($opening_tag_string, 0, strpos($opening_tag_string, '>') + 1); // since the expanded part could have children tags
	$opening_tag_string = preg_replace('/ ' . $attribute_name . '="[^"]{0,}"/is', '', $opening_tag_string); // not accounting for attributes without attribute values or single quotes
	$opening_tag_string = preg_replace('/(<' . $this->tagname_regex . ')([^<>]{0,})([\s\/]{0,}>)/is', '$1 ' . $attribute_name . '="' . $attribute_value . '"$2$3', $opening_tag_string);
	//print('$initial_opening_tag_string, $opening_tag_string in set_tag_attribute: ');var_dump($initial_opening_tag_string, $opening_tag_string);
	return substr($code, 0, $offset) . $opening_tag_string . substr($code, $offset + strlen($initial_opening_tag_string));
}

function increment_attribute($attribute_name, $selector) {
	return O::set_attribute($attribute_name, O::get_attribute($attribute_name, $selector) + 1, $selector);
	/*if(is_array($attribute_name) && !is_array($selector)) { // swap them
		$temp_selector = $selector;
		$selector = $attribute_name;
		$attribute_name = $temp_selector;
	}*/
	//print('$selector in increment_attribute: ');var_dump($selector);
	/*if(!is_array($selector)) { // assume it's an index
		$index = $selector;
	} else {
		foreach($selector as $index => $value) { break; } 
		if(is_array($value)) {
			foreach($value as $index => $value) { break; } 
		}
	}
	//print('$this->LOM[$index] in increment_attribute: ');var_dump($this->LOM[$index]);
	//print('$this->LOM[$index][1][1] in increment_attribute: ');var_dump($this->LOM[$index][1][1]);
	$this->LOM[$index][1][1][$attribute_name]++;
	if($this->use_context) {
		foreach($this->context as $context_index => $context_value) {
			if(is_array($context_value[3])) {
				foreach($context_value[3] as $context_index2 => $context_value2) {
					if(sizeof($this->context[$context_index][$index][1][1]) > 0) {
						$this->context[$context_index][$index][1][1][$attribute_name]++;
					}
				}
			}
		}
	}*/
	/*if(!is_array($selector)) { // assume it's an index
		$index = $selector;
	} else {
		//foreach($selector as $index => $value) { break; } 
		//if(is_array($value)) {
		//	foreach($value as $index => $value) { break; } 
		//}
		if(is_array($selector[0])) {
			$index = O::opening_LOM_index_from_offset($selector[0][1]);
		} else {
			$index = O::opening_LOM_index_from_offset($selector[1]);
		}
	}
	$new_value = (string)((int)$this->LOM[$index][1][1][$attribute_name] + 1);
	if(isset($this->LOM[$index][1][1][$attribute_name])) {
		$offset_adjust = strlen($new_value) - strlen($this->LOM[$index][1][1][$attribute_name]);
	} else {
		$new_attribute_string = ' ' . $attribute_name . '="' . $new_value . '"';
		$offset_adjust = strlen($new_attribute_string);
	}
	//$this->code = O::set_tag_attribute($this->code, $attribute_name, $new_value, $this->LOM[$index][2], $this->LOM[$index][1][0]);
	$this->LOM[$index][1][1][$attribute_name] = $new_value;
	foreach($this->LOM as $LOM_index => $LOM_value) {
		if($LOM_index > $index) {
			$this->LOM[$LOM_index][2] += $offset_adjust;
		}
	}
	if($this->use_context) {
		foreach($this->context as $context_index => $context_value) {
			if($context_value[1] !== false) {
				foreach($context_value[1] as $context1_index => $context1_value) {
					if($context1_value[1] === $this->LOM[$index][2] && strlen($context1_value[0]) > 0) {
						$this->context[$context_index][1][$context1_index][0] = O::set_tag_attribute($this->context[$context_index][1][$context1_index][0], $attribute_name, $new_value, $this->LOM[$index][2], $this->LOM[$index][1][0]);
					} elseif($context1_value[1] > $this->LOM[$index][2]) {
						$this->context[$context_index][1][$context1_index][1] += $offset_adjust;
					}
				}
			}
			foreach($context_value[2] as $context2_index => $context2_value) {
				if($context2_value > $this->LOM[$index][2]) {
					$this->context[$context_index][2][$context2_index] += $offset_adjust;
				}
			}
			if(is_array($context_value[3]) && is_array($context_value[3][0])) {
				foreach($context_value[3] as $context3_index => $context3_value) {
					if($context3_value[1] === $this->LOM[$index][2] && strlen($context3_value[0]) > 0) {
						$this->context[$context_index][3][$context3_index][0] = O::set_tag_attribute($this->context[$context_index][3][$context3_index][0], $attribute_name, $new_value, $this->LOM[$index][2], $this->LOM[$index][1][0]);
					} elseif($context3_value[1] > $this->LOM[$index][2]) {
						$this->context[$context_index][3][$context3_index][1] += $offset_adjust;
					}
				}
			}
		}
	}
	return true;*/
}

function decrement_attribute($attribute_name, $selector) {
	return O::set_attribute($attribute_name, O::get_attribute($attribute_name, $selector) - 1, $selector);
}

function select($selector, $matching_array = false, $offset_depths = false) {
	//print('start of select()<br>');
	if($matching_array === false) {
		$matching_array = array(array($this->code, 0));
	}
	if($offset_depths === false) {
		//$offset_depths = $this->offset_depths;
		$offset_depths = array();
		foreach($matching_array as $index => $value) {
			$offset_depths[] = $this->offset_depths;
		}
	}
	if(O::all_entries_are_arrays($matching_array)) {
		
	} else {
		$matching_array = array($matching_array);
	}
	//print('$selector, $matching_array, $offset_depths in select: ');var_dump($selector, $matching_array, $offset_depths);
	O::parse_selector_string($selector);
	//print('$selector, $this->selector_piece_sets: ');var_dump($selector, $this->selector_piece_sets);
	$selector_matches = array();
	foreach($matching_array as $index => $value) {
		$selector_matches = array_merge($selector_matches, O::preg_select($value[0], $value[1], $offset_depths[$index]));
	}
	/*print('$offset_depths in select before testing format: ');var_dump($offset_depths);
	if(O::all_entries_are_arrays($offset_depths)) {
		
	} else {
		$new_offset_depths = array();
		foreach($selector_matches as $index => $value) {
			$new_offset_depths[] = O::get_offset_depths(substr($this->code, $selector_matches[$index][1])); // depths aren't correct but that's probably fine for the purposes of get_tag_string
		}
		$offset_depths = $new_offset_depths;
	}*/
	//print('$selector_matches, $offset_depths in select before get_tag_string: ');var_dump($selector_matches, $offset_depths);
	foreach($selector_matches as $index => $value) {
		//$selector_matches[$index][0] = O::get_tag_string(substr($this->code, $selector_matches[$index][1]), strlen($selector_matches[$index][0]), $selector_matches[$index][1]);
		//$selector_matches[$index][0] = O::get_tag_string($selector_matches[$index][1], O::get_offset_depths(substr($this->code, $selector_matches[$index][1]))); // hard to say whether it's more efficient to make a new offset_depths array or just use that of the whole code, in this instance
		$selector_matches[$index][0] = O::get_tag_string($selector_matches[$index][1], $this->offset_depths); // I'd have to guess that some extraneous looping over an array will on average be faster than making an array that's more specific every time
		// if you want to get real fancy, then consider some condition like "if we're near the end of the code"... but how to make that exact?
	}
	//print('$selector_matches at the end of select: ');var_dump($selector_matches);
	return $selector_matches;
}

function preg_select($code = false, $offset_to_add = 0, $offset_depths = false) {
	//O::warning_once('preg_select needs to become a lot more sophisticated; including handling all the query syntax and expanding under the condition of finding a full result, look only in direct children, should check again that $this->code is not used, etc.');
	if($code === false) {
		$code = $this->code;
	}
	if($offset_depths === false) {
		$offset_depths = $this->offset_depths;
	}
	//print('$code, $offset_to_add, $offset_depths at the start of preg_select: ');var_dump($code, $offset_to_add, $offset_depths);
	$selector_matches = array();
	//$this->saved_tagname_indices = false;
	$this->saved_tagvalue_indices = false;
	$this->saved_attributes_indices = false;
	foreach($this->selector_piece_sets as $this->selector_piece_set_index => $this->selector_pieces) {
		$this->selected_parent_matches = false;
		$this->selected_parent_piece_index = false;
		$this->selector_scopes = $this->selector_scope_sets[$this->selector_piece_set_index];
		$selector_matches = array_merge($selector_matches, O::recursive_select($code, $offset_to_add, 0, $offset_depths, $offset_depths[$offset_to_add]));
	}
	//print('$this->saved_tagname_indices, $this->saved_tagvalue_indices, $this->saved_attributes_indices: ');var_dump($this->saved_tagname_indices, $this->saved_tagvalue_indices, $this->saved_attributes_indices);
	//if($this->saved_tagname_indices !== false) {
	//	if(sizeof($this->saved_tagname_indices) > 1) {
	//		O::fatal_error('sizeof($this->saved_tagname_indices) > 1 is not coded for yet');
	//	}
	//	$selector_matches = array($selector_matches[$this->saved_tagname_indices[0]]);
	//}
	if($this->saved_tagvalue_indices !== false) {
		if(sizeof($this->saved_tagvalue_indices) > 1) {
			O::fatal_error('sizeof($this->saved_tagvalue_indices) > 1 is not coded for yet');
		}
		$selector_matches = array($selector_matches[$this->saved_tagvalue_indices[0]]);
	}
	if($this->saved_attributes_indices !== false) {
		if(sizeof($this->saved_attributes_indices) > 1) {
			O::fatal_error('sizeof($this->saved_attributes_indices) > 1 is not coded for yet');
		}
		$selector_matches = array($selector_matches[$this->saved_attributes_indices[0]]);
	}
	//print('$selector_matches at the end of preg_select: ');var_dump($selector_matches);
	return $selector_matches;
}

function recursive_select($code, $offset_to_add = 0, $selector_piece_index = 0, $offset_depths = false, $parent_depth = false) { // terrible nomenclature for these select functions?
	if($offset_depths === false) {
		$offset_depths = $this->offset_depths;
	}
	if($parent_depth === false) {
		$parent_depth = O::depth($offset_to_add, $offset_depths);
	}
	O::parse_selector_piece($this->selector_pieces[$selector_piece_index], $selector_piece_index);
	//print('$selector_piece_index, $this->selector_pieces, $this->selector_pieces[$selector_piece_index]: ');var_dump($selector_piece_index, $this->selector_pieces, $this->selector_pieces[$selector_piece_index]);
	//print('$this->selector_scopes, $this->tagnames, $this->tagname_indices, $this->tagvalues, $this->tagvalue_indices, $this->required_attribute_sets, $this->attributes_indices: ');var_dump($this->selector_scopes, $this->tagnames, $this->tagname_indices, $this->tagvalues, $this->tagvalue_indices, $this->required_attribute_sets, $this->attributes_indices);
	// what happens if multiple of $this->tagname_indices, $this->tagvalue_indices, $this->attributes_indices are specified in the same selector? this needs more work especially since we're hacking at the end for $this->tagvalue_indices and $this->attributes_indices
	if(sizeof($this->required_attributes) > 1) {
		O::fatal_error('preg requires more complex code to handle all the possible orders of attributes. is this enough to not use preg for this particular select operation?');
	}
	// it probably really hurts performance to use preg_match_all instead of preg_match stopping on the first match; oh well, it was nice while it lasted
	preg_match_all('/<(' . $this->tagname_regex . ')([^<>]*?)>/', $code, $matches, PREG_OFFSET_CAPTURE);
	//$this->zero_offsets = array();
	//print('$code, $offset_to_add, $offset_depths, $matches, $parent_depth at the start of recursive_select: ');var_dump($code, $offset_to_add, $offset_depths, $matches, $parent_depth);
	//print('$this->selector_scopes: ');var_dump($this->selector_scopes);
	//print('$this->tagnames, $this->tagvalues, $this->required_attribute_sets: ');var_dump($this->tagnames, $this->tagvalues, $this->required_attribute_sets);
	//O::warning_once('matched_scope not coded');	
	//print('$this->tagname_indices: ');var_dump($this->tagname_indices);
	if(sizeof($this->tagnames) > 1 && $this->selector_scopes[$selector_piece_index] !== 'direct') {
		O::fatal_error('how to match multiple tags together when not only looking in direct scope is not coded');
	}
	foreach($this->tagnames as $tagname_index => $tagname) {
		$tagname_match_counter[$tagname_index] = 0;
		//$tagvalue_match_counter[$tagname_index] = 0;
		//$attributes_match_counter[$tagname_index] = 0;
	}
	foreach($matches[0] as $index => $value) {
		//print('$value: ');var_dump($value);
		$matched_tagname = false;
		$matched_scope = false;
		$matched_tagvalue = false;
		$matched_attributes = false;
		foreach($this->tagnames as $tagname_index => $tagname) {
			//print('$tagname, $matches[1][$index][0]: ');var_dump($tagname, $matches[1][$index][0]);
			if($matches[1][$index][0] === $tagname || $tagname === '*') {
				//print('matched_tagname<br>');
				$matched_tagname = true;
				//if($this->tagname_indices[$tagname_index] !== false) {
				//	$this->saved_tagname_indices = $this->tagname_indices;
				//}
				if($this->selector_scopes[$selector_piece_index] === 'direct') { // matching scope is logically prior than matching tagname but it's more processing time so it's after in the code
					//print('$code, $matches[0][$index][1], $offset_to_add, O::depth($matches[0][$index][1] + $offset_to_add), $parent_depth: ');var_dump($code, $matches[0][$index][1], $offset_to_add, O::depth($matches[0][$index][1] + $offset_to_add), $parent_depth);
					//print('O::depth($matches[0][$index][1] + $offset_to_add), $parent_depth: ');var_dump(O::depth($matches[0][$index][1] + $offset_to_add), $parent_depth);
					//if(O::depth($code, $matches[0][$index][1], $offset_to_add) === 0) {
					//if(O::depth($matches[0][$index][1] + $offset_to_add) === 0) {
					/*if($selector_piece_index === 0 && O::depth($matches[0][$index][1] + $offset_to_add) === $parent_depth) {
						$matched_scope = true;
					} else*/if(O::depth($matches[0][$index][1] + $offset_to_add, $offset_depths) === $parent_depth + 1) {
						$matched_scope = true;
					}
				} else {
					$matched_scope = true;
				}
				if($matched_scope) {
					//print('$tagname, $this->tagname_indices, $tagname_match_counter in matched_scope: ');var_dump($tagname, $this->tagname_indices, $tagname_match_counter);
					if($this->tagname_indices[$tagname_index] !== false && $tagname_match_counter[$tagname_index] == $this->tagname_indices[$tagname_index]) {
						//print('here290675<br>');
						$tagname_match_counter2 = 0;
						foreach($matches[0] as $index => $value) {
							//print('here290676<br>');
							if($matches[1][$index][0] === $tagname) {
								//print('here290677<br>');
								if($tagname_match_counter2 === $this->tagname_indices[$tagname_index]) {
									//print('here290678<br>');
								} else {
									//print('here290679<br>');
									unset($matches[0][$index]);
								}
								$tagname_match_counter2++;
							}
						}
						//print('$matches[0] after tagname index matching: ');var_dump($matches[0]);
						//continue 2;
					}
					$tagname_match_counter[$tagname_index]++;
					$matched_tagvalue = false;
					if($this->tagvalues[$tagname_index] === false) {
						$matched_tagvalue = true;
					} else {
						$expanded_LOM = O::expand($code, $matches[0][$index][1] + strlen($matches[0][$index][0]), $offset_to_add, $offset_depths, 'lazy');
						$tagvalue = $expanded_LOM[1][0];
						//print('$this->tagvalues[$tagname_index], $tagvalue: ');var_dump($this->tagvalues[$tagname_index], $tagvalue);
						if($this->tagvalues[$tagname_index] === $tagvalue) {
							$matched_tagvalue = true;
						}
					}
					if($matched_tagvalue) {
						if($this->tagvalue_indices[$tagname_index] !== false) {
							$this->saved_tagvalue_indices = $this->tagvalue_indices;
						}
						/*if($this->tagvalue_indices[$tagname_index] !== false && $tagvalue_match_counter[$tagname_index] == $this->tagvalue_indices[$tagname_index]) {
							$tagvalue_match_counter2 = 0;
							foreach($matches[0] as $index => $value) {
								if($matches[1][$index][0] === $tagname) {
									if($tagvalue_match_counter2 === $this->tagvalue_indices[$tagname_index]) {
										
									} else {
										unset($matches[0][$index]);
									}
									$tagvalue_match_counter2 = 0;
								}
							}
							//continue 2;
						}
						$tagvalue_match_counter[$tagname_index]++;*/
						if(sizeof($this->required_attribute_sets[$tagname_index]) === 0) {
							$matched_attributes = true;
						} else {
							$attributes_string = $matches[2][$index][0];
							preg_match_all('/ (' . $this->attributename_regex . ')="([^"]+)"/', $attributes_string, $existing_attributes);
							foreach($this->required_attribute_sets[$tagname_index] as $required_attribute_name => $required_attribute_value) {
								$matched_required_attribute = false;
								if($required_attribute_value === false) {
									foreach($existing_attributes[1] as $existing_attribute_name) {
										if($existing_attribute_name === $required_attribute_name) {
											$matched_required_attribute = true;
											break;
										}
									}
								} else {
									foreach($existing_attributes[1] as $existing_attribute_index => $existing_attribute_name) {
										$existing_attribute_value = $existing_attributes[2][$existing_attribute_index];
										//print('$existing_attribute_name, $required_attribute_name, $existing_attribute_value, $required_attribute_value: ');var_dump($existing_attribute_name, $required_attribute_name, $existing_attribute_value, $required_attribute_value);
										if($existing_attribute_name === $required_attribute_name && $existing_attribute_value === $required_attribute_value) {
											$matched_required_attribute = true;
											break;
										}
									}
								}
								if(!$matched_required_attribute) {
									$matched_attributes = false;
									break;
								}
								$matched_attributes = true;
							}
						}
						if($matched_attributes) {
							if($this->attributes_indices[$tagname_index] !== false) {
								$this->saved_attributes_indices = $this->attributes_indices;
							}
							if($this->attributes_indices[$tagname_index] !== false && $attributes_match_counter[$tagname_index] == $this->attributes_indices[$tagname_index]) {
								$attributes_match_counter2 = 0;
								foreach($matches[0] as $index => $value) {
									if($matches[1][$index][0] === $tagname) {
										if($attributes_match_counter2 === $this->attributes_indices[$tagname_index]) {
											
										} else {
											unset($matches[0][$index]);
										}
										$attributes_match_counter2++;
									}
								}
								//continue 2;
							}
							$attributes_match_counter[$tagname_index]++;
						}
					}
				}
			}
		}
		//print('$matched_tagname, $matched_scope, $matched_tagvalue, $matched_attributes: ');var_dump($matched_tagname, $matched_scope, $matched_tagvalue, $matched_attributes);
		if($matched_tagname && $matched_scope && $matched_tagvalue && $matched_attributes) {
			
		} else {
			//print('unsetting due to unmatched tagname or scope<br>');
			unset($matches[0][$index]);
		}
	}
	if(sizeof($this->tagnames) > 1) { // pretty crude
		if(sizeof($matches[0]) === sizeof($this->tagnames)) {
			
		} else {
			$matches[0] = array();
		}
	}
	//O::warning_once('need to really think about whether matched_index should match the tagname or the tagname with a tagvalue or the tagname with a tagvalue with an attribute set. these would be non-traditional uses but that could mean they become interesting, if rare in application');
	//$matched_index = true;
	$matches[0] = array_values($matches[0]);
	//print('$matches mid recursive_select: ');var_dump($matches);
	if(sizeof($matches[0]) === 0) {
		return array();
	}
	if(sizeof($this->selector_pieces) > 1) { // for when the selection operator . is ised even though there's only one selector piece
		if($selector_piece_index === $this->selected_parent_piece_index) {
			$this->selected_parent_matches = $matches[0];
			$this->selected_parent_offset_depths = $offset_depths;
			foreach($this->selected_parent_matches as $index => $value) {
				$this->selected_parent_matches[$index][1] += $offset_to_add;
			}
		}
	}
	//print('$selector_piece_index, sizeof($this->selector_pieces), $this->selected_parent_matches: ');var_dump($selector_piece_index, sizeof($this->selector_pieces), $this->selected_parent_matches);
	if($selector_piece_index === sizeof($this->selector_pieces) - 1) {
		foreach($matches[0] as $index => $value) {
			$matches[0][$index][1] += $offset_to_add;
		}
		if($this->selected_parent_matches !== false) {
			$matches_at_last_tag = $matches[0];
			//print('$matches_at_last_tag, $this->selected_parent_matches, $offset_to_add when selecting parent: ');var_dump($matches_at_last_tag, $this->selected_parent_matches, $offset_to_add);
			$selected_parent_full_selector_matches = array();
			foreach($matches_at_last_tag as $matches_at_last_tag_index => $matches_at_last_tag_value) {
				//print('$matches_at_last_tag_value: ');var_dump($matches_at_last_tag_value);
				$best_match = false; // ugly?
				foreach($this->selected_parent_matches as $selected_parent_matches_index => $selected_parent_matches_value) {
					if($selected_parent_matches_value[1] > $matches_at_last_tag_value[1] + $offset_to_add) {
						break;
					}
					//print('$matches_at_last_tag_value, $offset_to_add, $selected_parent_matches_value: ');var_dump($matches_at_last_tag_value, $offset_to_add, $selected_parent_matches_value);
					//$child_offset = $matches_at_last_tag_value[1] + $offset_to_add;
					$child_offset = $matches_at_last_tag_value[1];
					$parent_offset = $selected_parent_matches_value[1];
					//$parent_full_string = O::get_tag_string($this->code, $parent_offset + $child_offset, $parent_offset);
					//$parent_full_string = O::get_tag_string(substr($this->code, $parent_offset), strlen($selected_parent_matches_value[0]), $parent_offset);
					$parent_full_string = O::get_tag_string($parent_offset, $this->selected_parent_offset_depths); // would have to be pretty fancy to keep track of $offset_depths for the parent that will end up being selected
					//print('$child_offset, $parent_offset, $parent_offset + strlen($parent_full_string), $parent_full_string: ');var_dump($child_offset, $parent_offset, $parent_offset + strlen($parent_full_string), $parent_full_string);
					if($child_offset >= $parent_offset && $child_offset <= $parent_offset + strlen($parent_full_string)) { // match at last tag is within selected parent
						//if($matches_at_last_tag_value === $selected_parent_matches_value) { // again, ugly but probably works
						//if($matches_at_last_tag_value[0] === $selected_parent_matches_value[0] && $matches_at_last_tag_value[1] + $offset_to_add === $selected_parent_matches_value[1]) { // again, ugly but probably works
						if($matches_at_last_tag_value[0] === $selected_parent_matches_value[0] && $matches_at_last_tag_value[1] === $selected_parent_matches_value[1]) { // again, ugly but probably works
							
						} else {							
							$best_match = $selected_parent_matches_value;
						}
						//continue 2;
					}
				}
				//print('$best_match: ');var_dump($best_match);
				if($best_match === false) {
					print('$matches_at_last_tag: ');var_dump($matches_at_last_tag);
					O::fatal_error('should never not find a selected parent');
					//O::warning('should never not find a selected parent'); // some wierdness, maybe with having an attribute on the last tag?
					//$selected_parent_full_selector_matches = $matches_at_last_tag;
				} else {
					$selected_parent_full_selector_matches[] = $best_match;
				}
			}
			$selected_parent_full_selector_matches = array_unique($selected_parent_full_selector_matches); // for & in the selector
			$selected_parent_full_selector_matches = array_values($selected_parent_full_selector_matches);
			//print('$selected_parent_full_selector_matches: ');var_dump($selected_parent_full_selector_matches);exit(0);
			//$selector_piece_set_matches = $selected_parent_full_selector_matches;
			return $selected_parent_full_selector_matches;
		}
		return $matches[0];
	}
	$selector_matches = array();
	foreach($matches[0] as $index => $value) {
		$offset = $matches[0][$index][1] + strlen($matches[0][$index][0]);
		//$offset += strpos($code, '<', $offset);
		$expanded_LOM = O::expand($code, $offset, $offset_to_add, $offset_depths, 'greedy');
		//$expanded_LOM = O::expand($code, $offset, $matches[0][$index][1]);
		//$offset_to_add = $expanded_LOM[0][1] + strlen($expanded_LOM[0][0]);
		//$offset_to_add2 = $expanded_LOM[1][1] + $offset_to_add;
		$offset_to_add2 = $expanded_LOM[1][1];
		//print('$expanded_LOM: ');var_dump($expanded_LOM);
		$code2 = $expanded_LOM[1][0];
		$parent_depth = $expanded_LOM[2];
		//print('$code2, $offset, $offset_to_add: ');var_dump($code2, $offset, $offset_to_add);
		//print('$code at the end of recursive_select: ');var_dump($code);
		//return O::recursive_select($code, $offset_to_add);
		$selector_matches = array_merge($selector_matches, O::recursive_select($code2, $offset_to_add2, $selector_piece_index + 1, O::get_offset_depths($code2, $offset_to_add2, $parent_depth + O::depth($offset_to_add2, $offset_depths) - $parent_depth), $parent_depth));
	}
	//foreach($selector_matches as $index => $value) {
	//	$selector_matches[$index][1] += $offset_to_add;
	//}
	return $selector_matches;
}

function parse_selector_piece($piece, $selector_piece_index) {
	$piece_offset = 0;
	$this->tagnames = array();
	$tagname = '';
	$attribute_name_piece = '';
	$attribute_value_piece = '';
	$this->tagvalues = false;
	$tagvalue = false;
	$this->tagname_indices = false;
	$this->tagvalue_indices = false;
	$this->attributes_indices = false;
	$tagname_index = false;
	$tagvalue_index = false;
	$attributes_index = false;
	$parsing_attribute_name = false;
	$parsing_attribute_value = false;
	$this->required_attribute_sets = array();
	$required_attributes = array();
	// attribute systax doesn't use square brackets [ ] unlike XPath; tagname1[5]=tagvalue1[17]@attname1=attvalue1@attname2=attvalue2@attname3=attvalue3[0]&tagname2[3]=tagvalue2[0]@attname4=attvalue4@attname5=attvalue5[8]
	while($piece_offset < strlen($piece)) {
		if($parsing_attribute_name) {
			if($piece[$piece_offset] === '@') {
				$required_attributes[O::query_decode($attribute_name_piece)] = false;
				$attribute_name_piece = '';
				$piece_offset++;
				continue;
			} elseif($piece[$piece_offset] === '=') {
				$parsing_attribute_name = false;
				$parsing_attribute_value = true;
				$piece_offset++;
				continue;
			} elseif($piece[$piece_offset] === '[') {
				$possible_index_length = strpos($piece, ']') - $piece_offset - 1;
				$possible_index = substr($piece, $piece_offset + 1, $possible_index_length);
				$attributes_index = (int)$possible_index;
				$piece_offset += $possible_index_length + 2;
				continue;
			}
			$attribute_name_piece .= $piece[$piece_offset];
			$piece_offset++;
			continue;
		} elseif($parsing_attribute_value) {
			if($piece[$piece_offset] === '@') {
				$required_attributes[O::query_decode($attribute_name_piece)] = O::query_decode($attribute_value_piece);
				$parsing_attribute_name = true;
				$parsing_attribute_value = false;
				$attribute_name_piece = '';
				$attribute_value_piece = '';
				$piece_offset++;
				continue;
			} elseif($piece[$piece_offset] === '[') {
				$possible_index_length = strpos($piece, ']') - $piece_offset - 1;
				$possible_index = substr($piece, $piece_offset + 1, $possible_index_length);
				$attributes_index = (int)$possible_index;
				$piece_offset += $possible_index_length + 2;
				continue;
			}
			$attribute_value_piece .= $piece[$piece_offset];
			$piece_offset++;
			continue;
		} elseif($piece[$piece_offset] === '=') { // then we have the tagname and we find the specified tagvalue
			$piece_offset++;
			$tagvalue = '';
			while($piece_offset < strlen($piece) && $piece[$piece_offset] !== '@' && $piece[$piece_offset] !== '&' && $piece[$piece_offset] !== '[') {
				$tagvalue .= $piece[$piece_offset];
				$piece_offset++;
			}
			if($piece[$piece_offset] === '[') {
				$possible_index_length = strpos($piece, ']') - $piece_offset - 1;
				$possible_index = substr($piece, $piece_offset + 1, $possible_index_length);
				$tagvalue_index = (int)$possible_index;
				$piece_offset += $possible_index_length + 2;
				continue;
			}
			continue;
		} elseif($piece[$piece_offset] === '[') {
			$possible_index_length = strpos($piece, ']') - $piece_offset - 1;
			$possible_index = substr($piece, $piece_offset + 1, $possible_index_length);
			$tagname_index = (int)$possible_index;
			$piece_offset += $possible_index_length + 2;
			continue;
		} elseif($piece[$piece_offset] === '@') {
			if($piece_offset === 0) {
				print('$piece: ');var_dump($piece);
				O::fatal_error('trying to select an attribute in a system (Logical Object Model (LOM)) where attributes are properties of tags rather than standing on their own.');
			} else {
				$parsing_attribute_name = true;
				$piece_offset++;
				continue;
			}
		} elseif($piece[$piece_offset] === '&') {
			if($piece_offset === 0) {
				print('$piece: ');var_dump($piece);
				O::fatal_error('query piece starting with &amp; makes no sense.');
			} else {
				if($tagname[0] === '.') {
					$tagname = substr($tagname, 1);
					$this->selected_parent_piece_index = $selector_piece_index;
				}
				$this->tagnames[] = O::query_decode($tagname);
				$tagname = '';
				$this->tagvalues[] = O::query_decode($tagvalue);
				$tagvalue = false;
				$this->tagname_indices[] = $tagname_index;
				$tagname_index = false;
				$this->tagvalue_indices[] = $tagvalue_index;
				$tagvalue_index = false;
				if($parsing_attribute_name) {
					$required_attributes[O::query_decode($attribute_name_piece)] = false;
				} elseif($parsing_attribute_value) {
					$required_attributes[O::query_decode($attribute_name_piece)] = O::query_decode($attribute_value_piece);
				}
				$this->required_attribute_sets[] = $required_attributes;
				$required_attributes = array();
				$this->attributes_indices[] = $attributes_index;
				$attributes_index = false;
				$piece_offset++;
				continue;
			}
		}
		$tagname .= $piece[$piece_offset];
		$piece_offset++;
	}
	if(strlen($tagname) > 0) {
		if($tagname[0] === '.') {
			$tagname = substr($tagname, 1);
			$this->selected_parent_piece_index = $selector_piece_index;
		}
		$this->tagnames[] = O::query_decode($tagname);
		$this->tagvalues[] = O::query_decode($tagvalue);
		$this->tagname_indices[] = $tagname_index;
		$this->tagvalue_indices[] = $tagvalue_index;
		if($parsing_attribute_name) {
			$required_attributes[O::query_decode($attribute_name_piece)] = false;
		} elseif($parsing_attribute_value) {
			$required_attributes[O::query_decode($attribute_name_piece)] = O::query_decode($attribute_value_piece);
		}
		$this->required_attribute_sets[] = $required_attributes;
		$this->attributes_indices[] = $attributes_index;
	}
}

function select_old($selector, $matching_array = false, $offset_depths = false) {
	//print('start of select()<br>');
	if($matching_array === false) {
		//print('here374859---0005.6<br>');
		//$code = array($this->LOM);
		//$this->code = O::code_from_LOM();
		//$matching_array = array(array($this->code, 0));
		//$matching_array = $this->LOM;
		$matching_array = array(array($this->code, 0));
	}
	if($offset_depths === false) {
		$offset_depths = $this->offset_depths;
	}
	if(O::all_entries_are_arrays($matching_array)) {
		
	} else {
		$matching_array = array($matching_array);
	}
	//print('$selector, $matching_array in select: ');var_dump($selector, $matching_array);
	O::parse_selector_string($selector);
	//print('$selector, $this->selector_piece_sets: ');var_dump($selector, $this->selector_piece_sets);
	$selector_matches = array();
	foreach($matching_array as $index => $value) {
		$selector_matches = array_merge($selector_matches, O::preg_select($value[0], $value[1], $offset_depths));
	}
	//print('$selector_matches in select before get_tag_string: ');var_dump($selector_matches);
	foreach($selector_matches as $index => $value) {
		//$selector_matches[$index][0] = O::get_tag_string(substr($this->code, $selector_matches[$index][1]), strlen($selector_matches[$index][0]), $selector_matches[$index][1]);
		$selector_matches[$index][0] = O::get_tag_string($selector_matches[$index][1], $offset_depths);
	}
	//print('$selector_matches at the end of select: ');var_dump($selector_matches);
	return $selector_matches;
	//print('$selector, $matching_array in select:');var_dump($selector, $matching_array);
	//print('$selector, $this->context in select:');var_dump($selector, $this->context);
	//print('$selector, $code in select:');var_dump($selector, $code);
	//print('$selector in select:');var_dump($selector);
	//if(is_string($code)) {
	//	return array();
	//}
	//if(is_array($code)) {
	//	return '';
	//}
	//print('$code at the start of select: ');var_dump($code);
	$selector_piece_sets = O::parse_selector_string($selector);
	//print('$selector_piece_sets, $parent_node, $code: ');var_dump($selector_piece_sets, $parent_node, $code);
	//print('$selector_piece_sets: ');var_dump($selector_piece_sets);
	$selector_matches = array();
	foreach($selector_piece_sets as $selector_piece_set_index => $selector_piece_set) {
		$selector_piece_set_matches = false;
		$selected_parent_matches = false;
		$selected_parent_piece_set_index = false;
		//print('here374859---0006<br>');
		$contextual_matches = $matching_array;
		$last_piece = 'Z'; // dummy initialization
		foreach($selector_piece_set as $piece_index => $piece) {
			//print('here374859---0007<br>');
			//print('$piece: ');var_dump($piece);
			// parse the piece
			//if($piece_index === 0 || strlen($last_piece) === 0 || strpos($last_piece, '*') !== false) {
			if($piece_index === 0 || strlen($last_piece) === 0) {
				//print('here374859---0008<br>');
				$look_only_in_direct_children = false;
			} else {
				//print('here374859---0008.5<br>');
				$look_only_in_direct_children = true;
			}
			$piece_offset = 0;
			$tagnames = array();
			$tagname = '';
			$attribute_name_piece = '';
			$attribute_value_piece = '';
			$tagvalues = false;
			$tagvalue = false;
			$matching_indices = false;
			$matching_index = false;
			//$parsing_attributes = false;
			$parsing_attribute_name = false;
			$parsing_attribute_value = false;
			$required_attribute_sets = array();
			$required_attributes = array();
			//print('here374859---0009<br>');
			// attribute systax doesn't use square brackets [ ] unlike XPath; tagname[5]=tagvalue@attname1=attvalue1@attname2=attvalue2@attname3=attvalue3
			while($piece_offset < strlen($piece)) {
				//print('here374859---0010<br>');
				if($parsing_attribute_name) {
					if($piece[$piece_offset] === '@') {
						$required_attributes[O::query_decode($attribute_name_piece)] = false;
						$attribute_name_piece = '';
						$piece_offset++;
						continue;
					} elseif($piece[$piece_offset] === '=') {
						$parsing_attribute_name = false;
						$parsing_attribute_value = true;
						$piece_offset++;
						continue;
					}
					$attribute_name_piece .= $piece[$piece_offset];
					$piece_offset++;
					continue;
				} elseif($parsing_attribute_value) {
					if($piece[$piece_offset] === '@') {
						$required_attributes[O::query_decode($attribute_name_piece)] = O::query_decode($attribute_value_piece);
						$parsing_attribute_name = true;
						$parsing_attribute_value = false;
						$attribute_name_piece = '';
						$attribute_value_piece = '';
						$piece_offset++;
						continue;
					}
					$attribute_value_piece .= $piece[$piece_offset];
					$piece_offset++;
					continue;
				} elseif($piece[$piece_offset] === '=') { // then we have the tagname and we find the specified tagvalue
					//print('here374859---0011<br>');
					//if($tagvalues === false) {
					//	$tagvalues = array();
					//}
					$piece_offset++;
					$tagvalue = '';
					while($piece_offset < strlen($piece) && $piece[$piece_offset] !== '@' && $piece[$piece_offset] !== '&') {
						//print('here374859---0011.5<br>');
						$tagvalue .= $piece[$piece_offset];
						$piece_offset++;
					}
					continue;
				} elseif($piece[$piece_offset] === '[') { // check whether we are selecting by order or by attribute
					//print('here374859---0012<br>');
					$possible_index_length = strpos($piece, ']') - $piece_offset - 1;
					$possible_index = substr($piece, $piece_offset + 1, $possible_index_length);
					if(is_numeric($possible_index)) {
						//print('here374859---0013<br>');
						$matching_index = $possible_index;
						$piece_offset += $possible_index_length + 2;
						continue;
					} else {
						//print('here374859---0031<br>');
						print('$possible_index: ');var_dump($possible_index);
						O::fatal_error('!is_numeric($possible_index)');
					}
				} elseif($piece[$piece_offset] === '@') {
					//print('here374859---0032<br>');
					if($piece_offset === 0) {
						print('$piece: ');var_dump($piece);
						O::fatal_error('trying to select an attribute in a system (Logical Object Model (LOM)) where attributes are properties of tags rather than standing on their own.');
					} else {
						$parsing_attribute_name = true;
						//$attribute_name_piece = '';
						$piece_offset++;
						continue;
					}
				} elseif($piece[$piece_offset] === '&') {
					//print('here374859---0032.5<br>');
					if($piece_offset === 0) {
						print('$piece: ');var_dump($piece);
						O::fatal_error('query piece starting with &amp; makes no sense.');
					} else {
						$tagnames[] = O::query_decode($tagname);
						$tagname = '';
						$tagvalues[] = O::query_decode($tagvalue);
						$tagvalue = false;
						$matching_indices[] = $matching_index;
						$matching_index = false;
						if($parsing_attribute_name) {
							$required_attributes[O::query_decode($attribute_name_piece)] = false;
						} elseif($parsing_attribute_value) {
							$required_attributes[O::query_decode($attribute_name_piece)] = O::query_decode($attribute_value_piece);
						}
						$required_attribute_sets[] = $required_attributes;
						$required_attributes = array();
						$piece_offset++;
						continue;
					}
				}
				$tagname .= $piece[$piece_offset];
				$piece_offset++;
			}
			//if($parsing_attributes) {
			//	$required_attributes[O::query_decode($attribute_name_piece)] = O::query_decode($attribute_piece);
			//}
			//print('$contextual_matches before match_by_tagname: ');var_dump($contextual_matches);
			if(strlen($tagname) > 0) {
				$tagnames[] = O::query_decode($tagname);
				$tagvalues[] = O::query_decode($tagvalue);
				$matching_indices[] = $matching_index;
				if($parsing_attribute_name) {
					$required_attributes[O::query_decode($attribute_name_piece)] = false;
				} elseif($parsing_attribute_value) {
					$required_attributes[O::query_decode($attribute_name_piece)] = O::query_decode($attribute_value_piece);
				}
				$required_attribute_sets[] = $required_attributes;
				//print('$tagnames, $contextual_matches, $look_only_in_direct_children, $tagvalues, $matching_indices, $required_attribute_sets: ');var_dump($tagnames, $contextual_matches, $look_only_in_direct_children, $tagvalues, $matching_indices, $required_attribute_sets);
				$contextual_matches = O::match_by_tagname($tagnames, $contextual_matches, $look_only_in_direct_children, $tagvalues, $matching_indices, $required_attribute_sets);
			}
			//print('$contextual_matches after match_by_tagname: ');var_dump($contextual_matches);
			//print('here374859---0033<br>');
			foreach($tagnames as $tagname) {
				if($tagname[0] === '.') {
					//print('here374859---0034<br>');
					$selected_parent_piece_index = $piece_index;
					$selected_parent_matches = $contextual_matches;
					break;
				}
			}
			// ensure we're always looking inside previous matches
			if($piece_index < sizeof($selector_piece_set) - 1) {
				foreach($contextual_matches as $index => $value) {
					$contextual_matches[$index][0] = substr($contextual_matches[$index][0], 1);
					$contextual_matches[$index][1]++;
				}
			}
			$last_piece = $piece;
		}
		$matches_at_last_tag = $contextual_matches;
		$selector_piece_set_matches = $contextual_matches;
		//print('here374859---0038<br>');
		//print('$selected_parent_matches before selected parent processing in select: ');O::var_dump_full($selected_parent_matches);
		//print('$selector_piece_set_matches before selected parent processing in select: ');var_dump($selector_piece_set_matches);
		if($selected_parent_matches !== false) {
			$selected_parent_full_selector_matches = array();
			foreach($matches_at_last_tag as $matches_at_last_tag_index => $matches_at_last_tag_value) {
				//print('$matches_at_last_tag_value: ');var_dump($matches_at_last_tag_value);
				$best_match = false; // ugly?
				foreach($selected_parent_matches as $selected_parent_matches_index => $selected_parent_matches_value) {
					if($selected_parent_matches_value[1] > $matches_at_last_tag_value[1]) {
						break;
					}
					if($matches_at_last_tag_value[1] >= $selected_parent_matches_value[1] && $matches_at_last_tag_value[1] + strlen($matches_at_last_tag_value[0]) <= $selected_parent_matches_value[1] + strlen($selected_parent_matches_value[0])) { // match at last tag is within selected parent
						if($matches_at_last_tag_value === $selected_parent_matches_value) { // again, ugly but probably works
							
						} else {							
							$best_match = $selected_parent_matches_value;
						}
						//continue 2;
					}
				}
				//print('$best_match: ');var_dump($best_match);
				if($best_match === false) {
					print('$matches_at_last_tag: ');var_dump($matches_at_last_tag);
					O::fatal_error('should never not find a selected parent');
					//O::warning('should never not find a selected parent'); // some wierdness, maybe with having an attribute on the last tag?
					//$selected_parent_full_selector_matches = $matches_at_last_tag;
				} else {
					$selected_parent_full_selector_matches[] = $best_match;
				}
			}
			//$selected_parent_full_selector_matches = array_unique($selected_parent_full_selector_matches);
			//$selected_parent_full_selector_matches = array_values($selected_parent_full_selector_matches);
			/*
			$depth_requirement = false;
			if(strpos($selector, '__') !== false) {
				//O::fatal_error('__ unhandled for selecting the parent in select');
				$depth_requirement = $piece_index - $selected_parent_piece_index;
				//foreach($selector_piece_set as $piece_index2 => $piece2) {
				//	if(strlen($piece2) === 0) {
				//		$depth_requirement--;
				//	}
				//}
			}
			//print('here374859---0038.5<br>');
			//print('$selected_parent_matches: ');var_dump($selected_parent_matches);
			$selected_parent_full_selector_matches = array();
			foreach($selector_piece_set_matches as $contextual_matches_index => $contextual_matches_value) {
				//print('here374859---0038.51<br>');
				foreach($contextual_matches_value as $contextual_matches_first_index => $contextual_matches_first_value) { break; }
				foreach($selected_parent_matches as $selected_parent_matches_index => $selected_parent_matches_value) {
					//print('here374859---0038.52<br>');
					//print('$selected_parent_matches_index: ');var_dump($selected_parent_matches_index);
					$first_index4 = false;
					foreach($selected_parent_matches_value as $index4 => $value4) {
						if($first_index4 === false) {
							$first_index4 = $index4;
						}
						//print('here374859---0038.53<br>');
						if($contextual_matches_first_index === $index4) {
							//print('$contextual_matches_first_index, $index4: ');var_dump($contextual_matches_first_index, $index4);
							//print('here374859---0038.54<br>');
							$counter = $index4 - 1;
							$depth_counter = 0;
							while($counter >= $first_index4) {
								//print('here374859---0038.55<br>');
								if($selected_parent_matches_value[$counter][0] == 1) {
									if($selected_parent_matches_value[$counter][1][2] === 0) {
										//print('here374859---0038.56<br>');
										$depth_counter++;
									}
									if($selected_parent_matches_value[$counter][1][2] === 1) {
										//print('here374859---0038.561<br>');
										$depth_counter--;
									}
								}
								$counter--;
							}
							//print('here374859---0038.57<br>');
							//print('$depth_counter, $depth_requirement, $piece_index, $selected_parent_piece_index: ');var_dump($depth_counter, $depth_requirement, $piece_index, $selected_parent_piece_index);
							if($depth_requirement !== false && $depth_counter >= $depth_requirement) {
								//print('here374859---0038.571<br>');
								$its_already_there = false;
								foreach($selected_parent_full_selector_matches as $index35 => $value35) {
									//print('here374859---0038.572<br>');
									if($value35 === $selected_parent_matches_value) {
										//print('here374859---0038.573<br>');
										$its_already_there = true;
										break;
									}
								}
								//print('here374859---0038.574<br>');
								if(!$its_already_there) {
									//print('here374859---0038.575<br>');
									$selected_parent_full_selector_matches[] = $selected_parent_matches_value;
								}
							} elseif($depth_counter === $piece_index - $selected_parent_piece_index) {
								//print('here374859---0038.58<br>');
								$its_already_there = false;
								foreach($selected_parent_full_selector_matches as $index35 => $value35) {
									//print('here374859---0038.581<br>');
									if($value35 === $selected_parent_matches_value) {
										//print('here374859---0038.582<br>');
										$its_already_there = true;
										break;
									}
								}
								//print('here374859---0038.583<br>');
								if(!$its_already_there) {
									//print('here374859---0038.584<br>');
									$selected_parent_full_selector_matches[] = $selected_parent_matches_value;
								}
							}
							//break 2;
						}
					}
				}
			}
			*/
			$selector_piece_set_matches = $selected_parent_full_selector_matches;
		}
		//print('$selector_piece_set_matches after selected parent processing in select: ');var_dump($selector_piece_set_matches);
		if(sizeof($selector_piece_set_matches) > 0) {
			//print('here374859---0035<br>');
			$selector_matches = array_merge($selector_matches, $selector_piece_set_matches);
			//break;
		}
	}
	//print('$code at the end of select: ');var_dump($code);
	//print('$selector_matches at end of select: ');var_dump($selector_matches);
	//print('end of select()<br>');
	return $selector_matches;
}

function offset_to_LOM_index($offset) { // alias
	O::fatal_error('offset_to_LOM_index probably obsolete');
	return O::LOM_index_from_offset($offset);
}

function LOM_index_from_offset($offset) {
	O::fatal_error('LOM_index_from_offset probably obsolete');
	//print('$offset, $this->LOM in LOM_index_from_offset: ');O::var_dump_full($offset, $this->LOM);
	foreach($this->LOM as $index => $value) {
		if($value[2] === $offset) {
			//$counter = 0;
			//print('just remind of format of LOM (debug) $this->LOM[$index]: ');var_dump($this->LOM[$index]);exit(0);
		//	while($this->LOM[$index][2] === $offset && $this->LOM[$index][0] === 0 && strlen($this->LOM[$index][1]) === 0) { // skip zero-length text nodes
		//		//$counter++;
		//		$index++;
		//	}
			//print('$index, $counter in LOM_index_from_offset: ');var_dump($index, $counter);
			//return $index + $counter;
			return $index;
		}
	}
	return false;
}

function opening_tag_LOM_index_from_offset($offset) { // alias
	O::fatal_error('opening_tag_LOM_index_from_offset probably obsolete');
	return O::opening_LOM_index_from_offset($offset);
}

function opening_LOM_index_from_offset($offset) {
	O::fatal_error('opening_LOM_index_from_offset probably obsolete');
	$LOM_index = O::LOM_index_from_offset($offset);
	while($this->LOM[$LOM_index][2] === $offset && $this->LOM[$LOM_index][0] === 0 && strlen($this->LOM[$LOM_index][1]) === 0) { // skip zero-length text nodes
		$LOM_index++;
	}
	return $LOM_index;
}

function closing_tag_LOM_index_from_offset($offset) { // alias
	O::fatal_error('closing_tag_LOM_index_from_offset probably obsolete');
	// the behavior is the same; skip zero-length text nodes 
	return O::opening_LOM_index_from_offset($offset);
}

function offset_from_LOM_index($LOM_index) { // alias
	O::fatal_error('offset_from_LOM_index probably obsolete');
	return O::LOM_index_to_offset($LOM_index);
}

function LOM_index_to_offset($LOM_index) {
	O::fatal_error('LOM_index_to_offset probably obsolete');
	foreach($this->LOM as $index => $value) {
		if($index === $LOM_index) {
			return $this->LOM[$index][2];
		}
	}
	return false;
}

function match_by_tagname($tagname_array, $matching_array = false, $look_only_in_direct_children = true, $tagvalue_array = false, $matching_indices = false, $required_attribute_sets = false) {
	O::fatal_error('match_by_tagname probably obsolete');
	if(!is_array($tagname_array)) {
		$tagname_array = array($tagname_array);
	}
	if($matching_array === false || $matching_array === NULL) {
		//$this->code = O::code_from_LOM();
		//$matching_array = array(array($this->code, 0));
		$matching_array = $this->LOM;
	}
	if(O::all_entries_are_arrays($matching_array)) {
		
	} else {
		return array();
	}
	if($tagvalue_array === false) {
		$tagvalue_array = array();
		foreach($tagname_array as $tagname) {
			$tagvalue_array[] = false;
		}
	}
	if(is_string($tagvalue_array)) {
		$tagvalue_array = array($tagvalue_array);
	}
	if($matching_indices === false) {
		$matching_indices = array();
		foreach($tagname_array as $tagname) {
			$matching_indices[] = false;
		}
	}
	if(is_string($matching_indices)) {
		$matching_indices = array((int)$matching_index);
	}
	if($required_attribute_sets === false) {
		$required_attribute_sets = array();
		foreach($tagname_array as $tagname) {
			$required_attribute_sets[] = array();
		}
	}
	if(is_string($required_attribute_sets)) {
		$required_attribute_sets = array(array($required_attribute_sets => false));
	}
	//print('$tagname_array, $matching_array, $look_only_in_direct_children, $tagvalue_array, $matching_indices, $required_attribute_sets in match_by_tagname: ');var_dump($tagname_array, $matching_array, $look_only_in_direct_children, $tagvalue_array, $matching_indices, $required_attribute_sets);
	$matches = array();
	foreach($matching_array as $index => $value) {
		if($look_only_in_direct_children) {
			foreach($tagname_array as $tagname_index => $tagname) {
				if($tagname[0] === '.') {
					$tagname = substr($tagname, 1);
				}
				//print('$tagname, $tagvalue_array[$tagname_index]: ');var_dump($tagname, $tagvalue_array[$tagname_index]);
				if($tagname === '*') {
					//print('get_all_tags_at_this_level<br>');
					$tagname_matches = O::get_all_tags_at_this_level($value[0], $value[1], $tagvalue_array[$tagname_index], $matching_indices[$tagname_index], $required_attribute_sets[$tagname_index]);
				} else {
					//print('get_all_named_tags_at_this_level<br>');
					$tagname_matches = O::get_all_named_tags_at_this_level($value[0], $tagname, $value[1], $tagvalue_array[$tagname_index], $matching_indices[$tagname_index], $required_attribute_sets[$tagname_index]);
				}
				// since it doesn't make sense to try to look for a child under two separate tags, just return the last tagname match if all tagnames are satisfied
				if(sizeof($tagname_matches) > 0) {
					
				} else {
					continue 2;
				}
			}
		} else {
			foreach($tagname_array as $tagname_index => $tagname) {
				if($tagname[0] === '.') {
					$tagname = substr($tagname, 1);
				}
				//print('$tagname, $tagvalue_array[$tagname_index]: ');var_dump($tagname, $tagvalue_array[$tagname_index]);
				if($tagname === '*') {
					//print('get_all_tags<br>');
					$tagname_matches = O::get_all_tags($value[0], $value[1], $tagvalue_array[$tagname_index], $matching_indices[$tagname_index], $required_attribute_sets[$tagname_index]);
				} else {
					//print('get_all_named_tags<br>');
					$tagname_matches = O::get_all_named_tags($value[0], $tagname, $value[1], $tagvalue_array[$tagname_index], $matching_indices[$tagname_index], $required_attribute_sets[$tagname_index]);
				}
				// since it doesn't make sense to try to look for a child under two separate tags, just return the last tagname match if all tagnames are satisfied
				if(sizeof($tagname_matches) > 0) {
					
				} else {
					continue 2;
				}
			}
		}
		//if(sizeof($matches) === 0) {
		//	$matches = $tagname_matches;
		//} else {
		//	$matches = array_intersect($matches, $tagname_matches);
		//}
		$matches = array_merge($matches, $tagname_matches);
	}
	//print('$this->code, $tagname, $matches: ');var_dump($this->code, $tagname, $matches);exit(0);
	return $matches;
	/*$LOMified_matches = array();
	foreach($matches as $index => $value) {
		$string = $value[0];
		$offset = $value[1];
		$LOMified_matches[] = O::LOM($string, O::opening_LOM_index_from_offset($offset), $offset);
	}
	// need to update $this->LOM and $this->code and indices and offsets now
	print('$LOMified_matches: ');var_dump($LOMified_matches);
	return $LOMified_matches;*/
}
	
function LOM_match_by_tagname($tagname, $matching_array, $look_only_in_direct_children = true, $tagvalue = false, $matching_index = false, $required_attributes = array()) {	
	O::fatal_error('LOM_match_by_tagname probably obsolete');
	// debug???
	foreach($matching_array as $first_index => $first_value) { break; }
	if(!is_array($first_value)) {
		return array();
	}
	//if(strlen($tagvalue) === 0) {
	//	$tagvalue = false;
	//}
	if(is_string($matching_index)) {
		$matching_index = (int)$matching_index;
	}
	if(is_array($tagname)) {
		$tagname_array = $tagname;
	} else {
		$tagname_array = array($tagname);
	}
	if(is_array($tagvalue)) {
		$tagvalue_array = $tagvalue;
	} else {
		$tagvalue_array = array($tagvalue);
	}
	$matching_depth = 0;
	$matches_counter = 0;
	$matches = array();
	//print('$matching_array: ');O::var_dump_full($matching_array);
	//print('$tagvalue in match_by_tagname: ');var_dump($tagvalue);
	//print('$tagname, $look_only_in_direct_children, $tagvalue, $matching_index, $required_attributes in match_by_tagname: ');var_dump($tagname, $look_only_in_direct_children, $tagvalue, $matching_index, $required_attributes);
	if(sizeof($matching_array) > 0) {
		foreach($matching_array as $index2 => $value2) {
			//print('here374859---0018<br>');
			//print('$index2, $value2: ');var_dump($index2, $value2);
			// debug
			if(!is_array($value2)) {
				print('$matching_array, $index2, $value2: ');var_dump($matching_array, $index2, $value2);
				O::fatal_error('!is_array($value2)');
			}
			$tagvalues_satisfied = array();
			foreach($tagvalue_array as $tagvalue_index => $tagvalue) {
				$tagvalues_satisfied[$tagvalue_index] = false;
			}
			$potential_matches = array();
			foreach($value2 as $index => $value) {
				//print('here374859---0019<br>');
				$required_attributes_exist = true;
				if($value[0] == 1) {
					$existing_attributes = $value[1][1];
					//print('$existing_attributes, $value: ');var_dump($existing_attributes, $value);
					foreach($required_attributes as $required_attribute_name => $required_attribute_value) {
						$required_attributes_exist = false;
						if(sizeof($existing_attributes) > 0) {
							foreach($existing_attributes as $existing_attribute_name => $existing_attribute_value) {
								if($required_attribute_name === $existing_attribute_name && ($required_attribute_value === false || $existing_attribute_value === $required_attribute_value)) {
									$required_attributes_exist = true;
									break;
								}
							}
						}
						if(!$required_attributes_exist) {
							break;
						}
					}
				} else {
					if(sizeof($required_attributes) > 0) {
						$required_attributes_exist = false;
					}
				}
				//if(sizeof($required_attributes) > 0) {
				//	print('$value, $required_attributes, $existing_attributes, $required_attributes_exist: ');var_dump($value, $required_attributes, $existing_attributes, $required_attributes_exist);
				//}
				//$first_tagvalue_was_matched = false;
				
				foreach($tagvalue_array as $tagvalue_index => $tagvalue) {
					$tagname = $tagname_array[$tagvalue_index];
					if($tagname[0] === '.') {
						$tagname = substr($tagname, 1);
					}
					//print('$index, $value[0], $value[1][2], $tagname, $value[1][0], $tagvalue, $value2[$index + 1][0], $tagvalue, $value2[$index + 1][1]: ');var_dump($index, $value[0], $value[1][2], $tagname, $value[1][0], $tagvalue, $value2[$index + 1][0], $tagvalue, $value2[$index + 1][1]);
					//print('here237541<br>');
					if(($look_only_in_direct_children === false || $matching_depth === 1) && $value[0] == 1 && $value[1][2] === 0 && ($tagname === $value[1][0] || $tagname == '*') && ($tagvalue === false || (strlen($tagvalue) > 0 && $value2[$index + 1][0] === 0 && $tagvalue === $value2[$index + 1][1])) && $required_attributes_exist) {
						//print('here237542<br>');
						if($matching_index === false || $matching_index === $matches_counter) {
							//print('here237543<br>');
							// build up the match
							$match_depth = $matching_depth;
							$match_at_depth = array();
							foreach($value2 as $match_index2 => $matching_entry2) {
								if($match_index2 < $index) {
									continue;
								}
								$match_at_depth[$match_index2] = $matching_entry2;
								if($matching_entry2[0] == 1 && $matching_entry2[1][2] === 0) {
									//print('here237544<br>');
									$match_depth++;
								}
								if($matching_entry2[0] == 1 && $matching_entry2[1][2] === 1) {
									//print('here237545<br>');
									$match_depth--;
									if($match_depth === $matching_depth) {
										//print('here237546<br>');
										break;
									}
								}
							}
							//print('here237547<br>');
							//print('$match_at_depth: ');var_dump($match_at_depth);
							$potential_matches[] = $match_at_depth;
							$tagvalues_satisfied[$tagvalue_index] = true;
							//print('$potential_matches, $tagvalues_satisfied: ');var_dump($potential_matches, $tagvalues_satisfied);
							//$first_tagvalue_was_matched = true;
						}
						//print('here237548<br>');
						$matches_counter++;
					}
					//if($tagvalue_index > 0 && $first_tagvalue_was_matched) { // if both tagvalue conditions are not satisfied then don't call it a match
					//	unset($matches[sizeof($matches) - 1]);
					//}
					//print('$matches at bottom of tagvalue_array processing: ');var_dump($matches);
				}
				//print('$potential_matches: ');var_dump($potential_matches);
				
				if($value[0] == 1 && $value[1][2] === 0) {
					//print('here374859---0021<br>');
					$matching_depth++;
				}
				if($value[0] == 1 && $value[1][2] === 1) {
					//print('here374859---0022<br>');
					$matching_depth--;
				}
			}
			$all_tagvalues_satisfied = true;
			foreach($tagvalues_satisfied as $index33 => $value33) {
				if($value33 !== true) {
					$all_tagvalues_satisfied = false;
					break;
				}
			}
			if($all_tagvalues_satisfied) {
				$matches = array_merge($matches, $potential_matches);
			}
			/*
			$all_tagvalues_satisfied = true;
			if($look_only_in_direct_children) {
				if(sizeof($tagvalue_array) === 1 || sizeof($potential_matches) === sizeof($tagvalue_array)) {
					
				} else {
					$all_tagvalues_satisfied = false;
				}
			} else {
				if(sizeof($tagvalue_array) > 1) {
					O::fatal_error('code to handle more than one required tagvalue while not only looking in direct children  has not been written');
				}
			}*/
		}
	}
	//print('$matches in match_by_tagname: ');var_dump($matches);
	return $matches;
}

function parse_selector_string($selector_string) {
	// treat underscore the same as a directory separator or node level marker. this depends on underscore character not being used in tag names, so the parser should check for this
	$selector_string = str_replace('\\', '_', $selector_string);
	$selector_string = str_replace('/', '_', $selector_string);
	// doing context-specific selection frees up the . character from being used as a root indicator to being used to tell which tag we want according to its parents _and_ children
	/*
	$query = '//' . O::get_html_namespace() . implode('/text() | //' . O::get_html_namespace(), $this->config['fix_text_tags']) . '/text()';
	$query = './/' . O::get_html_namespace() . 'th | .//' . O::get_html_namespace() . 'td';
	$query = O::get_html_namespace() . 'tr[1]';
	$query = './/' . O::get_html_namespace() . 'th | .//' . O::get_html_namespace() . 'td[@newtag="th"]';
	$query = './/' . O::get_html_namespace() . 'tbody/tr';
	$query = './/@new_tbody';
	$query = './/' . O::get_html_namespace() . 'th | .//' . O::get_html_namespace() . '*[@newtag="th"]';
	$query = '//' . O::get_html_namespace() . 'div[@id="XXX9o9TOCdiv9o9XXX"]//' . O::get_html_namespace() . 'p';
	$query = '//' . O::get_html_namespace() . '*[@*=""]';
	$query = '//' . O::get_html_namespace() . 'a[@href="#footnote"][@name="note"][@title="Link to footnote "][@id="note"]';
	$query = '//*[@class]';
	*/
	$this->selector_scope_sets = array();
	$this->selector_piece_sets = array();
	$selector_strings = explode('|', $selector_string);
	foreach($selector_strings as $selector_string) {
		if($selector_string[0] === '_') {
			$scopes = array('direct');
			$offset = 1;
		} else {
			$scopes = array(false);
			$offset = 0;
		}
		$pieces = array();
		$piece = '';
		while($offset < strlen($selector_string)) {
			if($selector_string[$offset] === '_') {
				if($selector_string[$offset + 1] === '_') {
					$scopes[] = false;
					$offset++;
				} else {
					$scopes[] = 'direct';
				}
				$pieces[] = $piece;
				$piece = '';
				$offset++;
				continue;
			} elseif($selector_string[$offset] == ' ' || $selector_string[$offset] == "\t" || $selector_string[$offset]  == "\n" || $selector_string[$offset]  == "\r") { // ignore spaces
				$offset++;
				continue;
			}/* elseif($selector_string[$offset] === '@') {
				
			}*/
			$piece .= $selector_string[$offset];
			$offset++;
		}
		if(strlen($piece) > 0) {
			$pieces[] = $piece;
		}
		$this->selector_scope_sets[] = $scopes;
		$this->selector_piece_sets[] = $pieces;
	}
	//print('$this->selector_scope_sets, $this->selector_piece_sets at the end of parse_selector_string: ');var_dump($this->selector_scope_sets, $this->selector_piece_sets);
	//return $this->selector_piece_sets;
}

function strinsert($code, $new_value, $offset = 0) { // alias
	return O::string_insert($code, $new_value, $offset);
}

function str_insert($code, $new_value, $offset = 0) { // alias
	return O::string_insert($code, $new_value, $offset);
}

function string_insert($code, $new_value, $offset = 0) {
	if($offset > strlen($code)) {
		$this->string_operation_made_a_change = false;
		return $code;
	}
	$this->string_operation_made_a_change = true;
	//$this->zero_offsets = array();
	return substr($code, 0, $offset) . $new_value . substr($code, $offset);
}

function str_delete($code, $delete_string, $offset = 0) { // alias
	return O::string_delete($code, $delete_string, $offset);
}

function string_delete($code, $delete_string, $offset = 0) {
	if($offset > strlen($code)) {
		$this->string_operation_made_a_change = false;
		return $code;
	}
	$this->string_operation_made_a_change = true;
	//$this->zero_offsets = array();
	//print('$code, $delete_string, substr($code, 0, $offset) . substr($code, $offset + strlen($delete_string)): ');var_dump($code, $delete_string, substr($code, 0, $offset) . substr($code, $offset + strlen($delete_string)));
	return substr($code, 0, $offset) . substr($code, $offset + strlen($delete_string));
}

function replace($code, $old_value, $new_value, $offset = 0) {
	if($offset > strlen($code)) {
		$this->string_operation_made_a_change = false;
		return $code;
	}
	$this->string_operation_made_a_change = true;
	//$this->zero_offsets = array();
	//print('$code, $old_value, $new_value, substr($code, 0, $offset) . $new_value . substr($code, $offset + strlen($old_value)): ');var_dump($code, $old_value, $new_value, substr($code, 0, $offset) . $new_value . substr($code, $offset + strlen($old_value)));
	return substr($code, 0, $offset) . $new_value . substr($code, $offset + strlen($old_value));
}

function set($selector, $new_value = false, $parent_node = false, $parent_node_only = false) {
	if(is_array($new_value) && !is_array($selector)) { // swap them
		$temp_selector = $new_value;
		$selector = $new_value;
		$new_value = $temp_selector;
	}
	// this function assumes the selector only chooses a single entry rather than a range, otherwise array_slice_replace would have to be used. not an unassailable position
	//print('$selector, $new_value, $parent_node in set: ');var_dump($selector, $new_value, $parent_node);
	if(is_numeric($parent_node)) {
		print('$selector, $new_value, $parent_node, $parent_node_only in set: ');var_dump($selector, $new_value, $parent_node, $parent_node_only);
		O::fatal_error('assuming parent_node is an offset in set but this is not coded yet');
	} elseif(is_string($parent_node) && strpos(O::query_decode($parent_node), '<') !== false) {
		$parent_node = array(array($parent_node, strpos($this->code, $parent_node)));
	} elseif(is_string($parent_node)) { // assume it's a selector
		$parent_node = O::get_tagged($parent_node);
		//print('$selector, $new_value, $parent_node in set with string $parent_node: ');var_dump($selector, $new_value, $parent_node);
	} elseif($parent_node === NULL) {
		$parent_node = false;
	}
	$new_value = (string)$new_value;
	if($new_value === false || $new_value === NULL || strlen($new_value) === 0) {
		O::delete($selector, $parent_node);
	} elseif(is_numeric($selector)) { // treat it as an offset
		$selector = (int)$selector;
		if($this->code[$selector] === '<') {
			$offset = strpos($this->code, '>', $selector) + 1;
		} else {
			$offset = $selector;
		}
		$expanded_LOM = O::expand($this->code, $offset, false, false, 'lazy');
		$old_value = $expanded_LOM[1][0];
		$text_node_offset = $expanded_LOM[1][1];
		/*if($this->LOM[$selector][0] === 0) { // then it is text
			$offset = O::LOM_index_to_offset($selector);
			$old_value = $this->LOM[$selector][1];*/
			$offset_adjust = strlen($new_value) - strlen($old_value);
			//print('$offset, $old_value, $new_value, $offset_adjust in is_numeric($selector) in set: ');O::var_dump_full($offset, $old_value, $new_value, $offset_adjust);
			//print('$selector, $offset, $offset_adjust, substr($this->code, $offset - 10, 20) in is_numeric($selector) in set: ');O::var_dump_full($selector, $offset, $offset_adjust, substr($this->code, $offset - 10, 20));
			//print('$this->LOM in is_numeric($selector) in set: ');O::var_dump_full($this->LOM);
			if(!$parent_node_only) {
				$this->code = O::replace($this->code, $old_value, $new_value, $offset);
				//print('before adjust_offset_depths in set<br>');
				O::adjust_offset_depths($offset, $offset_adjust);
				//print('$this->code, $this->string_operation_made_a_change in is_numeric($selector) in set: ');O::var_dump_full($this->code, $this->string_operation_made_a_change);
				if($this->string_operation_made_a_change) {
					/*$this->LOM[$selector][1] = $new_value;
					foreach($this->LOM as $LOM_index => $LOM_value) {
						if($LOM_value[2] >= $offset) {
							$this->LOM[$LOM_index][2] += $offset_adjust;
						}
					}*/
					if($this->use_context) {
						//print('$this->context (using context) in is_numeric($selector) in set: ');O::var_dump_full($this->context);
						/*foreach($this->context as $context_index => $context_value) {
							if(is_array($context_value[3])) {
								foreach($context_value[3] as $index2 => $value2) {
									if(isset($value2[$selector])) {
										$this->context[$context_index][3][$index2][$selector][1] = $new_value;
									}
								}
							} else {
								if($this->context[$context_index][2] === $selector) {
									$this->context[$context_index][3] = $new_value;
								}
							}
						}*/
						foreach($this->context as $context_index => $context_value) {
							//print('$context_value[1]: ');var_dump($context_value[1]);
							if($context_value[1] !== false) {
								foreach($context_value[1] as $context1_index => $context1_value) {
									if($context1_value[0] <= $offset && $context1_value[0] + $context1_value[1] > $offset) {
										$this->context[$context_index][1][$context1_index][1] += $offset_adjust;
									} elseif($context1_value[0] >= $offset) {
										$this->context[$context_index][1][$context1_index][0] += $offset_adjust;
									}
									//if($context1_value[1] >= $offset) {
									//	$this->context[$context_index][1][$context1_index][1] += $offset_adjust;
									//}
								}
							}
							foreach($context_value[2] as $context2_index => $context2_value) {
								if($context2_value[0] <= $offset && $context2_value[0] + $context2_value[1] > $offset) {
									$this->context[$context_index][2][$context2_index][1] += $offset_adjust;
								} elseif($context2_value[0] >= $offset) {
									$this->context[$context_index][2][$context2_index][0] += $offset_adjust;
								}
								//if($context2_value[1] >= $offset) {
								//	$this->context[$context_index][2][$context2_index][1] += $offset_adjust;
								//}
							}
						}
					}
				}
			}
			//print('$this->context after offset_adjust in set: ');O::var_dump_full($this->context);
			/*if($parent_node !== false) {
				//$parent_node[$selector][1] = $new_value;
				if(O::all_sub_entries_are_arrays($parent_node)) {
					foreach($parent_node as $index => $value) {
						$parent_node[$index][$selector][1] = $new_value;
					}
				} else {
					$parent_node[$selector][1] = $new_value;
				}
			}*/
			//print('here4569702<br>');
			
			foreach($this->variables as $variable_index => $variable_value) {
				if(is_array($this->variables[$variable_index])) {
					if(is_array($this->variables[$variable_index][0])) {
						foreach($this->variables[$variable_index] as $index => $value) {
							if($offset >= $value[1] && $offset <= $value[1] + strlen($value[0])) {
								$this->variables[$variable_index][$index][0] = O::replace($this->variables[$variable_index][$index][0], $old_value, $new_value, $offset - $value[1]);
							}
							if($this->variables[$variable_index][$index][1] >= $offset) {
								$this->variables[$variable_index][$index][1] += $offset_adjust;
							}
						}
					} else {
						if($offset >= $this->variables[$variable_index][1] && $offset <= $this->variables[$variable_index][1] + strlen($this->variables[$variable_index][0])) {
							$this->variables[$variable_index][0] = O::replace($this->variables[$variable_index][0], $old_value, $new_value, $offset - $this->variables[$variable_index][1]);
						}
						if($this->variables[$variable_index][1] >= $offset) {
							$this->variables[$variable_index][1] += $offset_adjust;
						}
					}
				} else {
					$this->variables[$variable_index] = $new_value;
				}
			}
			
			if(is_array($parent_node)) {
				//print('here4569703<br>');
				if(is_array($parent_node[0])) {
					//print('here4569704<br>');
					foreach($parent_node as $index => $value) {
						//print('here4569705<br>');
						if($offset >= $value[1] && $offset <= $value[1] + strlen($value[0])) {
						//if($value[1] === $offset) {
							//print('here4569706<br>');
							$parent_node[$index][0] = O::replace($parent_node[$index][0], $old_value, $new_value, $offset - $value[1]);
						}
						//print('here4569707<br>');
						if($parent_node[$index][1] >= $offset) {
							//print('here4569708<br>');
							$parent_node[$index][1] += $offset_adjust;
						}
					}
				} else {
					//foreach($parent_node as $index => $value) {
					//	$parent_node[$index] = $new_value;
					//}
					if($offset >= $parent_node[1] && $offset <= $parent_node[1] + strlen($parent_node[0])) {
					//if($parent_node[1] === $offset) {
						$parent_node[0] = O::replace($parent_node[0], $old_value, $new_value, $offset - $parent_node[1]);
					}
					if($parent_node[1] >= $offset) {
						$parent_node[1] += $offset_adjust;
					}
				}
			} else {
				$parent_node = $new_value;
			}
		/*} else { // what should be changed about a tag?
			print('$selector, $new_value, $this->code, $this->LOM, $this->context: ');O::var_dump_full($selector, $new_value, $this->code, $this->LOM, $this->context);
			O::fatal_error('what to set of a tag when a LOM index is provided has not been figured out');
		}*/
	} elseif(is_string($selector)) {
		//print('$selector, $parent_node, $this->context in set: ');var_dump($selector, $parent_node, $this->context);
		//print('$selector, $new_value in set: ');var_dump($selector, $new_value);
		//$selector_matches = O::get_LOM_indices($selector, $parent_node, false, false, $parent_node_only);
		//print('$selector_matches in set: ');var_dump($selector_matches);
		//print('$this->LOM before in set');O::var_dump_full($this->LOM);
		$selector_matches = O::get_tagged($selector, $parent_node, false, false, $parent_node_only);
		//foreach($selector_matches as $index => $value) {
		$index = sizeof($selector_matches) - 1; // have to go in reverse order
		while($index > -1) {
			$parent_node = O::set($selector_matches[$index][1], $new_value, $parent_node, $parent_node_only);
			/*if($this->LOM[$result][0] === 0) { // then it is text
				//print('it is text<br>');
				$parent_node = O::set($result, $new_value, $parent_node, $parent_node_only);
			} else {
				//print('it is not text<br>');
				$parent_node = O::set($result + 1, $new_value, $parent_node, $parent_node_only); // + 1 since get_LOM_indices is returning the opening tags
			}*/
			$index--;
		}
	} elseif(is_array($selector)) {
		O::fatal_error('array selector not handled in set function');
	} else {
		print('$selector: ');var_dump($selector);
		O::fatal_error('Unknown selector type in set');
	}
	//print('$parent_node at the end of set: ');var_dump($parent_node);
	//print('$this->context at the end of set: ');O::var_dump_full($this->context);
	/*if(is_array($parent_node) && sizeof($parent_node) === 1) {
		foreach($parent_node as $parent_node_first_index => $parent_node_first_value) {  }
		if(!is_array($parent_node_first_value)) {
			$parent_node = $parent_node[$parent_node_first_index];
		}
	}*/
	//print('$this->context overview: ');O::var_dump_full($this->context );
	return $parent_node;
}

function array_slice_replace($array, $start_index = false, $end_index = false, $replace_array) {
	//O::fatal_error('not sure if array_slice_replace is working properly. test it first.');
	if($start_index === false) {
		$start_index = 0;
	}
	if($end_index === false) {
		$end_index = sizeof($array) - 1;
	}
	$new_array = array();
	$did_replace = false;
	$index_counter = false;
	foreach($array as $index => $value) {
		if($index_counter === false) {
			$index_counter = $index;
		}
		if(!$did_replace && $index >= $start_index && $index <= $end_index) {
			foreach($replace_array as $index2 => $value2) {
				$new_array[$index_counter] = $value2;
				$index_counter++;
			}
			$did_replace = true;
		} else {
			$new_array[$index_counter] = $value;
			$index_counter++;
		}
	}
	return $new_array;
}

function insert($new_value, $selector = false) {
	if(is_array($new_value) && !is_array($selector)) { // swap them
		$temp_selector = $selector;
		$selector = $new_value;
		$new_value = $temp_selector;
	}
	O::fatal_error('consider changing the code to work with string-based data rather than using this insert() function which uses LOM_match_by_tagname. probably done.');
	// would like insert to do the same work as new_ but return the parent_node whereas new_ returns the new tag
	O::new_($new_value, $selector);
	$selector_matches = O::get($selector);
	if(sizeof($selector_matches) !== 1) {
		print('$selector_matches: ');var_dump($selector_matches);
		O::fatal_error('sizeof($selector_matches) !== 1 not handled in insert');
	}
	//foreach($selector_matches as $index => $value) {
	//	foreach($value as $first_index => $first_value) { break 2; }
	//}
	$match = O::LOM_match_by_tagname(O::tagname($first_index), array(array_slice($this->LOM, $first_index, sizeof($this->LOM) - 1, true)), false);
	return $match;
	/*O::fatal_error('insert is deprecated in favor of internal_new');
	if($insert_index === false) {
		$insert_index = sizeof($array) - 2;
	}
	$new_array = array();
	$index_counter = false;
	foreach($array as $index => $value) {
		if($index_counter === false) {
			$index_counter = $index;
		}
		if($index === $insert_index) {
			foreach($insert_array as $index2 => $value2) {
				$new_array[$index_counter] = $value2;
				$index_counter++;
			}
		}
		$new_array[$index_counter] = $value;
		$index_counter++;
	}
	return $new_array;*/
}

private function internal_delete($array, $first_index, $last_index, $process_at_first_level = false) {
	$selection_range = $last_index - $first_index + 1;
	//if(!$process_at_first_level) { // debug
	//	print('$array, $first_index, $last_index, $selection_range before internal_delete: ');O::var_dump_full(O::tagstring($array), $first_index, $last_index, $selection_range);
	//}
	if($array === false) {
		return false;
	}
	$new_array = array();
	// analyze whether the provided array is a results array or LOM array (which have different formats)
	/*if($process_at_first_level) {
		$all_sub_entries_are_arrays = false;
	} else {
		$all_sub_entries_are_arrays = true;
		foreach($array as $index => $value) {
			foreach($value as $index2 => $value2) {
				if(is_array($value2)) {
					
				} else {
					$all_sub_entries_are_arrays = false;
					break 2;
				}
			}
		}
	}*/
	if(is_array($array) && sizeof($array) > 0) {
		if(O::all_sub_entries_are_arrays($array) && !$process_at_first_level) {
			$determine_first_index = false;
			if($first_index === false) {
				$determine_first_index = true;
			}
			$determine_last_index = false;
			if($last_index === false) {
				$determine_last_index = true;
			}
			foreach($array as $index2 => $value2) {
				if($determine_first_index) {
					foreach($value2 as $first_index => $first_value) { break; }
				}
				if($determine_last_index) {
					foreach($value2 as $last_index => $last_value) {  }
				}
				foreach($value2 as $index => $value) {
					if($index >= $first_index && $index <= $last_index) {
						
					} else {
						if($index > $first_index) {
							$index -= $selection_range;
						}
						$new_array[$index2][$index] = $value;
					}
				}
			}
		} else {
			if($first_index === false) {
				foreach($array as $first_index => $first_value) { break; }
			}
			if($last_index === false) {
				foreach($array as $last_index => $last_value) {  }
			}
			foreach($array as $index => $value) {
				if($index >= $first_index && $index <= $last_index) {
					
				} else {
					if($index > $first_index) {
						$index -= $selection_range;
					}
					$new_array[$index] = $value;
				}
			}
		}
	}
	//if(!$process_at_first_level) { // debug
	//	print('$new_array after internal_delete: ');O::var_dump_full(O::tagstring($new_array));
	//}
	//O::validate();
	return $new_array;
}

private function internal_new($array, $new_LOM, $insert_index) {
	//print('$array, $new_LOM, $insert_index, before internal_new: ');O::var_dump_full(O::tagstring($array), $new_LOM, $insert_index);
	if($array === false) {
		return false;
	}
	$new_array = array();
	$index_counter = false; // have to preserve indices
	if(is_array($array) && sizeof($array) > 0) {
		if(O::all_sub_entries_are_arrays($array)) {
			$determine_insert_index = false;
			if($insert_index === false) {
				$determine_insert_index = true;
			}
			foreach($array as $index3 => $value3) {
				if($determine_insert_index) {
					foreach($value3 as $insert_index => $insert_value) {  }
				}
				foreach($value3 as $index => $value) {
					if($index_counter === false) {
						$index_counter = $index;
					}
					if($index == $insert_index) {
						foreach($new_LOM as $index2 => $value2) {
							$new_array[$index3][$index_counter] = $value2;
							$index_counter++;
						}
					}
					$new_array[$index3][$index_counter] = $value;
					$index_counter++;
				}
			}
		} else {
			if($insert_index === false) {
				foreach($array as $insert_index => $insert_value) {  }
			}
			foreach($array as $index => $value) {
				if($index_counter === false) {
					$index_counter = $index;
				}
				if($index == $insert_index) {
					foreach($new_LOM as $index2 => $value2) {
						$new_array[$index_counter] = $value2;
						$index_counter++;
					}
				}
				$new_array[$index_counter] = $value;
				$index_counter++;
			}
		}
	}
	//print('$new_array after internal_new: ');O::var_dump_full(O::tagstring($new_array));
	//O::validate();
	return $new_array;
}

function new_tag($new_value = false, $selector = '') { // alias
	return O::new_($new_value, $selector);
}

function new_($new_value, $selector = false) { // this function assumes that the new tag should go right before the closing tag of the selector
	// no allowance for parent_node?
	//if(is_array($new_value) && !is_array($selector)) { // swap them
	//	$temp_selector = $selector;
	//	$selector = $new_value;
	//	$new_value = $temp_selector;
	//}
	//print('$this->LOM before, $this->context in new_: ');var_dump($this->LOM, $this->context);
	//print('$new_value, $selector, $this->LOM, $this->context in new_: ');O::var_dump_full($new_value, $selector, $this->LOM, $this->context);
	//print('$new_value, $selector in new_: ');var_dump($new_value, $selector);
	if(is_array($new_value)) {
		if(is_array($new_value[0])) {
			$new_value = '';
			foreach($new_value as $index => $value) {
				$new_value .= $value[0];
			}
		} else {
			$new_value = $new_value[0];
		}
	}
	if($selector === false) {
		//$selector = O::get_tag_name($this->LOM);
		//$selector = O::tagname($this->code);
		$selector = strlen($this->code);
	}
	//print('$new_value, $selector, $this->code after check for false in new_: ');var_dump($new_value, $selector, $this->code);
	$new_matches = array();
	if(is_numeric($selector)) { // treat it as an offset
		//print('is_numeric($selector) in new_<br>');
		$selector = (int)$selector;
		//print('expanding in new_<br>');
		$expanded_LOM = O::expand($this->code, $selector, false, false, 'greedy');
	//	print('$expanded_LOM in new_: ');var_dump($expanded_LOM);
		$offset = $expanded_LOM[1][1] + strlen($expanded_LOM[1][0]);
		//print('$this->LOM before new_: ');O::var_dump_full($this->LOM);
		//$offset = O::LOM_index_to_offset($selector);
		//print('$offset in new_: ');O::var_dump_full($offset);
		//$new_LOM = O::generate_LOM($new_value, $selector, $offset);
		//print('$new_LOM in new_: ');O::var_dump_full($new_LOM);
		//$this->LOM = O::insert($this->LOM, $selector, $new_LOM);
		$this->code = O::str_insert($this->code, $new_value, $offset);
		if($this->string_operation_made_a_change) {
			$offset_adjust = strlen($new_value);
			if($expanded_LOM[2] === -1) { // round-about way of saying that we are adding to the end of the code
				$depth_of_offset = 0;
			} else {
				$depth_of_offset = O::depth($offset);
			}
	//		print('$offset, $depth_of_offset, $this->code in new_: ');O::var_dump_full($offset, $depth_of_offset, $this->code);
			//print('before adjust_offset_depths in new_<br>');
			O::adjust_offset_depths($offset, $offset_adjust);
			//if() {
			$new_value_offset_depths = O::get_offset_depths($new_value, $offset, $depth_of_offset);
			//print('$new_value, $new_value_offset_depths: ');var_dump($new_value, $new_value_offset_depths);exit(0);
			foreach($new_value_offset_depths as $new_value_offset => $new_value_depth) {
				$this->offset_depths[$new_value_offset] = $new_value_depth;
			}
			//ksort($this->offset_depths);
			//foreach($this->LOM as $LOM_index => $LOM_value) {
			//	if($LOM_value[2] >= $offset) {
			//		$this->LOM[$LOM_index][2] += $offset_adjust;
			//	}
			//}
			//print('$this->LOM mid new_: ');O::var_dump_full($this->LOM);
			//$this->LOM = O::internal_new($this->LOM, $new_LOM, $selector);
			//print('$this->LOM after new_: ');O::var_dump_full($this->LOM);
			
			// strictly speaking, the solution to properly updating the context with a new tag will be for the context to be structured in such a way that a context1_value refers to the context2_value of a previous context entry.
			// this will require some thought and some coding and will probably be left until version 0.3. this temporary solution may be good enough with certain help by the coder when using LOM (intelligently resetting the context to avoid problems)
			// proper context updating for new_() not written yet!
			
			if($this->use_context) {
				foreach($this->context as $context_index => $context_value) {
					$new_context2_entry = false;
					$new_context3_entry = false;
					if($context_value[1] !== false) {
						foreach($context_value[1] as $context1_index => $context1_value) {
							if($context1_value[0] <= $offset && $context1_value[0] + $context1_value[1] > $offset) {
								$this->context[$context_index][1][$context1_index][1] += $offset_adjust;
							} elseif($context1_value[0] >= $offset) {
								$this->context[$context_index][1][$context1_index][0] += $offset_adjust;
							}
							//if($context1_value[1] >= $offset) {
							//	$this->context[$context_index][1][$context1_index][1] += $offset_adjust;
							//}
						}
						// this is where the context_value1s would be properly chained and thus we could be confident they are properly updated
					} else { // this context uses the whole code so there is a definate possibility the new value matches the selector
					//	O::reset_context();
					//	break;
						$result = O::get_tagged($context_value[0], array(array($new_value, $offset)), false, true);
						//print('$context_value, $new_value, $offset, $result: ');var_dump($context_value, $new_value, $offset, $result);
						if(sizeof($result) === 1) {
							$new_context2_entry = O::context_array($result)[0];
							$new_context3_entry = O::get_offset_depths($result[0][0], $result[0][1], O::depth($result[0][1]));
						} elseif(sizeof($result) > 1) {
							print('$context_value, $context_value[0], $result: ');var_dump($context_value, $context_value[0], $result);
							O::fatal_error('sizeof($result) > 1 in adjusting context entries in new_');
						}
					}
					foreach($context_value[2] as $context2_index => $context2_value) {
						if($context2_value[0] <= $offset && $context2_value[0] + $context2_value[1] > $offset) {
							$this->context[$context_index][2][$context2_index][1] += $offset_adjust;
						} elseif($context2_value[0] >= $offset) {
							$this->context[$context_index][2][$context2_index][0] += $offset_adjust;
						}
						//if($context2_value[1] >= $offset) {
						//	$this->context[$context_index][2][$context2_index][1] += $offset_adjust;
						//}
					}
					if($new_context2_entry !== false) {
						$new_context2 = array();
						$new_context3 = array();
						$did_new_context2 = false;
						foreach($context_value[2] as $context2_index => $context2_value) {
							if($new_context2_entry[0] > $context2_value[0]) {
								
							} elseif($new_context2_entry !== false) {
								$new_context2[] = $new_context2_entry;
								$new_context3[] = $new_context3_entry; // since they correspond
								$new_context2_entry = false;
								$new_context3_entry = false;
							}
							$new_context2[] = $context2_value;
							$new_context3[] = $context_value[3][$context2_index];
						}
						$this->context[$context_index][2] = $new_context2;
						$this->context[$context_index][3] = $new_context3;
						$this->context = array($this->context[0]); // hack, but it's probably good enough for now (only keeping the first context entry)
						break;
					}
				}
			}
		}
		$new_matches = array(array($new_value, $offset));
		//$selector_matches = array($new_LOM);
		//$selector_matches = $new_LOM;
		//$selector_matches = $new_value;
		//if($this->use_context) {
		//	//$this->context[] = array($selector, false, $selector, $selector_matches);
		//	$this->context[] = array($selector, false, $offset, $selector_matches);
		//}
	} elseif(is_string($selector)) {
		//print('is_string($selector) in new_<br>');
		//$selector_matches = O::get($selector);
		//$selector_matches = O::get_closing_LOM_indices($selector, false, false, false, false);
		//$selector_matches = O::get_LOM_indices($selector, false, false, false, false); // kind of ugly to always add before? after? the opening tag
		/*$selector_matches = O::get_opening_LOM_indices($selector, false, false, false, false);
		foreach($selector_matches as $result) {
			//if($this->LOM[$result][0] === 1) { // tag node
			//	O::new_($new_value, $result);
			//} else {
				$new_matches = array_merge($new_matches, O::new_($new_value, $result + 1));
			//}
		}*/
		$selector_matches = O::get_tagged($selector, false, false, false, false);
		//print('$selector_matches in is_string($selector) in new_: ');var_dump($selector_matches);
		//foreach($selector_matches as $index => $value) {
		$index = sizeof($selector_matches) - 1; // have to go in reverse order
		while($index > -1) {
			$new_matches = array_merge($new_matches, O::new_($new_value, $selector_matches[$index][1] + O::strpos_last($selector_matches[$index][0], '<'))); // add inside of the closing tag
			$index--;
		}
		/*
		//if($parent_node_only) { // not an option, currently
		//	$selector_matches = O::get($selector, $parent_node, false, true, true);
		//} else {
			$selector_matches = O::get($selector, false, false);
		//}
		//print('$selector, $new_value, $selector_matches in new_: ');var_dump($selector, $new_value, $selector_matches);
		if($this->use_context) {
			if(sizeof($selector_matches) === 1) {
				foreach($selector_matches as $result) {
					foreach($result as $last_index => $last_value) {  }
					break;
				}
				$new_context_array = array(O::get_tag_name($new_value), false, $last_index, array());
			} else {
				//O::fatal_error('would have to change the code in some places for context[2] to allow an array of values rather than only one value');
				$new_context_array = array(O::get_tag_name($new_value), false, $this->offsets_from_get, array());
			}
		}
		//print('$new_context_array0: ');O::var_dump_full($new_context_array);
		$selector_matches = array();
		foreach($selector_matches as $result) {
			//print('$result in new_: ');O::var_dump_full($result);
			//foreach($result as $first_index => $first_value) { break; }
			foreach($result as $last_index => $last_value) {  }
			//$selection_range = $last_index - $first_index + 1;
			$new_LOM = O::generate_LOM($new_value, $last_index);
			//print('$first_index, $new_LOM in new_: ');O::var_dump_full($first_index, $new_LOM);
			//$this->LOM = O::insert($this->LOM, $last_index, $new_LOM);
			$this->LOM = O::internal_new($this->LOM, $new_LOM, $last_index);
			if($this->use_context) {
				if(sizeof($this->context) > 0) {
					//print('$this->context 717: ');var_dump($this->context);
					foreach($this->context as $context_index => $context_value) {
						if($this->context[$context_index][1] === false) { // then recalculate it
							$this->context[$context_index][3] = O::get($this->context[$context_index][0], $this->context[$context_index][1], false, true);
							$this->context[$context_index][2] = $this->offsets_from_get;
						} else {
							$initial_context_LOM_array = $this->context[$context_index][1];
							$this->context[$context_index][1] = O::internal_new($this->context[$context_index][1], $new_LOM, $last_index);
							if($initial_context_LOM_array !== $this->context[$context_index][1]) { // then recalculate it
								//$this->context[$context_index][2] = O::get_offsets($this->context[$context_index][0], $this->context[$context_index][1], true);
								$this->context[$context_index][3] = O::get($this->context[$context_index][0], $this->context[$context_index][1], false);
								$this->context[$context_index][2] = $this->offsets_from_get;
							}
						}
						//if(is_array($this->context[$context_index][2])) {
						//	foreach($context_value[3] as $index2 => $value2) {
						//		if(is_array($this->context[$context_index][3][$index2])) {
						//			$this->context[$context_index][3][$index2] = O::insert($this->context[$context_index][3][$index2], $last_index, $new_LOM);
						//		} elseif($last_index === $this->context[$context_index][2][$index2]) {
						//			$this->context[$context_index][3][$index2][$last_index] = $new_LOM[$last_index];
						//		}
						//		if($last_index <= $index2) {
						//			$this->context[$context_index][2] += $selection_range;
						//			if(isset($this->context[$context_index][3][$index]) && !is_array($this->context[$context_index][3][$index])) {
						//				if(isset($this->context[$context_index][3][$index + $selection_range])) {
						//					O::fatal_error('rare case where shifting indices due to adding of tag(s) creates a collision which hasn\'t been coded yet.');
						//				}
						//				$this->context[$context_index][3][$index + $selection_range] = $this->context[$context_index][3][$index];
						//				unset($this->context[$context_index][3][$index]);
						//			}
						//		}
						//	}
						//} else {
						//	if($this->context[$context_index][2] === $last_index) {
						//		$this->context[$context_index][3] = $new_LOM[$last_index][1];
						//	}
						//	if($last_index <= $this->context[$context_index][2]) {
						//		$this->context[$context_index][2] += $selection_range;
						//	}
						//}
					}
				}
			}
			if($this->use_context) {
				$new_context_array[3][] = $new_LOM;
			}
			$selector_matches[] = $new_LOM;
		}
		if($this->use_context) {
			$this->context[] = $new_context_array;
		}
		*/
	} elseif(is_array($selector)) { // recurse??
		//print('is_array($selector) in new_<br>');
		if(O::all_entries_are_arrays($selector)) {
			$index = sizeof($selector_matches) - 1; // have to go in reverse order
			while($index > -1) {
				$new_matches = array_merge($new_matches, O::new_($new_value, $selector_matches[$index][1]));
				$index--;
			}
		} else {
			$new_matches = array_merge($new_matches, O::new_($new_value, $selector[1]));
		}
		/*$counter = 0;
		//print('here37589708-0<br>');
		if(sizeof($selector) === 1) {
			//print('here37589708-1<br>');
			$its_an_array_of_LOM_arrays = false;
			foreach($selector as $index => $value) {
				//print('here37589708-2<br>');
				$counter = 0;
				$proper_LOM_array_format_counter = 0;
				foreach($value as $index2 => $value2) {
					//print('here37589708-3<br>');
					if($value2[0] === 0 || $value2[0] === 1) {
						//print('here37589708-4<br>');
						$proper_LOM_array_format_counter++;
					}
					//print('here37589708-5<br>');
					$counter++;
					if($counter === 2) {
						//print('here37589708-6<br>');
						break;
					}
				}
				//print('here37589708-7<br>');
				if($counter === $proper_LOM_array_format_counter) {
					//print('here37589708-8<br>');
					$its_an_array_of_LOM_arrays = true;
					break;
				}
			}
			//print('here37589708-9<br>');
			if($its_an_array_of_LOM_arrays) {
				//print('here37589708-10<br>');
				$selector = $selector[$index];
			}
		}
		foreach($selector as $last_index => $last_value) {  }
		//$selector_matches = O::new_($new_value, $last_index - 1);
		$selector_matches = O::new_($new_value, $last_index);*/
		/*$selector_matches = array();
		if(O::all_sub_entries_are_arrays($selector)) {
			$index_matches = array();
			foreach($selector as $index => $value) {
				foreach($value as $last_index => $last_value) {  }
				$new_LOM = O::generate_LOM($new_value, $last_index);
				$this->LOM = O::internal_new($this->LOM, $new_LOM, $last_index);
				$selector = O::internal_new($selector, $new_LOM, $last_index);
				if($this->use_context) {
					foreach($this->context as $context_index => $context_value) {
						if($this->context[$context_index][1] === false) { // then recalculate it
							$this->context[$context_index][3] = O::get($this->context[$context_index][0], $this->context[$context_index][1], false, true);
							$this->context[$context_index][2] = $this->offsets_from_get;
						} else {
							$initial_context_LOM_array = $this->context[$context_index][1];
							$this->context[$context_index][1] = O::internal_new($this->context[$context_index][1], $new_LOM, $last_index);
							if($initial_context_LOM_array !== $this->context[$context_index][1]) { // then recalculate it
								//$this->context[$context_index][2] = O::get_offsets($this->context[$context_index][0], $this->context[$context_index][1], true);
								$this->context[$context_index][3] = O::get($this->context[$context_index][0], $this->context[$context_index][1], false);
								$this->context[$context_index][2] = $this->offsets_from_get;
							}
						}
					}
				}
				$selector_matches[] = $new_LOM;
				$index_matches[] = $last_index;
			}
		} else {
			foreach($selector as $last_index => $last_value) {  }
			$new_LOM = O::generate_LOM($new_value, $last_index);
			$this->LOM = O::internal_new($this->LOM, $new_LOM, $last_index);
			$selector = O::internal_new($selector, $new_LOM, $last_index);
			if($this->use_context) {
				foreach($this->context as $context_index => $context_value) {
					if($this->context[$context_index][1] === false) { // then recalculate it
						$this->context[$context_index][3] = O::get($this->context[$context_index][0], $this->context[$context_index][1], false, true);
						$this->context[$context_index][2] = $this->offsets_from_get;
					} else {
						$initial_context_LOM_array = $this->context[$context_index][1];
						$this->context[$context_index][1] = O::internal_new($this->context[$context_index][1], $new_LOM, $last_index);
						if($initial_context_LOM_array !== $this->context[$context_index][1]) { // then recalculate it
							//$this->context[$context_index][2] = O::get_offsets($this->context[$context_index][0], $this->context[$context_index][1], true);
							$this->context[$context_index][3] = O::get($this->context[$context_index][0], $this->context[$context_index][1], false);
							$this->context[$context_index][2] = $this->offsets_from_get;
						}
					}
				}
			}
			$selector_matches = array($new_LOM);
			$index_matches = $last_index;
		}
		if($this->use_context) {
			if(sizeof($index_matches) === 1) {
				$index_matches = $index_matches[0];
			}
			$this->context[] = array(O::get_tag_name($new_value), $selector, $index_matches, $selector_matches);
			//$this->context[] = array($selector, false, $selector, $selector_matches);
		}*/
	} else {
		print('$selector: ');var_dump($selector);
		O::fatal_error('Unknown selector type in new_');
	}
	//print('$this->LOM after new_: ');O::var_dump_full($this->LOM);exit(0);
	//print('$this->code after new_: ');O::var_dump_full($this->code);exit(0);
	//return $selector_matches;
	return $new_matches;
}

function delayed_new_($new_value, $selector) { // alias
	return O::delayed_new($new_value, $selector);
}

function delayed_new($new_value, $selector) {
	O::fatal_error('Using delayed_new in string-based LOM is very questionable since any intervening code change could alter the offsets. An implementation of this functionality specific to the code you are working on is recommended instead. Consider using a living variable for this.');
	if(is_array($new_value) && !is_array($selector)) { // swap them
		$temp_selector = $selector;
		$selector = $new_value;
		$new_value = $temp_selector;
	}
	if(is_numeric($selector)) {
		$selector = (int)$selector;
		$this->array_delayed_new[] = array($new_value, $selector);
	} elseif(is_string($selector)) {
		$selector_matches = O::get_tagged($selector, false, false);
		$index = sizeof($selector_matches) - 1; // questionable to go in reverse order here. would expect this reverse to occur in delayed_actions??
		while($index > -1) {
			//foreach($selector_matches[$index] as $first_index => $first_value) { break; }
			$this->array_delayed_new[] = array($new_value, $selector_matches[$index][1]);
			$index--;
		}
	} elseif(is_array($selector)) {
		//foreach($selector as $first_index => $first_value) { break; }
		if(O::all_entries_are_arrays($selector)) {
			$index = sizeof($selector) - 1;
			while($index > -1) {
				$this->array_delayed_new[] = array($new_value, $selector[$index][1]);
				$index--;
			}
		} else {
			$this->array_delayed_new[] = array($new_value, $selector[1]);
		}
	} else {
		print('$selector: ');var_dump($selector);
		O::fatal_error('Unknown selector type in delayed_new');
	}
	return true;
}

function rand($selector, $parent_node = false, $parent_node_only = false) {
	return O::random($selector, $parent_node, $parent_node_only);
}

function random($selector, $parent_node = false, $parent_node_only = false) {
	if(is_numeric($selector)) {
		$selector = (int)$selector;
		$selector_matches = O::get($selector, $parent_node, $parent_node_only);
	} elseif(is_string($selector)) {
		$selector_matches = O::get($selector, $parent_node, $parent_node_only);
	} elseif(is_array($selector)) {
		if(!is_array($selector[0])) {
			print('$selector: ');var_dump($selector);
			O::fatal_error('how to pick a random entry from something other than a LOM array is not coded yet');
		}
		$selector_matches = $selector;
	} else {
		print('$selector, $parent_node, $parent_node_only: ');var_dump($selector, $parent_node, $parent_node_only);
		O::fatal_error('unknown selector type in random()');
	}
	return $selector_matches[rand(0, sizeof($selector_matches) - 1)];
}

function delete($selector, $parent_node = false, $parent_node_only = false) {
	//print('$selector, $parent_node, $this->code, $this->LOM, $this->context before delete: ');var_dump($selector, $parent_node, $this->code, $this->LOM, $this->context);
	if($parent_node === false) {
	
	} elseif(!is_array($parent_node)) {
		print('$parent_node: ');var_dump($parent_node);
		O::fatal_error('!is_array($parent_node) is not coded for yet because how to get what the selector is referring to in a $parent_node that is a mere string without an accompanying offset is unclear');
	}
	// worth noting that it probably would have been easier to have this function be a sort of alias and return O::set($selector, '', $parent_node = false); although the assumption that set is working on only single values would no longer hold...
	if(is_numeric($selector)) { // treat it as an offset
		$selector = (int)$selector;
		//$tag_LOM_array = O::get_tag_LOM_array($selector);
		//print('$tag_LOM_array: ');O::var_dump_full($tag_LOM_array);exit(0);
		//$deleted_string = O::tostring($tag_LOM_array);
		$expanded_LOM = O::expand($this->code, $selector, false, false, 'lazy');
		//print('$expanded_LOM in delete: ');var_dump($expanded_LOM);
		$deleted_string = $expanded_LOM[1][0];
		//$offset = O::LOM_index_to_offset($selector);
		$offset = $selector;
		//print('$expanded_LOM, $deleted_string, $offset, $this->code: ');O::var_dump_full($expanded_LOM, $deleted_string, $offset, $this->code);
		$offset_adjust = -1 * strlen($deleted_string);
		if(!$parent_node_only) {
			//print('$this->code, $deleted_string, $offset in delete: ');var_dump($this->code, $deleted_string, $offset);
			$this->code = O::str_delete($this->code, $deleted_string, $offset);
			//print('$this->code, $this->string_operation_made_a_change after deleting: ');var_dump($this->code, $this->string_operation_made_a_change);
			if($this->string_operation_made_a_change) {
				$deleted_string_offset_depths = O::get_offset_depths($deleted_string, $offset);
				foreach($deleted_string_offset_depths as $deleted_string_offset => $deleted_string_depth) {
					unset($this->offset_depths[$deleted_string_offset]);
				}
				//foreach($tag_LOM_array as $last_index => $last_value) {  }
				//$this->LOM = O::internal_delete($this->LOM, $selector, $last_index);
				//print('$this->LOM after deleting: ');var_dump($this->LOM);
				//foreach($this->LOM as $LOM_index => $LOM_value) {
				//	if($LOM_value[2] >= $offset) {
				//		$LOM_value[2] += $offset_adjust;
				//	}
				//}
				if($this->use_context) {
					$deleted_string_context_array = O::context_array($expanded_LOM[1]);
					foreach($this->context as $context_index => $context_value) {
						if($context_value[1] !== false) {
							$counter = sizeof($context_value[1]) - 1;
							$unset_something = false;
							while($counter > -1) {
								if($context_value[1][$counter] === $deleted_string_context_array) {
									unset($this->context[$context_index][1][$counter]);
									$unset_something = true;
								}
								$counter--;
							}
							if($unset_something) {
								$this->context[$context_index][1] = array_values($this->context[$context_index][1]);
							}
							foreach($context_value[1] as $context1_index => $context1_value) {
								if($context1_value[0] <= $offset && $context1_value[0] + $context1_value[1] > $offset) {
									$this->context[$context_index][1][$context1_index][1] += $offset_adjust;
								} elseif($context1_value[0] >= $offset) {
									$this->context[$context_index][1][$context1_index][0] += $offset_adjust;
								}
								//if($context1_value[1] >= $offset) {
								//	$this->context[$context_index][1][$context1_index][1] += $offset_adjust;
								//}
							}
						}
						foreach($context_value[2] as $context2_index => $context2_value) {
							$counter = sizeof($context_value[2]) - 1;
							$unset_something = false;
							while($counter > -1) {
								if($context_value[2][$counter] === $deleted_string_context_array) {
									unset($this->context[$context_index][2][$counter]);
									unset($this->context[$context_index][3][$counter]); // since these correspond
									$unset_something = true;
								}
								$counter--;
							}
							if($unset_something) {
								$this->context[$context_index][2] = array_values($this->context[$context_index][2]);
								$this->context[$context_index][3] = array_values($this->context[$context_index][3]); // since these correspond
							}
							if($context2_value[0] <= $offset && $context2_value[0] + $context2_value[1] > $offset) {
								$this->context[$context_index][2][$context2_index][1] += $offset_adjust;
							} elseif($context2_value[0] >= $offset) {
								$this->context[$context_index][2][$context2_index][0] += $offset_adjust;
							}
							//if($context2_value[1] >= $offset) {
							//	$this->context[$context_index][2][$context2_index][1] += $offset_adjust;
							//}
						}
						foreach($context_value[3] as $context3_index => $context3_value) {
							foreach($deleted_string_offset_depths as $deleted_string_offset => $deleted_string_depth) {
								unset($context_value[3][$context3_index][$deleted_string_offset]);
							}
						}
					}
				}
				//print('before adjust_offset_depths in delete<br>');
				O::adjust_offset_depths($offset, $offset_adjust);
			}
		}
		if($parent_node !== false) {
			if(O::all_entries_are_arrays($parent_node)) {
				$counter = sizeof($parent_node) - 1;
				$unset_something = false;
				while($counter > -1) {
					if($parent_node[$counter] === $expanded_LOM[1]) {
						unset($parent_node[$counter]);
						$unset_something = true;
					}
					$counter--;
				}
				if($unset_something) {
					$parent_node = array_values($parent_node);
				}
				foreach($parent_node as $index => $value) {
					$parent_node[$index][0] = O::str_delete($parent_node[$index][0], $deleted_string, $offset - $parent_node[$index][1]);
					if($this->string_operation_made_a_change) {
						if($parent_node[$index][1] >= $offset) {
							$parent_node[$index][1] += $offset_adjust;
						}
					}
				}
			} else {
				if($parent_node === $expanded_LOM[1]) {
					$parent_node = false;
				} else {
					$parent_node[0] = O::str_delete($parent_node[0], $deleted_string, $offset - $parent_node[1]);
					if($this->string_operation_made_a_change) {
						if($parent_node[1] >= $offset) {
							$parent_node[1] += $offset_adjust;
						}
					}
				}
			}
		}
		/*
		$selector = (int)$selector;
		//$new_array = array();
		//$index_counter = false;
		if(!$parent_node_only) {
			$this->LOM = O::internal_delete($this->LOM, $selector, $selector);
		}
		$parent_node = O::internal_delete($parent_node, $selector, $selector);
		if($this->use_context && !$parent_node_only) {
			foreach($this->context as $context_index => $context_value) {
				if($this->context[$context_index][1] === false) { // then recalculate it
					$this->context[$context_index][3] = O::get($this->context[$context_index][0], $this->context[$context_index][1], false, true);
					$this->context[$context_index][2] = $this->offsets_from_get;
				} else {
					//print('$context_value[3]: ');var_dump($context_value[3]);
					$initial_context_LOM_array = $this->context[$context_index][1];
					$this->context[$context_index][1] = O::internal_delete($this->context[$context_index][1], $selector, $selector);
					if(sizeof($this->context[$context_index][1]) === 0) {
						//$this->context = O::internal_delete($this->context, $context_index, $context_index, true);
						unset($this->context[$context_index]);
					} elseif($initial_context_LOM_array !== $this->context[$context_index][1]) { // then recalculate it
						//$this->context[$context_index][2] = O::get_offsets($this->context[$context_index][0], $this->context[$context_index][1], true);
						$this->context[$context_index][3] = O::get($this->context[$context_index][0], $this->context[$context_index][1], false);
						$this->context[$context_index][2] = $this->offsets_from_get;
					}
				}
			}
			//sort($this->context);
			$this->context = array_values($this->context);
		}*/
	} elseif(is_string($selector)) {
		//$selector_matches = O::get_LOM_indices($selector, false, false, false, false);
		$selector_matches = O::get_tagged($selector, false, false, false, false);
		//print('$selector_matches in is_string($selector) in delete(): ');var_dump($selector_matches);
		$counter = sizeof($this->offsets_from_get) - 1;
		//if($parent_node === false) {
			while($counter > -1) { // go in reverse order
				//print('$counter, $this->offsets_from_get[$counter], O::opening_LOM_index_from_offset($this->offsets_from_get[$counter]): ');var_dump($counter, $this->offsets_from_get[$counter], O::opening_LOM_index_from_offset($this->offsets_from_get[$counter]));
				/*if($this->LOM[$selector_matches[$counter]][0] === 0) { // text node
					$parent_node = O::delete($selector_matches[$counter] - 1, $parent_node, $parent_node_only);
				} else {
					//$parent_node = O::delete(O::opening_LOM_index_from_offset($this->offsets_from_get[$counter]), $parent_node, $parent_node_only);
					$parent_node = O::delete($selector_matches[$counter], $parent_node, $parent_node_only);
				}*/
				$parent_node = O::delete($selector_matches[$counter][1], $parent_node, $parent_node_only);
				$counter--;
			}
		//}
		/*if($parent_node_only) {
			$selector_matches = O::get($selector, $parent_node, false, true, true);
		} else {
			$selector_matches = O::get($selector, false, false);
		}
		//print('$selector_matches in is_string($selector) in delete: ');var_dump($selector_matches);
		if(is_string($selector_matches)) {
			if(is_array($this->offsets_from_get)) {
				$index = $this->offsets_from_get[0];
			} else {
				$index = $this->offsets_from_get;
			}
			//print('$index by is_string($selector_matches) in delete: ');var_dump($index);exit(0);
			if(!$parent_node_only) {
				$this->LOM = O::internal_delete($this->LOM, $index - 1, $index + 1);
			}
			$parent_node = O::internal_delete($parent_node, $index - 1, $index + 1);
			if($this->use_context && !$parent_node_only) {
				foreach($this->context as $context_index => $context_value) {
					$initial_context_LOM_array = $this->context[$context_index][1];
					$this->context[$context_index][1] = O::internal_delete($this->context[$context_index][1], $index - 1, $index + 1);
					if(sizeof($this->context[$context_index][1]) === 0) {
						//$this->context = O::internal_delete($this->context, $context_index, $context_index, true);
						unset($this->context[$context_index]);
					} elseif($initial_context_LOM_array !== $this->context[$context_index][1]) { // then recalculate it
						//$this->context[$context_index][2] = O::get_offsets($this->context[$context_index][0], $this->context[$context_index][1], true);
						$this->context[$context_index][3] = O::get($this->context[$context_index][0], $this->context[$context_index][1], false);
						$this->context[$context_index][2] = $this->offsets_from_get;
					}
				}
				$this->context = array_values($this->context);
			}
		} else {
			if(sizeof($selector_matches) === 0) { // didn't find anything to delete
				return $parent_node;
			}
			foreach($selector_matches as $first_index => $first_value) { break; }
			if(is_string($first_value)) {
				$selector_matches = array_reverse($selector_matches, true); // since we don't want to disrupt the indices when deleting
				foreach($selector_matches as $index => $value) {
					if(!$parent_node_only) {
						$this->LOM = O::internal_delete($this->LOM, $index - 1, $index + 1);
					}
					$parent_node = O::internal_delete($parent_node, $index - 1, $index + 1);
					if($this->use_context && !$parent_node_only) {
						foreach($this->context as $context_index => $context_value) {
							$initial_context_LOM_array = $this->context[$context_index][1];
							$this->context[$context_index][1] = O::internal_delete($this->context[$context_index][1], $index - 1, $index + 1);
							if(sizeof($this->context[$context_index][1]) === 0) {
								//$this->context = O::internal_delete($this->context, $context_index, $context_index, true);
								unset($this->context[$context_index]);
							} elseif($initial_context_LOM_array !== $this->context[$context_index][1]) { // then recalculate it
								//$this->context[$context_index][2] = O::get_offsets($this->context[$context_index][0], $this->context[$context_index][1], true);
								$this->context[$context_index][3] = O::get($this->context[$context_index][0], $this->context[$context_index][1], false);
								$this->context[$context_index][2] = $this->offsets_from_get;
							}
						}
						$this->context = array_values($this->context);
					}
				}
			} else {
				$index = sizeof($selector_matches) - 1;
				while($index > -1) { // go in reverse order so that the indices are not disrupted by the deletions
					foreach($selector_matches[$index] as $first_index => $first_value) { break; }
					foreach($selector_matches[$index] as $last_index => $last_value) {  }
					//print('$first_index, $last_index in delete with string selector: ');var_dump($first_index, $last_index);
					if(!$parent_node_only) {
						$this->LOM = O::internal_delete($this->LOM, $first_index, $last_index);
					}
					$parent_node = O::internal_delete($parent_node, $first_index, $last_index);
					$index--;
				}
				if($this->use_context && !$parent_node_only) {
					foreach($this->context as $context_index => $context_value) {
						$index = sizeof($selector_matches) - 1;
						while($index > -1) { // go in reverse order so that the indices are not disrupted by the deletions
							if($this->context[$context_index][1] === false) { // then recalculate it
								$this->context[$context_index][3] = O::get($this->context[$context_index][0], $this->context[$context_index][1], false, true);
								$this->context[$context_index][2] = $this->offsets_from_get;
							} else {
								foreach($selector_matches[$index] as $first_index => $first_value) { break; }
								foreach($selector_matches[$index] as $last_index => $last_value) {  }
								$initial_context_LOM_array = $this->context[$context_index][1];
								$this->context[$context_index][1] = O::internal_delete($this->context[$context_index][1], $first_index, $last_index);
								if(sizeof($this->context[$context_index][1]) === 0) {
									//$this->context = O::internal_delete($this->context, $context_index, $context_index, true);
									unset($this->context[$context_index]);
								} elseif($initial_context_LOM_array !== $this->context[$context_index][1]) { // then recalculate it
									//$this->context[$context_index][2] = O::get_offsets($this->context[$context_index][0], $this->context[$context_index][1], true);
									$this->context[$context_index][3] = O::get($this->context[$context_index][0], $this->context[$context_index][1], false);
									$this->context[$context_index][2] = $this->offsets_from_get;
								}
							}
							$index--;
						}
					}
					//sort($this->context);
					$this->context = array_values($this->context);
				}
			}
		}*/
	} elseif(is_array($selector)) {
		//print('is_array($selector) in delete: ');O::var_dump_full($selector);
		if(O::all_entries_are_arrays($selector)) {
			$counter = sizeof($selector) - 1;
			//if($parent_node === false) {
				while($counter > -1) { // go in reverse order
					$parent_node = O::delete($selector[$counter][1], $parent_node, $parent_node_only);
					$counter--;
				}
			//}
		} else {
			//if($parent_node === false) {
				$parent_node = O::delete($selector[1], $parent_node, $parent_node_only);
			//}
		}
		//$recurse = false;
		//if(sizeof($selector) === 1) {
		//	foreach($selector as $selector_first_index => $selector_first_value) {  }
		//	if(!is_array($selector_first_value)) {
		//		$selector = $selector[$selector_first_index];
		//	}
		//}
		// was pretty in that it tried to handle fractal arrays but that's more work than it's worth
		// have to go in reverse order
		/*foreach($selector as $counter1 => $value) {  }
		while(isset($selector[$counter1])) {
			foreach($selector[$counter1] as $index2 => $value2) { break; }
			if(is_array($selector[$counter1][$index2])) {
				//print('nested array $selector[$counter1] in delete: ');O::var_dump_full($selector[$counter1]);
				// have to go in reverse order
				foreach($selector[$counter1] as $counter2 => $value2) {  }
				while(isset($selector[$counter1][$counter2])) {
					//print('deleting index2: ' . $counter2 . '<br>');
					$parent_node = O::delete($counter2, $parent_node);
					//print('nested array $selector[$counter1] after nested delete: ');O::var_dump_full($selector[$counter1]);
					$counter2--;
				}
			} else {
				//print('deleting index: ' . $counter1 . '<br>');
				$parent_node = O::delete($counter1, $parent_node);
			}
			$counter1--;
		}*/
		/*
		if(O::all_sub_entries_are_arrays($selector)) {
			foreach($selector as $index => $value) {
				foreach($value as $first_index => $first_value) { break; }
				foreach($value as $last_index => $last_value) {  }
				if(!$parent_node_only) {
					$this->LOM = O::internal_delete($this->LOM, $first_index, $last_index);
				}
				$parent_node = O::internal_delete($parent_node, $first_index, $last_index);
				if($this->use_context && !$parent_node_only) {
					foreach($this->context as $context_index => $context_value) {
						if($this->context[$context_index][1] === false) { // then recalculate it
							$this->context[$context_index][3] = O::get($this->context[$context_index][0], $this->context[$context_index][1], false, true);
							$this->context[$context_index][2] = $this->offsets_from_get;
						} else {
							$initial_context_LOM_array = $this->context[$context_index][1];
							$this->context[$context_index][1] = O::internal_delete($this->context[$context_index][1], $first_index, $last_index);
							if(sizeof($this->context[$context_index][1]) === 0) {
								//$this->context = O::internal_delete($this->context, $context_index, $context_index, true);
								unset($this->context[$context_index]);
							} elseif($initial_context_LOM_array !== $this->context[$context_index][1]) { // then recalculate it
								//$this->context[$context_index][2] = O::get_offsets($this->context[$context_index][0], $this->context[$context_index][1], true);
								$this->context[$context_index][3] = O::get($this->context[$context_index][0], $this->context[$context_index][1], false);
								$this->context[$context_index][2] = $this->offsets_from_get;
							}
						}
					}
					//sort($this->context);
					$this->context = array_values($this->context);
				}
			}
		} else {
			foreach($selector as $first_index => $first_value) { break; }
			foreach($selector as $last_index => $last_value) {  }
			if(!$parent_node_only) {
				$this->LOM = O::internal_delete($this->LOM, $first_index, $last_index);
			}
			$parent_node = O::internal_delete($parent_node, $first_index, $last_index);
			if($this->use_context && !$parent_node_only) {
				foreach($this->context as $context_index => $context_value) {
					if($this->context[$context_index][1] === false) { // then recalculate it
						$this->context[$context_index][3] = O::get($this->context[$context_index][0], $this->context[$context_index][1], false, true);
						$this->context[$context_index][2] = $this->offsets_from_get;
					} else {
						$initial_context_LOM_array = $this->context[$context_index][1];
						$this->context[$context_index][1] = O::internal_delete($this->context[$context_index][1], $first_index, $last_index);
						if(sizeof($this->context[$context_index][1]) === 0) {
							//$this->context = O::internal_delete($this->context, $context_index, $context_index, true);
							unset($this->context[$context_index]);
						} elseif($initial_context_LOM_array !== $this->context[$context_index][1]) { // then recalculate it
							//$this->context[$context_index][2] = O::get_offsets($this->context[$context_index][0], $this->context[$context_index][1], true);
							$this->context[$context_index][3] = O::get($this->context[$context_index][0], $this->context[$context_index][1], false);
							$this->context[$context_index][2] = $this->offsets_from_get;
						}
					}
				}
				//sort($this->context);
				$this->context = array_values($this->context);
			}
		}*/
	} else {
		print('$selector: ');var_dump($selector);
		O::fatal_error('Unknown selector type in delete');
	}
	//print('O::LOM_to_string($this->LOM) after delete: ');O::var_dump_full(O::LOM_to_string($this->LOM));
	//print('$this->code, $this->LOM, $this->context after delete: ');O::var_dump_full($this->code, $this->LOM, $this->context);
	if($parent_node === false) {
		return true;
	}
	return $parent_node;
}

function strip($selector, $parent_node = false, $parent_node_only = false) { // alias
	return O::strip_tag($selector, $parent_node, $parent_node_only);
}

function striptag($selector, $parent_node = false, $parent_node_only = false) { // alias
	return O::strip_tag($selector, $parent_node, $parent_node_only);
}

function strip_tag($selector, $parent_node = false, $parent_node_only = false) {
	O::fatal_error('strip_tag seems unused');
	//print('$selector in strip_tag: ');var_dump($selector);
	//print('O::LOM_to_string($this->LOM) before strip_tag: ');O::var_dump_full(O::LOM_to_string($this->LOM));
	// worth noting that it probably would have been easier to have this function be a sort of alias and return O::set($selector, '', $parent_node = false); although the assumption that set is working on only single values would no longer hold...
	if(is_numeric($selector)) { // treat it as an offset
		//print('is_numeric($selector) in strip_tag<br>');
		$selector = (int)$selector;
		//$new_array = array();
		//$index_counter = false;
		$match = O::LOM_match_by_tagname(O::tagname($selector), array(array_slice($this->LOM, $selector, sizeof($this->LOM) - 1, true)), false);
		//foreach($match as $last_index => $last_value) {  }
		//print('O::tagname($selector), array_slice($this->LOM, $selector, sizeof($this->LOM) - 1, true), $match: ');var_dump(O::tagname($selector), array_slice($this->LOM, $selector, sizeof($this->LOM) - 1, true), $match);
		//$last_index = sizeof($match) - 1;
		foreach($match[0] as $last_index => $last_value) {  }
		$opening_tag = O::tostring($this->LOM[$selector]);
		$closing_tag = O::tostring($this->LOM[$last_index]);
		$opening_offset = $this->LOM[$selector][2];
		$closing_offset = $this->LOM[$last_index][2];
		$opening_offset_adjust = -1 * strlen($opening_string);
		$closing_offset_adjust = -1 * strlen($closing_string);
		if(!$parent_node_only) {
			$this->LOM = O::internal_delete($this->LOM, $selector, $selector);
			$this->LOM = O::internal_delete($this->LOM, $last_index, $last_index);
			$this->code = substr($this->code, 0, $opening_offset) . substr($this->code, $opening_offset + strlen($opening_tag), $closing_offset - $opening_offset - strlen($opening_tag)) . substr($this->code, $closing_offset + strlen($closing_tag));
			foreach($this->LOM as $LOM_index => $LOM_value) {
				if($LOM_value[2] >= $opening_offset) {
					$this->LOM[$LOM_index][2] += $opening_offset_adjust;
				}
				if($LOM_value[2] >= $closing_offset) {
					$this->LOM[$LOM_index][2] += $closing_offset_adjust;
				}
			}
		}
		if(O::all_entries_are_arrays($parent_node)) {
			foreach($parent_node as $index => $value) {
				$parent_node[$index][0] = O::str_delete($parent_node[$index][0], $closing_tag, $closing_offset - $parent_node[$index][1]); // closing first
				$parent_node[$index][0] = O::str_delete($parent_node[$index][0], $opening_tag, $opening_offset - $parent_node[$index][1]);
				if($parent_node[$index][1] >= $opening_offset) {
					$parent_node[$index][1] += $opening_offset_adjust;
				}
				if($parent_node[$index][1] >= $closing_offset) {
					$parent_node[$index][1] += $closing_offset_adjust;
				}
			}
		} else {
			$parent_node[0] = O::str_delete($parent_node[0], $closing_tag, $closing_offset - $parent_node[1]); // closing first
			$parent_node[0] = O::str_delete($parent_node[0], $opening_tag, $opening_offset - $parent_node[1]);
			if($parent_node[1] >= $opening_offset) {
				$parent_node[1] += $opening_offset_adjust;
			}
			if($parent_node[1] >= $closing_offset) {
				$parent_node[1] += $closing_offset_adjust;
			}
		}
		if($this->use_context && !$parent_node_only) {
			foreach($this->context as $context_index => $context_value) {
				if($context_value[1] !== false) {
					foreach($context_value[1] as $context1_index => $context1_value) {
						if($closing_offset >= $context1_value[1] && $closing_offset < $context1_value[1] + strlen($context1_value[0])) { // closing first
							$this->context[$context_index][1][$context1_index][0] = O::str_delete($this->context[$context_index][1][$context1_index][0], $closing_tag, $closing_offset - $context1_value[1]);
						}
						if($opening_offset >= $context1_value[1] && $opening_offset < $context1_value[1] + strlen($context1_value[0])) {
							$this->context[$context_index][1][$context1_index][0] = O::str_delete($this->context[$context_index][1][$context1_index][0], $opening_tag, $opening_offset - $context1_value[1]);
						}
						if($context1_value[1] > $opening_offset) {
							$this->context[$context_index][1][$context1_index][1] += $opening_offset_adjust;
						}
						if($context1_value[1] > $closing_offset) {
							$this->context[$context_index][1][$context1_index][1] += $closing_offset_adjust;
						}
					}
				}
				foreach($context_value[2] as $context2_index => $context2_value) {
					if($context2_value > $opening_offset) {
						$this->context[$context_index][2][$context2_index] += $opening_offset_adjust;
					}
					if($context2_value > $closing_offset) {
						$this->context[$context_index][2][$context2_index] += $closing_offset_adjust;
					}
				}
				if(is_array($context_value[3])) {
					foreach($context_value[3] as $context3_index => $context3_value) {
						if($closing_offset >= $context3_value[1] && $closing_offset < $context3_value[1] + strlen($context3_value[0])) { // closing first
							$this->context[$context_index][3][$context3_index][0] = O::str_delete($this->context[$context_index][3][$context3_index][0], $closing_tag, $closing_offset - $context3_value[1]);
						}
						if($opening_offset >= $context3_value[1] && $opening_offset < $context3_value[1] + strlen($context3_value[0])) {
							$this->context[$context_index][3][$context3_index][0] = O::str_delete($this->context[$context_index][3][$context3_index][0], $opening_tag, $opening_offset - $context3_value[1]);
						}
						if($context3_value[1] > $opening_offset) {
							$this->context[$context_index][3][$context3_index][1] += $opening_offset_adjust;
						}
						if($context3_value[1] > $closing_offset) {
							$this->context[$context_index][3][$context3_index][1] += $closing_offset_adjust;
						}
					}
				}
			}
		}
		/*
		$parent_node = O::internal_delete($parent_node, $selector, $selector);
		$parent_node = O::internal_delete($parent_node, $last_index, $last_index);
		if($this->use_context && !$parent_node_only) {
			foreach($this->context as $context_index => $context_value) {
				//print('$context_value[3]: ');var_dump($context_value[3]);
				if($this->context[$context_index][1] === false) { // then recalculate it
					$this->context[$context_index][3] = O::get($this->context[$context_index][0], $this->context[$context_index][1], false, true);
					$this->context[$context_index][2] = $this->offsets_from_get;
				} else {
					$initial_context_LOM_array = $this->context[$context_index][1];
					$this->context[$context_index][1] = O::internal_delete($this->context[$context_index][1], $selector, $selector);
					$this->context[$context_index][1] = O::internal_delete($this->context[$context_index][1], $last_index, $last_index);
					if(sizeof($this->context[$context_index][1]) === 0) {
						//$this->context = O::internal_delete($this->context, $context_index, $context_index, true);
						unset($this->context[$context_index]);
					} elseif($initial_context_LOM_array !== $this->context[$context_index][1]) { // then recalculate it
						//$this->context[$context_index][2] = O::get_offsets($this->context[$context_index][0], $this->context[$context_index][1], true);
						$this->context[$context_index][3] = O::get($this->context[$context_index][0], $this->context[$context_index][1], false);
						$this->context[$context_index][2] = $this->offsets_from_get;
					}
				}
			}
			//sort($this->context);
			$this->context = array_values($this->context);
		}*/
	} elseif(is_string($selector) && $selector[0] === '<') { // would like this type of data type handling in more functions
		//print('is_string($selector) && $selector[0] === '<' in strip_tag<br>');
		return substr($selector, strpos($selector, '>') + 1, strlen($selector) - (strpos($selector, '>') + 1) - (strlen($selector) - O::strpos_last($selector, '<')));
	} elseif(is_string($selector)) {
		//print('is_string($selector) in strip_tag<br>');
		$selector_matches = O::get_LOM_indices($selector, false, false, false, false);
		//print('$selector_matches: ');var_dump($selector_matches);
		$counter = sizeof($selector_matches) - 1;
		while($counter > -1) { // go in reverse order
			//print('array_slice: ');O::var_dump_full(array_slice($this->LOM, $selector_matches[$counter] - 10, 20, true));
			if($this->LOM[$selector_matches[$counter]][0] === 0) { // text node
				$parent_node = O::strip_tag($selector_matches[$counter] - 1, $parent_node, $parent_node_only);
			} else {
				$parent_node = O::strip_tag($selector_matches[$counter], $parent_node, $parent_node_only);
			}
			$counter--;
		}
		/*if($parent_node_only) {
			$selector_matches = O::get($selector, $parent_node, false, true, true);
		} else {
			$selector_matches = O::get($selector, false, false);
		}
		//print('$selector_matches for string $selector in strip_tag: ');var_dump($selector_matches);
		$index = sizeof($selector_matches) - 1;
		//print('$parent_node before: ');O::var_dump_full($parent_node);
		while($index > -1) { // go in reverse order so that the indices are not disrupted by the deletions
			foreach($selector_matches[$index] as $first_index => $first_value) { break; }
			foreach($selector_matches[$index] as $last_index => $last_value) {  }
			//print('$first_index, $last_index in strip_tag with string selector: ');var_dump($first_index, $last_index);
			if(!$parent_node_only) {
				$this->LOM = O::internal_delete($this->LOM, $last_index, $last_index);
				$this->LOM = O::internal_delete($this->LOM, $first_index, $first_index);
			}
			$parent_node = O::internal_delete($parent_node, $last_index, $last_index);
			$parent_node = O::internal_delete($parent_node, $first_index, $first_index);
			$index--;
			//print('$parent_node mid: ');O::var_dump_full($parent_node);
		}
		//print('$parent_node after: ');O::var_dump_full($parent_node);exit(0);
		if($this->use_context && !$parent_node_only) {
			foreach($this->context as $context_index => $context_value) {
				$index = sizeof($selector_matches) - 1;
				while($index > -1) { // go in reverse order so that the indices are not disrupted by the deletions
					if($this->context[$context_index][1] === false) { // then recalculate it
						$this->context[$context_index][3] = O::get($this->context[$context_index][0], $this->context[$context_index][1], false, true);
						$this->context[$context_index][2] = $this->offsets_from_get;
					} else {
						foreach($selector_matches[$index] as $first_index => $first_value) { break; }
						foreach($selector_matches[$index] as $last_index => $last_value) {  }
						$initial_context_LOM_array = $this->context[$context_index][1];
						$this->context[$context_index][1] = O::internal_delete($this->context[$context_index][1], $last_index, $last_index);
						$this->context[$context_index][1] = O::internal_delete($this->context[$context_index][1], $first_index, $first_index);
						if(sizeof($this->context[$context_index][1]) === 0) {
							//$this->context = O::internal_delete($this->context, $context_index, $context_index, true);
							unset($this->context[$context_index]);
						} elseif($initial_context_LOM_array !== $this->context[$context_index][1]) { // then recalculate it
							//$this->context[$context_index][2] = O::get_offsets($this->context[$context_index][0], $this->context[$context_index][1], true);
							$this->context[$context_index][3] = O::get($this->context[$context_index][0], $this->context[$context_index][1], false);
							$this->context[$context_index][2] = $this->offsets_from_get;
						}
					}
					$index--;
				}
			}
			//sort($this->context);
			$this->context = array_values($this->context);
		}*/
	} elseif(is_array($selector)) {
		//print('is_array($selector) in strip_tag<br>');
		if(O::all_entries_are_arrays($selector)) {
			$counter = sizeof($selector) - 1;
			while($counter > -1) { // go in reverse order
				$parent_node = O::strip_tag(O::opening_LOM_index_from_offset($selector[$counter][1]), $parent_node, $parent_node_only);
				$counter--;
			}
		} else {
			//print('$parent_node before strip: ');var_dump($parent_node);
			$parent_node = O::strip_tag(O::opening_LOM_index_from_offset($selector[1]), $parent_node, $parent_node_only);
			//print('$parent_node after strip: ');var_dump($parent_node);
		}
		/*if(O::all_sub_entries_are_arrays($selector)) {
			foreach($selector as $index => $value) {
				foreach($value as $first_index => $first_value) { break; }
				foreach($value as $last_index => $last_value) {  }
				if(!$parent_node_only) {
					$this->LOM = O::internal_delete($this->LOM, $last_index, $last_index);
					$this->LOM = O::internal_delete($this->LOM, $first_index, $first_index);
				}
				$parent_node = O::internal_delete($parent_node, $last_index, $last_index);
				$parent_node = O::internal_delete($parent_node, $first_index, $first_index);
				if($this->use_context && !$parent_node_only) {
					foreach($this->context as $context_index => $context_value) {
						if($this->context[$context_index][1] === false) { // then recalculate it
							$this->context[$context_index][3] = O::get($this->context[$context_index][0], $this->context[$context_index][1], false, true);
							$this->context[$context_index][2] = $this->offsets_from_get;
						} else {
							$initial_context_LOM_array = $this->context[$context_index][1];
							$this->context[$context_index][1] = O::internal_delete($this->context[$context_index][1], $last_index, $last_index);
							$this->context[$context_index][1] = O::internal_delete($this->context[$context_index][1], $first_index, $first_index);
							if(sizeof($this->context[$context_index][1]) === 0) {
								//$this->context = O::internal_delete($this->context, $context_index, $context_index, true);
								unset($this->context[$context_index]);
							} elseif($initial_context_LOM_array !== $this->context[$context_index][1]) { // then recalculate it
								//$this->context[$context_index][2] = O::get_offsets($this->context[$context_index][0], $this->context[$context_index][1], true);
								$this->context[$context_index][3] = O::get($this->context[$context_index][0], $this->context[$context_index][1], false);
								$this->context[$context_index][2] = $this->offsets_from_get;
							}
						}
					}
					//sort($this->context);
					$this->context = array_values($this->context);
				}
			}
		} else {
			foreach($selector as $first_index => $first_value) { break; }
			foreach($selector as $last_index => $last_value) {  }
			if(!$parent_node_only) {
				$this->LOM = O::internal_delete($this->LOM, $last_index, $last_index);
				$this->LOM = O::internal_delete($this->LOM, $first_index, $first_index);
			}
			$parent_node = O::internal_delete($parent_node, $last_index, $last_index);
			$parent_node = O::internal_delete($parent_node, $first_index, $first_index);
			if($this->use_context && !$parent_node_only) {
				foreach($this->context as $context_index => $context_value) {
					if($this->context[$context_index][1] === false) { // then recalculate it
						$this->context[$context_index][3] = O::get($this->context[$context_index][0], $this->context[$context_index][1], false, true);
						$this->context[$context_index][2] = $this->offsets_from_get;
					} else {
						$initial_context_LOM_array = $this->context[$context_index][1];
						$this->context[$context_index][1] = O::internal_delete($this->context[$context_index][1], $last_index, $last_index);
						$this->context[$context_index][1] = O::internal_delete($this->context[$context_index][1], $first_index, $first_index);
						if(sizeof($this->context[$context_index][1]) === 0) {
							//$this->context = O::internal_delete($this->context, $context_index, $context_index, true);
							unset($this->context[$context_index]);
						} elseif($initial_context_LOM_array !== $this->context[$context_index][1]) { // then recalculate it
							//$this->context[$context_index][2] = O::get_offsets($this->context[$context_index][0], $this->context[$context_index][1], true);
							$this->context[$context_index][3] = O::get($this->context[$context_index][0], $this->context[$context_index][1], false);
							$this->context[$context_index][2] = $this->offsets_from_get;
						}
					}
				}
				//sort($this->context);
				$this->context = array_values($this->context);
			}
		}*/
	} else {
		print('$selector: ');var_dump($selector);
		O::fatal_error('Unknown selector type in strip_tag');
	}
	//print('O::LOM_to_string($this->LOM) after strip_tag: ');O::var_dump_full(O::LOM_to_string($this->LOM));
	//print('$this->LOM after strip_tag: ');O::var_dump_full($this->LOM);
	return $parent_node;
}

function delayed_delete($selector) {
	O::fatal_error('Using delayed_delete in string-based LOM is very questionable since any intervening code change could alter the offsets. An implementation of this functionality specific to the code you are working on is recommended instead. Consider using a living variable for this.');
	if(is_numeric($selector)) {
		$selector = (int)$selector;
		$this->array_delayed_delete[] = array($selector);
	} elseif(is_string($selector)) {
		$selector_matches = O::get_tagged($selector, false, false);
		$index = sizeof($selector_matches) - 1;
		while($index > -1) {
			//foreach($selector_matches[$index] as $first_index => $first_value) { break; }
			//foreach($selector_matches[$index] as $last_index => $last_value) {  }
			$this->array_delayed_delete[] = array($selector_matches[$index][1]);
			$index--;
		}
	} elseif(is_array($selector)) {
		//foreach($selector as $first_index => $first_value) { break; }
		//foreach($selector as $last_index => $last_value) {  }
		if(O::all_entries_are_arrays($selector)) {
			$index = sizeof($selector) - 1;
			while($index > -1) {
				$this->array_delayed_delete[] = array($selector[$index][1]);
				$index--;
			}
		} else {
			$this->array_delayed_delete[] = array($selector[1]);
		}
	} else {
		print('$selector: ');var_dump($selector);
		O::fatal_error('Unknown selector type in delayed_delete');
	}
	return true;
}

function delayed_actions() {
	//print('$this->array_delayed_delete, $this->array_delayed_new at the start of delayed_actions: ');var_dump($this->array_delayed_delete, $this->array_delayed_new);
	O::fatal_error('Using delayed_actions in string-based LOM is very questionable since any intervening code change could alter the offsets. An implementation of this functionality specific to the code you are working on is recommended instead. Consider using a living variable for this.');
	//O::fatal_error('might have to alter this function to pass the normal parameters for the function like delete() new_() instead of internal parameters... not sure');
	$this->array_delayed_delete = array_unique($this->array_delayed_delete);
	$this->array_delayed_new = array_unique($this->array_delayed_new);
	while(sizeof($this->array_delayed_delete) > 0) {
		
	}
	/*while(sizeof($this->array_delayed_delete) > 0) {
		//print('$this->array_delayed_delete start of while: ');var_dump($this->array_delayed_delete);
		//print('$this->array_delayed_delete: ');O::var_dump_full($this->array_delayed_delete);
		foreach($this->array_delayed_delete as $index => $value) {
			$first_index = $value[0];
			$last_index = $value[1];
			$selection_range = $last_index - $first_index + 1;
			$this->LOM = O::internal_delete($this->LOM, $first_index, $last_index);
			if($this->use_context) {
				foreach($this->context as $context_index => $context_value) {
					if($this->context[$context_index][1] === false) { // then recalculate it
						$this->context[$context_index][3] = O::get($this->context[$context_index][0], $this->context[$context_index][1], false, true);
						$this->context[$context_index][2] = $this->offsets_from_get;
					} else {
						$initial_context_LOM_array = $this->context[$context_index][1];
						$this->context[$context_index][1] = O::internal_delete($this->context[$context_index][1], $first_index, $last_index);
						if(sizeof($this->context[$context_index][1]) === 0) {
							//$this->context = O::internal_delete($this->context, $context_index, $context_index, true);
							unset($this->context[$context_index]);
						} elseif($initial_context_LOM_array !== $this->context[$context_index][1]) { // then recalculate it
							//$this->context[$context_index][2] = O::get_offsets($this->context[$context_index][0], $this->context[$context_index][1], true);
							$this->context[$context_index][3] = O::get($this->context[$context_index][0], $this->context[$context_index][1], false);
							$this->context[$context_index][2] = $this->offsets_from_get;
						}
					}
				}
				//sort($this->context);
				$this->context = array_values($this->context);
			}
			break;
		}
		unset($this->array_delayed_delete[$index]);
		//print('sizeof($this->array_delayed_delete) after unset: ');var_dump(sizeof($this->array_delayed_delete));exit(0);
		// now adjust the values in delayed arrays
		foreach($this->array_delayed_delete as $index => $value) {
			if($this->array_delayed_delete[$index][0] > $first_index) {
				$this->array_delayed_delete[$index][0] -= $selection_range;
				$this->array_delayed_delete[$index][1] -= $selection_range;
			}
		}
		foreach($this->array_delayed_new as $index => $value) {
			if($this->array_delayed_new[$index][1] > $first_index) {
				$this->array_delayed_new[$index][1] -= $selection_range;
			}
		}
	}*/
	while(sizeof($this->array_delayed_new) > 0) {
		//print('$this->array_delayed_new start of while: ');var_dump($this->array_delayed_new);
		foreach($this->array_delayed_new as $index => $value) {
			$new_value = $value[0];
			$first_index = $value[1];
			$new_LOM = O::generate_LOM($new_value, $first_index);
			//if($first_index == 0) { // debug
			//	print('$new_value, $first_index, $new_LOM, $this->array_delayed_new: ');var_dump($new_value, $first_index, $new_LOM, $this->array_delayed_new);exit(0);
			//}
			$this->LOM = O::internal_new($this->LOM, $new_LOM, $first_index);
			if($this->use_context) {
				foreach($this->context as $context_index => $context_value) {
					if($this->context[$context_index][1] === false) { // then recalculate it
						$this->context[$context_index][3] = O::get($this->context[$context_index][0], $this->context[$context_index][1], false, true);
						$this->context[$context_index][2] = $this->offsets_from_get;
					} else {
						$initial_context_LOM_array = $this->context[$context_index][1];
						$this->context[$context_index][1] = O::internal_new($this->context[$context_index][1], $new_LOM, $first_index);
						if($initial_context_LOM_array !== $this->context[$context_index][1]) { // then recalculate it
							//$this->context[$context_index][2] = O::get_offsets($this->context[$context_index][0], $this->context[$context_index][1], true);
							$this->context[$context_index][3] = O::get($this->context[$context_index][0], $this->context[$context_index][1], false);
							$this->context[$context_index][2] = $this->offsets_from_get;
						}
					}
				}
			}
			break;
		}
		unset($this->array_delayed_new[$index]);
		// now adjust the values in delayed array
		foreach($this->array_delayed_new as $index => $value) {
			if($this->array_delayed_new[$index][1] >= $first_index) {
				$this->array_delayed_new[$index][1] += sizeof($new_LOM);
			}
		}
		//O::validate();
	}
	//print('$this->array_delayed_delete, $this->array_delayed_new at the end of delayed_actions: ');var_dump($this->array_delayed_delete, $this->array_delayed_new);
	return true;
}

// notice that add/subtract follow the sentence structure "add x to y" while multiply/divide follow the sentence strcuture "divide y by x" in the order they expect their function parameters
function add($to_add, $selector, $parent_node = false, $parent_node_only = false) {
	if(is_array($to_add) && !is_array($selector)) { // swap them
		$temp_selector = $selector;
		$selector = $to_add;
		$to_add = $temp_selector;
	}
	if(!is_numeric($selector) && !is_numeric($to_add)) {
		$to_add = O::get($to_add, $parent_node);
	}
	if(is_numeric($selector) && !is_numeric($to_add)) {
		$temp_selector = $selector;
		$selector = $to_add;
		$to_add = $temp_selector;
	}
	if($to_add === false) {
		O::fatal_error('to_add false in add');
		$to_add = O::get($value_to_add_selector, $value_to_add_parent_node);
	}
	//print('$to_add, $selector, $parent_node, $parent_node_only in add after parameter swapping: ');var_dump($to_add, $selector, $parent_node, $parent_node_only);
	if(is_numeric($selector)) { // treat it as an offset
		//print('here8567080<br>');
		$selector = (int)$selector;
		$parent_node = O::set($selector, O::get($selector, $parent_node) + $to_add, $parent_node, $parent_node_only);
	} elseif(is_string($selector)) {
		//print('here8567081<br>');
		//$index_results = O::get_LOM_indices($selector, $parent_node);
		//$offsets = O::get_offsets($selector, $parent_node);
		//foreach($offsets as $offset) {
		$selector_matches = O::get_tagged($selector, $parent_node);
		$selector_matches_index = sizeof($selector_matches) - 1;
		//print('$selector_matches, $selector_matches_index in add: ');var_dump($selector_matches, $selector_matches_index);
		//foreach($selector_matches as $selector_match) {
		while($selector_matches_index > -1) {
			//print('here8567082<br>');
			//print('$index, O::get($index), $to_add in add: ');var_dump($index, O::get($index), $to_add);
			//$parent_node = O::set($offset, O::get($offset, $parent_node) + $to_add, $parent_node, $parent_node_only);
			$parent_node = O::set($selector_matches[$selector_matches_index][1], O::tagless($selector_matches[$selector_matches_index][0]) + $to_add, $parent_node, $parent_node_only);
			$selector_matches_index--;
		}
		//O::set($selector, O::get($selector, $parent_node) + $to_add, $parent_node);
	} elseif(is_array($selector)) { // recurse??
		O::fatal_error('array selector not handled in add function');
	} else {
		print('$selector: ');var_dump($selector);
		O::fatal_error('Unknown selector type in add');
	}
	//print('$parent_node at the end of add: ');var_dump($parent_node);
	return $parent_node;
}

function add_zero_ceiling($to_add, $selector, $parent_node = false, $parent_node_only = false) {
	if(is_array($to_add) && !is_array($selector)) { // swap them
		$temp_selector = $selector;
		$selector = $to_add;
		$to_add = $temp_selector;
	}
	if(!is_numeric($selector) && !is_numeric($to_add)) {
		$to_add = O::get($to_add, $parent_node);
	}
	if(is_numeric($selector) && !is_numeric($to_add)) {
		$temp_selector = $selector;
		$selector = $to_add;
		$to_add = $temp_selector;
	}
	if($to_add === false) {
		O::fatal_error('to_add false in add_zero_ceiling');
		$to_add = O::get($value_to_add_selector, $value_to_add_parent_node);
	}
	if(is_numeric($selector)) { // treat it as an offset
		$selector = (int)$selector;
		$new_value = O::get($selector, $parent_node) + $to_add;
		if($new_value > 0) {
			$new_value = 0;
		}
		$parent_node = O::set($selector, $new_value, $parent_node, $parent_node_only);
	} elseif(is_string($selector)) {
		/*$offsets = O::get_offsets($selector, $parent_node);
		$offset_index = sizeof($offsets) - 1;
		while($offset_index > -1) {
			//print('$index, O::get($index), $to_add in add_zero_ceiling: ');var_dump($index, O::get($index), $to_add);
			$new_value = O::get($offsets[$offset_index], $parent_node) + $to_add;
			if($new_value > 0) {
				$new_value = 0;
			}
			$parent_node = O::set($offsets[$offset_index], $new_value, $parent_node, $parent_node_only);
			$offset_index--;
		}*/
		$selector_matches = O::get_tagged($selector, $parent_node);
		$selector_matches_index = sizeof($selector_matches) - 1;
		while($selector_matches_index > -1) {
			$new_value = O::tagless($selector_matches[$selector_matches_index][0]) + $to_add;
			if($new_value > 0) {
				$new_value = 0;
			}
			$parent_node = O::set($selector_matches[$selector_matches_index][1], $new_value, $parent_node, $parent_node_only);
			$selector_matches_index--;
		}
		//O::set($selector, O::get($selector, $parent_node) + $to_add, $parent_node);
	} elseif(is_array($selector)) { // recurse??
		O::fatal_error('array selector not handled in add_zero_ceiling function');
	} else {
		print('$selector: ');var_dump($selector);
		O::fatal_error('Unknown selector type in add_zero_ceiling');
	}
	return $parent_node;
}

function subtract($to_subtract, $selector, $parent_node = false, $parent_node_only = false) {
	if(is_array($to_subtract) && !is_array($selector)) { // swap them
		$temp_selector = $selector;
		$selector = $to_subtract;
		$to_subtract = $temp_selector;
	}
	if(!is_numeric($selector) && !is_numeric($to_subtract)) {
		$to_subtract = O::get($to_subtract, $parent_node);
	}
	if(is_numeric($selector) && !is_numeric($to_subtract)) {
		$temp_selector = $selector;
		$selector = $to_subtract;
		$to_subtract = $temp_selector;
	}
	if($to_subtract === false) {
		O::fatal_error('to_subtract false in subtract');
		$to_subtract = O::get($value_to_subtract_selector, $value_to_subtract_parent_node);
	}
	if(is_numeric($selector)) { // treat it as an offset
		$selector = (int)$selector;
		$parent_node = O::set($selector, O::get($selector, $parent_node) - $to_subtract, $parent_node, $parent_node_only);
	} elseif(is_string($selector)) {
		/*$offsets = O::get_offsets($selector, $parent_node);
		$offset_index = sizeof($offsets) - 1;
		while($offset_index > -1) {
			//print('$index, O::get($index), $to_subtract in subtract: ');var_dump($index, O::get($index), $to_subtract);
			$parent_node = O::set($offsets[$offset_index], O::get($offsets[$offset_index], $parent_node) - $to_subtract, $parent_node, $parent_node_only);
			$offset_index--;
		}*/
		$selector_matches = O::get_tagged($selector, $parent_node);
		$selector_matches_index = sizeof($selector_matches) - 1;
		while($selector_matches_index > -1) {
			$parent_node = O::set($selector_matches[$selector_matches_index][1], O::tagless($selector_matches[$selector_matches_index][0]) - $to_subtract, $parent_node, $parent_node_only);
			$selector_matches_index--;
		}
		//O::set($selector, O::get($selector, $parent_node) - $to_subtract, $parent_node);
	} elseif(is_array($selector)) { // recurse??
		O::fatal_error('array selector not handled in subtract function');
	} else {
		print('$selector: ');var_dump($selector);
		O::fatal_error('Unknown selector type in subtract');
	}
	return $parent_node;
}

function subtract_zero_floor($to_subtract, $selector, $parent_node = false, $parent_node_only = false) {
	if(is_array($to_subtract) && !is_array($selector)) { // swap them
		$temp_selector = $selector;
		$selector = $to_subtract;
		$to_subtract = $temp_selector;
	}
	if(!is_numeric($selector) && !is_numeric($to_subtract)) {
		$to_subtract = O::get($to_subtract, $parent_node);
	}
	if(is_numeric($selector) && !is_numeric($to_subtract)) {
		$temp_selector = $selector;
		$selector = $to_subtract;
		$to_subtract = $temp_selector;
	}
	if($to_subtract === false) {
		O::fatal_error('to_subtract false in subtract_zero_floor');
		$to_subtract = O::get($value_to_add_selector, $value_to_add_parent_node);
	}
	//print('$to_subtract, $selector, $parent_node, $parent_node_only in subtract_zero_floor after sorting parameters: ');var_dump($to_subtract, $selector, $parent_node, $parent_node_only);
	if(is_numeric($selector)) { // treat it as an offset
		$selector = (int)$selector;
		$new_value = O::get($selector, $parent_node) - $to_subtract;
		if($new_value < 0) {
			$new_value = 0;
		}
		$parent_node = O::set($selector, $new_value, $parent_node, $parent_node_only);
	} elseif(is_string($selector)) {
		/*$offsets = O::get_offsets($selector, $parent_node);
		$offset_index = sizeof($offsets) - 1;
		while($offset_index > -1) {
			$new_value = O::get($offsets[$offset_index], $parent_node) - $to_subtract;
			if($new_value < 0) {
				$new_value = 0;
			}
			//print('$index, $new_value, $parent_node, $parent_node_only in subtract_zero_floor: ');var_dump($index, $new_value, $parent_node, $parent_node_only);
			$parent_node = O::set($offsets[$offset_index], $new_value, $parent_node, $parent_node_only);
			$offset_index--;
		}*/
		$selector_matches = O::get_tagged($selector, $parent_node);
		$selector_matches_index = sizeof($selector_matches) - 1;
		while($selector_matches_index > -1) {
			$new_value = O::tagless($selector_matches[$selector_matches_index][0]) - $to_subtract;
			if($new_value < 0) {
				$new_value = 0;
			}
			$parent_node = O::set($selector_matches[$selector_matches_index][1], $new_value, $parent_node, $parent_node_only);
			$selector_matches_index--;
		}
		//O::set($selector, O::get($selector, $parent_node) - $to_subtract, $parent_node);
	} elseif(is_array($selector)) { // recurse??
		O::fatal_error('array selector not handled in subtract_zero_floor function');
	} else {
		print('$selector: ');var_dump($selector);
		O::fatal_error('Unknown selector type in subtract_zero_floor');
	}
	return $parent_node;
}

function increment($selector, $parent_node = false, $parent_node_only = false) {
	if(is_numeric($selector)) { // treat it as an offset
		$selector = (int)$selector;
		$parent_node = O::set($selector, O::get($selector, $parent_node) + 1, $parent_node, $parent_node_only);
	} elseif(is_string($selector)) {
		/*$offsets = O::get_offsets($selector, $parent_node);
		$offset_index = sizeof($offsets) - 1;
		while($offset_index > -1) {
			//print('$index, O::get($index): ');var_dump($index, O::get($index));
			$parent_node = O::set($offsets[$offset_index], O::get($offsets[$offset_index], $parent_node) + 1, $parent_node, $parent_node_only);
			//print('$index, O::get($index), $this->context, $this->LOM after increment: ');O::var_dump_full($index, O::get($index), $this->context, $this->LOM);
			$offset_index--;
		}*/
		$parent_node = O::add(1, $selector, $parent_node, $parent_node_only);
	} elseif(is_array($selector)) { // recurse??
		O::fatal_error('array selector not handled in increment function');
	} else {
		print('$selector: ');var_dump($selector);
		O::fatal_error('Unknown selector type in increment');
	}
	return $parent_node;
}

function increment_zero_ceiling($selector, $parent_node = false, $parent_node_only = false) {
	if(is_numeric($selector)) { // treat it as an offset
		$selector = (int)$selector;
		$incremented = O::get($selector, $parent_node) + 1;
		if($incremented <= 0) {
			$parent_node = O::set($selector, $incremented, $parent_node, $parent_node_only);
		}
	} elseif(is_string($selector)) {
		/*$offsets = O::get_offsets($selector, $parent_node);
		$offset_index = sizeof($offsets) - 1;
		while($offset_index > -1) {
			$incremented = O::get($offsets[$offset_index], $parent_node) + 1;
			if($incremented <= 0) {
				$parent_node = O::set($offsets[$offset_index], $incremented, $parent_node, $parent_node_only);
			}
			$offset_index--;
		}*/
		$parent_node = O::add_zero_ceiling(1, $selector, $parent_node, $parent_node_only);
	} elseif(is_array($selector)) { // recurse??
		O::fatal_error('array selector not handled in increment_zero_ceiling function');
	} else {
		print('$selector: ');var_dump($selector);
		O::fatal_error('Unknown selector type in increment_zero_ceiling');
	}
	return $parent_node;
}

function decrement($selector, $parent_node = false, $parent_node_only = false) {
	if(is_numeric($selector)) { // treat it as an offset
		$selector = (int)$selector;
		$parent_node = O::set($selector, O::get($selector, $parent_node) - 1, $parent_node, $parent_node_only);
	} elseif(is_string($selector)) {
		/*$offsets = O::get_offsets($selector, $parent_node);
		$offset_index = sizeof($offsets) - 1;
		while($offset_index > -1) {
			$parent_node = O::set($offsets[$offset_index], O::get($offsets[$offset_index], $parent_node) - 1, $parent_node, $parent_node_only);
			$offset_index--;
		}*/
		$parent_node = O::subtract(1, $selector, $parent_node, $parent_node_only);
	} elseif(is_array($selector)) { // recurse??
		O::fatal_error('array selector not handled in decrement function');
	} else {
		print('$selector: ');var_dump($selector);
		O::fatal_error('Unknown selector type in decrement');
	}
	return $parent_node;
}

function decrement_zero_floor($selector, $parent_node = false, $parent_node_only = false) {
	if(is_numeric($selector)) { // treat it as an offset
		$selector = (int)$selector;
		$decremented = O::get($selector, $parent_node) - 1;
		if($decremented >= 0) {
			$parent_node = O::set($selector, $decremented, $parent_node, $parent_node_only);
		}
	} elseif(is_string($selector)) {
		/*$offsets = O::get_offsets($selector, $parent_node);
		$offset_index = sizeof($offsets) - 1;
		while($offset_index > -1) {
			$decremented = O::get($offsets[$offset_index], $parent_node) - 1;
			if($decremented >= 0) {
				$parent_node = O::set($offsets[$offset_index], $decremented, $parent_node, $parent_node_only);
			}
			$offset_index--;
		}*/
		$parent_node = O::subtract_zero_floor(1, $selector, $parent_node, $parent_node_only);
	} elseif(is_array($selector)) { // recurse??
		O::fatal_error('array selector not handled in decrement_zero_floor function');
	} else {
		print('$selector: ');var_dump($selector);
		O::fatal_error('Unknown selector type in decrement_zero_floor');
	}
	return $parent_node;
}

function operation($selector, $operation, $parent_node = false, $parent_node_only = false) {
	if(is_array($operation) && !is_array($selector)) { // swap them
		$temp_selector = $selector;
		$selector = $operation;
		$operation = $temp_selector;
	}
	if(!is_numeric($selector) && !is_numeric($operation)) {
		$operation = O::get($operation, $parent_node);
	}
	// probably exec the operation
	if(is_numeric($selector)) { // treat it as an offset
		$selector = (int)$selector;
		$parent_node = O::set($selector, exec('O::get($selector, $parent_node)' . $operation), $parent_node, $parent_node_only);
	} elseif(is_string($selector)) {
		/*$offsets = O::get_offsets($selector, $parent_node);
		$offset_index = sizeof($offsets) - 1;
		while($offset_index > -1) {
			$parent_node = O::set($offsets[$offset_index], exec('O::get($selector, $parent_node)' . $operation), $parent_node, $parent_node_only);
			$offset_index--;
		}*/
		$selector_matches = O::get_tagged($selector, $parent_node);
		$selector_matches_index = sizeof($selector_matches) - 1;
		while($selector_matches_index > -1) {
			$parent_node = O::set($selector_matches[$selector_matches_index][1], exec('O::tagless($selector_matches[$selector_matches_index][0]' . $operation), $parent_node, $parent_node_only);
			$selector_matches_index--;
		}
	} elseif(is_array($selector)) { // recurse??
		O::fatal_error('array selector not handled in operation function');
	} else {
		print('$selector: ');var_dump($selector);
		O::fatal_error('Unknown selector type in operation');
	}
	return $parent_node;
}

function multiply($selector, $to_multiply = false, $parent_node = false, $parent_node_only = false) {
	if(is_array($to_multiply) && !is_array($selector)) { // swap them
		$temp_selector = $selector;
		$selector = $to_multiply;
		$to_multiply = $temp_selector;
	}
	if(!is_numeric($selector) && !is_numeric($to_multiply)) {
		$to_multiply = O::get($to_multiply, $parent_node);
	}
	if(is_numeric($selector) && !is_numeric($to_multiply)) {
		$temp_selector = $selector;
		$selector = $to_multiply;
		$to_multiply = $temp_selector;
	}
	if($to_multiply === false) {
		O::fatal_error('to_multiply false in multiply');
		$to_multiply = O::get($value_to_add_selector, $value_to_add_parent_node);
	}
	if(is_numeric($selector)) { // treat it as an offset
		$selector = (int)$selector;
		$parent_node = O::set($selector, O::get($selector, $parent_node) * $to_multiply, $parent_node, $parent_node_only);
	} elseif(is_string($selector)) {
		/*$offsets = O::get_offsets($selector, $parent_node);
		$offset_index = sizeof($offsets) - 1;
		while($offset_index > -1) {
			$parent_node = O::set($offsets[$offset_index], O::get($offsets[$offset_index], $parent_node) * $to_multiply, $parent_node, $parent_node_only);
			$offset_index--;
		}*/
		$selector_matches = O::get_tagged($selector, $parent_node);
		$selector_matches_index = sizeof($selector_matches) - 1;
		while($selector_matches_index > -1) {
			$parent_node = O::set($selector_matches[$selector_matches_index][1], O::tagless($selector_matches[$selector_matches_index][0]) * $to_multiply, $parent_node, $parent_node_only);
			$selector_matches_index--;
		}
		//O::set($selector, O::get($selector, $parent_node) - $to_multiply, $parent_node);
	} elseif(is_array($selector)) { // recurse??
		O::fatal_error('array selector not handled in multiply function');
	} else {
		print('$selector: ');var_dump($selector);
		O::fatal_error('Unknown selector type in multiply');
	}
	return $parent_node;
}

function divide($selector, $to_divide = false, $parent_node = false, $parent_node_only = false) {
	if(is_array($to_divide) && !is_array($selector)) { // swap them
		$temp_selector = $selector;
		$selector = $to_divide;
		$to_divide = $temp_selector;
	}
	if(!is_numeric($selector) && !is_numeric($to_divide)) { // a bit odd in that the assumed order of parameters is out of order with the function definition but in order of the sentence "<function_name> <value_to_use> on <selector>"
		$to_divide = O::get($to_divide, $parent_node);
	}
	if(is_numeric($selector) && !is_numeric($to_divide)) {
		$temp_selector = $selector;
		$selector = $to_divide;
		$to_divide = $temp_selector;
	}
	//print('$selector, $to_divide, $parent_node: ');var_dump($selector, $to_divide, $parent_node);
	if($to_divide === false) {
		O::fatal_error('to_divide false in divide');
		$to_divide = O::get($value_to_add_selector, $value_to_add_parent_node);
	}
	if(is_numeric($selector)) { // treat it as an offset
		$selector = (int)$selector;
		$parent_node = O::set($selector, O::get($selector, $parent_node) / $to_divide, $parent_node, $parent_node_only);
	} elseif(is_string($selector)) {
		/*$offsets = O::get_offsets($selector, $parent_node);
		$offset_index = sizeof($offsets) - 1;
		while($offset_index > -1) {
			$parent_node = O::set($offsets[$offset_index], O::get($offsets[$offset_index], $parent_node) / $to_divide, $parent_node, $parent_node_only);
			$offset_index--;
		}*/
		$selector_matches = O::get_tagged($selector, $parent_node);
		$selector_matches_index = sizeof($selector_matches) - 1;
		while($selector_matches_index > -1) {
			$parent_node = O::set($selector_matches[$selector_matches_index][1], O::tagless($selector_matches[$selector_matches_index][0]) / $to_divide, $parent_node, $parent_node_only);
			$selector_matches_index--;
		}
		//O::set($selector, O::get($selector, $parent_node) - $to_divide, $parent_node);
	} elseif(is_array($selector)) { // recurse??
		O::fatal_error('array selector not handled in divide function');
	} else {
		print('$selector: ');var_dump($selector);
		O::fatal_error('Unknown selector type in divide');
	}
	return $parent_node;
}

function sum($selector, $parent_node = false) {
	//print('$selector, $parent_node in sum: ');var_dump($selector, $parent_node);
	//print('$this->context in sum: ');var_dump($this->context);
	$sum = 0;
	if(is_numeric($selector)) { // treat it as an offset
		$selector = (int)$selector;
		$matches = O::get_tagged($selector, $parent_node);
		//if(!is_array($matches)) {
		//	return (float)$matches;
		//}
	} elseif(is_string($selector)) {
		$matches = O::get_tagged($selector, $parent_node);
		//if(!is_array($matches)) {
		//	return (float)$matches;
		//}
	} elseif(is_array($selector)) { // recurse??
		if(O::all_sub_entries_are_arrays($selector)) {
			$matches = $selector;
		} else {
			$matches = array($selector);
		}
	} else {
		print('$selector: ');var_dump($selector);
		O::fatal_error('Unknown selector type in sum');
	}
	//print('$matches in sum: ');var_dump($matches);
	//if(O::all_sub_entries_are_arrays($matches)) {
		foreach($matches as $index => $value) {
			//foreach($match as $match2) {
			//	if($match2[0] === 0) {
			//		$sum += $match2[1];
			//	}
			//}
			$sum += O::tagless($value[0]);
		}
	//} else {
	//	foreach($matches as $match) {
	//		$sum += $match;
	//	}
	//}
	/*if(is_array($selector)) {
		foreach($selector as $first_index => $first_value) { break; }
		if(is_array($first_value)) {
			$values = O::get($selector, $parent_node);
		} else {
			$values = $selector;
		}
		foreach($values as $value) {
			$sum += $value;
		}
	}*/
	//print('$sum at the end of sum: ');var_dump($sum);
	return $sum;
}

function average($selector, $parent_node = false) {
	$sum = 0;
	if(is_numeric($selector)) { // treat it as an offset
		$selector = (int)$selector;
		$matches = O::get_tagged($selector, $parent_node);
		//if(!is_array($matches)) {
		//	return (float)$matches;
		//}
	} elseif(is_string($selector)) {
		$matches = O::get_tagged($selector, $parent_node);
		//if(!is_array($matches)) {
		//	return (float)$matches;
		//}
	} elseif(is_array($selector)) { // recurse??
		if(O::all_sub_entries_are_arrays($selector)) {
			$matches = $selector;
		} else {
			$matches = array($selector);
		}
	} else {
		print('$selector: ');var_dump($selector);
		O::fatal_error('Unknown selector type in average');
	}
	//if(O::all_sub_entries_are_arrays($matches)) {
		foreach($matches as $index => $value) {
			//foreach($match as $match2) {
			//	if($match2[0] === 0) {
			//		$sum += $match2[1];
			//	}
			//}
			$sum += O::tagless($value[0]);
		}
	//} else {
	//	foreach($matches as $match) {
	//		$sum += $match;
	//	}
	//}
	/*if(is_array($selector)) {
		foreach($selector as $first_index => $first_value) { break; }
		if(is_array($first_value)) {
			$values = O::get($selector, $parent_node);
		} else {
			$values = $selector;
		}
		foreach($values as $value) {
			$sum += $value;
		}
	}
	return $sum / sizeof($values);*/
	$average = $sum / sizeof($matches);
	return $average;
}

function change_tag_names_from_to($array, $from, $to) { // alias
	return O::change_tags_named_to($array, $from, $to);
}

function change_tag_names_to($array, $from, $to) { // alias
	return O::change_tags_named_to($array, $from, $to);
}

function change_tags_named_to($array, $from, $to) {
	if(is_array($from) && !is_array($to) && !is_array($selector)) { // swap them
		$temp_selector = $selector;
		$selector = $from;
		$from = $temp_selector;
	}
	if(is_array($to) && !is_array($from) && !is_array($selector)) { // swap them
		$temp_selector = $selector;
		$selector = $to;
		$to = $temp_selector;
	}
	/*if(O::all_sub_entries_are_arrays($array)) {
		foreach($array as $index => $value) {
			foreach($value as $index2 => $value2) {
				if($value2[0] === 1 && $value2[1][0] === $from) {
					$array[$index][$index2][1][0] = $to;
				}
			}
		}
	} else {
		foreach($array as $index => $value) {
			if($value[0] === 1 && $value[1][0] === $from) {
				$array[$index][1][0] = $to;
			}
		}
	}*/
	foreach($array as $index => $value) {
		$array[$index][0] = str_replace('<' . $from, '<' . $to, $array[$index][0]);
		$array[$index][0] = str_replace('</' . $from . '>', '</' . $to . '>', $array[$index][0]);
	}
	return $array;
}

function change_tagname($selector, $new_tag_name) { // alias
	return O::set_tag_name($selector, $new_tag_name);
}

function change_tag_name($selector, $new_tag_name) { // alias
	return O::set_tag_name($selector, $new_tag_name);
}

function set_tagname($selector, $new_tag_name) { // alias
	return O::set_tag_name($selector, $new_tag_name);
}

function set_tag_name($selector, $new_tag_name) {
	O::fatal_error('set_tag_name seems unused');
	if(is_array($new_tag_name) && !is_array($selector)) { // swap them
		$temp_selector = $selector;
		$selector = $new_tag_name;
		$new_tag_name = $temp_selector;
	}
	if(is_numeric($selector)) {
		$selector = (int)$selector;
		$offset = O::LOM_index_to_offset($selector) + 1;
		$old_tag_name = $this->LOM[$selector][1][0];
		$offset_adjust = strlen($new_tag_name) - strlen($old_tag_name);
		$this->code = O::replace($this->code, $old_tag_name, $new_tag_name, $offset);
		if($this->string_operation_made_a_change) {
			$this->LOM[$selector][1][0] = $new_tag_name;
			foreach($this->LOM as $LOM_index => $LOM_value) {
				if($LOM_value[2] >= $offset) {
					$this->LOM[$LOM_index][2] += $offset_adjust;
				}
			}
			if($this->use_context) {
				foreach($this->context as $context_index => $context_value) {
					if($context_value[1] !== false) {
						foreach($context_value[1] as $context1_index => $context1_value) {
							if($context1_value[1] + 1 === $offset) { // is this correct?
								$this->context[$context_index][1][$context1_index][0] = O::replace($this->context[$context_index][1][$context1_index][0], $old_tag_name, $new_tag_name, 1);
							} elseif($context1_value[1] >= $offset) {
								$this->context[$context_index][1][$context1_index][1] += $offset_adjust;
							}
						}
					}
					foreach($context_value[2] as $context2_index => $context2_value) {
						if($context2_value >= $offset) {
							$this->context[$context_index][2][$context2_index] += $offset_adjust;
						}
					}
					if(is_array($context_value[3])) {
						foreach($context_value[3] as $context3_index => $context3_value) {
							if($context3_value[1] === $offset) { // is this correct?
								$this->context[$context_index][3][$context3_index][0] = O::replace($this->context[$context_index][3][$context3_index][0], $old_tag_name, $new_tag_name, 1);
							} elseif($context3_value[1] >= $offset) {
								$this->context[$context_index][3][$context3_index][1] += $offset_adjust;
							}
						}
					}
				}
			}
		}
		return true;
	} elseif(is_string($selector) && $selector[0] === '<') {
		return O::preg_replace_first('/<(' . $this->tagname_regex . ')/is', '<' . $new_tag_name, $selector);
	} elseif(is_string($selector)) {
		$selector_matches = O::get_LOM_indices($selector, false, false, false, false);
		$counter = sizeof($selector_matches) - 1;
		while($counter > -1) { // go in reverse order
			if($this->LOM[$selector_matches[$counter]][0] === 0) { // text node
				O::set_tag_name($selector_matches[$counter] - 1, $new_tag_name);
			} else {
				O::set_tag_name($selector_matches[$counter], $new_tag_name);
			}
			$counter--;
		}
		/*$selector_matches = O::get($selector);
		foreach($selector_matches as $index => $value) {
			$did_first = false;
			$counter = 0;
			foreach($value as $index2 => $value2) {
				if(!did_first) {
					$this->LOM[$index2][1][0] = $new_tag_name;
					foreach($this->context as $context_index => $context_value) {
						if(is_array($context_value[3])) {
							if(O::all_sub_entries_are_arrays($context_value[3])) {
								foreach($context_value[3] as $index3 => $value3) {
									foreach($value3 as $index4 => $value4) {
										if($index2 === $index4) {
											$this->context[$context_index][3][$index3][$index2][1][0] = $new_tag_name;
											continue;
										}
									}
								}
							}
						}
					}
					$did_first = true;
				} elseif($counter === sizeof($value) - 1) {
					$this->LOM[$index2][1][0] = $new_tag_name;
					foreach($this->context as $context_index => $context_value) {
						if(is_array($context_value[3])) {
							if(O::all_sub_entries_are_arrays($context_value[3])) {
								foreach($context_value[3] as $index3 => $value3) {
									foreach($value3 as $index4 => $value4) {
										if($index2 === $index4) {
											$this->context[$context_index][3][$index3][$index2][1][0] = $new_tag_name;
											continue;
										}
									}
								}
							}
						}
					}
				}
				$counter++;
			}
		}*/
		return true;
	} elseif(is_array($selector)) {
		$counter = sizeof($selector) - 1;
		while($counter > -1) { // go in reverse order
			$LOM_index = O::opening_LOM_index_from_offset($selector[$counter][1]);
			$LOM_index = (int)$LOM_index;
			$initial_string = $selector[$counter][0];
			$selector[$counter][0] = O::replace($selector[$counter][0], $this->LOM[$LOM_index][1][0], $new_tag_name, 1);
			if($this->string_operation_made_a_change) {
				$offset = $selector[$counter][1] + 1;
				$offset_adjust = strlen($new_tag_name) - strlen($this->LOM[$LOM_index][1][0]);
				$this->code = O::replace($this->code, $this->LOM[$LOM_index][1][0], $new_tag_name, $offset);
				$this->LOM[$LOM_index][1][0] = $new_tag_name;
				foreach($this->LOM as $LOM_index => $LOM_value) {
					if($LOM_value[2] >= $offset) {
						$this->LOM[$LOM_index][2] += $offset_adjust;
					}
				}
				if($this->use_context) {
					foreach($this->context as $context_index => $context_value) {
						if($context_value[1] !== false) {
							foreach($context_value[1] as $context1_index => $context1_value) {
								if($context1_value[1] + 1 === $offset) {
									$this->context[$context_index][1][$context1_index][0] = O::replace($this->context[$context_index][1][$context1_index][0], $this->LOM[$LOM_index][1][0], $new_tag_name, 1);
								} elseif($context1_value[1] >= $offset) {
									$this->context[$context_index][1][$context1_index][1] += $offset_adjust;
								}
							}
						}
						foreach($context_value[2] as $context2_index => $context2_value) {
							if($context2_value >= $offset) {
								$this->context[$context_index][2][$context2_index] += $offset_adjust;
							}
						}
						if(is_array($context_value[3])) {
							foreach($context_value[3] as $context3_index => $context3_value) {
								if($context3_value[1] === $offset) {
									$this->context[$context_index][3][$context3_index][0] = O::replace($this->context[$context_index][3][$context3_index][0], $this->LOM[$LOM_index][1][0], $new_tag_name, 1);
								} elseif($context3_value[1] >= $offset) {
									$this->context[$context_index][3][$context3_index][1] += $offset_adjust;
								}
							}
						}
					}
				}
			}
			$counter--;
		}
		/*if(O::all_sub_entries_are_arrays($selector)) {
			foreach($selector as $index => $value) {
				$did_first = false;
				$counter = 0;
				foreach($value as $index2 => $value2) {
					if(!$did_first) {
						$this->LOM[$index2][1][0] = $new_tag_name;
						$selector[$index2][1][0] = $new_tag_name;
						foreach($this->context as $context_index => $context_value) {
							if(is_array($context_value[3])) {
								if(O::all_sub_entries_are_arrays($context_value[3])) {
									foreach($context_value[3] as $index3 => $value3) {
										foreach($value3 as $index4 => $value4) {
											if($index2 === $index4) {
												$this->context[$context_index][3][$index3][$index2][1][0] = $new_tag_name;
												continue;
											}
										}
									}
								}
							}
						}
						$did_first = true;
					} elseif($counter === sizeof($value) - 1) {
						$this->LOM[$index2][1][0] = $new_tag_name;
						$selector[$index2][1][0] = $new_tag_name;
						foreach($this->context as $context_index => $context_value) {
							if(is_array($context_value[3])) {
								if(O::all_sub_entries_are_arrays($context_value[3])) {
									foreach($context_value[3] as $index3 => $value3) {
										foreach($value3 as $index4 => $value4) {
											if($index2 === $index4) {
												$this->context[$context_index][3][$index3][$index2][1][0] = $new_tag_name;
												continue;
											}
										}
									}
								}
							}
						}
					}
				}
				$counter++;
			}
		} else {
			$did_first = false;
			//print('here927600<br>');
			$counter = 0;
			foreach($selector as $index => $value) {
				//print('here927601<br>');
				if(!$did_first) {
					//print('here927602<br>');
					$this->LOM[$index][1][0] = $new_tag_name;
					$selector[$index][1][0] = $new_tag_name;
					foreach($this->context as $context_index => $context_value) {
						if(is_array($context_value[3])) {
							if(O::all_sub_entries_are_arrays($context_value[3])) {
								foreach($context_value[3] as $index3 => $value3) {
									foreach($value3 as $index4 => $value4) {
										if($index2 === $index4) {
											$this->context[$context_index][3][$index3][$index2][1][0] = $new_tag_name;
											continue;
										}
									}
								}
							}
						}
					}
					$did_first = true;
				} elseif($counter === sizeof($selector) - 1) {
					//print('here927603<br>');
					$this->LOM[$index][1][0] = $new_tag_name;
					$selector[$index][1][0] = $new_tag_name;
					foreach($this->context as $context_index => $context_value) {
						if(is_array($context_value[3])) {
							if(O::all_sub_entries_are_arrays($context_value[3])) {
								foreach($context_value[3] as $index3 => $value3) {
									foreach($value3 as $index4 => $value4) {
										if($index2 === $index4) {
											$this->context[$context_index][3][$index3][$index2][1][0] = $new_tag_name;
											continue;
										}
									}
								}
							}
						}
					}
				}
				$counter++;
			}
		}*/
		return $selector;
	} else {
		print('$selector: ');var_dump($selector);
		O::fatal_error('unknown selector type in set_tag_name');
	}
}

function all_entries_are_arrays($array) {
	foreach($array as $index => $value) {
		if(!is_array($value)) {
			return false;
		}
	}
	return true;
}

function all_sub_entries_are_arrays($array) {
	//print('$array in all_sub_entries_are_arrays: ');var_dump($array);
	// analyze whether the provided array is a results array or LOM array (which have different formats)
	//$all_sub_entries_are_arrays = true;
	foreach($array as $index => $value) {
		if(!is_array($value)) {
			return false;
		}
		foreach($value as $index2 => $value2) {
			if(is_array($value2)) {
				
			} else {
				return false;
				break 2;
			}
		}
	}
	return true;
}

function get_tag_name($variable) {
	if(is_numeric($variable)) {
		$variable = (int)$variable;
		//print('$this->LOM[$variable]: ');var_dump($this->LOM[$variable]);exit(0);
		/*while($this->LOM[$variable][0] !== 1) { // tag
			$variable--;
			if($variable < 0) {
				return false;
			}
		}
		return $this->LOM[$variable][1][0];*/
		preg_match('/<(' . $this->tagname_regex . ')/is', substr($this->code, $variable), $matches);
		return $matches[1];
	} elseif(is_string($variable)) {
		//return substr($variable, strpos($variable, '<') + 1, strpos($variable, '>') - strpos($variable, '<') - 1);
		preg_match('/<(' . $this->tagname_regex . ')/is', $variable, $matches);
		return $matches[1];
	} elseif(is_array($variable)) {
		if(O::all_entries_are_arrays($variable)) {
			/*
			// assuming a DOM array (which would never be passed in)
			foreach($variable as $index => $value) {
				foreach($value as $first_index => $first_value) { break; }
				if(is_array($first_value)) {
					foreach($first_value as $first_index2 => $first_value2) { break; }
					if($first_value2 === 1) {
						return $first_value[1][0];
					}
				} elseif($first_value === 1) {
					return $value[1][0];
				}
			}*/
			$tagnames = array();
			foreach($variable as $entry) {
				$tagnames[] = O::get_tag_name($entry[0]);
			}
			return $tagnames;
		} else {
			return O::get_tag_name($variable[0]);
		}
	} else {
		print('$variable: ');var_dump($variable);
		O::fatal_error('unhandled variable type in get_tag_name');
	}
	return false;
}

function tag_name($variable) {
	return O::get_tag_name($variable);
}

function tagname($variable) {
	return O::get_tag_name($variable);
}

function reverse_get_object_string($string, $opening_string, $closing_string, $offset = 0) {
	$offset = $offset + strlen($closing_string) - 1;
	return O::get_object_string(strrev($string), strrev($closing_string), strrev($opening_string), strlen($string) - $offset - 1);
}

function get_object_string($string, $opening_string, $closing_string, $offset = 0) {
	// notice that this does not really handle the HTML short-hand of self-closing tags. (2011-08-10; see get_all_tags)
	//print('$string, $opening_string, $closing_string, $offset: ');var_dump($string, $opening_string, $closing_string, $offset);
	$first_opening_string_pos = strpos($string, $opening_string, $offset);
	if($first_opening_string_pos === false) {
		return false;
	}
	$offset = $first_opening_string_pos + strlen($opening_string);
	$object_string = $opening_string;
	$depth = 1;
	while($offset < strlen($string) && $depth > 0) {
		if(substr($string, $offset, strlen($opening_string)) === $opening_string) {
			$depth++;
			$object_string .= $opening_string;
			$offset += strlen($opening_string);
		} elseif(substr($string, $offset, strlen($closing_string)) === $closing_string) {
			$depth--;
			$object_string .= $closing_string;
			$offset += strlen($closing_string);
		} else {
			$object_string .= $string[$offset];
			$offset++;
		}
	}
	return $object_string;
}

function get_object_string_contents($string, $opening_string, $closing_string, $offset = 0) {
	// notice that this does not really handle the HTML short-hand of self-closing tags. (2011-08-10; see get_all_tags)
	//print('$string, $opening_string, $closing_string, $offset: ');var_dump($string, $opening_string, $closing_string, $offset);
	$first_opening_string_pos = strpos($string, $opening_string, $offset);
	if($first_opening_string_pos === false) {
		return false;
	}
	$offset = $first_opening_string_pos + strlen($opening_string);
	$object_string = '';
	$depth = 1;
	while($offset < strlen($string) && $depth > 0) {
		if(substr($string, $offset, strlen($opening_string)) === $opening_string) {
			$depth++;
			$object_string .= $opening_string;
			$offset += strlen($opening_string);
		} elseif(substr($string, $offset, strlen($closing_string)) === $closing_string) {
			$depth--;
			$object_string .= $closing_string;
			$offset += strlen($closing_string);
		} else {
			$object_string .= $string[$offset];
			$offset++;
		}
	}
	return substr($object_string, 0, strlen($object_string) - strlen($closing_string));
}

function get_tag($string, $tagName, $offset = 0) { // alias
	O::fatal_error('get_tag probably obsolete');
	return O::get_tag_string($string, $tagName, $offset);
}

//function get_tag_string($string, $offset, $offset_to_add = 0) {
function get_tag_string($offset, $offset_depths = false) {
	if($offset_depths === false) {
		$offset_depths = $this->offset_depths;
	}
	//O::fatal_error('get_tag_string probably obsolete');
	//if(strpos($string, $tagName) === false && strpos($tagName, $string) !== false) { // swap them
	//	$temp_string = $tagName;
	//	$string = $tagName;
	//	$tagName = $temp_string;
	//}
	//O::adjust_offset_depths($offset, $offset_adjust);
	$depth_to_match = O::depth($offset, $offset_depths);
	//print('$offset, $depth_to_match, $offset_depths in get_tag_string: ');O::var_dump_full($offset, $depth_to_match, $offset_depths);
	$pointer_got_to_offset = false;
	$offset_of_matched_depth = false;
	reset($offset_depths);
	//$depth = current($offset_depths);
	//print('$depth in get_tag_string: ');O::var_dump_full($depth);
	//print('before iterating over $offset_depths in get_tag_string<br>');
	$next_result = true;
	while($next_result !== false) {
		//print('key($offset_depths): ');var_dump(key($offset_depths));
		if($pointer_got_to_offset) {
			//print('pointer_got_to_offset<br>');
			if(current($offset_depths) === $depth_to_match) {
				//print('matched depth<br>');
				$offset_of_matched_depth = key($offset_depths);
				break;
			}
		} else {
			//print('not pointer_got_to_offset<br>');
			if(key($offset_depths) === $offset) {
				//print('setting pointer_got_to_offset<br>');
				$pointer_got_to_offset = true;
			}
		}
		$next_result = next($offset_depths);
	}
	if($offset_of_matched_depth === false) { // the whole code
		$offset_of_matched_depth = strlen($this->code);
	}
	//print('$offset, $offset_of_matched_depth in get_tag_string: ');O::var_dump_full($offset, $offset_of_matched_depth);
	$tag_string = substr($this->code, $offset, $offset_of_matched_depth - $offset);
	if($tag_string[strlen($tag_string) - 1] !== '>') {
		$tag_string = substr($tag_string, 0, O::strpos_last($tag_string, '>') + 1);
	}
	return $tag_string;
	/*$expanded_LOM = O::expand($string, $offset, $offset_to_add);
	$closing_tag_offset = $expanded_LOM[1][1] + strlen($expanded_LOM[1][0]) - $offset_to_add;
	//print('$expanded_LOM, $string, $offset, $offset_to_add, $closing_tag_offset in get_tag_string: ');var_dump($expanded_LOM, $string, $offset, $offset_to_add, $closing_tag_offset);
	//return $expanded_LOM[0][0] . $expanded_LOM[1][0] . substr($string, $after_closing_tag_offset, strpos($string, '>', $after_closing_tag_offset) + 1 - $after_closing_tag_offset);
	return $expanded_LOM[0][0] . $expanded_LOM[1][0] . substr($string, $closing_tag_offset, strpos($string, '>', $closing_tag_offset) + 1 - $closing_tag_offset);*/
	/*$initial_offset = $offset;
	$parsing_tag = false;
	while($offset < strlen($string)) {
		if($parsing_tag) {
			if(substr($string, $offset, 2) === '/>') { // it's self-closing
				return substr($string, $tag_start_offset, $offset - $tag_start_offset + 2);
			} elseif($string[$offset] === '>') { // it's not self-closing
				break;
			}
		} elseif(substr($string, $offset, strlen($tagName) + 1) === '<' . $tagName) {
			$parsing_tag = true;
			$tag_start_offset = $offset;
		}
		$offset++;
	}*/
	// first check for a self-closing tag
	/*preg_match('/<' . $tagName . '([^<>]*)\/>/s', $string, $matches, PREG_OFFSET_CAPTURE, $offset);
	$pos_first_open_tag = strpos($string, '<' . $tagName, $offset);
	$pos_self_closing = $matches[0][1];
	if($pos_self_closing === $pos_first_open_tag) { // then it is self-closing
		return $matches[0][0];
	} else {
		return O::get_object_string($string, '<' . $tagName, '</' . $tagName . '>', $offset);
	}
	return O::get_object_string($string, '<' . $tagName, '</' . $tagName . '>', $initial_offset);*/
}

function get_all_named_tags($string, $tagName, $offset = 0, $tagvalue = false, $matching_index = false, $required_attributes = array()) {
	if(strpos($string, $tagName) === false && strpos($tagName, $string) !== false) { // swap them
		$temp_string = $tagName;
		$string = $tagName;
		$tagName = $temp_string;
	}
	if(!is_string($string)) {
		O::fatal_error('!is_string($string): ' . $string . ' in get_all_named_tags.');
	}
	if(!is_string($tagName)) {
		O::fatal_error('!is_string($tagName): ' . $tagName . ' in get_all_named_tags.');
	}
	if(is_string($matching_index)) {
		$matching_index = (int)$matching_index;
	}
	//print('$string, $tagName, $offset, $tagvalue, $matching_index, $required_attributes in get_all_named_tags: ');var_dump($string, $tagName, $offset, $tagvalue, $matching_index, $required_attributes);
	//$string = substr($string, $offset); // bad
	$arrayOStrings = array();
	// it should be mentioned that "tags" such as CDATA regions will not be caught since we are only looking for words rather than non-spaces
	preg_match_all('/<' . $tagName . '[\s>]/is', $string, $matches, PREG_OFFSET_CAPTURE);
	foreach($matches[0] as $index => $value) {
		$match = $value[0];
		$offset2 = $value[1];
		$object_string = O::get_tag_string($string, $tagName, $offset2);
		if($object_string !== false) {
			if($tagvalue === false) {
				
			} else {
				if($tagvalue === O::tagvalue($object_string)) {
					
				} else {
					continue;
				}
			}
			if($matching_index !== false) {
				if($index === $matching_index) {
					
				} else {
					continue;
				}
			}
			if(sizeof($required_attributes) > 0) {
				$attributes = O::get_tag_attributes_of_string($object_string);
				//print('$object_string, $attributes: ');var_dump($object_string, $attributes);
				foreach($required_attributes as $required_attribute_name => $required_attribute_value) {
					//print('$attributes[$required_attribute_name], $required_attribute_value: ');var_dump($attributes[$required_attribute_name], $required_attribute_value);
					if($attributes[$required_attribute_name] === $required_attribute_value || ($required_attribute_value === false && isset($attributes[$required_attribute_name]))) {
						
					} else {
						continue 2;
					}
				}
			}
			$arrayOStrings[] = array($object_string, $offset2 + $offset);
		}
	}
	//print('$arrayOStrings in get_all_named_tags: ');var_dump($arrayOStrings);
	return $arrayOStrings;
}

function get_all_tags($string, $offset = 0, $tagvalue = false, $matching_index = false, $required_attributes = array()) {
	/*
	//$string = substr($string, $offset); // bad
	$arrayOStrings = array();
	// it should be mentioned that "tags" such as CDATA regions will not be caught since we are only looking for words rather than non-spaces
	preg_match_all('/<(' . $this->tagname_regex . ')[\s>]/is', $string, $matches, PREG_OFFSET_CAPTURE);
	foreach($matches[0] as $index => $value) {
		$match = $value[0];
		$offset2 = $value[1];
		$tagName = $matches[1][$index][0];
		$object_string = O::get_tag_string($string, $tagName, $offset2);
		if($object_string !== false) {
			$arrayOStrings[] = array($object_string, $offset2);
		}
	}
	return $arrayOStrings;*/
	if(is_string($matching_index)) {
		$matching_index = (int)$matching_index;
	}
	$LOM = O::LOM($string);
	$tags_in_LOM = O::get_tags_in_LOM($LOM);
	foreach($tags_in_LOM as $index => $value) {
		if($tagvalue === false) {
			
		} else {
			if($tagvalue === O::tagvalue($value[0])) {
				
			} else {
				unset($tags_in_LOM[$index]);
				continue;
			}
		}
		if($matching_index !== false) {
			if($index === $matching_index) {
				
			} else {
				unset($tags_in_LOM[$index]);
				continue;
			}
		}
		if(sizeof($required_attributes) > 0) {
			$attributes = O::get_tag_attributes_of_string($value[0]);
			foreach($required_attributes as $required_attribute_name => $required_attribute_value) {
				if($attributes[$required_attribute_name] === $required_attribute_value || ($required_attribute_value === false && isset($attributes[$required_attribute_name]))) {
					
				} else {
					unset($tags_in_LOM[$index]);
					continue 2;
				}
			}
		}
		$tags_in_LOM[$index][1] += $offset;
	}
	$tags_in_LOM = array_values($tags_in_LOM);
	//print('$tags_in_LOM: ');var_dump($tags_in_LOM);exit(0);
	return $tags_in_LOM;
}

function get_all_tags_at_this_level($string, $offset = 0, $tagvalue = false, $matching_index = false, $required_attributes = array()) {
	if(is_string($matching_index)) {
		$matching_index = (int)$matching_index;
	}
	$LOM = O::LOM($string);
	$tags_in_LOM = O::get_tags_in_LOM_at_this_level($LOM);
	foreach($tags_in_LOM as $index => $value) {
		if($tagvalue !== false) {
			if($tagvalue === O::tagvalue($value[0])) {
				
			} else {
				unset($tags_in_LOM[$index]);
				continue;
			}
		}
		if($matching_index !== false) {
			if($index === $matching_index) {
				
			} else {
				unset($tags_in_LOM[$index]);
				continue;
			}
		}
		if(sizeof($required_attributes) > 0) {
			$attributes = O::get_tag_attributes_of_string($value[0]);
			foreach($required_attributes as $required_attribute_name => $required_attribute_value) {
				if($attributes[$required_attribute_name] === $required_attribute_value || ($required_attribute_value === false && isset($attributes[$required_attribute_name]))) {
					
				} else {
					unset($tags_in_LOM[$index]);
					continue 2;
				}
			}
		}
		$tags_in_LOM[$index][1] += $offset;
	}
	$tags_in_LOM = array_values($tags_in_LOM);
	return $tags_in_LOM;
}

function get_all_named_tags_at_this_level($string, $tagname, $offset = 0, $tagvalue = false, $matching_index = false, $required_attributes = array()) {
	if(is_string($matching_index)) {
		$matching_index = (int)$matching_index;
	}
	$LOM = O::LOM($string);
	$tags_in_LOM = O::get_tags_in_LOM_at_this_level($LOM);
	foreach($tags_in_LOM as $index => $value) {
		if($tagname === O::tagname($value[0])) {
			
		} else {
			unset($tags_in_LOM[$index]);
			continue;
		}
		if($tagvalue !== false) {
			if($tagvalue === O::tagvalue($value[0])) {
				
			} else {
				unset($tags_in_LOM[$index]);
				continue;
			}
		}
		if($matching_index !== false) {
			if($index === $matching_index) {
				
			} else {
				unset($tags_in_LOM[$index]);
				continue;
			}
		}
		if(sizeof($required_attributes) > 0) {
			$attributes = O::get_tag_attributes_of_string($value[0]);
			foreach($required_attributes as $required_attribute_name => $required_attribute_value) {
				if($attributes[$required_attribute_name] === $required_attribute_value || ($required_attribute_value === false && isset($attributes[$required_attribute_name]))) {
					
				} else {
					unset($tags_in_LOM[$index]);
					continue 2;
				}
			}
		}
		$tags_in_LOM[$index][1] += $offset;
	}
	$tags_in_LOM = array_values($tags_in_LOM);
	return $tags_in_LOM;
}

function get_tags_in_LOM($LOM, $index = 0) { // beauty of a recursive function
	$tags = array();
	$depth = 0;
	//print('here3645756860<br>');
	while($index < sizeof($LOM)) {
		//print('here3645756861<br>');
		// what about programming instructions and such?
		if($LOM[$index][0] === 1 && $LOM[$index][1][2] === 0) { // opening tag
			//print('here3645756862<br>');
			if($depth === 0) {
				//$tags = array_merge(O::get_tags_in_LOM($LOM, $index + 1), $tags);
				//print('here3645756863<br>');
				//$saved_tagname = $LOM[$index][1][0];
				$saved_index = $index;
			}
			$depth++;
		} elseif($LOM[$index][0] === 1 && $LOM[$index][1][2] === 1) { // closing tag
			$depth--;
			//if($depth === 0 && $LOM[$index][1][0] === $saved_tagname) { // assumes even numbers of opening and closing tags and proper nesting
			if($depth === 0) {
				$tags = array_merge($tags, array(O::to_string_offset_pair(array_slice($LOM, $saved_index, $index - $saved_index + 1))));
				$tags = array_merge($tags, O::get_tags_in_LOM($LOM, $saved_index + 1));
			} elseif($depth === -1) { // assumes even numbers of opening and closing tags and proper nesting
				break;
			}
		} elseif($LOM[$index][0] === 1 && $LOM[$index][1][2] === 2) { // self-closing tag
			if($depth === 0) {
				$tags = array_merge($tags, array(O::to_string_offset_pair(array($LOM[$index]))));
			}
		}
		$index++;
	}
	//$tags = array_reverse($tags);
	return $tags;
}

function get_tags_in_LOM_at_this_level($LOM, $index = 0) {
	$tags = array();
	$depth = 0;
	while($index < sizeof($LOM)) {
		// what about programming instructions and such?
		if($LOM[$index][0] === 1 && $LOM[$index][1][2] === 0) { // opening tag
			if($depth === 0) {
				$saved_index = $index;
			}
			$depth++;
		} elseif($LOM[$index][0] === 1 && $LOM[$index][1][2] === 1) { // closing tag
			$depth--;
			if($depth === 0) {
				$tags = array_merge($tags, array(O::to_string_offset_pair(array_slice($LOM, $saved_index, $index - $saved_index + 1))));
			} elseif($depth === -1) { // assumes even numbers of opening and closing tags and proper nesting
				break;
			}
		} elseif($LOM[$index][0] === 1 && $LOM[$index][1][2] === 2) { // self-closing tag
			if($depth === 0) {
				$tags = array_merge($tags, array(O::to_string_offset_pair(array($LOM[$index]))));
			}
		}
		$index++;
	}
	return $tags;
}

function get_tag_LOM_array($index = 0) {
	$LOM_array = array();
	$depth = 0;
	while($index < sizeof($this->LOM)) {
		// what about programming instructions and such?
		if($this->LOM[$index][0] === 1 && $this->LOM[$index][1][2] === 0) { // opening tag
			$depth++;
		} elseif($this->LOM[$index][0] === 1 && $this->LOM[$index][1][2] === 1) { // closing tag
			$depth--;
			if($depth === 0) { // assumes even numbers of opening and closing tags and proper nesting
				break;
			}
		}
		$LOM_array[$index] = $this->LOM[$index];
		$index++;
	}
	$LOM_array[$index] = $this->LOM[$index];
	return $LOM_array;
}

function get_tag_attributes($string) { // alias
	return O::get_tag_attributes_of_string($string);
}

function get_tag_attributes_of_string($string) {
	//print('$string in get_tag_attributes_of_string: ');var_dump($string);
	$offset = 0;
	while($offset < strlen($string)) {
		if($string[$offset] === '<') {
			$first_tag = '';
			$offset++;
			while($offset < strlen($string)) {
				if($string[$offset] === '>') {
					//print('$first_tag in get_tag_attributes_of_string: ');var_dump($first_tag);
					preg_match_all('/(' . $this->attributename_regex . ')="([^"]*)"/is', $first_tag, $matches); // ignoring the possibility of single quotes
					//print('$matches in get_tag_attributes_of_string: ');var_dump($matches);
					$attributes = array();
					foreach($matches[0] as $index => $value) {
						$attributes[$matches[1][$index]] = $matches[2][$index];
					}
					return $attributes;
				}
				$first_tag .= $string[$offset];
				$offset++;
			}
		}
		$offset++;
	}
	return false;
}

//function depth($code = false, $offset = 0, $offset_to_add = 0) {
function depth($offset, $offset_depths = false) {
	if($offset_depths === false) {
		$offset_depths = $this->offset_depths;
	}
	//print('$offset, $offset_depths, $offset_depths[$offset] in depth(): ');var_dump($offset, $offset_depths, $offset_depths[$offset]);
	// this is a time consuming function worthy of optimization
	// maybe keep track of 0 depth points for given $code so that these 0 depth points can be skipped to?
	// I think probably go through $this->code once and mark all the depth points (at <) $this->offset_depths updated with new_() set() etc.
	//preg_match_all('/<[^<>]+>/', $code, $matches);
	/*preg_match_all('/<[^<>]+>/', substr($code, 0, $offset), $matches);
	//preg_match_all('/<[^<>]+>/', substr($code, 0, $offset), $matches, PREG_OFFSET_CAPTURE);
	//$zero_offset = 0;
	//if(sizeof($this->zero_offsets[$offset_to_add]) > 0) {
	//	foreach($this->zero_offsets[$offset_to_add] as $potential_zero_offset => $true) {
	//		if($potential_zero_offset > $zero_offset && $potential_zero_offset <= $offset) {
	//			$zero_offset = $potential_zero_offset;
	//		}
	//	}
	//}
	//preg_match_all('/<[^<>]+>/', substr($code, $zero_offset, $offset - $zero_offset), $matches, PREG_OFFSET_CAPTURE);
	$index = 0;
	$depth = 0;
	while($index < sizeof($matches[0])) {
		//if($matches[0][$index][0][1] === '/') { // closing tag
		if($matches[0][$index][1] === '/') { // closing tag
			$depth--;
			//if($depth < 0) { // shouldn't ever occur if expand() is working properly
			//	break;
			//}
			//if($depth === 0) {
			//	$this->zero_offsets[$offset_to_add][$matches[0][$index][1] + strlen($matches[0][$index][0])] = true;
			//}
		} else { // opening tag
			$depth++;
		}
		$index++;
	}
	return $depth;*/
	//print('$this->offset_depths in depth(): ');var_dump($this->offset_depths);
	//return $this->offset_depths[$offset];
	return $offset_depths[$offset];
}

function expand($code = false, $offset = 0, $offset_to_add = 0, $offset_depths = false, $mode = 'lazy') {
	// would it be better if this function always received an offset on the opening angle bracket < rather than looking for it? is this a reasonable expectation?
	if($offset_depths === false) {
		$offset_depths = $this->offset_depths;
	}
	//$depth_to_match = O::depth($offset + $offset_to_add + strpos($code, '<', $offset));
	//preg_match('/\s+/s', substr($code, $offset), $opening_whitespace_matches, PREG_OFFSET_CAPTURE);
	preg_match('/\s+/s', $code, $opening_whitespace_matches, PREG_OFFSET_CAPTURE, $offset);
	//print('$opening_whitespace_matches: ');var_dump($opening_whitespace_matches);
	$opening_whitespace_length = 0;
	if($opening_whitespace_matches[0][1] === $offset) {
		$opening_whitespace_length = strlen($opening_whitespace_matches[0][0]);
	}
	$new_LOM = array(array(substr($code, 0, $offset + $opening_whitespace_length), $offset_to_add));
	//$offset_of_opening_angle_bracket = strpos($code, '<', $offset);
		//   <bb>  <cc>  <dd>ee</dd>  </cc>  </bb>
		//             ^
	if($code[$offset + $opening_whitespace_length] === '<') { // opening angle bracket
		// aaaaa<bb><cc><dd>ee</dd></cc></bb>
		//          ^
		$depth_to_match = O::depth($offset_to_add + $offset + $opening_whitespace_length, $offset_depths);
		$parent_depth = $depth_to_match - 1;
		$matching_text = false;
	} else {
		// aaaaa<bb><cc><dd>ee</dd></cc></bb>
		//                  ^
		$depth_to_match = O::depth($offset_to_add + strpos($code, '<', $offset), $offset_depths);
		$parent_depth = $depth_to_match;
		$matching_text = true;
	}
	//print('$depth_to_match in expand: ');O::var_dump_full($depth_to_match);
	//print('$code, $offset, $offset_to_add, $opening_whitespace_length, $depth_to_match, $offset_depths in expand: ');O::var_dump_full($code, $offset, $offset_to_add, $opening_whitespace_length, $depth_to_match, $offset_depths);
	$pointer_got_to_offset = false;
	//$offset_of_last_depth_match = false;
	reset($offset_depths);
	$next_result = true;
	//print('here280<br>');
	/*while($next_result !== false) {
		//print('here281<br>');
		//print('current($offset_depths): ');var_dump(current($offset_depths));
		if($pointer_got_to_offset) {
			//print('pointer_got_to_offset<br>');
			//print('current($offset_depths): ');var_dump(current($offset_depths));
			if(current($offset_depths) < $depth_to_match) {
	//			//print('here282.5<br>');
	//			//$offset_of_matched_depth = key($this->offset_depths);
				break;
			}
			//print('here283<br>');
			if(current($offset_depths) === $depth_to_match) {
				//print('here284<br>');
				$offset_of_matched_depth = key($offset_depths);
	//			if($depth_to_match === 0) { // raw tags wihtout a container tag for the whole XML file
				if($mode === 'lazy') {
					break;
				}
	//			}
				//if($offset_of_opening_angle_bracket === $offset) {
				//	break;
				//}
			}
		} else {
			//print('here285<br>');
			//if(key($offset_depths) === $offset_of_opening_angle_bracket + $offset_to_add) {
			//if(key($offset_depths) === $offset + $opening_whitespace_length) {
			//if(key($offset_depths) >= $offset) {
			if(key($offset_depths) >= $offset_to_add + $offset) {
				//print('here286<br>');
				$pointer_got_to_offset = true;
	//			$offset_of_matched_depth = key($offset_depths);
			}
		}
		//print('here287<br>');
		$next_result = next($offset_depths);
	}*/
	
	if($mode === 'lazy') {
		while($next_result !== false) {
			if($pointer_got_to_offset) {
				if(current($offset_depths) === $depth_to_match) {
					$offset_of_matched_depth = key($offset_depths);
					break;
				}
			} else {
				if(key($offset_depths) >= $offset_to_add + $offset) {
					$pointer_got_to_offset = true;
				}
			}
			$next_result = next($offset_depths);
		}
	} else {
		while($next_result !== false) {
			if($pointer_got_to_offset) {
				if(current($offset_depths) < $depth_to_match) {
					break;
				}
				if(current($offset_depths) === $depth_to_match) {
					$offset_of_matched_depth = key($offset_depths);
				}
			} else {
				if(key($offset_depths) >= $offset_to_add + $offset) {
					$pointer_got_to_offset = true;
				}
			}
			$next_result = next($offset_depths);
		}
	}
	//print('here288<br>');
	/*if($next_result === false) { // the whole code
		$offset_of_matched_depth = key($offset_depths);
	} else*/if($offset_of_matched_depth === NULL) { // the whole code
		//print('here289<br>');
		//$offset_of_matched_depth = strlen($this->code);
		$offset_of_matched_depth = $offset_to_add + strlen($code);
		//$offset_of_matched_depth = strlen($code);
	}
	// probably rare to have whitespace at the end of a value in a tag but also probably we'll need to account for that
	//print('$offset, $opening_whitespace_length, $offset_of_matched_depth, $matching_text in expand: ');O::var_dump_full($offset, $opening_whitespace_length, $offset_of_matched_depth, $matching_text);
	//else {
		$contained_string = substr($code, $offset + $opening_whitespace_length, $offset_of_matched_depth - $offset_to_add - $offset - $opening_whitespace_length);
	//}
	//if($matching_text) {
	if($contained_string[0] !== '<') {
		$contained_string = substr($contained_string, 0, strpos($contained_string, '<'));
	}
	//$contained_string = substr($code, $offset + $opening_whitespace_length, $offset_of_matched_depth - $offset - $opening_whitespace_length - $offset_to_add);
	//if($contained_string[strlen($contained_string) - 1] === '<' && $offset_of_matched_depth > $offset_of_opening_angle_bracket) { // hacks upon hacks. what is this intended to fix?
	//	$contained_string = substr($contained_string, 0, strlen($contained_string) - 1);
	//}
	//print('$contained_string before strrev: ');var_dump($contained_string);
	$reversed_contained_string = strrev($contained_string);
	preg_match('/\s+/s', $reversed_contained_string, $closing_whitespace_matches, PREG_OFFSET_CAPTURE);
	if($closing_whitespace_matches[0][1] === 0) {
		$closing_whitespace_length = strlen($closing_whitespace_matches[0][0]);
		if($closing_whitespace_length > 0) {
			$contained_string = strrev(substr($reversed_contained_string, $closing_whitespace_length));
		}
	}
	//print('$contained_string: ');var_dump($contained_string);
	//$new_LOM[] = array($contained_string, $offset + strlen($contained_string) + $offset_to_add);
	$new_LOM[] = array($contained_string, $offset_to_add + $offset + $opening_whitespace_length);
	$new_LOM[] = $parent_depth; // parent depth for use in scope matching
	//print('$new_LOM: ');var_dump($new_LOM);
	return $new_LOM;
}

function string_to_LOM($code, $start_index = false, $offset_to_add = 0, $offset_of_code = false) { // alias
	return O::generate_LOM($code, $start_index, $offset_to_add, $offset_of_code);
}

function LOM($code, $start_index = false, $offset_to_add = 0, $offset_of_code = false) { // alias
	return O::generate_LOM($code, $start_index, $offset_to_add, $offset_of_code);
}

function generate_LOM($code, $start_index = false, $offset_to_add = 0, $offset_of_code = false) {
	return $this->code; // LOM is more of an abstract concept now hehe
	// documentation
	/*
	0 => node type: text or tag; 0 = text, 1 = tag
	1 => text string if node type is text, tag array if node type is tag
		0 => tag name
		1 => attributes array; an associative array
		2 => tag type; 0 = opening, 1 = closing, 2 = self-closing, 3 = DOCTYPE, 4 = CDATA, 5 = comment, 6 = programming instruction, 7 = ASP
		3 => block tag; true or false
	2 => offset
	*/
	// new documentation
	/*
	0 => text string
	1 => offset
	2 => node array
		0 => node type: text or tag; 0 = text, 1 = tag
		1 => tag array (if it's a tag)
			0 => tag name
			1 => attributes array; an associative array
			2 => tag type; 0 = opening, 1 = closing, 2 = self-closing, 3 = DOCTYPE, 4 = CDATA, 5 = comment, 6 = programming instruction, 7 = ASP
			3 => block tag; true or false
	*/
	
	//$code = str_replace('&#10;', ' ', $code); // line feed
	//$code = str_replace('&#13;', ' ', $code); // carriage return
	//$code = str_replace('&#xa;', ' ', $code); // line feed
	//$code = str_replace('&#xd;', ' ', $code); // carriage return
	//$code = str_replace('&#xA;', ' ', $code); // line feed
	//$code = str_replace('&#xD;', ' ', $code); // carriage return
	//0xC2 . 0xA0 multibyte non-breaking space?
	//O::convert_to('utf-8');
	//O::warning_once('we may not want to full on tidy code but at least a check whether there is the same number of opening and closing tags?');
	//$tag_types = array('opening' => 0, 'closing' => 0, 'self-closing' => 0);
	$code = (string)$code; // for when we are generating $LOM from an int ;p
	$LOM = array();
	$saved_offset = 0;
	$parsing_tag = false;
	$code_piece = '';
	if($start_index === false) {
		$index_counter = 0;
	} else {
		$index_counter = $start_index;
	}
	if($offset_of_code === false) {
		$offset_of_code = 0;
	}
	$offset = $offset_of_code;
	//print('$code, $start_index, $offset_to_add, $offset_of_code in generate_LOM: ');var_dump($code, $start_index, $offset_to_add, $offset_of_code);
	//print('5054<br>');exit(0);
	while($offset < strlen($code)) {
		//print('5055<br>');
		if($parsing_tag) {
			//print('5056<br>');
			if($code[$offset] === '<') {
				O::fatal_error('LOM alert: invalid syntax; <code>' . htmlentities($code_piece) . '</code> will be treated as text (unexpected &lt;).');
				$LOM[$index_counter] = array(0, $code_piece, $saved_offset + $offset_to_add);
				$index_counter++;
				$code_piece = '';
			} elseif($code[$offset] === '>') {
				$LOM[$index_counter] = array(1, $code_piece . '>', $saved_offset + $offset_to_add);
				$saved_offset = $offset + 1;
				$index_counter++;
				$code_piece = '';
				$parsing_tag = false;
			} else {
				$code_piece .= $code[$offset];
			}
		} else {
			//print('5057<br>');
			if($code[$offset] === '<') {
				$LOM[$index_counter] = array(0, $code_piece, $saved_offset + $offset_to_add);
				$saved_offset = $offset;
				$index_counter++;
				$offset++;
				if(substr($code, $offset, 8) === '![CDATA[') { // non-parsed character data
					//print('5058<br>');
					$offset += 8;
					$code_piece = '<![CDATA[';
					while($offset < strlen($code)) {
						//print('5059<br>');
						if(substr($code, $offset, 3) === ']]>') {
							//print('her3287394560845069<br>');
							$LOM[$index_counter] = array(1, array($code_piece . ']]>', false, 4, false), $saved_offset + $offset_to_add);
							/*$LOM[$index_counter] = array(0, array($code_piece . ']]>', false, 4, false));*/
							$index_counter++;
							$code_piece = '';
							$offset += 3;
							continue 2;
						} else {
							$code_piece .= $code[$offset];
						}
						$offset++;
					}
					//print('###########' . $code . '#################');
					O::fatal_error('Non-parsed character data was not properly terminated; <code>' . htmlentities($code_piece) . '</code>.');
				} elseif(substr($code, $offset, 3) === '!--') { // comment
					//print('5060<br>');
					//print(substr($code, $offset));
					$offset += 3;
					$code_piece = '<!--';
					while($offset < strlen($code)) {
						//print('5061<br>');
						if(substr($code, $offset, 3) === '-->') {
							//print('her3287394560845070<br>');
							//var_dump(array(1, array($code_piece, '', 5)));
							$LOM[$index_counter] = array(1, array($code_piece . '-->', false, 5, false), $saved_offset + $offset_to_add);
							/*$LOM[$index_counter] = array(0, array($code_piece . '-->', false, 5, false));*/
							$index_counter++;
							$code_piece = '';
							$offset += 3;
							continue 2;
						} else {
							$code_piece .= $code[$offset];
						}
						$offset++;
					}
					O::fatal_error('Comment was not properly terminated; <code>' . htmlentities($code_piece) . '</code>.');
				} elseif($code[$offset] === '?') { // programming instruction
					//print('5062<br>');
					$offset++;
					$code_piece = '<?';
					while($offset < strlen($code)) {
						//print('5063<br>');
						if(substr($code, $offset, 2) === '?>') {
							//print('her3287394560845071<br>');
							$LOM[$index_counter] = array(1, array($code_piece . '?>', false, 6, false), $saved_offset + $offset_to_add);
							/*$LOM[$index_counter] = array(0, array($code_piece . '?>', false, 6, false));*/
							$index_counter++;
							$code_piece = '';
							$offset += 2;
							continue 2;
						} else {
							$code_piece .= $code[$offset];
						}
						$offset++;
					}
					O::fatal_error('Programming instruction was not properly terminated; <code>' . htmlentities($code_piece) . '</code>.');
				} elseif($code[$offset] === '%') { // ASP
					//print('5064<br>');//exit(0);
					print('ASP...' . substr($code, $offset));
					$offset++;
					$code_piece = '<%';
					while($offset < strlen($code)) {
						//print('5065<br>');
						if(substr($code, $offset, 2) === '%>') {
							//print('her3287394560845072<br>');
							//var_dump(array(1, array($code_piece, '', 7)));
							$LOM[$index_counter] = array(1, array($code_piece . '%>', false, 7, false), $saved_offset + $offset_to_add);
							/*$LOM[$index_counter] = array(0, array($code_piece . '%>', false, 7, false));*/
							$index_counter++;
							$code_piece = '';
							$offset += 2;
							continue 2;
						} else {
							$code_piece .= $code[$offset];
						}
						$offset++;
					}
					O::fatal_error('ASP code was not properly terminated; <code>' . htmlentities($code_piece) . '</code>.');
				} else {
					//print('5066<br>');
					//var_dump($LOM);
					$code_piece = '<';
					$parsing_tag = true;
					continue;
				}
			} elseif($code[$offset] === '>') {
				//print('$code, $LOM, $index_counter, $offset, $saved_offset, $parsing_tag, $code_piece, $saved_offset, $offset_to_add: ');var_dump($code, $LOM, $index_counter, $offset, $saved_offset, $parsing_tag, $code_piece, $saved_offset, $offset_of_code);
				//O::fatal_error('LOM alert: invalid syntax; <code>' . htmlentities($code_piece) . '</code> will be treated as text (unexpected &gt;).');
				// since we are removing the first < to ensure we are looking in offspring for string-based querying we do get tag fragments like this while the code may not contain bad syntax; leave code validation to that function
				$LOM[$index_counter] = array(0, $code_piece . '>', $saved_offset + $offset_to_add);
				$index_counter++;
				$code_piece = '';
			} else {
				$code_piece .= $code[$offset];
			}
		}
		$offset++;
	}
	if(strlen($code_piece) > 0) {
		$LOM[$index_counter] = array(0, $code_piece, $saved_offset + $offset_to_add);	
	}
	//var_dump($LOM);exit(0);
	// this is where we could have a LOM without any changes to the code; although I don't know what purpose that would serve...
	
	//print('5067<br>');//exit(0);
	//print($code);
	//return;
	//print('$LOM mid generate: ');var_dump($LOM);
	foreach($LOM as $index => $value) {
		if($value[0] === 1) { // tag
			if($value[1][2] === 4) { // non-parsed character data
				continue;
			} elseif($value[1][2] === 5) { // comment
				continue;
			} elseif($value[1][2] === 6) { // programming instruction
				continue;
			} elseif($value[1][2] === 7) { // ASP
				continue;
			}
			$tag_array = array();
			$attributes_array = array();
			$tag_array[2] = 0; // default to an opening tag
			$offset = 1;
			$tag = $value[1];
			//var_dump($tag);
			$strlen_tag = strlen($tag);
			$parsed_tag_name = false;
			while($offset < $strlen_tag) {
				//print('here4068950697-80<br>');
				if($parsed_tag_name) {
					//print('here4068950697-81<br>');
					if($tag[$offset] === '>') {
						break;
					} elseif(substr($tag, $offset, 2) === '/>') {
						$tag_array[2] = 2;
						break;
					} else {
						preg_match('/\s*/is', $tag, $space_matches, PREG_OFFSET_CAPTURE, $offset);
						$space = $space_matches[0][0];
						$space_offset = $space_matches[0][1];
						$strlen_space = strlen($space);
						if($space_offset === $offset && $strlen_space > 0) {
							$offset += $strlen_space;
						}
						preg_match('/' . $this->tagname_regex . '/is', $tag, $attribute_name_matches, PREG_OFFSET_CAPTURE, $offset); // notice that by including ':' we are confounding namespaces
						// here would be where to make attribute names lowercase
						//$attribute_name = strtolower($attribute_name_matches[0][0]);
						$attribute_name = $attribute_name_matches[0][0];
						$strlen_attribute_name = strlen($attribute_name);
						if($strlen_attribute_name > 0) { // to guard against space at the ends of tags
							$offset += $strlen_attribute_name;
							//var_dump($tag[$offset]);
							if($tag[$offset] === '=') {
								$offset++;
								if($tag[$offset] === '"') {
									$offset++;
									preg_match('/[^"]*/is', $tag, $attribute_value_matches, PREG_OFFSET_CAPTURE, $offset);
									$attribute_value = $attribute_value_matches[0][0];
									$strlen_attribute_value = strlen($attribute_value);
									if(strlen(trim($attribute_value)) > 0) { // only keep it if it's non-empty
										//$new_tag .= $attribute_name . '="' . O::clean_attribute_value_according_to_attribute_name($attribute_value, $attribute_name) . '"';
										//$attributes_array[] = array($attribute_name, O::clean_attribute_value_according_to_attribute_name($attribute_value, $attribute_name));
										// we expect clean code; this is not tidy
										$attributes_array[$attribute_name] = O::clean_attribute_value_according_to_attribute_name($attribute_value, $attribute_name);
									}
									$offset += $strlen_attribute_value;
									$offset++;
								} elseif($tag[$offset] === "'") {
									$offset++;
									preg_match("/[^']*/is", $tag, $attribute_value_matches, PREG_OFFSET_CAPTURE, $offset);
									$attribute_value = $attribute_value_matches[0][0];
									$strlen_attribute_value = strlen($attribute_value);
									if(strlen(trim($attribute_value)) > 0) { // only keep it if it's non-empty
										//$new_tag .= $attribute_name . '="' . O::clean_attribute_value_according_to_attribute_name($attribute_value, $attribute_name) . '"';
										//$attributes_array[] = array($attribute_name, O::clean_attribute_value_according_to_attribute_name($attribute_value, $attribute_name));
										// we expect clean code; this is not tidy
										$attributes_array[$attribute_name] = O::clean_attribute_value_according_to_attribute_name($attribute_value, $attribute_name);
									}
									$offset += $strlen_attribute_value;
									$offset++;
								} else { // undelimited attribute value
									preg_match("/[^\s<>]*/is", $tag, $attribute_value_matches, PREG_OFFSET_CAPTURE, $offset);
									$attribute_value = $attribute_value_matches[0][0];
									$strlen_attribute_value = strlen($attribute_value);
									if($strlen_attribute_value > 0) { // only keep it if it's non-empty
										//$new_tag .= $attribute_name . '="' . O::clean_attribute_value_according_to_attribute_name($attribute_value, $attribute_name) . '"';
										//$attributes_array[] = array($attribute_name, O::clean_attribute_value_according_to_attribute_name($attribute_value, $attribute_name));
										// we expect clean code; this is not tidy
										$attributes_array[$attribute_name] = O::clean_attribute_value_according_to_attribute_name($attribute_value, $attribute_name);
									}
									$offset += $strlen_attribute_value;
								}
							} else { // attribute with no attribute value
								if($attribute_name === 'nowrap') {
									//$new_tag .= 'nowrap="nowrap"';
									//$attributes_array[] = array('nowrap', 'nowrap');
									// we expect clean code; this is not tidy
									$attributes_array['nowrap'] = 'nowrap';
								} else {
									//$attributes_array[] = array($attribute_name, '');
									O::fatal_error('found attribute with no attribute value: ' . $attribute_name . ' in tag ' . $tag . ' but how to handle it is not specified 2897497592');
								}
							}
						}
						//preg_match('/\s*/is', $tag, $space_after_attribute_matches, PREG_OFFSET_CAPTURE, $offset);
						//$space_after_attribute = $space_after_attribute_matches[0][0];
						//$strlen_space_after_attribute = strlen($space_after_attribute);
						//$offset += $strlen_space_after_attribute;
						//if($strlen_space_after_attribute > 0) {
						//	$new_tag .= ' ';
						//}
						continue;
					}
				} else {
					//print('here4068950697-82<br>');
					$parsed_tag_name = true;
					/*
					'!doctype' => 'parse_doctype',
					'?' => 'parse_php',
					'?php' => 'parse_php',
					'%' => 'parse_asp',
					'style' => 'parse_style',
					'script' => 'parse_script'
					*/
					
					// concerning these partial (very limited) parsings of special stuff like programming instructions and scripts from different language than HTML; it shouldn't be a problem as long as they do not 
					// contain reflexive code (for example a PHP string like ...... '[question-mark]>' (I can't actually write it because notepad++'s parser busts into comments to find ends of programming instructions apparently...)
					if(substr($tag, $offset, 8) === '!doctype' || substr($tag, $offset, 8) === '!DOCTYPE') {
						//print('here4068950697-83<br>');
						// could handle doctype; for now just keep it
						$tag_array = array($tag, '', 3);
						break;
					}/* elseif(substr($tag, $offset, 3) === '!--') { // comment
						// could handle comments; for now just keep them
						$tag_array = array($tag, '', 4);
						break;
					} elseif($tag[$offset] === '?') { // programming instruction
						// could handle programming instructions; for now just keep them
						$tag_array = array($tag, '', 5);
						break;
					} elseif($tag[$offset] === '%') { // ASP
						// could handle ASP; for now just keep it
						$tag_array = array($tag, '', 6);
						break;
					}*/ elseif($tag[$offset] === '/') { // end tag
						$offset++;
						$tag_array[2] = 1;
					}
					preg_match('/' . $this->tagname_regex . '/is', $tag, $tag_name_matches, PREG_OFFSET_CAPTURE, $offset); // should namespace be separated from tagname?
					// here would be where to make tag names lowercase
					//$tag_name = strtolower($tag_name_matches[0][0]);
					$tag_name = $tag_name_matches[0][0];
					$strlen_tag_name = strlen($tag_name);
					$tag_array[0] = $tag_name;
					if(O::is_block($tag_name)) {
						// mark it as block for future reference
						//print('marked a tag as a block<br>');
						$tag_array[3] = true;
					}
					//$tag_name_offset = $tag_name_matches[0][1];
					//$new_tag .= $tag_name;
					/*if($strlen_tag_name == 0 || (!$parsing_end_tag && $tag_name_offset !== 1) || ($parsing_end_tag && $tag_name_offset !== 2)) {
						var_dump($strlen_tag_name, $parsing_end_tag, $tag_name_offset, $tag_name_offset);
						print('tag_name: ' . $tag_name . ' was problematically identified from tag: ' . $tag . ' 2897497591');exit(0);
					} else {*/
						$offset += $strlen_tag_name;
						continue;
					//}
					//print('here4068950697-84<br>');
				}
				$offset++;
			}
			// I suppose we could sort attributes as desired here
			//print('here4068950697-85<br>');
			$tag_array[1] = $attributes_array;
		//	if($tag_array[2] === 0) {
		//		$tag_types['opening']++;
		//	} elseif($tag_array[2] === 1) {
		//		$tag_types['closing']++;
		//	} elseif($tag_array[2] === 2) {
		//		$tag_types['self-closing']++;
		//	}
			//$LOM[$index] = array(1, $tag_array);
			$LOM[$index][1] = $tag_array;
		}
	}
	//if($tag_types['opening'] !== $tag_types['closing']) {
	//	print('$LOM: ');O::var_dump_full($LOM);
	//	print('$tag_types: ');var_dump($tag_types);
	//	O::fatal_error('different numbers of opening and closing tags');
	//}
	//print('$LOM at bottom of generate_LOM: ');O::var_dump_full($LOM);exit(0);
	return $LOM;
}

function sv($name, $data) { // alias
	return O::set_variable($name, $data);
}

function s_v($name, $data) { // alias
	return O::set_variable($name, $data);
}

function __v($name, $data) { // alias
	return O::set_variable($name, $data);
}

function __variable($name, $data) { // alias
	return O::set_variable($name, $data);
}

function set_variable($name, $data) {
	$this->variables[$name] = $data;
	return true;
}

function cv($name) { // alias
	return O::clear_variable($name);
}

function c_v($name) { // alias
	return O::clear_variable($name);
}

function clear_variable($name) {
	unset($this->variables[$name]);
	return true;
}

function v($name) { // alias
	return O::get_variable($name);
}

function _v($name) { // alias
	return O::get_variable($name);
}

function variable($name) { // alias
	return O::get_variable($name);
}

function get_variable($name) {
	//fatal_error('we could keep variables alive but performance would diminish as the number of living variables increased, of course this performance hit could be offset by not having to requery multiple times and instead having the answer already available; this hasn\'t been benchmarked');
	return $this->variables[$name];
}

function validate() {
	// only simplistically checks syntax
	$tag_types = array('opening' => 0, 'closing' => 0, 'self-closing' => 0);
	foreach($this->LOM as $index => $value) {
		if($value[0] === 1) { // tag
			if($value[1][2] === 0) {
				$tag_types['opening']++;
			} elseif($value[1][2] === 1) {
				$tag_types['closing']++;
			} elseif($value[1][2] === 2) {
				$tag_types['self-closing']++;
			}
		}
	}
	if($tag_types['opening'] !== $tag_types['closing']) {
		print('$this->LOM: ');O::var_dump_full($this->LOM);
		print('$this->LOM string: ');O::var_dump_full(O::tagstring($this->LOM));
		print('$tag_types: ');var_dump($tag_types);
		O::fatal_error('different numbers of opening and closing tags');
	}
}

function save($filename = false, $parent_node = false) { // alias
	return O::save_LOM_to_file($filename, $parent_node);
}

function save_LOM($filename = false, $parent_node = false) { // alias
	return O::save_LOM_to_file($filename, $parent_node);
}

function save_LOM_to_file($filename = false, $parent_node = false) {
	if($filename === false) {
		$filename = $this->file;
	}
	if($parent_node === false) {
		$code = O::generate_code_from_LOM($this->LOM);
	} else {
		//$code = O::generate_code_from_LOM(O::get($parent_node));
		$code = O::generate_code_from_LOM($parent_node);
	}
	//print('$filename, $code in save_LOM_to_file: ');var_dump($filename, $code);
	file_put_contents($filename, $code);
}

/*function to_string_offset_pair($array) {
	foreach($array as $first_index => $first_value) { break; }
	return array(O::generate_code_from_LOM_array($array), $array[$first_index][2]);
}
*/
function tagstring($array) { // alias
	return O::generate_code_from_LOM_array($array);
}

function tag_string($array) { // alias
	return O::generate_code_from_LOM_array($array);
}

function tostring($array) { // alias
	return O::generate_code_from_LOM_array($array);
}

function to_string($array) { // alias
	return O::generate_code_from_LOM_array($array);
}

function node_string($array) { // alias
	return O::generate_code_from_LOM_array($array);
}

function node_to_string($array) { // alias
	return O::generate_code_from_LOM_array($array);
}

function LOM_to_string($array) { // alias
	return O::generate_code_from_LOM_array($array);
}

function string_from_LOM($array) { // alias
	return O::generate_code_from_LOM_array($array);
}

function LOM_array_to_string($array) { // alias
	return O::generate_code_from_LOM_array($array);
}

function code_from_LOM($array) { // alias
	return O::generate_code_from_LOM_array($array);
}

function generate_code_from_LOM($array) { // alias
	return O::generate_code_from_LOM_array($array);
}

function generate_code_from_LOM_array($array) {
	return $this->code; // LOM is more of an abstract concept now hehe
	if($array === false) {
		return '';
	}/* else { // have to be able to take a LOM_array or array of LOM_arrays
		$counter = 0;
		if(sizeof($array) === 1) {
			$its_an_array_of_LOM_arrays = false;
			foreach($array as $index => $value) {
				$counter = 0;
				$proper_LOM_array_format_counter = 0;
				foreach($value as $index2 => $value2) {
					if($value2[0] === 0 || $value2[0] === 1) {
						$proper_LOM_array_format_counter++;
					}
					$counter++;
					if($counter === 2) {
						break;
					}
				}
				if($counter === $proper_LOM_array_format_counter) {
					$its_an_array_of_LOM_arrays = true;
					break;
				}
			}
			if($its_an_array_of_LOM_arrays) {
				$array = $array[$index];
			}
		}
	}*/
	//print('$array (3): ');var_dump($array);
	if(!isset($this->config['indentation_string'])) {
		//$this->config['indentation_string'] = '	'; // tab; I don't like it :)
		//$this->config['indentation_string'] = '';
		$this->config['indentation_string'] = '
';
	}
	$code = '';
	//print('$array before one_dimensional_LOM_array_to_code: ');var_dump($array);
	if(O::all_sub_entries_are_arrays($array)) {
		foreach($array as $index => $value) {
			$code .= O::one_dimensional_LOM_array_to_code($value) . '
';
		}
	} elseif(!O::all_entries_are_arrays($array)) {
		$code .= O::one_dimensional_LOM_array_to_code(array($array));
	} else {
		$code .= O::one_dimensional_LOM_array_to_code($array);
	}
	return $code;
}

private function one_dimensional_LOM_array_to_code($array) {
	//print('$array in one_dimensional_LOM_array_to_code: ');var_dump($array);
	$code = '';
	$block_depth = 0;
	foreach($array as $index => $value) {
		if($value[0] === 0) { // text
			$text = $value[1];
			// intelligently trim unnecessary space on text
			preg_match('/[\s]+/is', $text, $space_matches, PREG_OFFSET_CAPTURE);
			if($space_matches[0][1] === 0 && $array[$index - 1][1][3]) {
				//var_dump($space_matches);exit(0);
				$text = substr($text, strlen($space_matches[0][0]));
			}
			$rev_text = strrev($text);
			preg_match('/[\s]+/is', $rev_text, $space_matches, PREG_OFFSET_CAPTURE);
			if($space_matches[0][1] === 0 && $array[$index + 1][1][3]) {
				//var_dump($space_matches);exit(0);
				$text = substr($text, 0, strlen($text) - strlen($space_matches[0][0]));
			}
			$code .= $text;
		} elseif($value[0] === 1) { // tag
			$tag_array = $value[1];
			//var_dump($tag_array);
			$tag_name = $tag_array[0];
			$attributes_array = $tag_array[1];
			$tag_type = $tag_array[2];
			//var_dump($tag_type);
			if($tag_type > 2) {
				$tag = $tag_name;
			} elseif($tag_type === 0 || $tag_type === 2) {
				$tag = '<' . $tag_name;
			} elseif($tag_type === 1) {
				$tag = '</' . $tag_name;
			} else {
				print($tag_type);var_dump($tag_type);
				print('$value: ');var_dump($value);
				print('generate_code_from_LOM_array thinks this is a tag that is neither opening, closing or self-closing...?');exit(0);
			}
			if($attributes_array !== false) {
				//foreach($attributes_array as $attribute_index => $attribute_array) {
				// if we expected dirty code where attribute names could be duplicated then this would make sense, but we expect clean code
				foreach($attributes_array as $attribute_name => $attribute_value) {
					// here is where we could put attributes on their own lines, if desired
					//$tag .= ' ' . $attribute_array[0] . '="' . $attribute_array[1] . '"';
					$tag .= ' ' . $attribute_name . '="' . $attribute_value . '"';
				}
			}
			if($tag_type > 2) {
				
			} elseif($tag_type === 0 || $tag_type === 1) {
				$tag .= '>';
			} elseif($tag_type === 2) {
				$tag .= ' />';
			} else {
				print($tag_type);var_dump($tag_type);
				print('$value: ');var_dump($value);
				print('generate_code_from_LOM_array thinks this is a tag that is neither opening, closing or self-closing(2)...?');exit(0);
			}
			$indentation_string = '';
			if($tag_array[3]) { // is block
				//print('found a tag marked as a block<br>');
				if($tag_type === 1) {
					$block_depth--;
				}
				$indentation_counter = $block_depth;
				while($indentation_counter > 0) {
					$indentation_string .= $this->config['indentation_string'];
					$indentation_counter--;
				}
				if($tag_type === 0) {
					$block_depth++;
				}
			}
			if(($tag_type >= 2 || $tag_type === 0) && $tag_array[3]) {
				$code .= '
' . $indentation_string . $tag;
			} elseif($tag_type === 1) {
				$found_previous_tag = false;
				$counter = $index - 1;
				while($counter > -1 && !$found_previous_tag) {
					if($array[$counter][0] === 1) { // tag
						$found_previous_tag = true;
					} else {
						$counter--;
					}
				}
				if($found_previous_tag && $array[$counter][1][3] && ($array[$counter][1][2] === 1 || $array[$counter][1][2] === 2)) { // previous tag is a closing block
					$code .= '
' . $indentation_string . $tag;
				} else {
					$code .= $tag;
				}
			} else {
				$code .= $tag;
			}
			//var_dump($tag);
		} else {
			var_dump($value);
			O::fatal_error('one_dimensional_LOM_array_to_code thinks there is content that is neither text or a tag in this code...?');
		}
	}
	return $code;
}

function is_self_closing($tag_name) {
	if(!isset($this->array_self_closing)) {
		O::set_arrays_of_tag_types();
	}
	foreach($this->array_self_closing as $index => $self_closing) {
		if($self_closing === $tag_name) {
			return true;
		}
	}
	return false;
}

function is_block($tag_name) {
	if(!isset($this->array_blocks)) {
		O::set_arrays_of_tag_types();
	}
	foreach($this->array_blocks as $index => $block) {
		if($block === $tag_name) {
			return true;
		}
	}
	return false;
}

function set_blocks($array_block_tags) { // alias
	return O::set_block_tags($array_block_tags);
}

function set_block_tags($array_block_tags) {
	$this->array_blocks = $array_block_tags;
	return true;
}

function set_inlines($array_inline_tags) { // alias
	return O::set_inline_tags($array_inline_tags);
}

function set_inline_tags($array_inline_tags) {
	$this->array_inlines = $array_inline_tags;
	return true;
}

function tag_name_from_tag_string($tag_string) {
	preg_match('/<\/?(\w+)/is', $tag_string, $tag_name_matches);
	return $tag_name_matches[1];
}

function clean_attribute_value_according_to_attribute_name($attribute_value, $attribute_name) {
	if($attribute_name === 'class') {
		$attribute_value = preg_replace('/\s+/is', ' ', $attribute_value);
		$attribute_value = trim($attribute_value);
	} elseif($attribute_name === 'style') {
		$attribute_value = O::cleanStyle_for_LOM($attribute_value);
	}
	return $attribute_value;
}

function cleanStyle_for_LOM($string) {
	$string = str_replace('"', '&quot;', $string);
	return O::cleanStyleInformation($string);
}

function cleanStyle($string) {
	return O::cleanStyleInformation($string);
}

function cleanStyleInformation($string) {
	// HTML character entities cause problems because of their ampersands.
	$string = str_replace('&nbsp;', ' ', $string);
	$string = str_replace('&quot;', "'", $string);
	$string = O::decode_for_DOM_character_entities($string);
	/* // 2011-11-28
	preg_match_all('/&[\w#x0-9]+;/is', $string, $character_entity_matches);
	foreach($character_entity_matches[0] as $character_entity_match) {
		//$decoded = html_entity_decode($character_entity_match);
		if(strpos($decoded, ";") === false) {
			$string = str_replace($character_entity_match, $decoded, $string);
		} else { // then we still have a problem
			print("did not properly decode HTML character entity in style attribute4892589435: <br>\r\n");var_dump($decoded);print("<br>\r\n");var_dump($string);print("<br>\r\n");exit(0);
		}
	}*/
	$string = preg_replace('/\/\*.*\*\//s', '', $string);
	// the above could already be taken care of
	$string = preg_replace('/\s*;\s*/s', '; ', $string);
	$string = preg_replace('/\s*:\s*/s', ': ', $string);
	// pseudo-elements...
	$string = preg_replace('/\s*:\s*(\w*)\s*\{([^\{\}]*)\}/s', ' :$1 {$2};', $string);
	// we would probably like to force a format on things like media rules here also
	$string = preg_replace('/\r\n/', ' ', $string);
	$string = preg_replace('/\s+/', ' ', $string);
	$string = trim($string);
	$string = O::delete_empty_styles($string);
	$string = O::ensureStyleInformationBeginsProperly($string);
	$string = O::ensureStyleInformationEndsProperly($string);
	return $string;
}

function cleanSelector($string) {
	$string = preg_replace('/\/\*.*\*\//s', '', $string);
	// the above could already be taken care of
	$string = preg_replace('/\r\n/', ' ', $string);
	$string = preg_replace('/\s+/', ' ', $string);
	$string = trim($string);
	return $string;
}

function query_encode($string) {
	if(!is_string($string)) {
		return $string;
	}
	$string = str_replace(' ', '<space>', $string);
	$string = str_replace('_', '<underscore>', $string);
	$string = str_replace('@', '<at>', $string);
	$string = str_replace('=', '<equals>', $string);
	$string = str_replace('&', '<ampersand>', $string);
	$string = str_replace('[', '<leftsquarebracket>', $string);
	$string = str_replace(']', '<rightsquarebracket>', $string);
	$string = str_replace('.', '<dot>', $string);
	$string = str_replace('*', '<asterisk>', $string);
	$string = str_replace('|', '<bar>', $string);
	return $string;
}

function query_decode($string) {
	if(!is_string($string)) {
		return $string;
	}
	$string = str_replace('<space>', ' ', $string);
	$string = str_replace('<underscore>', '_', $string);
	$string = str_replace('<at>', '@', $string);
	$string = str_replace('<equals>', '=', $string);
	$string = str_replace('<ampersand>', '&', $string);
	$string = str_replace('<leftsquarebracket>', '[', $string);
	$string = str_replace('<rightsquarebracket>', ']', $string);
	$string = str_replace('<dot>', '.', $string);
	$string = str_replace('<asterisk>', '*', $string);
	$string = str_replace('<bar>', '|', $string);
	return $string;
}

function enc($string) { // alias
	return O::query_encode($string);
}

function dec($string) { // alias
	return O::query_decode($string);;
}

function fatal_error($message) { 
	print('<span style="color: red;">' . $message . '</span>');exit(0);
}

function fatal_error_once($string) {
	if(!isset($this->printed_strings[$string])) {
		print('<span style="color: red;">' . $string . '</span>');exit(0);
		$this->printed_strings[$string] = true;
	}
	return true;
}

function warning($message) { 
	print('<span style="color: orange;">' . $message . '</span><br>');
}

function warning_if($string, $count) {
	if($count > 1) {
		O::warning($string);
	}
}

function warning_once($string) {
	if(!isset($this->printed_strings[$string])) {
		print('<span style="color: orange;">' . $string . '</span><br>');
		$this->printed_strings[$string] = true;
	}
	return true;
}

function good_message($message) { 
	print('<span style="color: green;">' . $message . '</span><br>');
}

function good_message_once($string) {
	if(!isset($this->printed_strings[$string])) {
		print('<span style="color: green;">' . $string . '</span><br>');
		$this->printed_strings[$string] = true;
	}
	return true;
}

function var_dump_short() {
	$arguments_array = func_get_args();
	foreach($arguments_array as $index => $value) {
		$data_type = gettype($value);
		if($data_type == 'array') {
			ini_set('xdebug.var_display_max_children', '2000');
			ini_set('xdebug.var_display_max_depth', '3');
		} elseif($data_type == 'string') {
			ini_set('xdebug.var_display_max_data', '100');
		} elseif($data_type == 'integer' || $data_type == 'float' || $data_type == 'chr' || $data_type == 'boolean' || $data_type == 'NULL') {
			// these are already compact enough
		} else {
			O::warning('Unhandled data type in var_dump_short: ' . gettype($value));
		}
		var_dump($value);
	}
	//ini_set('xdebug.var_display_max_depth', $this->var_display_max_depth);
	ini_set('xdebug.var_display_max_children', $this->var_display_max_children);
}

function var_dump_full() {
	$arguments_array = func_get_args();
	foreach($arguments_array as $index => $value) {
		$data_type = gettype($value);
		if($data_type == 'array') {
			$biggest_array_size = O::get_biggest_sizeof($value);
			if($biggest_array_size > 2000) {
				ini_set('xdebug.var_display_max_children', '2000');
			} elseif($biggest_array_size > ini_get('xdebug.var_display_max_children')) {
				ini_set('xdebug.var_display_max_children', $biggest_array_size);
			}
		} elseif($data_type == 'string') {
			$biggest_string_size = strlen($value);
			if($biggest_string_size > 10000) {
				ini_set('xdebug.var_display_max_data', '10000');
			} elseif($biggest_string_size > ini_get('xdebug.var_display_max_data')) {
				ini_set('xdebug.var_display_max_data', $biggest_string_size);
			}
		} elseif($data_type == 'integer' || $data_type == 'float' || $data_type == 'chr' || $data_type == 'boolean' || $data_type == 'NULL') {
			// these are already compact enough
		} else {
			O::warning('Unhandled data type in var_dump_full: ' . gettype($value));
		}
		var_dump($value);
	}
	//ini_set('xdebug.var_display_max_depth', $this->var_display_max_depth);
	ini_set('xdebug.var_display_max_children', $this->var_display_max_children);
}

function get_biggest_sizeof($array, $biggest = 0) {
	if(sizeof($array) > $biggest) {
		$biggest = sizeof($array);
	}
	foreach($array as $index => $value) {
		if(is_array($value)) {
			$biggest = O::get_biggest_sizeof($value, $biggest);
		}
	}
	return $biggest;
}

function filename_minus_extension($string) {
	return substr($string, 0, O::strpos_last($string, '.'));
}

function file_extension($string) {
	if(strpos($string, '.') === false || O::strpos_last($string, '.') < O::strpos_last($string, DS)) {
		return false;
	}
	return substr($string, O::strpos_last($string, '.'));
}

function shortpath($string) {
	return substr($string, O::strpos_last($string, DS));
}

function strpos_last($haystack, $needle) {
	//print('$haystack, $needle: ');var_dump($haystack, $needle);
	if(strlen($needle) === 0) {
		return false;
	}
	$len_haystack = strlen($haystack);
	$len_needle = strlen($needle);		
	$pos = strpos(strrev($haystack), strrev($needle));
	if($pos === false) {
		return false;
	}
	return $len_haystack - $pos - $len_needle;
}

function reindex($array) {
	//$array = array_unique($array);
	foreach($array as $index => $value) {
		$new_array[] = $value;
	}
	return $new_array;
}

function getmicrotime() {
	list($usec, $sec) = explode(' ', microtime());
	return ((float)$usec + (float)$sec);
}

function dump_total_time_taken() {
	$time_spent = O::getmicrotime() - $this->O_initial_time;
	print('Total time spent querying XML: ' . $time_spent . ' seconds.<br>');
}

}

?>