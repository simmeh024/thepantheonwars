<?php
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('dispatch_translations.edit');
$input = pw_input();
pw_require_csrf($input);

$dispatchId = isset($input['dispatch_id']) ? (int)$input['dispatch_id'] : 0;
$translation = isset($input['translation']) ? trim($input['translation']) : '';

if ($dispatchId <= 0) {
    pw_error('Missing dispatch id.');
}
if ($translation === '') {
    pw_error('Translation text can\'t be empty.');
}
if (strlen($translation) > 5000) {
    pw_error('Translation is too long (5000 characters max).');
}

$db = pw_db();

$stmt = $db->prepare('SELECT sha, subject FROM dispatch_entries WHERE id = ?');
$stmt->execute([$dispatchId]);
$dispatch = $stmt->fetch();
if (!$dispatch) {
    pw_error('That dispatch no longer exists.', 404);
}

$stmt = $db->prepare('SELECT id FROM dispatch_translations WHERE dispatch_id = ?');
$stmt->execute([$dispatchId]);
$isUpdate = (bool)$stmt->fetch();

$stmt = $db->prepare(
    'INSERT INTO dispatch_translations (dispatch_id, sha, translation)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE sha = VALUES(sha), translation = VALUES(translation)'
);
$stmt->execute([$dispatchId, $dispatch['sha'], $translation]);

// Saving is explicit editorial approval. Remove any local rule-based draft
// only after the approved text has been written successfully.
try {
    $draftStmt = $db->prepare('DELETE FROM dispatch_translation_drafts WHERE dispatch_id = ?');
    $draftStmt->execute([$dispatchId]);
} catch (PDOException $e) {
    // Backward-compatible while the optional draft migration is pending.
}

pw_log_admin_activity(
    $isUpdate ? 'translation_updated' : 'translation_added',
    ($isUpdate ? 'Updated' : 'Added') . ' the BH-4 translation for "' . $dispatch['subject'] . '" (' . substr($dispatch['sha'], 0, 7) . ').',
    $adminUser
);

pw_json(['ok' => true]);
