<?php
/* restoauth - Triggers.
 * USER_ENABLEDISABLE: when a user is disabled, every connection of that user ends at once (refresh
 * tokens locked, access tokens deleted). Enabling the user again does not bring old tokens back; the
 * user has to connect the application again. */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';
require_once __DIR__.'/../../lib/restoauth.lib.php';

/**
 * Trigger class for restoauth
 */
class InterfaceRestOAuthTriggers extends DolibarrTriggers
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);
		$this->family = 'interface';
		$this->description = 'restoauth: disabling a user ends all of their OAuth connections';
		$this->version = self::VERSIONS['prod'];
		$this->picto = 'technic';
	}

	/**
	 * @param string    $action Event code
	 * @param CommonObject $object Object
	 * @param User      $user   User who triggered the event
	 * @param Translate $langs  Translations
	 * @param Conf      $conf   Configuration
	 * @return int 0 = nothing done, 1 = done, <0 = error
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if ($action !== 'USER_ENABLEDISABLE' || !isModEnabled('restoauth') || empty($object->id)) {
			return 0;
		}
		// User::setstatus() calls the trigger after its UPDATE and before it sets $object->status,
		// inside the same transaction: read the new status from the database.
		$res = $this->db->query("SELECT statut FROM ".$this->db->prefix()."user WHERE rowid = ".((int) $object->id));
		$o = $res ? $this->db->fetch_object($res) : null;
		if ($o && (int) $o->statut === 1) {
			return 0;
		}
		restoauth_revoke_user($this->db, (int) $object->id);
		dol_syslog('restoauth: user '.$object->login.' disabled by '.$user->login.', all connections revoked', LOG_NOTICE);
		return 1;
	}
}
