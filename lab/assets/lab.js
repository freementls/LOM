(function () {
	var editor = document.getElementById('code');
	var highlightEl = document.getElementById('code-hl');
	var linesEl = document.getElementById('code-lines');
	var iframe = document.getElementById('app-frame');
	var pillsEl = document.getElementById('pills');
	var addBtn = document.getElementById('add-app');
	var resetBtn = document.getElementById('reset-app');
	var statusDot = document.getElementById('status-dot');
	var statusLine = document.getElementById('status-line');
	var newDlg = document.getElementById('new-app');
	var newName = document.getElementById('new-name');
	var newForm = document.getElementById('new-form');

	var currentSlug = document.body.getAttribute('data-app') || 'hello';
	var applyingXml = false;
	var saveTimer = null;
	var saveSeq = 0;
	var shipped = { hello: true, approval: true };

	var PHP_KW = /^(and|or|xor|as|break|case|catch|class|const|continue|declare|default|do|else|elseif|endfor|endforeach|endif|endswitch|endwhile|extends|final|finally|for|foreach|function|global|if|include|include_once|instanceof|insteadof|interface|namespace|new|private|protected|public|require|require_once|return|static|switch|throw|trait|try|use|var|while|yield|true|false|null|array|echo|print|isset|unset|empty)$/;
	var JS_KW = /^(break|case|catch|class|const|continue|debugger|default|delete|do|else|export|extends|finally|for|function|if|import|in|instanceof|let|new|return|super|switch|this|throw|try|typeof|var|void|while|with|yield|true|false|null|undefined|async|await)$/;

	function escapeHtml(s) {
		return String(s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function span(cls, text) {
		return '<span class="' + cls + '">' + escapeHtml(text) + '</span>';
	}

	function highlightXml(text) {
		return escapeHtml(text)
			.replace(/(&lt;\/?)([\w:.-]+)/g, '$1<span class="x-tag">$2</span>')
			.replace(/([\w:.-]+)=(&quot;.*?&quot;)/g, '<span class="x-attr">$1</span>=<span class="x-val">$2</span>');
	}

	function readQuoted(src, i) {
		var q = src.charAt(i);
		var out = q;
		i++;
		while (i < src.length) {
			var ch = src.charAt(i);
			out += ch;
			if (ch === '\\' && i + 1 < src.length) {
				out += src.charAt(i + 1);
				i += 2;
				continue;
			}
			if (ch === q) {
				return { text: out, next: i + 1 };
			}
			i++;
		}
		return { text: out, next: i };
	}

	function highlightCode(src, kind) {
		var kw = kind === 'js' ? JS_KW : PHP_KW;
		var out = '';
		var i = 0;
		var n = src.length;
		while (i < n) {
			var ch = src.charAt(i);
			var two = src.slice(i, i + 2);
			if (ch === '/' && src.charAt(i + 1) === '/') {
				var end = src.indexOf('\n', i);
				if (end === -1) end = n;
				out += span('tok-com', src.slice(i, end));
				i = end;
				continue;
			}
			if (kind === 'php' && ch === '#') {
				var hashEnd = src.indexOf('\n', i);
				if (hashEnd === -1) hashEnd = n;
				out += span('tok-com', src.slice(i, hashEnd));
				i = hashEnd;
				continue;
			}
			if (two === '/*') {
				var cend = src.indexOf('*/', i + 2);
				cend = cend === -1 ? n : cend + 2;
				out += span('tok-com', src.slice(i, cend));
				i = cend;
				continue;
			}
			if (ch === '\'' || ch === '"') {
				var quoted = readQuoted(src, i);
				out += span('tok-str', quoted.text);
				i = quoted.next;
				continue;
			}
			if (ch === '$') {
				var vm = src.slice(i).match(/^\$[A-Za-z_][A-Za-z0-9_]*/);
				if (vm) {
					out += span(vm[0].indexOf('$XML') === 0 ? 'tok-xmlvar' : 'tok-var', vm[0]);
					i += vm[0].length;
					continue;
				}
			}
			if (/[A-Za-z_]/.test(ch)) {
				var wm = src.slice(i).match(/^[A-Za-z_][A-Za-z0-9_]*/);
				var word = wm ? wm[0] : ch;
				if (kw.test(word)) out += span('tok-kw', word);
				else out += escapeHtml(word);
				i += word.length;
				continue;
			}
			if (kind === 'html' && ch === '<' && /[a-zA-Z\/!?]/.test(src.charAt(i + 1) || '')) {
				var tagEnd = src.indexOf('>', i);
				if (tagEnd === -1) tagEnd = n - 1;
				out += span('tok-tag', src.slice(i, tagEnd + 1));
				i = tagEnd + 1;
				continue;
			}
			if (kind === 'php' && src.slice(i, i + 5) === '<?php') {
				out += span('tok-kw', '<?php');
				i += 5;
				continue;
			}
			if (kind === 'php' && src.slice(i, i + 3) === '<?=') {
				out += span('tok-kw', '<?=');
				i += 3;
				continue;
			}
			if (kind === 'php' && two === '?>') {
				out += span('tok-kw', '?>');
				i += 2;
				continue;
			}
			out += escapeHtml(ch);
			i++;
		}
		return out;
	}

	function highlightNowdoc(block) {
		var m = block.match(/^(\$XML[A-Za-z0-9_]*\s*=\s*<<<'XML'\r?\n)([\s\S]*)(\r?\nXML;)$/);
		if (!m) return highlightCode(block, 'php');
		return highlightCode(m[1], 'php') + highlightXml(m[2]) + highlightCode(m[3], 'php');
	}

	function highlightJsBlock(block) {
		var open = block.match(/^<script\b[^>]*>/i);
		var close = block.match(/<\/script>$/i);
		if (!open || !close) return highlightCode(block, 'js');
		var inner = block.slice(open[0].length, block.length - close[0].length);
		return span('tok-tag', open[0]) + highlightCode(inner, 'js') + span('tok-tag', close[0]);
	}

	function collectRegions(src) {
		function scan(re) {
			var out = [];
			var m;
			re.lastIndex = 0;
			while ((m = re.exec(src))) {
				out.push({ start: m.index, end: m.index + m[0].length });
			}
			return out;
		}
		var xmls = scan(/\$XML[A-Za-z0-9_]*\s*=\s*<<<'XML'\r?\n[\s\S]*?\r?\nXML;/g);
		var phps = scan(/<\?(?:php|=)?[\s\S]*?\?>/g);
		if (src.slice(0, 5) === '<?php' && src.indexOf('?>') === -1) {
			phps = [{ start: 0, end: src.length }];
		}
		var scripts = scan(/<script\b[^>]*>[\s\S]*?<\/script>/gi);
		var marked = [];
		xmls.forEach(function (r) {
			marked.push({ start: r.start, end: r.end, kind: 'xml' });
		});
		scripts.forEach(function (r) {
			if (xmls.some(function (x) { return r.start < x.end && r.end > x.start; })) return;
			marked.push({ start: r.start, end: r.end, kind: 'js' });
		});
		phps.forEach(function (p) {
			var cursor = p.start;
			xmls.filter(function (x) { return x.start >= p.start && x.end <= p.end; })
				.sort(function (a, b) { return a.start - b.start; })
				.forEach(function (x) {
					if (x.start > cursor) marked.push({ start: cursor, end: x.start, kind: 'php' });
					cursor = Math.max(cursor, x.end);
				});
			if (cursor < p.end) marked.push({ start: cursor, end: p.end, kind: 'php' });
		});
		marked.sort(function (a, b) { return a.start - b.start; });
		var regions = [];
		var at = 0;
		marked.forEach(function (r) {
			if (r.start > at) regions.push({ kind: 'html', text: src.slice(at, r.start) });
			regions.push({ kind: r.kind, text: src.slice(r.start, r.end) });
			at = r.end;
		});
		if (at < src.length) regions.push({ kind: 'html', text: src.slice(at) });
		return regions;
	}

	function highlightSource(src) {
		return collectRegions(src).map(function (r) {
			if (r.kind === 'xml') return highlightNowdoc(r.text);
			if (r.kind === 'js') return highlightJsBlock(r.text);
			if (r.kind === 'html') return highlightCode(r.text, 'html');
			return highlightCode(r.text, 'php');
		}).join('');
	}

	function wrapSpans(html, hits, cls) {
		var out = '';
		var si = 0;
		var on = false;
		for (var h = 0; h < html.length; h++) {
			if (html.charAt(h) === '<') {
				if (on) {
					out += '</span>';
					on = false;
				}
				var gt = html.indexOf('>', h);
				out += html.slice(h, gt + 1);
				h = gt;
				continue;
			}
			var want = !!hits[si];
			if (want && !on) {
				out += '<span class="' + cls + '">';
				on = true;
			} else if (!want && on) {
				out += '</span>';
				on = false;
			}
			if (html.charAt(h) === '&') {
				var semi = html.indexOf(';', h);
				if (semi !== -1) {
					out += html.slice(h, semi + 1);
					h = semi;
					si++;
					continue;
				}
			}
			out += html.charAt(h);
			si++;
		}
		if (on) out += '</span>';
		return out;
	}

	function wrapHits(html, src, q, skipStart, skipEnd) {
		if (!q) return html;
		var hits = {};
		var i = 0;
		while ((i = src.indexOf(q, i)) !== -1) {
			if (i !== skipStart || i + q.length !== skipEnd) {
				for (var k = 0; k < q.length; k++) hits[i + k] = true;
			}
			i += q.length;
		}
		return wrapSpans(html, hits, 'tok-hit');
	}

	function wrapError(html, src) {
		var range = errorRange(src);
		if (!range) return html;
		var hits = {};
		for (var i = range.start; i < range.end; i++) hits[i] = true;
		return wrapSpans(html, hits, 'tok-err');
	}

	function paint() {
		var src = editor.value;
		var html = highlightSource(src);
		var a = editor.selectionStart;
		var b = editor.selectionEnd;
		var q = a === b ? '' : src.slice(Math.min(a, b), Math.max(a, b));
		if (q && q.length <= 200 && !/^\s+$/.test(q)) {
			html = wrapHits(html, src, q, Math.min(a, b), Math.max(a, b));
		}
		html = wrapError(html, src);
		highlightEl.innerHTML = html + '\n';
		paintLines(src);
		syncScroll();
	}

	var lastLineCount = 0;
	var lastErrorLine = 0;
	var errorLine = 0;
	var errorToken = '';

	function parseErrorLine(msg) {
		var m = String(msg || '').match(/\bon line (\d+)\b/i);
		if (!m) return 0;
		return parseInt(m[1], 10) || 0;
	}

	function parseErrorToken(msg) {
		msg = String(msg || '');
		var m = msg.match(/unexpected token "([^"]+)"/i)
			|| msg.match(/unexpected identifier "([^"]+)"/i)
			|| msg.match(/unexpected variable "([^"]+)"/i)
			|| msg.match(/unexpected '([^']+)'/)
			|| msg.match(/unexpected ([^\s,]+)/);
		if (!m) return '';
		var tok = m[1];
		if (!tok || tok === 'end' || /^T_/.test(tok) || tok === 'token') return '';
		if (tok === 'end of file') return '';
		return tok.replace(/^["']|["']$/g, '');
	}

	function errorRange(src) {
		if (!errorLine) return null;
		var start = 0;
		var ln = 1;
		while (ln < errorLine) {
			var nl = src.indexOf('\n', start);
			if (nl === -1) return null;
			start = nl + 1;
			ln++;
		}
		var end = src.indexOf('\n', start);
		if (end === -1) end = src.length;
		var line = src.slice(start, end);
		if (errorToken) {
			var idx = /[\])};,]/.test(errorToken) ? line.lastIndexOf(errorToken) : line.indexOf(errorToken);
			if (idx !== -1) return { start: start + idx, end: start + idx + errorToken.length };
		}
		var j = line.length;
		while (j > 0 && /\s/.test(line.charAt(j - 1))) j--;
		if (j === 0) return null;
		return { start: start + j - 1, end: start + j };
	}

	function setErrorLine(n, token) {
		errorLine = n > 0 ? n : 0;
		errorToken = errorLine ? (token || '') : '';
		lastLineCount = 0;
		paint();
	}

	function paintLines(src) {
		var n = 1;
		for (var i = 0; i < src.length; i++) {
			if (src.charAt(i) === '\n') n++;
		}
		if (errorLine > n) errorLine = 0;
		if (n === lastLineCount && errorLine === lastErrorLine) return;
		lastLineCount = n;
		lastErrorLine = errorLine;
		var out = '';
		for (var line = 1; line <= n; line++) {
			if (line === errorLine) out += '<span class="editor__line--err">' + line + '</span>\n';
			else out += line + '\n';
		}
		linesEl.innerHTML = out;
		linesEl.style.minWidth = Math.max(2, String(n).length) + 'ch';
	}

	var syncQueued = false;
	function syncScroll() {
		if (syncQueued) return;
		syncQueued = true;
		requestAnimationFrame(function () {
			syncQueued = false;
			highlightEl.style.transform = 'translate(' + (-editor.scrollLeft) + 'px,' + (-editor.scrollTop) + 'px)';
			linesEl.style.transform = 'translateY(' + (-editor.scrollTop) + 'px)';
		});
	}

	function setStatus(kind, msg) {
		statusDot.classList.remove('is-wait', 'is-err');
		if (kind) statusDot.classList.add(kind);
		statusLine.classList.toggle('is-err', kind === 'is-err');
		statusLine.textContent = msg || '';
	}

	function findXmlVar(source, varName) {
		var name = varName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
		var nowdoc = new RegExp('(\\$' + name + "\\s*=\\s*<<<'XML'\\r?\\n)([\\s\\S]*?)(\\r?\\nXML;)");
		var m = nowdoc.exec(source);
		if (m) {
			return { start: m.index + m[1].length, end: m.index + m[1].length + m[2].length, style: 'nowdoc' };
		}
		var single = new RegExp('(\\$' + name + "\\s*=\\s*')((?:\\\\.|[^'])*)(')");
		m = single.exec(source);
		if (m) {
			return { start: m.index + m[1].length, end: m.index + m[1].length + m[2].length, style: 'single' };
		}
		var dbl = new RegExp('(\\$' + name + '\\s*=\\s*")((?:\\\\.|[^"])*)(")');
		m = dbl.exec(source);
		if (m) {
			return { start: m.index + m[1].length, end: m.index + m[1].length + m[2].length, style: 'double' };
		}
		return null;
	}

	function quoteXmlForPhp(xml, style) {
		if (style === 'single') return String(xml).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
		if (style === 'double') return String(xml).replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\$/g, '\\$');
		return xml;
	}

	function patchXmlVar(varName, xml) {
		if (xml == null) return;
		var source = editor.value;
		var found = findXmlVar(source, varName);
		if (!found) return;
		xml = quoteXmlForPhp(xml, found.style);
		var start = found.start;
		var end = found.end;
		if (source.slice(start, end) === xml) return;
		var selStart = editor.selectionStart;
		var selEnd = editor.selectionEnd;
		var next = source.slice(0, start) + xml + source.slice(end);
		var delta = xml.length - (end - start);
		applyingXml = true;
		editor.value = next;
		if (selStart >= end) {
			selStart += delta;
			selEnd += delta;
		} else if (selStart > start) {
			selStart = start + xml.length;
			selEnd = selStart;
		}
		try {
			editor.setSelectionRange(selStart, selEnd);
		} catch (e) {}
		applyingXml = false;
		paint();
		syncScroll();
	}

	function setResetVisible() {
		resetBtn.hidden = !shipped[currentSlug];
	}

	function markPill(slug) {
		pillsEl.querySelectorAll('[data-app]').forEach(function (btn) {
			btn.classList.toggle('is-on', btn.getAttribute('data-app') === slug);
		});
	}

	function addPill(slug, title) {
		if (pillsEl.querySelector('[data-app="' + slug + '"]')) return;
		var wrap = document.createElement('span');
		wrap.className = 'chip';
		wrap.setAttribute('data-app', slug);
		var nameBtn = document.createElement('button');
		nameBtn.type = 'button';
		nameBtn.className = 'chip__name';
		nameBtn.textContent = title;
		var xBtn = document.createElement('button');
		xBtn.type = 'button';
		xBtn.className = 'chip__x';
		xBtn.setAttribute('aria-label', 'Delete ' + title);
		xBtn.textContent = '×';
		wrap.appendChild(nameBtn);
		wrap.appendChild(xBtn);
		pillsEl.insertBefore(wrap, addBtn);
		bindPill(wrap);
	}

	function bindPill(wrap) {
		var slug = wrap.getAttribute('data-app');
		var nameBtn = wrap.querySelector('.chip__name');
		var xBtn = wrap.querySelector('.chip__x');
		if (nameBtn) nameBtn.addEventListener('click', function () { selectApp(slug); });
		if (xBtn) xBtn.addEventListener('click', function (e) {
			e.stopPropagation();
			deleteApp(slug);
		});
	}

	function firstPillSlug() {
		var wrap = pillsEl.querySelector('[data-app]');
		return wrap ? wrap.getAttribute('data-app') : '';
	}

	function showEmpty() {
		currentSlug = '';
		document.body.setAttribute('data-app', '');
		history.replaceState(null, '', location.pathname + location.search);
		applyingXml = true;
		editor.value = '';
		applyingXml = false;
		paint();
		setResetVisible();
		iframe.src = 'about:blank';
		setStatus('', 'No apps');
	}

	function deleteApp(slug) {
		clearTimeout(saveTimer);
		setStatus('is-wait', 'Deleting…');
		fetch('save.php', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ app: slug, delete: true })
		})
			.then(function (res) { return res.json(); })
			.then(function (data) {
				if (!data.ok) throw new Error(data.error || 'Delete failed.');
				var wrap = pillsEl.querySelector('[data-app="' + slug + '"]');
				if (wrap) wrap.remove();
				if (currentSlug !== slug) {
					setStatus('', 'Deleted');
					return;
				}
				var next = firstPillSlug();
				if (next) return loadApp(next);
				showEmpty();
			})
			.catch(function (err) {
				setStatus('is-err', err.message || 'Delete failed.');
			});
	}

	function runFrame() {
		iframe.src = 'run.php?app=' + encodeURIComponent(currentSlug) + '&t=' + Date.now();
	}

	function loadApp(slug, opts) {
		opts = opts || {};
		return fetch('save.php?app=' + encodeURIComponent(slug))
			.then(function (res) { return res.json(); })
			.then(function (data) {
				if (!data.ok) throw new Error(data.error || 'Could not load app.');
				currentSlug = slug;
				document.body.setAttribute('data-app', slug);
				if (location.hash.replace(/^#/, '') !== slug) {
					history.replaceState(null, '', '#' + slug);
				}
				applyingXml = true;
				editor.value = data.source || '';
				applyingXml = false;
				paint();
				markPill(slug);
				setResetVisible();
				setErrorLine(0);
				if (opts.run !== false) runFrame();
				setStatus('', (data.title || slug) + ' loaded');
				return data;
			});
	}

	function selectApp(slug) {
		flushSave(function () { loadApp(slug); });
	}

	function saveSource(thenReload) {
		var my = ++saveSeq;
		var slug = currentSlug;
		if (!slug) return Promise.resolve();
		var source = editor.value;
		setStatus('is-wait', 'Saving…');
		return fetch('save.php', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ app: slug, source: source })
		})
			.then(function (res) { return res.json(); })
			.then(function (data) {
				if (my !== saveSeq) return data;
				if (!data.ok) {
					setStatus('is-err', data.error || 'Save failed.');
					return data;
				}
				if (data.lint) {
					setStatus('is-err', data.lint);
					setErrorLine(parseErrorLine(data.lint), parseErrorToken(data.lint));
					return data;
				}
				setErrorLine(0);
				setStatus('', 'Saved');
				if (thenReload && slug === currentSlug) runFrame();
				return data;
			})
			.catch(function (err) {
				if (my !== saveSeq) return;
				setStatus('is-err', err.message || 'Save failed.');
			});
	}

	function scheduleSave() {
		clearTimeout(saveTimer);
		setStatus('is-wait', 'Editing…');
		saveTimer = setTimeout(function () { saveSource(true); }, 400);
	}

	function flushSave(done) {
		clearTimeout(saveTimer);
		saveSource(false).then(function () {
			if (done) done();
		});
	}

	var findBar = document.getElementById('findbar');
	var findQ = document.getElementById('find-q');
	var findR = document.getElementById('find-r');

	function posToLine(text, pos) {
		var n = 0;
		var i = 0;
		while (i < pos) {
			var nl = text.indexOf('\n', i);
			if (nl === -1 || nl >= pos) break;
			n++;
			i = nl + 1;
		}
		return n;
	}

	function moveLine(dir) {
		var text = editor.value;
		var selStart = editor.selectionStart;
		var selEnd = editor.selectionEnd;
		var fromPos = Math.min(selStart, selEnd);
		var toPos = Math.max(selStart, selEnd);
		if (toPos > fromPos && text.charAt(toPos - 1) === '\n') toPos--;
		var from = posToLine(text, fromPos);
		var to = posToLine(text, toPos);
		var lines = text.split('\n');
		var count = to - from + 1;
		if (dir < 0 && from === 0) return;
		if (dir > 0 && to >= lines.length - 1) return;
		var adj = dir < 0 ? lines[from - 1] : lines[to + 1];
		var delta = dir < 0 ? -(adj.length + 1) : adj.length + 1;
		var block = lines.splice(from, count);
		lines.splice.apply(lines, [dir < 0 ? from - 1 : from + 1, 0].concat(block));
		editor.value = lines.join('\n');
		editor.setSelectionRange(selStart + delta, selEnd + delta);
		paint();
		if (!applyingXml) scheduleSave();
	}

	function scrollSelIntoView() {
		var before = editor.value.slice(0, editor.selectionStart);
		var line = before.split('\n').length;
		var lh = parseFloat(getComputedStyle(editor).lineHeight);
		if (!lh || isNaN(lh)) lh = 18;
		editor.scrollTop = Math.max(0, (line - 1) * lh - editor.clientHeight / 3);
		syncScroll();
	}

	function openFind() {
		findBar.hidden = false;
		var sel = editor.value.slice(editor.selectionStart, editor.selectionEnd);
		if (sel && sel.indexOf('\n') === -1) findQ.value = sel;
		findQ.focus();
		findQ.select();
	}

	function closeFind() {
		findBar.hidden = true;
		editor.focus();
	}

	function findNext(back) {
		var q = findQ.value;
		if (!q) return;
		var text = editor.value;
		var idx;
		if (back) {
			idx = text.lastIndexOf(q, editor.selectionStart - 1);
			if (idx === -1) idx = text.lastIndexOf(q);
		} else {
			idx = text.indexOf(q, editor.selectionEnd);
			if (idx === -1) idx = text.indexOf(q);
		}
		if (idx === -1) {
			setStatus('is-err', 'No match');
			return;
		}
		editor.focus();
		editor.setSelectionRange(idx, idx + q.length);
		paint();
		scrollSelIntoView();
		setStatus('', 'Match');
	}

	function replaceOne() {
		var q = findQ.value;
		if (!q) return;
		var start = editor.selectionStart;
		var end = editor.selectionEnd;
		if (editor.value.slice(start, end) !== q) {
			findNext(false);
			return;
		}
		editor.setRangeText(findR.value, start, end, 'end');
		paint();
		if (!applyingXml) scheduleSave();
		findNext(false);
	}

	function replaceAll() {
		var q = findQ.value;
		if (!q) return;
		var text = editor.value;
		if (text.indexOf(q) === -1) {
			setStatus('is-err', 'No match');
			return;
		}
		var parts = text.split(q);
		editor.value = parts.join(findR.value);
		paint();
		if (!applyingXml) scheduleSave();
		setStatus('', (parts.length - 1) + ' replaced');
	}

	function createApp(name) {
		return fetch('save.php', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ create: name })
		})
			.then(function (res) { return res.json(); })
			.then(function (data) {
				if (!data.ok) throw new Error(data.error || 'Could not create app.');
				addPill(data.slug, data.title);
				return loadApp(data.slug);
			});
	}

	editor.addEventListener('input', function () {
		paint();
		if (!applyingXml) scheduleSave();
	});
	['scroll', 'keydown'].forEach(function (evt) {
		editor.addEventListener(evt, syncScroll);
	});
	['keyup', 'click', 'select', 'mouseup'].forEach(function (evt) {
		editor.addEventListener(evt, function () {
			paint();
		});
	});
	function isWordChar(ch) {
		return /[A-Za-z0-9_$#]/.test(ch);
	}
	function selectWholeWord() {
		var text = editor.value;
		var start = editor.selectionStart;
		var end = editor.selectionEnd;
		while (start > 0 && isWordChar(text.charAt(start - 1))) start--;
		while (end < text.length && isWordChar(text.charAt(end))) end++;
		if (end > start) editor.setSelectionRange(start, end);
		paint();
	}
	editor.addEventListener('dblclick', function () {
		requestAnimationFrame(selectWholeWord);
	});
	function lineBounds(text, pos) {
		var start = text.lastIndexOf('\n', pos - 1) + 1;
		var end = text.indexOf('\n', pos);
		if (end === -1) end = text.length;
		return { start: start, end: end };
	}

	function indentEnd(text, start, end) {
		var i = start;
		while (i < end && (text.charAt(i) === ' ' || text.charAt(i) === '\t')) i++;
		return i;
	}

	function moveCaret(e, dest) {
		if (e.shiftKey) {
			var anchor = editor.selectionStart === editor.selectionEnd
				? editor.selectionStart
				: (editor.selectionDirection === 'backward' ? editor.selectionEnd : editor.selectionStart);
			if (dest <= anchor) editor.setSelectionRange(dest, anchor, 'backward');
			else editor.setSelectionRange(anchor, dest, 'forward');
		} else {
			editor.setSelectionRange(dest, dest);
		}
		paint();
	}

	editor.addEventListener('keydown', function (e) {
		if (e.key === 'Tab') {
			e.preventDefault();
			var start = editor.selectionStart;
			var end = editor.selectionEnd;
			editor.setRangeText('\t', start, end, 'end');
			paint();
			if (!applyingXml) scheduleSave();
			return;
		}
		if (e.key === 'Home' && !e.ctrlKey && !e.metaKey) {
			e.preventDefault();
			var text = editor.value;
			var caret = e.shiftKey && editor.selectionDirection === 'backward'
				? editor.selectionStart
				: (e.shiftKey ? editor.selectionEnd : editor.selectionStart);
			var bounds = lineBounds(text, caret);
			var afterIndent = indentEnd(text, bounds.start, bounds.end);
			moveCaret(e, caret === afterIndent && afterIndent !== bounds.start ? bounds.start : afterIndent);
			return;
		}
		if (e.key === 'End' && !e.ctrlKey && !e.metaKey) {
			e.preventDefault();
			var endCaret = e.shiftKey && editor.selectionDirection === 'backward'
				? editor.selectionStart
				: (e.shiftKey ? editor.selectionEnd : editor.selectionEnd);
			moveCaret(e, lineBounds(editor.value, endCaret).end);
			return;
		}
		if (e.key === 'Enter' && !e.ctrlKey && !e.metaKey && !e.altKey) {
			e.preventDefault();
			var src = editor.value;
			var from = editor.selectionStart;
			var to = editor.selectionEnd;
			var line = lineBounds(src, from);
			var indent = src.slice(line.start, indentEnd(src, line.start, line.end));
			editor.setRangeText('\n' + indent, from, to, 'end');
			paint();
			if (!applyingXml) scheduleSave();
			return;
		}
		if ((e.ctrlKey || e.metaKey) && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
			e.preventDefault();
			moveLine(e.key === 'ArrowUp' ? -1 : 1);
		}
	});

	document.addEventListener('keydown', function (e) {
		if ((e.ctrlKey || e.metaKey) && (e.key === 'f' || e.key === 'F')) {
			e.preventDefault();
			openFind();
			return;
		}
		if (e.key === 'Escape' && !findBar.hidden) {
			e.preventDefault();
			closeFind();
		}
	});

	findQ.addEventListener('keydown', function (e) {
		if (e.key === 'Enter') {
			e.preventDefault();
			findNext(e.shiftKey);
		}
	});
	findR.addEventListener('keydown', function (e) {
		if (e.key === 'Enter') {
			e.preventDefault();
			if (e.ctrlKey || e.metaKey) replaceAll();
			else replaceOne();
		}
	});
	document.getElementById('find-next').addEventListener('click', function () { findNext(false); });
	document.getElementById('find-prev').addEventListener('click', function () { findNext(true); });
	document.getElementById('find-rep').addEventListener('click', replaceOne);
	document.getElementById('find-all').addEventListener('click', replaceAll);
	document.getElementById('find-close').addEventListener('click', closeFind);

	pillsEl.querySelectorAll('[data-app]').forEach(bindPill);

	addBtn.addEventListener('click', function () {
		newName.value = '';
		if (typeof newDlg.showModal === 'function') newDlg.showModal();
		newName.focus();
	});

	document.getElementById('new-cancel').addEventListener('click', function () {
		newDlg.close();
	});

	newForm.addEventListener('submit', function (e) {
		e.preventDefault();
		var name = newName.value.trim();
		if (!name) return;
		newDlg.close();
		createApp(name).catch(function (err) {
			setStatus('is-err', err.message || 'Create failed.');
		});
	});

	resetBtn.addEventListener('click', function () {
		if (!shipped[currentSlug]) return;
		clearTimeout(saveTimer);
		fetch('save.php', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ app: currentSlug, reset: true })
		})
			.then(function (res) { return res.json(); })
			.then(function (data) {
				if (!data.ok) throw new Error(data.error || 'Reset failed.');
				applyingXml = true;
				editor.value = data.source || '';
				applyingXml = false;
				paint();
				setErrorLine(0);
				runFrame();
				setStatus('', 'Reset to template');
			})
			.catch(function (err) {
				setStatus('is-err', err.message || 'Reset failed.');
			});
	});

	window.addEventListener('message', function (e) {
		if (e.origin !== location.origin) return;
		if (!e.data || e.data.type !== 'lab-source') return;
		if (e.data.app && e.data.app !== currentSlug) return;
		patchXmlVar(e.data.var, e.data.xml);
		setStatus('', '$' + e.data.var + ' updated');
	});

	window.addEventListener('hashchange', function () {
		var slug = location.hash.replace(/^#/, '');
		if (slug && slug !== currentSlug) selectApp(slug);
	});

	paint();
	setResetVisible();
	var hash = location.hash.replace(/^#/, '');
	if (hash && hash !== currentSlug) {
		loadApp(hash).catch(function () {
			runFrame();
		});
	} else {
		runFrame();
		setStatus('', 'Ready');
	}
})();
