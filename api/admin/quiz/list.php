<?php
require_once __DIR__ . '/helpers.php';
pw_require_permission('quiz.view');

$caps = pw_quiz_capabilities();
$db = pw_db();

$optionOrder = $caps['sort_order'] ? 'o.sort_order ASC, o.id ASC' : 'o.score_index ASC, o.id ASC';
$rows = $db->query(
    'SELECT q.id, q.question_text, q.sort_order, q.is_active,
            o.id AS option_id, o.option_text, o.score_index
     FROM quiz_questions q
     LEFT JOIN quiz_options o ON o.question_id = q.id
     ORDER BY q.sort_order ASC, q.id ASC, ' . $optionOrder
)->fetchAll();

$optionIds = [];
foreach ($rows as $row) {
    if ($row['option_id'] !== null) {
        $optionIds[] = (int)$row['option_id'];
    }
}
$weights = pw_quiz_option_weights($optionIds);

// How often each answer was actually chosen. A question where nearly everyone
// picks the same option carries no signal and is worth rewriting -- there was
// previously no way to tell which questions those were.
$responses = [];
if ($caps['answers']) {
    try {
        foreach ($db->query('SELECT question_id, option_id, COUNT(*) AS cnt FROM quiz_result_answers GROUP BY question_id, option_id')->fetchAll() as $row) {
            $responses[(int)$row['option_id']] = (int)$row['cnt'];
        }
    } catch (PDOException $e) {
        $responses = [];
    }
}

$questions = [];
foreach ($rows as $row) {
    $id = (int)$row['id'];
    if (!isset($questions[$id])) {
        $questions[$id] = [
            'id'            => $id,
            'question_text' => $row['question_text'],
            'sort_order'    => (int)$row['sort_order'],
            'is_active'     => (bool)$row['is_active'],
            'options'       => [],
            'responses'     => 0,
            'top_share'     => null,
        ];
    }
    if ($row['option_id'] === null) {
        continue;
    }
    $optionId = (int)$row['option_id'];

    // Always a six-slot vector for the admin UI, whether it came from the
    // weights table or from a legacy single score_index.
    $vector = array_fill(0, 6, 0);
    if (isset($weights[$optionId])) {
        foreach ($weights[$optionId] as $index => $weight) {
            $vector[$index] = $weight;
        }
    } elseif ($row['score_index'] !== null) {
        $vector[(int)$row['score_index']] = 1;
    }

    $count = $responses[$optionId] ?? 0;
    $questions[$id]['options'][] = [
        'id'        => $optionId,
        'text'      => $row['option_text'],
        'weights'   => $vector,
        'responses' => $count,
    ];
    $questions[$id]['responses'] += $count;
}

foreach ($questions as $id => $question) {
    if ($question['responses'] > 0) {
        $top = 0;
        foreach ($question['options'] as $option) {
            if ($option['responses'] > $top) {
                $top = $option['responses'];
            }
        }
        $questions[$id]['top_share'] = (int)round(($top / $question['responses']) * 100);
    }
}

pw_json([
    'ok'           => true,
    'capabilities' => $caps,
    'overlords'    => pw_quiz_overlord_cast(),
    'questions'    => array_values($questions),
]);
