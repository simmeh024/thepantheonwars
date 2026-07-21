<?php
/**
 * Explicit Good/Bad quality feedback an admin can leave on any published
 * translation. Purely observational -- never read by the translator itself,
 * never changes a translation's text, confidence, or auto-publication
 * decision. One row per (dispatch, rater); re-rating overwrites your own
 * prior vote but never another rater's.
 */
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('dispatch_translations.edit');
$input = pw_input();
pw_require_csrf($input);

$dispatchId = isset($input['dispatch_id']) ? (int)$input['dispatch_id'] : 0;
$rating = isset($input['rating']) ? trim((string)$input['rating']) : '';

if ($dispatchId <= 0) {
    pw_error('Missing dispatch id.');
}
if (!in_array($rating, ['good', 'bad'], true)) {
    pw_error('Rating must be "good" or "bad".');
}

$db = pw_db();

$stmt = $db->prepare('SELECT 1 FROM dispatch_translations WHERE dispatch_id = ?');
$stmt->execute([$dispatchId]);
if (!$stmt->fetch()) {
    pw_error('That dispatch has no published translation to rate.', 404);
}

try {
    $existingStmt = $db->prepare('SELECT rating FROM dispatch_translation_feedback WHERE dispatch_id = ? AND rated_by_user_id = ?');
    $existingStmt->execute([$dispatchId, $adminUser['id']]);
    $existingRating = $existingStmt->fetchColumn();

    // Clicking the same rating again removes your own vote entirely, rather
    // than being a no-op -- the expected toggle behavior for a rating button.
    if ($existingRating === $rating) {
        $deleteStmt = $db->prepare('DELETE FROM dispatch_translation_feedback WHERE dispatch_id = ? AND rated_by_user_id = ?');
        $deleteStmt->execute([$dispatchId, $adminUser['id']]);
        $rating = null;
    } else {
        $stmt = $db->prepare(
            'INSERT INTO dispatch_translation_feedback (dispatch_id, rating, rated_by_user_id, rated_by_username)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE rating = VALUES(rating)'
        );
        $stmt->execute([$dispatchId, $rating, $adminUser['id'], $adminUser['username']]);
    }
} catch (PDOException $e) {
    pw_error('Quality feedback is not set up yet. Run the pending SQL migration first.', 500);
}

$tallyStmt = $db->prepare(
    "SELECT
        SUM(rating = 'good') AS good,
        SUM(rating = 'bad') AS bad
     FROM dispatch_translation_feedback
     WHERE dispatch_id = ?"
);
$tallyStmt->execute([$dispatchId]);
$tally = $tallyStmt->fetch();

pw_json([
    'ok' => true,
    'feedback' => [
        'good' => (int)($tally['good'] ?? 0),
        'bad' => (int)($tally['bad'] ?? 0),
        'my_rating' => $rating,
    ],
]);
