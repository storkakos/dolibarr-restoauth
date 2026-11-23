# restoauth: OAuth 2.1 for the Dolibarr REST API

A Dolibarr module that makes Dolibarr the **authorization server** for its own REST API. AI
assistants (an MCP server such as [dolibarr-mcp-server](https://github.com/storkakos/dolibarr-mcp-server),
reached from an MCP client such as LibreChat) then work with the rights of the signed-in user. Users
log in through the normal Dolibarr login, which includes Keycloak via `openid_connect`. No API keys
have to be created or copied, and **no Dolibarr core code is changed**.

- Tested and supported with **Dolibarr 23** (23.0.3) and PHP 8.3. The module refuses to install on
  Dolibarr versions below 23.
- Authorization code flow with mandatory PKCE S256, dynamic client registration with a redirect URI
  allowlist, resource indicators (RFC 8707), rotating refresh tokens with reuse detection, and
  revocation.
- Users see and end their connections on their user card; administrators manage clients on the
  module's setup page.
- Existing `DOLAPIKEY` integrations (for example n8n) keep working unchanged.

## Contents

1. [Prerequisites](#prerequisites)
2. [Install](#install)
3. [Configure Dolibarr](#configure-dolibarr)
4. [Rewrite rules for discovery](#rewrite-rules-for-discovery)
5. [Endpoints](#endpoints)
6. [Lifetimes](#lifetimes)
7. [How the REST API accepts the token (Dolibarr 23)](#how-the-rest-api-accepts-the-token-dolibarr-23)
8. [Tables](#tables)
9. [Security notes](#security-notes)
10. [Licence](#licence)
11. [Development](#development)

## Prerequisites

- Dolibarr 23 with PHP 8.1 or newer (tested with 23.0.3 and PHP 8.3)
- The alternative module root `custom` set up in `conf/conf.php`
  (`$dolibarr_main_url_root_alt='/custom'` and `$dolibarr_main_document_root_alt` pointing at
  `htdocs/custom`), which is the Dolibarr default for external modules
- Access to the web server configuration for one rewrite rule (see
  [Rewrite rules for discovery](#rewrite-rules-for-discovery))
- If users log in through Keycloak (`openid_connect`): every user must already exist in Dolibarr,
  with the Dolibarr login equal to the Keycloak `preferred_username`. Dolibarr 23 does not create
  users automatically on first login.

## Install

**From the release ZIP (recommended).** Download `module_restoauth-X.Y.Z.zip` from the tagged
release (or build it yourself, see [Development](#development)). In Dolibarr go to Setup → Modules
→ Deploy/install external module, upload the ZIP and install it. Dolibarr unpacks it to
`htdocs/custom/restoauth`. Then continue with [Configure Dolibarr](#configure-dolibarr). Module
upload must be allowed in Dolibarr (no `installmodules.lock` in the documents folder).

**From a checkout.** Check out a release tag and copy the repository content (this folder) to
`htdocs/custom/restoauth`, so that `htdocs/custom/restoauth/core/modules/modRestOAuth.class.php`
exists.

What changed between versions: [CHANGELOG.md](CHANGELOG.md). To update, install the newer ZIP the
same way (Dolibarr replaces the module folder); to roll back, install the older ZIP.

## Configure Dolibarr

1. **Enable the modules.** Setup → Modules: enable **API REST** (`modApi`) and **REST-OAuth (AI
   access)**. restoauth depends on API REST and creates its tables, constants, rights and cron job
   on activation.
2. **Open the setup page.** Setup → Modules → REST-OAuth → gear icon, then set:
   - **Allowed redirect URIs**: one per line (or comma-separated). Only clients whose redirect URIs
     match an entry may register. An entry matches exactly; an entry that ends in `*` matches every
     URI that starts with the part before the `*`. Example:
     `https://chat.example.com/api/mcp/dolibarr/oauth/callback`
   - **Allowed resources**: the URL of the MCP server (RFC 8707), for example
     `https://mcp-dolibarr.example.com/mcp`. It must match the MCP server's resource URL (a trailing
     slash is ignored). **Leave this list empty only for testing:** an empty list allows any
     resource.
   - **Allow loopback redirects** (`http://localhost`, `127.0.0.1`, `[::1]`): only for local tests.
   - **Issuer**: leave empty to use the module URL (`<dolibarr>/custom/restoauth`). Set it only if
     Dolibarr is reached under a different public URL, for example behind a reverse proxy.
   - Token, code, consent request and connection lifetimes (see [Lifetimes](#lifetimes)).
3. **Grant the connect right.** Give the users or groups that may use the assistant the right
   **"Connect applications (AI assistant) via OAuth with own rights"** (REST-OAuth module).
   Administrators may always connect. Users without this right cannot authorize a client.
   The second right, **"Administer OAuth clients and connections of all users"**, is for
   administrators of the module.
4. **Add the discovery rewrite rule** to the web server (next section).
5. **Leave `API_IN_TOKEN_TABLE` off** on Dolibarr 23. It is broken in 23.0.3; restoauth uses a hook
   instead (see [below](#how-the-rest-api-accepts-the-token-dolibarr-23)). The setup page shows an
   error box if it is switched on with Dolibarr 23.
6. **Cron.** Dolibarr's scheduled jobs (module Scheduled jobs / `cron`) should run, so that the job
   `RestOAuthCleanup` deletes expired access tokens, authorization codes and refresh tokens every
   five minutes. Expired entries are also cleaned up on the side at the token endpoint.

### Check list

- `GET <dolibarr>/.well-known/oauth-authorization-server/custom/restoauth` returns JSON with
  `"issuer": "<dolibarr>/custom/restoauth"`.
- The setup page shows "Disabled" for `API_IN_TOKEN_TABLE`.
- A test user with the connect right can connect the MCP client and sees the consent page; a user
  without it is refused.
- After connecting, the user card shows the tab **Connected applications** with the new
  connection.

## Rewrite rules for discovery

MCP clients look up the authorization server metadata at the server root, with the issuer path
appended (RFC 8414): `/.well-known/oauth-authorization-server/custom/restoauth`. Dolibarr cannot
serve that path itself, so the web server rewrites it to the module's `metadata.php`.

Apache (in the virtual host configuration or in `htdocs/.htaccess`, with `mod_rewrite` enabled):

```apache
RewriteEngine On
RewriteRule ^/?\.well-known/(oauth-authorization-server|openid-configuration)/custom/restoauth/?$ /custom/restoauth/metadata.php [L]
RewriteRule ^/?custom/restoauth/\.well-known/(oauth-authorization-server|openid-configuration)/?$ /custom/restoauth/metadata.php [L]
```

nginx (in the `server` block that serves Dolibarr):

```nginx
location ~ ^/\.well-known/(oauth-authorization-server|openid-configuration)/custom/restoauth/?$ {
    rewrite ^ /custom/restoauth/metadata.php last;
}
location ~ ^/custom/restoauth/\.well-known/(oauth-authorization-server|openid-configuration)/?$ {
    rewrite ^ /custom/restoauth/metadata.php last;
}
```

If Dolibarr does not live at the web root, put its path prefix in front of `/custom` in both the
pattern and the target.

## Endpoints

Issuer: `<dolibarr>/custom/restoauth`

| Address | Purpose |
| --- | --- |
| `metadata.php`, `/.well-known/oauth-authorization-server/custom/restoauth` | Authorization server metadata (RFC 8414) |
| `authorize.php` | Authorization endpoint (`response_type=code` only, PKCE S256 required, `resource` per RFC 8707) |
| `consent.php` | Consent page behind the normal Dolibarr login; lists the rights the assistant will act with |
| `token.php` | Grants `authorization_code` and `refresh_token`; client authentication `none`, `client_secret_post`, `client_secret_basic` |
| `register.php` | Dynamic client registration (RFC 7591), redirect URIs from the allowlist only |
| `revoke.php` | Token revocation (RFC 7009); ends the whole connection |
| `connected.php` | User card tab **Connected applications**: list and end one's own connections |
| `/api/index.php/restoauth/me` | REST: user, active modules, current rights, token expiry and the rights rules from `rights_map.json` |
| `/api/index.php/restoauth/endpoints` | REST: readable REST paths of all active API classes |

`restoauth/me` and `restoauth/endpoints` are the contract the Dolibarr MCP server depends on. The
second one exists because Dolibarr's Swagger explorer does not run the module's hook.

`rights_map.json` lists which Dolibarr rights a REST path needs where the default rule (first path
segment = module, `GET` → `lire`/`read`) does not apply. It was read from the `api_*.class.php`
files of Dolibarr 23.0.3.

## Lifetimes

| Setting | Default | Configurable |
| --- | --- | --- |
| Access token | 3600 s | yes (`RESTOAUTH_ACCESS_TOKEN_LIFETIME`, 60 to 86400 s) |
| Authorization code (single use) | 120 s | yes (`RESTOAUTH_CODE_LIFETIME`, 30 to 600 s) |
| Signed authorization request (authorize → login and consent) | 900 s (15 min) | yes (`RESTOAUTH_CONSENT_REQUEST_LIFETIME`, 60 to 3600 s) |
| Connection without use (refresh token idle) | 30 days | yes (`RESTOAUTH_REFRESH_IDLE_DAYS`, 1 to 365 days) |
| Connection at most (refresh token family) | 90 days | yes (`RESTOAUTH_REFRESH_MAX_DAYS`, 1 to 365 days, at least the idle value) |

All values are set on the module's setup page. The page shows the allowed range of each value and
refuses the whole save if a value is not a whole number inside its range, or if the idle days are
greater than the maximum days; the entered values stay in the form. The rule of thumb from RFC 6749
is a code lifetime of at most 10 minutes. The consent request lifetime is the time a user has from
the client's authorize request until they click **Allow**, including the Dolibarr or Keycloak login.

A changed lifetime applies to codes, requests and tokens issued after the change: the expiry is
fixed when they are issued. Refresh tokens of existing connections get the new idle and maximum
days at their next refresh. If a constant is missing (for example after updating from 0.1.0 without
reactivating the module) or is not a whole number, the default applies; a value set outside the
setup page is clamped to the allowed range, and a maximum below the idle days counts as the idle
days.

## How the REST API accepts the token (Dolibarr 23)

1. The access token is stored encrypted in the core table `llx_oauth_token`
   (`service='dolibarr_rest_api'`, `state='restoauth:<connection>'`).
2. The hook `beforeApiCall` (runs in `api/index.php` before the core checks the key) finds the
   token, rejects it with `401` if it has expired, and otherwise puts the user's internal API key in
   its place for this one request. restoauth creates that internal key on first connection if the
   user has none.
3. The core then checks as for any API key: user active, validity period, rights. Disabling a
   Dolibarr user therefore cuts off all of that user's connections.

Requests with a key that is not a restoauth token pass through unchanged, so existing `DOLAPIKEY`
integrations keep working.

From Dolibarr 24 with `API_IN_TOKEN_TABLE=1` the core reads the token itself and the hook only
checks the expiry. That path is not tested and not supported yet.

## Tables

| Table | Content |
| --- | --- |
| `llx_restoauth_client` | Clients (client_id, SHA-256 of the secret, name, redirect URIs) |
| `llx_restoauth_code` | Authorization codes (SHA-256, PKCE challenge, expiry) |
| `llx_restoauth_refresh` | Refresh tokens (SHA-256, connection ID, expiry, rotated/revoked) |
| `llx_oauth_token` (core) | Access tokens (encrypted, with `expire_at`) |

Codes, refresh tokens and client secrets are stored only as hashes; access tokens are stored
encrypted. When the module is disabled, the tables stay. To end connections: user card →
**Connected applications**, or setup page → delete a client.

## Security notes

- PKCE S256 is mandatory; authorization codes are single use and live 120 s by default
  (configurable, at most 10 minutes).
- A refresh token is rotated on every use. Reusing an old refresh token revokes the whole
  connection.
- Tokens are bound to the resource the client asked for; restrict **Allowed resources** to your MCP
  server's URL.
- The assistant acts with the user's own Dolibarr rights; the consent page lists them.

## Licence

restoauth is licensed under the **GNU General Public License v3.0 or later**
(GPL-3.0-or-later), the same licence as Dolibarr. See [LICENSE](LICENSE).

The module runs inside Dolibarr. The Dolibarr MCP server
([dolibarr-mcp-server](https://github.com/storkakos/dolibarr-mcp-server), MIT) is a separate
program that runs as its own process and talks to Dolibarr only over HTTP; it needs restoauth on
the Dolibarr side.

## Development

Agent workflow, tickets and the test VM: see [AGENTS.md](AGENTS.md).

Lint every PHP file (`php -l`); the script uses a local `php` or, if there is none, a
`php:8.3-cli` Docker container:

```sh
bash scripts/lint-php.sh
```

On the Windows dev machine, never run it locally: one command copies the working tree
(uncommitted changes included) to the test VM, runs the lint there, shows the result with the
lint's exit code and cleans up. Run it from Git Bash; see
[docs/agents/test-vm.md](docs/agents/test-vm.md):

```sh
bash scripts/test-on-vm.sh
```

Build the release ZIP from a tag. The script checks that the tag `vX.Y.Z` matches
`$this->version` in `core/modules/modRestOAuth.class.php` and writes
`dist/module_restoauth-X.Y.Z.zip` with `git archive`, so only committed files of that tag go in and
the same tag always gives the same ZIP. Repo-only files (`AGENTS.md`, `docs/`, `scripts/`) are left
out via `export-ignore` in `.gitattributes`.

```sh
bash scripts/build-release-zip.sh v0.2.1
```

Release steps: set the new version in the module descriptor, add a `CHANGELOG.md` entry, commit,
tag `vX.Y.Z`, build the ZIP and attach it to the GitHub release.

The module is tested end to end in the test stack of dolibarr-mcp-server (Dolibarr 23, Keycloak,
LibreChat). There are no PHPUnit tests yet.
