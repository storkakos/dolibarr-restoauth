# restoauth

The Dolibarr module that lets applications connect to Dolibarr's REST API on a user's behalf through OAuth.

## Consent

**Consent request**:
The signed authorization request that the authorization endpoint hands to the consent page; it expires after its own lifetime.
_Avoid_: req, packed request

**Forced request**:
A consent request for which a fresh login was already forced once; it never forces another login.
_Avoid_: marked URL, retry

## Login

**Session login time**:
When this browser's Dolibarr session began, which is what `prompt=login` and `max_age` compare with.
_Avoid_: last login, datelastlogin (the user's last login on any device)
