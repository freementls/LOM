<?php

const LAB_MAX_SOURCE = 400000;
const LAB_SHIPPED = array(
	'hello' => 'Hello world app',
	'approval' => 'App lab demo',
);

function lab_apps_dir() {
	return __DIR__ . DIRECTORY_SEPARATOR . 'apps';
}

function lab_templates_dir() {
	return __DIR__ . DIRECTORY_SEPARATOR . 'templates';
}

function lab_valid_slug($slug) {
	return is_string($slug) && preg_match('/^[a-z0-9-]+$/', $slug) === 1 && strlen($slug) <= 48;
}

function lab_app_path($slug) {
	if(!lab_valid_slug($slug)) {
		return false;
	}
	return lab_apps_dir() . DIRECTORY_SEPARATOR . $slug . '.php';
}

function lab_template_path($name) {
	if(!lab_valid_slug($name)) {
		return false;
	}
	return lab_templates_dir() . DIRECTORY_SEPARATOR . $name . '.php';
}

function lab_title_from_slug($slug) {
	return ucwords(str_replace('-', ' ', $slug));
}

function lab_app_title($slug, $source = null) {
	if(isset(LAB_SHIPPED[$slug])) {
		return LAB_SHIPPED[$slug];
	}
	if($source === null) {
		$source = lab_read_source($slug);
	}
	if(is_string($source) && preg_match('/<title>([^<]+)<\/title>/i', $source, $m)) {
		$title = trim($m[1]);
		if($title !== '') {
			return $title;
		}
	}
	return lab_title_from_slug($slug);
}

function lab_is_shipped($slug) {
	return isset(LAB_SHIPPED[$slug]);
}

function lab_read_source($slug) {
	$path = lab_app_path($slug);
	if($path === false || !is_file($path)) {
		return false;
	}
	$source = file_get_contents($path);
	return ($source === false) ? false : $source;
}

function lab_write_source($slug, $source) {
	$path = lab_app_path($slug);
	if($path === false) {
		return 'Invalid app name.';
	}
	if(strlen($source) > LAB_MAX_SOURCE) {
		return 'Source is too large (400 KB cap).';
	}
	$dir = lab_apps_dir();
	if(!is_dir($dir) && !mkdir($dir, 0775, true)) {
		return 'Could not create the apps folder.';
	}
	if(file_put_contents($path, $source) === false) {
		return 'Could not write the app file. The web server needs write access to lab/apps/.';
	}
	@chmod($path, 0666);
	return true;
}

function lab_delete_source($slug) {
	$path = lab_app_path($slug);
	if($path === false) {
		return 'Invalid app name.';
	}
	if(!is_file($path)) {
		return 'App not found.';
	}
	if(!unlink($path)) {
		return 'Could not delete the app file.';
	}
	return true;
}

function lab_list_apps() {
	$apps = array();
	$seen = array();
	foreach(LAB_SHIPPED as $slug => $title) {
		$path = lab_app_path($slug);
		if($path && is_file($path)) {
			$apps[] = array(
				'slug' => $slug,
				'title' => $title,
				'shipped' => true,
			);
			$seen[$slug] = true;
		}
	}
	$files = glob(lab_apps_dir() . DIRECTORY_SEPARATOR . '*.php');
	if(!is_array($files)) {
		$files = array();
	}
	sort($files, SORT_STRING);
	foreach($files as $file) {
		$slug = basename($file, '.php');
		if(isset($seen[$slug]) || !lab_valid_slug($slug)) {
			continue;
		}
		$apps[] = array(
			'slug' => $slug,
			'title' => lab_app_title($slug),
			'shipped' => false,
		);
	}
	return $apps;
}

function lab_slugify($name) {
	$s = strtolower(trim((string)$name));
	$s = preg_replace('/[^a-z0-9]+/', '-', $s);
	$s = trim($s, '-');
	if($s === '') {
		$s = 'app';
	}
	if(strlen($s) > 40) {
		$s = rtrim(substr($s, 0, 40), '-');
	}
	$base = $s;
	$n = 2;
	while(is_file(lab_app_path($s))) {
		$s = $base . '-' . $n;
		$n++;
	}
	return $s;
}

function lab_lint($source) {
	try {
		token_get_all($source, TOKEN_PARSE);
		return true;
	} catch(ParseError $e) {
		return $e->getMessage() . ' on line ' . $e->getLine();
	} catch(Throwable $e) {
		return $e->getMessage();
	}
}

