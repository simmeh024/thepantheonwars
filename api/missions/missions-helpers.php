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
            /* MariaDB/PDO does not consistently support the previous
             * parameterized table-listing probe as a native prepared
             * statement. It can throw even when the table exists, and the
             * catch below then incorrectly reports that Missions V0 has not
             * been migrated. Table names here are an internal fixed allow-list,
             * so a direct, quoted probe is safe. */
            $db->query('SELECT 1 FROM `' . $table . '` LIMIT 1');
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
 * Mission Successions is an additive migration on top of Missions V0. Keep it
 * separate from the base table probe so an older, otherwise working mission
 * installation receives an actionable migration message instead of a generic
 * SQL failure when progression data is requested.
 */
function pw_mission_successions_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!pw_missions_ready($db)) return $ready = false;
    try {
        $db->query('SELECT unlocks_after_mission_id, unlocks_after_completion_count FROM `game_mission_definitions` LIMIT 1');
        return $ready = true;
    } catch (Throwable $e) {
        return $ready = false;
    }
}

function pw_missions_require_successions_ready(PDO $db): void {
    if (!pw_mission_successions_ready($db)) {
        pw_error('Mission progressions are being prepared. Please try again after the Mission Successions migration has been run.', 503);
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
