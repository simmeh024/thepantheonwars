<?php
/**
 * Shared validation for Loot Table Management.
 *
 * Chance figures are stored as DECIMAL(6,3) so a genuinely rare reward can be
 * set below 1%. The floor is 0.01 rather than 0.001 because the shared roller
 * resolves to two decimal places -- accepting a figure that could never fire
 * would be a worse answer than rejecting it.
 */
require_once __DIR__ . '/../../missions/missions-helpers.php';

const PW_LOOT_CHANCE_MIN = 0.01;
const PW_LOOT_CHANCE_MAX = 100.0;

function pw_admin_loot_chance($value, string $label): float {
    if (!is_numeric($value)) pw_error($label . ' must be a percentage between 0.01 and 100.');
    $chance = round((float)$value, 3);
    if ($chance < PW_LOOT_CHANCE_MIN || $chance > PW_LOOT_CHANCE_MAX) {
        pw_error($label . ' must be between ' . PW_LOOT_CHANCE_MIN . '% and 100%.');
    }
    return $chance;
}

function pw_admin_loot_table_input(array $input): array {
    $name = trim((string)($input['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 150) pw_error('Loot table name must be between 1 and 150 characters.');
    $slug = trim((string)($input['slug'] ?? ''));
    if (!preg_match('/\A[a-z0-9][a-z0-9-]{0,149}\z/', $slug)) pw_error('Loot table slug may use lowercase letters, numbers, and hyphens only.');
    $description = trim((string)($input['description'] ?? ''));
    if (mb_strlen($description) > 2000) pw_error('Loot table description must be 2,000 characters or fewer.');
    return [
        'name' => $name,
        'slug' => $slug,
        'description' => $description,
        'is_enabled' => !empty($input['is_enabled']) ? 1 : 0,
        'is_research_rare' => !empty($input['is_research_rare']) ? 1 : 0,
    ];
}

/**
 * Normalise the entry rows a table save carries.
 *
 * A duplicate source is rejected rather than merged: two rows for the same
 * character or gear item would roll twice and quietly double its real drop
 * chance, which is the opposite of what an administrator entering "5%" twice
 * expects.
 */
function pw_admin_loot_entries_input($raw): array {
    if (!is_array($raw)) return [];
    if (count($raw) > 60) pw_error('A loot table can hold at most 60 entries.');
    $entries = [];
    $seen = [];
    foreach ($raw as $index => $row) {
        if (!is_array($row)) pw_error('One loot entry is malformed.');
        $entryType = strtolower(trim((string)($row['entry_type'] ?? 'crew')));
        if (!in_array($entryType, ['crew', 'gear'], true)) pw_error('Loot entries must be a character or gear item.');
        // The old crew_definition_id shape remains accepted so a browser with a
        // cached pre-gear editor cannot accidentally erase a table while a new
        // deployment is propagating.
        $definitionId = filter_var(
            $row['definition_id'] ?? ($entryType === 'crew' ? ($row['crew_definition_id'] ?? null) : ($row['loot_definition_id'] ?? null)),
            FILTER_VALIDATE_INT
        );
        if ($definitionId === false || $definitionId < 1) {
            pw_error('Choose a ' . ($entryType === 'crew' ? 'character' : 'gear item') . ' for every loot entry.');
        }
        $key = $entryType . ':' . $definitionId;
        if (isset($seen[$key])) {
            pw_error('Each ' . ($entryType === 'crew' ? 'character' : 'gear item') . ' may only appear once in a loot table. Adjust that entry\'s chance instead of adding it twice.');
        }
        $seen[$key] = true;
        $entries[] = [
            'entry_type' => $entryType,
            'definition_id' => $definitionId,
            'chance_percent' => pw_admin_loot_chance($row['chance_percent'] ?? null, 'Drop chance'),
            'sort_order' => $index,
        ];
    }
    return $entries;
}

/** Every selected source must exist, or the save is rejected as a whole. */
function pw_admin_loot_require_sources_exist(PDO $db, array $entries): void {
    $crewIds = [];
    $gearIds = [];
    foreach ($entries as $entry) {
        if ($entry['entry_type'] === 'crew') $crewIds[] = (int)$entry['definition_id'];
        else $gearIds[] = (int)$entry['definition_id'];
    }
    $crewIds = array_values(array_unique($crewIds));
    $gearIds = array_values(array_unique($gearIds));
    if ($crewIds) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM game_crew_definitions WHERE id IN (' . pw_missions_placeholders(count($crewIds)) . ')');
        $stmt->execute($crewIds);
        if ((int)$stmt->fetchColumn() !== count($crewIds)) pw_error('One selected character no longer exists.', 404);
    }
    if ($gearIds) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM game_loot_definitions WHERE '
            . pw_missions_carryable_item_sql($db, 'game_loot_definitions')
            . ' AND id IN (' . pw_missions_placeholders(count($gearIds)) . ')');
        $stmt->execute($gearIds);
        if ((int)$stmt->fetchColumn() !== count($gearIds)) pw_error('One selected gear item no longer exists.', 404);
    }
}

/**
 * Replace a table's entries in place.
 *
 * Rows are matched by their reward type and source, then updated rather than
 * deleted and recreated:
 * game_loot_table_entries has no dependants today, but the delete-all-then-
 * reinsert shape is exactly what cost the quiz module its answer history when
 * an admin fixed a typo, and there is no reason to repeat it.
 */
