<?php
/**
 * Feeds the "System Status" card next to the BH-4 welcome card on the admin
 * Home page. Every item below reports a normalized status of ok/warn/bad/
 * unknown (used by the frontend to color the value green/gold/red) plus a
 * human label. Seven signals:
 *
 *  - GitHub Repository: a live HTTP call to the GitHub REST API for the
 *    latest commit on main. If it responds 200, the repo is reachable; we
 *    also keep the sha it returns to cross-check Dispatch Sync below.
 *  - Database: a trivial SELECT against the connection this very request is
 *    already using. In practice, if the DB were actually down, pw_require_permission()
 *    would have thrown before we got here -- this is a defensive check, not
 *    the primary signal, since a hard DB outage surfaces as this whole
 *    request failing rather than a graceful "Unreachable" row.
 *  - Database Load: how long a real query against the users table takes
 *    right now, as a rough proxy for DB contention on this shared host (see
 *    pw_check_database_load() in status-helpers.php for the thresholds).
 *  - Forum: a lightweight query against the topics table, standing in for
 *    "is the community/forum feature's storage reachable."
 *  - Dispatch Sync: compares the sha of the most recently stored dispatch
 *    entry against the sha GitHub reports as the tip of main. They should
 *    always match since every push fires the webhook immediately -- a
 *    mismatch means the webhook missed a push and a manual Re-Sync
 *    (Dispatch Control > Re-Sync) is needed to catch up.
 *  - Avatar Storage: total bytes under uploads/avatars against a 5 GiB soft
 *    budget (see status-helpers.php for the thresholds).
 *  - Last Backup: most recent manually logged backup; it is warning at three
 *    days and critical at seven days (or immediately critical when missing).
 *
 * (A "Site Errors" check was tried here too, but this host's PHP error log
 * isn't readable from application code -- see status-helpers.php's removed
 * pw_error_log_path() history in git log for the investigation -- so it was
 * pulled back out rather than permanently showing "Unavailable".)
 *
 * The actual checks live in pw_build_system_signals() (status-helpers.php)
 * so api/admin/task-advisor.php can reuse them verbatim for its critical-alert
 * detection instead of re-implementing the same GitHub/DB/forum/sync/storage
 * checks a second time.
 */
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/status-helpers.php';

pw_require_permission('dashboards.view_system_status');
$db = pw_db();

$signals = pw_build_system_signals($db);

pw_json(array_merge(['ok' => true], $signals, ['checked_at' => gmdate('c')]));
