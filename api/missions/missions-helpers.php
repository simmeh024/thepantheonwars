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
 * Campaign Progress is a further additive migration on top of Mission
 * Successions. A missing column is a hard SQL error rather than NULL, so every
 * read path probes for it and falls back to "no campaign configured" instead of
 * failing the whole mission view. Deploy order is therefore not load-bearing.
 */
function pw_mission_campaign_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!pw_mission_successions_ready($db)) return $ready = false;
    try {
        $db->query('SELECT is_campaign_final FROM `game_mission_definitions` LIMIT 1');
        return $ready = true;
    } catch (Throwable $e) {
        return $ready = false;
    }
}

/**
 * Resolve one world's campaign into ordered steps.
 *
 * The chain is walked backwards from the administrator-flagged final mission
 * through unlocks_after_mission_id, then reversed, so the bar always reflects
 * the real gating relationships rather than a separately maintained list.
 *
 * A step's requirement is stored on its *successor*: mission B carrying
 * unlocks_after_completion_count = 3 means step A needs three claimed runs.
 * The final step needs a single claimed run to finish the campaign. This is the
 * same rule the unlock gate itself uses, so the bar can never disagree with
 * which missions are actually playable.
 *
 * @param array $missionsById   Every mission in the world, keyed by id.
 * @param array $claimedCounts  mission_definition_id => claimed run count.
 */
function pw_missions_campaign_progress(array $missionsById, array $claimedCounts): ?array {
    $final = null;
    foreach ($missionsById as $mission) {
        if (!empty($mission['is_campaign_final'])) { $final = $mission; break; }
    }
    if ($final === null) return null;

    $chain = [];
    $visited = [];
    $cursor = $final;
    while ($cursor !== null) {
        $cursorId = (int)$cursor['id'];
        // Defensive: the admin save path rejects loops, but a chain read on
        // every page load must never be able to hang on damaged data.
        if (isset($visited[$cursorId])) break;
        $visited[$cursorId] = true;
        array_unshift($chain, $cursor);
        $previousId = $cursor['unlocks_after_mission_id'] !== null ? (int)$cursor['unlocks_after_mission_id'] : null;
        $cursor = $previousId !== null && isset($missionsById[$previousId]) ? $missionsById[$previousId] : null;
    }
    if (!$chain) return null;

    $steps = [];
    $completedSteps = 0;
    $currentIndex = null;
    foreach ($chain as $index => $mission) {
        $missionId = (int)$mission['id'];
        $successor = $chain[$index + 1] ?? null;
        $required = $successor !== null ? max(1, (int)$successor['unlocks_after_completion_count']) : 1;
        $done = min($required, (int)($claimedCounts[$missionId] ?? 0));
        $isComplete = $done >= $required;
        if ($isComplete) $completedSteps++;
        if (!$isComplete && $currentIndex === null) $currentIndex = $index;
        $steps[] = [
            'position' => $index + 1,
            'runs_done' => $done,
            'runs_required' => $required,
            'is_complete' => $isComplete,
            // A step the player has never reached must not leak its mission
            // name; only completed and in-progress steps are named.
            'name' => null,
            'state' => $isComplete ? 'complete' : 'locked',
        ];
    }
    if ($currentIndex !== null) {
        $steps[$currentIndex]['state'] = 'current';
    }
    foreach ($steps as $index => $step) {
        if ($step['state'] !== 'locked') $steps[$index]['name'] = $chain[$index]['name'];
    }

    $total = count($chain);
    return [
        'total_steps' => $total,
        'completed_steps' => $completedSteps,
        'is_complete' => $completedSteps >= $total,
        'final_name' => $completedSteps >= $total ? $final['name'] : null,
        'steps' => $steps,
    ];
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
