# Etsy connection on Windows XAMPP

Open **Sistem → Koneksi Marketplace → Etsy** in Paperbell.

1. Register this exact redirect URI in the Etsy app settings:
   `https://app.paperbell.id/etsy-callback.php`
2. Use the Paperbell host computer and a browser that trusts the existing
   Paperbell local HTTPS certificate. The hostname must resolve to this XAMPP
   installation. Etsy redirects the browser; the callback does not need to be
   exposed to the public internet.
3. Click **Hubungkan Etsy**, sign in to the intended Etsy seller account, and
   approve shop read and listing read/write access. Complete the flow in the
   same browser within ten minutes. Return to Koneksi Marketplace afterward.

Connecting does not create, publish, edit, or delete listings. Order sync and
listing management screens are separate features, not included in this change.
Disconnect clears Paperbell's stored tokens and pending authorizations; revoke
the app separately in Etsy if access should also be withdrawn there.

## Credentials and storage

App credentials live in `storage/etsy/credentials.json`, with `keystring` and
`shared_secret`. Optional `redirect_uri` overrides the default; it must be an
HTTPS URL on this installation ending in `/etsy-callback.php`, without query
parameters, and must match the registered Etsy URL exactly. Its sibling
`etsy-authorize.php` must also be served on that host.

`storage/.htaccess` denies web access and Git ignores `storage/`. Do not commit
credentials or tokens. Tokens and PKCE state are encrypted using the existing
OAuthVault host key, in the dedicated `etsy_oauth_connection` and
`etsy_oauth_states` MySQL tables. Status responses never include tokens or app
credentials. OAuth uses one-use launch tickets, expiring state, PKCE S256, and
a Secure/HttpOnly/SameSite=Lax cookie binding the authorization to its browser.
Access tokens are refreshed on status/credential reads when close to expiry.

## Validation

Run `C:/xampp/php/php.exe tests/EtsyOAuthServiceTest.php` for isolated fake
transport tests of PKCE, browser binding, state/ticket replay rejection,
expiry, token encryption, refresh, denial handling, and disconnect.

Official reference:
https://developers.etsy.com/documentation/essentials/authentication/
