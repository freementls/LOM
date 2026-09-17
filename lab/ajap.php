<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'common.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'O.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const LAB_AJAP_MAX_QUERY = 4000;
const LAB_AJAP_MAX_WRITE = 20000;

function lab_ajap_fail($message, $code = 400, $extra = array()) {
	http_response_code($code);
	echo json_encode(array_merge(array(
		'ok' => false,
		'error' => $message,
		'matches' => array(),
		'xml' => null,
		'source' => null,
	), $extra), lab_json_flags());
	exit;
}

function lab_ajap_normalize($result) {
	if($result === false || $result === null || $result === 0 || $result === '0') {
		return array();
	}
	if(is_string($result) || is_numeric($result)) {
		$text = (string)$result;
		if($text === '') {
			return array();
		}
		return array(array(
			'text' => $text,
			'offset' => null,
		));
	}
	if(!is_array($result) || count($result) === 0) {
		return array();
	}
	$out = array();
	foreach($result as $row) {
		if(is_array($row) && array_key_exists(0, $row)) {
			$out[] = array(
				'text' => (string)$row[0],
				'offset' => isset($row[1]) && is_numeric($row[1]) ? (int)$row[1] : null,
			);
		} elseif(is_string($row) || is_numeric($row)) {
			$out[] = array(
				'text' => (string)$row,
				'offset' => null,
			);
		}
	}
	return $out;
}

function lab_ajap_write($O, $action, $query, $write) {
	if($action === 'get') {
		return;
	}
	if($action === 'delete') {
		if($query === '') {
			return;
		}
		$O->delete($query);
		return;
	}
	if($write === '') {
		return;
	}
	if($action === 'new_') {
		if(strpos($write, '<') === false) {
			return;
		}
		$O->new_($write, $query === '' ? false : $query);
		return;
	}
	if(strpos($write, '<') === false && preg_match('/^([^\s=]+)\s*=\s*([\s\S]*)$/', $write, $m)) {
		$O->set($m[1], $m[2], $query === '' ? false : $query);
		return;
	}
	$O->set($query, $write);
}

$data = lab_json_input();
$slug = isset($data['app']) ? (string)$data['app'] : '';
$var = isset($data['var']) ? (string)$data['var'] : '';
$action = isset($data['action']) ? (string)$data['action'] : 'get';
$query = isset($data['query']) ? (string)$data['query'] : '';
$write = isset($data['write']) ? (string)$data['write'] : '';

if(!in_array($action, array('get', 'set', 'new_', 'delete'), true)) {
	$action = 'get';
}
if(!lab_valid_slug($slug)) {
	lab_ajap_fail('Invalid app name.');
}
if(!preg_match('/^XML[A-Za-z0-9_]*$/', $var)) {
	lab_ajap_fail('Variable must begin with XML.');
}
if(strlen($query) > LAB_AJAP_MAX_QUERY) {
	lab_ajap_fail('Query is too long.');
}
if(strlen($write) > LAB_AJAP_MAX_WRITE) {
	lab_ajap_fail('Write value is too long.');
}

$source = lab_read_source($slug);
if($source === false) {
	lab_ajap_fail('App not found.', 404);
}

$vars = lab_find_xml_vars($source);
if(!isset($vars[$var])) {
	lab_ajap_fail('$' . $var . ' was not found.');
}

$xml = $vars[$var]['xml'];
set_time_limit(8);
ini_set('display_errors', '0');

$started = microtime(true);
try {
	$O = new O($xml, false);
	lab_ajap_write($O, $action, $query, $write);
	$result = ($query === '') ? $O->code : $O->_($query);
	$matches = ($query === '') ? array() : lab_ajap_normalize($result);
	$out_xml = $O->code;
} catch(Throwable $e) {
	lab_ajap_fail($e->getMessage(), 400, array(
		'ms' => round((microtime(true) - $started) * 1000, 2),
	));
}

if($action !== 'get') {
	$patched = lab_patch_xml_var($source, $var, $out_xml);
	if($patched === false) {
		lab_ajap_fail('Could not patch $' . $var . '.');
	}
	$written = lab_write_source($slug, $patched);
	if($written !== true) {
		lab_ajap_fail($written);
	}
	$source = $patched;
}

echo json_encode(array(
	'ok' => true,
	'error' => null,
	'app' => $slug,
	'var' => $var,
	'action' => $action,
	'matches' => $matches,
	'count' => count($matches),
	'ms' => round((microtime(true) - $started) * 1000, 2),
	'xml' => $out_xml,
	'source' => $source,
), lab_json_flags());
