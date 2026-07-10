<?php
/**
 * Upserts the logged-in user's notification type preferences from
 * profile.html's Notification Settings tab. Accepts {like, mention, quote,
 * report_resolved} booleans (missing keys default to enabled, matching
 * this table's own column defaults) and writes/updates a single row.
 */
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);

$like = !empty($input['like']) ? 1 : 0;
$mention = !empty($input['mention']) ? 1 : 0;
$quote = !empty($input['quote']) ? 1 : 0;
$reportResolved = !empty($input['report_resolved']) ? 1 : 0;

$stmt = pw_db()->prepare(
    'INSERT INTO notification_preferences (user_id, notif_like, notif_mention, notif_quote, notif_report_resolved)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE notif_like = VALUES(notif_like), notif_mention = VALUES(notif_mention),
       notif_quote = VALUES(notif_quote), notif_report_resolved = VALUES(notif_report_resolved)'
);
$stmt->execute([$user['id'], $like, $mention, $quote, $reportResolved]);

pw_json(['ok' => true]);
