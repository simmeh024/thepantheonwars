<?php
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);

$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$keys = isset($input['achievement_keys']) && is_array($input['achievement_keys']) ? $input['achievement_keys'] : null;
if ($keys === null) pw_error('Choose valid achievements.');

$keys = array_values(array_unique(array_filter(array_map(function ($key) { return is_string($key) ? $key : ''; }, $keys))));
if (count($keys) > 10) pw_error('You can showcase up to ten achievements.');

$catalog = [];
foreach (pw_reputation_achievement_catalog() as $achievement) $catalog[$achievement['key']] = $achievement;
foreach ($keys as $key) if (!isset($catalog[$key])) pw_error('One of those achievements is not valid.');

$db = pw_db();
try {
    $unlockedStmt = $db->prepare('SELECT achievement_key FROM user_reputation_achievements WHERE user_id = ?');
    $unlockedStmt->execute([(int)$user['id']]);
    $unlocked = array_flip(array_map(function ($row) { return $row['achievement_key']; }, $unlockedStmt->fetchAll()));
    foreach ($keys as $key) if (!isset($unlocked[$key])) pw_error('Only unlocked achievements can be showcased.');

    $db->beginTransaction();
    $delete = $db->prepare('DELETE FROM user_reputation_achievement_showcase WHERE user_id = ?');
    $delete->execute([(int)$user['id']]);
    $insert = $db->prepare('INSERT INTO user_reputation_achievement_showcase (user_id, achievement_key, position) VALUES (?, ?, ?)');
    foreach ($keys as $index => $key) $insert->execute([(int)$user['id'], $key, $index + 1]);
    $db->commit();
    pw_json(['ok' => true, 'showcase_keys' => $keys]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    pw_error('Could not save your achievement showcase. The showcase migration may still need to be run.', 503);
}
