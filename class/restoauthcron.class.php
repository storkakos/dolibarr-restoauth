<?php
/* restoauth - geplante Aufgabe: abgelaufene Access Tokens, Codes und Refresh-Token-Familien loeschen.
 * Der Hook beforeApiCall weist abgelaufene Tokens ohnehin ab; der Cron raeumt die Tabelle auf. */

require_once __DIR__.'/../lib/restoauth.lib.php';

/**
 * Cron job class
 */
class RestOAuthCron
{
	/** @var DoliDB */
	public $db;
	/** @var string */
	public $output = '';
	/** @var string */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Aufraeumen
	 *
	 * @return int 0 if OK
	 */
	public function cleanup()
	{
		$n = restoauth_cleanup($this->db);
		$this->output = $n.' expired access token(s) deleted';
		return 0;
	}
}
