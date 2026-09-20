<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');

function lom_large_fail($message, $code = 400) {
	http_response_code($code);
	echo json_encode(array(
		'ok' => false,
		'error' => $message,
		'n' => 0,
		'construct_ms' => 0,
		'query_ms' => 0,
		'warm_ms' => 0,
		'write_ms' => 0,
		'ops' => array(),
	), JSON_UNESCAPED_UNICODE);
	exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
	$data = $_POST;
}
if (!is_array($data)) {
	$data = array();
}

$root = dirname(__DIR__, 2);
$fixtures = array(
	'1gb' => $root . '/.bench_out/fixture_1GB.xml',
	'20gb' => $root . '/.bench_out/fixture_20GB.xml',
);
$id = isset($data['fixture']) ? (string) $data['fixture'] : '1gb';
if (!isset($fixtures[$id])) {
	lom_large_fail('Unknown fixture.');
}
$path = $fixtures[$id];
if (!is_readable($path)) {
	lom_large_fail('Fixture is not on this machine. Generate it under .bench_out/.');
}

$ops = array(
	'construct' => 1,
	'get' => 1,
	'count' => 1,
	'warm' => 1,
	'parent' => 1,
	'set' => 1,
	'new_' => 1,
	'delete' => 1,
	'validate' => 1,
	'suite' => 1,
);
$op = isset($data['op']) ? (string) $data['op'] : '';
if ($op === '' && isset($data['kind'])) {
	$op = (string) $data['kind'];
}
if ($op === '') {
	$op = 'get';
}
if (!isset($ops[$op])) {
	lom_large_fail('Unknown paper op.');
}

$sel = isset($data['query']) ? trim((string) $data['query']) : '';
$needs_sel = !in_array($op, array('construct', 'suite', 'validate'), true);
if ($needs_sel && ($sel === '' || strlen($sel) > 400)) {
	lom_large_fail('Query is empty or too long.');
}
if ($sel !== '' && !preg_match('/^[A-Za-z0-9_#@=\\[\\]\\-\\/,\\|\\*\\.\\%\\$\\^\\~<>!]+$/', $sel)) {
	lom_large_fail('Query has characters the large demo does not accept.');
}

$write = isset($data['write']) ? (string) $data['write'] : '';
if (in_array($op, array('set', 'new_'), true)) {
	if ($write === '') {
		$write = ($op === 'new_')
			? '<bonus><name>surge</name><value>99</value></bonus>'
			: 'changed-note';
	}
	if (strlen($write) > 400) {
		lom_large_fail('Write payload is too long.');
	}
	if (!preg_match('/^[A-Za-z0-9_#@=\\s.<>\\/\\"\'-]+$/', $write)) {
		lom_large_fail('Write payload has characters the large demo does not accept.');
	}
}

$broad = ($sel !== '' && strpbrk($sel, '=/[') === false);
if ($op === 'get' && $broad) {
	$op = 'count';
}
if ($op === 'warm' && $broad) {
	/* A warm get of region / descendant would allocate tens of millions of matches. */
	$op = 'count';
}
if ($op === 'parent' && $broad) {
	lom_large_fail('Parent on a broad selector would materialize tens of millions of nodes. Use an indexed path.');
}

$lomc = $root . '/native/bin/lomc';
if (!is_executable($lomc)) {
	lom_large_fail('native/bin/lomc is not built. Run make -C native.');
}

$cmd = array($lomc, '--json', '--op', $op);
if ($sel !== '') {
	$cmd[] = '--sel';
	$cmd[] = $sel;
}
if (in_array($op, array('set', 'new_'), true)) {
	$cmd[] = '--write';
	$cmd[] = $write;
}
$cmd[] = $path;

set_time_limit(90);
$desc = array(
	0 => array('pipe', 'r'),
	1 => array('pipe', 'w'),
	2 => array('pipe', 'w'),
);
$proc = proc_open($cmd, $desc, $pipes, $root, array(
	'LOM_SIDECAR' => '1',
	'LOM_TILE' => '1',
	'LOM_FRACTAL' => '1',
	'LOM_OPEN_TMPDIR' => '/var/tmp',
	'LOM_SIDECAR_KEEP' => '1',
));
if (!is_resource($proc)) {
	lom_large_fail('Could not start lomc.');
}
fclose($pipes[0]);
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($proc);

$decoded = json_decode(trim($out), true);
if (!is_array($decoded) || empty($decoded['ok'])) {
	$hint = trim($err) !== '' ? trim($err) : trim($out);
	lom_large_fail($hint !== '' ? $hint : 'lomc failed (exit ' . $code . ').');
}

echo json_encode(array(
	'ok' => true,
	'error' => null,
	'fixture' => $id,
	'op' => isset($decoded['op']) ? $decoded['op'] : $op,
	'kind' => isset($decoded['mode']) ? $decoded['mode'] : $op,
	'query' => $sel,
	'n' => isset($decoded['n']) ? $decoded['n'] : 0,
	'st' => isset($decoded['st']) ? $decoded['st'] : 0,
	'construct_ms' => isset($decoded['construct_ms']) ? $decoded['construct_ms'] : 0,
	'query_ms' => isset($decoded['query_ms']) ? $decoded['query_ms'] : 0,
	'warm_ms' => isset($decoded['warm_ms']) ? $decoded['warm_ms'] : 0,
	'write_ms' => isset($decoded['write_ms']) ? $decoded['write_ms'] : 0,
	'census_ms' => isset($decoded['census_ms']) ? $decoded['census_ms'] : 0,
	'sample_ms' => isset($decoded['sample_ms']) ? $decoded['sample_ms'] : 0,
	'persist_ms' => isset($decoded['persist_ms']) ? $decoded['persist_ms'] : 0,
	'opens' => isset($decoded['opens']) ? $decoded['opens'] : 0,
	'sidecar' => !empty($decoded['sidecar']),
	'recipe' => !empty($decoded['recipe']),
	'bytes' => isset($decoded['bytes']) ? $decoded['bytes'] : 0,
	'version' => isset($decoded['version']) ? $decoded['version'] : '',
	'ops' => isset($decoded['ops']) && is_array($decoded['ops']) ? $decoded['ops'] : array(),
), JSON_UNESCAPED_UNICODE);
