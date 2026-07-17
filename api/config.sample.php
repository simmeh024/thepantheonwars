<?php
/**
 * TEMPLATE ONLY — this file is committed to git so the shape is documented.
 *
 * The REAL config.php must NOT live in this repo and must NOT be committed.
 * Create it directly on the server, outside public_html, at:
 *   /home/rdy3i6my40b0/pantheonwars-secrets/config.php
 * (create the "pantheonwars-secrets" folder once via cPanel File Manager,
 * one level above public_html, then create config.php inside it with
 * these same constants filled in with your real values.)
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'rdy3i6my40b0_pantheonwars');
define('DB_USER', 'rdy3i6my40b0_pwuser');
define('DB_PASS', 'REPLACE_WITH_REAL_PASSWORD');

// Google OAuth (optional until Google sign-in is enabled). Create a Web
// application OAuth client in Google Cloud Console and register this exact
// authorised redirect URI:
// https://thepantheonwars.com/api/oauth/callback.php?provider=google
// Keep the real client secret only in the outside-webroot config.php file.
// define('GOOGLE_OAUTH_CLIENT_ID', 'REPLACE_WITH_GOOGLE_CLIENT_ID');
// define('GOOGLE_OAUTH_CLIENT_SECRET', 'REPLACE_WITH_GOOGLE_CLIENT_SECRET');
// define('GOOGLE_OAUTH_REDIRECT_URI', 'https://thepantheonwars.com/api/oauth/callback.php?provider=google');

// Authenticator-app two-factor authentication for password sign-ins. Generate
// this once with `php -r "echo base64_encode(random_bytes(32));"` and keep it
// in the outside-webroot config.php file. It encrypts TOTP secrets before they
// are stored in MariaDB; changing it without a planned rotation invalidates
// existing authenticator enrolments.
// define('TWO_FACTOR_ENCRYPTION_KEY', 'REPLACE_WITH_BASE64_32_BYTE_RANDOM_KEY');

// Optional local spaCy/RapidFuzz enrichment for Dispatch translations. The rule-based
// formatter remains fully functional without it. Create the Python venv with
// the instructions in docs/dispatch-spacy.md, then point at that interpreter.
// Keep this outside the web root because the exact venv path is host-specific.
// define('SPACY_PYTHON_BIN', '/home/rdy3i6my40b0/virtualenv/dispatch-nlp/3.11/bin/python');
// define('SPACY_MODEL', 'en_core_web_md');

// Transactional mail is deliberately off until Mail Settings has a sender
// identity and its delivery toggle is enabled. Shared hosting uses PHP's native
// mail() transport, so no SMTP password is stored in the admin database. These
// optional defaults live outside public_html and are used until an admin saves
// Mail Settings (the saved values take precedence).
// define('MAIL_FROM_NAME', 'The Pantheon Wars');
// define('MAIL_FROM_EMAIL', 'noreply@thepantheonwars.com');
// define('MAIL_REPLY_TO', 'privacy@thepantheonwars.com');

// Optional. When set, outbound mail is sent through the MailerSend HTTP API
// instead of the shared host's local PHP mail() transport -- MailerSend
// signs and relays through its own verified-domain infrastructure, which is
// far more reliable than a shared hosting IP for SPF/DKIM alignment. Create
// a token at https://app.mailersend.com with Email: Send, Domains: Read, and
// Suppressions: Read scopes, and make sure Mail Settings' sender email uses
// that verified domain. Leave commented out and delivery falls back to PHP mail().
// define('MAILERSEND_API_TOKEN', 'REPLACE_WITH_REAL_MAILERSEND_TOKEN');

// Optional. Lets api/mail/mailersend-webhook.php record real delivery
// outcomes (delivered/opened/clicked/bounced/spam complaint/unsubscribed)
// into Mail Log instead of only ever showing "accepted by the API". Create
// a webhook in the MailerSend dashboard (Domains -> your domain -> Webhooks
// -> Add webhook) pointing at
// https://thepantheonwars.com/api/mail/mailersend-webhook.php with the
// activity events you want, then paste the "Signing secret" it shows you
// here (that secret is only ever shown once at creation time).
// define('MAILERSEND_WEBHOOK_SIGNING_SECRET', 'REPLACE_WITH_REAL_SIGNING_SECRET');

// Optional signed inbound-mail webhook. PHP mail() can send email but cannot
// read a mailbox. Configure a provider or mail pipe to POST parsed inbound
// metadata to /api/mail/inbound.php with an X-PW-Mail-Signature HMAC-SHA256
// header. The endpoint records only metadata and body length, never content.
// define('MAIL_INBOUND_WEBHOOK_SECRET', 'REPLACE_WITH_A_LONG_RANDOM_SECRET');

// Optional. Raises the GitHub REST API rate limit used by the System
// Status card/page and the language-snapshot sync from 60 requests/hour
// (unauthenticated) to 5,000 requests/hour (authenticated) -- see
// https://docs.github.com/en/rest/using-the-rest-api/rate-limits-for-the-rest-api
// Create a fine-grained personal access token at
// https://github.com/settings/tokens with read-only "Contents" access to
// the simmeh024/thepantheonwars repo (no other scopes needed), then
// uncomment the line below with the real value. Leave it commented out
// and everything keeps working at the lower unauthenticated limit.
// define('GITHUB_TOKEN', 'REPLACE_WITH_REAL_TOKEN');

// Required for the System Status "CPU (Shared)" 24h chart's cron sampler
// (api/cron/sample-load.php) AND the Visitor Statistics page's daily
// rollup/prune job (api/cron/rollup-page-views.php) -- both are cron-only,
// publicly-reachable endpoints with the same trust boundary, so they share
// this one shared secret rather than needing a constant each. Only
// requests carrying the matching ?key= value (set on each cPanel Cron
// Job's command line) are allowed to hit them. Generate any long random
// string, e.g.:
//   php -r "echo bin2hex(random_bytes(24));"
define('CRON_SAMPLE_KEY', 'REPLACE_WITH_REAL_RANDOM_STRING');
