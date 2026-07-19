<?php
require_once __DIR__ . '/helpers.php';
pw_require_permission('quiz.view');
$db = pw_db();
$rows = $db->query(
    'SELECT q.id, q.question_text, q.sort_order, q.is_active, o.score_index, o.option_text
     FROM quiz_questions q LEFT JOIN quiz_options o ON o.question_id = q.id
     ORDER BY q.sort_order ASC, q.id ASC, o.score_index ASC'
)->fetchAll();
$questions = [];
foreach ($rows as $row) {
    $id = (int)$row['id'];
    if (!isset($questions[$id])) $questions[$id] = ['id' => $id, 'question_text' => $row['question_text'], 'sort_order' => (int)$row['sort_order'], 'is_active' => (bool)$row['is_active'], 'options' => array_fill(0, 6, '')];
    if ($row['score_index'] !== null) $questions[$id]['options'][(int)$row['score_index']] = $row['option_text'];
}
pw_json(['ok' => true, 'questions' => array_values($questions)]);
