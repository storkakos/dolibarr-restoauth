<?php
/* restoauth - Einstellungen: Laufzeiten, Redirect-Allowlist, Ressourcen, API_IN_TOKEN_TABLE, registrierte Clients */
$res = 0;
foreach (array('/../../../main.inc.php', '/../../../../main.inc.php') as $rel) {
	if (!$res && file_exists(__DIR__.$rel)) {
		$res = @include __DIR__.$rel;
	}
}
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
	$res = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'].'/main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once __DIR__.'/../lib/restoauth.lib.php';

$langs->loadLangs(array('admin', 'restoauth@restoauth'));
if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$settings = array(
	'RESTOAUTH_ACCESS_TOKEN_LIFETIME' => array('RestOAuthAccessLifetime', 'int'),
	'RESTOAUTH_CODE_LIFETIME' => array('RestOAuthCodeLifetime', 'lifetime'),
	'RESTOAUTH_CONSENT_REQUEST_LIFETIME' => array('RestOAuthConsentRequestLifetime', 'lifetime'),
	'RESTOAUTH_REFRESH_IDLE_DAYS' => array('RestOAuthIdleDays', 'int'),
	'RESTOAUTH_REFRESH_MAX_DAYS' => array('RestOAuthMaxDays', 'int'),
	'RESTOAUTH_ALLOW_LOOPBACK' => array('RestOAuthAllowLoopback', 'yesno'),
	'RESTOAUTH_REDIRECT_ALLOWLIST' => array('RestOAuthRedirectAllowlist', 'text'),
	'RESTOAUTH_RESOURCES' => array('RestOAuthResources', 'text'),
	'RESTOAUTH_ISSUER' => array('RestOAuthIssuer', 'string'),
);

