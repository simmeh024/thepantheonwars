<?php
require_once __DIR__ . '/helpers.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$admin = pw_require_permission('quiz.edit');
$input = pw_input(); pw_require_csrf($input);
$data = pw_quiz_question_input($input);
$id = isset($input['id']) ? (int)$input['id'] : 0;
$caps = pw_quiz_capabilities();
$db = pw_db();

try {
    $db->beginTransaction();

    if ($id > 0) {
        $check = $db->prepare('SELECT id FROM quiz_questions WHERE id = ?'); $check->execute([$id]);
        if (!$check->fetch()) pw_error('Quiz question not found.', 404);
        $stmt = $db->prepare('UPDATE quiz_questions SET question_text = ?, sort_order = ?, is_active = ? WHERE id = ?');
        $stmt->execute([$data['question_text'], $data['sort_order'], $data['is_active'], $id]);
        $action = 'quiz_question_updated';
    } else {
        if ($data['sort_order'] === 0) $data['sort_order'] = (int)$db->query('SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_order FROM quiz_questions')->fetch()['next_order'];
        $stmt = $db->prepare('INSERT INTO quiz_questions (question_text, sort_order, is_active) VALUES (?, ?, ?)');
        $stmt->execute([$data['question_text'], $data['sort_order'], $data['is_active']]);
        $id = (int)$db->lastInsertId(); $action = 'quiz_question_created';
    }

    // Options are matched by id and updated in place rather than deleted and
    // re-inserted. quiz_result_answers references quiz_options with ON DELETE
    // CASCADE, so recreating the rows on every save -- which is what this used
    // to do -- would discard the answer history behind Quiz Control's own
    // answer-distribution report each time an admin fixed a typo.
    $existing = [];
    $existingStmt = $db->prepare('SELECT id FROM quiz_options WHERE question_id = ?');
    $existingStmt->execute([$id]);
    foreach ($existingStmt->fetchAll() as $row) {
        $existing[(int)$row['id']] = true;
    }

    $hasSortOrder = $caps['sort_order'];
    $updateSql = $hasSortOrder
        ? 'UPDATE quiz_options SET option_text = ?, score_index = ?, sort_order = ? WHERE id = ? AND question_id = ?'
        : 'UPDATE quiz_options SET option_text = ?, score_index = ? WHERE id = ? AND question_id = ?';
    $insertSql = $hasSortOrder
        ? 'INSERT INTO quiz_options (question_id, option_text, score_index, sort_order) VALUES (?, ?, ?, ?)'
        : 'INSERT INTO quiz_options (question_id, option_text, score_index) VALUES (?, ?, ?)';
    $updateStmt = $db->prepare($updateSql);
    $insertStmt = $db->prepare($insertSql);

    $keptIds = [];
    $optionIds = [];
    foreach ($data['options'] as $option) {
        if ($option['id'] !== null && isset($existing[$option['id']])) {
            $params = $hasSortOrder
                ? [$option['text'], $option['score_index'], $option['sort_order'], $option['id'], $id]
                : [$option['text'], $option['score_index'], $option['id'], $id];
            $updateStmt->execute($params);
            $optionId = $option['id'];
        } else {
            $params = $hasSortOrder
                ? [$id, $option['text'], $option['score_index'], $option['sort_order']]
                : [$id, $option['text'], $option['score_index']];
            $insertStmt->execute($params);
            $optionId = (int)$db->lastInsertId();
        }
        $keptIds[$optionId] = true;
        $optionIds[$optionId] = $option['weights'];
    }

    $removed = array_diff_key($existing, $keptIds);
    if ($removed) {
        $placeholders = implode(',', array_fill(0, count($removed), '?'));
        $db->prepare('DELETE FROM quiz_options WHERE question_id = ? AND id IN (' . $placeholders . ')')
           ->execute(array_merge([$id], array_keys($removed)));
    }

    if ($caps['weights']) {
        $clearStmt = $db->prepare('DELETE FROM quiz_option_weights WHERE option_id = ?');
        $weightStmt = $db->prepare('INSERT INTO quiz_option_weights (option_id, score_index, weight) VALUES (?, ?, ?)');
        foreach ($optionIds as $optionId => $weights) {
            $clearStmt->execute([$optionId]);
            foreach ($weights as $index => $weight) {
                $weightStmt->execute([$optionId, $index, $weight]);
            }
        }
    }

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    pw_error('Quiz question could not be saved. Please try again.', 503);
}

pw_log_admin_activity($action, ($action === 'quiz_question_created' ? 'Created' : 'Updated') . ' a quiz question.', $admin);
pw_json(['ok' => true, 'id' => $id]);
