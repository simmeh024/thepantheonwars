<?php
require_once __DIR__ . '/helpers.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$admin = pw_require_permission('quiz.delete');
$input = pw_input(); pw_require_csrf($input);
$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) pw_error('Missing quiz question id.');
$stmt = pw_db()->prepare('DELETE FROM quiz_questions WHERE id = ?'); $stmt->execute([$id]);
if (!$stmt->rowCount()) pw_error('Quiz question not found.', 404);
pw_log_admin_activity('quiz_question_deleted', 'Deleted a quiz question.', $admin);
pw_json(['ok' => true]);
