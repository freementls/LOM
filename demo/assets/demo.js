(function () {
	var xmlEl = document.getElementById('xml');
	var queryEl = document.getElementById('query');
	var writeEl = document.getElementById('write');
	var resultsEl = document.getElementById('results');
	var metersEl = document.getElementById('meters');
	var statusEl = document.getElementById('status-dot');
	var resetBtn = document.getElementById('reset-xml');
	var encodeBtn = document.getElementById('encode-sel');
	var applyBtn = document.getElementById('apply-write');
	var defaultXml = xmlEl.getAttribute('data-default') || xmlEl.value;
	var defaultQuery = queryEl.getAttribute('data-default') || queryEl.value;
	var mode = 'tagged';
	var action = 'set';
	var timer = null;
	var seq = 0;
	var applyingXml = false;

	function setStatus(kind) {
		statusEl.classList.remove('is-wait', 'is-err');
		if (kind) statusEl.classList.add(kind);
	}

	function escapeHtml(s) {
		return String(s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function highlightXml(text) {
		var safe = escapeHtml(text);
		return safe
			.replace(/(&lt;\/?)([\w:.-]+)/g, '$1<span class="x-tag">$2</span>')
			.replace(/([\w:.-]+)=(&quot;.*?&quot;)/g, '<span class="x-attr">$1</span>=<span class="x-val">$2</span>');
	}

	function renderMeters(data) {
		var count = data.count || 0;
		var ms = typeof data.ms === 'number' ? data.ms : 0;
		var cls = count ? 'meter--cool' : 'meter--hot';
		var act = data.applied ? (data.action || action) : 'query';
		metersEl.innerHTML =
			'<span class="meter ' + cls + '"><strong>' + count + '</strong> match' + (count === 1 ? '' : 'es') + '</span>' +
			'<span class="meter"><strong>' + ms + '</strong> ms</span>' +
			'<span class="meter meter--live">' + (mode === 'tagged' ? 'nodes' : 'values') + '</span>' +
			'<span class="meter">' + act + '</span>';
	}

	function render(data) {
		renderMeters(data);
		if (!data.ok) {
			setStatus('is-err');
			resultsEl.innerHTML = '<p class="error">' + escapeHtml(data.error || 'Query failed.') + '</p>';
			return;
		}
		setStatus('');
		if (data.error) {
			resultsEl.innerHTML = '<p class="error">' + escapeHtml(data.error) + '</p>';
			return;
		}
		if (!data.matches || !data.matches.length) {
			resultsEl.innerHTML = '<p class="empty">No matches.</p>';
			return;
		}
		resultsEl.innerHTML = data.matches.map(function (m, i) {
			var offset = m.offset == null ? '—' : '@' + m.offset;
			var body = mode === 'tagged' ? highlightXml(m.text) : escapeHtml(m.text);
			return '<article class="result"><div class="result__meta"><span>#' + (i + 1) + '</span><span>' + offset + '</span></div><code>' + body + '</code></article>';
		}).join('');
	}

	function request(apply) {
		var my = ++seq;
		var sentAction = apply ? action : 'set';
		var sentWrite = apply ? writeEl.value : '';
		if (apply && action === 'delete') {
			sentAction = 'delete';
		}
		setStatus('is-wait');
		fetch('query.php', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({
				xml: xmlEl.value,
				query: queryEl.value,
				write: sentWrite,
				action: sentAction,
				mode: mode
			})
		})
			.then(function (res) { return res.text(); })
			.then(function (text) {
				if (my !== seq) return;
				var data;
				try {
					data = JSON.parse(text);
				} catch (e) {
					render({ ok: false, applied: !!apply, error: 'Server returned a non-JSON response.', matches: [], count: 0, ms: 0 });
					return;
				}
				data.applied = !!apply;
				if (apply && data.ok && data.xml != null && data.xml !== xmlEl.value) {
					applyingXml = true;
					xmlEl.value = data.xml;
					applyingXml = false;
				}
				render(data);
			})
			.catch(function (err) {
				if (my !== seq) return;
				render({ ok: false, applied: !!apply, error: err.message || 'Network error', matches: [], count: 0, ms: 0 });
			});
	}

	function scheduleQuery() {
		clearTimeout(timer);
		setStatus('is-wait');
		timer = setTimeout(function () { request(false); }, 160);
	}

	function applyWrite() {
		if (action !== 'delete' && writeEl.value === '') {
			return;
		}
		clearTimeout(timer);
		request(true);
	}

	function syncWriteButton() {
		if (!applyBtn) return;
		applyBtn.textContent = action === 'delete' ? 'Delete' : 'Write';
		applyBtn.classList.toggle('is-delete', action === 'delete');
	}

	function setAction(next) {
		if (next !== action) {
			writeEl.value = '';
		}
		action = next;
		document.querySelectorAll('[data-action]').forEach(function (b) {
			b.classList.toggle('is-on', b.getAttribute('data-action') === next);
		});
		syncWriteButton();
	}

	xmlEl.addEventListener('input', function () {
		if (!applyingXml) scheduleQuery();
	});
	queryEl.addEventListener('input', scheduleQuery);

	document.querySelectorAll('[data-mode]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			mode = btn.getAttribute('data-mode');
			document.querySelectorAll('[data-mode]').forEach(function (b) {
				b.classList.toggle('is-on', b === btn);
			});
			request(false);
		});
	});

	document.querySelectorAll('[data-action]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			setAction(btn.getAttribute('data-action'));
		});
	});

	if (applyBtn) {
		applyBtn.addEventListener('click', applyWrite);
	}

	document.querySelectorAll('[data-query], [data-write]').forEach(function (chip) {
		chip.addEventListener('click', function () {
			document.querySelectorAll('.chip-row .chip').forEach(function (c) {
				c.classList.toggle('is-on', c === chip);
			});
			if (chip.hasAttribute('data-query')) {
				queryEl.value = chip.getAttribute('data-query');
			}
			if (chip.hasAttribute('data-write-action')) {
				setAction(chip.getAttribute('data-write-action'));
			}
			if (chip.hasAttribute('data-write')) {
				writeEl.value = chip.getAttribute('data-write');
			}
			scheduleQuery();
		});
	});

	if (resetBtn) {
		resetBtn.addEventListener('click', function () {
			xmlEl.value = defaultXml;
			queryEl.value = defaultQuery;
			writeEl.value = '';
			setAction('set');
			request(false);
		});
	}

	if (encodeBtn) {
		encodeBtn.addEventListener('click', function () {
			var start = queryEl.selectionStart;
			var end = queryEl.selectionEnd;
			if (start === end) {
				queryEl.value = queryEl.value.replace(/_/g, '#underscore#');
			} else {
				queryEl.setRangeText(
					queryEl.value.slice(start, end).replace(/_/g, '#underscore#'),
					start,
					end,
					'end'
				);
			}
			request(false);
		});
	}

	syncWriteButton();
	request(false);
})();
