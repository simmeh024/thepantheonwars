<?php
/** Shared, server-authoritative rules for the player Research Facility. */
require_once __DIR__ . '/../missions/missions-helpers.php';

/* The authoring canvas. A node card is 196x126 and the board draws a 30px
 * grid, so both dimensions are kept as multiples of 30 and both pitches below
 * are grid-aligned: 240 across (196 + 44) and 180 down (126 + 54).
 *
 * Widened from 1560 by two column pitches, which is room for two more steps on
 * the longest chain, and deepened from 900 by one row pitch for another row of
 * cards beneath the existing tree. Four places hold these numbers -- these
 * constants, the CSS fallback on .research-tree-board, and the fallback
 * defaults in js/research.js and js/admin-research.js -- and all four must
 * agree or nodes place outside a board that clips them. */
const PW_RESEARCH_BOARD_WIDTH = 2040;
const PW_RESEARCH_BOARD_HEIGHT = 1080;

/**
 * Research is deliberately a small closed vocabulary. The player never sends a
 * bonus name or amount that the server has not authored, which keeps the tree
 * expressive without allowing a crafted request to invent a new reward type.
 */
function pw_research_effect_types(): array {
    return [
        'mission_speed' => ['label' => 'Mission speed', 'short' => 'Mission time reduced', 'value_label' => 'Speed boost (%)', 'description' => 'Reduces the duration locked in when a mission launches.'],
        'xp_gain' => ['label' => 'Experience gain', 'short' => 'Mission XP increased', 'value_label' => 'XP boost (%)', 'description' => 'Increases the XP each assigned crew member earns from a successful mission.'],
        'reputation_gain' => ['label' => 'Reputation gain', 'short' => 'Mission reputation increased', 'value_label' => 'Reputation boost (%)', 'description' => 'Increases reputation paid by successful missions.'],
        'credit_gain' => ['label' => 'Credit gain', 'short' => 'Mission credits increased', 'value_label' => 'Credit boost (%)', 'description' => 'Increases credits paid by successful missions, after the crew assignment bonus.'],
        'crew_capacity' => ['label' => 'Crew capacity', 'short' => 'Crew berth capacity expanded', 'value_label' => 'Additional crew slots', 'description' => 'Adds permanent room for more crew members to join the expedition.'],
        'crew_fatigue' => ['label' => 'Crew endurance', 'short' => 'Crew fatigue capacity raised', 'value_label' => 'Additional fatigue', 'description' => 'Raises the fatigue ceiling of every crew member, so they can run more operations back to back before resting.'],
        'fatigue_recovery' => ['label' => 'Crew recovery', 'short' => 'Crew rest faster', 'value_label' => 'Recovery boost (%)', 'description' => 'Speeds up the rate at which resting crew regain fatigue, shortening the wait between operations.'],
        'luck' => ['label' => 'Rarity promotion', 'short' => 'Loot rarity improved', 'value_label' => 'Promotion chance (%)', 'description' => 'Raises the chance that a recovered item is promoted one rarity tier.'],
        'market_discount' => ['label' => 'Market discount', 'short' => 'Market prices reduced', 'value_label' => 'Discount (%)', 'description' => 'Reduces the credit price shown for every Market offer.'],
        'market_refresh' => ['label' => 'Market refresh', 'short' => 'Market signal cycles faster', 'value_label' => 'Refresh boost (%)', 'description' => 'Moves this command\'s Market rotation onto a faster signal cadence.'],
        'secret_mission' => ['label' => 'Secret mission access', 'short' => 'Classified operation unlocked', 'value_label' => 'Unused', 'description' => 'Reveals one administrator-selected classified mission.'],
        'rare_loot_table' => ['label' => 'Rare loot table access', 'short' => 'Rare recovery table unlocked', 'value_label' => 'Unused', 'description' => 'Opens one administrator-selected rare loot table for missions that carry it.'],
    ];
}

function pw_research_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!pw_mission_credits_ready($db) || !pw_mission_gear_ready($db)) return $ready = false;
    try {
        foreach (['game_research_categories', 'game_research_nodes', 'game_research_prerequisites', 'game_player_research'] as $table) {
            $db->query('SELECT 1 FROM `' . $table . '` LIMIT 1');
        }
        $db->query('SELECT requires_all_other_unlocked FROM `game_research_categories` LIMIT 1');
        return $ready = true;
    } catch (Throwable $e) {
        return $ready = false;
    }
}