function lab_php_unquote($inner, $style) {
	if($style === 'nowdoc') {
		return $inner;
	}
	$inner = str_replace('\\\\', "\0", $inner);
	if($style === 'single') {
		$inner = str_replace("\\'", "'", $inner);
	} else {
		$inner = str_replace(array('\\"', '\\$'), array('"', '$'), $inner);
	}
	return str_replace("\0", '\\', $inner);
}

function lab_php_quote($inner, $style) {
	if($style === 'nowdoc') {
		return $inner;
	}
	if($style === 'single') {
		return str_replace(array('\\', "'"), array('\\\\', "\\'"), $inner);
	}
	return str_replace(array('\\', '"', '$'), array('\\\\', '\\"', '\\$'), $inner);
}

function lab_find_xml_vars($source) {
	$vars = array();
	if(!is_string($source) || $source === '') {
		return $vars;
	}
	$patterns = array(
		'nowdoc' => '/\$XML([A-Za-z0-9_]*)\s*=\s*<<<\'XML\'\r?\n([\s\S]*?)\r?\nXML;/',
		'single' => '/\$XML([A-Za-z0-9_]*)\s*=\s*\'((?:\\\\.|[^\'])*)\'/',
		'double' => '/\$XML([A-Za-z0-9_]*)\s*=\s*"((?:\\\\.|[^"])*)"/',
	);
	foreach($patterns as $style => $re) {
		if(!preg_match_all($re, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
			continue;
		}
		foreach($matches as $m) {
			$name = 'XML' . $m[1][0];
			if(isset($vars[$name])) {
				continue;
			}
			$vars[$name] = array(
				'name' => $name,
				'xml' => lab_php_unquote($m[2][0], $style),
				'xml_start' => $m[2][1],
				'xml_len' => strlen($m[2][0]),
				'style' => $style,
			);
		}
	}
	return $vars;
}

function lab_patch_xml_var($source, $var, $new_xml) {
	$vars = lab_find_xml_vars($source);
	if(!isset($vars[$var])) {
		return false;
	}
	$info = $vars[$var];
	$encoded = lab_php_quote($new_xml, isset($info['style']) ? $info['style'] : 'nowdoc');
	return substr($source, 0, $info['xml_start']) . $encoded . substr($source, $info['xml_start'] + $info['xml_len']);
}

function lab_json_flags() {
	$flags = JSON_UNESCAPED_UNICODE;
	if(defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
		$flags |= JSON_INVALID_UTF8_SUBSTITUTE;
	}
	return $flags;
}

function lab_css_line_opens_rule($line) {
	return (bool)preg_match('/^\s*(@|[.#:\[]|[A-Za-z]|\\*)/', $line) && strpos($line, '{') !== false;
}

function lab_css_line_is_atrule($line) {
	return (bool)preg_match('/^\s*@[\w-]+/', $line);
}

function lab_split_css_rules($css) {
	$lines = preg_split('/\R/', (string)$css);
	$groups = array();
	$buf = '';
	$depth = 0;
	$in_atrule = false;
	foreach($lines as $line) {
		if(trim($line) === '' && $buf === '') {
			continue;
		}
		if($buf !== '' && $depth > 0 && !$in_atrule && lab_css_line_opens_rule($line)) {
			$groups[] = rtrim($buf);
			$buf = '';
			$depth = 0;
		}
		if($buf === '') {
			$in_atrule = lab_css_line_is_atrule($line);
		}
		$buf .= ($buf === '' ? '' : "\n") . $line;
		$depth += substr_count($line, '{') - substr_count($line, '}');
		if($depth < 0) {
			$depth = 0;
		}
		if($depth === 0 && trim($buf) !== '') {
			$groups[] = rtrim($buf);
			$buf = '';
			$in_atrule = false;
		}
	}
	if(trim($buf) !== '') {
		$groups[] = rtrim($buf);
	}
	return $groups;
}

function lab_isolate_styles($html) {
	return preg_replace_callback('/<style\b([^>]*)>([\s\S]*?)<\/style>/i', function ($m) {
		$chunks = lab_split_css_rules($m[2]);
		if(count($chunks) <= 1) {
			return $m[0];
		}
		$out = '';
		foreach($chunks as $chunk) {
			$out .= '<style' . $m[1] . '>' . $chunk . '</style>';
		}
		return $out;
	}, $html);
}

function lab_json_input() {
	$raw = file_get_contents('php://input');
	$data = json_decode($raw, true);
	if(!is_array($data)) {
		$data = $_POST;
	}
	return is_array($data) ? $data : array();
}
