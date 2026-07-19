<?php
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    pw_require_permission('reputation.view');
    try {
        $rows = pw_db()->query('SELECT e.*, u.display_name AS creator_name FROM reputation_events e LEFT JOIN users u ON u.id = e.created_by ORDER BY e.starts_at DESC LIMIT 40')->fetchAll();
        foreach ($rows as &$row) $row['reward_keys'] = json_decode($row['reward_keys_json'], true) ?: [];
        pw_json(['ok' => true, 'events' => $rows]);
    } catch (Throwable $e) { pw_error('Events require the reputation expansion migration.', 503); }
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$admin = pw_require_permission('reputation.edit'); $input = pw_input(); pw_require_csrf($input);
$name = trim((string)($input['name'] ?? '')); $multiplier = (int)($input['multiplier'] ?? 1); $starts = trim((string)($input['starts_at'] ?? '')); $ends = trim((string)($input['ends_at'] ?? '')); $keys = isset($input['reward_keys']) && is_array($input['reward_keys']) ? array_values(array_unique(array_map('strval', $input['reward_keys']))) : [];
if ($name === '' || mb_strlen($name) > 100 || !in_array($multiplier, [2, 3, 4], true) || !$keys) pw_error('Give the event a name, multiplier, and at least one reward type.');
try { $start = (new DateTimeImmutable($starts))->setTimezone(new DateTimeZone('UTC')); $end = (new DateTimeImmutable($ends))->setTimezone(new DateTimeZone('UTC')); } catch (Throwable $e) { pw_error('Choose valid start and end dates.'); }
if ($end <= $start) pw_error('The end time must be after the start time.');
$known = array_column(pw_reputation_reward_catalog(), 'key'); foreach ($keys as $key) if (!in_array($key, $known, true)) pw_error('An event reward type is invalid.');
try {
    $stmt = pw_db()->prepare('INSERT INTO reputation_events (name, multiplier, starts_at, ends_at, reward_keys_json, created_by) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$name, $multiplier, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), json_encode($keys), $admin['id']]);
    pw_log_admin_activity('reputation_event_created', 'Scheduled ' . $multiplier . 'x reputation event "' . $name . '".', $admin);
    pw_json(['ok' => true, 'id' => (int)pw_db()->lastInsertId()]);
} catch (Throwable $e) { pw_error('Events require the reputation expansion migration.', 503); }