$lifetimeBounds = restoauth_lifetime_bounds();
$posted = array();	// values of a refused save, shown again in the form
if ($action === 'update') {
	// Validate the lifetimes first; one invalid value refuses the whole save
	$errors = array();
	foreach ($settings as $name => $def) {
		if ($def[1] !== 'lifetime') {
			continue;
		}
		list(, $min, $max) = $lifetimeBounds[$name];
		$raw = trim(GETPOST($name, 'alphanohtml'));
		if (!preg_match('/^[0-9]{1,9}$/', $raw) || (int) $raw < $min || (int) $raw > $max) {
			$errors[] = $langs->trans('RestOAuthLifetimeInvalid', $langs->transnoentitiesnoconv($def[0]), $min, $max);
		}
	}
	if ($errors) {
		setEventMessages(null, $errors, 'errors');
		foreach ($settings as $name => $def) {
			$posted[$name] = $def[1] === 'yesno' ? (GETPOST($name, 'int') ? '1' : '0') : GETPOST($name, 'restricthtml');
		}
	} else {
		foreach ($settings as $name => $def) {
			$val = GETPOST($name, $def[1] === 'int' ? 'int' : 'restricthtml');
			if ($def[1] === 'yesno') {
				$val = GETPOST($name, 'int') ? '1' : '0';
			} elseif ($def[1] === 'lifetime') {
				$val = (string) ((int) trim(GETPOST($name, 'alphanohtml')));
			}
			dolibarr_set_const($db, $name, (string) $val, 'chaine', 0, '', $conf->entity);
		}
		setEventMessages($langs->trans('SetupSaved'), null);
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
}
if ($action === 'deleteclient') {
	$cid = GETPOSTINT('clientrowid');
	$resq = $db->query("SELECT DISTINCT family_id FROM ".$db->prefix()."restoauth_refresh WHERE fk_client = ".((int) $cid));
	while ($resq && ($o = $db->fetch_object($resq))) {
		restoauth_revoke_family($db, $o->family_id);
	}
	$db->query("DELETE FROM ".$db->prefix()."restoauth_refresh WHERE fk_client = ".((int) $cid));
	$db->query("DELETE FROM ".$db->prefix()."restoauth_code WHERE fk_client = ".((int) $cid));
	$db->query("DELETE FROM ".$db->prefix()."restoauth_client WHERE rowid = ".((int) $cid));
	setEventMessages($langs->trans('RecordDeleted'), null);
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

llxHeader('', $langs->trans('RestOAuthSetup'));
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('RestOAuthSetup'), $linkback, 'title_setup');

// Betriebsart der Token-Pruefung
$tokenTable = getDolGlobalString('API_IN_TOKEN_TABLE');
$v23 = version_compare(DOL_VERSION, '24.0.0', '<');
print '<div class="'.($tokenTable && $v23 ? 'error' : 'info').'">';
print '<strong>'.$langs->trans('RestOAuthApiInTokenTable').': '.($tokenTable ? $langs->trans('Enabled') : $langs->trans('Disabled')).'</strong><br>';
print $langs->trans($tokenTable ? ($v23 ? 'RestOAuthTokenTableBroken' : 'RestOAuthTokenTableMode') : 'RestOAuthHookMode');
print '</div><br>';

// Einstellungen
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="update">';
print '<table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';
foreach ($settings as $name => $def) {
	if (array_key_exists($name, $posted)) {
		$val = $posted[$name];
	} elseif ($def[1] === 'lifetime') {
		$val = (string) restoauth_lifetime($name);
	} else {
		$val = getDolGlobalString($name);
	}
	print '<tr class="oddeven"><td class="titlefield">'.$langs->trans($def[0]);
	if ($def[1] === 'lifetime') {
		print '<br><span class="opacitymedium small">'.$langs->trans('RestOAuthAllowedRange', $lifetimeBounds[$name][1], $lifetimeBounds[$name][2]).'</span>';
	}
	print '</td><td>';
	if ($def[1] === 'text') {
		print '<textarea name="'.$name.'" rows="3" class="quatrevingtpercent">'.dol_escape_htmltag($val).'</textarea>';
	} elseif ($def[1] === 'yesno') {
		print '<input type="checkbox" name="'.$name.'" value="1"'.($val ? ' checked' : '').'>';
	} else {
		print '<input type="text" name="'.$name.'" class="'.(in_array($def[1], array('int', 'lifetime'), true) ? 'width75' : 'quatrevingtpercent').'" value="'.dol_escape_htmltag($val).'">';
	}
	print '</td></tr>';
}
print '</table><div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div></form><br>';

// Endpunkte
$iss = restoauth_issuer();
print load_fiche_titre($langs->trans('RestOAuthEndpoints'), '', '');
print '<table class="noborder centpercent">';
foreach (restoauth_metadata() as $k => $v) {
	if (is_string($v) && strpos($v, 'http') === 0) {
		print '<tr class="oddeven"><td class="titlefield">'.dol_escape_htmltag($k).'</td><td>'.dol_escape_htmltag($v).'</td></tr>';
	}
}
print '<tr class="oddeven"><td>metadata (RFC 8414)</td><td>'.dol_escape_htmltag($iss.'/metadata.php').'</td></tr>';
print '<tr class="oddeven"><td>me (REST)</td><td>'.dol_escape_htmltag(DOL_MAIN_URL_ROOT.'/api/index.php/restoauth/me').'</td></tr>';
print '</table><div class="opacitymedium">'.$langs->trans('RestOAuthWellKnownHint').'</div><br>';

// Clients
print load_fiche_titre($langs->trans('RestOAuthClients'), '', '');
print '<table class="noborder centpercent"><tr class="liste_titre"><td>client_id</td><td>'.$langs->trans('Name').'</td><td>Redirect URIs</td><td>Auth</td><td>'.$langs->trans('DateCreation').'</td><td></td></tr>';
$resq = $db->query("SELECT rowid, client_id, client_name, redirect_uris, auth_method, datec FROM ".$db->prefix()."restoauth_client ORDER BY datec DESC");
$n = 0;
while ($resq && ($o = $db->fetch_object($resq))) {
	$n++;
	print '<tr class="oddeven"><td>'.dol_escape_htmltag($o->client_id).'</td><td>'.dol_escape_htmltag($o->client_name).'</td>';
	print '<td class="small">'.dol_escape_htmltag(implode(' ', json_decode($o->redirect_uris, true) ?: array())).'</td><td>'.dol_escape_htmltag($o->auth_method).'</td>';
	print '<td>'.dol_print_date($db->jdate($o->datec), 'dayhour').'</td>';
	print '<td class="right"><a class="reposition" href="'.$_SERVER['PHP_SELF'].'?action=deleteclient&clientrowid='.((int) $o->rowid).'&token='.newToken().'">'.img_delete().'</a></td></tr>';
}
if (!$n) {
	print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">'.$langs->trans('None').'</span></td></tr>';
}
print '</table>';

llxFooter();
$db->close();
