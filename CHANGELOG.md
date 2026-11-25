# Changelog

All notable changes to restoauth are listed here. Versions follow [Semantic Versioning](https://semver.org/);
each release is a git tag `vX.Y.Z` with the ZIP `module_restoauth-X.Y.Z.zip` for Dolibarr's module
upload.

## Unreleased

- New endpoint `frontchannel-logout.php` for Keycloak's front-channel logout (README: "Front-channel
  logout (Keycloak)"). It ends the browser's Dolibarr session like Dolibarr's own Logout, without a
  redirect, never shows the login page and sets no cookie. Used instead of Dolibarr's
  `user/logout.php`, it avoids that the next Keycloak login after Dolibarr's own Logout ends on the
  logout page (cookie `DOL_rollback_url_*`). No new constants; no reactivation needed.
- The consent page shows "Not <name>? Log out and use another account" next to the user, and since
  when and how that account is logged in (README: "Consent page"). The link ends the Dolibarr session
  like Dolibarr's own Logout and returns to the same consent request, so a second person in the same
  browser can log in as themselves. An expired consent request now says that it expired instead of
  "invalid or expired". The front-channel logout endpoint and the link share one function that ends
  the session. No new constants; no reactivation needed.

## 0.2.2 - 2026-11-24

- Disabling a Dolibarr user now ends all of that user's connections at once: their refresh tokens
  are locked and their access tokens deleted (trigger on `USER_ENABLEDISABLE`). Enabling the user
  again does not bring the old tokens back; the user connects the application again. Before, an
  access token that was not used while the user was disabled worked again after re-enabling.
- The REST hook also ends all connections of a user when it sees a restoauth token of a disabled or
  deleted user (a status changed without the trigger, e.g. directly in the database), and answers
  `401`.
- Upgrading from 0.2.1: install the ZIP over the old version, then deactivate and activate the module
  once in Setup → Modules so that Dolibarr registers the new trigger. Clients, connections and
  settings are kept; no new constants.

## 0.2.1 - 2026-11-23

- The setup page now also validates the access token lifetime (`RESTOAUTH_ACCESS_TOKEN_LIFETIME`,
  60 to 86400 s), the connection idle days (`RESTOAUTH_REFRESH_IDLE_DAYS`, 1 to 365) and the
  connection maximum days (`RESTOAUTH_REFRESH_MAX_DAYS`, 1 to 365). A value that is not a whole
  number inside its range, or idle days greater than the maximum days, refuses the whole save with
  an error naming the setting; the entered values stay in the form. Before, these values were only
  clamped at runtime.
- Runtime: these three settings use the same bounds as the setup page. A missing or non-numeric value
  now falls back to the default (3600 s, 30 days, 90 days) instead of the minimum; a value outside
  the range is clamped, and a maximum below the idle days still counts as the idle days.
- Upgrading from 0.2.0: install the ZIP over the old version. Clients, connections and settings are
  kept; no new constants.

## 0.2.0 - 2026-11-23

- The authorization code lifetime (`RESTOAUTH_CODE_LIFETIME`, default 120 s, 30 to 600 s) and the
  lifetime of the signed authorize-to-consent request (`RESTOAUTH_CONSENT_REQUEST_LIFETIME`, default
  900 s, 60 to 3600 s) can be set on the setup page. Both were fixed before; the defaults keep the
  old behaviour.
- The setup page refuses the whole save, with an error naming the setting and its range, if one of
  these values is not a whole number inside its range.
- Upgrading from 0.1.0: install the ZIP over the old version. Clients, connections and settings are
  kept. Until the module is deactivated and activated again the two new constants do not exist and
  the defaults apply; saving the setup page once also creates them.

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
