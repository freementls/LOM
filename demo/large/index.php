<?php
$root = dirname(__DIR__, 2);
$fixtures = array();
foreach (array(
	'1gb' => array($root . '/.bench_out/fixture_1GB.xml', '1 GB tiling fixture'),
	'20gb' => array($root . '/.bench_out/fixture_20GB.xml', '20 GB tiling fixture'),
) as $id => $info) {
	if (is_readable($info[0])) {
		$fixtures[$id] = array(
			'label' => $info[1],
			'bytes' => (int) filesize($info[0]),
		);
	}
}
$paper_set_sel = 'region[1]_zone[3]_entity[4]_meta_note';
$paper_new_sel = 'region[1]_zone[3]_entity[4]_meta';
$paper_new_frag = '<bonus><name>surge</name><value>99</value></bonus>';
$paper_indexed = 'region[10]_zone[5]_entity[7]_stats';
$paper_parent = 'region[1]_zone[3]_entity[4]_stats';
$paper_entity = 'entity_meta_name=Entity#underscore#42';
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8"/>
	<meta name="viewport" content="width=device-width, initial-scale=1"/>
	<meta name="robots" content="noindex,nofollow"/>
	<title>LOM · large fixture (local)</title>
	<link rel="icon" href="../../icons/lom-favicon.svg?v=5" type="image/svg+xml"/>
	<link rel="preconnect" href="https://fonts.googleapis.com"/>
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
	<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:ital,wght@0,400;0,500;0,600;1,400&family=Syne:wght@600;700;800&display=swap" rel="stylesheet"/>
	<link rel="stylesheet" href="../assets/demo.css?v=8"/>
</head>
<body>
	<a class="skip" href="#query">Skip to query</a>
	<header class="rail">
		<a class="rail__brand" href="../">
			<span>LOM</span>
		</a>
		<p class="rail__tag">Large fixture · localhost only · paper ops via native <code>lomc</code></p>
	</header>
	<main class="wrap">
		<div class="stage">
			<section class="panel">
				<div class="panel__head">
					<label class="field-label" for="fixture">Fixture</label>
				</div>
				<p class="tiny-hint">Same clocks as the paper table: construct (sidecar reload), get / warm, count, parent, set, new_, delete, validate. Writes stay in-process — the fixture and <code>.lomidx</code> on disk are not saved.</p>
				<div class="modes" role="group" aria-label="Fixture" id="fixture-modes">
<?php if (!$fixtures) { ?>
					<p class="error">No fixtures in <code>.bench_out/</code>. Generate with <code>php gen_perf_fixture.php 1GB .bench_out/fixture_1GB.xml</code>.</p>
<?php } else { foreach ($fixtures as $id => $fx) { ?>
					<button type="button" class="mode<?php echo $id === '1gb' ? ' is-on' : ''; ?>" data-fixture="<?php echo htmlspecialchars($id); ?>"><?php echo htmlspecialchars($fx['label']); ?> · <?php echo number_format($fx['bytes'] / 1e9, 1); ?> GB</button>
<?php } } ?>
				</div>
			</section>
			<section class="panel panel--query">
				<div class="panel__head">
					<label class="field-label" for="query">LOM query</label>
				</div>
				<textarea id="query" class="live live--query" rows="2" spellcheck="false" data-default="<?php echo htmlspecialchars($paper_entity, ENT_QUOTES); ?>"><?php echo htmlspecialchars($paper_entity); ?></textarea>
				<div class="panel__head">
					<label class="field-label" for="write">Write</label>
					<div class="write-tools">
						<div class="modes" role="group" aria-label="Paper op" id="op-modes">
							<button type="button" class="mode" data-op="construct">construct</button>
							<button type="button" class="mode is-on" data-op="get">get</button>
							<button type="button" class="mode" data-op="warm">warm</button>
							<button type="button" class="mode" data-op="count">count</button>
							<button type="button" class="mode" data-op="parent">parent</button>
							<button type="button" class="mode" data-op="set">set</button>
							<button type="button" class="mode" data-op="new_">new_</button>
							<button type="button" class="mode" data-op="delete">delete</button>
							<button type="button" class="mode" data-op="validate">validate</button>
							<button type="button" class="mode" data-op="suite">suite</button>
						</div>
						<button type="button" class="write-go" id="apply-write">Run</button>
					</div>
				</div>
				<textarea id="write" class="live live--write" rows="2" spellcheck="false" placeholder="Value for set, or XML for new_. Paper default: changed-note / bonus fragment."></textarea>
				<div class="chip-row">
					<button type="button" class="chip" data-op="construct">construct</button>
					<button type="button" class="chip is-on" data-op="get" data-query="<?php echo htmlspecialchars($paper_entity, ENT_QUOTES); ?>">get Entity_42</button>
					<button type="button" class="chip" data-op="warm" data-query="<?php echo htmlspecialchars($paper_entity, ENT_QUOTES); ?>">warm Entity_42</button>
					<button type="button" class="chip" data-op="get" data-query="name=/^Entity_42$/">get Entity_42 regex</button>
					<button type="button" class="chip" data-op="get" data-query="<?php echo htmlspecialchars($paper_indexed, ENT_QUOTES); ?>">get indexed</button>
					<button type="button" class="chip" data-op="warm" data-query="<?php echo htmlspecialchars($paper_indexed, ENT_QUOTES); ?>">warm indexed</button>
					<button type="button" class="chip" data-op="count" data-query="region">count region</button>
					<button type="button" class="chip" data-op="count" data-query="region_zone_entity_stats">count descendant</button>
					<button type="button" class="chip" data-op="count" data-query="entity@kind">count entity@kind</button>
					<button type="button" class="chip" data-op="parent" data-query="<?php echo htmlspecialchars($paper_parent, ENT_QUOTES); ?>">parent indexed</button>
					<button type="button" class="chip" data-op="set" data-query="<?php echo htmlspecialchars($paper_set_sel, ENT_QUOTES); ?>" data-write="changed-note">set note</button>
					<button type="button" class="chip" data-op="new_" data-query="<?php echo htmlspecialchars($paper_new_sel, ENT_QUOTES); ?>" data-write="<?php echo htmlspecialchars($paper_new_frag, ENT_QUOTES); ?>">new_ bonus</button>
					<button type="button" class="chip" data-op="delete" data-query="<?php echo htmlspecialchars($paper_set_sel, ENT_QUOTES); ?>">delete note</button>
					<button type="button" class="chip" data-op="validate">validate</button>
					<button type="button" class="chip" data-op="suite">paper suite</button>
				</div>
			</section>
			<section class="panel panel--results">
				<div class="panel__head">
					<label class="field-label">Clocks <span class="status-dot" id="status-dot" aria-hidden="true"></span></label>
				</div>
				<div id="meters" class="meters" aria-live="polite"></div>
				<div id="results" class="results" aria-live="polite"></div>
			</section>
		</div>
	</main>
	<script src="large.js?v=2"></script>
</body>
</html>
