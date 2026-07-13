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
- MySQL/MariaDB database: `rdy3i6my40b0_pantheonwars` (shown as `pantheonwars` in
  phpMyAdmin's tree)

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
- PHP 8.3.31, litespeed SAPI, MariaDB 10.11.18-cll-lve, 12 CPU cores reported (whole
  shared box, not a per-account allocation).

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

- Sidebar categories: **Lore Management** (Book Control, World Control, Overlord
  Control), **Community** (Forum Control, Members, Topic Reports), **Development
  Dispatches** (Dispatch Control, Dispatch Translations), **System** (System Status,
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
- No external chart library anywhere in this codebase. Two hand-rolled patterns exist:
  a stacked div bar chart (`dev-metrics.html`'s language-history, percentage-based,
  no real axis scaling needed) and a hand-built inline SVG line chart (System
  Status's CPU chart -- computes its own x/y pixel scale from real data ranges, builds
  the whole `<svg>...</svg>` as a template string, sets `.innerHTML` once per refresh).
  Match whichever pattern fits if you add another chart.
- **No GSAP or Alpine dependency is installed.** Prefer CSS transitions and native
  browser APIs (for example the Books page's `IntersectionObserver`) for modest
  motion and local UI state. Add either library only for a clearly measured feature,
  load it after the initial render, and preserve `prefers-reduced-motion` behavior.
- Cache-busting: `css/style.css?v=N` -- bump `N` across all public HTML files plus
  the bundle reference and import query that include the changed source. Current
  versions: public/community v=172 and admin v=173. Public pages use
  `css/public.css`, community pages use `css/community-bundle.css`, and the console
  uses `css/admin-bundle.css`; `css/style.css` remains the legacy full compatibility
  bundle. The ordered source and bundle map is in `css/SOURCES.md`.
- Same pattern, separate counters, each easy to miss since `.htaccess`'s no-cache
  headers only cover `.html$` -- a stale cached JS file can silently serve old code
  after a deploy even though the HTML/CSS look right (confirmed the hard way more
  than once): `js/main.js?v=N` (current: v=4), `js/members.js?v=N` (current: v=10)
  and `js/notifications.js?v=N` (current: v=8), across the public pages
  (not admin). The notification script is now loaded dynamically for
  authenticated visitors rather than referenced in every page's HTML.
  `js/books.js?v=N` is page-specific (current: v=3) and only needs a version
  bump in `books.html`.
- Static CSS, JavaScript, font, and image assets have a one-year
  `public, immutable` cache policy in `.htaccess`; HTML remains no-cache so
  changed version URLs reach visitors immediately. Never replace an asset at
  the same URL without changing its filename or version query string.
- **No shared JS module anywhere in this static site** -- BBCode rendering
  (`formatBody()`/`escapeHtml()`) is hand-duplicated in `community.html` (canonical,
  also owns the editor toolbar) and `member.html` (Recent Posts). A plain-text
  variant, `stripBbcodePreview()`, is *also* duplicated in `profile.html`,
  `notifications.html`, and `js/notifications.js` (nav-bell dropdown) for contexts
  that echo comment text without ever rendering BBCode (it strips brackets to plain
  text, but replaces `[spoiler]...[/spoiler]` with a `"(spoiler hidden)"` placeholder
  rather than un-hiding it). Any new BBCode tag must be added to all of these in
  lockstep or it'll show as literal bracket text somewhere.

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
- Ask before running anything with real production side effects that goes beyond what
  was explicitly requested (e.g., live diagnostic queries, resetting passwords,
  deleting data) -- a question from the user is not authorization to act.

## Recent history (most recent first)

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
  analytics, never render-critical. `js/members.js` defers the first session check
  until after load/idle, but opens it immediately when a visitor opens the auth
  modal. Do not move either request back into the initial render path without a
  measured reason. Notification code is not referenced by public HTML; it is loaded
  after the authenticated session result.

- **About portrait ratio:** `images/pascal_author.jpg` is 700×1074. Its frame rule
  must keep `width: 100%; height: auto`; omitting the explicit automatic height lets
  the HTML `height="1074"` attribute survive a width resize and vertically stretch
  the portrait.

- **API response security headers:** `api/helpers.php` applies
  `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, and
  `Referrer-Policy: strict-origin-when-cross-origin` to normal API responses.
  Cron and bootstrap/error paths that cannot rely on helpers set the same headers
  themselves; keep any new exceptional API entry point consistent.

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
  priority is critical alerts -> topic reports -> privacy requests -> dispatch
  translations. Request content and staff outcomes must never be copied into
  the ordinary admin audit log.

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
  redundant row locks. The current members-script cache version is v=10 across
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
- **Forum Control**: `forum_boards`/`forum_board_roles` tables replacing hardcoded
  board arrays in `community.html` and the API layer -- boards are now fully
  admin-managed (name/description/icon/visibility/order), with hidden boards scoped
  to specific roles via `pw_can_see_board()`.
- Fixed Total Storage metric twice in a row post-deploy (see "Server introspection
  notes" above) -- first showed `undefined MB` (field-name mismatch with the shared
  `setAvatarStorageBar()` renderer, which expects `used_mb`/`max_mb` not `used_gb`/
  `max_gb`), then showed a real but wrong `0 MB` (the `disk_free_space()` quota
  assumption was wrong). Now uses `du -sb` and reads ~600 MB / 24576 MB, matching
  cPanel's own Disk Usage page.
- Added System Status "CPU (Shared)" card (24h line chart, live load1/5/15 + core
  count) and expanded the Database card (connections, QPS, slow queries, uptime,
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