function pw_admin_loot_sync_entries(PDO $db, int $tableId, array $entries): void {
    $existingStmt = $db->prepare('SELECT id, entry_type, crew_definition_id, loot_definition_id FROM game_loot_table_entries WHERE loot_table_id = ?');
    $existingStmt->execute([$tableId]);
    $existing = [];
    foreach ($existingStmt->fetchAll() as $row) {
        $type = $row['entry_type'] === 'gear' ? 'gear' : 'crew';
        $definitionId = (int)($type === 'gear' ? $row['loot_definition_id'] : $row['crew_definition_id']);
        $existing[$type . ':' . $definitionId] = (int)$row['id'];
    }

    $update = $db->prepare('UPDATE game_loot_table_entries SET chance_percent = ?, sort_order = ? WHERE id = ? AND loot_table_id = ?');
    $insert = $db->prepare('INSERT INTO game_loot_table_entries (loot_table_id, entry_type, crew_definition_id, loot_definition_id, chance_percent, sort_order) VALUES (?, ?, ?, ?, ?, ?)');
    $keep = [];
    foreach ($entries as $entry) {
        $key = $entry['entry_type'] . ':' . (int)$entry['definition_id'];
        if (isset($existing[$key])) {
            $update->execute([$entry['chance_percent'], $entry['sort_order'], $existing[$key], $tableId]);
            $keep[] = $existing[$key];
            continue;
        }
        $crewId = $entry['entry_type'] === 'crew' ? (int)$entry['definition_id'] : null;
        $gearId = $entry['entry_type'] === 'gear' ? (int)$entry['definition_id'] : null;
        $insert->execute([$tableId, $entry['entry_type'], $crewId, $gearId, $entry['chance_percent'], $entry['sort_order']]);
        $keep[] = (int)$db->lastInsertId();
    }
    $delete = $keep
        ? $db->prepare('DELETE FROM game_loot_table_entries WHERE loot_table_id = ? AND id NOT IN (' . pw_missions_placeholders(count($keep)) . ')')
        : $db->prepare('DELETE FROM game_loot_table_entries WHERE loot_table_id = ?');
    $delete->execute(array_merge([$tableId], $keep));
}

/** Mission -> table attachments, same replace-in-place rule as entries. */
function pw_admin_loot_mission_links_input($raw): array {
    if (!is_array($raw)) return [];
    if (count($raw) > 20) pw_error('A mission can open at most 20 loot tables.');
    $links = [];
    $seen = [];
    foreach ($raw as $index => $row) {
        if (!is_array($row)) pw_error('One loot table attachment is malformed.');
        $tableId = filter_var($row['loot_table_id'] ?? null, FILTER_VALIDATE_INT);
        if ($tableId === false || $tableId < 1) pw_error('Choose a loot table for every attachment.');
        if (isset($seen[$tableId])) pw_error('Each loot table may only be attached to a mission once.');
        $seen[$tableId] = true;
        $links[] = [
            'loot_table_id' => $tableId,
            'chance_percent' => pw_admin_loot_chance($row['chance_percent'] ?? null, 'Table chance'),
            'sort_order' => $index,
        ];
    }
    return $links;
}

function pw_admin_loot_sync_mission_links(PDO $db, int $missionId, array $links): void {
    if ($links) {
        $tableIds = array_map(static function ($link) { return (int)$link['loot_table_id']; }, $links);
        $stmt = $db->prepare('SELECT COUNT(*) FROM game_loot_tables WHERE id IN (' . pw_missions_placeholders(count($tableIds)) . ')');
        $stmt->execute($tableIds);
        if ((int)$stmt->fetchColumn() !== count($tableIds)) pw_error('One selected loot table no longer exists.', 404);
    }

    $existingStmt = $db->prepare('SELECT id, loot_table_id FROM game_mission_loot_tables WHERE mission_definition_id = ?');
    $existingStmt->execute([$missionId]);
    $existing = [];
    foreach ($existingStmt->fetchAll() as $row) $existing[(int)$row['loot_table_id']] = (int)$row['id'];

    $update = $db->prepare('UPDATE game_mission_loot_tables SET chance_percent = ?, sort_order = ? WHERE id = ? AND mission_definition_id = ?');
    $insert = $db->prepare('INSERT INTO game_mission_loot_tables (mission_definition_id, loot_table_id, chance_percent, sort_order) VALUES (?, ?, ?, ?)');
    $keep = [];
    foreach ($links as $link) {
        $tableId = (int)$link['loot_table_id'];
        if (isset($existing[$tableId])) {
            $update->execute([$link['chance_percent'], $link['sort_order'], $existing[$tableId], $missionId]);
            $keep[] = $existing[$tableId];
            continue;
        }
        $insert->execute([$missionId, $tableId, $link['chance_percent'], $link['sort_order']]);
        $keep[] = (int)$db->lastInsertId();
    }
    $delete = $keep
        ? $db->prepare('DELETE FROM game_mission_loot_tables WHERE mission_definition_id = ? AND id NOT IN (' . pw_missions_placeholders(count($keep)) . ')')
        : $db->prepare('DELETE FROM game_mission_loot_tables WHERE mission_definition_id = ?');
    $delete->execute(array_merge([$missionId], $keep));
}
