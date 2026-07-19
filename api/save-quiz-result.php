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

// Index-matched to $validOverlords -- see pw_overlord_icon_keys() in
// api/helpers.php, the single source of truth for the fixed icon catalog.
$iconKeys = pw_overlord_icon_keys();

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
        pw_award_reputation($db, (int)$user['id'], 10, 'quiz_completed', ['source_type' => 'quiz_result']);
    } catch (PDOException $e) {
        // migration_reputation.sql may be run after code deployment.
    }
}

// Pure Resonance (100%): a score total entirely on one overlord (all other
// scores at 0), the same "tier-pure" threshold quiz.html's own client-side
// resonance display already uses. Unlocks that overlord's icon.
$total = array_sum($scores);
if ($total > 0) {
    foreach ($scores as $i => $s) {
        if ($s === $total && isset($iconKeys[$i])) {
            pw_unlock_overlord_icon((int)$user['id'], $iconKeys[$i], $validOverlords[$i]);
        }
    }
}

pw_json(['ok' => true]);
