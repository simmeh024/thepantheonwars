<?php
require_once __DIR__ . '/helpers.php';

$user = pw_require_login();
$db = pw_db();

$roleStmt = $db->prepare('SELECT color FROM roles WHERE slug = ?');
$roleStmt->execute([$user['role']]);
$roleRow = $roleStmt->fetch();
$roleColor = $roleRow ? $roleRow['color'] : '#c7ccd6';

$stmt = $db->prepare('SELECT overlord_result, scores_json, created_at FROM quiz_results WHERE user_id = ? ORDER BY created_at DESC LIMIT 20');
$stmt->execute([$user['id']]);
$quizHistory = array_map(function ($row) {
    return [
        'overlord_result' => $row['overlord_result'],
        'created_at' => $row['created_at'],
        // Older rows predate Maerion Thal and may have only 5 scores, or
        // predate scores_json entirely -- both are handled client-side.
        'scores' => $row['scores_json'] ? json_decode($row['scores_json']) : null,
    ];
}, $stmt->fetchAll());

$stmt = $db->prepare('SELECT id, body, created_at FROM comments WHERE user_id = ? AND is_deleted = 0 ORDER BY created_at DESC LIMIT 20');
$stmt->execute([$user['id']]);
$comments = $stmt->fetchAll();

pw_json([
    'ok' => true,
    'user' => [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'display_name' => $user['display_name'],
        'email' => $user['email'],
        'overlord_affinity' => $user['overlord_affinity'],
        'role' => $user['role'],
        'role_color' => $roleColor,
        'created_at' => $user['created_at'],
    ],
    'quizHistory' => $quizHistory,
    'comments' => $comments,
]);
