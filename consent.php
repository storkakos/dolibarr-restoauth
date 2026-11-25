<?php
/* restoauth - Zustimmungsseite. Laeuft hinter der normalen Dolibarr-Anmeldung (Formular oder Keycloak/openid_connect).
 * Nach "Erlauben" wird ein einmaliger Autorisierungscode (lifetime RESTOAUTH_CODE_LIFETIME, default 120 s) erzeugt und der Browser zum Client zurueckgeschickt. */
require __DIR__.'/lib/bootstrap.inc.php';

$langs->loadLangs(array('restoauth@restoauth', 'users', 'admin'));

$packed = isset($_REQUEST['req']) && is_string($_REQUEST['req']) ? $_REQUEST['req'] : '';
$expired = false;
$req = restoauth_unpack_request($packed, $expired);
$client = $req ? restoauth_get_client($db, $req['client_id']) : null;
if (!isModEnabled('restoauth') || !$req || !$client || !in_array($req['redirect_uri'], $client->redirect_uris, true)) {
	llxHeader('', $langs->trans('RestOAuthConsentTitle'));
	print load_fiche_titre($langs->trans('RestOAuthConsentTitle'), '', 'technic');
	if ($expired && isModEnabled('restoauth')) {
		$minutes = (int) ceil(restoauth_lifetime('RESTOAUTH_CONSENT_REQUEST_LIFETIME') / 60);
		print '<div class="warning">'.$langs->trans('RestOAuthRequestExpired', $minutes).'</div>';
	} else {
		print '<div class="error">'.$langs->trans('RestOAuthRequestInvalid').'</div>';
	}
	llxFooter();
	exit;
}

$sendBack = function ($params) use ($req) {
	$params['iss'] = restoauth_issuer();
	if ($req['state'] !== '') {
		$params['state'] = $req['state'];
	}
	$uri = $req['redirect_uri'];
	header('Cache-Control: no-store');
	header('Location: '.$uri.(strpos($uri, '?') === false ? '?' : '&').http_build_query($params), true, 302);
	exit;
};

// prompt=login / max_age of the authorization request (see authorize.php): a session that is older than the request
// (login time before "iat") and is not recent enough is ended, and the browser comes back to this same request.
// The login time is the user's datelastlogin, which Dolibarr sets at every new login (password form and
// openid_connect alike). After the new login it is >= iat, so the request passes and there is no loop. Sessions
// that cannot be ended (auth mode http / forceuser) are left alone.
if (!empty($req['login']) || isset($req['max_age'])) {
	$loginAt = (int) $user->datelastlogin;
	$iat = isset($req['iat']) ? (int) $req['iat'] : 0;
	$stale = !empty($req['login']) || (dol_now() - $loginAt) > (int) $req['max_age'];
	if ($loginAt < $iat && $stale && restoauth_end_session($db, 'consent page, '.(!empty($req['login']) ? 'prompt=login' : 'max_age'))) {
		restoauth_forget_login_return();
		header('Cache-Control: no-store');
		header('Location: '.restoauth_issuer().'/consent.php?req='.$packed, true, 302);
		exit;
	}
}

$mayConnect = restoauth_user_may_connect($user);
$action = GETPOST('restoauth_action', 'aZ09');

// "Not <name>? Log out and use another account": end the Dolibarr session and come back to the same request,
// so that the next person logs in as themselves. The link carries the page's token, so another site cannot
// log the user out through it (Dolibarr's own Logout does not check one).
// With a login through the identity provider (openid_connect) the provider's session is ended too, by its
// RP-initiated logout; the provider sends the browser back to this same request. Otherwise its SSO session
// would log the previous person in again without a password. The return target is a parameter of its own,
// not "state". Without a known end_session endpoint the behaviour is the plain one and the page shows a hint.
if ($action === 'switch' && GETPOST('token', 'alpha') !== '' && GETPOST('token', 'alpha') === currentToken()) {
	$returnUrl = restoauth_issuer().'/consent.php?req='.$packed;
	$goTo = $returnUrl;
	if (restoauth_session_is_openid_connect()) {
		$idpUrl = restoauth_idp_end_session_url($returnUrl);
		if ($idpUrl !== '') {
			$goTo = $idpUrl;
		}
	}
	if (restoauth_end_session($db, 'consent page, other account'.($goTo !== $returnUrl ? ' and identity provider' : ''))) {
		restoauth_forget_login_return();
		// A session that could not be ended (see restoauth_session_can_end) never reaches the provider's logout.
	} else {
		$goTo = $returnUrl;
	}
	header('Cache-Control: no-store');
	header('Location: '.$goTo, true, 302);
	exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'deny') {
	dol_syslog('restoauth: consent denied by '.$user->login.' for client '.$client->client_id, LOG_NOTICE);
	$sendBack(array('error' => 'access_denied', 'error_description' => 'The user denied the request'));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'allow') {
	if (!$mayConnect) {
		$sendBack(array('error' => 'access_denied', 'error_description' => 'User is not allowed to connect applications'));
	}
	$code = restoauth_random(32);
	$now = dol_now();
	$sql = "INSERT INTO ".$db->prefix()."restoauth_code (entity, code_hash, fk_client, fk_user, redirect_uri, code_challenge, resource, scope, expire_at, datec)";
	$sql .= " VALUES (".((int) $conf->entity).", '".$db->escape(restoauth_hash($code))."', ".((int) $client->rowid).", ".((int) $user->id).",";
	$sql .= " '".$db->escape($req['redirect_uri'])."', '".$db->escape($req['code_challenge'])."',";
	$sql .= " ".($req['resource'] !== '' ? "'".$db->escape($req['resource'])."'" : "NULL").",";
	$sql .= " ".($req['scope'] !== '' ? "'".$db->escape($req['scope'])."'" : "NULL").",";
	$sql .= " '".$db->idate($now + restoauth_lifetime('RESTOAUTH_CODE_LIFETIME'))."', '".$db->idate($now)."')";
	if (!$db->query($sql)) {
		$sendBack(array('error' => 'server_error', 'error_description' => 'Could not store authorization code'));
	}
	dol_syslog('restoauth: consent given by '.$user->login.' for client '.$client->client_id, LOG_NOTICE);
	$sendBack(array('code' => $code));
}

