<?php
$default_xml = file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'test.xml');
$default_query = '.person_name=sally';
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8"/>
	<meta name="viewport" content="width=device-width, initial-scale=1"/>
	<title>LOM · live query</title>
	<meta name="description" content="Live LOM demo: edit the XML and the selector, and watch matches update."/>
	<link rel="icon" href="../icons/lom-favicon.svg?v=5" type="image/svg+xml"/>
	<link rel="preconnect" href="https://fonts.googleapis.com"/>
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
	<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:ital,wght@0,400;0,500;0,600;1,400&family=Syne:wght@600;700;800&display=swap" rel="stylesheet"/>
	<link rel="stylesheet" href="assets/demo.css?v=8"/>
</head>
<body>
	<a class="skip" href="#xml">Skip to XML</a>
	<header class="rail">
		<a class="rail__brand" href="./">
			<svg class="logo" viewBox="0 0 64 64" width="40" height="40" role="img" aria-label="LOM">
				<defs>
					<linearGradient id="lom-live" x1="8" y1="8" x2="46" y2="46" gradientUnits="userSpaceOnUse">
						<stop offset="0%" stop-color="#ff5d6c"/>
						<stop offset="20%" stop-color="#ffa14a"/>
						<stop offset="40%" stop-color="#ffe25c"/>
						<stop offset="62%" stop-color="#6cf09e"/>
						<stop offset="80%" stop-color="#5ec8ff"/>
						<stop offset="100%" stop-color="#c47bff"/>
					</linearGradient>
				</defs>
				<rect width="64" height="64" rx="14" fill="#0a0e18"/>
				<g class="logo__field">
					<rect x="4" y="20" width="36" height="36" rx="5" fill="url(#lom-live)"/>
					<rect x="12" y="28" width="20" height="20" rx="4" fill="#0a0e18" opacity="0.22"/>
					<rect x="12" y="28" width="20" height="20" rx="4" fill="none" stroke="#fffef6" stroke-width="2.5"/>
					<rect class="logo__hit" x="20" y="36" width="12" height="12" rx="2.5" fill="#fff7d6"/>
				</g>
				<g class="logo__arrow">
					<path d="M56 12 L40 28" fill="none" stroke="#0a0e18" stroke-width="4" stroke-linecap="square"/>
					<path d="M56 12 L40 28" fill="none" stroke="#fffef6" stroke-width="2.2" stroke-linecap="square"/>
				</g>
			</svg>
			<span>LOM</span>
		</a>
		<p class="rail__tag">Living Object Model · live query</p>
	</header>

	<main class="wrap">
		<div class="stage">
			<section class="panel">
				<div class="panel__head">
					<label class="field-label" for="xml">XML</label>
					<button type="button" class="ghost" id="reset-xml">Reset sample</button>
				</div>
				<textarea id="xml" class="live" rows="4" spellcheck="false" data-default="<?php echo htmlspecialchars($default_xml, ENT_QUOTES); ?>"><?php echo htmlspecialchars($default_xml); ?></textarea>
			</section>

			<section class="panel panel--query">
				<div class="panel__head">
					<label class="field-label" for="query">LOM query</label>
					<button type="button" class="ghost" id="encode-sel" title="Replace _ with #underscore#">Encode _</button>
				</div>
				<textarea id="query" class="live live--query" rows="2" spellcheck="false" data-default="<?php echo htmlspecialchars($default_query, ENT_QUOTES); ?>"><?php echo htmlspecialchars($default_query); ?></textarea>
				<div class="panel__head">
					<label class="field-label" for="write">Write</label>
					<div class="write-tools">
						<div class="modes" role="group" aria-label="Write action">
							<button type="button" class="mode is-on" data-action="set">set</button>
							<button type="button" class="mode" data-action="new_">new_</button>
							<button type="button" class="mode" data-action="delete">delete</button>
						</div>
						<button type="button" class="write-go" id="apply-write">Write</button>
					</div>
				</div>
				<textarea id="write" class="live live--write" rows="2" spellcheck="false" placeholder="Value for set, or XML for new_. hobby=breathing sets a child of the query."></textarea>
				<div class="modes" role="group" aria-label="Result shape">
					<button type="button" class="mode is-on" data-mode="tagged">Nodes</button>
					<button type="button" class="mode" data-mode="values">Values</button>
				</div>
				<p class="tiny-hint">Query updates as you type. <code>Write</code> applies <code>set</code> / <code>new_</code> / <code>delete</code> to the XML.</p>
				<div class="chip-row">
					<button type="button" class="chip is-on" data-query=".person_name=sally">.person_name=sally</button>
					<button type="button" class="chip" data-query="hobby">hobby</button>
					<button type="button" class="chip" data-write-action="set" data-query=".person_lastname=mott" data-write="hobby=breathing">set mott hobby</button>
					<button type="button" class="chip" data-write-action="new_" data-query="big#underscore#container" data-write="&lt;person age=&quot;33&quot;&gt;&lt;name&gt;santa&lt;/name&gt;&lt;lastname&gt;klaus&lt;/lastname&gt;&lt;hobby&gt;presents&lt;/hobby&gt;&lt;/person&gt;">new_ santa</button>
					<button type="button" class="chip" data-write-action="delete" data-query=".person_name=santa">delete santa</button>
					<button type="button" class="chip" data-query=".person@age=16_name=sally">age 16 sallys</button>
					<button type="button" class="chip" data-query="*@age&gt;16">@age&gt;16</button>
					<button type="button" class="chip" data-query=".person_hobby^=s">hobby ^= s</button>
					<button type="button" class="chip" data-query=".tag2__name">.tag2__name</button>
					<button type="button" class="chip" data-query="person@name!=sally">name != sally</button>
					<button type="button" class="chip" data-query="a[0-3]">a[0-3]</button>
					<button type="button" class="chip" data-query="a[0-2,4-6,8]">a[0-2,4-6,8]</button>
					<button type="button" class="chip" data-query="a@id=1">a@id=1</button>
					<button type="button" class="chip" data-query="a[0]&amp;a[1]">a[0]&amp;a[1]</button>
					<button type="button" class="chip" data-query="a[0]|a[1]">a[0]|a[1]</button>
				</div>
			</section>

			<section class="panel panel--results">
				<div class="panel__head">
					<label class="field-label">Results <span class="status-dot" id="status-dot" aria-hidden="true"></span></label>
				</div>
				<div id="meters" class="meters" aria-live="polite"></div>
				<div id="results" class="results" aria-live="polite"></div>
			</section>
		</div>

		<ul class="ops" aria-label="LOM operators">
			<li><code>.</code> earlier tag</li>
			<li><code>_</code> child</li>
			<li><code>__</code> descendant</li>
			<li><code>|</code> or</li>
			<li><code>&amp;</code> and</li>
			<li><code>*</code> any</li>
			<li><code>@</code> attribute</li>
			<li><code>[n]</code> <code>[n-m]</code> <code>[n,m]</code> index</li>
			<li><code>=</code></li>
			<li><code>!=</code></li>
			<li><code>&gt;</code></li>
			<li><code>&gt;=</code></li>
			<li><code>&lt;</code></li>
			<li><code>&lt;=</code></li>
			<li><code>^=</code> starts</li>
			<li><code>$=</code> ends</li>
			<li><code>%=</code> contains</li>
			<li><code>~=</code> word</li>
		</ul>
	</main>
	<script src="assets/demo.js?v=6"></script>
</body>
</html>