function pw_research_require_ready(PDO $db): void {
    if (!pw_research_ready($db)) {
        pw_error('The Research Facility is being prepared. Please try again after the research migrations have been run.', 503);
    }
}

/**
 * Renders one research node's effect as a single reader-facing sentence, using
 * the same closed effect vocabulary the Research Facility itself renders from
 * so the reputation preview can never promise a bonus the tree does not grant.
 */
function pw_research_effect_sentence(array $node): string {
    $types = pw_research_effect_types();
    $effect = (string)$node['effect_type'];
    $meta = $types[$effect] ?? null;
    $value = (float)$node['effect_value'];
    if ($effect === 'crew_capacity') {
        return 'Adds ' . max(1, (int)$value) . ' permanent crew ' . ((int)$value === 1 ? 'berth' : 'berths') . ' to your expedition.';
    }
    if ($effect === 'rare_loot_table') {
        return 'Opens a rare recovery table for the missions that carry it.';
    }
    if ($meta === null) {
        return 'Adds a permanent expedition advantage.';
    }
    $percent = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    if ($percent === '' || $percent === '0') {
        return (string)$meta['description'];
    }
    return $meta['short'] . ' by ' . $percent . '%. ' . $meta['description'];
}

/**
 * The research protocols and classified missions that a reputation rank makes
 * researchable. `game_research_nodes.required_reputation_level` is a rank
 * number (1-based ladder position), matching the gate `api/research/unlock.php`
 * enforces, so this preview and that check can never disagree.
 *
 * A `secret_mission` node is deliberately reported as a mission unlock rather
 * than a research one: the mission is what the player actually gains, and it is
 * the only route by which a mission becomes reachable from a reputation rank.
 */
function pw_research_next_rank_unlocks(PDO $db, int $rankNumber, int $threshold, string $accent): array {
    if ($rankNumber < 1) return [];
    try {
        $stmt = $db->prepare(
            'SELECT n.name, n.description, n.effect_type, n.effect_value, n.credit_cost,
                    c.name AS category_name, m.name AS mission_name, m.description AS mission_description,
                    m.world_key AS mission_world, m.mission_type AS mission_type
             FROM game_research_nodes n
             LEFT JOIN game_research_categories c ON c.id = n.research_category_id
             LEFT JOIN game_mission_definitions m ON m.id = n.target_mission_definition_id AND m.is_enabled = 1
             WHERE n.is_enabled = 1 AND n.required_reputation_level = ?
             ORDER BY n.sort_order ASC, n.id ASC'
        );
        $stmt->execute([$rankNumber]);
        $unlocks = [];
        foreach ($stmt->fetchAll() as $node) {
            $protocol = trim((string)$node['name']);
            $isMission = (string)$node['effect_type'] === 'secret_mission' && trim((string)$node['mission_name']) !== '';
            if ($isMission) {
                $detail = trim((string)$node['mission_description']);
                if ($detail === '') {
                    $type = trim((string)$node['mission_type']);
                    $detail = 'A classified ' . ($type !== '' ? $type . ' ' : '') . 'operation joins your mission board.';
                }
                $unlocks[] = [
                    'type' => 'mission',
                    'title' => trim((string)$node['mission_name']),
                    'eyebrow' => 'Classified mission',
                    'description' => $detail . ' Revealed by the ' . ($protocol !== '' ? $protocol : 'classified') . ' protocol, which becomes researchable at this rank.',
                    'accent' => $accent,
                    'threshold' => $threshold,
                ];
                continue;
            }
            $category = trim((string)$node['category_name']);
            $cost = (int)$node['credit_cost'];
            $unlocks[] = [
                'type' => 'research',
                'title' => $protocol !== '' ? $protocol : 'Research protocol',
                'eyebrow' => $category !== '' ? 'Research · ' . $category : 'Research protocol',
                'description' => pw_research_effect_sentence($node)
                    . ($cost > 0 ? ' Research cost: ' . number_format($cost) . ' credits.' : ''),
                'accent' => $accent,
                'threshold' => $threshold,
            ];
        }
        return $unlocks;
    } catch (Throwable $e) {
        // Reputation predates the Research Facility and must keep its preview
        // when those migrations have not been run yet.
        return [];
    }
}

