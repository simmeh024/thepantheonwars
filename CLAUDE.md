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

**Dropping an index that a foreign key leans on: create the replacement first.**
InnoDB requires an index whose leftmost column is the FK column, and it refuses
to drop the only one that qualifies -- `#1553 - Cannot drop index '<name>':
needed in a foreign key constraint`. This is easy to miss because the index
doing the work is often a *unique key added for an unrelated reason* that just
happens to lead with the FK column. It bit `migration_quiz_enhancements.sql`
live in phpMyAdmin (2026-07-22): `uq_quiz_option_score (question_id,
score_index)` was silently serving `fk_quiz_options_question`. Order the
statements `ADD KEY` (also leading with the FK column) **then** `DROP INDEX`.
Note this class of error is invisible to the parse-level checks used when there
is no MySQL in the sandbox -- quote/paren/encoding balance cannot see a
constraint dependency, so an untested migration is structurally valid, not
proven to run. Keep every statement idempotent (`IF NOT EXISTS` / `IF EXISTS` /
`INSERT IGNORE`) so a migration that fails partway can just be re-run from the
top; phpMyAdmin stops at the first error and executes nothing after it.

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

Three cPanel cron jobs exist:

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

3. Sundays at 00:00 UTC (cPanel cron fields: minute `0`, hour `0`, day `*`,
   month `*`, weekday `0`; it always looks back exactly 7 days from whenever
   it runs, so the exact day/time isn't load-bearing, just what was chosen),
   Dispatch Translation quality report:
```
curl -s -o \dev\null "https://thepantheonwars.com/api/cron/generate-quality-report.php?key=<CRON_SAMPLE_KEY>"
```
This computes and stores a `dispatch_quality_reports` row (see "Dispatch
Translation quality feedback and weekly self-tuning report" below); viewed
in Admin -> Development Dispatches -> Translation Quality. Reuses the same
`CRON_SAMPLE_KEY` as the other two rather than adding a fourth secret for
the same trust boundary. Requires
`sql/migration_dispatch_translation_quality.sql` and
`sql/migration_dispatch_quality_reports.sql` to have been run first; until
then the endpoint 500s harmlessly (no partial/corrupt row is ever written).

## Permission-aware UI is a standing requirement, not a one-off audit

The backend permission system (`permissions` table, `role_permissions`,
`pw_has_permission()`/`pw_require_permission()` in `api/helpers.php`,
`pw_user_permissions()` returning `['*']` for a superuser role) is the only
real security boundary and is never to be weakened for UI convenience. But
every control, button, modal, nav entry, or dashboard card that a given
session cannot actually use must also be hidden from that session -- a
visible-but-blocked control is a UX bug even when the backend correctly
rejects it. This was audited application-wide (2026-07-21) and is now a
standing convention for every new admin feature, not just the modules fixed
at that time.

**The reusable mechanism (already built, use it, do not reinvent it):**
- `window.PW_AUTH.permissions` (from `api/session-check.php`) is the single
  source of truth on the frontend, same as the backend's own permission set.
- `pwHasPermission(key)` (defined once in `js/members.js`, available on every
  page including the admin console) checks it -- `'*'` short-circuits true for
  a superuser role, matching the backend's own bypass exactly.
- In `admin/index.html`, `applyNavPermissions()` runs once at `checkAccess()`
  and hides every element carrying a static `data-requires-permission="key"`
  (or `"key1,key2"`, OR-matched) attribute. Use this for elements with no
  other logic toggling their `hidden` state (nav `<li>`s, dashboard cards,
  toolbar buttons that are always either fully visible or fully hidden).
- For a control whose visibility is *also* driven by other state (a Delete
  button hidden in create-mode but shown in edit-mode; a Save button that's
  temporarily disabled while a fetch is in flight) do **not** rely on the
  static attribute -- it only runs once and other code can silently re-show
  the element afterward. Instead compute the final state at the same point
  the other logic already runs, e.g.
  `xDeleteBtn.hidden = <business condition> || !pwHasPermission('x.delete');`
  and `xSaveBtn.disabled = !pwHasPermission('x.edit');` inside the section's
  `loadX()`/`openXModal()`/`openCreateXModal()` functions. Every flat-CRUD
  module (Book/World/Overlord/Quiz/Soundtrack/Known Figures/Forum/Dispatch
  Control) follows this exact pattern now -- copy it rather than inventing a
  new one.
- A "+ Add X" create action must refuse to open even if called directly
  (quick action, keyboard shortcut, a stale button), not just hide its
  trigger: `function openCreateXModal() { if (!pwHasPermission('x.edit'))
  return; ... }`. A view-only session (has `x.view` but not `x.edit`) may
  still open an *existing* record's modal to inspect it, matching the
  Members module's own precedent -- only Save/Delete are disabled there, the
  modal itself still opens.
- For rows/buttons built dynamically in JS (Topic Reports' per-report
  lock/move/delete/resolve buttons, previously built unconditionally), guard
  the `document.createElement`/`appendChild` calls themselves with
  `pwHasPermission(...)`, and re-check the same permission inside whatever
  function actually performs the action (`openReportActionModal()` etc.) as
  defense in depth -- never trust that a hidden trigger is the only path to
  the handler.
- Never invent a hardcoded role check (`role === 'admin'`) anywhere in new
  code, frontend or backend. Where a capability depends on more than a flat
  permission key (forum moderation's `canModerate`/`canDelete`, which also
  needs to know about board-specific context), compute it **server-side**
  from `pw_has_permission()` and send the resolved boolean down in the API
  response (see `api/topics/get.php`/`api/comments/list.php`) -- the frontend
  branches on that flag, never re-derives it from a role string. This is
  still permission-based, just resolved once on the trusted side.
- An empty dashboard card caused by a missing permission (the backend
  computing that section's data only `if (pw_has_permission(...))`, per
  `api/admin/home-summary.php`) must be hidden via
  `data-requires-permission`, not left to render as a blank/loading card
  forever. Before gating a card, check whether its backend data is actually
  permission-conditional at all (`grep` `home-summary.php` for the field) --
  several Home cards (Content Drafts, Security Snapshot, Site Stats,
  Translation Confidence) are deliberately computed for every admin
  regardless of role and must stay ungated.

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
  the Worlds atlas and Known Figures (both GSAP+ScrollTrigger), and now
  `overlord.html` (GSAP alone -- its pointer-tilt portrait effect needs no
  scroll-triggering) -- all deliberate uses of the same pinned files, not
  separate dependencies. The files load after
  the initial page markup and are documented with their package source and
  Standard License link in `js/vendor/README.md`. Do not replace them with a CDN
  dependency. No Alpine
  dependency is installed; continue to prefer CSS transitions and native browser
  APIs for modest motion and local UI state. Any new continuous motion must preserve
  the site-wide `prefers-reduced-motion` behavior and pause while hidden/off-screen.
- Cache-busting: bump the query version across every HTML reference and the relevant
  bundle/import when a static source changes. Current entry versions are public
  `css/public.css?v=307`, community `css/community-bundle.css?v=308`, and admin
  `css/admin-bundle.css?v=281`. Public pages use `css/public.css`, community pages
  use `css/community-bundle.css`, and the console uses `css/admin-bundle.css`;
  `css/style.css` remains the legacy full compatibility bundle. The ordered source
  and bundle map is in `css/SOURCES.md`.
- Same pattern, separate counters, each easy to miss since `.htaccess`'s no-cache
  headers only cover `.html$` -- a stale cached JS file can silently serve old code
  after a deploy even though the HTML/CSS look right (confirmed the hard way more
  than once): `js/main.js?v=N` (current: v=17), `js/members.js?v=N` (current: v=40)
  and `js/notifications.js?v=N` (loaded dynamically), across the public pages
  (not admin). The notification script is now loaded dynamically for
  authenticated visitors rather than referenced in every page's HTML.
  `js/books.js?v=N` is page-specific (current: v=4) and only needs a version
  bump in `books.html`. `js/news.js?v=N` is likewise page-specific (current: v=9)
  and only needs a version bump in `news.html`. `js/news-post.js?v=N` powers the
  dedicated public transmission page (current: v=13); it is only loaded by
  `news-post.html`. `js/messages.js?v=N` (current: v=6) is only loaded by
  `messages.html`. `js/timeline.js?v=N` (current: v=1) is only loaded by
  `timeline.html`.
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
  too tall again.) The authenticated profile chip is rendered by
  `js/members.js`: it shows the member avatar, a role-coloured ring, display name,
  and caret on desktop; on narrow screens only its avatar remains next to the bell.
  The generated avatar markup has inline `22px` width/height, `flex-basis`, and
  `object-fit: cover` as a defensive floor beneath the component CSS. Keep those
  intrinsic dimensions when touching it: a CSS cache/cascade mismatch previously
  allowed the source avatar to expand across the header. If the avatar fails to
  load, the user initial fallback is shown instead.

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

- Commit messages: keep the first line (subject) short and plain -- it feeds
  this repo's own GitHub-webhook-driven Dispatch Translation pipeline (see
  "Dispatch Draft Translator" below), and a long or clause-heavy subject
  produces a garbled public Development Dispatch. Follow it with a blank
  line and a real, detailed body explaining what changed and why -- detail
  belongs there, not crammed into the subject. Never a bare single-line
  commit with no body.
- **`Dispatch:` trailer -- use it whenever a commit is reader-facing.** A line
  in the commit body of the form `Dispatch: <one sentence>` is published to
  the public Development Dispatches page **verbatim**, at 100% confidence,
  skipping the whole inference engine (domain voice, benefit sentence, object
  phrase). Put it on its own line, anywhere in the body:

  ```
  Match the benefit sentence to the commit intent

  Dispatch: Development updates are now written in plain, first-person language.

  <normal detailed body follows>
  ```

  Write it as a complete reader-facing sentence with no jargon, no file
  paths and no commit hashes -- a trailer containing any of those is rejected
  and the engine falls back silently, as is one shorter than 10 characters.
  A trailing period is added if missing. Omit the trailer for internal work
  and the engine behaves exactly as before. This exists because everything
  else in the translator infers reader wording from a developer-written
  subject, which is a hard ceiling: "in first person" reached readers purely
  because a commit title said it.
- Always commit and push finished, verified changes to `main` without waiting to be
  asked -- the user handles the cPanel deploy step and any SQL migrations themselves,
  so git push is the one action that should happen proactively. Still stage only the
  intended files (never a blanket `git add -A`) and use the inline-PAT push method
  above, stripping the token back out immediately afterward.
- Ask before running anything with real production side effects that goes beyond what
  was explicitly requested (e.g., live diagnostic queries, resetting passwords,
  deleting data) -- a question from the user is not authorization to act.

## Recent history (most recent first)

- **Real texture on Neoh's and High Hammer's weather cards.** Both switched
  from a CSS-drawn surface to a real user-supplied image applied as a
  `border-image` 9-slice, the same technique Babki Prime's tablet established
  below. Neoh (`images/Neoh-wall-background.webp`, 1122x1402) got a projected
  hologram HUD panel -- measured corners cyan at top drifting cooler/violet at
  the foot -- replacing the CSS-drawn registration-mark bars, plus three new
  touches layered under its existing icon glitch: an always-on ambient bloom
  on every reading, a hologram scan line (6.2s), and a rare whole-panel power
  flicker (11.7s), all three durations deliberately not multiples of each
  other or of the icon glitch's 6.5s. `stretch`, not `round`: this frame has a
  plain glow line with no repeating glyphs to protect from a mid-tile seam.
  High Hammer (`images/High-hammer-background.webp`, same 1122x1402 source
  dimensions) got a real riveted iron plate, replacing the four CSS-drawn
  corner rivets its own code comment had already flagged as "the same
  technique as Neoh's registration marks, which is why the two must never
  both apply" -- the real plate carries genuine bolted hardware now, so the
  simulated set was removed rather than left duplicating it. Both use
  `border-width: 28px`, `100 fill / 28px / 0 stretch`, and padding trimmed to
  `13px 15px` to compensate, matching Babki's own adjustment.
  Neoh needed a real contrast pass -- text sits over the projected texture --
  and one was caught only by checking a *compounded* case: the far-day
  forecast's own pre-existing opacity dimming (0.78/0.86, tuned against the
  old muted colour) still failed AA (4.00:1) even after the base fix, because
  a generic contrast sweep only samples the first, undimmed DOM match. Landed
  on `#e0ecf6`, unifying `--wx-muted`, day-name and footer to one value/family,
  clearing even the dimmed case at 4.99:1. High Hammer needed no equivalent
  pass: its border-image sits only in the border box, never under text, so
  swapping the frame material didn't touch any existing contrast reading.
  As with Babki below, this sandbox cannot composite or screenshot, so each
  frame's exact visual balance (the 100/92 slice, the 28/27px width) is
  unverified until viewed live.
  `content.css?v=268` / `public.css?v=307` / `community-bundle.css?v=308`.

- **Auto-generate toggle for Today and Tomorrow's weather.** Two new
  independent per-world flags, `current_auto` and `tomorrow_auto`
  (`sql/migration_weather_auto_forecast.sql`, idempotent `ADD COLUMN IF NOT
  EXISTS`), let either of the two previously always-fixed, admin-authored
  days opt into the same generated path days 3-5 have always used, without
  disturbing the other day or any world that leaves both off (default 0, so
  nothing changes until an admin flips one). `current_secondary` (the small
  flavour line) is blanked under `current_auto` rather than shown against a
  condition it was never written for -- there is no generated equivalent for
  it. A real bug was caught while wiring this, not introduced by it: the
  top-level `current` summary object re-read the raw authored columns
  directly instead of the day-loop's own `forecast[0]`, so even with the
  toggle read correctly everywhere else the hero display would have kept
  showing the stale authored figure beside a correctly generated day tile;
  fixed by having `current` mirror `forecast[0]` throughout. All four read
  paths that touch these columns (including `api/weather/witness.php`, which
  independently re-derives severity to guard the storm-witness award) use the
  established guarded-fallback pattern -- try the new columns, fall back to
  the pre-migration list on `PDOException` -- so deploy order isn't
  load-bearing. Weather Control's toggle greys the paired fields rather than
  hiding them, so the last authored values stay visible and still save,
  letting a toggle-off restore exactly what was there. Verified by porting
  the generator branch to Python (21 assertions: both-off is byte-identical
  to the old behaviour, each toggle only touches its own day, generated
  values stay in-range, same inputs are deterministic, a missing-column
  profile behaves as fully authored) and by exercising the real admin
  functions in a browser, including the real risk that a disabled `<input>`
  is excluded from native form submission but not from the scripted
  `.value` read this save path actually uses.
  `admin.css?v=235` / `admin-bundle.css?v=281`.

