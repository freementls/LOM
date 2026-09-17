<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'common.php';

$apps = lab_list_apps();
$initial = isset($apps[0]['slug']) ? $apps[0]['slug'] : '';
if(isset($_GET['app']) && lab_valid_slug($_GET['app']) && is_file(lab_app_path($_GET['app']))) {
	$initial = (string)$_GET['app'];
}
$source = $initial !== '' ? lab_read_source($initial) : '';
if($source === false) {
	$source = '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8"/>
	<meta name="viewport" content="width=device-width, initial-scale=1"/>
	<title>LOM · app lab</title>
	<meta name="description" content="Live LOM app lab: edit PHP, JavaScript, and $XML* data on the left, run the app on the right."/>
	<link rel="icon" href="../icons/lom-favicon.svg?v=5" type="image/svg+xml"/>
	<link rel="preconnect" href="https://fonts.googleapis.com"/>
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
	<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:ital,wght@0,400;0,500;0,600;1,400&family=Syne:wght@600;700;800&display=swap" rel="stylesheet"/>
	<link rel="stylesheet" href="assets/lab.css?v=12"/>
</head>
<body data-app="<?php echo htmlspecialchars($initial); ?>">
	<a class="skip" href="#code">Skip to code</a>
	<header class="rail">
		<nav class="pills" id="pills" aria-label="Apps">
<?php foreach($apps as $app) {
	$on = $app['slug'] === $initial ? ' is-on' : '';
?>
			<span class="chip<?php echo $on; ?>" data-app="<?php echo htmlspecialchars($app['slug']); ?>">
				<button type="button" class="chip__name"><?php echo htmlspecialchars($app['title']); ?></button>
				<button type="button" class="chip__x" aria-label="Delete <?php echo htmlspecialchars($app['title']); ?>">×</button>
			</span>
<?php } ?>
			<button type="button" class="chip chip--add" id="add-app" title="New app" aria-label="New app">+</button>
		</nav>
		<p class="rail__tag">App lab</p>
	</header>

	<main class="wrap">
		<div class="stage">
			<section class="panel">
				<div class="panel__head">
					<label class="field-label" for="code">Code <span class="status-dot" id="status-dot" aria-hidden="true"></span></label>
					<button type="button" class="ghost" id="reset-app"<?php echo lab_is_shipped($initial) ? '' : ' hidden'; ?>>Reset</button>
				</div>
				<div class="findbar" id="findbar" hidden>
					<input id="find-q" type="text" placeholder="Find" autocomplete="off"/>
					<input id="find-r" type="text" placeholder="Replace" autocomplete="off"/>
					<button type="button" id="find-prev">Prev</button>
					<button type="button" id="find-next">Next</button>
					<button type="button" id="find-rep">Replace</button>
					<button type="button" id="find-all">Replace All</button>
					<button type="button" id="find-close" aria-label="Close find">×</button>
				</div>
				<div class="editor">
					<div class="editor__gutter" aria-hidden="true">
						<pre class="editor__lines" id="code-lines"></pre>
					</div>
					<div class="editor__main">
						<pre class="editor__hl" id="code-hl" aria-hidden="true"></pre>
						<textarea id="code" class="editor__in" spellcheck="false"><?php echo htmlspecialchars($source); ?></textarea>
					</div>
				</div>
				<p class="status-line" id="status-line">Ready</p>
			</section>
			<section class="panel panel--app">
				<div class="panel__head">
					<span class="field-label">App</span>
				</div>
				<iframe class="frame" id="app-frame" title="Rendered app" src="about:blank"></iframe>
			</section>
		</div>
	</main>

	<dialog class="dlg" id="new-app">
		<form id="new-form" method="dialog">
			<h2>New app</h2>
			<label>Name
				<input id="new-name" type="text" name="name" required maxlength="48" placeholder="Budget tracker" autocomplete="off"/>
			</label>
			<div class="dlg__row">
				<button type="button" id="new-cancel">Cancel</button>
				<button type="submit" value="ok">Create</button>
			</div>
		</form>
	</dialog>
	<script src="assets/lab.js?v=15"></script>
</body>
</html>
