<?php
/* restoauth - Token Endpoint (RFC 6749 / OAuth 2.1): authorization_code (mit PKCE) und refresh_token (rotierend,
 * Wiederverwendung eines alten Refresh-Tokens sperrt die ganze Verbindung). */
require __DIR__.'/lib/public_bootstrap.inc.php';

restoauth_cors();
if (!isModEnabled('restoauth')) {
	restoauth_error(404, 'not_found', 'Module restoauth is disabled');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	restoauth_error(405, 'invalid_request', 'POST required');
}

$p = function ($k) {
	return isset($_POST[$k]) && is_string($_POST[$k]) ? $_POST[$k] : '';
};

restoauth_cleanup($db);
$client = restoauth_authenticate_client($db);
$grant = $p('grant_type');

if ($grant === 'authorization_code') {
	$codeHash = restoauth_hash($p('code'));
	$sql = "SELECT rowid, fk_client, fk_user, redirect_uri, code_challenge, resource, scope, expire_at FROM ".$db->prefix()."restoauth_code";
	$sql .= " WHERE code_hash = '".$db->escape($codeHash)."'";
	$res = $db->query($sql);
	$row = $res ? $db->fetch_object($res) : null;
	if (!$row) {
		restoauth_error(400, 'invalid_grant', 'Unknown or already used authorization code');
	}
	// Einmal nutzbar: sofort loeschen (bei parallelem Einloesen gewinnt nur einer)
	$del = $db->query("DELETE FROM ".$db->prefix()."restoauth_code WHERE rowid = ".((int) $row->rowid));
	if (!$del || $db->affected_rows($del) !== 1) {
		restoauth_error(400, 'invalid_grant', 'Authorization code already used');
	}
	if ($db->jdate($row->expire_at) < dol_now()) {
		restoauth_error(400, 'invalid_grant', 'Authorization code expired');
	}
	if ((int) $row->fk_client !== (int) $client->rowid) {
		restoauth_error(400, 'invalid_grant', 'Code was issued to another client');
	}
	if ($p('redirect_uri') !== '' && $p('redirect_uri') !== $row->redirect_uri) {
		restoauth_error(400, 'invalid_grant', 'redirect_uri does not match');
	}
	$verifier = $p('code_verifier');
	if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier)) {
		restoauth_error(400, 'invalid_request', 'code_verifier missing or malformed');
	}
	$calc = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
	if (!hash_equals($row->code_challenge, $calc)) {
		restoauth_error(400, 'invalid_grant', 'PKCE verification failed');
	}
	$resource = (string) $row->resource;
	if ($p('resource') !== '' && $resource !== '' && rtrim($p('resource'), '/') !== rtrim($resource, '/')) {
		restoauth_error(400, 'invalid_target', 'resource does not match the authorization request');
	}
	$u = restoauth_load_user($db, $row->fk_user);
	if (!$u) {
		restoauth_error(400, 'invalid_grant', 'User is disabled or not allowed to connect applications');
	}
	dol_syslog('restoauth: tokens issued (authorization_code) for '.$u->login.' client '.$client->client_id, LOG_NOTICE);
	restoauth_json(200, restoauth_issue_tokens($db, $client, $u, $resource, (string) $row->scope));
}

if ($grant === 'refresh_token') {
	$hash = restoauth_hash($p('refresh_token'));
	$sql = "SELECT rowid, family_id, fk_client, fk_user, resource, scope, family_datec, expire_at, used_at, revoked FROM ".$db->prefix()."restoauth_refresh";
	$sql .= " WHERE token_hash = '".$db->escape($hash)."'";
	$res = $db->query($sql);
	$row = $res ? $db->fetch_object($res) : null;
	if (!$row || (int) $row->revoked) {
		restoauth_error(400, 'invalid_grant', 'Unknown or revoked refresh token');
	}
	if ((int) $row->fk_client !== (int) $client->rowid) {
		restoauth_error(400, 'invalid_grant', 'Refresh token was issued to another client');
	}
	if (!empty($row->used_at)) {
		// Wiederverwendung: Token wurde schon rotiert -> moeglicherweise gestohlen -> ganze Verbindung sperren
		restoauth_revoke_family($db, $row->family_id);
		dol_syslog('restoauth: refresh token reuse detected, family '.$row->family_id.' revoked', LOG_WARNING);
		restoauth_error(400, 'invalid_grant', 'Refresh token reuse detected, connection revoked');
	}
	if ($db->jdate($row->expire_at) < dol_now()) {
		restoauth_revoke_family($db, $row->family_id);
		restoauth_error(400, 'invalid_grant', 'Refresh token expired');
	}
	$upd = $db->query("UPDATE ".$db->prefix()."restoauth_refresh SET used_at = '".$db->idate(dol_now())."' WHERE rowid = ".((int) $row->rowid)." AND used_at IS NULL");
	if (!$upd || $db->affected_rows($upd) !== 1) {
		restoauth_revoke_family($db, $row->family_id);
		restoauth_error(400, 'invalid_grant', 'Refresh token reuse detected, connection revoked');
	}
	// Alter Access Token dieser Verbindung wird ungueltig
	$db->query("DELETE FROM ".$db->prefix()."oauth_token WHERE service = 'dolibarr_rest_api' AND state = '".$db->escape(RESTOAUTH_STATE_PREFIX.$row->family_id)."'");
	$u = restoauth_load_user($db, $row->fk_user);
	if (!$u) {
		restoauth_revoke_family($db, $row->family_id);
		restoauth_error(400, 'invalid_grant', 'User is disabled or not allowed to connect applications');
	}
	restoauth_json(200, restoauth_issue_tokens($db, $client, $u, (string) $row->resource, (string) $row->scope, $row->family_id, $db->jdate($row->family_datec)));
}

restoauth_error(400, 'unsupported_grant_type', 'Supported: authorization_code, refresh_token');
