<?php
require_once __DIR__ . '/market-helpers.php';

pw_require_permission('market.view');
$db = pw_db();
pw_admin_market_require_ready($db);

try {
    $now = pw_missions_utc_now($db);
    $rotations = pw_market_current_rotations($db, $now);
    $entries = $db->query(
        'SELECT e.id, e.offer_type, e.loot_definition_id, e.crew_definition_id, e.credit_price, e.required_reputation_level,
                e.rotation_weight, e.stock_per_rotation, e.is_enabled, e.created_at, e.updated_at,
                COALESCE(l.name, c.name) AS name, l.tier, l.slot, c.role,
                CASE WHEN l.id IS NOT NULL THEN l.is_enabled ELSE c.is_enabled END AS source_enabled
         FROM game_market_entries e
         LEFT JOIN game_loot_definitions l ON l.id = e.loot_definition_id
         LEFT JOIN game_crew_definitions c ON c.id = e.crew_definition_id
         ORDER BY e.offer_type ASC, e.required_reputation_level ASC, name ASC, e.id ASC'
    )->fetchAll();
    foreach ($entries as &$entry) {
        foreach (['id', 'loot_definition_id', 'crew_definition_id', 'credit_price', 'required_reputation_level', 'rotation_weight', 'stock_per_rotation'] as $field) {
            $entry[$field] = $entry[$field] === null ? null : (int)$entry[$field];
        }
        $entry['is_enabled'] = (bool)$entry['is_enabled'];
        $entry['source_enabled'] = (bool)$entry['source_enabled'];
    }
    unset($entry);
    $gear = $db->query('SELECT id, name, tier, slot, is_enabled FROM game_loot_definitions WHERE slot <> "" ORDER BY is_enabled DESC, name ASC')->fetchAll();
    $characters = $db->query('SELECT id, name, role, is_enabled FROM game_crew_definitions WHERE is_starter = 0 ORDER BY is_enabled DESC, name ASC')->fetchAll();
    foreach ($gear as &$row) { $row['id'] = (int)$row['id']; $row['is_enabled'] = (bool)$row['is_enabled']; }
    unset($row);
    foreach ($characters as &$row) { $row['id'] = (int)$row['id']; $row['is_enabled'] = (bool)$row['is_enabled']; }
    unset($row);
    $levels = [];
    foreach (pw_reputation_levels() as $index => $level) {
        $levels[] = ['number' => $index + 1, 'name' => (string)$level['name'], 'threshold' => (int)$level['threshold'], 'color' => (string)$level['color']];
    }
    $rotationItems = $db->prepare(
        'SELECT i.id, i.credit_price, i.required_reputation_level, i.stock_initial, i.stock_remaining, i.sort_order,
                r.offer_type, r.window_started_at, r.window_ends_at, COALESCE(l.name, c.name) AS name
         FROM game_market_rotation_items i
         JOIN game_market_rotations r ON r.id = i.market_rotation_id
         JOIN game_market_entries e ON e.id = i.market_entry_id
         LEFT JOIN game_loot_definitions l ON l.id = e.loot_definition_id
         LEFT JOIN game_crew_definitions c ON c.id = e.crew_definition_id
         WHERE i.market_rotation_id IN (?, ?)
         ORDER BY r.offer_type ASC, i.sort_order ASC, i.id ASC'
    );
    $rotationItems->execute([(int)$rotations['gear']['id'], (int)$rotations['character']['id']]);
    $current = ['gear' => [], 'character' => []];
    foreach ($rotationItems->fetchAll() as $item) {
        foreach (['id', 'credit_price', 'required_reputation_level', 'stock_initial', 'stock_remaining', 'sort_order'] as $field) $item[$field] = (int)$item[$field];
        $current[$item['offer_type']][] = $item;
    }
    pw_json([
        'ok' => true,
        'entries' => $entries,
        'gear' => $gear,
        'characters' => $characters,
        'reputation_levels' => $levels,
        'server_now' => pw_missions_datetime($now),
        'rotations' => [
            'gear' => ['ends_at' => $rotations['gear']['window_ends_at'], 'items' => $current['gear']],
            'character' => ['ends_at' => $rotations['character']['window_ends_at'], 'items' => $current['character']],
        ],
    ]);
} catch (Throwable $e) {
    pw_error('Could not load Market Control. Confirm that sql/migration_market.sql has been run.', 503);
}
