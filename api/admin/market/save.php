<?php
require_once __DIR__ . '/market-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$admin = pw_require_permission('market.manage');
$input = pw_input();
pw_require_csrf($input);
$id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
$id = $id === false ? null : $id;
$db = pw_db();
pw_admin_market_require_ready($db);
$data = pw_admin_market_input($input);
$source = pw_admin_market_source($db, $data['offer_type'], $data['definition_id']);

if ($id) {
    $existingStmt = $db->prepare('SELECT id, offer_type, loot_definition_id, crew_definition_id FROM game_market_entries WHERE id = ?');
    $existingStmt->execute([$id]);
    $existing = $existingStmt->fetch();
    if (!$existing) pw_error('Market entry not found.', 404);
    $existingDefinitionId = $existing['offer_type'] === 'gear' ? (int)$existing['loot_definition_id'] : (int)$existing['crew_definition_id'];
    if ($existing['offer_type'] !== $data['offer_type'] || $existingDefinitionId !== $data['definition_id']) {
        pw_error('A live market entry cannot be retargeted. Disable it and create a new entry instead.', 409);
    }
    $stmt = $db->prepare('UPDATE game_market_entries SET credit_price = ?, required_reputation_level = ?, rotation_weight = ?, stock_per_rotation = ?, is_enabled = ? WHERE id = ?');
    $stmt->execute([$data['credit_price'], $data['required_reputation_level'], $data['rotation_weight'], $data['stock_per_rotation'], $data['is_enabled'], $id]);
    pw_log_admin_activity('market_entry_updated', 'Updated market offer for "' . $source['name'] . '".', $admin);
    pw_json(['ok' => true, 'id' => $id]);
}

$column = $data['offer_type'] === 'gear' ? 'loot_definition_id' : 'crew_definition_id';
$duplicate = $db->prepare('SELECT id FROM game_market_entries WHERE ' . $column . ' = ?');
$duplicate->execute([$data['definition_id']]);
if ($duplicate->fetch()) pw_error('That catalogue entry is already configured for the Market. Edit the existing entry instead.', 409);
$stmt = $db->prepare(
    'INSERT INTO game_market_entries (offer_type, ' . $column . ', credit_price, required_reputation_level, rotation_weight, stock_per_rotation, is_enabled)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([$data['offer_type'], $data['definition_id'], $data['credit_price'], $data['required_reputation_level'], $data['rotation_weight'], $data['stock_per_rotation'], $data['is_enabled']]);
$newId = (int)$db->lastInsertId();
pw_log_admin_activity('market_entry_created', 'Added "' . $source['name'] . '" to the market catalogue.', $admin);
pw_json(['ok' => true, 'id' => $newId]);
