<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/quiz/quiz-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);

// The client submits which option it picked per question; it no longer submits
// its own scores or its own winning Overlord. Both used to be stored unchecked,
// which meant a crafted request could claim any result -- including a Pure
// Resonance icon unlock -- without answering anything.
$submitted = isset($input['answers']) && is_array($input['answers']) ? $input['answers'] : null;
if ($submitted === null) {
    pw_error('Answers are required.');
}
if (count($submitted) > 200) {
    pw_error('Too many answers.');
}

$result = pw_quiz_score_answers($submitted);
$scores = $result['scores'];
$winner = $result['winner'];

$cast = pw_quiz_overlord_cast();
if (!isset($cast[$winner])) {
    pw_error('Unrecognized result.', 503);
}
$overlord = $cast[$winner]['name'];

$iconKeys = pw_overlord_icon_keys();
$caps = pw_quiz_capabilities();
$db = pw_db();

$countStmt = $db->prepare('SELECT COUNT(*) AS cnt FROM quiz_results WHERE user_id = ?');
$countStmt->execute([$user['id']]);
$isFirstQuiz = (int)$countStmt->fetch()['cnt'] === 0;

try {
    $db->beginTransaction();

    $stmt = $db->prepare('INSERT INTO quiz_results (user_id, overlord_result, scores_json) VALUES (?, ?, ?)');
    $stmt->execute([$user['id'], $overlord, json_encode($scores)]);
    $resultId = (int)$db->lastInsertId();

    // Per-question answers back Quiz Control's answer-distribution report.
    // quiz_results only ever kept the six totals, so nothing could show which
    // option readers actually chose.
    if ($caps['answers']) {
        $answerStmt = $db->prepare('INSERT INTO quiz_result_answers (result_id, question_id, option_id) VALUES (?, ?, ?)');
        foreach ($result['answers'] as $questionId => $optionId) {
            $answerStmt->execute([$resultId, $questionId, $optionId]);
        }
    }

    $stmt = $db->prepare('UPDATE users SET overlord_affinity = ? WHERE id = ?');
    $stmt->execute([$overlord, $user['id']]);

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    pw_error('Your result could not be saved. Please try again.', 503);
}

// +10 reputation for finishing the quiz, first time only -- retakes
// (Re-Sync Overlord Resonance) never award it again.
if ($isFirstQuiz) {
    try {
        pw_award_reputation($db, (int)$user['id'], 10, 'quiz_completed', ['source_type' => 'quiz_result', 'source_id' => $resultId]);
    } catch (PDOException $e) {
        // migration_reputation.sql may be run after code deployment.
    }
}

// Pure Resonance (100%): the entire score total sitting on one Overlord, the
// same "tier-pure" threshold the resonance display uses. Unlocks that icon.
// Now derived from server-computed scores, so it can no longer be claimed by
// posting a fabricated total.
$total = $result['total'];
foreach ($scores as $i => $score) {
    if ($score === $total && isset($iconKeys[$i])) {
        pw_unlock_overlord_icon((int)$user['id'], $iconKeys[$i], $cast[$i]['name']);
    }
}

pw_json([
    'ok'           => true,
    'overlord'     => $overlord,
    'score_index'  => $winner,
    'scores'       => $scores,
    'distribution' => pw_quiz_affinity_distribution(),
]);
