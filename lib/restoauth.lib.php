<?php
/* restoauth - OAuth 2.1 authorization server for the Dolibarr REST API
 * Shared functions of all endpoints.
 *
 * Tokens:
 *  - Access token: random value, stored encrypted (dolEncrypt, fixed seed as in the core) in llx_oauth_token,
 *    service 'dolibarr_rest_api', state 'restoauth:<family_id>', expire_at = now + lifetime.
 *  - Dolibarr 23.0.3: the core's token table (API_IN_TOKEN_TABLE=1) is broken there (SQL error "rowid ambiguous"
 *    in api_access.class.php, fixed in 24). So the hook beforeApiCall maps a valid access token, before the core's
 *    check, to the user's internal API key (llx_user.api_key). The core then checks user, status and rights as
 *    always. The hook refuses expired or revoked tokens; the core does not know them.
 *    With API_IN_TOKEN_TABLE=1 (from Dolibarr 24) the mapping is not needed, the core reads the table itself.
 *  - Refresh token, authorization code, client secret: only as sha256 in the module's tables.
 */

/** Prefix in the field state of llx_oauth_token, so that only the module's own tokens are touched */
define('RESTOAUTH_STATE_PREFIX', 'restoauth:');

/**
 * Issuer = base URL of the module (no trailing slash)
 *
 * @return string
 */
function restoauth_issuer()
{
	$issuer = getDolGlobalString('RESTOAUTH_ISSUER');
	if ($issuer === '') {
		$issuer = dol_buildpath('/restoauth', 2);
	}
	return rtrim($issuer, '/');
}

/**
 * Metadata as in RFC 8414
 *
 * @return array<string,mixed>
 */
function restoauth_metadata()
{
	$iss = restoauth_issuer();
	$authMethods = array('none', 'client_secret_post', 'client_secret_basic');
	return array(
		'issuer' => $iss,
		'authorization_endpoint' => $iss.'/authorize.php',
		'token_endpoint' => $iss.'/token.php',
		'registration_endpoint' => $iss.'/register.php',
		'revocation_endpoint' => $iss.'/revoke.php',
		'response_types_supported' => array('code'),
		'response_modes_supported' => array('query'),
		'grant_types_supported' => array('authorization_code', 'refresh_token'),
		'code_challenge_methods_supported' => array('S256'),
		'token_endpoint_auth_methods_supported' => $authMethods,
		'revocation_endpoint_auth_methods_supported' => $authMethods,
		'scopes_supported' => array('dolibarr'),
		'authorization_response_iss_parameter_supported' => true,
		'service_documentation' => $iss.'/README.md',
	);
}

/**
 * Send JSON and end the request
 *
 * @param int                 $status  HTTP status
 * @param array<string,mixed> $data    Body
 * @param array<string>       $headers Additional headers
 * @return never
 */
function restoauth_json($status, $data, $headers = array())
{
	http_response_code($status);
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	header('Pragma: no-cache');
	foreach ($headers as $h) {
		header($h);
	}
	echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	exit;
}

/**
 * Send an OAuth error (RFC 6749 5.2)
 *
 * @param int    $status HTTP status
 * @param string $error  Error code
 * @param string $desc   Description
 * @return never
 */
function restoauth_error($status, $error, $desc = '')
{
	$data = array('error' => $error);
	if ($desc !== '') {
		$data['error_description'] = $desc;
	}
	$headers = array();
	if ($status == 401) {
		$headers[] = 'WWW-Authenticate: Basic realm="restoauth"';
	}
	restoauth_json($status, $data, $headers);
}

/**
 * CORS for the public JSON endpoints (browser clients, MCP Inspector)
 *
 * @return void
 */
function restoauth_cors()
{
	header('Access-Control-Allow-Origin: *');
	header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
	header('Access-Control-Allow-Headers: Authorization, Content-Type, MCP-Protocol-Version');
	if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
		http_response_code(204);
		exit;
	}
}

/**
 * Random value, base64url without padding
 *
 * @param int $bytes Number of bytes
 * @return string
 */
