<?php
/* restoauth - Hooks.
 * beforeApiCall laeuft in api/index.php VOR der Token-Pruefung des Kerns (Restler handle()).
 *  - Abgelaufener Token des Moduls -> 401, Datensatz wird geloescht (der Kern beachtet expire_at nicht).
 *  - Gueltiger Token des Moduls -> fuer diese Anfrage durch den internen API-Schluessel des Benutzers ersetzt
 *    (Dolibarr 23: API_IN_TOKEN_TABLE ist im Kern defekt). Der Kern prueft danach Benutzerstatus und Rechte.
 *  - Alles andere bleibt unveraendert (normale DOLAPIKEY-Anbindungen funktionieren weiter). */

require_once __DIR__.'/../lib/restoauth.lib.php';

/**
 * Hook class for restoauth
 */
class ActionsRestoauth
{
	/** @var DoliDB */
	public $db;
	/** @var string */
	public $error = '';
	/** @var string[] */
	public $errors = array();
	/** @var array<string,mixed> */
	public $results = array();
	/** @var string */
	public $resprints = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * 401 ausgeben und beenden
	 *
	 * @param string $desc Beschreibung
	 * @return never
	 */
	private function reject($desc)
	{
		http_response_code(401);
		header('Content-Type: application/json; charset=utf-8');
		header('WWW-Authenticate: Bearer error="invalid_token", error_description="'.$desc.'"');
		echo json_encode(array('error' => array('code' => 401, 'message' => 'Unauthorized: '.$desc)));
		exit;
	}

	/**
	 * OAuth-Token des Moduls pruefen und fuer den Kern abbilden
	 *
	 * @param array<string,mixed> $parameters  Hook parameters
	 * @param object              $object      Api object
	 * @param string              $action      Request method
	 * @param HookManager         $hookmanager Hook manager
	 * @return int 0 = continue
	 */
	public function beforeApiCall($parameters, &$object, &$action, $hookmanager)
	{
		$key = restoauth_request_api_key();
		if ($key === '') {
			return 0;
		}
		$at = restoauth_find_access_token($this->db, $key);
		if (!$at) {
			return 0;
		}
		if (!empty($at->expire_at) && $at->expire_at < dol_now()) {
			$this->db->query("DELETE FROM ".$this->db->prefix()."oauth_token WHERE rowid = ".((int) $at->rowid));
			dol_syslog('restoauth: expired access token rejected (connection '.$at->family_id.')', LOG_NOTICE);
			$this->reject('The access token expired');
		}
		if (getDolGlobalString('API_IN_TOKEN_TABLE')) {
			return 0;   // ab Dolibarr 24: der Kern liest llx_oauth_token selbst
		}
		$internal = restoauth_user_internal_key($this->db, $at->fk_user, false);
		if ($internal === '') {
			$this->reject('No API access for this user');
		}
		$GLOBALS['restoauth_original_key'] = $key;
		$_SERVER['HTTP_DOLAPIKEY'] = $internal;
		return 0;
	}
}
