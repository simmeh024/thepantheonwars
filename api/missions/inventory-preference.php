<?php
/** Save a player's private inventory organisation choices. */
require_once __DIR__ . '/missions-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$itemId = filter_var($input['loot_definition_id'] ?? null, FILTER_VALIDATE_INT);
if ($itemId === false || $itemId < 1) pw_error('Choose a valid inventory item.');
$favorite = !empty($input['is_favorite']) ? 1 : 0;
$tag = strtolower(trim((string)($input['tag_key'] ?? '')));
if ($tag !== '' && !in_array($tag, pw_missions_inventory_tags(), true)) {
    pw_error('Choose a valid inventory tag.');
}

$db = pw_db();
pw_missions_require_ready($db);
if (!pw_mission_inventory_workbench_ready($db)) {
    pw_error('Inventory workbench upgrades are being prepared. Please try again after the Inventory Workbench migration has been run.', 503);
}
$userId = (int)$user['id'];

try {
    $held = $db->prepare('SELECT 1 FROM game_player_loot WHERE user_id = ? AND loot_definition_id = ? AND quantity > 0');
    $held->execute([$userId, $itemId]);
    if (!$held->fetchColumn()) throw new RuntimeException('That item is no longer in your inventory.');

    if (!$favorite && $tag === '') {
        $delete = $db->prepare('DELETE FROM game_player_loot_preferences WHERE user_id = ? AND loot_definition_id = ?');
        $delete->execute([$userId, $itemId]);
    } else {
        $save = $db->prepare(
            'INSERT INTO game_player_loot_preferences (user_id, loot_definition_id, is_favorite, tag_key)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE is_favorite = VALUES(is_favorite), tag_key = VALUES(tag_key), updated_at = CURRENT_TIMESTAMP'
        );
        $save->execute([$userId, $itemId, $favorite, $tag]);
    }
    pw_json(['ok' => true, 'loot_definition_id' => $itemId, 'is_favorite' => (bool)$favorite, 'tag_key' => $tag]);
} catch (Throwable $e) {
    pw_error($e instanceof RuntimeException ? $e->getMessage() : 'Could not update that inventory item. Please try again.', 409);
}
