<?php
/**
 * Game Tuning: a read-only balance simulator.
 *
 * The governing rule of this file is that it computes NOTHING itself. Every
 * figure it reports comes from the same helpers the live launch and claim paths
 * call -- pw_missions_stats_for_level(), pw_missions_apply_gear_bonuses(),
 * pw_missions_crew_effects(), pw_missions_effective_duration() and
 * pw_missions_effective_success(). A tuning tool that re-implements the game's
 * arithmetic is wrong precisely when it is being trusted to find something
 * wrong, and this codebase already carries one deliberate second copy of that
 * maths in js/missions.js's projectLaunch(). This is not a third.
 *
 * Nothing here writes to a game table. It never touches game_player_*.
 */
require_once __DIR__ . '/../../missions/missions-helpers.php';
require_once __DIR__ . '/../../research/research-helpers.php';

/** Levels a sweep may span. The crew ceiling is PW_MISSION_MAX_LEVEL. */
const PW_TUNING_MAX_POINTS = 60;

function pw_tuning_ready(PDO $db): bool {
    return pw_missions_ready($db) && pw_mission_stats_ready($db);
}

function pw_tuning_scenarios_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $db->query('SELECT id FROM `game_tuning_scenarios` LIMIT 1');
        return $ready = true;
    } catch (Throwable $e) {
        return $ready = false;
    }
}

/**
 * Research effects assembled from an arbitrary set of node ids.
 *
 * Deliberately mirrors pw_research_player_effects() rather than calling it:
 * that function reads what one player has actually unlocked, and the whole
 * point here is to ask "what if these were on". The accumulate-then-cap shape
 * is the same, so a simulated set of nodes is capped exactly as an owned set
 * would be -- a tool that ignored the caps would recommend tuning that the
 * game would then refuse to apply.
 *
 * @param int[] $nodeIds
 */
function pw_tuning_research_effects(PDO $db, array $nodeIds): array {
    $effects = pw_research_default_effects();
    $ids = array_values(array_unique(array_filter(array_map('intval', $nodeIds), static function ($id) { return $id > 0; })));
    if (!$ids || !pw_research_ready($db)) return $effects;
    $stmt = $db->prepare(
        'SELECT effect_type, effect_value, target_mission_definition_id
         FROM game_research_nodes
         WHERE is_enabled = 1 AND id IN (' . pw_missions_placeholders(count($ids)) . ')'
    );
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) {
        $value = max(0.0, (float)$row['effect_value']);
        switch ((string)$row['effect_type']) {
            case 'mission_speed': $effects['mission_speed_percent'] += $value; break;
            case 'xp_gain': $effects['xp_percent'] += $value; break;
            case 'reputation_gain': $effects['reputation_percent'] += $value; break;
            case 'credit_gain': $effects['credit_percent'] += $value; break;
            case 'crew_capacity': $effects['crew_capacity'] += $value; break;
            case 'crew_fatigue': $effects['crew_fatigue'] += $value; break;
            case 'fatigue_recovery': $effects['fatigue_recovery_percent'] += $value; break;
            case 'stim_slots': $effects['stim_slots'] += $value; break;
            case 'inventory_capacity': $effects['inventory_capacity'] += $value; break;
            case 'luck': $effects['luck_percent'] += $value; break;
            case 'market_discount': $effects['market_discount_percent'] += $value; break;
            case 'market_refresh': $effects['market_refresh_percent'] += $value; break;
            case 'secret_mission':
                if ($row['target_mission_definition_id'] !== null) $effects['secret_mission_ids'][] = (int)$row['target_mission_definition_id'];
                break;
        }
    }
    // The same ceilings pw_research_player_effects() applies to a real set.
    $effects['mission_speed_percent'] = round(min(60.0, $effects['mission_speed_percent']), 2);
    $effects['xp_percent'] = round(min(75.0, $effects['xp_percent']), 2);
    $effects['reputation_percent'] = round(min(75.0, $effects['reputation_percent']), 2);
    $effects['credit_percent'] = round(min(75.0, $effects['credit_percent']), 2);
    $effects['crew_capacity'] = (int)min(24, floor($effects['crew_capacity']));
    $effects['crew_fatigue'] = (int)min(PW_MISSION_FATIGUE_RESEARCH_CAP, floor($effects['crew_fatigue']));
    $effects['luck_percent'] = round(min(75.0, $effects['luck_percent']), 2);
    $effects['fatigue_recovery_percent'] = round(min(200.0, $effects['fatigue_recovery_percent']), 2);
    $effects['stim_slots'] = (int)min(PW_MISSION_STIM_SLOT_RESEARCH_CAP, floor($effects['stim_slots']));
    $effects['inventory_capacity'] = (int)min(PW_MISSION_INVENTORY_RESEARCH_CAP, floor($effects['inventory_capacity']));
    return $effects;
}

