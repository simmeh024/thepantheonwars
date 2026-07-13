<?php
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);

$allowedTypes = ['access', 'rectification', 'erasure', 'portability', 'restriction', 'objection', 'other'];
$type = isset($input['request_type']) ? trim((string)$input['request_type']) : '';
$message = isset($input['message']) ? trim((string)$input['message']) : '';

if (!in_array($type, $allowedTypes, true)) {
    pw_error('Choose a valid privacy request type.');
}
if (mb_strlen($message) > 2000) {
    pw_error('Please keep the additional details to 2000 characters or fewer.');
}

$db = pw_db();
try {
    // Avoid accidental duplicate clicks while still allowing a member to make
    // a distinct request later. No sensitive request text is written to logs.
    $recent = $db->prepare(
        "SELECT id FROM privacy_requests
         WHERE requester_user_id = ? AND request_type = ?
           AND status IN ('submitted', 'identity_check', 'in_progress')
           AND created_at >= NOW() - INTERVAL 1 DAY
         LIMIT 1"
    );
    $recent->execute([$user['id'], $type]);
    if ($recent->fetch()) {
        pw_error('You already have a recent open request of this type. Please wait for our response.');
    }

    $stmt = $db->prepare(
        'INSERT INTO privacy_requests (requester_user_id, requester_email, request_type, message, due_at)
         VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 MONTH))'
    );
    $stmt->execute([$user['id'], $user['email'], $type, $message !== '' ? $message : null]);
    pw_json(['ok' => true, 'request_id' => (int)$db->lastInsertId()]);
} catch (PDOException $e) {
    pw_error('Privacy requests are not available yet. Please email Privacy@thepantheonwars.com.', 503);
}
