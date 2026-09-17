<?php
require __DIR__.'/../../O.php';
$XMLdata = <<<'XML'
<app>
	<message>Hello world</message>
</app>
XML;
$O = new O($XMLdata, false);
?>
<h1 id="message"><?=$O->_('message')?></h1>
<input id="nextMessage" value="Hello">
<button onclick="go()">Update</button>
<script>
function go() {
	fetch('ajap.php', {
		method: 'POST',
		headers: {'Content-Type': 'application/json'},
		body: JSON.stringify({app: LAB_APP, var: 'XMLdata', action: 'set', query: 'message', write: nextMessage.value})
	}).then(function (response) { return response.json(); }).then(function (data) {
		if (parent !== window) parent.postMessage({type: 'lab-source', app: LAB_APP, var: 'XMLdata', xml: data.xml}, location.origin);
		message.textContent = nextMessage.value;
	});
}
</script>
<style>
body { font-family: sans-serif; padding: 1.2em; }
input, button { font-size: 1em; margin-right: .4em; }
</style>
