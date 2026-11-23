<?php
/* restoauth - OAuth 2.1 authorization server for the Dolibarr REST API
 * Gemeinsame Funktionen fuer alle Endpunkte.
 *
 * Tokens:
 *  - Access Token: Zufallswert, liegt verschluesselt (dolEncrypt, fester Seed wie im Kern) in llx_oauth_token,
 *    service 'dolibarr_rest_api', state 'restoauth:<family_id>', expire_at = jetzt + Laufzeit.
 *  - Dolibarr 23.0.3: Die Token-Tabelle des Kerns (API_IN_TOKEN_TABLE=1) ist dort defekt (SQL-Fehler "rowid ambiguous"
 *    in api_access.class.php, behoben in 24). Deshalb bildet der Hook beforeApiCall einen gueltigen Access Token vor der
 *    Kernpruefung auf den internen API-Schluessel des Benutzers (llx_user.api_key) ab. Der Kern prueft dann Benutzer,
 *    Status und Rechte wie immer. Abgelaufene oder widerrufene Tokens weist der Hook ab bzw. kennt der Kern nicht.
 *    Mit API_IN_TOKEN_TABLE=1 (ab Dolibarr 24) entfaellt die Abbildung, der Kern liest die Tabelle selbst.
 *  - Refresh Token, Autorisierungscode, Client-Secret: nur als sha256 in den Tabellen des Moduls.
 */

/** Praefix im Feld state von llx_oauth_token, damit nur eigene Tokens angefasst werden */
define('RESTOAUTH_STATE_PREFIX', 'restoauth:');

/**
 * Issuer = Basis-URL des Moduls (ohne Slash am Ende)
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
 * Metadaten nach RFC 8414
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
 * JSON ausgeben und beenden
 *
 * @param int                 $status  HTTP-Status
 * @param array<string,mixed> $data    Inhalt
 * @param array<string>       $headers zusaetzliche Header
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
 * OAuth-Fehler (RFC 6749 5.2) ausgeben
 *
 * @param int    $status HTTP-Status
 * @param string $error  Fehlercode
 * @param string $desc   Beschreibung
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
 * CORS fuer die oeffentlichen JSON-Endpunkte (Browser-Clients, MCP Inspector)
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
 * Zufallswert, base64url ohne Padding
 *
 * @param int $bytes Anzahl Bytes
 * @return string
 */
