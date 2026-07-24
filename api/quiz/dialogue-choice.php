<?php
require_once __DIR__ . '/dialogue-runtime-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$slug = trim((string)($input['overlord'] ?? ''));
$choiceId = trim((string)($input['choice_id'] ?? ''));
if (!preg_match('/\A[a-z0-9-]{1,100}\z/', $slug) || !preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/', $choiceId)) {
    pw_error('That dialogue choice is invalid.');
}

$db = pw_db();
$published = pw_dialogue_runtime_published_tree($db, $slug);
if (!$published) pw_error('This dialogue is not currently available.', 404);
try {
    $result = pw_dialogue_runtime_apply_choice($db, $user, $published, $choiceId);
    pw_json(['ok' => true] + $result);
} catch (Throwable $e) {
    pw_error('This dialogue choice could not be preserved right now. Please try again.', 503);
}
