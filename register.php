<?php
/* restoauth - Dynamic Client Registration (RFC 7591). Nur Redirect-URIs aus der Allowlist (oder Loopback, falls erlaubt). */
require __DIR__.'/lib/public_bootstrap.inc.php';

restoauth_cors();
if (!isModEnabled('restoauth')) {
	restoauth_error(404, 'not_found', 'Module restoauth is disabled');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	restoauth_error(405, 'invalid_request', 'POST required');
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
	restoauth_error(400, 'invalid_client_metadata', 'JSON body required');
}

$uris = $body['redirect_uris'] ?? null;
if (!is_array($uris) || count($uris) < 1 || count($uris) > 10) {
	restoauth_error(400, 'invalid_redirect_uri', 'redirect_uris (1-10) required');
}
foreach ($uris as $u) {
	if (!is_string($u) || strlen($u) > 500 || !restoauth_redirect_allowed($u)) {
		restoauth_error(400, 'invalid_redirect_uri', 'Redirect URI not allowed: '.(is_string($u) ? $u : '?'));
	}
}

$method = isset($body['token_endpoint_auth_method']) ? (string) $body['token_endpoint_auth_method'] : 'client_secret_basic';
if (!in_array($method, array('none', 'client_secret_post', 'client_secret_basic'), true)) {
	restoauth_error(400, 'invalid_client_metadata', 'Unsupported token_endpoint_auth_method');
}
$grantTypes = $body['grant_types'] ?? array('authorization_code', 'refresh_token');
$responseTypes = $body['response_types'] ?? array('code');
if (!is_array($grantTypes) || array_diff($grantTypes, array('authorization_code', 'refresh_token'))
	|| !is_array($responseTypes) || array_diff($responseTypes, array('code'))) {
	restoauth_error(400, 'invalid_client_metadata', 'Only authorization_code/refresh_token and response_type code are supported');
}
$name = isset($body['client_name']) && is_string($body['client_name']) ? dol_trunc(trim($body['client_name']), 250, 'right', 'UTF-8', 1) : '';

$clientId = 'dc_'.restoauth_random(24);
$secret = $method === 'none' ? null : restoauth_random(32);
$now = dol_now();

$sql = "INSERT INTO ".$db->prefix()."restoauth_client (entity, client_id, secret_hash, client_name, redirect_uris, auth_method, datec)";
$sql .= " VALUES (".((int) $conf->entity).", '".$db->escape($clientId)."', ".($secret === null ? "NULL" : "'".restoauth_hash($secret)."'").",";
$sql .= " '".$db->escape($name)."', '".$db->escape(json_encode(array_values($uris), JSON_UNESCAPED_SLASHES))."', '".$db->escape($method)."', '".$db->idate($now)."')";
if (!$db->query($sql)) {
	restoauth_error(500, 'server_error', 'Could not store client');
}
dol_syslog('restoauth: client registered '.$clientId.' ('.$name.')', LOG_NOTICE);

$out = array(
	'client_id' => $clientId,
	'client_id_issued_at' => $now,
	'client_name' => $name,
	'redirect_uris' => array_values($uris),
	'token_endpoint_auth_method' => $method,
	'grant_types' => array_values($grantTypes),
	'response_types' => array_values($responseTypes),
);
if ($secret !== null) {
	$out['client_secret'] = $secret;
	$out['client_secret_expires_at'] = 0;
}
restoauth_json(201, $out);