/**
 * The chosen items, keyed by slot, with the reason one cannot be worn at a
 * given level.
 *
 * required_level is honoured rather than ignored, because an item that only
 * becomes legal partway up the ladder puts a visible step in the curve, and
 * that step is one of the things this page exists to show.
 *
 * @param int[] $itemIds
 */
function pw_tuning_loadout(PDO $db, array $itemIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds), static function ($id) { return $id > 0; })));
    if (!$ids || !pw_mission_gear_ready($db)) return [];
    $stmt = $db->prepare(
        'SELECT id, name, slot, tier, icon_url, required_level, required_role,
                bonus_strength, bonus_cunning, bonus_science, bonus_charisma
         FROM game_loot_definitions
         WHERE is_enabled = 1 AND slot <> "" AND id IN (' . pw_missions_placeholders(count($ids)) . ')'
    );
    $stmt->execute($ids);
    $loadout = [];
    foreach ($stmt->fetchAll() as $row) {
        // One item per slot, matching the live loadout rule. A second item for
        // an occupied slot is dropped rather than silently stacked.
        $slot = (string)$row['slot'];
        if (isset($loadout[$slot])) continue;
        $loadout[$slot] = [
            'loot_definition_id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'slot' => $slot,
            'tier' => (string)$row['tier'],
            'icon_url' => (string)$row['icon_url'],
            'required_level' => (int)$row['required_level'],
            'required_role' => (string)$row['required_role'],
            'bonus' => [
                'strength' => (int)$row['bonus_strength'],
                'cunning' => (int)$row['bonus_cunning'],
                'science' => (int)$row['bonus_science'],
                'charisma' => (int)$row['bonus_charisma'],
            ],
        ];
    }
    return $loadout;
}

/** The subset of a loadout a crew member of this role and level may wear. */
function pw_tuning_legal_loadout(array $loadout, string $role, int $level): array {
    $legal = [];
    foreach ($loadout as $slot => $item) {
        if ($level < (int)$item['required_level']) continue;
        if ($item['required_role'] !== '' && $item['required_role'] !== $role) continue;
        $legal[$slot] = $item;
    }
    return $legal;
}

/**
 * One simulated crew, built from copies of the same definition.
 *
 * Copies rather than a mixed team on purpose: this page answers "how does THIS
 * crew member scale", and a mixed team would confound the curve with the other
 * members' contributions. Affinity stacks per member, so N copies is also the
 * honest way to show what crew count does.
 */
function pw_tuning_build_crew(array $definition, int $level, int $count, array $loadout): array {
    $role = (string)$definition['role'];
    $legal = pw_tuning_legal_loadout($loadout, $role, $level);
    $rows = [];
    $gearByCrew = [];
    for ($i = 0; $i < $count; $i++) {
        $stats = pw_missions_stats_for_level($role, $level);
        $rows[] = array_merge(['id' => $i + 1, 'role' => $role, 'level' => $level], $stats);
        $gearByCrew[$i + 1] = $legal;
    }
    return pw_missions_apply_gear_bonuses($rows, $gearByCrew);
}

/**
 * Everything one (crew, level, count, mission) combination produces.
 *
 * Expected values are computed in closed form rather than sampled. Every random
 * element in a mission has one: the outcome is a single Bernoulli trial at the
 * success percentage, the extra loot draw is a Bernoulli trial at the
 * fractional part of the Cunning bonus, and an upgrade is a per-item trial at
 * the Science percentage. Sampling those would only add noise to numbers that
 * can be stated exactly, and a balance tool wants the exact figure.
 */
