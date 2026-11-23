<?php
/* restoauth - REST-API-Erweiterung: GET /api/index.php/restoauth/me
 * Liefert Benutzer, aktive Module, die Rechte des Benutzers (bei jedem Aufruf frisch aus der Datenbank) und den Ablauf
 * des Tokens. Die Users-API des Kerns gibt die Rechte nicht heraus; der MCP-Server braucht sie, um Werkzeuge zu filtern. */

use Luracast\Restler\RestException;

require_once DOL_DOCUMENT_ROOT.'/api/class/api.class.php';
require_once __DIR__.'/../lib/restoauth.lib.php';

/**
 * API for restoauth
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class Restoauth extends DolibarrApi
{
	/**
	 * Constructor
	 */
	public function __construct()
	{
		global $db;
		$this->db = $db;
	}

	/**
	 * Current user, enabled modules, permissions and token expiry
	 *
	 * Rights are returned as "module.permission" or "module.object.permission" (e.g. facture.lire, societe.client.voir).
	 * api_rights_rules describes which permission an API path needs (default rule plus exceptions).
	 *
	 * @url GET me
	 *
	 * @return array
	 *
	 * @throws RestException 401
	 */
	public function me()
	{
		global $conf;

		$u = DolibarrApiAccess::$user;
		$token = null;
		$at = restoauth_find_access_token($this->db, restoauth_request_api_key());
		if ($at) {
			if (!empty($at->expire_at) && $at->expire_at < dol_now()) {
				throw new RestException(401, 'Access token expired');
			}
			$clientName = '';
			$res = $this->db->query("SELECT c.client_name, c.client_id FROM ".$this->db->prefix()."restoauth_refresh AS r JOIN ".$this->db->prefix()."restoauth_client AS c ON c.rowid = r.fk_client WHERE r.family_id = '".$this->db->escape($at->family_id)."' LIMIT 1");
			if ($res && ($o = $this->db->fetch_object($res))) {
				$clientName = $o->client_name ?: $o->client_id;
			}
			$token = array('type' => 'restoauth', 'expires_at' => (int) $at->expire_at, 'connection' => $at->family_id, 'client' => $clientName);
		}

		$modules = array();
		foreach ((array) $conf->modules as $k => $v) {
			$modules[] = is_string($k) ? $k : $v;
		}
		sort($modules);

		$rules = array();
		$file = __DIR__.'/../rights_map.json';
		if (is_readable($file)) {
			$rules = json_decode((string) file_get_contents($file), true) ?: array();
		}

		return array(
			'user' => array(
				'id' => (int) $u->id,
				'login' => $u->login,
				'firstname' => $u->firstname,
				'lastname' => $u->lastname,
				'email' => $u->email,
				'admin' => (int) $u->admin,
				'external' => $u->socid > 0,
				'socid' => (int) $u->socid,
			),
			'entity' => (int) $conf->entity,
			'dolibarr_version' => DOL_VERSION,
			'modules' => $modules,
			'rights' => restoauth_flatten_rights($u),
			'token' => $token,
			'api_rights_rules' => $rules,
		);
	}

	/**
	 * Read-only REST paths (GET) of all enabled API classes, including those of custom modules
	 *
	 * Same scan as the Swagger explorer (api/index.php). Needed because the explorer does not run the
	 * beforeApiCall hook, so it only shows public paths for OAuth tokens of this module.
	 *
	 * @url GET endpoints
	 *
	 * @return array
	 */
	public function endpoints()
	{
		$out = array();
		foreach (dolGetModulesDirs() as $dir) {
			$handle = @opendir(dol_osencode($dir));
			if (!is_resource($handle)) {
				continue;
			}
			while (($file = readdir($handle)) !== false) {
				if (!preg_match('/^mod(.*)\.class\.php$/i', $file, $regmod)) {
					continue;
				}
				$module = strtolower($regmod[1]);
				$enabledName = array('propale' => 'propal', 'supplierproposal' => 'supplier_proposal', 'ficheinter' => 'intervention')[$module] ?? $module;
				if (!isModEnabled($enabledName)) {
					continue;
				}
				$dirPart = dol_buildpath('/'.getModuleDirForApiClass($module).'/class/');
				$h2 = @opendir(dol_osencode($dirPart));
				if (!is_resource($h2)) {
					continue;
				}
				while (($f = readdir($h2)) !== false) {
					if ($f === 'api_access.class.php' || !preg_match('/^api_(.*)\.class\.php$/i', $f, $regapi)) {
						continue;
					}
					$classname = str_replace('_', '', ucwords($regapi[1]));
					require_once $dirPart.$f;
					if (class_exists($classname.'Api')) {
						$classname .= 'Api';
					} elseif (!class_exists($classname)) {
						continue;
					}
					foreach ($this->getPathsOfClass($classname) as $p) {
						$out[$p['path']] = $p;
					}
				}
				closedir($h2);
			}
			closedir($handle);
		}
		ksort($out);
		return array_values($out);
	}

	/**
	 * GET routes of one API class (from @url annotations, or index()/get() by Restler convention)
	 *
	 * @param string $classname Class
	 * @return array<array{path:string,summary:string}>
	 */
	private function getPathsOfClass($classname)
	{
		$res = array();
		$base = '/'.strtolower($classname);
		$rc = new ReflectionClass($classname);
		foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
			if ($m->isStatic() || $m->isConstructor() || $m->getDeclaringClass()->getName() !== $classname || strpos($m->getName(), '_') === 0) {
				continue;
			}
			$doc = (string) $m->getDocComment();
			$summary = '';
			if (preg_match('/\/\*\*\s*\n\s*\*\s*([^@\n][^\n]*)/', $doc, $sm)) {
				$summary = trim($sm[1]);
			}
			$urls = array();
			if (preg_match_all('/@url\s+(GET|POST|PUT|DELETE|PATCH)\s+(\S*)/i', $doc, $mm, PREG_SET_ORDER)) {
				foreach ($mm as $u) {
					if (strtoupper($u[1]) === 'GET') {
						$urls[] = trim($u[2], '/');
					}
				}
			} elseif ($m->getName() === 'index') {
				$urls[] = '';
			} elseif ($m->getName() === 'get') {
				$params = $m->getParameters();
				$urls[] = $params ? '{'.$params[0]->getName().'}' : '';
			}
			foreach ($urls as $u) {
				$res[] = array('path' => rtrim($base.'/'.$u, '/'), 'summary' => dol_trunc($summary, 120));
			}
		}
		return $res;
	}
}
