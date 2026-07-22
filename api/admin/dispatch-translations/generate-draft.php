<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../dispatch-translation-drafts.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('dispatch_translations.edit');
$input = pw_input();
pw_require_csrf($input);

$dispatchId = isset($input['dispatch_id']) ? (int)$input['dispatch_id'] : 0;
if ($dispatchId <= 0) {
    pw_error('Missing dispatch id.');
}

$db = pw_db();

// Preview mode writes nothing at all. It exists so an editor can re-run the
// engine over a dispatch that already has an approved translation and see the
// proposed wording in the editor, while the live public text stays untouched
// until they explicitly save. Previously the only route was to delete the
// published translation first, which took the public page down in between.
if (!empty($input['preview'])) {
    $preview = pw_dispatch_preview_translation_draft($db, $dispatchId);
    if (empty($preview['ok'])) {
        pw_error('That dispatch no longer exists.', 404);
    }
    pw_json([
        'ok' => true,
        'preview' => true,
        'draft' => $preview['draft'],
        'confidence' => $preview['confidence'],
        'auto_published' => false,
        'requires_editor_review' => !empty($preview['requires_editor_review']),
        'best_semantic_match' => $preview['best_semantic_match'] ?? [],
    ]);
}

try {
    $result = pw_create_dispatch_translation_draft($db, $dispatchId);
} catch (PDOException $e) {
    pw_error('Draft storage is not available yet. Run migration_dispatch_translation_drafts.sql first.', 503);
}

if (empty($result['ok'])) {
    pw_error('This dispatch already has an approved translation or no longer exists.', 409);
}

if (!empty($result['auto_published'])) {
    pw_json([
        'ok' => true,
        'auto_published' => true,
        'translation' => $result['translation'],
        'confidence' => $result['confidence'],
        'requires_editor_review' => false,
        'best_semantic_match' => $result['best_semantic_match'] ?? [],
    ]);
}

$draftStmt = $db->prepare(
    'SELECT dtd.draft, dtd.updated_at, d.subject, d.body, d.tag
     FROM dispatch_translation_drafts dtd
     INNER JOIN dispatch_entries d ON d.id = dtd.dispatch_id
     WHERE dtd.dispatch_id = ?'
);
$draftStmt->execute([$dispatchId]);
$draft = $draftStmt->fetch();
if (!$draft) {
    pw_error('The draft could not be loaded after generation.', 500);
}

pw_json([
    'ok' => true,
    'draft' => $draft['draft'],
    'updated_at' => $draft['updated_at'],
    'confidence' => $result['confidence'],
    'auto_published' => false,
    'requires_editor_review' => !empty($result['requires_editor_review']),
    'best_semantic_match' => $result['best_semantic_match'] ?? [],
]);
