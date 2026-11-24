<?php
/* restoauth - OAuth 2.1 authorization server for the Dolibarr REST API
 *
 * Moduldeskriptor. Das Modul aendert keinen Kerncode: Es schreibt Access Tokens in llx_oauth_token
 * (service 'dolibarr_rest_api'), die die REST-API mit API_IN_TOKEN_TABLE=1 selbst akzeptiert.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Module descriptor for restoauth
 */
class modRestOAuth extends DolibarrModules
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->numero = 525100;
		$this->rights_class = 'restoauth';
		$this->family = 'interface';
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'OAuth 2.1 server for the REST API (AI assistants, MCP) with login via Dolibarr/Keycloak';
		$this->descriptionlong = 'Issues per-user REST API tokens through OAuth 2.1 (authorization code + PKCE, dynamic client registration, rotating refresh tokens, revocation).';
		$this->editor_name = 'storkakos';
		$this->version = '0.2.2';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'technic';

		$this->module_parts = array(
			'hooks' => array('data' => array('api'), 'entity' => '0'),
			// core/triggers: disabling a user ends all of their connections
			'triggers' => 1,
		);

		$this->dirs = array();
		$this->config_page_url = array('setup.php@restoauth');
		$this->hidden = false;
		$this->depends = array('modApi');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('restoauth@restoauth');
		$this->phpmin = array(8, 1);
		$this->need_dolibarr_version = array(23, 0);

		$this->const = array(
			array('RESTOAUTH_ACCESS_TOKEN_LIFETIME', 'chaine', '3600', 'Access token lifetime in seconds (60-86400)', 0, 'current', 0),
			array('RESTOAUTH_CODE_LIFETIME', 'chaine', '120', 'Authorization code lifetime in seconds (30-600)', 0, 'current', 0),
			array('RESTOAUTH_CONSENT_REQUEST_LIFETIME', 'chaine', '900', 'Signed authorize-to-consent request lifetime in seconds (60-3600)', 0, 'current', 0),
			array('RESTOAUTH_REFRESH_IDLE_DAYS', 'chaine', '30', 'Refresh token: days without use until it expires (1-365)', 0, 'current', 0),
			array('RESTOAUTH_REFRESH_MAX_DAYS', 'chaine', '90', 'Refresh token family: maximum age in days (1-365, at least the idle days)', 0, 'current', 0),
			array('RESTOAUTH_ALLOW_LOOPBACK', 'chaine', '0', 'Allow http://localhost / 127.0.0.1 redirect URIs', 0, 'current', 0),
		);

		// Tab "Verbundene Anwendungen" in the user card
		$this->tabs = array(
			array('data' => 'user:+restoauth:ConnectedApps:restoauth@restoauth:isModEnabled("restoauth"):/restoauth/connected.php?id=__ID__'),
		);

		$this->cronjobs = array(
			0 => array(
				'label' => 'RestOAuthCleanup',
				'jobtype' => 'method',
				'class' => '/restoauth/class/restoauthcron.class.php',
				'objectname' => 'RestOAuthCron',
				'method' => 'cleanup',
				'parameters' => '',
				'comment' => 'Delete expired access tokens, authorization codes and refresh tokens of restoauth',
				'frequency' => 5,
				'unitfrequency' => 60,
				'status' => 1,
				'test' => 'isModEnabled("restoauth")',
				'priority' => 50,
			),
		);

		// Permission: who may connect an AI assistant (like personal_tokens_allowed_profiles in iTop)
		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = $this->numero.'01';
		$this->rights[$r][1] = 'Connect applications (AI assistant) via OAuth with own rights';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'connect';
		$r++;
		$this->rights[$r][0] = $this->numero.'02';
		$this->rights[$r][1] = 'Administer OAuth clients and connections of all users';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'admin';
		$r++;

		$this->menu = array();
	}

	/**
	 * Called when module is enabled: creates tables, constants, permissions, cron job.
	 *
	 * @param string $options Options when enabling module ('', 'noboxes')
	 * @return int 1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/restoauth/sql/');
		if ($result < 0) {
			return -1;
		}
		$this->remove($options);
		return $this->_init(array(), $options);
	}

	/**
	 * Called when module is disabled. Tables and tokens are kept.
	 *
	 * @param string $options Options when disabling module
	 * @return int 1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
