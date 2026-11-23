<?php
/* restoauth - Token Revocation (RFC 7009). Access oder Refresh Token -> die ganze Verbindung (Familie) wird beendet. */
require __DIR__.'/lib/public_bootstrap.inc.php';

restoauth_cors();
if (!isModEnabled('restoauth')) {
	restoauth_error(404, 'not_found', 'Module restoauth is disabled');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	restoauth_error(405, 'invalid_request', 'POST required');
}
$client = restoauth_authenticate_client($db);
$token = isset($_POST['token']) && is_string($_POST['token']) ? $_POST['token'] : '';
if ($token === '') {
	restoauth_error(400, 'invalid_request', 'token required');
}

$family = null;
$res = $db->query("SELECT family_id, fk_client FROM ".$db->prefix()."restoauth_refresh WHERE token_hash = '".$db->escape(restoauth_hash($token))."'");
if ($res && ($o = $db->fetch_object($res)) && (int) $o->fk_client === (int) $client->rowid) {
	$family = $o->family_id;
}
if ($family === null) {
	$at = restoauth_find_access_token($db, $token);
	if ($at) {
		$res = $db->query("SELECT fk_client FROM ".$db->prefix()."restoauth_refresh WHERE family_id = '".$db->escape($at->family_id)."' LIMIT 1");
		if ($res && ($o = $db->fetch_object($res)) && (int) $o->fk_client === (int) $client->rowid) {
			$family = $at->family_id;
		}
	}
}
if ($family !== null) {
	restoauth_revoke_family($db, $family);
	dol_syslog('restoauth: connection '.$family.' revoked by client '.$client->client_id, LOG_NOTICE);
}
// RFC 7009: auch bei unbekanntem Token 200
restoauth_json(200, new stdClass());
