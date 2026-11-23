<?php
/* restoauth - Bootstrap fuer die oeffentlichen Endpunkte (ohne Dolibarr-Login, ohne Session, ohne HTML) */
foreach (array('NOLOGIN', 'NOSESSION', 'NOCSRFCHECK', 'NOTOKENRENEWAL', 'NOREQUIREMENU', 'NOREQUIREHTML', 'NOREQUIREAJAX', 'NOIPCHECK') as $restoauthFlag) {
	if (!defined($restoauthFlag)) {
		define($restoauthFlag, '1');
	}
}
require __DIR__.'/bootstrap.inc.php';
