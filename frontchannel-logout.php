<?php
/* restoauth - Front-channel logout URL for the Keycloak (OpenID Connect) client of Dolibarr.
 *
 * On a Keycloak logout, Keycloak's logout page loads this URL in an iframe. If the browser has a Dolibarr
 * session that was opened through the identity provider (authentication mode openid_connect), it is ended the
 * way user/logout.php ends it (trigger USER_LOGOUT, hooks afterLogout in the context logout, session_destroy()),
 * without a redirect. Without a session, and for a session opened another way (Dolibarr's own login form), nothing
 * happens: such a session does not belong to the Keycloak session that ends.
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

if (isModEnabled('restoauth')) {
	// Only a session opened through the identity provider ends here. A session from Dolibarr's own login form
	// (mode dolibarr), http or forceuser survives: it is not the Keycloak session's.
	if (restoauth_session_is_openid_connect()) {
		restoauth_end_session($db, 'front-channel logout');
	} elseif (restoauth_session_can_end()) {
		// Session values go into the log only as harmless characters (no line breaks or control characters).
		$clean = function ($v) {
			return substr(preg_replace('/[^A-Za-z0-9_.@,+-]/', '?', (string) $v), 0, 64);
		};
		dol_syslog('restoauth: front-channel logout leaves the session of '.$clean(isset($_SESSION['dol_login']) ? $_SESSION['dol_login'] : '').' alone, authentication mode '
			.(empty($_SESSION['dol_authmode']) ? '(none)' : $clean($_SESSION['dol_authmode'])).' is not openid_connect', LOG_INFO);
	}
}
restoauth_frontchannel_done();
