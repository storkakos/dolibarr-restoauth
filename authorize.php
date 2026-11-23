<?php
/* restoauth - Authorization Endpoint (OAuth 2.1, Authorization Code + PKCE S256).
 * Prueft die Anfrage OHNE Login und leitet signiert zur Zustimmungsseite (consent.php) weiter.
 * Grund: Der Keycloak-Login von Dolibarr 23 liest "state" zuerst aus der URL (GETPOST); der state des
 * Clients darf deshalb nicht in der URL der Seite stehen, die den Login ausloest. */
require __DIR__.'/lib/public_bootstrap.inc.php';

/**
 * Fehler, ohne zum Client zurueckzuleiten (unbekannter Client oder Redirect-URI, RFC 6749 4.1.2.1)
 *
 * @param string $msg Meldung
 * @return never
 */
function restoauth_authorize_fail($msg)
{
	http_response_code(400);
	header('Content-Type: text/plain; charset=utf-8');
	header('Cache-Control: no-store');
	echo 'OAuth authorization request rejected: '.$msg;
	exit;
}

if (!isModEnabled('restoauth')) {
	restoauth_authorize_fail('module disabled');
}

$in = function ($k) {
	return isset($_GET[$k]) && is_string($_GET[$k]) ? $_GET[$k] : '';
};
$client = restoauth_get_client($db, $in('client_id'));
if (!$client) {
	restoauth_authorize_fail('unknown client_id');
}
$redirectUri = $in('redirect_uri');
if ($redirectUri === '' && count($client->redirect_uris) === 1) {
	$redirectUri = $client->redirect_uris[0];
}
if (!in_array($redirectUri, $client->redirect_uris, true)) {
	restoauth_authorize_fail('redirect_uri is not registered for this client');
}

$state = $in('state');
$back = function ($error, $desc) use ($redirectUri, $state) {
	$q = array('error' => $error, 'error_description' => $desc, 'iss' => restoauth_issuer());
	if ($state !== '') {
		$q['state'] = $state;
	}
	header('Location: '.$redirectUri.(strpos($redirectUri, '?') === false ? '?' : '&').http_build_query($q), true, 302);
	exit;
};

if ($in('response_type') !== 'code') {
	$back('unsupported_response_type', 'Only response_type=code is supported');
}
$challenge = $in('code_challenge');
if ($in('code_challenge_method') !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43,128}$/', $challenge)) {
	$back('invalid_request', 'PKCE with code_challenge_method=S256 is required');
}
$resource = $in('resource');
if (!restoauth_resource_allowed($resource)) {
	$back('invalid_target', 'Resource is not allowed');
}
if (strlen($state) > 1000 || strlen($in('scope')) > 255) {
	$back('invalid_request', 'Parameter too long');
}

$packed = restoauth_pack_request(array(
	'client_id' => $client->client_id,
	'redirect_uri' => $redirectUri,
	'state' => $state,
	'code_challenge' => $challenge,
	'resource' => $resource,
	'scope' => $in('scope'),
));
header('Cache-Control: no-store');
header('Location: '.restoauth_issuer().'/consent.php?req='.$packed, true, 302);
exit;
