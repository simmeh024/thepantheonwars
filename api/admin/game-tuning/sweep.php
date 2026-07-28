<?php
/**
 * Salvage Sweep balance: what one sector is worth, and what it risks.
 *
 * Expected values are closed-form, not sampled -- the same rule the mission
 * simulator follows. Every random element of a sweep has one:
 *
 *   The hazards are placed uniformly, so the cells a player turns over are a
 *   uniform sample without replacement. The chance the first k scans are all
 *   safe is therefore a product of falling ratios, not a power -- treating each
 *   scan as an independent coin at H/C would understate the risk of a long
 *   board and is the easy mistake here.
 *
 *   Strength buys exactly one escape, so a run survives k scans if it met no
 *   hazard, or met exactly one and braced through it.
 *
 * The reference line is "spend every scan, then bank", which is the maximum
 * risk a player can take. It is not the optimal line -- a player may bank
 * early -- but it is the only one that does not require modelling a decision,
 * and the marginal risk of the next scan is reported so the stopping point is
 * visible too.
 */
require_once __DIR__ . '/tuning-helpers.php';
require_once __DIR__ . '/../../missions/sweep-helpers.php';

pw_require_permission('game_tuning.view');
$db = pw_db();
if (!pw_sweep_ready($db)) {
    pw_error('The Salvage Sweep migration has not been run, so there is nothing to tune yet.', 503);
}

$input = pw_input();
$crewId = filter_var($input['crew_definition_id'] ?? null, FILTER_VALIDATE_INT);
$level = filter_var($input['level'] ?? 10, FILTER_VALIDATE_INT);
$level = max(1, min(PW_MISSION_MAX_LEVEL, $level === false ? 10 : $level));

try {
    $definition = null;
    if ($crewId !== false && $crewId > 0) {
        $stmt = $db->prepare('SELECT id, name, role, tier FROM game_crew_definitions WHERE id = ?');
        $stmt->execute([$crewId]);
        $definition = $stmt->fetch() ?: null;
    }
    /* No crew chosen means the sector's own numbers, unmodified -- which is
     * also the honest baseline for comparing two sectors against each other. */
    $stats = $definition
        ? pw_missions_stats_for_level((string)$definition['role'], $level, (string)($definition['tier'] ?? 'common'))
        : ['strength' => 0, 'cunning' => 0, 'science' => 0, 'charisma' => 0];

    $rows = $db->query(
        'SELECT tier.*, lt.name AS loot_table_name, lt.is_enabled AS loot_table_enabled
         FROM game_sweep_tiers tier
         LEFT JOIN game_loot_tables lt ON lt.id = tier.loot_table_id
         WHERE tier.is_enabled = 1
         ORDER BY tier.rank_number ASC'
    )->fetchAll();

    $sectors = [];
    foreach ($rows as $row) {
        $tier = pw_sweep_normalise_tier($row);
        $conditionRun = pw_sweep_apply_condition(
            $tier,
            pw_sweep_crew_bonuses($stats, $tier),
            pw_sweep_effective_hazards($tier),
            0.0
        );
        $bonuses = $conditionRun['bonuses'];
        $simulatedTier = $tier;
        $simulatedTier['hazard_count'] = $conditionRun['hazard_count'];
        $sectors[] = array_merge(
            [
                'rank_number' => $tier['rank_number'],
                'name' => $tier['name'] !== '' ? $tier['name'] : 'Sector ' . $tier['rank_number'],
                'loot_table_name' => (string)$tier['loot_table_name'],
                'has_manifest' => $tier['loot_table_id'] !== null && $tier['loot_table_enabled'],
                'grid' => $tier['grid_rows'] . '×' . $tier['grid_cols'],
                'cells' => $tier['grid_rows'] * $tier['grid_cols'],
                'hazards' => $simulatedTier['hazard_count'],
                'picks' => $bonuses['picks_total'],
                'hint_radius' => $bonuses['hint_radius'],
                'shrug_percent' => $bonuses['shrug_percent'],
                'fatigue_cost' => $tier['fatigue_cost'],
                'xp_reward' => $bonuses['xp_reward'],
                'condition' => pw_sweep_condition_public($tier['condition_key']),
            ],
            pw_tuning_sweep_outcome($simulatedTier, $bonuses)
        );
    }

    pw_json([
        'ok' => true,
        'crew' => $definition ? ['id' => (int)$definition['id'], 'name' => (string)$definition['name'],
                                 'role' => (string)$definition['role'], 'tier' => (string)($definition['tier'] ?? 'common')] : null,
        'level' => $level,
        'stats' => $stats,
        'sectors' => $sectors,
        'findings' => pw_tuning_sweep_findings($sectors),
    ]);
} catch (Throwable $e) {
    pw_error('The sweep tuning read failed: ' . $e->getMessage(), 503);
}
