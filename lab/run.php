<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'common.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$slug = isset($_GET['app']) ? (string)$_GET['app'] : '';
if(!lab_valid_slug($slug)) {
	http_response_code(400);
	echo 'Invalid app name.';
	exit;
}

$path = lab_app_path($slug);
if($path === false || !is_file($path)) {
	http_response_code(404);
	echo 'App not found.';
	exit;
}

$source = lab_read_source($slug);
if($source === false) {
	http_response_code(500);
	echo 'Could not read the app.';
	exit;
}

$lint = lab_lint($source);
if($lint !== true) {
	http_response_code(400);
	header('Content-Type: text/html; charset=utf-8');
	echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"/><title>Syntax error</title>';
	echo '<link rel="stylesheet" href="assets/app.css"/></head><body><main class="app">';
	echo '<p class="error">' . htmlspecialchars($lint) . '</p>';
	echo '</main></body></html>';
	exit;
}

ob_start();
try {
	include $path;
} catch(Throwable $e) {
	ob_end_clean();
	http_response_code(500);
	header('Content-Type: text/html; charset=utf-8');
	echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"/><title>App error</title>';
	echo '<link rel="stylesheet" href="assets/app.css"/></head><body><main class="app">';
	echo '<p class="error">' . htmlspecialchars($e->getMessage()) . '</p>';
	echo '</main></body></html>';
	exit;
}
$html = ob_get_clean();
$inject = '<script>window.LAB_APP=' . json_encode($slug) . ';</script>';
if(preg_match('/<head[^>]*>/i', $html)) {
	$html = preg_replace('/<head[^>]*>/i', '$0' . $inject, $html, 1);
} else {
	$html = $inject . $html;
}
echo lab_isolate_styles($html);
