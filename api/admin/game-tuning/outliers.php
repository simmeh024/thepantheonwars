<?php
/**
 * A whole-catalogue scan for things that look mistuned.
 *
 * Advisory only. Every finding is a statement about the numbers as authored,
 * never an instruction -- a mission really can be meant to pay double, and an
 * item really can be meant to be the best in its tier. The value is in being
 * told where to look rather than having to notice by reading every row.
 *
 * Missions are compared at a common baseline -- one level-1 crew member of the
 * role the operation prefers, no gear, no research -- so the comparison is
 * between the operations themselves rather than between whoever happened to be
 * simulated against each.
 */
require_once __DIR__ . '/tuning-helpers.php';

pw_require_permission('game_tuning.view');
$db = pw_db();
if (!pw_tuning_ready($db)) {
    pw_error('Game Tuning needs the Missions and crew-stats migrations before it can scan anything.', 503);
}

$findings = [];
$creditsReady = pw_mission_credits_ready($db);
$affinity = pw_missions_affinity_matrix();

$missions = $db->query(
    'SELECT id, name, mission_type, duration_seconds, min_crew, max_crew, xp_reward,
            reputation_reward, base_success_percent, loot_rolls, is_enabled'
    . ($creditsReady ? ', credit_reward' : ', 0 AS credit_reward') . '
     FROM game_mission_definitions WHERE is_enabled = 1
     ORDER BY sort_order ASC, id ASC'
)->fetchAll();

$rates = [];
foreach ($missions as $mission) {
    /* One crew member of a role this operation prefers, so a mission is never
     * scored while carrying the mismatch penalty it was never meant to take. */
    $preferred = array_keys($affinity[strtolower((string)$mission['mission_type'])] ?? []);
    $role = $preferred ? $preferred[0] : 'Vanguard';
    $point = pw_tuning_simulate_point(
        $db,
        ['id' => 0, 'name' => 'baseline', 'role' => $role, 'starting_level' => 1],
        $mission,
        1,
        max(1, (int)$mission['min_crew']),
        [],
        pw_research_default_effects()
    );
    $rates[] = [
        'mission' => $mission,
        'point' => $point,
    ];
}

$xpRates = pw_tuning_median(array_map(static function ($r) { return $r['point']['xp_per_hour']; }, $rates));
$creditRates = pw_tuning_median(array_map(static function ($r) { return $r['point']['credits_per_hour']; }, $rates));

foreach ($rates as $entry) {
    $mission = $entry['mission'];
    $point = $entry['point'];
    $name = (string)$mission['name'];
    if ((int)$mission['base_success_percent'] >= 100) {
        $findings[] = [
            'severity' => 'info', 'area' => 'Mission', 'subject' => $name,
            'detail' => 'Cannot fail. Its base success chance is 100%, so the whole success system, crew Strength and the mismatch penalty have no effect on it.',
        ];
    }
    if ($xpRates > 0 && $point['xp_per_hour'] > $xpRates * 2) {
        $findings[] = [
            'severity' => 'warn', 'area' => 'Mission', 'subject' => $name,
            'detail' => 'Pays ' . $point['xp_per_hour'] . ' XP an hour against a catalogue median of ' . round($xpRates, 1) . ' -- more than double the going rate.',
        ];
    }
    if ($xpRates > 0 && $point['xp_per_hour'] > 0 && $point['xp_per_hour'] < $xpRates / 3) {
        $findings[] = [
            'severity' => 'info', 'area' => 'Mission', 'subject' => $name,
            'detail' => 'Pays ' . $point['xp_per_hour'] . ' XP an hour against a median of ' . round($xpRates, 1) . '. Nothing will choose it while a better rate is unlocked.',
        ];
    }
    if ($creditsReady && $creditRates > 0 && $point['credits_per_hour'] > $creditRates * 2) {
        $findings[] = [
            'severity' => 'warn', 'area' => 'Mission', 'subject' => $name,
            'detail' => 'Pays ' . $point['credits_per_hour'] . ' credits an hour against a median of ' . round($creditRates, 1) . '.',
        ];
    }
    if ((int)$mission['xp_reward'] === 0 && (int)$mission['reputation_reward'] === 0 && (int)($mission['credit_reward'] ?? 0) === 0 && (int)$mission['loot_rolls'] === 0) {
        $findings[] = [
            'severity' => 'warn', 'area' => 'Mission', 'subject' => $name,
            'detail' => 'Pays nothing at all: no XP, reputation, credits or loot draws.',
        ];
    }
    if (pw_missions_fatigue_cost((int)$mission['duration_seconds']) === 0 && (int)$mission['duration_seconds'] >= 300) {
        $findings[] = [
            'severity' => 'info', 'area' => 'Mission', 'subject' => $name,
            'detail' => 'Costs no fatigue. Anything under ten minutes is free to run, so it can be repeated without limit.',
        ];
    }
}

