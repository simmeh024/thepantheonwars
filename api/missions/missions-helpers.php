<?php
/**
 * Shared server-authoritative helpers for Missions V0. The game tables are
 * optional until their manual migration is run, so public callers get a clear
 * migration message instead of a partial mission state.
 */
require_once __DIR__ . '/../helpers.php';

function pw_missions_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        foreach ([
            'game_crew_definitions',
            'game_player_crew',
            'game_mission_definitions',
            'game_player_missions',
            'game_player_mission_crew',
        ] as $table) {
            $stmt = $db->prepare('SHOW TABLES LIKE ?');
            $stmt->execute([$table]);
            if (!$stmt->fetch()) {
                $ready = false;
                return false;
            }
        }
        $ready = true;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function pw_missions_require_ready(PDO $db): void {
    if (!pw_missions_ready($db)) {
        pw_error('Missions are being prepared. Please try again after the Missions V0 migration has been run.', 503);
    }
}

/**
 * Starter templates are cloned into a player-owned crew record the first time
 * the player visits Missions. INSERT IGNORE and the unique user/template key
 * make this safe across simultaneous page loads and future starter additions.
 */
function pw_missions_grant_starter_crew(PDO $db, int $userId): void {
    $stmt = $db->prepare(
        'INSERT IGNORE INTO game_player_crew (user_id, crew_definition_id, level, xp, status)
         SELECT ?, c.id, c.starting_level, 0, "available"
         FROM game_crew_definitions c
         WHERE c.is_starter = 1 AND c.is_enabled = 1'
    );
    $stmt->execute([$userId]);
}

function pw_missions_normalize_crew_ids($rawIds): array {
    if (!is_array($rawIds) || !$rawIds || count($rawIds) > 8) {
        pw_error('Choose the crew members for this mission.');
    }
    $ids = [];
    foreach ($rawIds as $rawId) {
        $id = filter_var($rawId, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) pw_error('One selected crew member is invalid.');
        $ids[] = $id;
    }
    $unique = array_values(array_unique($ids));
    if (count($unique) !== count($ids)) pw_error('Choose each crew member only once.');
    return $unique;
}

function pw_missions_placeholders(int $count): string {
    return implode(',', array_fill(0, $count, '?'));
}

function pw_missions_utc_now(PDO $db): DateTimeImmutable {
    $row = $db->query('SELECT UNIX_TIMESTAMP(UTC_TIMESTAMP()) AS timestamp')->fetch();
    return (new DateTimeImmutable('@' . (int)$row['timestamp']))->setTimezone(new DateTimeZone('UTC'));
}

function pw_missions_datetime(DateTimeImmutable $time): string {
    return $time->format('Y-m-d H:i:s');
}
