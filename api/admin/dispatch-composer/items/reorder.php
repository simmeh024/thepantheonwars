<?php
/** Persists a new drag-and-drop order for a Composer draft's attached dispatches. */
require_once __DIR__ . '/../../../helpers.php';
require_once __DIR__ . '/../composer-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('dispatch_composer.edit');
$input = pw_input();
pw_require_csrf($input);

$composerPostId = isset($input['composer_post_id']) ? (int)$input['composer_post_id'] : 0;
$orderedDispatchIds = isset($input['dispatch_ids']) && is_array($input['dispatch_ids']) ? array_map('intval', $input['dispatch_ids']) : null;
if ($composerPostId <= 0 || $orderedDispatchIds === null) {
    pw_error('Missing Composer post id or dispatch order.');
}

$db = pw_db();
pw_composer_require_editable_post($db, $composerPostId);

$attachedStmt = $db->prepare('SELECT dispatch_id FROM dispatch_composer_items WHERE composer_post_id = ?');
$attachedStmt->execute([$composerPostId]);
$attachedIds = array_map('intval', array_column($attachedStmt->fetchAll(), 'dispatch_id'));

// Reject silently-partial reorders: the submitted list must be exactly the
// same set of dispatches already attached, just in a new order.
sort($attachedIds);
$submittedSorted = $orderedDispatchIds;
sort($submittedSorted);
if ($attachedIds !== $submittedSorted) {
    pw_error('The dispatch order does not match this draft\'s attached dispatches. Refresh and try again.', 409);
}

$stmt = $db->prepare('UPDATE dispatch_composer_items SET sort_order = ? WHERE composer_post_id = ? AND dispatch_id = ?');
foreach ($orderedDispatchIds as $index => $dispatchId) {
    $stmt->execute([$index, $composerPostId, $dispatchId]);
}
$db->prepare('UPDATE dispatch_composer_posts SET updated_by = ? WHERE id = ?')->execute([(int)$adminUser['id'], $composerPostId]);

pw_json(['ok' => true]);