/* Items, compared within their own tier. A legendary out-scaling a common is
 * the design; a common out-scaling its own tier's peers is usually a typo. */
if (pw_mission_gear_ready($db)) {
    $items = $db->query(
        'SELECT id, name, slot, tier, required_level, required_role,
                bonus_strength, bonus_cunning, bonus_science, bonus_charisma
         FROM game_loot_definitions WHERE is_enabled = 1 AND slot <> ""'
    )->fetchAll();
    $power = static function (array $item): float {
        return ((int)$item['bonus_strength'] * 0.5) + (int)$item['bonus_cunning']
            + ((int)$item['bonus_science'] * 1.5) + ((int)$item['bonus_charisma'] * 0.5);
    };
    $byTier = [];
    foreach ($items as $item) $byTier[(string)$item['tier']][] = $power($item);
    foreach ($items as $item) {
        $score = $power($item);
        $median = pw_tuning_median($byTier[(string)$item['tier']] ?? []);
        $name = (string)$item['name'];
        if ($score <= 0) {
            $findings[] = [
                'severity' => 'warn', 'area' => 'Item', 'subject' => $name,
                'detail' => 'Grants no stat bonus at all, so equipping it changes nothing.',
            ];
            continue;
        }
        if ($median > 0 && $score > $median * 2.5) {
            $findings[] = [
                'severity' => 'warn', 'area' => 'Item', 'subject' => $name,
                'detail' => 'Rated ' . round($score, 1) . ' against a ' . $item['tier'] . ' median of ' . round($median, 1) . '. It outclasses its own tier.',
            ];
        }
        if ((int)$item['required_level'] > PW_MISSION_MAX_LEVEL) {
            $findings[] = [
                'severity' => 'warn', 'area' => 'Item', 'subject' => $name,
                'detail' => 'Requires level ' . (int)$item['required_level'] . ', above the crew ceiling of ' . PW_MISSION_MAX_LEVEL . '. No crew member can ever equip it.',
            ];
        }
    }
}

/* Research, against the ceilings pw_research_player_effects() enforces. A node
 * at or near its own cap makes every later node in that line worthless. */
if (pw_research_ready($db)) {
    $caps = [
        'mission_speed' => 60.0, 'xp_gain' => 75.0, 'reputation_gain' => 75.0,
        'credit_gain' => 75.0, 'luck' => 75.0, 'market_discount' => 50.0,
        'market_refresh' => 50.0, 'crew_capacity' => 24.0,
        'crew_fatigue' => (float)PW_MISSION_FATIGUE_RESEARCH_CAP,
    ];
    $nodes = $db->query('SELECT id, name, effect_type, effect_value, required_reputation_level FROM game_research_nodes WHERE is_enabled = 1')->fetchAll();
    $totals = [];
    foreach ($nodes as $node) {
        $type = (string)$node['effect_type'];
        if (!isset($caps[$type])) continue;
        $totals[$type] = ($totals[$type] ?? 0) + (float)$node['effect_value'];
        if ((float)$node['effect_value'] >= $caps[$type] * 0.6) {
            $findings[] = [
                'severity' => 'warn', 'area' => 'Research', 'subject' => (string)$node['name'],
                'detail' => 'Contributes ' . (float)$node['effect_value'] . ' of a ' . $caps[$type] . ' ceiling on its own. Later protocols in this line will be largely wasted.',
            ];
        }
        if ((int)$node['required_reputation_level'] > 99) {
            $findings[] = [
                'severity' => 'warn', 'area' => 'Research', 'subject' => (string)$node['name'],
                'detail' => 'Gated above rank 99 and is therefore unreachable.',
            ];
        }
    }
    foreach ($totals as $type => $total) {
        if ($total > $caps[$type]) {
            $findings[] = [
                'severity' => 'info', 'area' => 'Research', 'subject' => ucfirst(str_replace('_', ' ', $type)),
                'detail' => 'The whole line totals ' . round($total, 2) . ' against a ' . $caps[$type] . ' ceiling, so ' . round($total - $caps[$type], 2) . ' of it can never apply.',
            ];
        }
    }
}

/* Warnings first: an ordering by how likely each is to be a real mistake. */
usort($findings, static function ($a, $b) {
    $rank = ['warn' => 0, 'info' => 1];
    return [$rank[$a['severity']], $a['area'], $a['subject']] <=> [$rank[$b['severity']], $b['area'], $b['subject']];
});

pw_json([
    'ok' => true,
    'findings' => $findings,
    'baseline' => [
        'xp_per_hour_median' => round($xpRates, 1),
        'credits_per_hour_median' => round($creditRates, 1),
        'missions_scanned' => count($missions),
    ],
]);