function restoauth_random($bytes = 32)
{
	return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

/**
 * @param string $value Value
 * @return string sha256 hex
 */
function restoauth_hash($value)
{
	return hash('sha256', $value);
}

/**
 * Key for the signature of the authorization request (derived from the instance ID)
 *
 * @return string
 */
function restoauth_signing_key()
{
	global $conf;
	return hash('sha256', 'restoauth-request|'.$conf->file->instance_unique_id, true);
}

/**
 * Configurable lifetimes: constant => array(default, minimum, maximum), in the unit of the setting
 * (seconds for *_LIFETIME, days for *_DAYS). The setup page refuses values outside these bounds;
 * restoauth_lifetime() clamps to them at runtime.
 *
 * @return array<string,array{0:int,1:int,2:int}>
 */
function restoauth_lifetime_bounds()
{
	return array(
		'RESTOAUTH_ACCESS_TOKEN_LIFETIME' => array(3600, 60, 86400),
		'RESTOAUTH_CODE_LIFETIME' => array(120, 30, 600),
		'RESTOAUTH_CONSENT_REQUEST_LIFETIME' => array(900, 60, 3600),
		'RESTOAUTH_REFRESH_IDLE_DAYS' => array(30, 1, 365),
		'RESTOAUTH_REFRESH_MAX_DAYS' => array(90, 1, 365),
	);
}

/**
 * Effective lifetime in the unit of the setting: the configured value, the default if unset or not
 * a number, clamped to the bounds (in case the constant was edited outside the setup page).
 *
 * @param string $name Constant from restoauth_lifetime_bounds()
 * @return int
 */
function restoauth_lifetime($name)
{
	$bounds = restoauth_lifetime_bounds();
	list($default, $min, $max) = $bounds[$name];
	$val = trim(getDolGlobalString($name));
	if ($val === '' || !ctype_digit($val)) {
		return $default;
	}
	return min($max, max($min, (int) $val));
}

/**
 * Pack the authorization request signed and hex-encoded (no state/code in the URL of the consent page,
 * otherwise the state parameter collides with the Keycloak login of Dolibarr 23).
 *
 * @param array<string,mixed> $req Request
 * @param int                 $exp Expiry to keep (re-packing a request); 0 = now plus the request lifetime
 * @return string
 */
function restoauth_pack_request($req, $exp = 0)
{
	$req['exp'] = $exp > 0 ? (int) $exp : dol_now() + restoauth_lifetime('RESTOAUTH_CONSENT_REQUEST_LIFETIME');
	$payload = json_encode($req, JSON_UNESCAPED_SLASHES);
	return bin2hex($payload).'.'.hash_hmac('sha256', $payload, restoauth_signing_key());
}

/**
 * @param string    $packed  Value from restoauth_pack_request
 * @param bool|null $expired Out: true if the request is genuine (signature valid) but expired
 * @return array<string,mixed>|null
 */
function restoauth_unpack_request($packed, &$expired = null)
{
	$expired = false;
	if (!preg_match('/^([0-9a-f]+)\.([0-9a-f]{64})$/', (string) $packed, $m)) {
		return null;
	}
	$payload = hex2bin($m[1]);
	if ($payload === false || !hash_equals(hash_hmac('sha256', $payload, restoauth_signing_key()), $m[2])) {
		return null;
	}
	$req = json_decode($payload, true);
	if (!is_array($req) || empty($req['exp'])) {
		return null;
	}
	if ($req['exp'] < dol_now()) {
		$expired = true;
		return null;
	}
	return $req;
}

/**
 * Can restoauth end this browser's Dolibarr session? Not without a session, and not in the authentication
 * modes http and forceuser: Dolibarr's own Logout (user/logout.php) refuses those too, because the next
 * request would log the same user in again at once.
 *
 * @return bool
 */
function restoauth_session_can_end()
{
	if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['dol_login'])) {
		return false;
	}
	$authmode = empty($_SESSION['dol_authmode']) ? '' : (string) $_SESSION['dol_authmode'];
	return !in_array($authmode, array('forceuser', 'http'), true);
}

/**
 * Ends this browser's Dolibarr session the way Dolibarr 23's user/logout.php does: trigger USER_LOGOUT, hooks
 * afterLogout in the context logout, unset dol_login/dol_entity/urlfrom, session_destroy(). No redirect, no
 * output, no cookie: the caller decides what the browser gets. Works with and without NOLOGIN (with NOLOGIN,
 * main.inc.php has not loaded the session's user; it is loaded here for the trigger and the hooks).
 *
 * Used by frontchannel-logout.php and, through restoauth_restart_consent(), by consent.php: the "Not <name>?"
 * link and the forced login of prompt=login / max_age.
 *
 * @param DoliDB $db  Database
 * @param string $why Caller, for the log (e.g. "front-channel logout")
 * @return bool True if a session was ended, false if there was none or it cannot be ended (see restoauth_session_can_end)
 */
