<?php
require_once __DIR__ . '/../../missions/sweep-helpers.php';

pw_require_permission('sweep_tiers.view');
$db = pw_db();
if (!pw_sweep_ready($db)) {
    pw_error('Run sql/migration_salvage_sweep.sql before opening Sweep Tiers.', 503);
}

try {
    $tiers = $db->query(
        'SELECT tier.*, lt.name AS loot_table_name, lt.is_enabled AS loot_table_enabled
         FROM game_sweep_tiers tier
         LEFT JOIN game_loot_tables lt ON lt.id = tier.loot_table_id
         ORDER BY tier.rank_number ASC'
    )->fetchAll();
    foreach ($tiers as &$tier) {
        foreach (['id', 'rank_number', 'grid_rows', 'grid_cols', 'base_picks', 'hazard_count', 'cache_credits', 'fatigue_cost', 'xp_reward'] as $field) {
            $tier[$field] = (int)$tier[$field];
        }
        $tier['loot_table_id'] = $tier['loot_table_id'] !== null ? (int)$tier['loot_table_id'] : null;
        $tier['is_enabled'] = (bool)$tier['is_enabled'];
        $tier['loot_table_enabled'] = !empty($tier['loot_table_enabled']);
        $tier['cells'] = $tier['grid_rows'] * $tier['grid_cols'];
    }
    unset($tier);

    /* The reputation ladder, so the editor can name a rank rather than only
     * number it -- and so an administrator can see at a glance which rungs
     * still have no sector. Read from pw_reputation_levels() so this page and
     * the reputation ladder can never disagree about what rank 12 is called. */
    $ranks = [];
    foreach (pw_reputation_levels() as $index => $level) {
        $ranks[] = ['number' => $index + 1, 'name' => (string)$level['name'], 'threshold' => (int)$level['threshold']];
    }

    /* Sweep manifests first, then everything else: the picker still offers
     * every table, because marking one is a convenience rather than a
     * requirement, but the ones written for a sector sort to the top. */
    $sweepFlag = pw_mission_loot_table_sweep_flag_ready($db);
    $lootTables = $db->query('SELECT id, name, is_enabled, '
        . ($sweepFlag ? 'is_sweep_only' : '0 AS is_sweep_only')
        . ' FROM game_loot_tables ORDER BY ' . ($sweepFlag ? 'is_sweep_only DESC, ' : '') . 'name ASC')->fetchAll();
    foreach ($lootTables as &$table) {
        $table['id'] = (int)$table['id'];
        $table['is_enabled'] = (bool)$table['is_enabled'];
        $table['is_sweep_only'] = (bool)$table['is_sweep_only'];
    }
    unset($table);

    pw_json([
        'ok' => true,
        'tiers' => $tiers,
        'ranks' => $ranks,
        'loot_tables' => $lootTables,
        'limits' => [
            'max_rows' => PW_SWEEP_MAX_ROWS,
            'max_cols' => PW_SWEEP_MAX_COLS,
            'max_picks' => PW_SWEEP_MAX_PICKS,
        ],
    ]);
} catch (Throwable $e) {
    pw_error('Could not load Sweep Tiers.', 503);
}
