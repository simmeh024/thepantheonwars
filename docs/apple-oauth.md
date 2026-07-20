# Apple OAuth setup ("Sign in with Apple")

Apple sign-in is inactive until **both** of these are true:

1. Credentials are placed in the outside-webroot secrets file (steps below).
2. **Enable Apple OAuth** is switched on in Admin Console → System → Site
   Settings (off by default — `oauth_apple_enabled` in `app_settings`). This
   toggle exists so Apple can be wired up and tested independently of
   whether a Developer account is ready yet, and switched off again later
   without touching credentials or code. Google has the same toggle
   (on by default) for symmetry. Whichever is off, that provider's button is
   also hidden on the public login/register modal, not just blocked server-side.

Unlike Google, there is no static client secret string to paste in — Apple's
token endpoint takes a short-lived JWT that the server signs itself on every
sign-in attempt, using a private key you generate once.

Requires an active Apple Developer Program membership ($99/year).

1. In the [Apple Developer portal](https://developer.apple.com/account/resources/identifiers/list),
   create an **App ID** (Identifiers → App IDs) with the **Sign In with Apple**
   capability enabled, if one doesn't already exist for this project.
2. Create a separate **Services ID** (Identifiers → Services IDs → +). Its
   identifier (e.g. `com.thepantheonwars.web`) becomes `APPLE_OAUTH_CLIENT_ID`
   below — this is **not** the App ID's bundle identifier.
3. On that Services ID, enable **Sign In with Apple**, click Configure, and
   register:
   - **Domain:** `thepantheonwars.com`
   - **Return URL:** `https://thepantheonwars.com/api/oauth/callback.php?provider=apple`
4. Apple will show a domain-verification file to download
   (`apple-developer-domain-association.txt`). Host it, unmodified, at:

   `https://thepantheonwars.com/.well-known/apple-developer-domain-association.txt`

   The domain will not verify until this file is reachable at that exact path.
5. Under Keys (Certificates, Identifiers & Profiles → Keys → +), create a new
   key with **Sign In with Apple** enabled, associated with the App ID from
   step 1. Download the `.p8` file immediately — Apple only lets you download
   it once. Note its **Key ID** (shown on the key's details page) and your
   account's **Team ID** (top-right of the Developer portal, or Membership
   page).
6. In cPanel File Manager, edit
   `/home/rdy3i6my40b0/pantheonwars-secrets/config.php` and add:

   ```php
   define('APPLE_OAUTH_CLIENT_ID', 'com.thepantheonwars.web');
   define('APPLE_OAUTH_TEAM_ID', 'YOUR10CHARTEAMID');
   define('APPLE_OAUTH_KEY_ID', 'YOURKEYID1');
   define('APPLE_OAUTH_PRIVATE_KEY', "-----BEGIN PRIVATE KEY-----\n...contents of the .p8 file...\n-----END PRIVATE KEY-----");
   define('APPLE_OAUTH_REDIRECT_URI', 'https://thepantheonwars.com/api/oauth/callback.php?provider=apple');
   ```

   The private key never expires and never needs manual rotation — the
   server signs a fresh, minutes-long JWT client secret from it on every
   sign-in attempt (`pw_oauth_apple_client_secret()` in `api/oauth.php`),
   rather than storing one long-lived secret string the way Google does.

`oauth_identities.provider` is a plain `VARCHAR(32)`, not an enum, so it
already accepts `'apple'` as a value with no schema change. Run
`sql/migration_site_settings.sql` once (if it hasn't already run for the
Google toggle) to add the Site Settings permissions and the
`oauth_apple_enabled`/`oauth_google_enabled` `app_settings` rows the toggle
above reads and writes.

## How it differs from the Google flow

- Apple's authorization request uses `response_mode=form_post`: Apple **POSTs**
  the result (state, code, and — on the very first authorization only — the
  user's name) back to the redirect URI instead of a GET redirect. `api/oauth/
  callback.php` merges `$_POST` and `$_GET` so the same endpoint URL serves
  both providers.
- There is no Apple "UserInfo" REST endpoint. The identity (subject id,
  email, whether the email is verified) comes from decoding the `id_token`
  JWT that Apple's token endpoint returns directly over TLS in the same
  server-to-server response used to redeem the authorization code — the
  trust boundary is that direct, authenticated connection to Apple, the same
  way the Google flow trusts an access-token-authenticated REST call rather
  than independently re-verifying a signature. Standard claim checks
  (`aud`, `iss`, `exp`, `email_verified`) are still applied defensively.
- Apple never provides a profile picture. The "import profile picture"
  option only appears on the Google button.
- Apple only ever sends the user's name once — the very first time an
  account authorizes this Services ID. If that first grant is lost (e.g. the
  account creation transaction fails), Apple will not send the name again
  for that user; `api/oauth/callback.php` falls back to the generated
  username in that case, identical to how a Google profile with no `name`
  claim is already handled.

Both providers share the same state/PKCE handling, `oauth_identities` table,
session issuance, account-linking rules (an email that already belongs to a
password account is not auto-linked — the visitor signs in with the password
first and links the new provider from Profile Settings), and audit logging in
`api/oauth.php` / `api/oauth/callback.php`. A third provider only needs a
config branch plus a verified-profile exchange function alongside these two.