- **Babki Prime's tablet, refined in three follow-up passes.** All three
  build on the real-texture swap documented just below.
  1. *Carved in, not laid over*: every panel's bevel had light on top and
     shadow below -- the signature of a raised surface sitting on the stone,
     when the slab's own light falls from the top-left and a real recess
     should be dark along its top/left walls with a lit lower-right lip. A
     shared `--bab-recess` now gives every panel that correct cut, flat fills
     dropped from 0.34-0.42 alpha to 0.18-0.26 so granite reads through
     rather than being hidden under a tint, and the gold inlay direction was
     likewise inverted (dark above, lit below). Re-measured across the full
     stone-scrim-panel stack against the texture's brightest crack: worst
     reading (footer) 4.68:1.
  2. *Worn gilding, not fresh gold leaf*: all 11 gold text/icon colours mixed
     16% toward a duller stone-ash tone, with the inlay's lit lip and glow
     both trimmed. The live-archive status dot and the advisory's daubed
     ochre glyph were deliberately left untouched -- one is a light rather
     than lettering, the other is meant to read as pigment freshly pressed
     onto old stone. Re-verified worst case (climate line) still clears AA
     at 4.66:1.
  3. *Served as WebP*: the border-image source moved from a 3MB PNG to a
     906KB WebP at the same 1190x1322 (slice unchanged) and the PNG was
     removed from the repo -- per the standing cPanel gotcha, `cp -R` never
     deletes on deploy, so the orphaned PNG needs manual removal from
     `public_html` via File Manager once live.
  `content.css?v=263` / `public.css?v=302` / `community-bundle.css?v=303`
  (final state after all three).

- **Babki Prime's weather tablet, as a real carved-stone texture.** The
  first real-texture `border-image` swap in this series, replacing the
  CSS-drawn stela below with the supplied render
  (`images/babki-stone-tablet.png`, 1190x1322, glyph frieze and corner seal
  baked in): a symmetric 92px slice with `fill`, `round` (not `stretch`, so
  the frieze band repeats cleanly down a tall card instead of smearing), a
  27px transparent border reserving the frame, padding trimmed to suit, and a
  dark granite gradient as the load-failure fallback. The texture's own
  cracks and moss reach luminance 138 in places -- gold on that is only
  ~3:1 -- so a 0.62 scrim (padding-box only, so the bright frieze itself
  stays untouched) seats every reading on consistently dark granite,
  measured by sampling the actual texture on a canvas; this took the worst
  reading (footer) from 1.4:1 to 4.67:1. Established the pattern every later
  real-texture card in this file follows: symmetric slice, transparent
  border-color, trimmed padding, a plain-gradient load-failure fallback, and
  -- since this sandbox cannot composite or screenshot -- an explicit note
  that the slice and frame width are the two dials to check once deployed.