function pw_research_image_url($value): string {
    $url = trim((string)$value);
    return preg_match('~^/uploads/research-images/img_[a-f0-9]{16}\.jpg$~', $url) ? $url : '';
}

function pw_research_default_effects(): array {
    return [
        'mission_speed_percent' => 0.0,
        'xp_percent' => 0.0,
        'reputation_percent' => 0.0,
        'credit_percent' => 0.0,
        'crew_capacity' => 0,
        'crew_fatigue' => 0,
        'fatigue_recovery_percent' => 0.0,
        'luck_percent' => 0.0,
        'market_discount_percent' => 0.0,
        'market_refresh_percent' => 0.0,
        'secret_mission_ids' => [],
        'rare_loot_table_ids' => [],
    ];
}

/**
 * Rare-table protocols were added after the first Research Facility release.
 * Keep this probe independent from the base readiness check so the existing
 * tree remains usable while a deployment is waiting for its one-off migration.
 */
function pw_research_loot_table_locks_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!pw_research_ready($db) || !pw_mission_loot_table_research_locks_ready($db)) return $ready = false;
    try {
        $db->query('SELECT target_loot_table_id FROM `game_research_nodes` LIMIT 1');
        return $ready = true;
    } catch (Throwable $e) {
        return $ready = false;
    }
}

/** Queue selections and authored activation transmissions were added after the
 * first Research Facility release. Keep their probe independent so existing
 * players can still use the lattice while this optional migration is pending. */
function pw_research_queue_transmissions_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!pw_research_ready($db)) return $ready = false;
    try {
        $db->query('SELECT activation_transmission FROM `game_research_nodes` LIMIT 1');
        foreach (['game_player_research_queue', 'game_player_research_transmissions'] as $table) {
            $db->query('SELECT 1 FROM `' . $table . '` LIMIT 1');
        }
        return $ready = true;
    } catch (Throwable $e) {
        return $ready = false;
    }
}

/** Active bonuses are account-owned and therefore read only from the server.
 * Each aggregate has a conservative ceiling so a large future tree can deepen
 * a build without turning a mission instant or a market offer free. */
function pw_research_player_effects(PDO $db, int $userId): array {
    $effects = pw_research_default_effects();
    /* Stims still apply with no research tree at all: they arrive from the loot
     * pool and the Market, neither of which depends on the Research Facility
     * having been migrated. Returning bare defaults here would silently ignore
     * a boost the player had already spent. */
    if (!pw_research_ready($db)) return pw_missions_apply_stim_effects($db, $userId, $effects);
    $lootTableLocksReady = pw_research_loot_table_locks_ready($db);
    $stmt = $db->prepare(
        'SELECT n.effect_type, n.effect_value, n.target_mission_definition_id'
        . ($lootTableLocksReady ? ', n.target_loot_table_id' : ', NULL AS target_loot_table_id') . '
         FROM game_player_research pr
         JOIN game_research_nodes n ON n.id = pr.research_node_id
         WHERE pr.user_id = ?'
    );
    $stmt->execute([$userId]);
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
            case 'luck': $effects['luck_percent'] += $value; break;
            case 'market_discount': $effects['market_discount_percent'] += $value; break;
            case 'market_refresh': $effects['market_refresh_percent'] += $value; break;
            case 'secret_mission':
                if ($row['target_mission_definition_id'] !== null) $effects['secret_mission_ids'][] = (int)$row['target_mission_definition_id'];
                break;
            case 'rare_loot_table':
                if ($row['target_loot_table_id'] !== null) $effects['rare_loot_table_ids'][] = (int)$row['target_loot_table_id'];
                break;
        }
    }
    $effects['mission_speed_percent'] = round(min(60.0, $effects['mission_speed_percent']), 2);
    $effects['xp_percent'] = round(min(75.0, $effects['xp_percent']), 2);
    $effects['reputation_percent'] = round(min(75.0, $effects['reputation_percent']), 2);
    $effects['credit_percent'] = round(min(75.0, $effects['credit_percent']), 2);
    $effects['crew_capacity'] = (int)min(24, floor($effects['crew_capacity']));
    $effects['crew_fatigue'] = (int)min(PW_MISSION_FATIGUE_RESEARCH_CAP, floor($effects['crew_fatigue']));
    $effects['fatigue_recovery_percent'] = round(min(200.0, $effects['fatigue_recovery_percent']), 2);
    $effects['luck_percent'] = round(min(75.0, $effects['luck_percent']), 2);
    $effects['market_discount_percent'] = round(min(50.0, $effects['market_discount_percent']), 2);
    $effects['market_refresh_percent'] = round(min(50.0, $effects['market_refresh_percent']), 2);
    $effects['secret_mission_ids'] = array_values(array_unique($effects['secret_mission_ids']));
    $effects['rare_loot_table_ids'] = array_values(array_unique($effects['rare_loot_table_ids']));
    /* Stims are folded in here rather than beside this call so every existing
     * consumer picks a running boost up unchanged -- the launch projection, the
     * claim payout and the mission card all read this one array. Threading a
     * second array through those paths is how one of them ends up silently
     * ignoring boosts. The combined ceilings sit above the research-only caps
     * applied just above; see pw_missions_apply_stim_effects(). */
    return pw_missions_apply_stim_effects($db, $userId, $effects);
}

