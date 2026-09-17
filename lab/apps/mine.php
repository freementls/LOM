<?php

include('../O.php');
// PHP variable names beginning with XML get edited right here in the code
$XMLrequests = '<requests>
<request id="1"><name>John</name><for>more money</for></request>
<request id="2"><name>Sam</name><for>vacation</for></request>
<request id="3"><name>Susan</name><for>margaritas</for></request>
<request id="4"><name>Xin</name><for>a basketball</for></request>
</requests>';
$XMLapprovers = '<approvers>
<approver id="1"><name>Big Mike</name><requestid>4</requestid><decision>approved</decision></approver>
<approver id="2"><name>Samantha</name><requestid>2</requestid><decision>unsure</decision></approver>
<approver id="3"><name>Jim bob</name><requestid>3</requestid><decision>not approved</decision></approver>
<approver id="4"><name>Unruly Mighty Oak</name><requestid>1</requestid><decision></decision></approver>
</approvers>';
$requests = new O($XMLrequests);
$approvers = new O($XMLapprovers);
$screen_from_get = isset($_GET['section']) ? $_GET['section'] : 'new';
$request_from_get = isset($_GET['requestId']) ? $_GET['requestId'] : '';

// app lab filename is run.php
?>
<h1>Our Requests App</h1>
<div id="sections">
<button id="newRequest" onclick="go('new')">New</button>
<button id="existingRequests" onclick="go('existingRequests')">Existing Requests</button>
<button id="approvers" onclick="go('approvers')">Approvers</button>
</div>
<?php
if($screen_from_get === 'new') {
	// <request id="1"><name>John</name><for>more money</for></request>	
	print('<h2>New Request</h2>
<label for="name">Name: </label><input type="text" id="name" />
<label for="for">For: </label><input type="text" id="for" /><!-- ironic -->
<button onclick="add()">Save</button>
');
} elseif($screen_from_get === 'existingRequests') {
	print('<h2>Existing Requests</h2>
<table>
<thead>
<tr>
<th scope="col">ID</th><th scope="col">Name</th><th scope="col">For</th>
</tr>
</thead>
<tbody>
');
foreach($requests->_('request') as $request) {
	$id = $requests->_('@id', $request);
	$name = $requests->_('name', $request);
	$for = $requests->_('for', $request);
	print('<tr>
	<th scope="row">' . $id . '</th>
	<td>' . $name . '</td>
	<td>' . $for . '</td>
	</tr>');
}
print('
</tbody>
<!--tfoot>if you wanted sums and things</tfoot-->
</table>
');
} elseif($screen_from_get === 'approvers') {
	print('<h2>Approvers</h2>
<table>
<thead>
<tr>
<th scope="col">Name</th><th scope="col">Request</th><th scope="col">Decision</th>
</tr>
</thead>
<tbody>
');
	foreach($approvers->_('approver') as $approver) {
		$approverName = $approvers->_('name', $approver);
		$linkedRequest = $approvers->_('requestid', $approver);
		$decision = $approvers->_('decision', $approver);
		print('<tr>
	<td>' . $approverName . '</td>
	<td>' . $linkedRequest . '</td>
	<td>' . ($decision !== '' ? $decision : 'pending') . '</td>
	</tr>');
	}
	print('
</tbody>
</table>
');
} else { // default to new request screen
	print('<h2>New Request</h2>
<label for="name">Name: </label><input type="text" id="name" />
<label for="for">For: </label><input type="text" id="for" />
<button onclick="add()">Save</button>
');
}
?>
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
function go(section, requestId) {
	location = 'run.php?app=' + LAB_APP + '&section=' + section + (requestId ? '&requestId=' + requestId : '') + '&time=' + Date.now();
}
function add() {
	var requestId = Date.now();
	var nameValue = document.getElementById('name').value;
	var forValue = document.getElementById('for').value;
	ajap('XMLrequests', 'new_', 'requests',
		'<request id="' + requestId + '"><name>' + nameValue + '</name><for>' + forValue + '</for></request>'
	).then(function () { go('existingRequests'); });
}
function decide(approverId, requestId, decision) {
	ajap('XMLapprovers', 'set', 'approver@id=' + approverId, 'decision=' + decision).then(function () {
		return ajap('XMLrequests', 'set', 'request@id=' + requestId, 'status=' + decision);
	}).then(function () { go('item', requestId); });
}
</script>
<style>
h1 { text-align: center; }
body {
  background-image: radial-gradient(#000 1px, transparent 0);
  background-size: 30px 30px;
}
#sections { text-align: center; }
button { background-color: lightblue; font-size: 20px; border-radius: 6px; }
button:hover { background-color: #999999; font-color: #222200; }
</style>