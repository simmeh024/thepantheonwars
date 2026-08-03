<?php
/**
 * Turns a single notification type on or off for the logged-in member.
 *
 * Deliberately NOT a partial call to save.php. That endpoint takes the whole
 * preference set and treats every missing key as "off" (`!empty($input[...])`),
 * which is correct for the Notification Settings form -- it always posts all
 * twelve checkboxes -- and catastrophic for a one-field caller: muting a single
 * type through it would silently disable every other type the member had on.
 *
 * Backs the per-row mute control in the nav bell dropdown, where a member sees
 * the notification that annoyed them and wants to stop that kind of thing
 * without first working out which of twelve toggles in Profile Settings
 * corresponds to it.
 *
 * The allowlist is narrower than the preferences table on purpose:
 * `warning_issued` is a moderation notice and is not the member's to opt out
 * of from a convenience control, and `direct_message` has no preference column
 * at all. Both are simply absent here, and js/notifications.js does not offer
 * the control for them either -- but this is the check that actually enforces
 * it, since a hidden button is never the security boundary.
 */
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);

/** type key => the column it owns. */
$MUTABLE_TYPES = [
    'like' => 'notif_like',
    'mention' => 'notif_mention',
    'quote' => 'notif_quote',
    'report_resolved' => 'notif_report_resolved',
    'world_available' => 'notif_world_available',
    'news_published' => 'notif_news_published',
    'topic_reply' => 'notif_topic_reply',
    'icon_unlocked' => 'notif_icon_unlocked',
    'new_device_login' => 'notif_new_device_login',
    'weather_alert' => 'notif_weather_alert',
    'mission_ready' => 'notif_mission_ready',
];

$type = isset($input['type']) ? (string)$input['type'] : '';
if (!isset($MUTABLE_TYPES[$type])) {
    pw_error('That notification type cannot be changed here.');
}
$column = $MUTABLE_TYPES[$type];
$enabled = !empty($input['enabled']) ? 1 : 0;

$db = pw_db();

/**
 * Most members have no row at all -- the table is only written when someone
 * opens Notification Settings, and pw_notifications_enabled() treats a missing
 * row as everything enabled. Naming one column on the insert is therefore
 * correct rather than lossy: every other column is `NOT NULL DEFAULT 1`, which
 * is exactly the "enabled" that a missing row already meant. Any future column
 * must keep that default for this to hold.
 *
 * The column name is interpolated, which is safe only because it came out of
 * the allowlist above -- it can never be caller-supplied text.
 */
$stmt = $db->prepare(
    "INSERT INTO notification_preferences (user_id, $column) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE $column = VALUES($column)"
);
$stmt->execute([(int)$user['id'], $enabled]);

pw_json(['ok' => true, 'type' => $type, 'enabled' => (bool)$enabled]);
