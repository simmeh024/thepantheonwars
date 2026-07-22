<?php
/**
 * Scores an answer set without storing anything.
 *
 * Scoring moved server-side so a result can no longer be forged, which also
 * means the client can no longer work out its own result -- it never receives
 * the Overlord weights. A signed-out visitor therefore needs somewhere to send
 * answers, and api/save-quiz-result.php requires a login.
 *
 * Writes nothing: no quiz_results row, no affinity, no icon unlock, no
 * reputation. Those all belong to the authenticated save path.
 */

require_once __DIR__ . '/quiz-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$input = pw_input();

// No CSRF check: this endpoint changes no state and is deliberately reachable
// without a session, so requiring a token would only force a session to be
// created for every anonymous quiz taker.
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

pw_json([
    'ok'           => true,
    'overlord'     => isset($cast[$winner]) ? $cast[$winner]['name'] : '',
    'score_index'  => $winner,
    'scores'       => $result['scores'],
    'distribution' => pw_quiz_affinity_distribution(),
    'saved'        => false,
]);