/** The starter berth count is eight. Capacity protocols are flat slots rather
 * than a percentage because their result must be immediately legible as 8/8,
 * 12/12, and so on across the command UI. */
function pw_research_crew_capacity(PDO $db, int $userId): int {
    return 8 + (int)(pw_research_player_effects($db, $userId)['crew_capacity'] ?? 0);
}

/** Classified missions are hidden in the mission response and re-checked at
 * launch. Once the Mission Management research-lock migration is active, that
 * checkbox is the source of truth: an unchecked mission is never classified,
 * while a checked mission stays sealed until an enabled Secret mission access
 * protocol targeting it is owned. The legacy query preserves the original
 * behaviour while an older database is still awaiting that migration. */
function pw_research_secret_missions(PDO $db, int $userId): array {
    $researchReady = pw_research_ready($db);
    $missionLocksReady = pw_mission_research_locks_ready($db);
    if (!$researchReady && !$missionLocksReady) return ['locked' => [], 'unlocked' => []];

    if ($missionLocksReady) {
        /* When Research itself is still being prepared, all explicitly sealed
         * missions remain hidden. Failing closed avoids a migration race ever
         * revealing an operation the author marked as research-gated. */
        $unlockExpression = $researchReady
            ? 'EXISTS (
                SELECT 1
                FROM game_research_nodes n
                JOIN game_player_research pr ON pr.research_node_id = n.id AND pr.user_id = ?
                WHERE n.is_enabled = 1 AND n.effect_type = "secret_mission"
                  AND n.target_mission_definition_id = mission.id
              )'
            : '0';
        $stmt = $db->prepare(
            'SELECT mission.id AS target_mission_definition_id, ' . $unlockExpression . ' AS is_unlocked
             FROM game_mission_definitions mission
             WHERE mission.requires_research_unlock = 1'
        );
        $stmt->execute($researchReady ? [$userId] : []);
    } else {
        $stmt = $db->prepare(
            'SELECT n.target_mission_definition_id, (pr.user_id IS NOT NULL) AS is_unlocked
             FROM game_research_nodes n
             LEFT JOIN game_player_research pr ON pr.research_node_id = n.id AND pr.user_id = ?
             WHERE n.is_enabled = 1 AND n.effect_type = "secret_mission" AND n.target_mission_definition_id IS NOT NULL'
        );
        $stmt->execute([$userId]);
    }
    $secret = ['locked' => [], 'unlocked' => []];
    foreach ($stmt->fetchAll() as $row) {
        $key = !empty($row['is_unlocked']) ? 'unlocked' : 'locked';
        $secret[$key][] = (int)$row['target_mission_definition_id'];
    }
    foreach ($secret as $key => $ids) $secret[$key] = array_values(array_unique($ids));
    return $secret;
}

function pw_research_mission_is_unlocked(PDO $db, int $userId, int $missionId): bool {
    return !in_array($missionId, pw_research_secret_missions($db, $userId)['locked'], true);
}

function pw_research_player_unlocked_ids(PDO $db, int $userId): array {
    $stmt = $db->prepare('SELECT research_node_id FROM game_player_research WHERE user_id = ?');
    $stmt->execute([$userId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function pw_research_format_percent(float $value): string {
    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
}
