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
/* Only written once the inventory migration has been run. Naming a missing
 * column is a hard SQL error, not a silent NULL, so a deploy that lands ahead
 * of its migration must not include them. */
if (pw_mission_stims_ready($db)) {
    array_splice($columns, -1, 0, ['stim_effect', 'stim_value', 'stim_duration_seconds']);
}
if (pw_mission_item_levels_ready($db)) {
    array_splice($columns, -1, 0, ['item_level']);
}
if (pw_mission_field_grade_ready($db)) {
    array_splice($columns, -1, 0, ['field_grade']);
}
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
    $reason = '';
    if ($previousSlot !== $data['slot']) {
        $clear = $db->prepare('DELETE FROM game_player_crew_gear WHERE loot_definition_id = ?');
        $clear->execute([$id]);
        $unequipped = $clear->rowCount();
        $reason = 'slot';
    } else {
        /* A raised level requirement, or a role requirement that now names
         * somebody else, is only ever checked when a player equips something --
         * so without this an item carried before the edit keeps granting its
         * bonuses to crew who could no longer pick it up.
         *
         * Every equipped copy is re-tested rather than the edit being compared
         * against the old values: that covers a level rise, a role change and
         * both at once with one rule, and a copy that still qualifies is never
         * disturbed. Nothing is lost either way -- the quantity ledger counts
         * equipped copies, so removing the row simply makes it a spare again.
         *
         * Deployed crew are included, matching the slot-change branch above. It
         * does mean an operation already in flight is paid on the smaller
         * loadout, since a claim recomputes from the crew as they stand; leaving
         * them equipped would be worse, because the rule the administrator just
         * wrote would go unapplied for as long as the mission runs. */
        $requiredRole = trim((string)$data['required_role']);
        /* The role clause is added rather than written with an empty-string
         * literal in the SQL: "an item open to any role" is the absence of a
         * condition, and a literal would also have to be quoted in a way that
         * survives ANSI_QUOTES. The comparison itself is case-insensitive
         * because these columns are utf8mb4_unicode_ci, which is what
         * pw_missions_gear_requirement_error() does with strcasecmp() -- the two
         * must agree, or a copy could be stripped on save and immediately
         * re-equipped by its owner. */
        $failing = 'pc.level < ?';
        $parameters = [$id, (int)$data['required_level']];
        if ($requiredRole !== '') {
            $failing .= ' OR c.role <> ?';
            $parameters[] = $requiredRole;
        }
        $clear = $db->prepare(
            'DELETE g FROM game_player_crew_gear g
             JOIN game_player_crew pc ON pc.id = g.player_crew_id
             JOIN game_crew_definitions c ON c.id = pc.crew_definition_id
             WHERE g.loot_definition_id = ? AND (' . $failing . ')'
        );
        $clear->execute($parameters);
        $unequipped = $clear->rowCount();
        if ($unequipped > 0) $reason = 'requirement';
    }
    $note = '';
    if ($unequipped > 0) {
        $note = $reason === 'slot'
            ? ' Slot changed, so ' . $unequipped . ' equipped copies were returned to inventory.'
            : ' Requirements changed, so ' . $unequipped . ' equipped copies were returned to inventory.';
    }
    pw_log_admin_activity('mission_gear_updated', 'Updated equipment "' . $data['name'] . '".' . $note, $admin);
    pw_json(['ok' => true, 'id' => $id, 'unequipped' => $unequipped, 'unequipped_reason' => $reason]);
}

$stmt = $db->prepare('INSERT INTO game_loot_definitions (' . implode(', ', $columns) . ') VALUES (' . pw_missions_placeholders(count($columns)) . ')');
$stmt->execute($values);
$newId = (int)$db->lastInsertId();
pw_log_admin_activity('mission_gear_created', 'Created equipment "' . $data['name'] . '".', $admin);
pw_json(['ok' => true, 'id' => $newId]);
