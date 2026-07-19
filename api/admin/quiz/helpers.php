<?php
require_once __DIR__ . '/../../helpers.php';

function pw_quiz_question_input(array $input): array {
    $question = trim((string)($input['question_text'] ?? ''));
    $sortOrder = isset($input['sort_order']) ? (int)$input['sort_order'] : 0;
    $options = isset($input['options']) && is_array($input['options']) ? array_values($input['options']) : [];
    if ($question === '' || mb_strlen($question) > 500) pw_error('Question text must be between 1 and 500 characters.');
    if (count($options) !== 6) pw_error('A quiz question needs all six Overlord options.');
    $clean = [];
    foreach ($options as $index => $option) {
        $option = trim((string)$option);
        if ($option === '' || mb_strlen($option) > 1000) pw_error('Each answer must be between 1 and 1,000 characters.');
        $clean[$index] = $option;
    }
    return ['question_text' => $question, 'sort_order' => max(0, $sortOrder), 'is_active' => !empty($input['is_active']) ? 1 : 0, 'options' => $clean];
}
