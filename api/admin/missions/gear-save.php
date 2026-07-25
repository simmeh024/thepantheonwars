<?php
require_once __DIR__ . '/missions-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$admin = pw_require_permission('missions.edit');
$input = pw_input(); pw_require_csrf($input);
$db = pw_db(); pw_admin_mission_gear_require_ready($db);
/* Create and update in one endpoint, matching api/admin/loot-tables/save.php:
 * the validation and the column list are identical either way, and two files
 * would only give them somewhere to drift apart. */
$id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
$id = $id === false ? null : $id;
$data = pw_admin_mission_gear_input($input);

$duplicate = $db->prepare('SELECT id FROM game_loot_definitions WHERE slug = ?' . ($id ? ' AND id != ?' : ''));
$duplicate->execute($id ? [$data['slug'], $id] : [$data['slug']]);
if ($duplicate->fetch()) pw_error('An item with that slug already exists.', 409);

$columns = ['name', 'slug', 'description', 'tier', 'slot', 'world_key', 'drop_weight',
    'bonus_strength', 'bonus_cunning', 'bonus_science', 'bonus_charisma',
    'required_level', 'required_role', 'icon_url', 'is_enabled'];
$values = array_map(static function ($column) use ($data) { return $data[$column]; }, $columns);

if ($id) {
    $existing = $db->prepare('SELECT id FROM game_loot_definitions WHERE id = ?');
    $existing->execute([$id]);
    if (!$existing->fetch()) pw_error('Equipment not found.', 404);
    /* A slot change on an item players are already carrying would leave those
     * rows in a slot the item no longer belongs to, and the loadout reads by
     * slot -- so the equipped rows are cleared rather than left inconsistent.
     * Nothing is lost: the copies stay in every player's inventory. */
    $previous = $db->prepare('SELECT slot FROM game_loot_definitions WHERE id = ?');
    $previous->execute([$id]);
    $previousSlot = (string)$previous->fetchColumn();
    $sets = implode(', ', array_map(static function ($column) { return $column . ' = ?'; }, $columns));
    $stmt = $db->prepare('UPDATE game_loot_definitions SET ' . $sets . ' WHERE id = ?');
    $stmt->execute(array_merge($values, [$id]));
    $unequipped = 0;
    if ($previousSlot !== $data['slot']) {
        $clear = $db->prepare('DELETE FROM game_player_crew_gear WHERE loot_definition_id = ?');
        $clear->execute([$id]);
        $unequipped = $clear->rowCount();
    }
    pw_log_admin_activity('mission_gear_updated', 'Updated equipment "' . $data['name'] . '".'
        . ($unequipped > 0 ? ' Slot changed, so ' . $unequipped . ' equipped copies were returned to inventory.' : ''), $admin);
    pw_json(['ok' => true, 'id' => $id, 'unequipped' => $unequipped]);
}

$stmt = $db->prepare('INSERT INTO game_loot_definitions (' . implode(', ', $columns) . ') VALUES (' . pw_missions_placeholders(count($columns)) . ')');
$stmt->execute($values);
$newId = (int)$db->lastInsertId();
pw_log_admin_activity('mission_gear_created', 'Created equipment "' . $data['name'] . '".', $admin);
pw_json(['ok' => true, 'id' => $newId]);
