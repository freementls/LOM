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

class O {

function __construct($file_to_parse, $use_context = true, $array_blocks = false, $array_inline = false) {
	$this->var_display_max_depth = 6;
	$this->var_display_max_children = 8;
	ini_set('xdebug.var_display_max_depth', $this->var_display_max_depth);
	ini_set('xdebug.var_display_max_children', $this->var_display_max_children);
	$this->LOM = array();
	$this->context = array();
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
	$files_to_parse = func_get_args();
	$this->code = file_get_contents($file_to_parse);
	$this->LOM = O::generate_LOM($this->code);
}

function _($selector, $LOM_array = false) { // just an alias
	return O::get($selector, $LOM_array);
}

function __($selector, $new_value = 0, $parent_node = false) { // just an alias
	return O::set($selector, $new_value, $parent_node);
}

function get($selector, $LOM_array = false, $add_to_context = true) {
	//print('$selector, $LOM_array, $add_to_context at start of get: ');var_dump($selector, $LOM_array, $add_to_context);
	$LOM_array_was_provided = true;
	if(is_string($LOM_array)) {
		$LOM_array = O::get($LOM_array, false, $add_to_context); // not sure if we should force whether to add to context
	} elseif($LOM_array === false) {
		$LOM_array_was_provided = false;
		$LOM_array = array($this->LOM);
	}
	// detect the arrayification of parent_node and ensure it is the proper format as both individual results and sets of results can be passed
	if(is_array($LOM_array)) {
		foreach($LOM_array as $index => $value) { break; }
		if($index !== 0) { // then we weren't passed a set of results and we need to format it as such
			$LOM_array = array($LOM_array);
		} else {
			if(is_array($LOM_array[0])) {
				foreach($LOM_array[0] as $index2 => $value2) { break; }
				if(!is_array($LOM_array[0][$index2])) { // then we weren't passed a set of results and we need to format it as such
					$LOM_array = array($LOM_array);
				}
			}
		}
	}
	$used_context = false;
	//print('here374859---0000<br>');
	//print('$selector, $LOM_array before selector type determination in get: ');var_dump($selector, $LOM_array);
	//print('$selector at start of get: ');var_dump($selector);
	$selector_matches = array();
	if(is_numeric($selector)) { // treat it as an index in the LOM
		//print('is_numeric($selector) in get<br>');
		$selector = (int)$selector;
		if($this->LOM[$selector][0] === 0) { // assume that if it's text we want the text value
			//$selector_matches = array($LOM_array[$selector][1]);
			$selector_matches = $this->LOM[$selector][1];
		} else {
			$selector_matches = array($this->LOM[$selector]);
		}
		$add_to_context = false;
	} elseif(is_string($selector)) { // do XPath type processing
		//print('is_string($selector) in get<br>');
		$normalized_selector = $selector;
		$normalized_selector = str_replace('\\', '_', $normalized_selector);
		$normalized_selector = str_replace('/', '_', $normalized_selector);
		//print('here26344<br>');
		if($LOM_array_was_provided) {
			//print('here26345<br>');
			if($LOM_array === false) {
				return false;
			}
			$selector_matches = O::select_from_LOM_array($normalized_selector, $LOM_array);
		} else {
			// first go through previous contexts, then go from most broad context to most specific?? 
			if($this->use_context) {
				$context_counter = sizeof($this->context) - 1;
				//print('here26346<br>');
				while($context_counter > -1 && sizeof($selector_matches) === 0 && !is_string($selector_matches)) {
					//print('getting selector_matches from context<br>');
					//print('$context_counter: ');var_dump($context_counter);
					//print('here26347<br>');
					//print('$this->context[$context_counter]: ');var_dump($this->context[$context_counter]);
					if($normalized_selector === $this->context[$context_counter][0] && $LOM_array === $this->context[$context_counter][1]) {
						//print('here26348<br>');
						//$selector_matches = $this->context[$context_counter][3];
						return $this->context[$context_counter][3];
					} elseif(!is_array($this->context[$context_counter][3])) { // skip context entries with only a single value
						//print('here26349<br>');
					} else {
						//print('here26350<br>');
						// need to only look in the context here if the selector is a subset of the selector in the context but how can this be known without knowing the format of the XML? could get away with it if it is assumed that tags do not contain themselves?
						// this (unusually) falls into the category of grammar rather than syntax as computers are mostly concerned with but seems to make sense given the desire to query using imprecise statements that is the purpose of this code
						$first_selector_tag = O::parse_selector_string($normalized_selector)[0][0];
						$cleaned_first_selector_tag = O::clean_selector_tag_for_context_comparison($first_selector_tag);
						$first_context_selector_tag = O::parse_selector_string($this->context[$context_counter][0])[0][0];
						$cleaned_first_context_selector_tag = O::clean_selector_tag_for_context_comparison($first_context_selector_tag);
						//print('$first_selector_tag, $cleaned_first_selector_tag, $first_context_selector_tag, $cleaned_first_context_selector_tag: ');var_dump($first_selector_tag, $cleaned_first_selector_tag, $first_context_selector_tag, $cleaned_first_context_selector_tag);
						//print('$this->context: ');var_dump($this->context);
						if($cleaned_first_selector_tag === $cleaned_first_context_selector_tag || $cleaned_first_selector_tag === '*' || $cleaned_first_context_selector_tag === '*') {
							
						} else {
							$selector_matches = O::select_from_LOM_array($normalized_selector, $this->context[$context_counter][3]);
						}
					}
					$context_counter--;
				}
			}
			if(is_array($selector_matches) && sizeof($selector_matches) === 0) {
				//print('getting selector_matches from $this->LOM<br>');
				$selector_matches = O::select_from_LOM_array($normalized_selector, $LOM_array);
			} else {
				//O::good_message('used the context instead of querying the whole LOM');
				$used_context = true;
			}
		}
	} elseif(is_array($selector)) { // recurse??
		$selector_matches = array();
		foreach($selector as $index => $value) {
			$matches = O::get($value, $LOM_array);
			$selector_matches = array_merge($selector_matches, $matches);
		}
	} else {
		print('$selector: ');var_dump($selector);
		O::fatal_error('Unknown selector type in get');
	}
	//print('here374859---0042<br>');
	//print('$selector_matches, $this->context mid get: ');var_dump($selector_matches, $this->context);
	//print('$selector, $selector_matches mid get: ');var_dump($selector, $selector_matches);
	//print('$selector_matches mid get: ');var_dump($selector_matches);
	if(sizeof($selector_matches) === 0 || sizeof($selector_matches) === 1 && ($selector_matches[0] === NULL || $selector_matches[0] === false)) {
		//print('here374859---0044<br>');
		//print('$selector_matches: ');var_dump($selector_matches);
		//return false;
		// debateable whether it's better to return false or an empty array when nothing is found; coder expectation doens't really exist so ease of checking against a boolean and having different data type based on success will prevail, I guess
		$selector_matches = false;
		// turns out an empty array still evaluates to false in an if statement so we're good? nvm
		//$selector_matches = array();
		//$selector_matches = '';
		$add_to_context = false;
	}
	if($add_to_context) {
		//print('here374859---0045<br>');
		if(sizeof($selector_matches) > 0) {
			//print('here374859---0047<br>');
			//$text_only_value = false;
			//$text_only_index = false;
			// debug
			if(!is_array($selector_matches[0])) {
				//print('here374859---0048<br>');
				if(is_int($selector)) {
					//print('here374859---0049<br>');
					$start_indices = array($selector);
				} elseif($used_context) {
					//print('here374859---0050<br>');
					$start_indices = $this->context[$context_counter + 1][2];
				} else {
					//print('$selector, $selector_matches, $this->context: ');var_dump($selector, $selector_matches, $this->context);
					O::fatal_error('!is_array($selector_matches[0])');
				}
			} else {
				// this seems wonky
				//print('here374859---0051<br>');
				$start_indices = array();
				$new_start_indices = array();
				$new_selector_matches = array();
				$did_a_text_only_value = false;
				foreach($selector_matches as $index => $value) {
					$value_counter = 0;
					foreach($value as $value_index => $value_value) {
						if($value_counter === 0) {
							$start_indices[] = $value_index;
						}
						if($value_counter === 1) {
							$text_only_index = $value_index;
							$text_only_value = $value_value[1];
						}
						$value_counter++;
					}
					if($value_counter === 3 && strlen(trim($text_only_value)) > 0) { // making the assumption that existing tags with nothing in them should only be populated with tags rather than raw text
					//if($value_counter === 3) {
						//print('here374859---0055<br>');
						$new_start_indices[] = $text_only_index;
						$new_selector_matches[$text_only_index] = $text_only_value;
						$did_a_text_only_value = true;
					}
				}
				//print('$new_selector_matches1: ');var_dump($new_selector_matches);
				if(!$did_a_text_only_value) {
					$new_start_indices = $start_indices;
					$new_selector_matches = $selector_matches;
				}
				// if the selection resolves to a single value then that is what's desired rather than the array of thar single value
				if(sizeof($new_selector_matches) === 1 && (is_string($new_selector_matches[$text_only_index]) || is_int($new_selector_matches[$text_only_index]) || is_float($new_selector_matches[$text_only_index]))) {
				//if(sizeof($new_selector_matches) === 1) {
					//print('here374859---0056<br>');
					$new_start_indices = $text_only_index;
					$new_selector_matches = $text_only_value;
				}
			}
		}
		//print('$new_selector_matches2: ');var_dump($new_selector_matches);
		// debug
		if(sizeof($new_start_indices) !== sizeof($new_selector_matches)) {
			print('sizeof($new_start_indices), sizeof($new_selector_matches): ');var_dump(sizeof($new_start_indices), sizeof($new_selector_matches));
			O::fatal_error('sizeof($new_start_indices) !== sizeof($new_selector_matches)');
		}
		//print('$normalized_selector, $LOM_array, $new_start_indices, $new_selector_matches: ');var_dump($normalized_selector, $LOM_array, $new_start_indices, $new_selector_matches);
		if($this->use_context) {
			$this->context[] = array($normalized_selector, $LOM_array, $new_start_indices, $new_selector_matches);
		}
		$selector_matches = $new_selector_matches;
		//print('sizeof($this->context) - 1, $this->context[sizeof($this->context) - 1]: ');var_dump(sizeof($this->context) - 1, $this->context[sizeof($this->context) - 1]);
		// debug
		//if($normalized_selector === 'items') {
		//	print('items $this->context: ');O::var_dump_full($this->context);exit(0);
		//}
	}
	//print('$selector_matches, $this->context at end of get: ');var_dump($selector_matches, $this->context);
	//print('$selector_matches at end of get: ');var_dump($selector_matches);
	return $selector_matches;
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

function get_indices($selector, $LOM_array = false) {
	//print('$selector, $this->context in get_indices: ');var_dump($selector, $this->context);
	if($LOM_array === false) {
		$LOM_array = array($this->LOM);
	}
	$index_matches = array();
	if(is_numeric($selector)) { // treat it as an index in the LOM
		$selector = (int)$selector;
		$index_matches[] = $selector;
	} elseif(is_string($selector)) { // do XPath type processing
		$normalized_selector = $selector;
		$normalized_selector = str_replace('\\', '_', $normalized_selector);
		$normalized_selector = str_replace('/', '_', $normalized_selector);
		$selector_matches = array();
		if($this->use_context) {
			$context_counter = sizeof($this->context) - 1;
			//print('here249702667-9<br>');
			while($context_counter > -1 && sizeof($selector_matches) === 0 && !is_string($selector_matches)) {
				//print('here249702668-0<br>');
				//print('getting selector_matches from context in get_indices<br>');
				//print('$context_counter: ');var_dump($context_counter);
				//print('$this->context: ');O::var_dump_full($this->context);
				if($normalized_selector === $this->context[$context_counter][0] && $LOM_array === $this->context[$context_counter][1]) {
					//print('here249702668-1<br>');
					if(is_array($this->context[$context_counter][2])) {
						return $this->context[$context_counter][2];
					} else {
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
						
					} else {
						$selector_matches = O::select_from_LOM_array($normalized_selector, $this->context[$context_counter][3]);
					}
					//$selector_matches = O::select_from_LOM_array($normalized_selector, $this->context[$context_counter][3]);
				}
				$context_counter--;
			}
		}
		//print('here249702668-4<br>');
		if(sizeof($selector_matches) === 0) {
			//print('here249702668-5<br>');
			//print('getting selector_matches from $this->LOM in get_indices<br>');
			$selector_matches = O::select_from_LOM_array($normalized_selector, $LOM_array);
		}
		//print('here249702668-6<br>');
		if(sizeof($selector_matches) === 1 && (strpos($normalized_selector, '_') !== false || $LOM_array !== false || sizeof($selector_matches[0] === 3))) {
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
		//print('$selector_matches in get_indices: ');var_dump($selector_matches);
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
		//O::fatal_error('is_array($selector) in get_indices');
		$index_matches = array();
		foreach($selector as $index => $value) {
			$matches = O::get_indices($value, $LOM_array);
			$index_matches = array_merge($index_matches, $matches);
		}
	} else {
		print('$selector: ');var_dump($selector);
		O::fatal_error('Unknown selector type in get_indices');
	}
	return $index_matches;
}

function get_attribute_value($attribute_name, $LOM_array) {
	//print('$LOM_array in get_attribute_value: ');var_dump($LOM_array);
	if(!is_array($LOM_array)) { // assume it's an index
		//$LOM_array = O::get($LOM_array, false, false);
		$first_index = $LOM_array;
	} else {
		foreach($LOM_array as $first_index => $first_value) { break; } 
		if(is_array($first_value)) {
			foreach($first_value as $first_index => $first_value) { break; } 
		}
	}
	$attributes_array = $this->LOM[$first_index][1][1];
	//print('$attributes_array in get_attribute_value: ');var_dump($attributes_array);
	return $attributes_array[$attribute_name];
}

function get_attribute($attribute_name, $LOM_array) { // alias
	return O::get_attribute_value($attribute_name, $LOM_array);
}

function get_attributes($LOM_array) {
	if(!is_array($LOM_array)) { // assume it's an index
		$first_index = $LOM_array;
	} else {
		foreach($LOM_array as $first_index => $first_value) { break; } 
		if(is_array($first_value)) {
			foreach($first_value as $first_index => $first_value) { break; } 
		}
	}
	$attributes_array = $this->LOM[$first_index][1][1];
	return $attributes_array;
}

function set_attribute($attribute_name, $new_value, $selector) {
	if(!is_array($selector)) { // assume it's an index
		$index = $selector;
	} else {
		foreach($selector as $index => $value) { break; } 
		if(is_array($value)) {
			foreach($value as $index => $value) { break; } 
		}
	}
	$this->LOM[$index][1][1][$attribute_name] = $new_value;
	if($this->use_context) {
		foreach($this->context as $context_index => $context_value) {
			if(is_array($context_value[3])) {
				foreach($context_value[3] as $context_index2 => $context_value2) {
					if(sizeof($this->context[$context_index][$index][1][1]) > 0) {
						$this->context[$context_index][$index][1][1][$attribute_name] = $new_value;
					}
				}
			}
		}
	}
	return true;
}

function increment_attribute($attribute_name, $selector) {
	//print('$selector in increment_attribute: ');var_dump($selector);
	if(!is_array($selector)) { // assume it's an index
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
	}
	return true;
}

function select_from_LOM_array($selector, $LOM_array = false) {
	//print('here374859---0005.5<br>');
	if($LOM_array === false) {
		//print('here374859---0005.6<br>');
		$LOM_array = array($this->LOM);
	}
	//print('$selector, $this->context in select_from_LOM_array:');var_dump($selector, $this->context);
	//print('$selector, $LOM_array in select_from_LOM_array:');var_dump($selector, $LOM_array);
	//print('$selector in select_from_LOM_array:');var_dump($selector);
	if(is_string($LOM_array)) {
		return array();
	}
	$selector_piece_sets = O::parse_selector_string($selector);
	//print('$selector_piece_sets, $parent_node, $LOM_array: ');var_dump($selector_piece_sets, $parent_node, $LOM_array);
	//print('$selector_piece_sets: ');var_dump($selector_piece_sets);
	$selector_matches = array();
	foreach($selector_piece_sets as $selector_piece_set_index => $selector_piece_set) {
		$selector_piece_set_matches = false;
		$selected_parent_matches = false;
		$selected_parent_piece_set_index = false;
		//print('here374859---0006<br>');
		$contextual_matches = $LOM_array;
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
			$tagvalues = false;
			$tagvalue = false;
			$matching_index = false;
			$parsing_attributes = false;
			$required_attributes = array();
			//print('here374859---0009<br>');
			// attribute systax doesn't use square brackets [ ] unlike XPath; tagname[5]=tagvalue@attname1=attvalue1@attname2=attvalue2@attname3=attvalue3
			while($piece_offset < strlen($piece)) {
				//print('here374859---0010<br>');
				if($parsing_attributes) {
					if($piece[$piece_offset] === '@') {
						$required_attributes[O::query_decode($attribute_name_piece)] = O::query_decode($attribute_piece);
						$attribute_name_piece = '';
						$attribute_piece = '';
						$piece_offset++;
						continue;
					} elseif($piece[$piece_offset] === '=') {
						$attribute_name_piece = $attribute_piece;
						$attribute_piece = '';
						$piece_offset++;
						continue;
					}
					$attribute_piece .= $piece[$piece_offset];
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
						$parsing_attributes = true;
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
						$piece_offset++;
						continue;
					}
				}
				$tagname .= $piece[$piece_offset];
				$piece_offset++;
			}
			if($parsing_attributes) {
				$required_attributes[O::query_decode($attribute_name_piece)] = O::query_decode($attribute_piece);
			}
			//print('$contextual_matches before match_by_tagname: ');var_dump($contextual_matches);
			if(strlen($tagname) > 0) {
				$tagnames[] = O::query_decode($tagname);
				$tagvalues[] = O::query_decode($tagvalue);
				//print('$tagnames, $tagvalues: ');var_dump($tagnames, $tagvalues);
				$contextual_matches = O::match_by_tagname($tagnames, $contextual_matches, $look_only_in_direct_children, $tagvalues, $matching_index, $required_attributes);
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
			$last_piece = $piece;
		}
		$selector_piece_set_matches = $contextual_matches;
		
		//print('here374859---0038<br>');
		//print('$selected_parent_matches before selected parent processing in select_from_LOM_array: ');O::var_dump_full($selected_parent_matches);
		//print('$selector_piece_set_matches before selected parent processing in select_from_LOM_array: ');var_dump($selector_piece_set_matches);
		if($selected_parent_matches !== false) {
			$depth_requirement = false;
			if(strpos($selector, '__') !== false) {
				//O::fatal_error('__ unhandled for selecting the parent in select_from_LOM_array');
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
			//if(sizeof($selected_parent_full_selector_matches) <= 1) { // real ugly...
				$selector_piece_set_matches = $selected_parent_full_selector_matches;
			//} else {
			//	$selector_matches = array($selected_parent_full_selector_matches[sizeof($selected_parent_full_selector_matches) - 2]);
			//}
		}
		//print('$selector_piece_set_matches after selected parent processing in select_from_LOM_array: ');var_dump($selector_piece_set_matches);
		if(sizeof($selector_piece_set_matches) > 0) {
			//print('here374859---0035<br>');
			$selector_matches = array_merge($selector_matches, $selector_piece_set_matches);
			//break;
		}
	}
	
	//print('$selector_matches at end of select_from_LOM_array: ');var_dump($selector_matches);
	return $selector_matches;
}

function match_by_tagname($tagname, $matching_array, $look_only_in_direct_children = true, $tagvalue = false, $matching_index = false, $required_attributes = array()) {
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
								if($required_attribute_name === $existing_attribute_name && $existing_attribute_value === $required_attribute_value) {
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
	$selector_strings = explode('|', $selector_string);
	foreach($selector_strings as $selector_string) {
		$offset = 0;
		$pieces = array();
		$piece = '';
		while($offset < strlen($selector_string)) {
			if($selector_string[$offset] === '_') {
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
		$selector_piece_sets[] = $pieces;
	}
	return $selector_piece_sets;
}

function set($selector, $new_value = false, $parent_node = false) {
	// this function assumes the selector only chooses a single entry rather than a range, otherwise array_slice_replace would have to be used. not an unassailable position
	//print('$selector, $new_value, $parent_node in set: ');var_dump($selector, $new_value, $parent_node);
	$new_value = (string)$new_value;
	if($new_value === false || $new_value === NULL || strlen($new_value) === 0) {
		O::delete($selector, $parent_node);
	} elseif(is_numeric($selector)) { // treat it as an index in the LOM
		$selector = (int)$selector;
		if($this->LOM[$selector][0] === 0) { // then it is text
			$this->LOM[$selector][1] = $new_value;
			$parent_node[$selector][1] = $new_value;
			if($this->use_context) {
				foreach($this->context as $index => $value) {
					if(is_array($value[3])) {
						foreach($value[3] as $index2 => $value2) {
							if(isset($value2[$selector])) {
								$this->context[$index][3][$index2][$selector][1] = $new_value;
							}
						}
					} else {
						if($this->context[$index][2] === $selector) {
							$this->context[$index][3] = $new_value;
						}
					}
				}
			}
		} else { // what should be changed about a tag?
			print('$selector, $new_value: ');var_dump($selector, $new_value);
			O::fatal_error('what to set of a tag when a LOM index is provided has not been figured out');
		}
	} elseif(is_string($selector)) { // do XPath type processing
		//print('$selector, $parent_node, $this->context in set: ');var_dump($selector, $parent_node, $this->context);
		//print('$selector, $new_value in set: ');var_dump($selector, $new_value);
		$results = O::get_indices($selector, $parent_node);
		//print('$results, $new_LOM in set: ');var_dump($results, $new_LOM);
		//print('$this->LOM before in set');var_dump($this->LOM);
		foreach($results as $result) {
			$new_LOM = O::generate_LOM($new_value, $result);
			//foreach($result as $first_index => $first_value) { break; }
			//foreach($result as $last_index => $last_value) {  }
			//$this->LOM = O::insert($this->LOM, $result, $new_LOM); // wha, why are you inserting?
			$this->LOM[$result] = $new_LOM[$result];
			$parent_node[$result] = $new_LOM[$result][1]; // since it's just a text node
			//print('here375861<br>');
			if($this->use_context) {
				if(sizeof($this->context) > 0) {
					foreach($this->context as $index => $value) {
						if(is_array($value[3])) {
							foreach($value[3] as $index2 => $value2) {
								if(isset($value2[$result])) {
									//print('here375862<br>');
									//$this->context[$index][3][$index2] = O::insert($this->context[$index][3][$index2], $result, $new_LOM); // wha, why are you inserting?
									if(is_array($this->context[$index][3][$index2][$result])) {
										$this->context[$index][3][$index2][$result] = $new_LOM[$result];
									} else {
										$this->context[$index][3][$index2][$result] = $new_LOM[$result][1]; // since it's just a text node
									}
								}
							}
						} elseif($this->context[$index][2] === $result) {
							//print('here375863<br>');
							$this->context[$index][3] = $new_value;
						} elseif(is_array($this->context[$index][2])) {
							foreach($this->context[$index][2] as $start_index_index => $start_index_value) {
								if($context_result === $result) {
									if(isset($this->context[$index][3][$start_index_value][$result])) {
										if(is_array($this->context[$index][3][$start_index_value][$result])) {
											$this->context[$index][3][$start_index_value][$result] = $new_LOM[$result];
										} else {
											$this->context[$index][3][$start_index_value][$result] = $new_LOM[$result][1]; // since it's just a text node
										}
									}
								}
							}
						}
					}
				}
			}
		}
	} elseif(is_array($selector)) {
		O::fatal_error('array selector not handled in set function');
	} else {
		print('$selector: ');var_dump($selector);
		O::fatal_error('Unknown selector type in set');
	}
	if(sizeof($parent_node) === 1) {
		foreach($parent_node as $parent_node_first_index => $parent_node_first_value) {  }
		if(!is_array($parent_node_first_value)) {
			$parent_node = $parent_node[$parent_node_first_index];
		}
	}
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

function insert($array, $insert_index = false, $insert_array) {
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
	return $new_array;
}

private function internal_delete($array, $first_index, $last_index) {
	//print('$array, $first_index, $last_index before internal_delete: ');O::var_dump_full($array, $first_index, $last_index);
	if($array === false) {
		return false;
	}
	$new_array = array();
	$index_counter = false; // have to preserve indices
	if(is_array($array) && sizeof($array) > 0) {
		foreach($array as $index => $value) {
			if($index_counter === false) {
				$index_counter = $index;
			}
			if($index >= $first_index && $index <= $last_index) {
				
			} else {
				$new_array[$index_counter] = $value;
				$index_counter++;
			}
		}
	}
	//print('$new_array after internal_delete: ');O::var_dump_full($new_array);
	return $new_array;
}

function new_tag($new_value = false, $selector = '') { // just an alias
	return O::new_($new_value, $selector);
}

function new_($new_value, $selector = false) { // this function assumes that the new tag should go right before the closing tag of the selector
	//print('$this->LOM before, $this->context in new_: ');var_dump($this->LOM, $this->context);
	//print('$new_value, $selector in new_: ');var_dump($new_value, $selector);
	if(is_array($new_value)) {
		$new_value = O::to_string($new_value);
	}
	if($selector === false) {
		$selector = O::get_tag_name($this->LOM);
	}
	if(is_numeric($selector)) { // treat it as an index in the LOM
		$selector = (int)$selector;
		$new_LOM = O::generate_LOM($new_value, $selector);
		$this->LOM = O::insert($this->LOM, $selector, $new_LOM);
		if($this->use_context) {
			foreach($this->context as $context_index => $context_value) {
				if(is_array($context_value[2])) {
					foreach($context_value[3] as $index => $value) {
						if(is_array($this->context[$context_index][3][$index])) {
							$this->context[$context_index][3][$index] = O::insert($this->context[$context_index][3][$index], $selector, $new_LOM);
						} elseif($selector === $this->context[$context_index][2][$index]) {
							$this->context[$context_index][3][$index][$selector] = $new_LOM[$selector];
						}
						if($selector <= $this->context[$context_index][2][$index]) {
							$this->context[$context_index][2][$index]++;
							if(isset($this->context[$context_index][3][$index]) && !is_array($this->context[$context_index][3][$index])) {
								$this->context[$context_index][3][$index + 1] = $this->context[$context_index][3][$index];
								unset($this->context[$context_index][3][$index]);
							}
						}
					}
				} else {
					if($selector === $this->context[$context_index][2]) {
						$this->context[$context_index][3] = $new_LOM[$selector][1];
					}
					if($selector <= $this->context[$context_index][2]) {
						$this->context[$context_index][2]++;
					}
				}
			}
		}
		$selector_matches = array($new_LOM);
		if($this->use_context) {
			$this->context[] = array($selector, false, $selector, $selector_matches);
		}
	} elseif(is_string($selector)) { // do XPath type processing
		//$results = O::get($selector);
		$results = O::get($selector, false, false);
		//print('$selector, $new_LOM, $results in new_: ');var_dump($selector, $new_LOM, $results);
		if(sizeof($results) === 1) {
			foreach($results as $result) {
				foreach($result as $last_index => $last_value) {  }
				break;
			}
			$new_context_array = array(O::get_tag_name($new_value), false, $last_index, array());
		} else {
			O::fatal_error('would have to change the code in some places for context[2] to allow an array of values rather than only one value');
			$new_context_array = array(O::get_tag_name($new_value), false, false, array());
		}
		$selector_matches = array();
		foreach($results as $result) {
			//print('$result in new_: ');O::var_dump_full($result);
			foreach($result as $first_index => $first_value) { break; }
			foreach($result as $last_index => $last_value) {  }
			$selection_range = $last_index - $first_index + 1;
			$new_LOM = O::generate_LOM($new_value, $last_index);
			//print('$first_index, $new_LOM in new_: ');O::var_dump_full($first_index, $new_LOM);
			$this->LOM = O::insert($this->LOM, $last_index, $new_LOM);
			if($this->use_context) {
				if(sizeof($this->context) > 0) {
					//print('$this->context 717: ');var_dump($this->context);
					foreach($this->context as $context_index => $context_value) {
						if(is_array($this->context[$context_index][2])) {
							foreach($context_value[3] as $index2 => $value2) {
								if(is_array($this->context[$context_index][3][$index2])) {
									$this->context[$context_index][3][$index2] = O::insert($this->context[$context_index][3][$index2], $last_index, $new_LOM);
								} elseif($last_index === $this->context[$context_index][2][$index2]) {
									$this->context[$context_index][3][$index2][$last_index] = $new_LOM[$last_index];
								}
								if($last_index <= $index2) {
									$this->context[$context_index][2] += $selection_range;
									if(isset($this->context[$context_index][3][$index]) && !is_array($this->context[$context_index][3][$index])) {
										if(isset($this->context[$context_index][3][$index + $selection_range])) {
											O::fatal_error('rare case where shifting indices due to adding of tag(s) creates a collision which hasn\'t been coded yet.');
										}
										$this->context[$context_index][3][$index + $selection_range] = $this->context[$context_index][3][$index];
										unset($this->context[$context_index][3][$index]);
									}
								}
							}
						} else {
							if($this->context[$context_index][2] === $last_index) {
								$this->context[$context_index][3] = $new_LOM[$last_index][1];
							}
							if($last_index <= $this->context[$context_index][2]) {
								$this->context[$context_index][2] += $selection_range;
							}
						}
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
	} elseif(is_array($selector)) { // recurse??
		$counter = 0;
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
		$selector_matches = O::new_($new_value, $last_index);
	} else {
		print('$selector: ');var_dump($selector);
		O::fatal_error('Unknown selector type in new_');
	}
	//print('$this->LOM after new_: ');O::var_dump_full($this->LOM);exit(0);
	return $selector_matches;
}

function delete($selector, $parent_node = false) {
	//print('O::LOM_to_string($this->LOM) before delete: ');O::var_dump_full(O::LOM_to_string($this->LOM));
	// worth noting that it probably would have been easier to have this function be a sort of alias and return O::set($selector, '', $parent_node = false); although the assumption that set is working on only single values would no longer hold...
	if(is_numeric($selector)) { // treat it as an index in the LOM
		$selector = (int)$selector;
		//$new_array = array();
		//$index_counter = false;
		$this->LOM = O::internal_delete($this->LOM, $selector, $selector);
		$parent_node = O::internal_delete($parent_node, $selector, $selector);
		if($this->use_context) {
			foreach($this->context as $context_index => $context_value) {
				//print('$context_value[3]: ');var_dump($context_value[3]);
				if(is_array($context_value[3])) {
					foreach($context_value[3] as $index => $value) {
						//$new_array = array();
						//$index_counter = false;
						if(is_array($value)) {
							foreach($value as $index2 => $value2) {
								//if($index_counter === false) {
									if($selector <= $index2) {
										$index_counter = $index2 - 1;
										//print('$this->context[$context_index][2]: ');var_dump($this->context[$context_index][2]);
										if(is_array($this->context[$context_index][2])) {
											$this->context[$context_index][2][$index]--;
										} else {
											$this->context[$context_index][2]--;
										}
										//if(isset($this->context[$context_index][3][$index][]) && !is_array($this->context[$context_index][3][$index])) {
										//	$this->context[$context_index][3][$index - 1] = $this->context[$context_index][3][$index];
										//	unset($this->context[$context_index][3][$index]);
										//}
									//} else {
									//	$index_counter = $index2;
									}
								//}
								//if($index2 === $selector) {
								//	
								//} else {
								//	//$new_array[$index_counter] = $value2;
								//	$index_counter++;
								//}
							}
							if(is_array($this->context[$context_index][3])) {
								$this->context[$context_index][3][$index] = O::internal_delete($this->context[$context_index][3][$index], $selector, $selector);
							}
							//$this->context[$context_index][3][$index] = $new_array;
						} elseif($index === $selector) {
							unset($this->context[$context_index][2][$index]);
							unset($this->context[$context_index][3][$index]);
						}
					}
				} else {
					if($context_value[2] === $selector) {
						$new_context = array();
						foreach($this->context as $context_index2 => $context_value2) {
							if($context_index === $context_index2) {
								
							} else {
								$new_context[] = $context_value2;
							}
						}
						$this->context = $new_context;
					}
					if($selector <= $this->context[$context_index][2]) {
						$this->context[$context_index][2]--;
					}
				}
			}
		}
	} elseif(is_string($selector)) { // do XPath type processing
		$results = O::get($selector, false, false);
		$results_counter = sizeof($results) - 1;
		while($results_counter > -1) { // go in reverse order so that the indices are not disrupted by the deletions
			foreach($results[$results_counter] as $first_index => $first_value) { break; }
			foreach($results[$results_counter] as $last_index => $last_value) {  }
			//print('$first_index, $last_index in delete with string selector: ');var_dump($first_index, $last_index);
			$this->LOM = O::internal_delete($this->LOM, $first_index, $last_index);
			$parent_node = O::internal_delete($parent_node, $first_index, $last_index);
			$results_counter--;
		}
		if($this->use_context) {
			foreach($this->context as $context_index => $context_value) {
				$results_counter = sizeof($results) - 1;
				while($results_counter > -1) { // go in reverse order so that the indices are not disrupted by the deletions
					foreach($results[$results_counter] as $first_index => $first_value) { break; }
					foreach($results[$results_counter] as $last_index => $last_value) {  }
					$selection_range = $last_index - $first_index + 1;
					if(is_array($context_value[3])) {
						foreach($context_value[3] as $index => $value) {
							$index_counter = false;
							$new_array = array();
							foreach($value as $index2 => $value2) {
								if($index_counter === false) {
									// overlapping of the delete range and the selector range is not handled but this should never happen if the XML is well formatted
									if($last_index <= $index2) {
										$index_counter = $index2 - $selection_range;
										$this->context[$context_index][2][$index] -= $selection_range;
										if(isset($this->context[$context_index][3][$index]) && !is_array($this->context[$context_index][3][$index])) {
											$this->context[$context_index][3][$index - $selection_range] = $this->context[$context_index][3][$index];
											unset($this->context[$context_index][3][$index]);
										}
									} else {
										$index_counter = $index2;
									}
								}
								if($index2 >= $first_index && $index2 <= $last_index) {
									
								} else {
									$new_array[$index_counter] = $value2;
									$index_counter++;
								}
							}
							$this->context[$context_index][3][$index] = $new_array;
						}
					} else {
						if($context_value[2] >= $first_index && $context_value[2] <= $last_index) {
							$new_context = array();
							foreach($this->context as $context_index2 => $context_value2) {
								if($context_index === $context_index2) {
									
								} else {
									$new_context[] = $context_value2;
								}
							}
							$this->context = $new_context;
						}
						if($last_index <= $this->context[$context_index][2]) {
							$this->context[$context_index][2] -= $selection_range;
						}
					}
					$results_counter--;
				}
			}
		}
	} elseif(is_array($selector)) {
		//print('is_array($selector) in delete: ');O::var_dump_full($selector);
		//$recurse = false;
		//if(sizeof($selector) === 1) {
		//	foreach($selector as $selector_first_index => $selector_first_value) {  }
		//	if(!is_array($selector_first_value)) {
		//		$selector = $selector[$selector_first_index];
		//	}
		//}
		// have to go in reverse order
		foreach($selector as $counter1 => $value) {  }
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
		}
	} else {
		print('$selector: ');var_dump($selector);
		O::fatal_error('Unknown selector type in delete');
	}
	//print('O::LOM_to_string($this->LOM) after delete: ');O::var_dump_full(O::LOM_to_string($this->LOM));
	//print('$this->LOM after delete: ');O::var_dump_full($this->LOM);
	return $parent_node;
}

function add($to_add, $selector, $parent_node = false) {
	if($to_add === false) {
		O::fatal_error('to_add false in add');
		$to_add = O::get($value_to_add_selector, $value_to_add_parent_node);
	}
	if(is_numeric($selector)) { // treat it as an index in the LOM
		$selector = (int)$selector;
		$parent_node = O::set($selector, O::get($selector, $parent_node) + $to_add, $parent_node);
	} elseif(is_string($selector)) { // do XPath type processing
		$index_results = O::get_indices($selector, $parent_node);
		foreach($index_results as $index) {
			//print('$index, O::get($index), $to_add in add: ');var_dump($index, O::get($index), $to_add);
			$parent_node = O::set($index, O::get($index, $parent_node) + $to_add, $parent_node);
		}
		//O::set($selector, O::get($selector, $parent_node) + $to_add, $parent_node);
	} elseif(is_array($selector)) { // recurse??
		O::fatal_error('array selector not handled in add function');
	} else {
		print('$selector: ');var_dump($selector);
		O::fatal_error('Unknown selector type in add');
	}
	return $parent_node;
}

function subtract($selector, $to_subtract = false, $parent_node = false) {
	if($to_subtract === false) {
		O::fatal_error('to_subtract false in subtract');
		$to_subtract = O::get($value_to_add_selector, $value_to_add_parent_node);
	}
	if(is_numeric($selector)) { // treat it as an index in the LOM
		$selector = (int)$selector;
		$parent_node = O::set($selector, O::get($selector, $parent_node) - $to_subtract, $parent_node);
	} elseif(is_string($selector)) { // do XPath type processing
		$index_results = O::get_indices($selector, $parent_node);
		foreach($index_results as $index) {
			$parent_node = O::set($index, O::get($index, $parent_node) - $to_subtract, $parent_node);
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

function increment($selector, $parent_node = false) {
	if(is_numeric($selector)) { // treat it as an index in the LOM
		$selector = (int)$selector;
		$parent_node = O::set($selector, O::get($selector, $parent_node) + 1, $parent_node);
	} elseif(is_string($selector)) { // do XPath type processing
		$index_results = O::get_indices($selector, $parent_node);
		//print('$selector, $parent_node, $index_results in increment: ');var_dump($selector, $parent_node, $index_results);
		foreach($index_results as $index) {
			//print('$index, O::get($index): ');var_dump($index, O::get($index));
			$parent_node = O::set($index, O::get($index, $parent_node) + 1, $parent_node);
			//print('$index, O::get($index), $this->context, $this->LOM after increment: ');O::var_dump_full($index, O::get($index), $this->context, $this->LOM);
		}
	} elseif(is_array($selector)) { // recurse??
		O::fatal_error('array selector not handled in increment function');
	} else {
		print('$selector: ');var_dump($selector);
		O::fatal_error('Unknown selector type in increment');
	}
	return $parent_node;
}

function increment_zero_ceiling($selector, $parent_node = false) {
	if(is_numeric($selector)) { // treat it as an index in the LOM
		$selector = (int)$selector;
		$incremented = O::get($selector, $parent_node) + 1;
		if($incremented <= 0) {
			$parent_node = O::set($selector, $incremented, $parent_node);
		}
	} elseif(is_string($selector)) { // do XPath type processing
		$index_results = O::get_indices($selector, $parent_node);
		foreach($index_results as $index) {
			$incremented = O::get($index, $parent_node) + 1;
			if($incremented <= 0) {
				$parent_node = O::set($index, $incremented, $parent_node);
			}
		}
	} elseif(is_array($selector)) { // recurse??
		O::fatal_error('array selector not handled in increment_zero_ceiling function');
	} else {
		print('$selector: ');var_dump($selector);
		O::fatal_error('Unknown selector type in increment_zero_ceiling');
	}
	return $parent_node;
}

function decrement($selector, $parent_node = false) {
	if(is_numeric($selector)) { // treat it as an index in the LOM
		$selector = (int)$selector;
		$parent_node = O::set($selector, O::get($selector, $parent_node) - 1, $parent_node);
	} elseif(is_string($selector)) { // do XPath type processing
		$index_results = O::get_indices($selector, $parent_node);
		foreach($index_results as $index) {
			$parent_node = O::set($index, O::get($index, $parent_node) - 1, $parent_node);
		}
	} elseif(is_array($selector)) { // recurse??
		O::fatal_error('array selector not handled in decrement function');
	} else {
		print('$selector: ');var_dump($selector);
		O::fatal_error('Unknown selector type in decrement');
	}
	return $parent_node;
}

function decrement_zero_floor($selector, $parent_node = false) {
	if(is_numeric($selector)) { // treat it as an index in the LOM
		$selector = (int)$selector;
		$decremented = O::get($selector, $parent_node) - 1;
		if($decremented >= 0) {
			$parent_node = O::set($selector, $decremented, $parent_node);
		}
	} elseif(is_string($selector)) { // do XPath type processing
		$index_results = O::get_indices($selector, $parent_node);
		foreach($index_results as $index) {
			$decremented = O::get($index, $parent_node) - 1;
			if($decremented >= 0) {
				$parent_node = O::set($index, $decremented, $parent_node);
			}
		}
	} elseif(is_array($selector)) { // recurse??
		O::fatal_error('array selector not handled in decrement_zero_floor function');
	} else {
		print('$selector: ');var_dump($selector);
		O::fatal_error('Unknown selector type in decrement_zero_floor');
	}
	return $parent_node;
}

function operation($selector, $operation, $parent_node = false) {
	// probably exec the operation
	if(is_numeric($selector)) { // treat it as an index in the LOM
		$selector = (int)$selector;
		$parent_node = O::set($selector, exec('O::get($selector, $parent_node)' . $operation), $parent_node);
	} elseif(is_string($selector)) { // do XPath type processing
		$index_results = O::get_indices($selector, $parent_node);
		foreach($index_results as $index) {
			$parent_node = O::set($index, exec('O::get($selector, $parent_node)' . $operation), $parent_node);
		}
	} elseif(is_array($selector)) { // recurse??
		O::fatal_error('array selector not handled in operation function');
	} else {
		print('$selector: ');var_dump($selector);
		O::fatal_error('Unknown selector type in operation');
	}
	return $parent_node;
}

function sum($selector, $parent_node = false) {
	$sum = 0;
	$values = O::get($selector, $parent_node);
	if(is_array($values)) {
		foreach($values as $value) {
			$sum += $value;
		}
	}
	return $sum;
}

function average($selector, $parent_node = false) {
	$sum = 0;
	$values = O::get($selector, $parent_node);
	if(is_array($values)) {
		foreach($values as $value) {
			$sum += $value;
		}
	}
	return $sum / sizeof($values);
}

function get_tag_name($variable) {
	if(is_string($variable)) {
		return substr($variable, strpos($variable, '<') + 1, strpos($variable, '>') - strpos($variable, '<') - 1);
	} elseif(is_array($variable)) {
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
		}
	} else {
		print('$variable: ');var_dump($variable);
		O::fatal_error('unhandled variable type in get_tag_name');
	}
	return false;
}

function string_to_LOM($code, $start_index = false) { // alias
	return O::generate_LOM($code, $start_index);
}

function generate_LOM($code, $start_index = false) {
	// documentation
	/*
	0 => text or tag; 0 = text, 1 = tag
	1 => text string of tag array, if tag array
		0 => tag name
		1 => attributes array; probably an associative array
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
	$tag_types = array('opening' => 0, 'closing' => 0, 'self-closing' => 0);
	$code = (string)$code; // for when we are generating $LOM from an int ;p
	$offset = 0;
	$LOM = array();
	$parsing_tag = false;
	$code_piece = '';
	if($start_index === false) {
		$index_counter = 0;
	} else {
		$index_counter = $start_index;
	}
	//print('5054<br>');exit(0);
	while($offset < strlen($code)) {
		//print('5055<br>');
		if($parsing_tag) {
			//print('5056<br>');
			if($code[$offset] === '<') {
				O::fatal_error('LOM alert: invalid syntax; <code>' . htmlentities($code_piece) . '</code> will be treated as text (unexpected &lt;).');
				$LOM[$index_counter] = array(0, $code_piece);
				$index_counter++;
				$code_piece = '';
			} elseif($code[$offset] === '>') {
				$LOM[$index_counter] = array(1, $code_piece . '>');
				$index_counter++;
				$code_piece = '';
				$parsing_tag = false;
			} else {
				$code_piece .= $code[$offset];
			}
		} else {
			//print('5057<br>');
			if($code[$offset] === '<') {
				$LOM[$index_counter] = array(0, $code_piece);
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
							$LOM[$index_counter] = array(1, array($code_piece . ']]>', false, 4, false));
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
							$LOM[$index_counter] = array(1, array($code_piece . '-->', false, 5, false));
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
							$LOM[$index_counter] = array(1, array($code_piece . '?>', false, 6, false));
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
							$LOM[$index_counter] = array(1, array($code_piece . '%>', false, 7, false));
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
				O::fatal_error('LOM alert: invalid syntax; <code>' . htmlentities($code_piece) . '</code> will be treated as text (unexpected &gt;).');
				$LOM[$index_counter] = array(0, $code_piece . '>');
				$index_counter++;
				$code_piece = '';
			} else {
				$code_piece .= $code[$offset];
			}
		}
		$offset++;
	}
	if(strlen($code_piece) > 0) {
		$LOM[$index_counter] = array(0, $code_piece);	
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
						preg_match('/[\w\-:]+/is', $tag, $attribute_name_matches, PREG_OFFSET_CAPTURE, $offset); // notice that by including ':' we are confounding namespaces
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
					preg_match('/[\w\-]+/is', $tag, $tag_name_matches, PREG_OFFSET_CAPTURE, $offset);
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
			if($tag_array[2] === 0) {
				$tag_types['opening']++;
			} elseif($tag_array[2] === 1) {
				$tag_types['closing']++;
			} elseif($tag_array[2] === 2) {
				$tag_types['self-closing']++;
			}
			$LOM[$index] = array(1, $tag_array);
		}
	}
	if($tag_types['opening'] !== $tag_types['closing']) {
		print('$LOM: ');O::var_dump_full($LOM);
		print('$tag_types: ');var_dump($tag_types);
		O::fatal_error('different numbers of opening and closing tags');
	}
	//print('$LOM at bottom of generate_LOM: ');O::var_dump_full($LOM);exit(0);
	return $LOM;
}

function save_LOM_to_file($filename, $parent_node = false) {
	if($parent_node === false) {
		$code = O::generate_code_from_LOM($this->LOM);
	} else {
		//$code = O::generate_code_from_LOM(O::get($parent_node));
		$code = O::generate_code_from_LOM($parent_node);
	}
	file_put_contents($filename, $code);
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

function generate_code_from_LOM($array) { // alias
	return O::generate_code_from_LOM_array($array);
}

function generate_code_from_LOM_array($array) {
	if($array === false) {
		return '';
	} else { // have to be able to take a LOM_array or array of LOM_arrays
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
	}
	//print('$array (3): ');O::var_dump_full($array);exit(0);
	if(!isset($this->config['indentation_string'])) {
		//$this->config['indentation_string'] = '	'; // tab; I don't like it :)
		$this->config['indentation_string'] = '';
	}
	$block_depth = 0;
	$code = '';
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
			O::fatal_error('generate_code_from_LOM_array thinks there is content that is neither text or a tag in this code...?');
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
	if(strpos($string, '.') === false || O::strpos_last($string, '.') < O::strpos_last($string, DIRECTORY_SEPARATOR)) {
		return false;
	}
	return substr($string, O::strpos_last($string, '.'));
}

function shortpath($string) {
	return substr($string, O::strpos_last($string, DIRECTORY_SEPARATOR));
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

}

?>