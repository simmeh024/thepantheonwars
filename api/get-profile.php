<?php
require_once __DIR__ . '/helpers.php';

$user = pw_require_login();
$db = pw_db();

$stmt = $db->prepare('SELECT overlord_result, created_at FROM quiz_results WHERE user_id = ? ORDER BY created_at DESC LIMIT 20');
$stmt->execute([$user['id']]);
$quizHistory = $stmt->fetchAll();

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
        'created_at' => $user['created_at'],
    ],
    'quizHistory' => $quizHistory,
    'comments' => $comments,
]);
