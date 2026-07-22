<?php
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../quiz/quiz-helpers.php';

const PW_QUIZ_MIN_OPTIONS = 2;
const PW_QUIZ_MAX_OPTIONS = 12;
const PW_QUIZ_MAX_WEIGHT = 9;

/**
 * Validates a Quiz Control question payload.
 *
 * Two shapes are accepted so a deploy landing ahead of
 * sql/migration_quiz_enhancements.sql keeps working:
 *
 *  - weighted (post-migration): 2-12 options, each with a six-slot weight
 *    vector, so one answer can resonate with several Overlords at once.
 *  - legacy (pre-migration): exactly six options, one per Overlord, each
 *    scoring a single point -- the original model.
 *
 * Both arrive from the admin console in the same {text, weights} form; the
 * legacy branch just ignores the weights and uses option position, exactly as
 * score_index did before.
 */
function pw_quiz_question_input(array $input): array {
    $question = trim((string)($input['question_text'] ?? ''));
    if ($question === '' || mb_strlen($question) > 500) {
        pw_error('Question text must be between 1 and 500 characters.');
    }

    $weighted = pw_quiz_capabilities()['weights'];
    $rawOptions = isset($input['options']) && is_array($input['options']) ? array_values($input['options']) : [];

    if ($weighted) {
        if (count($rawOptions) < PW_QUIZ_MIN_OPTIONS || count($rawOptions) > PW_QUIZ_MAX_OPTIONS) {
            pw_error('A question needs between ' . PW_QUIZ_MIN_OPTIONS . ' and ' . PW_QUIZ_MAX_OPTIONS . ' answers.');
        }
    } elseif (count($rawOptions) !== 6) {
        pw_error('A quiz question needs all six Overlord options.');
    }

    $options = [];
    $questionHasWeight = false;

    foreach ($rawOptions as $position => $raw) {
        // Accept a bare string so an older client payload still validates.
        $text = is_array($raw) ? trim((string)($raw['text'] ?? '')) : trim((string)$raw);
        if ($text === '' || mb_strlen($text) > 1000) {
            pw_error('Each answer must be between 1 and 1,000 characters.');
        }

        $id = is_array($raw) && isset($raw['id']) ? (int)$raw['id'] : 0;

        $weights = [];
        if ($weighted) {
            $rawWeights = is_array($raw) && isset($raw['weights']) && is_array($raw['weights']) ? array_values($raw['weights']) : [];
            foreach ($rawWeights as $index => $weight) {
                if ($index > 5) {
                    break;
                }
                $weight = (int)$weight;
                if ($weight < 0 || $weight > PW_QUIZ_MAX_WEIGHT) {
                    pw_error('Each Overlord weight must be between 0 and ' . PW_QUIZ_MAX_WEIGHT . '.');
                }
                if ($weight > 0) {
                    $weights[$index] = $weight;
                    $questionHasWeight = true;
                }
            }
        } else {
            // Legacy: position is the Overlord, worth one point.
            $weights = [$position => 1];
            $questionHasWeight = true;
        }

        $options[] = [
            'id'      => $id > 0 ? $id : null,
            'text'    => $text,
            'weights' => $weights,
            // Kept in step with the weights so a legacy reader (and the
            // migration's weight backfill) still resolves the row. NULL when an
            // answer deliberately scores nothing.
            'score_index' => pw_quiz_dominant_score_index($weights),
            'sort_order'  => $position,
        ];
    }

    if (!$questionHasWeight) {
        pw_error('At least one answer must carry an Overlord weight, or the question cannot score.');
    }

    return [
        'question_text' => $question,
        'sort_order'    => max(0, isset($input['sort_order']) ? (int)$input['sort_order'] : 0),
        'is_active'     => !empty($input['is_active']) ? 1 : 0,
        'options'       => $options,
    ];
}

/**
 * The Overlord an answer leans towards most, or NULL when it scores nothing.
 * Ties resolve to the lowest index, matching how the scorer breaks ties.
 */
function pw_quiz_dominant_score_index(array $weights): ?int {
    $best = null;
    $bestWeight = 0;
    foreach ($weights as $index => $weight) {
        if ($weight > $bestWeight) {
            $best = (int)$index;
            $bestWeight = $weight;
        }
    }
    return $best;
}
