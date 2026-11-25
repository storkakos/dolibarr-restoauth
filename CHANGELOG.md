# Changelog

All notable changes to restoauth are listed here. Versions follow [Semantic Versioning](https://semver.org/);
each release is a git tag `vX.Y.Z` with the ZIP `module_restoauth-X.Y.Z.zip` for Dolibarr's module
upload.

## Unreleased

- Fix: before the identity provider's logout ("Not <name>?" link), the login return cookie `DOL_rollback_url_*`
  now holds the consent page's path as Dolibarr builds it (with Dolibarr's own sub-path), not the path of
  `RESTOAUTH_ISSUER`, which can be a public path a proxy rewrites. The cookie gets `Secure` also when Dolibarr is
  set to force https behind a TLS proxy (as Dolibarr's own cookies). No reactivation needed.
- Front-channel logout writes the login and authentication mode of a session it leaves alone to the log only as
  plain characters. Code comments of the shared library are in English; small cleanups on the consent page.

## 0.3.0 - 2026-11-25

Logout and fresh login through the identity provider: front-channel logout, the consent page's "Not <name>?"
link (also through the provider's logout), `prompt=login` and `max_age`, each with its fixes.

- Upgrading from 0.2.2: install the ZIP over the old version. No reactivation needed; clients, connections and
  settings are kept, and no new constant is required. To use the new parts with Keycloak: front-channel logout
  URL `<issuer>/frontchannel-logout.php` (README: "Front-channel logout (Keycloak)"), and for the IdP logout of
  the "Not <name>?" link `<issuer>/consent.php*` under "Valid post logout redirect URIs" plus, if Dolibarr's
  `MAIN_AUTHENTICATION_OIDC_LOGOUT_URL` is not set, the optional constant `RESTOAUTH_OIDC_END_SESSION_URL`.
- Fix: `prompt=login` and `max_age` no longer miss a request sent in the same second as the last login, and
  they use this browser session's login time (stored by a new `USER_LOGIN` trigger in the PHP session), not the
  user's last login on any device. The login made for the request is never asked for again (marker in the signed
  request), so there is no loop. Known limit: until the request expires, the marked URL opened again in the same
  browser is answered without another login (see README, `prompt=login` and `max_age`). "Logged in since" on the consent page shows the same session time. No
  reactivation needed; sessions that began before the update use the user's last login date until they end.
- Fix: `max_age` is measured from the authorization request (`iat`), not from when the consent page loads, so
  waiting on the consent page no longer forces an extra login. The "Not <name>?" link carries its own token in
  the parameter `restoauth_switch` (bound to the session and user); Dolibarr's CSRF check drops a GET `token`
  that is not the current page token, so before, the link only reloaded the page once the session had opened
  another Dolibarr page. No reactivation needed.
- Fix: the front-channel logout endpoint (`frontchannel-logout.php`) ends a Dolibarr session only if it was
  opened through the identity provider (authentication mode `openid_connect`). Before, it also ended a session
  opened with Dolibarr's own login form (password), contrary to the README: an administrator logged in with a
  password lost the session when someone else logged out of Keycloak in the same browser. No new constants; no
  reactivation needed.
- The consent page's "Not <name>?" link also ends the identity provider's session when the Dolibarr session came
  from `openid_connect`: it sends the browser through the provider's RP-initiated logout
  (`client_id`, `post_logout_redirect_uri` = the same consent request) and back. The endpoint is the new optional
  constant `RESTOAUTH_OIDC_END_SESSION_URL` or taken from `MAIN_AUTHENTICATION_OIDC_LOGOUT_URL`; without one the
  page shows a hint to log out of the provider first. Keycloak needs `<issuer>/consent.php*` under "Valid post
  logout redirect URIs". Before the hop the cookie `DOL_rollback_url_*` is set to the consent page, so Keycloak's
  front-channel iframe (loading `user/logout.php` without a session, same-site setups) cannot make the next
  login return to the logout page. No reactivation needed.
- `authorize.php` honours `prompt=login` and `max_age`: if the browser's Dolibarr session began before the
  request (and, for `max_age`, is older than that), the consent page ends it and the user logs in again.
  Without the parameters nothing changes. A login through Keycloak can still be a silent single sign-on.
  No new constants; no reactivation needed.
- README: the nginx discovery rules are also tested with nginx serving Dolibarr through PHP-FPM, and
  the nginx section says what the rest of the server block needs for restoauth (`PATH_INFO`,
  files that exist, `/conf/` denied). Documentation only.
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
