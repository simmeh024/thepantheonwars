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
 * Group one world's missions into ordered campaign tracks.
 *
 * A track is a succession chain followed forward from a mission that has no
 * prerequisite. Each track occupies a single card on the Missions page: only
 * its current step is playable, and clearing that step replaces the card with
 * the next operation rather than adding a second one beside it.
 *
 * Branching is possible in the schema (two missions may name the same
 * prerequisite) even though the admin UI encourages a straight chain. The
 * lowest sort_order successor continues the track and any sibling starts a
 * track of its own, so a branch degrades into two visible tracks instead of
 * silently dropping missions.
 *
 * A mission flagged is_campaign_final ends its track. Anything chained beyond
 * it begins a fresh track, so an early finale retires the ending rather than
 * deleting the operations that follow it.
 *
 * @param array $missionsById Every mission in the world, keyed by id.
 * @return array[] Ordered lists of mission rows, one list per track.
 */
function pw_missions_build_campaign_tracks(array $missionsById): array {
    $successors = [];
    foreach ($missionsById as $mission) {
        $previous = $mission['unlocks_after_mission_id'];
        if ($previous !== null && isset($missionsById[$previous])) {
            $successors[$previous][] = (int)$mission['id'];
        }
    }
    $rank = static function (int $id) use ($missionsById): array {
        return [(int)$missionsById[$id]['sort_order'], $id];
    };
    foreach ($successors as $previous => $ids) {
        usort($ids, static function ($a, $b) use ($rank) { return $rank($a) <=> $rank($b); });
        $successors[$previous] = $ids;
    }

    $queue = [];
    foreach ($missionsById as $mission) {
        $previous = $mission['unlocks_after_mission_id'];
        if ($previous === null || !isset($missionsById[$previous])) $queue[] = (int)$mission['id'];
    }
    usort($queue, static function ($a, $b) use ($rank) { return $rank($a) <=> $rank($b); });

    /* A cycle among damaged rows has no root at all, so a root-only sweep would
     * drop every mission caught in it -- silently losing operations is a worse
     * failure than showing them in an odd order. Anything left unassigned is
     * seeded as its own root, which guarantees every mission lands in exactly
     * one track. The admin save path already rejects cycles; this is the floor
     * beneath that. */
    $leftovers = array_keys($missionsById);
    usort($leftovers, static function ($a, $b) use ($rank) { return $rank($a) <=> $rank($b); });

    $tracks = [];
    $assigned = [];
    while ($queue || $leftovers) {
        $cursor = $queue ? array_shift($queue) : array_shift($leftovers);
        if (isset($assigned[$cursor])) continue;
        $chain = [];
        while ($cursor !== null && !isset($assigned[$cursor])) {
            $assigned[$cursor] = true;
            $chain[] = $missionsById[$cursor];
            $next = $successors[$cursor] ?? [];
            if (!empty($missionsById[$cursor]['is_campaign_final'])) {
                // The finale closes this track; whatever chains after it is a
                // separate campaign that opens once this one is finished.
                foreach ($next as $laterId) $queue[] = $laterId;
                break;
            }
            foreach (array_slice($next, 1) as $branchId) $queue[] = $branchId;
            $cursor = $next[0] ?? null;
        }
        if ($chain) $tracks[] = $chain;
    }
    return $tracks;
}

/**
 * Resolve a single track into the blocks the progress bar draws.
 *
 * A step's requirement is stored on its *successor*: mission B carrying
 * unlocks_after_completion_count = 3 means step A needs three claimed runs.
 * The last step needs a single claimed run. This is the same rule the unlock
 * gate itself applies, so the bar can never disagree with which mission is
 * actually playable.
 *
 * The returned current_index is the step the player is on. Once every step is
 * complete it stays on the finale, which remains replayable -- finishing a
 * campaign should not leave an empty slot where the card used to be.
 *
 * @param array $chain          Ordered mission rows for one track.
 * @param array $claimedCounts  mission_definition_id => claimed run count.
 */
function pw_missions_track_progress(array $chain, array $claimedCounts): array {
    $steps = [];
    $completed = 0;
    $currentIndex = null;
    foreach ($chain as $index => $mission) {
        $successor = $chain[$index + 1] ?? null;
        $required = $successor !== null ? max(1, (int)$successor['unlocks_after_completion_count']) : 1;
        $done = min($required, (int)($claimedCounts[(int)$mission['id']] ?? 0));
        $isComplete = $done >= $required;
        if ($isComplete) $completed++;
        if (!$isComplete && $currentIndex === null) $currentIndex = $index;
        $steps[] = [
            'position' => $index + 1,
            'runs_done' => $done,
            'runs_required' => $required,
            'is_complete' => $isComplete,
            'name' => null,
            'state' => $isComplete ? 'complete' : 'locked',
        ];
    }
    $total = count($chain);
    $isComplete = $completed >= $total;
    if ($currentIndex === null) $currentIndex = $total - 1;
    if (!$isComplete) $steps[$currentIndex]['state'] = 'current';

    /* A step the player has not reached carries no name. Naming stops at the
     * current step, so the bar shows how far the campaign runs without
     * revealing what is still sealed. */
    foreach ($steps as $index => $step) {
        if ($index <= $currentIndex) $steps[$index]['name'] = $chain[$index]['name'];
    }

    return [
        'total_steps' => $total,
        'completed_steps' => $completed,
        'is_complete' => $isComplete,
        'current_index' => $currentIndex,
        'steps' => $steps,
    ];
}

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
