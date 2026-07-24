<?php
require_once __DIR__ . '/dialogue-runtime-helpers.php';

$slug = isset($_GET['overlord']) ? trim((string)$_GET['overlord']) : '';
if (!preg_match('/\A[a-z0-9-]{1,100}\z/', $slug)) pw_error('Missing Overlord.');
$user = pw_current_user();
if (!$user) pw_json(['ok' => true, 'authenticated' => false, 'state' => pw_dialogue_runtime_default_state(), 'migration_required' => false]);

$db = pw_db();
$published = pw_dialogue_runtime_published_tree($db, $slug);
if (!$published) pw_error('This dialogue is not currently available.', 404);
$ready = pw_dialogue_runtime_ready($db);
$state = $ready ? pw_dialogue_runtime_load_state($db, (int)$user['id'], (int)$published['overlord_id']) : pw_dialogue_runtime_default_state();
$result = pw_dialogue_runtime_result_context($db, (int)$user['id'], $slug);
if ((int)$state['result_id'] !== (int)$result['result_id']) {
    $state['node_id'] = (string)($published['tree']['start_node_id'] ?? '');
    $state['result_id'] = (int)$result['result_id'];
}
pw_json([
    'ok' => true,
    'authenticated' => true,
    'migration_required' => !$ready,
    'state' => $state,
    'result_matches' => (bool)$result['matches'],
    'resonance' => (int)$result['resonance'],
    'reputation' => pw_reputation_info((int)$user['reputation']),
]);