function restoauth_end_session($db, $why)
{
	global $user, $hookmanager;

	if (!restoauth_session_can_end()) {
		return false;
	}
	$login = (string) $_SESSION['dol_login'];
	$entity = empty($_SESSION['dol_entity']) ? 0 : (int) $_SESSION['dol_entity'];
	$u = $user;
	if (!is_object($u) || empty($u->id) || $u->login !== $login) {
		$u = new User($db);
		if ($u->fetch(0, $login, '', 1, ($entity > 0 ? $entity : -1)) <= 0) {
			dol_syslog('restoauth: '.$why.', user '.$login.' of the session not found', LOG_WARNING);
		}
	}

	if ($u->id > 0) {
		if ($u->call_trigger('USER_LOGOUT', $u) < 0) {
			dol_syslog('restoauth: '.$why.', trigger USER_LOGOUT failed for '.$login, LOG_WARNING);
		}
		if (is_object($hookmanager)) {
			$action = '';
			$hookmanager->initHooks(array('logout'));
			if ($hookmanager->executeHooks('afterLogout', array(), $u, $action) < 0) {
				dol_syslog('restoauth: '.$why.', hook afterLogout failed for '.$login, LOG_WARNING);
			}
		}
	}

	dol_syslog('restoauth: '.$why.' of '.$login.', end of session '.session_id(), LOG_NOTICE);
	unset($_SESSION['dol_login'], $_SESSION['dol_entity'], $_SESSION['urlfrom']);
	session_destroy();
	return true;
}

/**
 * When did this browser's Dolibarr session begin? The trigger USER_LOGIN stores the time in
 * $_SESSION['restoauth_login_at'] at every login (password form and openid_connect alike), so it is this
 * session's own login, not the user's last login on any device. A session that began before the trigger existed
 * has no such value; the user's datelastlogin is the fallback for it.
 *
 * @return int Unix time, 0 if unknown
 */
function restoauth_session_login_time()
{
	global $user;

	if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['restoauth_login_at'])) {
		return (int) $_SESSION['restoauth_login_at'];
	}
	return is_object($user) && !empty($user->datelastlogin) ? (int) $user->datelastlogin : 0;
}

/**
 * Did this browser's session begin before the authorization request (so it is not the login the request led to)?
 * With the microsecond times (the request's iat_us, the session's restoauth_login_at_us) the comparison is exact
 * also within one clock second: a session that was there first is older, a login made after the request (no
 * session when it was sent, password form in the same second) is not. Without them (a session from before the
 * trigger, a request without iat_us) the whole seconds are compared and the same second counts as older, so a
 * request is never wrongly answered by an old session; the marker "forced" ends any resulting extra login.
 *
 * @param array<string,mixed> $req Unpacked request
 * @return bool
 */
function restoauth_session_older_than_request($req)
{
	$iat = isset($req['iat']) ? (int) $req['iat'] : 0;
	if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['restoauth_login_at_us']) && !empty($req['iat_us'])) {
		return (float) $_SESSION['restoauth_login_at_us'] < (float) $req['iat_us'];
	}
	return restoauth_session_login_time() <= $iat;
}

/**
 * Ends the session for a consent request and sends the browser back to that request, as a marked one: with
 * prompt=login / max_age the request gets the marker "forced" (signed, expiry unchanged). A request with the
 * marker never forces a login again, so the next login (made for this request) is accepted whatever its time and
 * a login path that does not update the login time cannot cause a loop. Used for the forced login of
 * prompt=login / max_age and for the "Not <name>?" link, which can also go through the identity provider's
 * RP-initiated logout ($viaIdp, only for a session from openid_connect). Sets the cookie of the login return
 * to the request (or deletes it) and redirects; does not return then.
 *
 * @param DoliDB              $db     Database
 * @param array<string,mixed> $req    Unpacked request (restoauth_unpack_request)
 * @param string              $why    Caller, for the log
 * @param bool                $viaIdp Also end the identity provider's session if there is a way
 * @return bool False if the session cannot be ended (see restoauth_session_can_end); nothing was sent then
 */
