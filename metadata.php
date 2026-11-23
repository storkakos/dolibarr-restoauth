<?php
/* restoauth - Authorization Server Metadata (RFC 8414). Auch erreichbar unter
 * /.well-known/oauth-authorization-server/custom/restoauth (Rewrite-Regel im Webserver bzw. php-s-router.php). */
require __DIR__.'/lib/public_bootstrap.inc.php';

restoauth_cors();
if (!isModEnabled('restoauth')) {
	restoauth_error(404, 'not_found', 'Module restoauth is disabled');
}
restoauth_json(200, restoauth_metadata(), array('Cache-Control: public, max-age=300'));
