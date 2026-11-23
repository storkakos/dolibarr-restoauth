<?php
/* restoauth - Reiter "Verbundene Anwendungen" in der Benutzerkarte: aktive Verbindungen anzeigen und beenden */
require __DIR__.'/lib/bootstrap.inc.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/usergroups.lib.php';

$langs->loadLangs(array('users', 'restoauth@restoauth'));

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$isSelf = ((int) $id === (int) $user->id);
if (!isModEnabled('restoauth') || (!$isSelf && empty($user->admin) && !$user->hasRight('restoauth', 'admin'))) {
	accessforbidden();
}
$object = new User($db);
if ($object->fetch($id) <= 0) {
	accessforbidden();
}

if ($action === 'revoke') {
	$family = GETPOST('connection', 'aZ09');
	$resq = $db->query("SELECT family_id FROM ".$db->prefix()."restoauth_refresh WHERE fk_user = ".((int) $object->id)." AND family_id = '".$db->escape($family)."' LIMIT 1");
	if ($resq && $db->fetch_object($resq)) {
		restoauth_revoke_family($db, $family);
		dol_syslog('restoauth: connection '.$family.' of '.$object->login.' revoked by '.$user->login, LOG_NOTICE);
		setEventMessages($langs->trans('RestOAuthRevoked'), null);
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.((int) $object->id));
	exit;
}

llxHeader('', $langs->trans('ConnectedApps'));
$head = user_prepare_head($object);
print dol_get_fiche_head($head, 'restoauth', $langs->trans('User'), -1, 'user');
dol_banner_tab($object, 'id', '', $user->hasRight('user', 'user', 'lire') || $user->admin);
print '<div class="underbanner clearboth"></div><br>';

$sql = "SELECT r.family_id, MIN(r.family_datec) AS since, MAX(r.datec) AS lastrenew, MAX(r.expire_at) AS validuntil, c.client_name, c.client_id";
$sql .= " FROM ".$db->prefix()."restoauth_refresh AS r JOIN ".$db->prefix()."restoauth_client AS c ON c.rowid = r.fk_client";
$sql .= " WHERE r.fk_user = ".((int) $object->id)." AND r.revoked = 0";
$sql .= " GROUP BY r.family_id, c.client_name, c.client_id HAVING MAX(r.expire_at) > '".$db->idate(dol_now())."' ORDER BY since DESC";
$resq = $db->query($sql);

print '<table class="noborder centpercent"><tr class="liste_titre">';
print '<td>'.$langs->trans('RestOAuthApplication').'</td><td>'.$langs->trans('RestOAuthConnectedSince').'</td><td>'.$langs->trans('RestOAuthLastRenewal').'</td>';
print '<td>'.$langs->trans('RestOAuthAccessTokenUntil').'</td><td>'.$langs->trans('RestOAuthValidUntil').'</td><td></td></tr>';
$n = 0;
while ($resq && ($o = $db->fetch_object($resq))) {
	$n++;
	$tokenUntil = '';
	$r2 = $db->query("SELECT MAX(expire_at) AS e FROM ".$db->prefix()."oauth_token WHERE service = 'dolibarr_rest_api' AND state = '".$db->escape(RESTOAUTH_STATE_PREFIX.$o->family_id)."'");
	if ($r2 && ($t = $db->fetch_object($r2)) && $t->e) {
		$tokenUntil = dol_print_date($db->jdate($t->e), 'dayhour');
	}
	print '<tr class="oddeven"><td><strong>'.dol_escape_htmltag($o->client_name ?: $o->client_id).'</strong></td>';
	print '<td>'.dol_print_date($db->jdate($o->since), 'dayhour').'</td><td>'.dol_print_date($db->jdate($o->lastrenew), 'dayhour').'</td>';
	print '<td>'.$tokenUntil.'</td><td>'.dol_print_date($db->jdate($o->validuntil), 'dayhour').'</td>';
	print '<td class="right"><a class="button smallpaddingimp" href="'.$_SERVER['PHP_SELF'].'?id='.((int) $object->id).'&action=revoke&connection='.urlencode($o->family_id).'&token='.newToken().'">'.$langs->trans('RestOAuthRevoke').'</a></td></tr>';
}
if (!$n) {
	print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">'.$langs->trans('RestOAuthNoConnections').'</span></td></tr>';
}
print '</table>';

print dol_get_fiche_end();
llxFooter();
