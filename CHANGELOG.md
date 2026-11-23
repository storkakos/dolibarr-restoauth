# Changelog

All notable changes to restoauth are listed here. Versions follow [Semantic Versioning](https://semver.org/);
each release is a git tag `vX.Y.Z` with the ZIP `module_restoauth-X.Y.Z.zip` for Dolibarr's module
upload.

## 0.1.0 - 2026-11-23

First release.

- Dolibarr becomes the OAuth 2.1 authorization server for its own REST API: authorization server
  metadata (RFC 8414), authorization code flow with mandatory PKCE S256 and resource indicators
  (RFC 8707), consent page behind the normal Dolibarr login, token endpoint (`authorization_code`,
  `refresh_token`; client authentication `none`, `client_secret_post`, `client_secret_basic`),
  dynamic client registration with a redirect URI allowlist (RFC 7591) and revocation (RFC 7009).
- Rotating refresh tokens with reuse detection; a reused refresh token revokes the whole connection.
- `beforeApiCall` hook that lets the REST API accept restoauth access tokens on Dolibarr 23 without
  `API_IN_TOKEN_TABLE`; existing `DOLAPIKEY` integrations keep working.
- REST extensions `GET /restoauth/me` and `GET /restoauth/endpoints` for the Dolibarr MCP server.
- Rights "Connect applications (AI assistant) via OAuth with own rights" and "Administer OAuth
  clients and connections of all users".
- User card tab **Connected applications**; setup page with allowlists, lifetimes and client list.
- Codes, refresh tokens and client secrets stored as hashes, access tokens encrypted; cron job
  `RestOAuthCleanup` removes expired entries.
- Requires Dolibarr 23 (tested with 23.0.3) and PHP 8.1 or newer.