function restoauth_restart_consent($db, $req, $why, $viaIdp = false)
{
	if (!empty($req['login']) || isset($req['max_age'])) {
		$req['forced'] = 1;
	}
	$packed = restoauth_pack_request($req, (int) $req['exp']);
	$returnUrl = restoauth_consent_url($packed);
	$goTo = $viaIdp && restoauth_idp_logout_available() ? restoauth_idp_end_session_url($returnUrl) : $returnUrl;
	if (!restoauth_end_session($db, $why.($goTo !== $returnUrl ? ' and identity provider' : ''))) {
		return false;
	}
	// Before the IdP hop the cookie is set to this page, not just deleted: Keycloak's front-channel iframe
	// can load user/logout.php (no session) and its login page would store that URL as the way back.
	// Dolibarr 23's login page (core/tpl/login.tpl.php) stores $_SERVER['REQUEST_URI'] there, and its openid_connect
	// callback (core/modules/openid_connect/callback.php) posts to the stored value as it is, prefixing nothing. So
	// the value is built the way Dolibarr builds its own URLs (DOL_URL_ROOT, a sub-path included once), not from
	// RESTOAUTH_ISSUER, whose path may be a public one that a proxy rewrites.
	$rel = dol_buildpath('/restoauth/consent.php', 1).'?req='.$packed;
	if ($goTo === $returnUrl || !restoauth_set_login_return($rel)) {
		restoauth_forget_login_return();
	}
	header('Cache-Control: no-store');
	header('Location: '.$goTo, true, 302);
	exit;
}

/**
 * URL of the consent page for a packed consent request (under the issuer, the URL the browser uses).
 *
 * @param string $packed Value from restoauth_pack_request
 * @return string
 */
function restoauth_consent_url($packed)
{
	return restoauth_issuer().'/consent.php?req='.$packed;
}

/**
 * Can the "Not <name>?" link also end the identity provider's session? Only for a session from openid_connect
 * and only if the provider's end_session endpoint and Dolibarr's client id are known (restoauth_idp_end_session_url).
 * Call before restoauth_end_session(), which destroys the session.
 *
 * @return bool
 */
function restoauth_idp_logout_available()
{
	return restoauth_session_is_openid_connect() && restoauth_idp_end_session_url('-') !== '';
}

/**
 * Is this browser session one that Dolibarr created through the identity provider (authentication mode
 * openid_connect)? Call before restoauth_end_session(), which destroys the session.
 *
 * @return bool
 */
function restoauth_session_is_openid_connect()
{
	return session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['dol_authmode'])
		&& in_array('openid_connect', array_map('trim', explode(',', (string) $_SESSION['dol_authmode'])), true);
}

/**
 * The identity provider's end_session endpoint (OIDC RP-initiated logout) without query: the constant
 * RESTOAUTH_OIDC_END_SESSION_URL, else scheme, host, port and path of Dolibarr's
 * MAIN_AUTHENTICATION_OIDC_LOGOUT_URL (Dolibarr appends its own parameters to that one, so its query is dropped).
 *
 * @return string URL, or '' if none is configured or it is not http(s)
 */
function restoauth_idp_end_session_endpoint()
{
	foreach (array('RESTOAUTH_OIDC_END_SESSION_URL', 'MAIN_AUTHENTICATION_OIDC_LOGOUT_URL') as $name) {
		$raw = trim(getDolGlobalString($name));
		if ($raw === '') {
			continue;
		}
		$p = parse_url($raw);
		if (!is_array($p) || empty($p['host']) || empty($p['scheme']) || !in_array(strtolower($p['scheme']), array('http', 'https'), true)) {
			continue;
		}
		return strtolower($p['scheme']).'://'.$p['host'].(isset($p['port']) ? ':'.(int) $p['port'] : '').(isset($p['path']) ? $p['path'] : '');
	}
	return '';
}

/**
 * URL that ends the identity provider's session through RP-initiated logout and then returns the browser to
 * $returnUrl: <end_session_endpoint>?client_id=<Dolibarr's client>&post_logout_redirect_uri=<$returnUrl>. The
 * return target is a parameter of its own, never "state". Without id_token_hint the provider may ask the person
 * to confirm the logout once. $returnUrl must be allowed under "Valid post logout redirect URIs" of the client.
 *
 * @param string $returnUrl Where the provider sends the browser afterwards
 * @return string URL, or '' if the endpoint or the client id (MAIN_AUTHENTICATION_OIDC_CLIENT_ID) is not known
 */
function restoauth_idp_end_session_url($returnUrl)
{
	$endpoint = restoauth_idp_end_session_endpoint();
	$clientId = trim(getDolGlobalString('MAIN_AUTHENTICATION_OIDC_CLIENT_ID'));
	if ($endpoint === '' || $clientId === '') {
		return '';
	}
	return $endpoint.'?'.http_build_query(array('client_id' => $clientId, 'post_logout_redirect_uri' => $returnUrl), '', '&', PHP_QUERY_RFC3986);
}

