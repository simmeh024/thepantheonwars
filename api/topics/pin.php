<?php
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
if (!pw_has_permission($user, 'community.pin')) {
    pw_error('Only the author and moderators can pin topics.', 403);
}

$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    pw_error('Missing topic id.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT id, is_pinned FROM topics WHERE id = ? AND is_deleted = 0');
$stmt->execute([$id]);
$topic = $stmt->fetch();

if (!$topic) {
    pw_error('That topic no longer exists.', 404);
}

$newState = $topic['is_pinned'] ? 0 : 1;
$stmt = $db->prepare('UPDATE topics SET is_pinned = ?, pinned_at = ? WHERE id = ?');
$stmt->execute([$newState, $newState ? date('Y-m-d H:i:s') : null, $id]);

pw_log_admin_activity($newState ? 'topic_pinned' : 'topic_unpinned', ($newState ? 'Pinned' : 'Unpinned') . ' topic #' . $id . '.', $user);

pw_json(['ok' => true, 'isPinned' => (bool)$newState]);
