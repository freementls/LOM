(function () {
	var queryEl = document.getElementById('query');
	var writeEl = document.getElementById('write');
	var resultsEl = document.getElementById('results');
	var metersEl = document.getElementById('meters');
	var statusEl = document.getElementById('status-dot');
	var applyBtn = document.getElementById('apply-write');
	var fixture = '1gb';
	var op = 'get';
	var timer = null;
	var seq = 0;
	var mutating = { set: 1, new_: 1, delete: 1 };

	var firstFix = document.querySelector('#fixture-modes [data-fixture].is-on');
	if (firstFix) fixture = firstFix.getAttribute('data-fixture');

	function setStatus(kindName) {
		statusEl.classList.remove('is-wait', 'is-err');
		if (kindName) statusEl.classList.add(kindName);
	}

	function escapeHtml(s) {
		return String(s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function fmt(n) {
		if (typeof n !== 'number' || !isFinite(n)) return '0';
		return n < 10 ? n.toFixed(3).replace(/\.?0+$/, '') || '0' : n.toFixed(2);
	}

	function setOp(next, runNow) {
		op = next || 'get';
		document.querySelectorAll('#op-modes [data-op]').forEach(function (b) {
			b.classList.toggle('is-on', b.getAttribute('data-op') === op);
		});
		applyBtn.textContent = op === 'delete' ? 'Delete' : (mutating[op] ? 'Write' : 'Run');
		applyBtn.classList.toggle('is-delete', op === 'delete');
		if (runNow) request();
	}

	function renderMeters(data) {
		var n = data.n || 0;
		var q = typeof data.query_ms === 'number' ? data.query_ms : 0;
		var c = typeof data.construct_ms === 'number' ? data.construct_ms : 0;
		var w = typeof data.warm_ms === 'number' ? data.warm_ms : 0;
		var wr = typeof data.write_ms === 'number' ? data.write_ms : 0;
		var cur = data.op || op;
		var html =
			'<span class="meter meter--cool"><strong>' + n + '</strong> n</span>' +
			'<span class="meter"><strong>' + fmt(c) + '</strong> ms construct</span>';
		if (cur === 'warm' || w > 0) {
			html +=
				'<span class="meter"><strong>' + fmt(q) + '</strong> ms cold</span>' +
				'<span class="meter meter--live"><strong>' + fmt(w) + '</strong> ms warm</span>';
		} else if (cur === 'set' || cur === 'new_' || cur === 'delete') {
			html += '<span class="meter meter--hot"><strong>' + fmt(wr) + '</strong> ms ' + escapeHtml(cur) + '</span>';
		} else if (cur === 'construct' || cur === 'suite') {
			/* construct is the headline clock */
		} else {
			html += '<span class="meter"><strong>' + fmt(q) + '</strong> ms ' + escapeHtml(cur) + '</span>';
		}
		html +=
			'<span class="meter">' + escapeHtml(cur) + '</span>' +
			'<span class="meter">' + (data.sidecar ? 'sidecar' : 'scan') + '</span>' +
			'<span class="meter">' + (data.recipe ? 'recipe' : 'rows') + '</span>';
		metersEl.innerHTML = html;
	}

	function renderOps(ops) {
		if (!ops || !ops.length) return '';
		var rows = ops.map(function (row) {
			return '<div class="result__meta"><span>' + escapeHtml(row.name) + '</span><span><strong>' +
				fmt(row.ms) + '</strong> ms' +
				(row.n ? ' · n=' + row.n : '') +
				(row.st ? ' · st=' + row.st : '') +
				'</span></div>';
		}).join('');
		return '<article class="result">' + rows + '</article>';
	}

	function render(data) {
		renderMeters(data);
		if (!data.ok) {
			setStatus('is-err');
			resultsEl.innerHTML = '<p class="error">' + escapeHtml(data.error || 'Query failed.') + '</p>';
			return;
		}
		setStatus('');
		var opens = data.opens != null ? data.opens : '—';
		var ver = data.version || '';
		var body = renderOps(data.ops);
		if (!body) {
			body =
				'<article class="result"><div class="result__meta"><span>' +
				escapeHtml(ver) + '</span><span>' + escapeHtml(data.fixture || fixture) + '</span></div>' +
				'<code>' + escapeHtml(data.op || op) +
				(data.query ? ' ' + escapeHtml(data.query) : '') + '\n' +
				'n=' + (data.n || 0) + ' opens=' + opens +
				(data.bytes ? ' bytes=' + data.bytes : '') +
				(data.st ? ' st=' + data.st : '') +
				'</code></article>';
		} else if (ver) {
			body = '<p class="tiny-hint">' + escapeHtml(ver) + ' · ' + escapeHtml(data.fixture || fixture) +
				' · writes not saved</p>' + body;
		}
		resultsEl.innerHTML = body;
	}

	function request() {
		var my = ++seq;
		setStatus('is-wait');
		fetch('query.php', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({
				fixture: fixture,
				query: queryEl.value,
				write: writeEl.value,
				op: op
			})
		})
			.then(function (res) { return res.text(); })
			.then(function (text) {
				if (my !== seq) return;
				var data;
				try { data = JSON.parse(text); }
				catch (e) {
					render({ ok: false, error: 'Server returned a non-JSON response.', n: 0, query_ms: 0, construct_ms: 0 });
					return;
				}
				render(data);
			})
			.catch(function (err) {
				if (my !== seq) return;
				render({ ok: false, error: err.message || 'Network error', n: 0, query_ms: 0, construct_ms: 0 });
			});
	}

	function schedule() {
		if (mutating[op]) return;
		clearTimeout(timer);
		setStatus('is-wait');
		timer = setTimeout(request, 200);
	}

	queryEl.addEventListener('input', schedule);

	applyBtn.addEventListener('click', function () {
		request();
	});

	document.querySelectorAll('[data-fixture]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			fixture = btn.getAttribute('data-fixture');
			document.querySelectorAll('[data-fixture]').forEach(function (b) {
				b.classList.toggle('is-on', b === btn);
			});
			if (!mutating[op]) request();
		});
	});

	document.querySelectorAll('#op-modes [data-op]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			setOp(btn.getAttribute('data-op'), !mutating[btn.getAttribute('data-op')]);
		});
	});

	document.querySelectorAll('.chip-row .chip').forEach(function (chip) {
		chip.addEventListener('click', function () {
			document.querySelectorAll('.chip-row .chip').forEach(function (c) {
				c.classList.toggle('is-on', c === chip);
			});
			if (chip.hasAttribute('data-query')) queryEl.value = chip.getAttribute('data-query');
			if (chip.hasAttribute('data-write')) writeEl.value = chip.getAttribute('data-write');
			var next = chip.getAttribute('data-op') || op;
			setOp(next, false);
			if (mutating[next]) {
				/* Writes and the paper suite wait for Run / Write. */
				return;
			}
			request();
		});
	});

	setOp(op, false);
	if (document.querySelector('[data-fixture]')) request();
	else render({ ok: false, error: 'No fixtures available.', n: 0, query_ms: 0, construct_ms: 0 });
})();