/**
 * Sets the cookie DOL_rollback_url_<prefix> to a Dolibarr-relative URL, the page Dolibarr's openid_connect login
 * returns to. Used before the identity provider's logout: Keycloak's front-channel logout iframe may load
 * Dolibarr's user/logout.php with the browser's cookies (same-site deployments) while there is no session, that
 * page shows the login page, and the login page stores its own URL here if the cookie is empty. A value set here
 * stays. Only an internal relative URL is accepted (Dolibarr's callback accepts nothing else).
 *
 * @param string $relativeUrl Path and query, starting with '/' (for example /custom/restoauth/consent.php?req=...)
 * @return bool true if the cookie was set
 */
function restoauth_set_login_return($relativeUrl)
{
	if (!preg_match('/^\/[a-z0-9]/i', (string) $relativeUrl) || strpbrk($relativeUrl, "\r\n") !== false) {
		return false;
	}
	global $dolibarr_main_force_https;

	// Secure as in Dolibarr's own dolSetCookie(): also when Dolibarr is set to force https ($dolibarr_main_force_https,
	// for a TLS proxy that does not tell PHP), not only when isHTTPS() sees https.
	$https = !empty($dolibarr_main_force_https) || isHTTPS();
	header('Set-Cookie: DOL_rollback_url_'.dol_getprefix('').'='.rawurlencode($relativeUrl).'; Max-Age=3600; Path=/; HttpOnly; SameSite=Lax'.($https ? '; Secure' : ''), false);
	return true;
}

/**
 * Deletes the cookies DOL_rollback_url_* of Dolibarr's login page. The login page stores the URL that showed it
 * there (only if the cookie is not set yet), and Dolibarr's openid_connect login returns to that URL. Deleting
 * it makes the next login page store the current URL, so the login returns there.
 *
 * @return void
 */
function restoauth_forget_login_return()
{
	foreach (array_keys($_COOKIE) as $name) {
		if (preg_match('/^DOL_rollback_url_[A-Za-z0-9_]*$/', (string) $name)) {
			header('Set-Cookie: '.$name.'=deleted; Expires=Thu, 01 Jan 1970 00:00:01 GMT; Max-Age=0; Path=/; HttpOnly; SameSite=Lax', false);
		}
	}
}

/**
 * List from a multi-line (or comma-separated) constant
 *
 * @param string $name Constant
 * @return string[]
 */
function restoauth_const_list($name)
{
	$out = array();
	foreach (preg_split('/[\r\n,]+/', getDolGlobalString($name)) as $v) {
		$v = trim($v);
		if ($v !== '') {
			$out[] = $v;
		}
	}
	return $out;
}

/**
 * May this redirect URI be registered? (allowlist, optionally loopback)
 *
 * @param string $uri URI
 * @return bool
 */
function restoauth_redirect_allowed($uri)
{
	$p = parse_url($uri);
	if (!$p || empty($p['scheme']) || empty($p['host']) || isset($p['fragment'])) {
		return false;
	}
	$scheme = strtolower($p['scheme']);
	$host = strtolower($p['host']);
	foreach (restoauth_const_list('RESTOAUTH_REDIRECT_ALLOWLIST') as $allowed) {
		if (substr($allowed, -1) === '*') {
			if (strpos($uri, substr($allowed, 0, -1)) === 0) {
				return true;
			}
		} elseif ($uri === $allowed) {
			return true;
		}
	}
	if (getDolGlobalInt('RESTOAUTH_ALLOW_LOOPBACK') && $scheme === 'http' && in_array($host, array('localhost', '127.0.0.1', '[::1]', '::1'), true)) {
		return true;
	}
	return false;
}

/**
 * Is the resource (RFC 8707) allowed? Empty list = all.
 *
 * @param string $resource URL of the MCP server
 * @return bool
 */
function restoauth_resource_allowed($resource)
{
	$list = restoauth_const_list('RESTOAUTH_RESOURCES');
	if ($resource === '' || empty($list)) {
		return true;
	}
	foreach ($list as $r) {
		if (rtrim($r, '/') === rtrim($resource, '/')) {
			return true;
		}
	}
	return false;
}

/**
 * Load a client by client_id
 *
 * @param DoliDB $db       Database
 * @param string $clientId client_id
 * @return object|null
 */
function restoauth_get_client($db, $clientId)
{
	if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', (string) $clientId)) {
		return null;
	}
	$sql = "SELECT rowid, client_id, secret_hash, client_name, redirect_uris, auth_method, datec FROM ".$db->prefix()."restoauth_client";
	$sql .= " WHERE client_id = '".$db->escape($clientId)."'";
	$res = $db->query($sql);
	if ($res && ($obj = $db->fetch_object($res))) {
		$obj->redirect_uris = json_decode($obj->redirect_uris, true) ?: array();
		return $obj;
	}
	return null;
}