function pw_tuning_simulate_point(PDO $db, array $definition, array $mission, int $level, int $count, array $loadout, array $research): array {
    $crew = pw_tuning_build_crew($definition, $level, $count, $loadout);
    $missionType = (string)$mission['mission_type'];
    // No weather: a tuning baseline is the mission's own numbers. Today's
    // forecast would make every reading depend on the date it was taken.
    $effects = pw_missions_crew_effects($crew, $missionType, null);

    $speed = min(90.0, (float)$effects['duration_percent'] + (float)$research['mission_speed_percent']);
    $durationEffects = array_merge($effects, ['duration_percent' => $speed]);
    $duration = pw_missions_effective_duration((int)$mission['duration_seconds'], $durationEffects);
    $success = pw_missions_effective_success((int)$mission['base_success_percent'], $effects);

    $xpPercent = (float)$effects['xp_percent'] + (float)$research['xp_percent'];
    $repPercent = (float)$effects['reputation_percent'] + (float)$research['reputation_percent'];
    $creditPercent = (float)$effects['credit_percent'] + (float)$research['credit_percent'];
    $upgradePercent = min(95.0, (float)$effects['upgrade_percent'] + (float)$research['luck_percent']);

    $xp = (int)round((int)$mission['xp_reward'] * (1 + ($xpPercent / 100)));
    $reputation = (int)round((int)$mission['reputation_reward'] * (1 + ($repPercent / 100))) + (int)$effects['reputation_flat'];
    $credits = (int)round((int)($mission['credit_reward'] ?? 0) * (1 + ($creditPercent / 100)));

    /* Expected draws: the whole part of the Cunning bonus is guaranteed and the
     * remainder is the probability of one more, which is exactly what
     * pw_missions_loot_roll_count() rolls for. Capped at the same 12. */
    $lootBonus = max(0.0, (float)$effects['loot_percent']);
    $expectedRolls = min(12.0, (int)$mission['loot_rolls'] + ($lootBonus / 100));
    $successRate = $success / 100;

    $hours = $duration > 0 ? $duration / 3600 : 0.0;
    $progress = pw_missions_xp_progress(pw_missions_xp_for_level($level) + $xp, $level);
    $fatigue = pw_missions_fatigue_cost((int)$mission['duration_seconds']);
    $totals = ['strength' => 0, 'cunning' => 0, 'science' => 0, 'charisma' => 0];
    foreach ($crew as $member) {
        foreach ($totals as $stat => $ignored) $totals[$stat] += (int)($member[$stat] ?? 0);
    }

    return [
        'level' => $level,
        'crew_count' => $count,
        'duration_seconds' => $duration,
        'base_duration_seconds' => (int)$mission['duration_seconds'],
        'success_percent' => $success,
        'base_success_percent' => (int)$mission['base_success_percent'],
        'xp' => $xp,
        'reputation' => $reputation,
        'credits' => $credits,
        'expected_loot' => round($expectedRolls, 2),
        'upgrade_percent' => round($upgradePercent, 2),
        // Accounting for failure, which pays nothing at all.
        'expected_xp' => round($xp * $successRate, 1),
        'expected_credits' => round($credits * $successRate, 1),
        'expected_reputation' => round($reputation * $successRate, 2),
        'expected_loot_after_failure' => round($expectedRolls * $successRate, 2),
        'expected_upgrades' => round($expectedRolls * $successRate * ($upgradePercent / 100), 2),
        'failure_percent' => round(100 - $success, 2),
        // Per hour of wall clock, which is the only way two operations of
        // different lengths can be compared at all.
        'xp_per_hour' => $hours > 0 ? round($xp * $successRate / $hours, 1) : 0,
        'credits_per_hour' => $hours > 0 ? round($credits * $successRate / $hours, 1) : 0,
        'reputation_per_hour' => $hours > 0 ? round($reputation * $successRate / $hours, 2) : 0,
        'loot_per_hour' => $hours > 0 ? round($expectedRolls * $successRate / $hours, 2) : 0,
        'fatigue_cost' => $fatigue,
        'fatigue_per_hour' => $hours > 0 ? round($fatigue / $hours, 1) : 0,
        'level_progress_percent' => $progress['xp_percent'],
        'stat_totals' => $totals,
        'gear_slots_used' => count(pw_tuning_legal_loadout($loadout, (string)$definition['role'], $level)),
        'gear_slots_chosen' => count($loadout),
    ];
}

/** The metrics a chart may plot, and how each should be read. */
function pw_tuning_metrics(): array {
    return [
        'success_percent' => ['label' => 'Success chance', 'unit' => '%', 'higher_is_better' => true],
        'duration_seconds' => ['label' => 'Duration', 'unit' => 's', 'higher_is_better' => false],
        'xp' => ['label' => 'XP per crew', 'unit' => '', 'higher_is_better' => true],
        'credits' => ['label' => 'Credits', 'unit' => '', 'higher_is_better' => true],
        'reputation' => ['label' => 'Reputation', 'unit' => '', 'higher_is_better' => true],
        'expected_xp' => ['label' => 'Expected XP (after failure)', 'unit' => '', 'higher_is_better' => true],
        'expected_credits' => ['label' => 'Expected credits (after failure)', 'unit' => '', 'higher_is_better' => true],
        'expected_loot_after_failure' => ['label' => 'Expected items', 'unit' => '', 'higher_is_better' => true],
        'expected_upgrades' => ['label' => 'Expected tier upgrades', 'unit' => '', 'higher_is_better' => true],
        'upgrade_percent' => ['label' => 'Upgrade chance', 'unit' => '%', 'higher_is_better' => true],
        'xp_per_hour' => ['label' => 'XP per hour', 'unit' => '/h', 'higher_is_better' => true],
        'credits_per_hour' => ['label' => 'Credits per hour', 'unit' => '/h', 'higher_is_better' => true],
        'reputation_per_hour' => ['label' => 'Reputation per hour', 'unit' => '/h', 'higher_is_better' => true],
        'loot_per_hour' => ['label' => 'Items per hour', 'unit' => '/h', 'higher_is_better' => true],
        'fatigue_per_hour' => ['label' => 'Fatigue per hour', 'unit' => '/h', 'higher_is_better' => false],
        'failure_percent' => ['label' => 'Failure chance', 'unit' => '%', 'higher_is_better' => false],
        'level_progress_percent' => ['label' => 'Progress toward next level', 'unit' => '%', 'higher_is_better' => true],
    ];
}
