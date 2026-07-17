<?php
/**
 * Server-synced unread tracking: upserts a "last seen" timestamp for the
 * current user against either a board (scope=board, board=<slug>) or a
 * topic (scope=topic, topic_id=<id>). Mirrors the existing client-side
 * localStorage shape 1:1 -- see community.html's loadSeenStore() -- so
 * logged-in members get a read state that survives across devices while
 * guests keep the localStorage-only fallback (this endpoint requires login).
 */
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();

$input = pw_input();
pw_require_csrf($input);

$scope = isset($input['scope']) ? trim((string)$input['scope']) : '';
$now = date('Y-m-d H:i:s');
$db = pw_db();

if ($scope === 'board') {
    $board = isset($input['board']) ? trim((string)$input['board']) : '';
    if (!preg_match('/^[a-z0-9\-]{1,50}$/', $board)) {
        pw_error('Unknown board.');
    }
    $boardRow = pw_forum_board_by_slug($board);
    if (!$boardRow || !pw_can_see_board($user, $boardRow)) {
        pw_error('Unknown board.');
    }
    $stmt = $db->prepare(
        'INSERT INTO forum_board_seen (user_id, board_slug, seen_at) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE seen_at = VALUES(seen_at)'
    );
    $stmt->execute([(int)$user['id'], $board, $now]);
} elseif ($scope === 'topic') {
    $topicId = isset($input['topic_id']) ? (int)$input['topic_id'] : 0;
    if ($topicId <= 0) {
        pw_error('Missing topic id.');
    }
    $stmt = $db->prepare('SELECT id FROM topics WHERE id = ? AND is_deleted = 0');
    $stmt->execute([$topicId]);
    if (!$stmt->fetch()) {
        pw_error('That topic no longer exists.', 404);
    }
    $stmt = $db->prepare(
        'INSERT INTO forum_topic_seen (user_id, topic_id, seen_at) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE seen_at = VALUES(seen_at)'
    );
    $stmt->execute([(int)$user['id'], $topicId, $now]);
} else {
    pw_error('Unknown scope.');
}

pw_json(['ok' => true]);