/**
 * Authenticate the client at the token / revoke endpoint (none, client_secret_post, client_secret_basic)
 *
 * @param DoliDB $db Database
 * @return object Client (ends the request with invalid_client otherwise)
 */
function restoauth_authenticate_client($db)
{
	$id = '';
	$secret = null;
	$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
	if ($auth === '' && function_exists('getallheaders')) {
		foreach (getallheaders() as $k => $v) {
			if (strtolower($k) === 'authorization') {
				$auth = $v;
			}
		}
	}
	if (preg_match('/^Basic\s+(.+)$/i', $auth, $m)) {
		$dec = base64_decode($m[1], true);
		if ($dec !== false && strpos($dec, ':') !== false) {
			list($id, $secret) = explode(':', $dec, 2);
			$id = urldecode($id);
			$secret = urldecode($secret);
		}
	}
	if ($id === '') {
		$id = (string) ($_POST['client_id'] ?? '');
		if (isset($_POST['client_secret'])) {
			$secret = (string) $_POST['client_secret'];
		}
	}
	$client = restoauth_get_client($db, $id);
	if (!$client) {
		restoauth_error(401, 'invalid_client', 'Unknown client');
	}
	if (!empty($client->secret_hash)) {
		if ($secret === null || !hash_equals($client->secret_hash, restoauth_hash($secret))) {
			restoauth_error(401, 'invalid_client', 'Client authentication failed');
		}
	}
	return $client;
}

/**
 * May this user connect an application? (enabled, within validity dates, right restoauth->connect)
 *
 * @param User $u User (with rights loaded)
 * @return bool
 */
function restoauth_user_may_connect($u)
{
	if (empty($u->id) || (int) $u->status !== User::STATUS_ENABLED || $u->isNotIntoValidityDateRange()) {
		return false;
	}
	return !empty($u->admin) || $u->hasRight('restoauth', 'connect');
}

/**
 * Load and check a user
 *
 * @param DoliDB $db     Database
 * @param int    $userId ID
 * @return User|null
 */
function restoauth_load_user($db, $userId)
{
	require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
	$u = new User($db);
	if ($u->fetch((int) $userId) <= 0) {
		return null;
	}
	$u->loadRights();
	return restoauth_user_may_connect($u) ? $u : null;
}

/**
 * Issue a new token pair (new or existing family)
 *
 * @param DoliDB      $db          Database
 * @param object      $client      Client
 * @param User        $u           User
 * @param string      $resource    Ressource
 * @param string      $scope       Scope
 * @param string|null $familyId    Existing family (refresh) or null
 * @param int|null    $familyDatec Start of the family
 * @return array<string,mixed> Token response (RFC 6749 5.1)
 */
function restoauth_issue_tokens($db, $client, $u, $resource, $scope, $familyId = null, $familyDatec = null)
{
	$now = dol_now();
	$lifetime = restoauth_lifetime('RESTOAUTH_ACCESS_TOKEN_LIFETIME');
	$idleDays = restoauth_lifetime('RESTOAUTH_REFRESH_IDLE_DAYS');
	$maxDays = max($idleDays, restoauth_lifetime('RESTOAUTH_REFRESH_MAX_DAYS'));	// fallback if set outside the setup page
	if ($familyId === null) {
		$familyId = bin2hex(random_bytes(16));
		$familyDatec = $now;
	}
	$refreshExpire = min($now + $idleDays * 86400, $familyDatec + $maxDays * 86400);
	$entity = (int) (!empty($u->entity) ? $u->entity : 1);

	$access = restoauth_random(32);
	$refresh = restoauth_random(32);
	if (!getDolGlobalString('API_IN_TOKEN_TABLE') && restoauth_user_internal_key($db, $u->id, true) === '') {
		restoauth_error(500, 'server_error', 'Could not prepare API access for user');
	}

	$db->begin();
	$sql = "INSERT INTO ".$db->prefix()."oauth_token (service, tokenstring, state, fk_user, entity, datec, expire_at)";
	$sql .= " VALUES ('dolibarr_rest_api', '".$db->escape(dolEncrypt($access, '', '', 'dolibarr'))."',";
	$sql .= " '".$db->escape(RESTOAUTH_STATE_PREFIX.$familyId)."', ".((int) $u->id).", ".$entity.",";
	$sql .= " '".$db->idate($now)."', '".$db->idate($now + $lifetime)."')";
	$ok = $db->query($sql);
	if ($ok) {
		$sql = "INSERT INTO ".$db->prefix()."restoauth_refresh (entity, token_hash, family_id, fk_client, fk_user, resource, scope, family_datec, expire_at, datec)";
		$sql .= " VALUES (".$entity.", '".$db->escape(restoauth_hash($refresh))."', '".$db->escape($familyId)."', ".((int) $client->rowid).", ".((int) $u->id).",";
		$sql .= " ".($resource !== '' ? "'".$db->escape($resource)."'" : "NULL").", ".($scope !== '' ? "'".$db->escape($scope)."'" : "NULL").",";
		$sql .= " '".$db->idate($familyDatec)."', '".$db->idate($refreshExpire)."', '".$db->idate($now)."')";
		$ok = $db->query($sql);
	}
	if (!$ok) {
		$db->rollback();
		dol_syslog('restoauth: token insert failed '.$db->lasterror(), LOG_ERR);
		restoauth_error(500, 'server_error', 'Could not store token');
	}
	$db->commit();

	return array(
		'access_token' => $access,
		'token_type' => 'Bearer',
		'expires_in' => $lifetime,
		'refresh_token' => $refresh,
		'scope' => $scope !== '' ? $scope : 'dolibarr',
	);
}

