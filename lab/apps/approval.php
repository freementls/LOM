<?php
//require __DIR__.'/../../O.php';
include('../O.php');
$XMLrequests = '
<requests>
	<request id="1"><title>Pens</title><who>Ada</who><status>pending</status></request>
	<request id="2"><title>Travel</title><who>Lin</who><status>pending</status></request>
	<request id="3"><title>Badge</title><who>Mo</who><status>approved</status></request>
	<request id="4"><title>Travel</title><who>Bam</who><status>pending</status></request>
</requests>
';
$XMLapprovers = '
<approvers>
	<approver id="1"><requestId>1</requestId><name>Grace</name><decision></decision></approver>
	<approver id="2"><requestId>2</requestId><name>Sam</name><decision></decision></approver>
	<approver id="3"><requestId>3</requestId><name>Grace</name><decision>approved</decision></approver>
</approvers>
';
$Request = new O($XMLrequests);
$Approvers = new O($XMLapprovers);
$screen = 'requests'; // default
$screen = $_GET['screen'];
$requestId = $_GET['requestId'];
?>
<div id="buttonlist">
<button onclick="go('requests')">Requests</button>
<button onclick="go('new')">New</button>
<button onclick="go('approvers')">Approvers</button>
</div>

<?php if($screen === 'new') { ?>
<p>Title <input id="title"></p>
<p>Who <input id="who"></p>
<button onclick="add()">Save</button>
<?php } elseif($screen === 'approvers') {
	foreach($Approvers->_('approver') as $row) {
		$decision = $Approvers->_('decision', $row);
		echo '<p onclick="go(\'item\',\''.$Approvers->_('requestId', $row).'\')">'.$Approvers->_('name', $row).' on #'.$Approvers->_('requestId', $row).' ('.($decision !== '' ? $decision : 'pending').')</p>';
	}
} elseif($screen === 'item' && $requestId !== '') {
	$request = $Request->_('request@id='.$requestId);
	echo '<p><b>'.$Request->_('title', $request).'</b> — '.$Request->_('who', $request).' ('.$Request->_('status', $request).')</p>';
	foreach($Approvers->_('.approver_requestId='.$requestId) as $row) {
		$decision = $Approvers->_('decision', $row);
		echo '<p>'.$Approvers->_('name', $row).': '.($decision !== '' ? $decision : 'pending').
			' <button onclick="decide(\''.$Approvers->get_attribute('id', $row).'\',\''.$requestId.'\',\'approved\')">ok</button>'.
			' <button onclick="decide(\''.$Approvers->get_attribute('id', $row).'\',\''.$requestId.'\',\'denied\')">no</button></p>';
	}
} else {
	$requestRows = $Request->_('request');
	$titles = $Request->_('title', $requestRows);
	$whoNames = $Request->_('who', $requestRows);
	$statuses = $Request->_('status', $requestRows);
	foreach($titles as $i => $title) {
		echo '<p onclick="go(\'item\',\''.$Request->get_attribute('id', $requestRows[$i]).'\')">'.$title.' — '.$whoNames[$i].' ('.$statuses[$i].')</p>';
	}
} ?>

<script>
function ajap(xmlVar, action, query, write) {
	return fetch('ajap.php', {
		method: 'POST',
		headers: {'Content-Type': 'application/json'},
		body: JSON.stringify({app: LAB_APP, var: xmlVar, action: action, query: query || '', write: write || ''})
	}).then(function (response) { return response.json(); }).then(function (data) {
		if (parent !== window) parent.postMessage({type: 'lab-source', app: LAB_APP, var: xmlVar, xml: data.xml}, location.origin);
		return data;
	});
}
function go(screen, requestId) {
	location = 'run.php?app=' + LAB_APP + '&screen=' + screen + (requestId ? '&requestId=' + requestId : '') + '&time=' + Date.now();
}
function add() {
	var requestId = Date.now();
	ajap('XMLrequests', 'new_', 'requests',
		'<request id="' + requestId + '"><title>' + title.value + '</title><who>' + who.value + '</who><status>pending</status></request>'
	).then(function () {
		return ajap('XMLapprovers', 'new_', 'approvers',
			'<approver id="' + requestId + '"><requestId>' + requestId + '</requestId><name>Grace</name><decision></decision></approver>');
	}).then(function () { go('requests'); });
}
function decide(approverId, requestId, decision) {
	ajap('XMLapprovers', 'set', 'approver@id=' + approverId, 'decision=' + decision).then(function () {
		return ajap('XMLrequests', 'set', 'request@id=' + requestId, 'status=' + decision);
	}).then(function () { go('item', requestId); });
}
</script>
<style>
body { font-family: sans-serif; padding: 1.2em; }
body {
  background-image: radial-gradient(#000 2px, transparent 0);
  background-size: 40px 40px;
}
button { margin: 0 .35em .6em 0; }
p { cursor: pointer; }
#buttonlist { border: 1px solid red; padding: 8px; background-color: lightyellow; }
</style>
