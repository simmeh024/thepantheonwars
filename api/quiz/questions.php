<?php
require_once __DIR__ . '/../helpers.php';

$db = pw_db();
try {
    $rows = $db->query(
        'SELECT q.id, q.question_text, q.sort_order, o.score_index, o.option_text
         FROM quiz_questions q
         JOIN quiz_options o ON o.question_id = q.id
         WHERE q.is_active = 1
         ORDER BY q.sort_order ASC, q.id ASC, o.score_index ASC'
    )->fetchAll();
} catch (PDOException $e) {
    // The public quiz keeps its legacy questions until its optional content
    // migration has been run.
    pw_json(['ok' => true, 'questions' => []]);
}

$questions = [];
foreach ($rows as $row) {
    $id = (int)$row['id'];
    if (!isset($questions[$id])) {
        $questions[$id] = ['id' => $id, 'q' => $row['question_text'], 'options' => []];
    }
    $questions[$id]['options'][] = ['text' => $row['option_text'], 'score_index' => (int)$row['score_index']];
}
// Ignore incomplete records: Quiz Control only publishes questions with the
// full six-option score mapping.
$questions = array_values(array_filter($questions, function ($question) {
    return count($question['options']) === 6;
}));
pw_json(['ok' => true, 'questions' => $questions]);
