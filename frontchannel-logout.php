<?php
/* restoauth - Front-channel logout URL for the Keycloak (OpenID Connect) client of Dolibarr.
 *
 * On a Keycloak logout, Keycloak's logout page loads this URL in an iframe. If the browser has a Dolibarr
 * session, it is ended the way user/logout.php ends it (trigger USER_LOGOUT, hooks afterLogout in the context
 * logout, session_destroy()), without a redirect. Without a session nothing happens.
 *
 * Unlike user/logout.php, this page never shows Dolibarr's login page (NOLOGIN). The login page sets the cookie
 * DOL_rollback_url_* to the page that showed it, so a front-channel call to user/logout.php without a session
 * sent the next Keycloak login back to the logout page. This page sets no cookie at all and answers 200 with an
 * empty body. Keycloak's iss and sid parameters are ignored: Dolibarr keeps no Keycloak session ID, and both are
 * chosen by whoever opens the URL.
 */
foreach (array('NOLOGIN', 'NOCSRFCHECK', 'NOTOKENRENEWAL', 'NOREQUIREMENU', 'NOREQUIREHTML', 'NOREQUIREAJAX', 'NOBROWSERNOTIF', 'NOIPCHECK') as $restoauthFlag) {
	if (!defined($restoauthFlag)) {
		define($restoauthFlag, '1');
	}
}
require __DIR__.'/lib/bootstrap.inc.php';

/**
 * Ends the response: 200, empty body, nothing cached, no cookie.
 *
 * @return never
 */
function restoauth_frontchannel_done()
{
	// A browser without a Dolibarr session gets a new, empty session from main.inc.php, with its cookie.
	// Drop that session and every cookie header: the iframe must leave the browser's cookies as they were.
	if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['dol_login'])) {
		session_destroy();
	}
	header_remove('Set-Cookie');
	http_response_code(200);
	header('Content-Type: text/plain; charset=utf-8');
	header('Content-Length: 0');
	header('Cache-Control: no-store');
	header('Pragma: no-cache');
	exit;
}

if (!isModEnabled('restoauth') || session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['dol_login'])) {
	restoauth_frontchannel_done();
}

// Sessions of the authentication modes http and forceuser are not ended by Dolibarr's logout either
// (user/logout.php stops with a message); the next request would log in again at once.
$restoauthAuthmode = empty($_SESSION['dol_authmode']) ? '' : $_SESSION['dol_authmode'];
if (in_array($restoauthAuthmode, array('forceuser', 'http'), true)) {
	restoauth_frontchannel_done();
}

// NOLOGIN: main.inc.php has not loaded the session's user. Load it for the trigger and the hooks.
$restoauthLogin = (string) $_SESSION['dol_login'];
$restoauthEntity = empty($_SESSION['dol_entity']) ? 0 : (int) $_SESSION['dol_entity'];
if (empty($user->id) || $user->login !== $restoauthLogin) {
	$user = new User($db);
	if ($user->fetch(0, $restoauthLogin, '', 1, ($restoauthEntity > 0 ? $restoauthEntity : -1)) <= 0) {
		dol_syslog('restoauth: front-channel logout, user '.$restoauthLogin.' of the session not found', LOG_WARNING);
	}
}

if ($user->id > 0) {
	$result = $user->call_trigger('USER_LOGOUT', $user);
	if ($result < 0) {
		dol_syslog('restoauth: front-channel logout, trigger USER_LOGOUT failed for '.$restoauthLogin, LOG_WARNING);
	}
	$action = '';
	$hookmanager->initHooks(array('logout'));
	$reshook = $hookmanager->executeHooks('afterLogout', array(), $user, $action);
	if ($reshook < 0) {
		dol_syslog('restoauth: front-channel logout, hook afterLogout failed for '.$restoauthLogin, LOG_WARNING);
	}
}

dol_syslog('restoauth: front-channel logout of '.$restoauthLogin.', end of session '.session_id(), LOG_NOTICE);
unset($_SESSION['dol_login'], $_SESSION['dol_entity'], $_SESSION['urlfrom']);
session_destroy();
restoauth_frontchannel_done();
