<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const LOM_DEMO_MAX_XML = 200000;
const LOM_DEMO_MAX_QUERY = 4000;
const LOM_DEMO_MAX_WRITE = 20000;

function lom_demo_fail($message, $code = 400, $extra = array()) {
	http_response_code($code);
	echo json_encode(array_merge(array(
		'ok' => false,
		'error' => $message,
		'matches' => array(),
		'count' => 0,
		'ms' => 0,
		'xml' => null,
	), $extra), JSON_UNESCAPED_UNICODE);
	exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if(!is_array($data)) {
	$data = $_POST;
}
if(!is_array($data)) {
	$data = array();
}

$xml = isset($data['xml']) ? (string)$data['xml'] : '';
$query = isset($data['query']) ? (string)$data['query'] : '';
$write = isset($data['write']) ? (string)$data['write'] : '';
$action = isset($data['action']) ? (string)$data['action'] : 'set';
if(!in_array($action, array('set', 'new_', 'delete'), true)) {
	$action = 'set';
}
$mode = isset($data['mode']) ? (string)$data['mode'] : 'tagged';
if($mode !== 'values') {
	$mode = 'tagged';
}

if($xml === '') {
	lom_demo_fail('XML is empty.');
}
if(strlen($xml) > LOM_DEMO_MAX_XML) {
	lom_demo_fail('XML is too large for the live demo (200 KB cap).');
}
if(strlen($query) > LOM_DEMO_MAX_QUERY) {
	lom_demo_fail('Query is too long.');
}
if(strlen($write) > LOM_DEMO_MAX_WRITE) {
	lom_demo_fail('Write value is too long.');
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'O.php';

set_time_limit(8);
ini_set('display_errors', '0');

$started = microtime(true);
$completed = false;

register_shutdown_function(function() use (&$completed, $started) {
	if($completed) {
		return;
	}
	$noise = trim((string)ob_get_contents());
	$ms = (microtime(true) - $started) * 1000;
	if(!headers_sent()) {
		header('Content-Type: application/json; charset=utf-8');
		http_response_code(400);
	}
	echo json_encode(array(
		'ok' => false,
		'error' => $noise !== '' ? strip_tags($noise) : 'Query aborted.',
		'matches' => array(),
		'count' => 0,
		'ms' => round($ms, 2),
		'xml' => null,
	), JSON_UNESCAPED_UNICODE);
});

ob_start();
try {
	$O = new O($xml, false);
	lom_demo_write($O, $action, $query, $write);
	$result = ($mode === 'values') ? $O->_($query) : $O->get_tagged($query);
	$buffer = trim(ob_get_clean());
	$matches = lom_demo_normalize($result);
	$out_xml = $O->code;
} catch(Throwable $e) {
	ob_end_clean();
	$completed = true;
	lom_demo_fail($e->getMessage(), 400, array(
		'ms' => round((microtime(true) - $started) * 1000, 2),
	));
}

$completed = true;
$payload = array(
	'ok' => true,
	'error' => ($buffer !== '' ? strip_tags($buffer) : null),
	'matches' => $matches,
	'count' => count($matches),
	'ms' => round((microtime(true) - $started) * 1000, 2),
	'mode' => $mode,
	'action' => $action,
	'xml' => $out_xml,
);
$flags = JSON_UNESCAPED_UNICODE;
if(defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
	$flags |= JSON_INVALID_UTF8_SUBSTITUTE;
}
$json = json_encode($payload, $flags);
if($json === false) {
	lom_demo_fail('Could not encode the result as JSON.');
}
echo $json;

function lom_demo_write($O, $action, $query, $write) {
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

function lom_demo_normalize($result) {
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
