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
