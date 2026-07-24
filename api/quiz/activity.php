<?php
/**
 * Begins one anonymous managed-quiz attempt for the Site Statistics activity
 * card. The browser supplies opaque UUIDs; quiz-helpers hashes them before
 * storage, so this endpoint never persists a raw guest identifier.
 */
require_once __DIR__ . '/quiz-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$input = pw_input();
if (($input['action'] ?? '') !== 'start') {
    pw_error('Unknown quiz activity action.', 400);
}

// This changes only anonymous analytics state and is available to guests, so
// CSRF is not useful here. The UUID pair makes retries idempotent; the quiz
// itself remains server-scored before any completion is stored.
$user = pw_current_user();
$recorded = pw_quiz_track_attempt_start($input, $user ? (int)$user['id'] : null);

pw_json(['ok' => true, 'recorded' => $recorded]);
