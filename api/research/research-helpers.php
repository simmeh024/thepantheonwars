<?php
/** Shared, server-authoritative rules for the player Research Facility. */
require_once __DIR__ . '/../missions/missions-helpers.php';

const PW_RESEARCH_BOARD_WIDTH = 1560;
const PW_RESEARCH_BOARD_HEIGHT = 900;

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
        'luck' => ['label' => 'Recovery luck', 'short' => 'Loot quality improved', 'value_label' => 'Luck boost (%)', 'description' => 'Raises the chance that a recovered item is promoted one rarity tier.'],
        'market_discount' => ['label' => 'Market discount', 'short' => 'Market prices reduced', 'value_label' => 'Discount (%)', 'description' => 'Reduces the credit price shown for every Market offer.'],
        'market_refresh' => ['label' => 'Market refresh', 'short' => 'Market signal cycles faster', 'value_label' => 'Refresh boost (%)', 'description' => 'Moves this command\'s Market rotation onto a faster signal cadence.'],
        'secret_mission' => ['label' => 'Secret mission access', 'short' => 'Classified operation unlocked', 'value_label' => 'Unused', 'description' => 'Reveals one administrator-selected classified mission.'],
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

function pw_research_image_url($value): string {
    $url = trim((string)$value);
    return preg_match('~^/uploads/research-images/img_[a-f0-9]{16}\.jpg$~', $url) ? $url : '';
}

function pw_research_default_effects(): array {
    return [
        'mission_speed_percent' => 0.0,
        'xp_percent' => 0.0,
        'reputation_percent' => 0.0,
        'luck_percent' => 0.0,
        'market_discount_percent' => 0.0,
        'market_refresh_percent' => 0.0,
        'secret_mission_ids' => [],
    ];
}

/** Active bonuses are account-owned and therefore read only from the server.
 * Each aggregate has a conservative ceiling so a large future tree can deepen
 * a build without turning a mission instant or a market offer free. */
function pw_research_player_effects(PDO $db, int $userId): array {
    $effects = pw_research_default_effects();
    if (!pw_research_ready($db)) return $effects;
    $stmt = $db->prepare(
        'SELECT n.effect_type, n.effect_value, n.target_mission_definition_id
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
            case 'luck': $effects['luck_percent'] += $value; break;
            case 'market_discount': $effects['market_discount_percent'] += $value; break;
            case 'market_refresh': $effects['market_refresh_percent'] += $value; break;
            case 'secret_mission':
                if ($row['target_mission_definition_id'] !== null) $effects['secret_mission_ids'][] = (int)$row['target_mission_definition_id'];
                break;
        }
    }
    $effects['mission_speed_percent'] = round(min(60.0, $effects['mission_speed_percent']), 2);
    $effects['xp_percent'] = round(min(75.0, $effects['xp_percent']), 2);
    $effects['reputation_percent'] = round(min(75.0, $effects['reputation_percent']), 2);
    $effects['luck_percent'] = round(min(75.0, $effects['luck_percent']), 2);
    $effects['market_discount_percent'] = round(min(50.0, $effects['market_discount_percent']), 2);
    $effects['market_refresh_percent'] = round(min(50.0, $effects['market_refresh_percent']), 2);
    $effects['secret_mission_ids'] = array_values(array_unique($effects['secret_mission_ids']));
    return $effects;
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
