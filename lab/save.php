<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'common.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function lab_save_fail($message, $code = 400, $extra = array()) {
	http_response_code($code);
	echo json_encode(array_merge(array(
		'ok' => false,
		'error' => $message,
	), $extra), lab_json_flags());
	exit;
}

function lab_save_ok($payload) {
	echo json_encode(array_merge(array('ok' => true, 'error' => null), $payload), lab_json_flags());
	exit;
}

function lab_app_payload($slug, $source = null) {
	if($source === null) {
		$source = lab_read_source($slug);
	}
	return array(
		'slug' => $slug,
		'title' => lab_app_title($slug, $source),
		'shipped' => lab_is_shipped($slug),
		'source' => $source,
	);
}

if($_SERVER['REQUEST_METHOD'] === 'GET') {
	if(!isset($_GET['app']) || $_GET['app'] === '') {
		lab_save_ok(array('apps' => lab_list_apps()));
	}
	$slug = (string)$_GET['app'];
	if(!lab_valid_slug($slug)) {
		lab_save_fail('Invalid app name.');
	}
	$source = lab_read_source($slug);
	if($source === false) {
		lab_save_fail('App not found.', 404);
	}
	lab_save_ok(lab_app_payload($slug, $source));
}

$data = lab_json_input();

if(!empty($data['create'])) {
	$name = trim((string)$data['create']);
	if($name === '') {
		lab_save_fail('Name the app first.');
	}
	$slug = lab_slugify($name);
	$source = '';
	$written = lab_write_source($slug, $source);
	if($written !== true) {
		lab_save_fail($written);
	}
	lab_save_ok(array_merge(lab_app_payload($slug, $source), array(
		'apps' => lab_list_apps(),
	)));
}

$slug = isset($data['app']) ? (string)$data['app'] : '';
if(!lab_valid_slug($slug)) {
	lab_save_fail('Invalid app name.');
}

if(!empty($data['delete'])) {
	$gone = lab_delete_source($slug);
	if($gone !== true) {
		lab_save_fail($gone);
	}
	lab_save_ok(array(
		'slug' => $slug,
		'deleted' => true,
		'apps' => lab_list_apps(),
	));
}

if(!empty($data['reset'])) {
	if(!lab_is_shipped($slug)) {
		lab_save_fail('Only shipped apps can be reset.');
	}
	$template = lab_template_path($slug);
	if($template === false || !is_file($template)) {
		lab_save_fail('Reset template is missing.', 500);
	}
	$source = file_get_contents($template);
	if($source === false) {
		lab_save_fail('Could not read the reset template.', 500);
	}
	$written = lab_write_source($slug, $source);
	if($written !== true) {
		lab_save_fail($written);
	}
	lab_save_ok(lab_app_payload($slug, $source));
}

if(!isset($data['source'])) {
	lab_save_fail('Missing source.');
}

$source = (string)$data['source'];
if(strlen($source) > LAB_MAX_SOURCE) {
	lab_save_fail('Source is too large (400 KB cap).');
}

$lint = lab_lint($source);
$written = lab_write_source($slug, $source);
if($written !== true) {
	lab_save_fail($written);
}

lab_save_ok(array(
	'slug' => $slug,
	'title' => lab_app_title($slug, $source),
	'shipped' => lab_is_shipped($slug),
	'lint' => ($lint === true) ? null : $lint,
	'reload' => ($lint === true),
));
