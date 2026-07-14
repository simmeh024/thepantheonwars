# Google OAuth setup

Google sign-in is intentionally inactive until credentials are placed in the
outside-webroot secrets file. This keeps the client secret out of Git and out
of `public_html`.

1. In Google Cloud Console, create or select the project for The Pantheon Wars.
2. Configure the OAuth consent screen for the site and create an **OAuth client
   ID** of type **Web application**.
3. Add this exact Authorized redirect URI:

   `https://thepantheonwars.com/api/oauth/callback.php?provider=google`

4. In cPanel File Manager, edit
   `/home/rdy3i6my40b0/pantheonwars-secrets/config.php` and add:

   ```php
   define('GOOGLE_OAUTH_CLIENT_ID', 'your-client-id.apps.googleusercontent.com');
   define('GOOGLE_OAUTH_CLIENT_SECRET', 'your-client-secret');
   define('GOOGLE_OAUTH_REDIRECT_URI', 'https://thepantheonwars.com/api/oauth/callback.php?provider=google');
   ```

5. Run `sql/migration_oauth_google.sql` in phpMyAdmin before enabling the
   credentials. It creates `oauth_identities` and permits a `NULL`
   `users.password_hash` for Google-only accounts.

The implementation uses OAuth state and PKCE, exchanges the authorization code
server-side, and asks Google's UserInfo endpoint for a verified email. It stores
only Google's stable subject identifier and verified email—never an access token,
refresh token, or Google password. An email that already belongs to a password
account is deliberately not linked automatically: the visitor must first sign in
to that existing account and link Google from Profile Settings.

Google is configured through the provider-neutral helpers in `api/oauth.php`.
Adding a later provider means adding its configuration and verified-profile
exchange there while reusing the same state, identity table, session, profile,
and audit-log flow.
