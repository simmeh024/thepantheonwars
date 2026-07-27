<?php
require_once __DIR__ . '/../../missions/sweep-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$admin = pw_require_permission('sweep_tiers.manage');
$input = pw_input();
pw_require_csrf($input);
$db = pw_db();
if (!pw_sweep_ready($db)) {
    pw_error('Run sql/migration_salvage_sweep.sql before editing Sweep Tiers.', 503);
}

$rank = filter_var($input['rank_number'] ?? null, FILTER_VALIDATE_INT);
if ($rank === false || $rank < 1 || $rank > 99) pw_error('Choose a reputation rank between 1 and 99.');

$name = trim((string)($input['name'] ?? ''));
if (mb_strlen($name) > 120) pw_error('The sector name may not exceed 120 characters.');

$rows = filter_var($input['grid_rows'] ?? null, FILTER_VALIDATE_INT);
$cols = filter_var($input['grid_cols'] ?? null, FILTER_VALIDATE_INT);
if ($rows === false || $rows < 2 || $rows > PW_SWEEP_MAX_ROWS) pw_error('Field rows must be between 2 and ' . PW_SWEEP_MAX_ROWS . '.');
if ($cols === false || $cols < 2 || $cols > PW_SWEEP_MAX_COLS) pw_error('Field columns must be between 2 and ' . PW_SWEEP_MAX_COLS . '.');
$cells = $rows * $cols;

$picks = filter_var($input['base_picks'] ?? null, FILTER_VALIDATE_INT);
if ($picks === false || $picks < 1 || $picks > PW_SWEEP_MAX_PICKS) pw_error('Base scans must be between 1 and ' . PW_SWEEP_MAX_PICKS . '.');

$hazards = filter_var($input['hazard_count'] ?? null, FILTER_VALIDATE_INT);
/* Two cells have to survive being hazards, or the board is a loss the moment
 * it opens -- the same floor pw_sweep_normalise_tier() applies at read time,
 * enforced here so an administrator is told rather than silently corrected. */
if ($hazards === false || $hazards < 0 || $hazards > $cells - 2) {
    pw_error('Collapses must leave at least two safe cells on a ' . $rows . ' x ' . $cols . ' field (0 to ' . ($cells - 2) . ').');
}

$cache = filter_var($input['cache_credits'] ?? 0, FILTER_VALIDATE_INT);
if ($cache === false || $cache < 0 || $cache > 1000000) pw_error('Cache credits must be between 0 and 1,000,000.');
$fatigue = filter_var($input['fatigue_cost'] ?? 0, FILTER_VALIDATE_INT);
if ($fatigue === false || $fatigue < 0 || $fatigue > 200) pw_error('Fatigue cost must be between 0 and 200.');
$xp = filter_var($input['xp_reward'] ?? 0, FILTER_VALIDATE_INT);
if ($xp === false || $xp < 0 || $xp > 10000) pw_error('XP reward must be between 0 and 10,000.');

$lootTableId = null;
$lootRaw = trim((string)($input['loot_table_id'] ?? ''));
if ($lootRaw !== '') {
    $lootTableId = filter_var($lootRaw, FILTER_VALIDATE_INT);
    if ($lootTableId === false || $lootTableId < 1) pw_error('Choose a valid recovery manifest.');
    $exists = $db->prepare('SELECT id FROM game_loot_tables WHERE id = ?');
    $exists->execute([$lootTableId]);
    if (!$exists->fetch()) pw_error('That recovery manifest no longer exists.', 404);
}

$enabled = !empty($input['is_enabled']) ? 1 : 0;

try {
    /* One tier per rank, enforced by the unique key. Upserting on it means the
     * ladder can be filled in any order and re-saved without an id round trip,
     * which matters when there are forty of them to author. */
    $stmt = $db->prepare(
        'INSERT INTO game_sweep_tiers
            (rank_number, name, loot_table_id, grid_rows, grid_cols, base_picks, hazard_count,
             cache_credits, fatigue_cost, xp_reward, is_enabled)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            name = VALUES(name), loot_table_id = VALUES(loot_table_id),
            grid_rows = VALUES(grid_rows), grid_cols = VALUES(grid_cols),
            base_picks = VALUES(base_picks), hazard_count = VALUES(hazard_count),
            cache_credits = VALUES(cache_credits), fatigue_cost = VALUES(fatigue_cost),
            xp_reward = VALUES(xp_reward), is_enabled = VALUES(is_enabled)'
    );
    $stmt->execute([$rank, $name, $lootTableId, $rows, $cols, $picks, $hazards, $cache, $fatigue, $xp, $enabled]);
} catch (Throwable $e) {
    pw_error('That sweep tier could not be saved.', 500);
}

pw_log_admin_activity('sweep_tier_saved', 'Saved the Salvage Sweep sector for rank ' . $rank . '.', $admin);
pw_json(['ok' => true, 'rank_number' => $rank]);
