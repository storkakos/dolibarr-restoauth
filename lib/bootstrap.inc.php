<?php
/* restoauth - Dolibarr-main.inc.php laden (Modul liegt in htdocs/custom/restoauth). Die Seite setzt vorher ihre
 * define()-Schalter (NOLOGIN usw.). */
$res = 0;
if (!empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
	$res = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'].'/main.inc.php';
}
foreach (array('/../../../main.inc.php', '/../../main.inc.php') as $rel) {
	if (!$res && file_exists(__DIR__.$rel)) {
		$res = @include __DIR__.$rel;
	}
}
if (!$res) {
	http_response_code(500);
	die('restoauth: main.inc.php not found');
}
require_once __DIR__.'/restoauth.lib.php';
