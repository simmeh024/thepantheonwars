# The Pantheon Wars — Project Handoff / Context

This file is for a fresh Claude Code session picking up work on this repo. Read this
before touching anything — the deploy and DB workflows have real gotchas that have
bitten past sessions more than once.

## What this is

`thepantheonwars.com` — a fiction/worldbuilding site (books, lore, community forum,
dev-dispatches blog) plus an admin console at `/admin/`. Static HTML/vanilla-JS
frontend, PHP 8.3/PDO/MariaDB backend, **no build step**. cPanel shared hosting.

- GitHub repo: `simmeh024/thepantheonwars` (branch `main`)
- Live site: https://thepantheonwars.com
- Admin console: https://thepantheonwars.com/admin/ (login required, role-gated:
  member/moderator/admin)
- cPanel account: `rdy3i6my40b0`, home dir `/home/rdy3i6my40b0`
- MySQL/MariaDB database: the real schema name is simply `pantheonwars` --
  **not** `rdy3i6my40b0_pantheonwars`. That prefixed form was a wrong
  assumption in this file that cost a live debugging session two rounds of
  false "everything is MISSING" results from an INFORMATION_SCHEMA query
  (2026-07-18): `TABLE_SCHEMA = 'rdy3i6my40b0_pantheonwars'` silently
  matched zero rows against every check. Confirmed directly in phpMyAdmin's
  database tree and by a successful query scoped to `TABLE_SCHEMA =
  'pantheonwars'`. If writing a raw INFORMATION_SCHEMA/`DATABASE()` query
  outside the app's normal PDO connection (which selects the database by
  name at connect time and never needs this), use the bare `pantheonwars`
  name, not the cPanel account prefix.

## Deploy workflow (READ THIS FIRST — has a sharp edge)

