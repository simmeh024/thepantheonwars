<?php
/** Attaches several approved dispatches to a Composer draft in one ordered operation. */
require_once __DIR__ . '/../../../helpers.php';
require_once __DIR__ . '/../composer-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('dispatch_composer.edit');
$input = pw_input();
pw_require_csrf($input);

$composerPostId = isset($input['composer_post_id']) ? (int)$input['composer_post_id'] : 0;
$dispatchIdsInput = isset($input['dispatch_ids']) && is_array($input['dispatch_ids']) ? $input['dispatch_ids'] : [];
$dispatchIds = [];
foreach ($dispatchIdsInput as $dispatchId) {
    if (!is_scalar($dispatchId)) {
        continue;
    }
    $dispatchId = (int)$dispatchId;
    if ($dispatchId > 0) {
        $dispatchIds[$dispatchId] = $dispatchId;
    }
}
$dispatchIds = array_values($dispatchIds);

if ($composerPostId <= 0 || !$dispatchIds) {
    pw_error('Choose at least one dispatch to add.');
}
if (count($dispatchIds) > 200) {
    pw_error('You can add up to 200 dispatches at once.');
}

$db = pw_db();
pw_composer_require_editable_post($db, $composerPostId);

$placeholders = implode(',', array_fill(0, count($dispatchIds), '?'));
$dispatchStmt = $db->prepare(
    "SELECT d.id, d.subject
     FROM dispatch_entries d
     JOIN dispatch_translations dt ON dt.dispatch_id = d.id
     WHERE d.id IN ($placeholders)"
);
$dispatchStmt->execute($dispatchIds);
$dispatchRows = $dispatchStmt->fetchAll();
if (count($dispatchRows) !== count($dispatchIds)) {
    pw_error('One or more selected dispatches no longer have an approved translation.', 409);
}

$existingStmt = $db->prepare(
    "SELECT dispatch_id FROM dispatch_composer_items
     WHERE composer_post_id = ? AND dispatch_id IN ($placeholders)"
);
$existingStmt->execute(array_merge([$composerPostId], $dispatchIds));
$existingIds = array_flip(array_map('intval', array_column($existingStmt->fetchAll(), 'dispatch_id')));
$newDispatchIds = array_values(array_filter($dispatchIds, function ($dispatchId) use ($existingIds) {
    return !isset($existingIds[$dispatchId]);
}));

if ($newDispatchIds) {
    try {
        $db->beginTransaction();
        $nextOrderStmt = $db->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_order
             FROM dispatch_composer_items WHERE composer_post_id = ?'
        );
        $nextOrderStmt->execute([$composerPostId]);
        $nextOrder = (int)$nextOrderStmt->fetch()['next_order'];

        $insertStmt = $db->prepare(
            'INSERT INTO dispatch_composer_items (composer_post_id, dispatch_id, sort_order) VALUES (?, ?, ?)'
        );
        foreach ($newDispatchIds as $dispatchId) {
            $insertStmt->execute([$composerPostId, $dispatchId, $nextOrder]);
            $nextOrder++;
        }
        $db->prepare('UPDATE dispatch_composer_posts SET updated_by = ? WHERE id = ?')
            ->execute([(int)$adminUser['id'], $composerPostId]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        pw_error('Could not add the selected dispatches. Please try again.', 500);
    }

    pw_log_admin_activity(
        'dispatch_composer_dispatches_attached',
        'Attached ' . count($newDispatchIds) . ' dispatch' . (count($newDispatchIds) === 1 ? '' : 'es')
            . ' to Composer draft #' . $composerPostId . '.',
        $adminUser
    );
}

pw_json([
    'ok' => true,
    'attached_count' => count($newDispatchIds),
    'already_attached_count' => count($dispatchIds) - count($newDispatchIds),
]);