- **Bespoke per-world weather card materials: High Hammer, Cerius, Reanium,
  and Babki Prime (CSS-drawn, pre-dating the real-texture passes above).**
  Each treats its world's weather card as a different physical object rather
  than a reskin of the shared console template, reading the same
  `is-weather-<icon>` class `world-detail.js` puts on every card (added here)
  so the object itself can react to the live condition without any new
  script or migration.
  - **High Hammer**: a cast, struck, bolted foundry gauge plate -- engraved
    type (dark stroke above, warm below), four domed rivets, chamfered
    stamped-sheet day tags, a chevron-and-bolts warning plate, and the CRT
    sweep repurposed as heat standing off the forge floor. Its one live
    signal: a new `--heat` custom property, set by `applyHeatScale()` from
    today's reading against *this world's own configured bounds* (never an
    absolute temperature -- 43°C is ordinary on Sed, an event on Beoctica),
    driving the current figure's colour from ash grey to near white, its
    bloom, and the floor's own glow. Set on every card (not just this one)
    exactly as `--world-weather-icon` is, so a future variant opting in needs
    no plumbing change; removed rather than blanked when the range is
    missing, since an empty custom property still counts as set and defeats
    a `var()` fallback -- every consumer reads `var(--heat, 0.4)`.
  - **Cerius**: imperial enamel gone to ruin -- ash lying wherever nothing
    disturbs it, firelight climbing through three fractures *from below*
    (the deliberate inverse of High Hammer, where the plate itself is hot),
    and a cinderfall CRT-sweep replacement matching the atlas's own
    `drawCerius()`. Reads `--veil` (how thick the air is) off the condition
    class: soot darkens rather than washes out, so firelight and gilt both
    go dull as smog thickens. Its advisory is deliberately the most neglected
    object in the set -- visibly more ash, chipped enamel corners, a flaked
    rule, a cracked wax-seal marker -- since this is the card telling a
    reader the air will hurt them, so neglect you can see is worth having
    and copy you can't read is not.
  - **Reanium**: irradiated glass, with contamination light living *inside*
    the material and crazing running across the readings themselves (screen-
    blended, so a hairline crossing a glyph can only add light, never darken
    it below AA). Its organizing idea is that radiation is stochastic, not
    rhythmic: a smooth pulse reads as a heartbeat, so the live dot became an
    8-burst Geiger counter at uneven intervals (longest gap 7x the shortest),
    cut with `steps(1, end)` rather than eased. A follow-up pass
    (`bb0df92`) made the dosimeter itself untrustworthy: a small state
    machine (normal/frozen/surge/hold/ease) occasionally freezes the reading,
    then overshoots by ~20 Sv before easing back to the true value -- a
    counter you can't trust says more about the world's danger than a high
    number does. Both the base dose readout (`c9b0b37`, seeded from the
    forecast's own date so every visitor sees the same figure, colder days
    dosing harder against Reanium's own configured range) and the fault
    state machine were verified by stepping them with a controlled clock/RNG
    rather than by eye.
  - **Babki Prime** (original CSS-drawn pass, since replaced by the real
    texture above): a carved jungle-temple stela the forest is reclaiming --
    moss thinning upward from the foot, a root splitting one corner, incised
    (not printed) type, leaf-fall replacing the CRT sweep to match the
    atlas's `drawBabki()`. Reclamation is held strictly to edges and low
    corners and never crosses a glyph.
  Each card was verified against a harness built from `world-detail.js`'s own
  real card template, always with the *previous* card in the series as an
  unaffected control, checking every reading clears AA against that card's
  own worst composited state (measured, not assumed) and that nothing
  escapes the object's bounds at any width.
  `world-detail.js?v=15` (final state after the Reanium fault-state pass).

- **Weather-varying district quotes** (`world_quote_variants`, **run
  `sql/migration_world_quote_variants.sql` once**). A World Record district can
  carry up to five alternate pull quotes, one per condition icon, and shows the
  one matching that world's weather today. Entirely authored -- the system only
  chooses between lines the author wrote. Generating them would hit the same
  ceiling the Dispatch Translator spent v26-v34 proving.
  **Keyed by the five icon keys, never by a world's own condition names**: those
  live in `condition_pool_json` and can be edited, which would orphan any quote
  keyed to the old wording. Every world therefore has the same five keys.
  **Resolved server-side in `api/worlds.php`, on the single-world path only.**
  Client-side selection would paint one quote and visibly swap it when the
  separate weather request landed; and running the generator on the twelve-world
  atlas path would cost twelve profile loads for quotes that page never shows.
  Silent by design (no "recorded during a storm" cite) -- a returning reader
  just finds a different remark. Any layer with no variant for today keeps its
  own `quote_text`, so these can be written one at a time.
  `world_quote_variants` is polymorphic so landmarks can reuse it with no
  migration, which also means **no foreign key can cascade it** --
  `layers/delete.php` clears them explicitly, or a recycled AUTO_INCREMENT id
  would hand a future layer someone else's quotes.
  **Two verification lessons, both cost real time:**
  1. **Bash heredocs in this sandbox silently eat backslashes**, even quoted
     `<<'PY'` ones. That flattened `'layer'` to `'layer'` in two PHP files,
     terminating a SQL string early. **Write files containing escapes with the
     Write tool, never a heredoc.**
  2. The brace/paren checker **strips strings, so it is structurally blind to a
     broken string delimiter**. Quote *parity* does not catch it either --
     `a = 'layer''` is balanced, parsing as string/bareword/string. The signal
     is an identifier hard against a closing quote; `tools`-style check added at
     `scratchpad/php_quotes.py`, calibrated to fail on that exact pattern and
     pass every existing file including `dispatch-translation-drafts.php`.
  `admin.css?v=234` / `admin-bundle.css?v=280`.

- **Weather: ambient effects, severity, season, and a witnessable event.**
  **Run `sql/migration_weather_severe.sql` once.**
  **Severity is judged against each world's own configured bounds, never an
  absolute figure** -- 43°C is an ordinary afternoon on Sed and an event on
  Beoctica (asserted both ways). Storm conditions always qualify; wind and
  precipitation trigger at 0.9 / 0.95 of the profile's own range, and
  temperature at the range edge. Those two fractions were tuned by measurement:
  at 0.85 / 0.9 roughly a third of generated days flagged severe, which makes
  "severe" ordinary. They now land near a quarter.
  **Witnessing severe weather is awarded once per world, not once per storm**
  (`api/weather/witness.php`, `user_lore_discoveries.entity_type =
  'severe_weather'`). The endpoint **recomputes the severity itself** rather
  than trusting the caller, exactly as the Timeline discovery endpoint
  re-checks its gate -- it is a separate entry point, so without that a crafted
  POST could claim the award on a calm day. Two achievements sit on it
  (`storm_witness`, `stormchaser`) and it sends a `weather_alert` notification;
  the full notification checklist in this file was worked through and audited.
  **The season phase surfaces `pw_weather_seasonal_bias()`**, which had been
  shaping temperatures with a per-world phase offset since it shipped and was
  invisible to readers. Labels are derived, not authored, by comparing today's
  bias with a fortnight ahead so a world reads as warming or cooling rather
  than only warm or cold.
  **`js/weather-effects.js` is a new module, NOT the atlas engine.** The
  earlier assumption that `js/world-atlas-effects.js` could be reused was
  wrong: its drawers are private to that IIFE, keyed by world slug, and bound
  to the atlas's fixed 1672x941 medallion geometry. This is the same technique
  at card scale, keyed by the five condition icons instead, so a card changes
  with its weather rather than its identity.
  **Three bugs worth remembering, all found in the browser pass:**
  1. `.world-weather-card > *:not(.world-weather-card-scan)` (class + universal)
     outranks a background layer's own single class, so the new effect canvas
     was forced to `position: relative; z-index: 1` -- in flow, sized to the
     whole card, and level with the content. Background layers must be named in
     that exclusion.
  2. `world-detail.js` has no `global` binding; it is a plain DOMContentLoaded
     handler. `global.PW_AUTH` would have thrown and taken the whole card with
     it. Use `window.` there.
  3. The witness call checked `PW_AUTH` once and gave up, so a signed-in member
     arriving before session-check resolved never recorded anything. It waits
     for `pw-auth-ready` like the lore-discovery call beside it.
  **Browser-pane limits, now confirmed three times over:** `requestAnimationFrame`,
  smooth scrolling, and `ResizeObserver` all fail to deliver when the pane is
  not displayed, because `document.hidden` is true and the rendering lifecycle
  never runs. A control probe confirmed ResizeObserver gets zero callbacks for
  a real size change. **The particle rendering itself is therefore unverified
  locally** -- structure, sizing, stacking and tint were checked, but not a
  painted frame.
  `content.css?v=242` / `public.css?v=282` / `community-bundle.css?v=274` /
  `world-detail.js?v=11` / `weather-effects.js?v=2`.

- **Hourly weather projections.** Two new surfaces over the existing
  deterministic generator, no schema change and no migration.
  **The World Record's five-day strip** now opens an hourly panel per day.
  Each day became a `<button>` so it is reachable by keyboard and on touch,
  where there is no hover at all. **Today lists only the hours still to come**,
  so it shrinks through the day (16 rows at 08:00, 2 at 22:00) and never
  repeats hours the Tomorrow card owns; later days list all 24. The server
  drops the elapsed hours, so the client renders whatever it was handed.
  **The header pill** gains a rolling twelve hours on hover, crossing midnight,
  with rows fading by distance and a "confidence degrades beyond 6h" line.
  **Times are UTC everywhere and the panels say so.** Resolving them in the
  visitor's own zone would put hours under a day heading whose boundaries are
  UTC and the two would disagree -- at UTC+10 "Today" would show two rows in
  the visitor's morning.
  **Two consistency rules the generator must keep**, both worth re-checking if
  it is ever touched: every hour is clamped to that day's own `low_c`/`high_c`,
  so the hourly panel can never contradict the card above it; and day 0's curve
  **peaks at the current hour** (rather than mid-afternoon) because for day 0
  `high_c` IS the administrator's authored "right now" temperature -- so the
  now row matches the big current-conditions figure exactly. The peak alone was
  not enough: jitter still pulled it a degree under on 7 of 24 hours, so the
  now row takes the authored value directly.
  Deviation from the day's headline condition, and temperature spread, both
  widen with distance into the five-day window -- measured **from the start of
  day 0, not from "now"**, so a given hour keeps its value all day instead of
  shifting under the reader every time the clock ticks.
  The pill's roll comes from `api/world-weather-hours.php`, a small separate
  endpoint fetched on first hover and cached per UTC hour. Deliberately not
  folded into `api/worlds-weather-glance.php`, which serves every available
  world and would have carried twelve unused rows apiece. Both surfaces read
  the same generator, so they always agree on an hour they both show
  (asserted).
  **Verified by porting the generator to Python** and asserting the invariants
  across all 24 possible current hours -- there is no PHP CLI in this sandbox,
  and that port is what caught the jitter bug above. Then in a browser against
  a harness built from the real `world.html`.
  **Two bugs found in the browser pass, both worth remembering.** A
  `@keyframes` that animates `transform` outranks normal declarations through
  its fill state, so it overrode the edge rule holding the first day's panel
  inside the card -- Today's panel opened 60px outside it. Animate opacity only
  when a positioning transform must survive. And reading `:hover` back when the
  response lands is wrong for a keyboard user: `:hover` is false on focus, so
  the panel would never have appeared on first focus before the cache warmed.
  Track the intent in a flag instead.
  **Presentation:** the World Record's hours are one shared horizontal rail
  under the five-day grid, not a popup per day. Two reasons it had to move out
  of the day element: a ten-column strip cannot live inside a 60px column, and
  the scroll arrows are buttons -- nesting those inside the day, which is
  itself a button, is invalid. Six columns show at a time
  (`calc((100% - 10px) / 6)`, ~59px each in the 456px sidecard) and the rest
  scroll. **`min-width` on those columns is a trap**: set anywhere near the
  computed width it wins the calc and silently caps the rail at eight or nine,
  which happened twice. Keep it well below.
  The arrows call `scrollBy` **without a `behavior`**, deliberately, so the CSS
  `scroll-behavior` governs and the reduced-motion override actually takes
  effect -- passing `behavior: 'smooth'` in JS overrides CSS and defeats it.
  **Icon colour is one custom property, not a list of classes.** Each of the
  eleven `.world-weather-card--<slug>` variants used to recolour by naming
  `.world-weather-current-icon` and `.world-weather-day-icon` explicitly, so
  the hourly rail was born stuck on the default teal while the day icons above
  it were correctly tinted. They now set `--world-weather-icon` /
  `--world-weather-icon-current` on the card once, and every icon class reads
  those -- a new icon type is tinted automatically. The two defaults differ
  (`#70ccdf` small, `#78d8ee` large) only because they always have, which
  matters for the worlds with no variant such as Neoh; every variant sets both
  to the same value.
  `content.css?v=240` / `components.css?v=214` / `public.css?v=280` /
  `community-bundle.css?v=272` / `admin-bundle.css?v=279` / `main.js?v=17` /
  `world-detail.js?v=8`.
  **Browser-pane limit worth knowing:** a smooth scroll never advances when the
  Browser pane is not displayed, because it needs frames the pane is not
  producing -- direct `scrollLeft` assignment stalls too. Verify a scroll
  handler by recording what it asks for, not by reading the position back.

- **Header weather widget** (`js/main.js?v=14`, `.pw-weather*` in
  `css/components.css`). **Run `sql/migration_weather_widget.sql` once.** A
  compact bar in `.nav-utility` showing one world's current conditions, default
  Neoh, pointable at any unlocked world, clicking through to that World Record.
  **No header markup was added to any page.** The header is hand-duplicated
  across 26 pages, so the widget is built and injected by JS, exactly as
  `js/members.js` already renders the authenticated profile chip. Any future
  header chrome should do the same rather than editing 26 files.
  **It reuses `api/worlds-weather-glance.php` unchanged** — that endpoint
  already returns condition/icon/temp for every world that is both `available`
  and weather-enabled, which is also precisely the picker's "unlocked" set. Only
  an accent was added. Response is cached in `localStorage` for **30 minutes**
  and fetched through `requestIdleCallback`, so this costs about one request per
  visitor per half hour rather than one per page; it paints from cache first, so
  there is no flash.
  **Per-world colour now lives in the database**: `worlds.accent_rgb`, seeded
  from the atlas's existing `ATLAS_TONES`, stored as bare `"R, G, B"` components
  (not a CSS colour) so one value drives both a solid fill and a translucent
  glow — the `--node-accent` convention the timeline markers use. Editable in
  World Control. `ATLAS_TONES` deliberately stays in JS: it drives the atlas
  canvas effects and must render before any fetch.
  Member choice is `users.weather_world_slug`, returned by `api/session-check.php`
  (which every page already calls, so reading it costs no request) and written by
  `api/weather-widget/select.php`. Guests use `localStorage`. **The read is
  deliberately NOT in `pw_current_user()`'s SELECT** — that runs on every
  authenticated request site-wide, so a pre-migration deploy would fatal the
  whole site rather than one value; it is a guarded query in session-check's
  signed-in branch instead. The write re-checks the world is actually available,
  since it is a separate entry point from the glance endpoint.
  **The header has less room than it looks, and this is the part worth
  remembering.** `.nav-inner` is capped at `max-width: 1180px`, so the spare
  space beside the nav **never grows past ~154px however wide the screen gets**,
  and how much is left depends on whether the visitor is signed in — a ~115px
  profile chip versus a ~45px "Login" link. No media query can distinguish those,
  so `fitToHeader()` measures the header instead and steps the widget down:
  full bar → drop the condition text and the °C unit → hide. Two signals, both
  needed: the header growing taller (nav links wrapping), and `.nav-utility`
  running past the content box.
  **Do not use `inner.scrollWidth` for that width test** — the nav's mega-menu
  panels are absolutely positioned and count towards `scrollWidth` even while
  invisible, so it reports an overflow that is not real. Compare
  `.nav-utility`'s right edge against the content box instead.
  **Found while measuring:** the header already overruns its content box between
  the 780px breakpoint and roughly 1090px, where the desktop nav is still shown
  but no longer fits. That is pre-existing and was left alone; the widget simply
  stands down in that band rather than adding to it.
  Verified by measuring computed geometry against a harness built from the real
  header block and the real profile-chip markup — an earlier hand-made chip was
  ~25px too wide and produced a wrong conclusion (that the widget could never
  fit signed in). Build the fixture from the real markup, not an approximation.
  `components.css?v=210` / `public.css?v=271` / `community-bundle.css?v=263` /
  `admin-bundle.css?v=275`.

- **Timeline desktop layout fix; nav link renamed to "The Timeline".**
  Reported as the page looking "funky": every marker's date, title and era ran
  together on one line (`0YEAR 0First cycle`) and the dots sat at different
  heights, off the spine.
  **Root cause is worth remembering: a rule that existed only inside a media
  query.** `.timeline-node-inner` is a `<span>` wrapping three more `<span>`s,
  and its `display: flex; flex-direction: column` lived *only* in the
  `@media (max-width: 780px)` block. On desktop it stayed inline, so the three
  spans flowed together. The narrow layout looked right, which is exactly why
  this survived review -- when a component only renders correctly at one
  breakpoint, check whether the rule doing the work is inside a media query
  rather than assuming the desktop rule is being overridden. The stack is now a
  base rule and the narrow block keeps only what genuinely differs
  (`align-items: flex-start`, to hug the left-hand rule instead of centring).
  Second, independent defect: `.timeline-rail` used `align-items: center`, so a
  marker whose title wrapped to two lines was taller and its dot floated off
  the spine. Now `flex-start`, and the spine's position is derived from shared
  `--rail-top` / `--timeline-dot` custom properties on `.timeline-rail-wrap`
  instead of a `top: 50%` that only lined up while every marker happened to be
  the same height.
  Verified by measuring computed geometry in a browser against a static harness
  reproducing `js/timeline.js`'s exact markup, not by eye: all four dot centres
  equal, spine centre equal to them, and date/title/era on three distinct rows;
  then re-measured at 375px to confirm the vertical layout still stacks at
  x=30 with no horizontal overflow. Prefer this over a screenshot for layout
  bugs -- it states the invariant rather than showing a picture of it.
  Nav/footer link text is now **"The Timeline"** (43 occurrences across the 23
  pages carrying it, including its own `class="active"` instance).
  `content.css?v=234` / `public.css?v=270` / `community-bundle.css?v=262`.

- **Quiz overhaul: server-side scoring, weighted answers, DB-driven cast,
  answer analytics, and resumable play** (`quiz.html`, Lore Management ->
  Quiz Control). **Run `sql/migration_quiz_enhancements.sql` once**; every
  statement is idempotent and `pw_quiz_capabilities()` detects each piece, so
  deploy order is not load-bearing.
  **The security fix is the headline.** `api/save-quiz-result.php` accepted
  the client's own `scores` array *and* its own winning `overlord`, stored
  both unchecked, and awarded a **Pure Resonance icon unlock** off them --
  `{"overlord":"Syn Dravus","scores":[1]}` minted the rarest reward in the
  system and overwrote `users.overlord_affinity`. Nothing validated the array
  length, sign, or that the named Overlord was even the argmax. The client now
  posts *answer option ids*; `pw_quiz_score_answers()` scores them against the
  active questions, requires every question answered exactly once, and rejects
  an option that does not belong to its question. This is the same class of
  boundary as the Timeline discovery endpoint re-checking its own gate.
  **A consequence worth knowing:** the client is no longer sent each answer's
  Overlord weights, so it *cannot* compute a result -- which also means a
  reader can no longer read off which answer belongs to which Overlord. A
  signed-out visitor therefore needs somewhere to send answers, hence
  `api/quiz/score.php`: public, no CSRF (it changes no state and forcing a
  token would create a session for every anonymous taker), and it writes
  nothing at all -- no result row, affinity, icon or reputation.
  **Weighted answers** (`quiz_option_weights`) replace the one-option-per-
  Overlord model. `uq_quiz_option_score` forced exactly six answers per
  question, one per Overlord, which was brutal to author; questions may now
  carry 2-12 answers and one answer may weight several Overlords. The
  migration backfills every legacy option as a weight-1 vote, so scoring is
  byte-identical until someone edits a weight.
  **`api/admin/quiz/save.php` no longer deletes and recreates options** -- it
  matches by id and updates in place. `quiz_result_answers.option_id` cascades
  from `quiz_options`, so the old delete-all-then-reinsert would have wiped the
  answer history behind the new distribution report every time an admin fixed a
  typo.
  **The cast comes from Overlord Control now.** `quiz.html` hardcoded all six
  Overlords (name, epithet, portrait, blurb) in a *fifth* copy of that list, so
  an edited portrait or epithet never reached the quiz. `pw_quiz_overlord_cast()`
  joins `overlords`->`worlds`, with built-in fallbacks so an empty table still
  renders. The result blurb became `overlords.quiz_result_blurb` (its own
  column -- `card_teaser` is roster-card copy and reads differently).
  **Order is `pw_overlord_icon_keys()`, never `overlords.sort_order`**: the
  index is baked into every stored `scores_json`, every `score_index` and the
  icon catalog, so a roster reorder must never change what an index means --
  the same reasoning as the Worlds atlas mapping medallions by slug.
  **Found while doing this:** `quiz.html`'s result card carries
  `class="affinity-themed"`, but those rules live in `community.css` and
  `public.css` never imports it -- the per-Overlord result theming had been
  **dead on the quiz page entirely**. Fixed by having the API supply
  `accent_color`/`accent_glow` (Overlord Control, with fallbacks matching
  community.css's six colours) which JS sets as `--ov-accent`/`--ov-glow`, plus
  consuming rules in `content.css`. The six colour definitions are deliberately
  **not** duplicated into a second stylesheet.
  **Analytics from data that was already there.** `quiz_results` had every
  attempt's `scores_json` and nothing read it in aggregate. Public: a rarity
  line on the result ("one of the 12% who resonate with..."), computed from
  `users.overlord_affinity` rather than counting `quiz_results` rows, so a
  member who retakes ten times counts once; hidden under 20 members, where a
  percentage says more about sample size than the reader. Admin: new
  `quiz_result_answers` captures the per-question choice (the totals alone
  could never show *which* option people picked), surfacing an amber
  "N% pick one answer" pill on any question where ≥70% converge -- that
  question separates nobody and is worth rewriting.
  **Play fixes:** a Previous-question button, `localStorage` resume keyed by a
  question-set signature (an edit in Quiz Control discards stale progress), and
  progress that deliberately **survives until the result is actually scored**,
  so a failed request no longer throws away twenty answers -- a restored
  complete set goes straight back to scoring. `prefers-reduced-motion` skips
  the fixed 4.4s terminal wait instead of sitting through it. Arrow-key roving
  focus across answers, `role="status"`/`aria-live` on the counter, and the
  previously-chosen answer marked when revisited.
  Changing an earlier answer keeps the later ones (`current = answers.length`):
  the questions are independent, so invalidating them would force pointless
  re-answering.
  **The twenty built-in questions are seeded into Quiz Control** by the
  migration (only when `quiz_questions` is empty), which is what lets scoring
  move server-side unconditionally. `quiz.html` keeps them as an offline
  fallback; a result played against that copy has no ids, cannot be scored or
  saved, and says so. The seed SQL was **generated from `quiz.html` rather than
  hand-transcribed** -- worth repeating for any similar seed, because the first
  attempt piped it through a shell redirect and silently mangled every em-dash
  into a cp1252 `0x97` byte. Generate and verify with explicit UTF-8.
  `content.css?v=233` / `admin.css?v=232` / `public.css?v=269` /
  `community-bundle.css?v=261` / `admin-bundle.css?v=273`.
  **Sandbox note:** this session had **no node and no PHP CLI** (past sessions
  had node). The verification checklist was run with Python stand-ins; the JS
  balance checker was calibrated against `js/worlds.js` and
  `js/known-figures.js` first, per the warning above. `admin/index.html`'s
  pre-existing checker failures (`section 33/32`, `select 29/28`, JS-built ids)
  were confirmed identical against the committed baseline before trusting them.

- **Lore Timeline with reputation-gated events** (`timeline.html`,
  `js/timeline.js?v=1`, Lore Management -> Timeline Control). New
  `timeline_events` table (**run `sql/migration_timeline.sql` once**) plus a
  flat admin CRUD mirroring Known Figures Control file for file.
  Two data-model decisions worth keeping: `date_label` is a **string, not a
  DATE** (in-world time like "Cycle 4.207" has no calendar, so nothing can
  sort or format it -- `sort_order` alone decides bar position, and the admin
  copy says so), and the gate is a **reputation level FK**, not a raw point
  total, so the existing Reputation Levels admin stays the only place tiers
  are defined. `ON DELETE SET NULL` is deliberate: removing a level must
  **unlock** its events, never seal them behind a gate nobody can satisfy.
  **The unlock check is a server-side security boundary, not a display rule.**
  `api/timeline.php` omits a locked event's title, summary, body, image *and
  slug* from the response entirely -- only its position and the required level
  name are sent. Hiding that text client-side would hand every sealed record
  to anyone opening the network tab, the same reason a locked world's record
  stays sealed server-side rather than dimmed in the atlas. The discovery
  endpoint **re-checks the gate itself** rather than trusting that: it is a
  separate entry point, so without it a crafted POST could claim the award for
  a sealed event.
  Discovery reuses the existing first-visit path (`user_lore_discoveries`
  gained a `timeline_event` enum value; the `lore_discovery` reward rule is
  unchanged) and fires on first **open** of an event, not on page load, so
  scrolling past does not silently bank the reward. Deleting an event clears
  its discovery rows explicitly -- no FK covers them, and a recycled
  AUTO_INCREMENT id would otherwise mark a future event as already discovered.
  Public page is one DOM order with two presentations: horizontal drag/scroll
  rail on desktop, vertical spine under 780px, switched in CSS alone. The
  spine gradient sequences the twelve worlds' `ATLAS_TONES` in atlas order
  (the same fixed brand sequence as the footer's top-edge strip, hardcoded so
  it renders before any fetch). Sealed markers reuse the Known Figures glitch
  motif and the atlas's `ERROR: LORE LOCK` language; newly-unlocked titles
  reuse the existing `prismatic-shift` keyframe. All loops pause via
  `IntersectionObserver` and are skipped under `prefers-reduced-motion`. This
  is a fourth deliberate use of the vendored GSAP, not a new dependency.
  Nav rollout touched the 23 pages carrying The Universe dropdown (22 existing
  + the new page) and 20 footers; the 6 `overlord-*` redirect stubs and 3
  minimal legal pages are excluded, matching the Known Figures rollout.
  `content.css?v=232` / `public.css?v=268` / `community-bundle.css?v=260`.
  **Verification note:** the JS balance checker used for this work is only
  trustworthy because it strips **regex literals** as well as strings and
  comments. An earlier version without that reported false failures on
  `js/worlds.js` and `js/known-figures.js` -- always run a new checker against
  known-good files first, or it will report noise as breakage.

- **Hide a Dispatch from the public site (Dispatch Control).** New
  `dispatch_entries.is_hidden` (`sql/migration_dispatch_visibility.sql` --
  **run it once in phpMyAdmin**) plus a checkbox in the Dispatch Control edit
  modal. Nothing is deleted: the commit record, category, approved
  translation, reactions and quality feedback all survive, so hiding is fully
  reversible and does not lose data the way "delete the translation" does.
  **Every public surface is covered, not just the feed** -- this was checked
  by grepping every consumer of `dispatch_entries` rather than assuming:
  `api/dispatches/list.php` (which also backs the `?dispatch=<id>` deep link,
  so hiding cannot be defeated by knowing the id, **and** `dev-metrics.html`,
  so a hidden dispatch drops out of public metrics too),
  `api/dispatches/react.php` (a separate endpoint that only ever received an
  id -- without its own check, a stale open page could still react), and
  `pw_composer_attached_dispatches()` in `api/admin/news/news-helpers.php`,
  which feeds the public "Related Development" sidecard on News articles.
  That last one required `news-helpers.php` to require `dispatch-helpers.php`,
  since the public article path reaches it through `api/news/get.php`.
  **A missing column is a hard SQL error, not NULL**, so `COALESCE` cannot
  make this migration-safe -- `pw_dispatch_has_visibility_column()` does a
  request-cached `SHOW COLUMNS` instead, and every read and write path falls
  back to its previous behaviour when the migration has not been run. Deploy
  order is therefore not load-bearing.
  Hiding and restoring get their own audit actions (`dispatch_hidden` /
  `dispatch_unhidden`) with labels, icons and Audit Log filter options, since
  an action that removes something the public could already read should not be
  folded into the title/category entries. The admin list shows a muted
  "Hidden" pill. The modal checkbox reuses the existing `.site-settings-toggle`
  styling rather than adding a near-duplicate rule. `admin.css?v=231` /
  `admin-bundle.css?v=272`.

- **Regenerate a published translation without deleting it first.**
  `api/admin/dispatch-translations/generate-draft.php` returned **409** when a
  dispatch already had an approved translation, so the only way to re-run the
  engine over published text was Delete Translation -> Generate Draft -- which
  removes the public explanation from `dev-dispatches.html` in between, and
  loses the row (and with it any Good/Bad feedback attached to it). That was
  the workflow behind every "regenerate it by hand" note in the entries below.
  Added an explicit **preview mode** (`preview: 1`): it calls the new
  `pw_dispatch_preview_translation_draft()`, which generates text and **writes
  nothing at all** -- no draft row, no publication, no embedding upsert, no
  audit entry. The proposed wording lands in the modal's editor while the live
  public text stays exactly as it was; only pressing Save Changes replaces it,
  through the existing save path (so the edit-similarity quality signal is
  still recorded normally). Nothing is destructive, so no confirm dialog is
  needed -- closing the modal simply discards it.
  In the admin modal the Generate/Regenerate button is no longer hidden once a
  translation exists (it is still gated on `dispatch_translations.edit`, per
  the standing permission-aware-UI convention), reads "Regenerate Draft" for
  both drafts and published rows, and the preview branch deliberately does
  **not** touch `has_translation` or reload the list, since there is no
  server-side change for either to reflect.
  Entry loading was extracted into `pw_dispatch_load_entry_for_draft()` so the
  generator and the preview cannot drift on which columns they read or on the
  unmigrated-database fallback. No draft-hash bump: engine output is
  unchanged, only the workflow around it.

- **The category now feeds the translator; spaCy entities generalize the
  proper-noun list (dispatch-draft-v34).** Audit finding: the categoriser and
  the domain classifier were **two classifiers over the same commit with
  overlapping keyword lists that shared nothing** but a last-resort
  `infrastructure -> operations` fallback, and the translator loaded only
  `id, sha, subject, body, tag` -- it never saw `category_confidence` or
  `category_source`, so an administrator's hand-corrected tag counted for
  exactly as much as a 20% keyword guess.
  - **Category as a fifth domain signal**, worth up to 40 (above a body
    mention at 20, below a subject mention at 50), **scaled by trust**:
    `manual` counts as 100, otherwise `category_confidence` is used. Only the
    four subject-matter tags map to a domain
    (`pw_dispatch_category_domain_affinity()`: lore, ui_ux, performance,
    infrastructure); feature/improvement/fix/refactor/experimental describe
    intent, not vocabulary. Deliberately not decisive -- the category is
    partly derived from the same subject and body, so treating it as
    independent proof would double-count that evidence. Verified: a subject
    keyword still beats a wrong manual tag. Reads the two columns with a
    try/catch fallback to the old column list, so a deploy landing ahead of
    the migration cannot break translation; with no metadata the contribution
    is zero and behaviour is byte-identical to before.
  - **spaCy `entities` now back the proper-noun guard**, generalizing the
    ~35-name hardcoded list to any ORG/PRODUCT/PERSON/WORK_OF_ART or acronym
    spaCy tags -- a new world or feature keeps its capital with no code
    change. Grounded in the subject, per the body-leak rule.
  - **Fixed a regression from v33:** `$shift` (near-duplicate detected) moved
    selection by one, which was harmless when variants were equal but became
    a *penalty* once pools were quality-ordered -- a near-duplicate commit
    deliberately started at the weaker line even when the best one had never
    been used. Ranked pools now ignore `$shift` and rely on the recency walk,
    which is evidence of actual reuse rather than a proxy for it.
  **Dropped after investigation:** using spaCy `phrases` (noun chunks) to trim
  implementation tails like "in first person". The chunk structure of
  "Dispatch summaries | in first person" and "a dedicated rate limit | for the
  login endpoint" is *identical*, so no syntactic rule separates a manner
  phrase from the actual target; trimming would have improved the first and
  broken the second. The `Dispatch:` trailer is the real answer for those.
  **Still unused and worth doing next:** `dispatch_category_overrides` is
  write-only -- every admin correction is a labelled example of the scorer
  being wrong, and nothing reads it. Pair it with the weekly quality report.

- **`Dispatch:` commit trailer, and quality-ordered variant pools
  (dispatch-draft-v33).** Two changes, one raising the ceiling and one the
  floor.
  1. **A `Dispatch: <sentence>` line in a commit body is now published
     verbatim** at 100% confidence, short-circuiting the entire formatter --
     no domain voice, no benefit sentence, no object phrase, nothing
     inferred. See the standing instruction near the top of this file for
     the exact format. This exists because every other part of the
     translator infers reader wording from a developer-written subject,
     which is a hard ceiling no template work can lift: "in first person"
     reached readers purely because a commit title said so. The trailer is
     the escape hatch, adopted per-commit; absent, the engine runs exactly
     as before, so it carries no risk. It passes the same safety floor as
     any generated object (no path, hash or source filename) and is
     discarded -- silently falling back to the engine -- if it fails that,
     is under 10 characters, or exceeds 400. `pw_dispatch_draft_confidence()`
     short-circuits on it too: an author-written line is not inferred, so
     scoring it against evidence weights is meaningless.
  2. **`$pickVariant()` gained a `$rankedPool` flag.** Selection was
     `crc32(subject) % count` -- uniform -- so across a two-item pool the
     better line won only half the time. That is exactly how the sharpest
     new tooling line ("This changes how updates are written, not what the
     site does.") lost to its blander alternative *on the very commit that
     introduced it*, visible in the published Dispatch. A ranked pool starts
     at index 0 and only walks forward when
     `pw_dispatch_draft_phrase_is_recent()` says that line was just used, so
     variety becomes repetition-avoidance rather than a randomiser.
     `$domainBenefits` is ranked (all 48 sentences were authored
     strongest-first); pools whose order carries no quality meaning keep
     hash distribution, deliberately, since promoting an arbitrary first
     element is not an improvement.
  Five regression cases added, including the two trailer-rejection paths
  (path/filename, too short) which must fall back rather than publish.
  **Sequencing note:** this was done *before* starting to collect Good/Bad
  ratings, on purpose -- rating a known-weak baseline would waste the first
  week of the quality report described below.

- **Benefit sentence follows commit intent; product names survive lcfirst
  (dispatch-draft-v32).** Two refinements to summary quality.
  1. **`$domainBenefits` is keyed by domain AND intent**, not domain alone
     (8 domains x addition/correction/refinement x 2 variants = 48 sentences,
     all distinct). One pool per domain meant the same hash-picked line
     described an addition, a repair and a cosmetic tidy-up alike -- true of
     every commit in that domain, so it carried no information and read as
     filler. `$mode` is already computed by
     `pw_dispatch_draft_action_mode()` from the commit's own verb, so this
     needed no new signal. The best of the new lines is tooling/refinement's
     **"This changes how updates are written, not what the site does."** --
     a genuinely useful distinction for a reader that the old pool could not
     express. `scopes` and `files_changed` remain unused for wording;
     intent was taken first as the strongest of the three, keeping the
     quality shift attributable.
  2. **`pw_dispatch_proper_nouns()`** protects single-capital product names
     from the `lcfirst()` applied to every object phrase -- "Dispatch
     summaries" had published as "dispatch summaries". The v29 acronym rule
     only catches multi-capital tokens (BH-4, CSS), so it could not see
     these. Multi-word names are matched **in full** rather than by first
     word, so a generic leading "World", "Admin" or "System" is still
     lowercased and only the real product name ("World Record", "Admin
     Console") is preserved -- verified both directions.
  Harness gained a `contains_exact` assertion: `contains`/`forbidden` both
  use `stripos`, so neither could ever have caught a capitalisation bug.
  Three regression cases added, including two commits differing only in their
  verb, each forbidding the other mode's sentence pair.

- **Lore pre-check no longer reads the commit body (dispatch-draft-v31).**
  The v30 tooling split below did not take effect on the very first commit
  that needed it: "Rewrite Dispatch summaries in first person" still published
  the worldbuilding voice, because the **named-world/lore hard pre-check** ran
  on subject + body + scope, and that commit's body contained "worldbuilding",
  "world" and "lore" while explaining that exact problem. This was the same
  subject-over-body flaw fixed for the scored domains in v27 -- in the one
  place deliberately left as a hard pre-check, and flagged at the time as a
  known remaining edge. It is now decisive from the **subject and changed-file
  scope only**; body lore cues still count, but at ordinary body weight via
  the `content` pattern, which gained the same cues. Verified: the offending
  commit now resolves to `tooling`, while "Unlock Asmecu ... district map" and
  "Add a new chapter preview for Book Two" still resolve to `content`.
  Also fixed in the same pass: **`rewrite` had no action template**, so it fell
  through to the spaCy object path and leaked the bare verb as "rewrite
  Dispatch". Added to the shared verb list and to the existing
  `redesign|rework|restructure` template together, keeping the
  opening-list-vs-template gap at zero (checked; that invariant is what makes
  a verb leak impossible).
  **Known remaining keyword ambiguity** in the lore cue, deliberately not
  fixed: `map` also matches an analytics "country map", so "Add content
  popularity, bounce rate, country map ..." resolves to `content`. Same class
  as `report` (community) and `index` (database) noted in v27.
  **Not a bug -- the webhook always runs the *previous* translator.** A
  commit that changes the translator is translated by the code deployed
  *before* it, because the GitHub webhook fires at push time while the cPanel
  deploy happens afterwards. Every translator change therefore needs its own
  Dispatch regenerated by hand after deploying. This is inherent to the
  push-then-deploy order, not a defect.

- **Dispatch voice is now first person; tooling split from content; generic
  second sentence suppressed (dispatch-draft-v30).** Three changes to how a
  generated summary reads, prompted by a published Dispatch whose second
  sentence was unrelated to the change.
  1. **First-person voice.** All 62 generated prose sentences moved from
     "BH-4 has refined..." to "We have refined...". BH-4 remains the console
     persona -- avatar, verified badge, Technical Analysis transcript label,
     and the `admin_activity_log` actor for an automatic publication are all
     untouched; only the generated prose changed. Dictionary values that
     mention BH-4 as an *object* ("a clearer BH-4 status presentation on the
     Admin Home page") are describing the BH-4 UI feature and were correctly
     left alone. **One public string still uses the old voice and was left
     deliberately** for a separate decision: `dev-dispatches.html`'s "A
     simpler explanation is not available right now. BH-4 has retained the
     original development record below."
  2. **`tooling` split out of `content`.** They shared one domain, so a
     change to the Dispatch pipeline itself got the worldbuilding voice --
     the trigger was a commit about internal confidence checks publishing
     "Readers have a clearer route into the affected part of the Pantheon
     Wars record", having added no lore at all. `content` now matches
     `story|character|quiz` (plus the unchanged named-world pre-check);
     `tooling` matches `dispatch|translation|translator|changelog|release
     notes`. Replaying the last 60 commits moved 9 into `tooling`, all
     genuinely pipeline work, with no lore commit swept in. **Constraint for
     new templates: no verb may agree with `%s`**, since the object is often
     plural ("the confidence checks behind ..." would give "... reaches
     readers").
  3. **The benefit sentence is suppressed when nothing supports it** -- an
     unclassified (`general`) domain or a low-confidence draft. It is a
     hash-selected line from a fixed pool with no relationship to the commit,
     so in those cases it padded the summary without adding a fact. A natural
     override keeps its benefit, since that text is written against specific
     recognized content. This follows the same conclusion already reached and
     commented for the `contextLibrary` benefit further up. Required moving
     the `pw_dispatch_draft_confidence()` call above the return so the level
     can gate the sentence.
  Four regression cases added. **Not done, deliberately:** deriving the
  benefit from the signals `$plan` already computes (`intent`, `scopes`,
  `files_changed`) rather than a hash, which is the real fix for "the second
  sentence feels random" -- held back so this pass's quality shift stays
  attributable to the split and the suppression alone.

- **`lcfirst()` mangled acronym-led objects (dispatch-draft-v29).** Seen live
  as "BH-4 has refined the reader-facing presentation of **bH-4**". An object
  phrase is dropped mid-sentence so it normally needs `lcfirst()`, but that
  damages any object starting with a name or acronym -- BH-4, CSS, API, SQL,
  UTC. All four object-facing call sites now go through
  `pw_dispatch_lcfirst_object()`, which leaves the value untouched when its
  first token carries a second capital, or a capital followed by a digit or
  hyphen, and otherwise behaves exactly as `lcfirst()` did. Note this was two
  bugs stacked: the object being `BH-4` *at all* came from the body-leak fixed
  in v28 below, while the `bH-4` casing is independent and would still have
  affected a legitimately acronym-led subject. Single-capital proper nouns
  (Asmecu) are deliberately still lowercased -- unchanged behaviour, and the
  world-release path has its own override that preserves them.

- **Commit body could reach public reader copy verbatim (dispatch-draft-v28).**
  Caught from a live published Dispatch immediately after the domain fix
  below: the commit *"Score Dispatch draft domains instead of first match"*
  published as "BH-4 has made the record around **expand the Dispatch** easier
  to explore." The domain was now correct (content voice), so this was a
  second, independent bug the first fix merely revealed. Chain: `Score` is not
  in the action-verb list and has **no action template**, so `$draft` stayed
  empty and fell through to the spaCy fallback; `pw_dispatch_spacy_reader_object()`
  returns the first entity or noun chunk from spaCy's analysis of **subject
  *and* body** (`pw_dispatch_spacy_analyze($subject, $body, ...)`); that
  commit's own body opened by quoting the previous commit's title, and spaCy
  labels a quoted title `WORK_OF_ART`/`PRODUCT`/`ORG`, all of which are in the
  allowed set -- so the phrase was lifted verbatim into published copy.
  **This violated a contract the file already stated** in the comment where
  `$bodyContext` is built: the body "shapes confidence only and is never
  copied verbatim into reader copy". It is also a real disclosure risk, not
  just an awkward sentence -- a commit body can hold internal notes, paths or
  quoted text, and any of it could reach the public Dispatches page.
  Two guards added. `pw_dispatch_spacy_object_is_grounded()` requires a
  spaCy-supplied object to actually appear in the subject before it may be
  used. `pw_dispatch_strip_leading_action_verb()` removes a leading verb from
  the two fallback object paths (raw cleaned title, spaCy phrase) -- the
  action-template path was already verb-free via its capture group, which is
  why this went unnoticed. It recognizes the shared static verb list **plus
  any lemma spaCy tagged as a VERB**, so it handles verbs like "score" that
  the static list never had, and degrades to the static list alone when spaCy
  is down. The 150-verb alternation was extracted into
  `pw_dispatch_action_verbs()` and is now shared with the action-opening test
  so the two cannot drift; verified byte-identical to the original (1153
  chars, regex reconstructs exactly) rather than retyped.
  Two regression cases added: the real commit (asserting the quoted body
  phrase never appears and the bare verb is stripped) and a counterpart where
  a spaCy phrase that *is* grounded in the subject must still be used, so the
  fix cannot be satisfied by ignoring spaCy's object entirely.
  **Worth knowing for future debugging:** the cause was invisible from the
  subject alone. Statically simulating the subject showed the dictionary never
  touches it and every action-opening verb has a consuming template (verified:
  zero gap), which appeared to make the observed output impossible -- the
  missing piece was that the offending text came from the *body*, via spaCy.

- **Dispatch domain selection is now scored, not a first-match cascade
  (dispatch-draft-v27).** Caught from a live published Dispatch: the commit
  *"Expand the Dispatch translation dictionary"* rendered in the **security**
  voice -- "BH-4 has reinforced the safeguards around the Dispatch translation
  dictionary. The affected account or data path now carries a more deliberate
  safeguard." Root cause was not the dictionary but
  `pw_dispatch_draft_domain()`, which returned the **first** matching domain in
  a fixed array where `security` sat earliest. Subject and body were also
  concatenated into one haystack and weighted identically, so a single
  incidental body mention of CSRF (that commit's body listed the newly added
  acronyms) outranked a subject that said "Dispatch" and "translation"
  outright. Reproduced exactly before changing anything: subject alone
  classified as `content`, subject+body as `security`.
  **This is the identical bug class already fixed once in this codebase** --
  `pw_dispatch_categorize()` was rewritten from an if/elif cascade to
  independent scoring for exactly this reason (infrastructure always beating
  performance/lore purely by running first). The domain classifier, which
  picks the reader-facing template wording, never got the same treatment.
  Fixed by mirroring that function's precedent: subject match 50,
  changed-file scope label 30, body match 20, boolean presence per domain so a
  longer keyword list cannot win on chances alone, highest score wins, ties
  falling back to the original array order so tied records resolve exactly as
  before. `pw_dispatch_draft_plan()` and `pw_dispatch_draft_domain()` now take
  the body as a separate trailing parameter (defaulted, so a 3-argument call
  keeps the old single-text behaviour). The named-world/map/book override
  stays a hard pre-check ahead of all scoring, per the standing rule that
  worldbuilding cues are decisive.
  Replaying the last 60 commits through both versions reclassified 13:
  eleven clear corrections (member warning system security -> community,
  GitHub webhook interface -> operations, Apple sign-in button database ->
  interface, and so on), and two that merely trade one wrong answer for
  another because of **keyword ambiguity, not ordering**: `report` in the
  community list also catches "quality report", and `index` in the database
  list also catches "the public index" page. Those two lists are deliberately
  left alone -- re-curating them is a separate change with its own regression
  surface. Two regression cases added (the real misclassified commit, plus a
  genuine security subject that must still resolve to `security`, so the fix
  cannot be satisfied by just demoting that domain) along with a new
  `plan_domain` assertion type in the harness.
  **Already-published translations are not rewritten by a hash bump**, so the
  one Dispatch that shipped in the wrong voice needs a manual
  regenerate/edit in Admin -> Dispatch Translations.

- **Translation-engine audit + dictionary expansion (dispatch-draft-v26).**
  A frequency audit of all 626 commit subjects against the live dictionary
  found that 41 of the last 50 commits matched no entry at all, and that
  several recurring words reached readers verbatim through `$object` (line
  ~578, `$object = lcfirst($clean)`): **modal appeared in 13 commit subjects,
  dropdown in 12, tooltip in 9, embedding in 9 (all recent), stale in 6,
  OAuth in 4, viewport in 4, backfill in 4** -- none of which had any entry.
  Added 21 entries in four documented groups (interface surfaces, sign-in and
  safeguard acronyms, translation-pipeline vocabulary, and recent operational
  jargon). After the change, zero jargon leaks remain across the last 50
  commit subjects. Two conventions are now written down in
  `docs/dispatch-spacy.md`, both learned the hard way here: replacements are
  **article-free** (`pop-up panel`, so "the modal" -> "the pop-up panel" and
  not "the a pop-up panel" -- the pre-existing `'/\bstubs?\b/i' => 'a
  placeholder'` entry still has this bug, as does `'/\bflaky\b/i'`, which
  renders "a flaky test" as "a inconsistent test"), and patterns must match
  the **de-hyphenated** form because the letter-hyphen-letter rule at line
  ~132 runs before the dictionary (`sentence-embedding` arrives as `sentence
  embedding`, `proc_open` as `proc open`).
  **Two real engine bugs found and fixed along the way:**
  1. **Nine dictionary entries were unreachable duplicate array keys.** PHP
     array literals silently overwrite on a duplicate string key, keeping the
     *first* position but the *last* value -- so the live dictionary was 184
     entries, not the 193 written, and four entries' earlier wording (e.g.
     "a more polished forum presentation") had been dead for a long time
     while the later wording silently won. De-duplicated by moving the
     surviving (live) value onto the first occurrence's position, so runtime
     behaviour is byte-identical.
  2. **The dictionary could auto-publish a jargon-heavy commit with no
     review.** `$rulesMatched` was incremented once *per matched entry*, and
     `pw_dispatch_draft_confidence()` treats `$rulesMatched >= 2` as enough on
     its own to force a 65% score *and* satisfy the high-confidence gate.
     Whole-title entries rarely stack, but word-level swaps do: "Replace
     persistent embeddings service with one-shot proc_open worker (fixes
     OOM-killing spaCy)" would have matched three new entries and
     auto-published on vocabulary alone. The dictionary now counts as **one**
     formatter rule regardless of how many terms it rewrites, matching the
     `reader_safe_dictionary` evidence flag which was already a single
     boolean. This only ever makes auto-publication stricter, never looser.
  Four regression cases added to `tools/test-dispatch-translator.php`,
  including one asserting that a three-term subject with no other signal
  stays **medium** confidence -- that case fails if fix 2 is ever reverted.
  Draft hash bumped `v25` -> `v26` so unapproved local drafts regenerate;
  published translations are untouched. No SQL migration, no asset version
  bump (PHP/docs only). Verified by static simulation of the pre-processing
  and dictionary loop against real commit history -- **this sandbox has no
  PHP CLI**, so `php tools/test-dispatch-translator.php` still needs to be
  run once on the server to confirm.

- **Member Warning System, with an optional temporary mute.** Staff with the
  right role can now issue a warning (reason + minor/moderate/severe
  severity) against a member, from either a new **Community -> Warnings**
  admin page or a small **Warn** icon that appears next to the Report icon
  on every forum post/reply and News comment (never on your own content).
  One icon serves both "issue" and "see" per the original request: it opens
  a compact modal with a view section (that member's active warnings, shown
  only with `warnings.view`) and an issue section (shown only with
  `warnings.manage`). New `member_warnings` table
  (`sql/migration_member_warnings.sql`) with `status='active'/'revoked'` --
  revoking keeps the row for the audit trail; only the separate
  `warnings.delete` permission permanently removes one, mirroring Topic
  Reports' resolve-vs-delete split rather than the ban toggle's single-state
  model. Three new permissions: `warnings.view` / `warnings.manage` /
  `warnings.delete`.
  **Mute, bundled into the same Issue Warning flow:** a fixed duration (1
  hour / 12 hours / 1 day / 3 days / 1 week, chosen by staff judgment --
  the mute-duration `<select>`'s initial highlight nudges toward the chosen
  severity as a suggestion only, never a hard rule) blocks new forum
  topics/replies and News comments outright
  (`pw_require_not_muted()` in `api/helpers.php`, one added line in
  `api/topics/create.php` / `api/comments/post.php` /
  `api/news/comments/post.php`) and blocks direct messages to anyone except
  staff (`api/direct-messages/send.php` -- reuses the existing
  `pw_is_staff_messenger()` recipient check already used there for banned
  senders). Mute state lives on `users.muted_until`/`mute_reason`,
  deliberately mirroring `banned_at`/`banned_until`'s simplicity (no
  "permanent" option, just NULL-or-past means not muted) rather than a
  separate table, since every authenticated request's already-loaded
  `$user` row can then check it with zero extra queries. Unlike a ban, a
  mute never touches the session -- a muted member stays fully logged in
  and can keep browsing/reading, only specific write actions are rejected
  with a clear reason + expiry message.
  New endpoints: `api/admin/warnings/{list,issue,revoke,delete}.php` and
  `api/admin/members/unmute.php`. The Issue Warning modal's member picker
  reuses the existing `api/direct-messages/member-search.php` rather than a
  new search endpoint. `api/admin/members/list.php` gained
  `active_warning_count`/`muted`/`muted_until` per row, resolved server-side
  to `null` for a viewer without `warnings.view` (same pattern as
  `dashboards.view_ip_addresses` gating `last_login_ip`) -- the Members list
  gets a warning-count pill and a Muted-until pill, and the Member edit
  modal gets a Warnings status line, View All / Issue Warning buttons, and
  an Unmute Now button, all following the standing permission-aware-UI
  convention (built only when the permission holds, never hidden-but-present).
  The warned member is notified via a new `warning_issued` notification type
  (reason + severity + mute expiry if any, but never the issuer's identity --
  matching the anonymous topic-report-resolution precedent), which touched
  every file that convention requires: the ENUM/preference-column migration,
  `pw_notifications_enabled()`, both `notification-prefs` endpoints, both
  `notifications/{stats,list}.php`, a new Notification Settings toggle in
  `profile.html`, and hand-duplicated icon/link/text entries in
  `js/notifications.js` and `notifications.html` (plus its filter chip).
  Every action (`warning_issued`/`warning_revoked`/`warning_deleted`/
  `member_muted`/`member_unmuted`) is audited via `pw_log_admin_activity()`
  with matching Audit Log labels/icons/filter options. The per-post Warn
  icon and its modal are hand-duplicated between `community.html` (forum
  posts/replies) and `js/news-post.js`+`news-post.html` (News comments), per
  this codebase's established no-shared-JS-module convention -- both needed
  a new `authorUserId` field added to the message-actions `ctx` object
  (previously only `authorName` was passed, insufficient to target a
  warning). Run `sql/migration_member_warnings.sql` once in phpMyAdmin after
  deploy; it also adds the `notif_warning_issued` column and
  `users.muted_until`/`mute_reason`.

- **Visitor Statistics: content popularity, bounce/session-depth, a real
  country map, and new-vs-returning visitors.** Four additions to the
  admin Visitor Statistics page, each its own new endpoint under
  `api/admin/visitor-stats/`:
  - **Content Popularity** (`content-popularity.php`): which specific
    World/Book/Overlord a visitor actually looked at, not just the shared
    template page (`world.html`, `overlord.html`, `chapter-one.html`).
    Required a new nullable `page_views.query_string` column
    (`sql/migration_visitor_stats_content_tracking.sql`) -- the existing
    `path` column stays pathname-only on purpose (Top Pages, journeys, and
    the heatmap all group by it already; changing its meaning would
    fragment those). `js/main.js` now also sends `location.search`;
    `api/track-visit.php` stores it with a try/catch fallback to the old
    INSERT shape if the migration hasn't run yet. Only visits recorded
    after this shipped are counted -- there is no historical backfill,
    since the query string was never captured before.
  - **Bounce Rate & Session Depth** (`session-depth.php`): a deliberately
    simplified per-UTC-day proxy for true timeout-based sessionization
    (which this codebase has no session-boundary concept for outside of
    login sessions) -- a "session" here is one visitor's page views within
    a single UTC day. Bounce rate is the share of visitor-days with
    exactly one page view; session depth is the average views per
    visitor-day. Computed live from raw `page_views`, not a cron rollup;
    flagged as a candidate for a `page_view_daily_stats`-style rollup if
    it proves slow at scale, same as any other direct query in this
    codebase that started this way.
  - **Country map** (`country-map.php` + vendored
    `images/vendor/world-map.svg`): the existing "Traffic by Country"
    ranked list gained a List/Map toggle. The map is
    `flekschas/simple-world-map` (CC BY-SA 3.0, attribution in
    `images/vendor/README.md`), fetched once and injected inline (not an
    `<img>`) so individual `<path id="xx">` country shapes -- ISO 3166-1
    alpha-2, lowercase -- can be recolored by visit volume and hover-
    tooltipped. `country-map.php` is a separate endpoint from
    `top-countries.php` (same data, no `LIMIT 10`) so the ranked list's
    existing top-10 contract never changes.
  - **New vs Returning Visitors** (`new-vs-returning.php`): a visitor is
    "new" on a given day only if they have no `page_views` row before that
    day anywhere in history, not just within the chart's own window -- a
    visitor who first appeared months ago and returns today is still
    "returning" even if that first visit predates the queried range.
    Rendered as a stat-pair plus a stacked daily bar, reusing the same
    hand-rolled div-based stacked-bar technique as `dev-metrics.html`'s
    language-history chart -- still no chart library anywhere in this
    codebase.
  Run `sql/migration_visitor_stats_content_tracking.sql` for the Content
  Popularity card to start collecting data; the other three work
  immediately against existing `page_views` columns. `admin.css?v=229` /
  `admin-bundle.css?v=270`, `js/main.js?v=12` (all 25 public-page
  references bumped).

- **Weekly self-tuning quality report, built on the feedback below:**
  `api/dispatch-quality-report.php`'s `pw_dispatch_generate_quality_report()`
  reads the past week's `dispatch_translation_feedback`/
  `dispatch_translation_edit_events` and produces an advisory summary --
  overall good/bad ratio and average edit-similarity, a per-category
  bad-rate breakdown, a per-confidence-evidence bad-rate breakdown (cheaply
  recomputed via `pw_dispatch_end_user_draft()` with stored diff-context but
  deliberately *without* re-calling the spaCy or embedding worker, so
  `semantic_context` is undercounted here -- an accepted limitation, not a
  live-translator bug), and "weak clusters" of Bad-rated translations that
  are semantically similar to each other (threshold-based connected
  components over the already-cached embedding vectors, reusing
  `pw_dispatch_cosine_similarity()` -- not a general ML clustering
  algorithm). New weekly cron job
  (`api/cron/generate-quality-report.php`, see "Cron jobs" above) plus a
  manual "Generate Report Now" button for on-demand runs
  (`api/admin/quality-reports/generate-now.php`). New Admin -> Development
  Dispatches -> **Translation Quality** page renders the latest report
  (stat cards, two tables, a cluster list) and a history list to page back
  through prior weeks; "Mark Reviewed" just tracks that a human looked at
  it. Deliberately advisory-only for everything except one thing: nothing
  here can safely rewrite the translator's hardcoded PHP weights/thresholds/
  dictionary automatically (they're source code, not a database setting),
  so this report's job is to give a human the data to decide whether a code
  change is warranted -- there is no "Apply" button for those. The one
  genuinely automatic, safe-by-construction rule already ships unconditionally
  and needed no report: `pw_dispatch_nearest_embedding_match()` in
  `api/dispatch-embeddings.php` now excludes any dispatch rated more Bad
  than Good from ever being recommended as a "similar past Dispatch"
  reference again, with its own independent fail-open fallback (matching
  without the exclusion) if the feedback migration isn't applied yet but the
  embeddings migration is. Run `sql/migration_dispatch_quality_reports.sql`
  after the feedback migration below.

- **Dispatch Translation quality feedback (two purely observational
  signals, neither read by the translator or the auto-publish gate):**
  `sql/migration_dispatch_translation_quality.sql` adds
  `dispatch_translation_feedback` (an explicit Good/Bad rating an admin can
  leave on any published translation -- one row per dispatch+rater, upsert
  on re-rating, clicking the same rating again removes your own vote) and
  `dispatch_translation_edit_events` (an automatic log of how much an
  approved translation differs from whatever the engine originally
  suggested, computed with PHP's built-in `similar_text()` -- 100% for an
  auto-published translation since by definition nothing was edited, a real
  percentage for a manual save compared against the previously published
  text or the queued rule-based draft, and `NULL` when there was nothing to
  compare against). New `api/admin/dispatch-translations/rate.php` (gated by
  the existing `dispatch_translations.edit` permission, no new permission
  key needed) and a new `pw_dispatch_log_translation_edit_event()` helper in
  `api/dispatch-translation-drafts.php`, called from both
  `pw_create_dispatch_translation_draft()`'s auto-publish path and
  `api/admin/dispatch-translations/save.php`. Admin UI: Good/Bad buttons
  with a live tally in the Dispatch Translations review modal, shown only
  once a translation is actually published (there's nothing reader-facing
  to judge before that) -- `admin.css?v=227` / `admin-bundle.css?v=268`.
  Deliberately staged: this pass only captures the two signals; there is no
  dashboard/aggregation view yet, matching this project's usual "capture
  first, analyze later" rollout pattern (see the embeddings feature above).

- **Developer-slang glossary added to the reader-safe terminology
  dictionary** (`api/dispatch-translation-drafts.php`): ~28 new entries for
  general software-engineering jargon (hotfix, WIP, tech debt, boilerplate,
  spaghetti code, race condition, flaky, regression, rollback, and similar)
  in the same `$replacements` array that already mixed this project's own
  historical commit titles with generic short-word swaps (CSS, JavaScript,
  N+1 queries, etc.) -- no new mechanism needed, just more entries in the
  existing one. Conventional Commits prefixes (`refactor:`, `chore:`,
  `revert`, etc.) already had their own dedicated commit-intent handling
  and are deliberately not duplicated here. Each entry earns the same
  10-point `reader_safe_dictionary` confidence evidence as any other match.
  Two new regression cases added to `tools/test-dispatch-translator.php`
  confirm the raw jargon never leaks into reader-facing prose. Documented in
  `docs/dispatch-spacy.md`'s "Reader-safe terminology dictionary" section.

- **Sentence-embedding semantic similarity for Dispatch Translations
  (planned via EnterPlanMode, then implemented):** a second, independent
  local NLP capability alongside the existing spaCy/RapidFuzz worker --
  full setup and architecture rationale in the new `docs/dispatch-
  embeddings.md`. Motivated by a real weak auto-generated Dispatch ("BH-4
  has made the community experience around darken Saga Complete's easier
  to follow") that nothing in the dictionary/domain-regex/RapidFuzz-alias
  system recognized, because those all need literal word overlap and this
  commit shared none with any prior similar change. **Architecture
  decision:** a *persistent* Flask service
  (`tools/dispatch_embeddings_service.py`, `all-MiniLM-L6-v2`, loaded once
  at process start via a **second, separate** cPanel "Setup Python App" /
  Passenger instance) rather than extending the existing one-shot
  `proc_open`-per-call pattern -- `import torch` alone commonly costs
  1-3 seconds, which would routinely blow through
  `api/dispatch-spacy.php`'s existing hardcoded 6-second budget on every
  single draft generation rather than occasionally. `api/dispatch-
  embeddings.php` is the new bridge (curl over loopback HTTP, same
  fail-open/`$unavailable`-latch shape as `pw_dispatch_spacy_analyze()`),
  gated by a new `DISPATCH_EMBEDDING_SERVICE_URL` secrets constant.
  **Embedding cache is PHP-owned, not Python-resident:** the Flask service's
  only job is `POST /encode {text} -> {embedding}` -- it never touches
  MySQL or sees a corpus. PHP stores one embedding per approved translation
  in the new `dispatch_translation_embeddings` table
  (`migration_dispatch_translation_embeddings.sql`, upserted by
  `pw_dispatch_update_translation_embedding()` at publish/edit time in both
  `api/admin/dispatch-translations/save.php` and the auto-publish path in
  `pw_create_dispatch_translation_draft()`) and computes cosine similarity
  itself (`pw_dispatch_cosine_similarity()`/
  `pw_dispatch_nearest_embedding_match()`, plain PHP, no dependency). Only
  the one new incoming commit is ever sent to the service per draft --
  a strictly *tighter* version of the pre-existing "raw prior translations
  never leave the PHP/Python boundary" guarantee than the status quo
  (`nearest_translation_similarity()` already ships up to 8 full past-
  translation snippets into the spaCy worker per call for a much weaker
  signal). **Deliberately staged scope** (a bigger, riskier "template
  replay" stage that could let retrieval steer auto-published wording was
  designed and explicitly deferred, not built): Stage 1 folds match
  strength into the existing `semantic_context` confidence-evidence slot in
  `pw_dispatch_draft_confidence()` (still capped at its existing 5-point
  weight this pass -- only the underlying signal changed, so any quality
  shift is attributable to one change at a time) via a new `>= 0.75`
  threshold OR'd alongside spaCy's existing static-vector domain check;
  Stage 2 surfaces the single best match (score + subject + text) as a
  read-only "Similar past Dispatch" reference panel in the Dispatch
  Translations review modal (`admin/index.html`, new `#admin-semantic-
  match` block, copy button reusing the existing `wireCopyCommitButton()`
  pattern) for a human editor to adapt manually -- it never changes draft
  text automatically. System Status gets a new "Embedding Service"
  Connected/Disconnected row, deliberately *not* wired into BH-4's
  critical-directive escalation the way spaCy is (`task-advisor-
  helpers.php`'s `$criticalPriority` list) since this signal is additive
  and never load-bearing for publication. `tools/backfill-dispatch-
  embeddings.php` is the one-off CLI backfill for translations approved
  before this shipped (safe to re-run; re-verifies each write actually
  landed rather than trusting the call silently). `tools/test-dispatch-
  translator.php` gained two new cases using a synthetic
  `options['embedding_match']` (no live service needed, matching the
  existing `spacy_analysis['fuzzy_concept']` test pattern) plus a new
  `forbidden_evidence`/`best_semantic_match` assertion type in the harness
  itself. `admin.css?v=226` / `admin-bundle.css?v=267`.
  **Corrected during actual cPanel setup:** unlike a self-managed server,
  "Setup Python App" always routes a Python app through a real URL path on
  the account's own domain -- there is no raw `127.0.0.1:<port>` loopback
  option the way the original design assumed. Added
  `DISPATCH_EMBEDDING_SERVICE_KEY`, a shared secret sent as the
  `X-Dispatch-Key` header on every request and checked by
  `tools/dispatch_embeddings_service.py` (renamed from
  `dispatch-embeddings-service.py` -- Python can't import a hyphenated
  module name, which matters once `passenger_wsgi.py` has to import it by
  name), so the endpoint can't be used by anyone who merely finds its URL.
  `docs/dispatch-embeddings.md`'s setup steps now match the real "Setup
  Python App" form fields exactly (Application root / URL / startup file /
  entry point / environment variable), not a generic description.

  **Reversed again after real production evidence (persistent service was
  wrong, not just its URL model):** once actually running, the persistent
  Flask/Passenger app sat at ~852MB resident memory on its own
  (`ps -u rdy3i6my40b0 --sort=-rss`), on an account with roughly 1.5GB total
  RAM -- and every live translation that invoked it immediately got the
  much-lighter spaCy Passenger worker OOM-killed (confirmed repeatedly:
  restart spaCy, it works, trigger one live translation, spaCy disconnects).
  Reverted the whole persistent-service architecture: `tools/
  dispatch_embeddings_service.py` (Flask) was replaced by
  `tools/dispatch-embeddings.py`, a one-shot script invoked via `proc_open`
  from `api/dispatch-embeddings.php`, following `api/dispatch-spacy.php`'s
  exact existing pattern (fresh process per call, ~10s budget, model loaded
  and released every single call). This trades a few extra seconds of
  `import torch` latency per Dispatch draft generation -- the same tradeoff
  the spaCy worker already makes -- for zero resident memory between calls,
  so it can never starve spaCy (or anything else on the account) again. The
  one-shot script also pins `OMP_NUM_THREADS`/`MKL_NUM_THREADS`/
  `torch.set_num_threads(1)` to keep its brief footprint minimal on the
  account's 2 allocated vCPUs. `DISPATCH_EMBEDDING_SERVICE_URL`/`_KEY` (the
  HTTP/shared-secret scheme, no longer relevant since this never listens on
  anything now) were replaced by a single `DISPATCH_EMBEDDING_PYTHON_BIN`
  venv-interpreter constant, matching `SPACY_PYTHON_BIN`'s existing shape.
  The already-created `dispatch-embeddings-app` venv is kept and reused
  (only the always-on Passenger app itself needs to be stopped/deleted in
  cPanel); no new SQL migration, no change to the PHP-owned embedding cache
  design, and no change to `pw_dispatch_cosine_similarity()`/
  `pw_dispatch_nearest_embedding_match()`. `docs/dispatch-embeddings.md` was
  rewritten again to document this and to explain why a persistent service
  is the wrong shape for this specific 1.5GB-RAM account, even though it
  remains the right shape for the model-load-cost reasoning that motivated
  it in the first place -- the constraint that actually decided it was
  memory, not the earlier-assumed timeout budget.

- **Fixed: Saga Complete's rainbow filled the whole pill, not just the
  ring.** Confirmed live (screenshot) right after the gradient-border fix
  below shipped: the `padding-box`-clipped fill layer's alpha
  (`rgba(224,178,74,0.14)`/`rgba(176,95,255,0.12)`) was tuned for a flat
  *tint over the page background*, not for masking an opaque layer sitting
  directly behind it in the same element -- at ~12-14% opacity it barely
  dimmed the border-box rainbow layer, so the rainbow dominated the entire
  interior instead of staying confined to the 2px border ring, and the
  label/title text became hard to read against it. Raised that fill layer
  to ~94-96% opacity (`rgba(38,24,58,0.94)` idle / `rgba(48,30,70,0.96)`
  hover) so it actually masks the center; the rainbow now only shows
  through the ring where the fill layer doesn't extend. `community.css?v=207`
  / `community-bundle.css?v=258` / `admin-bundle.css?v=266`.

- **Fixed: Saga Complete's rotating ring escaped its pill.** Confirmed live
  (screenshot) immediately after the entry below shipped: the `::before`
  ring, absolutely positioned at `inset: -3px` behind
  `.member-current-reading.is-saga-complete`, rendered as a huge rotating
  band cutting diagonally across the entire page -- avatar, achievement
  showcase, and Overlord Affinity card all included -- rather than a thin
  ring around the small pill. `position: relative` on the pill should have
  scoped the pseudo-element's containing block correctly, but evidently
  didn't resolve that way in practice; rather than chase the exact cause
  further, replaced it with a gradient-border technique instead (two
  `background` layers -- one clipped to `padding-box` for the pill's own
  fill, one to `border-box` carrying the rainbow gradient -- animated via
  the same `background-position`-shifting `prismatic-shift` keyframe the
  label already uses, rather than `transform: rotate()` on a pseudo-
  element). A background layer is structurally incapable of escaping its
  own element's box the way an absolutely-positioned one can, so this
  closes off the whole failure mode rather than just this one instance of
  it. The pulsing gem glyph (arrow-slot swap) was unaffected and unchanged.
  `community.css?v=206` / `community-bundle.css?v=257` /
  `admin-bundle.css?v=265`.

- **Saga Complete badge: rotating ring + pulsing gem glyph.** Two follow-up
  effects on the reading-completion badge below, picked from a shortlist of
  five. **Rotating rainbow ring**: `.member-current-reading.is-saga-complete`
  gets a `position: relative` + `::before` at `inset: -3px` with a spinning
  `conic-gradient` (blurred 2px) sitting behind the pill at `z-index: -1` --
  the pill's own background covers the center, leaving only a thin glowing
  ring visible at the edges, rather than a flat animated border. **Pulsing
  gem glyph**: rather than adding a fourth grid column, the existing arrow
  slot (`#member-current-reading-arrow`) is reused -- JS swaps its character
  to `◆` (the same diamond already used as the `saga_finisher` reputation
  achievement's own icon in `pw_reputation_achievement_catalog()`) only in
  this state, and `prismatic-glyph-pulse` cycles its color/glow/scale
  through the identical six rainbow stops as the ring and the label's
  existing `prismatic-shift` text gradient, so all three effects read as
  one consistent rainbow language rather than three different color sets.
  `community.css?v=205` / `community-bundle.css?v=256` (and
  `admin-bundle.css?v=264` for its unreachable-from-admin `community.css`
  import, same reasoning as the entry below).

- **Reading-completion profile badge:** the reputation-achievement system
  already had exactly the "finished a book" / "finished all 14" milestones
  (`seven_books_finished`, `saga_finisher` -- prismatic tier -- in
  `pw_reputation_achievement_catalog()`, `api/helpers.php`), computed live
  off `user_book_progress.finished_at` and unlocked automatically the first
  time a book is marked finished (`books.html`'s per-book "Mark finished"
  button -> `api/reading-progress/update.php` -> `pw_award_reputation()` ->
  `pw_evaluate_reputation_achievements()`). What was missing was any
  *automatic* surfacing of that on the public profile: the Achievement
  Showcase on `member.html` is deliberately opt-in (a member must visit
  Profile Settings and manually pin an unlocked achievement before it shows
  up), and the "Currently Reading" pill simply went `hidden` once nothing
  had `status = 'reading'` -- exactly what happens once every book is marked
  finished, leaving a member who just completed the whole saga with a blank
  gap instead of any acknowledgment. Fixed by extending that *same* pill
  (always-automatic, unlike the showcase) rather than the showcase system:
  `api/members/get-public-profile.php` now also returns
  `last_finished_book` (most recent `finished_at`), `books_finished_count`,
  and `books_total` (a live `COUNT(*) FROM books`, not the achievement
  catalog's hardcoded `14`, so this stays correct if a 15th book is ever
  added even though the achievement's own literal target would not).
  `member.html`'s reading-pill JS now falls through: currently reading (
  unchanged) -> all books finished ("Saga Complete", `.is-saga-complete`) ->
  most recently finished book ("Finished Reading: Book N") -> hidden (only
  if the member has never started anything). The Saga Complete state reuses
  the existing `prismatic-shift` keyframe (already defined in
  `css/components.css` for the Nexus Veil card's rainbow text) for the
  label, rather than inventing a new special-effect language for the top
  achievement tier. Needed no new table/migration -- both new aggregate
  values are plain queries against the already-migrated
  `user_book_progress`/`books` tables. `community.css?v=204` /
  `community-bundle.css?v=255` (and `admin-bundle.css?v=263`, which also
  imports `community.css` even though nothing changed here is reachable
  from any admin markup).

- **Public-site forms polish** (the visitor/member-facing counterpart to the
  admin console pass below): same three ingredients, applied where the
  public site actually needed them rather than mechanically everywhere.
  **Focus/hover**: the login/register modal's `.auth-field input` already
  had a strong focus glow (`box-shadow` + border tint) from an earlier
  build -- only missing `:hover`, added here -- but half a dozen other
  public/community fields had the same weak `outline: none; border-color`
  pattern the admin pass fixed: `.community-form textarea` (forum
  composer), `.reply-form-wrap textarea`, `.inline-edit-title`/
  `.inline-edit-form textarea` (moderator inline edit), `.forum-search-form
  input[search]`, `.profile-reading-control select`, `.dispatch-search
  input`, and (`css/content.css`, reused by `privacy-request.html`)
  `.privacy-form-card .admin-field textarea/select` and
  `.privacy-request-status`/`-resolution`. **Loading state**: `.btn.is-busy`
  (identical CSS to `admin.css`'s copy) was added to `css/content.css`
  instead of a shared file, specifically because content.css is imported by
  `public.css`/`community-bundle.css` but never `admin-bundle.css` -- avoids
  a redundant duplicate definition landing in the admin console. Wired into
  the login/2FA/register submit buttons in `js/members.js`, `js/messages.js`'s
  message-send button, `js/news-post.js`'s and `community.html`'s report-
  submit buttons, `privacy-request.html`'s submit, and three spots in
  `profile.html` (Now Reading save, all three two-factor setup/confirm/
  disable buttons) -- deliberately *not* added to buttons that already had
  their own working text-swap loading pattern (`password-reset.html`,
  `community.html`'s image-upload button, `news-post.js`'s comment-submit),
  since a second competing loading signal would be redundant, not an
  improvement. One real, previously-unprotected gap found and fixed along
  the way: `profile.html`'s Change Password submit (`passwordSubmit`, a
  `var` already declared but never actually used before this) had *no*
  disable/loading guard at all, meaning a fast double-click could double-
  submit a password change. **Inline validation**: investigated whether the
  login modal's `.auth-field.is-valid`/`.is-invalid` classes (with a
  `setFieldState()`/`updateFieldState()` pair in `js/members.js`, wired to
  `input`/`blur` on every required field) were real or vestigial -- they're
  real and already fully wired, just not literally named the way an initial
  grep for `classList.add('is-valid')` expected (it's built as `'is-' +
  state`). `password-reset.html` reuses the same `.auth-field` CSS classes
  but, being a standalone page outside that modal's closure, never had the
  matching JS -- hand-duplicated an equivalent `setFieldState()`/
  `updateFieldState()` pair directly in its own inline script (this
  codebase's established no-shared-module convention), plus a client-side
  password-match check before the confirm-password field ever reaches the
  server. Touched CSS: `components.css?v=209`, `community.css?v=203`,
  `content.css?v=230` (`public.css?v=266`, `community-bundle.css?v=254`,
  and `admin-bundle.css?v=262` for its `community.css` import). Touched JS:
  `members.js?v=39`, `messages.js?v=6`, `news-post.js?v=13`.

- **Admin Console forms polish** (focus/hover, loading state, inline
  validation): three additions to `css/admin.css` and `admin/index.html`,
  all reusing existing conventions rather than introducing new ones.
  **Stronger focus/hover on form controls**: `.admin-field input[type="text"/
  "email"/"number"/"password"/"search"/"datetime-local"], select, textarea`
  (plus the toolbar `.admin-search`) gained a `:hover` border tint and a
  `:focus` box-shadow glow on top of the existing border-color change --
  previously `:focus { outline: none; border-color: ...; }` alone, which is
  weaker than it looks: the sitewide `:focus-visible` ring in `base.css`
  (`!important`) still applied underneath for keyboard focus, but there was
  no visible hover affordance at all and the focus treatment was
  inconsistent in strength across field types. **Button loading state**:
  every one of the ~55 Save/Delete/Assign/Reset/Upload/Resync/Confirm
  buttons already disables itself before a fetch and re-enables after
  (an existing, established pattern -- 137 call sites found via `grep`),
  so rather than inventing a new pattern, a mechanical Python-regex pass
  added `.classList.add('is-busy')` / `.classList.remove('is-busy')`
  alongside every literal `X.disabled = true/false;` line for `\w*Btn`-named
  variables, with `.btn.is-busy` in CSS drawing a small `::before` spinner
  (border-top-color: currentColor, so it tints to match each button's own
  text color) without ever touching button text content -- no button's
  label needs to be stored/restored in JS. That mechanical pass alone
  wasn't complete: several re-enable paths use an inline one-liner
  (`.finally(function () { x.disabled = false; })`) or a dynamic expression
  re-evaluating `disabled` from current state/permissions
  (`x.disabled = !pwHasPermission(...)`, or `x.disabled =
  selectedCount() === 0`) rather than a bare literal, which the first
  regex pass couldn't match -- a second pass plus manual fixes found and
  fixed 18 such spots (`bookSaveBtn`'s edit-load path, `mailSendTestBtn`,
  `mailTemplateSendTestBtn`, `memberLookupAssignBtn`, `memberRemoveBtn`,
  `addRolesSubmitBtn`'s shared `updateAddRolesSubmitState()`, and 11 more
  inline `.finally()/.catch()` cases) that would otherwise have left a
  button showing a permanent stuck spinner after its very first use. Cross-
  checked afterward via a per-variable add/remove count script -- zero
  `is-busy` adds remain without a matching remove anywhere in the file.
  **Reusable inline field validation**: `pwSetFieldError()` /
  `pwClearFieldError()` / `pwWireFieldValidation()` (new, near `escapeHtml()`
  since every modal in this one file already shares helpers like it) toggle
  a `.has-error` class on the `.admin-field` wrapper plus an injected
  `.admin-field-error` message line, wired on blur (and live thereafter) via
  a generic `validator(value) -> errorString` callback. Deliberately a
  sibling of `.admin-field-hint` rather than replacing it, so a field can
  show both a hint and a validation error at once. Demonstrated on Book
  Control (Title, Status label) and Members (Display name, Email format) --
  the pattern is generic and ready to adopt on any other modal's required
  fields without duplicating the wiring, but wasn't mechanically retrofitted
  onto all ~20 admin modals in this pass.

- **GitHub webhook repository validation:** `api/github-webhook.php` now
  checks the payload's `repository.full_name` against a configurable
  expected value immediately after HMAC signature verification and before
  any payload processing (decoding the body once, right there, instead of
  the second time further down where it used to happen) -- a valid
  `X-Hub-Signature-256` only proves the caller knows `GITHUB_WEBHOOK_SECRET`,
  not that the delivery actually came from this project's own repo; the
  same secret could end up reused on another repo's webhook config by
  accident. Mismatches respond 403 and are recorded via
  `pw_log_activity('webhook_repository_rejected', ...)` into the same
  `admin_activity_log` every other security block already uses (visible in
  Audit Log under the new "Webhook rejected (wrong repository)" filter, in
  the Dispatches optgroup next to "Force re-synced from GitHub" since
  that's the feature this webhook feeds). Configurable via `GITHUB_REPOSITORY`
  in the outside-webroot secrets config (see `api/config.sample.php`), but
  defaults to `simmeh024/thepantheonwars` when unset so existing production
  webhooks keep working with zero config changes required. This also pulled
  `api/github-webhook.php` into requiring `api/helpers.php` (previously it
  only required `db.php` directly) to get `pw_log_activity()`/
  `pw_client_ip()` -- matching helpers.php's own header comment that every
  `api/*.php` entry point should require it; no behavior change for the
  file's existing header-setting or session logic since the webhook never
  carries a session cookie.

- **Login endpoint rate limit:** a new `login_rate_limit_hits` table
  (`sql/migration_login_rate_limit.sql`) plus `pw_login_endpoint_rate_limited()`
  in `api/helpers.php` add a tighter, separate layer in front of `api/login.php`
  -- 10 requests per IP per rolling 60 seconds, checked before the empty-field
  validation and before any password hash comparison. This is deliberately
  distinct from the two throttles already in that file: the 20-failures-in-15-
  minutes IP check and the 6-failures/5-minute per-account lockout both only
  log a row (`login_attempts`) once identifier/password have already passed
  basic validation as a real credential attempt, so a flood of malformed or
  empty POSTs against the endpoint itself would never trip either one. The new
  check inserts one hit row per request unconditionally (valid or not) and is
  checked first, before those other two. It is deliberately not wired through
  `pw_log_activity()` -- an automated burst can trip it dozens of times a
  minute, which would flood the admin audit log the way the existing
  `login_ip_blocked` entries never do (those are comparatively rare); the hit
  table itself is the record. Run
  `sql/migration_login_rate_limit.sql` once in phpMyAdmin after deployment.

- **Admin Console typography and whitespace polish** (`css/admin.css`, CSS-only,
  no markup changes): a real bug was found while auditing spacing rather than
  just eyeballing it -- a bare `.admin-field` (the wrapper div around every
  label+input/select/textarea in every CRUD modal across the whole console)
  had no `margin-bottom` defined anywhere in the cascade. `.admin-field-row
  .admin-field { margin-bottom: 0; }` (for side-by-side fields) and
  `.weather-boundary-grid .admin-field { margin: 0; }` both exist specifically
  to *cancel* a base margin that turned out to never exist, and
  `.admin-field-hint`'s `-0.3em` top pull only makes sense as a small trim
  against an expected positive gap above it -- three independent pieces of
  existing CSS whose design intent already assumed this spacing existed.
  Added `.admin-field { margin-bottom: 18px; }` once, near the other
  `.admin-field` rules; both existing override contexts have higher
  selector specificity so neither needed to change. Beyond that fix: page
  title (`.admin-section-head h1`, 1.6rem -> 1.75rem, plus tighter
  letter-spacing and more margin below), subsection (`.admin-subsection-head
  h2`) and modal title (`.admin-modal-title`, 1.15rem -> 1.25rem) all got a
  clearer size/spacing step between hierarchy levels; wrapping text blocks
  that had no explicit `line-height` (`.admin-section-head p`,
  `.admin-subsection-head p`, `.admin-modal-sub`, `.admin-field-hint`) now
  match the `1.5`/`1.55` already used elsewhere in this same file
  (`.admin-form-card-head p`, `.mail-settings-card-head p`) instead of
  inheriting the body's tighter default; and `.admin-row` (every list row
  across Members/Books/Worlds/Dispatches/etc.) and `.admin-nav-link`
  (sidebar) both got a couple of extra vertical padding pixels. Bumped
  `admin.css?v=224` / `admin-bundle.css?v=260`. Note: this session's sandbox
  has no way to load the real admin console (it requires a live PHP session
  against `api/session-check.php`, which a static `file://` preview can't
  reach -- it just sits on "Checking access..."), so this was verified by
  hand-tracing CSS cascade/specificity and brace/paren balance rather than a
  live screenshot; worth a quick look on the real deployed console before
  trusting it fully.

- **Hardened client IP detection (`pw_client_ip()`):** previously trusted
  `CF-Connecting-IP` and `X-Forwarded-For` unconditionally -- since this host
  has no Cloudflare (or any) reverse proxy in front of it (confirmed
  separately: real DNS lives at GoDaddy's `pdns13/14.domaincontrol.com`, no
  CDN), any visitor could set either header directly and spoof the IP behind
  login rate limiting, audit logging, and visitor stats. Extracted into its
  own dependency-free file, `api/client-ip.php` (required by `api/helpers.
  php`, same as before for every existing caller), so it can be exercised by
  a new `tools/test-client-ip.php` CLI regression script without a database
  connection -- same reasoning `api/dispatch-translation-drafts.php` is kept
  standalone for `tools/test-dispatch-translator.php`. Now: every candidate
  value (`REMOTE_ADDR`, the proxy headers) is validated with `filter_var(...,
  FILTER_VALIDATE_IP)` before being trusted at all; the two proxy headers are
  only consulted when `REMOTE_ADDR` itself falls inside Cloudflare's
  published edge ranges (`PW_CLOUDFLARE_IP_RANGES`, hardcoded rather than
  fetched live so this check can never depend on a third-party HTTP call
  succeeding); an `X-Forwarded-For` chain only ever considers its first
  entry, and a malformed first entry discards the whole header rather than
  reading further down a chain the client could also have forged; the final
  fallback is always the real, un-spoofable `REMOTE_ADDR`, itself validated
  the same way. CIDR containment (`pw_ip_in_cidr()`) uses `inet_pton()` binary
  comparison, which handles IPv4 and IPv6 with the same logic. No behavior
  changed for the 8 existing call sites (login/2FA/OAuth rate limiting,
  activity logging, visitor tracking) beyond no longer trusting a header this
  host was never actually receiving from a real proxy.

- **New sign-in (device/location) alerts:** `user_sessions` already recorded
  `browser_name`/`operating_system`/`country_code` per session (and is never
  pruned, only `revoked_at` is set), so no new "known devices" table was
  needed -- `pw_maybe_notify_new_device()` in `api/helpers.php`, called from
  inside `pw_issue_user_session()` right after the insert, just checks
  whether this exact browser+OS+country combination has ever appeared
  before anywhere else in that user's own `user_sessions` rows (NULL-safe
  `<=>` on country, since an unresolved IP stores NULL and that must never
  itself look "different"). Deliberately requires at least one *other*
  session to already exist, so a brand-new registration -- where literally
  everything is new -- never fires it. On a genuine first-ever sighting it
  sends a new `new_device_login` notification (`sql/migration_new_device_
  alerts.sql` adds the ENUM value and a `notif_new_device_login` opt-out
  column, default enabled), linking to Profile -> User Sessions
  (`profile.html?tab=sessions`) so the visitor can review or revoke it if it
  wasn't them. Wired into every existing surface a notification type needs
  to touch in this codebase: `pw_notifications_enabled()`'s column map,
  both `api/notification-prefs/{get,save}.php`, `api/notifications/
  stats.php`'s counts and `list.php`'s type whitelist, a new Notification
  Settings toggle, and the icon/link/text entries hand-duplicated in both
  `js/notifications.js` (bell dropdown) and `notifications.html` (full
  history page, plus its own filter chip) per this codebase's no-shared-
  module convention. Session storage, country resolution, and the Session
  Manager list itself were already fully built by the earlier User Sessions
  feature -- this only added the detection and the notification.

- **Application-wide permission-visibility audit:** cross-referenced every
  `pw_require_permission()`/`pw_has_permission()` key checked anywhere under
  `api/` against `admin/index.html`'s frontend gates and found the "+ Add X"
  create button and/or in-modal Save button ungated (visible and clickable,
  even though the backend correctly blocked the save) across Book, World,
  Overlord, Quiz, Soundtrack, Known Figures (delete only), Forum Control
  (boards + categories), Dispatch Control (Force Re-sync + edit modal), and
  Dispatch Translations (save/generate-draft/delete) -- a consistent gap
  where `.view`/`.delete` had been wired up but `.edit`/`.create` was missed.
  Also fixed: Topic Reports' per-row lock/move/close/reopen/delete buttons
  were built in JS with zero permission checks at all (now gated by
  `topic_reports.manage`/`.delete`); Members' Reset Avatar/Generate Password
  buttons were unconditionally visible (now `members.reset_avatar`/
  `members.reset_password`); Home's "Recent Activity" card is the literal
  reported bug (an audit-log preview that rendered as a permanently-empty
  "Loading activity..." card for anyone without `dashboards.view_audit_log`,
  since the backend only populates that field conditionally) plus four
  Pending Work rows with the same empty-by-permission problem; the "Add New
  Book"/"Add New Member" Quick Actions were reachable and would silently
  no-op-to-403 for a Test-role user. Verified (not changed) that
  `community.html`'s forum moderation menu was already doing this correctly
  -- `canModerate`/`canDelete` are resolved server-side from
  `pw_has_permission()` in `api/topics/get.php`/`api/comments/list.php` and
  sent down as booleans, which is the same permission-based approach just
  computed once on the trusted side rather than re-checked as a raw key in
  the browser. See the new "Permission-aware UI is a standing requirement"
  section above for the pattern every future admin control must follow.

- **Overlord profile page enhancements** (`overlord.html`): six additions.
  **Per-Overlord theming**: `accent_color`/`accent_glow` were captured by
  Overlord Control and returned by `api/overlords.php` since that feature
  shipped, but never actually rendered anywhere on the detail page itself
  (only the separately-hardcoded `THRONE_THEMES` map in `js/overlords.js`
  used colors, and only on the roster carousel) -- the page now sets
  `--overlord-accent`/`--overlord-glow` on `documentElement` the same way the
  existing Throne Ring "profile handoff" portal already sets
  `--portal-accent`/`--portal-glow`, driving the portrait border/glow,
  epithet color, and the two new panels below. Setting a CSS custom property
  to `''` still counts as "set" and silently breaks a `var(--x, fallback)`
  default (a real gotcha, not just theory) -- so an Overlord with no accent
  color removes the property instead of setting it empty. **3D tilt
  portrait**: ported `js/known-figures.js`'s pointer-tilt "holo card" +
  radial sheen technique to the circular portrait frame (fine-pointer/hover
  only, `prefers-reduced-motion` skips it); this is GSAP's third deliberate
  use in this codebase (see above), needing only the bare library, not
  ScrollTrigger. **Resonance sigil + "Your Overlord"**: the fixed 6-icon
  `OVERLORD_ICONS` set from `js/members.js` (quiz-covered Overlords only) is
  hand-duplicated into `overlord.html` per this codebase's no-shared-module
  convention, shown as a small badge; separately, a signed-in visitor whose
  `overlord_affinity` (set by the quiz) matches the page gets a gold "Your
  Overlord" badge and a matching portrait ring -- previously this page
  rendered identically for every visitor regardless of quiz history.
  **Decree of the day**: `overlords.decrees` (new, one line per admin-authored
  decree, run `sql/migration_overlord_enhancements.sql`) rotates
  deterministically by UTC date (`crc32(slug + date) % count`, the same
  date-seeding technique already used for World Weather forecasts) via a new
  `pw_resolve_overlord_decree()` in `api/overlords.php`; the endpoint only
  ever returns today's already-resolved line, never the raw list. Left
  blank, it falls back to a generated line instead of an empty panel.
  **Ambient hero particles**: a pure-CSS drifting-mote layer tinted by
  `--overlord-glow`, gated by the same `prefers-reduced-motion` `display:
  none` pattern already used for the Throne Ring's own particle effects --
  deliberately simpler than either the Throne Ring's 7-variant particle
  system or the Worlds atlas's canvas engine, since this page only needed
  one ambient layer tied to a color already being computed for other reasons,
  not a full per-motif system.

- **Fixed: disabling a Site Settings OAuth provider didn't hide its button.**
  Confirmed live (2026-07-20): `api/session-check.php` correctly reported
  `oauth.apple: false` after the toggle was switched off and saved, but the
  Apple button stayed visible in the login/register modal regardless --
  a client-side CSS bug, not a deploy/migration/caching issue. `.auth-google-
  btn`/`.auth-apple-btn`/`.auth-oauth-divider`/`.auth-oauth-option` (added for
  Apple OAuth/Site Settings) each set their own `display: flex`, which ties
  in CSS specificity with the browser's default `[hidden] { display: none }`
  rule and wins the cascade since the author rule is declared later --
  exactly the reason `.auth-form[hidden] { display: none; }` already exists
  a few lines above in `components.css` for the same tab-switching mechanism,
  a defensive override this codebase already established the need for and
  that these four new selectors simply didn't get. Fixed with
  `.auth-google-btn[hidden], .auth-apple-btn[hidden], .auth-oauth-divider[hidden],
  .auth-oauth-option[hidden] { display: none; }`. Google's own button was
  never observed broken only because it defaults enabled; the underlying bug
  would have hit it identically the first time someone disabled Google.

- **Maintenance Mode (Site Settings):** a third Site Settings switch
  alongside the two OAuth toggles, with an optional custom message
  (`app_settings.maintenance_message`, `sql/migration_maintenance_mode.sql`)
  that falls back to `PW_MAINTENANCE_DEFAULT_MESSAGE` in `api/helpers.php`
  whenever it's left blank -- `pw_maintenance_settings()` resolves that
  default for public consumption, while a separate `pw_maintenance_settings_raw()`
  keeps the actually-stored (possibly empty) value for the admin edit form,
  so re-saving an unedited blank field can never silently bake the default
  in as if it were deliberately-authored text. **This is a visitor-facing
  interstitial, not an API firewall**: `api/session-check.php` exposes
  `enabled`/`message`, and `js/members.js`'s `applyMaintenanceMode()` shows a
  full-page lockout card to every visitor except accounts with
  `admin_console.access` and anyone already under `/admin/` -- existing
  per-endpoint permission checks remain the only real security boundary
  during maintenance, same as always; a sufficiently direct API call is not
  blocked by this feature. Chosen deliberately over a broader server-side
  request-blocking gate in `api/helpers.php` (which would touch the shared
  bootstrap every single request goes through) to keep the blast radius of
  a bug in this feature limited to a visible banner, not an accidental
  site-wide outage.

- **Site Settings (Admin Console -> System, new) + OAuth provider toggles:**
  built because Apple OAuth (below) landed before the user could actually
  create an Apple Developer account, so Apple needed to exist in code but
  stay firmly off until that account is ready -- without a future code
  deploy just to flip it on. `app_settings` gained `oauth_google_enabled`
  (default `1`) and `oauth_apple_enabled` (default `0`), a new
  `site_settings.view`/`site_settings.manage` permission pair, and
  `api/admin/site-settings/{get,update}.php` (`sql/migration_site_settings.sql`).
  `pw_oauth_settings()` in `api/oauth.php` reads them with the same
  fail-safe-default try/catch pattern as `pw_mail_settings()`; critically,
  `pw_oauth_provider_config($provider)` now checks this toggle *before*
  checking credentials, so switching a provider off is a hard server-side
  stop regardless of whether its `GOOGLE_OAUTH_*`/`APPLE_OAUTH_*` constants
  are configured -- the toggle always wins. `api/session-check.php` (already
  the endpoint every page's `js/members.js` calls on load) now also returns
  these two booleans, so the public login/register modal can hide a
  disabled provider's button (`applyOauthButtonVisibility()`) without a
  separate request; the Apple button additionally starts `hidden` in the
  raw HTML as the safe default before that JS ever runs, so there is no
  flash of an Apple button before the real setting loads. Google's button
  stays visible by default, matching its own default-enabled setting.

- **Apple OAuth ("Sign in with Apple"):** a second provider alongside Google,
  documented end-to-end in `docs/apple-oauth.md` (Apple Developer Program
  setup, Services ID + Return URL, domain-verification file, private key/Team
  ID/Key ID). Landing this exposed that `api/oauth.php`'s "provider-neutral"
  claim wasn't actually true yet -- `api/oauth/{start,callback,unlink}.php`
  all hardcoded literal `'google'` strings in result codes and audit-log
  actions -- so those three files were generalized to interpolate `$provider`
  (Google's own behavior is unchanged; every result code it already produced,
  e.g. `google-signed-in`, is byte-for-byte the same). Apple's flow differs
  structurally from Google's in three ways `api/oauth.php` now accounts for:
  it requires `response_mode=form_post` (Apple POSTs state/code/user to the
  redirect URI instead of Google's GET redirect -- `callback.php` merges
  `$_POST + $_GET` so one endpoint serves both), its client secret is a
  short-lived ES256 JWT signed per-request with a Sign In with Apple private
  key rather than a static string (`pw_oauth_apple_client_secret()`, hand-
  rolled since no JWT library exists in this codebase -- includes a small
  DER-to-raw ECDSA signature conversion), and there is no UserInfo REST call:
  the identity comes from decoding (not re-verifying against Apple's JWKS --
  the trust boundary is the direct server-to-server TLS response from
  Apple's token endpoint, the same trust model the Google flow already uses
  for its access-token-authenticated REST call) the `id_token` JWT Apple's
  token endpoint returns directly. Apple never provides a profile picture and
  only ever sends the user's name once (their very first authorization).
  `oauth_identities.provider` needed no schema change (`VARCHAR(32)`, not an
  enum). Profile Settings -> Sign-in Methods, the admin audit log
  (`apple_*` actions/icons), and the admin Members list auth badge (now
  Google/Apple/both/"Pantheon Wars" instead of a Google-only binary) all
  gained an Apple counterpart alongside Google's.

- **Remember me / session persistence:** login has an opt-in "Remember me"
  checkbox (checked by default, so nothing changes unless a visitor unchecks
  it). Unchecked, `pw_apply_session_persistence()` issues a session-only
  cookie (dies on browser close) instead of the 30-day one, and
  `pw_issue_user_session()` writes a matching `user_sessions.expires_at`
  (1-day backstop cap instead of 30 days) and `is_persistent` value -- a
  column that had existed since the User Sessions feature but was hardcoded
  to `1` and never actually varied until now. The choice survives the
  two-factor challenge round trip via `$_SESSION['pw_two_factor_pending_
  remember']`. Registration and Google/Apple OAuth sign-in remain always-
  persistent (no checkbox there). Profile -> User Sessions shows a
  "Temporary session" badge for non-persistent rows.

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
  `dispatch-draft-v34`, so regeneration refreshes unapproved local drafts
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
  PKCE, safe local return URLs, code exchange/profile validation, identity
  linking and optional first-registration avatar import (Google's provider
  config and profile exchange were the first branch added there; see "Apple
  OAuth" above for the second). `oauth_identities` stores
  only `(provider, provider_subject, verified email)`—never provider tokens. Run
  `migration_oauth_google.sql` before defining `GOOGLE_OAUTH_CLIENT_ID`,
  `GOOGLE_OAUTH_CLIENT_SECRET`, and `GOOGLE_OAUTH_REDIRECT_URI` in the external
  secrets config; `docs/google-oauth.md` has the exact Google Console redirect URI.
  Existing email addresses are never auto-linked: users sign in normally, then use
  Profile Settings -> Sign-in Methods to link Google. Google-only accounts have a
  NULL password hash, may add a local password later, and cannot unlink their last
  sign-in method until they do. Login/registration/link/unlink outcomes for both
  providers are recorded in `admin_activity_log` and sessions carry
  `auth_provider = google` or `auth_provider = apple`.

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
  redundant row locks. The current members-script cache version is v=32 across
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
