<?php
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);

$validOverlords = ['Syn Dravus', 'Malric Thorne', 'Korrus Vale', 'Lysara Venthe', 'Zura Kaleth', 'Maerion Thal'];
$overlord = isset($input['overlord']) ? trim($input['overlord']) : '';

if (!in_array($overlord, $validOverlords, true)) {
    pw_error('Unrecognized result.');
}

$scores = isset($input['scores']) && is_array($input['scores']) ? array_slice(array_map('intval', $input['scores']), 0, 6) : [];
$scoresJson = json_encode($scores);

$db = pw_db();

$countStmt = $db->prepare('SELECT COUNT(*) AS cnt FROM quiz_results WHERE user_id = ?');
$countStmt->execute([$user['id']]);
$isFirstQuiz = (int)$countStmt->fetch()['cnt'] === 0;

$stmt = $db->prepare('INSERT INTO quiz_results (user_id, overlord_result, scores_json) VALUES (?, ?, ?)');
$stmt->execute([$user['id'], $overlord, $scoresJson]);

$stmt = $db->prepare('UPDATE users SET overlord_affinity = ? WHERE id = ?');
$stmt->execute([$overlord, $user['id']]);

// +10 reputation for finishing the quiz, first time only -- retakes
// (Re-Sync Overlord Resonance) never award it again.
if ($isFirstQuiz) {
    try {
        $db->prepare('UPDATE users SET reputation = reputation + 10 WHERE id = ?')->execute([$user['id']]);
    } catch (PDOException $e) {
        // migration_reputation.sql may be run after code deployment.
    }
}

pw_json(['ok' => true]);
