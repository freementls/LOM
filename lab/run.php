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
$hook = '(function(){var M=[],dead=0;window.addEventListener("pagehide",function(){dead=1;});function L(l,msg){l=+l||0;if(!M.length)return l;var i,r,n;if(msg&&/end of input|end of script/i.test(String(msg))&&M.length)return M[M.length-1].s1;for(i=0;i<M.length;i++){r=M[i];n=r.s1-r.s0+1;if(l>=1&&l<=n&&(l<r.o0||l<=n)){var x=r.s0+l-1;return x>r.s1?r.s1:x;}if(l>=r.o0&&l<=r.o1){x=r.s0+(l-r.o0);return x>r.s1?r.s1:x;}}if(M.length){r=M[M.length-1];if(l>=r.o0)return r.s1;}return l;}function r(m,l,c){m=String(m||"");if(parent===window||dead||/failed to fetch|networkerror|abort/i.test(m))return;parent.postMessage({type:"lab-js-error",app:window.LAB_APP,message:m,line:L(l,m),col:c||0},location.origin);}window.onerror=function(m,s,l,c,e){r((e&&e.message)||m,l,c);};window.addEventListener("unhandledrejection",function(ev){var x=ev.reason;r((x&&x.message)||x,0,0);});window.LAB_LINE_MAP=M;})();';
$inject = '<script>window.LAB_APP=' . json_encode($slug) . ';' . $hook . '</script>';
if(preg_match('/<head[^>]*>/i', $html)) {
	$html = preg_replace('/<head[^>]*>/i', '$0' . $inject, $html, 1);
} else {
	$html = $inject . $html;
}
$html = lab_isolate_styles($html);
$map = lab_script_line_map($source, $html);
$html = preg_replace('/var M=\[\]/', 'var M=' . json_encode($map, lab_json_flags()), $html, 1);
echo $html;
