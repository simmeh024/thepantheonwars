<?php
/** Creates one member reply beneath a News transmission. */
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
pw_require_not_muted($user);
pw_require_site_feature('news_comments_enabled', 'News comments are temporarily unavailable.');

$slug = isset($input['slug']) ? trim((string)$input['slug']) : '';
if (!preg_match('/^[a-z0-9-]{1,120}$/', $slug)) {
    pw_error('Unknown news article.', 404);
}
$body = isset($input['body']) ? trim((string)$input['body']) : '';
if ($body === '') {
    pw_error('Your comment is empty.');
}
if ((function_exists('mb_strlen') ? mb_strlen($body) : strlen($body)) > 3500) {
    pw_error('That comment is too long (3500 characters max).');
}

$db = pw_db();
$postStmt = $db->prepare('SELECT id, comments_enabled FROM news_posts WHERE slug = ?');
$postStmt->execute([$slug]);
$post = $postStmt->fetch();
if (!$post) {
    pw_error('That news article no longer exists.', 404);
}
if (!(bool)$post['comments_enabled']) {
    pw_error('Comments are disabled for this transmission.', 403);
}

$stmt = $db->prepare('INSERT INTO news_comments (news_post_id, user_id, body) VALUES (?, ?, ?)');
$stmt->execute([(int)$post['id'], (int)$user['id'], $body]);
$commentId = (int)$db->lastInsertId();
try {
    pw_award_reputation($db, (int)$user['id'], 1, 'news_comment_posted', ['source_type' => 'news_comment', 'source_id' => $commentId]);
} catch (PDOException $e) {}

pw_json(['ok' => true, 'id' => $commentId]);
