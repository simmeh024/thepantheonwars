<?php
/**
 * Scores an answer set and records its anonymous activity outcome.
 *
 * Scoring moved server-side so a result can no longer be forged, which also
 * means the client can no longer work out its own result -- it never receives
 * the Overlord weights. A signed-out visitor therefore needs somewhere to send
 * answers, and api/save-quiz-result.php requires a login for personal history.
 *
 * It never writes a quiz_results row, affinity, icon unlock, or reputation.
 * Those personal member effects remain exclusive to the authenticated path.
 */

require_once __DIR__ . '/quiz-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$input = pw_input();

// No CSRF check: this public endpoint deliberately accepts guest answers and
// writes only an opaque, idempotent analytics row. Requiring a token would
// create a PHP session for every anonymous quiz taker without protecting any
// account state; the final outcome is still computed server-side.
$submitted = isset($input['answers']) && is_array($input['answers']) ? $input['answers'] : null;
if ($submitted === null) {
    pw_error('Answers are required.');
}
if (count($submitted) > 200) {
    pw_error('Too many answers.');
}

$result = pw_quiz_score_answers($submitted);
$cast = pw_quiz_overlord_cast();
$winner = $result['winner'];
$overlord = isset($cast[$winner]) ? $cast[$winner]['name'] : '';

// Guest outcomes are stored only as an aggregate-friendly, hashed-browser
// attempt. This has no raw visitor id or answer payload, and it is intentionally
// optional while the migration is awaiting deployment.
$currentUser = pw_current_user();
pw_quiz_track_attempt_completion($input, $currentUser ? (int)$currentUser['id'] : null, $overlord);

pw_json([
    'ok'           => true,
    'overlord'     => $overlord,
    'score_index'  => $winner,
    'scores'       => $result['scores'],
    'distribution' => pw_quiz_affinity_distribution(),
    'saved'        => false,
]);
