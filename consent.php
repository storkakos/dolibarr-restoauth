<?php
/* restoauth - Zustimmungsseite. Laeuft hinter der normalen Dolibarr-Anmeldung (Formular oder Keycloak/openid_connect).
 * Nach "Erlauben" wird ein einmaliger Autorisierungscode (2 Minuten) erzeugt und der Browser zum Client zurueckgeschickt. */
require __DIR__.'/lib/bootstrap.inc.php';

$langs->loadLangs(array('restoauth@restoauth', 'users', 'admin'));

$packed = isset($_REQUEST['req']) && is_string($_REQUEST['req']) ? $_REQUEST['req'] : '';
$req = restoauth_unpack_request($packed);
$client = $req ? restoauth_get_client($db, $req['client_id']) : null;
if (!isModEnabled('restoauth') || !$req || !$client || !in_array($req['redirect_uri'], $client->redirect_uris, true)) {
	llxHeader('', $langs->trans('RestOAuthConsentTitle'));
	print '<div class="error">'.$langs->trans('RestOAuthRequestInvalid').'</div>';
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

$mayConnect = restoauth_user_may_connect($user);
$action = GETPOST('restoauth_action', 'aZ09');

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
	$sql .= " '".$db->idate($now + 120)."', '".$db->idate($now)."')";
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
print '<tr><td>'.$langs->trans('User').'</td><td>'.dol_escape_htmltag($user->getFullName($langs).' ('.$user->login.')').'</td></tr>';
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
