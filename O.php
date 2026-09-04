<?php
/**
 * LOM: Living Object Model (started as Logical Object Model)
 *
 * SPDX-License-Identifier: Apache-2.0
 * Copyright contributors to LOM
 */

require_once __DIR__ . '/lom_packed_depths.php';

// ...... combining tidyer_DOM, DOM, OM, XPath, preg ......

// see test.php for usage examples

// maybe a short description of what a logical object is; such an object is defined when what it is is separated from what it is not; this is how logic works. on its own this may not be very interesting but when such logical
// objects can interact it becomes interesting. the facility of this code comes from the basic list of operations used which allows a shorthand for these. also worth mentioning that logical objects are hierarchically structured
// so that they are still caught in the matrix, but this is nothing new for language or computers. what's more interesting is that it's to the point where the dead, logical, unchanging, hierarchical, structured, etc. is to the
// point of being bridged with what's living in that it has changing and adapting properties based on code context and processing context and programming context, as well as using living principles of organization to achieve
// efficiency which in turn makes applications that are more dynamic and variable and life-based possible than if no effort was made to interface with how a human thinks and behaves

// should handle operators other than = such as < > != ~= not equal etc all the operators from CSS, jquery, PHP, XSLT selectors in version 0.8
// https://www.php.net/manual/en/language.operators.php
// https://www.w3schools.com/jquery/jquery_ref_selectors.asp
// https://www.w3schools.com/cssref/css_selectors.asp
// https://www.w3schools.com/xml/xpath_syntax.asp

// no way to select elements with an attribute with a specific value in the element itself instead of in a subtag... could be in 0.9 tag@att=attvalue=tagvalue

class O {
	public $LOM = array();
	public $O_initial_time = 0;
	public $array_blocks = array();
	public $array_delayed_delete = array();
	public $array_delayed_new = array();
	public $array_in_between = array();
	public $array_inline = array();
	public $array_inlines = array();
	public $array_self_closing = array();
	public $attributename_regex = '';
	public $attributes_indices = array();
	public $attributes_match_counter = array();
	public $attributes_match_counter2 = array();
	public $code = '';
	public $config = array();
	public $context = array();
	public $context_selector_index = array();
	public $context_selector_index_size = -1;
	public $context_exact_false_scope_index = array();
	public $context_exact_false_scope_lom_cache = array();
	public $debug = false;
	public $debug_counter = 0;
	public $debug_level = 2;
	public $debug_categories = array();
	public $debug_log_buffer = array();
	public $debug_log_limit = 200;
	public $debug_sequence = 0;
	public $document_header_end_offset = false;
	public $expands = array();
	public $file = false;
	public $fractal_depth_sets = array();
	public $fractal_depths = array();
	public $initial_code = '';
	public $must_check_for_ASP = false;
	public $must_check_for_comment = false;
	public $must_check_for_doctype = false;
	public $must_check_for_non_parsed_character_data = false;
	public $must_check_for_programming_instruction = false;
	public $must_check_for_self_closing = false;
	public $offset_depths = array();

	public $parent_indexes_ready = false;
	public $self_closing_open_count_in_index = 0;
	public $opening_tag_offsets = array();
	public $opening_tag_offsets_count = 0;
	public $parent_offsets = array();
	public $parent_children_index = array();
	public $parent_children_index_ready = false;
	public $parent_children_index_size = -1;
	public $node_end_offsets = array();
	public $tag_end_offsets = array();
	public $parent_result_cache = array();
	public $parent_query_cache = array();
	public $opening_tag_names = array();
	public $tag_index = array();
	public $attribute_index = array();
	public $attribute_value_index = array();
	public $attribute_index_ready = array();
	public $attribute_full_index_ready = false;
	public $offsets_from_get = array();
	public $offsets_need_adjusting = false;
	public $operators = array();
	public $printed_strings = array();
	public $reached_selector_index = 0;
	public $required_attribute_sets = array();
	public $required_attributes = array();
	public $reset_context = false;
	public $saved_attributes_indices = array();
	public $saved_tagname_indices = array();
	public $saved_tagvalue_indices = array();
	public $selected_parent_matches = array();
	public $selected_parent_offset_depths = array();
	public $selected_parent_piece_index = false;
	public $selector_piece_set_index = false;
	public $selector_piece_sets = array();
	public $selector_pieces = array();
	public $selector_scope_sets = array();
	public $selector_scopes = array();
	public $string_operation_made_a_change = false;
	public $tagname_indices = array();
	public $tagname_match_counter = array();
	public $tagname_match_counter2 = array();
	public $tagname_regex = '';
	public $tagnames = array();
	public $tagvalue_indices = array();
	public $tagvalue_match_counter = array();
	public $tagvalue_match_counter2 = array();
	public $tagvalues = array();
	public $use_context = true;
	public $var_display_max_children = 8;
	public $var_display_max_depth = 6;
	public $variables = array();
	public $zero_offsets = array();
	public $selector_piece_multi_and = array();
	public $profile_enabled = false;
	public $profile_stats = array();
	public $selector_pieces_preparsed = false;
	public $comparison_operators = array();
	public $comparison_operators_regex = '';
	public $selector_operators = array();
	public $tagvalue_comparison_operators = array();
	public $attribute_sets_comparison_operators = array();
	public $native_doc = null; /* optional lom_accel document resource */
	public $selector_regex_table = array(); /* id => array(pattern, flags, compiled) */
	public $selector_regex_compile_cache = array(); /* "pattern\0flags" => compiled */
	public $fmem_intern = array(); /* content-hash => string; L1 slice intern */
	public $fmem_hits = 0;
	public $fmem_misses = 0;
	public $fmem_enabled = true; /* ROI gate may disable after poor hit rate */
	public $fcache_regex_matches = array(); /* key => match arrays */
	public $fcache_hits = 0;
	public $fcache_misses = 0;
	public $fcache_enabled = true;
	public $fcache_compiled_patterns = array(); /* alias of compile cache for ROI stats */
	public $offset_depths_pending = false; /* lazy depth map for large documents */
	public $tag_index_slim_ready = false;
	public $slim_open_offsets = array();
	public $slim_node_ends = array();

	function __construct($file_to_parse, $use_context = true, $array_blocks = false, $array_inline = false) {
		$this->O_initial_time = O::getmicrotime();
		if(!defined('DS')) {
			define('DS', DIRECTORY_SEPARATOR);
		}
		$this->var_display_max_depth = 6;
		$this->var_display_max_children = 8;
		$this->debug = false;
		ini_set('xdebug.var_display_max_depth', $this->var_display_max_depth);
		ini_set('xdebug.var_display_max_children', $this->var_display_max_children);
		//ini_set('max_execution_time', '0.1'); // debug; some infinite loop
		$this->tagname_regex = '[\w\-:]+';
		$this->attributename_regex = '[\w\-:]+';
		//$this->LOM = array();
		$this->context = array();
		$this->expands = array();
		$this->variables = array();
		$this->offset_depths = array();
		$this->required_attributes = array();
		$this->use_context = $use_context;
		$this->profile_enabled = false;
		$profile_env = getenv('LOM_PROFILE');
		if($profile_env !== false) {
			$profile_env = strtolower(trim((string)$profile_env));
			$this->profile_enabled = in_array($profile_env, array('1', 'true', 'on', 'yes'), true);
		}
		$this->profile_stats = array();
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
			//} elseif(O::file_extension($file_to_parse) === '.xml' || O::file_extension($file_to_parse) === '.html') { // others?
		} elseif(O::file_extension($file_to_parse) === 'xml' || O::file_extension($file_to_parse) === 'html') { // others?
			$this->code = '';
			// assume it will be saved later? no. just properly work from the no found contents.
		} else {
			$this->code = $file_to_parse;
			$this->file = false;
		}
		$this->initial_code = $this->code;
		O::validate_syntax();
		//$this->LOM = O::generate_LOM($this->code); // only generate_LOM as needed; it's possible that only simple preg operations on $this->code are needed
		//print('$this->code in __construct: ');var_dump($this->code);
		O::reset_tag_types();
		O::check_tag_types($this->code);
		/* Large documents: defer the full offset_depths PHP array (huge per-key overhead)
		 * until a query path needs it. Force with LOM_EAGER_DEPTHS=1. */
		$code_bytes = strlen($this->code);
		$eager = getenv('LOM_EAGER_DEPTHS');
		$eager_on = ($eager !== false && $eager !== '' && $eager !== '0' && strcasecmp((string)$eager, 'false') !== 0 && strcasecmp((string)$eager, 'off') !== 0);
		if(!$eager_on && $code_bytes >= 8 * 1024 * 1024) {
			$this->offset_depths = array();
			$this->offset_depths_pending = true;
			$this->fmem_enabled = false; /* avoid second copies of large slices by default */
		} else {
			$this->offset_depths_pending = false;
			O::set_offset_depths();
		}
		O::set_LOM_operators();
		O::native_doc_boot();
		//O::debug();
	}

	function ensure_offset_depths() {
		if(!$this->offset_depths_pending) {
			return true;
		}
		$this->offset_depths_pending = false;
		return O::set_offset_depths();
	}

	function native_doc_boot() {
		$this->native_doc = null;
		$env = getenv('LOM_NATIVE');
		if($env === false) {
			return false;
		}
		$env = strtolower(trim((string)$env));
		if(!in_array($env, array('1', 'true', 'on', 'yes'), true)) {
			return false;
		}
		if(!O::lom_accel_enabled() || !function_exists('lom_doc_create')) {
			return false;
		}
		$doc = lom_doc_create($this->code);
		if($doc === false) {
			return false;
		}
		$this->native_doc = $doc;
		return true;
	}

	function native_selector_supported($selector) {
		if(!is_string($selector) || $selector === '') {
			return false;
		}
		if(strpos($selector, '|') !== false || strpos($selector, '.') !== false || strpos($selector, '&') !== false) {
			return false;
		}
		if(strpos($selector, '__') !== false) {
			return false;
		}
		// Indexed selectors are always 0-based in PHP; keep them off the native path.
		if(strpos($selector, '[') !== false) {
			return false;
		}
		return true;
	}

	function native_sync_from_doc() {
		if(!$this->native_doc || !function_exists('lom_doc_sync_code')) {
			return false;
		}
		$code = lom_doc_sync_code($this->native_doc);
		if(!is_string($code)) {
			return false;
		}
		$this->code = $code;
		O::clear_parent_structural_indexes();
		O::invalidate_runtime_caches_only();
		O::try_build_parent_indexes_native();
		O::check_tag_types($this->code);
		O::set_offset_depths();
		return true;
	}

	function reset_tag_types() {
		$this->must_check_for_self_closing = false;
		$this->must_check_for_doctype = false;
		$this->must_check_for_non_parsed_character_data = false;
		$this->must_check_for_comment = false;
		$this->must_check_for_programming_instruction = false;
		$this->must_check_for_ASP = false;
	}

	function check_tag_types($code) {
		$this->document_header_end_offset = false;
		if(strpos($code, '/>') !== false) { // self-closing tag
			$this->must_check_for_self_closing = true;
			//print('$this->code: ');O::var_dump_full($this->code);
			//O::fatal_error('found self-closing but code to handle this is not written yet.');
		}
		if(strpos($code, '<!DOCTYPE') !== false || strpos($code, '<!doctype') !== false) { // doctype
			$this->must_check_for_doctype = true;
			//print('$this->code: ');O::var_dump_full($this->code);
			//O::fatal_error('found doctype but code to handle this is not written yet.');
		}
		if(strpos($code, '<![CDATA[') !== false) { // non-parsed character data
			$this->must_check_for_non_parsed_character_data = true;
			//print('$this->code: ');O::var_dump_full($this->code);
			//O::fatal_error('found non-parsed character data but code to handle this is not written yet.');
		}
		if(strpos($code, '<!--') !== false) { // comment
			$this->must_check_for_comment = true;
			//print('$this->code: ');O::var_dump_full($this->code);
			//O::fatal_error('found comment but code to handle this is not written yet.');
		}
		if(strpos($code, '<?') !== false) { // programming instruction
			$this->must_check_for_programming_instruction = true;
			//print('$this->code: ');O::var_dump_full($this->code);
			//O::fatal_error('found programming instruction but code to handle this is not written yet.');
			// Keep legacy document-header marker behavior (first PI end), but allow
			// multiple processing instructions / PHP blocks in real-world documents.
			$first_pi_end = strpos($code, '?>');
			if($first_pi_end !== false) {
				$this->document_header_end_offset = $first_pi_end + 1;
			}
		}
		if(strpos($code, '<%') !== false) { // ASP
			$this->must_check_for_ASP = true;
			//print('$this->code: ');O::var_dump_full($this->code);
			//O::fatal_error('found ASP but code to handle this is not written yet.');
		}
		//print('$this->must_check_for_self_closing, $this->must_check_for_doctype, $this->must_check_for_non_parsed_character_data, $this->must_check_for_comment, $this->must_check_for_programming_instruction, $this->must_check_for_ASP: ');var_dump($this->must_check_for_self_closing, $this->must_check_for_doctype, $this->must_check_for_non_parsed_character_data, $this->must_check_for_comment, $this->must_check_for_programming_instruction, $this->must_check_for_ASP);
	}

	function parse_fast_overlay_attribute_selector($normalized_selector) {
		static $fast_overlay_attribute_selector_cache = array();
		if(!is_string($normalized_selector) || $normalized_selector === '') {
			return false;
		}
		if(isset($fast_overlay_attribute_selector_cache[$normalized_selector])) {
			return $fast_overlay_attribute_selector_cache[$normalized_selector];
		}
		if(strpos($normalized_selector, '_') !== false || strpos($normalized_selector, '.') !== false || strpos($normalized_selector, '&') !== false || strpos($normalized_selector, '[') !== false) {
			$fast_overlay_attribute_selector_cache[$normalized_selector] = false;
			return false;
		}
		if(strpos($normalized_selector, '|') !== false && strpos($normalized_selector, '|=') === false) {
			$fast_overlay_attribute_selector_cache[$normalized_selector] = false;
			return false;
		}
		if(!preg_match('/^([A-Za-z0-9:\-*]*)@([A-Za-z0-9:\-]+)(' . $this->comparison_operators_regex . ')(.*)$/', $normalized_selector, $matches)) {
			$fast_overlay_attribute_selector_cache[$normalized_selector] = false;
			return false;
		}
		$tagname = ($matches[1] === '') ? '*' : $matches[1];
		$attribute_name = $matches[2];
		$operator = $matches[3];
		$attribute_value = trim(O::query_decode($matches[4]));
		if(strlen($attribute_value) >= 2) {
			$q1 = $attribute_value[0];
			$q2 = $attribute_value[strlen($attribute_value) - 1];
			if(($q1 === '"' && $q2 === '"') || ($q1 === "'" && $q2 === "'")) {
				$attribute_value = substr($attribute_value, 1, -1);
			}
		}
		$parsed = array('tagname' => $tagname, 'attribute_name' => $attribute_name, 'operator' => $operator, 'attribute_value' => $attribute_value);
		$fast_overlay_attribute_selector_cache[$normalized_selector] = $parsed;
		return $parsed;
	}

	function fast_get_overlay_attribute_selector($normalized_selector, $add_to_context = true, $ignore_context = false, $parent_node_only = false, $tagged_result = false) {
		$profile_token = $this->profile_function_start('fast_get_overlay_attribute_selector');
		try {
		$parsed = O::parse_fast_overlay_attribute_selector($normalized_selector);
		if($parsed === false) {
			return false;
		}
		O::ensure_attribute_index($parsed['attribute_name']);
		$attribute_name = $parsed['attribute_name'];
		$candidate_map = array();
		if(isset($this->attribute_value_index[$attribute_name])) {
			foreach($this->attribute_value_index[$attribute_name] as $indexed_value => $offset_map) {
				if($this->compare($indexed_value, $parsed['operator'], $parsed['attribute_value'])) {
					foreach($offset_map as $offset => $true_value) {
						$candidate_map[$offset] = true;
					}
				}
			}
		}
		if(sizeof($candidate_map) === 0) {
			$fb = O::fast_overlay_fallback_child_tag($parsed, $parent_node_only);
			$results = $fb[0];
			$this->offsets_from_get = $fb[1];
			if(sizeof($results) === 0) {
				if($add_to_context && $this->use_context && !$ignore_context) {
					$this->append_context_entry(array($normalized_selector, false, false, array()));
				}
				return array();
			}
			sort($this->offsets_from_get, SORT_NUMERIC);
			if($add_to_context && $this->use_context && !$ignore_context) {
				$this->append_context_entry(array($normalized_selector, false, false, $results));
			}
			if($tagged_result) {
				return $results;
			}
			return O::export($results);
		}
		$matching_offsets = array_keys($candidate_map);
		if($parsed['tagname'] !== '*' && $parsed['tagname'] !== false && $parsed['tagname'] !== '') {
			O::ensure_parent_indexes();
			$filtered_offsets = array();
			foreach($matching_offsets as $candidate_offset) {
				if(isset($this->opening_tag_names[$candidate_offset]) && $this->opening_tag_names[$candidate_offset] === $parsed['tagname']) {
					$filtered_offsets[] = $candidate_offset;
				}
			}
			$matching_offsets = $filtered_offsets;
		}
		if(sizeof($matching_offsets) === 0) {
			$this->offsets_from_get = array();
			if($add_to_context && $this->use_context && !$ignore_context) {
				$this->append_context_entry(array($normalized_selector, false, false, array()));
			}
			return array();
		}
		sort($matching_offsets, SORT_NUMERIC);
		$results = array();
		$this->offsets_from_get = array();
		foreach($matching_offsets as $matching_offset) {
			$result = O::build_node_result_from_offset($matching_offset, true, $parent_node_only);
			$results[] = $result;
			$this->offsets_from_get[] = $matching_offset;
		}
		if($add_to_context && $this->use_context && !$ignore_context) {
			$this->append_context_entry(array($normalized_selector, false, false, $results));
		}
		if($tagged_result) {
			return $results;
		}
		return O::export($results);
		} finally {
			$this->profile_function_stop('fast_get_overlay_attribute_selector', $profile_token);
		}
	}

	function _lom_selector_has_overlay($selector) {
		return preg_match('/(?:' . $this->comparison_operators_regex . ')/', $selector) === 1;
	}

	function lom_regex_token_id($value) {
		if(!is_string($value)) {
			return false;
		}
		if(preg_match('/^#lomregex#(\d+)#$/', $value, $m)) {
			return (int)$m[1];
		}
		return false;
	}

	function lom_regex_allowed_flags($flags) {
		if($flags === '') {
			return true;
		}
		return (bool)preg_match('/^[imsux]+$/', $flags);
	}

	function compile_lom_regex($pattern, $flags) {
		$cache_key = $pattern . "\0" . $flags;
		if(isset($this->selector_regex_compile_cache[$cache_key])) {
			return $this->selector_regex_compile_cache[$cache_key];
		}
		if(!O::lom_regex_allowed_flags($flags)) {
			O::fatal_error('unsupported regex flags (allowed: i m s u x): ' . $flags);
		}
		$delim = '/';
		$compiled = $delim . $pattern . $delim . $flags;
		// Validate compile: empty subject; catch invalid patterns.
		$prev = error_reporting(0);
		$ok = @preg_match($compiled, '');
		error_reporting($prev);
		if($ok === false && preg_last_error() !== PREG_NO_ERROR) {
			O::fatal_error('invalid regex pattern: ' . $pattern);
		}
		$this->selector_regex_compile_cache[$cache_key] = $compiled;
		return $compiled;
	}

	function extract_selector_regexes($selector) {
		if(!is_string($selector) || $selector === '' || strpos($selector, '/') === false) {
			return $selector;
		}
		if(!isset($this->comparison_operators) || !is_array($this->comparison_operators) || sizeof($this->comparison_operators) === 0) {
			O::set_LOM_operators();
		}
		$ops = array_keys($this->comparison_operators); // longest-first already
		$out = '';
		$len = strlen($selector);
		$i = 0;
		while($i < $len) {
			$matched_op = false;
			foreach($ops as $op) {
				$op_len = strlen($op);
				if(substr($selector, $i, $op_len) === $op) {
					$after = $i + $op_len;
					if($after < $len && $selector[$after] === '/') {
						// Parse /pattern/flags
						$p = $after + 1;
						$pattern = '';
						while($p < $len) {
							if($selector[$p] === '\\' && $p + 1 < $len) {
								$pattern .= $selector[$p] . $selector[$p + 1];
								$p += 2;
								continue;
							}
							if($selector[$p] === '/') {
								break;
							}
							$pattern .= $selector[$p];
							$p++;
						}
						if($p >= $len || $selector[$p] !== '/') {
							O::fatal_error('unclosed regex literal after ' . $op);
						}
						$p++; // closing /
						$flags = '';
						while($p < $len && ctype_alpha($selector[$p])) {
							$flags .= $selector[$p];
							$p++;
						}
						if(!O::lom_regex_allowed_flags($flags)) {
							O::fatal_error('unsupported regex flags (allowed: i m s u x): ' . $flags);
						}
						$id = sizeof($this->selector_regex_table);
						$compiled = O::compile_lom_regex($pattern, $flags);
						$this->selector_regex_table[$id] = array(
							'pattern' => $pattern,
							'flags' => $flags,
							'compiled' => $compiled,
						);
						$out .= $op . '#lomregex#' . $id . '#';
						$i = $p;
						$matched_op = true;
						break;
					}
					// Operator without regex value — emit op and continue
					$out .= $op;
					$i = $after;
					$matched_op = true;
					break;
				}
			}
			if($matched_op) {
				continue;
			}
			$out .= $selector[$i];
			$i++;
		}
		return $out;
	}

	function fcache_roi_update() {
		$total = $this->fcache_hits + $this->fcache_misses;
		if($total >= 64) {
			$this->fcache_enabled = ($this->fcache_hits / $total) >= 0.05;
		}
	}

	function fmem_roi_update() {
		$total = $this->fmem_hits + $this->fmem_misses;
		if($total >= 64) {
			$this->fmem_enabled = ($this->fmem_hits / $total) >= 0.05;
		}
	}

	function clear_fmem_fcache($keep_compiled = true) {
		$this->fmem_intern = array();
		$this->fcache_regex_matches = array();
		if(!$keep_compiled) {
			$this->selector_regex_compile_cache = array();
			$this->fcache_compiled_patterns = array();
		}
	}

	function regex_match_array($text, $compiled, $need_all = true) {
		$key = $compiled . "\0" . ($need_all ? '1' : '0') . "\0" . $text;
		if($this->fcache_enabled && isset($this->fcache_regex_matches[$key])) {
			$this->fcache_hits++;
			O::fcache_roi_update();
			return $this->fcache_regex_matches[$key];
		}
		if($this->fcache_enabled) {
			$this->fcache_misses++;
		}
		$matches = array();
		if($need_all) {
			$ok = @preg_match_all($compiled, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
		} else {
			$ok = @preg_match($compiled, $text, $m, PREG_OFFSET_CAPTURE);
			if($ok) {
				$matches = array($m);
			}
		}
		if($ok === false) {
			$err = preg_last_error();
			if($err !== PREG_NO_ERROR) {
				O::fatal_error('regex match failed (preg_last_error=' . $err . ')');
			}
			$matches = array();
		}
		if($this->fcache_enabled) {
			if(sizeof($this->fcache_regex_matches) > 256) {
				$this->fcache_regex_matches = array();
			}
			$this->fcache_regex_matches[$key] = $matches;
			O::fcache_roi_update();
		}
		return $matches;
	}

	function fmem_intern_string($string) {
		if(!$this->fmem_enabled || !is_string($string) || strlen($string) < 32) {
			return $string;
		}
		if(in_array('xxh3', hash_algos(), true)) {
			$hash = hash('xxh3', $string, false);
		} else {
			$hash = hash('sha1', $string, false);
		}
		if(isset($this->fmem_intern[$hash]) && $this->fmem_intern[$hash] === $string) {
			$this->fmem_hits++;
			O::fmem_roi_update();
			return $this->fmem_intern[$hash];
		}
		$this->fmem_misses++;
		if(sizeof($this->fmem_intern) > 4096) {
			$this->fmem_intern = array();
		}
		$this->fmem_intern[$hash] = $string;
		O::fmem_roi_update();
		return $this->fmem_intern[$hash];
	}

	function compare_regex_matches($text, $op, $regex_id) {
		if(!isset($this->selector_regex_table[$regex_id])) {
			return false;
		}
		$entry = $this->selector_regex_table[$regex_id];
		$compiled = $entry['compiled'];
		$text = (string)$text;
		$text_len = strlen($text);
		if($op === '!=') {
			$matches = O::regex_match_array($text, $compiled, false);
			return sizeof($matches) === 0;
		}
		if($op === '%=') {
			$matches = O::regex_match_array($text, $compiled, false);
			return sizeof($matches) > 0;
		}
		/* ^= : prefer a single match; only scan all if the first hit is not at offset 0. */
		if($op === '^=') {
			$matches = O::regex_match_array($text, $compiled, false);
			if(sizeof($matches) === 0) {
				return false;
			}
			if($matches[0][0][1] === 0) {
				return true;
			}
			$matches = O::regex_match_array($text, $compiled, true);
			foreach($matches as $m) {
				if($m[0][1] === 0) {
					return true;
				}
			}
			return false;
		}
		$matches = O::regex_match_array($text, $compiled, true);
		if(sizeof($matches) === 0) {
			return false;
		}
		foreach($matches as $m) {
			$full = $m[0][0];
			$off = $m[0][1];
			$mlen = strlen($full);
			switch($op) {
				case '=':
					if($off === 0 && $mlen === $text_len) {
						return true;
					}
					break;
				case '$=':
					if($off + $mlen === $text_len) {
						return true;
					}
					break;
				case '~=':
					$before_ok = ($off === 0) || ctype_space($text[$off - 1]);
					$after_ok = ($off + $mlen === $text_len) || ctype_space($text[$off + $mlen]);
					if($before_ok && $after_ok) {
						return true;
					}
					break;
				case '>':
				case '<':
				case '>=':
				case '<=':
					$num_str = (isset($m[1]) && is_array($m[1])) ? $m[1][0] : $full;
					if(is_numeric($num_str)) {
						return true;
					}
					break;
			}
		}
		return false;
	}

	function compare($left, $op, $right) {
		$regex_id = O::lom_regex_token_id($right);
		if($regex_id !== false) {
			return O::compare_regex_matches($left, $op, $regex_id);
		}
		if(in_array($op, array('>','<','>=','<='), true) && is_numeric($left) && is_numeric($right)) {
			$left += 0;
			$right += 0;
		}
		switch($op) {
			case "=": return $left == $right;
			case "!=": return $left != $right;
			case "^=":
				if(strpos($left, $right) === 0) {
					return true;
				}
				foreach(preg_split('/\s+/', trim($left)) as $word) {
					if($word !== '' && strpos($word, $right) === 0) {
						return true;
					}
				}
				return false;
			case "$=":
			if($right === '' || $left === '') {
				return $left === $right;
			}
			if(substr($left, -strlen($right)) === $right) {
				return true;
			}
			foreach(preg_split('/\s+/', trim($left)) as $word) {
				if($word !== '' && substr($word, -strlen($right)) === $right) {
					return true;
				}
			}
			return false;
			case "%=": return strpos($left,$right) !== false;
			case "~=": return in_array($right,preg_split('/\s+/',$left));
			// case "|=": return ($left === $right) || (strpos($left,$right."-") === 0);
			case ">": return $left > $right;
			case "<": return $left < $right;
			case ">=": return $left >= $right;
			case "<=": return $left <= $right;
		}
		return false;
	}

	function selector_overlay_postfilter_applies($normalized_selector) {
		if(!is_string($normalized_selector) || $normalized_selector === '' || strpos($normalized_selector, '[') !== false) {
			return false;
		}
		// tag=value@attr=... is fully applied in recursive_select; a naive
		// postfilter would treat the first "=" only and wipe valid matches.
		if(preg_match('/(?:' . $this->comparison_operators_regex . ')[^@\[]*@/', $normalized_selector)) {
			return false;
		}
		O::parse_selector_string($normalized_selector);
		if(!isset($this->selector_piece_sets) || !is_array($this->selector_piece_sets)) {
			return false;
		}
		foreach($this->selector_piece_sets as $pieces) {
			if(!is_array($pieces) || sizeof($pieces) > 1) {
				return false;
			}
		}
		return true;
	}

	function comparison_postfilter($nodes, $selector) {
		// checks for comparison operators; if there are none, return as-is
		// Logical AND/OR in full selector strings are not a single comparison; preg would misparse (e.g. mike|sally).
		if(strpos($selector, '&') !== false) {
			return $nodes;
		}
		if(preg_match('/\|(?!=)/', $selector)) {
			return $nodes;
		}
		// Wildcard tag text selectors (*=, *^=, *$=, etc.) are fully handled in recursive_select; this filter would misparse * as the left-hand value.
		if($selector !== '' && $selector[0] === '*' && strpos($selector, '@') === false && strpos($selector, '.') === false && strpos($selector, '&') === false) {
			return $nodes;
		}
		if(!preg_match('/^(.*?)(' . $this->comparison_operators_regex . ')(.*)$/', $selector, $m)) {
			return $nodes;
		}
		$left = trim($m[1]);
		$op = $m[2];
		$right = trim(O::query_decode($m[3]));
		$out = array();
		foreach($nodes as $k => $node) {
			$value = null;
			// *@attr (overlay / any tag with attribute) — same as @attr; plain *@ was falling through to tagless() and broke numeric compares after scoped select().
			if(strlen($left) && $left[0] === '*' && isset($left[1]) && $left[1] === '@') {
				$attrs = O::get_tag_attributes_of_string($node[0]);
				$name = substr($left, 2);
				if(isset($attrs[$name])) {
					$value = $attrs[$name];
				} else {
					$child_txt = O::overlay_first_child_tag_text($node[0], $name);
					if($child_txt !== null) {
						$value = $child_txt;
					}
				}
			} elseif(strlen($left) && strpos($left, '@') !== false && preg_match('/^([A-Za-z0-9:\-*]+)@([A-Za-z0-9:\-]+)$/', $left, $tm)) {
				// tagname@attr e.g. person@name!=sally
				$want_tag = $tm[1];
				if($want_tag !== '*' && $this->tagname($node[0]) !== $want_tag) {
					$value = null;
				} else {
					$attrs = O::get_tag_attributes_of_string($node[0]);
					$aname = $tm[2];
					if(isset($attrs[$aname])) {
						$value = $attrs[$aname];
					} else {
						$child_txt = O::overlay_first_child_tag_text($node[0], $aname);
						if($child_txt !== null) {
							$value = $child_txt;
						}
					}
				}
			} elseif(strlen($left) && $left[0] === '@') {
				// attribute selector: @attrname
				$attrs = O::get_tag_attributes_of_string($node[0]);
				$name = substr($left, 1);
				if(isset($attrs[$name])) {
					$value = $attrs[$name];
				} else {
					$child_txt = O::overlay_first_child_tag_text($node[0], $name);
					if($child_txt !== null) {
						$value = $child_txt;
					}
				}
			} elseif(strlen($left) && $left[0] === '.') {
				// dotted path selector: .parenttag_childtag — extract last piece as child selector
				$left_inner = substr($left, 1); // strip leading dot
				$first_sep = strpos($left_inner, '_');
				$child_selector = ($first_sep !== false) ? substr($left_inner, $first_sep + 1) : $left_inner;
				// use LOM select to find the child tag within this node
				$child_matches = O::_($child_selector, $node, false, true);
				if(is_string($child_matches)) {
					$value = trim($child_matches); // _() returns scalar string for single-node context
				} elseif(is_array($child_matches) && sizeof($child_matches) > 0) {
					$value = trim(O::tagless($child_matches[0][0]));
				}
			} else {
				// plain selector: use tagless on the whole node
				$value = trim(O::tagless($node[0]));
			}
			if($value !== null && O::compare($value, $op, $right)) {
				$out[$k] = $node;
			}
		}
		return $out;
	}

	function invalidate_derived_state($recheck_tag_types = true, $rebuild_depths = true, $clear_context = true, $clear_expands = true) {
		if($recheck_tag_types) {
			O::reset_tag_types();
			O::check_tag_types($this->code);
		}
		if($rebuild_depths) {
			O::set_offset_depths();
		}
		if($clear_expands) {
			$this->expands = array();
		}
		if($clear_context) {
			$this->context = array();
			$this->invalidate_context_selector_index();
		}
		O::clear_parent_structural_indexes();
		$this->attribute_index = array();
		$this->attribute_value_index = array();
		$this->attribute_index_ready = array();
		$this->attribute_full_index_ready = false;
		O::clear_fmem_fcache(true);
		O::debug_log_event('invalidate', 'derived state refreshed', array(
			'recheck_tag_types' => $recheck_tag_types,
			'rebuild_depths' => $rebuild_depths,
			'clear_context' => $clear_context,
			'clear_expands' => $clear_expands,
			'code_bytes' => strlen($this->code),
		));
	}

	function merge_check_tag_types_from_fragment($code) {
		if(!is_string($code) || $code === '') {
			return;
		}
		if(strpos($code, '/>') !== false) {
			$this->must_check_for_self_closing = true;
		}
		if(stripos($code, '<!DOCTYPE') !== false || stripos($code, '<!doctype') !== false) {
			$this->must_check_for_doctype = true;
		}
		if(strpos($code, '<![CDATA[') !== false) {
			$this->must_check_for_non_parsed_character_data = true;
		}
		if(strpos($code, '<!--') !== false) {
			$this->must_check_for_comment = true;
		}
		if(strpos($code, '<?') !== false) {
			$this->must_check_for_programming_instruction = true;
			// Multiple PI/PHP close markers are valid in mixed-markup documents.
		}
		if(strpos($code, '<%') !== false) {
			$this->must_check_for_ASP = true;
		}
	}

	function invalidate_after_insert_write($inserted_fragment = '') {
		O::invalidate_runtime_caches_only();
		if(is_string($inserted_fragment) && $inserted_fragment !== '') {
			O::merge_check_tag_types_from_fragment($inserted_fragment);
		}
		// Depth map: new_() always reaches here after replace(), which already ran replace_offsets_and_depths(). A full set_offset_depths() would re-expand the whole document and duplicate that work.
	}

	function is_opening_tag($string) {
		if($string[0] === '<') {
			if($string[1] === '!') { // doctype or non-parsed character data or comment
				return false;
			}
			if($string[1] === '?') { // programming instruction
				return false;
			}
			if($string[1] === '%') { // ASP
				return false;
			}
			if($string[1] === '/') { // closing tag
				return false;
			}
			if($string[strlen($string) - 1] === '>') {
				if($string[strlen($string) - 2] === '/') { // self-closing tag
					return false;
				}
			} else {
				return false;
			}
			return true;
		}
		return false;
	}

	function debug_on() { // alias
		return O::debug();
	}

	function debug($on = true, $level = 2, $categories = false, $buffer_limit = false) {
		if($on === false) {
			$this->debug = false;
			$this->debug_counter = 0;
			$this->debug_log_buffer = array();
			return false;
		}
		if(is_int($on) && $on >= 0) {
			$level = $on;
			$on = true;
		}
		$this->debug = (bool)$on;
		$this->debug_counter = 0;
		$this->debug_level = (int)$level;
		if($categories === false || $categories === null) {
			$this->debug_categories = array();
		} elseif(is_string($categories)) {
			$this->debug_categories = array($categories);
		} else {
			$this->debug_categories = array_values($categories);
		}
		if(is_int($buffer_limit) && $buffer_limit > 0) {
			$this->debug_log_limit = $buffer_limit;
		}
		$this->debug_log_buffer = array();
		return true;
	}

	function debug_off() {
		$this->debug = false;
		$this->debug_counter = 0;
		$this->debug_log_buffer = array();
	}

	function debug_set_level($level) {
		$this->debug_level = max(0, (int)$level);
		return $this->debug_level;
	}

	function debug_set_categories($categories = false) {
		if($categories === false || $categories === null) {
			$this->debug_categories = array();
		} elseif(is_string($categories)) {
			$this->debug_categories = array($categories);
		} else {
			$this->debug_categories = array_values($categories);
		}
		return $this->debug_categories;
	}

	function debug_clear_log() {
		$this->debug_log_buffer = array();
		$this->debug_counter = 0;
	}

	function debug_log_event($category, $message, $context = array(), $level = 2) {
		if(!$this->debug) {
			return false;
		}
		if($level > $this->debug_level) {
			return false;
		}
		if(sizeof($this->debug_categories) > 0 && !in_array($category, $this->debug_categories, true)) {
			return false;
		}
		$this->debug_counter++;
		$this->debug_sequence++;
		$entry = array(
			'index' => $this->debug_sequence,
			'category' => $category,
			'level' => $level,
			'message' => $message,
			'context' => $context,
		);
		$this->debug_log_buffer[] = $entry;
		if(sizeof($this->debug_log_buffer) > $this->debug_log_limit) {
			$this->debug_log_buffer = array_slice($this->debug_log_buffer, -1 * $this->debug_log_limit);
		}
		return true;
	}

	function debug_tail($count = 50) {
		$count = max(1, (int)$count);
		return array_slice($this->debug_log_buffer, -1 * $count);
	}

	function debug_dump($count = false) {
		$entries = ($count === false) ? $this->debug_log_buffer : O::debug_tail($count);
		foreach($entries as $entry) {
			print('[#' . $entry['index'] . '][' . $entry['category'] . '][L' . $entry['level'] . '] ' . $entry['message']);
			if(sizeof($entry['context']) > 0) {
				print(' ');
				var_dump($entry['context']);
			} else {
				print(PHP_EOL);
			}
		}
		return $entries;
	}

	function get_number_opening_tags($code) { // alias
		return O::get_number_of_opening_tags($code);
	}

	function get_number_of_opening_tags($code) {
		return sizeof(O::get_opening_tags($code));
	}

	function get_number_closing_tags($code) { // alias
		return O::get_number_of_closing_tags($code);
	}

	function get_number_of_closing_tags($code) {
		return sizeof(O::get_closing_tags($code));
	}

	function get_opening_tags($code) {
		//print('$code at very start of get_opening_tags: ');var_dump($code);
		$offset = 0;
		$opening_tags = array();
		while($offset < strlen($code)) {
			if($code[$offset] === '<') {
				if($code[strpos($code, '>', $offset + 1) - 1] === '/') { // self-closing
					$offset += strpos($code, '>', $offset + 1) - $offset + 1;
				} elseif(substr($code, $offset, 9) === '<!DOCTYPE') { // doctype
					$offset += strpos($code, '>', $offset + 9) - $offset + 1;
				} elseif(substr($code, $offset, 9) === '<![CDATA[') { // non-parsed character data
					$offset += strpos($code, ']]>', $offset + 9) - $offset + 3;
				} elseif(substr($code, $offset, 4) === '<!--') { // comment
					$offset += strpos($code, '-->', $offset + 3) - $offset + 3;
				} elseif(substr($code, $offset, 2) === '<?') { // programming instruction
					$offset += strpos($code, '?>', $offset + 2) - $offset + 2;
				} elseif(substr($code, $offset, 2) === '<%') { // ASP
					$offset += strpos($code, '%>', $offset + 2) - $offset + 2;
				} elseif($code[$offset + 1] === '/') { // closing tag
					$offset += strpos($code, '>', $offset + 2) - $offset + 1;
				} else { // opening tag
					$after_opening_tag_position = strpos($code, '>', $offset + 1) + 1;
					$opening_tags[] = substr($code, $offset, $after_opening_tag_position - $offset);
					$offset += $after_opening_tag_position - $offset;
				}
				continue;
			}
			$offset++;
		}
		// print('$opening_tags at end of get_offset_depths: ');var_dump($opening_tags);
		return $opening_tags;
	}

	function get_closing_tags($code) {
		//print('$code at very start of get_closing_tags: ');var_dump($code);
		$offset = 0;
		$closing_tags = array();
		while($offset < strlen($code)) {
			if($code[$offset] === '<') {
				if($code[strpos($code, '>', $offset + 1) - 1] === '/') { // self-closing
					$offset += strpos($code, '>', $offset + 1) - $offset + 1;
				} elseif(substr($code, $offset, 9) === '<!DOCTYPE') { // doctype
					$offset += strpos($code, '>', $offset + 9) - $offset + 1;
				} elseif(substr($code, $offset, 9) === '<![CDATA[') { // non-parsed character data
					$offset += strpos($code, ']]>', $offset + 9) - $offset + 3;
				} elseif(substr($code, $offset, 4) === '<!--') { // comment
					$offset += strpos($code, '-->', $offset + 3) - $offset + 3;
				} elseif(substr($code, $offset, 2) === '<?') { // programming instruction
					$offset += strpos($code, '?>', $offset + 2) - $offset + 2;
				} elseif(substr($code, $offset, 2) === '<%') { // ASP
					$offset += strpos($code, '%>', $offset + 2) - $offset + 2;
				} elseif($code[$offset + 1] === '/') { // closing tag
					$after_closing_tag_position = strpos($code, '>', $offset + 2) + 1;
					$closing_tags[] = substr($code, $offset, $after_closing_tag_position - $offset);
					$offset += $after_closing_tag_position - $offset;
				} else { // opening tag
					$offset += strpos($code, '>', $offset + 1) - $offset + 1;
				}
				continue;
			}
			$offset++;
		}
		// print('$closing_tags at end of get_offset_depths: ');var_dump($closing_tags);
		return $closing_tags;
	}

	function set_offset_depths() {
		$this->offset_depths_pending = false;
		$code_bytes = strlen($this->code);
		if($code_bytes >= 8 * 1024 * 1024) {
			$this->offset_depths = LomPackedOffsetDepths::build_from_code(
				$this->code,
				$this->must_check_for_self_closing,
				$this->must_check_for_doctype,
				$this->must_check_for_non_parsed_character_data,
				$this->must_check_for_comment,
				$this->must_check_for_programming_instruction,
				$this->must_check_for_ASP
			);
		} else {
			$this->offset_depths = O::get_offset_depths(false, 0, 0, true);
		}
		return true;
		/*$depth = 0;
			*	//$this->offset_depths = array();
			*	//$this->offset_depths = array(0 => 0); // good thinking for initialization but don't do this because adjust_offsets() only expect the depths of offsets with '<' to be in the array
			*	//preg_match_all('/</', $this->code, $matches, PREG_OFFSET_CAPTURE);
			*	//foreach($matches[0] as $index => $value) {
			*	//	$this->offset_depths[$value[1]] = $depth;
			*	//	if($this->code[$value[1] + 1] === '/') { // closing tag
			*	//		$depth--;
			*	//	} else { // opening tag
			*	//		$depth++;
			*	//	}
			*	//}
			*	// string-based instead of preg code is something like ~15% faster
			*	$position = -1;
			*	while(($position = strpos($this->code, '<', $position + 1)) !== false) {
			*		//print('$position: ');var_dump($position);
			*		if($this->code[$position + 1] === '/') { // closing tag
			*			//print('closing tag at position: ' . $position . '<br />' . PHP_EOL);
			*			$depth--;
	} elseif($this->must_check_for_self_closing && $this->code[strpos($this->code, '>', $position + 1) - 1] === '/') { // self-closing tag
		//print('self-closing tag at position: ' . $position . '<br />' . PHP_EOL);
		$this->offset_depths[$position] = $depth;
	} elseif($this->must_check_for_non_parsed_character_data && substr($this->code, $position + 1, 8) === '![CDATA[') { // non-parsed character data
		//print('non-parsed character data at position: ' . $position . '<br />' . PHP_EOL);
		$this->offset_depths[$position] = $depth;
	} elseif($this->must_check_for_comment && substr($this->code, $position + 1, 3) === '!--') { // comment
		//print('comment at position: ' . $position . '<br />' . PHP_EOL);
		$this->offset_depths[$position] = $depth;
	} elseif($this->must_check_for_programming_instruction && $this->code[$position + 1] === '?') { // programming instruction
		//print('programming instruction at position: ' . $position . '<br />' . PHP_EOL);
		$this->offset_depths[$position] = $depth;
	} elseif($this->must_check_for_ASP && $this->code[$position + 1] === '%') { // ASP
		//print('ASP at position: ' . $position . '<br />' . PHP_EOL);
		$this->offset_depths[$position] = $depth;
	} else { // opening tag
		//print('opening tag at position: ' . $position . '<br />' . PHP_EOL);
		$this->offset_depths[$position] = $depth;
		$depth++;
	}
	// also mark the depth of text (in case there is any). if there's an opening angle bracket right after the closing angle bracket it'll be marked twice but nbd
	$closing_angle_bracket_position = strpos($this->code, '>', $position + 1);
	//if($this->code[$closing_angle_bracket_position + 1] === '<') {
	//	if($this->code[$closing_angle_bracket_position + 2] === '/') { // still add the zero-length text string
	//		$this->offset_depths[$closing_angle_bracket_position + 1] = $depth;
	//	}// else { // it'll be caught by code above
	//	//
	//	//}
	//} else { // it's a text string
	//	$this->offset_depths[$closing_angle_bracket_position + 1] = $depth;
	//}
	$this->offset_depths[$closing_angle_bracket_position + 1] = $depth;
	}
	//print('$this->offset_depths at the end of set_offset_depths: ');O::var_dump_full($this->offset_depths);exit(0);
	*/
	}

	function get_offset_depths_of_matches($selector_matches) {
		//O::fatal_error('get_offset_depths_of_matches is obsolete'); // good because it was not obvious that this function returned an array of offset_depths while get_offset_depths return the uncontained offset_depths
		//print('$selector_matches in get_offset_depths_of_matches: ');var_dump($selector_matches);
		$this->ensure_parent_indexes();
		$global_depths = $this->offset_depths;
		$has_global = is_array($global_depths) && sizeof($global_depths) > 0;
		$code_len = strlen($this->code);
		$offset_depths_of_matches = array();
		foreach($selector_matches as $index => $value) {
			$open = $value[1];
			if($has_global && isset($this->node_end_offsets[$open])) {
				$end = $this->node_end_offsets[$open];
				if($value[0] === $this->code && $end - $open + 1 === $code_len) {
					$offset_depths_of_matches[] = $global_depths;
				} else {
					$slice = array();
					for($k = $open; $k <= $end; $k++) {
						if(isset($global_depths[$k])) {
							$slice[$k] = $global_depths[$k];
						}
					}
					$offset_depths_of_matches[] = $slice;
				}
			} else {
				$offset_depths_of_matches[] = O::get_offset_depths($value[0], $value[1], O::depth($value[1]));
			}
		}
		return $offset_depths_of_matches;
	}

	function get_offset_depths_of_matching_array($matching_array) {
		if(sizeof($matching_array) > 1) {
			print('$matching_array: ');O::var_dump_full($matching_array);
			O::fatal_error('$matching_array > 1 in get_offset_depths_of_matching_array');
		}
		return O::get_offset_depths($matching_array[0][0], $matching_array[0][1], O::depth($matching_array[0][1]));
	}

	function offset_depths($code = false, $offset_to_add = 0, $depth_to_add = 0) { // alias
		return O::get_offset_depths($code, $offset_to_add, $depth_to_add);
	}

	function build_offset_depths_linear($code, $offset_to_add = 0, $depth_to_add = 0) {
		$offset_depths = array();
		$depth = 0;
		$len = strlen($code);
		if($len === 0) {
			return $offset_depths;
		}
		$offset = 0;
		if($code[0] !== '<') {
			$offset_depths[$offset_to_add] = $depth + $depth_to_add;
		}
		while(($offset = strpos($code, '<', $offset)) !== false) {
			$offset_depths[$offset + $offset_to_add] = $depth + $depth_to_add;
			$next = $offset + 1;
			if($next >= $len) {
				break;
			}
			$c1 = $code[$next];
			if($c1 === '/') {
				$depth--;
				$gt = strpos($code, '>', $next);
				$offset = ($gt === false) ? $len : $gt + 1;
				continue;
			}
			if($this->must_check_for_comment && $c1 === '!' && substr($code, $next, 3) === '!--') {
				$end = strpos($code, '-->', $next);
				$offset = ($end === false) ? $len : $end + 3;
				continue;
			}
			if($this->must_check_for_non_parsed_character_data && $c1 === '!' && substr($code, $next, 8) === '![CDATA[') {
				$end = strpos($code, ']]>', $next);
				$offset = ($end === false) ? $len : $end + 3;
				continue;
			}
			if($this->must_check_for_doctype && $c1 === '!' && strncasecmp(substr($code, $offset, 9), '<!DOCTYPE', 9) === 0) {
				$gt = strpos($code, '>', $next);
				$offset = ($gt === false) ? $len : $gt + 1;
				continue;
			}
			if($this->must_check_for_programming_instruction && $c1 === '?') {
				$end = strpos($code, '?>', $next);
				$offset = ($end === false) ? $len : $end + 2;
				continue;
			}
			if($this->must_check_for_ASP && $c1 === '%') {
				$end = strpos($code, '%>', $next);
				$offset = ($end === false) ? $len : $end + 2;
				continue;
			}
			$gt = strpos($code, '>', $next);
			if($gt === false) {
				break;
			}
			$self = ($gt > $next && $code[$gt - 1] === '/');
			if(!$self) {
				$depth++;
			}
			$offset = $gt + 1;
		}
		return $offset_depths;
	}

	function get_offset_depths($code = false, $offset_to_add = 0, $depth_to_add = 0, $for_writing = false) {
		if(!$for_writing && $this->offset_depths_pending && ($code === false || $code === $this->code)) {
			O::ensure_offset_depths();
			if($code === false) {
				return $this->offset_depths;
			}
		}
		if($code === false) {
			$code = $this->code;
		}
		if(is_array($code)) {
			if(sizeof($code) === 1 && is_array($code[0]) && sizeof($code[0]) === 2 && is_string($code[0][0])) {
				$code = $code[0][0];
			} else {
				print('$code in get_offset_depths: ');var_dump($code);
				O::fatal_error('get_offset_depths expects $code to be a string');
			}
		}
		if(strlen($code) === 0) {
			return array();
		}
		static $snippet_depth_cache = array();
		static $snippet_depth_cache_order = array();
		$can_use_snippet_cache = (
			is_string($code) &&
			$code !== $this->code &&
			strlen($code) <= 262144
		);
		if($can_use_snippet_cache) {
			$cache_key = md5($code);
			if(isset($snippet_depth_cache[$cache_key])) {
				$base = $snippet_depth_cache[$cache_key];
			} else {
				$base = O::build_offset_depths_linear($code, 0, 0);
				$snippet_depth_cache[$cache_key] = $base;
				$snippet_depth_cache_order[] = $cache_key;
				if(sizeof($snippet_depth_cache_order) > 128) {
					$evict = array_shift($snippet_depth_cache_order);
					unset($snippet_depth_cache[$evict]);
				}
			}
			if($offset_to_add === 0 && $depth_to_add === 0) {
				return $base;
			}
			$shifted = array();
			foreach($base as $k => $v) {
				$shifted[$k + $offset_to_add] = $v + $depth_to_add;
			}
			return $shifted;
		}
		/* Full-document and large spans: linear scan — expand() materializes huge arrays. */
		return O::build_offset_depths_linear($code, $offset_to_add, $depth_to_add);
	}

	function replace_offsets_and_depths($old_value, $new_value, $offset, $offset_adjust, $depth_of_offset = 0) {
		if($this->offset_depths instanceof LomPackedOffsetDepths) {
			$this->offset_depths = $this->offset_depths->thaw();
		}
		//function replace_offsets_and_depths($offset, $offset_adjust) {
		// could check if old_value === new_value but it's a waste since there's a condition on the only place this function is called with $this->string_operation_made_a_change
		// $this->variables do not keep their own separate offset_depths, nor does included_array
		// if($depth_of_offset == false) {
		// 	$depth_of_offset = 0;
		// }
		//print('$old_value, $new_value, $offset, $depth_of_offset at start of replace_offsets_and_depths(): ');var_dump($old_value, $new_value, $offset, $depth_of_offset);
		$old_value_offset_depths = O::get_offset_depths($old_value, $offset, $depth_of_offset);
		$new_value_offset_depths = O::get_offset_depths($new_value, $offset, $depth_of_offset, true); // parameter specifying that new values are being written
		//print('$old_value, $new_value, $offset, $offset_adjust, $depth_of_offset, $old_value_offset_depths, $new_value_offset_depths in replace_offsets_and_depths: ');var_dump($old_value, $new_value, $offset, $offset_adjust, $depth_of_offset, $old_value_offset_depths, $new_value_offset_depths);
		$this->offset_depths = O::internal_replace_offsets_and_depths($this->offset_depths, $offset, $offset_adjust, $old_value_offset_depths, $new_value_offset_depths, strlen($old_value));
		//$this->offset_depths = O::internal_replace_offsets_and_depths($this->offset_depths, $offset, $offset_adjust);
		// foreach($this->context as $context_index => $context_value) {
		// 	//print('here rep002<br />' . PHP_EOL);
		// 	if($context_value[3] === false) { // false here means use $this->offset_depths
		// 		continue;
		// 	}
		// 	foreach($this->context[$context_index][3] as $context3_index => $context3_value) {
		// 		if($context3_value === false) { // false here means use $this->offset_depths
		// 			continue;
		// 		}
		// 		//print('$this->context[$context_index][3][$context3_index] before internal_replace_offsets_and_depths(): ');var_dump($this->context[$context_index][3][$context3_index]);
		// 		$this->context[$context_index][3][$context3_index] = O::internal_replace_offsets_and_depths($this->context[$context_index][3][$context3_index], $offset, $offset_adjust, $old_value_offset_depths, $new_value_offset_depths);
		// 		//print('$this->context[$context_index][3][$context3_index] after internal_replace_offsets_and_depths(): ');var_dump($this->context[$context_index][3][$context3_index]);
		// 	}
		// }

		//print('$old_value_offset_depths: ');var_dump($old_value_offset_depths);
		//print('$this->offset_depths before replace in replace_offsets_and_depths(): ');var_dump($this->offset_depths);
		/*foreach($old_value_offset_depths as $old_value_offset => $old_value_depth) {
			*		//print('here rep001<br />' . PHP_EOL);
			*		unset($this->offset_depths[$old_value_offset]);
			*		foreach($this->context as $context_index => $context_value) {
			*			//print('here rep002<br />' . PHP_EOL);
			*			if($context_value[3] === false) { // false here means use $this->offset_depths
			*				continue;
	}
	foreach($context_value[1] as $context1_index => $context1_value) {
		if($context1_value[0] <= $offset && $context1_value[0] + $context1_value[1] > $offset) {
			foreach($this->context[$context_index][3] as $context3_index => $context3_value) {
				if($context3_value === false) { // false here means use $this->offset_depths
					continue;
	}
	unset($this->context[$context_index][3][$context3_index][$old_value_offset]);
	}
	}
	}
	}
	}
	//print('$new_value_offset_depths: ');var_dump($new_value_offset_depths);
	foreach($new_value_offset_depths as $new_value_offset => $new_value_depth) {
		//print('here rep003<br />' . PHP_EOL);
		$this->offset_depths[$new_value_offset] = $new_value_depth;
		foreach($this->context as $context_index => $context_value) {
			//print('here rep004<br />' . PHP_EOL);
			if($context_value[3] === false) { // false here means use $this->offset_depths
				continue;
	}
	foreach($context_value[1] as $context1_index => $context1_value) {
		//print('here rep005<br />' . PHP_EOL);
		if($context1_value[0] <= $offset && $context1_value[0] + $context1_value[1] > $offset) {
			//print('here rep006<br />' . PHP_EOL);
			foreach($this->context[$context_index][3] as $context3_index => $context3_value) {
				//print('here rep007<br />' . PHP_EOL);
				if($context3_value === false) { // false here means use $this->offset_depths
					continue;
	}
	$this->context[$context_index][3][$context3_index][$new_value_offset] = $new_value_depth;
	}
	ksort($this->context[$context_index][3][$context3_index]);
	}
	}
	}
	}
	ksort($this->offset_depths);*/
		//print('$this->offset_depths after replace in replace_offsets_and_depths(): ');var_dump($this->offset_depths);
		// debug
		if($this->debug) { // consider validate()
			/*$last_offset = 0;
				*		foreach($this->offset_depths as $offset => $depth) {
				*			//print($offset . ': ' . htmlentities(substr($this->code, $last_offset, $offset - $last_offset)) . '<br />' . PHP_EOL);
				*			if($this->code[$offset] !== '<' && $this->code[$offset - 1] !== '>') {
				*				print('$this->code, $this->offset_depths, $offset, $depth: ');O::var_dump_full($this->code, $this->offset_depths, $offset, $depth);
				*				O::fatal_error('$this->offset_depths (in replace_offsets_and_depths) should never point to anything other than &lt; or text right after &gt;');
		}
		$last_offset = $offset;
		}*/
		}
		//print('$this->offset_depths after adjustment in replace_offsets_and_depths: ');O::var_dump_full($this->offset_depths);
		return true;
	}

	function replace_offsets_and_depths_text_only($offset, $old_len, $new_len, $depth_of_offset = 0) {
		$offset = (int)$offset;
		$old_len = (int)$old_len;
		$new_len = (int)$new_len;
		$offset_adjust = $new_len - $old_len;
		$old_value_offset_depths = ($old_len > 0) ? array($offset => $depth_of_offset) : array();
		$new_value_offset_depths = ($new_len > 0) ? array($offset => $depth_of_offset) : array();
		$this->offset_depths = O::internal_replace_offsets_and_depths(
			$this->offset_depths,
			$offset,
			$offset_adjust,
			$old_value_offset_depths,
			$new_value_offset_depths,
			$old_len
		);
		return true;
	}

	private function internal_replace_offsets_and_depths($offset_depths, $offset, $offset_adjust, $old_value_offset_depths, $new_value_offset_depths, $old_value_byte_length = 0) {
		/*end($offset_depths);
			*	//print('key($offset_depths) after end: ');var_dump(key($offset_depths));
			*	$prev_result = true;
			*	while($prev_result !== false) {
			*		//print('$prev_result: ');var_dump($prev_result);
			*		if(key($offset_depths) >= $offset) {
			*			//print('her278487<br />' . PHP_EOL);
			*			if(key($offset_depths) + $offset_adjust >= $offset) {
			*				$offset_depths[key($offset_depths) + $offset_adjust] = current($offset_depths);
	} // delete offsets when offset_adjust is negative and they are within its magnitude
	// may leave artifacts from deleting or changing values in the offset_depths array but this seems insurmoutable with how LOM is currently coded... MAAAAYBE version 0.9 ;p
	unset($offset_depths[key($offset_depths)]);
	} else {
		//print('her278488<br />' . PHP_EOL);
		break;
	}
	//print('her278489<br />' . PHP_EOL);
	$prev_result = prev($offset_depths);
	//print('$prev_result2: ');var_dump($prev_result);
	}
	ksort($offset_depths);*/
		//print('$offset, $offset_adjust, $old_value_offset_depths, $new_value_offset_depths in internal_replace_offsets_and_depths: ');var_dump($offset, $offset_adjust, $old_value_offset_depths, $new_value_offset_depths);
		$shift_from = $offset + $old_value_byte_length; // first old-document index after the replaced byte range; for insert (length 0) this equals $offset so the displaced byte at $offset shifts
		foreach($old_value_offset_depths as $old_value_offset => $old_value_depth) {
			unset($offset_depths[$old_value_offset]);
		}
		if(sizeof($offset_depths) > 0) {
			if($this->offsets_need_adjusting) {
				if($offset_adjust > 0) { // very important to go in reverse order
					end($offset_depths);
					//print('key($offset_depths) after end: ');var_dump(key($offset_depths));
					$prev_result = true;
					while($prev_result !== false) {
						//print('$prev_result: ');var_dump($prev_result);
						//if(key($offset_depths) >= $offset) {
						if(key($offset_depths) >= $shift_from) { // keys from the first post-replace position onward move by $offset_adjust
							$offset_depths[key($offset_depths) + $offset_adjust] = current($offset_depths);
							//print('her278487<br />' . PHP_EOL);
							//if(key($offset_depths) + $offset_adjust === $offset) { // keep if it's not text right after text as a result of deleting intervening offsets
							//	if($code[key($offset_depths) + $offset_adjust] === '<' || $code[key($offset_depths) + $offset_adjust - 1] === '>') {
							//		$offset_depths[key($offset_depths) + $offset_adjust] = current($offset_depths);
							//	}
							//} elseif(key($offset_depths) + $offset_adjust >= $offset) {
							//	$offset_depths[key($offset_depths) + $offset_adjust] = current($offset_depths);
							//} // delete offsets when offset_adjust is negative and they are within its magnitude
							// may leave artifacts from deleting or changing values in the offset_depths array but this seems insurmoutable with how LOM is currently coded... MAAAAYBE version 0.9 ;p
							unset($offset_depths[key($offset_depths)]);
						} else {
							//print('her278488<br />' . PHP_EOL);
							break;
						}
						//print('her278489<br />' . PHP_EOL);
						$prev_result = prev($offset_depths);
						//print('$prev_result2: ');var_dump($prev_result);
					}
				} elseif($offset_adjust < 0) { // very important to go in forward order
					reset($offset_depths);
					$new_offset_depths = array(); // going forward is apparently quite a bit more difficult than going backwards (because adding elements causes them to be caught later if uncarefully iterating and trying to change values while doing so)!
					$next_result = true;
					while($next_result !== false) {
						if(key($offset_depths) < $shift_from) {
							$new_offset_depths[key($offset_depths)] = current($offset_depths);
						} else { // keys from $shift_from onward shift by $offset_adjust
							$new_offset_depths[key($offset_depths) + $offset_adjust] = current($offset_depths);
							//unset($offset_depths[key($offset_depths)]);
						}
						$next_result = next($offset_depths);
					}
					//$offset_depths = array_merge($offset_depths, $new_offset_depths);
					$offset_depths = $new_offset_depths;
				}
			}
		}
		//print('iro00010<br />' . PHP_EOL);
		foreach($new_value_offset_depths as $new_value_offset => $new_value_depth) {
			//print('iro00011<br />' . PHP_EOL);
			$offset_depths[$new_value_offset] = $new_value_depth;
		}
		ksort($offset_depths);
		//if(sizeof($initial_offset_depths) !== sizeof($offset_depths)) { // debug
		//	print('$initial_offset_depths, $offset_depths: ');var_dump($initial_offset_depths, $offset_depths);
		//	O::fatal_error('some error in internal_replace_offsets_and_depths; sizes of offset_depths before and after are different...');
		//}
		return $offset_depths;
	}

	function adjust_offsets($offset, $offset_adjust, $included_array = false) {
		if($offset_adjust == 0) {
			return true;
		}
		//print('$offset, $offset_adjust in adjust_offsets: ');var_dump($offset, $offset_adjust);
		//print('$this->offset_depths before adjustment in adjust_offsets: ');O::var_dump_full($this->offset_depths);
		//if($offset_adjust > 0) {
		//print('key($this->offset_depths) before end: ');var_dump(key($this->offset_depths));
		//	$this->offset_depths = O::internal_replace_offsets_and_depths($this->offset_depths, $offset, $offset_adjust);
		//	if($this->debug) { // notice that this has the potential to create different results since it's resetting the pointer
		//		//end($this->offset_depths);
		//		//if(current($this->offset_depths) > 1) {
		//		//	print('$this->offset_depths: ');var_dump($this->offset_depths);
		//		//	O::fatal_error('last entry of $this->offset_depths > 1 in adjust_offsets');
		//		//}
		//		reset($this->offset_depths);
		//		if(sizeof($this->offset_depths) > 0 && current($this->offset_depths) !== 0) {
		//			print('$this->offset_depths: ');var_dump($this->offset_depths);
		//			O::fatal_error('first entry of $this->offset_depths !== 0 in adjust_offsets');
		//		}
		//	}
		foreach($this->context as $context_index => $context_value) {
			//print('$this->context[$context_index] before adjusting offsets: ');var_dump($this->context[$context_index]);
			//if($context_value[3] === false) { // false here means use $this->offset_depths
			//	continue;
			//}
			if($context_value[1] !== false) {
				foreach($context_value[1] as $context1_index => $context1_value) {
					//if($context1_value[0] <= $offset && $context1_value[0] + $context1_value[1] > $offset) {
					//	$this->context[$context_index][1][$context1_index][1] += $offset_adjust;
					//} elseif($context1_value[0] >= $offset) {
					//	$this->context[$context_index][1][$context1_index][0] += $offset_adjust;
					//}
					// if($context1_value[1] >= $offset) {
					// 	$this->context[$context_index][1][$context1_index][1] += $offset_adjust;
					// }
					if($context1_value[0] >= $offset) {
						$this->context[$context_index][1][$context1_index][0] += $offset_adjust;
					}
					if($offset >= $context1_value[0] && $offset <= $context1_value[0] + $context1_value[1]) {
						$this->context[$context_index][1][$context1_index][1] += $offset_adjust;
					}
					// should context entries be cleaned based on the matching array? it is a difficult question. it comes down to whether we want to keep a context entry for the case that a matching array is deleted and then readded
					// which is difficult to answer
				}
			}
			if(is_array($context_value[2])) foreach($context_value[2] as $context2_index => $context2_value) {
				if($context2_value[0] >= $offset) {
					$this->context[$context_index][2][$context2_index][0] += $offset_adjust;
				}
				if($offset >= $context2_value[0] && $offset <= $context2_value[0] + $context2_value[1]) {
					$this->context[$context_index][2][$context2_index][1] += $offset_adjust;
				}
				if($this->context[$context_index][2][$context2_index][1] === 0) { // clean up the context
					unset($this->context[$context_index][1][$context2_index]);
					unset($this->context[$context_index][2][$context2_index]);
					//	unset($this->context[$context_index][3][$context2_index]);
					if(sizeof($this->context[$context_index][2]) === 0) {
						unset($this->context[$context_index]);
						break;
					}
				}
			}
			//	foreach($this->context[$context_index][3] as $context3_index => $context3_value) {
			//		if($context3_value === false) { // false here means use $this->offset_depths
			//			continue;
			//		}
			//		$this->context[$context_index][3][$context3_index] = O::internal_replace_offsets_and_depths($this->context[$context_index][3][$context3_index], $offset, $offset_adjust);
			/*$depth = end($this->context[$context_index][3][$context3_index]);
				*				if($offset > key($this->context[$context_index][3][$context3_index])) { // this context entry is unaffected
				*					continue 2; // assumes that the context3 entries are in offset order
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
			// 				$context_depth_array = $this->context[$context_index][3][$context3_index];
			// 				end($context_depth_array);
			// 				if($offset > key($context_depth_array)) { // this context entry is unaffected
			// 					continue 2; // assumes that the context3 entries are in offset order
			// 				}
			// 				//$new_context_depth_array = array();
			// 				$prev_result = true;
			// 				while($prev_result !== false) {
			// 					if(key($context_depth_array) >= $offset) {
			// 						//$context_depth_array[key($context_depth_array) + $offset_adjust] = current($context_depth_array);
			// 						if(key($context_depth_array) + $offset_adjust >= $offset) {
			// 							$context_depth_array[key($context_depth_array) + $offset_adjust] = current($context_depth_array);
			// 						} // delete offsets when offset_adjust is negative and they are within its magnitude
			// 						// may leave artifacts from deleting or changing values in the offset_depths array but this seems insurmoutable with how LOM is currently coded... MAAAAYBE version 0.9 ;p
			// 						unset($context_depth_array[key($context_depth_array)]);
			// 					} else {
			// 						//$context_depth_array[key($context_depth_array)] = current($context_depth_array);
			// 						break;
			// 					}
			// 					$prev_result = prev($context_depth_array);
			// 				}
			// 				ksort($context_depth_array); // since we are going in reverse order, and partially rewriting values on the fly
			// 				$this->context[$context_index][3][$context3_index] = $context_depth_array;
			//	}
			//print('$this->context[$context_index] after adjusting offsets: ');var_dump($this->context[$context_index]);
		}
		/*} else {
			*		foreach($this->offset_depths as $depth_offset => $depth) {
			*			if($depth_offset >= $offset) {
			*				$this->offset_depths[$depth_offset + $offset_adjust] = $depth;
			*				unset($this->offset_depths[$depth_offset]);
	}
	}
	foreach($this->context as $context_index => $context_value) {
		$depth = end($this->context[$context_index][3]);
		if($offset > key($this->context[$context_index][3])) { // this context entry is unaffected
			continue;
	}
	}
	}*/
		foreach($this->variables as $variable_index => $variable_value) {
			if(is_array($this->variables[$variable_index])) {
				if(is_array($this->variables[$variable_index][0])) {
					foreach($this->variables[$variable_index] as $index => $value) {
						if($this->variables[$variable_index][$index][1] >= $offset) {
							$this->variables[$variable_index][$index][1] += $offset_adjust;
						}
					}
				} else {
					if($this->variables[$variable_index][1] >= $offset) {
						$this->variables[$variable_index][1] += $offset_adjust;
					}
				}
			}
		}
		if(is_array($included_array)) {
			//print('here4569703<br />' . PHP_EOL);
			if(is_array($included_array[0])) {
				//print('here4569704<br />' . PHP_EOL);
				foreach($included_array as $index => $value) {
					//print('here4569705<br />' . PHP_EOL);
					//print('here4569707<br />' . PHP_EOL);
					if($included_array[$index][1] >= $offset) {
						//print('here4569708<br />' . PHP_EOL);
						$included_array[$index][1] += $offset_adjust;
					}
				}
			} else {
				//print('here4569709<br />' . PHP_EOL);
				//foreach($included_array as $index => $value) {
				//	$included_array[$index] = $new_value;
				//}
				if($included_array[1] >= $offset) {
					//print('here4569711<br />' . PHP_EOL);
					$included_array[1] += $offset_adjust;
				}
			}
		}
		//	ksort($this->offset_depths);
		// debug
		if($this->debug) { // consider validate()
			/*$last_offset = 0;
				*		foreach($this->offset_depths as $offset => $depth) {
				*			//print($offset . ': ' . htmlentities(substr($this->code, $last_offset, $offset - $last_offset)) . '<br />' . PHP_EOL);
				*			if($this->code[$offset] !== '<' && $this->code[$offset - 1] !== '>') {
				*				print('$this->code, $this->offset_depths, $offset, $depth: ');O::var_dump_full($this->code, $this->offset_depths, $offset, $depth);
				*				O::fatal_error('$this->offset_depths (in adjust_offsets) should never point to anything other than &lt; or text right after &gt;');
		}
		$last_offset = $offset;
		}*/
		}
		//print('$this->offset_depths after adjustment in adjust_offsets: ');O::var_dump_full($this->offset_depths);
		return $included_array;
	}

	//function add_to_context($normalized_selector, $matching_array_context_array, $selector_matches_context_array, $offset_depths_of_selector_matches) {
	function add_to_context($normalized_selector, $matching_array_context_array, $selector_matches_context_array) {
		$profile_token = $this->profile_function_start('add_to_context');
		//print('$normalized_selector, $matching_array_context_array, $selector_matches_context_array, $offset_depths_of_selector_matches in add_to_context: ');var_dump($normalized_selector, $matching_array_context_array, $selector_matches_context_array, $offset_depths_of_selector_matches);
		if($this->debug) {
			//if($this->code[$matching_array_context_array[0][0]] !== '<') { // you can have text!
			//	print('$this->code[$matching_array_context_array[0][1]], $matching_array_context_array: ');var_dump($this->code[$matching_array_context_array[0][1]], $matching_array_context_array);
			//	O::information('matching array was misaligned with code');
			//	return false;
			//}
			//if($this->code[$selector_matches_context_array[0][0]] !== '<') { // you can have text!
			//	print('$this->code[$selector_matches_context_array[0][1]], $selector_matches_context_array: ');var_dump($this->code[$selector_matches_context_array[0][1]], $selector_matches_context_array);
			//	O::information('selector was misaligned with code');
			//	return false;
			//}
			// foreach($offset_depths_of_selector_matches as $offset_depths_of_selector_match) {
			// 	foreach($offset_depths_of_selector_match as $offset => $depth) {
			// 		if(!isset($this->offset_depths[$offset])) {
			// 			print('$offset_depths_of_selector_matches, $this->offset_depths: ');var_dump($offset_depths_of_selector_matches, $this->offset_depths);
			// 			O::information('$offset_depths_of_selector_matches was misaligned with $this->offset_depths');
			// 			return false;
			// 		}
			// 	}
			// }
			if(sizeof($this->context) > 50) {
				O::debug_log_event('context', 'context size warning', array('context_entries' => sizeof($this->context), 'selector' => $normalized_selector), 1);
			}
		}
		// do not preserve duplicates, but if it's already there, then it ends up bumped to the bottom
		//$new_context_array_entry = array($normalized_selector, $matching_array_context_array, $selector_matches_context_array, $offset_depths_of_selector_matches);
		$new_context_array_entry = array($normalized_selector, $matching_array_context_array, $selector_matches_context_array);
		if(sizeof($this->context) === 0) {
			$this->context = array($new_context_array_entry);
			$this->invalidate_context_selector_index();
			$this->profile_function_stop('add_to_context', $profile_token);
			return true;
		}
		$serialized_new_context_array_entry = serialize($new_context_array_entry);
		$new_context_array = array();
		foreach($this->context as $context_array_entry) {
			if(!isset($context_array_entry[0]) || $context_array_entry[0] !== $normalized_selector) {
				$new_context_array[] = $context_array_entry;
				continue;
			}
			if($serialized_new_context_array_entry === serialize($context_array_entry)) {
				// duplicate
			} else {
				$new_context_array[] = $context_array_entry;
			}
		}
		$new_context_array[] = $new_context_array_entry;
		$this->context = $new_context_array;
		$this->invalidate_context_selector_index();
		$this->profile_function_stop('add_to_context', $profile_token);
		return true;
	}

	private function rebuild_context_selector_index() {
		$this->context_selector_index = array();
		$this->context_exact_false_scope_index = array();
		$this->context_exact_false_scope_lom_cache = array();
		$context_size = sizeof($this->context);
		for($i = 0; $i < $context_size; $i++) {
			if(!isset($this->context[$i]) || !is_array($this->context[$i]) || !isset($this->context[$i][0])) {
				continue;
			}
			$selector_key = $this->context[$i][0];
			if(!is_string($selector_key) || $selector_key === '') {
				continue;
			}
			if(!isset($this->context_selector_index[$selector_key])) {
				$this->context_selector_index[$selector_key] = array();
			}
			$this->context_selector_index[$selector_key][] = $i;
			if(isset($this->context[$i][1]) && $this->context[$i][1] === false) {
				$this->context_exact_false_scope_index[$selector_key] = $i;
			}
		}
		$this->context_selector_index_size = $context_size;
	}

	private function invalidate_context_selector_index() {
		$this->context_selector_index = array();
		$this->context_selector_index_size = -1;
		$this->context_exact_false_scope_index = array();
		$this->context_exact_false_scope_lom_cache = array();
	}

	private function append_context_entry($context_entry) {
		$this->context[] = $context_entry;
		$new_size = sizeof($this->context);
		if($this->context_selector_index_size === ($new_size - 1) && is_array($context_entry) && isset($context_entry[0]) && is_string($context_entry[0]) && $context_entry[0] !== '') {
			if(!isset($this->context_selector_index[$context_entry[0]])) {
				$this->context_selector_index[$context_entry[0]] = array();
			}
			$this->context_selector_index[$context_entry[0]][] = $new_size - 1;
			if(isset($context_entry[1]) && $context_entry[1] === false) {
				$this->context_exact_false_scope_index[$context_entry[0]] = $new_size - 1;
				unset($this->context_exact_false_scope_lom_cache[$context_entry[0]]);
			}
			$this->context_selector_index_size = $new_size;
			return;
		}
		$this->context_selector_index_size = -1;
	}

	private function context_array_signature($context_array) {
		if(!is_array($context_array) || sizeof($context_array) === 0) {
			return 'e';
		}
		if(!isset($context_array[0]) || !is_array($context_array[0])) {
			if(isset($context_array[0]) && isset($context_array[1])) {
				return 's|' . $context_array[0] . ',' . $context_array[1];
			}
			return 'u';
		}
		$n = sizeof($context_array);
		$first = $context_array[0];
		$last = $context_array[$n - 1];
		$f0 = isset($first[0]) ? $first[0] : '';
		$f1 = isset($first[1]) ? $first[1] : '';
		$l0 = isset($last[0]) ? $last[0] : '';
		$l1 = isset($last[1]) ? $last[1] : '';
		return $n . '|' . $f0 . ',' . $f1 . '|' . $l0 . ',' . $l1;
	}

	private function get_exact_false_scope_lom_cached($normalized_selector, $context_index) {
		if(!isset($this->context[$context_index])) {
			return false;
		}
		if(!isset($this->context[$context_index][2])) {
			return false;
		}
		$context_pair_array = $this->context[$context_index][2];
		$sig = $this->context_array_signature($context_pair_array);
		if(isset($this->context_exact_false_scope_lom_cache[$normalized_selector])) {
			$cached = $this->context_exact_false_scope_lom_cache[$normalized_selector];
			if(
				is_array($cached) &&
				isset($cached['i']) && $cached['i'] === $context_index &&
				isset($cached['s']) && $cached['s'] === $sig &&
				isset($cached['v']) && is_array($cached['v'])
			) {
				return $cached['v'];
			}
		}
		$lom = O::LOM_array($context_pair_array);
		$this->context_exact_false_scope_lom_cache[$normalized_selector] = array('i' => $context_index, 's' => $sig, 'v' => $lom);
		if(sizeof($this->context_exact_false_scope_lom_cache) > 64) {
			array_shift($this->context_exact_false_scope_lom_cache);
		}
		return $lom;
	}

	function context_array($LOM_array = false) {
		// a context_array is a offset-length pair
		/*
			*	context structure
			*	0 => selector
			*	1 => parent
			*	2 => matches array (offset-length pairs)
			*	3 => matches offset depths -- obsolete; these are now in expands()
			*/
		if($LOM_array === false || $LOM_array === NULL || $LOM_array === '' || (is_array($LOM_array) && sizeof($LOM_array) === 0)) {
			return false;
		}
		if(!is_array($LOM_array)) {
			print('$LOM_array, $this->context:');var_dump($LOM_array, $this->context);
			O::fatal_error('function context_array does not handle non-arrays yet. did you throw the wrong variable into a query somewhere?');
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
		// a LOM_array is a string-offset pair
		if($context_array === false || $context_array === NULL || (is_array($context_array) && sizeof($context_array) === 0)) {
			return array();
		}
		if(!is_array($context_array)) {
			print('$context_array: ');var_dump($context_array);
			O::fatal_error('function LOM_array does not handle non-arrays yet.');
		} elseif(!is_array($context_array[0])) {
			//print('$context_array: ');var_dump($context_array);
			//O::fatal_error('function LOM_array does not handle single string-offset pairs yet.');
			return array(array(substr($this->code, $context_array[0], $context_array[1]), $context_array[0]));
		}
		$LOM_array = array();
		foreach($context_array as $index => $value) {
			//$LOM_array[] = array(substr($this->code, $value[0], $value[1] - $value[0]), $value[0]);
			$LOM_array[] = array(substr($this->code, $value[0], $value[1]), $value[0]);
		}
		/* if($this->debug) {
			*		foreach($LOM_array as $index => $value) {
			*			$string = $LOM_array[$index][0];
			*			if($string[0] !== '<') {
			*				print('$LOM_array: ');var_dump($LOM_array);
			*				O::fatal_error('string not opened properly in LOM_array');
	}
	if($string[strlen($string) - 1] !== '>') {
		print('$LOM_array: ');var_dump($LOM_array);
		O::fatal_error('string not closed properly in LOM_array');
	}
	}
	} */
		return $LOM_array;
	}

	function delete_context() {
		$this->context = array();
		$this->invalidate_context_selector_index();
		//O::set_offset_depths();
	}

	function reset_context() {
		$this->context = array();
		$this->invalidate_context_selector_index();
		//O::set_offset_depths();
	}

	function context_reset() {
		$this->context = array();
		$this->invalidate_context_selector_index();
		//O::set_offset_depths();
	}

	function new_context() {
		$this->context = array();
		$this->invalidate_context_selector_index();
		//O::set_offset_depths();
	}

	function get_tagged_parent($selector, $matching_array = false, $add_to_context = true, $ignore_context = false, $parent_node_only = false) {
		$profile_token = $this->profile_function_start('get_tagged_parent');
		try {
			if($this->native_doc && $matching_array === false && O::native_selector_supported($selector) && function_exists('lom_doc_get_tagged_parent')) {
				$r = lom_doc_get_tagged_parent($this->native_doc, $selector);
				if(is_array($r)) {
					return $r;
				}
			}
			return O::get_parent($selector, $matching_array, $add_to_context, $ignore_context, $parent_node_only, true);
		} finally {
			$this->profile_function_stop('get_tagged_parent', $profile_token);
		}
	}

	function _parent($selector, $matching_array = false, $add_to_context = true, $ignore_context = false, $parent_node_only = false, $tagged_result = false) { // alias
		return O::get_parent($selector, $matching_array, $add_to_context, $ignore_context, $parent_node_only, $tagged_result);
	}

	function g_p($selector, $matching_array = false, $add_to_context = true, $ignore_context = false, $parent_node_only = false, $tagged_result = false) { // alias
		return O::get_parent($selector, $matching_array, $add_to_context, $ignore_context, $parent_node_only, $tagged_result);
	}

	function _p($selector, $matching_array = false, $add_to_context = true, $ignore_context = false, $parent_node_only = false, $tagged_result = false) { // alias
		return O::get_parent($selector, $matching_array, $add_to_context, $ignore_context, $parent_node_only, $tagged_result);
	}

	function ensure_parent_indexes() {
		if($this->parent_indexes_ready) {
			return true;
		}
		/* Building PHP hash maps for every open on 100MB+ docs can take multi-GB.
		 * Skip unless LOM_PHP_HEAVY_INDEX=1; callers fall back (prefer liblom for large files). */
		$heavy = getenv('LOM_PHP_HEAVY_INDEX');
		$heavy_on = ($heavy !== false && $heavy !== '' && $heavy !== '0');
		if(!$heavy_on && strlen($this->code) >= 8 * 1024 * 1024) {
			return false;
		}
		O::build_parent_indexes();
		return true;
	}

	/** Tag-name → offsets (+ packed open/end). $want_tag limits tag_index RAM. */
	function ensure_tag_index_slim($want_tag = false) {
		if($this->parent_indexes_ready) {
			return true;
		}
		if(!empty($this->tag_index_slim_ready) && ($want_tag === false || isset($this->tag_index[$want_tag]))) {
			return true;
		}
		$this->tag_index = array();
		$code = $this->code;
		$len = strlen($code);
		$offset = 0;
		$stack = array();
		$cap = (int)max(1024, intdiv($len, 24));
		$opens = new SplFixedArray($cap);
		$ends = new SplFixedArray($cap);
		$n = 0;
		while($offset < $len) {
			$lt = strpos($code, '<', $offset);
			if($lt === false) {
				break;
			}
			$next = $lt + 1;
			if($next >= $len) {
				break;
			}
			$c1 = $code[$next];
			if($c1 === '!' || $c1 === '?' || $c1 === '%') {
				$gt = strpos($code, '>', $next);
				$offset = ($gt === false) ? $len : $gt + 1;
				continue;
			}
			$gt = O::find_tag_close_offset($code, $lt);
			if($gt === false) {
				break;
			}
			if($c1 === '/') {
				if(sizeof($stack) > 0) {
					$idx = array_pop($stack);
					$ends[$idx] = $gt;
				}
				$offset = $gt + 1;
				continue;
			}
			$tagname = O::extract_tagname_at($code, $lt, $gt);
			$self = ($gt > $lt && $code[$gt - 1] === '/');
			if($n >= $cap) {
				$cap = (int)($cap * 3 / 2) + 64;
				$opens->setSize($cap);
				$ends->setSize($cap);
			}
			$idx = $n;
			$opens[$n] = $lt;
			$ends[$n] = $self ? $gt : -1;
			$n++;
			if($tagname !== false && ($want_tag === false || $tagname === $want_tag)) {
				if(!isset($this->tag_index[$tagname])) {
					$this->tag_index[$tagname] = array();
				}
				$this->tag_index[$tagname][] = $lt;
			}
			if(!$self) {
				$stack[] = $idx;
			}
			$offset = $gt + 1;
		}
		while(sizeof($stack) > 0) {
			$idx = array_pop($stack);
			$ends[$idx] = $len - 1;
		}
		$opens->setSize($n);
		$ends->setSize($n);
		$this->slim_open_offsets = $opens;
		$this->slim_node_ends = $ends;
		$this->tag_index_slim_ready = true;
		return true;
	}

	function slim_node_slice($offset) {
		$lo = 0;
		$hi = count($this->slim_open_offsets);
		while($lo < $hi) {
			$mid = ($lo + $hi) >> 1;
			$v = $this->slim_open_offsets[$mid];
			if($v < $offset) {
				$lo = $mid + 1;
			} elseif($v > $offset) {
				$hi = $mid;
			} else {
				$end = $this->slim_node_ends[$mid];
				return substr($this->code, $offset, $end - $offset + 1);
			}
		}
		return '';
	}

	private function ensure_parent_children_index() {
		if(!O::ensure_parent_indexes()) {
			return false;
		}
		if($this->parent_children_index_ready && $this->parent_children_index_size === $this->opening_tag_offsets_count) {
			return true;
		}
		$this->parent_children_index = array();
		$root_key = 'root';
		$this->parent_children_index[$root_key] = array();
		foreach($this->opening_tag_offsets as $offset) {
			$parent = isset($this->parent_offsets[$offset]) ? $this->parent_offsets[$offset] : false;
			$key = ($parent === false) ? $root_key : ('o:' . $parent);
			if(!isset($this->parent_children_index[$key])) {
				$this->parent_children_index[$key] = array();
			}
			$this->parent_children_index[$key][] = $offset;
		}
		$this->parent_children_index_ready = true;
		$this->parent_children_index_size = $this->opening_tag_offsets_count;
		return true;
	}

	function clear_parent_structural_indexes() {
		$this->parent_indexes_ready = false;
		$this->self_closing_open_count_in_index = 0;
		$this->opening_tag_offsets = array();
		$this->opening_tag_offsets_count = 0;
		$this->parent_offsets = array();
		$this->parent_children_index = array();
		$this->parent_children_index_ready = false;
		$this->parent_children_index_size = -1;
		$this->node_end_offsets = array();
		$this->tag_end_offsets = array();
		$this->parent_result_cache = array();
		$this->parent_query_cache = array();
		$this->opening_tag_names = array();
		$this->tag_index = array();
	}

	function recompute_self_closing_open_count() {
		$this->self_closing_open_count_in_index = 0;
		$code = $this->code;
		foreach($this->opening_tag_offsets as $open_off) {
			if(!isset($this->tag_end_offsets[$open_off])) {
				continue;
			}
			if(O::tag_is_self_closing_at($code, $open_off, $this->tag_end_offsets[$open_off])) {
				$this->self_closing_open_count_in_index++;
			}
		}
	}

	private function merge_three_sorted_numeric($a, $b, $c) {
		$na = sizeof($a);
		$nb = sizeof($b);
		$nc = sizeof($c);
		$i = 0;
		$j = 0;
		$k = 0;
		$out = array();
		while($i < $na || $j < $nb || $k < $nc) {
			$va = ($i < $na) ? $a[$i] : PHP_INT_MAX;
			$vb = ($j < $nb) ? $b[$j] : PHP_INT_MAX;
			$vc = ($k < $nc) ? $c[$k] : PHP_INT_MAX;
			$m = $va;
			if($vb < $m) {
				$m = $vb;
			}
			if($vc < $m) {
				$m = $vc;
			}
			if($m === PHP_INT_MAX) {
				break;
			}
			$out[] = $m;
			if($va === $m) {
				$i++;
			} elseif($vb === $m) {
				$j++;
			} else {
				$k++;
			}
		}
		return $out;
	}

	private function merge_two_sorted_numeric($a, $b) {
		$na = sizeof($a);
		$nb = sizeof($b);
		$i = 0;
		$j = 0;
		$out = array();
		while($i < $na || $j < $nb) {
			$va = ($i < $na) ? $a[$i] : PHP_INT_MAX;
			$vb = ($j < $nb) ? $b[$j] : PHP_INT_MAX;
			if($va <= $vb) {
				$out[] = $va;
				$i++;
			} else {
				$out[] = $vb;
				$j++;
			}
		}
		return $out;
	}

	private function tag_index_remove_opening_offset($offset, $tagname) {
		if($tagname === false || $tagname === '' || !isset($this->tag_index[$tagname])) {
			return;
		}
		$filtered = array();
		foreach($this->tag_index[$tagname] as $o) {
			if($o !== $offset) {
				$filtered[] = $o;
			}
		}
		if(sizeof($filtered) === 0) {
			unset($this->tag_index[$tagname]);
		} else {
			$this->tag_index[$tagname] = $filtered;
		}
	}

	private function tag_index_remove_opening_offsets_map($tagname, $offset_map) {
		if($tagname === false || $tagname === '' || !isset($this->tag_index[$tagname]) || !is_array($offset_map) || sizeof($offset_map) === 0) {
			return;
		}
		$filtered = array();
		foreach($this->tag_index[$tagname] as $o) {
			if(!isset($offset_map[$o])) {
				$filtered[] = $o;
			}
		}
		if(sizeof($filtered) === 0) {
			unset($this->tag_index[$tagname]);
		} else {
			$this->tag_index[$tagname] = $filtered;
		}
	}

	private function tag_index_shift_suffix_offsets($shift_from, $adjust) {
		if($adjust === 0) {
			return;
		}
		foreach($this->tag_index as $tagname => $offsets) {
			$next = array();
			foreach($offsets as $o) {
				if($o >= $shift_from) {
					$next[] = $o + $adjust;
				} else {
					$next[] = $o;
				}
			}
			if($adjust < 0) {
				sort($next, SORT_NUMERIC);
			}
			$this->tag_index[$tagname] = $next;
		}
	}

	private function shift_attribute_indexes_suffix_offsets($shift_from, $adjust) {
		if($adjust === 0) {
			return true;
		}
		if(sizeof($this->attribute_index) === 0 && sizeof($this->attribute_value_index) === 0) {
			return true;
		}
		$next_attribute_index = array();
		foreach($this->attribute_index as $attribute_name => $offset_map) {
			if($adjust > 0) {
				$pre = array();
				$suf = array();
				foreach($offset_map as $offset_key => $present) {
					$offset = (int)$offset_key;
					if($offset >= $shift_from) {
						$suf[$offset + $adjust] = true;
					} else {
						$pre[$offset] = true;
					}
				}
				$next_attribute_index[$attribute_name] = $pre + $suf;
			} else {
				$next_map = array();
				foreach($offset_map as $offset_key => $present) {
					$offset = (int)$offset_key;
					$new_offset = ($offset >= $shift_from) ? ($offset + $adjust) : $offset;
					if($new_offset < 0) {
						return false;
					}
					$next_map[$new_offset] = true;
				}
				$next_attribute_index[$attribute_name] = $next_map;
			}
		}
		$next_attribute_value_index = array();
		foreach($this->attribute_value_index as $attribute_name => $value_map) {
			$next_value_map = array();
			foreach($value_map as $attribute_value => $offset_map) {
				if($adjust > 0) {
					$pre = array();
					$suf = array();
					foreach($offset_map as $offset_key => $present) {
						$offset = (int)$offset_key;
						if($offset >= $shift_from) {
							$suf[$offset + $adjust] = true;
						} else {
							$pre[$offset] = true;
						}
					}
					$next_value_map[$attribute_value] = $pre + $suf;
				} else {
					$next_offsets = array();
					foreach($offset_map as $offset_key => $present) {
						$offset = (int)$offset_key;
						$new_offset = ($offset >= $shift_from) ? ($offset + $adjust) : $offset;
						if($new_offset < 0) {
							return false;
						}
						$next_offsets[$new_offset] = true;
					}
					$next_value_map[$attribute_value] = $next_offsets;
				}
			}
			$next_attribute_value_index[$attribute_name] = $next_value_map;
		}
		$this->attribute_index = $next_attribute_index;
		$this->attribute_value_index = $next_attribute_value_index;
		return true;
	}

	private function replace_span_intersects_any_opening_tag($start, $span_len) {
		if($span_len <= 0) {
			return false;
		}
		$end = $start + $span_len - 1;
		foreach($this->opening_tag_offsets as $O) {
			if($O > $end) {
				break;
			}
			if(!isset($this->tag_end_offsets[$O])) {
				continue;
			}
			$te = $this->tag_end_offsets[$O];
			if($end >= $O && $start <= $te) {
				return true;
			}
		}
		return false;
	}

	private function append_parent_indexes_scan($scan_from, &$stack, $scan_end_exclusive = null, $finalize_unclosed = true) {
		$code = $this->code;
		$length = strlen($code);
		if($scan_end_exclusive === null) {
			$scan_end_exclusive = $length;
		}
		$offset = $scan_from;
		while(($offset = strpos($code, '<', $offset)) !== false) {
			if($offset >= $scan_end_exclusive) {
				break;
			}
			if(!isset($code[$offset + 1])) {
				break;
			}
			$next_character = $code[$offset + 1];
			if($next_character === '!') {
				if(substr($code, $offset, 4) === '<!--') {
					$end_offset = strpos($code, '-->', $offset + 4);
					if($end_offset === false) { break; }
					$offset = $end_offset + 3;
					continue;
				} elseif(substr($code, $offset, 9) === '<![CDATA[') {
					$end_offset = strpos($code, ']]>', $offset + 9);
					if($end_offset === false) { break; }
					$offset = $end_offset + 3;
					continue;
				} elseif(stripos(substr($code, $offset, 10), '<!DOCTYPE') === 0) {
					$end_offset = O::find_tag_close_offset($code, $offset);
					if($end_offset === false) { break; }
					$offset = $end_offset + 1;
					continue;
				}
			}
			if($next_character === '?') {
				$end_offset = strpos($code, '?>', $offset + 2);
				if($end_offset === false) { break; }
				$offset = $end_offset + 2;
				continue;
			}
			if($next_character === '%') {
				$end_offset = strpos($code, '%>', $offset + 2);
				if($end_offset === false) { break; }
				$offset = $end_offset + 2;
				continue;
			}
			$tag_end_offset = O::find_tag_close_offset($code, $offset);
			if($tag_end_offset === false) {
				return false;
			}
			if($next_character === '/') {
				if(sizeof($stack) > 0) {
					$opening_offset = array_pop($stack);
					$this->node_end_offsets[$opening_offset] = $tag_end_offset;
				}
				$offset = $tag_end_offset + 1;
				continue;
			}
			$this->opening_tag_offsets[] = $offset;
			$this->opening_tag_offsets_count++;
			$this->tag_end_offsets[$offset] = $tag_end_offset;
			$this->parent_offsets[$offset] = (sizeof($stack) > 0) ? $stack[sizeof($stack) - 1] : false;
			$tagname = O::extract_tagname_at($code, $offset, $tag_end_offset);
			$this->opening_tag_names[$offset] = $tagname;
			if($tagname !== false) {
				if(!isset($this->tag_index[$tagname])) {
					$this->tag_index[$tagname] = array();
				}
				$this->tag_index[$tagname][] = $offset;
			}
			if(O::tag_is_self_closing_at($code, $offset, $tag_end_offset)) {
				$this->self_closing_open_count_in_index++;
				$this->node_end_offsets[$offset] = $tag_end_offset;
			} else {
				$stack[] = $offset;
			}
			$offset = $tag_end_offset + 1;
		}
		if($finalize_unclosed) {
			while(sizeof($stack) > 0) {
				$opening_offset = array_pop($stack);
				$this->node_end_offsets[$opening_offset] = $length - 1;
			}
		}
		return true;
	}

	function patch_parent_indexes_from_edit($edit_start, $old_len, $new_len, $old_has_less = false, $new_has_less = false) {
		if(!$this->parent_indexes_ready) {
			return false;
		}
		$this->parent_children_index_ready = false;
		$this->parent_children_index_size = -1;
		$this->parent_children_index = array();
		$code = $this->code;
		$length = strlen($code);
		if($length === 0) {
			O::clear_parent_structural_indexes();
			return true;
		}
		$edit_start = (int)$edit_start;
		if($edit_start < 0 || $edit_start > $length) {
			return false;
		}
		$old_len = (int)$old_len;
		$new_len = (int)$new_len;
		if($old_len < 0 || $new_len < 0) {
			return false;
		}
		$adjust = $new_len - $old_len;
		$shift_from = $edit_start + $old_len;
		$parent_offsets =& $this->parent_offsets;
		$tag_end_offsets =& $this->tag_end_offsets;
		$node_end_offsets =& $this->node_end_offsets;
		$opening_tag_names =& $this->opening_tag_names;
		// Fast path: equal-length plain-text edits do not affect structure or offsets.
		// Only cached parent query/results may be stale due to changed node text.
		if($adjust === 0 && !$old_has_less && !$new_has_less) {
			$this->parent_result_cache = array();
			$this->parent_query_cache = array();
			return true;
		}
		$old_openings = $this->opening_tag_offsets;
		$n_old_openings = sizeof($old_openings);
		$removed_self_closing_count = 0;
		$prefix_only = array();
		$suffix_only = array();
		$suffix_start_idx = $n_old_openings;
		$removed_openings_by_tag = array();
		for($oi = 0; $oi < $n_old_openings; $oi++) {
			$O = $old_openings[$oi];
			if($O < $edit_start) {
				if(isset($tag_end_offsets[$O]) && $tag_end_offsets[$O] >= $shift_from) {
					$tag_end_offsets[$O] += $adjust;
				}
				if(isset($node_end_offsets[$O]) && $node_end_offsets[$O] >= $shift_from) {
					$node_end_offsets[$O] += $adjust;
				}
				$prefix_only[] = $O;
			} elseif($O >= $shift_from) {
				if($suffix_start_idx === $n_old_openings) {
					$suffix_start_idx = $oi;
				}
				$suffix_only[] = $O + $adjust;
			} else {
				if(isset($tag_end_offsets[$O]) && O::tag_is_self_closing_at($code, $O, $tag_end_offsets[$O])) {
					$removed_self_closing_count++;
				}
				$tn = isset($opening_tag_names[$O]) ? $opening_tag_names[$O] : false;
				if($tn !== false && $tn !== '') {
					if(!isset($removed_openings_by_tag[$tn])) {
						$removed_openings_by_tag[$tn] = array();
					}
					$removed_openings_by_tag[$tn][$O] = true;
				}
				unset($parent_offsets[$O]);
				unset($node_end_offsets[$O]);
				unset($tag_end_offsets[$O]);
				unset($opening_tag_names[$O]);
			}
		}
		foreach($removed_openings_by_tag as $tagname => $removed_offset_map) {
			$this->tag_index_remove_opening_offsets_map($tagname, $removed_offset_map);
		}
		$t_suffix = $this->profile_enabled ? O::getmicrotime() : false;
		if($adjust !== 0) {
			if($adjust > 0) {
				for($i = $n_old_openings - 1; $i >= $suffix_start_idx; $i--) {
					$K = $old_openings[$i];
					if(!isset($tag_end_offsets[$K]) || !isset($node_end_offsets[$K])) {
						return false;
					}
					$newK = $K + $adjust;
					$pe = isset($parent_offsets[$K]) ? $parent_offsets[$K] : false;
					if($pe === false) {
						$new_pe = false;
					} elseif($pe < $edit_start) {
						$new_pe = $pe;
					} elseif($pe >= $shift_from) {
						$new_pe = $pe + $adjust;
					} else {
						return false;
					}
					$new_te = $tag_end_offsets[$K] + $adjust;
					$new_ne = $node_end_offsets[$K] + $adjust;
					$name = isset($opening_tag_names[$K]) ? $opening_tag_names[$K] : false;
					unset($parent_offsets[$K]);
					unset($tag_end_offsets[$K]);
					unset($node_end_offsets[$K]);
					unset($opening_tag_names[$K]);
					$parent_offsets[$newK] = $new_pe;
					$tag_end_offsets[$newK] = $new_te;
					$node_end_offsets[$newK] = $new_ne;
					$opening_tag_names[$newK] = $name;
				}
			} else {
				for($i = $suffix_start_idx; $i < $n_old_openings; $i++) {
					$K = $old_openings[$i];
					if(!isset($tag_end_offsets[$K]) || !isset($node_end_offsets[$K])) {
						return false;
					}
					$newK = $K + $adjust;
					$pe = isset($parent_offsets[$K]) ? $parent_offsets[$K] : false;
					if($pe === false) {
						$new_pe = false;
					} elseif($pe < $edit_start) {
						$new_pe = $pe;
					} elseif($pe >= $shift_from) {
						$new_pe = $pe + $adjust;
					} else {
						return false;
					}
					$new_te = $tag_end_offsets[$K] + $adjust;
					$new_ne = $node_end_offsets[$K] + $adjust;
					$name = isset($opening_tag_names[$K]) ? $opening_tag_names[$K] : false;
					unset($parent_offsets[$K]);
					unset($tag_end_offsets[$K]);
					unset($node_end_offsets[$K]);
					unset($opening_tag_names[$K]);
					$parent_offsets[$newK] = $new_pe;
					$tag_end_offsets[$newK] = $new_te;
					$node_end_offsets[$newK] = $new_ne;
					$opening_tag_names[$newK] = $name;
				}
			}
			$this->tag_index_shift_suffix_offsets($shift_from, $adjust);
		}
		if($t_suffix !== false) {
			$this->profile_add_time('patch_parent_suffix_remap', O::getmicrotime() - $t_suffix);
		}
		// Fast-path: if old/new fragments contain no markup ('<'), the edit is text-only.
		// In this common case we can skip the fragment scanning logic entirely.
		if(!$old_has_less && !$new_has_less) {
			$t_fast = $this->profile_enabled ? O::getmicrotime() : false;
			$this->opening_tag_offsets = array_merge($prefix_only, $suffix_only);
			$this->opening_tag_offsets_count = sizeof($prefix_only) + sizeof($suffix_only);
			$this->parent_result_cache = array();
			$this->parent_query_cache = array();
			// Text-only edits cannot add/remove tags, so the self-closing count is unchanged.
			if($t_fast !== false) {
				$this->profile_add_time('patch_text_fastpath', O::getmicrotime() - $t_fast);
			}
			return true;
		}
		$this->opening_tag_offsets = $prefix_only;
		$this->opening_tag_offsets_count = sizeof($prefix_only);
		$stack = array();
		foreach($this->opening_tag_offsets as $O) {
			while(sizeof($stack) > 0 && $this->node_end_offsets[$stack[sizeof($stack) - 1]] < $O) {
				array_pop($stack);
			}
			$stack[] = $O;
		}
		while(sizeof($stack) > 0 && $this->node_end_offsets[$stack[sizeof($stack) - 1]] < $edit_start) {
			array_pop($stack);
		}
		$scan_stack = $stack;
		$fragment_end = $edit_start + $new_len;
		if($fragment_end > $length) {
			return false;
		}
		$n_prefix = sizeof($this->opening_tag_offsets);
		$t_scan = $this->profile_enabled ? O::getmicrotime() : false;
		if($this->append_parent_indexes_scan($edit_start, $scan_stack, $fragment_end, false) === false) {
			return false;
		}
		if($t_scan !== false) {
			$this->profile_add_time('patch_parent_fragment_scan', O::getmicrotime() - $t_scan);
		}
		$fragment_openings = array();
		if(sizeof($this->opening_tag_offsets) > $n_prefix) {
			$fragment_openings = array_slice($this->opening_tag_offsets, $n_prefix);
		}
		$this->opening_tag_offsets = $this->merge_three_sorted_numeric($prefix_only, $fragment_openings, $suffix_only);
		$this->opening_tag_offsets_count = sizeof($this->opening_tag_offsets);
		if(sizeof($fragment_openings) === 0) {
			if($this->profile_enabled) {
				$this->profile_add_time('patch_parent_fragment_bookkeeping', 0.0);
			}
			$this->parent_result_cache = array();
			$this->parent_query_cache = array();
			return true;
		}
		$t_bookkeeping = $this->profile_enabled ? O::getmicrotime() : false;
		$added_self_closing_count = 0;
		foreach($fragment_openings as $fo) {
			if(isset($this->tag_end_offsets[$fo]) && O::tag_is_self_closing_at($code, $fo, $this->tag_end_offsets[$fo])) {
				$added_self_closing_count++;
			}
		}
		$fragment_openings_by_tag = array();
		foreach($fragment_openings as $fo) {
			if(!isset($this->opening_tag_names[$fo])) {
				continue;
			}
			$ftn = $this->opening_tag_names[$fo];
			if($ftn !== false && $ftn !== '') {
				if(!isset($fragment_openings_by_tag[$ftn])) {
					$fragment_openings_by_tag[$ftn] = array();
				}
				$fragment_openings_by_tag[$ftn][] = $fo;
			}
		}
		foreach($fragment_openings_by_tag as $ftn => $fragment_offsets_for_tag) {
			if(isset($this->tag_index[$ftn])) {
				$current_offsets = $this->tag_index[$ftn];
				$fragment_count_for_tag = sizeof($fragment_offsets_for_tag);
				$current_count = sizeof($current_offsets);
				if($fragment_count_for_tag > 0 && $current_count >= $fragment_count_for_tag) {
					$existing_offsets = array_slice($current_offsets, 0, $current_count - $fragment_count_for_tag);
					$this->tag_index[$ftn] = $this->merge_two_sorted_numeric($existing_offsets, $fragment_offsets_for_tag);
				} else {
					sort($this->tag_index[$ftn], SORT_NUMERIC);
				}
			}
		}
		$this->self_closing_open_count_in_index += ($added_self_closing_count - $removed_self_closing_count);
		if($this->self_closing_open_count_in_index < 0) {
			$this->self_closing_open_count_in_index = 0;
		}
		if($t_bookkeeping !== false) {
			$this->profile_add_time('patch_parent_fragment_bookkeeping', O::getmicrotime() - $t_bookkeeping);
		}
		$this->parent_result_cache = array();
		$this->parent_query_cache = array();
		return true;
	}

	function build_parent_indexes() {
		$this->self_closing_open_count_in_index = 0;
		$this->opening_tag_offsets = array();
		$this->opening_tag_offsets_count = 0;
		$this->parent_offsets = array();
		$this->parent_children_index = array();
		$this->parent_children_index_ready = false;
		$this->parent_children_index_size = -1;
		$this->node_end_offsets = array();
		$this->tag_end_offsets = array();
		$this->parent_result_cache = array();
		$this->parent_query_cache = array();
		$this->attribute_index = array();
		$this->attribute_value_index = array();
		$this->attribute_index_ready = array();
		$this->attribute_full_index_ready = false;
		$this->opening_tag_names = array();
		$this->tag_index = array();
		$length = strlen($this->code);
		if($length === 0) {
			$this->parent_indexes_ready = true;
			$this->attribute_full_index_ready = true;
			return true;
		}
		if(O::try_build_parent_indexes_native()) {
			return true;
		}
		$stack = array();
		if($this->append_parent_indexes_scan(0, $stack) === false) {
			O::clear_parent_structural_indexes();
			return false;
		}
		$this->opening_tag_offsets_count = sizeof($this->opening_tag_offsets);
		$this->parent_indexes_ready = true;
		return true;
	}

	/* Optional C accelerator (lom_accel). Pure PHP remains the default when unavailable. */
	function lom_accel_enabled() {
		$env = getenv('LOM_ACCEL');
		if($env !== false) {
			$env = strtolower(trim((string)$env));
			if(in_array($env, array('0', 'false', 'off', 'no'), true)) {
				return false;
			}
			if(in_array($env, array('1', 'true', 'on', 'yes'), true)) {
				return function_exists('lom_accel_scan');
			}
		}
		return function_exists('lom_accel_scan');
	}

	function try_build_parent_indexes_native() {
		if(!O::lom_accel_enabled()) {
			return false;
		}
		$scan = lom_accel_scan($this->code, true);
		if(!is_array($scan) || !isset($scan['opening_tag_offsets'])) {
			return false;
		}
		$this->opening_tag_offsets = $scan['opening_tag_offsets'];
		$this->opening_tag_offsets_count = sizeof($this->opening_tag_offsets);
		$this->tag_end_offsets = $scan['tag_end_offsets'];
		$this->parent_offsets = $scan['parent_offsets'];
		foreach($this->parent_offsets as $k => $v) {
			if($v === null) {
				$this->parent_offsets[$k] = false;
			}
		}
		$this->node_end_offsets = $scan['node_end_offsets'];
		$this->opening_tag_names = $scan['opening_tag_names'];
		$this->tag_index = $scan['tag_index'];
		$this->self_closing_open_count_in_index = isset($scan['self_closing_open_count']) ? (int)$scan['self_closing_open_count'] : 0;
		if(!empty($scan['attribute_full_index_ready'])) {
			$this->attribute_index = isset($scan['attribute_index']) ? $scan['attribute_index'] : array();
			$this->attribute_value_index = isset($scan['attribute_value_index']) ? $scan['attribute_value_index'] : array();
			$this->attribute_full_index_ready = true;
			if(is_array($this->attribute_index)) {
				foreach($this->attribute_index as $aname => $_unused) {
					$this->attribute_index_ready[$aname] = true;
				}
			}
		}
		$this->parent_indexes_ready = true;
		return true;
	}

	function rebuild_all_attribute_indexes_from_structure() {
		$this->attribute_index = array();
		$this->attribute_value_index = array();
		$this->attribute_index_ready = array();
		if($this->opening_tag_offsets_count === 0) {
			return true;
		}
		$pattern = '/\s(' . $this->attributename_regex . ')\s*=\s*("([^"]*)"|\'([^\']*)\')/';
		foreach($this->opening_tag_offsets as $offset) {
			if(!isset($this->tag_end_offsets[$offset])) {
				continue;
			}
			$opening_tag_string = substr($this->code, $offset, $this->tag_end_offsets[$offset] - $offset + 1);
			if(!preg_match_all($pattern, $opening_tag_string, $matches, PREG_SET_ORDER)) {
				continue;
			}
			foreach($matches as $m) {
				$aname = $m[1];
				$attr_value = isset($m[3]) && $m[3] !== '' ? $m[3] : (isset($m[4]) ? $m[4] : '');
				if(!isset($this->attribute_index[$aname])) {
					$this->attribute_index[$aname] = array();
				}
				$this->attribute_index[$aname][$offset] = true;
				if(!isset($this->attribute_value_index[$aname])) {
					$this->attribute_value_index[$aname] = array();
				}
				if(!isset($this->attribute_value_index[$aname][$attr_value])) {
					$this->attribute_value_index[$aname][$attr_value] = array();
				}
				$this->attribute_value_index[$aname][$attr_value][$offset] = true;
			}
		}
		return true;
	}

	function tag_is_self_closing_at($code, $tag_offset, $tag_end_offset = false) {
		if($tag_end_offset === false) {
			$tag_end_offset = O::find_tag_close_offset($code, $tag_offset);
			if($tag_end_offset === false) {
				return false;
			}
		}
		$position = $tag_end_offset - 1;
		$code_len = strlen($code);
		if($position >= $code_len) return false; // stale offset after mutation
		while($position > $tag_offset && ctype_space($code[$position])) {
			$position--;
		}
		if($position >= $code_len) return false;
		return ($position > $tag_offset && $code[$position] === '/');
	}

	function find_tag_close_offset($code, $tag_offset) {
		$length = strlen($code);
		$quote = false;
		$offset = $tag_offset + 1;
		while($offset < $length) {
			$character = $code[$offset];
			if($quote !== false) {
				if($character === $quote) {
					$quote = false;
				}
			} else {
				if($character === '"' || $character === "'") {
					$quote = $character;
				} elseif($character === '>') {
					return $offset;
				}
			}
			$offset++;
		}
		return false;
	}

	function extract_tagname_at($code, $tag_offset, $tag_end_offset = false) {
		if($tag_end_offset === false) {
			$tag_end_offset = O::find_tag_close_offset($code, $tag_offset);
			if($tag_end_offset === false) {
				return false;
			}
		}
		$start = $tag_offset + 1;
		if(!isset($code[$start])) {
			return false;
		}
		if($code[$start] === '/') {
			$start++;
		}
		$position = $start;
		while($position <= $tag_end_offset && isset($code[$position]) && !ctype_space($code[$position]) && $code[$position] !== '>' && $code[$position] !== '/') {
			$position++;
		}
		if($position <= $start) {
			return false;
		}
		return substr($code, $start, $position - $start);
	}

	function get_tag_index_offsets($tagname) {
		O::ensure_parent_indexes();
		if(isset($this->tag_index[$tagname])) {
			return $this->tag_index[$tagname];
		}
		return array();
	}

	function ensure_attribute_index($attribute_name) {
		$attribute_name = (string)$attribute_name;
		if($attribute_name === '') {
			return false;
		}
		if($this->attribute_full_index_ready) {
			return true;
		}
		if(isset($this->attribute_index_ready[$attribute_name])) {
			return true;
		}
		O::build_attribute_index($attribute_name);
		return true;
	}

	function build_attribute_index($attribute_name) {
		$attribute_name = (string)$attribute_name;
		O::ensure_parent_indexes();
		if($this->attribute_full_index_ready) {
			return true;
		}
		$this->attribute_index[$attribute_name] = array();
		$this->attribute_value_index[$attribute_name] = array();
		if($attribute_name === '' || sizeof($this->opening_tag_offsets) === 0) {
			$this->attribute_index_ready[$attribute_name] = true;
			return true;
		}
		$pattern = '/\s' . preg_quote($attribute_name, '/') . '\s*=\s*("([^"]*)"|\'([^\']*)\')/';
		foreach($this->opening_tag_offsets as $offset) {
			if(!isset($this->tag_end_offsets[$offset])) {
				continue;
			}
			$opening_tag_string = substr($this->code, $offset, $this->tag_end_offsets[$offset] - $offset + 1);
			if(preg_match($pattern, $opening_tag_string, $matches)) {
				$this->attribute_index[$attribute_name][$offset] = true;
				$attribute_value = isset($matches[2]) && $matches[2] !== '' ? $matches[2] : (isset($matches[3]) ? $matches[3] : '');
				if(!isset($this->attribute_value_index[$attribute_name][$attribute_value])) {
					$this->attribute_value_index[$attribute_name][$attribute_value] = array();
				}
				$this->attribute_value_index[$attribute_name][$attribute_value][$offset] = true;
			}
		}
		$this->attribute_index_ready[$attribute_name] = true;
		return true;
	}

	function overlay_first_child_tag_text($node_xml, $child_tag) {
		$child_tag = preg_quote($child_tag, '/');
		if(preg_match('/<' . $child_tag . '(\s[^>]*)?>(.*?)<\/' . $child_tag . '>/is', $node_xml, $m)) {
			return trim($m[2]);
		}
		return null;
	}

	function fast_overlay_fallback_child_tag($parsed, $parent_node_only = false) {
		$results = array();
		$offsets_out = array();
		$tagname = $parsed['tagname'];
		foreach($this->opening_tag_offsets as $off) {
			if(!isset($this->opening_tag_names[$off])) {
				continue;
			}
			if($tagname !== '*' && $this->opening_tag_names[$off] !== $tagname) {
				continue;
			}
			if($tagname === '*' && $this->opening_tag_names[$off] === $parsed['attribute_name']) {
				continue;
			}
			$node = O::build_node_result_from_offset($off, true, $parent_node_only);
			if(!is_array($node) || !isset($node[0]) || $node[0] === '') {
				continue;
			}
			$xml = $node[0];
			$txt = O::overlay_first_child_tag_text($xml, $parsed['attribute_name']);
			if($txt === null) {
				continue;
			}
			if(!O::compare($txt, $parsed['operator'], $parsed['attribute_value'])) {
				continue;
			}
			$results[] = $node;
			$offsets_out[] = $off;
		}
		return array($results, $offsets_out);
	}

	function filter_drop_strict_ancestor_matches($matches) {
		if(!is_array($matches) || sizeof($matches) < 2) {
			return $matches;
		}
		$this->ensure_parent_indexes();
		$out = array();
		foreach($matches as $i => $mi) {
			if(!is_array($mi) || !isset($mi[1]) || !isset($this->node_end_offsets[$mi[1]])) {
				$out[] = $mi;
				continue;
			}
			$i_open = $mi[1];
			$i_end = $this->node_end_offsets[$i_open];
			$strictly_contains_descendant_result = false;
			foreach($matches as $j => $mj) {
				if($i === $j || !is_array($mj) || !isset($mj[1]) || !isset($this->node_end_offsets[$mj[1]])) {
					continue;
				}
				$j_open = $mj[1];
				$j_end = $this->node_end_offsets[$j_open];
				if($j_open > $i_open && $j_end < $i_end) {
					$strictly_contains_descendant_result = true;
					break;
				}
			}
			if(!$strictly_contains_descendant_result) {
				$out[] = $mi;
			}
		}
		return $out;
	}

	function filter_multi_and_sibling_requirements($match_rows, $code, $offset_to_add, $selector_piece_index) {
		if(sizeof($match_rows) === 0 || empty($this->selector_piece_multi_and[$selector_piece_index])) {
			return $match_rows;
		}
		$tagnames = $this->tagnames[$selector_piece_index];
		$tagvalues = $this->tagvalues[$selector_piece_index];
		if(sizeof($tagnames) < 2) {
			return $match_rows;
		}
		$out = array();
		foreach($match_rows as $value) {
			$global_off = $value[1] + $offset_to_add;
			$par = O::get_parent($global_off, false, false, true, false, true);
			if(!is_array($par) || !isset($par[0][0])) {
				continue;
			}
			$parent_str = $par[0][0];
			$ok = true;
			for($i = 1; $i < sizeof($tagnames); $i++) {
				$tn = $tagnames[$i];
				$tv = isset($tagvalues[$i]) ? $tagvalues[$i] : false;
				$tnq = preg_quote($tn, '/');
				if($tv === false) {
					if(!preg_match('/<' . $tnq . '(\s[^>]*)?>/i', $parent_str)) {
						$ok = false;
						break;
					}
				} else {
					$tve = O::preg_escape($tv);
					if(!preg_match('/<' . $tnq . '(\s[^>]*)?>' . $tve . '<\/' . $tnq . '>/is', $parent_str)) {
						$ok = false;
						break;
					}
				}
			}
			if($ok) {
				$out[] = $value;
			}
		}
		return $out;
	}

	function parse_fast_simple_attribute_selector($normalized_selector) {
		static $fast_simple_attribute_selector_cache = array();
		if(!is_string($normalized_selector) || $normalized_selector === '') {
			return false;
		}
		if(isset($fast_simple_attribute_selector_cache[$normalized_selector])) {
			return $fast_simple_attribute_selector_cache[$normalized_selector];
		}
		if(strpos($normalized_selector, '|') !== false || strpos($normalized_selector, '.') !== false || strpos($normalized_selector, '&') !== false || strpos($normalized_selector, '[') !== false) {
			$fast_simple_attribute_selector_cache[$normalized_selector] = false;
			return false;
		}
		O::parse_selector_string($normalized_selector);
		if(sizeof($this->selector_piece_sets) !== 1 || sizeof($this->selector_piece_sets[0]) !== 1) {
			$fast_simple_attribute_selector_cache[$normalized_selector] = false;
			return false;
		}
		$piece = $this->selector_piece_sets[0][0];
		O::parse_selector_piece($piece, 0);
		$tagname = (isset($this->tagnames[0]) && isset($this->tagnames[0][0]) && $this->tagnames[0][0] !== false && $this->tagnames[0][0] !== '') ? $this->tagnames[0][0] : '*';
		if(isset($this->tagname_indices[0]) && isset($this->tagname_indices[0][0]) && $this->tagname_indices[0][0] !== false) {
			$fast_simple_attribute_selector_cache[$normalized_selector] = false;
			return false;
		}
		$attribute_name = false;
		if(isset($this->required_attribute_sets[0]) && isset($this->required_attribute_sets[0][0]) && sizeof($this->required_attribute_sets[0][0]) === 1 && isset($this->required_attribute_sets[0][0][0]) && $this->required_attribute_sets[0][0][0] !== '') {
			$attribute_name = $this->required_attribute_sets[0][0][0];
		} else {
			// Some selector shapes like "entity@kind" can leave
			// required_attribute_sets under-populated even though they are valid.
			$at_pos = strpos($piece, '@');
			if($at_pos !== false && $at_pos < strlen($piece) - 1) {
				$attr_candidate = substr($piece, $at_pos + 1);
				if($attr_candidate !== '' && strpos($attr_candidate, '@') === false && strpos($attr_candidate, '_') === false) {
					$attribute_name = $attr_candidate;
				}
			}
		}
		if($attribute_name === false || $attribute_name === null || $attribute_name === '') {
			$fast_simple_attribute_selector_cache[$normalized_selector] = false;
			return false;
		}
		$attribute_value = false;
		if(isset($this->tagvalues[0]) && isset($this->tagvalues[0][0]) && $this->tagvalues[0][0] !== false) {
			$attribute_value = $this->tagvalues[0][0];
		}
		if($attribute_value !== false && isset($this->tagvalue_indices[0]) && isset($this->tagvalue_indices[0][0]) && $this->tagvalue_indices[0][0] !== false) {
			$fast_simple_attribute_selector_cache[$normalized_selector] = false;
			return false;
		}
		$parsed = array('tagname' => $tagname, 'attribute_name' => $attribute_name, 'attribute_value' => $attribute_value);
		$fast_simple_attribute_selector_cache[$normalized_selector] = $parsed;
		return $parsed;
	}

	function fast_get_simple_attribute_selector($normalized_selector, $add_to_context = true, $ignore_context = false, $parent_node_only = false, $tagged_result = false) {
		$profile_token = $this->profile_function_start('fast_get_simple_attribute_selector');
		try {
		$parsed = O::parse_fast_simple_attribute_selector($normalized_selector);
		if($parsed === false) {
			return false;
		}
		O::ensure_attribute_index($parsed['attribute_name']);
		$candidate_map = ($parsed['attribute_value'] !== false) ? (isset($this->attribute_value_index[$parsed['attribute_name']][$parsed['attribute_value']]) ? $this->attribute_value_index[$parsed['attribute_name']][$parsed['attribute_value']] : array()) : (isset($this->attribute_index[$parsed['attribute_name']]) ? $this->attribute_index[$parsed['attribute_name']] : array());
		if(sizeof($candidate_map) === 0) {
			$this->offsets_from_get = array();
			if($add_to_context && $this->use_context && !$ignore_context) {
				$this->append_context_entry(array($normalized_selector, false, false, array()));
			}
			return array();
		}
		$matching_offsets = array_keys($candidate_map);
		if($parsed['tagname'] !== '*' && $parsed['tagname'] !== false && $parsed['tagname'] !== '') {
			O::ensure_parent_indexes();
			$filtered_offsets = array();
			foreach($matching_offsets as $candidate_offset) {
				if(isset($this->opening_tag_names[$candidate_offset]) && $this->opening_tag_names[$candidate_offset] === $parsed['tagname']) {
					$filtered_offsets[] = $candidate_offset;
				}
			}
			$matching_offsets = $filtered_offsets;
		}
		if(sizeof($matching_offsets) === 0) {
			$this->offsets_from_get = array();
			if($add_to_context && $this->use_context && !$ignore_context) {
				$this->append_context_entry(array($normalized_selector, false, false, array()));
			}
			return array();
		}
		sort($matching_offsets, SORT_NUMERIC);
		$results = array();
		$this->offsets_from_get = array();
		foreach($matching_offsets as $matching_offset) {
			$result = O::build_node_result_from_offset($matching_offset, true, $parent_node_only);
			$results[] = $result;
			$this->offsets_from_get[] = $matching_offset;
		}
		if($add_to_context && $this->use_context && !$ignore_context) {
			$this->append_context_entry(array($normalized_selector, false, O::context_array($results), $results));
		}
		if($tagged_result) {
			return $results;
		}
		return O::export($results);
		} finally {
			$this->profile_function_stop('fast_get_simple_attribute_selector', $profile_token);
		}
	}

	function invalidate_runtime_caches_only() {
		$this->expands = array();
		$this->context = array();
		$this->invalidate_context_selector_index();
		$this->parent_result_cache = array();
		$this->parent_query_cache = array();
		O::clear_fmem_fcache(true);
	}

	function parse_fast_direct_tag_chain($normalized_selector) {
		static $fast_direct_chain_cache = array();
		if(!is_string($normalized_selector) || $normalized_selector === '') {
			return false;
		}
		if(isset($fast_direct_chain_cache[$normalized_selector])) {
			return $fast_direct_chain_cache[$normalized_selector];
		}
		if(strpos($normalized_selector, '|') !== false) {
			$fast_direct_chain_cache[$normalized_selector] = false;
			return false;
		}
		if(strpos($normalized_selector, '.') !== false) {
			$fast_direct_chain_cache[$normalized_selector] = false;
			return false;
		}
		O::parse_selector_string($normalized_selector);
		if(sizeof($this->selector_piece_sets) !== 1) {
			$fast_direct_chain_cache[$normalized_selector] = false;
			return false;
		}
		if($this->selected_parent_piece_index !== false) {
			$fast_direct_chain_cache[$normalized_selector] = false;
			return false;
		}
		$pieces = $this->selector_piece_sets[0];
		$scopes = $this->selector_scope_sets[0];
		$chain = array();
		foreach($pieces as $piece_index => $piece) {
			if($piece === '' || strpos($piece, '&') !== false || strpos($piece, '@') !== false || strpos($piece, '=') !== false || $piece === '*') {
				$fast_direct_chain_cache[$normalized_selector] = false;
				return false;
			}
			if($piece_index > 0 && (!isset($scopes[$piece_index]) || $scopes[$piece_index] !== 'direct')) {
				$fast_direct_chain_cache[$normalized_selector] = false;
				return false;
			}
			O::parse_selector_piece($piece, $piece_index);
			if(!isset($this->tagnames[$piece_index]) || sizeof($this->tagnames[$piece_index]) !== 1) {
				$fast_direct_chain_cache[$normalized_selector] = false;
				return false;
			}
			if($this->tagnames[$piece_index][0] === '*' || $this->tagnames[$piece_index][0] === false || $this->tagnames[$piece_index][0] === '') {
				$fast_direct_chain_cache[$normalized_selector] = false;
				return false;
			}
			if(isset($this->tagvalues[$piece_index]) && $this->tagvalues[$piece_index] !== false && $this->tagvalues[$piece_index][0] !== false) {
				$fast_direct_chain_cache[$normalized_selector] = false;
				return false;
			}
			if(isset($this->required_attribute_sets[$piece_index]) && sizeof($this->required_attribute_sets[$piece_index]) > 0 && sizeof($this->required_attribute_sets[$piece_index][0]) > 0) {
				$fast_direct_chain_cache[$normalized_selector] = false;
				return false;
			}
			$chain[] = array(
				'tagname' => $this->tagnames[$piece_index][0],
				'index' => (isset($this->tagname_indices[$piece_index]) && isset($this->tagname_indices[$piece_index][0])) ? $this->tagname_indices[$piece_index][0] : false,
			);
		}
		$fast_direct_chain_cache[$normalized_selector] = $chain;
		return $chain;
	}

	function parse_fast_indexed_or_wildcard_direct_chain($normalized_selector) {
		static $fast_indexed_chain_cache = array();
		if(!is_string($normalized_selector) || $normalized_selector === '') {
			return false;
		}
		if(isset($fast_indexed_chain_cache[$normalized_selector])) {
			return $fast_indexed_chain_cache[$normalized_selector];
		}
		if(strpos($normalized_selector, '|') !== false || strpos($normalized_selector, '.') !== false) {
			$fast_indexed_chain_cache[$normalized_selector] = false;
			return false;
		}
		O::parse_selector_string($normalized_selector);
		if(sizeof($this->selector_piece_sets) !== 1 || $this->selected_parent_piece_index !== false) {
			$fast_indexed_chain_cache[$normalized_selector] = false;
			return false;
		}
		$pieces = $this->selector_piece_sets[0];
		$scopes = $this->selector_scope_sets[0];
		$chain = array();
		$has_index_or_wildcard = false;
		$has_leaf_tagvalue = false;
		foreach($pieces as $piece_index => $piece) {
			if($piece === '') {
				$fast_indexed_chain_cache[$normalized_selector] = false;
				return false;
			}
			if($piece_index > 0 && (!isset($scopes[$piece_index]) || $scopes[$piece_index] !== 'direct')) {
				$fast_indexed_chain_cache[$normalized_selector] = false;
				return false;
			}
			O::parse_selector_piece($piece, $piece_index);
			if(!isset($this->tagnames[$piece_index]) || sizeof($this->tagnames[$piece_index]) !== 1) {
				$fast_indexed_chain_cache[$normalized_selector] = false;
				return false;
			}
			$tagname = $this->tagnames[$piece_index][0];
			if($tagname === false || $tagname === '') {
				$fast_indexed_chain_cache[$normalized_selector] = false;
				return false;
			}
			if($tagname === '*') {
				$has_index_or_wildcard = true;
			}
			$tagvalue = (isset($this->tagvalues[$piece_index]) && $this->tagvalues[$piece_index][0] !== false) ? $this->tagvalues[$piece_index][0] : false;
			$tagvalue_op = (isset($this->tagvalue_comparison_operators[$piece_index]) && isset($this->tagvalue_comparison_operators[$piece_index][0])) ? $this->tagvalue_comparison_operators[$piece_index][0] : false;
			$required_attributes = (isset($this->required_attribute_sets[$piece_index]) && isset($this->required_attribute_sets[$piece_index][0]) && is_array($this->required_attribute_sets[$piece_index][0])) ? $this->required_attribute_sets[$piece_index][0] : array();
			$attribute_ops = (isset($this->attribute_sets_comparison_operators[$piece_index]) && isset($this->attribute_sets_comparison_operators[$piece_index][0]) && is_array($this->attribute_sets_comparison_operators[$piece_index][0])) ? $this->attribute_sets_comparison_operators[$piece_index][0] : array();
			$tagname_index = (isset($this->tagname_indices[$piece_index]) && isset($this->tagname_indices[$piece_index][0])) ? $this->tagname_indices[$piece_index][0] : false;
			$tagvalue_index = (isset($this->tagvalue_indices[$piece_index]) && isset($this->tagvalue_indices[$piece_index][0])) ? $this->tagvalue_indices[$piece_index][0] : false;
			$attributes_index = (isset($this->attributes_indices[$piece_index]) && isset($this->attributes_indices[$piece_index][0])) ? $this->attributes_indices[$piece_index][0] : false;
			if($tagname_index !== false && $tagname_index !== null) {
				$has_index_or_wildcard = true;
			}
			if($tagvalue_index !== false && $tagvalue_index !== null) {
				$has_index_or_wildcard = true;
			}
			if($attributes_index !== false && $attributes_index !== null) {
				$has_index_or_wildcard = true;
			}
			if($tagvalue !== false) {
				$has_leaf_tagvalue = true;
			}
			/* Non-leaf pieces keep structure-only so parent walk stays O(hits). */
			if($piece_index < sizeof($pieces) - 1 && $tagvalue !== false) {
				$fast_indexed_chain_cache[$normalized_selector] = false;
				return false;
			}
			$chain[] = array(
				'tagname' => $tagname,
				'index' => $tagname_index,
				'tagname_index' => $tagname_index,
				'tagvalue' => $tagvalue,
				'tagvalue_op' => $tagvalue_op,
				'tagvalue_index' => $tagvalue_index,
				'required_attributes' => $required_attributes,
				'attribute_ops' => $attribute_ops,
				'attributes_index' => $attributes_index,
			);
		}
		if(!$has_index_or_wildcard && !$has_leaf_tagvalue) {
			$fast_indexed_chain_cache[$normalized_selector] = false;
			return false;
		}
		$fast_indexed_chain_cache[$normalized_selector] = $chain;
		return $chain;
	}

	function fast_get_indexed_or_wildcard_direct_chain($normalized_selector, $add_to_context = true, $ignore_context = false, $parent_node_only = false, $tagged_result = false) {
		$chain = O::parse_fast_indexed_or_wildcard_direct_chain($normalized_selector);
		if($chain === false) {
			return false;
		}
		if(sizeof($chain) > 1) {
			O::ensure_parent_children_index();
		} else {
			O::ensure_parent_indexes();
		}
		$current_parents = array();
		$root_key = 'root';
		foreach($chain as $chain_index => $piece) {
			$next_offsets = array();
			if(
				$chain_index === 0 &&
				sizeof($chain) === 1 &&
				isset($piece['tagvalue']) &&
				$piece['tagvalue'] !== false &&
				($rid = O::lom_regex_token_id($piece['tagvalue'])) !== false &&
				(!isset($piece['required_attributes']) || sizeof($piece['required_attributes']) === 0)
			) {
				$op = (isset($piece['tagvalue_op']) && $piece['tagvalue_op'] !== false && $piece['tagvalue_op'] !== '') ? $piece['tagvalue_op'] : '=';
				$doc_hits = O::try_document_level_regex_tagvalue(
					$piece['tagname'],
					$op,
					$rid,
					isset($piece['tagvalue_index']) ? $piece['tagvalue_index'] : false
				);
				if($doc_hits !== false) {
					/* Still honor tagname [n] if present. */
					if($piece['tagname_index'] !== false && $piece['tagname_index'] !== null) {
						$doc_hits = O::pick_offsets_by_index_spec($doc_hits, $piece['tagname_index']);
					}
					$next_offsets = $doc_hits;
					$current_parents = $next_offsets;
					break;
				}
			}
			if($chain_index === 0 && ($piece['tagname_index'] === false || $piece['tagname_index'] === null)) {
				if($piece['tagname'] === '*') {
					$candidates = $this->opening_tag_offsets;
				} else {
					$candidates = O::get_tag_index_offsets($piece['tagname']);
				}
				$next_offsets = O::fast_apply_index_piece($candidates, $piece);
			} elseif($chain_index === 0) {
				O::ensure_parent_children_index();
				foreach($this->parent_children_index as $parent_key => $children) {
					if(sizeof($children) === 0) {
						continue;
					}
					$picked = O::fast_apply_index_piece($children, $piece);
					foreach($picked as $po) {
						$next_offsets[] = $po;
					}
				}
			} else {
				foreach($current_parents as $parent_offset) {
					$key = ($parent_offset === false) ? $root_key : ('o:' . $parent_offset);
					$children = isset($this->parent_children_index[$key]) ? $this->parent_children_index[$key] : array();
					if(sizeof($children) === 0) {
						continue;
					}
					$picked = O::fast_apply_index_piece($children, $piece);
					foreach($picked as $po) {
						$next_offsets[] = $po;
					}
				}
			}
			sort($next_offsets, SORT_NUMERIC);
			$current_parents = $next_offsets;
			if(sizeof($current_parents) === 0) {
				break;
			}
		}
		$this->offsets_from_get = $current_parents;
		if(sizeof($current_parents) === 0) {
			if($add_to_context) {
				O::add_to_context($normalized_selector, false, array());
			}
			return array();
		}
		$selector_matches = array();
		foreach($current_parents as $offset) {
			$selector_matches[] = O::build_node_result_from_offset($offset, true, $parent_node_only);
		}
		if($add_to_context) {
			O::add_to_context($normalized_selector, false, O::context_array($selector_matches));
		}
		if($tagged_result) {
			return $selector_matches;
		}
		return O::export($selector_matches);
	}

	function fast_get_or_union_direct_chains($normalized_selector, $add_to_context = true, $ignore_context = false, $parent_node_only = false, $tagged_result = false) {
		if(!is_string($normalized_selector) || strpos($normalized_selector, '|') === false || strpos($normalized_selector, '.') !== false) {
			return false;
		}
		$branches = array();
		$cur = '';
		$len = strlen($normalized_selector);
		for($i = 0; $i < $len; $i++) {
			if($normalized_selector[$i] === '|' && ($i + 1 >= $len || $normalized_selector[$i + 1] !== '=')) {
				$branches[] = $cur;
				$cur = '';
				continue;
			}
			$cur .= $normalized_selector[$i];
		}
		$branches[] = $cur;
		if(sizeof($branches) < 2) {
			return false;
		}
		$seen = array();
		$merged_offsets = array();
		foreach($branches as $branch) {
			if($branch === '') {
				return false;
			}
			$part = O::fast_get_indexed_or_wildcard_direct_chain($branch, false, true, $parent_node_only, true);
			if($part === false) {
				$part = O::fast_get_simple_direct_tag_chain($branch, false, true, $parent_node_only, true);
			}
			if($part === false || !is_array($part)) {
				return false;
			}
			foreach($part as $m) {
				if(!is_array($m) || !isset($m[1]) || isset($seen[$m[1]])) {
					continue;
				}
				$seen[$m[1]] = 1;
				$merged_offsets[] = $m[1];
			}
		}
		sort($merged_offsets, SORT_NUMERIC);
		$this->offsets_from_get = $merged_offsets;
		if(sizeof($merged_offsets) === 0) {
			return array();
		}
		$selector_matches = array();
		foreach($merged_offsets as $offset) {
			$selector_matches[] = O::build_node_result_from_offset($offset, true, $parent_node_only);
		}
		if($tagged_result) {
			return $selector_matches;
		}
		return O::export($selector_matches);
	}

	function build_node_result_from_offset($offset, $tagged_result = true, $parent_node_only = false) {
		O::ensure_parent_indexes();
		if(!isset($this->node_end_offsets[$offset])) {
			$expanded = O::expand(false, $offset);
			$node_string = $expanded[0][0];
		} elseif($parent_node_only) {
			if(isset($this->tag_end_offsets[$offset])) {
				$node_string = substr($this->code, $offset, $this->tag_end_offsets[$offset] - $offset + 1);
			} else {
				$expanded = O::expand(false, $offset);
				$node_string = $expanded[0][0];
			}
		} else {
			$node_string = substr($this->code, $offset, $this->node_end_offsets[$offset] - $offset + 1);
		}
		return array($node_string, $offset);
	}

	function fast_get_simple_direct_tag_chain($normalized_selector, $add_to_context = true, $ignore_context = false, $parent_node_only = false, $tagged_result = false) {
		$profile_token = $this->profile_function_start('fast_get_simple_direct_tag_chain');
		try {
		$chain = O::parse_fast_direct_tag_chain($normalized_selector);
		if($chain === false) {
			return false;
		}
		$have_full = O::ensure_parent_indexes();
		if(!$have_full) {
			/* Large-doc slim path: single tagname only (no parent walk). */
			if(sizeof($chain) !== 1) {
				return false;
			}
			$piece = $chain[0];
			O::ensure_tag_index_slim($piece['tagname']);
			$candidate_offsets = isset($this->tag_index[$piece['tagname']]) ? $this->tag_index[$piece['tagname']] : array();
			if($piece['index'] !== false && $piece['index'] !== null) {
				$matching_offsets = O::pick_offsets_by_index_spec($candidate_offsets, $piece['index']);
			} else {
				$matching_offsets = $candidate_offsets;
			}
			$selector_matches = array();
			$this->offsets_from_get = array();
			foreach($matching_offsets as $offset) {
				$selector_matches[] = array(O::slim_node_slice($offset), $offset);
				$this->offsets_from_get[] = $offset;
			}
			if($add_to_context) {
				O::add_to_context($normalized_selector, false, O::context_array($selector_matches));
			}
			if($tagged_result) {
				return $selector_matches;
			}
			return O::export($selector_matches);
		}
		if(sizeof($chain) === 1) {
			$piece = $chain[0];
			$candidate_offsets = O::get_tag_index_offsets($piece['tagname']);
			if(sizeof($candidate_offsets) === 0) {
				$this->offsets_from_get = array();
				return array();
			}
			if($piece['index'] !== false && $piece['index'] !== null) {
				$matching_offsets = O::pick_offsets_by_index_spec($candidate_offsets, $piece['index']);
			} else {
				$matching_offsets = $candidate_offsets;
			}
			$selector_matches = array();
			$this->offsets_from_get = array();
			foreach($matching_offsets as $offset) {
				$selector_matches[] = O::build_node_result_from_offset($offset, true, $parent_node_only);
				$this->offsets_from_get[] = $offset;
			}
			if($add_to_context) {
				O::add_to_context($normalized_selector, false, O::context_array($selector_matches));
			}
			if($tagged_result) {
				return $selector_matches;
			}
			return O::export($selector_matches);
		}
		$last_piece = $chain[sizeof($chain) - 1];
		$candidate_offsets = O::get_tag_index_offsets($last_piece['tagname']);
		if(sizeof($candidate_offsets) === 0) {
			$this->offsets_from_get = array();
			return array();
		}
		$matching_offsets = array();
		foreach($candidate_offsets as $candidate_offset) {
			$current_offset = $candidate_offset;
			$matched = true;
			for($chain_index = sizeof($chain) - 2; $chain_index > -1; $chain_index--) {
				$current_offset = isset($this->parent_offsets[$current_offset]) ? $this->parent_offsets[$current_offset] : false;
				if($current_offset === false || !isset($this->opening_tag_names[$current_offset]) || $this->opening_tag_names[$current_offset] !== $chain[$chain_index]['tagname']) {
					$matched = false;
					break;
				}
			}
			if($matched) {
				$matching_offsets[] = $candidate_offset;
			}
		}
		// Apply global per-piece tagname indices after structural filtering, matching existing semantics.
		foreach($chain as $chain_index => $piece) {
			if($piece['index'] !== false && $piece['index'] !== null) {
				$piece_offsets = array();
				$piece_tagname = $piece['tagname'];
				$piece_candidate_offsets = O::get_tag_index_offsets($piece_tagname);
				if($chain_index === sizeof($chain) - 1) {
					$piece_offsets = $matching_offsets;
				} else {
					foreach($matching_offsets as $matched_leaf_offset) {
						$current_offset = $matched_leaf_offset;
						for($walk_index = sizeof($chain) - 2; $walk_index >= $chain_index; $walk_index--) {
							$current_offset = isset($this->parent_offsets[$current_offset]) ? $this->parent_offsets[$current_offset] : false;
							if($current_offset === false) {
								break;
							}
						}
						if($current_offset !== false) {
							$piece_offsets[] = $current_offset;
						}
					}
					$unique_piece_offsets = array();
					$piece_offsets_deduped = array();
					foreach($piece_offsets as $po) {
						$po_key = (string)$po;
						if(!isset($unique_piece_offsets[$po_key])) {
							$unique_piece_offsets[$po_key] = true;
							$piece_offsets_deduped[] = $po;
						}
					}
					$piece_offsets = $piece_offsets_deduped;
				}
				$picked = O::pick_offsets_by_index_spec($piece_offsets, $piece['index']);
				if(sizeof($picked) === 0) {
					$this->offsets_from_get = array();
					return array();
				}
				$required_set = array();
				foreach($picked as $po) {
					$required_set[(string)$po] = true;
				}
				$filtered_offsets = array();
				foreach($matching_offsets as $matched_leaf_offset) {
					$current_offset = $matched_leaf_offset;
					$current_piece_offset = ($chain_index === sizeof($chain) - 1) ? $matched_leaf_offset : false;
					if($chain_index < sizeof($chain) - 1) {
						for($walk_index = sizeof($chain) - 2; $walk_index >= $chain_index; $walk_index--) {
							$current_offset = isset($this->parent_offsets[$current_offset]) ? $this->parent_offsets[$current_offset] : false;
							if($current_offset === false) {
								break;
							}
						}
						$current_piece_offset = $current_offset;
					}
					if($current_piece_offset !== false && isset($required_set[(string)$current_piece_offset])) {
						$filtered_offsets[] = $matched_leaf_offset;
					}
				}
				$matching_offsets = $filtered_offsets;
				if(sizeof($matching_offsets) === 0) {
					$this->offsets_from_get = array();
					return array();
				}
			}
		}
		$selector_matches = array();
		$this->offsets_from_get = array();
		foreach($matching_offsets as $offset) {
			$selector_matches[] = O::build_node_result_from_offset($offset, true, $parent_node_only);
			$this->offsets_from_get[] = $offset;
		}
		if($add_to_context) {
			O::add_to_context($normalized_selector, false, O::context_array($selector_matches));
		}
		if($tagged_result) {
			return $selector_matches;
		}
		return O::export($selector_matches);
		} finally {
			$this->profile_function_stop('fast_get_simple_direct_tag_chain', $profile_token);
		}
	}

	function get_parent_offset_fast($offset) {
		O::ensure_parent_indexes();
		$offset = (int)$offset;
		if(isset($this->parent_offsets[$offset]) || array_key_exists($offset, $this->parent_offsets)) {
			return $this->parent_offsets[$offset];
		}
		if($this->opening_tag_offsets_count === 0) {
			return false;
		}
		$low = 0;
		$high = $this->opening_tag_offsets_count - 1;
		$nearest_index = -1;
		while($low <= $high) {
			$mid = (int)(($low + $high) / 2);
			$mid_offset = $this->opening_tag_offsets[$mid];
			if($mid_offset <= $offset) {
				$nearest_index = $mid;
				$low = $mid + 1;
			} else {
				$high = $mid - 1;
			}
		}
		if($nearest_index < 0) {
			return false;
		}
		for($index = $nearest_index; $index > -1; $index--) {
			$opening_offset = $this->opening_tag_offsets[$index];
			if(!isset($this->node_end_offsets[$opening_offset])) {
				continue;
			}
			if($opening_offset <= $offset && $this->node_end_offsets[$opening_offset] >= $offset) {
				if($opening_offset === $offset) {
					return $this->parent_offsets[$opening_offset];
				}
				return $opening_offset;
			}
		}
		return false;
	}

	function build_parent_result_from_offset($parent_offset, $tagged_result = false, $parent_node_only = false) {
		$cache_key = (($parent_offset === false) ? 'root' : (string)$parent_offset) . '|' . ((int)$tagged_result) . '|' . ((int)$parent_node_only);
		if(isset($this->parent_result_cache[$cache_key])) {
			return $this->parent_result_cache[$cache_key];
		}
		if($parent_offset === false) {
			$result = $tagged_result ? array(array($this->code, 0)) : $this->code;
			$this->parent_result_cache[$cache_key] = $result;
			return $result;
		}
		if($parent_node_only) {
			if(isset($this->tag_end_offsets[$parent_offset])) {
				$node_string = substr($this->code, $parent_offset, $this->tag_end_offsets[$parent_offset] - $parent_offset + 1);
			} else {
				$expanded = O::expand(false, $parent_offset);
				$node_string = $expanded[0][0];
			}
		} else {
			if(isset($this->node_end_offsets[$parent_offset])) {
				$node_string = substr($this->code, $parent_offset, $this->node_end_offsets[$parent_offset] - $parent_offset + 1);
			} else {
				$expanded = O::expand(false, $parent_offset);
				$node_string = $expanded[0][0];
			}
		}
		$result = $tagged_result ? array(array($node_string, $parent_offset)) : $node_string;
		$this->parent_result_cache[$cache_key] = $result;
		return $result;
	}

	function get_parent_stringwise($offset) { // for fractal get
		O::fatal_error('probably should not use get_parent_stringwise');
		if($this->must_check_for_doctype || $this->must_check_for_non_parsed_character_data || $this->must_check_for_comment || $this->must_check_for_programming_instruction || $this->must_check_for_ASP) {
			O::fatal_error('get_parent_stringwise cannot be used as is since the code contains more complex taglike things');
		}
		// parser
		$offset--;
		while($offset > -1) {
			if($this->code[$offset] === '<') {
				if($this->code[$offset + 1] === '/') {
					// found a closing tag
				} else {
					return array(array(O::tag_string($offset), $offset));
				}
			}
			$offset--;
		}
		return array(array());
	}

	function get_parent($selector, $matching_array = false, $add_to_context = true, $ignore_context = false, $parent_node_only = false, $tagged_result = false) {
		if(is_array($selector)) {
			if(!isset($selector[0])) {
				return array();
			}
			if(!is_array($selector[0])) {
				$selector = array($selector);
			}
			$parents_array = array();
			foreach($selector as $value) {
				if(!is_array($value) || !isset($value[1])) {
					continue;
				}
				$parent_offset = O::get_parent_offset_fast($value[1]);
				$parent_result = O::build_parent_result_from_offset($parent_offset, $tagged_result, $parent_node_only);
				if($tagged_result) {
					if(is_array($parent_result) && isset($parent_result[0])) {
						$parents_array[] = $parent_result[0];
					}
				} else {
					$parents_array[] = $parent_result;
				}
			}
			return $parents_array;
		} elseif(is_string($selector)) {
			$normalized_selector = O::normalize_selector($selector);
			$selector_uses_overlay = $this->_lom_selector_has_overlay($normalized_selector);
			// Keep parent reads on the same selector path so get() can reuse context/cache
			// from preceding calls in the same workload.
			if($matching_array === false || $matching_array === NULL || (is_array($matching_array) && sizeof($matching_array) === 0)) {
				$matching_key = '';
			} else {
				$matching_key = serialize(O::context_array($matching_array));
			}
			$cache_key = $normalized_selector . '|' . $matching_key . '|' . ((int)$tagged_result) . '|' . ((int)$parent_node_only);
			if(!$ignore_context && isset($this->parent_query_cache[$cache_key])) {
				return $this->parent_query_cache[$cache_key];
			}
			$selector_matches = O::get($selector, $matching_array, $add_to_context, $ignore_context, $parent_node_only, true);
			if(!is_array($selector_matches) || sizeof($selector_matches) === 0) {
				if(!$ignore_context) {
					$this->parent_query_cache[$cache_key] = array();
				}
				return array();
			}
			$result = O::get_parent($selector_matches, false, $add_to_context, $ignore_context, $parent_node_only, $tagged_result);
			if(!$ignore_context) {
				$this->parent_query_cache[$cache_key] = $result;
			}
			return $result;
		} elseif(is_numeric($selector)) {
			$parent_offset = O::get_parent_offset_fast((int)$selector);
			return O::build_parent_result_from_offset($parent_offset, $tagged_result, $parent_node_only);
		} else {
			O::fatal_error('unhandled $selector type in get_parent; $selector: ');var_dump($selector);
		}
	}

	function get_parent_depth($selector, $offset_depths = false) {
		//print('$selector in get_parent_depth: ');var_dump($selector);
		if(is_numeric($selector)) {
			if($offset_depths === false) {
				//$offset_depths = $this->offset_depths;
				$expanded_LOM = O::expand(false, $selector);
				$offset_depths = $expanded_LOM[3];
			}
			$selector = (int)$selector;
			return O::depth($selector, $offset_depths) - 1;
		} else {
			print('$selector: ');var_dump($selector);
			O::fatal_error('unhandled $selector type in get_parent_depth');
		}
		print('$selector, $this->offset_depths: ');var_dump($selector, $this->offset_depths);
		print('in get_parent_depth');exit(0);
	}

	function _ge($selector, $matching_array = false, $add_to_context = true, $ignore_context = false, $parent_node_only = false, $tagged_result = false) { // alias
		return O::get_encoded($selector, $matching_array, $add_to_context, $ignore_context, $parent_node_only, $tagged_result);
	}

	function ge($selector, $matching_array = false, $add_to_context = true, $ignore_context = false, $parent_node_only = false, $tagged_result = false) { // alias
		return O::get_encoded($selector, $matching_array, $add_to_context, $ignore_context, $parent_node_only, $tagged_result);
	}

	function ge_($selector, $matching_array = false, $add_to_context = true, $ignore_context = false, $parent_node_only = false, $tagged_result = false) { // alias
		return O::get_encoded($selector, $matching_array, $add_to_context, $ignore_context, $parent_node_only, $tagged_result);
	}

	function get_encoded($selector, $matching_array = false, $add_to_context = true, $ignore_context = false, $parent_node_only = false, $tagged_result = false) {
		// this is just to clean up the code and make it more legible
		return O::get(O::enc($selector), $matching_array, $add_to_context, $ignore_context, $parent_node_only, $tagged_result);
	}

	function _gt($selector, $matching_array = false, $add_to_context = true, $ignore_context = false, $parent_node_only = false) { // alias
		return O::get_tagged($selector, $matching_array, $add_to_context, $ignore_context, $parent_node_only, true);
	}

	function gt($selector, $matching_array = false, $add_to_context = true, $ignore_context = false, $parent_node_only = false) { // alias
		return O::get_tagged($selector, $matching_array, $add_to_context, $ignore_context, $parent_node_only, true);
	}

	function gt_($selector, $matching_array = false, $add_to_context = true, $ignore_context = false, $parent_node_only = false) { // alias
		return O::get_tagged($selector, $matching_array, $add_to_context, $ignore_context, $parent_node_only, true);
	}


	function can_use_incremental_direct_selector_get($normalized_selector) {
		return false;
		if(!is_string($normalized_selector) || $normalized_selector === '') {
			return false;
		}
		if(strpos($normalized_selector, '|') !== false) {
			return false;
		}
		O::parse_selector_string($normalized_selector);
		if(sizeof($this->selector_piece_sets) !== 1) {
			return false;
		}
		$pieces = $this->selector_piece_sets[0];
		if(sizeof($pieces) < 2) {
			return false;
		}
		$scopes = $this->selector_scope_sets[0];
		$piece_index = 0;
		while($piece_index < sizeof($pieces)) {
			$piece = $pieces[$piece_index];
			if($piece === '') {
				return false;
			}
			if(strpos($piece, '.') !== false || strpos($piece, '&') !== false) {
				return false;
			}
			if($piece_index > 0 && (!isset($scopes[$piece_index]) || $scopes[$piece_index] !== 'direct')) {
				return false;
			}
			$piece_index++;
		}
		return true;
	}

	function get_incremental_direct_selector_matches($normalized_selector, $add_to_context = true, $ignore_context = false, $parent_node_only = false, $tagged_result = false) {
		O::parse_selector_string($normalized_selector);
		$pieces = $this->selector_piece_sets[0];
		$matches = O::get($pieces[0], false, $add_to_context, $ignore_context, $parent_node_only, $tagged_result);
		if(!is_array($matches) || sizeof($matches) === 0) {
			return array();
		}
		$piece_index = 1;
		while($piece_index < sizeof($pieces)) {
			$matches = O::get($pieces[$piece_index], $matches, $add_to_context, $ignore_context, $parent_node_only, $tagged_result);
			if(!is_array($matches) || sizeof($matches) === 0) {
				return array();
			}
			$piece_index++;
		}
		return $matches;
	}

	function get_tagged($selector, $matching_array = false, $add_to_context = true, $ignore_context = false, $parent_node_only = false) {
		if($this->native_doc && $matching_array === false && O::native_selector_supported($selector) && function_exists('lom_doc_get_tagged')) {
			$r = lom_doc_get_tagged($this->native_doc, $selector);
			if(is_array($r)) {
				return $r;
			}
		}
		return O::get($selector, $matching_array, $add_to_context, $ignore_context, $parent_node_only, true);
	}

	function _($selector, $matching_array = false, $add_to_context = true, $ignore_context = false, $parent_node_only = false, $tagged_result = false) { // alias
		return O::get($selector, $matching_array, $add_to_context, $ignore_context, $parent_node_only, $tagged_result);
	}

	function g($selector, $matching_array = false, $add_to_context = true, $ignore_context = false, $parent_node_only = false, $tagged_result = false) { // alias
		return O::get($selector, $matching_array, $add_to_context, $ignore_context, $parent_node_only, $tagged_result);
	}

	function g_($selector, $matching_array = false, $add_to_context = true, $ignore_context = false, $parent_node_only = false, $tagged_result = false) { // alias
		return O::get($selector, $matching_array, $add_to_context, $ignore_context, $parent_node_only, $tagged_result);
	}

	function get($selector, $matching_array = false, $add_to_context = true, $ignore_context = false, $parent_node_only = false, $tagged_result = false) {
		$profile_token = $this->profile_function_start('get');
		try {
		/* Large docs: keep depths lazy until a path that needs them (not slim/native fastpaths). */
		if(!$this->offset_depths_pending) {
			/* already built */
		} elseif(strlen($this->code) < 8 * 1024 * 1024) {
			O::ensure_offset_depths();
		}
		$normalized_selector = false;
		//O::warning_once('need to garbage-collect the context to have good performance. use test.xml to test and garbage-collect according to scope of queries');
		//print('$selector, $matching_array, $add_to_context at start of get: ');var_dump($selector, $matching_array, $add_to_context);
		//$this->offsets_from_get = false;
		if(is_array($matching_array)) {
			if(!O::all_entries_are_arrays($matching_array)) {
				$matching_array = array($matching_array);
			}
			// if we get a matching_array with code that doesn't match $this->code then we don't add it to the context since that would corrupt the context. code in matching array can come from many places, such as a variable
			// not saved as a living variable that was not updated by external code.
			// print('$matching_array in is_array($matching_array) in get: ');var_dump($matching_array);
			// foreach($matching_array as $index => $value) {
			// 	if(substr($this->code, $matching_array[$index][1], strlen($matching_array[$index][0])) !== $matching_array[$index][0]) {
			// 		$add_to_context = false;
			// 		$ignore_context = true;
			// 		$parent_node_only = true;
			// 		break;
			// 	}
			// }
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
			$matching_array = O::get_tagged($matching_array, false, $add_to_context, $ignore_context); // not sure if we should force whether to add to context
		}
		if(is_array($matching_array) && sizeof($matching_array) === 0) {
			return array();
		}
		//print('$matching_array, sizeof($matching_array) in get: ');var_dump($matching_array, sizeof($matching_array));
		//$used_context = false;
		//print('here374859---0000<br />' . PHP_EOL);
		//print('$selector, $matching_array, $tagged_result before selector type determination in get: ');var_dump($selector, $matching_array, $tagged_result);
		//print('$selector at start of get: ');var_dump($selector);
		if(is_numeric($selector)) { // treat it as an offset
			//print('is_numeric($selector) in get<br />' . PHP_EOL);
			$selector = (int)$selector;
			//	if($this->LOM[$selector][0] === 0) { // assume that if it's text we want the text value
			//$selector_matches = array($matching_array[$selector][1]);
			//$selector_matches = $this->LOM[$selector][1];
			//		$matching_array = false;
			//$selector_matches = $this->LOM[$selector];
			//print('expanding in get()<br />' . PHP_EOL);
			/* if($selector >= strlen($this->code)) {
				*			return array();
		} */
			//$expanded_LOM = O::expand($this->code, $selector, false, false, 'greedy');
			// maybe turn this on for marginal performance boost
			//if(is_array($matching_array)) {
			//	if(sizeof($matching_array) === 1) {
			//		//$expanded_LOM = O::expand($matching_array[0][0], $selector, 0);
			//		$expanded_LOM = O::expand($matching_array[0][0], $selector - $matching_array[0][1], $matching_array[0][1]);
			//	} else {
			//		print('$matching_array: ');var_dump($matching_array);
			//		O::fatal_error('unhandled $matching_array in is_numeric($selector) in get()');
			//	}
			//} else {
			$expanded_LOM = O::expand($this->code, $selector, 0);
			//}
			//print('$selector, $matching_array, $expanded_LOM in is_numeric($selector) in get(): ');var_dump($selector, $matching_array, $expanded_LOM);
			//$expanded_LOM = O::expand($this->code, $selector, false, false, 'lazy');
			//print('$expanded_LOM in is_numeric($selector) in get(): ');var_dump($expanded_LOM);
			//$selector_matches = $expanded_LOM[1]; // historical assumption
			//if($expanded_LOM[1] === false) { // it's just text
			//	return $expanded_LOM[0][0];
			//} else {
			$selector_matches = array($expanded_LOM[0]);
			//}
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
			//print('$this->code[$selector]: ');var_dump($this->code[$selector]);
			//print('$expanded_LOM in is_numeric($selector_matches): ');var_dump($expanded_LOM);
			//print('$selector, $selector_matches in is_numeric($selector_matches): ');var_dump($selector, $selector_matches);
		} elseif(is_string($selector)) { // do XPath-type processing
			//print('is_string($selector) in get<br />' . PHP_EOL);
			if($selector[0] === '@' && strpos($selector, '_') === false && strpos($selector, '[') === false && strpos($selector, '=') === false && !$this->_lom_selector_has_overlay($selector)) {
				$attribute_name = substr($selector, 1);
				return O::get_attribute_value($attribute_name, $matching_array);
			}
			$normalized_selector = O::normalize_selector($selector);
			$selector_uses_overlay = $this->_lom_selector_has_overlay($normalized_selector);
			$get_t_context_total = ($this->profile_enabled ? O::getmicrotime() : false);
			$get_t_fastpaths_total = false;
			$get_t_select_fallback_total = false;
			// Simple single-piece attribute selectors benefit from trying the
			// dedicated attribute index path before any context scan.
			if($matching_array === false && !$selector_uses_overlay && strpos($normalized_selector, '@') !== false) {
				$early_fast_attribute_matches = O::fast_get_simple_attribute_selector($normalized_selector, $add_to_context, $ignore_context, $parent_node_only, $tagged_result);
				if($early_fast_attribute_matches !== false) {
					if($get_t_context_total !== false) {
						$this->profile_add_time('get_context_probe_total', 0);
					}
					return $early_fast_attribute_matches;
				}
			}
			//print('$normalized_selector in is_string($selector): ');var_dump($normalized_selector);
			//print('here26344<br />' . PHP_EOL);
			$selector_matches = array();
			// if we're given a matching array then we don't have to look for one
			if(is_array($matching_array) && sizeof($matching_array) > 0) {
				//print('looking in selector matches from matching_array only<br />' . PHP_EOL);
				//print('$normalized_selector, $matching_array: ');var_dump($normalized_selector, $matching_array);
				$selector_matches = O::select($normalized_selector, $matching_array);
				//print('$selector_matches when using $matching_array: ');var_dump($selector_matches);
				if(is_array($selector_matches) && sizeof($selector_matches) > 0) {
					//print('checking to see if we need to cull obsolete context entries after successful matching_array search<br />' . PHP_EOL);
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
							//print('context entry at ($context_counter is ' . $context_counter . ') is obsolete<br />' . PHP_EOL);
							unset($this->context[$context_counter]);
							//$culled_context_entry = true;
						}
						$context_counter--;
					}
					//if($culled_context_entry) {
					$this->context = array_values($this->context);
		$this->invalidate_context_selector_index();
					//}
				}
			} else {
				// check the context
				//print('check the context<br />' . PHP_EOL);
				$allow_context_probe = true;
				if(
					$matching_array === false &&
					!$selector_uses_overlay &&
					is_string($normalized_selector) &&
					strpos($normalized_selector, '_') !== false &&
					strpos($normalized_selector, '.') === false &&
					strpos($normalized_selector, '*') === false &&
					strpos($normalized_selector, '@') === false &&
					strpos($normalized_selector, '=') === false &&
					strpos($normalized_selector, '&') === false &&
					strpos($normalized_selector, '|') === false
				) {
					$allow_context_probe = false;
				}
				$context_size = sizeof($this->context);
				if($this->use_context && !$ignore_context && $allow_context_probe && $context_size > 0) {
					if($this->context_selector_index_size !== $context_size) {
						$this->rebuild_context_selector_index();
					}
					if($matching_array === false && isset($this->context_exact_false_scope_index[$normalized_selector])) {
						$exact_ci = $this->context_exact_false_scope_index[$normalized_selector];
						if(isset($this->context[$exact_ci]) && isset($this->context[$exact_ci][0]) && $this->context[$exact_ci][0] === $normalized_selector && isset($this->context[$exact_ci][1]) && $this->context[$exact_ci][1] === false) {
							$cached_exact_matches = $this->get_exact_false_scope_lom_cached($normalized_selector, $exact_ci);
							if($cached_exact_matches !== false) {
								$selector_matches = $cached_exact_matches;
								$add_to_context = false;
							}
						}
					}
					$context_counter = sizeof($this->context) - 1;
					// Avoid recomputing a derived representation of $matching_array
					// for every context entry. In perf_test.php most calls use
					// $matching_array === false.
					$matching_array_context_array = false;
					$context_lom_cache = array();
					if($matching_array !== false) {
						$matching_array_context_array = O::context_array($matching_array);
					}
					//print('$this->context at the start of is_string($selector) in get: ');O::var_dump_full($this->context);
					if($matching_array === false && isset($this->context_selector_index[$normalized_selector])) {
						$selector_context_positions = $this->context_selector_index[$normalized_selector];
						for($scp = sizeof($selector_context_positions) - 1; $scp >= 0 && sizeof($selector_matches) === 0 && !is_string($selector_matches); $scp--) {
							$ci = $selector_context_positions[$scp];
							if(
								!isset($this->context[$ci]) ||
								!isset($this->context[$ci][0]) ||
								$this->context[$ci][0] !== $normalized_selector ||
								!isset($this->context[$ci][1]) ||
								$this->context[$ci][1] !== false
							) {
								continue;
							}
							if(!isset($context_lom_cache[$ci])) {
								$context_lom_cache[$ci] = O::LOM_array($this->context[$ci][2]);
							}
							$selector_matches = $context_lom_cache[$ci];
							$add_to_context = false;
						}
					}
					while($context_counter > -1 && sizeof($selector_matches) === 0 && !is_string($selector_matches)) {
						//print('looking in context $context_counter, $this->context[$context_counter]: ');var_dump($context_counter, $this->context[$context_counter]);
						//print('$normalized_selector, $this->context[$context_counter][0], $matching_array: ');var_dump($normalized_selector, $this->context[$context_counter][0], $matching_array);
						//print('$matching_array when looking in context: ');var_dump($matching_array);
						//print('check context 0001<br />' . PHP_EOL);
						//print('check context 0002<br />' . PHP_EOL);
						if(
							$normalized_selector === $this->context[$context_counter][0] &&
							(
								($matching_array === false && isset($this->context[$context_counter][1]) && $this->context[$context_counter][1] === false) ||
								($matching_array !== false && $matching_array_context_array === $this->context[$context_counter][1])
							)
						) {
							//print('found a match by same selector and $this->context[$context_counter][1]<br />' . PHP_EOL);
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
							if(!isset($context_lom_cache[$context_counter])) {
								$context_lom_cache[$context_counter] = O::LOM_array($this->context[$context_counter][2]);
							}
							$selector_matches = $context_lom_cache[$context_counter];
							//print('$selector_matches by same selector and $this->context[$context_counter][1]:' );var_dump($selector_matches);
							$add_to_context = false;
							break;
							//return O::LOM_array($this->context[$context_counter][2]);
							/*if($tagged_result) {
								*							$context_result_is_tagged = false;
								*							if(is_string($this->context[$context_counter][3])) {
								*								if(strpos($this->context[$context_counter][3], '<') !== false) {
								*									$context_result_is_tagged = true;
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
						} /*elseif(is_array($matching_array) && sizeof($matching_array) > 0) { // don't look for anything other than exact matches in the context if a matching_array has been provided // this will never happen 2022-08-17
						print('skipping this context entry since matching array was provided but not matched<br />' . PHP_EOL);
						//print('O::context_array($matching_array), $this->context[$context_counter][2]: ');var_dump(O::context_array($matching_array), $this->context[$context_counter][2]);
						if($matching_array_context_array === $this->context[$context_counter][2]) {
							//print('found $offset_depths for this matching array from context<br />' . PHP_EOL);
							$offset_depths = $this->context[$context_counter][3];
							break;
					}
					if(sizeof($matching_array_context_array) === 1) {
						foreach($this->context[$context_counter][2] as $context_index2 => $context_value2) {
							if($matching_array_context_array[0] === $this->context[$context_counter][2][$context_index2]) {
								//print('found $offset_depths for this matching array from an element in context<br />' . PHP_EOL);
								$offset_depths = array($this->context[$context_counter][3][$context_index2]);
								break;
					}
					}
					}
					} elseif(!is_array($this->context[$context_counter][3])) { // skip context entries with only a single value
						print('here26349<br />' . PHP_EOL);
					} */elseif($matching_array === false && !$selector_uses_overlay) {
						// Overlay/comparison selectors need full-document (or dedicated fast) resolution; scoped context + postfilter used tagless() on *@… and broke *@age>16 etc.
						//print('looking in context entry when $matching_array === false<br />' . PHP_EOL);
						// need to only look in the context here if the selector is a subset of the selector in the context but how can this be known without knowing the format of the XML? could get away with it if it is assumed that tags do not contain themselves?
						// this (unusually) falls into the category of grammar rather than syntax as computers are mostly concerned with but seems to make sense given the desire to query using imprecise statements that is the purpose of this code
						/*$context_selector_is_too_specific = false;
							*						O::parse_selector_string($normalized_selector);
							*						$selector_piece_sets = $this->selector_piece_sets;
							*						//$first_selector_tag = $selector_piece_sets[0][0];
							*						//$cleaned_first_selector_tag = O::clean_selector_tag_for_context_comparison($first_selector_tag);
							*						O::parse_selector_string($this->context[$context_counter][0]);
							*						$context_selector_piece_sets = $this->selector_piece_sets;
							*						$first_context_selector_tag = $context_selector_piece_sets[0][0];
							*						$cleaned_first_context_selector_tag = O::clean_selector_tag_for_context_comparison($first_context_selector_tag);
							*						if($cleaned_first_selector_tag === '*' && $cleaned_first_context_selector_tag !== '*') { // too specific by wildcard use
							*							$context_selector_is_too_specific = true;
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
							print('context selector is too specific than selector so we go to a broader context<br />' . PHP_EOL);
							//} elseif(is_array($this->context[$context_counter][2]) && O::all_entries_are_arrays($this->context[$context_counter][2])) {
					} else {*/
						//print('using context entry to search for match<br />' . PHP_EOL);
						//print('$this->context[$context_counter][3]: ');var_dump($this->context[$context_counter][3]);
						//print('O::LOM_array($this->context[$context_counter][2]): ');var_dump(O::LOM_array($this->context[$context_counter][2]));
						//print('looking in selector matches of context entry<br />' . PHP_EOL);
						//$selector_matches = O::select($normalized_selector, O::LOM_array($this->context[$context_counter][2]), $this->context[$context_counter][3]);
						$can_use_context_match_scope = !(
							isset($this->context[$context_counter][0]) &&
							$this->context[$context_counter][0] === $normalized_selector &&
							isset($this->context[$context_counter][1]) &&
							$this->context[$context_counter][1] !== false
						);
						if($can_use_context_match_scope) {
							if(!isset($context_lom_cache[$context_counter])) {
								$context_lom_cache[$context_counter] = O::LOM_array($this->context[$context_counter][2]);
							}
							$selector_matches = O::select($normalized_selector, $context_lom_cache[$context_counter]);
							//print('$selector_matches when using context entry: ');var_dump($selector_matches);
							if(is_array($selector_matches) && sizeof($selector_matches) > 0) {
								//print('stopping looking due to empty context entry<br />' . PHP_EOL);
								$matching_array = $context_lom_cache[$context_counter];
								//$offset_depths = $this->context[$context_counter][3];
								break;
							}
						}
						// don't only look in the matches; also look in the parent in the context entry
						//print('$this->context[$context_counter] when looking in the parent in the context entry: ');var_dump($this->context[$context_counter]);
						if($this->context[$context_counter][1] != false) {
							//print('looking in parent of context entry<br />' . PHP_EOL);
							//$selector_matches = O::get($normalized_selector, $this->context[$context_counter][1], false, true, true, true);
							//print('$normalized_selector, O::LOM_array($this->context[$context_counter][1]) before select while looking for parent: ');var_dump($normalized_selector, O::LOM_array($this->context[$context_counter][1]));
							$parent_cache_key = 'p:' . $context_counter;
							if(!isset($context_lom_cache[$parent_cache_key])) {
								$context_lom_cache[$parent_cache_key] = O::LOM_array($this->context[$context_counter][1]);
							}
							$selector_matches = O::select($normalized_selector, $context_lom_cache[$parent_cache_key]);
							//print('$selector_matches from parent of context entry: ');var_dump($selector_matches);
							if(is_array($selector_matches) && sizeof($selector_matches) > 0) {
								break;
							}
						}
						/////// the offset depths of the selector matches offset depths are being passed in the above case, but not the below case of the parent... does this slow things down? should they be held in the context and adapt its structure?
						//print('found a match by doing a query in $this->context[$context_counter][3]<br />' . PHP_EOL);
						// breaks things...
						/*$overscoped = false;
							*							foreach($matching_array as $first_index => $first_value) { break; }
							*							foreach($matching_array as $last_index => $last_value) {  }
							*							foreach($this->context[$context_counter][3] as $index => $value) {
							*								if($index < $first_index || $index > $last_index) {
							*									$overscoped = true;
							*									break 2;
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
				if($get_t_context_total !== false) {
					$this->profile_add_time('get_context_probe_total', O::getmicrotime() - $get_t_context_total);
				}
				// ???
				if(is_array($matching_array) && sizeof($matching_array) === 0) {
					O::fatal_error('how is an empty $matching_array getting here??');
					return array();
				}
				//if(strlen($this->code) === 0) {
				//	O::fatal_error('how is $this->code empty??');
				//	return array();
				//}
				// if nothing's found in the context, then we have to do a fresh check
				//print('finished looking through context<br />' . PHP_EOL);
				if(is_array($selector_matches) && sizeof($selector_matches) === 0) {
					if($this->profile_enabled) {
						$get_t_fastpaths_total = O::getmicrotime();
					}
					//print('nothing was found from context<br />' . PHP_EOL);
					//print('$this->code, $this->offset_depths before select(): ');var_dump($this->code, $this->offset_depths);
					// and garbage-collect the context
					//if($matching_array === false) {
					//print('getting selector_matches by doing a straight query (on the whole code) instead of using context<br />' . PHP_EOL);
					//$selector_matches = O::select($normalized_selector, array(array($this->code, 0)));
					// if($matching_array === false && !$selector_uses_overlay) {
					// 	$fast_attribute_selector_matches = O::fast_get_simple_attribute_selector($normalized_selector, $add_to_context, $ignore_context, $parent_node_only, $tagged_result);
					// 	if($fast_attribute_selector_matches !== false) {
					// 		return $fast_attribute_selector_matches;
					// 	}
     //
					// 	$fast_selector_matches = O::fast_get_simple_direct_tag_chain($normalized_selector, $add_to_context, $ignore_context, $parent_node_only, $tagged_result);
					// 	if($fast_selector_matches !== false) {
					// 		return $fast_selector_matches;
					// 	}
					// }
					// if($matching_array === false && O::can_use_incremental_direct_selector_get($normalized_selector)) {

					//if($matching_array === false && !$selector_uses_overlay) {
					if($matching_array === false) {
						if($selector_uses_overlay) {
							$fast_overlay_attribute_selector_matches = O::fast_get_overlay_attribute_selector($normalized_selector, $add_to_context, $ignore_context, $parent_node_only, $tagged_result);
							if($fast_overlay_attribute_selector_matches !== false) {
								if($get_t_fastpaths_total !== false) {
									$this->profile_add_time('get_fastpaths_total', O::getmicrotime() - $get_t_fastpaths_total);
								}
								return $fast_overlay_attribute_selector_matches;
							}
							/* Tagvalue comparisons (incl. /regex/) use the indexed chain; do not
							 * fall straight to select just because overlay/comparison ops are present. */
							$fast_indexed_selector_matches = O::fast_get_indexed_or_wildcard_direct_chain($normalized_selector, $add_to_context, $ignore_context, $parent_node_only, $tagged_result);
							if($fast_indexed_selector_matches !== false) {
								if($get_t_fastpaths_total !== false) {
									$this->profile_add_time('get_fastpaths_total', O::getmicrotime() - $get_t_fastpaths_total);
								}
								return $fast_indexed_selector_matches;
							}
						} else {
							$fast_attribute_selector_matches = O::fast_get_simple_attribute_selector($normalized_selector, $add_to_context, $ignore_context, $parent_node_only, $tagged_result);
							if($fast_attribute_selector_matches !== false) {
								if($get_t_fastpaths_total !== false) {
									$this->profile_add_time('get_fastpaths_total', O::getmicrotime() - $get_t_fastpaths_total);
								}
								return $fast_attribute_selector_matches;
							}

							$fast_indexed_selector_matches = O::fast_get_indexed_or_wildcard_direct_chain($normalized_selector, $add_to_context, $ignore_context, $parent_node_only, $tagged_result);
							if($fast_indexed_selector_matches !== false) {
								if($get_t_fastpaths_total !== false) {
									$this->profile_add_time('get_fastpaths_total', O::getmicrotime() - $get_t_fastpaths_total);
								}
								return $fast_indexed_selector_matches;
							}

							$fast_or_selector_matches = O::fast_get_or_union_direct_chains($normalized_selector, $add_to_context, $ignore_context, $parent_node_only, $tagged_result);
							if($fast_or_selector_matches !== false) {
								if($get_t_fastpaths_total !== false) {
									$this->profile_add_time('get_fastpaths_total', O::getmicrotime() - $get_t_fastpaths_total);
								}
								return $fast_or_selector_matches;
							}

							$fast_selector_matches = O::fast_get_simple_direct_tag_chain($normalized_selector, $add_to_context, $ignore_context, $parent_node_only, $tagged_result);
							if($fast_selector_matches !== false) {
								if($get_t_fastpaths_total !== false) {
									$this->profile_add_time('get_fastpaths_total', O::getmicrotime() - $get_t_fastpaths_total);
								}
								return $fast_selector_matches;
							}
						}
					}
					if($get_t_fastpaths_total !== false) {
						$this->profile_add_time('get_fastpaths_total', O::getmicrotime() - $get_t_fastpaths_total);
					}
					$get_t_select_fallback_total = ($this->profile_enabled ? O::getmicrotime() : false);
					if($matching_array === false && O::can_use_incremental_direct_selector_get($normalized_selector)) {

						$selector_matches = O::get_incremental_direct_selector_matches($normalized_selector, $add_to_context, $ignore_context, $parent_node_only, $tagged_result);
					} else {
						$selector_matches = O::select($normalized_selector, array(array($this->code, 0)), array($this->offset_depths));
					}
					if($get_t_select_fallback_total !== false) {
						$this->profile_add_time('get_select_fallback_total', O::getmicrotime() - $get_t_select_fallback_total);
					}
					// 				if(is_array($selector_matches) && sizeof($selector_matches) > 0) {
					// 					// if a raw query on the whole code is done then the context is completely reset
					// 					print('$normalized_selector, $selector_matches, $this->context having done a raw query on the whole code: ');var_dump($normalized_selector, $selector_matches, $this->context);
					// 					//print('reseting context<br />' . PHP_EOL);
					// 					$this->context = array();
					// 				}
					/*} else {
						*					print('getting selector_matches by looking in matching_array (not the whole code)<br />' . PHP_EOL);
						*					//print('$offset_depths: ');var_dump($offset_depths);exit(0);
						*					if($offset_depths === NULL) {
						*						//O::fatal_error('$offset_depths === NULL when getting selector_matches by looking in matching_array (not the whole code)');
						*						//$offset_depths = O::get_offset_depths_of_matches($matching_array);
						*						$offset_depths = array($this->offset_depths);
				}
				//print('$offset_depths (of matching array): ');var_dump($offset_depths);
				$selector_matches = O::select($normalized_selector, $matching_array, $offset_depths);
				}*/
					//print('$normalized_selector, $selector_matches when did not match from context: ');var_dump($normalized_selector, $selector_matches);
				} else {
					//print('something was found from context<br />' . PHP_EOL);
					//$culled_context_entry = false;
					$context_counter++;
					$pre_cull_context_size = sizeof($this->context);
					while($context_counter < $pre_cull_context_size) {
						//print('culling an obsolete context entry after successful context search<br />' . PHP_EOL);
						unset($this->context[$context_counter]);
						//$culled_context_entry = true;
						$context_counter++;
					}
					//if($culled_context_entry) {
					$this->context = array_values($this->context);
		$this->invalidate_context_selector_index();
					//}
					//O::good_message('used the context instead of querying the whole LOM');
					//$used_context = true;
				}
			}
			//print('$selector, $selector_matches in is_string in get: ');var_dump($selector, $selector_matches);
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
		//print('here374859---0042<br />' . PHP_EOL);
		//print('$selector_matches, $this->context mid get: ');var_dump($selector_matches, $this->context);
		//print('$selector, $matching_array, $this->context, $selector_matches mid get: ');var_dump($selector, $matching_array, $this->context, $selector_matches);
		//print('$selector_matches mid get: ');var_dump($selector_matches);//exit(0);
		//if($selector === 'player_health') {
		//	print('debug $selector_matches mid get: ');var_dump($selector_matches);exit(0);
		//}
		// should this check be on all selector_matches and not only exported ones?
		//if(!is_array($selector_matches[0])) {
		//	print('$selector_matches: ');var_dump($selector_matches);
		//	O::warning('really confused about this... probably getting non-arrayed first selector match from some code somewhere like context');
		//	$selector_matches = array($selector_matches);
		//}
		// `data_unique()` is very expensive (serialize/unserialize). For `get_tagged()`
		// we can de-duplicate safely by node offset, which is stable and sufficient
		// to prevent duplicate matches.
		if($tagged_result) {
			$seen_offsets = array();
			$unique_matches = array();
			foreach($selector_matches as $m) {
				if(!is_array($m) || !isset($m[1])) {
					$unique_matches[] = $m;
					continue;
				}
				$offset_key = (string)$m[1];
				if(!isset($seen_offsets[$offset_key])) {
					$seen_offsets[$offset_key] = true;
					$unique_matches[] = $m;
				}
			}
			$selector_matches = $unique_matches;
		} else {
			$selector_matches = O::data_unique($selector_matches); // recursive unique via serialize/unserialize
		}
		if(is_array($matching_array) && sizeof($matching_array) === 1 && is_array($selector_matches) && sizeof($selector_matches) > 1) {
			$selector_matches = $this->filter_drop_strict_ancestor_matches($selector_matches);
		}
		if(isset($selector_uses_overlay) && $selector_uses_overlay && is_array($selector_matches) && sizeof($selector_matches) > 0 && $normalized_selector !== false && is_string($normalized_selector) && $this->selector_overlay_postfilter_applies($normalized_selector)) {
			// Square brackets: misparse as "=" (e.g. .a_b_c=tagvalue2[2]). Multi-piece (_-separated) selectors: one comparison per piece; postfilter treats the whole string as one expression and wipes results. Checked for every | branch via selector_overlay_postfilter_applies().
			$selector_matches = O::comparison_postfilter($selector_matches, $normalized_selector);
		}
		// if(isset($selector_uses_overlay) && $selector_uses_overlay && is_array($selector_matches)) {
		// 	$selector_matches = $this->comparison_postfilter($selector_matches, $normalized_selector);
		// }
		// remove whitespace from selector_matches
		$selector_matches = O::trim_whitespace($selector_matches);
		//print('$selector_matches after trimming whitespace in get(): ');var_dump($selector_matches);
		if($this->debug) {
			foreach($selector_matches as $index => $value) {
				//$string = $selector_matches[0][0];
				$string = $value[0];
				$selector_matches[$index][0] = trim($selector_matches[$index][0]); // kind of a big hack that wouldn't be necessary if expand or somewhere was more precise, but MAY be great
				$opening_angle_bracket_position = strpos($string, '<');
				$closing_angle_bracket_position = strpos($string, '>');
				if($closing_angle_bracket_position < $opening_angle_bracket_position || $opening_angle_bracket_position === false) {
					print('$string, $closing_angle_bracket_position, $opening_angle_bracket_position: ');var_dump($string, $closing_angle_bracket_position, $opening_angle_bracket_position);
					print('$selector_matches: ');var_dump($selector_matches);
					O::fatal_error('Improper result (incomplete tag) in get. This may be due to angle brackets &gt; &lt; in what <abbr title="Living Object Model">LOM</abbr> is treating as text. This may be due to an offset not pointing to the right place, which may be due to a change in the data but not in the parent node, which may be solved by using a living variable.');
				}
				//$string = $selector_matches[$index][0];
				if($string[0] !== '<') {
					print('$selector_matches: ');var_dump($selector_matches);
					O::fatal_error('string not opened properly');
				}
				if($string[strlen($string) - 1] !== '>') {
					print('$selector_matches: ');var_dump($selector_matches);
					O::fatal_error('string not closed properly');
				}
				$opening_substr_count = substr_count($string, '<');
				$closing_substr_count = substr_count($string, '>');
				if($opening_substr_count !== $closing_substr_count) {
					print('$opening_substr_count, $closing_substr_count, $string: ');var_dump($opening_substr_count, $closing_substr_count, $string);
					O::fatal_error('$opening_substr_count !== $closing_substr_count');
				}
				$opening_tags_count = O::get_number_of_opening_tags($string);
				$closing_tags_count = O::get_number_of_closing_tags($string);
				if($opening_tags_count !== $closing_tags_count) {
					print('$opening_tags_count, $closing_tags_count, $string: ');var_dump($opening_tags_count, $closing_tags_count, $string);
					O::fatal_error('$opening_tags_count !== $closing_tags_count');
				}
			}
		}
		/*
			*
			*	if(sizeof($selector_matches) === 0 || (sizeof($selector_matches) === 1 && ($selector_matches[0] === NULL || $selector_matches[0] === false))) {
			*	//if(sizeof($selector_matches) === 0 && ($selector_matches[0] === NULL || $selector_matches[0] === false)) {
			*		//print('here374859---0044<br />' . PHP_EOL);
			*		//print('$selector_matches: ');var_dump($selector_matches);
			*		//return false;
			*		// debateable whether it's better to return false or an empty array when nothing is found; coder expectation doens't really exist so ease of checking against a boolean and having different data type based on success will prevail, I guess
			*		//$selector_matches = false;
			*		// turns out an empty array still evaluates to false in an if statement so we're good? nvm
			*		$selector_matches = array();
			*		//$selector_matches = '';
			*		$add_to_context = false;
	}
	//if($add_to_context) {
	//print('$selector_matches in add_to_context: ');var_dump($selector_matches);
	//print('here374859---0045<br />' . PHP_EOL);
	//if(sizeof($selector_matches) > 0) {
	//print('here374859---0047<br />' . PHP_EOL);
	//$text_only_value = false;
	//$text_only_index = false;
	// debug
	//if(!is_array($selector_matches[0])) {
	//print('here374859---0048<br />' . PHP_EOL);
	if(is_int($selector)) {
		//print('here374859---0049<br />' . PHP_EOL);
		$new_start_indices = array($selector);
	} elseif($used_context) {
		//print('here374859---0050<br />' . PHP_EOL);
		$new_start_indices = $this->context[$context_counter + 1][2];
		//} else {
		//	print('$selector, $selector_matches, $this->context: ');var_dump($selector, $selector_matches, $this->context);
		//	O::fatal_error('!is_array($selector_matches[0])');
		//}
	} else {
		// this seems wonky
		//print('here374859---0051<br />' . PHP_EOL);
		$start_offsets = array();
		$new_start_indices = array();
		$new_selector_matches = array();
		$did_a_text_only_value = false;
		foreach($selector_matches as $index => $value) {
			//print('here374859---0052<br />' . PHP_EOL);
			$value_counter = 0;
			foreach($value as $value_index => $value_value) {
				//print('here374859---0053<br />' . PHP_EOL);
				if($value_counter === 0) {
					//print('here374859---0054<br />' . PHP_EOL);
					$start_offsets[] = $value_index;
	}
	if($value_counter === 1) {
		//print('here374859---0055<br />' . PHP_EOL);
		$text_only_index = $value_index;
		$text_only_value = $value_value[1];
	}
	$value_counter++;
	}
	if($value_counter === 3 && strlen(trim($text_only_value)) > 0) { // making the assumption that existing tags with nothing in them should only be populated with tags rather than raw text
		//if($value_counter === 3) {
		//print('here374859---0056<br />' . PHP_EOL);
		$new_start_indices[] = $text_only_index;
		$new_selector_matches[$text_only_index] = $text_only_value;
		$did_a_text_only_value = true;
	}
	}
	//print('$new_selector_matches1: ');var_dump($new_selector_matches);
	if(!$did_a_text_only_value) {
		//print('here374859---0057<br />' . PHP_EOL);
		$new_start_indices = $start_offsets;
		$new_selector_matches = $selector_matches;
	}
	// if the selection resolves to a single value then that is what's desired rather than the array of thar single value
	if(sizeof($new_selector_matches) === 1 && (is_string($new_selector_matches[$text_only_index]) || is_int($new_selector_matches[$text_only_index]) || is_float($new_selector_matches[$text_only_index]))) {
		//if(sizeof($new_selector_matches) === 1) {
		//print('here374859---0058<br />' . PHP_EOL);
		$new_start_indices = $text_only_index;
		$new_selector_matches = $text_only_value;
	}
	}
	//}
	//print('$new_selector_matches2: ');var_dump($new_selector_matches);
	*/
		// debug
		// 	if($this->debug && sizeof($new_start_indices) !== sizeof($new_selector_matches)) {
		// 		print('$selector, $matching_array, $new_start_indices, $new_selector_matches, sizeof($new_start_indices), sizeof($new_selector_matches): ');var_dump($selector, $matching_array, $new_start_indices, $new_selector_matches, sizeof($new_start_indices), sizeof($new_selector_matches));
		// 		O::fatal_error('sizeof($new_start_indices) !== sizeof($new_selector_matches)');
		// 	}

		// may be duplication of functionality of offset_depths but it does have the property of easy iteration that $this->offset_depths does not have
		//$this->offsets_from_get = array();
		//foreach($selector_matches as $index => $value) {
		//	$this->offsets_from_get[] = $value[1];
		//}
		//print('$normalized_selector, $matching_array, $selector_matches before adding to context: ');var_dump($normalized_selector, $matching_array, $selector_matches);
		//if(sizeof($selector_matches) > 0 && $add_to_context && $this->use_context && !$ignore_context && !$used_context) { // non-results (tested by sizeof) are not added to the context because the code could be updated and the context using this selector wouldn't "know". sizeof(empty string) returns 1 wierdly but conveniently
		//O::warning_once('test whether not adding to context when all text (here and in new_) is the reason test.php is not giving some proper results');
		if(is_array($selector_matches) && $add_to_context && $this->use_context && !$ignore_context) { // empty result sets are now cacheable because writes centrally invalidate derived state
			//$this->context[] = array($normalized_selector, O::context_array($matching_array), O::context_array($selector_matches));
			//$offset_depths_of_selector_matches = O::get_offset_depths_of_matches($selector_matches);
			$all_text = (sizeof($selector_matches) === 0) ? false : true;
			//foreach($offset_depths_of_selector_matches as $offset_depths) {
			foreach($selector_matches as $selector_match) {
				//if(sizeof($offset_depths) === 2) {
				if(strpos($selector_match[0], '<') === false) {

				} else {
					$all_text = false;
					break;
				}
			}
			if($all_text) {

			} else {
				//print('$normalized_selector, O::context_array($matching_array), O::context_array($selector_matches), $offset_depths_of_selector_matches when adding to context in get(): ');var_dump($normalized_selector, O::context_array($matching_array), O::context_array($selector_matches), $offset_depths_of_selector_matches);
				//$this->context[] = array($normalized_selector, O::context_array($matching_array), O::context_array($selector_matches), $offset_depths_of_selector_matches);
				//O::add_to_context($normalized_selector, O::context_array($matching_array), O::context_array($selector_matches), $offset_depths_of_selector_matches);
				O::add_to_context($normalized_selector, O::context_array($matching_array), O::context_array($selector_matches));
			}
			//print('$this->context[sizeof($this->context) - 1] after adding to context: ');var_dump($this->context[sizeof($this->context) - 1]);
		}
		//print('$selector_matches before potentially providing text-only results: ');var_dump($selector_matches);//exit(0);
		//if(sizeof($selector_matches) !== 3) { // debug
		//	O::fatal_error('should find all 3 games! (debug)');
		//}
		//print('$selector, $matching_array, $tagged_result: ');var_dump($selector, $matching_array, $tagged_result);
		//print('$tagged_result at end of get: ');var_dump($tagged_result);
		if($tagged_result === true) {
			//print('returning tagged result<br />' . PHP_EOL);
			return $selector_matches;
		}
		//print('returning untagged result<br />' . PHP_EOL);
		//$start_offsets = $start_offsets;
		//$tagged_selector_matches = $selector_matches;
		//$offsets = array();
		//$start_offsets = array();
		//$new_selector_matches = array();

		$selector_matches = O::export($selector_matches);
		//print('$selector_matches, $this->context at end of get: ');var_dump($selector_matches, $this->context);
		//print('$selector_matches at end of get after export: ');var_dump($selector_matches);//exit(0);
		//print('$this->context overview: ');O::var_dump_full($this->context);
		// validation in order to, hopefully, track down a bug
		// UGH!
		/*O::warning_once('doing time-consuming selector_matches correction');
			*	if($selector_matches > 0) {
			*		foreach($selector_matches as $index => $value) {
			*			// parser
			*			$depth = 0;
			*			$offset = 0;
			*			$string = $value[0];
			*			while($offset < strlen($string)) {
			*				if($string[$offset] === '<') {
			*					if($string[$offset + 1] === '/') {
			*						$depth--;
	} else {
		$depth++;
	}
	}
	if($depth < 0) { // cut it short
		O::warning_once('curtailed a selector_match instead of having code that doesn\'t generate overlong matches rarely');
		print('before curtail: ');var_dump($selector_matches[$index][0]);
		$selector_matches[$index][0] = substr($selector_matches[$index][0], 0, $offset);
		print('after curtail: ');var_dump($selector_matches[$index][0]);
	}
	$offset++;
	}
	}
	}*/
		return $selector_matches;
		} finally {
			$this->profile_function_stop('get', $profile_token);
		}
	}

	function trim_whitespace($selector_matches) {
		if(is_array($selector_matches)) {
			foreach($selector_matches as $selector_match_index => $selector_match) {
				// Fast-path: most tag matches start with '<' and end with '>' so there
				// is no whitespace to trim. Avoid the expensive regex scans in that case.
				$match_str = $selector_matches[$selector_match_index][0];
				$match_len = strlen($match_str);
				if($match_len === 0) {
					continue;
				}
				$first_ord = ord($match_str[0]);
				$last_ord = ord($match_str[$match_len - 1]);
				$first_is_ws = ($first_ord === 32 || $first_ord === 9 || $first_ord === 10 || $first_ord === 13);
				$last_is_ws = ($last_ord === 32 || $last_ord === 9 || $last_ord === 10 || $last_ord === 13);
				if(!$first_is_ws && !$last_is_ws) {
					continue;
				}

				preg_match('/\s+/s', strrev($match_str), $closing_whitespace_matches, PREG_OFFSET_CAPTURE);
				if(isset($closing_whitespace_matches[0][1]) && $closing_whitespace_matches[0][1] === 0) {
					$selector_matches[$selector_match_index][0] = substr($selector_matches[$selector_match_index][0], 0, strlen($selector_matches[$selector_match_index][0]) - strlen($closing_whitespace_matches[0][0]));
					// no need to change offset
				}
				preg_match('/\s+/s', $selector_matches[$selector_match_index][0], $opening_whitespace_matches, PREG_OFFSET_CAPTURE);
				//print('$opening_whitespace_matches: ');var_dump($opening_whitespace_matches);
				if(isset($opening_whitespace_matches[0][1]) && $opening_whitespace_matches[0][1] === 0) {
					$selector_matches[$selector_match_index][0] = substr($selector_matches[$selector_match_index][0], strlen($opening_whitespace_matches[0][0]));
					$selector_matches[$selector_match_index][1] += strlen($opening_whitespace_matches[0][0]);
				}
				//print('$selector_matches[$selector_match_index][0], $closing_whitespace_matches, $opening_whitespace_matches: ');var_dump($selector_matches[$selector_match_index][0], $closing_whitespace_matches, $opening_whitespace_matches);
			}
		} else {
			print('$selector_matches: ');var_dump($selector_matches);
			O::fatal_error('trim_whitespace() expects $selector_matches to be an array');
		}
		return $selector_matches;
	}

	function export($selector_matches) {
		// this could probably be optimized by using the offset_depths to figure out whether it's just text
		// if every match is text in a single tag then assume we want to return the text (see above)
		//print('$selector_matches at start of export(): ');var_dump($selector_matches);
		$all_texts_in_single_tags = true;
		$all_texts_untagged = true;
		$text_onlys = array();
		//$text_only_offsets = array();
		foreach($selector_matches as $index => $value) {
			//$string_of_match = trim($value[0]);
			$string_of_match = $value[0];
			//print('$string_of_match, substr_count($string_of_match, \'<\'), substr_count($string_of_match, \'>\'): ');var_dump($string_of_match, substr_count($string_of_match, '<'), substr_count($string_of_match, '>'));
			if($all_texts_in_single_tags) {
				if($string_of_match[0] === '<' && $string_of_match[strlen($value[0]) - 1] === '>' && substr_count($string_of_match, '<') === 2 && substr_count($string_of_match, '>') === 2) {

				} else {
					$all_texts_in_single_tags = false;
				}
			}
			if($all_texts_untagged) {
				if(strpos($string_of_match, '<') === false && strpos($string_of_match, '>') === false) {

				} else {
					$all_texts_untagged = false;
				}
			}
			$text_onlys[] = O::tagless($string_of_match);
			//$text_only_offsets[] = $value[1] + (strpos($string_of_match, '>') + 1);
			//$start_offsets[] = $value[1];
		}
		//print('$text_onlys mid export: ');var_dump($text_onlys);
		//print('$all_texts_in_single_tags: ');var_dump($all_texts_in_single_tags);
		/*if($tagged_result !== true && $all_texts_in_single_tags) {
			*		if(sizeof($selector_matches) > 0) {
			*			foreach($selector_matches as $index => $value) {
			*				//$start_offsets[] = O::LOM_index_from_offset($value[1]);
			*				//$start_offsets[] = $value[1];
			*				$new_selector_matches[] = $text_onlys[$index];
			*				$start_offsets[] = $text_only_offsets[$index];
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

		//print('$all_texts_in_single_tags, $all_texts_untagged: ');var_dump($all_texts_in_single_tags, $all_texts_untagged);
		// if there is only a single text-only result then return it as a string
		if($all_texts_in_single_tags || $all_texts_untagged) {
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
	//print('$selector_matches at end of export(): ');var_dump($selector_matches);//exit(0);
	return $selector_matches;
	}

	function import($variable) {
		O::fatal_error('conceivably we could import text with tags in it and this would be useful in some way but I do not know how it would be different from set() or new() which place content in the proper tag');
	}

	function _c($variable, $separator = false) { // alias
		return O::_implode($variable, $separator);
	}

	function cat($variable, $separator = false) { // alias
		return O::_implode($variable, $separator);
	}

	function concatenate($variable, $separator = false) { // alias
		return O::_implode($variable, $separator);
	}

	function _implode($variable, $separator = false) {
		//print('_implode0001<br />' . PHP_EOL);
		//print('$variable, $separator in _implode: ');var_dump($variable, $separator);
		if(is_array($variable)) {
			if(sizeof($variable) === 0) {
				return '';
			} elseif(O::all_entries_are_arrays($variable)) {
				$implode_array = array();
				foreach($variable as $index => $value) {
					$implode_array[] = $value[0];
				}
				return O::_implode($implode_array, $separator);
			} else {
				if($separator === false || $separator === NULL) {
					$separator = '';
				}
				return implode($separator, $variable);
			}
		} else {
			return O::_implode(O::get($variable), $separator);
		}
	}

	function _e($variable, $separator = false) { // alias
		return O::_explode($variable, $separator);
	}

	function decat($variable, $separator = false) { // alias
		return O::_explode($variable, $separator);
	}

	function decatenate($variable, $separator = false) { // alias
		return O::_explode($variable, $separator);
	}

	function _explode($variable, $separator = false) {
		if(is_string($variable)) {
			return explode($separator, $variable);
		} else {
			print('$variable, $separator: ');var_dump($variable, $separator);
			O::fatal_error('!is_string($variable) in _explode');
		}
	}

	function tagless($variable) {
		// print('tagless<br />' . PHP_EOL);
		// if(is_array($variable)) {
		// 	if(O::all_entries_are_arrays($variable)) {
		// 		$tagless_array = array();
		// 		foreach($variable as $index => $value) {
		// 			$tagless_array[] = O::tagless($value[0]);
		// 		}
		// 		if(sizeof($tagless_array) === 1) {
		// 			return $tagless_array[0]; // can't assume every array passed to this function will be in LOM format
		// 		}
		// 		return $tagless_array;
		// 	} else {
		// 		return O::tagless($variable[0]);
		// 	}
		// 	//O::fatal_error('tagless() expects string input');
		// }
		// return preg_replace('/<[^>]*>/is', '', $variable);
		if(is_array($variable)) {
			foreach($variable as $index => $value) {
				$variable[$index] = O::tagless($value);
			}
		} elseif(is_string($variable)) {
			$variable = preg_replace('/<[^>]*>/is', '', $variable);
		}
		return $variable;
	}

	function tagvalue($variable) {
		if(is_array($variable)) {
			if(sizeof($variable) === 2) {
				$variable = $variable[0];
			} else {
				print('$variable: ');var_dump($O->tagname($tag));
				O::fatal_error('not sure how to handle $variable in tagvalue()');
			}
		}
		return O::preg_replace_first('/<[^>]+>/is', '', O::preg_replace_last('/<[^>]+>/is', '', $variable));
	}

	function preg_search_escape($string) { // alias
		return O::preg_escape($string);
	}

	function preg_escape($string) {
		return str_replace('/', '\/', preg_quote($string));
	}

	function preg_replace_escape($string) { // alias
		return O::preg_escape_replacement($string);
	}

	function preg_replacement_escape($string) { // alias
		return O::preg_escape_replacement($string);
	}

	function preg_escape_replacement($string) {
		$string = str_replace('$', '\$', $string);
		$string = str_replace('{', '\{', $string);
		$string = str_replace('}', '\}', $string);
		return $string;
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

	function reverse_quantifier_components($string) {
		if($expression[0] === '{') {
			$offset = 0;
			while($offset < strlen($expression)) {
				if($expression[$offset] === '{') {
					$start = '';
					while($expression[$offset] !== ',') {
						$start .= $expression[$offset];
						$offset++;
					}
					$new_expression .= '}';
					$offset++;
					continue;
				} elseif($expression[$offset] === ',') {
					$end = '';
					while($expression[$offset] !== '}') {
						$end .= $expression[$offset];
						$offset++;
					}
					$new_expression .= strrev($end) . ',' . strrev($start);
					$offset++;
					continue;
				} elseif($expression[$offset] === '}') {
					$new_expression .= '{';
					$offset++;
					continue;
				}
				$offset++;
			}
		} else {
			$new_expression = strrev($expression);
		}
		return $new_expression;
	}

	function reverse_preg_expression($string) { // alias
		return O::reverse_preg_pattern($string);
	}

	function reverse_preg_query($string) { // alias
		return O::reverse_preg_pattern($string);
	}

	function reverse_preg($string) { // alias
		return O::reverse_preg_pattern($string);
	}

	function reverse_preg_pattern($string) {
		if($string[0] !== '/') {
			print('$string in reverse_preg_pattern: ');var_dump($string);
			O::fatal_error('reverse_preg_pattern assumes that the limiter used is / when any limiter could be used to separate the modifiers');
		}
		//if(strpos($string, '+') !== false) {
		//	print('$string in reverse_preg_pattern: ');var_dump($string);
		//	O::fatal_error('+ not properly handled in reverse_preg_pattern');
		//}
		if(strpos($string, '|') !== false) {
			print('$string in reverse_preg_pattern: ');var_dump($string);
			O::fatal_error('| not properly handled in reverse_preg_pattern');
		}
		//if(strpos($string, '*') !== false) {
		//	print('$string in reverse_preg_pattern: ');var_dump($string);
		//	O::fatal_error('* not properly handled in reverse_preg_pattern');
		//}
		//print('$string at start of reverse_preg_pattern(): ');var_dump($string);
		// woah there fella
		//preg_match('/\/(.*?)\/(.*)/', $string, $matches);
		//$expression = $matches[1];
		//$modifiers = $matches[2];
		$offset = 0;
		while($offset < strlen($string)) {
			if($string[$offset] === '/') {
				$last_slash_position = $offset;
			}
			$offset++;
		}
		$expression = substr($string, 1, $last_slash_position - 1);
		$modifiers = substr($string, $last_slash_position + 1);
		//$expression = strrev($expression);
		//$expression = preg_replace('/(.)\\\\/', '\\\\$1', $expression);
		$offset = 0;
		//$literal = '';
		//$round_depth = 0;
		//$square_depth = 0;
		//$curly_depth = 0;
		$round_offsets = array();
		$square_offsets = array();
		//$curly_offsets = array();
		//print('$expression before while loop in preg_reverse_expression: ');var_dump($expression);
		// everything with sidedness has to flip; brackets, representations of number with significance of digits defined by their order, operators like the escape operator affecting what is on one side of it, quantifiers
		// ... TO DO: move quantifiers from after to before, switch the order or bracketed expressions () and [], replace < with > and > with < even though they are not regular expression operators, switch the order of things on either side of | operator
		// ... as a moderate example to test against: /<(big)(\s+[^>]+|[^\w:\->][^>]*?)>/is may be used
		// /<(big)(\s+[^>]+|[^\w:\->][^>]*?|>{0})>/is
		// ... kinda have to decode the entire linear universe for this to work properly, which is a big ask.
		while($offset < strlen($expression)) {
			//print('$offset in while loop in preg_reverse_expression: ');var_dump($offset);
			if($expression[$offset] === '\\') {
				if($expression[$offset + 1] === '{') {
					preg_match('/[\{\}0-9]+/is', $expression, $escape_matches, PREG_OFFSET_CAPTURE, $offset + 1);
					$escaped_piece = strrev($escape_matches[0]) . '\\';
					$new_expression .= $escaped_piece;
				} else {
					$escaped_piece = $expression[$offset + 1] . '\\';
					$new_expression .= $escaped_piece;
				}
				$offset += strlen($escaped_piece);
				continue;
			} elseif($expression[$offset] === '(') {
				$round_offsets[] = $offset;
				$new_expression .= ')';
				$offset++;
				continue;
			} elseif($expression[$offset] === ')') {
				$new_expression .= '(';
				$offset++;
				// quantifiers
				$round_offset = array_pop($round_offsets);
				preg_match('/[\*\?\+\{\}\,0-9]+/is', $expression, $quantifier_matches, PREG_OFFSET_CAPTURE, $offset + 1);
				$quantifier = $quantifier_matches[0];
				$quantifier_with_reversed_components = O::reverse_quantifier_components($quantifier);
				$new_expression = substr($new_expression, 0, $round_offset) . $quantifier_with_reversed_components . substr($new_expression, $round_offset);
				continue;
			} elseif($expression[$offset] === '[') {
				$square_offsets[] = $offset;
				if($expression[$offset + 1] === '^') {
					$found_negation = true;
					$offset++;
				}
				$new_expression .= ']';
				$offset++;
				continue;
			} elseif($expression[$offset] === ']') {
				if($found_negation) {
					$new_expression .= '^';
					$found_negation = false;
				}
				$new_expression .= '[';
				$offset++;
				// quantifiers
				$square_offset = array_pop($square_offsets);
				preg_match('/[\*\?\+\{\}\,0-9]+/is', $expression, $quantifier_matches, PREG_OFFSET_CAPTURE, $offset + 1);
				$quantifier = $quantifier_matches[0];
				$quantifier_with_reversed_components = O::reverse_quantifier_components($quantifier);
				$new_expression = substr($new_expression, 0, $round_offset) . $quantifier_with_reversed_components . substr($new_expression, $round_offset);
				continue;
			}
			$new_expression .= $expression[$offset]; // literal is the default
			$offset++;
		}
		//print('$new_expression mid reverse_preg_pattern(): ');var_dump($new_expression);
		$new_expression = strrev($new_expression);
		$string = '/' . $new_expression . '/' . $modifiers;
		//print('$string at end of reverse_preg_pattern(): ');var_dump($string);
		return $string;
	}

	//function preg_match_last($pattern, $subject, &$matches, $flags = "", $offset = 0) {
	// offset and flags are not supported now (2009-08-26)
	// flags resupported? (2011-12-02) naw...
	// welp, over 10 years later (2022-02-21), gotta do it; fractal_get needs it ;p
	function preg_match_last($pattern, $subject, &$matches, $flags = false, $offset = 0) {
		//print('$pattern, $subject, $matches, $flags in preg_match_last(): ');var_dump($pattern, $subject, $matches, $flags);
		if($offset !== 0) {
			print('$pattern, $subject, $matches, $flags, $offset in preg_match_last: ');var_dump($pattern, $subject, $matches, $flags, $offset);
			O::fatal_error('$offset has not been written into preg_match_last');
		}
		preg_match(O::reverse_preg_pattern($pattern), strrev($subject), $matches2, $flags);
		//print('O::reverse_preg_pattern($pattern), strrev($subject), $matches2, $flags in preg_match_last(): ');var_dump(O::reverse_preg_pattern($pattern), strrev($subject), $matches2, $flags);
		if(is_array($matches2)) { // PREG_OFFSET_CAPTURE
			// aaaa<bb>cccc</bb>dddd
			$matches = array(array(strrev($matches2[0][0]), strlen($subject) - $matches2[0][1] - strlen($matches2[0][0])));
		} else {
			$matches = array(strrev($matches2));
		}
		//if($flags === false) {
		//	preg_match_all($pattern, $subject, $matches2);
		//	$matches = array();
		//	foreach($matches2 as $index => $value) {
		//		$matches[$index] = $value[sizeof($value) - 1];
		//	}
		//} else {
		//	preg_match_all($pattern, $subject, $matches2, $flags);
		//	$matches = array();
		//	foreach($matches2 as $index => $value) {
		//		$matches[$index] = $value[sizeof($value) - 1];
		//	}
		//}
	}

	function normalize_selector($selector) {
		if($selector[0] === '@') { // not sure if this was ever used, but now get() will look for a query beginning with @ to see whether get_attribute_value should be used instead of always treating it as a shorthand for a tag with an attribute
			$selector = '*' . $selector;
		}
		$selector = str_replace('_@', '_*@', $selector);
		// Extract /pattern/flags before rewriting path backslashes so \w etc. stay intact.
		if(strpos($selector, '/') !== false) {
			$this->selector_regex_table = array();
			$selector = O::extract_selector_regexes($selector);
		}
		$selector = str_replace('\\', '_', $selector);
		return $selector;
	}

	function rejoin_underscore_selector_pieces($pieces, $scopes) {
		if(!is_array($pieces) || sizeof($pieces) === 0) {
			return '';
		}
		$out = $this->query_encode($pieces[0]);
		if(isset($scopes[0]) && $scopes[0] === 'direct') {
			$out = '_' . $out;
		}
		for($i = 1; $i < sizeof($pieces); $i++) {
			$sep = (isset($scopes[$i]) && $scopes[$i] === 'direct') ? '_' : '__';
			$out .= $sep . $this->query_encode($pieces[$i]);
		}
		return $out;
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
			if($this->debug && $LOM_index === false) { // debug
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
			*	$first_entry = false;
			*	$first_entry_is_tagless = false;
			*	if(is_array($selector_matches)) {
			*		if(is_array($selector_matches[0])) {
			*			$offsets = array();
			*			foreach($selector_matches as $index => $value) {
			*				if($first_entry === false) {
			*					$first_entry = $value[0];
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
		*	return O::get_index($selector, $matching_array, $add_to_context, $ignore_context, $parent_node_only);
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
			*	foreach($this->offsets_from_get as $index_from_get) {
			*
	}
	return ;*/
	}

	function get_offsets($selector, $matching_array = false, $add_to_context = true, $ignore_context = false, $parent_node_only = false) {
		O::warning('get_offsets() seems obsoleted by get_offset_depths. any code that uses get_offsets() should probably use get_offsets_depths()');
		//print('$selector, $matching_array in get_offsets: ');var_dump($selector, $matching_array);
		if(is_array($selector) && !O::all_entries_are_arrays($selector)) {
			return array($selector[1]);
		}
		//get($selector, $matching_array = false, $add_to_context = true, $ignore_context = false)
		O::get($selector, $matching_array, $add_to_context, $ignore_context, $parent_node_only);
		return $this->offsets_from_get;
		/*
			*	if($matching_array === false) {
			*		$matching_array = array($this->LOM);
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
			//print('here249702667-9<br />' . PHP_EOL);
			while($context_counter > -1 && sizeof($selector_matches) === 0 && !is_string($selector_matches)) {
				//print('here249702668-0<br />' . PHP_EOL);
				//print('getting selector_matches from context in get_offsets<br />' . PHP_EOL);
				//print('$context_counter: ');var_dump($context_counter);
				//print('$this->context: ');O::var_dump_full($this->context);
				if($normalized_selector === $this->context[$context_counter][0] && $matching_array === $this->context[$context_counter][1]) {
					//print('here249702668-1<br />' . PHP_EOL);
					if(is_array($this->context[$context_counter][2])) {
						//print('here249702668-1.1<br />' . PHP_EOL);
						return $this->context[$context_counter][2];
	} else {
		//print('here249702668-1.2<br />' . PHP_EOL);
		return array($this->context[$context_counter][2]);
	}
	} elseif(!is_array($this->context[$context_counter][3])) { // skip context entries with only a single value
		//print('here249702668-2<br />' . PHP_EOL);
	} else {
		//print('here249702668-3<br />' . PHP_EOL);
		$first_selector_tag = O::parse_selector_string($normalized_selector)[0][0];
		$cleaned_first_selector_tag = O::clean_selector_tag_for_context_comparison($first_selector_tag);
		$first_context_selector_tag = O::parse_selector_string($this->context[$context_counter][0])[0][0];
		$cleaned_first_context_selector_tag = O::clean_selector_tag_for_context_comparison($first_context_selector_tag);
		//print('$first_selector_tag, $cleaned_first_selector_tag, $first_context_selector_tag, $cleaned_first_context_selector_tag: ');var_dump($first_selector_tag, $cleaned_first_selector_tag, $first_context_selector_tag, $cleaned_first_context_selector_tag);
		//print('$this->context: ');var_dump($this->context);
		if($cleaned_first_selector_tag === $cleaned_first_context_selector_tag || $cleaned_first_selector_tag === '*' || $cleaned_first_context_selector_tag === '*') {
			//print('here249702668-3.1<br />' . PHP_EOL);
	} else {
		//print('here249702668-3.2<br />' . PHP_EOL);
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
	//print('here249702668-4<br />' . PHP_EOL);
	//print('$selector_matches in get_offsets1: ');var_dump($selector_matches);
	if(sizeof($selector_matches) === 0) {
		//print('here249702668-5<br />' . PHP_EOL);
		//print('getting selector_matches from $this->LOM in get_offsets<br />' . PHP_EOL);
		// ??
		$selector_matches = O::select($normalized_selector, $matching_array);
		//foreach($matching_array as $first_index => $first_value) { break; }
		//if(is_array($matching_array[$first_index])) {
		//	print('here249702668-5.1<br />' . PHP_EOL);
		//	$selector_matches = O::select($normalized_selector, array($matching_array));
		//} else {
		//	print('here249702668-5.2<br />' . PHP_EOL);
		//	$selector_matches = O::select($normalized_selector, $matching_array);
		//}
	}
	//print('here249702668-6<br />' . PHP_EOL);
	//print('$selector_matches in get_offsets2: ');var_dump($selector_matches);
	if(sizeof($selector_matches) === 1 && (strpos($normalized_selector, '_') !== false || $matching_array !== false || sizeof($selector_matches[0] === 3))) {
		//print('here249702668-7<br />' . PHP_EOL);
		$value_counter = 0;
		$text_only_value = false;
		$text_only_index = false;
		foreach($selector_matches[0] as $value_index => $value_value) {
			//print('here249702668-8<br />' . PHP_EOL);
			if($value_counter === 1) {
				$text_only_value = $value_value[1];
				$text_only_index = $value_index;
	}
	$value_counter++;
	}
	if($value_counter === 3 && strlen(trim($text_only_value)) > 0) { // making the assumption that existing tags with nothing in them should only be populated with tags rather than raw text
		//if($value_counter === 3) {
		//print('here249702668-9<br />' . PHP_EOL);
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

	function is_in_an_opening_tag($offset, $code = false) { // alias
		return O::is_in_opening_tag($offset, $code);
	}

	function is_in_opening_tag($offset, $code = false) {
		if($code === false) {
			$code = $this->code;
		}
		if(is_array($code)) {
			if(sizeof($code) === 1 && is_array($code[0]) && sizeof($code[0]) === 2 && is_string($code[0][0])) {
				$code = $code[0][0];
			} else {
				print('$code in is_in_opening_tag: ');var_dump($code);
				O::fatal_error('is_in_opening_tag expects $code to be a string');
			}
		}
		$position = $offset;
		while(($position = strpos($code, '>', $position + 1)) !== false) {
			//print('$position: ');var_dump($position);
			if($this->must_check_for_self_closing && $code[$position - 1] === '/') { // self-closing tag
				//print('self-closing tag at position: ' . $position . '<br />' . PHP_EOL);
				return false;
			} elseif($this->must_check_for_non_parsed_character_data && substr($code, $position - 2, 2) === ']]') { // non-parsed character data
				//print('non-parsed character data at position: ' . $position . '<br />' . PHP_EOL);
				return false;
			} elseif($this->must_check_for_comment && substr($code, $position - 2, 2) === '--') { // comment
				//print('comment at position: ' . $position . '<br />' . PHP_EOL);
				return false;
			} elseif($this->must_check_for_programming_instruction && $code[$position - 1] === '?') { // programming instruction
				//print('programming instruction at position: ' . $position . '<br />' . PHP_EOL);
				return false;
			} elseif($this->must_check_for_ASP && $code[$position - 1] === '%') { // ASP
				//print('ASP at position: ' . $position . '<br />' . PHP_EOL);
				return false;
			} else { // closing or opening tag
				//print('closing or opening tag at position2: ' . $position2 . '<br />' . PHP_EOL);
				$code = strrev(substr($code, 0, $offset));
				$position2 = 0;
				while(($position2 = strpos($code, '<', $position2 + 1)) !== false) {
					//print('$position2: ');var_dump($position2);
					if($code[$position2 - 1] === '/') { // closing tag
						//print('closing tag at position2: ' . $position2 . '<br />' . PHP_EOL);
						return false;
					} elseif($this->must_check_for_doctype && (substr($code, $position2 - 8, 8) === 'EPYTCOD!' || substr($code, $position2 - 8, 8) === 'epytcod!')) { // doctype
						//print('non-parsed character data at position2: ' . $position2 . '<br />' . PHP_EOL);
						return false;
					} elseif($this->must_check_for_non_parsed_character_data && substr($code, $position2 - 8, 8) === '[ATADC[!') { // non-parsed character data
						//print('non-parsed character data at position2: ' . $position2 . '<br />' . PHP_EOL);
						return false;
					} elseif($this->must_check_for_comment && substr($code, $position2 - 3, 3) === '--!') { // comment
						//print('comment at position2: ' . $position2 . '<br />' . PHP_EOL);
						return false;
					} elseif($this->must_check_for_programming_instruction && $code[$position2 - 1] === '?') { // programming instruction
						//print('programming instruction at position2: ' . $position2 . '<br />' . PHP_EOL);
						return false;
					} elseif($this->must_check_for_ASP && $code[$position2 - 1] === '%') { // ASP
						//print('ASP at position2: ' . $position2 . '<br />' . PHP_EOL);
						return false;
					} else { // opening tag
						//print('opening tag at position2: ' . $position2 . '<br />' . PHP_EOL);
						return true;
					}
				}
			}
		}
		//return true;
	}

	function is_in_other_markup($offset, $code = false) {
		//return false; // hack
		if(!$this->must_check_for_doctype && !$this->must_check_for_non_parsed_character_data && !$this->must_check_for_comment && !$this->must_check_for_programming_instruction && !$this->must_check_for_ASP) {
			return false;
		}
		if($code === false) {
			$code = $this->code;
		}
		if(is_array($code)) {
			if(sizeof($code) === 1 && is_array($code[0]) && sizeof($code[0]) === 2 && is_string($code[0][0])) {
				$code = $code[0][0];
			} else {
				print('$code in is_in_other_markup: ');var_dump($code);
				O::fatal_error('is_in_other_markup expects $code to be a string');
			}
		}
		// need to generalize to handle other markup sections more than simply a document header; although this is very common
		if($code !== $this->code) {
			return false;
		}
		if($offset > $this->document_header_end_offset) {
			return false;
		}
		//print('at the start of is_in_other_markup $offset, $code: ');var_dump($offset, $code);
		//print('iiom001<br />' . PHP_EOL);
		$position = $offset;
		while(($position = strpos($code, '>', $position + 1)) !== false) {
			//print('$position: ');var_dump($position);
			if($this->must_check_for_non_parsed_character_data && substr($code, $position - 2, 2) === ']]') { // non-parsed character data
				//print('non-parsed character data at position: ' . $position . '<br />' . PHP_EOL);
				if(strpos(substr($code, $offset, $position), '<![CDATA[') !== false) {
					continue;
				}
				//print('iiom002<br />' . PHP_EOL);
				return true;
			} elseif($this->must_check_for_comment && substr($code, $position - 2, 2) === '--') { // comment
				if(strpos(substr($code, $offset, $position), '<!--') !== false) {
					continue;
				}
				//print('iiom003<br />' . PHP_EOL);
				//print('comment at position: ' . $position . '<br />' . PHP_EOL);
				return true;
			} elseif($this->must_check_for_programming_instruction && $code[$position - 1] === '?') { // programming instruction
				if(strpos(substr($code, $offset, $position), '<?') !== false) {
					continue;
				}
				//print('iiom004<br />' . PHP_EOL);
				//print('programming instruction at position: ' . $position . '<br />' . PHP_EOL);
				return true;
			} elseif($this->must_check_for_ASP && $code[$position - 1] === '%') { // ASP
				if(strpos(substr($code, $offset, $position), '<%') !== false) {
					continue;
				}
				//print('iiom005<br />' . PHP_EOL);
				//print('ASP at position: ' . $position . '<br />' . PHP_EOL);
				return true;
			}
		}
		//print('iiom006<br />' . PHP_EOL);
		$code = strrev(substr($code, 0, $offset));
		if(strlen($code) > 0) {
			$position2 = 0;
			//print('going reverse in is_in_other_markup $code, $position2, $offset: ');var_dump($code, $position2, $offset);
			while(($position2 = strpos($code, '<', $position2 + 1)) !== false) {
				//print('$position2: ');var_dump($position2);
				if($this->must_check_for_doctype && (substr($code, $position2 - 8, 8) === 'EPYTCOD!' || substr($code, $position2 - 8, 8) === 'epytcod!')) { // doctype
					if(strpos(substr($code, 0, $position2), '>') !== false) {
						continue;
					}
					//print('iiom007<br />' . PHP_EOL);
					//print('non-parsed character data at position2: ' . $position2 . '<br />' . PHP_EOL);
					return true;
				} elseif($this->must_check_for_non_parsed_character_data && substr($code, $position2 - 8, 8) === '[ATADC[!') { // non-parsed character data
					if(strpos(substr($code, 0, $position2), '>]]') !== false) {
						continue;
					}
					//print('iiom008<br />' . PHP_EOL);
					//print('non-parsed character data at position2: ' . $position2 . '<br />' . PHP_EOL);
					return true;
				} elseif($this->must_check_for_comment && substr($code, $position2 - 3, 3) === '--!') { // comment
					if(strpos(substr($code, 0, $position2), '>--') !== false) {
						continue;
					}
					//print('iiom009<br />' . PHP_EOL);
					//print('comment at position2: ' . $position2 . '<br />' . PHP_EOL);
					return true;
				} elseif($this->must_check_for_programming_instruction && $code[$position2 - 1] === '?') { // programming instruction
					if(strpos(substr($code, 0, $position2), '>?') !== false) {
						continue;
					}
					//print('iiom010<br />' . PHP_EOL);
					//print('programming instruction at position2: ' . $position2 . '<br />' . PHP_EOL);
					return true;
				} elseif($this->must_check_for_ASP && $code[$position2 - 1] === '%') { // ASP
					if(strpos(substr($code, 0, $position2), '>%') !== false) {
						continue;
					}
					//print('iiom011<br />' . PHP_EOL);
					//print('ASP at position2: ' . $position2 . '<br />' . PHP_EOL);
					return true;
				}
			}
		}
		return false;
	}

	function has_attribute_with_value($attribute_name, $attribute_value, $selector) {
		if(!is_string($attribute_name)) {
			print('$attribute_name: ');var_dump($attribute_name);
			O::fatal_error('non-string $attribute_name is not handled in has_attribute_with_value');
		}
		if(!is_string($attribute_value)) {
			print('$attribute_value: ');var_dump($attribute_value);
			O::fatal_error('non-string $attribute_value is not handled in has_attribute_with_value');
		}
		if(is_numeric($selector)) {
			$selector = (int)$selector;
			//print('expanding in has_attribute_with_value()<br />' . PHP_EOL);
			//$expanded_LOM = O::expand($this->code, $selector, false, false, 'lazy');
			$expanded_LOM = O::expand($this->code, $selector, false);
			$opening_tag_string = $expanded_LOM[0][0];
		} elseif(is_string($selector)) {
			if(strpos($selector, '>') === false) {
				return O::get_attribute_value($attribute_name, O::get_tagged($selector));
			} else {
				$opening_tag_string = substr($selector, 0, strpos($selector, '>'));
			}
		} else {
			if(!is_array($selector) || !isset($selector[0])) {
				return false;
			}
			if(is_array($selector[0])) {
				$opening_tag_string = substr($selector[0][0], 0, strpos($selector[0][0], '>'));
			} else {
				$opening_tag_string = substr($selector[0], 0, strpos($selector[0], '>'));
			}
		}
		if(preg_match('/ ' . $attribute_name . '="([^"]+)"/', $opening_tag_string, $matches)) {
			if($matches[1] === $attribute_value) {
				return true;
			}
		}
		return false;
	}

	function has_attribute($attribute_name, $selector) {
		if((!is_string($attribute_name) && is_string($selector)) || (is_string($attribute_name) && is_string($selector) && strpos($attribute_name, $selector) !== false)) { // swap them
			$temp_selector = $selector;
			$selector = $attribute_name;
			$attribute_name = $temp_selector;
		}
		if(is_numeric($selector)) {
			$selector = (int)$selector;
			//print('expanding in has_attribute()<br />' . PHP_EOL);
			//$expanded_LOM = O::expand($this->code, $selector, false, false, 'lazy');
			$expanded_LOM = O::expand($this->code, $selector, false);
			$opening_tag_string = $expanded_LOM[0][0];
		} elseif(is_string($selector)) {
			if(strpos($selector, '>') === false) {
				return O::get_attribute_value($attribute_name, O::get_tagged($selector));
			} else {
				$opening_tag_string = substr($selector, 0, strpos($selector, '>'));
			}
		} else {
			if(!is_array($selector) || !isset($selector[0])) {
				return false;
			}
			if(is_array($selector[0])) {
				$opening_tag_string = substr($selector[0][0], 0, strpos($selector[0][0], '>'));
			} else {
				$opening_tag_string = substr($selector[0], 0, strpos($selector[0], '>'));
			}
		}
		return(preg_match('/ ' . $attribute_name . '="([^"]+)"/', $opening_tag_string, $matches));
	}

	function get_attribute_value($attribute_name, $selector) {
		//print('$attribute_name, $selector before smart parameters in get_attribute_value: ');var_dump($attribute_name, $selector);
		if((!is_string($attribute_name) && is_string($selector)) || (is_string($attribute_name) && is_string($selector) && strpos($attribute_name, $selector) !== false)) { // swap them
			$temp_selector = $selector;
			$selector = $attribute_name;
			$attribute_name = $temp_selector;
		}
		//print('$attribute_name, $selector in get_attribute_value: ');var_dump($attribute_name, $selector);
		if(is_numeric($selector)) {
			$selector = (int)$selector;
			//print('expanding in get_attribute_value()<br />' . PHP_EOL);
			//$expanded_LOM = O::expand($this->code, $selector, false, false, 'lazy');
			$expanded_LOM = O::expand($this->code, $selector, false);
			$opening_tag_string = $expanded_LOM[0][0];
		} elseif(is_string($selector)) {
			if(strpos($selector, '>') === false) {
				return O::get_attribute_value($attribute_name, O::get_tagged($selector));
			} else {
				$opening_tag_string = substr($selector, 0, strpos($selector, '>'));
			}
		} else {
			if(!is_array($selector) || !isset($selector[0])) {
				return false;
			}
			if(is_array($selector[0])) {
				$opening_tag_string = substr($selector[0][0], 0, strpos($selector[0][0], '>'));
			} else {
				$opening_tag_string = substr($selector[0], 0, strpos($selector[0], '>'));
			}
		}
		if(!preg_match('/ ' . $attribute_name . '="([^"]+)"/', $opening_tag_string, $matches)) {
			return false;
		}
		return $matches[1];
		/*
			*	//print('$attribute_name, $matching_array in get_attribute_value: ');var_dump($attribute_name, $matching_array);
			*	if(!is_array($matching_array)) { // assume it's an index
			*		$attributes_array = O::get_tag_attributes($matching_array);
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

	function new_attribute($attribute_name, $new_value, $selector) {
		return O::set_attribute($attribute_name, $new_value, $selector);
	}

	function remove_attribute($attribute_name, $selector) {
		//print('remove attribute0001<br />' . PHP_EOL);
		$attribute_name = (string)$attribute_name;
		$selector_matches = O::get_tagged($selector);
		// needs personalized attention and can't use replace()?
		//print('$attribute_name, $selector, $selector_matches in remove_attribute: ');var_dump($attribute_name, $selector, $selector_matches);
		//foreach($selector_matches as $index => $value) {
		$index = sizeof($selector_matches) - 1; // have to go in reverse order
		while($index > -1) {
			$string = $selector_matches[$index][0];
			$offset = $selector_matches[$index][1];
			//$tagname = O::tagname($string);
			//$this->code = O::set_tag_attribute($this->code, $attribute_name, $new_value, $tagname, $offset);
			// not sure if it's valid code to not have the leading space... but I've NEVER seen it!
			$new_attribute_string = '';
			if(O::has_attribute($attribute_name, $string)) {
				$old_attribute_string = ' ' . $attribute_name . '="' . O::get_attribute_value($attribute_name, $string) . '"';
				//$offset_adjust = strlen($new_value) - O::get_attribute($attribute_name, $string);
				$attribute_offset = strpos($string, $old_attribute_string, 1) + $offset;
				O::replace($old_attribute_string, $new_attribute_string, $attribute_offset);
			}
			//print('$this->context in remove_attribute: ');var_dump($this->context);
			$index--;
		}
		//print('remove attribute0005<br />' . PHP_EOL);
		//print('$this->code, $selector_matches at the end of remove_attribute: ');var_dump($this->code, $selector_matches);
		O::invalidate_runtime_caches_only();
		if(sizeof($selector_matches) === 1) { // questionable
			return $selector_matches[0];
		} else {
			return $selector_matches;
		}
	}

	function set_attribute($attribute_name, $new_value, $selector) {
		//print('set attribute0001<br />' . PHP_EOL);
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
			*		$temp_new_value = $new_value;
			*		$new_value = $attribute_name;
			*		$attribute_name = $temp_new_value;
	}*/
		//print('set attribute0002<br />' . PHP_EOL);

		$selector_matches = O::get_tagged($selector);
		// needs personalized attention and can't use replace()?
		//print('$attribute_name, $new_value, $selector, $selector_matches after swapping in set_attribute: ');var_dump($attribute_name, $new_value, $selector, $selector_matches);
		//foreach($selector_matches as $index => $value) {
		$index = sizeof($selector_matches) - 1; // have to go in reverse order
		while($index > -1) {
			$string = $selector_matches[$index][0];
			$offset = $selector_matches[$index][1];
			//$tagname = O::tagname($string);
			//$this->code = O::set_tag_attribute($this->code, $attribute_name, $new_value, $tagname, $offset);
			// not sure if it's valid code to not have the leading space... but I've NEVER seen it!
			$new_attribute_string = ' ' . $attribute_name . '="' . $new_value . '"';
			if(O::has_attribute($attribute_name, $string)) {
				$old_attribute_string = ' ' . $attribute_name . '="' . O::get_attribute_value($attribute_name, $string) . '"';
				//$offset_adjust = strlen($new_value) - O::get_attribute($attribute_name, $string);
				$attribute_offset = strpos($string, $old_attribute_string, 1) + $offset;
			} else {
				$old_attribute_string = '';
				//$offset_adjust = strlen($new_attribute_string);
				$closing_angle_bracket_position = strpos($string, '>', 1);
				if(substr($string, $closing_angle_bracket_position - 2, 2) === ' /') { // self-closing tag
					$closing_position = $closing_angle_bracket_position - 2;
				} elseif($string[$closing_angle_bracket_position - 1] === '/') { // self-closing tag (no space)
					$closing_position = $closing_angle_bracket_position - 1;
				} else {
					$closing_position = $closing_angle_bracket_position;
				}
				$attribute_offset = $closing_position + $offset;
			}
			//print('set attribute0003<br />' . PHP_EOL);
			//print('$attribute_name, $new_value, $offset, $offset_adjust, $tagname, $string in set_attribute: ');var_dump($attribute_name, $new_value, $offset, $offset_adjust, $tagname, $string);
			//print('before adjust_offsets in set_attribute<br />' . PHP_EOL);
			//O::adjust_offsets($offset + 1, $offset_adjust); // +1 since we're dealing with attributes after the start of the tag
			//print('$selector_matches[$index][0], $attribute_name, $new_value, $tagname, $offset, $this->context[sizeof($this->context) - 1][3][$index]: ');var_dump($selector_matches[$index][0], $attribute_name, $new_value, $tagname, $offset, $this->context[sizeof($this->context) - 1][3][$index]);
			//$selector_matches[$index][0] = O::set_tag_attribute($selector_matches[$index][0], $attribute_name, $new_value, $tagname, $offset, $this->context[sizeof($this->context) - 1][3][$index]); // assumes that get_tagged above put the offest_depths we want into the context
			//$selector_matches[$index][0] = O::set_tag_attribute($selector_matches[$index][0], $attribute_name, $new_value, $tagname, 0, $offset, $this->context[sizeof($this->context) - 1][3][$index]); // assumes that get_tagged above put the offest_depths we want into the context
			//foreach($selector_matches as $index2 => $value2) {
			//	if($selector_matches[$index2][1] > $offset) { // > instead of >= since we're dealing with attributes after the start of the tag
			//		$selector_matches[$index2][1] += $offset_adjust;
			//	}
			//}
			//print('$old_attribute_string, $new_attribute_string, $attribute_offset, $this->context in set_attribute: ');var_dump($old_attribute_string, $new_attribute_string, $attribute_offset, $this->context);
			O::replace($old_attribute_string, $new_attribute_string, $attribute_offset);
			//print('set attribute0004<br />' . PHP_EOL);

			//print('here376561<br />' . PHP_EOL);
			/*if($this->use_context) {
				*			foreach($this->context as $context_index => $context_value) {
				*				if($context_value[1] !== false) {
				*					foreach($context_value[1] as $context1_index => $context1_value) {
				*						if($context1_value[0] <= $offset && $context1_value[0] + $context1_value[1] > $offset) {
				*							$this->context[$context_index][1][$context1_index][1] += $offset_adjust;
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
		}*/
			//print('$this->context in set_attribute: ');var_dump($this->context);
			$index--;
		}
		//print('set attribute0005<br />' . PHP_EOL);
		//print('$this->code, $selector_matches at the end of set_attribute: ');var_dump($this->code, $selector_matches);
		O::invalidate_runtime_caches_only();
		if(sizeof($selector_matches) === 1) { // questionable
			return $selector_matches[0];
		} else {
			return $selector_matches;
		}
	}

	//function set_tag_attribute($code, $attribute_name, $attribute_value, $tagname = false, $offset = 0, $offset_to_add = 0, $offset_depths = false) {
	function set_tag_attribute($code, $attribute_name, $attribute_value, $tagname = false, $offset = 0, $offset_to_add = 0) {
		//print('$code, $attribute_name, $attribute_value, $offset, $tagname: ');var_dump($code, $attribute_name, $attribute_value, $offset, $tagname);
		if($code[$offset] !== '<') {
			print('$code, $attribute_name, $attribute_value, $offset, $tagname: ');var_dump($code, $attribute_name, $attribute_value, $offset, $tagname);
			O::fatal_error('set_tag_attribute was unable to find the tag to set the attribute of.');
		}
		if($tagname === false) {
			O::fatal_error('please provide set_tag_attribute with a tagname.');
		}
		// if($offset_depths === false) {
		// 	$offset_depths = $this->offset_depths;
		// //	$offset_depths = O::get_offset_depths(substr($code, $offset), $offset + $offset_to_add, O::depth($offset + $offset_to_add));
		// }
		// if($offset_depths == false) {
		// 	if($code === $this->code) {
		// 		$offset_depths = $this->offset_depths;
		// 	} else {
		// 		$offset_depths = O::get_offset_depths($code, 0, O::depth($offset_to_add));
		// 	}
		// }
		//print('$code, $attribute_name, $attribute_value, $offset, $tagname in set_tag_attribute: ');var_dump($code, $attribute_name, $attribute_value, $offset, $tagname);
		if($offset === 0) {
			$initial_opening_tag_string = $opening_tag_string = substr($code, 0, strpos($code, '>') + 1);
		} else {
			//print('expanding in set_tag_attribute()<br />' . PHP_EOL);
			//$expanded_LOM = O::expand($code, $offset, $offset_to_add, $offset_depths, 'lazy');
			$expanded_LOM = O::expand($code, $offset, $offset_to_add);
			//print('$expanded_LOM in set_tag_attribute: ');var_dump($expanded_LOM);
			$initial_opening_tag_string = $opening_tag_string = $expanded_LOM[1][0];
		}
		$initial_opening_tag_string = $opening_tag_string = substr($opening_tag_string, 0, strpos($opening_tag_string, '>') + 1); // since the expanded part could have children tags
		$opening_tag_string = preg_replace('/ ' . $attribute_name . '="[^"]{0,}"/is', '', $opening_tag_string); // not accounting for attributes without attribute values or single quotes
		//$opening_tag_string = preg_replace('/(<' . $this->tagname_regex . ')([^>]{0,})([\s\/]{0,}>)/is', '$1 ' . $attribute_name . '="' . $attribute_value . '"$2$3', $opening_tag_string);
		$opening_tag_string = preg_replace('/(<' . $this->tagname_regex . ')(\s+[^>]+|[^\w:\->][^>]*?|>{0})>)/is', '$1 ' . $attribute_name . '="' . O::preg_replacement_escape($attribute_value) . '"$2$3', $opening_tag_string);
		//print('$initial_opening_tag_string, $opening_tag_string in set_tag_attribute: ');var_dump($initial_opening_tag_string, $opening_tag_string);
		return substr($code, 0, $offset) . $opening_tag_string . substr($code, $offset + strlen($initial_opening_tag_string));
	}

	function attribute_add($attribute_name, $to_add, $selector) { // alias
		return O::add_to_attribute($attribute_name, $to_add, $selector);
	}

	function attr_add($attribute_name, $to_add, $selector) { // alias
		return O::add_to_attribute($attribute_name, $to_add, $selector);
	}

	function att_add($attribute_name, $to_add, $selector) { // alias
		return O::add_to_attribute($attribute_name, $to_add, $selector);
	}

	function add_to_att($attribute_name, $to_add, $selector) { // alias
		return O::add_to_attribute($attribute_name, $to_add, $selector);
	}

	function add_to_attr($attribute_name, $to_add, $selector) { // alias
		return O::add_to_attribute($attribute_name, $to_add, $selector);
	}

	function add_to_attribute($attribute_name, $to_add, $selector) {
		if(is_numeric($attribute_name) && !is_numeric($to_add)) { // swap them
			$temp_to_add = $to_add;
			$to_add = $attribute_name;
			$attribute_name = $temp_to_add;
		}
		return O::set_attribute($attribute_name, O::get_attribute($attribute_name, $selector) + $to_add, $selector);
	}

	function inc_attribute($attribute_name, $selector) { // alias
		return O::increment_attribute($attribute_name, $selector);
	}

	function inc_att($attribute_name, $selector) { // alias
		return O::increment_attribute($attribute_name, $selector);
	}

	function inc_attr($attribute_name, $selector) { // alias
		return O::increment_attribute($attribute_name, $selector);
	}

	function increment_att($attribute_name, $selector) { // alias
		return O::increment_attribute($attribute_name, $selector);
	}

	function increment_attr($attribute_name, $selector) { // alias
		return O::increment_attribute($attribute_name, $selector);
	}

	function increment_attribute($attribute_name, $selector) {
		return O::set_attribute($attribute_name, O::get_attribute($attribute_name, $selector) + 1, $selector);
		/*if(is_array($attribute_name) && !is_array($selector)) { // swap them
			*		$temp_selector = $selector;
			*		$selector = $attribute_name;
			*		$attribute_name = $temp_selector;
	}*/
		//print('$selector in increment_attribute: ');var_dump($selector);
		/*if(!is_array($selector)) { // assume it's an index
			*		$index = $selector;
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
			*		$index = $selector;
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

	function dec_attribute($attribute_name, $selector) { // alias
		return O::decrement_attribute($attribute_name, $selector);
	}

	function dec_att($attribute_name, $selector) { // alias
		return O::decrement_attribute($attribute_name, $selector);
	}

	function dec_attr($attribute_name, $selector) { // alias
		return O::decrement_attribute($attribute_name, $selector);
	}

	function decrement_att($attribute_name, $selector) { // alias
		return O::decrement_attribute($attribute_name, $selector);
	}

	function decrement_attribute($attribute_name, $selector) {
		return O::set_attribute($attribute_name, O::get_attribute($attribute_name, $selector) - 1, $selector);
	}

	function select($selector, $matching_array = false, $offset_depths_of_matches = false) {
		$profile_token = $this->profile_function_start('select');
		try {
		//function select($selector, $matching_array = false) {
		//print('start of select()<br />' . PHP_EOL);
		//print('$this->code at start of select: ');var_dump($this->code);
		//print('$selector, $matching_array, $offset_depths at start of select: ');var_dump($selector, $matching_array, $offset_depths);
		if($matching_array === false) {
			$matching_array = array(array($this->code, 0));
		}
		if(O::all_entries_are_arrays($matching_array)) {

		} else {
			$matching_array = array($matching_array);
		}
		if($offset_depths_of_matches === false) {
			// //	$offset_depths = $this->offset_depths;
			// 	//$offset_depths = array();
			// 	//foreach($matching_array as $index => $value) {
			// 	//$index = sizeof($matching_array) - 1;
			// 	//while($index > -1) { // very important to go in reverse_order?
			// 	//	$value = $matching_array[$index];
			// 	//	$offset_depths[] = $this->offset_depths;
			// 	//	$index--;
			// 	//}
			// 	$offset_depths = O::get_offset_depths_of_matches($matching_array);
			$offset_depths_of_matches = O::get_offset_depths_of_matches($matching_array);
		}
		//print('$selector, $matching_array, $offset_depths_of_matches in select: ');var_dump($selector, $matching_array, $offset_depths_of_matches);
		O::parse_selector_string($selector);
		// Optimization: `preg_select()` re-parses selector pieces every time it's called.
		// When the selector has a single piece-set (common case for perf_test.php), we
		// can pre-parse once here and have `preg_select()` skip that work.
		$this->selector_pieces_preparsed = false;
		if(is_array($this->selector_piece_sets) && sizeof($this->selector_piece_sets) === 1 && is_array($this->selector_piece_sets[0])) {
			$pieces = $this->selector_piece_sets[0];
			$parsed_ok = true;
			for($spi = 0; $spi < sizeof($pieces); $spi++) {
				O::parse_selector_piece($pieces[$spi], $spi);
				// parse_selector_piece populates tagnames/tagvalues/etc for the given selector_piece_index
			}
			$this->selector_pieces_preparsed = $parsed_ok;
		}
		//print('$selector, $this->selector_piece_sets: ');var_dump($selector, $this->selector_piece_sets);
		$selector_matches = array();
		//print('$this->offset_depths in select(): ');var_dump($this->offset_depths);
		foreach($matching_array as $index => $value) {
			//$index = sizeof($matching_array) - 1;
			//while($index > -1) { // very important to go in reverse_order?
			//	$value = $matching_array[$index];
			//$selector_matches = array_merge($selector_matches, O::preg_select($value[0], $value[1], $offset_depths[$index]));
			//print('$value in $matching_array in select(): ');var_dump($value);
			$pmatches = O::preg_select($value[0], $value[1], $offset_depths_of_matches[$index]);
			if(is_array($pmatches) && sizeof($pmatches) > 0) {
				foreach($pmatches as $pmatch) {
					$selector_matches[] = $pmatch;
				}
			}
			//$selector_matches = array_merge($selector_matches, O::preg_select($value[0], $value[1], $this->offset_depths)); // debug
			//	$index--;
		}
		/*print('$offset_depths in select before testing format: ');var_dump($offset_depths);
			*	if(O::all_entries_are_arrays($offset_depths)) {
			*
	} else {
		$new_offset_depths = array();
		foreach($selector_matches as $index => $value) {
			$new_offset_depths[] = O::get_offset_depths(substr($this->code, $selector_matches[$index][1])); // depths aren't correct but that's probably fine for the purposes of get_tag_string
	}
	$offset_depths = $new_offset_depths;
	}*/
		//print('$selector_matches, $matching_array, $offset_depths in select before get_tag_string: ');var_dump($selector_matches, $matching_array, $offset_depths);
		foreach($selector_matches as $index => $value) {
			//$selector_matches[$index][0] = O::get_tag_string(substr($this->code, $selector_matches[$index][1]), strlen($selector_matches[$index][0]), $selector_matches[$index][1]);
			//$selector_matches[$index][0] = O::get_tag_string($selector_matches[$index][1], O::get_offset_depths(substr($this->code, $selector_matches[$index][1]))); // hard to say whether it's more efficient to make a new offset_depths array or just use that of the whole code, in this instance
			//$selector_matches[$index][0] = O::get_tag_string($selector_matches[$index][1], $this->offset_depths); // I'd have to guess that some extraneous looping over an array will on average be faster than making an array
			$selector_matches[$index][0] = O::get_tag_string($selector_matches[$index][1]); // extract the node string for this offset
			// if you want to get real fancy, then consider some condition like "if we're near the end of the code"... but how to make that exact?
		}
		//print('$selector_matches at the end of select: ');var_dump($selector_matches);
		$this->selector_pieces_preparsed = false;
		return O::apply_qualified_result_indexes($selector_matches);
		} finally {
			$this->profile_function_stop('select', $profile_token);
		}
	}

	function preg_expression_from_selector_piece($selector_piece_index) {
		//print('un here 002<br />' . PHP_EOL);
		//print('$selector_piece_index at start of preg_expression_from_selector_piece: ');var_dump($selector_piece_index);
		$match_any_tagname = false;
		$tagname_component = '';
		$multi_and = sizeof($this->tagnames[$selector_piece_index]) > 1;
		$this->selector_piece_multi_and[$selector_piece_index] = $multi_and;
		//print('$this->tagnames at start of preg_expression_from_selector_piece: ');var_dump($this->tagnames);
		if($multi_and) {
			$tagname = $this->tagnames[$selector_piece_index][0];
			if($tagname === '*') {
				$match_any_tagname = true;
				$tagname_component = $this->tagname_regex;
			} else {
				$tagname_component = $tagname;
			}
		} else {
			foreach($this->tagnames[$selector_piece_index] as $tagname_index => $tagname) {
				//if($tagname[0] === '.') {
				//	$tagname = substr($tagname, 1); // ignore the dot operator . in this function; that is the job of select to handle... unnecessary since the dot is already removed when creating $this->tagnames
				//}
				$tagname_component .= $tagname . '|';
				if($tagname === '*') {
					$match_any_tagname = true;
					break;
				}
			}
			//print('$match_any_tagname at start of preg_expression_from_selector_piece: ');var_dump($match_any_tagname);
			//O::warning_once('forcing inefficient tagname_regex in recursive_select() for benchmarking'); if(true) {
			if($match_any_tagname) {
				$tagname_component = $this->tagname_regex;
			} else {
				$tagname_component = substr($tagname_component, 0, strlen($tagname_component) - 1);
			}
		}
		//$attributes_component = '[]|\s+[^>]';
		// (a)?b(?(1)c|d)
		$attributes_component = '\s+[^>]+|[^\w:\->][^>]*?|>{0}'; // this was tricky to account for empty attributes string, self-closing tags, while still not allowing for the possibility of undercatching tagnames e.g. <a id="top"> and <audio> in the same document; all while not using round brackets () inside
		// attributes are too complicated (processing intensive) to do in general usage HERE but important to do FRACTAL
		/*$attributes_component = '';
			*	if(strpos($selector_piece, '[') !== false) { // default to linear selection since the linear counter in the selector piece requires it
			*		$attributes_component = '[^>]{0,}';
	} else {
		$attributes_component = '[^>]{0,}';
		if(strpos($selector_piece, '@') !== false) {
			print('$this->required_attributes in fractal_get in preg_select: ');var_dump($this->required_attributes);exit(0);
			$attributes_string_array = array();
			foreach($this->required_attributes as $required_attribute_name => $required_attribute_value) {
				if($required_attribute_value === false) {
					$required_attribute_value_component = ''; // there are potential attributes without attribute values in HTML4 (nowrap) but mainly if it's not specified we allow it to ba anything
	} else {
		$required_attribute_value_component = '="' . $required_attribute_value . '"';
	}
	$attributes_string_array[] = '\s+' . $required_attribute_name . $required_attribute_value_component . '[^>]{0,}';
	}
	// permutations
	array_merge_recursive

	//	# $arr = array_intersect_key($arr, array_unique(array_map('serialize', $arr)));
	//	$arr = array_intersect_key($arr, array_unique(array_map(function ($el) {
	//		return $el['user_id'];
	//	}, $arr)));

	$matches[0] = array_unique($matches[0]);
	$matches[1] = array_unique($matches[1]);
	$matches[2] = array_unique($matches[2]);
	}
	}*/
		//print('$tagname_component at start of preg_expression_from_selector_piece: ');var_dump($tagname_component);
		//if(sizeof($this->tagvalues[$selector_piece_index]) > 1) {
		//	print('$this->tagvalues[$selector_piece_index]: ');var_dump($this->tagvalues[$selector_piece_index]);
		//	O::fatal_error('sizeof($this->tagvalues[$selector_piece_index]) > 1 not handled in preg_expression_from_selector_piece()');
		//}
		// it's left up to matching functions rather than this regular expression to handle all the permutations of tagnames, attributes and tagvalues that can match be selector_piece_index but not with any of each other
		//print('$this->tagnames, $this->tagvalues: ');var_dump($this->tagnames, $this->tagvalues);
		$tagvalue_exists = false;
		$match_any_tagvalue = false;
		$tagvalue_component = '';
		if($multi_and) {
			$tagvalue_list = array($this->tagvalues[$selector_piece_index][0]);
		} else {
			$tagvalue_list = $this->tagvalues[$selector_piece_index];
		}
		foreach($tagvalue_list as $tagvalue_index => $tagvalue) {
			if($tagvalue !== false) {
				if(O::lom_regex_token_id($tagvalue) !== false) {
					$tagvalue_exists = true;
					$match_any_tagvalue = true; // real check via compare() on regex match arrays
					continue;
				}
				//$tagvalue_component .= $tagvalue . '|';
				$tagvalue_component .= O::preg_escape($tagvalue) . '|';
				$tagvalue_exists = true;
			} else {
				$match_any_tagvalue = true;
			}
		}
		if($tagvalue_exists) {
			if($match_any_tagvalue) {
				$tagvalue_component = '[^>]+';
			} else {
				$tagvalue_component = substr($tagvalue_component, 0, strlen($tagvalue_component) - 1);
			}
			$tagvalue_key = $multi_and ? 0 : $tagvalue_index;
			$tag_op = $this->tagvalue_comparison_operators[$selector_piece_index][$tagvalue_key];
			if($tag_op === false || $tag_op === '') {
				$tag_op = '=';
			}
			if($this->tagnames[$selector_piece_index][$tagvalue_key] === '*' && $tag_op === '=') {
				$tag_op = '%=';
			}
			//print('$this->tagnames[$selector_piece_index][$tagvalue_index], $this->tagvalues[$selector_piece_index][$tagvalue_index]: ');var_dump($this->tagnames[$selector_piece_index][$tagvalue_index], $this->tagvalues[$selector_piece_index][$tagvalue_index]);
			//print('$this->tagvalue_comparison_operators, $this->tagvalue_comparison_operators[$selector_piece_index][$tagvalue_index]: ');var_dump($this->tagvalue_comparison_operators, $this->tagvalue_comparison_operators[$selector_piece_index][$tagvalue_index]);
			switch($tag_op) {
				case "=": /*print('case = (tagvalue_comparison_operators)<br />' . PHP_EOL);*/ $tagvalue_component = $tagvalue_component; break; // no change
				case "!=": /*print('case != (tagvalue_comparison_operators)<br />' . PHP_EOL);*/ $tagvalue_component = '[^>]+'; break; // real check in O::compare(); inline regex cannot do word- or token-level rules (e.g. *$=do on "domination")
				case "^=": /*print('case ^= (tagvalue_comparison_operators)<br />' . PHP_EOL);*/ $tagvalue_component = '[^>]+'; break;
				case "$=": /*print('case $= (tagvalue_comparison_operators)<br />' . PHP_EOL);*/ $tagvalue_component = '[^>]+'; break;
				case "%=": /*print('case %= (tagvalue_comparison_operators)<br />' . PHP_EOL);*/ $tagvalue_component = '[^>]*?' . $tagvalue_component . '[^>]*?'; break;
				case "~=": /*print('case ~= (tagvalue_comparison_operators)<br />' . PHP_EOL);*/ $tagvalue_component = '[^>]+'; break;
				//case "|=": return ($left === $right) || (strpos($left,$right."-") === 0);
				case ">": O::fatal_error('regular expression to check for greater than may be impossible');
				case "<": O::fatal_error('regular expression to check for less than may be impossible');
				case ">=": O::fatal_error('regular expression to check for greater than or equal may be impossible');
				case "<=": O::fatal_error('regular expression to check for less than or equal may be impossible');
				default: O::fatal_error('unhandled tagvalue_comparison_operators: ' . $this->tagvalue_comparison_operators[$selector_piece_index][$tagvalue_key]);
			}

			$tagvalue_component = '(' . $tagvalue_component . ')';
			$closing_tag_component = '<\/\1>';
		} else {
			$tagvalue_component = '';
			$closing_tag_component = '';
		}
		//print('$tagvalue_component at end of preg_expression_from_selector_piece: ');var_dump($tagvalue_component);
		$preg_expression = '/<(' . $tagname_component . ')(' . $attributes_component . ')>' . $tagvalue_component . $closing_tag_component . '/is';
		//print('$preg_expression at end of preg_expression_from_selector_piece: ');var_dump($preg_expression);
		//print('O::reverse_preg_expression($preg_expression) "for fun": ');var_dump(O::reverse_preg_expression($preg_expression));
		return $preg_expression;
	}

	function depth_match($child_offset, $parent_offset, $offset_depths = false) {
		//function depth_match($child_offset, $parent_offset) {
		if($offset_depths === false) {
			//$offset_depths = $this->offset_depths;
			$expanded_LOM = O::expand(false, $parent_offset);
			$offset_depths = $expanded_LOM[3];
		}
		$minimum_depth = $offset_depths[$parent_offset] + 1;
		//$minimum_depth = $this->offset_depths[$parent_offset] + 1;
		$maximum_depth = $minimum_depth;
		foreach($this->selector_scopes as $selector_scope_index => $selector_scope_value) {
			if($selector_scope_value === 'direct') {
				$maximum_depth++;
			} else {
				$maximum_depth = 9001; // https://www.youtube.com/watch?v=SiMHTK15Pik
				break;
			}
		}
		$child_depth = $offset_depths[$child_offset];
		//$child_depth = $this->offset_depths[$child_offset];
		if($child_depth >= $minimum_depth && $child_depth <= $maximum_depth) {
			return true;
		}
		return false;
	}

	function preg_select($code = false, $offset_to_add = 0, $offset_depths = false) {
		//function preg_select($code = false, $offset_to_add = 0) {
		//O::warning_once('preg_select needs to become a lot more sophisticated; including handling all the query syntax and expanding under the condition of finding a full result, look only in direct children, should check again that $this->code is not used, etc.');
		if($code === false) {
			$code = $this->code;
		}
		// if($offset_depths === false) {
		// 	$offset_depths = $this->offset_depths;
		// }
		// if($offset_depths == false) {
		// 	if($code === $this->code) {
		// 		$offset_depths = $this->offset_depths;
		// 	} else {
		// 		$offset_depths = O::get_offset_depths($code, 0, O::depth($offset_to_add));
		// 	}
		// }
		// if($offset_frame == false) {
		// 	$offset_frame = O::get_offset_frame($offset_to_add);
		// }
		// if($this->debug) {
		// 	if(!is_array($offset_depths)) {
		// 		print('$offset_depths: ');var_dump($offset_depths);
		// 		O::fatal_error('!is_array($offset_depths) at start of preg_select');
		// 	}
		// }
		//	$expanded_LOM = O::expand($code, 0, $offset_to_add);
		//	$offset_depths = $expanded_LOM[3];
		//print('$code, $offset_to_add, $offset_depths at the start of preg_select: ');var_dump($code, $offset_to_add, $offset_depths);

		$selector_matches = array();
		// these 3 saved indices variables were probably a previous attempt that is redundant now though still uncommented-out lol
		//$this->saved_tagname_indices = false;
		//	$this->saved_tagvalue_indices = false;
		//	$this->saved_attributes_indices = false;
		$initial_code = $code;
		$initial_offset_to_add = $offset_to_add;
		foreach($this->selector_piece_sets as $this->selector_piece_set_index => $this->selector_pieces) {
			$code = $initial_code;
			$offset_to_add = $initial_offset_to_add;
			//print('fractal_here0000<br />' . PHP_EOL);
			$fractally_get = true;
			//O::parse_selector_piece($this->selector_pieces[0], 0);
			//if(strpos($this->selector_pieces[0], '[') !== false) {
			//	$fractally_get = false;
			//}
			// thought I could get away with only doing the first before clipping; but both the start and end selector pieces are needed
			$skip_piece_parsing = $this->selector_pieces_preparsed && sizeof($this->selector_piece_sets) === 1;
			$selector_piece_index = 0;
			while($selector_piece_index < sizeof($this->selector_pieces)) {
				if(!$skip_piece_parsing) {
					O::parse_selector_piece($this->selector_pieces[$selector_piece_index], $selector_piece_index);
				}
				if(strpos($this->selector_pieces[$selector_piece_index], '[') !== false) {
					$fractally_get = false; // default to linear
				}
				$selector_piece_index++;
			}
			// fractal_get matches tag text with an exact-string regex and cannot handle ^=, $=, *= (*=al), ~=, !=, or wildcard * with substring =.
			if($fractally_get) {
				for($spi = 0; $spi < sizeof($this->selector_pieces); $spi++) {
					$ops = $this->tagvalue_comparison_operators[$spi];
					if(!is_array($ops)) {
						continue;
					}
					foreach($ops as $tvi => $top) {
						$eff_top = ($top === false || $top === '') ? '=' : $top;
						if($eff_top !== '=') {
							$fractally_get = false;
							break 2;
						}
						if(isset($this->tagnames[$spi][$tvi]) && $this->tagnames[$spi][$tvi] === '*') {
							$fractally_get = false;
							break 2;
						}
						if(isset($this->tagvalues[$spi][$tvi]) && O::lom_regex_token_id($this->tagvalues[$spi][$tvi]) !== false) {
							$fractally_get = false;
							break 2;
						}
					}
					if(isset($this->required_attribute_sets[$spi]) && is_array($this->required_attribute_sets[$spi])) {
						foreach($this->required_attribute_sets[$spi] as $attr_set) {
							if(!is_array($attr_set)) {
								continue;
							}
							foreach($attr_set as $an => $av) {
								if($av !== false && O::lom_regex_token_id($av) !== false) {
									$fractally_get = false;
									break 3;
								}
							}
						}
					}
				}
			}
			// Fractal reverse-walk mis-handles required attributes on non-terminal pieces (e.g. person@age=16_name=sally).
			if($fractally_get && sizeof($this->selector_pieces) > 1) {
				for($spi = 0; $spi < sizeof($this->selector_pieces) - 1; $spi++) {
					if(isset($this->required_attribute_sets[$spi][0]) && is_array($this->required_attribute_sets[$spi][0]) && sizeof($this->required_attribute_sets[$spi][0]) > 0) {
						$fractally_get = false;
						break;
					}
				}
			}
			//print('$fractally_get after checking for [: ');var_dump($fractally_get);
			// clip the start
			//print('$code, $this->tagnames before clipping start: ');var_dump($code, $this->tagnames);
			$shallowest_start_position = strlen($code);
			foreach($this->tagnames[0] as $tagname_index => $tagname) {
				//print('un here 001<br />' . PHP_EOL);
				//print('O::preg_expression_from_selector_piece(0): ');var_dump(O::preg_expression_from_selector_piece(0));
				//print('un here 003<br />' . PHP_EOL);
				preg_match(O::preg_expression_from_selector_piece(0), $code, $start_position_matches, PREG_OFFSET_CAPTURE);
				$start_position = isset($start_position_matches[0][1]) ? $start_position_matches[0][1] : false;
				if(is_numeric($start_position)) {
					if($start_position < $shallowest_start_position) {
						$shallowest_start_position = $start_position;
					}
				}
			}
			$code = substr($code, $shallowest_start_position);
			if($code == false) { // false, null, string of length zero, etc
				continue;
			}
			$offset_to_add += $shallowest_start_position;
			// also don't waste time on code that is past the point where the deepest thing we are looking for occurs (clip the end) here we can get fancy; the essence of fractal_get
			//print('$code, $this->tagvalues before clipping end: ');var_dump($code, $this->tagvalues);
			// $deepest_end_position = 0;
			// //print('clippy001<br />' . PHP_EOL);
			// //print('$this->tagnames: ');var_dump($this->tagnames);
			// foreach($this->tagnames[sizeof($this->selector_pieces) - 1] as $tagname_index => $tagname) {
			// 	//print('clippy002<br />' . PHP_EOL);
			// 	if($tagname === '*') {
			// 		$tagname = $this->tagname_regex;
			// 	}
			// 	if($this->tagvalues[0][0] === false) { // there may be no tagvalues in the selector piece, in which case; match the closing tag alone without a tagvalue
			// 		//print('clippy003<br />' . PHP_EOL);
			// 		$clip_end_regex = '/<\/' . $tagname . '>/is';
			// 		O::preg_match_last($clip_end_regex, $code, $end_position_matches, PREG_OFFSET_CAPTURE);
			// 		$end_position = $end_position_matches[0][1] + strlen($end_position_matches[0][0]);
			// 		if(is_numeric($end_position)) {
			// 			if($end_position > $deepest_end_position) {
			// 				$deepest_end_position = $end_position;
			// 			}
			// 		}
			// 	} else {
			// 		//print('clippy004<br />' . PHP_EOL);
			// 		foreach($this->tagvalues[sizeof($this->selector_pieces) - 1] as $tagvalue_index => $tagvalue) {
			// 			//print('clippy005<br />' . PHP_EOL);
			// 			$clip_end_regex = '/>' . $tagvalue . '<\/' . $tagname . '>/is';
			// 			O::preg_match_last($clip_end_regex, $code, $end_position_matches, PREG_OFFSET_CAPTURE);
			// 			$end_position = $end_position_matches[0][1] + strlen($end_position_matches[0][0]);
			// 			if(is_numeric($end_position)) {
			// 				if($end_position > $deepest_end_position) {
			// 					$deepest_end_position = $end_position;
			// 				}
			// 			}
			// 		}
			// 	}
			// }
			// //print('clippy006<br />' . PHP_EOL);
			// $code = substr($code, 0, $deepest_end_position);
			//print('$code after clipping end: ');var_dump($code);
			if($code == false) { // false, null, string of length zero, etc
				continue;
			}
			// it probably really hurts performance to use preg_match_all instead of preg_match stopping on the first match; oh well, it was nice while it lasted
			// could still get fancier for the perfectionist; could include attributes in the criteria, could cull offset_depths
			// also check fractal_depths (love that fancy shit)
			// what about stepping using offset_depths??? :) could do it: fractal_get
			// is there a better way to select the parent (with the '.' operator) then shoving a match from fractal_get into linear get (select)?
			// also; wouldn't need to linear get (select) if all the things (selected parent, attributes matching, other?) were done by fractal get
			// spooky thought: is it somehow possible to do the index matching ~~~fractally??

			$this->selector_scopes = $this->selector_scope_sets[$this->selector_piece_set_index];
			//$this->fractal_depths = $this->fractal_depth_sets[$this->selector_piece_set_index];
			// parse selector pieces here instead of in resursive_select both to prevent duplications and have the the results from the last piece to look at for fractal_get
			// array(/*' ', */'_', '@', '=', '&', '[', ']', '.', '*', '|');
			//print('fractal_here0001<br />' . PHP_EOL);
			$selector_scope_index = 1; // skip the first index which will (almost) always be indicating offspring of any depth
			while($selector_scope_index < sizeof($this->selector_scopes)) {
				if($this->selector_scopes[$selector_scope_index] !== 'direct') {
					$fractally_get = false; // default to linear
					break;
				}
				$selector_scope_index++;
			}
			//print('$fractally_get: ');var_dump($fractally_get);
			//print('fractal_here0002<br />' . PHP_EOL);
			if($fractally_get) {
				//print('fractal_here0003<br />' . PHP_EOL);
				// fractal_get (a subfunction? fractfuntion? regardless, it's great)
				// since we're fractal, recursive_select works from the start, and here we'll work from the end
				//O::warning('working from the end for fractal_get');
				// initialization
				$selector_piece_index--;
				$fractal_matches = array(array($code, false, false));
				//print('$fractal_matches after initialization before fractal getting: ');var_dump($fractal_matches);
				//$fractal_offset_to_add = $offset_to_add;
				//print('fractal_here0004<br />' . PHP_EOL);
				while($selector_piece_index > -1) {
					//$this->debug_counter++;
					//if($this->debug_counter > 100) {
					//	O::fatal_error('$this->debug_counter > 100');
					//}
					//print('fractal_here0005<br />' . PHP_EOL);
					//print('$selector_piece_index, $fractal_matches at start of while loop in fractal get: ');var_dump($selector_piece_index, $fractal_matches);
					// go in reverse order trying to match the deepest part: tagvalue, attributes, tagname
					// HARD-CODING  \/
					$tagname_index = 0; // have to be very careful here! we've never had more than one tagname but it could~ happen :}
					if(isset($fractal_matches[0][2]) && $fractal_matches[0][2] !== false) {
						// step up one depth
						//print('fractal_here0005.1<br />' . PHP_EOL);
						foreach($fractal_matches as $fractal_index => $fractal_value) {
							//print('fractal_here0005.2<br />' . PHP_EOL);
							$fractal_code = $fractal_value[0];
							$fractal_offset = $fractal_value[1];
							$fractal_depth = $fractal_value[2];
							//print('$fractal_code, $fractal_offset, $fractal_depth, $initial_code before get_parent: ');var_dump($fractal_code, $fractal_offset, $fractal_depth, $initial_code);
							//$parent = O::get_parent($fractal_offset, false, false, true, false, true);
							//$parent = O::get_parent_stringwise($fractal_offset);
							$parent = O::get_parent($fractal_offset, array(array($initial_code, $offset_to_add)), false, true, false, true);
							// confusing since selector matches when tag matching will be an array with a string-offset pair (since only one parent is possible) instead of the possibly expected array with a single entry containing a string-offset pair
							//print('$fractal_offset, $parent: ');var_dump($fractal_offset, $parent);
							//$fractal_matches[$fractal_index] = array($parent[0], $parent[1], $fractal_depth - 1, $fractal_offset);
							$fractal_matches[$fractal_index] = array($parent[0][0], $parent[0][1], $fractal_depth - 1);
						}
					}
					//print('$fractal_matches after possibly broadening: ');var_dump($fractal_matches);
					$new_fractal_matches = array();
					//print('fractal_here0006<br />' . PHP_EOL);
					foreach($fractal_matches as $fractal_index => $fractal_value) {
						//print('fractal_here0006.5<br />' . PHP_EOL);
						//unset($fractal_matches[$fractal_index]);
						$fractal_code = $fractal_value[0];
						//print('$fractal_code when fractal getting: ');var_dump($fractal_code);
						if($fractal_value[1] !== false) {
							$offset_to_add = $fractal_value[1];
						}
						$minimum_depth = $selector_piece_index;
						$maximum_depth = 9001; // https://www.youtube.com/watch?v=SiMHTK15Pik
						if($fractal_value[2] !== false) {
							if($this->selector_scopes[$selector_piece_index] === 'direct') {
								$minimum_depth = $fractal_value[2];
							}
							$maximum_depth = $fractal_value[2];
						}
						//if($fractal_value[3] == false) {
						//	$child_offset = strlen($fractal_code);
						//} else {
						//	$child_offset = $fractal_value[3];
						//}
						//print('fractal_here0007<br />' . PHP_EOL);
						$tagvalue = $this->tagvalues[$selector_piece_index][$tagname_index];
						//print('$tagvalue in fractal_get: ');var_dump($tagvalue);
						if($tagvalue !== false) {
							// should only look for tagvalue in last selector piece btw
							//print('fractal_here0008 fractal matching by tagvalue<br />' . PHP_EOL);
							// what about whitespace? hasn't come up yet, and presumably only would in content that we wouldn't really query?
							if($this->tagnames[$selector_piece_index][$tagname_index] === '*') {
								$tagname_regex = $this->tagname_regex;
							} else {
								$tagname_regex = $this->tagnames[$selector_piece_index][$tagname_index];
							}
							preg_match_all('/<(' . $tagname_regex . ')(\s+[^>]+|[^\w:\->][^>]*?|>{0})>' . O::preg_escape($tagvalue) . '<\/\1>/is', $fractal_code, $tagvalue_matches, PREG_OFFSET_CAPTURE);
							//print('$selector_piece_index, $fractal_code, $tagvalue_matches: ');var_dump($selector_piece_index, $fractal_code, $tagvalue_matches);
							// reassess based on the paradigm of a structured universe; rather than the multidimensional fractal paradigm
							//foreach($tagvalue_matches[0] as $tagvalue_index => $tagvalue_value) {
							//	if(!isset($this->offset_depths[$tagvalue_value[1]])) { // implicitly exclude results like within comments or programming instructions
							//		unset($tagvalue_matches[0][$tagvalue_index]);
							//		unset($tagvalue_matches[1][$tagvalue_index]);
							//		unset($tagvalue_matches[2][$tagvalue_index]);
							//	}
							//}
							//print('after considering structure in fractal_get $tagvalue_matches: ');var_dump($tagvalue_matches);//exit(0);
							// function tag_match($fractal_code, $offset = 0, $offset_to_add = 0, $selector_piece_index = 0, $offset_depths = false, $minimum_depth = false, $maximum_depth = false) {
							foreach($tagvalue_matches[0] as $tagvalue_index => $tagvalue_value) {
								// get_parent($selector, $matching_array = false, $add_to_context = true, $ignore_context = false, $parent_node_only = false, $tagged_result = false) {
								//$parent = O::get_parent($tagvalue_matches[0][$tagvalue_index][1] + 1, array($fractal_code, $offset_to_add), false, true, true, true); // nope obsolete
								//print('$fractal_code, $tagvalue_matches[0][$tagvalue_index][1], $offset_to_add, $offset_depths at first fractal expand: ');var_dump($fractal_code, $tagvalue_matches[0][$tagvalue_index][1], $offset_to_add, $offset_depths);
								//$expanded_LOM = O::expand($fractal_code, $tagvalue_matches[0][$tagvalue_index][1], $offset_to_add, $offset_depths);
								//$expanded_LOM = O::expand($fractal_code, $tagvalue_matches[0][$tagvalue_index][1], $offset_to_add, false);
								$expanded_LOM = O::expand($fractal_code, $tagvalue_matches[0][$tagvalue_index][1], $offset_to_add);
								if(O::tag_match($fractal_code, $expanded_LOM[0][1] - $offset_to_add, $offset_to_add, $selector_piece_index, $offset_depths, $minimum_depth, $maximum_depth)) {
									//if(O::tag_match($fractal_code, $expanded_LOM[0][1] - $offset_to_add, $offset_to_add, $selector_piece_index, $minimum_depth, $maximum_depth)) {
									//print('tag_match in fractal matching by tagvalue<br />' . PHP_EOL);
									//print('$expanded_LOM, $offset_to_add, $offset_depths, O::depth($expanded_LOM[0][1], $offset_depths): ');var_dump($expanded_LOM, $offset_to_add, $offset_depths, O::depth($expanded_LOM[0][1], $offset_depths));
									$new_fractal_matches[] = array($expanded_LOM[0][0], $expanded_LOM[0][1], $expanded_LOM[2] + 1);
									//$new_fractal_matches[] = array($expanded_LOM[0][0], $expanded_LOM[0][1], O::depth($expanded_LOM[0][1]));
									//print('$new_fractal_matches[sizeof($new_fractal_matches) - 1][2]: ');var_dump($new_fractal_matches[sizeof($new_fractal_matches) - 1][2]);
									if($this->debug) {
										if(O::depth($expanded_LOM[0][1]) === NULL) {
											print('$expanded_LOM[0][1], O::depth($expanded_LOM[0][1]), $this->offset_depths: ');var_dump($expanded_LOM[0][1], O::depth($expanded_LOM[0][1]), $this->offset_depths);
											O::fatal_error('did not find depth in fractal match 1!');
										}
									}
								}
							}
						} else {
							//print('fractal_here0008.5 fractal matching not by tagvalue<br />' . PHP_EOL);
							// if($selector_piece_index < sizeof($this->selector_pieces) - 1) {
							// 	$fractal_code = substr($fractal_code, 0, strpos($fractal_code, '>') + 1);
							// } else {
							//	$fractal_code = $fractal_code;
							//}
							//print('$selector_piece_index, $tagname_index, $this->required_attribute_sets: ');var_dump($selector_piece_index, $tagname_index, $this->required_attribute_sets);
							if(sizeof($this->required_attribute_sets[$selector_piece_index][$tagname_index]) !== 0) {
								//print('fractal_here0009 fractal matching by attributes<br />' . PHP_EOL);
								//print('$selector_piece_index, $this->required_attribute_sets: ');var_dump($selector_piece_index, $this->required_attribute_sets);
								if($this->tagnames[$selector_piece_index][$tagname_index] === '*') {
									$tagname_regex = $this->tagname_regex;
								} else {
									$tagname_regex = $this->tagnames[$selector_piece_index][$tagname_index];
								}
								//print('fractal_here0009.1 fractal matching by attributes<br />' . PHP_EOL);
								foreach($this->required_attribute_sets[$selector_piece_index][$tagname_index] as $required_attribute_name => $required_attribute_value) {
									//print('fractal_here0009.2 fractal matching by attributes<br />' . PHP_EOL);
									if($required_attribute_value === false) {
										//print('fractal_here0009.3 fractal matching by attributes<br />' . PHP_EOL);
										$preg_required_attribute_value = '[^"]*';
									} else {
										//print('fractal_here0009.4 fractal matching by attributes<br />' . PHP_EOL);
										$preg_required_attribute_value = O::preg_escape($required_attribute_value);
									}
									//print('fractal_here0009.5 fractal matching by attributes<br />' . PHP_EOL);
									//print('$required_attribute_name, $required_attribute_value: ');var_dump($required_attribute_name, $required_attribute_value);
									preg_match_all('/<' . $tagname_regex . '[^>]{0,}\s+(' . $required_attribute_name . ')="(' . $preg_required_attribute_value . ')"/is', $fractal_code, $attributes_matches, PREG_OFFSET_CAPTURE);
									//print('$tagname_regex, $required_attribute_name, $required_attribute_value, $fractal_code, $tagname_matches: ');var_dump($tagname_regex, $required_attribute_name, $required_attribute_value, $fractal_code, $tagname_matches);
									foreach($attributes_matches[0] as $attribute_index => $attribute_string) {
										//print('$fractal_code, $attributes_matches[0][$attribute_index][1], $offset_to_add, $offset_depths at second fractal expand: ');var_dump($fractal_code, $attributes_matches[0][$attribute_index][1], $offset_to_add, $offset_depths);
										//$expanded_LOM = O::expand($fractal_code, $attributes_matches[0][$attribute_index][1], $offset_to_add, $offset_depths);
										//$expanded_LOM = O::expand($fractal_code, $attributes_matches[0][$attribute_index][1], $offset_to_add, false);
										$expanded_LOM = O::expand($fractal_code, $attributes_matches[0][$attribute_index][1], $offset_to_add);
										if(O::tag_match($fractal_code, $expanded_LOM[0][1] - $offset_to_add, $offset_to_add, $selector_piece_index, $offset_depths, $minimum_depth, $maximum_depth)) {
											//if(O::tag_match($fractal_code, $expanded_LOM[0][1] - $offset_to_add, $offset_to_add, $selector_piece_index, $minimum_depth, $maximum_depth)) {
											//print('tag match in fractal matching by attributes<br />' . PHP_EOL);
											$new_fractal_matches[] = array($expanded_LOM[0][0], $expanded_LOM[0][1], $expanded_LOM[2] + 1);
											//$new_fractal_matches[] = array($expanded_LOM[0][0], $expanded_LOM[0][1], O::depth($expanded_LOM[0][1]));
											//print('$new_fractal_matches[sizeof($new_fractal_matches) - 1][2]: ');var_dump($new_fractal_matches[sizeof($new_fractal_matches) - 1][2]);
											if($this->debug) {
												if(O::depth($expanded_LOM[0][1]) === NULL) {
													print('$expanded_LOM[0][1], O::depth($expanded_LOM[0][1]), $this->offset_depths: ');var_dump($expanded_LOM[0][1], O::depth($expanded_LOM[0][1]), $this->offset_depths);
													O::fatal_error('did not find depth in fractal match 2!');
												}
											}
										}/* else {
										print('non tag match in fractal matching by attributes<br />' . PHP_EOL);
									}*/
									}
								}
								//print('$new_fractal_matches at end of fractal matching by attributes: ');var_dump($new_fractal_matches);
							} else {
								//print('fractal_here0010 fractal matching by tagname<br />' . PHP_EOL);
								foreach($this->tagnames[$selector_piece_index] as $tagname_index => $tagname) {
									if($tagname === '*') {
										$tagname_regex = $this->tagname_regex;
									} else {
										$tagname_regex = $this->tagnames[$selector_piece_index][$tagname_index];
									}
									//print('$fractal_code at fractal match 3: ');var_dump($fractal_code);
									preg_match_all('/<' . $tagname_regex . '/is', $fractal_code, $tagname_matches, PREG_OFFSET_CAPTURE);
									//print('$tagname_regex, $fractal_code, $tagname_matches in fractal matching by tagname: ');var_dump($tagname_regex, $fractal_code, $tagname_matches);
									foreach($tagname_matches[0] as $tagname_match_index => $tagname_match_array) {
										//print('$fractal_code, $tagname_matches[0][$tagname_match_index][1], $offset_to_add, $offset_depths at third fractal expand: ');var_dump($fractal_code, $tagname_matches[0][$tagname_match_index][1], $offset_to_add, $offset_depths);
										//$expanded_LOM = O::expand($fractal_code, $tagname_matches[0][$tagname_match_index][1], $offset_to_add, $offset_depths);
										//$expanded_LOM = O::expand($fractal_code, $tagname_matches[0][$tagname_match_index][1], $offset_to_add, false);
										$expanded_LOM = O::expand($fractal_code, $tagname_matches[0][$tagname_match_index][1], $offset_to_add);
										//print('$offset_to_add, $expanded_LOM, $minimum_depth, $maximum_depth in fractal matching by tagname: ');var_dump($offset_to_add, $expanded_LOM, $minimum_depth, $maximum_depth);
										//print('$O->context: ');var_dump($O->context);
										if(O::tag_match($fractal_code, $expanded_LOM[0][1] - $offset_to_add, $offset_to_add, $selector_piece_index, $offset_depths, $minimum_depth, $maximum_depth)) {
											//if(O::tag_match($fractal_code, $expanded_LOM[0][1] - $offset_to_add, $offset_to_add, $selector_piece_index, $minimum_depth, $maximum_depth)) {
											//print('tag_match in fractal matching by tagname<br />' . PHP_EOL);
											// function depth($offset, $offset_depths = false) {
											//print('$expanded_LOM[0][1], $expanded_LOM[2] + 1: ');var_dump($expanded_LOM[0][1], $expanded_LOM[2] + 1);
											$new_fractal_matches[] = array($expanded_LOM[0][0], $expanded_LOM[0][1], $expanded_LOM[2] + 1);
											//$new_fractal_matches[] = array($expanded_LOM[0][0], $expanded_LOM[0][1], O::depth($expanded_LOM[0][1]));
											//print('$new_fractal_matches[sizeof($new_fractal_matches) - 1][2]: ');var_dump($new_fractal_matches[sizeof($new_fractal_matches) - 1][2]);
											if($this->debug) {
												if(O::depth($expanded_LOM[0][1]) === NULL) {
													print('$expanded_LOM[0][1], O::depth($expanded_LOM[0][1]), substr($this->code, $expanded_LOM[0][1], 10), $this->offset_depths: ');var_dump($expanded_LOM[0][1], O::depth($expanded_LOM[0][1]), substr($this->code, $expanded_LOM[0][1], 10), $this->offset_depths);
													O::fatal_error('did not find depth in fractal match 3!');
												}
											}
										}/* else {
										print('non tag_match in fractal matching by tagname<br />');
									}*/
									}
								}
							}
						}
						// debug
						/*$fractal_matches[] = array(false, false, false);
							*					$fractal_matches[] = array(false, false, false);
							*					$fractal_matches[] = array('<a>', 12, 5);
							*					$fractal_matches[] = array('<a>', 12, 5);
							*					$fractal_matches[] = array('<b>', 12, 5);
							*					$fractal_matches[] = array('<a>', 14, 5);
							*					$fractal_matches[] = array('<a>', 12, 6);*/
						// reassess based on the paradigm of a structured universe; rather than the multidimensional fractal paradigm
						//print('$offset_depths, $this->offset_depths: ');O::var_dump_full($offset_depths, $this->offset_depths);
						//print('$new_fractal_matches, $offset_depths before structuring: ');var_dump($new_fractal_matches, $offset_depths);
						$_filter_offset_depths = is_array($offset_depths) ? $offset_depths : $this->offset_depths;
						foreach($new_fractal_matches as $fractal_index => $fractal_value) {
							// implicitly exclude results like within comments or programming instructions
							if(!isset($_filter_offset_depths[$fractal_value[1]])) { // use $offset_depths when available (parent_node-only); fall back to $this->offset_depths when false
								unset($new_fractal_matches[$fractal_index]);
							}
						}
						//print('$new_fractal_matches before array_unique: ');var_dump($new_fractal_matches);
						$new_fractal_matches = O::data_unique($new_fractal_matches); // notice that data_unique accepts a "recursive" parameter!
						$new_fractal_matches = array_values($new_fractal_matches); // re-index
						//print('$new_fractal_matches after array_unique: ');var_dump($new_fractal_matches);
					}
					//print('fractal_here0010.5<br />' . PHP_EOL);
					$fractal_matches = $new_fractal_matches;
					$selector_piece_index--;
				}
				//print('$fractal_matches before structuring: ');var_dump($fractal_matches);
				//print('fractal_here0011<br />' . PHP_EOL);
				foreach($fractal_matches as $fractal_index => $fractal_value) {
					// 	//print('fractal_here0011.5<br />' . PHP_EOL);
					$fractal_code = $fractal_value[0];
					$offset_to_add = $fractal_value[1];
					$fractal_depth = $fractal_value[2];
					//$fractal_selector_piece_index = $fractal_value[3];
					// 	//print('$fractal_code, $offset_to_add at fractal_here11.5: ');var_dump($fractal_code, $offset_to_add);
					// 	print('$fractal_code, $offset_to_add, $offset_depths, $offset_depths[$offset_to_add] while structuring: ');var_dump($fractal_code, $offset_to_add, $offset_depths, $offset_depths[$offset_to_add]);
					// 	// if we were dynamically calculating the offset_depths, they would likely be in the offset_depths
					// 	//$selector_matches = array_merge($selector_matches, O::recursive_select($fractal_code, $offset_to_add, 0, $offset_depths, $offset_depths[$offset_to_add] - 1));
					$selector_matches = array_merge($selector_matches, O::recursive_select($fractal_code, $offset_to_add, 0, $fractal_depth - 1));
					//unset($fractal_matches[$fractal_index][2]);
				}
				//$selector_matches = array_merge($selector_matches, $fractal_matches);
				//print('$selector_matches from $fractal_matches: ');var_dump($selector_matches, $fractal_matches);
				//O::good_message('fractally got!');
			} else {
				//print('fractal_here0012<br />' . PHP_EOL);
				//print('$fractal_code, $offset_to_add at fractal_here12: ');var_dump($fractal_code, $offset_to_add);
				//$selector_matches = array_merge($selector_matches, O::recursive_select($fractal_code, $offset_to_add, 0, $offset_depths, $offset_depths[$offset_to_add] - 1));
				$selector_matches = array_merge($selector_matches, O::recursive_select($code, $offset_to_add, 0, $offset_depths[$offset_to_add] - 1));
				//O::warning('non-fractally got.');
			}
		}
		//print('fractal_here0013<br />' . PHP_EOL);
		//print('$this->saved_tagname_indices, $this->saved_tagvalue_indices, $this->saved_attributes_indices: ');var_dump($this->saved_tagname_indices, $this->saved_tagvalue_indices, $this->saved_attributes_indices);
		//if($this->saved_tagname_indices !== false) {
		//	if(sizeof($this->saved_tagname_indices) > 1) {
		//		O::fatal_error('sizeof($this->saved_tagname_indices) > 1 is not coded for yet');
		//	}
		//	$selector_matches = array($selector_matches[$this->saved_tagname_indices[0]]);
		//}
		//	if($this->saved_tagvalue_indices !== false) {
		//		if(sizeof($this->saved_tagvalue_indices) > 1) {
		//			O::fatal_error('sizeof($this->saved_tagvalue_indices) > 1 is not coded for yet');
		//		}
		//		$selector_matches = array($selector_matches[$this->saved_tagvalue_indices[0]]);
		//	}
		//	if($this->saved_attributes_indices !== false) {
		//		if(sizeof($this->saved_attributes_indices) > 1) {
		//			O::fatal_error('sizeof($this->saved_attributes_indices) > 1 is not coded for yet');
		//		}
		//		$selector_matches = array($selector_matches[$this->saved_attributes_indices[0]]);
		//	}
		//print('$selector_matches at the end of preg_select: ');var_dump($selector_matches);
		return $selector_matches;
	}

	// The match expression branches evaluation based on an identity check of a value. Similarly to a switch statement, a match expression has a subject expression that is compared against multiple alternatives. https://www.php.net/manual/en/control-structures.match.php
	//function match($code, $offset = 0, $offset_to_add = 0, $selector_piece_index = 0, $offset_depths = false, $minimum_depth = false, $maximum_depth = false) { // alias
	//	return O::match($code, $offset, $offset_to_add, $selector_piece_index, $offset_depths, $minimum_depth, $maximum_depth);
	//}

	function tag_match($code, $offset = 0, $offset_to_add = 0, $selector_piece_index = 0, $offset_depths = false, $minimum_depth = false, $maximum_depth = false) {
		//function tag_match($code, $offset = 0, $offset_to_add = 0, $selector_piece_index = 0, $minimum_depth = false, $maximum_depth = false) {
		if($offset_depths == false) {
			//$offset_depths = O::get_offset_depths(substr($code, $offset), $offset + $offset_to_add, O::depth($offset + $offset_to_add));
			//$offset_depths = $this->offset_depths;
			$expanded_LOM = O::expand($code, $offset, $offset_to_add);
			$offset_depths = $expanded_LOM[3];
		}
		if(O::is_in_other_markup($offset, $code)) {
			return false;
		}
		preg_match(O::preg_expression_from_selector_piece($selector_piece_index), $code, $matches, PREG_OFFSET_CAPTURE, $offset);
		if(!isset($matches[0][0]) || !isset($matches[1][0])) {
			return false;
		}
		//print('$code, $offset, $offset_to_add, $selector_piece_index, $minimum_depth, $maximum_depth, $matches: ');var_dump($code, $offset, $offset_to_add, $selector_piece_index, $minimum_depth, $maximum_depth, $matches);
		$matched_tagname = false;
		$matched_depth = false;
		$matched_tagvalue = false;
		$matched_attributes = false;
		foreach($this->tagnames[$selector_piece_index] as $tagname_index => $tagname) {
			if($matches[1][0] === $tagname || $tagname === '*') {
				//print('tag matched tagname<br />' . PHP_EOL);
				$matched_tagname = true;
				$matched_depth = false;
				//$depth = O::depth($matches[0][1] + $offset_to_add, $offset_depths);
				//$depth = O::depth($matches[0][1] + $offset_to_add);
				$depth = O::depth($matches[0][1] + $offset_to_add, $offset_depths);
				//print('$matches[0][1], $offset_to_add, $offset_depths, $depth, $minimum_depth, $maximum_depth: ');var_dump($matches[0][1], $offset_to_add, $offset_depths, $depth, $minimum_depth, $maximum_depth);
				if($depth >= $minimum_depth && $depth <= $maximum_depth) {
					$matched_depth = true;
				}
				if($matched_depth) {
					//print('tag matched depth<br />' . PHP_EOL);
					$matched_tagvalue = false;
					if($this->tagvalues[$selector_piece_index][$tagname_index] === false) {
						$matched_tagvalue = true;
					} else {
						//$expanded_LOM = O::expand($code, $matches[0][1] + strlen($matches[0][0]), $offset_to_add, $offset_depths, 'lazy');
						//$tagvalue = $expanded_LOM[1][0];
						$tagvalue = $matches[3][0];
						//if($this->tagvalues[$selector_piece_index][$tagname_index] === $tagvalue) {
						if(O::compare($matches[3][0], $this->tagvalue_comparison_operators[$selector_piece_index][$tagname_index], $tagvalue)) {
							$matched_tagvalue = true;
						}
					}
					if($matched_tagvalue) {
						//print('tag matched tagvalue<br />' . PHP_EOL);
						$matched_attributes = false;
						if(sizeof($this->required_attribute_sets[$selector_piece_index][$tagname_index]) === 0) {
							$matched_attributes = true;
						} else {
							$attributes_string = $matches[2][0];
							preg_match_all('/\s+(' . $this->attributename_regex . ')="([^"]*)"/', $attributes_string, $existing_attributes);
							// $attribute_sets_comparison_operators is indexed [piece][tagname_index][attribute_position]
							// while $required_attribute_sets is indexed [piece][tagname_index][attribute_name => attribute_value].
							// Track the position so we look up the right operator for each attribute filter.
							$required_attribute_position = 0;
							foreach($this->required_attribute_sets[$selector_piece_index][$tagname_index] as $required_attribute_name => $required_attribute_value) {
								$matched_required_attribute = false;
								if($required_attribute_value === false) {
									foreach($existing_attributes[1] as $existing_attribute_name) {
										if($existing_attribute_name === $required_attribute_name) {
											$matched_required_attribute = true;
											break;
										}
									}
								} else {
									$attribute_comparison_operator = '=';
									if(
										isset($this->attribute_sets_comparison_operators[$selector_piece_index][$tagname_index][$required_attribute_position])
										&& is_string($this->attribute_sets_comparison_operators[$selector_piece_index][$tagname_index][$required_attribute_position])
										&& $this->attribute_sets_comparison_operators[$selector_piece_index][$tagname_index][$required_attribute_position] !== ''
									) {
										$attribute_comparison_operator = $this->attribute_sets_comparison_operators[$selector_piece_index][$tagname_index][$required_attribute_position];
									}
									foreach($existing_attributes[1] as $existing_attribute_index => $existing_attribute_name) {
										$existing_attribute_value = $existing_attributes[2][$existing_attribute_index];
										//print('$existing_attribute_name, $required_attribute_name, $existing_attribute_value, $required_attribute_value: ');var_dump($existing_attribute_name, $required_attribute_name, $existing_attribute_value, $required_attribute_value);
										//if($existing_attribute_name === $required_attribute_name && $existing_attribute_value === $required_attribute_value) {
										if($existing_attribute_name === $required_attribute_name && O::compare($existing_attribute_value, $attribute_comparison_operator, $required_attribute_value)) {
											$matched_required_attribute = true;
											break;
										}
									}
								}
								$required_attribute_position++;
								if(!$matched_required_attribute) {
									$matched_attributes = false;
									break;
								}
								$matched_attributes = true;
							}
						}
					}
				}
			}
			//print('$matched_tagname, $matched_depth, $matched_tagvalue, $matched_attributes: ');var_dump($matched_tagname, $matched_depth, $matched_tagvalue, $matched_attributes);
			if($matched_tagname && $matched_depth && $matched_tagvalue && $matched_attributes) {
				return true;
			}
		}
		return false;
	}

	//function recursive_select($code, $offset_to_add = 0, $selector_piece_index = 0, $offset_depths = false, $parent_depth = -1) { // terrible nomenclature for these select functions?
	function recursive_select($code, $offset_to_add = 0, $selector_piece_index = 0, $parent_depth = -1) { // terrible nomenclature for these select functions?
		// this should really not duplicate functionality of tag_match and making sure all the threads go to the right place when dealing with something as complex as fractal get is not simple,
		// it is possible... but verges on the next universe
		// if($offset_depths === false) {
		// 	$offset_depths = $this->offset_depths;
		// }
		// if($offset_depths == false) {
		// 	if($code === $this->code) {
		// 		$offset_depths = $this->offset_depths;
		// 	} else {
		// 		$offset_depths = O::get_offset_depths($code, 0, O::depth($offset_to_add));
		// 	}
		// }
		//$expanded_LOM = O::expand($code, 0, $offset_to_add);
		//$offset_depths = $expanded_LOM[3];
		// if($offset_frame == false) {
		// 	$offset_frame = O::get_offset_frame($offset_to_add);
		// }
		//if($parent_depth === false) {
		//	$parent_depth = O::depth($offset_to_add, $offset_depths);
		//}
		//O::parse_selector_piece($this->selector_pieces[$selector_piece_index], $selector_piece_index);
		//print('$selector_piece_index, $this->selector_pieces, $this->selector_pieces[$selector_piece_index] at start of recursive_select(): ');var_dump($selector_piece_index, $this->selector_pieces, $this->selector_pieces[$selector_piece_index]);
		//print('$this->selector_scopes, $this->tagnames[$selector_piece_index], $this->tagname_indices[$selector_piece_index], $this->tagvalues[$selector_piece_index], $this->tagvalue_indices[$selector_piece_index], $this->required_attribute_sets[$selector_piece_index], $this->attributes_indices[$selector_piece_index]: ');var_dump($this->selector_scopes, $this->tagnames[$selector_piece_index], $this->tagname_indices[$selector_piece_index], $this->tagvalues[$selector_piece_index], $this->tagvalue_indices[$selector_piece_index], $this->required_attribute_sets[$selector_piece_index], $this->attributes_indices[$selector_piece_index]);
		// what happens if multiple of $this->tagname_indices[$selector_piece_index], $this->tagvalue_indices[$selector_piece_index], $this->attributes_indices[$selector_piece_index] are specified in the same selector? this needs more work especially since we're hacking at the end for $this->tagvalue_indices[$selector_piece_index] and $this->attributes_indices[$selector_piece_index]
		if(isset($this->required_attributes) && is_array($this->required_attributes) && sizeof($this->required_attributes) > 1) {
			O::fatal_error('preg requires more complex code to handle all the possible orders of attributes. is this enough to not use preg for this particular select operation?');
		}
		//print('$selector_piece_index, $this->tagnames, $this->tagname_indices: ');var_dump($selector_piece_index, $this->tagnames, $this->tagname_indices);
		if(sizeof($this->tagnames[$selector_piece_index]) > 1 && $this->selector_scopes[$selector_piece_index] !== 'direct') {
			print('$this->tagnames[$selector_piece_index], $this->selector_piece_sets, $this->selector_scopes, $selector_piece_index, $this->selector_scopes[$selector_piece_index]: ');var_dump($this->tagnames[$selector_piece_index], $this->selector_piece_sets, $this->selector_scopes, $selector_piece_index, $this->selector_scopes[$selector_piece_index]);
			O::fatal_error('how to match multiple tags together when not only looking in direct scope is not coded. did you mean to use the "or" operator "|" instead of the "and" operator "&"?');
		}
		foreach($this->tagnames[$selector_piece_index] as $tagname_index => $tagname) {
			$this->tagname_match_counter[$selector_piece_index][$tagname_index] = 0;
			$this->tagvalue_match_counter[$selector_piece_index][$tagname_index] = 0;
			$this->attributes_match_counter[$selector_piece_index][$tagname_index] = 0;
		}
		// selector piece to preg_expression
		$preg_expression = O::preg_expression_from_selector_piece($selector_piece_index);
		//print('$this->tagnames[$selector_piece_index], $this->tagvalues[$selector_piece_index], $preg_expression: ');var_dump($this->tagnames[$selector_piece_index], $this->tagvalues[$selector_piece_index], $preg_expression);
		//print('$preg_expression in recursive_select(): ');var_dump($preg_expression);//exit(0);
		//preg_match_all(string $pattern, string $subject, array &$matches = null, int $flags = 0, int $offset = 0): int|false
		preg_match_all($preg_expression, $code, $matches, PREG_OFFSET_CAPTURE);
		//print('$matches in recursive_select: ');var_dump($matches);
		//if($matches[1][$index][0] === $tagname || $tagname === '*') {
		//$this->zero_offsets = array();
		//print('$code, $offset_to_add, $offset_depths, $matches, $parent_depth at the start of recursive_select: ');var_dump($code, $offset_to_add, $offset_depths, $matches, $parent_depth);
		//print('$this->selector_scopes: ');var_dump($this->selector_scopes);
		//print('$this->tagnames[$selector_piece_index], $this->tagvalues[$selector_piece_index], $this->required_attribute_sets[$selector_piece_index]: ');var_dump($this->tagnames[$selector_piece_index], $this->tagvalues[$selector_piece_index], $this->required_attribute_sets[$selector_piece_index]);

		foreach($matches[0] as $index => $value) {
			//print('$value: ');var_dump($value);
			$matched_tagname = false;
			$matched_tagname_index = false;
			$matched_scope = false;
			$matched_tagvalue = false;
			$matched_tagvalue_index = false;
			$matched_attributes = false;
			$matched_attributes_index = false;
			$expanded_LOM = O::expand($code, $value[1], $offset_to_add);
			$offset_depths = $expanded_LOM[3];
			//print('$value, $offset_depths: ');var_dump($value, $offset_depths);
			foreach($this->tagnames[$selector_piece_index] as $tagname_index => $tagname) { // don't think we've come across a case where multiple tagnames are accepted...
				//print('$tagname, $matches[1][$index][0]: ');var_dump($tagname, $matches[1][$index][0]);
				//print('$this->attributes_match_counter[$selector_piece_index][$tagname_index]: ');var_dump($this->attributes_match_counter[$selector_piece_index][$tagname_index]);
				//if($matches[1][$index][0] === $tagname || $tagname === '*') { // tagname matching is handled by the regular expression above
				//print('matched tagname<br />' . PHP_EOL);
				$matched_tagname = true;
				//if($this->tagname_indices[$selector_piece_index][$tagname_index] !== false) {
				//	$this->saved_tagname_indices = $this->tagname_indices[$selector_piece_index];
				//}
				$matched_scope = false;
				if($this->selector_scopes[$selector_piece_index] === 'direct') { // matching scope is logically prior than matching tagname but it's more processing time so it's after in the code
					//print('$code, $matches[0][$index][1], $offset_to_add, O::depth($matches[0][$index][1] + $offset_to_add), $parent_depth: ');var_dump($code, $matches[0][$index][1], $offset_to_add, O::depth($matches[0][$index][1] + $offset_to_add), $parent_depth);

					//print('O::depth($matches[0][$index][1] + $offset_to_add), $parent_depth: ');var_dump(O::depth($matches[0][$index][1] + $offset_to_add), $parent_depth);
					//if(O::depth($code, $matches[0][$index][1], $offset_to_add) === 0) {
					//if(O::depth($matches[0][$index][1] + $offset_to_add) === 0) {
					/*if($selector_piece_index === 0 && O::depth($matches[0][$index][1] + $offset_to_add) === $parent_depth) {
						*						$matched_scope = true;
				} else*/if(O::depth($matches[0][$index][1] + $offset_to_add, $offset_depths) === $parent_depth + 1) {
					//if(O::depth($matches[0][$index][1] + $offset_to_add) === $parent_depth + 1) {
					$matched_scope = true;
				}
				} else {
					$matched_scope = true;
				}
				//print('$tagname, $tagvalue, $matches[0][$index][1], $offset_to_add, $offset_depths, $parent_depth, $matched_scope: ');var_dump($tagname, $tagvalue, $matches[0][$index][1], $offset_to_add, $offset_depths, $parent_depth, $matched_scope);
				//print('$tagname, $tagvalue, $parent_depth, $matched_scope: ');var_dump($tagname, $tagvalue, $parent_depth, $matched_scope);
				if($matched_scope) {
					//print('matched scope<br />' . PHP_EOL);
					$matched_tagname_index = false;
					//print('$value, $tagname, $selector_piece_index, $this->tagname_indices, $this->tagname_match_counter in matched_scope: ');var_dump($value, $tagname, $selector_piece_index, $this->tagname_indices, $this->tagname_match_counter);
					if($this->tagname_indices[$selector_piece_index][$tagname_index] === false) {
						//print('tagname index not specified<br />' . PHP_EOL);
						$matched_tagname_index = true;
					} else {
						$idx_spec = $this->tagname_indices[$selector_piece_index][$tagname_index];
						$idx_hit = O::consume_index_spec($idx_spec, $this->tagname_match_counter[$selector_piece_index][$tagname_index]);
						if($idx_hit === true) {
							$matched_tagname_index = true;
						} elseif($idx_hit === 'exclusive') {
							// a specified index is the most specific a selector can be and thus negates anything selection by the selector piece after the index. multiindexes are similarly impossibly ambiguous for a computer though fun to contemplate for humans
							//print('found tagname with required index<br />' . PHP_EOL);
							$matches = array(array($value));
							$this->reached_selector_index = true;
							break 2;
							// //print('here290675<br />' . PHP_EOL);
							// print('culling others than tagname index<br />' . PHP_EOL);
							// //$this->tagname_match_counter2 = 0;
							// foreach($matches[0] as $index2 => $value2) {
							// 	//print('here290676<br />' . PHP_EOL);
							// 	//$unset_match = true;
							// 	//if($matches[1][$index2][0] === $tagname) {
							// 	//	//print('here290677<br />' . PHP_EOL);
							// 	//	if($index === $index2) {
							// 	//		//print('here290678<br />' . PHP_EOL);
							// 	//		$unset_match = false;
							// 	//	}
							// 	//	//$this->tagname_match_counter2++;
							// 	//}
							// 	//print('here290679<br />' . PHP_EOL);
							// 	//if($unset_match) {
							// 	//	unset($matches[0][$index2]);
							// 	//	//unset($matches[1][$index2]);
							// 	//	//unset($matches[2][$index2]);
							// 	//}
							// 	if($index !== $index2) {
							// 		unset($matches[0][$index2]);
							// 	}
							// }
							// //print('$matches[0] after tagname index matching: ');var_dump($matches[0]);
							// //continue 2;
							// //break 2;
							// $matched_tagname_index = true;
							// $break_due_to_matched_index = true;
						}
					}
					if($matched_tagname_index) {
						//print('matched tagname index<br />' . PHP_EOL);
						$matched_tagvalue = false;
						if($this->tagvalues[$selector_piece_index][$tagname_index] === false) {
							//print('no tagvalue specified<br />' . PHP_EOL);
							$matched_tagvalue = true;
						} else {
							//$expanded_LOM = O::expand($code, $matches[0][$index][1] + strlen($matches[0][$index][0]), $offset_to_add, $offset_depths, 'lazy');
							//$tagvalue = (string)$expanded_LOM[1][0];
							//$expanded_LOM = O::expand($code, $matches[0][$index][1] + strlen($matches[0][$index][0]), $offset_to_add, $offset_depths, 'lazy');
							//$tagvalue = $expanded_LOM[1][0];
							$tagvalue = $matches[3][$index][0];
							//print('$this->tagvalues[$selector_piece_index][$tagname_index], $tagvalue: ');var_dump($this->tagvalues[$selector_piece_index][$tagname_index], $tagvalue);
							$tv_op = $this->tagvalue_comparison_operators[$selector_piece_index][$tagname_index];
							if($tv_op === false || $tv_op === '') {
								$tv_op = '=';
							}
							if($this->tagnames[$selector_piece_index][$tagname_index] === '*' && $tv_op === '=') {
								$tv_op = '%=';
							}
							if($tv_op === '=' && O::lom_regex_token_id($this->tagvalues[$selector_piece_index][$tagname_index]) === false) {
								$matched_tagvalue = ($this->tagvalues[$selector_piece_index][$tagname_index] == $tagvalue);
							} else {
								$matched_tagvalue = O::compare($tagvalue, $tv_op, $this->tagvalues[$selector_piece_index][$tagname_index]);
							}
						}
						if($matched_tagvalue) {
							//print('matched tagvalue<br />' . PHP_EOL);
							//	if($this->tagvalue_indices[$selector_piece_index][$tagname_index] !== false) {
							//		$this->saved_tagvalue_indices = $this->tagvalue_indices[$selector_piece_index];
							//	}
							//print('$value, $selector_piece_index, $this->tagvalue_indices, $this->tagvalue_match_counter: ');var_dump($value, $selector_piece_index, $this->tagvalue_indices, $this->tagvalue_match_counter);
							//print('$tagname, $tagvalue, $this->tagvalue_indices, $selector_piece_index, $tagname_index, $this->tagvalue_match_counter: ');var_dump($tagname, $tagvalue, $this->tagvalue_indices, $selector_piece_index, $tagname_index, $this->tagvalue_match_counter);
							$matched_tagvalue_index = false;
							if($this->tagvalue_indices[$selector_piece_index][$tagname_index] === false) {
								//print('no tagvalue index specified<br />' . PHP_EOL);
								$matched_tagvalue_index = true;
							} elseif(O::qualified_index_spec_at($selector_piece_index, $tagname_index) !== false) {
								$matched_tagvalue_index = true;
							} else {
								$tv_idx_hit = O::consume_index_spec($this->tagvalue_indices[$selector_piece_index][$tagname_index], $this->tagvalue_match_counter[$selector_piece_index][$tagname_index]);
								if($tv_idx_hit === true) {
									$matched_tagvalue_index = true;
								} elseif($tv_idx_hit === 'exclusive') {
									// a specified index is the most specific a selector can be and thus negates anything selection by the selector piece after the index. multiindexes are similarly impossibly ambiguous for a computer though fun to contemplate for humans
									//print('found tagvalue with required index<br />' . PHP_EOL);
									$matches = array(array($value));
									$this->reached_selector_index = true;
									break 2;
									// print('culling others than tagvalue index<br />' . PHP_EOL);
									// //print('$value, $selector_piece_index, $tagname, $tagvalue, $this->tagvalue_indices, $this->tagvalue_match_counter: ');var_dump($value, $selector_piece_index, $tagname, $tagvalue, $this->tagvalue_indices, $this->tagvalue_match_counter);
									// //$this->tagvalue_match_counter2 = 0;
									// foreach($matches[0] as $index => $value) {
									// 	//$unset_match = true;
									// 	//if($matches[1][$index][0] === $tagname) {
									// 	//	if($index === $index2) {
									// 	//		$unset_match = false;
									// 	//	}
									// 	//	//$this->tagvalue_match_counter2++;
									// 	//}
									// 	//if($unset_match) {
									// 	//	unset($matches[0][$index]);
									// 	//	//unset($matches[1][$index]);
									// 	//	//unset($matches[2][$index]);
									// 	//}
									// 	if($index !== $index2) {
									// 		unset($matches[0][$index2]);
									// 	}
									// }
									// //continue 2;
									// //break 2;
									// $matched_tagvalue_index = true;
									// $break_due_to_matched_index = true;
								}
							}
							if($matched_tagvalue_index) {
								//print('matched tagvalue index<br />' . PHP_EOL);
								$matched_attributes = false;
								if(sizeof($this->required_attribute_sets[$selector_piece_index][$tagname_index]) === 0) {
									//print('no required attributes sets specified<br />' . PHP_EOL);
									$matched_attributes = true;
								} else {
									$attributes_string = $matches[2][$index][0];
									preg_match_all('/\s+(' . $this->attributename_regex . ')="([^"]+)"/', $attributes_string, $existing_attributes);
									$required_attribute_position = 0;
									foreach($this->required_attribute_sets[$selector_piece_index][$tagname_index] as $required_attribute_name => $required_attribute_value) {
										$matched_required_attribute = false;
										$attribute_comparison_operator = '=';
										if(
											isset($this->attribute_sets_comparison_operators[$selector_piece_index][$tagname_index][$required_attribute_position])
											&& is_string($this->attribute_sets_comparison_operators[$selector_piece_index][$tagname_index][$required_attribute_position])
											&& $this->attribute_sets_comparison_operators[$selector_piece_index][$tagname_index][$required_attribute_position] !== ''
										) {
											$attribute_comparison_operator = $this->attribute_sets_comparison_operators[$selector_piece_index][$tagname_index][$required_attribute_position];
										}
										if($required_attribute_value === false) {
											foreach($existing_attributes[1] as $existing_attribute_name) {
												if($existing_attribute_name === $required_attribute_name) {
													$matched_required_attribute = true;
													break;
												}
											}
										} else {
											foreach($existing_attributes[1] as $existing_attribute_index => $existing_attribute_name) {
												if($existing_attribute_name !== $required_attribute_name) {
													continue;
												}
												$existing_attribute_value = $existing_attributes[2][$existing_attribute_index];
												if($attribute_comparison_operator === '=' && O::lom_regex_token_id($required_attribute_value) === false) {
													if($existing_attribute_value == $required_attribute_value) {
														$matched_required_attribute = true;
														break;
													}
												} elseif(O::compare($existing_attribute_value, $attribute_comparison_operator, $required_attribute_value)) {
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
										$required_attribute_position++;
									}
								}
								if($matched_attributes) {
									//print('matched attributes<br />' . PHP_EOL);
									//	if($this->attributes_indices[$selector_piece_index][$tagname_index] !== false) {
									//		$this->saved_attributes_indices = $this->attributes_indices[$selector_piece_index];
									//	}
									$matched_attributes_index = false;
									if($this->attributes_indices[$selector_piece_index][$tagname_index] === false) {
										//print('no attributes indices specified<br />' . PHP_EOL);
										$matched_attributes_index = true;
									} elseif(O::qualified_index_spec_at($selector_piece_index, $tagname_index) !== false) {
										$matched_attributes_index = true;
									} else {
										$at_idx_hit = O::consume_index_spec($this->attributes_indices[$selector_piece_index][$tagname_index], $this->attributes_match_counter[$selector_piece_index][$tagname_index]);
										if($at_idx_hit === true) {
											$matched_attributes_index = true;
										} elseif($at_idx_hit === 'exclusive') {
											// a specified index is the most specific a selector can be and thus negates anything selection by the selector piece after the index. multiindexes are similarly impossibly ambiguous for a computer though fun to contemplate for humans
											//print('found attributes with required index<br />' . PHP_EOL);
											$matches = array(array($value));
											$this->reached_selector_index = true;
											break 2;
										}
									}
									// debug
									//if($matched_attributes_index) {
									//	print('matched attributes index<br />' . PHP_EOL);
									//}
								}
							}
						}
					}
				}
				//}
				if($matched_tagname && $matched_tagname_index && $matched_scope && $matched_tagvalue && $matched_tagvalue_index && $matched_attributes && $matched_attributes_index) {
					break;
				}
			}
			//print('$matched_tagname, $matched_scope, $matched_tagvalue, $matched_attributes: ');var_dump($matched_tagname, $matched_scope, $matched_tagvalue, $matched_attributes);
			//print('$value, $matched_tagname, $matched_tagname_index, $matched_scope, $matched_tagvalue, $matched_tagvalue_index, $matched_attributes, $matched_attributes_index: ');var_dump($value, $matched_tagname, $matched_tagname_index, $matched_scope, $matched_tagvalue, $matched_tagvalue_index, $matched_attributes, $matched_attributes_index);
			// Waiting for nth tagvalue match ([n] index): do not treat as failure or unset — counter advanced inside the tagvalue_index branch.
			$waiting_tagvalue_index = false;
			if($matched_tagvalue && isset($tagname_index) && $this->tagvalue_indices[$selector_piece_index][$tagname_index] !== false && !$matched_tagvalue_index) {
				$waiting_tagvalue_index = true;
			}
			if($waiting_tagvalue_index) {
				unset($matches[0][$index]);
				continue;
			}
			if($matched_tagname && $matched_tagname_index && $matched_scope && $matched_tagvalue && $matched_tagvalue_index && $matched_attributes && $matched_attributes_index) {
				//print('match!<br />' . PHP_EOL);
			} else {
				//print('unsetting non-match<br />' . PHP_EOL);
				unset($matches[0][$index]);
				//unset($matches[1][$index]);
				//unset($matches[2][$index]);
			}
			// if($break_due_to_matched_index) {
			// 	break;
			// }
		}
		//print('recursive_select0010<br />' . PHP_EOL);
		if(!empty($this->selector_piece_multi_and[$selector_piece_index])) {
			$matches[0] = O::filter_multi_and_sibling_requirements($matches[0], $code, $offset_to_add, $selector_piece_index);
		} elseif(sizeof($this->tagnames[$selector_piece_index]) > 1) { // pretty crude
			if(sizeof($matches[0]) === sizeof($this->tagnames[$selector_piece_index])) {

			} else {
				$matches[0] = array();
			}
		}
		//print('recursive_select0011<br />' . PHP_EOL);
		//O::warning_once('need to really think about whether matched_index should match the tagname or the tagname with a tagvalue or the tagname with a tagvalue with an attribute set. these would be non-traditional uses but that could mean they become interesting, if rare in application');
		//$matched_index = true;
		$matches[0] = array_values($matches[0]);
		//print('$matches mid recursive_select: ');var_dump($matches);
		//print('recursive_select0012<br />' . PHP_EOL);
		if(sizeof($matches[0]) === 0) {
			return array();
		}
		//print('recursive_select0013<br />' . PHP_EOL);
		//print('$this->selected_parent_matches, $matches[0] before adjusting: ');var_dump($this->selected_parent_matches, $matches[0]);
		if(sizeof($this->selector_pieces) > 1) { // when the selection operator . is used even though there's only one selector piece this code isn't needed
			//print('recursive_select0013.1<br />' . PHP_EOL);
			if($selector_piece_index === $this->selected_parent_piece_index) {
				//print('recursive_select0013.2<br />' . PHP_EOL);
				$this->selected_parent_matches = $matches[0];
				//$this->selected_parent_offset_depths = $offset_depths;
				foreach($this->selected_parent_matches as $index => $value) {
					//print('recursive_select0013.3<br />' . PHP_EOL);
					if(O::is_opening_tag($this->selected_parent_matches[$index][0])) {
						//print('recursive_select0013.4<br />' . PHP_EOL);
						//$this->selected_parent_matches[$index][0] = O::get_tag_string($this->selected_parent_matches[$index][1], $this->selected_parent_offset_depths); // do not use!
						//$expanded_LOM = O::expand($code, $this->selected_parent_matches[$index][1], $offset_to_add, $offset_depths);
						//return $expanded_LOM[0][0];
						//print('expanding in recursive_select()1<br />' . PHP_EOL);
						$this->selected_parent_matches[$index][0] = O::expand($code, $this->selected_parent_matches[$index][1], $offset_to_add, false)[0][0];
					}
					//print('recursive_select0013.5<br />' . PHP_EOL);
					$this->selected_parent_matches[$index][1] += $offset_to_add;
				}
			}
			//print('$this->selected_parent_matches after adjusting: ');var_dump($this->selected_parent_matches);//exit(0);
		}
		//print('recursive_select0014<br />' . PHP_EOL);
		//print('$selector_piece_index, $this->selected_parent_piece_index, sizeof($this->selector_pieces), $this->selected_parent_matches: ');var_dump($selector_piece_index, $this->selected_parent_piece_index, sizeof($this->selector_pieces), $this->selected_parent_matches);
		if($selector_piece_index === sizeof($this->selector_pieces) - 1) {
			//print('recursive_select0014.1<br />' . PHP_EOL);
			foreach($matches[0] as $index => $value) {
				//print('recursive_select0014.2<br />' . PHP_EOL);
				$matches[0][$index][1] += $offset_to_add;
			}
			//print('recursive_select0014.3<br />' . PHP_EOL);
			if($this->selected_parent_matches !== false) {
				//print('recursive_select0014.4<br />' . PHP_EOL);
				$matches_at_last_tag = $matches[0];
				//print('$matches_at_last_tag, $this->selected_parent_matches, $offset_to_add when selecting parent: ');var_dump($matches_at_last_tag, $this->selected_parent_matches, $offset_to_add);
				$selected_parent_full_selector_matches = array();
				foreach($matches_at_last_tag as $matches_at_last_tag_index => $matches_at_last_tag_value) {
					//print('recursive_select0014.5<br />' . PHP_EOL);
					//print('$matches_at_last_tag_value: ');var_dump($matches_at_last_tag_value);
					//$best_match = false; // ugly?
					$child_offset = $matches_at_last_tag_value[1];
					foreach($this->selected_parent_matches as $selected_parent_matches_index => $selected_parent_matches_value) {
						//print('recursive_select0014.6<br />' . PHP_EOL);
						//if($selected_parent_matches_value[1] > $matches_at_last_tag_value[1] + $offset_to_add) {
						$parent_offset = $selected_parent_matches_value[1];
						if($parent_offset > $child_offset) {
							//print('recursive_select0014.7<br />' . PHP_EOL);
							break;
						}
						$parent_full_string = $selected_parent_matches_value[0];
						//print('$matches_at_last_tag_value, $offset_to_add, $selected_parent_matches_value: ');var_dump($matches_at_last_tag_value, $offset_to_add, $selected_parent_matches_value);
						//$child_offset = $matches_at_last_tag_value[1] + $offset_to_add;
						//$parent_full_string = O::get_tag_string($this->code, $parent_offset + $child_offset, $parent_offset);
						//$parent_full_string = O::get_tag_string(substr($this->code, $parent_offset), strlen($selected_parent_matches_value[0]), $parent_offset);
						//$parent_full_string = O::get_tag_string($parent_offset, $this->selected_parent_offset_depths); // would have to be pretty fancy to keep track of $offset_depths for the parent that will end up being selected
						//print('$child_offset, $parent_offset, $parent_offset + strlen($parent_full_string), $parent_full_string, $this->selected_parent_offset_depths, O::depth_match($child_offset, $parent_offset, $this->selected_parent_offset_depths): ');var_dump($child_offset, $parent_offset, $parent_offset + strlen($parent_full_string), $parent_full_string, $this->selected_parent_offset_depths, O::depth_match($child_offset, $parent_offset, $this->selected_parent_offset_depths));
						//print('selecting piece 0001<br />' . PHP_EOL);
						//if($child_offset >= $parent_offset && $child_offset <= $parent_offset + strlen($parent_full_string) && O::depth_match($child_offset, $parent_offset, $this->selected_parent_offset_depths)) { // match at last tag is within selected parent. if we have recursed properly then we shouldn't really have to check that the child_offset is within the parent_full_string
						//if($child_offset > $parent_offset && $child_offset < $parent_offset + strlen($parent_full_string) && O::depth_match($child_offset, $parent_offset, $this->selected_parent_offset_depths)) {
						if($child_offset > $parent_offset && $child_offset < $parent_offset + strlen($parent_full_string) && O::depth_match($child_offset, $parent_offset)) {
							//if($child_offset > $parent_offset && $child_offset < $parent_offset + strlen($parent_full_string) && O::depth_match($child_offset, $parent_offset)) {
							//print('recursive_select0014.8<br />' . PHP_EOL);
							//print('selecting piece 0002<br />' . PHP_EOL);
							//if($matches_at_last_tag_value === $selected_parent_matches_value) { // again, ugly but probably works
							//if($matches_at_last_tag_value[0] === $selected_parent_matches_value[0] && $matches_at_last_tag_value[1] + $offset_to_add === $selected_parent_matches_value[1]) { // again, ugly but probably works
							//if($matches_at_last_tag_value[0] === $selected_parent_matches_value[0] && $child_offset === $parent_offset) { // again, ugly but probably works
							//print('selecting piece 0003<br />' . PHP_EOL);
							//} else {
							//print('selecting piece 0004<br />' . PHP_EOL);
							//$best_match = $selected_parent_matches_value;
							$selected_parent_full_selector_matches[] = $selected_parent_matches_value;
							//}
							//continue 2;
							//print('selecting piece 0005<br />' . PHP_EOL);
						}
					}
					//print('recursive_select0014.9<br />' . PHP_EOL);
					//print('$best_match: ');var_dump($best_match);
					//print('selecting piece 0006<br />' . PHP_EOL);
					//if($best_match === false) {
					//	print('$this->selector_pieces, $matches_at_last_tag: ');var_dump($this->selector_pieces, $matches_at_last_tag);
					//	O::fatal_error('should never not find a selected parent');
					//	//O::warning('should never not find a selected parent'); // some wierdness, maybe with having an attribute on the last tag?
					//	//$selected_parent_full_selector_matches = $matches_at_last_tag;
					//} else {
					//	//print('selecting piece 0007<br />' . PHP_EOL);
					//	$selected_parent_full_selector_matches[] = $best_match;
					//}
				}
				//print('recursive_select0014.10<br />' . PHP_EOL);
				//print('selecting piece 0008<br />' . PHP_EOL);
				// if(isset($selector_uses_overlay) && $selector_uses_overlay && is_array($selected_parent_full_selector_matches) && sizeof($selected_parent_full_selector_matches) > 0) {
				// 	$selected_parent_full_selector_matches = $this->comparison_postfilter($selected_parent_full_selector_matches, $normalized_selector);
				// }
				$selected_parent_full_selector_matches = O::data_unique($selected_parent_full_selector_matches); // for & in the selector
				$selected_parent_full_selector_matches = array_values($selected_parent_full_selector_matches);
				//print('$selected_parent_full_selector_matches at the end of recursive_select(): ');var_dump($selected_parent_full_selector_matches);
				//$selector_piece_set_matches = $selected_parent_full_selector_matches;
				return $selected_parent_full_selector_matches;
			}
			//print('recursive_select0014.11<br />' . PHP_EOL);
			//print('$matches[0] at the end of recursive_select(): ');var_dump($matches[0]);
			return $matches[0];
		}
		//print('recursive_select0015<br />' . PHP_EOL);
		//print('$matches in recursive_select before recursing: ');var_dump($matches);
		$selector_matches = array();
		foreach($matches[0] as $index => $value) {
			//$offset = $matches[0][$index][1] + strlen($matches[0][$index][0]);
			$offset = $matches[0][$index][1];
			//$offset += strpos($code, '<', $offset);
			//print('expanding in recursive_select()2<br />' . PHP_EOL);
			//$expanded_LOM = O::expand($code, $offset, $offset_to_add, $offset_depths, 'greedy');
			$expanded_LOM = O::expand($code, $offset, $offset_to_add);
			//print('$expanded_LOM: ');var_dump($expanded_LOM);
			//$expanded_LOM = O::expand($code, $offset, $matches[0][$index][1]);
			//$offset_to_add = $expanded_LOM[0][1] + strlen($expanded_LOM[0][0]);
			//$offset_to_add2 = $expanded_LOM[1][1] + $offset_to_add;
			//$descendant_code = $expanded_LOM[0][0];
			//$descendant_offset_to_add = $expanded_LOM[0][1];
			$descendant_code = $expanded_LOM[1][0];
			$descendant_offset_to_add = $expanded_LOM[1][1];
			$parent_depth = $expanded_LOM[2];
			//$descendant_offset_depths = O::get_offset_depths($descendant_code, $descendant_offset_to_add, O::depth($descendant_offset_to_add, $offset_depths));
			//$descendant_offset_frame = array($descendant_offset_to_add, strlen($descendant_code));
			//print('$descendant_code, $descendant_offset_to_add, $parent_depth, $selector_piece_index, $descendant_offset_depths at end of recursive_select(): ');var_dump($descendant_code, $descendant_offset_to_add, $parent_depth, $selector_piece_index, $descendant_offset_depths);
			//print('$code at the end of recursive_select: ');var_dump($code);
			//return O::recursive_select($code, $offset_to_add);
			//$selector_matches = array_merge($selector_matches, O::recursive_select($code2, $offset_to_add2, $selector_piece_index + 1, O::get_offset_depths($code2, $offset_to_add2, $parent_depth + O::depth($offset_to_add2, $offset_depths) - $parent_depth), $parent_depth));
			//$selector_matches = array_merge($selector_matches, O::recursive_select($descendant_code, $descendant_offset_to_add, $selector_piece_index + 1, $descendant_offset_depths, $parent_depth + 1));
			//$selector_matches = array_merge($selector_matches, O::recursive_select($descendant_code, $descendant_offset_to_add, $selector_piece_index + 1, $descendant_offset_frame, $parent_depth + 1));
			$selector_matches = array_merge($selector_matches, O::recursive_select($descendant_code, $descendant_offset_to_add, $selector_piece_index + 1, $parent_depth + 1));
			if($this->reached_selector_index) { // stop matching and recursing
				break;
			}
		}
		//print('recursive_select0016<br />' . PHP_EOL);
		//foreach($selector_matches as $index => $value) {
		//	$selector_matches[$index][1] += $offset_to_add;
		//}
		//print('$selector_matches at end of recursive_select: ');var_dump($selector_matches);
		return $selector_matches;
	}

	function get_comparison_operator($piece, $piece_offset) {
		foreach($this->comparison_operators as $comparison_operator => $comaprison_operator_replacement) {
			if(substr($piece, $piece_offset, strlen($comparison_operator)) === $comparison_operator) {
				return $comparison_operator;
			}
		}
		return false;
	}

	function parse_index_spec($raw) {
		if(!is_string($raw) || $raw === '') {
			return false;
		}
		if(ctype_digit($raw)) {
			return (int)$raw;
		}
		$map = array();
		$parts = explode(',', $raw);
		$added = 0;
		foreach($parts as $part) {
			$part = trim($part);
			if($part === '') {
				continue;
			}
			$dash = strpos($part, '-');
			$left = ($dash !== false) ? trim(substr($part, 0, $dash)) : '';
			$right = ($dash !== false) ? trim(substr($part, $dash + 1)) : '';
			if($dash !== false && $left !== '' && $right !== '' && ctype_digit($left) && ctype_digit($right)) {
				$lo = (int)$left;
				$hi = (int)$right;
				if($lo > $hi) {
					$tmp = $lo;
					$lo = $hi;
					$hi = $tmp;
				}
				if($lo < 0) {
					$lo = 0;
				}
				if($hi - $lo > 512) {
					$hi = $lo + 512;
				}
				for($n = $lo; $n <= $hi; $n++) {
					$map[$n] = 1;
					$added++;
				}
			} else {
				if(!ctype_digit(str_replace(array(' ', "\t"), '', $part))) {
					continue;
				}
				$n = (int)$part;
				if($n >= 0) {
					$map[$n] = 1;
					$added++;
				}
			}
			if($added > 512) {
				break;
			}
		}
		if($added === 0) {
			return false;
		}
		if($added === 1) {
			reset($map);
			return (int)key($map);
		}
		return $map;
	}

	function pick_offsets_by_index_spec($piece_matches, $spec) {
		if($spec === false || $spec === null) {
			return $piece_matches;
		}
		$out = array();
		if(is_int($spec)) {
			if($spec >= 0 && isset($piece_matches[$spec])) {
				$out[] = $piece_matches[$spec];
			}
			return $out;
		}
		if(!is_array($spec)) {
			return $out;
		}
		$keys = array_keys($spec);
		sort($keys, SORT_NUMERIC);
		foreach($keys as $n) {
			$pick = (int)$n;
			if($pick >= 0 && isset($piece_matches[$pick])) {
				$out[] = $piece_matches[$pick];
			}
		}
		return $out;
	}

	function qualified_index_spec_at($selector_piece_index, $tagname_index) {
		// Indices on valued matches (tagvalue and/or attribute value) apply to the
		// collected result set, not per-parent sibling positions.
		$tv = (isset($this->tagvalues[$selector_piece_index][$tagname_index])) ? $this->tagvalues[$selector_piece_index][$tagname_index] : false;
		$tv_idx = (isset($this->tagvalue_indices[$selector_piece_index][$tagname_index])) ? $this->tagvalue_indices[$selector_piece_index][$tagname_index] : false;
		if($tv !== false && $tv !== '' && $tv_idx !== false && $tv_idx !== null) {
			return $tv_idx;
		}
		$at_idx = (isset($this->attributes_indices[$selector_piece_index][$tagname_index])) ? $this->attributes_indices[$selector_piece_index][$tagname_index] : false;
		if($at_idx === false || $at_idx === null) {
			return false;
		}
		$req = (isset($this->required_attribute_sets[$selector_piece_index][$tagname_index]) && is_array($this->required_attribute_sets[$selector_piece_index][$tagname_index])) ? $this->required_attribute_sets[$selector_piece_index][$tagname_index] : array();
		foreach($req as $attribute_value) {
			if($attribute_value !== false && $attribute_value !== null && $attribute_value !== '') {
				return $at_idx;
			}
		}
		return false;
	}

	function apply_qualified_result_indexes($matches) {
		if(!is_array($matches) || sizeof($matches) === 0) {
			return $matches;
		}
		$piece_count = 0;
		if(isset($this->selector_pieces) && is_array($this->selector_pieces)) {
			$piece_count = sizeof($this->selector_pieces);
		} elseif(isset($this->tagnames) && is_array($this->tagnames)) {
			$piece_count = sizeof($this->tagnames);
		}
		$spec = false;
		for($spi = $piece_count - 1; $spi >= 0; $spi--) {
			if(!isset($this->tagnames[$spi]) || !is_array($this->tagnames[$spi])) {
				continue;
			}
			$alts = sizeof($this->tagnames[$spi]);
			for($i = 0; $i < $alts; $i++) {
				$found = O::qualified_index_spec_at($spi, $i);
				if($found !== false) {
					$spec = $found;
					break 2;
				}
			}
		}
		if($spec === false) {
			return $matches;
		}
		return O::pick_offsets_by_index_spec($matches, $spec);
	}

	function consume_index_spec($spec, &$counter) {
		if($spec === false || $spec === null) {
			return true;
		}
		if(is_array($spec)) {
			$hit = isset($spec[$counter]);
			$counter++;
			return $hit;
		}
		if(!is_int($spec)) {
			return false;
		}
		$hit = ($counter === $spec);
		$counter++;
		return $hit;
	}

	function inner_text_from_offset($offset) {
		O::ensure_parent_indexes();
		if(!isset($this->opening_tag_names[$offset])) {
			return '';
		}
		if(!isset($this->tag_end_offsets[$offset]) || !isset($this->node_end_offsets[$offset])) {
			$expanded = O::expand(false, $offset);
			return (isset($expanded[1][0])) ? (string)$expanded[1][0] : '';
		}
		if(O::tag_is_self_closing_at($this->code, $offset, $this->tag_end_offsets[$offset])) {
			return '';
		}
		$tagname = $this->opening_tag_names[$offset];
		$inner_start = $this->tag_end_offsets[$offset] + 1;
		$closing = '</' . $tagname . '>';
		$close_start = $this->node_end_offsets[$offset] - strlen($closing) + 1;
		if($close_start < $inner_start) {
			return '';
		}
		return substr($this->code, $inner_start, $close_start - $inner_start);
	}

	function attribute_value_at_offset($offset, $attribute_name) {
		$attribute_name = (string)$attribute_name;
		O::ensure_attribute_index($attribute_name);
		if(!isset($this->attribute_index[$attribute_name][$offset])) {
			return null;
		}
		if(isset($this->attribute_value_index[$attribute_name]) && is_array($this->attribute_value_index[$attribute_name])) {
			foreach($this->attribute_value_index[$attribute_name] as $val => $offs) {
				if(isset($offs[$offset])) {
					return (string)$val;
				}
			}
		}
		return '';
	}

	function offset_matches_required_attributes($offset, $required_attributes, $attribute_ops) {
		if(!is_array($required_attributes) || sizeof($required_attributes) === 0) {
			return true;
		}
		$op_index = 0;
		foreach($required_attributes as $attribute_name => $attribute_value) {
			$op = false;
			if(is_array($attribute_ops)) {
				if(isset($attribute_ops[$op_index]) && $attribute_ops[$op_index] !== false && $attribute_ops[$op_index] !== '') {
					$op = $attribute_ops[$op_index];
				} elseif(isset($attribute_ops[$attribute_name]) && $attribute_ops[$attribute_name] !== false && $attribute_ops[$attribute_name] !== '') {
					$op = $attribute_ops[$attribute_name];
				}
			}
			$actual = O::attribute_value_at_offset($offset, $attribute_name);
			if($attribute_value === false) {
				if($actual === null) {
					return false;
				}
			} else {
				if($actual === null) {
					return false;
				}
				if($op === false || $op === '') {
					$op = '=';
				}
				if($op === '=') {
					if($actual != $attribute_value) {
						return false;
					}
				} elseif(!O::compare($actual, $op, $attribute_value)) {
					return false;
				}
			}
			$op_index++;
		}
		return true;
	}

	function find_named_open_covering_offset($tagname, $byte_off) {
		$offs = O::get_tag_index_offsets($tagname);
		$n = sizeof($offs);
		if($n === 0) {
			return false;
		}
		$lo = 0;
		$hi = $n;
		while($lo < $hi) {
			$mid = ($lo + $hi) >> 1;
			if($offs[$mid] <= $byte_off) {
				$lo = $mid + 1;
			} else {
				$hi = $mid;
			}
		}
		for($i = $lo - 1; $i >= 0; $i--) {
			$o = $offs[$i];
			if(!isset($this->node_end_offsets[$o])) {
				continue;
			}
			if($this->node_end_offsets[$o] >= $byte_off) {
				return $o;
			}
		}
		return false;
	}

	/**
	 * Document-level regex for selective patterns: one preg_match_all on $this->code,
	 * map hit offsets to tag opens, then apply the operator on inner text.
	 * Returns offset list, or false to fall back to per-candidate filtering.
	 */
	function try_document_level_regex_tagvalue($tagname, $op, $regex_id, $tagvalue_index = false) {
		if($tagname === false || $tagname === '' || $tagname === '*') {
			return false;
		}
		if(!in_array($op, array('%=', '=', '^=', '$=', '~='), true)) {
			return false;
		}
		if(!isset($this->selector_regex_table[$regex_id])) {
			return false;
		}
		$candidates = O::get_tag_index_offsets($tagname);
		$cand_n = sizeof($candidates);
		if($cand_n < 256) {
			return false;
		}
		$compiled = $this->selector_regex_table[$regex_id]['compiled'];
		$matches = O::regex_match_array($this->code, $compiled, true);
		$hit_n = sizeof($matches);
		if($hit_n === 0) {
			return array();
		}
		/* Not selective enough vs walking the tag index. */
		if($hit_n > ($cand_n / 4) && $hit_n > 512) {
			return false;
		}
		$seen = array();
		$out = array();
		foreach($matches as $m) {
			$byte_off = $m[0][1];
			$open = O::find_named_open_covering_offset($tagname, $byte_off);
			if($open === false || isset($seen[$open])) {
				continue;
			}
			$inner_start = isset($this->tag_end_offsets[$open]) ? ($this->tag_end_offsets[$open] + 1) : $open;
			$inner_end = isset($this->node_end_offsets[$open]) ? $this->node_end_offsets[$open] : $byte_off;
			/* Match must land in element text (or on the opening tag for attrs — skip those). */
			if($byte_off < $inner_start || $byte_off > $inner_end) {
				continue;
			}
			$tv = O::inner_text_from_offset($open);
			if(!O::compare_regex_matches($tv, $op, $regex_id)) {
				continue;
			}
			$seen[$open] = 1;
			$out[] = $open;
		}
		sort($out, SORT_NUMERIC);
		if($tagvalue_index !== false && $tagvalue_index !== null) {
			$out = O::pick_offsets_by_index_spec($out, $tagvalue_index);
		}
		return $out;
	}

	function fast_apply_index_piece($candidates, $piece) {
		$tagname = isset($piece['tagname']) ? $piece['tagname'] : '*';
		$piece_matches = array();
		foreach($candidates as $child_offset) {
			if($tagname !== '*') {
				if(!isset($this->opening_tag_names[$child_offset]) || $this->opening_tag_names[$child_offset] !== $tagname) {
					continue;
				}
			}
			$piece_matches[] = $child_offset;
		}
		$tagname_index = isset($piece['tagname_index']) ? $piece['tagname_index'] : (isset($piece['index']) ? $piece['index'] : false);
		if($tagname_index !== false && $tagname_index !== null) {
			$piece_matches = O::pick_offsets_by_index_spec($piece_matches, $tagname_index);
		}
		$tagvalue = isset($piece['tagvalue']) ? $piece['tagvalue'] : false;
		if($tagvalue !== false && $tagvalue !== null && $tagvalue !== '') {
			$op = (isset($piece['tagvalue_op']) && $piece['tagvalue_op'] !== false && $piece['tagvalue_op'] !== '') ? $piece['tagvalue_op'] : '=';
			if($tagname === '*' && $op === '=') {
				$op = '%=';
			}
			$filtered = array();
			foreach($piece_matches as $off) {
				$tv = O::inner_text_from_offset($off);
				if($op === '=' && O::lom_regex_token_id($tagvalue) === false) {
					if($tv == $tagvalue) {
						$filtered[] = $off;
					}
				} elseif(O::compare($tv, $op, $tagvalue)) {
					$filtered[] = $off;
				}
			}
			$piece_matches = $filtered;
		}
		$tagvalue_index = isset($piece['tagvalue_index']) ? $piece['tagvalue_index'] : false;
		if($tagvalue_index !== false && $tagvalue_index !== null) {
			$piece_matches = O::pick_offsets_by_index_spec($piece_matches, $tagvalue_index);
		}
		$required_attributes = (isset($piece['required_attributes']) && is_array($piece['required_attributes'])) ? $piece['required_attributes'] : array();
		if(sizeof($required_attributes) > 0) {
			$ops = (isset($piece['attribute_ops']) && is_array($piece['attribute_ops'])) ? $piece['attribute_ops'] : array();
			$filtered = array();
			foreach($piece_matches as $off) {
				if(O::offset_matches_required_attributes($off, $required_attributes, $ops)) {
					$filtered[] = $off;
				}
			}
			$piece_matches = $filtered;
		}
		$attributes_index = isset($piece['attributes_index']) ? $piece['attributes_index'] : false;
		if($attributes_index !== false && $attributes_index !== null) {
			$piece_matches = O::pick_offsets_by_index_spec($piece_matches, $attributes_index);
		}
		return $piece_matches;
	}

	function collapse_same_tag_index_and($selector_piece_index) {
		if(!isset($this->tagnames[$selector_piece_index])) {
			return;
		}
		$names = $this->tagnames[$selector_piece_index];
		$n = sizeof($names);
		if($n < 2) {
			return;
		}
		$first = $names[0];
		$acc = null;
		for($i = 0; $i < $n; $i++) {
			if($names[$i] !== $first) {
				return;
			}
			if(isset($this->tagvalues[$selector_piece_index][$i]) && $this->tagvalues[$selector_piece_index][$i] !== false) {
				return;
			}
			if(isset($this->required_attribute_sets[$selector_piece_index][$i]) && sizeof($this->required_attribute_sets[$selector_piece_index][$i]) > 0) {
				return;
			}
			$idx = isset($this->tagname_indices[$selector_piece_index][$i]) ? $this->tagname_indices[$selector_piece_index][$i] : false;
			if($idx === false || $idx === null) {
				return;
			}
			if(is_int($idx)) {
				$map = array($idx => 1);
			} elseif(is_array($idx)) {
				$map = $idx;
			} else {
				return;
			}
			if($acc === null) {
				$acc = $map;
			} else {
				$next = array();
				foreach($acc as $k => $_v) {
					if(isset($map[$k])) {
						$next[$k] = 1;
					}
				}
				$acc = $next;
			}
		}
		if($acc === null || sizeof($acc) === 0) {
			$spec = array();
		} elseif(sizeof($acc) === 1) {
			reset($acc);
			$spec = (int)key($acc);
		} else {
			$spec = $acc;
		}
		$this->tagnames[$selector_piece_index] = array($first);
		$this->tagvalues[$selector_piece_index] = array(false);
		$this->tagvalue_comparison_operators[$selector_piece_index] = array(false);
		$this->tagname_indices[$selector_piece_index] = array($spec);
		$this->tagvalue_indices[$selector_piece_index] = array(false);
		$this->attributes_indices[$selector_piece_index] = array(false);
		$this->required_attribute_sets[$selector_piece_index] = array(array());
		$this->attribute_sets_comparison_operators[$selector_piece_index] = array(array());
		$this->tagname_match_counter[$selector_piece_index] = array(0);
		$this->tagvalue_match_counter[$selector_piece_index] = array(0);
		$this->attributes_match_counter[$selector_piece_index] = array(0);
	}

	function parse_selector_piece($piece, $selector_piece_index) {
		//print('$piece, $selector_piece_index at start of parse_selector_piece: ');var_dump($piece, $selector_piece_index);
		static $piece_parse_cache = array();
		if(isset($piece_parse_cache[$piece])) {
			$cached = $piece_parse_cache[$piece];
			$this->tagnames[$selector_piece_index] = $cached['tagnames'];
			$this->tagvalues[$selector_piece_index] = $cached['tagvalues'];
			$this->tagvalue_comparison_operators[$selector_piece_index] = $cached['tagvalue_comparison_operators'];
			$this->tagname_indices[$selector_piece_index] = $cached['tagname_indices'];
			$this->tagvalue_indices[$selector_piece_index] = $cached['tagvalue_indices'];
			$this->attributes_indices[$selector_piece_index] = $cached['attributes_indices'];
			$this->required_attribute_sets[$selector_piece_index] = $cached['required_attribute_sets'];
			$this->attribute_sets_comparison_operators[$selector_piece_index] = $cached['attribute_sets_comparison_operators'];

			$n = $cached['n'];
			// Counters are mutable during matching; reinitialize for this query.
			// Cache zero-filled arrays by length to avoid repeated allocation work.
			static $zero_counter_cache = array();
			if($n > 0) {
				if(!isset($zero_counter_cache[$n])) {
					$zero_counter_cache[$n] = array_fill(0, $n, 0);
				}
				$zeros = $zero_counter_cache[$n];
			} else {
				$zeros = array();
			}
			$this->tagname_match_counter[$selector_piece_index] = $zeros;
			$this->tagvalue_match_counter[$selector_piece_index] = $zeros;
			$this->attributes_match_counter[$selector_piece_index] = $zeros;

			if($cached['selected_parent_set']) {
				$this->selected_parent_piece_index = $selector_piece_index;
			}
			return;
		}

		$piece_offset = 0;
		$this->tagnames[$selector_piece_index] = array();
		$tagname = '';
		$attribute_name_piece = '';
		$attribute_value_piece = '';
		$this->tagvalues[$selector_piece_index] = array();
		$this->tagvalue_comparison_operators[$selector_piece_index] = array();
		$tagvalue = false;
		$tagvalue_comparison_operator = false;
		$this->tagname_indices[$selector_piece_index] = array();
		$this->tagvalue_indices[$selector_piece_index] = array();
		$this->attributes_indices[$selector_piece_index] = array();
		$tagname_index = false;
		$tagvalue_index = false;
		$attributes_index = false;
		$parsing_attribute_name = false;
		$parsing_attribute_value = false;
		$this->required_attribute_sets[$selector_piece_index] = array();
		$this->attribute_sets_comparison_operators[$selector_piece_index] = array();
		$required_attributes = array();
		$attribute_set_comparison_operators = array();

		// attribute systax doesn't use square brackets [ ] unlike XPath; tagname1[5]=tagvalue1[17]@attname1=attvalue1@attname2=attvalue2@attname3=attvalue3[0]&tagname2[3]=tagvalue2[0]@attname4=attvalue4@attname5=attvalue5[8]
		while($piece_offset < strlen($piece)) {
			if($parsing_attribute_name) {
				if($piece[$piece_offset] === '@') {
					$required_attributes[O::query_decode($attribute_name_piece)] = false;
					$attribute_name_piece = '';
					$piece_offset++;
					continue;
				//} elseif($piece[$piece_offset] === '=') {
				} elseif(($attribute_set_comparison_operator = O::get_comparison_operator($piece, $piece_offset)) !== false) {
					//print('$attribute_set_comparison_operator: ');var_dump($attribute_set_comparison_operator);
					$attribute_set_comparison_operators[] = $attribute_set_comparison_operator;
					$parsing_attribute_name = false;
					$parsing_attribute_value = true;
					//$piece_offset++;
					$piece_offset += strlen($attribute_set_comparison_operator);
					continue;
				} elseif($piece_offset < strlen($piece) && $piece[$piece_offset] === '[') {
					$possible_index_length = strpos($piece, ']', $piece_offset) - $piece_offset - 1;
					$possible_index = substr($piece, $piece_offset + 1, $possible_index_length);
					$attributes_index = O::parse_index_spec($possible_index);
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
					$possible_index_length = strpos($piece, ']', $piece_offset) - $piece_offset - 1;
					$possible_index = substr($piece, $piece_offset + 1, $possible_index_length);
					$attributes_index = O::parse_index_spec($possible_index);
					$piece_offset += $possible_index_length + 2;
					continue;
				}
				$attribute_value_piece .= $piece[$piece_offset];
				$piece_offset++;
				continue;
			//} elseif($piece[$piece_offset] === '=') { // then we have the tagname and we find the specified tagvalue
			} elseif(($tagvalue_comparison_operator = O::get_comparison_operator($piece, $piece_offset)) !== false) {
				//print('$tagvalue_comparison_operator: ');var_dump($tagvalue_comparison_operator);
				//$piece_offset++;
				$piece_offset += strlen($tagvalue_comparison_operator);
				$tagvalue = '';
				while($piece_offset < strlen($piece) && $piece[$piece_offset] !== '@' && $piece[$piece_offset] !== '&' && $piece[$piece_offset] !== '[') {
					$tagvalue .= $piece[$piece_offset];
					$piece_offset++;
				}
				if($piece_offset < strlen($piece) && $piece[$piece_offset] === '[') {
					$possible_index_length = strpos($piece, ']', $piece_offset) - $piece_offset - 1;
					$possible_index = substr($piece, $piece_offset + 1, $possible_index_length);
					$tagvalue_index = O::parse_index_spec($possible_index);
					$piece_offset += $possible_index_length + 2;
					continue;
				}
				continue;
			} elseif($piece[$piece_offset] === '[') {
				$possible_index_length = strpos($piece, ']', $piece_offset) - $piece_offset - 1;
				$possible_index = substr($piece, $piece_offset + 1, $possible_index_length);
				$tagname_index = O::parse_index_spec($possible_index);
				$piece_offset += $possible_index_length + 2;
				continue;
			} elseif($piece[$piece_offset] === '@') {
				if($tagname === '') {
					$tagname = '*';
				}
				$parsing_attribute_name = true;
				$piece_offset++;
				continue;
			} elseif($piece[$piece_offset] === '&') {
				if($piece_offset === 0) {
					print('$piece: ');var_dump($piece);
					O::fatal_error('query piece starting with &amp; makes no sense.');
				} else {
					if($tagname[0] === '.') {
						$tagname = substr($tagname, 1);
						$this->selected_parent_piece_index = $selector_piece_index;
					}
					//print('$tagname when parsing & operator: ');var_dump($tagname);
					$this->tagnames[$selector_piece_index][] = O::query_decode($tagname);
					$tagname = '';
					//print('$tagvalue, $piece when parsing & operator: ');var_dump($tagvalue, $piece);
					$this->tagvalues[$selector_piece_index][] = O::query_decode($tagvalue);
					$op_push = $tagvalue_comparison_operator;
					if($op_push === false && $tagvalue !== false && $tagvalue !== '') {
						$op_push = '=';
					}
					$this->tagvalue_comparison_operators[$selector_piece_index][] = $op_push;
					$tagvalue = false;
					$tagvalue_comparison_operator = false;
					$this->tagname_indices[$selector_piece_index][] = $tagname_index;
					$tagname_index = false;
					$this->tagvalue_indices[$selector_piece_index][] = $tagvalue_index;
					$tagvalue_index = false;
					if($parsing_attribute_name) {
						$required_attributes[O::query_decode($attribute_name_piece)] = false;
					} elseif($parsing_attribute_value) {
						$required_attributes[O::query_decode($attribute_name_piece)] = O::query_decode($attribute_value_piece);
					}
					$this->required_attribute_sets[$selector_piece_index][] = $required_attributes;
					$this->attribute_sets_comparison_operators[$selector_piece_index][] = $attribute_set_comparison_operators;
					$required_attributes = array();
					$attribute_set_comparison_operators = array();
					$this->attributes_indices[$selector_piece_index][] = $attributes_index;
					$attributes_index = false;
					$this->tagname_match_counter[$selector_piece_index][] = 0;
					$this->tagvalue_match_counter[$selector_piece_index][] = 0;
					$this->attributes_match_counter[$selector_piece_index][] = 0;
					$piece_offset++;
					continue;
				}
			}
			$tagname .= $piece[$piece_offset];
			$piece_offset++;
		}
		if(strlen($tagname) > 0 || $tagname_index !== false) {
			if($tagname === '') {
				$tagname = '*';
			}
			if($tagname[0] === '.') {
				$tagname = substr($tagname, 1);
				$this->selected_parent_piece_index = $selector_piece_index;
			}
			//print('$tagname at the end of parsing: ');var_dump($tagname);
			$this->tagnames[$selector_piece_index][] = O::query_decode($tagname);
			//print('$tagvalue, $piece at end of parsing: ');var_dump($tagvalue, $piece);
			$this->tagvalues[$selector_piece_index][] = O::query_decode($tagvalue);
			$op_push_end = $tagvalue_comparison_operator;
			if($op_push_end === false && $tagvalue !== false && $tagvalue !== '') {
				$op_push_end = '=';
			}
			$this->tagvalue_comparison_operators[$selector_piece_index][] = $op_push_end;
			$this->tagname_indices[$selector_piece_index][] = $tagname_index;
			$this->tagvalue_indices[$selector_piece_index][] = $tagvalue_index;
			if($parsing_attribute_name) {
				$required_attributes[O::query_decode($attribute_name_piece)] = false;
			} elseif($parsing_attribute_value) {
				$required_attributes[O::query_decode($attribute_name_piece)] = O::query_decode($attribute_value_piece);
			}
			$this->required_attribute_sets[$selector_piece_index][] = $required_attributes;
			$this->attribute_sets_comparison_operators[$selector_piece_index][] = $attribute_set_comparison_operators;
			$this->attributes_indices[$selector_piece_index][] = $attributes_index;
			$this->tagname_match_counter[$selector_piece_index][] = 0;
			$this->tagvalue_match_counter[$selector_piece_index][] = 0;
			$this->attributes_match_counter[$selector_piece_index][] = 0;
		}
		O::collapse_same_tag_index_and($selector_piece_index);
		//print('$this->tagnames, $this->tagvalues at end of parse_selector_piece: ');var_dump($this->tagnames, $this->tagvalues);
		// Cache the parsed selector-piece metadata. Mutable counters are reinitialized
		// from cache on subsequent hits.
		$piece_parse_cache[$piece] = array(
			'tagnames' => $this->tagnames[$selector_piece_index],
			'tagvalues' => $this->tagvalues[$selector_piece_index],
			'tagvalue_comparison_operators' => $this->tagvalue_comparison_operators[$selector_piece_index],
			'tagname_indices' => $this->tagname_indices[$selector_piece_index],
			'tagvalue_indices' => $this->tagvalue_indices[$selector_piece_index],
			'attributes_indices' => $this->attributes_indices[$selector_piece_index],
			'required_attribute_sets' => $this->required_attribute_sets[$selector_piece_index],
			'attribute_sets_comparison_operators' => $this->attribute_sets_comparison_operators[$selector_piece_index],
			'n' => sizeof($this->tagnames[$selector_piece_index]),
			'selected_parent_set' => ($this->selected_parent_piece_index === $selector_piece_index),
		);
	}

	function select_old($selector, $matching_array = false, $offset_depths = false) {
		//print('start of select()<br />' . PHP_EOL);
		if($matching_array === false) {
			//print('here374859---0005.6<br />' . PHP_EOL);
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
			//$index = sizeof($matching_array) - 1;
			//while($index > -1) { // very important to go in reverse_order?
			//	$value = $matching_array[$index];
			$selector_matches = array_merge($selector_matches, O::preg_select($value[0], $value[1], $offset_depths));
			//	$index--;
		}
		//print('$selector_matches in select before get_tag_string: ');var_dump($selector_matches);
		foreach($selector_matches as $index => $value) {
			//$selector_matches[$index][0] = O::get_tag_string(substr($this->code, $selector_matches[$index][1]), strlen($selector_matches[$index][0]), $selector_matches[$index][1]);
			//$selector_matches[$index][0] = O::get_tag_string($selector_matches[$index][1], $offset_depths);
			$selector_matches[$index][0] = O::get_tag_string($selector_matches[$index][1]);
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
			//print('here374859---0006<br />' . PHP_EOL);
			$contextual_matches = $matching_array;
			$last_piece = 'Z'; // dummy initialization
			foreach($selector_piece_set as $piece_index => $piece) {
				//print('here374859---0007<br />' . PHP_EOL);
				//print('$piece: ');var_dump($piece);
				// parse the piece
				//if($piece_index === 0 || strlen($last_piece) === 0 || strpos($last_piece, '*') !== false) {
				if($piece_index === 0 || strlen($last_piece) === 0) {
					//print('here374859---0008<br />' . PHP_EOL);
					$look_only_in_direct_children = false;
				} else {
					//print('here374859---0008.5<br />' . PHP_EOL);
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
				//print('here374859---0009<br />' . PHP_EOL);
				// attribute systax doesn't use square brackets [ ] unlike XPath; tagname[5]=tagvalue@attname1=attvalue1@attname2=attvalue2@attname3=attvalue3
				while($piece_offset < strlen($piece)) {
					//print('here374859---0010<br />' . PHP_EOL);
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
						//print('here374859---0011<br />' . PHP_EOL);
						//if($tagvalues === false) {
						//	$tagvalues = array();
						//}
						$piece_offset++;
						$tagvalue = '';
						while($piece_offset < strlen($piece) && $piece[$piece_offset] !== '@' && $piece[$piece_offset] !== '&') {
							//print('here374859---0011.5<br />' . PHP_EOL);
							$tagvalue .= $piece[$piece_offset];
							$piece_offset++;
						}
						continue;
					} elseif($piece[$piece_offset] === '[') { // check whether we are selecting by order or by attribute
						//print('here374859---0012<br />' . PHP_EOL);
						$possible_index_length = strpos($piece, ']', $piece_offset) - $piece_offset - 1;
						$possible_index = substr($piece, $piece_offset + 1, $possible_index_length);
						if(is_numeric($possible_index)) {
							//print('here374859---0013<br />' . PHP_EOL);
							$matching_index = $possible_index;
							$piece_offset += $possible_index_length + 2;
							continue;
						} else {
							//print('here374859---0031<br />' . PHP_EOL);
							print('$possible_index: ');var_dump($possible_index);
							O::fatal_error('!is_numeric($possible_index)');
						}
					} elseif($piece[$piece_offset] === '@') {
						//print('here374859---0032<br />' . PHP_EOL);
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
						//print('here374859---0032.5<br />' . PHP_EOL);
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
				//print('here374859---0033<br />' . PHP_EOL);
				foreach($tagnames as $tagname) {
					if($tagname[0] === '.') {
						//print('here374859---0034<br />' . PHP_EOL);
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
			//print('here374859---0038<br />' . PHP_EOL);
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
					*			$depth_requirement = false;
					*			if(strpos($selector, '__') !== false) {
					*				//O::fatal_error('__ unhandled for selecting the parent in select');
					*				$depth_requirement = $piece_index - $selected_parent_piece_index;
					*				//foreach($selector_piece_set as $piece_index2 => $piece2) {
					*				//	if(strlen($piece2) === 0) {
					*				//		$depth_requirement--;
					*				//	}
					*				//}
			}
			//print('here374859---0038.5<br />' . PHP_EOL);
			//print('$selected_parent_matches: ');var_dump($selected_parent_matches);
			$selected_parent_full_selector_matches = array();
			foreach($selector_piece_set_matches as $contextual_matches_index => $contextual_matches_value) {
				//print('here374859---0038.51<br />' . PHP_EOL);
				foreach($contextual_matches_value as $contextual_matches_first_index => $contextual_matches_first_value) { break; }
				foreach($selected_parent_matches as $selected_parent_matches_index => $selected_parent_matches_value) {
					//print('here374859---0038.52<br />' . PHP_EOL);
					//print('$selected_parent_matches_index: ');var_dump($selected_parent_matches_index);
					$first_index4 = false;
					foreach($selected_parent_matches_value as $index4 => $value4) {
						if($first_index4 === false) {
							$first_index4 = $index4;
			}
			//print('here374859---0038.53<br />' . PHP_EOL);
			if($contextual_matches_first_index === $index4) {
				//print('$contextual_matches_first_index, $index4: ');var_dump($contextual_matches_first_index, $index4);
				//print('here374859---0038.54<br />' . PHP_EOL);
				$counter = $index4 - 1;
				$depth_counter = 0;
				while($counter >= $first_index4) {
					//print('here374859---0038.55<br />' . PHP_EOL);
					if($selected_parent_matches_value[$counter][0] == 1) {
						if($selected_parent_matches_value[$counter][1][2] === 0) {
							//print('here374859---0038.56<br />' . PHP_EOL);
							$depth_counter++;
			}
			if($selected_parent_matches_value[$counter][1][2] === 1) {
				//print('here374859---0038.561<br />' . PHP_EOL);
				$depth_counter--;
			}
			}
			$counter--;
			}
			//print('here374859---0038.57<br />' . PHP_EOL);
			//print('$depth_counter, $depth_requirement, $piece_index, $selected_parent_piece_index: ');var_dump($depth_counter, $depth_requirement, $piece_index, $selected_parent_piece_index);
			if($depth_requirement !== false && $depth_counter >= $depth_requirement) {
				//print('here374859---0038.571<br />' . PHP_EOL);
				$its_already_there = false;
				foreach($selected_parent_full_selector_matches as $index35 => $value35) {
					//print('here374859---0038.572<br />' . PHP_EOL);
					if($value35 === $selected_parent_matches_value) {
						//print('here374859---0038.573<br />' . PHP_EOL);
						$its_already_there = true;
						break;
			}
			}
			//print('here374859---0038.574<br />' . PHP_EOL);
			if(!$its_already_there) {
				//print('here374859---0038.575<br />' . PHP_EOL);
				$selected_parent_full_selector_matches[] = $selected_parent_matches_value;
			}
			} elseif($depth_counter === $piece_index - $selected_parent_piece_index) {
				//print('here374859---0038.58<br />' . PHP_EOL);
				$its_already_there = false;
				foreach($selected_parent_full_selector_matches as $index35 => $value35) {
					//print('here374859---0038.581<br />' . PHP_EOL);
					if($value35 === $selected_parent_matches_value) {
						//print('here374859---0038.582<br />' . PHP_EOL);
						$its_already_there = true;
						break;
			}
			}
			//print('here374859---0038.583<br />' . PHP_EOL);
			if(!$its_already_there) {
				//print('here374859---0038.584<br />' . PHP_EOL);
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
				//print('here374859---0035<br />' . PHP_EOL);
				$selector_matches = array_merge($selector_matches, $selector_piece_set_matches);
				//break;
			}
		}
		//print('$code at the end of select: ');var_dump($code);
		//print('$selector_matches at end of select: ');var_dump($selector_matches);
		//print('end of select()<br />' . PHP_EOL);
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

	function closing_tag_LOM_index_from_offset($offset) { // alias
		O::fatal_error('closing_tag_LOM_index_from_offset probably obsolete');
		// the behavior is the same; skip zero-length text nodes
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
			//$index = sizeof($matching_array) - 1;
			//while($index > -1) { // very important to go in reverse_order?
			//	$value = $matching_array[$index];
			if($look_only_in_direct_children) {
				foreach($tagname_array as $tagname_index => $tagname) {
					if($tagname[0] === '.') {
						$tagname = substr($tagname, 1);
					}
					//print('$tagname, $tagvalue_array[$tagname_index]: ');var_dump($tagname, $tagvalue_array[$tagname_index]);
					if($tagname === '*') {
						//print('get_all_tags_at_this_level<br />' . PHP_EOL);
						$tagname_matches = O::get_all_tags_at_this_level($value[0], $value[1], $tagvalue_array[$tagname_index], $matching_indices[$tagname_index], $required_attribute_sets[$tagname_index]);
					} else {
						//print('get_all_named_tags_at_this_level<br />' . PHP_EOL);
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
						//print('get_all_tags<br />' . PHP_EOL);
						$tagname_matches = O::get_all_tags($value[0], $value[1], $tagvalue_array[$tagname_index], $matching_indices[$tagname_index], $required_attribute_sets[$tagname_index]);
					} else {
						//print('get_all_named_tags<br />' . PHP_EOL);
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
			//	$index--;
		}
		//print('$this->code, $tagname, $matches: ');var_dump($this->code, $tagname, $matches);exit(0);
		return $matches;
		/*$LOMified_matches = array();
			*	foreach($matches as $index => $value) {
			*		$string = $value[0];
			*		$offset = $value[1];
			*		$LOMified_matches[] = O::LOM($string, O::opening_LOM_index_from_offset($offset), $offset);
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
				//$index2 = sizeof($matching_array) - 1;
				//while($index2 > -1) { // very important to go in reverse_order?
				//	$value2 = $matching_array[$index2];
				//print('here374859---0018<br />' . PHP_EOL);
				//print('$index2, $value2: ');var_dump($index2, $value2);
				// debug
				if($this->debug && !is_array($value2)) {
					print('$matching_array, $index2, $value2: ');var_dump($matching_array, $index2, $value2);
					O::fatal_error('!is_array($value2)');
				}
				$tagvalues_satisfied = array();
				foreach($tagvalue_array as $tagvalue_index => $tagvalue) {
					$tagvalues_satisfied[$tagvalue_index] = false;
				}
				$potential_matches = array();
				foreach($value2 as $index => $value) {
					//print('here374859---0019<br />' . PHP_EOL);
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
						//print('here237541<br />' . PHP_EOL);
						if(($look_only_in_direct_children === false || $matching_depth === 1) && $value[0] == 1 && $value[1][2] === 0 && ($tagname === $value[1][0] || $tagname == '*') && ($tagvalue === false || (strlen($tagvalue) > 0 && $value2[$index + 1][0] === 0 && $tagvalue === $value2[$index + 1][1])) && $required_attributes_exist) {
							//print('here237542<br />' . PHP_EOL);
							if($matching_index === false || $matching_index === $matches_counter) {
								//print('here237543<br />' . PHP_EOL);
								// build up the match
								$match_depth = $matching_depth;
								$match_at_depth = array();
								foreach($value2 as $match_index2 => $matching_entry2) {
									if($match_index2 < $index) {
										continue;
									}
									$match_at_depth[$match_index2] = $matching_entry2;
									if($matching_entry2[0] == 1 && $matching_entry2[1][2] === 0) {
										//print('here237544<br />' . PHP_EOL);
										$match_depth++;
									}
									if($matching_entry2[0] == 1 && $matching_entry2[1][2] === 1) {
										//print('here237545<br />' . PHP_EOL);
										$match_depth--;
										if($match_depth === $matching_depth) {
											//print('here237546<br />' . PHP_EOL);
											break;
										}
									}
								}
								//print('here237547<br />' . PHP_EOL);
								//print('$match_at_depth: ');var_dump($match_at_depth);
								$potential_matches[] = $match_at_depth;
								$tagvalues_satisfied[$tagvalue_index] = true;
								//print('$potential_matches, $tagvalues_satisfied: ');var_dump($potential_matches, $tagvalues_satisfied);
								//$first_tagvalue_was_matched = true;
							}
							//print('here237548<br />' . PHP_EOL);
							$matches_counter++;
						}
						//if($tagvalue_index > 0 && $first_tagvalue_was_matched) { // if both tagvalue conditions are not satisfied then don't call it a match
						//	unset($matches[sizeof($matches) - 1]);
						//}
						//print('$matches at bottom of tagvalue_array processing: ');var_dump($matches);
					}
					//print('$potential_matches: ');var_dump($potential_matches);

					if($value[0] == 1 && $value[1][2] === 0) {
						//print('here374859---0021<br />' . PHP_EOL);
						$matching_depth++;
					}
					if($value[0] == 1 && $value[1][2] === 1) {
						//print('here374859---0022<br />' . PHP_EOL);
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
					*			$all_tagvalues_satisfied = true;
					*			if($look_only_in_direct_children) {
					*				if(sizeof($tagvalue_array) === 1 || sizeof($potential_matches) === sizeof($tagvalue_array)) {
					*
			} else {
				$all_tagvalues_satisfied = false;
			}
			} else {
				if(sizeof($tagvalue_array) > 1) {
					O::fatal_error('code to handle more than one required tagvalue while not only looking in direct children  has not been written');
			}
			}*/
				//	$index2--;
			}
		}
		//print('$matches in match_by_tagname: ');var_dump($matches);
		return $matches;
	}

	function parse_selector_string($selector_string) {
		// Selector parsing is pure with respect to the selector text (it only
		// produces scope/piece sets). Cache those results to avoid re-splitting
		// the same selector repeatedly during perf_test.
		static $selector_parse_cache = array();
		$cache_key = $selector_string;
		// treat underscore the same as a directory separator or node level marker. this depends on underscore character not being used in tag names, so the parser should check for this
		//print('$selector_string at the start of parse_selector_string: ');var_dump($selector_string);
		//$selector_string = str_replace('\\', '_', $selector_string);
		//$selector_string = str_replace('/', '_', $selector_string);
		// doing context-specific selection frees up the . character from being used as a root indicator to being used to tell which tag we want according to its parents _and_ children
		/*
			*	$query = '//' . O::get_html_namespace() . implode('/text() | //' . O::get_html_namespace(), $this->config['fix_text_tags']) . '/text()';
			*	$query = './/' . O::get_html_namespace() . 'th | .//' . O::get_html_namespace() . 'td';
			*	$query = O::get_html_namespace() . 'tr[1]';
			*	$query = './/' . O::get_html_namespace() . 'th | .//' . O::get_html_namespace() . 'td[@newtag="th"]';
			*	$query = './/' . O::get_html_namespace() . 'tbody/tr';
			*	$query = './/@new_tbody';
			*	$query = './/' . O::get_html_namespace() . 'th | .//' . O::get_html_namespace() . '*[@newtag="th"]';
			*	$query = '//' . O::get_html_namespace() . 'div[@id="XXX9o9TOCdiv9o9XXX"]//' . O::get_html_namespace() . 'p';
			*	$query = '//' . O::get_html_namespace() . '*[@*=""]';
			*	$query = '//' . O::get_html_namespace() . 'a[@href="#footnote"][@name="note"][@title="Link to footnote "][@id="note"]';
			*	$query = '//*[@class]';
			*/

			$this->reached_selector_index = false;

			$this->tagnames = array();
			$this->tagvalues = array();
			$this->tagname_indices = array();
			$this->tagvalue_indices = array();
			$this->attributes_indices = array();
			$this->required_attribute_sets = array();

			$this->selected_parent_matches = false;
			$this->selected_parent_piece_index = false;
			$this->selector_scope_sets = array();
			$this->selector_piece_sets = array();
			$this->selector_piece_multi_and = array();
			//$this->fractal_depth_sets = array();
			//$selector_strings = explode('|', $selector_string);

			if(isset($selector_parse_cache[$cache_key])) {
				$cached = $selector_parse_cache[$cache_key];
				$this->selector_scope_sets = $cached[0];
				$this->selector_piece_sets = $cached[1];
				// selector_piece_multi_and will be re-derived by downstream parsing.
				return;
			}

			$selector_strings = array();
			$current_selector_string = '';
			$selector_string_length = strlen($selector_string);
			for($selector_offset = 0; $selector_offset < $selector_string_length; $selector_offset++) {
				if(
					$selector_string[$selector_offset] === '|'
					&& (!isset($selector_string[$selector_offset + 1]) || $selector_string[$selector_offset + 1] !== '=')
				) {
					$selector_strings[] = $current_selector_string;
					$current_selector_string = '';
					continue;
				}
				$current_selector_string .= $selector_string[$selector_offset];
			}
			$selector_strings[] = $current_selector_string;
			foreach($selector_strings as $selector_string) {
				$us_placeholder = '';
				if(strpos($selector_string, '#underscore#') !== false) {
					$us_placeholder = "\x1E";
					$selector_string = str_replace('#underscore#', $us_placeholder, $selector_string);
				}
				$depth = 0;
				if($selector_string !== '' && $selector_string[0] === '_') {
					$scopes = array('direct');
					//$fractal_depths = array(array('+1', '+1'));
					$offset = 1;
				} else {
					$scopes = array(false);
					//$fractal_depths = array(array('++', '++'));
					$offset = 0;
				}
				$pieces = array();
				$piece = '';
				while($offset < strlen($selector_string)) {
					if($selector_string[$offset] === '_') {
						// ___ could be unpredicatable but it's their own fault if someone uses that!
						if($selector_string[$offset + 1] === '_') {
							$scopes[] = false;
							//$fractal_depths[] = array('++', '++');
							$offset++;
						} else {
							$scopes[] = 'direct';
							//$fractal_depths[] = array('+1', '+1');
						}
						$pieces[] = $piece;
						$piece = '';
						$offset++;
						continue;
					}/* elseif($selector_string[$offset] == ' ' || $selector_string[$offset] == "\t" || $selector_string[$offset]  == "\n" || $selector_string[$offset]  == "\r") { // ignore spaces
					$offset++;
					continue;
				} elseif($selector_string[$offset] === '@') {

				}*/
				$piece .= $selector_string[$offset];
				$offset++;
				}
				if(strlen($piece) > 0) {
					$pieces[] = $piece;
				}
				if($us_placeholder !== '') {
					foreach($pieces as $pk => $pv) {
						$pieces[$pk] = str_replace($us_placeholder, '_', $pv);
					}
				}
				$this->selector_scope_sets[] = $scopes;
				$this->selector_piece_sets[] = $pieces;
				//$this->fractal_depth_sets[] = $fractal_depths;
			}
			// Cache the expensive scope/piece splitting work.
			$selector_parse_cache[$cache_key] = array($this->selector_scope_sets, $this->selector_piece_sets);
			//print('$this->selector_scope_sets, $this->selector_piece_sets at the end of parse_selector_string: ');var_dump($this->selector_scope_sets, $this->selector_piece_sets);
			//return $this->selector_piece_sets;
	}

	private function strinsert($code, $new_value, $offset = 0) { // alias
		return O::internal_insert($code, $new_value, $offset);
	}

	private function str_insert($code, $new_value, $offset = 0) { // alias
		return O::internal_insert($code, $new_value, $offset);
	}

	private function string_insert($code, $new_value, $offset = 0) {
		return O::internal_insert($code, $new_value, $offset);
	}

	private function interal_insert($code, $new_value, $offset = 0) {
		/*if($offset > strlen($code)) {
			*		$this->string_operation_made_a_change = false;
			*		return $code;
	}
	$this->string_operation_made_a_change = true;
	//$this->zero_offsets = array();
	return substr($code, 0, $offset) . $new_value . substr($code, $offset);*/
		return O::string_replace($code, '', $new_value, $offset);
	}

	private function strdelete($code, $delete_string, $offset = 0) { // alias
		return O::interal_delete($code, $delete_string, $offset);
	}

	private function str_delete($code, $delete_string, $offset = 0) { // alias
		return O::interal_delete($code, $delete_string, $offset);
	}

	private function string_delete($code, $delete_string, $offset = 0) { // alias
		return O::interal_delete($code, $delete_string, $offset);
	}

	private function interal_delete($code, $delete_string, $offset = 0) {
		/*if($offset > strlen($code)) {
			*		$this->string_operation_made_a_change = false;
			*		return $code;
	}
	$this->string_operation_made_a_change = true;
	//$this->zero_offsets = array();
	//print('$code, $delete_string, substr($code, 0, $offset) . substr($code, $offset + strlen($delete_string)): ');var_dump($code, $delete_string, substr($code, 0, $offset) . substr($code, $offset + strlen($delete_string)));
	return substr($code, 0, $offset) . substr($code, $offset + strlen($delete_string));*/
		return O::string_replace($code, $delete_string, '', $offset);
	}

	private function strreplace($code, $old_value, $new_value, $offset = 0) { // notice that it's different from PHP's built-in str_replace!
		return O::internal_replace($code, $old_value, $new_value, $offset);
	}

	/*private function str_replace($code, $old_value, $new_value, $offset = 0) { // PHP already has a str_replace built-in!
		*	return O::internal_replace($code, $old_value, $new_value, $offset);
}*/

	private function string_replace($code, $old_value, $new_value, $offset = 0) { // notice that it's different from PHP's built-in str_replace!
		return O::internal_replace($code, $old_value, $new_value, $offset);
	}

	private function internal_replace($code, $old_value, $new_value, $offset = 0) {
		//if($offset > strlen($code)) {
		//	$this->string_operation_made_a_change = false;
		//	return $code;
		//}
		//print('$code, $old_value, $new_value, $offset in internal_replace: ');var_dump($code, $old_value, $new_value, $offset);
		if($offset === strlen($code)) { // very specific exception... but it happens when adding new code to the very end (maybe not even needed!)
			$new_code = substr($code, 0, $offset) . $new_value;
		} else {
			$new_code = substr($code, 0, $offset) . $new_value . substr($code, $offset + strlen($old_value));
		}
		$this->offsets_need_adjusting = true;
		if($code === $new_code) {
			$this->string_operation_made_a_change = false;
			$this->offsets_need_adjusting = false;
		} else {
			$this->string_operation_made_a_change = true;
			if(strlen($old_value) === strlen($new_value)) {
				$this->offsets_need_adjusting = false;
			}
		}
		//$this->zero_offsets = array();
		//print('$code, $old_value, $new_value, substr($code, 0, $offset) . $new_value . substr($code, $offset + strlen($old_value)): ');var_dump($code, $old_value, $new_value, substr($code, 0, $offset) . $new_value . substr($code, $offset + strlen($old_value)));
		return $new_code;
	}

	function replace($old_value, $new_value, $offset = 0, $included_array = false) {
		if(is_numeric($included_array)) {
			print('$selector, $new_value, $included_array, $included_array_only in replace: ');var_dump($selector, $new_value, $included_array, $included_array_only);
			O::fatal_error('assuming included_array is an offset in set but this is not coded yet');
		} elseif(is_string($included_array) && strpos(O::query_decode($included_array), '<') !== false) {
			$included_array = array(array($included_array, strpos($this->code, $included_array)));
		} elseif(is_string($included_array)) { // assume it's a selector
			$included_array = O::get_tagged($included_array);
			//print('$selector, $new_value, $included_array in set with string $included_array: ');var_dump($selector, $new_value, $included_array);
		} elseif($included_array === NULL) {
			$included_array = false;
		}
		$old_len = strlen($old_value);
		$new_len = strlen($new_value);
		$span_len = ($old_len > $new_len) ? $old_len : $new_len;
		$offset_adjust = $new_len - $old_len;
		$had_parent_indexes = $this->parent_indexes_ready;
		$replace_span_hits_opening_tag = null;
		$taint_attribute_indexes = ($offset_adjust !== 0);
		if(!$taint_attribute_indexes) {
			$attr_span_len = $span_len;
			if($had_parent_indexes) {
				$replace_span_hits_opening_tag = $this->replace_span_intersects_any_opening_tag($offset, $attr_span_len);
				$taint_attribute_indexes = $replace_span_hits_opening_tag;
			} else {
				$taint_attribute_indexes = O::is_in_opening_tag($offset);
			}
		}
		//print('$old_value, $new_value, $offset, $offset_adjust in is_numeric($selector) in replace: ');O::var_dump_full($old_value, $new_value, $offset, $offset_adjust);
		//print('$selector, $offset, $offset_adjust, substr($this->code, $offset - 10, 20) in is_numeric($selector) in replace: ');O::var_dump_full($selector, $offset, $offset_adjust, substr($this->code, $offset - 10, 20));
		//print('$this->LOM in is_numeric($selector) in set: ');O::var_dump_full($this->LOM);
		$t_sr = $this->profile_enabled ? O::getmicrotime() : false;
		$this->code = O::string_replace($this->code, $old_value, $new_value, $offset);
		if($t_sr !== false) {
			$this->profile_add_time('replace_string_replace', O::getmicrotime() - $t_sr);
		}
		//print('before adjust_offsets in set<br />' . PHP_EOL);
		//print('$old_value, $new_value, $this->string_operation_made_a_change, $this->offsets_need_adjusting: ');var_dump($old_value, $new_value, $this->string_operation_made_a_change, $this->offsets_need_adjusting);
		if($this->string_operation_made_a_change) {
			$parent_offset_at_edit = false;
			//if($offset_adjust != 0) {
			// again, not clever enough, but it should be safe
			// if we're playing with something at 0 depth, don't trust the expands since we don't have clever culling code
			$edit_is_root_level = false;
			if($this->parent_indexes_ready) {
				$parent_offset_at_edit = O::get_parent_offset_fast($offset);
				$edit_is_root_level = ($parent_offset_at_edit === false);
			} else {
				$edit_is_root_level = (O::depth($offset) <= 0);
			}
			if($edit_is_root_level) {
				$this->expands = array(); // just reset it
			}
			//O::adjust_offsets($offset, $offset_adjust);
			// could do fancy detection of which expands need to be changed and do that, since we have the code to change offset_depths and things already written, but for now, just unset them and allow them to be
			// recalculated... this could be a marginal efficiency upgrade for something like version 0.9
			if($this->offsets_need_adjusting) {
				$included_array = O::adjust_offsets($offset + 1, $offset_adjust, $included_array); // + 1 because only subsequent offsets are affected
				foreach($this->expands as $expand_offset => $expanded_LOM) {
					//print('$offset, $expand_offset, $expanded_LOM[0][0]: ');var_dump($offset, $expand_offset, $expanded_LOM[0][0]);
					if($expand_offset >= $offset || ($offset >= $expand_offset && $offset <= $expand_offset + strlen($expanded_LOM[0][0]))) {
						//print('unset an expand 1<br />' . PHP_EOL);
						unset($this->expands[$expand_offset]);
					}
				}
			} else {
				foreach($this->expands as $expand_offset => $expanded_LOM) {
					//print('$offset, $expand_offset, $expanded_LOM[0][0]: ');var_dump($offset, $expand_offset, $expanded_LOM[0][0]);
					if($offset >= $expand_offset && $offset <= $expand_offset + strlen($expanded_LOM[0][0])) {
						//print('unset an expand 2<br />' . PHP_EOL);
						unset($this->expands[$expand_offset]);
					}
				}
			}
			//print('$this->code, $this->string_operation_made_a_change in is_numeric($selector) in set: ');O::var_dump_full($this->code, $this->string_operation_made_a_change);
			/*$this->LOM[$selector][1] = $new_value;
				*			foreach($this->LOM as $LOM_index => $LOM_value) {
				*				if($LOM_value[2] >= $offset) {
				*					$this->LOM[$LOM_index][2] += $offset_adjust;
		}
		}*/

			// delete then insert
			//if($expanded_LOM[2] === -1) { // round-about way of saying that we are adding to the end of the code
			//	$depth_of_offset = 0;
			//} else {
			//$depth_of_offset = O::depth($offset + $offset_adjust);
			$old_has_less = (is_string($old_value) && strpos($old_value, '<') !== false);
			$new_has_less = (is_string($new_value) && strpos($new_value, '<') !== false);
			$skip_replace_offsets_and_depths = (
				$offset_adjust === 0 &&
				!$old_has_less &&
				!$new_has_less
			);
			if($skip_replace_offsets_and_depths) {
				if($this->profile_enabled) {
					$this->profile_add_time('replace_offsets_and_depths_skipped_text', 0.0);
				}
			} else {
				$t_rad = $this->profile_enabled ? O::getmicrotime() : false;
				$depth_of_offset = 0;
				if($this->parent_indexes_ready) {
					if($parent_offset_at_edit !== false && isset($this->offset_depths[$parent_offset_at_edit])) {
						$depth_of_offset = $this->offset_depths[$parent_offset_at_edit] + 1;
					} else {
						$depth_of_offset = O::depth($offset);
					}
				} else {
					$depth_of_offset = O::depth($offset);
				}
				if(!$old_has_less && !$new_has_less) {
					O::replace_offsets_and_depths_text_only($offset, strlen($old_value), strlen($new_value), $depth_of_offset);
				} else {
					O::replace_offsets_and_depths($old_value, $new_value, $offset, $offset_adjust, $depth_of_offset);
				}
				if($t_rad !== false) {
					$this->profile_add_time('replace_offsets_and_depths', O::getmicrotime() - $t_rad);
				}
			}
			//O::replace_offsets_and_depths($offset, $offset_adjust);
			$t_attr = $this->profile_enabled ? O::getmicrotime() : false;
			$shifted_attribute_indexes = false;
			if(
				$taint_attribute_indexes &&
				$offset_adjust !== 0 &&
				!$old_has_less &&
				!$new_has_less &&
				$had_parent_indexes &&
				(
					($replace_span_hits_opening_tag === null && !($replace_span_hits_opening_tag = $this->replace_span_intersects_any_opening_tag($offset, $span_len))) ||
					($replace_span_hits_opening_tag === false)
				)
			) {
				$shifted_attribute_indexes = $this->shift_attribute_indexes_suffix_offsets($offset + $old_len, $offset_adjust);
			}
			if($taint_attribute_indexes && !$shifted_attribute_indexes) {
				$this->attribute_index = array();
				$this->attribute_value_index = array();
				$this->attribute_index_ready = array();
				$this->attribute_full_index_ready = false;
			}
			if($t_attr !== false && $taint_attribute_indexes) {
				if($shifted_attribute_indexes) {
					$this->profile_add_time('replace_attribute_index_shift_hit', O::getmicrotime() - $t_attr);
				} else {
					$this->profile_add_time('replace_attribute_index_shift_fallback', O::getmicrotime() - $t_attr);
				}
			}
			if($had_parent_indexes) {
				$t_patch = $this->profile_enabled ? O::getmicrotime() : false;
				$skip_patch_parent_indexes = (
					$offset_adjust === 0 &&
					!$old_has_less &&
					!$new_has_less
				);
				if($skip_patch_parent_indexes) {
					$this->parent_result_cache = array();
					$this->parent_query_cache = array();
					if($t_patch !== false) {
						$this->profile_add_time('replace_patch_parent_indexes_skipped_text', O::getmicrotime() - $t_patch);
					}
				} elseif(!O::patch_parent_indexes_from_edit($offset, $old_len, $new_len, $old_has_less, $new_has_less)) {
					if($t_patch !== false) {
						$this->profile_add_time('replace_patch_parent_indexes_fail', O::getmicrotime() - $t_patch);
					}
					O::clear_parent_structural_indexes();
				} elseif($t_patch !== false) {
					$this->profile_add_time('replace_patch_parent_indexes', O::getmicrotime() - $t_patch);
				}
			} else {
				$this->parent_indexes_ready = false;
				if($this->profile_enabled) {
					$this->profile_add_time('replace_patch_parent_indexes_unavailable', 0.0);
				}
			}

			/*$replacing_an_attribute = false;
				*			if(O::is_in_an_opening_tag($offset)) { // the replace is in an opening tag
				*				preg_match('/\s+(\w+)="([^"]{0,})"/is', $new_value, $attribute_matches, PREG_OFFSET_CAPTURE);
				*				if(($attribute_matches[0][0]) === strlen($new_value)) { // it's an attribute; then we know it's not simply text talking about XML attributes!
				*					$replacing_an_attribute = true;
				*					$new_value_attribute_name = $attribute_matches[1][0];
				*					$new_value_attribute_value = $attribute_matches[2][0];
				*					preg_match('/\s+(\w+)="([^"]{0,})"/is', $old_value, $attribute_matches, PREG_OFFSET_CAPTURE);
				*					$old_value_attribute_name = $attribute_matches[1][0];
				*					//$old_value_attribute_value = $attribute_matches[2][0];
		}
		}*/

			//print('$this->context (using context) in is_numeric($selector) in set: ');O::var_dump_full($this->context);
			/*foreach($this->context as $context_index => $context_value) {
				*				if(is_array($context_value[3])) {
				*					foreach($context_value[3] as $index2 => $value2) {
				*						if(isset($value2[$selector])) {
				*							$this->context[$context_index][3][$index2][$selector][1] = $new_value;
		}
		}
		} else {
			if($this->context[$context_index][2] === $selector) {
				$this->context[$context_index][3] = $new_value;
		}
		}
		}*/
			//	foreach($this->context as $context_index => $context_value) {
			//		if($context_value[3] === false) { // false here means use $this->offset_depths
			//			continue;
			//		}
			//print('$context_value[1]: ');var_dump($context_value[1]);
			//print('$offset, $offset_adjust, $this->context[$context_index] before offset adjusting: ');var_dump($offset, $offset_adjust, $this->context[$context_index]);
			/*if($offset >= $context_value[1] && $offset <= $context_value[1] + strlen($context_value[0])) {
				*					//if($context_value[1] === $offset) {
				*					//print('here4569710<br />' . PHP_EOL);
				*					//print('$old_value, $new_value: ');var_dump($old_value, $new_value);
				*					$context_value[0] = O::string_replace($context_value[0], $old_value, $new_value, $offset - $context_value[1]);
		}*/

			//		if(is_array($context_value[1])) { // it may be false, meaning the whole code
			//			foreach($context_value[1] as $index => $value) {
			//				if($offset >= $value[1] && $offset <= $value[1] + strlen($value[0])) {
			//					$context_value[1][$index][0] = O::string_replace($context_value[1][$index][0], $old_value, $new_value, $offset - $value[1]);
			//				}
			//				// context does not use string-offset pairs; that is LOM. context uses offset-strlen pairs, in other words: markers
			//			//	if($offset >= $value[0] && $offset <= $value[0] + $value[1]) { // change the strlen in this parent context entry
			//			//		$this->context[$context_index][1][$index][1] += $offset_adjust;
			//			//	}
			//			//	if($offset <= $value[0]) { // change the offset in this parent context entry
			//			//		$this->context[$context_index][1][$index][0] += $offset_adjust;
			//			//	}
			//			}
			//		}
			//	foreach($context_value[2] as $index => $value) {
			//		if($offset >= $value[1] && $offset <= $value[1] + strlen($value[0])) {
			//			$context_value[2][$index][0] = O::string_replace($context_value[2][$index][0], $old_value, $new_value, $offset - $value[1]);
			//			$this->context[$context_index][2][$index][1] += $offset_adjust;
			//		}
			//		if($offset >= $value[0] && $offset <= $value[0] + $value[1]) { // change the strlen in this matches context entry
			//			$this->context[$context_index][2][$index][1] += $offset_adjust;
			//		}
			//		if($offset <= $value[0]) { // change the offset in this matches context entry
			//			$this->context[$context_index][2][$index][0] += $offset_adjust;
			//		}
			//	}
			/*if($replacing_an_attribute) {
				*					print('$context_value[2] before replacing attribute: ');var_dump($context_value[2]);
				*					foreach($context_value[2] as $context2_index => $context2_value) {
				*						unset($this->context[$context_index][2][$context2_index][1][1][$old_value_attribute_name]);
				*						$this->context[$context_index][2][$context2_index][1][1][$new_value_attribute_name] = $new_value_attribute_value;
		}
		print('$context_value[2] after replacing attribute: ');var_dump($context_value[2]);
		exit(0);
		}*/
			/*if($offset_adjust != 0) {
				*					if($context_value[1] !== false) {
				*						foreach($context_value[1] as $context1_index => $context1_value) {
				*							if($context1_value[0] <= $offset && $context1_value[0] + $context1_value[1] > $offset) {
				*								$this->context[$context_index][1][$context1_index][1] += $offset_adjust;
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
		}*/
			//print('$this->context[$context_index] after offset adjusting: ');var_dump($this->context[$context_index]);
			//		}
			//	}
			//print('$this->context after offset_adjust in set: ');O::var_dump_full($this->context);
			/*if($parent_node !== false) {
				*			//$parent_node[$selector][1] = $new_value;
				*			if(O::all_sub_entries_are_arrays($parent_node)) {
				*				foreach($parent_node as $index => $value) {
				*					$parent_node[$index][$selector][1] = $new_value;
		}
		} else {
			$parent_node[$selector][1] = $new_value;
		}
		}*/
			//print('here4569702<br />' . PHP_EOL);

			/*foreach($this->variables as $variable_index => $variable_value) {
				*			if(is_array($this->variables[$variable_index])) {
				*				if(is_array($this->variables[$variable_index][0])) {
				*					foreach($this->variables[$variable_index] as $index => $value) {
				*						if($this->variables[$variable_index][$index][1] >= $offset) {
				*							$this->variables[$variable_index][$index][1] += $offset_adjust;
		}
		}
		} else {
			if($this->variables[$variable_index][1] >= $offset) {
				$this->variables[$variable_index][1] += $offset_adjust;
		}
		}
		}
		}*/

			foreach($this->variables as $variable_index => $variable_value) {
				//if(is_array($this->variables[$variable_index])) {
				//	if(is_array($this->variables[$variable_index][0])) {
				foreach($variable_value as $index => $value) {
					if($offset >= $value[1] && $offset <= $value[1] + strlen($value[0])) {
						$this->variables[$variable_index][$index][0] = O::string_replace($this->variables[$variable_index][$index][0], $old_value, $new_value, $offset - $value[1]);
					}
					//	if($offset_adjust != 0) {
					//		if($this->variables[$variable_index][$index][1] >= $offset) {
					//			$this->variables[$variable_index][$index][1] += $offset_adjust;
					//		}
					//	}
				}
				//	} else {
				//		if($offset >= $this->variables[$variable_index][1] && $offset <= $this->variables[$variable_index][1] + strlen($this->variables[$variable_index][0])) {
				//			$this->variables[$variable_index][0] = O::string_replace($this->variables[$variable_index][0], $old_value, $new_value, $offset - $this->variables[$variable_index][1]);
				//		}
				//		if($offset_adjust != 0) {
				//			if($this->variables[$variable_index][1] >= $offset) {
				//				$this->variables[$variable_index][1] += $offset_adjust;
				//			}
				//		}
				//	}
				//} else {
				//	$this->variables[$variable_index] = $new_value;
				//}
			}

			if(is_numeric($included_array)) {
				print('$included_array: ');var_dump($included_array);
				O::fatal_error('is_numeric($included_array) in replace() is not handled');
			} elseif(is_string($included_array)) {
				print('$included_array: ');var_dump($included_array);
				O::fatal_error('is_string($included_array) in replace() is not handled');
			} elseif(is_array($included_array)) {
				//print('here4569703<br />' . PHP_EOL);
				if(is_array($included_array[0])) {
					//print('here4569704<br />' . PHP_EOL);
					foreach($included_array as $index => $value) {
						//print('here4569705<br />' . PHP_EOL);
						if($offset >= $value[1] && $offset <= $value[1] + strlen($value[0])) {
							//if($value[1] === $offset) {
							//print('here4569706<br />' . PHP_EOL);
							//print('$included_array[$index][0], $old_value, $new_value, $offset - $value[1] before replace: ');var_dump($included_array[$index][0], $old_value, $new_value, $offset - $value[1]);
							$included_array[$index][0] = O::string_replace($included_array[$index][0], $old_value, $new_value, $offset - $value[1]);
							//print('$included_array[$index][0] after replace: ');var_dump($included_array[$index][0]);
						}
						//	if($offset_adjust != 0) {
						//		//print('here4569707<br />' . PHP_EOL);
						//		if($included_array[$index][1] >= $offset) {
						//			//print('here4569708<br />' . PHP_EOL);
						//			$included_array[$index][1] += $offset_adjust;
						//		}
						//	}
					}
				} else {
					//print('here4569709<br />' . PHP_EOL);
					//foreach($included_array as $index => $value) {
					//	$included_array[$index] = $new_value;
					//}
					if($offset >= $included_array[1] && $offset <= $included_array[1] + strlen($included_array[0])) {
						//if($included_array[1] === $offset) {
						//print('here4569710<br />' . PHP_EOL);
						//print('$old_value, $new_value: ');var_dump($old_value, $new_value);
						$included_array[0] = O::string_replace($included_array[0], $old_value, $new_value, $offset - $included_array[1]);
					}
					//	if($offset_adjust != 0) {
					//		if($included_array[1] >= $offset) {
					//			//print('here4569711<br />' . PHP_EOL);
					//			$included_array[1] += $offset_adjust;
					//		}
					//	}
				}
			} else {
				//print('here4569712<br />' . PHP_EOL);
				//$included_array = O::string_replace('', $new_value, $offset, $included_array);
				$included_array = true;
			}
		}
		return $included_array;
	}

	function __($selector, $new_value = 0, $parent_node = false) { // alias
		$profile_token = $this->profile_function_start('__');
		try {
			return O::set($selector, $new_value, $parent_node);
		} finally {
			$this->profile_function_stop('__', $profile_token);
		}
	}

	function s($selector, $new_value = 0, $parent_node = false) { // alias
		return O::set($selector, $new_value, $parent_node);
	}

	function s_($selector, $new_value = 0, $parent_node = false) { // alias
		return O::set($selector, $new_value, $parent_node);
	}

	function set($selector, $new_value = false, $parent_node = false, $parent_node_only = false) {
		//print('$selector, $new_value, $parent_node before swapping in set: ');var_dump($selector, $new_value, $parent_node);
		if(is_string($selector) && !is_string($new_value) && is_string($parent_node)) {
			$temp_new_value = $new_value;
			$new_value = $parent_node;
			$parent_node = $temp_new_value;
		}
		if($this->native_doc && is_string($selector) && is_string($new_value) && $parent_node === false && O::native_selector_supported($selector) && function_exists('lom_doc_set_inner_text')) {
			if(lom_doc_set_inner_text($this->native_doc, $selector, $new_value)) {
				O::native_sync_from_doc();
				return true;
			}
		}
		if(is_array($new_value) && is_array($new_value[0]) && !is_array($selector)) { // swap them
			$temp_selector = $selector;
			$selector = $new_value;
			$new_value = $temp_selector;
		}
		// print('$selector, $new_value before swapping in set: ');var_dump($selector, $new_value);
		// if(is_string($selector) && !O::valid_tagname($selector) && is_string($new_value) && O::valid_tagname($new_value)) { // swap them
		// 	$temp_selector = $selector;
		// 	$selector = $new_value;
		// 	$new_value = $temp_selector;
		// }
		// this function assumes the selector only chooses a single entry rather than a range, otherwise array_slice_replace would have to be used. not an unassailable position
		//print('$selector, $new_value, $parent_node in set: ');var_dump($selector, $new_value, $parent_node);
		if(is_array($parent_node) && is_array($parent_node[0]) && sizeof($parent_node) > 1) {
			if(!is_array($new_value)) {
				print('$selector, $new_value, $parent_node in set: ');var_dump($selector, $new_value, $parent_node);
				O::fatal_error('$parent_node is multiple but $new_value is not, so how to proceed is unwritten');
			} elseif(sizeof($new_value) !== sizeof($parent_node)) {
				print('$selector, $new_value, $parent_node in set: ');var_dump($selector, $new_value, $parent_node);
				O::fatal_error('$parent_node and $new_value are both multiple but have different sizes, so how to proceed is unwritten');
			} else {
				print('$parent_node before in set with multiple: ');var_dump($parent_node);exit(0);
				$old_value = O::get($selector, $parent_node, false, false, true);
				print('$old_value: ');var_dump($old_value);exit(0);
				$counter = sizeof($parent_node) - 1;
				while($counter > -1) { // very important to go in reverse order since offsets may change?
					print('$counter, $parent_node[$counter] single before: ');var_dump($counter, $parent_node[$counter]);
					$parent_node[$counter] = O::set($selector, $new_value[$counter], $parent_node[$counter], $parent_node_only);
					//print('$counter, $parent_node[$counter], $new_value[$counter], $old_value[$counter] single after: ');var_dump($counter, $parent_node[$counter], $new_value[$counter], $old_value[$counter]);
					// must do inline offset adjustment since parent_node is multiple
					$offset_adjust = strlen($new_value[$counter]) - strlen($old_value[$counter]);
					//print('$offset_adjust in set with multiple: ');var_dump($offset_adjust);
					if($offset_adjust != 0) {
						$counter2 = $counter + 1;
						while($counter2 < sizeof($parent_node)) {
							//print('$counter2 in set with multiple: ');var_dump($counter2);
							$parent_node[$counter2][1] += $offset_adjust;
							$counter2++;
							//break; // debug
						}
					}
					//break; // debug
					$counter--;
				}
				print('$parent_node after in set with multiple: ');var_dump($parent_node);
				return $parent_node;
			}
		} elseif(is_numeric($parent_node)) {
			print('$selector, $new_value, $parent_node, $parent_node_only in set: ');var_dump($selector, $new_value, $parent_node, $parent_node_only);
			O::fatal_error('assuming parent_node is an offset in set but this is not coded yet');
		} elseif(is_string($parent_node) && strpos(O::query_decode($parent_node), '<') !== false) {
			//print('is_string($parent_node) and has at least one tag in set<br />' . PHP_EOL);
			$parent_node = array(array($parent_node, strpos($this->code, $parent_node)));
		} elseif(is_string($parent_node)) { // assume it's a selector
			//print('is_string($parent_node) in set<br />' . PHP_EOL);
			$parent_node = O::get_tagged($parent_node);
			//print('$selector, $new_value, $parent_node in set with string $parent_node: ');var_dump($selector, $new_value, $parent_node);
		} elseif($parent_node === NULL) {
			//print('$parent_node === NULL in set<br />' . PHP_EOL);
			$parent_node = false;
		}
		//print('$parent_node mid set: ');var_dump($parent_node);
		//print('$selector, $new_value, $parent_node mid set: ');var_dump($selector, $new_value, $parent_node);
		$new_value = (string)$new_value;
		O::check_tag_types($new_value);
		//if($new_value === false || $new_value === NULL || strlen($new_value) === 0) {
		if($new_value === false || $new_value === NULL) {
			$parent_node = O::delete($selector, $parent_node);
		} elseif(is_numeric($selector)) { // treat it as an offset
			$selector = (int)$selector;
			//if($this->code[$selector] === '<') {
			//	$offset = strpos($this->code, '>', $selector) + 1;
			//} else {
			//	$offset = $selector;
			//}
			//print('expanding in set()<br />' . PHP_EOL);
			//$expanded_LOM = O::expand($this->code, $selector, false, false, 'lazy');
			$expanded_LOM = O::expand($this->code, $selector, false);
			//print('$expanded_LOM in set: ');var_dump($expanded_LOM);
			$full_string = $expanded_LOM[0][0];
			if($full_string[0] === '<') {
				if($full_string[1] === '/') { // closing tag
					if($this->debug) {
						print('$full_string: ');var_dump($full_string);
						O::fatal_error('not sure what to do when $full_string starts with a closing tag in set()');
					}
				} elseif($this->must_check_for_self_closing && $full_string[strpos($full_string, '>', 1) - 1] === '/') { // self-closing tag
					//print('self-closing tag at position: ' . $position . '<br />' . PHP_EOL);
					$old_value = $expanded_LOM[0][0];
					$offset = $expanded_LOM[0][1];
				} elseif($this->must_check_for_doctype && (substr($full_string, 1, 8) === '!DOCTYPE' || substr($full_string, 1, 8) === '!doctype')) { // doctype
					//print('doctype at position: ' . $position . '<br />' . PHP_EOL);
					$old_value = $expanded_LOM[0][0];
					$offset = $expanded_LOM[0][1];
				} elseif($this->must_check_for_non_parsed_character_data && substr($full_string, 1, 8) === '![CDATA[') { // non-parsed character data
					//print('non-parsed character data at position: ' . $position . '<br />' . PHP_EOL);
					$old_value = $expanded_LOM[0][0];
					$offset = $expanded_LOM[0][1];
				} elseif($this->must_check_for_comment && substr($full_string, 1, 3) === '!--') { // comment
					//print('comment at position: ' . $position . '<br />' . PHP_EOL);
					$old_value = $expanded_LOM[0][0];
					$offset = $expanded_LOM[0][1];
				} elseif($this->must_check_for_programming_instruction && $full_string[1] === '?') { // programming instruction
					//print('programming instruction at position: ' . $position . '<br />' . PHP_EOL);
					$old_value = $expanded_LOM[0][0];
					$offset = $expanded_LOM[0][1];
				} elseif($this->must_check_for_ASP && $full_string[1] === '%') { // ASP
					//print('ASP at position: ' . $position . '<br />' . PHP_EOL);
					$old_value = $expanded_LOM[0][0];
					$offset = $expanded_LOM[0][1];
				} else { // opening tag
					//	//print('opening tag at position: ' . $position . '<br />' . PHP_EOL);
					//	$this->offset_depths[$position] = $depth;
					//	$depth++;
					$old_value = $expanded_LOM[1][0];
					$offset = $expanded_LOM[1][1];
				}
			}
			//print('$this->context before replace in set(): ');O::var_dump_full($this->context);
			//print('$old_value, $new_value, $offset, $parent_node in set: ');var_dump($old_value, $new_value, $offset, $parent_node);
			$parent_node = O::replace($old_value, $new_value, $offset, $parent_node);
			//print('$this->context after replace in set(): ');O::var_dump_full($this->context);
			// might have to add to offset_depths like new_?
			/*
				*		$text_node_offset = $expanded_LOM[1][1];
				*		//if($this->LOM[$selector][0] === 0) { // then it is text
				*		//	$offset = O::LOM_index_to_offset($selector);
				*		//	$old_value = $this->LOM[$selector][1];
				*			$offset_adjust = strlen($new_value) - strlen($old_value);
				*			//print('$offset, $old_value, $new_value, $offset_adjust in is_numeric($selector) in set: ');O::var_dump_full($offset, $old_value, $new_value, $offset_adjust);
				*			//print('$selector, $offset, $offset_adjust, substr($this->code, $offset - 10, 20) in is_numeric($selector) in set: ');O::var_dump_full($selector, $offset, $offset_adjust, substr($this->code, $offset - 10, 20));
				*			//print('$this->LOM in is_numeric($selector) in set: ');O::var_dump_full($this->LOM);
				*			if(!$parent_node_only) {
				*				$this->code = O::replace($this->code, $old_value, $new_value, $offset);
				*				//print('before adjust_offsets in set<br />' . PHP_EOL);
				*				O::adjust_offsets($offset, $offset_adjust);
				*				//print('$this->code, $this->string_operation_made_a_change in is_numeric($selector) in set: ');O::var_dump_full($this->code, $this->string_operation_made_a_change);
				*				if($this->string_operation_made_a_change) {
				*					//$this->LOM[$selector][1] = $new_value;
				*					//foreach($this->LOM as $LOM_index => $LOM_value) {
				*					//	if($LOM_value[2] >= $offset) {
				*					//		$this->LOM[$LOM_index][2] += $offset_adjust;
				*					//	}
				*					//}
				*					if($this->use_context) {
				*						//print('$this->context (using context) in is_numeric($selector) in set: ');O::var_dump_full($this->context);
				*						//foreach($this->context as $context_index => $context_value) {
				*						//	if(is_array($context_value[3])) {
				*						//		foreach($context_value[3] as $index2 => $value2) {
				*						//			if(isset($value2[$selector])) {
				*						//				$this->context[$context_index][3][$index2][$selector][1] = $new_value;
				*						//			}
				*						//		}
				*						//	} else {
				*						//		if($this->context[$context_index][2] === $selector) {
				*						//			$this->context[$context_index][3] = $new_value;
				*						//		}
				*						//	}
				*						//}
				*						foreach($this->context as $context_index => $context_value) {
				*							//print('$context_value[1]: ');var_dump($context_value[1]);
				*							if($context_value[1] !== false) {
				*								foreach($context_value[1] as $context1_index => $context1_value) {
				*									if($context1_value[0] <= $offset && $context1_value[0] + $context1_value[1] > $offset) {
				*										$this->context[$context_index][1][$context1_index][1] += $offset_adjust;
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
		//if($parent_node !== false) {
		//	//$parent_node[$selector][1] = $new_value;
		//	if(O::all_sub_entries_are_arrays($parent_node)) {
		//		foreach($parent_node as $index => $value) {
		//			$parent_node[$index][$selector][1] = $new_value;
		//		}
		//	} else {
		//		$parent_node[$selector][1] = $new_value;
		//	}
		//}
		//print('here4569702<br />' . PHP_EOL);

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
			//print('here4569703<br />' . PHP_EOL);
			if(is_array($parent_node[0])) {
				//print('here4569704<br />' . PHP_EOL);
				foreach($parent_node as $index => $value) {
					//print('here4569705<br />' . PHP_EOL);
					if($offset >= $value[1] && $offset <= $value[1] + strlen($value[0])) {
						//if($value[1] === $offset) {
						//print('here4569706<br />' . PHP_EOL);
						//print('$parent_node[$index][0], $old_value, $new_value, $offset - $value[1] before replace: ');var_dump($parent_node[$index][0], $old_value, $new_value, $offset - $value[1]);
						$parent_node[$index][0] = O::replace($parent_node[$index][0], $old_value, $new_value, $offset - $value[1]);
						//print('$parent_node[$index][0] after replace: ');var_dump($parent_node[$index][0]);
		}
		//print('here4569707<br />' . PHP_EOL);
		if($parent_node[$index][1] >= $offset) {
			//print('here4569708<br />' . PHP_EOL);
			$parent_node[$index][1] += $offset_adjust;
		}
		}
		} else {
			//print('here4569709<br />' . PHP_EOL);
			//foreach($parent_node as $index => $value) {
			//	$parent_node[$index] = $new_value;
			//}
			if($offset >= $parent_node[1] && $offset <= $parent_node[1] + strlen($parent_node[0])) {
				//if($parent_node[1] === $offset) {
				//print('here4569710<br />' . PHP_EOL);
				//print('$old_value, $new_value: ');var_dump($old_value, $new_value);
				$parent_node[0] = O::replace($parent_node[0], $old_value, $new_value, $offset - $parent_node[1]);
		}
		if($parent_node[1] >= $offset) {
			//print('here4569711<br />' . PHP_EOL);
			$parent_node[1] += $offset_adjust;
		}
		}
		} else {
			//print('here4569712<br />' . PHP_EOL);
			$parent_node = $new_value;
		}
		*/
			/*} else { // what should be changed about a tag?
				*			print('$selector, $new_value, $this->code, $this->LOM, $this->context: ');O::var_dump_full($selector, $new_value, $this->code, $this->LOM, $this->context);
				*			O::fatal_error('what to set of a tag when a LOM index is provided has not been figured out');
		}*/
		} elseif(is_string($selector)) {
			//print('$selector, $parent_node, $this->context in set: ');var_dump($selector, $parent_node, $this->context);
		 //print('$selector, $new_value in set: ');var_dump($selector, $new_value);
			//$selector_matches = O::get_LOM_indices($selector, $parent_node, false, false, $parent_node_only);
			//print('$selector_matches in set: ');var_dump($selector_matches);
			//print('$this->LOM before in set');O::var_dump_full($this->LOM);
			$parent_node_was_false = ($parent_node === false);
			if($parent_node === false && $this->use_context && sizeof($this->context) > 0) {
				$last_ctx = $this->context[sizeof($this->context) - 1];
				if(isset($last_ctx[1]) && $last_ctx[1] !== false && is_array($last_ctx[1]) && sizeof($last_ctx[1]) > 0) {
					$parent_node = O::LOM_array($last_ctx[1]);
				}
			}
			$selector_matches = O::get_tagged($selector, $parent_node, false, false, $parent_node_only);
			//print('$selector_matches in is_string($selector) in set(): ');var_dump($selector_matches);
			//foreach($selector_matches as $index => $value) {
			$index = sizeof($selector_matches) - 1; // have to go in reverse order
			while($index > -1) {
				$parent_node = O::set($selector_matches[$index][1], $new_value, $parent_node, $parent_node_only);
				/*if($this->LOM[$result][0] === 0) { // then it is text
					*				//print('it is text<br />' . PHP_EOL);
					*				$parent_node = O::set($result, $new_value, $parent_node, $parent_node_only);
			} else {
				//print('it is not text<br />' . PHP_EOL);
				$parent_node = O::set($result + 1, $new_value, $parent_node, $parent_node_only); // + 1 since get_LOM_indices is returning the opening tags
			}*/
				$index--;
			}
			// Re-resolve the written tag under the (possibly updated) parent scope so contextual get() matches the edit — including when $parent_node was explicit (e.g. set('hobby', $x, $person)), not only when it was taken from context.
			if($parent_node !== false && is_array($parent_node) && sizeof($selector_matches) > 0) {
				O::get_tagged($selector, $parent_node, true, false, $parent_node_only);
			}
			return $parent_node_was_false ? true : $parent_node;
		} elseif(is_array($selector)) {
			//O::fatal_error('array selector not handled in set function');
			$selector_index = sizeof($selector) - 1;
			while($selector_index > -1) {
				$text_offset = $selector[$selector_index][1] + strpos($selector[$selector_index][0], '>') + 1;
				$parent_node = O::set($text_offset, $new_value, $parent_node, $parent_node_only);
				$selector_index--;
			}
		} else {
			print('$selector: ');var_dump($selector);
			O::fatal_error('Unknown selector type in set');
		}
		// `replace()` already patches offsets/depths and parent indexes when possible.
		// Avoid clearing structural parent indexes unconditionally here (it forces a costly rebuild on the next query).
		O::invalidate_runtime_caches_only();
		//print('$parent_node at the end of set: ');var_dump($parent_node);
		//print('$this->context at the end of set: ');O::var_dump_full($this->context);
		/*if(is_array($parent_node) && sizeof($parent_node) === 1) {
			*		foreach($parent_node as $parent_node_first_index => $parent_node_first_value) {  }
			*		if(!is_array($parent_node_first_value)) {
			*			$parent_node = $parent_node[$parent_node_first_index];
	}
	}*/
		//print('$this->context overview: ');O::var_dump_full($this->context );
		return $parent_node;
	}

	function array_slice_replace($array, $replace_array, $start_index = false, $end_index = false) {
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

	function data_and_type_unique($array) { // alias
		return O::super_unique($array);
	}

	function data_unique($array) { // alias
		return O::super_unique($array);
	}

	function super_unique($array, $recursive = false) {
		$result = array_map('unserialize', array_unique(array_map('serialize', $array)));
		if($recursive) {
			foreach($result as $key => $value) {
				if(is_array($value)) {
					$result[$key] = O::super_unique($value, $recursive);
				}
			}
		}
		return $result;
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
			*	if($insert_index === false) {
			*		$insert_index = sizeof($array) - 2;
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
		O::fatal_error('internal_delete is probably obsolete');
		$selection_range = $last_index - $first_index + 1;
		if($this->debug && !$process_at_first_level) { // debug
			print('$array, $first_index, $last_index, $selection_range before internal_delete: ');O::var_dump_full(O::tagstring($array), $first_index, $last_index, $selection_range);
		}
		if($array === false) {
			return false;
		}
		$new_array = array();
		// analyze whether the provided array is a results array or LOM array (which have different formats)
		/*if($process_at_first_level) {
			*		$all_sub_entries_are_arrays = false;
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
		if($this->debug) {
			if(!$process_at_first_level) { // debug
				print('$new_array after internal_delete: ');O::var_dump_full(O::tagstring($new_array));
			}
			O::validate();
		}
		return $new_array;
	}

	private function internal_new($array, $new_LOM, $insert_index) {
		O::fatal_error('internal_new if probably obsolete');
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
		if($this->debug) {
			print('$new_array after internal_new: ');O::var_dump_full(O::tagstring($new_array));
			O::validate();
		}
		return $new_array;
	}

	function n($new_value = false, $selector = false) { // alias
		return O::new_($new_value, $selector);
	}

	//function new_tag($new_value = false, $selector = '') { // alias
	function new_tag($new_value = false, $selector = false) { // alias
		return O::new_($new_value, $selector);
	}

	///function _new($new_value = false, $selector = '') { // alias
	function _new($new_value = false, $selector = false) { // alias
		return O::new_($new_value, $selector);
	}

	function new_($new_value, $selector = false, $add_to_context = true, $ignore_context = false, $parent_node_only = false, $tagged_result = true) {
		$profile_token = $this->profile_function_start('new_');
		try {
		if($this->debug) {
			$start_of_new_code = $this->code;
		}
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
		if($this->native_doc && is_string($new_value) && is_string($selector) && O::native_selector_supported($selector) && function_exists('lom_doc_new_before_close')) {
			if(lom_doc_new_before_close($this->native_doc, $selector, $new_value)) {
				O::native_sync_from_doc();
				$out = O::get_tagged($selector);
				return $out;
			}
		}
		if($selector === false) {
			$selector = strlen($this->code);
		}
		// no parent node in new_
		/*if(is_numeric($parent_node)) {
			*		print('$selector, $new_value, $parent_node, $parent_node_only in new_: ');var_dump($selector, $new_value, $parent_node, $parent_node_only);
			*		O::fatal_error('assuming parent_node is an offset in new_ but this is not coded yet');
	} elseif(is_string($parent_node) && strpos(O::query_decode($parent_node), '<') !== false) {
		$parent_node = array(array($parent_node, strpos($this->code, $parent_node)));
	} elseif(is_string($parent_node)) { // assume it's a selector
		$parent_node = O::get_tagged($parent_node);
		//print('$selector, $new_value, $parent_node in new_ with string $parent_node: ');var_dump($selector, $new_value, $parent_node);
	} elseif($parent_node === NULL) {
		$parent_node = false;
	}*/
		//print('$new_value, $selector, $this->code after check for false in new_: ');var_dump($new_value, $selector, $this->code);
		O::check_tag_types($new_value);
		$new_matches = array();
		if(is_numeric($selector)) { // treat it as an offset
			//print('is_numeric($selector) in new_<br />' . PHP_EOL);
			$selector = (int)$selector;
			////print('expanding in new_<br />' . PHP_EOL);
			//print('expanding in new_()<br />' . PHP_EOL);
			//$expanded_LOM = O::expand($this->code, $selector, false, false, 'greedy');
			$expanded_LOM = O::expand($this->code, $selector, false);
			//	print('$expanded_LOM in new_: ');var_dump($expanded_LOM);
			//if($expanded_LOM[0][1] === false) { // deadly hack?? seems so! 2019-07-09. um 2022-01-16
			$offset = $expanded_LOM[0][1];
			//} else {
			//	$offset = $expanded_LOM[1][1] + strlen($expanded_LOM[1][0]);
			//}
			//print('$this->LOM before new_: ');O::var_dump_full($this->LOM);
			//$offset = O::LOM_index_to_offset($selector);
			//print('$offset in new_: ');O::var_dump_full($offset);
			//$new_LOM = O::generate_LOM($new_value, $selector, $offset);
			//print('$new_LOM in new_: ');O::var_dump_full($new_LOM);
			//$this->LOM = O::insert($this->LOM, $selector, $new_LOM);
			//print('$selector, $offset, $expanded_LOM in is_numeric($selector) in new_: ');var_dump($selector, $offset, $expanded_LOM);
			/*$this->code = O::str_insert($this->code, $new_value, $offset);
				*		if($this->string_operation_made_a_change) {
				*			$offset_adjust = strlen($new_value);
				*			if($expanded_LOM[2] === -1) { // round-about way of saying that we are adding to the end of the code
				*				$depth_of_offset = 0;
		} else {
			$depth_of_offset = O::depth($offset);
		}
		print('$offset, $depth_of_offset, $this->code in is_numeric($selector) in new_: ');O::var_dump_full($offset, $depth_of_offset, $this->code);
		//print('before adjust_offsets in new_<br />' . PHP_EOL);
		O::adjust_offsets($offset, $offset_adjust);
		//$this->offset_depths[] = array($offset, $depth_of_offset + 1);
		//if() {
		*/
			//print('$this->context before replace in new_()1: ');O::var_dump_full($this->context);
			//print('$old_value, $new_value, $offset, $expanded_LOM in new_: ');var_dump($old_value, $new_value, $offset, $expanded_LOM);
			$selector_matches = O::replace('', $new_value, $offset, array($expanded_LOM[0]));
			//print('$this->context before replace in new_()1: ');O::var_dump_full($this->context);
			/*$new_value_offset_depths = O::get_offset_depths($new_value, $offset, $depth_of_offset);
				*		//print('$new_value, $new_value_offset_depths: ');var_dump($new_value, $new_value_offset_depths);exit(0);
				*		foreach($new_value_offset_depths as $new_value_offset => $new_value_depth) {
				*			$this->offset_depths[$new_value_offset] = $new_value_depth;
		}
		ksort($this->offset_depths);*/
			/*
				*			//foreach($this->LOM as $LOM_index => $LOM_value) {
				*			//	if($LOM_value[2] >= $offset) {
				*			//		$this->LOM[$LOM_index][2] += $offset_adjust;
				*			//	}
				*			//}
				*			//print('$this->LOM mid new_: ');O::var_dump_full($this->LOM);
				*			//$this->LOM = O::internal_new($this->LOM, $new_LOM, $selector);
				*			//print('$this->LOM after new_: ');O::var_dump_full($this->LOM);
				*
				*			// it was thought (before 2022-01-18) that the solution to properly updating the context with a new tag will be for the context to be structured in such a way that a context1_value refers to the context2_value of a previous context entry and that
				*			// this would require some thought and some coding and would probably be left until version 0.3. this fancy recursive solution would create huge bloat and be extremely difficult to code. instead the LOM is more abstract now and data for it are not
				*			// really maintained, rather we rely on the functions to be properly coded for every situation rather than having an explicit record of everything we are doing; which is more abstract but more efficient.
				*			// proper context updating for new_() written!
				*
				*			if($this->use_context) {
				*				foreach($this->context as $context_index => $context_value) {
				*					print('$this->context[$context_index] before updating context in new_: ');var_dump($this->context[$context_index]);
				*					$new_context2_entry = false;
				*					$new_context3_entry = false;
				*					if($context_value[1] !== false) {
				*						foreach($context_value[1] as $context1_index => $context1_value) {
				*							if($context1_value[0] <= $offset && $context1_value[0] + $context1_value[1] > $offset) {
				*								$this->context[$context_index][1][$context1_index][1] += $offset_adjust;
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
			$new_context2 = array();replace
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
		print('$this->context[$context_index] after updating context in new_: ');var_dump($this->context[$context_index]);
		}
		}
		// need to also update living variables - see set()
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
		// no need to update parent_node (since _new() does not accept one)
		}
		*/
			$new_matches = array(array($new_value, $offset));
			//print('$new_matches at the end of is_numeric($selector) in new_: ');O::var_dump_full($new_matches);
			//O::warning('checking new_ with an offset selector');
			//$selector_matches = array($new_LOM);
			//$selector_matches = $new_LOM;
			//$selector_matches = $new_value;
			//if($this->use_context) {
			//	//$this->context[] = array($selector, false, $selector, $selector_matches);
			//	$this->context[] = array($selector, false, $offset, $selector_matches);
			//}
		} elseif(is_string($selector)) {
			//print('is_string($selector) in new_<br />' . PHP_EOL);
			//$selector_matches = O::get($selector);
			//$selector_matches = O::get_closing_LOM_indices($selector, false, false, false, false);
			//$selector_matches = O::get_LOM_indices($selector, false, false, false, false); // kind of ugly to always add before? after? the opening tag
			/*$selector_matches = O::get_opening_LOM_indices($selector, false, false, false, false);
				*		foreach($selector_matches as $result) {
				*			//if($this->LOM[$result][0] === 1) { // tag node
				*			//	O::new_($new_value, $result);
				*			//} else {
				*				$new_matches = array_merge($new_matches, O::new_($new_value, $result + 1));
				*			//}
		}*/
			$selector_matches = O::get_tagged($selector, false, $add_to_context, $ignore_context, $parent_node_only);
			//foreach($selector_matches as $index => $value) {
			// could make the following chunk an internal_new function
			$selector_matches_index = sizeof($selector_matches) - 1; // have to go in reverse order
			//print('$selector_matches, O::strpos_last($selector_matches[$index][0], \'<\') in is_string($selector) in new_: ');var_dump($selector_matches, O::strpos_last($selector_matches[$index][0], '<'));
			while($selector_matches_index > -1) {
				$last_opening_angle_bracket_position = O::strpos_last($selector_matches[$selector_matches_index][0], '<'); // add inside of the closing tag
				$offset = $last_opening_angle_bracket_position + $selector_matches[$selector_matches_index][1];
				//$new_matches = array_merge($new_matches, O::new_($new_value, $selector_matches[$selector_matches_index][1] + $last_opening_angle_bracket_position, false)); // do not add each (internal) new_ operation to the context
				$new_matches[$selector_matches_index] = array($new_value, $offset);
				//$selector_matches[$selector_matches_index][0] = O::internal_replace($selector_matches[$selector_matches_index][0], '', $new_value, $last_opening_angle_bracket_position);
				//print('$this->context before replace in new_()2: ');O::var_dump_full($this->context);
				//print('$old_value, $new_value, $offset, $parent_node in set: ');var_dump($old_value, $new_value, $offset, $parent_node);
				$selector_matches[$selector_matches_index] = O::replace('', $new_value, $offset, $selector_matches[$selector_matches_index]);
				$selector_matches_offset_adjust_index = $selector_matches_index + 1;
				while($selector_matches_offset_adjust_index < sizeof($selector_matches)) {
					if(isset($selector_matches[$selector_matches_offset_adjust_index][1]) && $selector_matches[$selector_matches_offset_adjust_index][1] >= $offset) {
						$selector_matches[$selector_matches_offset_adjust_index][1] += strlen($new_value);
					}
					if(isset($new_matches[$selector_matches_offset_adjust_index][1]) && $new_matches[$selector_matches_offset_adjust_index][1] >= $offset) {
						$new_matches[$selector_matches_offset_adjust_index][1] += strlen($new_value);
					}
					$selector_matches_offset_adjust_index++;
				}
				$selector_matches_index--;
			}
			//print('$this->code in is_string($selector) in _new: ');O::var_dump_full($this->code);
			//O::warning('checking new_ with a string selector');
			/*
				*		//if($parent_node_only) { // not an option, currently
				*		//	$selector_matches = O::get($selector, $parent_node, false, true, true);
				*		//} else {
				*			$selector_matches = O::get($selector, false, false);
				*		//}
				*		//print('$selector, $new_value, $selector_matches in new_: ');var_dump($selector, $new_value, $selector_matches);
				*		if($this->use_context) {
				*			if(sizeof($selector_matches) === 1) {
				*				foreach($selector_matches as $result) {
				*					foreach($result as $last_index => $last_value) {  }
				*					break;
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
			//print('is_array($selector) in new_<br />' . PHP_EOL);
			// 		if(O::all_entries_are_arrays($selector)) {
			// 			$selector_matches = array();
			// 			$index = sizeof($selector) - 1; // have to go in reverse order
			// 			while($index > -1) {
			// 				$selector_matches = array_merge($selector_matches, O::get_tagged($selector[$index], false, $add_to_context, $ignore_context, $parent_node_only));
			// 				// could make the following chunk an internal_new function
			// 				$selector_matches_index = sizeof($selector_matches) - 1; // have to go in reverse order
			// 				while($selector_matches_index > -1) {
			// 					//$expanded_LOM = O::expand($selector_matches[$selector_matches_index][0], 0, $selector_matches[$selector_matches_index][1], false, 'greedy');
			// 					//$offset = $expanded_LOM[1][1];
			// 					//$offset_adjust = strlen($new_value);
			// 					//$new_matches = array_merge($new_matches, O::new_($new_value, $offset));
			// 					//$selector_matches_offset_adjust_index = $selector_matches_index;
			// 					//if($selector_matches[$selector_matches_index][1] >= $offset) {
			// 					//	$selector_matches[$selector_matches_index][1] += $offset_adjust;
			// 					//}
			// 					//$selector_matches_offset_adjust_index++;
			// 					$last_opening_angle_bracket_position = O::strpos_last($selector_matches[$selector_matches_index][0], '<'); // add inside of the closing tag
			// 					$new_matches = array_merge($new_matches, O::new_($new_value, $selector_matches[$selector_matches_index][1] + $last_opening_angle_bracket_position, false)); // do not add each (internal) new_ operation to the context
			// 					$selector_matches[$selector_matches_index][0] = O::internal_replace($selector_matches[$selector_matches_index][0], '', $new_value, $last_opening_angle_bracket_position);
			// 					$selector_matches_offset_adjust_index = $selector_matches_index + 1;
			// 					while($selector_matches_offset_adjust_index < sizeof($selector_matches)) {
			// 						$selector_matches[$selector_matches_offset_adjust_index][1] += strlen($new_value);
			// 						$selector_matches_offset_adjust_index++;
			// 					}
			// 					$selector_matches_index--;
			// 				}
			// 				$index--;
			// 			}
			//		} else {
			//$new_matches = array_merge($new_matches, O::new_($new_value, $selector[1]));
			$selector_matches = O::get_tagged($selector, false, $add_to_context, $ignore_context, $parent_node_only);
			//print('$selector_matches in is_string in new_: ');var_dump($selector_matches);
			// could make the following chunk an internal_new function
			$selector_matches_index = sizeof($selector_matches) - 1; // have to go in reverse order
			while($selector_matches_index > -1) {
				//$expanded_LOM = O::expand($selector_matches[$selector_matches_index][0], 0, $selector_matches[$selector_matches_index][1], false, 'greedy');
				//$new_matches = array_merge($new_matches, O::new_($new_value, $expanded_LOM[1][1]));
				$last_opening_angle_bracket_position = O::strpos_last($selector_matches[$selector_matches_index][0], '<'); // add inside of the closing tag
				$offset = $last_opening_angle_bracket_position + $selector_matches[$selector_matches_index][1];
				//$new_matches = array_merge($new_matches, O::new_($new_value, $selector_matches[$selector_matches_index][1] + $last_opening_angle_bracket_position, false)); // do not add each (internal) new_ operation to the context
				$new_matches[$selector_matches_index] = array($new_value, $offset);
				//$selector_matches[$selector_matches_index][0] = O::internal_replace($selector_matches[$selector_matches_index][0], '', $new_value, $last_opening_angle_bracket_position);
				$selector_matches[$selector_matches_index] = O::replace('', $new_value, $offset, $selector_matches[$selector_matches_index]);
				$selector_matches_offset_adjust_index = $selector_matches_index + 1;
				while($selector_matches_offset_adjust_index < sizeof($selector_matches)) {
					if(isset($selector_matches[$selector_matches_offset_adjust_index][1]) && $selector_matches[$selector_matches_offset_adjust_index][1] >= $offset) {
						$selector_matches[$selector_matches_offset_adjust_index][1] += strlen($new_value);
					}
					if(isset($new_matches[$selector_matches_offset_adjust_index][1]) && $new_matches[$selector_matches_offset_adjust_index][1] >= $offset) {
						$new_matches[$selector_matches_offset_adjust_index][1] += strlen($new_value);
					}
					$selector_matches_offset_adjust_index++;
				}
				$selector_matches_index--;
			}
			// 		}
			//print('$this->code in is_array($selector) in _new: ');O::var_dump_full($this->code);
			//O::warning('checking new_ with an array selector');
			//if($this->debug_counter === 3) {
			//	O::fatal_error('checking new_ with an array selector');
			//}
			//$this->debug_counter++;
			/*$counter = 0;
				*		//print('here37589708-0<br />' . PHP_EOL);
				*		if(sizeof($selector) === 1) {
				*			//print('here37589708-1<br />' . PHP_EOL);
				*			$its_an_array_of_LOM_arrays = false;
				*			foreach($selector as $index => $value) {
				*				//print('here37589708-2<br />' . PHP_EOL);
				*				$counter = 0;
				*				$proper_LOM_array_format_counter = 0;
				*				foreach($value as $index2 => $value2) {
				*					//print('here37589708-3<br />' . PHP_EOL);
				*					if($value2[0] === 0 || $value2[0] === 1) {
				*						//print('here37589708-4<br />' . PHP_EOL);
				*						$proper_LOM_array_format_counter++;
		}
		//print('here37589708-5<br />' . PHP_EOL);
		$counter++;
		if($counter === 2) {
			//print('here37589708-6<br />' . PHP_EOL);
			break;
		}
		}
		//print('here37589708-7<br />' . PHP_EOL);
		if($counter === $proper_LOM_array_format_counter) {
			//print('here37589708-8<br />' . PHP_EOL);
			$its_an_array_of_LOM_arrays = true;
			break;
		}
		}
		//print('here37589708-9<br />' . PHP_EOL);
		if($its_an_array_of_LOM_arrays) {
			//print('here37589708-10<br />' . PHP_EOL);
			$selector = $selector[$index];
		}
		}
		foreach($selector as $last_index => $last_value) {  }
		//$selector_matches = O::new_($new_value, $last_index - 1);
		$selector_matches = O::new_($new_value, $last_index);*/
			/*$selector_matches = array();
				*		if(O::all_sub_entries_are_arrays($selector)) {
				*			$index_matches = array();
				*			foreach($selector as $index => $value) {
				*				foreach($value as $last_index => $last_value) {  }
				*				$new_LOM = O::generate_LOM($new_value, $last_index);
				*				$this->LOM = O::internal_new($this->LOM, $new_LOM, $last_index);
				*				$selector = O::internal_new($selector, $new_LOM, $last_index);
				*				if($this->use_context) {
				*					foreach($this->context as $context_index => $context_value) {
				*						if($this->context[$context_index][1] === false) { // then recalculate it
				*							$this->context[$context_index][3] = O::get($this->context[$context_index][0], $this->context[$context_index][1], false, true);
				*							$this->context[$context_index][2] = $this->offsets_from_get;
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
		O::invalidate_after_insert_write($new_value);
		//print('$this->LOM after new_: ');O::var_dump_full($this->LOM);exit(0);
		//print('$this->code after new_: ');O::var_dump_full($this->code);
		//return $selector_matches;
		//print('$new_value, $selector, $new_matches before possibly adding to context in new_: ');O::var_dump_full($new_value, $selector, $new_matches);
		if(is_array($selector_matches) && $add_to_context && $this->use_context && !$ignore_context) { // empty result sets are now cacheable because writes centrally invalidate derived state
			//$this->context[] = array($normalized_selector, O::context_array($matching_array), O::context_array($selector_matches));
			//$offset_depths_of_selector_matches = O::get_offset_depths_of_matches($new_matches);
			$all_text = true;
			//foreach($offset_depths_of_selector_matches as $offset_depths) {
			foreach($new_matches as $new_match) {
				//if(sizeof($offset_depths) === 2)
				if(strpos($new_match[0], '<') === false) {

				} else {
					$all_text = false;
					break;
				}
			}
			if($all_text) {

			} else {
				//print('O::tagname($new_value), O::context_array($selector_matches), O::context_array($new_matches), $offset_depths_of_selector_matches) when adding to context in new_(): ');var_dump(O::tagname($new_value), O::context_array($selector_matches), O::context_array($new_matches), $offset_depths_of_selector_matches);
				//$this->context[] = array(O::tagname($new_value), O::context_array($selector_matches), O::context_array($new_matches), $offset_depths_of_selector_matches); // strictly speaking, the PERFECT answer would be some sort of parser that could always generate the selector going into the context from the $new_value string of code provided, instead of merely using the tagname, but that would be non-trivial
				//O::add_to_context(O::tagname($new_value), O::context_array($selector_matches), O::context_array($new_matches), $offset_depths_of_selector_matches);
				O::add_to_context(O::tagname($new_value), O::context_array($selector_matches), O::context_array($new_matches));
			}
			//print('$this->context[sizeof($this->context) - 1] after adding to context: ');var_dump($this->context[sizeof($this->context) - 1]);
		}
		//print('$this->context at end of new_: ');O::var_dump_full($this->context);
		if($this->debug) { // incremental depth map must match a full expand (debug-only; hash iteration order is not document order)
			$expected_depths = O::get_offset_depths(false, 0, 0, true);
			$actual = $this->offset_depths;
			ksort($actual);
			ksort($expected_depths);
			if($actual !== $expected_depths) {
				print('$this->offset_depths (incremental): ');O::var_dump_full($this->offset_depths);
				print('$expected_depths (full expand): ');O::var_dump_full($expected_depths);
				O::fatal_error('$this->offset_depths disagree with full expand after new_');
			}
			if(strlen($new_value) > 0 && $start_of_new_code === $this->code) {
				print('$new_value, $selector, $selector_matches: ');O::var_dump_full($new_value, $selector, $selector_matches);
				O::fatal_error('code was unchanged by new_');
			}
		}
		//print('new_0001<br />' . PHP_EOL);
		//print('$new_matches at end of new_: ');O::var_dump_full($new_matches);
		//print('$this->offset_depths at end of new_(): ');var_dump($this->offset_depths);exit(0);
		if($tagged_result === true) {
			//print('new_0002<br />' . PHP_EOL);
			return $new_matches;
		}
		//print('new_0003<br />' . PHP_EOL);
		$new_matches = O::export($new_matches);
		//print('new_0004<br />' . PHP_EOL);
		return $new_matches;
		} finally {
			$this->profile_function_stop('new_', $profile_token);
		}
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
		O::reset_context(); // noteworthy
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
		if(!is_array($selector_matches)) {
			//print('$selector_matches: ');var_dump($selector_matches);
			//O::fatal_error('!is_array($selector_matches) in rand()');
			$selector_matches = array($selector_matches);
		}
		//print('$selector_matches before choosing in random(): ');var_dump($selector_matches);
		return $selector_matches[rand(0, sizeof($selector_matches) - 1)];
	}

	function delete($selector, $parent_node = false, $parent_node_only = false) {
		//print('$selector, $parent_node, $this->code, $this->LOM, $this->context before delete: ');var_dump($selector, $parent_node, $this->code, $this->LOM, $this->context);
		// 	if($parent_node === false) {
		//
		// 	} elseif(!is_array($parent_node)) {
		// 		print('$parent_node: ');var_dump($parent_node);
		// 		O::fatal_error('!is_array($parent_node) is not coded for yet because how to get what the selector is referring to in a $parent_node that is a mere string without an accompanying offset is unclear');
		// 	}
		if(is_array($parent_node) && !O::all_entries_are_arrays($parent_node)) {
			$parent_node = array($parent_node);
		} elseif(is_string($parent_node) && strpos(O::query_decode($parent_node), '<') !== false) {
			$add_to_context = false;
			$ignore_context = true;
			$parent_node_only = true;
			//$this->code = O::code_from_LOM();
			if(strpos($this->code, $parent_node) !== false) {
				$parent_node = array(array($parent_node, strpos($this->code, $parent_node)));
			} else {
				$parent_node = array(array($parent_node, 0));
			}
		} elseif(is_string($parent_node)) {
			$parent_node = O::get($parent_node, false, $add_to_context, $ignore_context); // not sure if we should force whether to add to context
		}
		if(is_array($parent_node) && sizeof($parent_node) === 0) {
			return array();
		}
		//print('$parent_node in delete(): ');var_dump($parent_node);
		//print('$selector, $parent_node in delete(): ');var_dump($selector, $parent_node);
		//$this->debug_counter++;
		//if($this->debug_counter === 90) {
		//	print('debug_counter in delete');
		//	exit(0);
		//}
		// worth noting that it probably would have been easier to have this function be a sort of alias and return O::set($selector, '', $parent_node = false); although the assumption that set is working on only single values would no longer hold...
		if(is_numeric($selector)) { // treat it as an offset
			$selector = (int)$selector;
			//$tag_LOM_array = O::get_tag_LOM_array($selector);
			//print('$tag_LOM_array: ');O::var_dump_full($tag_LOM_array);exit(0);
			//$deleted_string = O::tostring($tag_LOM_array);
			//print('expanding in delete()<br />' . PHP_EOL);
			//$expanded_LOM = O::expand($this->code, $selector, false, false, 'lazy');
			$expanded_LOM = O::expand($this->code, $selector, false);
			//print('$expanded_LOM in delete: ');var_dump($expanded_LOM);
			//$deleted_string = $expanded_LOM[1][0]; // nope. to set something to empty (delete its contents) set should be used
			$deleted_string = $expanded_LOM[0][0];
			//$offset = O::LOM_index_to_offset($selector);
			$offset = $selector;
			//print('$expanded_LOM, $deleted_string, $offset, $this->code in delete(): ');O::var_dump_full($expanded_LOM, $deleted_string, $offset, $this->code);
			$parent_node = O::replace($deleted_string, '', $offset, $parent_node);
			/*
				*		$offset_adjust = -1 * strlen($deleted_string);
				*		if(!$parent_node_only) {
				*			//print('$this->code, $deleted_string, $offset in delete: ');var_dump($this->code, $deleted_string, $offset);
				*			$this->code = O::str_delete($this->code, $deleted_string, $offset);
				*			//print('$this->code, $this->string_operation_made_a_change after deleting: ');var_dump($this->code, $this->string_operation_made_a_change);
				*			if($this->string_operation_made_a_change) {
				*				$deleted_string_offset_depths = O::get_offset_depths($deleted_string, $offset);
				*				foreach($deleted_string_offset_depths as $deleted_string_offset => $deleted_string_depth) {
				*					unset($this->offset_depths[$deleted_string_offset]);
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
		//print('before adjust_offsets in delete<br />' . PHP_EOL);
		O::adjust_offsets($offset, $offset_adjust);
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
		*/	/*
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
		$this->invalidate_context_selector_index();
		}*/
		} elseif(is_string($selector)) {
			//$selector_matches = O::get_LOM_indices($selector, false, false, false, false);
			$selector_matches = O::get_tagged($selector, $parent_node, false, false, false);
			//print('$selector_matches in is_string($selector) in delete(): ');var_dump($selector_matches);
			//$counter = sizeof($this->offsets_from_get) - 1;
			$counter = sizeof($selector_matches) - 1;
			//if($parent_node === false) {
			while($counter > -1) { // go in reverse order
				//print('$counter, $this->offsets_from_get[$counter], O::opening_LOM_index_from_offset($this->offsets_from_get[$counter]): ');var_dump($counter, $this->offsets_from_get[$counter], O::opening_LOM_index_from_offset($this->offsets_from_get[$counter]));
				/*if($this->LOM[$selector_matches[$counter]][0] === 0) { // text node
					*					$parent_node = O::delete($selector_matches[$counter] - 1, $parent_node, $parent_node_only);
			} else {
				//$parent_node = O::delete(O::opening_LOM_index_from_offset($this->offsets_from_get[$counter]), $parent_node, $parent_node_only);
				$parent_node = O::delete($selector_matches[$counter], $parent_node, $parent_node_only);
			}*/
				$parent_node = O::delete($selector_matches[$counter][1], $parent_node, $parent_node_only);
				$counter--;
			}
			//}
			/*if($parent_node_only) {
				*			$selector_matches = O::get($selector, $parent_node, false, true, true);
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
		$this->invalidate_context_selector_index();
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
		$this->invalidate_context_selector_index();
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
		$this->invalidate_context_selector_index();
		}
		}
		}*/
		} elseif(is_array($selector)) {
			//print('is_array($selector) in delete: ');O::var_dump_full($selector);exit(0);
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
				*		while(isset($selector[$counter1])) {
				*			foreach($selector[$counter1] as $index2 => $value2) { break; }
				*			if(is_array($selector[$counter1][$index2])) {
				*				//print('nested array $selector[$counter1] in delete: ');O::var_dump_full($selector[$counter1]);
				*				// have to go in reverse order
				*				foreach($selector[$counter1] as $counter2 => $value2) {  }
				*				while(isset($selector[$counter1][$counter2])) {
				*					//print('deleting index2: ' . $counter2 . '<br />' . PHP_EOL);
				*					$parent_node = O::delete($counter2, $parent_node);
				*					//print('nested array $selector[$counter1] after nested delete: ');O::var_dump_full($selector[$counter1]);
				*					$counter2--;
		}
		} else {
			//print('deleting index: ' . $counter1 . '<br />' . PHP_EOL);
			$parent_node = O::delete($counter1, $parent_node);
		}
		$counter1--;
		}*/
			/*
				*		if(O::all_sub_entries_are_arrays($selector)) {
				*			foreach($selector as $index => $value) {
				*				foreach($value as $first_index => $first_value) { break; }
				*				foreach($value as $last_index => $last_value) {  }
				*				if(!$parent_node_only) {
				*					$this->LOM = O::internal_delete($this->LOM, $first_index, $last_index);
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
		$this->invalidate_context_selector_index();
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
		$this->invalidate_context_selector_index();
		}
		}*/
		} else {
			print('$selector: ');var_dump($selector);
			O::fatal_error('Unknown selector type in delete');
		}
		O::invalidate_derived_state();
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
			//print('is_numeric($selector) in strip_tag<br />' . PHP_EOL);
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
				*		$parent_node = O::internal_delete($parent_node, $selector, $selector);
				*		$parent_node = O::internal_delete($parent_node, $last_index, $last_index);
				*		if($this->use_context && !$parent_node_only) {
				*			foreach($this->context as $context_index => $context_value) {
				*				//print('$context_value[3]: ');var_dump($context_value[3]);
				*				if($this->context[$context_index][1] === false) { // then recalculate it
				*					$this->context[$context_index][3] = O::get($this->context[$context_index][0], $this->context[$context_index][1], false, true);
				*					$this->context[$context_index][2] = $this->offsets_from_get;
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
		$this->invalidate_context_selector_index();
		}*/
		} elseif(is_string($selector) && $selector[0] === '<') { // would like this type of data type handling in more functions
			//print('is_string($selector) && $selector[0] === '<' in strip_tag<br />' . PHP_EOL);
			return substr($selector, strpos($selector, '>') + 1, strlen($selector) - (strpos($selector, '>') + 1) - (strlen($selector) - O::strpos_last($selector, '<')));
		} elseif(is_string($selector)) {
			//print('is_string($selector) in strip_tag<br />' . PHP_EOL);
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
				*			$selector_matches = O::get($selector, $parent_node, false, true, true);
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
		$this->invalidate_context_selector_index();
		}*/
		} elseif(is_array($selector)) {
			//print('is_array($selector) in strip_tag<br />' . PHP_EOL);
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
				*			foreach($selector as $index => $value) {
				*				foreach($value as $first_index => $first_value) { break; }
				*				foreach($value as $last_index => $last_value) {  }
				*				if(!$parent_node_only) {
				*					$this->LOM = O::internal_delete($this->LOM, $last_index, $last_index);
				*					$this->LOM = O::internal_delete($this->LOM, $first_index, $first_index);
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
		$this->invalidate_context_selector_index();
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
		$this->invalidate_context_selector_index();
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
			*		//print('$this->array_delayed_delete start of while: ');var_dump($this->array_delayed_delete);
			*		//print('$this->array_delayed_delete: ');O::var_dump_full($this->array_delayed_delete);
			*		foreach($this->array_delayed_delete as $index => $value) {
			*			$first_index = $value[0];
			*			$last_index = $value[1];
			*			$selection_range = $last_index - $first_index + 1;
			*			$this->LOM = O::internal_delete($this->LOM, $first_index, $last_index);
			*			if($this->use_context) {
			*				foreach($this->context as $context_index => $context_value) {
			*					if($this->context[$context_index][1] === false) { // then recalculate it
			*						$this->context[$context_index][3] = O::get($this->context[$context_index][0], $this->context[$context_index][1], false, true);
			*						$this->context[$context_index][2] = $this->offsets_from_get;
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
		$this->invalidate_context_selector_index();
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
				if($this->debug && $first_index == 0) { // debug
					print('$new_value, $first_index, $new_LOM, $this->array_delayed_new: ');var_dump($new_value, $first_index, $new_LOM, $this->array_delayed_new);exit(0);
				}
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
			if($this->debug) {
				O::validate();
			}
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
			//print('here8567080<br />' . PHP_EOL);
			$selector = (int)$selector;
			$parent_node = O::set($selector, O::get($selector, $parent_node) + $to_add, $parent_node, $parent_node_only);
		} elseif(is_string($selector)) {
			//print('here8567081<br />' . PHP_EOL);
			//$index_results = O::get_LOM_indices($selector, $parent_node);
			//$offsets = O::get_offsets($selector, $parent_node);
			//foreach($offsets as $offset) {
			$selector_matches = O::get_tagged($selector, $parent_node);
			$selector_matches_index = sizeof($selector_matches) - 1;
			//print('$selector_matches, $selector_matches_index, $to_add in add: ');var_dump($selector_matches, $selector_matches_index, $to_add);
			//foreach($selector_matches as $selector_match) {
			while($selector_matches_index > -1) {
				//print('here8567082<br />' . PHP_EOL);
				//print('$index, O::get($index), $to_add in add: ');var_dump($index, O::get($index), $to_add);
				//$parent_node = O::set($offset, O::get($offset, $parent_node) + $to_add, $parent_node, $parent_node_only);
				$parent_node = O::set($selector_matches[$selector_matches_index][1], O::tagless($selector_matches[$selector_matches_index][0]) + $to_add, $parent_node, $parent_node_only);
				$selector_matches_index--;
			}
			//O::set($selector, O::get($selector, $parent_node) + $to_add, $parent_node);
		} elseif(is_array($selector)) { // recurse??
			//print('$to_add, $selector, $parent_node, $parent_node_only: ');var_dump($to_add, $selector, $parent_node, $parent_node_only);
			//O::fatal_error('array selector not handled in add function');
			$selector_index = sizeof($selector) - 1;
			while($selector_index > -1) {
				$text_offset = $selector[$selector_index][1] + strpos($selector[$selector_index][0], '>') + 1;
				$parent_node = O::add($to_add, $text_offset, $parent_node, $parent_node_only);
				$selector_index--;
			}
		} else {
			print('$to_add, $selector, $parent_node, $parent_node_only: ');var_dump($to_add, $selector, $parent_node, $parent_node_only);
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
				*		$offset_index = sizeof($offsets) - 1;
				*		while($offset_index > -1) {
				*			//print('$index, O::get($index), $to_add in add_zero_ceiling: ');var_dump($index, O::get($index), $to_add);
				*			$new_value = O::get($offsets[$offset_index], $parent_node) + $to_add;
				*			if($new_value > 0) {
				*				$new_value = 0;
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
			//print('$to_add, $selector, $parent_node, $parent_node_only: ');var_dump($to_add, $selector, $parent_node, $parent_node_only);
			//O::fatal_error('array selector not handled in add_zero_ceiling function');
			$selector_index = sizeof($selector) - 1;
			while($selector_index > -1) {
				$text_offset = $selector[$selector_index][1] + strpos($selector[$selector_index][0], '>') + 1;
				$parent_node = O::add_zero_ceiling($to_add, $text_offset, $parent_node, $parent_node_only);
				$selector_index--;
			}
		} else {
			print('$to_add, $selector, $parent_node, $parent_node_only: ');var_dump($to_add, $selector, $parent_node, $parent_node_only);
			O::fatal_error('Unknown selector type in add_zero_ceiling');
		}
		return $parent_node;
	}

	function add_with_ceiling($to_add, $selector, $ceiling, $parent_node = false, $parent_node_only = false) {
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
			O::fatal_error('to_add false in add_with_ceiling');
			$to_add = O::get($value_to_add_selector, $value_to_add_parent_node);
		}
		if(is_numeric($selector)) { // treat it as an offset
			$selector = (int)$selector;
			$new_value = O::get($selector, $parent_node) + $to_add;
			if($new_value > $ceiling) {
				$new_value = $ceiling;
			}
			$parent_node = O::set($selector, $new_value, $parent_node, $parent_node_only);
		} elseif(is_string($selector)) {
			$selector_matches = O::get_tagged($selector, $parent_node);
			$selector_matches_index = sizeof($selector_matches) - 1;
			while($selector_matches_index > -1) {
				$new_value = O::tagless($selector_matches[$selector_matches_index][0]) + $to_add;
				if($new_value > $ceiling) {
					$new_value = $ceiling;
				}
				$parent_node = O::set($selector_matches[$selector_matches_index][1], $new_value, $parent_node, $parent_node_only);
				$selector_matches_index--;
			}
		} elseif(is_array($selector)) { // recurse??
			$selector_index = sizeof($selector) - 1;
			while($selector_index > -1) {
				$text_offset = $selector[$selector_index][1] + strpos($selector[$selector_index][0], '>') + 1;
				$parent_node = O::add_with_ceiling($to_add, $text_offset, $ceiling, $parent_node, $parent_node_only);
				$selector_index--;
			}
		} else {
			print('$to_add, $selector, $parent_node, $parent_node_only: ');var_dump($to_add, $selector, $parent_node, $parent_node_only);
			O::fatal_error('Unknown selector type in add_with_ceiling');
		}
		return $parent_node;
	}

	function subtract($to_subtract, $selector, $parent_node = false, $parent_node_only = false) {
		//print('$to_subtract, $selector, $parent_node: ');var_dump($to_subtract, $selector, $parent_node);
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
			print('O::get($selector, $parent_node) in is_numeric($selector) in subtract(): ');var_dump(O::get($selector, $parent_node));
			$parent_node = O::set($selector, O::get($selector, $parent_node) - $to_subtract, $parent_node, $parent_node_only);
		} elseif(is_string($selector)) {
			/*$offsets = O::get_offsets($selector, $parent_node);
				*		$offset_index = sizeof($offsets) - 1;
				*		while($offset_index > -1) {
				*			//print('$index, O::get($index), $to_subtract in subtract: ');var_dump($index, O::get($index), $to_subtract);
				*			$parent_node = O::set($offsets[$offset_index], O::get($offsets[$offset_index], $parent_node) - $to_subtract, $parent_node, $parent_node_only);
				*			$offset_index--;
		}*/
			$selector_matches = O::get_tagged($selector, $parent_node);
			$selector_matches_index = sizeof($selector_matches) - 1;
			while($selector_matches_index > -1) {
				$parent_node = O::set($selector_matches[$selector_matches_index][1], O::tagless($selector_matches[$selector_matches_index][0]) - $to_subtract, $parent_node, $parent_node_only);
				$selector_matches_index--;
			}
			//O::set($selector, O::get($selector, $parent_node) - $to_subtract, $parent_node);
		} elseif(is_array($selector)) { // recurse??
			//print('$to_subtract, $selector, $parent_node, $parent_node_only: ');var_dump($to_add, $selector, $parent_node, $parent_node_only);
			//O::fatal_error('array selector not handled in subtract function');
			$selector_index = sizeof($selector) - 1;
			while($selector_index > -1) {
				$text_offset = $selector[$selector_index][1] + strpos($selector[$selector_index][0], '>') + 1;
				$parent_node = O::subtract($to_subtract, $text_offset, $parent_node, $parent_node_only);
				$selector_index--;
			}
		} else {
			print('$to_subtract, $selector, $parent_node, $parent_node_only: ');var_dump($to_add, $selector, $parent_node, $parent_node_only);
			O::fatal_error('Unknown selector type in subtract');
		}
		//print('$parent_node at end of subtract: ');var_dump($parent_node);
		//print('$this->code at end of subtract: ');var_dump($this->code);
		//print('$this->expands at end of subtract: ');var_dump($this->expands);
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
				*		$offset_index = sizeof($offsets) - 1;
				*		while($offset_index > -1) {
				*			$new_value = O::get($offsets[$offset_index], $parent_node) - $to_subtract;
				*			if($new_value < 0) {
				*				$new_value = 0;
		}
		//print('$index, $new_value, $parent_node, $parent_node_only in subtract_zero_floor: ');var_dump($index, $new_value, $parent_node, $parent_node_only);
		$parent_node = O::set($offsets[$offset_index], $new_value, $parent_node, $parent_node_only);
		$offset_index--;
		}*/
			$selector_matches = O::get_tagged($selector, $parent_node);
			$selector_matches_index = sizeof($selector_matches) - 1;
			while($selector_matches_index > -1) {
				//print('$selector_matches[$selector_matches_index][0]), $to_subtract: ');var_dump($selector_matches[$selector_matches_index][0], $to_subtract);
				$new_value = O::tagless($selector_matches[$selector_matches_index][0]) - $to_subtract;
				if($new_value < 0) {
					$new_value = 0;
				}
				$parent_node = O::set($selector_matches[$selector_matches_index][1], $new_value, $parent_node, $parent_node_only);
				$selector_matches_index--;
			}
			//O::set($selector, O::get($selector, $parent_node) - $to_subtract, $parent_node);
		} elseif(is_array($selector)) { // recurse??
			//print('$to_subtract, $selector, $parent_node, $parent_node_only: ');var_dump($to_add, $selector, $parent_node, $parent_node_only);
			//O::fatal_error('array selector not handled in subtract_zero_floor function');
			$selector_index = sizeof($selector) - 1;
			while($selector_index > -1) {
				$text_offset = $selector[$selector_index][1] + strpos($selector[$selector_index][0], '>') + 1;
				$parent_node = O::subtract_zero_floor($to_subtract, $text_offset, $parent_node, $parent_node_only);
				$selector_index--;
			}
		} else {
			print('$to_subtract, $selector, $parent_node, $parent_node_only: ');var_dump($to_add, $selector, $parent_node, $parent_node_only);
			O::fatal_error('Unknown selector type in subtract_zero_floor');
		}
		return $parent_node;
	}

	function subtract_with_floor($to_subtract, $selector, $floor, $parent_node = false, $parent_node_only = false) {
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
			O::fatal_error('to_subtract false in subtract_with_floor');
			$to_subtract = O::get($value_to_add_selector, $value_to_add_parent_node);
		}
		//print('$to_subtract, $selector, $parent_node, $parent_node_only in subtract_with_floor after sorting parameters: ');var_dump($to_subtract, $selector, $parent_node, $parent_node_only);
		if(is_numeric($selector)) { // treat it as an offset
			$selector = (int)$selector;
			$new_value = O::get($selector, $parent_node) - $to_subtract;
			if($new_value < $floor) {
				$new_value = $floor;
			}
			$parent_node = O::set($selector, $new_value, $parent_node, $parent_node_only);
		} elseif(is_string($selector)) {
			$selector_matches = O::get_tagged($selector, $parent_node);
			$selector_matches_index = sizeof($selector_matches) - 1;
			while($selector_matches_index > -1) {
				$new_value = O::tagless($selector_matches[$selector_matches_index][0]) - $to_subtract;
				if($new_value < $floor) {
					$new_value = $floor;
				}
				$parent_node = O::set($selector_matches[$selector_matches_index][1], $new_value, $parent_node, $parent_node_only);
				$selector_matches_index--;
			}
		} elseif(is_array($selector)) { // recurse??
			$selector_index = sizeof($selector) - 1;
			while($selector_index > -1) {
				$text_offset = $selector[$selector_index][1] + strpos($selector[$selector_index][0], '>') + 1;
				$parent_node = O::subtract_with_floor($to_subtract, $text_offset, $ceiling, $parent_node, $parent_node_only);
				$selector_index--;
			}
		} else {
			print('$to_subtract, $selector, $parent_node, $parent_node_only: ');var_dump($to_add, $selector, $parent_node, $parent_node_only);
			O::fatal_error('Unknown selector type in subtract_with_floor');
		}
		return $parent_node;
	}

	function inc($selector, $parent_node = false, $parent_node_only = false) { // alias
		return O::increment($selector, $parent_node, $parent_node_only);
	}

	function incr($selector, $parent_node = false, $parent_node_only = false) { // alias
		return O::increment($selector, $parent_node, $parent_node_only);
	}

	function increment($selector, $parent_node = false, $parent_node_only = false) {
		if(is_numeric($selector)) { // treat it as an offset
			$selector = (int)$selector;
			$parent_node = O::set($selector, O::get($selector, $parent_node) + 1, $parent_node, $parent_node_only);
		} elseif(is_string($selector)) {
			/*$offsets = O::get_offsets($selector, $parent_node);
				*		$offset_index = sizeof($offsets) - 1;
				*		while($offset_index > -1) {
				*			//print('$index, O::get($index): ');var_dump($index, O::get($index));
				*			$parent_node = O::set($offsets[$offset_index], O::get($offsets[$offset_index], $parent_node) + 1, $parent_node, $parent_node_only);
				*			//print('$index, O::get($index), $this->context, $this->LOM after increment: ');O::var_dump_full($index, O::get($index), $this->context, $this->LOM);
				*			$offset_index--;
		}*/
			$parent_node = O::add(1, $selector, $parent_node, $parent_node_only);
		} elseif(is_array($selector)) { // recurse??
			//print('$selector, $parent_node, $parent_node_only: ');var_dump($to_add, $selector, $parent_node, $parent_node_only);
			//O::fatal_error('array selector not handled in increment function');
			$selector_index = sizeof($selector) - 1;
			while($selector_index > -1) {
				$text_offset = $selector[$selector_index][1] + strpos($selector[$selector_index][0], '>') + 1;
				$parent_node = O::increment($text_offset, $parent_node, $parent_node_only);
				$selector_index--;
			}
		} else {
			print('$selector, $parent_node, $parent_node_only: ');var_dump($to_add, $selector, $parent_node, $parent_node_only);
			O::fatal_error('Unknown selector type in increment');
		}
		return $parent_node;
	}

	function inc_zero_ceil($selector, $parent_node = false, $parent_node_only = false) { // alias
		return O::increment_zero_ceiling($selector, $parent_node, $parent_node_only);
	}

	function inc_zero_ceiling($selector, $parent_node = false, $parent_node_only = false) { // alias
		return O::increment_zero_ceiling($selector, $parent_node, $parent_node_only);
	}

	function incr_zero_ceil($selector, $parent_node = false, $parent_node_only = false) { // alias
		return O::increment_zero_ceiling($selector, $parent_node, $parent_node_only);
	}

	function incr_zero_ceiling($selector, $parent_node = false, $parent_node_only = false) { // alias
		return O::increment_zero_ceiling($selector, $parent_node, $parent_node_only);
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
				*		$offset_index = sizeof($offsets) - 1;
				*		while($offset_index > -1) {
				*			$incremented = O::get($offsets[$offset_index], $parent_node) + 1;
				*			if($incremented <= 0) {
				*				$parent_node = O::set($offsets[$offset_index], $incremented, $parent_node, $parent_node_only);
		}
		$offset_index--;
		}*/
			$parent_node = O::add_zero_ceiling(1, $selector, $parent_node, $parent_node_only);
		} elseif(is_array($selector)) { // recurse??
			//print('$selector, $parent_node, $parent_node_only: ');var_dump($to_add, $selector, $parent_node, $parent_node_only);
			//O::fatal_error('array selector not handled in increment_zero_ceiling function');
			$selector_index = sizeof($selector) - 1;
			while($selector_index > -1) {
				$text_offset = $selector[$selector_index][1] + strpos($selector[$selector_index][0], '>') + 1;
				$parent_node = O::increment_zero_ceiling($text_offset, $parent_node, $parent_node_only);
				$selector_index--;
			}
		} else {
			print('$selector, $parent_node, $parent_node_only: ');var_dump($to_add, $selector, $parent_node, $parent_node_only);
			O::fatal_error('Unknown selector type in increment_zero_ceiling');
		}
		return $parent_node;
	}

	function increment_with_ceiling($selector, $ceiling, $parent_node = false, $parent_node_only = false) {
		if(is_numeric($selector)) { // treat it as an offset
			$selector = (int)$selector;
			$incremented = O::get($selector, $parent_node) + 1;
			if($incremented <= $ceiling) {
				$parent_node = O::set($selector, $incremented, $parent_node, $parent_node_only);
			}
		} elseif(is_string($selector)) {
			$parent_node = O::add_with_ceiling(1, $selector, $ceiling, $parent_node, $parent_node_only);
		} elseif(is_array($selector)) { // recurse??
			$selector_index = sizeof($selector) - 1;
			while($selector_index > -1) {
				$text_offset = $selector[$selector_index][1] + strpos($selector[$selector_index][0], '>') + 1;
				$parent_node = O::increment_with_ceiling($text_offset, $ceiling, $parent_node, $parent_node_only);
				$selector_index--;
			}
		} else {
			print('$selector, $parent_node, $parent_node_only: ');var_dump($to_add, $selector, $parent_node, $parent_node_only);
			O::fatal_error('Unknown selector type in increment_with_ceiling');
		}
		return $parent_node;
	}

	function decr($selector, $parent_node = false, $parent_node_only = false) { // alias
		return O::decrement($selector, $parent_node, $parent_node_only);
	}

	function decrement($selector, $parent_node = false, $parent_node_only = false) {
		if(is_numeric($selector)) { // treat it as an offset
			$selector = (int)$selector;
			$parent_node = O::set($selector, O::get($selector, $parent_node) - 1, $parent_node, $parent_node_only);
		} elseif(is_string($selector)) {
			/*$offsets = O::get_offsets($selector, $parent_node);
				*		$offset_index = sizeof($offsets) - 1;
				*		while($offset_index > -1) {
				*			$parent_node = O::set($offsets[$offset_index], O::get($offsets[$offset_index], $parent_node) - 1, $parent_node, $parent_node_only);
				*			$offset_index--;
		}*/
			$parent_node = O::subtract(1, $selector, $parent_node, $parent_node_only);
		} elseif(is_array($selector)) { // recurse??
			//print('$selector, $parent_node, $parent_node_only: ');var_dump($to_add, $selector, $parent_node, $parent_node_only);
			//O::fatal_error('array selector not handled in decrement function');
			$selector_index = sizeof($selector) - 1;
			while($selector_index > -1) {
				$text_offset = $selector[$selector_index][1] + strpos($selector[$selector_index][0], '>') + 1;
				$parent_node = O::decrement($text_offset, $parent_node, $parent_node_only);
				$selector_index--;
			}
		} else {
			print('$selector, $parent_node, $parent_node_only: ');var_dump($to_add, $selector, $parent_node, $parent_node_only);
			O::fatal_error('Unknown selector type in decrement');
		}
		return $parent_node;
	}

	function decr_zero_floor($selector, $parent_node = false, $parent_node_only = false) { // alias
		return O::decrement_zero_floor($selector, $parent_node, $parent_node_only);
	}

	function decr_zero_flr($selector, $parent_node = false, $parent_node_only = false) { // alias
		return O::decrement_zero_floor($selector, $parent_node, $parent_node_only);
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
				*		$offset_index = sizeof($offsets) - 1;
				*		while($offset_index > -1) {
				*			$decremented = O::get($offsets[$offset_index], $parent_node) - 1;
				*			if($decremented >= 0) {
				*				$parent_node = O::set($offsets[$offset_index], $decremented, $parent_node, $parent_node_only);
		}
		$offset_index--;
		}*/
			$parent_node = O::subtract_zero_floor(1, $selector, $parent_node, $parent_node_only);
		} elseif(is_array($selector)) { // recurse??
			//print('$selector, $parent_node, $parent_node_only: ');var_dump($to_add, $selector, $parent_node, $parent_node_only);
			//O::fatal_error('array selector not handled in decrement_zero_floor function');
			$selector_index = sizeof($selector) - 1;
			while($selector_index > -1) {
				$text_offset = $selector[$selector_index][1] + strpos($selector[$selector_index][0], '>') + 1;
				$parent_node = O::decrement_zero_floor($text_offset, $parent_node, $parent_node_only);
				$selector_index--;
			}
		} else {
			print('$selector, $parent_node, $parent_node_only: ');var_dump($to_add, $selector, $parent_node, $parent_node_only);
			O::fatal_error('Unknown selector type in decrement_zero_floor');
		}
		return $parent_node;
	}

	function decrement_with_floor($selector, $floor, $parent_node = false, $parent_node_only = false) {
		if(is_numeric($selector)) { // treat it as an offset
			$selector = (int)$selector;
			$decremented = O::get($selector, $parent_node) - 1;
			if($decremented >= $floor) {
				$parent_node = O::set($selector, $decremented, $parent_node, $parent_node_only);
			}
		} elseif(is_string($selector)) {
			$parent_node = O::subtract_with_floor(1, $selector, $floor, $parent_node, $parent_node_only);
		} elseif(is_array($selector)) { // recurse??
			$selector_index = sizeof($selector) - 1;
			while($selector_index > -1) {
				$text_offset = $selector[$selector_index][1] + strpos($selector[$selector_index][0], '>') + 1;
				$parent_node = O::decrement_with_floor($text_offset, $floor, $parent_node, $parent_node_only);
				$selector_index--;
			}
		} else {
			print('$selector, $parent_node, $parent_node_only: ');var_dump($to_add, $selector, $parent_node, $parent_node_only);
			O::fatal_error('Unknown selector type in decrement_with_floor');
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
				*		$offset_index = sizeof($offsets) - 1;
				*		while($offset_index > -1) {
				*			$parent_node = O::set($offsets[$offset_index], exec('O::get($selector, $parent_node)' . $operation), $parent_node, $parent_node_only);
				*			$offset_index--;
		}*/
			$selector_matches = O::get_tagged($selector, $parent_node);
			$selector_matches_index = sizeof($selector_matches) - 1;
			while($selector_matches_index > -1) {
				$parent_node = O::set($selector_matches[$selector_matches_index][1], exec('O::tagless($selector_matches[$selector_matches_index][0]' . $operation), $parent_node, $parent_node_only);
				$selector_matches_index--;
			}
		} elseif(is_array($selector)) { // recurse??
			//print('$selector, $operation, $parent_node, $parent_node_only: ');var_dump($to_add, $selector, $operation, $parent_node, $parent_node_only);
			//O::fatal_error('array selector not handled in operation function');
			$selector_index = sizeof($selector) - 1;
			while($selector_index > -1) {
				$text_offset = $selector[$selector_index][1] + strpos($selector[$selector_index][0], '>') + 1;
				$parent_node = O::operation($text_offset, $to_divide, $parent_node, $parent_node_only);
				$selector_index--;
			}
		} else {
			print('$selector, $operation, $parent_node, $parent_node_only: ');var_dump($to_add, $selector, $operation, $parent_node, $parent_node_only);
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
				*		$offset_index = sizeof($offsets) - 1;
				*		while($offset_index > -1) {
				*			$parent_node = O::set($offsets[$offset_index], O::get($offsets[$offset_index], $parent_node) * $to_multiply, $parent_node, $parent_node_only);
				*			$offset_index--;
		}*/
			$selector_matches = O::get_tagged($selector, $parent_node);
			$selector_matches_index = sizeof($selector_matches) - 1;
			while($selector_matches_index > -1) {
				$parent_node = O::set($selector_matches[$selector_matches_index][1], O::tagless($selector_matches[$selector_matches_index][0]) * $to_multiply, $parent_node, $parent_node_only);
				$selector_matches_index--;
			}
			//O::set($selector, O::get($selector, $parent_node) - $to_multiply, $parent_node);
		} elseif(is_array($selector)) { // recurse??
			//print('$selector, $to_multiply, $parent_node, $parent_node_only: ');var_dump($to_add, $selector, $to_multiply, $parent_node, $parent_node_only);
			//O::fatal_error('array selector not handled in multiply function');
			$selector_index = sizeof($selector) - 1;
			while($selector_index > -1) {
				$text_offset = $selector[$selector_index][1] + strpos($selector[$selector_index][0], '>') + 1;
				$parent_node = O::multiply($text_offset, $to_divide, $parent_node, $parent_node_only);
				$selector_index--;
			}
		} else {
			print('$selector, $to_multiply, $parent_node, $parent_node_only: ');var_dump($to_add, $selector, $to_multiply, $parent_node, $parent_node_only);
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
				*		$offset_index = sizeof($offsets) - 1;
				*		while($offset_index > -1) {
				*			$parent_node = O::set($offsets[$offset_index], O::get($offsets[$offset_index], $parent_node) / $to_divide, $parent_node, $parent_node_only);
				*			$offset_index--;
		}*/
			$selector_matches = O::get_tagged($selector, $parent_node);
			$selector_matches_index = sizeof($selector_matches) - 1;
			while($selector_matches_index > -1) {
				$parent_node = O::set($selector_matches[$selector_matches_index][1], O::tagless($selector_matches[$selector_matches_index][0]) / $to_divide, $parent_node, $parent_node_only);
				$selector_matches_index--;
			}
			//O::set($selector, O::get($selector, $parent_node) - $to_divide, $parent_node);
		} elseif(is_array($selector)) {
			//print('$selector, $to_divide, $parent_node, $parent_node_only: ');var_dump($to_add, $selector, $to_divide, $parent_node, $parent_node_only);
			//O::fatal_error('array selector not handled in divide function');
			$selector_index = sizeof($selector) - 1;
			while($selector_index > -1) {
				$text_offset = $selector[$selector_index][1] + strpos($selector[$selector_index][0], '>') + 1;
				$parent_node = O::divide($text_offset, $to_divide, $parent_node, $parent_node_only);
				$selector_index--;
			}
		} else {
			print('$selector, $to_divide, $parent_node, $parent_node_only: ');var_dump($to_add, $selector, $to_divide, $parent_node, $parent_node_only);
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
			*		foreach($selector as $first_index => $first_value) { break; }
			*		if(is_array($first_value)) {
			*			$values = O::get($selector, $parent_node);
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
			*		foreach($selector as $first_index => $first_value) { break; }
			*		if(is_array($first_value)) {
			*			$values = O::get($selector, $parent_node);
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
			*		foreach($array as $index => $value) {
			*			foreach($value as $index2 => $value2) {
			*				if($value2[0] === 1 && $value2[1][0] === $from) {
			*					$array[$index][$index2][1][0] = $to;
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
				*		foreach($selector_matches as $index => $value) {
				*			$did_first = false;
				*			$counter = 0;
				*			foreach($value as $index2 => $value2) {
				*				if(!did_first) {
				*					$this->LOM[$index2][1][0] = $new_tag_name;
				*					foreach($this->context as $context_index => $context_value) {
				*						if(is_array($context_value[3])) {
				*							if(O::all_sub_entries_are_arrays($context_value[3])) {
				*								foreach($context_value[3] as $index3 => $value3) {
				*									foreach($value3 as $index4 => $value4) {
				*										if($index2 === $index4) {
				*											$this->context[$context_index][3][$index3][$index2][1][0] = $new_tag_name;
				*											continue;
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
				*			foreach($selector as $index => $value) {
				*				$did_first = false;
				*				$counter = 0;
				*				foreach($value as $index2 => $value2) {
				*					if(!$did_first) {
				*						$this->LOM[$index2][1][0] = $new_tag_name;
				*						$selector[$index2][1][0] = $new_tag_name;
				*						foreach($this->context as $context_index => $context_value) {
				*							if(is_array($context_value[3])) {
				*								if(O::all_sub_entries_are_arrays($context_value[3])) {
				*									foreach($context_value[3] as $index3 => $value3) {
				*										foreach($value3 as $index4 => $value4) {
				*											if($index2 === $index4) {
				*												$this->context[$context_index][3][$index3][$index2][1][0] = $new_tag_name;
				*												continue;
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
			//print('here927600<br />' . PHP_EOL);
			$counter = 0;
			foreach($selector as $index => $value) {
				//print('here927601<br />' . PHP_EOL);
				if(!$did_first) {
					//print('here927602<br />' . PHP_EOL);
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
			//print('here927603<br />' . PHP_EOL);
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

	function is_valid_tagname($variable) { // alias
		return O::valid_tagname($variable);
	}

	function valid_tagname($variable) {
		return !preg_match('/[^\w]/is', $variable);
	}

	function tag_name($variable) { // alias
		return O::get_tag_name($variable);
	}

	function tagname($variable) { // alias
		return O::get_tag_name($variable);
	}

	function get_tag_name($variable) {
		//print('$variable at start of get_tag_name: ');var_dump($variable);
		if(is_numeric($variable)) {
			//print('is_numeric($variable) in get_tag_name<br />' . PHP_EOL);
			$variable = (int)$variable;
			//print('$this->LOM[$variable]: ');var_dump($this->LOM[$variable]);exit(0);
			/*while($this->LOM[$variable][0] !== 1) { // tag
				*			$variable--;
				*			if($variable < 0) {
				*				return false;
		}
		}
		return $this->LOM[$variable][1][0];*/
			preg_match('/<(' . $this->tagname_regex . ')/is', substr($this->code, $variable), $matches);
			return $matches[1];
		} elseif(is_string($variable)) {
			//print('is_string($variable) in get_tag_name<br />' . PHP_EOL);
			//return substr($variable, strpos($variable, '<') + 1, strpos($variable, '>') - strpos($variable, '<') - 1);
			preg_match('/<(' . $this->tagname_regex . ')/is', $variable, $matches);
			//print('$matches in get_tag_name: ');var_dump($matches);
			return $matches[1];
		} elseif(is_array($variable)) {
			//print('is_array($variable) in get_tag_name<br />' . PHP_EOL);
			if(O::all_entries_are_arrays($variable)) {
				/*
					*			// assuming a DOM array (which would never be passed in)
					*			foreach($variable as $index => $value) {
					*				foreach($value as $first_index => $first_value) { break; }
					*				if(is_array($first_value)) {
					*					foreach($first_value as $first_index2 => $first_value2) { break; }
					*					if($first_value2 === 1) {
					*						return $first_value[1][0];
			}
			} elseif($first_value === 1) {
				return $value[1][0];
			}
			}*/
				if(sizeof($variable) > 1) {
					print('$variable in get_tag_name(): ');var_dump($variable);
					O::warning('unlike get() in which the coder may be expected to not be surprised by the format of the exported result based on this coder knowing the structure of the code being queried in terms of whether the data that is being queried for has nested data or not, the exported result from get_tag_name may be a surprising format in the case of an input variable in array format of length 1 since the coder may not know the length of the input array variable since it depends on the contents of the data and not its structure. exporting based on contents not structure is problematic but there is no data type other than array that makes sense for multiple exported results from get_tag_name() while it is, of course, useful to have exported results in string format in the case of the tagname of a single tag (even when the input variable is an array)');
					$tagnames = array();
					foreach($variable as $entry) {
						$tagnames[] = O::get_tag_name($entry[0]);
					}
					return $tagnames;
				}
				return O::get_tag_name($variable[0][0]);
			} else {
				return O::get_tag_name($variable[0]);
			}
		} else {
			print('$variable: ');var_dump($variable);
			O::fatal_error('unhandled variable type in get_tag_name');
		}
		//print('$variable at end of get_tag_name: ');var_dump($variable);
		return false;
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

	function tag_string($offset) { // alias
		return O::get_tag_string($offset);
	}

	//function get_tag_string($string, $offset, $offset_to_add = 0) {
	//function get_tag_string($offset, $offset_depths = false) {
	function get_tag_string($offset) {
		// get_tag_string does less than expand() and so each may have their place: get_tag_string should be more efficient but less complete
		$this->ensure_parent_indexes();
		if(isset($this->node_end_offsets[$offset])) {
			$end = $this->node_end_offsets[$offset];
			return O::fmem_intern_string(substr($this->code, $offset, $end - $offset + 1));
		}
		$expanded_LOM = O::expand($this->code, $offset, 0);
		return O::fmem_intern_string($expanded_LOM[0][0]);
	}

	function get_all_named_tags($string, $tagName, $offset = 0, $tagvalue = false, $matching_index = false, $required_attributes = array()) {
		O::fatal_error('get_all_named_tags has not been updated for the new code paradigm');
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
			*	//$string = substr($string, $offset); // bad
			*	$arrayOStrings = array();
			*	// it should be mentioned that "tags" such as CDATA regions will not be caught since we are only looking for words rather than non-spaces
			*	preg_match_all('/<(' . $this->tagname_regex . ')[\s>]/is', $string, $matches, PREG_OFFSET_CAPTURE);
			*	foreach($matches[0] as $index => $value) {
			*		$match = $value[0];
			*		$offset2 = $value[1];
			*		$tagName = $matches[1][$index][0];
			*		$object_string = O::get_tag_string($string, $tagName, $offset2);
			*		if($object_string !== false) {
			*			$arrayOStrings[] = array($object_string, $offset2);
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
		//print('here3645756860<br />' . PHP_EOL);
		while($index < sizeof($LOM)) {
			//print('here3645756861<br />' . PHP_EOL);
			// what about programming instructions and such?
			if($LOM[$index][0] === 1 && $LOM[$index][1][2] === 0) { // opening tag
				//print('here3645756862<br />' . PHP_EOL);
				//if($depth === 0) {
				if($depth <= 0) {
					//$tags = array_merge(O::get_tags_in_LOM($LOM, $index + 1), $tags);
					//print('here3645756863<br />' . PHP_EOL);
					//$saved_tagname = $LOM[$index][1][0];
					$saved_index = $index;
				}
				$depth++;
			} elseif($LOM[$index][0] === 1 && $LOM[$index][1][2] === 1) { // closing tag
				$depth--;
				//if($depth === 0 && $LOM[$index][1][0] === $saved_tagname) { // assumes even numbers of opening and closing tags and proper nesting
				//if($depth === 0) {
				if($depth <= 0) {
					$tags = array_merge($tags, array(O::to_string_offset_pair(array_slice($LOM, $saved_index, $index - $saved_index + 1))));
					$tags = array_merge($tags, O::get_tags_in_LOM($LOM, $saved_index + 1));
				} elseif($depth === -1) { // assumes even numbers of opening and closing tags and proper nesting
					break;
				}
			} elseif($LOM[$index][0] === 1 && $LOM[$index][1][2] === 2) { // self-closing tag
				//if($depth === 0) {
				if($depth <= 0) {
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
				//if($depth === 0) {
				if($depth <= 0) {
					$saved_index = $index;
				}
				$depth++;
			} elseif($LOM[$index][0] === 1 && $LOM[$index][1][2] === 1) { // closing tag
				$depth--;
				//if($depth === 0) {
				if($depth <= 0) {
					$tags = array_merge($tags, array(O::to_string_offset_pair(array_slice($LOM, $saved_index, $index - $saved_index + 1))));
				} elseif($depth === -1) { // assumes even numbers of opening and closing tags and proper nesting
					break;
				}
			} elseif($LOM[$index][0] === 1 && $LOM[$index][1][2] === 2) { // self-closing tag
				//if($depth === 0) {
				if($depth <= 0) {
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
				//if($depth === 0) { // assumes even numbers of opening and closing tags and proper nesting
				if($depth <= 0) { // assumes even numbers of opening and closing tags and proper nesting
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
			//$offset_depths = $this->offset_depths;
			$expanded_LOM = O::expand(false, $offset);
			$offset_depths = $expanded_LOM[3];
		}
		if(!isset($offset_depths[$offset]) ) {
			if($offset === 0) {
				return 0;
			} elseif($offset === strlen($this->code)) {
				return 0;
			} else {
				//print('$offset, $offset_depths: ');O::var_dump_full($offset, $offset_depths);
				//O::fatal_error('offset not in $offset_depths in get_parent_depth');
				// work from previous existing offset
				// may be counterintuitive to test against $offset_depths then use $this->offset_depths to get the previous offset but the previous offset we need may not be in the $offset_depths
				//if(sizeof($offset_depths) === 0) {
				//
				//} else {
				//reset($offset_depths);
				$previous_offset = 0;
				reset($this->offset_depths);
				$next_result = true;
				while($next_result !== false) {
					$current_offset = key($this->offset_depths);
					if($current_offset === null || $current_offset >= $offset) {
						break;
					}
					$previous_offset = $current_offset;
					$next_result = next($this->offset_depths);
				}
				$offset = $previous_offset;
				//}
			}
		}

		//print('$offset in depth(): ');var_dump($offset);
		//print('$offset, $offset_depths, $offset_depths[$offset] in depth(): ');var_dump($offset, $offset_depths, $offset_depths[$offset]);
		// this is a time consuming function worthy of optimization
		// maybe keep track of 0 depth points for given $code so that these 0 depth points can be skipped to?
		// I think probably go through $this->code once and mark all the depth points (at <) $this->offset_depths updated with new_() set() etc.
		//preg_match_all('/<[^>]+>/', $code, $matches);
		/*preg_match_all('/<[^>]+>/', substr($code, 0, $offset), $matches);
			*	//preg_match_all('/<[^>]+>/', substr($code, 0, $offset), $matches, PREG_OFFSET_CAPTURE);
			*	//$zero_offset = 0;
			*	//if(sizeof($this->zero_offsets[$offset_to_add]) > 0) {
			*	//	foreach($this->zero_offsets[$offset_to_add] as $potential_zero_offset => $true) {
			*	//		if($potential_zero_offset > $zero_offset && $potential_zero_offset <= $offset) {
			*	//			$zero_offset = $potential_zero_offset;
			*	//		}
			*	//	}
			*	//}
			*	//preg_match_all('/<[^>]+>/', substr($code, $zero_offset, $offset - $zero_offset), $matches, PREG_OFFSET_CAPTURE);
			*	$index = 0;
			*	$depth = 0;
			*	while($index < sizeof($matches[0])) {
			*		//if($matches[0][$index][0][1] === '/') { // closing tag
			*		if($matches[0][$index][1] === '/') { // closing tag
			*			$depth--;
			*			//if($depth < 0) { // shouldn't ever occur if expand() is working properly
			*			//	break;
			*			//}
			*			//if($depth === 0) {
			*			//	$this->zero_offsets[$offset_to_add][$matches[0][$index][1] + strlen($matches[0][$index][0])] = true;
			*			//}
	} else { // opening tag
		$depth++;
	}
	$index++;
	}
	return $depth;*/
		//print('$this->offset_depths in depth(): ');var_dump($this->offset_depths);
		//return $this->offset_depths[$offset];
		//if(!isset($offset_depths[$offset])) {
		//	return 0; // should only happen when there is no code (and therefore no offset depths set)
		//} else {
		//print('$offset_depths[$offset] in depth(): ');var_dump($offset_depths[$offset]);
		return isset($offset_depths[$offset]) ? $offset_depths[$offset] : 0;
		//}
	}

	//function expand($code = false, $offset = 0, $offset_to_add = 0, $offset_depths = false, $mode = 'lazy') {
	function expand($code = false, $offset = 0, $offset_to_add = 0, $for_writing = false) {
		//print('start of expand()<br />' . PHP_EOL);
		if(!$for_writing) {
			O::ensure_offset_depths();
		}
		// expand does more than get_tag_string() and so each may have their place: expand should be more complete but less efficient
		// mode (lazy or greedy) seems obsolete and may be a vestige or a prior way of thinking that didn't set offset depths for opening angle brackets and text positions
		// would it be better if this function always received an offset on the opening angle bracket < rather than looking for it? is this a reasonable expectation?
		//print('$code, $offset, $offset_to_add at start of expand(): ');var_dump($code, $offset, $offset_to_add);
		$greedy = false;
		if($offset + $offset_to_add === 0) {
			//print('expand-E001<br />' . PHP_EOL);
			if($for_writing) { // should only happen when writing the initial $this->offset_depths. expand() continues to be processed
				//print('expand-E002<br />' . PHP_EOL);
				$greedy = true;
			} else { // sort of a pseudo-container... (all the code)
				//print('expand-E003<br />' . PHP_EOL);
				if(isset($this->code[0]) && $this->code[0] === '<') { // handles the case of a tag that does not occupy the whole code in the 0 position
					//print('expand-E004<br />' . PHP_EOL);
				} else {
					//print('expand-E005<br />' . PHP_EOL);
					return array(array($this->code, 0), false, -1, $this->offset_depths);
				}
			}
			//O::fatal_error('would hopefully never be trying to expand on offset 0... or rather it has to be handled better such that both cases where a tag containing the whole document and code without such a tag are appropriately handled... three cases: containing <games>, first <game> at 0 offset, first <game> at another offset');
		} else {
			// When $code is a fragment (e.g. replace/new_ $new_value) and $for_writing is true, using $this->code[$offset + $offset_to_add] is wrong after the edit is already applied: the byte at the insert offset is often '<', which keeps $greedy false, the parser exits at depth 0, and trailing text depth markers (e.g. final newline) are omitted from get_offset_depths — breaking incremental offset_depths vs a full-document expand. Limit to $for_writing so other expand(fragment, …, false) paths keep prior behavior.
			if($code !== false && $code !== $this->code && $for_writing) {
				$greedy = true;
			} elseif(isset($this->code[$offset + $offset_to_add]) && $this->code[$offset + $offset_to_add] === '<') { // handles the case of a tag that does not occupy the whole code in the 0 position
				//print('expand-E006<br />' . PHP_EOL);
			} else {
				$greedy = true;
			}
		}
		//print('$greedy in expand(): ');var_dump($greedy);
		$cacheable_expand = ($code === false || $code === $this->code);
		if($cacheable_expand && isset($this->expands[$offset + $offset_to_add])) { // only cache expands against the full current code
			//print('using saved expand: ');var_dump($this->expands[$offset + $offset_to_add]);
			return $this->expands[$offset + $offset_to_add];
		}
		//print('calculating expand<br />' . PHP_EOL);
		if($code === false) {
			$code = $this->code;
		}
		$cacheable_expand = ($code === $this->code);
		if($offset_to_add === false) {
			$offset_to_add = 0;
		}
		/* if($offset >= strlen($code)) {
			*		return array(false, false, false);
	} */
		// $parsing_offset = $offset;
		// while(!isset($this->offset_depths[$parsing_offset])) {
		// 	$parsing_offset--;
		// }
		// $depth_to_add = $this->offset_depths[$parsing_offset];
		//$depth_to_add = O::depth($offset + $offset_to_add);
		$depth_to_add = O::depth($offset + $offset_to_add, $this->offset_depths); // tricky... whether to default to $this->offset_depths here or in depth... it's a complex question which is more efficient
		//preg_match('/\s+/s', substr($code, $offset), $opening_whitespace_matches, PREG_OFFSET_CAPTURE);
		// preg_match('/\s+/s', $code, $opening_whitespace_matches, PREG_OFFSET_CAPTURE, $offset);
		// //print('$opening_whitespace_matches: ');var_dump($opening_whitespace_matches);
		// //$opening_whitespace_length = 0;
		// if($opening_whitespace_matches[0][1] === $offset) {
		// 	$offset += strlen($opening_whitespace_matches[0][0]);
		// }

		//$expand_debug_counter = 0;
		//print('$offset, $code: ');var_dump($offset, $code);
		$parsing_offset = $offset;
		$parsing_depth = 0;
		$offset_of_matched_depth = NULL;
		$offset_depths = array();
		if(!isset($code[$offset])) {
			return array(array('', $offset), array('', $offset), false, $offset_depths);
		}
		if($code[$offset] === '<') { // do nothing and let the parser set the offset_depth

		} else {
			$offset_depths[$parsing_offset + $offset_to_add] = $parsing_depth + $depth_to_add;
		}
		//print('expand parser start<br />' . PHP_EOL);
		while($parsing_offset < strlen($code)) {
			//$expand_debug_counter++;
			//if($expand_debug_counter > 100) {
			//	O::fatal_error('$expand_debug_counter > 100');
			//}
			//print('expand parser while start<br />' . PHP_EOL);
			//print('$parsing_depth: ');var_dump($parsing_depth);
			//print('$parsing_offset: ');var_dump($parsing_offset);
			//print('$parsing_offset, $code[$parsing_offset]: ');var_dump($parsing_offset, $code[$parsing_offset]);
			//print('$parsing_offset, $parsing_depth + $depth_to_add,  substr($code, $parsing_offset, 10): ');var_dump($parsing_offset, $parsing_depth + $depth_to_add, substr($code, $parsing_offset, 10));
			if($code[$parsing_offset] === '<') {
				//print('expand parser open angle bracket<br />' . PHP_EOL);
				$offset_depths[$parsing_offset + $offset_to_add] = $parsing_depth + $depth_to_add;
				if($this->must_check_for_self_closing && $code[strpos($code, '>', $parsing_offset + 1) - 1] === '/') { // self-closing
					//print('expand parser self-closing<br />' . PHP_EOL);
					$parsing_offset += strpos($code, '>', $parsing_offset + 1) - $parsing_offset + 1;
					if(!$greedy && $parsing_depth === 0 && $code[$offset] === '<') {
						$offset_of_matched_depth = $parsing_offset;
						break;
					}
				} elseif($this->must_check_for_doctype && substr($code, $parsing_offset, 9) === '<!DOCTYPE') { // doctype
					//print('expand parser doctype<br />' . PHP_EOL);
					$parsing_offset += strpos($code, '>', $parsing_offset + 9) - $parsing_offset + 1;
				} elseif($this->must_check_for_non_parsed_character_data && substr($code, $parsing_offset, 9) === '<![CDATA[') { // non-parsed character data
					//print('expand parser non-parsed character data<br />' . PHP_EOL);
					$parsing_offset += strpos($code, ']]>', $parsing_offset + 9) - $parsing_offset + 3;
				} elseif($this->must_check_for_comment && substr($code, $parsing_offset, 4) === '<!--') { // comment
					//print('expand parser comment<br />' . PHP_EOL);
					$parsing_offset += strpos($code, '-->', $parsing_offset + 3) - $parsing_offset + 3;
				} elseif($this->must_check_for_programming_instruction && substr($code, $parsing_offset, 2) === '<?') { // programming instruction
					//print('expand parser programming instruction<br />' . PHP_EOL);
					$parsing_offset += strpos($code, '?>', $parsing_offset + 2) - $parsing_offset + 2;
				} elseif($this->must_check_for_ASP && substr($code, $parsing_offset, 2) === '<%') { // ASP
					//print('expand parser ASP<br />' . PHP_EOL);
					$parsing_offset += strpos($code, '%>', $parsing_offset + 2) - $parsing_offset + 2;
				} elseif($code[$parsing_offset + 1] === '/') { // closing tag
					//print('expand parser closing tag<br />' . PHP_EOL);
					//$parsing_offset_depths[$parsing_offset + $parsing_offset_to_add]--;
					if(!$greedy && $parsing_depth === 0) {
						//print('expand parser end for text<br />' . PHP_EOL);
						$offset_of_matched_depth = $parsing_offset;
						break;
					}
					$parsing_offset += strpos($code, '>', $parsing_offset + 2) - $parsing_offset + 1;
					// while($parsing_offset < strlen($code)) {
					// 	$parsing_offset++;
					// 	if($code[$parsing_offset] === '>') {
					// 		$parsing_offset++;
					// 		break;
					// 	}
					// }
					$parsing_depth--;
					//if($parsing_depth === 0) {
					//if(!$for_writing && $parsing_depth === 0) {
					if(!$greedy && $parsing_depth === 0) {
						//print('expand parser end for matched depth<br />' . PHP_EOL);
						$offset_of_matched_depth = $parsing_offset;
						break;
					}
				} else { // opening tag
					//print('expand parser opening tag<br />' . PHP_EOL);
					$parsing_offset += strpos($code, '>', $parsing_offset + 1) - $parsing_offset + 1;
					// while($parsing_offset < strlen($code)) {
					// 	$parsing_offset++;
					// 	if($code[$parsing_offset] === '>') {
					// 		$parsing_offset++;
					// 		break;
					// 	}
					// }
					$parsing_depth++;
				}
				if($parsing_offset < strlen($code) && $code[$parsing_offset] === '<') { // do nothing and let the parser set the offset_depth

				} else { // text
					$offset_depths[$parsing_offset + $offset_to_add] = $parsing_depth + $depth_to_add;
				}
				//print('expand parser end open angle bracket<br />' . PHP_EOL);
				continue;
			}
			//print('expand parser end while<br />' . PHP_EOL);
			$parsing_offset++;
		}
		// if($for_writing) {
		// 	$offset_of_matched_depth = strlen($this->code);
		// }
		//print('expand parser end<br />' . PHP_EOL);

		/* $depth_to_match = O::depth($offset + $offset_to_add);
			*	//print('$code, $offset, $offset_to_add, $depth_to_match, $offset_depths at start of expand: ');O::var_dump_full($code, $offset, $offset_to_add, $depth_to_match, $offset_depths);
			*	// if($offset_depths == false) {
			*	// 	//print('creating $offset_depths in expand()<br />' . PHP_EOL);
			*	// 	// offset_depths can be off by 1 (probably -1) when inserting into text rather than at a set offset_depth but this isn't a problem since offset_depths stays inside expand() and the error doesn't propagate
			*		// should be able to be passed offset_depths here instead of always regenerating... (2024-07-12)
			*		if($code === $this->code) {
			*			$offset_depths = $this->offset_depths;
	} else {
		//print('$offset, $offset_to_add, O::depth($offset + $offset_to_add) in expand(): ');var_dump($offset, $offset_to_add, O::depth($offset + $offset_to_add));
		//$offset_depths = O::get_offset_depths(substr($code, $offset), $offset + $offset_to_add, O::depth($offset + $offset_to_add));
		$offset_depths = O::get_offset_depths($code, 0, O::depth($offset_to_add));
		//print('$code, $offset, $offset_to_add, $offset_depths in expand(): ');O::var_dump_full($code, $offset, $offset_to_add, $offset_depths);
	}
	// }
	//$depth_to_match = O::depth($offset + $offset_to_add + strpos($code, '<', $offset));
	//$offset_of_opening_angle_bracket = strpos($code, '<', $offset);
	//if($code[$offset + $opening_whitespace_length] === '<') { // opening angle bracket
	//	// aaaaa<bb><cc><dd>ee</dd></cc></bb>
	//	//          ^
	//	if($code[$offset + $opening_whitespace_length + 1] === '/') { // closing tag
	//		$depth_to_match = false;
	//		//$parent_depth?? not so good to not have it, but I'm not sure we can always deduce it...
	//		//$matching_text = true;
	//	} else {
	//		$depth_to_match = O::depth($offset_to_add + $offset + $opening_whitespace_length, $offset_depths);
	//		//$parent_depth = $depth_to_match - 1;
	//		$parent_depth = $depth_to_match - 1;
	//		//$matching_text = false;
	//	}
	//} else {
	//	// aaaaa<bb><cc><dd>ee</dd></cc></bb>
	//	//                  ^
	//	$depth_to_match = O::depth($offset_to_add + strpos($code, '<', $offset), $offset_depths);
	//	//$parent_depth = $depth_to_match;
	//	$parent_depth = $depth_to_match - 1;
	//	//$matching_text = true;
	//}
	//O::get_offset_depths($code);
	//print('$offset_depths before finding $depth_to_match in expand(): ');var_dump($offset_depths);
	//$look_for_offset_of_less_than_depth = true;
	// if(isset($offset_depths[$offset + $offset_to_add])) {
	// 	$depth_to_match = $offset_depths[$offset + $offset_to_add];
	// 	// if($code[$offset] === '<') {
	// 	// 	$look_for_offset_of_less_than_depth = false;
	// 	// }
	// } elseif(sizeof($offset_depths) === 1) { // may be counterintuitive to check for 1 before 0 and be flipping here, but I'm sure there's an interesting mathematical implication to the need to do so related to putting opening tag starts as well as text in the offset_depths
	// 	$depth_to_match = current($offset_depths); // take the first (only) entry
	// } elseif(sizeof($offset_depths) === 0) {
	// 	$depth_to_match = 0;
	// } else {
	// 	//$depth_to_match = $offset_depths[$offset];
	// 	//print('first current($offset_depths): ');var_dump(current($offset_depths));
	// 	$first_entry = current($offset_depths);
	// 	next($offset_depths);
	// 	//print('second current($offset_depths): ');var_dump(current($offset_depths));
	// 	$second_entry = current($offset_depths);
	// 	if($second_entry < $first_entry) {
	// 		$depth_to_match = $first_entry;
	// 	} else { // take the second entry
	// 		$depth_to_match = $second_entry;
	// 	}
	// }
	if($this->debug && !is_numeric($depth_to_match)) {
		print('$depth_to_match, $offset_depths in expand(): ');var_dump($depth_to_match, $offset_depths);
		O::fatal_error('!is_numeric($depth_to_match). fix this from happening then comment this debug block out?');
	}

	//print('$depth_to_match in expand: ');O::var_dump_full($depth_to_match);
	//print('$code, $offset, $offset_to_add, $depth_to_match, $offset_depths in expand: ');O::var_dump_full($code, $offset, $offset_to_add, $depth_to_match, $offset_depths);
	//print('$this->offset_depths in expand: ');O::var_dump_full($this->offset_depths);
	if($depth_to_match === false) { // don't go through the offsets array
		//$offset_of_matched_depth = $offset + $opening_whitespace_length;
		//$offset_of_less_than_depth = $offset_of_matched_depth = $offset_to_add + strlen($code);
		//$offset_of_less_than_depth = $offset_to_add + strlen($code);
		$offset_of_matched_depth = $offset_to_add + strlen($code);
	} else {
		$pointer_got_to_offset = false;
		//$offset_of_last_depth_match = false;
		reset($offset_depths);
		$next_result = true;
		//print('here280<br />' . PHP_EOL);
		//	while($next_result !== false) {
		//		//print('here281<br />' . PHP_EOL);
		//		//print('current($offset_depths): ');var_dump(current($offset_depths));
		//		if($pointer_got_to_offset) {
		//			//print('pointer_got_to_offset<br />' . PHP_EOL);
		//			//print('current($offset_depths): ');var_dump(current($offset_depths));
		//			if(current($offset_depths) < $depth_to_match) {
		//	//			//print('here282.5<br />' . PHP_EOL);
		//	//			//$offset_of_matched_depth = key($this->offset_depths);
		//				break;
		//			}
		//			//print('here283<br />' . PHP_EOL);
		//			if(current($offset_depths) === $depth_to_match) {
		//				//print('here284<br />' . PHP_EOL);
		//				$offset_of_matched_depth = key($offset_depths);
		//	//			if($depth_to_match === 0) { // raw tags wihtout a container tag for the whole XML file
		//				if($mode === 'lazy') {
		//					break;
		//				}
		//	//			}
		//				//if($offset_of_opening_angle_bracket === $offset) {
		//				//	break;
		//				//}
		//			}
		//		} else {
		//			//print('here285<br />' . PHP_EOL);
		//			//if(key($offset_depths) === $offset_of_opening_angle_bracket + $offset_to_add) {
		//			//if(key($offset_depths) === $offset + $opening_whitespace_length) {
		//			//if(key($offset_depths) >= $offset) {
		//			if(key($offset_depths) >= $offset_to_add + $offset) {
		//				//print('here286<br />' . PHP_EOL);
		//				$pointer_got_to_offset = true;
		//	//			$offset_of_matched_depth = key($offset_depths);
		//			}
		//		}
		//		//print('here287<br />' . PHP_EOL);
		//		$next_result = next($offset_depths);
		//	}

		//	if($mode === 'lazy') {
		//		while($next_result !== false) {
		//			if($pointer_got_to_offset) {
		//				if(current($offset_depths) === $depth_to_match) {
		//					$offset_of_matched_depth = key($offset_depths);
		//					break;
		//				}
		//			} else {
		//				if(key($offset_depths) >= $offset + $offset_to_add) {
		//					$pointer_got_to_offset = true;
		//				}
		//			}
		//			$next_result = next($offset_depths);
		//		}
		//	} else { // greedy by default??
		// while($next_result !== false) {
		// 	if($pointer_got_to_offset) {
		// 		if($look_for_offset_of_less_than_depth) {
		// 			if(current($offset_depths) < $depth_to_match) {
		// 				$offset_of_matched_depth = key($offset_depths);
		// 				//$offset_of_less_than_depth = key($offset_depths);
		// 				break;
		// 			}
		// 		} else {
		// 			if(current($offset_depths) === $depth_to_match) {
		// 				$offset_of_matched_depth = key($offset_depths);
		// 				break;
		// 			}
		// 		}
		// 	} else {
		// 		if(key($offset_depths) >= $offset + $offset_to_add) {
		// 			$pointer_got_to_offset = true;
		// 		}
		// 	}
		// 	$next_result = next($offset_depths);
		// }

		//print('ex001<br />' . PHP_EOL);
		while($next_result !== false) {
			//print('ex002<br />' . PHP_EOL);
			if($pointer_got_to_offset) {
				//print('ex003<br />' . PHP_EOL);
				if(current($offset_depths) === $depth_to_match) {
					//print('ex004<br />' . PHP_EOL);
					$offset_of_matched_depth = key($offset_depths);
					// now backtrack to end of closing tag
					//print('$code[$offset_of_matched_depth]1: ');var_dump($code[$offset_of_matched_depth]);
					$offset_of_matched_depth--;
					while($code[$offset_of_matched_depth] !== '>') {
						//print('$code[$offset_of_matched_depth]2: ');var_dump($code[$offset_of_matched_depth]);
						//print('ex005<br />' . PHP_EOL);
						$offset_of_matched_depth--;
	}
	//print('ex006<br />' . PHP_EOL);
	//$offset_of_matched_depth++;
	break;
	}
	} else {
		//print('ex007<br />' . PHP_EOL);
		//if(key($offset_depths) >= $offset + $offset_to_add) {
		if(key($offset_depths) >= $offset) {
			//print('ex008<br />' . PHP_EOL);
			$pointer_got_to_offset = true;
	}
	}
	//print('ex009<br />' . PHP_EOL);
	$next_result = next($offset_depths);
	}
	//}
	}
	//print('$code[14]: ');var_dump($code[14]);
	//print('$code[15]: ');var_dump($code[15]);
	//print('$code[16]: ');var_dump($code[16]);
	//print('$code[17]: ');var_dump($code[17]);
	//print('$code[18]: ');var_dump($code[18]);
	//print('$code[19]: ');var_dump($code[19]);

	//print('$code[117]: ');var_dump($code[117]);
	//print('$code[118]: ');var_dump($code[118]);
	//print('$code[119]: ');var_dump($code[119]);
	//print('$code[120]: ');var_dump($code[120]);
	//print('$code[121]: ');var_dump($code[121]);
	//print('$code[122]: ');var_dump($code[122]);
	//print('here288<br />' . PHP_EOL);
	//if($next_result === false) { // the whole code
	//	$offset_of_matched_depth = key($offset_depths);
	//} elseif($offset_of_matched_depth === NULL) { // the whole code
	//	//print('here289<br />' . PHP_EOL);
	//	//$offset_of_matched_depth = strlen($this->code);
	//	$offset_of_matched_depth = $offset_to_add + strlen($code);
	//	//$offset_of_matched_depth = strlen($code);
	//}
	//if($look_for_offset_of_less_than_depth) {
	//	if($offset_of_less_than_depth === NULL) { // assume we are in a tag?
	//		$offset_of_less_than_depth = $offset;
	//	}
	//} else { */
		if($offset_of_matched_depth === NULL) { // assume we are in a tag?
			//print('forcing $offset_of_matched_depth since it is null<br />' . PHP_EOL);
			//$offset_of_matched_depth = $offset;
			//$offset_of_matched_depth = strlen($code) - 1;
			$offset_of_matched_depth = strlen($code); // since we wrote the parser
		}
		//}
		//print('$depth_to_match, $offset_of_less_than_depth in expand(): ');var_dump($depth_to_match, $offset_of_less_than_depth);
		//print('$depth_to_match, $offset_of_matched_depth, substr($code, $offset_of_matched_depth - 40, 80) in expand(): ');var_dump($depth_to_match, $offset_of_matched_depth, substr($code, $offset_of_matched_depth - 40, 80));

		// probably rare to have whitespace at the end of a value in a tag but also probably we'll need to account for that
		//print('$offset, $opening_whitespace_length, $offset_of_matched_depth, $matching_text in expand: ');O::var_dump_full($offset, $opening_whitespace_length, $offset_of_matched_depth, $matching_text);
		//else {
		//		$contained_string = substr($code, $offset + $opening_whitespace_length, $offset_of_matched_depth - $offset_to_add - $offset - $opening_whitespace_length);
		//}
		//print('$offset, $depth_to_match, $offset_of_matched_depth before creating $full_string in expand(): ');var_dump($offset, $depth_to_match, $offset_of_matched_depth);
		//print('$offset, $offset_of_matched_depth before creating $full_string in expand(): ');var_dump($offset, $offset_of_matched_depth);
		//$full_string = substr($code, $offset + $opening_whitespace_length, $offset_of_matched_depth - $offset - $opening_whitespace_length);
		//$full_string = substr($code, $offset, $offset_of_matched_depth - $offset - $offset_to_add);
		//$full_string = substr($code, $offset, $offset_of_matched_depth - $offset + 1);
		$full_string = substr($code, $offset, $offset_of_matched_depth - $offset); // since we wrote the parser
		//print('initial $full_string: ');var_dump($full_string);
		//$full_string = substr($code, $offset, $offset_of_less_than_depth - $offset);
		/*if($full_string[0] === '<') {
			*		if($full_string[1] === '/') { // closing tag
			* // 			if($this->debug) {
			* // 				print('$full_string: ');var_dump($full_string);
			* // 				O::fatal_error('not sure what to do when $full_string starts with a closing tag in expand()');
			* // 			}
			*			$full_string = '';
	} elseif($this->must_check_for_self_closing && $full_string[strpos($full_string, '>', 1) - 1] === '/') { // self-closing tag
		//print('self-closing tag at position: ' . $position . '<br />' . PHP_EOL);
		$full_string = substr($full_string, 0, strpos($full_string, '>', 1) + 1);
	} elseif($this->must_check_for_doctype && (substr($full_string, 1, 8) === '!DOCTYPE' || substr($full_string, 1, 8) === '!doctype')) { // doctype
		//print('non-parsed character data at position: ' . $position . '<br />' . PHP_EOL);
		$full_string = substr($full_string, 0, strpos($full_string, '>', 1) + 1);
	} elseif($this->must_check_for_non_parsed_character_data && substr($full_string, 1, 8) === '![CDATA[') { // non-parsed character data
		//print('non-parsed character data at position: ' . $position . '<br />' . PHP_EOL);
		$full_string = substr($full_string, 0, strpos($full_string, ']]>', 1) + 3);
	} elseif($this->must_check_for_comment && substr($full_string, 1, 3) === '!--') { // comment
		//print('comment at position: ' . $position . '<br />' . PHP_EOL);
		$full_string = substr($full_string, 0, strpos($full_string, '-->', 1) + 3);
	} elseif($this->must_check_for_programming_instruction && $full_string[1] === '?') { // programming instruction
		//print('programming instruction at position: ' . $position . '<br />' . PHP_EOL);
		$full_string = substr($full_string, 0, strpos($full_string, '?>', 1) + 2);
	} elseif($this->must_check_for_ASP && $full_string[1] === '%') { // ASP
		//print('ASP at position: ' . $position . '<br />' . PHP_EOL);
		$full_string = substr($full_string, 0, strpos($full_string, '%>', 1) + 2);
	}// else { // opening tag
	//	//print('opening tag at position: ' . $position . '<br />' . PHP_EOL);
	//	$this->offset_depths[$position] = $depth;
	//	$depth++;
	//}
	}*/
		if($full_string === false) {
			$full_string = '';
		} elseif($full_string === NULL) {
			$full_string = '';
		}
		//$full_string_offset_depths = O::offset_depths($full_string);
		//print('$full_string_offset_depths before contained_string in expand: ');var_dump($full_string_offset_depths);
		//	if(sizeof($full_string_offset_depths) > 0) {
		//		print('contained_string01<br />
		//');
		//$pointer_got_into_contents = true;
		/*reset($full_string_offset_depths);
			*		$next_result = true;
			*		//$contents_end_position = strlen($full_string);
			*		while($next_result !== false) {
			*			//if($pointer_got_into_contents) {
			*			//	if(current($full_string_offset_depths) < 1) {
			*			//		$contents_end_position = key($full_string_offset_depths);
			*			//		break;
			*			//	}
			*			//} else {
			*				if(current($full_string_offset_depths) === 1) {
			*			//		$pointer_got_into_contents = true;
			*					$contents_start_position = key($full_string_offset_depths);
			*					break;
	}
	//}
	$next_result = next($full_string_offset_depths);
	}*/
		if($for_writing) {

		} else {
			preg_match('/<[^\/][^>]{0,}>/is', $full_string, $opening_tag_matches, PREG_OFFSET_CAPTURE);
			// if(strlen($opening_tag_matches[0][0]) === 0) { // no opening tag but a closing tag
			// 	preg_match('/<\/[^>]{1,}>/is', $full_string, $closing_tag_matches, PREG_OFFSET_CAPTURE);
			// 	if($closing_tag_matches[0][1] + strlen($closing_tag_matches[0][0]) === strlen($full_string)) {
			// 		$full_string = substr($full_string, 0, $closing_tag_matches[0][1]);
			// 	}
			// }
			if($this->debug) {
				$full_string_is_tag = (strlen($full_string) > 0 && $full_string[0] === '<');
				if($full_string_is_tag) {
					preg_match('/\s+/is', $full_string, $space_matches, PREG_OFFSET_CAPTURE);
					if(isset($space_matches[0][1]) && $space_matches[0][1] === 0) {
						print('$full_string in expand(): ');var_dump($full_string);
						O::fatal_error('$full_string has whitespace');
					}
					$reverse_full_string = strrev($full_string);
					preg_match('/\s+/is', $reverse_full_string, $space_matches, PREG_OFFSET_CAPTURE);
					if(isset($space_matches[0][1]) && $space_matches[0][1] === 0) {
						print('$full_string in expand(): ');var_dump($full_string);
						O::fatal_error('$reverse_full_string has whitespace');
					}
					$opening_tags_count = O::get_number_of_opening_tags($full_string);
					$closing_tags_count = O::get_number_of_closing_tags($full_string);
					if($opening_tags_count !== $closing_tags_count) {
						print('$opening_tags_count, $closing_tags_count, $full_string: ');var_dump($opening_tags_count, $closing_tags_count, $full_string);
						O::fatal_error('$opening_tags_count !== $closing_tags_count in $full_string');
					}
				}
			}
			$contents_start_position = 0;
			if(isset($opening_tag_matches[0][1])) {
				$contents_start_position = $opening_tag_matches[0][1] + strlen($opening_tag_matches[0][0]);
			}
			//	if(substr_count($full_string, '</') * 2 < substr_count($full_string, '<')) { // for (probably rare) cases when expand() is fed strings that are missing closing tags to properly close in which case we wouldn't want to assume one needs to be removed
			//		$contents_end_position = strlen($full_string);
			//	} else {
			$rev_full_string = strrev($full_string);
			$rev_closing_position = strpos($rev_full_string, '/<');
			if($rev_closing_position === false) {
				$contents_end_position = strlen($full_string);
			} else {
				$contents_end_position = strlen($full_string) - $rev_closing_position - 2;
			}
			//	}
			$contained_string = substr($full_string, $contents_start_position, $contents_end_position - $contents_start_position);
			$contained_string_offset = $contents_start_position + $offset + $offset_to_add;
			//print('$contents_start_position, $contents_end_position, $contained_string, $contained_string_offset: ');var_dump($contents_start_position, $contents_end_position, $contained_string, $contained_string_offset);
			//	} else {
			//		print('contained_string09<br />
			//');
			//		$contained_string = substr($full_string, $offset);
			//		$contained_string_offset = $offset + $offset_to_add;
			//	}
			if($contained_string === false) {
				$contained_string = '';
			} elseif($contained_string === NULL) {
				$contained_string = '';
			}
			/*$contained_string = substr($code, $offset + $opening_whitespace_length, $offset_of_matched_depth - $offset - $opening_whitespace_length);
				*		print('$matching_text in expand(): ');var_dump($matching_text);
				*		if($matching_text) {
				*			if(strpos($full_string, '<') !== false) {
				*				$contained_string_offset = $offset + strpos($full_string, '>') + 1 + $offset_to_add;
		} else {
			$contained_string_offset = $offset + $offset_to_add;
		}
		} else {
			if($contained_string[0] !== '<' || ($contained_string[0] === '<' && $contained_string[1] === '/')) {
				$contained_string = substr($contained_string, 0, strpos($contained_string, '<'));
		}
		$contained_string_offset = $offset + $offset_to_add + $offset_of_macthed_depth;
		}*/

			//if($matching_text) {
			//print('$contained_string before problem: ');var_dump($contained_string);
			//if($contained_string[0] !== '<') {

			//if(strlen($contained_string) > 0 && $contained_string[0] !== '<') {
			/*if(substr_count($contained_string, '<') === 2 && substr_count($contained_string, '>') === 2 && substr_count($contained_string, '/') === 1 && $contained_string[0] === '<' && $contained_string[1] !== '/') {
				*			$contained_string = O::tagless($contained_string);
		} elseif($contained_string[0] !== '<' || ($contained_string[0] === '<' && $contained_string[1] === '/')) {
			$contained_string = substr($contained_string, 0, strpos($contained_string, '<'));
		}*/
			//print('$contained_string after problem: ');var_dump($contained_string);
			//$contained_string = substr($code, $offset + $opening_whitespace_length, $offset_of_matched_depth - $offset - $opening_whitespace_length - $offset_to_add);
			//if($contained_string[strlen($contained_string) - 1] === '<' && $offset_of_matched_depth > $offset_of_opening_angle_bracket) { // hacks upon hacks. what is this intended to fix?
			//	$contained_string = substr($contained_string, 0, strlen($contained_string) - 1);
			//}
			//print('$contained_string before strrev: ');var_dump($contained_string);
			//	$reversed_contained_string = strrev($contained_string);
			//	preg_match('/\s+/s', $reversed_contained_string, $closing_whitespace_matches, PREG_OFFSET_CAPTURE);
			//	if(isset($closing_whitespace_matches[0][1]) && $closing_whitespace_matches[0][1] === 0) {
			//		$closing_whitespace_length = strlen($closing_whitespace_matches[0][0]);
			//		if($closing_whitespace_length > 0) {
			//			$contained_string = strrev(substr($reversed_contained_string, $closing_whitespace_length));
			//		}
			//	} else {
			//		$closing_whitespace_length = 0;
			//	}
			//print('$contained_string after strrev: ');var_dump($contained_string);
		}
		//print('$opening_whitespace_length, $offset, $offset_to_add, $offset_of_matched_depth, $closing_whitespace_length at end of expand(): ');var_dump($opening_whitespace_length, $offset, $offset_to_add, $offset_of_matched_depth, $closing_whitespace_length);
		//print('$opening_whitespace_length, $offset, $offset_to_add, $offset_of_matched_depth at end of expand(): ');var_dump($opening_whitespace_length, $offset, $offset_to_add, $offset_of_matched_depth);
		//print('$offset, $offset_to_add, $offset_of_less_than_depth at end of expand(): ');var_dump($offset, $offset_to_add, $offset_of_less_than_depth);
		//print('$offset, $offset_to_add, $offset_of_matched_depth at end of expand(): ');var_dump($offset, $offset_to_add, $offset_of_matched_depth);
		//$new_LOM[] = array($contained_string, $offset + strlen($contained_string) + $offset_to_add);
		//$full_string = substr($code, 0, $offset + $opening_whitespace_length);
		$new_LOM = array(array($full_string, $offset + $offset_to_add)); // full string
		//$new_LOM[] = array($contained_string, $offset_to_add + $offset + $opening_whitespace_length); // contained string
		if($for_writing) {
			$new_LOM[] = false; // it's just the full code
		} elseif($full_string === $contained_string) {
			$new_LOM[] = false; // it's just text
		} else {
			$new_LOM[] = array($contained_string, $contained_string_offset); // contained string
		}
		//$parent_depth = O::get_parent_depth($contained_string_offset);
		$parent_depth = O::get_parent_depth($offset + $offset_to_add, $offset_depths);
		if($this->debug && $parent_depth < -1) {
			print('$parent_depth: ');var_dump($parent_depth);
			print('$code, $offset, $offset_to_add, $offset_depths in expand()2: ');O::var_dump_full($code, $offset, $offset_to_add, $offset_depths);
			O::fatal_error('$parent_depth < -1');
		}
		$new_LOM[] = $parent_depth; // parent depth for use in scope matching
		$new_LOM[] = $offset_depths; // parent depth for use in scope matching
		//print('substr($code, $offset, 100): ');var_dump(substr($code, $offset, 100));
		//print('$new_LOM at end of expand(): ');var_dump($new_LOM);
		if($cacheable_expand && $offset + $offset_to_add !== 0) {
			$this->expands[$offset + $offset_to_add] = $new_LOM;
		}
		return $new_LOM;
	}

	function string_to_LOM($code, $start_index = false, $offset_to_add = 0, $offset_of_code = false) { // alias
		return O::generate_LOM($code, $start_index, $offset_to_add, $offset_of_code);
	}

	function LOM($code, $start_index = false, $offset_to_add = 0, $offset_of_code = false) { // alias
		return O::generate_LOM($code, $start_index, $offset_to_add, $offset_of_code);
	}

	function generate_LOM($code, $start_index = false, $offset_to_add = 0, $offset_of_code = false) {
		return $this->code; // LOM is more of an abstract concept now hehe?
		// documentation
		/*
			*	0 => node type: text or tag; 0 = text, 1 = tag
			*	1 => text string if node type is text, tag array if node type is tag
			*		0 => tag name
			*		1 => attributes array; an associative array
			*		2 => tag type; 0 = opening, 1 = closing, 2 = self-closing, 3 = DOCTYPE, 4 = CDATA, 5 = comment, 6 = programming instruction, 7 = ASP
			*		3 => block tag; true or false
			*	2 => offset
			*/
		// new documentation
		/*
			*	0 => text string
			*	1 => offset
			*	2 => node array
			*		0 => node type: text or tag; 0 = text, 1 = tag
			*		1 => tag array (if it's a tag)
			*			0 => tag name
			*			1 => attributes array; an associative array
			*			2 => tag type; 0 = opening, 1 = closing, 2 = self-closing, 3 = DOCTYPE, 4 = CDATA, 5 = comment, 6 = programming instruction, 7 = ASP
			*			3 => block tag; true or false
			*	3 => offset depths?
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
		//print('5054<br />' . PHP_EOL);exit(0);
		while($offset < strlen($code)) {
			//print('5055<br />' . PHP_EOL);
			if($parsing_tag) {
				//print('5056<br />' . PHP_EOL);
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
				//print('5057<br />' . PHP_EOL);
				if($code[$offset] === '<') {
					$LOM[$index_counter] = array(0, $code_piece, $saved_offset + $offset_to_add);
					$saved_offset = $offset;
					$index_counter++;
					$offset++;
					if(substr($code, $offset, 8) === '![CDATA[') { // non-parsed character data
						//print('5058<br />' . PHP_EOL);
						$offset += 8;
						$code_piece = '<![CDATA[';
						while($offset < strlen($code)) {
							//print('5059<br />' . PHP_EOL);
							if(substr($code, $offset, 3) === ']]>') {
								//print('her3287394560845069<br />' . PHP_EOL);
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
						//print('5060<br />' . PHP_EOL);
						//print(substr($code, $offset));
						$offset += 3;
						$code_piece = '<!--';
						while($offset < strlen($code)) {
							//print('5061<br />' . PHP_EOL);
							if(substr($code, $offset, 3) === '-->') {
								//print('her3287394560845070<br />' . PHP_EOL);
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
						//print('5062<br />' . PHP_EOL);
						$offset++;
						$code_piece = '<?';
						while($offset < strlen($code)) {
							//print('5063<br />' . PHP_EOL);
							if(substr($code, $offset, 2) === '?>') {
								//print('her3287394560845071<br />' . PHP_EOL);
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
						//print('5064<br />' . PHP_EOL);//exit(0);
						print('ASP...' . substr($code, $offset));
						$offset++;
						$code_piece = '<%';
						while($offset < strlen($code)) {
							//print('5065<br />' . PHP_EOL);
							if(substr($code, $offset, 2) === '%>') {
								//print('her3287394560845072<br />' . PHP_EOL);
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
						//print('5066<br />' . PHP_EOL);
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

		//print('5067<br />' . PHP_EOL);//exit(0);
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
					//print('here4068950697-80<br />' . PHP_EOL);
					if($parsed_tag_name) {
						//print('here4068950697-81<br />' . PHP_EOL);
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
						//print('here4068950697-82<br />' . PHP_EOL);
						$parsed_tag_name = true;
						/*
							*					'!doctype' => 'parse_doctype',
							*					'?' => 'parse_php',
							*					'?php' => 'parse_php',
							*					'%' => 'parse_asp',
							*					'style' => 'parse_style',
							*					'script' => 'parse_script'
							*/

						// concerning these partial (very limited) parsings of special stuff like programming instructions and scripts from different language than HTML; it shouldn't be a problem as long as they do not
						// contain reflexive code (for example a PHP string like ...... '[question-mark]>' (I can't actually write it because notepad++'s parser busts into comments to find ends of programming instructions apparently...)
						if(substr($tag, $offset, 8) === '!doctype' || substr($tag, $offset, 8) === '!DOCTYPE') {
							//print('here4068950697-83<br />' . PHP_EOL);
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
						//print('marked a tag as a block<br />' . PHP_EOL);
						$tag_array[3] = true;
					}
					//$tag_name_offset = $tag_name_matches[0][1];
					//$new_tag .= $tag_name;
					/*if($strlen_tag_name == 0 || (!$parsing_end_tag && $tag_name_offset !== 1) || ($parsing_end_tag && $tag_name_offset !== 2)) {
						*						var_dump($strlen_tag_name, $parsing_end_tag, $tag_name_offset, $tag_name_offset);
						*						print('tag_name: ' . $tag_name . ' was problematically identified from tag: ' . $tag . ' 2897497591');exit(0);
					} else {*/
					$offset += $strlen_tag_name;
					continue;
					//}
					//print('here4068950697-84<br />' . PHP_EOL);
					}
					$offset++;
				}
				// I suppose we could sort attributes as desired here
				//print('here4068950697-85<br />' . PHP_EOL);
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

	function sv($name, $selector) { // alias
		return O::set_variable($name, $selector);
	}

	function s_v($name, $selector) { // alias
		return O::set_variable($name, $selector);
	}

	function __v($name, $selector) { // alias
		return O::set_variable($name, $selector);
	}

	function __variable($name, $selector) { // alias
		return O::set_variable($name, $selector);
	}

	function __lv($name, $selector) { // alias
		return O::set_variable($name, $selector);
	}

	function lv($name, $selector) { // alias
		return O::set_variable($name, $selector);
	}

	function set_variable($name, $selector) {
		//print('$name, $selector in set_variable: ');var_dump($name, $selector);
		// get($selector, $matching_array = false, $add_to_context = true, $ignore_context = false, $parent_node_only = false, $tagged_result = false) {
		$this->variables[$name] = O::get($selector, false, true, false, false, true); // equivalent to get_tagged since last parameter is true
		return true;
	}

	function living_variable($name, $selector) { // alias
		return O::set_variable($name, $selector);
	}

	function cv($names) { // alias
		return O::clear_variable($names);
	}

	function c_v($names) { // alias
		return O::clear_variable($names);
	}

	function clear_variable($names) {
		if(is_array($names)) {
			foreach($names as $name) {
				O::clear_variable($name);
			}
		} elseif(is_string($names)) {
			unset($this->variables[$names]);
		} else {
			print('$names: ');var_dump($names);
			O::fatal_error('unhandled type of $names in clear_variable()');
		}
		return true;
	}

	function v($name) { // alias
		return O::get_variable($name);
	}

	function _v($name) { // alias
		return O::get_variable($name);
	}

	function gv($name) { // alias
		return O::get_variable($name);
	}

	function _gv($name) { // alias
		return O::get_variable($name);
	}

	function variable($name) { // alias
		return O::get_variable($name);
	}

	function get_variable($name) {
		//fatal_error('we could keep variables alive but performance would diminish as the number of living variables increased, of course this performance hit could be offset by not having to requery multiple times and instead having the answer already available; this hasn\'t been benchmarked');
		if(!isset($this->variables[$name])) {
			return false;
		}
		return O::export($this->variables[$name]);
	}

	function validate() {
		$profile_token = $this->profile_function_start('validate');
		try {
			O::validate_syntax();
			O::validate_markup();
			O::validate_internal();
		} finally {
			$this->profile_function_stop('validate', $profile_token);
		}
	}

	function validate_lightweight() {
		$lt = substr_count($this->code, '<');
		$gt = substr_count($this->code, '>');
		if($lt !== $gt) {
			return array('ok' => false, 'reason' => 'angle_bracket_count_mismatch', 'offset' => null);
		}
		O::ensure_parent_indexes();
		O::recompute_self_closing_open_count();
		$closes = substr_count($this->code, '</');
		if($this->opening_tag_offsets_count !== $closes + $this->self_closing_open_count_in_index) {
			return array('ok' => false, 'reason' => 'opening_vs_closing_tag_count_mismatch', 'offset' => null);
		}
		return array('ok' => true, 'reason' => null, 'offset' => null);
	}

	function validate_syntax() {
		// only simplistically checks syntax
		$opening_substr_count = substr_count($this->code, '<');
		$closing_substr_count = substr_count($this->code, '>');
		if($opening_substr_count !== $closing_substr_count) {
			print('$opening_substr_count, $closing_substr_count, $this->file: ');var_dump($opening_substr_count, $closing_substr_count, $this->file);
			O::fatal_error('$opening_substr_count !== $closing_substr_count');
		}
	}

	function validate_markup() {
		O::ensure_parent_indexes();
		O::recompute_self_closing_open_count();
		$closing_tag_sequences = substr_count($this->code, '</');
		if($this->opening_tag_offsets_count !== $closing_tag_sequences + $this->self_closing_open_count_in_index) {
			print('$opening_tag_offsets_count, $closing_tag_sequences, $this->self_closing_open_count_in_index, $this->code: ');
			O::var_dump_full($this->opening_tag_offsets_count, $closing_tag_sequences, $this->self_closing_open_count_in_index, $this->code);
			O::fatal_error('different numbers of opening and closing tags');
		}
	}

	function validate_internal() {
		// validate context
		foreach($this->context as $context_index => $context_value) {
			// selector, parent, offset and length, offset_depths
			if(!isset($context_value[1]) || !is_array($context_value[1])) {
				continue;
			}
			foreach($context_value[1] as $context1_index => $context1_value) {
				if(!isset($context1_value[0]) || !isset($context1_value[1])) {
					continue;
				}
				$parent_string = substr($this->code, $context1_value[0], $context1_value[1]);
				if($parent_string === '') {
					continue;
				}
				if($parent_string[0] !== '<') {
					print('$this->code, $context_value[1], $parent_string: ');O::var_dump_full($this->code, $context_value[1], $parent_string);
					O::fatal_error('$parent_string from context in validate() does not start with &lt; (so offsets may be messed up)');
				}
				if($parent_string[strlen($parent_string) - 1] !== '>') {
					print('$this->code, $context_value[1], $parent_string: ');O::var_dump_full($this->code, $context_value[1], $parent_string);
					O::fatal_error('$parent_string from context in validate() does not end with &gt; (so offsets may be messed up)');
				}
				$opening_substr_count = substr_count($parent_string, '<');
				$closing_substr_count = substr_count($parent_string, '>');
				if($opening_substr_count !== $opening_substr_count) {
					print('$opening_substr_count, $opening_substr_count: ');var_dump($opening_substr_count, $opening_substr_count);
					O::fatal_error('$opening_substr_count !== $opening_substr_count of $parent_string from context');
				}
				// could check the whole string too...
			}
			if(!isset($context_value[2]) || !is_array($context_value[2])) {
				continue;
			}
			foreach($context_value[2] as $context2_index => $context2_value) {
				if(!isset($context2_value[0]) || !isset($this->code[$context2_value[0]])) {
					continue;
				}
				if($this->code[$context2_value[0]] !== '<') {
					print('$context2_value[0], $this->context[$context_index], substr($this->code, $context2_value[0], $context2_value[1]): ');O::var_dump_full($context2_value[0], $this->context[$context_index], substr($this->code, $context2_value[0], $context2_value[1]));
					O::fatal_error('offset points to non-opening bracket in context');
				}
				// could check the whole string too...
			}
			// foreach($context_value[3] as $context3_index => $context3_value) {
			// 	foreach($context3_value as $offset => $depth) {
			// 		if($this->offset_depths[$offset] === $depth) {
			//
			// 		} else {
			// 			print('$this->context[$context_index]: ');O::var_dump_full($this->context[$context_index]);
			// 			print('$this->offset_depths, $offset, $depth: ');var_dump($this->offset_depths, $offset, $depth);
			// 			O::fatal_error('context offset_depths does not match $this->offset_depths');
			// 		}
			// 	}
			// }
		}
		// validate living variables
		foreach($this->variables as $variable_index => $variable_value) {
			foreach($variable_value as $index => $value) {
				if(substr($this->code, $value[1], strlen($value[0])) === $value[0]) {

				} else {
					print('$variable_index, $this->variables[$variable_index], substr($this->code, $value[1], strlen($value[0])): ');O::var_dump_full($variable_index, $this->variables[$variable_index], substr($this->code, $value[1], strlen($value[0])));
					O::fatal_error('living variable does not match $this->code');
				}
			}
		}
	}

	function save_if_needed($filename = false, $parent_node = false) { // alias
		return O::save_if($filename, $parent_node);
	}

	function save_if_changed($filename = false, $parent_node = false) { // alias
		return O::save_if($filename, $parent_node);
	}

	function save_if($filename = false, $parent_node = false) {
		if($this->code !== $this->initial_code) {
			return O::save_LOM_to_file($filename, $parent_node);
		} else {
			return false;
		}
	}

	function save($filename = false, $parent_node = false) { // alias
		return O::save_LOM_to_file($filename, $parent_node);
	}

	function save_LOM($filename = false, $parent_node = false) { // alias
		return O::save_LOM_to_file($filename, $parent_node);
	}

	function tidy() { // alias
		return O::tidy_code();
	}

	function tidy_code() {
		if(!isset($this->config['indentation_string'])) {
			$this->config['indentation_string'] = '	'; // single tabulator (tab)
			//$this->config['indentation_string'] = '';
		}
		// remove whitespace
		$this->code = preg_replace('/>\s+</is', '><', $this->code);
		preg_match('/\s+</is', $this->code, $matches, PREG_OFFSET_CAPTURE);
		//print('$matches: ');var_dump($matches);exit(0);
		if(isset($matches[0][0]) && strlen($matches[0][0]) > 1) {
			$this->code = substr($this->code, strlen($matches[0][0]) - 1);
		}
		//$this->offset_depths = O::get_offset_depths($this->code); // this function attempts to avoid wasting time and so doesn't write the offset depths unless explicitly told
		O::set_offset_depths();
		//print('$this->code, $this->offset_depths after removing whitespace: ');O::var_dump_full($this->code, $this->offset_depths);
		// add whitespace
		$code = '';
		$tidy_padding = '';
		$last_offset = 0;
		//$last_last_depth = $last_depth = 0;
		$last_depth = -1;
		// self-closing followed by closing doesn't properly wrap
		foreach($this->offset_depths as $offset => $depth) {
			//print('tidy $offset, $depth, substr: ');var_dump($offset, $depth, substr($this->code, $offset, 20));
			//if($offset > 400) { // debug
			//	print('tidied code piece: ');var_dump($code);exit(0);
			//	break;
			//}
			$string = $tidy_padding . substr($this->code, $last_offset, $offset - $last_offset);
			$code .= $string;
			if($depth < $last_depth) {
				$indentation_string = '';
				if($this->code[$offset + 1] === '/') {
					//print('$depth < $last_depth (subsequent closing)<br />' . PHP_EOL);
					$counter = 0;
					while($counter < $depth - 1) {
						$indentation_string .= $this->config['indentation_string'];
						$counter++;
					}
				} else {
					//print('$this->code[$offset + 1] !== \'/\' (opening after close)<br />' . PHP_EOL);
					$counter = 0;
					while($counter < $depth) {
						$indentation_string .= $this->config['indentation_string'];
						$counter++;
					}
				}
				$tidy_padding = PHP_EOL . $indentation_string;
			} elseif($depth === $last_depth) {
				//print('$depth === $last_depth (first closing)<br />' . PHP_EOL);
				// if($last_depth < $last_last_depth) {
				// 	print('$last_depth < $last_last_depth (closing um?)<br />' . PHP_EOL);
				// 	$indentation_string = '';
				// 	$counter = 0;
				// 	while($counter < $depth) {
				// 		$indentation_string .= $this->config['indentation_string'];
				// 		$counter++;
				// 	}
				// 	$code .= PHP_EOL . $indentation_string . substr($this->code, $last_offset, $offset - $last_offset);
				// } else {
				// 	print('$last_depth !< $last_last_depth (um2?)<br />' . PHP_EOL);
				// 	$code .= substr($this->code, $last_offset, $offset - $last_offset);
				// }
				$tidy_padding = '';
			} else {
				//print('$depth > $last_depth (nested content)<br />' . PHP_EOL);
				/*if($this->code[$offset + 1] === '/') {
					*					print('should never see this... 858392849');
					*					print('$this->code[$offset + 1] === \'/\' (closing tag)<br />' . PHP_EOL);
					*					$tidy_padding = '';
			} else*/
				if($this->code[$offset] === '<') {
					if($this->code[$offset + 1] === '/') {
						//print('$this->code[$offset] === \'<\' (closing tag for empty tag)<br />' . PHP_EOL);
						$tidy_padding = '';
					} else {
						//print('$this->code[$offset] === \'<\' (nested opening tag)<br />' . PHP_EOL);
						$indentation_string = '';
						$counter = 0;
						while($counter < $depth) {
							$indentation_string .= $this->config['indentation_string'];
							$counter++;
						}
						$tidy_padding = PHP_EOL . $indentation_string;
						// if(strlen($string) > 0) {
						// 	print('strlen($string) > 0 (add non-whitespace um4?)<br />' . PHP_EOL);
						// 	$code .= PHP_EOL . $indentation_string . $string;
						// }
					}
				} else {
					//print('$this->code[$offset] !== \'<\' (text)<br />' . PHP_EOL);
					$tidy_padding = '';
				}
			}
			$last_offset = $offset;
			//$last_last_depth = $last_depth;
			$last_depth = $depth;
		}
		$this->code = $code;
		//$this->offset_depths = O::get_offset_depths($this->code);
		O::set_offset_depths();
		// should apply the same cleanup to the whole context... but for now just delete it
		O::reset_context();
		//print('$this->code, $this->offset_depths at end of tidy_code: ');O::var_dump_full($this->code, $this->offset_depths);exit(0);
		return $this->code;
	}

	function save_LOM_to_file($filename = false, $parent_node = false) {
		if($filename === false) {
			$filename = $this->file;
		}
		if($parent_node === false) {
			$code = $this->code;
		} else {
			$code = O::generate_code_from_LOM($parent_node);
		}
		//print('$filename, $code in save_LOM_to_file: ');var_dump($filename, $code);
		// Atomic write with exclusive lock — prevents corruption under concurrent requests
		$fh = fopen($filename, 'c');
		if($fh === false) return false;
		if(!flock($fh, LOCK_EX)) { fclose($fh); return false; }
		ftruncate($fh, 0);
		rewind($fh);
		$result = fwrite($fh, $code);
		fflush($fh);
		flock($fh, LOCK_UN);
		fclose($fh);
		return $result;
	}

	/*function to_string_offset_pair($array) {
		*	foreach($array as $first_index => $first_value) { break; }
		*	return array(O::generate_code_from_LOM_array($array), $array[$first_index][2]);
}
*/
	// function tagstring($array) { // alias
	// 	return O::generate_code_from_LOM_array($array);
	// }
	//
	// function tag_string($array) { // alias
	// 	return O::generate_code_from_LOM_array($array);
	// }
	// confusing with get_tag_string

	function get_texts($code = false) { // alias
		return O::get_all_text_strings($code);
	}

	function _texts($code = false) { // alias
		return O::get_all_text_strings($code);
	}

	function get_text_strings($code = false) { // alias
		return O::get_all_text_strings($code);
	}

	function _text_strings($code = false) { // alias
		return O::get_all_text_strings($code);
	}

	function get_text_only($code = false) { // alias
		return O::get_all_text_strings($code);
	}

	function _text_only($code = false) { // alias
		return O::get_all_text_strings($code);
	}

	function get_all_text_strings($code = false) {
		if($code === false) {
			$code = $this->code;
		}
		//$this->reset_context();
		//$all_tags = O::get_tag_string('*');
		//print('$all_tags in get_all_text_strings: ');O::var_dump_full($all_tags);exit(0);
		$all_text_strings = array();
		$last_offset = false;
		$offset_depths = O::get_offset_depths($code);
		foreach($offset_depths as $offset => $depth) {
			if($last_offset !== false) {
				//$all_text_strings[$last_offset] = substr($code, $last_offset, $offset - $last_offset);
				// the above data format is like $offset_depths, but that's not really appropriate since get_all_text_strings() is more intended for external rather than internal LOM use
				$all_text_strings[] = array(substr($code, $last_offset, $offset - $last_offset), $last_offset);
			}
			if($code[$offset] === '<') { // tag
				$last_offset = false;
			} else { // text
				$last_offset = $offset;
			}
		}
		if($last_offset !== false) {
			//$all_text_strings[$last_offset] = substr($code, $last_offset, $offset - $last_offset);
			// the above data format is like $offset_depths, but that's not really appropriate since get_all_text_strings() is more intended for external rather than internal LOM use
			$all_text_strings[] = array(substr($code, $last_offset, $offset - $last_offset), $last_offset);
		}
		//print('$all_text_strings in get_all_text_strings: ');O::var_dump_full($all_text_strings);exit(0);
		return $all_text_strings;
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

	function get_code() { // alias
		return O::generate_code_from_LOM_array();
	}

	function code() { // alias
		return O::generate_code_from_LOM_array();
	}

	function get_context() { // alias
		return O::context();
	}

	function context() {
		return $this->context;
	}

	function generate_code_from_LOM_array($array = false) {
		//return $this->code; // LOM is more of an abstract concept now hehe?
		if($array === false || $array === NULL) {
			//return '';
			return $this->code;
		} elseif(is_int($array)) {
			return O::get_tagged($array, false, false, false, false);
		} elseif(is_string($array)) {
			return $array;
		} elseif(is_array($array)) {
			if(O::all_entries_are_arrays($array)) {
				$generated_code = '';
				foreach($array as $index => $value) {
					$generated_code .= $value[0];
				}
				return $generated_code;
			} else {
				return $array[0];
			}
		} else {
			print('$array in generate_code_from_LOM_array: ');var_dump($array);
			O::fatal_error('unhandled input format in generate_code_from_LOM_array');
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
					//print('found a tag marked as a block<br />' . PHP_EOL);
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
			*	preg_match_all('/&[\w#x0-9]+;/is', $string, $character_entity_matches);
			*	foreach($character_entity_matches[0] as $character_entity_match) {
			*		//$decoded = html_entity_decode($character_entity_match);
			*		if(strpos($decoded, ";") === false) {
			*			$string = str_replace($character_entity_match, $decoded, $string);
	} else { // then we still have a problem
		print("did not properly decode HTML character entity in style attribute4892589435: <br />\r\n");var_dump($decoded);print("<br />\r\n");var_dump($string);print("<br />\r\n");exit(0);
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

	/* ============================================================
		*  LOM selector operator overlay
		*  Adds support for:
		*  != ~= ^= $= %= |= > < >= <=
		*  without touching the fast selector engine
		= *=========================================================== */

	// function _lom_selector_has_overlay($selector_part)
	// {
	// 	return preg_match('/(?:!=|~=|\^=|\$=|\%=|\|=|>=|<=|>|<)/',$selector_part);
	// }

	function _lom_parse_cmp($expr) {
		//$ops = array("!=","~=","^=","$=","%=","|=",">=","<=",">","<","=");
		$ops = $this->comparison_operators;

		foreach($ops as $op)
		{
			$pos = strpos($expr,$op);

			if($pos !== false)
			{
				$left = trim(substr($expr,0,$pos));
				$right = trim(substr($expr,$pos+strlen($op)));

				if(strlen($right)>=2)
				{
					$q1=$right[0];
					$q2=$right[strlen($right)-1];

					if(($q1=='"' && $q2=='"') || ($q1=="'" && $q2=="'"))
						$right = substr($right,1,-1);
				}

				return array(
					"left"=>$left,
					"op"=>$op,
					"right"=>$right
				);
			}
		}

		return false;
	}

	// function _lom_compare($left,$op,$right)
	// {
	// 	$left=(string)$left;
	// 	$right=(string)$right;
 //
	// 	$ln=is_numeric($left);
	// 	$rn=is_numeric($right);
 //
	// 	if($ln && $rn)
	// 	{
	// 		$left=$left+0;
	// 		$right=$right+0;
	// 	}
 //
	// 	switch($op)
	// 	{
	// 		case "=":  return $left==$right;
	// 		case "!=": return $left!=$right;
 //
	// 		case "~=":
	// 			$parts=preg_split('/\s+/',$left);
	// 			return in_array($right,$parts,true);
 //
	// 		case "^=":
	// 			return strncmp($left,$right,strlen($right))===0;
 //
	// 		case "$=":
	// 			return substr($left,-strlen($right))===$right;
 //
	// 		case "%=":
	// 			return strpos($left,$right)!==false;
 //
	// 		case "|=":
	// 			return ($left===$right)||(strpos($left,$right."-")===0);
 //
	// 		case ">":  return $left>$right;
	// 		case "<":  return $left<$right;
	// 		case ">=": return $left>=$right;
	// 		case "<=": return $left<=$right;
	// 	}
 //
	// 	return false;
	// }
 //
	// function comparison_postfilter($nodes,$selector_part)
	// {
	// 	if(!$this->_lom_selector_has_overlay($selector_part))
	// 		return $nodes;
 //
	// 	$cmp=$this->_lom_parse_cmp($selector_part);
	// 	if(!$cmp) return $nodes;
 //
	// 	$out=array();
 //
	// 	foreach($nodes as $k=>$node)
	// 	{
	// 		$left_val=null;
 //
	// 		if(strlen($cmp["left"]) && $cmp["left"][0]=="@")
	// 		{
	// 			$attr=substr($cmp["left"],1);
	// 			$attrs=$this->attributes($node);
 //
	// 			if(isset($attrs[$attr]))
	// 				$left_val=$attrs[$attr];
	// 		}
 //
	// 		if($left_val!==null &&
	// 			$this->_lom_compare($left_val,$cmp["op"],$cmp["right"]))
	// 		{
	// 			$out[$k]=$node;
	// 		}
	// 	}
 //
	// 	return $out;
	// }

	function compare_values($left, $op, $right) {

		$ln = is_numeric($left);
		$rn = is_numeric($right);

		if($ln && $rn) {
			$left = $left + 0;
			$right = $right + 0;
		}

		switch($op) {

			case '=':
				return $left == $right;

			case '!=':
				return $left != $right;

			case '^=':
				return strpos($left, $right) === 0;

			case '$=':
				return substr($left, -strlen($right)) === $right;

			case '%=':
				return strpos($left, $right) !== false;

			case '~=':
				return in_array($right, preg_split('/\s+/', $left));

			// case '|=':
			// 	return ($left === $right) || (strpos($left, $right . '-') === 0);

			case '>':
				return $left > $right;

			case '<':
				return $left < $right;

			case '>=':
				return $left >= $right;

			case '<=':
				return $left <= $right;
		}

		return false;
	}

	function set_LOM_operators() {
		//$this->operators = array(/*' ', */'_', '@', '=', '&', '[', ']', '.', '*', '|');
		// operators were encoded using angle brackets < > but this conflicted greater than and less than operators, so now a symbol that is not used anywhere else is used: #
		$this->comparison_operators = array(
			// multibyte operators first because they can contain single byte operators
			'!=' => '#notequal#',
			'~=' => '#containsword#',
			'^=' => '#startswith#',
			'$=' => '#endswith#',
			'%=' => '#contains#',
			//'|=' => '#prefixdash>', // unused for now
			'>=' => '#greatereq#',
			'<=' => '#lesseq#',
			// single byte operators
			'>' => '#greater#',
			'<' => '#less#',
			'=' => '#equal#',
		);
		$this->comparison_operators_regex = '';
		foreach($this->comparison_operators as $comparison_operator => $comparison_operator_replacement) {
			$this->comparison_operators_regex .= O::preg_escape($comparison_operator) . '|';
		}
		$this->comparison_operators_regex = substr($this->comparison_operators_regex, 0, strlen($this->comparison_operators_regex) - 1);
		$this->selector_operators = array(
			/*' ',*/
			'\\' => '#backslash#',
			'/' => '#forwardslash#',
			'_' => '#underscore#',
			'@' => '#at#',
			'&' => '#ampersand#',
			'[' => '#leftsquarebracket#',
			']' => '#rightsquarebracket#',
			'.' => '#dot#',
			'*' => '#asterisk#',
			'|' => '#bar#',
			//"'" => '#apostrophe#'
		);
		$this->operators = array_merge($this->comparison_operators, $this->selector_operators);
		return true;
	}

	function get_LOM_operators($string) { // alias
		O::LOM_operators();
	}

	function get_operators($string) { // alias
		O::LOM_operators();
	}

	function LOM_operators() {
		if(!isset($this->operators)) {
			O::set_LOM_operators();
		}
		return $this->operators;
	}

	function escape($string) { // alias
		return O::query_encode($string);
	}

	function enter($string) { // alias
		return O::query_decode($string);
	}

	function enc($string) { // alias
		return O::query_encode($string);
	}

	function dec($string) { // alias
		return O::query_decode($string);
	}

	function query_encode($string) {
		if(!is_string($string)) {
			return $string;
		}
		//$string = str_replace(' ', '<space>', $string);
		/*$string = str_replace('_', '<underscore>', $string);
			*	$string = str_replace('@', '<at>', $string);
			*	$string = str_replace('=', '<equals>', $string);
			*	$string = str_replace('&', '<ampersand>', $string);
			*	$string = str_replace('[', '<leftsquarebracket>', $string);
			*	$string = str_replace(']', '<rightsquarebracket>', $string);
			*	$string = str_replace('.', '<dot>', $string);
			*	$string = str_replace('*', '<asterisk>', $string);
			*	$string = str_replace('|', '<bar>', $string);*/
		foreach($this->operators as $raw => $coded) {
			$string = str_replace($raw, $coded, $string);
		}
		return $string;
	}

	function query_decode($string) {
		if(!is_string($string)) {
			return $string;
		}
		//$string = str_replace('<space>', ' ', $string);
		/*$string = str_replace('<underscore>', '_', $string);
			*	$string = str_replace('<at>', '@', $string);
			*	$string = str_replace('<equals>', '=', $string);
			*	$string = str_replace('<ampersand>', '&', $string);
			*	$string = str_replace('<leftsquarebracket>', '[', $string);
			*	$string = str_replace('<rightsquarebracket>', ']', $string);
			*	$string = str_replace('<dot>', '.', $string);
			*	$string = str_replace('<asterisk>', '*', $string);
			*	$string = str_replace('<bar>', '|', $string);*/
		foreach($this->operators as $raw => $coded) {
			$string = str_replace($coded, $raw, $string);
		}
		return $string;
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
		print('<span style="color: orange;">' . $message . '</span><br />
		');
	}

	function warning_if($string, $count) {
		if($count > 1) {
			O::warning($string);
		}
	}

	function warning_once($string) {
		if(!isset($this->printed_strings[$string])) {
			print('<span style="color: orange;">' . $string . '</span><br />
			');
			$this->printed_strings[$string] = true;
		}
	}

	function information($message) {
		print('<span style="color: green;">' . $message . '</span><br />
		');
	}

	function good_message($message) {
		print('<span style="color: green;">' . $message . '</span><br />
		');
	}

	function good_message_once($string) {
		if(!isset($this->printed_strings[$string])) {
			print('<span style="color: green;">' . $string . '</span><br />
			');
			$this->printed_strings[$string] = true;
		}
	}

	function minimum($variable, $minimum_value) {
		if($variable < $minimum_value) {
			$variable = $minimum_value;
		}
		return $variable;
	}

	function maximum($variable, $maximum_value) {
		if($variable > $maximum_value) {
			$variable = $maximum_value;
		}
		return $variable;
	}

	function cup($variable, $minimum_value) { // alias
		return O::minimum($variable, $minimum_value);
	}

	function cap($variable, $maximum_value) { // alias
		return O::maximum($variable, $maximum_value);
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
		$info = pathinfo(parse_url($string, PHP_URL_PATH));
		return isset($info['extension']) ? $info['extension'] : '';
	}

	function file_extension_old($string) {
		return pathinfo($string)['extension'];
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
		//return strrpos($haystack, $needle, -1 * $offset);
		return strrpos($haystack, $needle);
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
		return (float)$usec + (float)$sec;
	}

	function profile_function_start($function_name) {
		if(!$this->profile_enabled) {
			return false;
		}
		return O::getmicrotime();
	}

	function profile_function_stop($function_name, $start_time) {
		if(!$this->profile_enabled || $start_time === false) {
			return false;
		}
		$elapsed = O::getmicrotime() - $start_time;
		if(!isset($this->profile_stats[$function_name])) {
			$this->profile_stats[$function_name] = array('calls' => 0, 'time' => 0.0);
		}
		$this->profile_stats[$function_name]['calls']++;
		$this->profile_stats[$function_name]['time'] += $elapsed;
		return true;
	}

	function profile_add_time($function_name, $elapsed) {
		if(!$this->profile_enabled) {
			return false;
		}
		if($elapsed === false || $elapsed < 0) {
			return false;
		}
		if(!isset($this->profile_stats[$function_name])) {
			$this->profile_stats[$function_name] = array('calls' => 0, 'time' => 0.0);
		}
		$this->profile_stats[$function_name]['calls']++;
		$this->profile_stats[$function_name]['time'] += $elapsed;
		return true;
	}

	function get_profile_stats() {
		return $this->profile_stats;
	}

	function dump_profile_report($limit = 20) {
		if(!$this->profile_enabled) {
			print("LOM profile disabled (set LOM_PROFILE=1 to enable)." . PHP_EOL);
			return false;
		}
		$stats = $this->profile_stats;
		if(!is_array($stats) || sizeof($stats) === 0) {
			print("LOM profile: no samples captured." . PHP_EOL);
			return false;
		}
		$rows = array();
		$total = 0.0;
		foreach($stats as $function_name => $entry) {
			$calls = isset($entry['calls']) ? (int)$entry['calls'] : 0;
			$time = isset($entry['time']) ? (float)$entry['time'] : 0.0;
			$total += $time;
			$rows[] = array(
				'name' => $function_name,
				'calls' => $calls,
				'time' => $time,
				'avg' => ($calls > 0) ? ($time / $calls) : 0.0,
			);
		}
		usort($rows, function($a, $b) {
			if($a['time'] == $b['time']) {
				return 0;
			}
			return ($a['time'] > $b['time']) ? -1 : 1;
		});
		$limit = (int)$limit;
		if($limit < 1) {
			$limit = sizeof($rows);
		}
		print(PHP_EOL . "LOM internal function profile (sorted by self timer):" . PHP_EOL);
		print(str_pad('function', 40) . str_pad('calls', 10, ' ', STR_PAD_LEFT) . str_pad('time_ms', 14, ' ', STR_PAD_LEFT) . str_pad('avg_ms', 14, ' ', STR_PAD_LEFT) . str_pad('%', 8, ' ', STR_PAD_LEFT) . PHP_EOL);
		$count = 0;
		foreach($rows as $row) {
			$count++;
			if($count > $limit) {
				break;
			}
			$time_ms = $row['time'] * 1000.0;
			$avg_ms = $row['avg'] * 1000.0;
			$pct = ($total > 0.0) ? (($row['time'] / $total) * 100.0) : 0.0;
			print(
				str_pad($row['name'], 40) .
				str_pad((string)$row['calls'], 10, ' ', STR_PAD_LEFT) .
				str_pad(number_format($time_ms, 3, '.', ''), 14, ' ', STR_PAD_LEFT) .
				str_pad(number_format($avg_ms, 3, '.', ''), 14, ' ', STR_PAD_LEFT) .
				str_pad(number_format($pct, 2, '.', ''), 8, ' ', STR_PAD_LEFT) .
				PHP_EOL
			);
		}
		print("total_profiled_ms: " . number_format($total * 1000.0, 3, '.', '') . PHP_EOL);
		return true;
	}

	function dump_total_time_taken() {
		$time_spent = O::getmicrotime() - $this->O_initial_time;
		print('Total time spent querying XML: ' . $time_spent . ' seconds.<br />' . PHP_EOL);
	}

}

?>