// Anzeige
$redirectHost = parse_url($req['redirect_uri'], PHP_URL_HOST);
llxHeader('', $langs->trans('RestOAuthConsentTitle'));
print load_fiche_titre($langs->trans('RestOAuthConsentTitle'), '', 'technic');

print '<div class="opacitymedium" style="margin-bottom: 10px">'.$langs->trans('RestOAuthConsentIntro').'</div>';
print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">'.$langs->trans('RestOAuthApplication').'</td><td><strong>'.dol_escape_htmltag($client->client_name ?: $client->client_id).'</strong></td></tr>';
print '<tr><td>'.$langs->trans('RestOAuthReturnsTo').'</td><td>'.dol_escape_htmltag((string) $redirectHost).'</td></tr>';
if ($req['resource'] !== '') {
	print '<tr><td>'.$langs->trans('RestOAuthResource').'</td><td>'.dol_escape_htmltag($req['resource']).'</td></tr>';
}
$fullName = trim($user->getFullName($langs));
print '<tr><td>'.$langs->trans('User').'</td><td>'.dol_escape_htmltag(($fullName !== '' ? $fullName : $user->login).' ('.$user->login.')');
if (restoauth_session_can_end()) {
	$switchUrl = restoauth_issuer().'/consent.php?req='.$packed.'&restoauth_action=switch&token='.newToken();
	print ' &nbsp; <a class="restoauth-switch" href="'.dol_escape_htmltag($switchUrl).'">'.dol_escape_htmltag($langs->transnoentities('RestOAuthNotYou', $fullName !== '' ? $fullName : $user->login)).'</a>';
	if (restoauth_session_is_openid_connect() && restoauth_idp_end_session_url('') === '') {
		print '<br><span class="opacitymedium">'.dol_escape_htmltag($langs->transnoentities('RestOAuthNotYouIdpHint')).'</span>';
	}
}
$sessionInfo = array();
if (!empty($user->datelastlogin)) {
	$sessionInfo[] = $langs->transnoentities('RestOAuthLoggedInSince', dol_print_date($user->datelastlogin, 'dayhour', 'tzuser'));
}
if (!empty($_SESSION['dol_authmode'])) {
	$sessionInfo[] = $langs->transnoentities('RestOAuthAuthMode', (string) $_SESSION['dol_authmode']);
}
if ($sessionInfo) {
	print '<br><span class="opacitymedium">'.dol_escape_htmltag(implode(', ', $sessionInfo)).'</span>';
}
print '</td></tr>';
print '</table><br>';

if (!$mayConnect) {
	print '<div class="warning">'.$langs->trans('RestOAuthNotAllowed').'</div>';
} else {
	print '<div class="info">'.$langs->trans('RestOAuthRightsIntro').'</div>';
	$rights = restoauth_rights_labels($db, $langs, $user);
	print '<table class="noborder centpercent"><tr class="liste_titre"><th>'.$langs->trans('Module').'</th><th>'.$langs->trans('Permissions').'</th></tr>';
	if (!empty($user->admin)) {
		print '<tr class="oddeven"><td colspan="2"><strong>'.$langs->trans('RestOAuthAdminWarning').'</strong></td></tr>';
	}
	foreach ($rights as $module => $labels) {
		print '<tr class="oddeven"><td class="tdtop">'.dol_escape_htmltag($module).'</td><td>'.dol_escape_htmltag(implode(', ', $labels)).'</td></tr>';
	}
	if (empty($rights)) {
		print '<tr class="oddeven"><td colspan="2"><span class="opacitymedium">'.$langs->trans('None').'</span></td></tr>';
	}
	print '</table><br>';
	print '<div class="opacitymedium">'.$langs->trans('RestOAuthRevokeHint').'</div><br>';
}

print '<form method="POST" action="'.dol_escape_htmltag(restoauth_issuer().'/consent.php').'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="req" value="'.dol_escape_htmltag($packed).'">';
print '<div class="center">';
if ($mayConnect) {
	print '<button type="submit" class="button button-save" name="restoauth_action" value="allow">'.$langs->trans('RestOAuthAllow').'</button> &nbsp; ';
}
print '<button type="submit" class="button button-cancel" name="restoauth_action" value="deny">'.$langs->trans('RestOAuthDeny').'</button>';
print '</div></form>';

llxFooter();