function restoauth_random($bytes = 32)
{
	return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

/**
 * @param string $value Wert
 * @return string sha256 hex
 */
function restoauth_hash($value)
{
	return hash('sha256', $value);
}

/**
 * Schluessel fuer die Signatur der Autorisierungsanfrage (aus der Instanz-ID abgeleitet)
 *
 * @return string
 */
function restoauth_signing_key()
{
	global $conf;
	return hash('sha256', 'restoauth-request|'.$conf->file->instance_unique_id, true);
}

/**
 * Autorisierungsanfrage signiert und hex-kodiert verpacken (kein state/code in der URL der Zustimmungsseite,
 * sonst kollidiert der Parameter state mit dem Keycloak-Login von Dolibarr 23).
 *
 * @param array<string,mixed> $req Anfrage
 * @return string
 */
function restoauth_pack_request($req)
{
	$req['exp'] = dol_now() + 900;
	$payload = json_encode($req, JSON_UNESCAPED_SLASHES);
	return bin2hex($payload).'.'.hash_hmac('sha256', $payload, restoauth_signing_key());
}

/**
 * @param string $packed Wert aus restoauth_pack_request
 * @return array<string,mixed>|null
 */
function restoauth_unpack_request($packed)
{
	if (!preg_match('/^([0-9a-f]+)\.([0-9a-f]{64})$/', (string) $packed, $m)) {
		return null;
	}
	$payload = hex2bin($m[1]);
	if ($payload === false || !hash_equals(hash_hmac('sha256', $payload, restoauth_signing_key()), $m[2])) {
		return null;
	}
	$req = json_decode($payload, true);
	if (!is_array($req) || empty($req['exp']) || $req['exp'] < dol_now()) {
		return null;
	}
	return $req;
}

/**
 * Liste aus einer mehrzeiligen Konstante
 *
 * @param string $name Konstante
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
 * Darf diese Redirect-URI registriert werden? (Allowlist, optional Loopback)
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
 * Ist die Ressource (RFC 8707) erlaubt? Leere Liste = alle.
 *
 * @param string $resource URL des MCP-Servers
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
 * Client per client_id laden
 *
 * @param DoliDB $db       Datenbank
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
 * Client am Token-/Revoke-Endpunkt authentifizieren (none, client_secret_post, client_secret_basic)
 *
 * @param DoliDB $db Datenbank
 * @return object Client (bricht mit invalid_client ab)
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
 * Darf dieser Benutzer eine Anwendung verbinden? (aktiv, gueltig, Recht restoauth->connect)
 *
 * @param User $u Benutzer (mit geladenen Rechten)
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
 * Benutzer laden und pruefen
 *
 * @param DoliDB $db     Datenbank
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
 * Neues Token-Paar ausgeben (neue oder bestehende Familie)
 *
 * @param DoliDB      $db          Datenbank
 * @param object      $client      Client
 * @param User        $u           Benutzer
 * @param string      $resource    Ressource
 * @param string      $scope       Scope
 * @param string|null $familyId    bestehende Familie (Refresh) oder null
 * @param int|null    $familyDatec Beginn der Familie
 * @return array<string,mixed> Token-Antwort (RFC 6749 5.1)
 */
function restoauth_issue_tokens($db, $client, $u, $resource, $scope, $familyId = null, $familyDatec = null)
{
	$now = dol_now();
	$lifetime = max(60, getDolGlobalInt('RESTOAUTH_ACCESS_TOKEN_LIFETIME', 3600));
	$idleDays = max(1, getDolGlobalInt('RESTOAUTH_REFRESH_IDLE_DAYS', 30));
	$maxDays = max($idleDays, getDolGlobalInt('RESTOAUTH_REFRESH_MAX_DAYS', 90));
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
 * Verbindung (Familie) widerrufen: Refresh-Tokens sperren, Access Tokens loeschen
 *
 * @param DoliDB $db       Datenbank
 * @param string $familyId Familie
 * @return void
 */
function restoauth_revoke_family($db, $familyId)
{
	$db->query("UPDATE ".$db->prefix()."restoauth_refresh SET revoked = 1 WHERE family_id = '".$db->escape($familyId)."'");
	$db->query("DELETE FROM ".$db->prefix()."oauth_token WHERE service = 'dolibarr_rest_api' AND state = '".$db->escape(RESTOAUTH_STATE_PREFIX.$familyId)."'");
}

/**
 * Access-Token-Datensatz zu einem Klartext-Token (nur eigene Tokens)
 *
 * @param DoliDB $db    Datenbank
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
 * Bearer-Token / DOLAPIKEY aus der Anfrage (wie DolibarrApiAccess)
 *
 * @return string
 */
function restoauth_request_api_key()
{
	if (!empty($GLOBALS['restoauth_original_key'])) {
		return (string) $GLOBALS['restoauth_original_key'];   // vom Hook ersetzt: urspruenglicher OAuth-Token
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
 * Interner API-Schluessel des Benutzers (llx_user.api_key), bei Bedarf anlegen. Er verlaesst Dolibarr nie;
 * der Hook beforeApiCall setzt ihn nur fuer die Dauer einer API-Anfrage mit gueltigem OAuth-Token ein.
 *
 * @param DoliDB $db     Datenbank
 * @param int    $userId Benutzer
 * @param bool   $create anlegen, falls leer
 * @return string Klartext oder ''
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
 * Abgelaufene Tokens und Codes entfernen (Cron und nebenbei am Token-Endpunkt)
 *
 * @param DoliDB $db Datenbank
 * @return int Anzahl geloeschter Access Tokens
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
	// Familien, deren letzter Refresh-Token abgelaufen ist, komplett entfernen
	$db->query("DELETE FROM ".$db->prefix()."restoauth_refresh WHERE family_id IN (SELECT family_id FROM (SELECT family_id FROM ".$db->prefix()."restoauth_refresh GROUP BY family_id HAVING MAX(expire_at) < ".$now.") AS t)");
	return $n;
}

/**
 * Rechte des Benutzers als flache Liste "modul.recht" bzw. "modul.objekt.recht"
 *
 * @param User $u Benutzer mit geladenen Rechten
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
 * Rechte mit lesbarer Bezeichnung (fuer die Zustimmungsseite), gruppiert nach Modul
 *
 * @param DoliDB    $db    Datenbank
 * @param Translate $langs Sprachen
 * @param User      $u     Benutzer
 * @return array<string,string[]> Modul => Bezeichnungen
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
