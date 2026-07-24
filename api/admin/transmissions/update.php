<?php
/**
 * Saves the editable result-screen transmission for one Overlord.
 */
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('transmissions.edit');
$input = pw_input();
pw_require_csrf($input);

$overlordId = isset($input['overlord_id']) ? (int)$input['overlord_id'] : 0;
if ($overlordId <= 0) {
    pw_error('Missing Overlord.');
}

$opening = trim((string)($input['opening_message'] ?? ''));
$followup = trim((string)($input['followup_message'] ?? ''));
if (mb_strlen($opening) > 500 || mb_strlen($followup) > 500) {
    pw_error('Each transmission message may be at most 500 characters.');
}

$enabled = !empty($input['is_enabled']) ? 1 : 0;
$delay = isset($input['typing_delay_ms']) ? (int)$input['typing_delay_ms'] : 700;
if ($delay < 250 || $delay > 4000) {
    pw_error('Response pace must be between 250 and 4000 milliseconds.');
}

$db = pw_db();
try {
    $ready = (bool)$db->query("SHOW TABLES LIKE 'overlord_transmissions'")->fetch();
    if (!$ready) {
        pw_error('Overlord Transmission Control needs its database migration before it can be used.', 503);
    }

    $overlordStmt = $db->prepare('SELECT name FROM overlords WHERE id = ?');
    $overlordStmt->execute([$overlordId]);
    $overlord = $overlordStmt->fetch();
    if (!$overlord) {
        pw_error('Overlord not found.', 404);
    }

    $stmt = $db->prepare(
        'INSERT INTO overlord_transmissions (overlord_id, is_enabled, opening_message, followup_message, typing_delay_ms)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           is_enabled = VALUES(is_enabled),
           opening_message = VALUES(opening_message),
           followup_message = VALUES(followup_message),
           typing_delay_ms = VALUES(typing_delay_ms)'
    );
    $stmt->execute([$overlordId, $enabled, $opening, $followup, $delay]);
} catch (Throwable $e) {
    pw_error('Could not save this Overlord transmission.', 500);
}

pw_log_admin_activity(
    'overlord_transmission_updated',
    'Updated quiz-result transmission for Overlord "' . $overlord['name'] . '"' . ($enabled ? '.' : ' (disabled).'),
    $adminUser
);

pw_json(['ok' => true]);