/**
 * Revoke a connection (family): lock its refresh tokens, delete its access tokens
 *
 * @param DoliDB $db       Database
 * @param string $familyId Family
 * @return void
 */
function restoauth_revoke_family($db, $familyId)
{
	$db->query("UPDATE ".$db->prefix()."restoauth_refresh SET revoked = 1 WHERE family_id = '".$db->escape($familyId)."'");
	$db->query("DELETE FROM ".$db->prefix()."oauth_token WHERE service = 'dolibarr_rest_api' AND state = '".$db->escape(RESTOAUTH_STATE_PREFIX.$familyId)."'");
}

/**
 * End every connection of a user: lock all their refresh tokens and delete all their access tokens.
 * Used when the user is disabled, so that enabling them again does not revive old tokens.
 *
 * @param DoliDB $db     Database
 * @param int    $userId User
 * @return void
 */
function restoauth_revoke_user($db, $userId)
{
	$db->query("UPDATE ".$db->prefix()."restoauth_refresh SET revoked = 1 WHERE fk_user = ".((int) $userId)." AND revoked = 0");
	$db->query("DELETE FROM ".$db->prefix()."oauth_token WHERE service = 'dolibarr_rest_api' AND fk_user = ".((int) $userId)." AND state LIKE '".$db->escape(RESTOAUTH_STATE_PREFIX)."%'");
}

/**
 * Access token record for a plain-text token (only the module's own tokens)
 *
 * @param DoliDB $db    Database
 * @param string $token Token
 * @return object|null rowid, fk_user, entity, state, expire_at (Timestamp)
 */
function restoauth_find_access_token($db, $token)
{
	if ($token === '' || strlen($token) > 200) {
		return null;
	}
	$sql = "SELECT rowid, fk_user, entity, state, expire_at FROM ".$db->prefix()."oauth_token";
	$sql .= " WHERE service = 'dolibarr_rest_api' AND tokenstring = '".$db->escape(dolEncrypt($token, '', '', 'dolibarr'))."'";
	$sql .= " AND state LIKE '".$db->escape(RESTOAUTH_STATE_PREFIX)."%'";
	$res = $db->query($sql);
	if ($res && ($obj = $db->fetch_object($res))) {
		$obj->expire_at = $db->jdate($obj->expire_at);
		$obj->family_id = substr($obj->state, strlen(RESTOAUTH_STATE_PREFIX));
		return $obj;
	}
	return null;
}

/**
 * Bearer token / DOLAPIKEY of the request (as DolibarrApiAccess reads it)
 *
 * @return string
 */
function restoauth_request_api_key()
{
	if (!empty($GLOBALS['restoauth_original_key'])) {
		return (string) $GLOBALS['restoauth_original_key'];   // replaced by the hook: the original OAuth token
	}
	$key = '';
	if (isset($_SERVER['HTTP_DOLAPIKEY'])) {
		$key = $_SERVER['HTTP_DOLAPIKEY'];
	} else {
		$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
		if ($auth === '' && function_exists('getallheaders')) {
			$h = getallheaders();
			$auth = $h['Authorization'] ?? ($h['authorization'] ?? '');
		}
		$key = preg_replace('/^Bearer\s+/i', '', (string) $auth);
	}
	return trim((string) $key);
}

