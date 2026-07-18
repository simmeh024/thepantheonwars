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

// "My Posts" merges topics you started with replies you posted into one feed,
// each linkable back to its thread (community.html?topic=<id>) -- a reply's
// topic_id links to the thread it lives in; a topic's own id links to itself.
$stmt = $db->prepare('SELECT id, title, created_at FROM topics WHERE user_id = ? AND is_deleted = 0 ORDER BY created_at DESC LIMIT 20');
$stmt->execute([$user['id']]);
$topicRows = $stmt->fetchAll();

$stmt = $db->prepare(
    'SELECT c.id, c.body, c.created_at, c.topic_id, t.title AS topic_title
     FROM comments c
     JOIN topics t ON t.id = c.topic_id AND t.is_deleted = 0
     WHERE c.user_id = ? AND c.is_deleted = 0
     ORDER BY c.created_at DESC LIMIT 20'
);
$stmt->execute([$user['id']]);
$commentRows = $stmt->fetchAll();

$posts = [];
foreach ($topicRows as $r) {
    $posts[] = [
        'type' => 'topic',
        'topic_id' => (int)$r['id'],
        'title' => $r['title'],
        'body' => null,
        'created_at' => $r['created_at'],
    ];
}
foreach ($commentRows as $r) {
    $posts[] = [
        'type' => 'comment',
        'topic_id' => (int)$r['topic_id'],
        'title' => $r['topic_title'],
        'body' => $r['body'],
        'created_at' => $r['created_at'],
    ];
}
usort($posts, function ($a, $b) {
    return strtotime($b['created_at']) <=> strtotime($a['created_at']);
});

// OAuth-only accounts intentionally have no local password until the member
// chooses to add one from Profile Settings.
$passwordStmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
$passwordStmt->execute([$user['id']]);
$passwordRow = $passwordStmt->fetch();
$hasPassword = $passwordRow && $passwordRow['password_hash'] !== null && $passwordRow['password_hash'] !== '';

pw_json([
    'ok' => true,
    'user' => [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'display_name' => $user['display_name'],
        'email' => $user['email'],
        'newsletter_subscribed' => (bool)$user['newsletter_subscribed'],
        'overlord_affinity' => $user['overlord_affinity'],
        'role' => $user['role'],
        'role_color' => $roleColor,
        'created_at' => $user['created_at'],
        'has_password' => $hasPassword,
        'reputation' => pw_reputation_info((int)$user['reputation']),
    ],
    'quizHistory' => $quizHistory,
    'posts' => $posts,
]);
