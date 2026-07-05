<?php
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
if (!in_array($user['role'], ['admin', 'moderator'], true)) {
    pw_error('Only moderators can move topics.', 403);
}

$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
$board = isset($input['board']) ? trim($input['board']) : '';

if ($id <= 0) {
    pw_error('Missing topic id.');
}

// Keep this in sync with the BOARDS list in community.html.
$validBoards = ['announcements', 'assembly', 'offworld'];
if (!in_array($board, $validBoards, true)) {
    pw_error('Unknown board.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT id, board FROM topics WHERE id = ? AND is_deleted = 0');
$stmt->execute([$id]);
$topic = $stmt->fetch();

if (!$topic) {
    pw_error('That topic no longer exists.', 404);
}

if ($topic['board'] === $board) {
    pw_json(['ok' => true, 'board' => $board]);
}

$stmt = $db->prepare('UPDATE topics SET board = ? WHERE id = ?');
$stmt->execute([$board, $id]);

pw_json(['ok' => true, 'board' => $board]);
