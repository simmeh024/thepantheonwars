<?php
/**
 * Public quiz bootstrap: the active question set, the Overlord cast, and the
 * current resonance distribution, in one request.
 *
 * Deliberately does NOT return each option's Overlord weights. Scoring happens
 * in api/save-quiz-result.php, so the mapping is no longer needed on the
 * client -- and withholding it means a reader can no longer read off which
 * answer belongs to which Overlord before choosing.
 */

require_once __DIR__ . '/quiz-helpers.php';

$questions = pw_quiz_active_questions();

$payload = [];
foreach ($questions as $question) {
    $options = [];
    foreach ($question['options'] as $option) {
        $options[] = ['id' => $option['id'], 'text' => $option['text']];
    }
    $payload[] = ['id' => $question['id'], 'q' => $question['text'], 'options' => $options];
}

pw_json([
    'ok'           => true,
    // False means Quiz Control has nothing publishable and the client should
    // keep using its built-in questions. Those carry no database ids, so a
    // result played against them cannot be scored or saved server-side.
    'managed'      => count($payload) > 0,
    'questions'    => $payload,
    'overlords'    => pw_quiz_overlord_cast(),
    'distribution' => pw_quiz_affinity_distribution(),
]);
