<?php
// Sets one member-owned reading status. Setting a book to "reading" moves
// the previous active book back to Not Started so public profiles always have
// one unambiguous current title (or none when the member clears it).
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);

$bookId = isset($input['book_id']) ? (int)$input['book_id'] : 0;
$status = isset($input['status']) ? (string)$input['status'] : '';
$allowedStatuses = ['not_started', 'reading', 'finished'];
if (!in_array($status, $allowedStatuses, true)) {
    pw_error('Choose a valid reading status.');
}

$db = pw_db();

// The clear action is intentionally bookless: it only clears the active
// title, keeping a member's completed/not-started markers intact.
if ($bookId <= 0) {
    if ($status !== 'not_started') {
        pw_error('Choose a book first.');
    }
    $stmt = $db->prepare("UPDATE user_book_progress SET status = 'not_started' WHERE user_id = ? AND status = 'reading'");
    $stmt->execute([(int)$user['id']]);
    pw_json(['ok' => true, 'current_book_id' => null]);
}

$bookStmt = $db->prepare('SELECT id FROM books WHERE id = ?');
$bookStmt->execute([$bookId]);
if (!$bookStmt->fetch()) {
    pw_error('That book is no longer available.', 404);
}

try {
    $db->beginTransaction();
    $existingStmt = $db->prepare(
        'SELECT status, started_at, finished_at FROM user_book_progress WHERE user_id = ? AND book_id = ? FOR UPDATE'
    );
    $existingStmt->execute([(int)$user['id'], $bookId]);
    $existing = $existingStmt->fetch();

    // A book can award each milestone once. The timestamp columns, rather
    // than the current status, are the source of truth so a member can freely
    // revisit a completed book without farming reputation by toggling states.
    $reputationAwarded = 0;
    if ($status === 'reading' && (!$existing || $existing['started_at'] === null)) {
        $reputationAwarded = 3;
    } elseif ($status === 'finished' && (!$existing || $existing['finished_at'] === null)) {
        $reputationAwarded = 5;
    }

    if ($status === 'reading') {
        $clearStmt = $db->prepare("UPDATE user_book_progress SET status = 'not_started' WHERE user_id = ? AND status = 'reading' AND book_id <> ?");
        $clearStmt->execute([(int)$user['id'], $bookId]);
    }
    $saveStmt = $db->prepare(
        "INSERT INTO user_book_progress (user_id, book_id, status, started_at, finished_at)
         VALUES (?, ?, ?, CASE WHEN ? = 'reading' THEN CURRENT_TIMESTAMP ELSE NULL END, CASE WHEN ? = 'finished' THEN CURRENT_TIMESTAMP ELSE NULL END)
         ON DUPLICATE KEY UPDATE
           status = VALUES(status),
           started_at = CASE WHEN VALUES(status) = 'reading' AND started_at IS NULL THEN CURRENT_TIMESTAMP ELSE started_at END,
           finished_at = CASE WHEN VALUES(status) = 'finished' AND finished_at IS NULL THEN CURRENT_TIMESTAMP ELSE finished_at END,
           updated_at = CURRENT_TIMESTAMP"
    );
    $saveStmt->execute([(int)$user['id'], $bookId, $status, $status, $status]);
    if ($reputationAwarded > 0) {
        $rewardKey = $status === 'finished' ? 'book_finished' : 'book_started';
        $reputationAwarded = pw_award_reputation($db, (int)$user['id'], $reputationAwarded, $rewardKey, ['source_type' => 'book', 'source_id' => $bookId]);
    }
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    pw_error('Could not save your reading progress right now.', 500);
}

pw_json([
    'ok' => true,
    'current_book_id' => $status === 'reading' ? $bookId : null,
    'reputation_awarded' => $reputationAwarded,
]);
