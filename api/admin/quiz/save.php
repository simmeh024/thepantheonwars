<?php
require_once __DIR__ . '/helpers.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$admin = pw_require_permission('quiz.edit');
$input = pw_input(); pw_require_csrf($input);
$data = pw_quiz_question_input($input);
$id = isset($input['id']) ? (int)$input['id'] : 0;
$db = pw_db();
try {
    $db->beginTransaction();
    if ($id > 0) {
        $check = $db->prepare('SELECT id FROM quiz_questions WHERE id = ?'); $check->execute([$id]);
        if (!$check->fetch()) pw_error('Quiz question not found.', 404);
        $stmt = $db->prepare('UPDATE quiz_questions SET question_text = ?, sort_order = ?, is_active = ? WHERE id = ?');
        $stmt->execute([$data['question_text'], $data['sort_order'], $data['is_active'], $id]);
        $db->prepare('DELETE FROM quiz_options WHERE question_id = ?')->execute([$id]);
        $action = 'quiz_question_updated';
    } else {
        if ($data['sort_order'] === 0) $data['sort_order'] = (int)$db->query('SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_order FROM quiz_questions')->fetch()['next_order'];
        $stmt = $db->prepare('INSERT INTO quiz_questions (question_text, sort_order, is_active) VALUES (?, ?, ?)');
        $stmt->execute([$data['question_text'], $data['sort_order'], $data['is_active']]);
        $id = (int)$db->lastInsertId(); $action = 'quiz_question_created';
    }
    $optionStmt = $db->prepare('INSERT INTO quiz_options (question_id, score_index, option_text) VALUES (?, ?, ?)');
    foreach ($data['options'] as $index => $option) $optionStmt->execute([$id, $index, $option]);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    pw_error('Quiz question could not be saved. Please try again.', 503);
}
pw_log_admin_activity($action, ($action === 'quiz_question_created' ? 'Created' : 'Updated') . ' a quiz question.', $admin);
pw_json(['ok' => true, 'id' => $id]);