1. Commit locally, then push with the token temporarily inlined into the remote URL:
   ```
   git remote set-url origin "https://simmeh024:<GITHUB_PAT>@github.com/simmeh024/thepantheonwars.git"
   git push origin main
   git remote set-url origin "https://github.com/simmeh024/thepantheonwars.git"
   ```
   (Strip the token back out immediately after pushing — don't leave it in the remote.)
2. In cPanel → Git™ Version Control → manage the `thepantheonwars` repo → **Pull or
   Deploy** tab:
   - Click **Update from Remote** (occasionally needs a second click — the HEAD
     Commit SHA shown doesn't always update on the first click).
   - Verify the **HEAD Commit** SHA matches what you just pushed.
   - Click **Deploy HEAD Commit**.
   - Verify **Last Deployed SHA** matches.

### Critical gotcha: deploys never delete files

`.cpanel.yml`'s deploy script is `cp -R * $DEPLOYPATH` — a **copy, not a sync**. If you
delete a file from the repo, commit, push, and deploy, cPanel will report success, but
**the old file is still live on the server**. This has bitten multiple sessions
(a stale `errors.php`, a temporary `diag-explore.php` that kept executing after
"removal"). The only fix is to delete the file by hand via cPanel File Manager. If you
ever remove a file from this codebase, go delete it from `public_html/...` manually
afterward and don't just trust the deploy.

### cPanel File Manager notes
- Single-click selects a row.
- Double-clicking a folder's filename text triggers inline rename.
- Double-clicking a folder's icon navigates into it.
- Delete opens a dialog with a "Skip the trash and permanently delete the files"
  checkbox — check it if you actually want the file gone now, not in `.trash`.

## Secrets

Nothing sensitive is in this repo. Real credentials live outside the web root at
`/home/rdy3i6my40b0/pantheonwars-secrets/config.php` (loaded by `api/db.php`). See
`api/config.sample.php` for the documented shape (constants only, no real values):
`DB_HOST/DB_NAME/DB_USER/DB_PASS`, `GITHUB_TOKEN` (optional, raises GitHub API rate
limit), `GITHUB_WEBHOOK_SECRET`, `CRON_SAMPLE_KEY` (gates `api/cron/sample-load.php`).
To edit the real file: cPanel File Manager -> navigate to `/pantheonwars-secrets` ->
select `config.php` -> Edit (opens cPanel's code editor in a new tab).

### Transactional mail

- `api/mail.php` is the only mail-sending path. It reads its enabled flag and
  sender identity from `app_settings`, and returns a result instead of throwing
  so email outages cannot block a sign-in, account creation, or ban.
- **Transport: MailerSend API, with PHP `mail()` as fallback.** Diagnosed via a
  live investigation (Jul 2026): this cPanel/GoDaddy reseller plan sends
  outbound mail through a shared, pooled relay with no self-service DKIM tool
  (`Email Deliverability` 404s, the local Zone Editor is inactive -- real DNS
  lives at GoDaddy's `pdns13/14.domaincontrol.com`) -- delivery worked for some
  sends and silently vanished for others (confirmed via Gmail's "Show
  original": one send passed SPF/DKIM/DMARC cleanly, a same-day burst of
  near-identical test sends vanished with no trace anywhere, not even Spam).
  MailerSend's HTTP API (`pw_mail_send_via_mailersend()`, used automatically
  when `MAILERSEND_API_TOKEN` is set) relays through its own verified-domain
  infrastructure instead, which is what actually fixes this rather than
  papering over it with fewer test sends. `MAILERSEND_API_TOKEN` lives only in
  the outside-webroot secrets config (see `api/config.sample.php`); when unset,
  `pw_mail_uses_mailersend()` is false and every send path falls back to PHP
  `mail()` exactly as before. Mail Settings' sender email must use MailerSend's
  verified domain or sending will be rejected. `mail_delivery_logs.
  provider_message_id` now gets MailerSend's real `X-Message-Id` on success.
- **MailerSend read-only API features** (Jul 2026, needs a token with Email:
  Send, Domains: Read, and Suppressions: Read scopes): shared client in
  `api/mail/mailersend-client.php` (`pw_mailersend_api_get()` and friends),
  every call best-effort/null-on-failure, never fatal.
  - **Real delivery status**: `api/mail/mailersend-webhook.php` is a signed
    receiver (header `Signature`, HMAC-SHA256 of the raw body with
    `MAILERSEND_WEBHOOK_SIGNING_SECRET`) for MailerSend's activity webhooks
    (sent/delivered/opened/clicked/soft_bounced/hard_bounced/
    spam_complaint/unsubscribed). Each event is its own new
    `mail_delivery_logs` row (append-only trail, never mutates the original
    "accepted" row), matched by `provider_message_id`. The webhook itself
    must be created by hand in the MailerSend dashboard (Domains -> your
    domain -> Webhooks) pointed at that URL -- there's no admin-UI "create
    webhook" action, matching this project's existing secrets convention
    (signing secret is shown once at creation, pasted into the
    outside-webroot config, never generated/stored through the admin UI).
  - **Domain verification status**: `api/admin/mail/domain-status.php`
    (`mail.view`) shows SPF/DKIM/MX/CNAME/return-path-CNAME pills in Mail
    Settings, looked up by the sender email's domain via MailerSend's
    `/v1/domains` + `/v1/domains/{id}/verify`.
  - **Send-activity stats**: `api/admin/mail/stats.php` (`mail.logs`)
    aggregates `mail_delivery_logs` itself (last 30 days, grouped by
    status) for the Mail Log stats row -- deliberately not a live
    MailerSend Activity API call, since the webhook-fed local log already
    has everything needed without another external dependency.
  - **Suppression lists**: `api/admin/mail/suppressions.php` (`mail.view`)
    live-queries MailerSend's hard-bounces/spam-complaints/unsubscribes
    lists for the read-only panel on Mail Log -- never cached locally, so
    it's always current with no sync job.
- `mail_templates` holds the closed allowlist of `password_reset`, `welcome`,
  `account_banned`, and `verify_account`. The template editor is gated by
  `mail.view` / `mail.manage`; variable expansion is limited to the keys defined
  in `pw_mail_variables()`.
- `mail_delivery_logs` is a bounded, metadata-only troubleshooting trail for
  outbound attempts and signed inbound events. It records addresses, template /
  subject, transport status, timestamp and body length; it **never stores a mail
  body**. Outbound `accepted` means the local PHP transport accepted the hand-off,
  not that a receiving inbox confirmed delivery. The separate **Mail Log** page
  is gated by `mail.logs`; run `sql/migration_mail_logs.sql` after the earlier
  mail migration to create it and add that permission.
- Native PHP `mail()` cannot read an inbox. `api/mail/inbound.php` is therefore
  a ready-to-connect HMAC-SHA256 receiver for a provider webhook or cPanel mail
  pipe. Keep `MAIL_INBOUND_WEBHOOK_SECRET` only in the outside-webroot config;
  callers sign the exact JSON body in `X-PW-Mail-Signature` (`sha256=<hex>`).
  Inbound rows stay empty until such a sender is configured, which is expected.
- System Status exposes the active mail transport (MailerSend API or PHP mail)
  as Connected or Disconnected. Delivery being deliberately off, or a missing
  sender identity, remains a non-critical configuration state; only a missing
  transport is a critical BH-4 alert because password recovery and
  transactional delivery cannot be sent at all in that state.
- Delivery is **off by default**. The Admin Console's pink-dot **Mail** category
  contains Mail Settings, Mail Templates, and Mail Log. Configure a verified
  sender there, then enable delivery deliberately. Optional `MAIL_FROM_NAME`,
  `MAIL_FROM_EMAIL`, `MAIL_REPLY_TO`, `MAIL_INBOUND_WEBHOOK_SECRET`, and
  `MAILERSEND_API_TOKEN` defaults belong only in the real outside-webroot
  config (documented in `api/config.sample.php`).
- Each Mail Template has a permissioned test action in its editor. It uses the
  saved template and current Mail Settings sender/transport, permits a paused
  template to be previewed, and supplies only harmless example reset/verify
  links rather than creating real account credentials.
- The default Welcome template is a self-contained Pantheon Wars email design
  with inline client-safe styles (deep purple panel, gold hierarchy, and CTA).
  Existing production copy can be refreshed with
  `sql/migration_mail_welcome_template_refresh.sql`; it only replaces the exact
  original default so any editorial customisation is preserved.
- Welcome delivery is hooked into password registration, Google registration, and
  administrator-created accounts; account-suspension delivery is hooked into new
  bans. Never email an administrator-generated password.
- Self-service password recovery is live through `password-reset.html`,
  `api/password-reset/request.php`, and `api/password-reset/reset.php`. It always
  returns the same confirmation for every email address, stores only a SHA-256
  hash of a 256-bit token, puts the raw token in the URL fragment (never in a
  request/Referer), expires it after 30 minutes, makes it single-use, rate-limits
  requests, checks reset passwords against the HIBP k-anonymity API, and revokes
  all remembered sessions once the password changes. Run
  `sql/migration_password_reset.sql` after the mail migration before deploying
  the flow. Verification templates remain prepared for their future token flow.
- Members admin includes a primary-email verification pill. Its source is
  `users.email_verified_at`: Google registrations are marked verified because
  the OAuth profile helper requires Google's `email_verified` claim, while
  password-created accounts stay unverified until a first-party verification
  flow is added. An administrator changing an email clears the timestamp.
  Run `sql/migration_account_verification_status.sql` for this column and the
  safe backfill of matching Google identities.

## Database

`sql/schema.sql` is the source-of-truth accumulator for every table (append new
`CREATE TABLE` statements here as you add them -- it's documentation, not an actual
migration runner). Actual migrations against production are run by hand via
phpMyAdmin's SQL tab (cPanel -> phpMyAdmin -> `pantheonwars` DB -> SQL tab), using a
one-off `migration_*.sql` file committed alongside the feature for the record.

Known schema quirk (fixed): the `books` table used to have a stray
`latin1_swedish_ci` collation while every other table uses `utf8mb4_unicode_ci` --
this was found via the System Status page's "Largest Tables" list (which flags any
non-`utf8mb4_unicode_ci` table in red) and fixed via `ALTER TABLE ... CONVERT TO
CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`. If that flag ever lights up again
on a new table, that's a real bug to fix, not a display artifact.

### World weather pilot

- `world_weather_profiles` stores fictional atmospheric controls separately from
  World Control's lore record. Run `sql/migration_world_weather.sql` after the
  corresponding deploy; it creates the table, adds `weather.view` / `weather.edit`,
  and seeds the first profile for Neoh.
- `api/weather-forecast.php` is the deterministic generator. Its seed combines the
  world slug, current UTC date, and `forecast_revision`, so the five-day forecast
  stays identical across refreshes for a full UTC day. Saving Weather Control
  advances the revision and deliberately produces a new stable sequence.
- **Seasonal drift:** `pw_weather_seasonal_bias()` nudges the randomized day
  3-5 temperature (never the admin-authored day 0/1 values) with a smooth
  sine wave across the calendar year, shifted by a per-world phase offset
  (hashed from the slug) so worlds don't all warm/cool on the same date. It
  is deliberately independent of `forecast_revision` -- season is a stable
  climate pattern, not part of the "reroll the dice" action a Weather Control
  save triggers -- and stays within the existing `forecast_min_c`/`max_c`
  bounds, so no schema or admin UI change was needed. Condition *selection*
  for those days remains uniform-random across the pool rather than
  season-weighted: the seeded condition pools (e.g. Asmecu's `["Salt
  fog","Clear tide","Tidal storm",...]`) were authored with no season
  ordering, so biasing by array position would misrepresent already-live
  content.
- **"Twelve Worlds at a Glance" strip (`worlds.html`):** `api/worlds-weather-
  glance.php` is a new public, unauthenticated endpoint that joins `worlds` to
  `world_weather_profiles` in one query (WHERE status = available AND
  enabled = 1) and calls the same `pw_build_weather_forecast()` used
  everywhere else, returning only each world's current condition/temp/icon --
  a single request instead of one `api/world-weather.php` call per available
  world, matching the same N+1-avoidance principle already used by
  `api/worlds.php`/`api/boards-summary.php`. `js/worlds.js`'s
  `renderWeatherGlance()` fetches it independently of the main atlas data
  fetch (non-blocking, same pattern as `world-detail.js`'s weather request)
  and renders a horizontally-scrollable card strip below the lore-progress
  bar; the whole `#worlds-glance` section stays `hidden` if the endpoint
  returns no worlds (e.g. before any world is both unlocked and has an
  enabled profile), so it never shows an empty shell. Each card's accent
  color reuses the existing `ATLAS_TONES` map (the same per-world glow
  already driving the atlas hover-info panel) via a `--glance-accent` CSS
  custom property, rather than yet another color list.
- `api/world-weather.php?slug=...` is public but only returns forecast details when
  both the World Control record is `available` and its weather profile is enabled.
  It is independent of `api/worlds.php`, preserving that endpoint's response shape.
- **Rollout order matters:** deploy the PHP/CSS/JS first, then run
  `sql/migration_world_weather.sql` in phpMyAdmin, then hard-refresh the admin
  console. The World Record treats a missing or unavailable weather endpoint as
  optional and remains usable; Weather Control itself correctly reports the missing
  profile data until the migration has run. Never fold weather data into
  `api/worlds.php` just to avoid this graceful, separate request.
- **Second calibration world (Asmecu):** `sql/migration_world_weather_asmecu.sql`
  seeds a second `world_weather_profiles` row (tidal/coastal conditions: sea fog,
  tidal storms, tropical humidity) reusing the same table/permissions/endpoints --
  no schema change was needed since the contract was already world-generic. The
  admin Weather Control page (`admin/index.html`) was rewritten from a single
  hardcoded Neoh form into one collapsible `.weather-world-section` per world that
  has a profile row (worlds without one simply don't appear); expand/collapse state
  persists per-browser in `localStorage` under `pw_admin_weather_expanded`, all
  collapsed by default. Every field previously addressed by a fixed `id=` is now
  scoped by class + `closest('.weather-world-section')` so ids never collide across
  worlds. The public World Record weather card gets a per-world class
  (`world-weather-card--<slug>`) and bureau label (`WEATHER_SERVICE_LABELS` in
  `js/world-detail.js`, falling back to `"<World> Atmospheric Service"`); Asmecu's
  variant recolors the live pill/icons/divider/background toward warm timber
  and coral -- the same terracotta already used for the Coral Palace layer
  (`.world-layer-tint--orange`, `rgba(210,120,50)`) -- rather than inventing an
  unrelated palette for the same world.
- **Full rollout (all worlds except The Nexus Veil):**
  `sql/migration_world_weather_remaining.sql` seeds one profile row apiece for
  the other ten calibrated worlds -- High Hammer, Cerius, Reanium, Babki Prime,
  Sed, Geof V, Beoctica, Terek II, Valerium Prime, and Vermillia XI. The Nexus
  Veil is deliberately excluded: it's neutral ground with no per-medallion atlas
  motif (no `DRAWERS` entry in `js/world-atlas-effects.js`), so it has no
  established color identity to reuse. Locked worlds are seeded exactly like
  available ones -- `api/world-weather.php` already gates on the World Control
  record being `available`, so a locked world's profile stays fully editable in
  Weather Control but invisible on the public site until it unlocks, the same
  as every other locked world's lore content. Every new `world-weather-card--
  <slug>` CSS variant and `WEATHER_SERVICE_LABELS` bureau name reuses that
  world's existing `js/world-atlas-effects.js` glow color rather than inventing
  a new palette (e.g. Reanium's toxic green, Sed's solar orange, Beoctica's ice
  white, Vermillia XI's rain blue-grey with a gold-flecked divider for its
  gold-fleck rain particles) -- the same principle used for Asmecu above. A
  future thirteenth world only needs a seed migration plus, optionally, its own
  bureau label/CSS variant; nothing else in the pipeline changes.

## Server introspection notes (this specific host)

Confirmed empirically (useful if extending System Status further):
- `sys_getloadavg()`, `/proc/loadavg`, `/proc/meminfo`, `/proc/cpuinfo` all readable.
- `shell_exec`/`exec` both work, `disable_functions` and `open_basedir` are empty
  (unusually permissive host).
- `SHOW GLOBAL STATUS`/`SHOW GLOBAL VARIABLES` fully queryable via the app's normal
  PDO connection (no elevated privilege needed).
- `SHOW ENGINE INNODB STATUS` -- not available (missing PROCESS privilege).
- `SHOW PROCESSLIST` -- only shows this app's own connection, not useful as a monitor.
- **`disk_free_space()`/`disk_total_space()` do NOT reflect this account's actual
  quota** -- they read the underlying shared partition (effectively unlimited), so
  `budget - disk_free_space()` always computes ~0 regardless of real usage. Use
  `shell_exec('du -sb <dir>')` instead for real usage figures (confirmed to agree with
  cPanel's own Disk Usage page). This cost two extra debug/fix/redeploy round trips
  the first time -- don't repeat that mistake.
- PHP 8.3.31, litespeed SAPI, MariaDB 10.11.18-cll-lve. The plan is allocated 2
  virtual CPU cores; `/proc/cpuinfo` exposes 12 host cores, which must only be used
  to contextualise the host-wide load average, not as this account's allocation.
- **Session bootstrap is intentionally lazy.** Public API calls without an existing
  `PHPSESSID` do not create one, preventing concurrent first-page requests from
  racing competing `Set-Cookie` responses and invalidating a CSRF token. Only
  `pw_csrf_token()`/`pw_require_csrf()` and OAuth flow setup explicitly start a new
  session. Do not change this back to unconditional `session_start()` in
  `api/helpers.php`.

## Cron jobs

Two cPanel cron jobs exist:

1. Every minute, CPU load sampling:
```
curl -s -o /dev/null "https://thepantheonwars.com/api/cron/sample-load.php?key=<CRON_SAMPLE_KEY>"
```
This samples `sys_getloadavg()` into the `cpu_load_history` table (pruned to ~25h),
backing the System Status page's CPU 24h line chart. The endpoint 403s without the
correct key (value lives only in the secrets config, not in git).

2. At 01:05 UTC daily, visitor-journey rollups:
```
curl -s -o /dev/null "https://thepantheonwars.com/api/cron/rollup-page-view-journeys.php?key=<CRON_SAMPLE_KEY>"
```
This writes completed-day transitions to `page_view_daily_transitions`; the endpoint
also supports a deliberately manual `?full=1` historical rebuild.

## Admin console conventions (`admin/index.html` -- single large file)

- Sidebar categories: **Lore Management** (Book Control, World Control, Weather
  Control, Overlord Control), **Community** (Forum Control, Members, Topic Reports), **Development
  Dispatches** (Dispatch Control, Dispatch Translations, Dispatch Composer), **System** (System Status,
  Audit Log). Home is the default landing section.
- `showSection(name)` toggles `.admin-section` visibility; `loadedSections[name]`
  guards first-load-only data fetches. Auto-refresh timers (Home: 60s, System Status:
  60s) are wired separately in `showSection` (start/stop pattern gated by
  `document.hidden` so backgrounded tabs don't poll).
- CRUD list/modal pattern: `.admin-toolbar` + `.admin-list` of `.admin-row` buttons +
  modal with `.admin-field` divs, Save/Cancel/Delete, `.admin-modal-error` /
  `.admin-modal-status`. World Control nests three levels deep (world -> layers ->
  landmarks/sublocations) using stacked `.admin-modal-over` modals; Overlord Control
  is a flat single-level version of the same pattern with a world-picker `<select>`
  (assignment lives on the Overlord record's `world_id`, not on the World record).
- Shared `IMAGE_FIELDS` registry (in the admin JS) powers every image
  upload/choose-from-library field across Book/World/Overlord Control -- one
  upload+list endpoint pair per section (`api/admin/{books,worlds,overlords}/
  upload-image.php` + `list-images.php`), each writing re-encoded JPGs into
  `uploads/{book,world,overlord}-images/<subfolder>/` (committed `.htaccess` per
  folder denying PHP execution). Register a new field by adding a key to
  `IMAGE_FIELDS` and calling `wireImageField(key)`.
- Avatar+role-ring CSS: `.profile-avatar-wrap` + `.role-member`/`.role-moderator`/
  `.role-admin` (colors: grey/green/red). Used on public member list, member-edit
  modal, and admin Members list rows (`.member-avatar-wrap` 40px variant).
- Topic Reports remains the one shared moderation queue, but Home deliberately
  reports Forum topic/comment items and News-comment items separately. Keep
  `target_type IN ('topic', 'comment')` and `target_type = 'news_comment'` separate
  in Pending Work and BH-4 advisor queries, while both rows still navigate to
  `topic-reports`.
- No external chart library anywhere in this codebase. Two hand-rolled patterns exist:
  a stacked div bar chart (`dev-metrics.html`'s language-history, percentage-based,
  no real axis scaling needed) and a hand-built inline SVG line chart (System
  Status's CPU chart -- computes its own x/y pixel scale from real data ranges, builds
  the whole `<svg>...</svg>` as a template string, sets `.innerHTML` once per refresh).
  Match whichever pattern fits if you add another chart.
- **GSAP 3.15.0 and ScrollTrigger are locally vendored** in `js/vendor/`, used by
  the Worlds atlas and, as of Known Figures, one other page -- both deliberate
  uses of the same pinned files, not separate dependencies. The files load after
  the initial page markup and are documented with their package source and
  Standard License link in `js/vendor/README.md`. Do not replace them with a CDN
  dependency. No Alpine
  dependency is installed; continue to prefer CSS transitions and native browser
  APIs for modest motion and local UI state. Any new continuous motion must preserve
  the site-wide `prefers-reduced-motion` behavior and pause while hidden/off-screen.
- Cache-busting: `css/style.css?v=N` -- bump `N` across all public HTML files plus
  the bundle reference and import query that include the changed source. Current
  versions: public v=218, community v=205, and admin v=224. Public pages use
  `css/public.css`, community pages use `css/community-bundle.css`, and the console
  uses `css/admin-bundle.css`; `css/style.css` remains the legacy full compatibility
  bundle. The ordered source and bundle map is in `css/SOURCES.md`.
- Same pattern, separate counters, each easy to miss since `.htaccess`'s no-cache
  headers only cover `.html$` -- a stale cached JS file can silently serve old code
  after a deploy even though the HTML/CSS look right (confirmed the hard way more
  than once): `js/main.js?v=N` (current: v=7), `js/members.js?v=N` (current: v=23)
  and `js/notifications.js?v=N` (current: v=10), across the public pages
  (not admin). The notification script is now loaded dynamically for
  authenticated visitors rather than referenced in every page's HTML.
  `js/books.js?v=N` is page-specific (current: v=3) and only needs a version
  bump in `books.html`. `js/news.js?v=N` is likewise page-specific (current: v=9)
  and only needs a version bump in `news.html`. `js/news-post.js?v=N` powers the
  dedicated public transmission page (current: v=3); it is only loaded by
  `news-post.html`.
- Static CSS, JavaScript, font, image, and WebM video assets have a one-year
  `public, immutable` cache policy in `.htaccess`; HTML remains no-cache so
  changed version URLs reach visitors immediately. Never replace an asset at
  the same URL without changing its filename or version query string.
- **Browser security headers:** `.htaccess` applies CSP, HSTS, `nosniff`,
  anti-framing, referrer and permissions policies site-wide. CSP permits local
  scripts and a temporary `unsafe-inline` compatibility exception for legacy
  inline scripts. The host mutates their bytes after deployment outside
  PageSpeed, so hash authorization is unreliable until they are moved to
  versioned local assets. Read `docs/security-headers.md`; new click behaviour
  must use listeners rather than HTML `on*=` attributes. The same root config permanently
  redirects HTTP to `https://thepantheonwars.com` to cover the first-visit
  period before HSTS can be stored; preserve this rule when editing rewrites.
- **No shared JS module anywhere in this static site** -- BBCode rendering
  (`formatBody()`/`escapeHtml()`) is hand-duplicated in `community.html` (canonical,
  also owns the editor toolbar) and `member.html` (Recent Posts). A plain-text
  variant, `stripBbcodePreview()`, is *also* duplicated in `profile.html`,
  `notifications.html`, and `js/notifications.js` (nav-bell dropdown) for contexts
  that echo comment text without ever rendering BBCode (it strips brackets to plain
  text, but replaces `[spoiler]...[/spoiler]` with a `"(spoiler hidden)"` placeholder
  rather than un-hiding it). Any new BBCode tag must be added to all of these in
  lockstep or it'll show as literal bracket text somewhere.
- **Header markup is still hand-duplicated across all 22 public pages**, same as
  everything above -- a DB-backed nav (admin-editable, following the Forum
  Control/World Control precedent of a table + admin CRUD + public API) was
  considered but deliberately deferred; static HTML stays the serving model for
  now. Any header change (new link, layout tweak, icon change) must currently be
  applied to all 22 files in lockstep; `main.js`'s `enhancePublicNavigation()`
  is fully generic and re-derives every `.active`/`.nav-current` class from
  `location.pathname` at runtime, so none of that needs to be hardcoded
  per-page -- confirmed empirically before this fix by diffing the header block
  across six different pages, which showed *zero* real content differences.
  **Mobile header layout:** `.nav-toggle` (hamburger) is the first child of
  `.nav-inner`, before `.logo`, and `.auth-nav-item`/`.notif-nav-item` are
  wrapped in a `.nav-utility` span that is a *sibling* of `nav.main-nav`, not a
  child of it -- this is deliberate. `nav.main-nav` collapses into a
  slide-down panel behind the hamburger on mobile (`display:none` unless
  `.open`), so anything nested inside it disappears/reappears with that
  panel. Profile and notifications must stay reachable at every width, so
  they live outside that panel entirely, always visible next to the logo.
  (This is also what fixed a real bug: auth/notif used to be the *last*
  children inside the collapsing panel, which had no `max-height`/
  `overflow-y`, so on a small screen with several dropdown sections expanded
  the "Login" link could render entirely below the visible viewport with no
  way to scroll to it. `nav.main-nav` now also has
  `max-height: calc(100vh - 64px); overflow-y: auto` as a second, independent
  safety net in case a future addition ever makes the plain link list itself
  too tall again.) On narrow screens the profile chip shows only the avatar
  initial (name label and caret hidden) to stay compact next to the bell.

## Verification checklist before every commit

- HTML tag balance via regex counts: div/section/ul/li/span/button/p/select/
  textarea/label/option/optgroup/svg/a/h1/h2/h3/blockquote/cite.
- Zero duplicate `id="..."` attributes (Counter over regex matches).
- Every inline `<script>` block extracted and run through `node --check`.
- Every `getElementById('...')`/`querySelector('#...')` call cross-checked against
  actual `id=` attributes in the HTML.
- PHP: brace `{`/`}` and paren `(`/`)` counts balanced on every touched file (no PHP
  CLI available in this sandbox, so this is the syntax-check substitute).

## Standing user instructions

- Always write detailed, multi-paragraph commit messages -- never a single-line
  commit. Explain what changed, why, and what was verified.
- Always commit and push finished, verified changes to `main` without waiting to be
  asked -- the user handles the cPanel deploy step and any SQL migrations themselves,
  so git push is the one action that should happen proactively. Still stage only the
  intended files (never a blanket `git add -A`) and use the inline-PAT push method
  above, stripping the token back out immediately afterward.
- Ask before running anything with real production side effects that goes beyond what
  was explicitly requested (e.g., live diagnostic queries, resetting passwords,
  deleting data) -- a question from the user is not authorization to act.

## Recent history (most recent first)

- **Private member messaging:** `messages.html` plus `js/messages.js` provides
  permanent, one-to-one member conversations backed by `direct_conversations`,
  `direct_messages`, and `user_blocks` (run
  `sql/migration_direct_messages.sql` after deployment). The Messages heading
  has **Find members** plus **Write message**; the latter opens a compose
  surface with an Outlook-style live **To:** member search
  (`api/direct-messages/member-search.php`). A member profile can also start a
  conversation; the dynamic signed-in profile menu links to Messages,
  so no repeated header markup was changed. The inbox uses 25-second,
  visibility-gated polling rather than a socket, marks messages and their
  collapsed bell notification read together, and has server-side CSRF, own-row
  authorization, 2,000-character validation, and a 15-message/minute rate
  limit. Blocking applies to ordinary members in either direction. A sender
  with an admin or moderator role (including an additional role) has a
  server-side override and can always deliver a staff message; that metadata is
  audited without storing the body in the audit log. Private messages are not
  end-to-end encrypted. They are only visible to staff when a participant
  reports a specific message, through the existing Topic Reports queue.

- **News article "Related Development" sidecard + category breakdown bar**
  (`news-post.html`/`js/news-post.js`, CSS in `css/content.css` since that
  page uses `public.css` and never imports `community.css`): when an
  article was published from a Dispatch Composer draft that had Dispatches
  attached as source material (`post.attached_dispatches`, from
  `pw_composer_attached_dispatches()`), a left sidecard now shows them as a
  2-column grid of cards -- category icon, tag label, title, short SHA +
  relative time -- each linking straight to `dev-dispatches.html?dispatch=
  <id>`'s existing deep-link (scrolls to and expands that entry). A
  category filter pill bar (`All` plus one pill per category actually
  present) toggles cards client-side via `[hidden]`, no extra request.
  Cards were then given several rounds of polish: hover elevation
  (`translateY` + accent-tinted shadow) with a top accent bar replacing a
  flat left border, an icon badge chip, an accent-tinted gradient card
  background, a staggered scroll-in reveal (`IntersectionObserver`, same
  pattern as `js/books.js`'s book-row reveal), an icon scale/rotate on
  hover, a pulsing "New" chip on dispatches committed in the last 48h, a
  count pill next to the "Dispatches in this update" heading, and a
  diagonal sheen sweep on hover reusing the `book-cover-sweep` keyframe
  verbatim. All motion is skipped under `prefers-reduced-motion`. A real
  bug was found and fixed along the way: `.post-read-cue` ("Read
  transmission &rarr;" on News feed cards, `js/news.js`) was
  `display: inline-block`; the `.post-tags` block between it and the
  `.stamp` byline is `display: flex` and normally forces a line break, but
  posts with **no tags** skip that div entirely, so the two inline-level
  elements collapsed onto the same line and ran together. Fixed by making
  `.post-read-cue` a block element so it always starts its own line
  regardless of what's between it and the byline. Finally, a single
  stacked category-breakdown bar (`js/news-post.js`'s `buildCategoryBar()`)
  sits at the bottom of the sidecard -- the same flat div-based bar
  pattern as `dev-metrics.html`'s language-history chart (no chart library
  anywhere in this codebase), reworked for the fixed 9-category catalog:
  gradient-filled segments, a scroll-in width-fill animation (segments
  grow from 0 once the bar enters the viewport), an inline percentage on
  any segment holding &ge;15% share, thin separators between segments, a
  hover/focus tooltip per segment (category name, percentage, and a
  one-line explanation from a new `TAG_DESCRIPTIONS` map), and a compact
  color-key legend row underneath so the palette reads at a glance without
  hovering every segment. No SQL migration was needed for any of this --
  purely CSS/JS on top of the already-shipped Composer/attached-dispatches
  data.

- **Dispatch category auto-scoring, confidence, and review queue** (full
  end-to-end flowchart of this system plus Translation and Composer
  together: `docs/dispatch-pipeline.md`):
  `pw_dispatch_categorize()` (`api/dispatch-helpers.php`) replaced the old
  if/elif keyword cascade that assigned each Dispatch's category
  (feature/improvement/fix/performance/ui_ux/lore/infrastructure/refactor/
  experimental). The cascade had a real, systematic bug: "infrastructure"
  always won over "performance"/"lore"/etc. purely because that check ran
  first, and bare-substring keyword matching couldn't tell "character" (a
  story figure) from "character" (a text-character limit) -- confirmed by
  running the real last-50-commit history through both the old and new
  logic locally. The replacement scores all 9 categories independently from
  four signals -- Conventional Commits prefix (65 pts, now also recognising
  `perf:`), word-boundary subject keyword hits (50), word-boundary body
  keyword hits (20), and the diff-context file-scope labels already
  computed for the Translator (45) -- and the highest score wins (ties keep
  the old cascade's priority order, since PHP array sorts are stable).
  A margin-aware confidence score (0-100) comes out of the same tally: zero
  signal at all (pure `feature` default) is reported as a flat 20% rather
  than implying evidence that doesn't exist; a winning margin under 15
  points over the runner-up (genuinely contested) gets pulled down to
  `max(15, score - 25)`; anything else is just the winning score capped at
  100. `pw_dispatch_category_needs_review()` is the single source of truth
  for the 65% "worth a human look" line everywhere it's checked.
  `dispatch_entries` gained `category_confidence` and `category_source`
  (`auto`/`manual`); any explicit category save in Dispatch Control now
  resets confidence to 100, flips source to `manual`, and is logged to a
  new `dispatch_category_overrides` table (previous tag/confidence/source,
  new tag, who) as a permanent evidence trail for future keyword/weight
  tuning. "Needs review" (`auto` + confidence < 65%, the same high-confidence
  floor already used for translation confidence) surfaces in three places:
  a new Home "Dispatches needing category review" queue row, a "Needs
  review only" toggle in Dispatch Control, and a low-confidence badge with
  an explanatory hint in the edit modal. The webhook and manual re-sync were
  both reordered to compute diff-context *before* categorizing (free for the
  webhook; for re-sync, the existing 25-lookup GitHub API budget is now only
  spent on commits that aren't already in the table, via an upfront SHA
  lookup, so no new API cost). Also fixed in passing: `sql/schema.sql` had
  documented `dispatch_entries.tag` as a 3-value ENUM
  (`feature`/`fix`/`update`) for a long time, while the real app-wide valid
  set has been the 9 values above for as long as "category_edited" has been
  a working, audited feature -- the same documentation-drift class already
  fixed once for `books`/`comment_reactions`. Run
  `sql/migration_dispatch_category_confidence.sql` once in phpMyAdmin
  (defensive/idempotent if the live ENUM already had all 9 values).
  Dispatch Composer's own category filter had independently inherited the
  same stale 3-value assumption from when it was built a few days earlier;
  both its dropdown and `candidates.php`'s validation now use the real
  `pw_dispatch_valid_tags()` list.

- **Audit Log icon/label gaps** (unrelated to Composer, found while auditing
  it): `known_figure_*`, `soundtrack_*`, `session_created`/`session_revoked`/
  `sessions_revoked_all`/`sessions_revoked_others`, and
  `member_sessions_revoked`/`member_two_factor_reset` had no entry in
  `ACTIVITY_LABELS` and no branch in `activityCategory()`, so they rendered
  as a raw uppercased action string falling into the generic "System"/
  backup-log icon and pill. Added labels, dedicated icons, and category
  branches (Known Figures/Soundtracks under the existing green Lore
  Management pill, Sessions under the existing teal Members pill). Also
  added 12 missing `<option>` entries to the Audit Log's own action filter
  dropdown (new Mail and Privacy Requests optgroups, plus entries folded
  into Login Activity/World Control/System) for actions that were already
  logging and rendering correctly but couldn't be filtered to individually.

- **Dispatch Composer** (Development Dispatches -> Dispatch Composer, new):
  an admin writing tool where approved Development Dispatches are searchable
  reference/traceability material only -- never auto-generated article
  text -- and the administrator writes the actual blog-style article by
  hand. `dispatch_composer_posts`/`dispatch_composer_items`
  (`sql/migration_dispatch_composer.sql`) plus five permissions
  (`dispatch_composer.view/create/edit/publish/archive`, publish kept
  deliberately separate from edit). The editor is a two-panel layout: left
  is a searchable/filterable panel of approved dispatches (approved = has a
  `dispatch_translations` row, the same definition Translation Review
  already uses) with attach/detach, drag-to-reorder, private per-dispatch
  notes never published, and an "Insert summary" action that drops the
  approved translation in as plain editable text at the cursor -- not a
  live link, so a later dispatch edit can never silently change a drafted
  or published article; right is a manual title/slug-preview/excerpt/
  featured-image/rich-text editor, an independent parallel instance of News
  Management's own contenteditable+toolbar pattern (own ids, own image
  library modal -- hand-duplicated rather than shared, matching this
  codebase's existing "no shared JS module" convention, to avoid risking a
  regression in the already-shipped News editor). Publication
  (`api/admin/dispatch-composer/publish.php`) reuses News Management's
  exact insertion path -- `pw_news_create_post()` was extracted out of
  `api/admin/news/create.php` (pure refactor, identical behaviour) into
  `news-helpers.php`, and both callers now share it rather than duplicating
  the INSERT/tag-sync logic. Duplicate-publish protection is layered: the
  Composer row is `SELECT ... FOR UPDATE` locked for the whole transaction,
  the already-published check happens inside that lock, `news_post_id`
  carries a UNIQUE constraint as a last-resort DB guarantee, and the
  publish button disables itself synchronously on click. Preview
  (`api/admin/dispatch-composer/preview.php` + a `?composer_preview=<id>`
  branch in `js/news-post.js`) deliberately reuses the real public article
  renderer instead of a second template -- the endpoint returns the exact
  JSON shape `api/news/get.php` does, so preview and the eventual published
  page can never visually drift apart; it never creates a real News post
  and is permission-gated. The featured-image field reuses News's own
  upload/list-image endpoints and the shared `IMAGE_FIELDS` registry
  outright. Not in this MVP (see the original plan's own scope notes):
  GitHub milestones, forum/newsletter/social publication, scheduled
  publication, AI rewriting, and a "Duplicate as new draft" action --
  archived/published Composer records are read-only with no path back.

- **Profile page polish batch** (`profile.html`): eight additions.
  **"My Posts" now links to real threads.** `api/get-profile.php` merges
  topics you started with replies you posted into one `posts` feed (each
  carrying `topic_id` + the thread's title), sorted server-side by date;
  previously it only ever queried `comments`, so topics you started were
  invisible on your own profile, and every row was unclickable plain text
  with no way back to the thread. Rows now link straight to
  `community.html?topic=<id>`, reusing that page's existing deep-link
  support (originally built for Topic Reports' "View" action). **Real
  pagination**, mirroring Quiz History's own client-side pattern exactly
  (`.history-pagination`/`.history-page-num` reused wholesale) instead of a
  silent `LIMIT 20` hard cutoff. **Profile-head meta row**: role shown as a
  text badge (previously only an avatar ring color, i.e. less transparent
  about your own status than the public Members list), "Member since
  \<date\>" (`users.created_at` was already returned by the API and simply
  never rendered), and a "View public profile" link to
  `member.html?id=<own id>` (there was previously no link at all from your
  own settings page to your own public card). **Every tab is now
  deep-linkable** (`profile.html?tab=settings|two-factor|notifications|
  sessions|posts`) -- `activateProfilePanel()` syncs the URL via
  `history.replaceState` on every switch; previously only `notifications`
  and `two-factor` were recognized on load, and switching tabs never
  touched the URL at all, so a refresh always dumped you back to Profile
  Settings. **Drag-and-drop avatar upload** onto the avatar circle itself,
  sharing the exact same `uploadAvatarFile()` path as the click-to-browse
  input (refactored out so both paths validate and upload identically) --
  added an explicit client-side image-type check specifically because
  `accept=""` only filters the OS file-picker dialog, never a drop.
  **Icons on all five profile tabs**, reusing exact existing icon paths
  where one already established the right meaning elsewhere on the site
  (gear for Settings, shield-check for Two-Factor, bell for Notifications,
  chat-bubble for My Posts) rather than inventing new ones; only the
  Sessions tab's monitor icon is new.

- **Mailing-list subscription is now a real account attribute.** The
  homepage/site-wide "Join the Pantheon" newsletter form (`.newsletter-form`,
  present on 14 public pages) used to be pure theater -- `js/main.js` showed a
  fake confirmation and sent the typed email nowhere (its own comment said
  so explicitly). It now sends visitors to Create Account instead
  (`window.openAuthModal('register')`, prefilling `#reg-email` with whatever
  they typed), since actual subscription is `users.newsletter_subscribed`
  (`TINYINT(1) NOT NULL DEFAULT 1` -- run `sql/migration_newsletter_
  subscription.sql`). Every registration path (password `api/register.php`,
  Google `api/oauth/callback.php`, admin-created `api/admin/members/
  create.php`) omits this column from its `INSERT`, so new accounts land on
  the default with zero code changes needed there. Members can opt out from
  Profile Settings, in a checkbox directly below the Change Password form
  (`#newsletter-subscribe-checkbox`, auto-saves on change via
  `api/newsletter-subscription/update.php` -- same "own account, no extra
  permission" trust level as `api/presence/update.php`). Admin Members list
  gained a **Subscription** column (green "Subscribed" / grey "Not
  subscribed" pill, reusing the exact colors already used for the
  Verification/2FA pills) between 2FA and Role. `pw_current_user()` in
  `api/helpers.php` runs on every authenticated request site-wide, so its
  query for this new column is wrapped in try/catch with a safe fallback
  (`newsletter_subscribed = 1`) exactly like `api/admin/members/list.php`'s
  existing email-verification/2FA columns -- without that, a request
  arriving after deploy but before the manual SQL migration runs would have
  fataled on every single login/session check site-wide.

- **Composer improvements batch** (forum + News comments): five additions.
  **Body limit raised 2000 -> 3500** everywhere a topic/comment/news-comment
  body is accepted or edited (client `maxlength` in `community.html` x5 and
  `news-post.html`, server-side length checks + error text in
  `api/topics/{create,edit}.php`, `api/comments/{post,edit}.php`,
  `api/news/comments/post.php`) -- no schema change needed, `TEXT` columns
  already hold far more than that. **Live character counter**, **Ctrl/Cmd+
  Enter to submit**, and a **live BBCode preview toggle** were all added
  inside `community.html`'s shared `attachEditorToolbar(textarea)` function
  rather than per call site, so all five places that already call it (new-
  topic composer, main reply composer, and the three inline topic/comment/
  reply-form edit instances) picked up all three for free. The counter reads
  `textarea.maxLength` generically (so it also just works if that number
  ever changes again); Ctrl/Cmd+Enter only fires when `textarea.form` is set
  (true for the two real `<form>` composers, harmlessly absent for the
  div-wrapped inline-edit/reply-form instances); Preview reuses the exact
  same `formatBody()` every rendered post goes through, so it can never
  drift from what posting actually produces. **News comments got a trimmed
  copy of the same toolbar** (`js/news-post.js`, new `attachEditorToolbar()`/
  `formatBody()`/`escapeHtml()` -- hand-duplicated from `community.html`,
  same "no shared JS module" convention as the rest of this codebase; no
  @mention autocomplete and no Quote button, since news comments are flat
  with no reply-to-a-specific-comment relationship to attach either to).
  Comment rendering switched from `textContent` to `innerHTML(formatBody())`,
  matching `community.html`'s own escape-then-whitelist approach exactly.
  The shared CSS classes (`.editor-toolbar`, `.comment-quote`,
  `.comment-spoiler`, `.editor-preview-box`, etc.) are duplicated into
  `content.css` rather than reused from `community.css`, since
  `news-post.html` is served by the `public.css` bundle and per `css/
  SOURCES.md` that bundle must never import the community-only source.
  **Draft auto-save** was added for the News comment composer too
  (`localStorage`, keyed by article slug), mirroring the forum composers'
  existing drafts.

- **Forum improvements batch** (Nexus Veil / `community.html`): seven
  additions, all `migration_forum_enhancements.sql`-backed. **Per-board
  accent color**: `forum_boards.accent_color` (admin color input in Forum
  Control) drives a `--board-accent` CSS custom property set inline per row,
  used by the board index icon/left-border and the topic-list heading's
  left-border -- default `#a279ec` matches the previous hardcoded
  `--purple-bright` so existing boards look unchanged until customized.
  **Edit attribution**: `topics.edited_by` / `comments.edited_by` record the
  moderator who made the edit (every edit already goes through the
  moderation-only `community.edit_any` endpoints -- there is no self-edit
  path for regular authors, so this is never ambiguous); the "(edited)"
  marker now reads "(edited by `<name>`)" via a shared `buildEditedMarker()`
  helper, replacing the old anonymous marker. **Server-synced unread
  tracking**: new `forum_board_seen` / `forum_topic_seen` tables (one row
  per user per board/topic) mirror the existing client localStorage shape
  1:1; `api/forum/mark-seen.php` upserts on board/topic visit, and
  `boards-summary.php` / `topics/{list,active,mine,bookmarks}.php` bundle a
  `seen_at` field for logged-in requests. `isBoardUnread()`/`isTopicUnread()`
  in `community.html` prefer that server value when logged in and fall back
  to the original localStorage-only behavior for guests (no user id to key
  a server row by) -- this is a graceful upgrade, not a replacement.
  **Forum-wide search**: `FULLTEXT` indexes on `topics(title, body)` and
  `comments(body)`; `api/topics/search.php` merges topic-direct matches and
  reply matches into one board-visibility-filtered, ranked result list (a
  direct topic match is score-boosted above an equivalent reply match, and
  only the single best-scoring reply per topic is kept), rendered in a new
  search-results view reached via a search box added to the forum sub-nav.
  **Draft auto-save**: both composers (new-topic and reply) save to
  `localStorage` (`pw_forum_drafts`, one draft per board / per topic) on
  every keystroke, restore automatically the next time that composer opens,
  and clear only once the post actually succeeds -- purely client-side, no
  schema. **Trending badge**: `api/topics/active.php` computes
  `recent_reply_count` (replies in the last 6 hours, a plain aggregate
  expression alongside the existing `COUNT(c.id)`) and flags
  `is_trending` at a fixed `>= 3` threshold (not admin-configurable);
  `buildTopicRow()` renders a flame badge only when that field is present,
  so it naturally never appears on the other three list endpoints that
  don't compute it.

- **Soundtrack Control** (Lore Management > Soundtrack Control, new): the
  single hand-authored `.soundtrack-panel` block on `soundtracks.html` is now
  a real, admin-managed CRUD -- same flat list/modal/reorder pattern as
  Known Figures Control / Overlord Control. An admin pastes a normal
  `open.spotify.com` album/playlist/track share link; `create.php`/
  `update.php` parse it once via `pw_parse_spotify_url()`
  (`api/admin/soundtracks/soundtracks-helpers.php`) into
  `spotify_embed_type`/`spotify_embed_id`, stored alongside the original
  `spotify_url` (kept verbatim for the "Listen on Spotify" badge link, so any
  `?si=` tracking param survives). Both the admin modal's live preview and
  the public page build the iframe `src` from a fixed
  `https://open.spotify.com/embed/<type>/<id>` template rather than
  re-parsing the URL, so the regex only has to be correct in one server-side
  place; the admin JS duplicates a client-side copy only for the instant
  preview, same "duplicated small parser, single source of truth for the
  stored value" tradeoff as this codebase's other hand-duplicated helpers.
  `api/soundtracks.php` is the public unauthenticated read
  (`is_published = 1`, ordered by `sort_order`); `js/soundtracks.js` renders
  one repeatable `.soundtrack-panel.ornate` block per record into
  `soundtracks.html`'s `#soundtrack-list`, reusing the existing
  `.soundtrack-grid`/`.spotify-badge`/`.spotify-embed` CSS from
  `components.css` untouched. Run `sql/migration_soundtracks.sql` once in
  phpMyAdmin after deploy; it seeds the one soundtrack already live
  (transcribed verbatim) so the cutover is visually identical.

- **Footer redesign** (all public pages): the footer had drifted into a flat,
  unstyled 12-link "Explore" column plus a thin "Stay Connected" column holding
  only a duplicate Soundtracks link and a dead `#newsletter` anchor with no real
  form behind it. Redesigned to three explicit, front-end-only directions (a
  fourth -- a real newsletter capture form -- was deliberately deferred since it
  needs backend work): a proper brand-column wordmark reusing the header's
  purple shard glyph (`.logo-shard`) via new `.footer-brand-mark`, with
  Home/About the Author/Privacy folded into a compact inline utility row; the
  old single "Explore" list split into three columns (**The Universe**,
  **Community**, **News**) that mirror the header nav's own dropdown grouping,
  each independently mobile-collapsible through the existing generic
  `.footer-toggle`/`.footer-collapsible-list` handler in `js/main.js` (already
  written to iterate every `.footer-toggle` element, so going from one
  collapsible group to three needed zero JS changes); and a new 3px top-edge
  gradient strip (`footer.site-footer::before`) sequencing all twelve worlds'
  established atlas signal colors (`js/worlds.js` `ATLAS_TONES`) in atlas order
  -- fixed brand colors, not DB-driven, so pure CSS with no JS/API dependency,
  and it renders even on the three minimal legal-page footers since it lives on
  `.site-footer` itself rather than inside `.footer-grid`. Applying the new
  grouped structure surfaced and fixed real drift across the 19 pages carrying
  the full footer (found by diffing every page's footer block against every
  other page's before touching anything): a duplicate Soundtracks link, the
  homepage-only dead newsletter anchor, a missing Development Dispatches link
  on most pages, and a stray Development Metrics link that had leaked into
  `soundtracks.html`'s footer despite being unrelated to that page.
  `about.html`'s page-specific "Shop on Amazon" utility link was preserved
  after the global replace. The three deliberately-minimal legal-page footers
  (`password-reset.html`, `privacy-request.html`, `privacy.html`) and the
  already-documented minimal `news-post.html` footer were left untouched,
  matching existing precedent -- those four files only received the routine
  cache-bust version bump. One real bug was caught during verification before
  shipping: `.footer-brand-mark`'s gold color was silently overridden by the
  pre-existing, higher-specificity `.footer-grid a` rule (class+type beats
  class alone); fixed by scoping the new rule as `.footer-grid
  .footer-brand-mark` and bumping `components.css` a second time.

- **Known Figures 3D portrait effects:** each figure's `.figure-portrait-frame`
  now gets a pointer-tilt "holo card" treatment on fine-pointer/hover devices
  only (`(hover: hover) and (pointer: fine)`) -- GSAP `quickTo`-driven
  `rotationX`/`rotationY` tracking the cursor (same pointer-tracking pattern
  already proven in `js/world-atlas-effects.js` for the Worlds atlas, at a
  stronger intensity appropriate for these hero-sized portraits), the sharp
  foreground image shifting a few pixels opposite the tilt for extra depth,
  and a new radial `.figure-portrait-sheen` overlay sweeping across on hover.
  Touch devices never attach the pointer listeners. Separately, each portrait
  frame now turns in from an alternating +/-26deg Y-axis tilt (scale 0.92 -> 1)
  as it scrolls into view, on its own dedicated ScrollTrigger object (same
  established pattern as the other per-tween ScrollTrigger objects on this
  page) reusing the existing `onRefresh` fix for sections already inside their
  trigger zone at setup time. Both motion paths are skipped entirely under
  `prefers-reduced-motion`, matching every other animation already on this
  page. `perspective` was added to `.figure-scene-inner` so the rotation reads
  as real 3D depth rather than a flat skew.

- **Known Figures** (`known-figures.html`, new page under The Universe nav):
  a static, cinematic vertical chronicle -- not DB/admin-managed, unlike
  Books/Worlds/Overlords, since this is fixed one-off lore content rather
  than a structured catalog (same reasoning as `chapter-one.html`/`about.html`
  staying static). Four full-bleed `.figure-scene` sections (Kael Veyr, Brann
  Ilex, VB, Teo Carnicus), each GSAP/ScrollTrigger-revealed once on scroll-in
  via `js/known-figures.js`, plus one small looping "signature detail"
  animation per figure tied to a physical prop from their character
  description -- Kael's shard gets a steady heartbeat pulse, Brann's cyborg
  eye a broken glitch-flicker, VB's tool an uneven restless twirl, Teo's
  knife a quiet occasional glint -- all paused via `IntersectionObserver`
  when their section leaves the viewport, and entirely skipped (page loads
  fully visible, no loops) under `prefers-reduced-motion`. GSAP/ScrollTrigger
  were previously vendored "for the Worlds atlas only"; this page is a
  deliberate, documented second use of those same local vendor files, not a
  new dependency. Kael and Brann reuse Neoh's existing atlas signal color
  (`rgb(154,96,238)`, from `ATLAS_TONES` in `js/worlds.js`) since both are
  Neoh-tied in-story; Teo reuses High Hammer's existing weather-card copper
  accent (`rgb(230,150,80)`) since he's High Hammer-tied; VB intentionally
  gets a neutral teal/red palette derived only from her own portrait, not
  from any established world's color identity, because her true world
  affiliation is a deliberate spoiler not meant to be hinted at visually.
  Portrait images: `images/char-kael.jpg` (already existing), plus
  `images/char-brann.jpg`, `images/char-vb.jpg`, and `images/char-teo.jpg`.
  The nav link and footer "Explore" entry were added across all pages
  that carry the full mega-menu; the three auth/legal utility pages
  (`password-reset.html`, `privacy-request.html`, `privacy.html`) were
  deliberately left alone since they never had The Universe dropdown to
  begin with, and `news-post.html`'s already-inconsistent minimal footer
  (missing several other Explore links too, pre-existing) was left as-is
  rather than scope-creeping into an unrelated fix.
- **Known Figures scroll-scrubbed parallax:** each `.figure-portrait-frame img`
  and `.figure-scene-bg` layer has its own `scrub`-tied GSAP tween (separate
  from the once-only reveal animation on different elements, so the two never
  conflict), giving each figure's sharp foreground portrait a strong vertical
  pan and its blurred background a subtler, differently-ranged one -- two
  distinct depth planes rather than the whole photo sliding as one flat image.
  Both layers are deliberately oversized in CSS (`transform: scale(1.22)` on
  the background, `height: 132%` with a `-16%` top offset on the portrait
  `<img>`) so the pan range never exposes an edge. Skipped entirely under
  `prefers-reduced-motion`, same as the rest of this page's motion. Also fixed
  a real bug found via live verification after the first deploy: a section
  already inside its ScrollTrigger zone at setup/refresh time (true for Kael,
  which sits right below the hero) never experiences the inactive->active
  transition its default "onEnter" toggle action needs, so it stayed
  permanently hidden. `onRefresh: function (self) { if (self.progress > 0)
  self.animation.progress(1); }` on the reveal ScrollTrigger closes that gap
  without affecting the normal scroll-triggered reveal for sections that
  start below the fold.

- Added permissioned Mail Log observability: best-effort outbound attempt
  logging (including skipped/failed delivery states) and a signed inbound
  webhook receiver that retains no body content. Production requires
  `sql/migration_mail_logs.sql`; inbound activity additionally needs a provider
  webhook or cPanel mail pipe configured with `MAIL_INBOUND_WEBHOOK_SECRET`.

- Added the transactional Mail foundation: permissioned Mail Settings + Mail
  Templates pages, native shared-host mail transport behind an explicit off-by-
  default switch, default HTML/plain-text templates, and the welcome/ban hooks.
  Production requires `sql/migration_mail_system.sql` to be run manually before
  the template editor can load.

- **News Management**: the static `news.html` articles are now served from the
  `news_posts` table via `api/news/list.php`. Admin Console > Content > News Management provides
  create/edit/delete operations with a server-enforced author choice: `bh4` has no
  user id; `member` always resolves to the currently authenticated editor, never a
  client-selected account. Permissions are `news.view`, `news.edit`, and
  `news.delete`; admins remain covered by the existing superuser bypass while other
  roles can be granted them through Roles & Permissions. Run
  `sql/migration_news_management.sql` once in phpMyAdmin after deployment: it adds
  the table/permission catalogue and imports the two former static articles.
  News posts now also support up to ten reusable tags through `news_tags` and
  `news_post_tags`; the editor uses its persisted tag catalogue for autocomplete
  and the public News page filters articles client-side by tag. The current full
  `migration_news_management.sql` includes those tables. If the prior News
  migration was already run, execute `sql/migration_news_tags.sql` once instead.
  Each feed card now previews only its first two paragraphs and links to
  `news-post.html?slug=...`, where the full article, Reddit share action, and
  flat member discussion live. The editor now stores a small server-sanitised HTML
  subset (paragraphs, headings, emphasis, lists, quotes, safe links and News-library
  images) while legacy plain-text records remain readable. Image uploads are decoded,
  re-encoded as JPGs in `uploads/news-images/`, and only those random server-generated
  URLs are accepted in article markup. No SQL migration is needed for this editor.
  Run `sql/migration_news_comments.sql` once to
  add `news_comments` and the per-post `comments_enabled` toggle (enabled by
  default); the toggle is controlled in the News Management modal.
  News replies can be reported from that detail page; those reports share the
  existing Topic Reports moderation queue and are marked with a **News** source
  pill (Forum reports retain the **Forum** pill). Run
  `sql/migration_news_comment_reports.sql` once after the comments migration to
  extend the shared report target enum.

- **Member presence:** run `migration_user_presence_status.sql` after deploy.
  `users.presence_status` stores only `online`, `away`, or `inactive`; **Offline
  is never selectable or stored** and is derived from the five-minute active
  session window. `api/presence/update.php` is CSRF-protected and updates the
  current signed-in user's selected state. Logout revokes the current registry
  session then clears `users.last_active_at` only if no other recently active
  session remains. The public nav dropdown uses role-coloured avatar rings and
  offers the three-state picker. Forum topic/reply, member-list, and public
  profile avatars render a presence dot with hover/focus text; API responses use
  `pw_public_presence_status()` so stale sessions resolve to Offline.

- **Dispatch Translation workflow documentation and queue clarity:**
  `docs/dispatch-spacy.md` now contains the complete Mermaid-ready translation
  flow: webhook/re-sync/manual input, deterministic PHP planning, optional local
  spaCy analysis, RapidFuzz reviewed-concept matching, confidence scoring,
  editor review, auto-publication, and public rendering. RapidFuzz runs only
  locally, needs a 92+ unambiguous reviewed alias match (four-point lead),
  returns only a concept id/score, and always sets `requires_editor_review`.
  In Admin → Dispatch Translations, the aligned columns are **Commit title**,
  **Status**, and **Confidence score**; the keyboard-accessible `?` explains the
  exact evidence weights and review gate. On narrow screens this header hides
  and rows return to the compact wrapped layout.
  **Flowchart source of truth:** input is GitHub webhook, Admin Re-Sync, or
  Generate/Regenerate Draft; duplicate SHAs are ignored; PHP creates safe
  aggregate diff context; it then loads at most 20 recent approved translations
  and plans public wording deterministically. If the local worker responds,
  spaCy supplies bounded linguistic/semantic hints and RapidFuzz compares the
  commit with reviewed aliases plus at most eight recent translations. A fuzzy
  concept needs score >=92 and a >=4-point lead over the runner-up; PHP
  allow-list-validates the returned ID. The final path is PHP draft -> optional
  separate safe file-scope paragraph -> explainable confidence -> private review
  draft or auto-publication -> audit entry -> public end-user Dispatch. The
  exact evidence weights are recognized subject 25, dictionary 10, commit
  intent 30, body context 10, path scope 20, semantic support 5. High requires
  >=65 plus independent evidence (two deterministic formatter rules may set the
  65 floor for older records); any RapidFuzz concept match always requires
  editorial review and never auto-publishes.

- **Home Security Snapshot:** the Admin Home card now shows only failed logins,
  locked accounts, and currently banned accounts. It is deliberately not a
  duplicate spaCy health surface: the Home security endpoint no longer loads
  the worker, while System Status → **Security and Scripts** remains the single
  visible spaCy Connected/Disconnected check. BH-4 still receives the shared
  System Status signal for a disconnected-worker alert.

- **High-confidence Dispatch auto-publication:** when the deterministic
  formatter finds two or more independent rules, newly received webhook
  Dispatches and manual Generate/Regenerate Draft actions publish that text
  directly to `dispatch_translations`. Medium and low confidence always remain
  in the editor queue. Publication uses `INSERT IGNORE` so it cannot overwrite
  a concurrent editor approval, and a successful automatic publication removes
  any local draft. The admin modal stays open and becomes an editable published
  translation. `translation_auto_published` and
  `translation_draft_waiting_review` are written once by the central generator
  with `BH-4` as the system actor, so webhook and resync events appear in the
  Audit Log too. Both have dedicated document-status icons; unknown future
  actions fall back to their category icon so activity cards never render an
  empty icon slot. Existing queued drafts are deliberately not bulk-published;
  regenerate one to apply this rule.

- **Dispatch Draft Translator (v25 + optional local language enrichment):** the deterministic formatter recognizes
  commit domains (security, database, performance, community, content,
  interface, and operations) and uses domain-specific BH-4 templates rather
  than one generic sentence shape. It also uses optional safe diff metadata:
  only changed-file count plus allow-listed file-type and product-area labels
  are stored—never paths, code, or diffs. Run
  `migration_dispatch_diff_context.sql` once in phpMyAdmin after deploy. The
  webhook records this aggregate context directly; a manual re-sync fetches
  it only for newly inserted commits and caps those supplemental lookups at
  25. Draft creation checks the 20 latest
  published translations and deterministically chooses another phrasing when
  a candidate sentence is already present. When a vector-enabled spaCy model is
  configured, it also receives only the closest vector-similarity score across
  at most eight recent translations, and uses that score to begin with a
  different stable wording variant for near-duplicate updates. Raw prior translations never
  leave the PHP/Python process boundary. The draft-format hash is
  `dispatch-draft-v25`, so regeneration refreshes unapproved local drafts
  without overwriting published text. If the optional migration is absent,
  the translator safely falls back to subject/body/tag-only behavior.
  Before rendering prose, PHP builds a reader-safe plan from recognized commit
  intent, domain, allow-listed changed-file scope, and optional semantic support.
  Domain profiles give security, database, performance, community, content,
  interface, and operations records distinct BH-4 vocabulary. Confidence is
  an explainable evidence score (recognized subject, intent, body context,
  path scope, semantic support), with semantic support capped at 5%; it cannot
  make an unsupported draft high confidence or bypass the multi-signal
  auto-publication gate. Vector domains may resolve only an otherwise general
  record—they must never override an explicit local cue such as a named world,
  map, member feature, or security term. Named worlds, maps, districts, books,
  and worldbuilding areas are decisive content signals before broad technical
  terms are considered. World-release records headed by `Unlock <World>` use a
  concrete plan: named world, full district map when present, stated clickable
  district count, and stated landmarks. This avoids generic content prose and
  never leaves `unlock` in the reader-facing object phrase. This is now an
  engine-wide invariant: when a reader-safe replacement turns an action-led
  source title into a noun phrase, the formatter retains the original action
  but uses the safer phrase as its object. `tools/test-dispatch-translator.php`
  is a database-free PHP CLI regression check for this behavior. spaCy and
  RapidFuzz are optional, entirely local enhancements in `tools/dispatch-nlp.py`.
  RapidFuzz checks only the current commit against the reviewed aliases in
  `tools/dispatch-fuzzy-concepts.json` and returns a concept id/score to PHP.
  PHP validates that id and owns all reader-facing wording. A strong fuzzy
  match can rescue a private draft from a minor wording variation or typo, but
  it always sets `requires_editor_review` and can never auto-publish by itself.
  extracts verbs, noun phrases, named terms, and (with `en_core_web_md`)
  conservative vector-based domain hints for vague commits, but never
  writes prose, calls an external service, or changes confidence/auto-publish
  thresholds. PHP uses the existing deterministic templates as the source of
  truth and falls back immediately if the configured venv is unavailable.
  See `docs/dispatch-spacy.md`; run `migration_dispatch_spacy.sql` after deploy
  to store the `rule_based_spacy` source marker.
  The System Status Security and Scripts card performs a real model-load check
  and shows **spaCy: Connected/Disconnected**. A disconnected worker is a
  BH-4 critical alert; drafts themselves still fall back safely to PHP rules.
  The bridge preserves the host process environment for `proc_open` and has a
  6-second bounded model-start budget; do not pass a replacement environment
  array on this LiteSpeed/cPanel host.
  The reader-safe terminology dictionary comes before the generic templates.
  It contains reviewed project vocabulary for recurring account, navigation,
  analytics, privacy, security, backup, performance, styling, and translation
  changes. A matching dictionary entry adds 10 points of explicit, explainable
  confidence evidence, on top of the ordinary subject and action signals; it
  raises the ceiling for well-understood records without changing the two-signal
  high-confidence gate. Add narrow, evidence-backed entries there when a real recurring
  commit pattern produces jargon; do not add broad substitutions that could
  silently change unrelated records. The regression script includes a
  dictionary case as well as action-intent, scoped legacy-title, and
  world-release coverage. For older `Area: change` subjects, a specific
  dictionary pattern is also matched against the original scoped title after
  the formatter has removed the area prefix; the first specific match wins.
  When safe diff context is available, it is presented as a separate final
  paragraph: `Total files edited: N in <allow-listed scope>.` It must never
  expose raw file paths or appear in the middle of the reader-facing summary.

- **Public Development Dispatches:** expanded entries now present the approved
  end-user translation first. If none is published, they show the notice
  “A simpler explanation is not available right now. BH-4 has retained the
  original development record below.” plus a link to the adjacent BH-4
  Technical Analysis transcript. The transcript keeps the BH-4 avatar visible,
  exposes the original commit body, and has the only GitHub source link (do not
  add a second footer link). Its label is `Developer Record #<id>`.
  `dispatch_entries.id` is an internal `AUTO_INCREMENT` value, **not** a
  sequential Dispatch count: duplicate webhook/resync `INSERT IGNORE` attempts
  can advance it even when the unique commit SHA is already present. Use the
  short Git SHA for a developer-facing commit identifier; do not describe a
  Developer Record number as “the Nth Dispatch.” The current page cache link is
  `community-bundle.css?v=189`, which imports `community.css?v=178`.

- **Admin runtime cache:** `admin_runtime_cache` is an optional, database-backed
  shared cache for expensive but non-user-specific Admin Console diagnostics.
  `pw_build_system_signals()` caches GitHub, spaCy, storage, and related System
  Status probes for 60 seconds; `?fresh=1` on `home-summary.php` bypasses that
  cache for a manual Home refresh. Dispatch translation confidence statistics
  are cached for five minutes in the same table. Run
  `sql/migration_admin_runtime_cache.sql` in phpMyAdmin after deployment. Cache
  reads and writes deliberately fail open while the migration is pending, so
  permissions and live functionality are never blocked by the optimization.

- **Notification bell polish:** `js/notifications.js` is dynamically appended only
  once an authenticated session is known (through `js/members.js`). It now manages
  `aria-expanded`/dialog state, outside-click and Escape closing (Escape returns
  focus to the bell), a focus-visible ring, loading shimmer, icon-led empty state,
  new-row highlight, and an unread-count pulse only when the count rises. The mobile
  dropdown is a fixed-width panel below the header. Keep API calls and 60-second,
  visibility-gated polling in this file; do not add Alpine for this isolated UI.
  All of these motion effects must remain disabled under `prefers-reduced-motion`.

- **Book-card motion:** `books.html` loads page-specific `js/books.js` (currently
  v=3), which waits for its live API rows or static fallback before applying a
  dependency-free `IntersectionObserver` reveal (16px rise, opacity fade, 70ms
  stagger). Cover hover scales to 1.03 with a restrained gold edge glow; the title
  rises 2px; `#book-1` through `#book-14` supply distinct colour variables for a
  single passing cover-light sweep. Preserve the `prefers-reduced-motion` path,
  which keeps cards visible and removes all new movement.

- **Homepage LCP hardening:** do not reintroduce `.hero-glitch` as a second large
  image element. The hero glitch reuses `.hero-bg`; a late duplicate had become a
  new LCP candidate around 15–25 seconds after load. `index.html` preloads both the
  hero image and the exact Latin Cinzel 600 WOFF2 used by the hero `<h1>` so the
  background and final heading font do not create a late LCP update. Keep the hero
  image preload `fetchpriority="high"` and do not animate/hide the LCP elements
  before first paint.

- **Initial-load request discipline:** `js/main.js` defers the public
  `track-visit.php` beacon to `requestIdleCallback` (3-second fallback); it is
  analytics, never render-critical. `js/members.js` starts its first session check
  as soon as its DOM-ready handler runs, because the account state in the header is
  interactive chrome and an idle delay left signed-in visitors looking logged out.
  Notification code is not referenced by public HTML; it is loaded after the
  authenticated session result.

- **About portrait ratio:** `images/pascal_author.jpg` is 700×1074. Its frame rule
  must keep `width: 100%; height: auto`; omitting the explicit automatic height lets
  the HTML `height="1074"` attribute survive a width resize and vertically stretch
  the portrait.

- **API response security headers:** `api/helpers.php` applies
  `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, and
  `Referrer-Policy: strict-origin-when-cross-origin` to normal API responses.
  The root `.htaccess` adds the site-wide CSP and HSTS policies to APIs as well
  as HTML; do not replace its CSP with a route-specific policy. Cron and
  bootstrap/error paths that cannot rely on helpers set the same basic headers
  themselves; keep any new exceptional API entry point consistent.

- **User Sessions:** `user_sessions` is a revocable registry layered over PHP
  sessions. It stores only SHA-256 hashes of opaque per-session and PHP-session
  identifiers—never raw cookies or tokens. Run `sql/migration_user_sessions.sql`
  manually after deployment. `pw_current_user()` validates each authenticated
  request against the registry and only updates `last_active_at` every five
  minutes. Login/register create a record; password changes revoke other
  sessions; bans revoke all sessions. The Profile -> User Sessions panel uses
  `api/user-sessions/{list,revoke,revoke-others,revoke-all}.php`; signing out
  everywhere requires the current password and destroys the local session.

- **Google OAuth:** the provider-neutral `api/oauth.php` centralizes OAuth state,
  PKCE, safe local return URLs, Google code exchange/userinfo validation, identity
  linking and optional first-registration avatar import. `oauth_identities` stores
  only `(provider, provider_subject, verified email)`—never provider tokens. Run
  `migration_oauth_google.sql` before defining `GOOGLE_OAUTH_CLIENT_ID`,
  `GOOGLE_OAUTH_CLIENT_SECRET`, and `GOOGLE_OAUTH_REDIRECT_URI` in the external
  secrets config; `docs/google-oauth.md` has the exact Google Console redirect URI.
  Existing email addresses are never auto-linked: users sign in normally, then use
  Profile Settings -> Sign-in Methods to link Google. Google-only accounts have a
  NULL password hash, may add a local password later, and cannot unlink their last
  sign-in method until they do. Google login/registration/link/unlink outcomes are
  recorded in `admin_activity_log` and sessions carry `auth_provider = google`.

- **BH-4 status imagery:** Admin Home swaps the BH-4 portrait from normal to
  medium or critical automatically from the Task Advisor priority: `clear` /
  `normal` is green, `high` is amber, and `critical` is red. The three files
  live at `images/bh4-{normal,medium,critical}.png`; retain the lower-case
  names because the production server is case-sensitive.

- **Privacy Requests:** public `privacy.html` explains the site privacy contact
  (Privacy@thepantheonwars.com) and links to `privacy-request.html`. Signed-in
  users can submit access, correction, erasure, portability, restriction,
  objection, or other requests; these always enter the permissioned Community
  -> Privacy Requests queue and are never fulfilled automatically. Run
  `migration_privacy_requests.sql` once in phpMyAdmin after deployment. BH-4
  priority is critical alerts -> backup reviews -> topic reports -> privacy
  requests -> dispatch translations. Request content and staff outcomes must
  never be copied into the ordinary admin audit log.

- **SQL Performance monitoring:** `api/db.php` uses a guarded PDO statement
  wrapper to record only statements taking at least 100ms into
  `sql_performance_logs`. It fingerprints SQL by replacing literals, never
  stores bound values, and ignores its own diagnostic insert. Apply
  `migration_sql_performance_monitoring.sql` in phpMyAdmin after deployment.
  The System Status page exposes the recent count, average, slowest endpoint,
  and highest cumulative-cost fingerprints. If the table is not present yet,
  normal requests and System Status safely continue without diagnostics.
  BH-4 creates a critical System Status directive for a >=2s query or five
  >=500ms queries within one hour.

- **Benchmarking:** JSON API responses using `helpers.php` emit a `Server-Timing`
  header containing total PHP duration plus aggregate DB duration/query count.
  Run `tools/benchmark-performance.ps1` before and after performance work; it
  makes five public requests per target and saves raw JSON under ignored
  `benchmarks/`. `PERFORMANCE_BENCHMARKS.md` defines the matching Lighthouse
  and EXPLAIN/ANALYZE protocol.

- **Visitor Statistics index plan:** raw `page_views` analytics are indexed
  by their shared UTC time window plus the grouping column used by each card:
  visitor/user, path, referrer and country. `analytics_explain_validation.sql`
  contains read-only MariaDB `ANALYZE FORMAT=JSON` checks (its `EXPLAIN
  ANALYZE` equivalent) to run after the index migration. The heatmap's annual
  query uses a sargable half-open UTC range rather than `YEAR(created_at)`.
  The Recent Visits feed derives an anonymous `Crawler` pill from the stored
  User-Agent through the allowlisted `pw_crawler_name()` helper; add recognised
  search-engine signatures there. The helper currently covers Googlebot,
  Bingbot, DuckDuckBot, YandexBot, Baiduspider, Applebot and several smaller
  engine crawlers. `api/admin/visitor-stats/recent.php` exposes only the
  derived `is_crawler`/`crawler_name` values, never the raw User-Agent. This
  does not alter stored visits, counting, IP masking, or authenticated member
  classifications; unknown bots remain Guests.

- **Admin Home summary endpoint:** `api/admin/home-summary.php` now returns
  every Home-card payload in one request (activity, queues, content/security/
  community metrics, site and Development Snapshot data, BH-4, System Status,
  and the Task Advisor). The browser's normal 60-second Home refresh uses
  this single endpoint instead of eleven card requests. Its per-session
  15-second server cache is bypassed with `?fresh=1` after a manual action.
  The bundled response reuses one system-health pass for both System Status
  and the Task Advisor; the card-specific endpoints remain for standalone
  compatibility.

- **Development Snapshot refresh:** the Home card has a lower-right
  “Refresh now” action. It performs a CSRF-protected admin request to GitHub
  for a fresh language snapshot, forces the deployed-repository LOC snapshot
  to recalculate, then rerenders the card. It does not push to GitHub or
  deploy code.

- **Visibility-aware presence:** `js/members.js` stops the logged-in session
  heartbeat entirely when a tab is hidden and restarts it with an immediate status
  refresh when the tab becomes visible. `api/session-check.php` additionally
  throttles `users.last_active_at` writes to once per user per minute; the online
  window remains five minutes, so multi-tab activity stays accurate without
  redundant row locks. The current members-script cache version is v=23 across
  every public page and the admin console.

- **Static asset caching:** `.htaccess` now gives versioned CSS, JavaScript,
  font, and image assets a one-year immutable cache lifetime, with matching
  `Expires` metadata when mod_expires is available. HTML and dynamic PHP/API
  responses retain their existing non-long-lived behavior.

- **Sankey analytics scaling:** visitor journeys now read completed UTC days
  from a compact `page_view_daily_transitions` rollup and calculate only the
  current day from raw `page_views`. The rollup table's `(include_admin,
  stat_date)` composite index serves date-range lookup; the existing raw
  `(visitor_id, created_at, id)` index remains the correct window-order index
  and should not be duplicated. Run `migration_page_view_journey_rollups.sql`
  and schedule its cron endpoint for 01:05 UTC after deployment.
- **Page-view rollup efficiency:** `api/cron/rollup-page-views.php` now
  processes only yesterday on normal daily runs, rather than recomputing the
  full retained history. A manual repair can use `?full=1` with the normal
  cron key to rebuild every finished raw-data day deliberately.

- **Image loading and layout stability:** the public home, books, about, and
  dynamic worlds pages now defer non-critical artwork with native
  `loading="lazy"` and `decoding="async"`. The API-driven books renderer applies
  the same policy after replacing its static fallback; static images declare
  their source dimensions; responsive images whose CSS constrains only width
  explicitly retain `height: auto`. Existing fixed/aspect-ratio image
  containers continue to reserve space for dynamically managed art. This
  reduces initial image transfers without changing the rendered design.

- **World catalog query optimization:** refactored public `api/worlds.php`
  from an N+1 detail-loading pattern into four fixed bulk queries (worlds,
  layers, landmarks, and sublocations). The endpoint groups the rows in PHP
  using the same shape as the World Control admin endpoint, while preserving
  the public `worlds` / single `world` response payload exactly.
- **Forum + visitor-analytics query hardening:**
  `migration_forum_analytics_indexes.sql` adds composite indexes for active
  topics/comments, bookmark ordering, and ordered visitor journeys; it also
  replaces the redundant single-column `page_views.visitor_id` index. The
  public forum index (`api/boards-summary.php`) now obtains per-board topic
  counts, reply counts, and latest activity in three set-based queries rather
  than four queries per visible board. Topic-list, Active Topics, My Topics,
  and Bookmarks now aggregate replies by joining only the selected topics,
  rather than first grouping every comment in the database. Verified in live
  phpMyAdmin on 2026-07-13: all four index sets already exist, including
  `idx_visitor_created_id`; do **not** re-run the migration on production.
  `sql/schema.sql` now documents those existing indexes for new installs.
- **Forum sub-navigation**: added a persistent 5-tab strip above the forum (Forum
  List / Active Topics / Bookmarks / My Topics / FAQ). New `topic_bookmarks` table +
  `api/topics/bookmark.php` toggle (login required, no admin permission needed, same
  trust level as `message_likes`). The bookmark toggle is exposed through the topic
  kebab menu (`buildModMenu` in `community.html`) which was previously visible to
  everyone but only ever did anything for moderators/admins -- Bookmark now renders
  unconditionally for `ctx.kind === 'topic'`, ahead of the still-gated
  Pin/Lock/Move/Edit/Delete block, so regular members finally get value from that
  menu. Active Topics/Bookmarks/My Topics all reuse one cross-board row renderer
  (`buildTopicRow(t, {showBoard: true})`) fed by three new endpoints
  (`api/topics/active.php`, `mine.php`, `bookmarks.php`), each filtered through
  `pw_can_see_board()` so a hidden board never leaks into a cross-board view.
- **Forum graphical polish pass** (`community.html`/`css/style.css`, no backend
  changes): gold/silver/bronze glow on leaderboard ranks #1-3; per-reaction-type
  accent colors (Shard/Ward/Ember) + a click pulse animation; unified all forum SVG
  icons to `stroke-width="1.6"`; a client-side-only "unread" dot on board/topic rows
  driven by a `pw_forum_last_seen` localStorage timestamp (no server-side
  read-tracking exists -- per-browser nicety only); skeleton/shimmer placeholder
  rows for the board index and topic list while fetching; reskinned the `[spoiler]`
  reveal button with an eye icon + Overcode-flavored copy; smoother mod-menu
  dropdown open animation (slight overshoot); a divider in the editor toolbar
  between the format group (B/I/U/C) and the insert group (Link/Img/Spoiler/color);
  pinned topics get an actual gold chip instead of plain text; reply-thread
  collapse/expand now animates instead of an instant `hidden`-attribute snap.
- Added a `[spoiler]...[/spoiler]` BBCode tag, collapsed behind a click-to-reveal
  button. Required editing every duplicate `formatBody()`/`stripBbcodePreview()` copy
  listed above in lockstep -- a bug was caught mid-session where `profile.html`'s
  Recent Comments and both notification-excerpt renderers would have shown a
  spoiler's actual hidden text in a preview with no reveal-gate at all (fixed by
  having `stripBbcodePreview()` replace spoiler content with a placeholder instead
  of unwrapping it).
- Admin Home page: added a "Total Lines of Code" tile (Development Snapshot card)
  with a `+N today` delta, styled after the existing trend-arrow pattern on
  `dev-metrics.html`. `api/admin/loc-stats.php` runs `git ls-files` (via `shell_exec`
  against `/home/rdy3i6my40b0/repositories/thepantheonwars`, the actual cPanel Git
  working copy -- same `shell_exec` pattern already proven for `du -sb`) and sums
  line counts across tracked source files, snapshotting once per calendar day into a
  new `loc_snapshots` table so the scan doesn't run on every Home page load.
- **Overlord Control**: new `overlords` table + `worlds.overlord_id` FK, replacing
  the free-text `worlds.overlord_name`/`overlord_title`/`overlord_page_slug` columns
  (kept for now, not yet dropped -- see loose ends below) with a real relationship.
  The six hand-authored `overlord-*.html` pages became one dynamic template
  (`overlord.html?slug=...`, same `?id=`-style pattern as `member.html`); the six old
  filenames are now meta-refresh redirect stubs so no inbound link broke. Full admin
  CRUD mirrors World Control's endpoints. Fixed a stale `worlds.html#high-hammer-view`
  anchor as a side effect of switching to a real join instead of hand-typed strings.
- **World Control**: `worlds`/`world_layers`/`world_layer_sublocations`/
  `world_landmarks` tables replacing hand-authored markup in `worlds.html` (now a
  dynamic template + `js/worlds.js`). Three admin CRUD levels (world -> layers ->
  landmarks), an 8-color `tint_key` palette replacing one-off per-layer CSS classes.
  Added a `world_available` notification type, broadcast on a world's status
  transition into `available` (never on every save), with a per-user opt-out
  preference.
- **Interactive Worlds atlas:** `worlds.html` now presents the supplied
  `images/twelve-worlds-atlas.png` as a wide 1672×941 interactive SVG overlay.
  `js/worlds.js?v=16` maps stable world slugs to the artwork's medallions (never
  use `worlds.sort_order`: Asmecu and Reanium are deliberately ordered differently
  in the database and artwork), so World Control's ordinary `available`/`locked` status
  automatically controls each destination. Available medallions open the stable
  dynamic record route `world.html?slug=<slug>`; locked medallions stay visually
  dimmed and expose `ERROR: LORE LOCK / MISSING INFORMATION` without leaking a
  record. The atlas is now the only public world index: the former card grid and
  inline world-detail sections were removed from `worlds.html`; full lore lives on
  each dedicated `world.html?slug=...` record. Beneath the atlas, the same API data
  drives a fixed 13-stage lore progress bar: the current numerator counts records
  whose World Control status is exactly `available`, while the thirteenth marker is
  deliberately reserved for the later world. GSAP fills the bar with `power3.out`,
  advances the integer counter and markers, runs one energy sweep and spark pass,
  then stops; reduced-motion users receive the final state immediately.
  `js/world-atlas-effects.js?v=8` adds
  the cinematic layer: GSAP owns one
  restrained scene transform and ScrollTrigger depth pass, while one transparent
  native-resolution canvas clips all ambient effects to their calibrated medallion
  circles. The twelve stable slugs select distinct motifs. The current late-orbit
  treatments deliberately avoid broad rectangular light shafts: Babki Prime has
  wind-driven falling leaves, Sed carries a hard solar glare with progressively
  revealed glowing medallion cracks, Beoctica performs a pronounced slow searching
  camera pan and zoom, Terek II stages small background explosions with expanding
  smoke and adds a medallion-only hover shake, Valerium
  Prime radiates fine holy revelation rays, and Vermillia XI rains inside its dome.
  Earlier-world motifs
  remain glitch, copper sparks, ash/embers, radioactive spores, water caustics, and
  steel rain/fog.
  Effects are built only for API records whose status is exactly `available`; a
  locked world must never receive either its effect or destination behavior.
  Rendering is throttled to a cinematic 24 fps, uses deterministic particle pools,
  clears the transparent overlay completely between frames (this prevents blurred
  world rims and sparks from leaving trails), and pauses when the atlas
  leaves the viewport or the tab is hidden. Available worlds render at a visible
  idle strength, ease to roughly double intensity on hover/focus, receive a local
  2.8% image zoom, and use their own tone for the illuminated rim and signal. Each
  motif also has a staggered 6–9 second signature flare so the orbit never pulses in
  unison. The Nexus clouds combine the author-supplied 8-second, 1280x720,
  234 KB VP9 WebM loop at `images/nexus-clouds-loop.webm?v=2` with soft canvas
  wisps, inward-moving dust, a
  breathing core, and deterministic lightning. CSS exposes the video only through a
  feathered central cloud mask; static atlas-image shields cover the orbit, labels,
  medallions, and central city so those pixels never move. Lightning bolts now appear
  as complete branched paths in a roughly half-second double flash instead of moving
  along a line. Their spawn band stays above the city and never follows an orbit.
  High Hammer uses compact copper motes instead of line-shaped sparks so its idle and
  hover states cannot leave vertical streaks. The Nexus remains active even if every
  world is lore-locked.
  Fine-pointer desktop devices get less than one
  degree of pointer tilt through `gsap.quickTo`; touch devices keep the atlas flat.
  The WebM and canvas ticker start and pause together through the atlas intersection,
  tab-visibility, and motion-preference controller. Reduced-motion users receive the
  static atlas with the video, canvas, and decorative depth layers disabled. Keep the
  tooltip outside the transformed scene so its text stays crisp and preserve the
  native coordinate system for every overlay.
  `world.html` plus `js/world-detail.js?v=2` is the dedicated,
  expandable World Record surface; it uses the existing single-world API response,
  renders the current map/layer detail for available worlds, and safely keeps direct
  links to locked records sealed. The artwork is cache-versioned as
  `images/twelve-worlds-atlas.png?v=2`; increment that query whenever the source
  image changes because image assets are immutable in browser caches. The SVG root
  uses `preserveAspectRatio="none"` and the matching image fills the same coordinate
  box. Locked medallions are painted once into a transparent native-resolution canvas
  from that already-loaded atlas image, then displayed in the identical CSS box. This
  avoids every secondary-image/SVG transform and is the authoritative lock treatment;
  do not reintroduce SVG blend modes or clipped image copies. Medallion centers are
  individually calibrated against the native 1672×941 artwork; do not replace them
  with an evenly spaced orbit. Each focus/hover signal opens a compact tooltip beside
  that medallion with a world-specific, restrained gradient; there is no persistent
  atlas information card below the artwork. The atlas itself still has no
  separate positioning table or migration.
- **World Control save access:** the World edit modal keeps its existing Save API
  path, but its Save World / Cancel / Delete action bar is sticky at the bottom of
  the modal. Status changes therefore remain saveable while the long layer and
  landmark editor is scrolled.
- **Weather Control / Neoh pilot:** the permissioned Lore Management page currently
  exposes Neoh only, even though its API and table are world-generic. Administrators
  can set the public location, current and next-day conditions/temperatures,
  generation ranges, condition library, and hazard advisory. The Neoh World Record
  renders an AccuWeather-style atmospheric sidecard with current metrics and five
  days of deterministic UTC output. Keep the public card absent for disabled or
  lore-locked profiles. Extend the same UI to other worlds only after the Neoh copy,
  visual hierarchy, and ranges have been approved. The current Neoh profile is
  deliberately seeded as acid rain, 19&deg;C, dense smog, with a colder smog forecast
  on the next day; these are content defaults, not a live real-world weather feed.
- **News publication notifications:** News Management creates public posts
  immediately, then broadcasts a `news_published` notification only after the
  database transaction commits. Each notification deep-links to
  `news-post.html?slug=...`; the publishing administrator is skipped and members can
  opt out through Profile Settings &rarr; Notification Settings (enabled by
  default, including for existing accounts).
- **Public News layout:** `news.html` uses `.news-layout`: the feed occupies the
  main column while a sticky right-side tag panel shows the ten most-used tags.
  Any remaining tags are inside its accessible “See more tags” disclosure. The
  tag chips on each article deliberately render below its body, rather than below
  its headline. A second sidebar card filters the same feed by UTC month and
  year; the tag and date filters intentionally compose. `js/news.js` owns both
  filters and the usage-count ordering. The first visible post receives the
  featured-transmission treatment; each card has an author-accented metadata rail
  and a tag-derived signal indicator. Its one-time scroll reveal and scanline are
  gated by `prefers-reduced-motion`, while the watermark and “Read transmission”
  affordance are hover-only.
- **Forum Control**: `forum_boards`/`forum_board_roles` tables replacing hardcoded
  board arrays in `community.html` and the API layer -- boards are now fully
  admin-managed (name/description/icon/visibility/order), with hidden boards scoped
  to specific roles via `pw_can_see_board()`.
- Fixed Total Storage metric twice in a row post-deploy (see "Server introspection
  notes" above) -- first showed `undefined MB` (field-name mismatch with the shared
  `setAvatarStorageBar()` renderer, which expects `used_mb`/`max_mb` not `used_gb`/
  `max_gb`), then showed a real but wrong `0 MB` (the `disk_free_space()` quota
  assumption was wrong). Now uses `du -sb` and reports against the 75 GiB disk
  allowance (76,800 MB, deliberately displayed in MB), matching cPanel's own Disk
  Usage page. The database-size allocation is 73.57 GiB (75,336.68 MB).
- **Last Backup** is a manually logged health signal because cPanel account backups
  are unavailable on this host: OK under 3 days, warning at 3 days, critical at 7
  days, and immediately critical when no backup has ever been logged. BH-4 surfaces
  its yellow backup-review warning immediately after critical system events, ahead
  of reports, privacy requests, and translations; a stale/missing backup is a
  critical system directive with its own explicit remediation wording.
- Added System Status "CPU Allocation & Host Load" card (24h line chart, live
  load1/5/15 + the 2-vCPU plan allocation) and expanded the Database card
  (connections, QPS, slow queries, uptime,
  buffer pool hit ratio, threads running, largest-tables list with collation-mismatch
  flagging). Fixed the `books` table's collation bug found via that research.
- Members admin section: avatar+role-ring in list rows (removed bare `@username`
  text), Generate Password button (14-char CSPRNG password, reveal-once UI, never
  logged in plaintext).
- Home page: 60s auto-refresh (visibility-gated) on all cards, "Add New Book" quick
  action.
- Book Control: image upload + library picker (reuses server-generated filenames,
  per-directory `.htaccess` denying PHP execution, same pattern as
  `api/upload-avatar.php`).
- Two-factor authentication is opt-in TOTP for password sign-ins only; Google
  OAuth keeps using Google's provider protection. `user_two_factor` holds an
  AES-256-GCM ciphertext, never a plaintext authenticator secret. Add a base64
  32-byte `TWO_FACTOR_ENCRYPTION_KEY` in external config and run
  `sql/migration_two_factor_authentication.sql` after deployment. Password
  acceptance creates a five-minute unauthenticated challenge; a code permits
  one adjacent clock window and cannot be replayed within the same counter.
  Staff recovery is `members.reset_two_factor`, revokes the target's sessions,
  and produces an audit record. Profile setup renders the provisioning QR code
  locally with the vendored MIT `js/qrcode-generator.min.js` library; never use
  a remote QR service because the URI contains the temporary authenticator
  secret. The manual key remains the accessibility and failure fallback.

## Known non-blocking loose ends

- `worlds.overlord_name`, `overlord_title`, and `overlord_page_slug` are unused dead
  columns now (Overlord Control replaced them with a real `overlords` table +
  `worlds.overlord_id` FK) but haven't been dropped yet -- planned as a short
  follow-up migration once the cutover has been stable for a while, same two-step
  caution already used for the `books` collation fix. Not urgent; nothing reads or
  writes them anymore.
- Two member accounts ("Josh" and "Cibit") had their passwords silently reset during
  an earlier live-verification session (a stuck `window.confirm()` dialog forced a
  page navigation mid-flow, and the Generate Password action had already fired before
  the dialog got stuck). The plaintext was never captured. User was informed and said
  "everything works" -- no further action was requested, so none is pending, but if
  either of those users reports being locked out, that's why.