/**
 * The user's internal API key (llx_user.api_key), created if needed. It never leaves Dolibarr; the hook
 * beforeApiCall uses it only for the duration of an API request with a valid OAuth token.
 *
 * @param DoliDB $db     Database
 * @param int    $userId User
 * @param bool   $create Create it if empty
 * @return string Plain text or ''
 */
function restoauth_user_internal_key($db, $userId, $create = false)
{
	$res = $db->query("SELECT api_key FROM ".$db->prefix()."user WHERE rowid = ".((int) $userId));
	$obj = $res ? $db->fetch_object($res) : null;
	if (!$obj) {
		return '';
	}
	if (!empty($obj->api_key)) {
		return (string) dolDecrypt($obj->api_key);
	}
	if (!$create) {
		return '';
	}
	$key = restoauth_random(32);
	$db->query("UPDATE ".$db->prefix()."user SET api_key = '".$db->escape(dolEncrypt($key, '', '', 'dolibarr'))."' WHERE rowid = ".((int) $userId)." AND (api_key IS NULL OR api_key = '')");
	return restoauth_user_internal_key($db, $userId, false);
}

/**
 * Remove expired tokens and codes (cron job, and in passing at the token endpoint)
 *
 * @param DoliDB $db Database
 * @return int Number of deleted access tokens
 */
function restoauth_cleanup($db)
{
	$now = "'".$db->idate(dol_now())."'";
	$n = 0;
	$res = $db->query("DELETE FROM ".$db->prefix()."oauth_token WHERE service = 'dolibarr_rest_api' AND state LIKE '".$db->escape(RESTOAUTH_STATE_PREFIX)."%' AND expire_at IS NOT NULL AND expire_at < ".$now);
	if ($res) {
		$n = $db->affected_rows($res);
	}
	$db->query("DELETE FROM ".$db->prefix()."restoauth_code WHERE expire_at < ".$now);
	// Remove families whose last refresh token has expired
	$db->query("DELETE FROM ".$db->prefix()."restoauth_refresh WHERE family_id IN (SELECT family_id FROM (SELECT family_id FROM ".$db->prefix()."restoauth_refresh GROUP BY family_id HAVING MAX(expire_at) < ".$now.") AS t)");
	return $n;
}

/**
 * The user's rights as a flat list "module.right" or "module.object.right"
 *
 * @param User $u User with rights loaded
 * @return string[]
 */
function restoauth_flatten_rights($u)
{
	$out = array();
	$walk = function ($node, $prefix) use (&$walk, &$out) {
		foreach ((array) $node as $k => $v) {
			if (is_object($v) || is_array($v)) {
				$walk($v, $prefix.$k.'.');
			} elseif (!empty($v)) {
				$out[] = $prefix.$k;
			}
		}
	};
	$walk($u->rights ?? array(), '');
	sort($out);
	return array_values(array_unique($out));
}

/**
 * Rights with readable labels (for the consent page), grouped by module
 *
 * @param DoliDB    $db    Database
 * @param Translate $langs Languages
 * @param User      $u     User
 * @return array<string,string[]> Module => labels
 */
function restoauth_rights_labels($db, $langs, $u)
{
	$have = array_flip(restoauth_flatten_rights($u));
	$langs->loadLangs(array('admin', 'users'));
	$out = array();
	$sql = "SELECT id, libelle, module, perms, subperms FROM ".$db->prefix()."rights_def";
	$sql .= " WHERE entity IN (0, ".((int) $u->entity ?: 1).") ORDER BY module, id";
	$res = $db->query($sql);
	while ($res && ($o = $db->fetch_object($res))) {
		$key = $o->module.'.'.$o->perms.($o->subperms ? '.'.$o->subperms : '');
		if (!isset($have[$key])) {
			continue;
		}
		$label = $langs->trans('Permission'.$o->id);
		if ($label === 'Permission'.$o->id) {
			$label = $o->libelle;
		}
		$out[$o->module][] = $label;
	}
	return $out;
}

/**
 * Token for the "Not you?" link of the consent page. It is bound to the PHP session and the user, and stays the same
 * while the session loads other Dolibarr pages (Dolibarr's own page token changes with every page load).
 * It does not expire within the session and is not bound to the consent request, on purpose: all it allows is to end
 * this user's own session in this browser (logout CSRF protection), it dies with the session, and binding it to
 * "req" would add nothing, as the link carries req and the token together.
 *
 * @return string
 */
function restoauth_switch_token()
{
	global $user;
	return hash_hmac('sha256', 'switch|'.session_id().'|'.((int) $user->id), restoauth_signing_key());
}
