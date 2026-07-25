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

/**
 * Crew Stats is a further additive migration. Every read and write path probes
 * for it and falls back to the pre-stats behaviour, so a deploy landing ahead
 * of the migration keeps missions fully playable -- they simply award no loot
 * and cannot fail, exactly as before.
 */
function pw_mission_stats_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!pw_missions_ready($db)) return $ready = false;
    try {
        $db->query('SELECT strength, cunning, science, charisma FROM `game_player_crew` LIMIT 1');
        $db->query('SELECT base_success_percent, loot_rolls FROM `game_mission_definitions` LIMIT 1');
        $db->query('SELECT id FROM `game_loot_definitions` LIMIT 1');
        return $ready = true;
    } catch (Throwable $e) {
        return $ready = false;
    }
}

/* ------------------------------------------------------------------------
 * Crew stats, levelling, and the effects a crew brings to a mission.
 *
 * Everything here is a pure function of role, level and stat values, so the
 * same numbers can be shown in the browser and enforced on the server without
 * two implementations drifting apart. The browser only ever displays them; the
 * server recomputes every figure it acts on.
 * ---------------------------------------------------------------------- */

const PW_MISSION_MAX_LEVEL = 50;
const PW_MISSION_MAX_STAT = 50;
const PW_MISSION_XP_PER_LEVEL = 100;

/**
 * Stats a crew member has automatically allocated by this level: two points per
 * level into the role's primary stat and one into Cunning, each capped.
 *
 * The two caps meet at different levels on purpose. A primary stat reaches 50
 * at level 25 and plateaus, while Cunning arrives at exactly 50 at level 50, so
 * the second half of a career trades raw specialism for the role bonus and
 * steadily better loot.
 */
function pw_missions_stat_plan(string $role): array {
    $primary = [
        'Vanguard' => 'strength',
        'Pathfinder' => 'charisma',
        'Engineer' => 'science',
    ][$role] ?? null;
    return ['primary' => $primary, 'primary_per_level' => 2, 'cunning_per_level' => 1];
}

function pw_missions_stats_for_level(string $role, int $level): array {
    $level = max(0, min(PW_MISSION_MAX_LEVEL, $level));
    $plan = pw_missions_stat_plan($role);
    $stats = ['strength' => 0, 'cunning' => 0, 'science' => 0, 'charisma' => 0];
    $stats['cunning'] = min(PW_MISSION_MAX_STAT, $level * $plan['cunning_per_level']);
    if ($plan['primary'] !== null) {
        $stats[$plan['primary']] = min(PW_MISSION_MAX_STAT, $level * $plan['primary_per_level']);
    }
    return $stats;
}

/**
 * Level implied by total XP, at a flat 100 XP per level -- the same threshold
 * the crew card has always displayed. A crew template may start above level 1,
 * and levelling must never demote it, so the definition's starting level acts
 * as a floor.
 */
function pw_missions_level_for_xp(int $xp, int $startingLevel = 1): int {
    $earned = (int)floor(max(0, $xp) / PW_MISSION_XP_PER_LEVEL) + 1;
    return max(1, min(PW_MISSION_MAX_LEVEL, max($earned, $startingLevel)));
}

/**
 * Per-level role bonus one crew member contributes to a mission.
 * Engineer  0.05% shorter mission per level
 * Pathfinder 0.10% more XP for the whole crew per level
 * Vanguard  0.05 flat reputation per level
 * These stack across every crew member assigned, so three level-2 Engineers
 * contribute 3 x (2 x 0.05%) = 0.30%.
 */
function pw_missions_role_rates(): array {
    return [
        'Engineer' => ['duration_percent_per_level' => 0.05],
        'Pathfinder' => ['xp_percent_per_level' => 0.10],
        'Vanguard' => ['reputation_per_level' => 0.05],
    ];
}

/**
 * Per-point stat rates. Applied to the summed stat totals of the assigned crew.
 */
function pw_missions_stat_rates(): array {
    return [
        'strength' => ['key' => 'success_percent_per_point', 'value' => 0.5],
        'cunning' => ['key' => 'loot_percent_per_point', 'value' => 1.0],
        'charisma' => ['key' => 'xp_percent_per_point', 'value' => 0.5],
        'science' => ['key' => 'upgrade_percent_per_point', 'value' => 1.5],
    ];
}

/**
 * Total effects a set of assigned crew brings to one mission.
 *
 * @param array $crew Rows carrying role, level and the four stat columns.
 */
function pw_missions_crew_effects(array $crew): array {
    $rates = pw_missions_role_rates();
    $totals = ['strength' => 0, 'cunning' => 0, 'science' => 0, 'charisma' => 0];
    $durationPercent = 0.0;
    $xpPercent = 0.0;
    $reputationFlat = 0.0;

    foreach ($crew as $member) {
        $level = max(0, min(PW_MISSION_MAX_LEVEL, (int)($member['level'] ?? 0)));
        foreach ($totals as $stat => $_) {
            $totals[$stat] += max(0, min(PW_MISSION_MAX_STAT, (int)($member[$stat] ?? 0)));
        }
        $role = (string)($member['role'] ?? '');
        if (isset($rates[$role]['duration_percent_per_level'])) $durationPercent += $level * $rates[$role]['duration_percent_per_level'];
        if (isset($rates[$role]['xp_percent_per_level'])) $xpPercent += $level * $rates[$role]['xp_percent_per_level'];
        if (isset($rates[$role]['reputation_per_level'])) $reputationFlat += $level * $rates[$role]['reputation_per_level'];
    }

    // Charisma adds to the same XP pool the Pathfinder role bonus feeds.
    $xpPercent += $totals['charisma'] * 0.5;

    return [
        // Clamped well short of a free mission: a duration multiplier must stay
        // positive however large a future crew or rate becomes.
        'duration_percent' => round(min(90.0, $durationPercent), 2),
        'xp_percent' => round($xpPercent, 2),
        'reputation_flat' => (int)floor($reputationFlat),
        'success_percent' => round($totals['strength'] * 0.5, 2),
        'loot_percent' => round($totals['cunning'] * 1.0, 2),
        'upgrade_percent' => round(min(95.0, $totals['science'] * 1.5), 2),
        'stat_totals' => $totals,
    ];
}

/**
 * Mission duration after the Engineer bonus, floored so an operation can never
 * become instant however deep a crew's experience runs.
 */
function pw_missions_effective_duration(int $baseSeconds, array $effects): int {
    $seconds = (int)round($baseSeconds * (1 - ($effects['duration_percent'] / 100)));
    return max(30, min($baseSeconds, $seconds));
}

function pw_missions_effective_success(int $baseSuccessPercent, array $effects): int {
    $percent = (int)round($baseSuccessPercent + $effects['success_percent']);
    return max(5, min(100, $percent));
}

/* ------------------------------------------------------------------------
 * Loot.
 *
 * Cunning buys extra draws: every whole 100% of loot bonus is one guaranteed
 * additional roll, and the remainder is the chance of one more. Science is a
 * per-item upgrade roll that promotes a drop one tier up the ladder.
 *
 * Both are resolved server-side with random_int(). The client is never told
 * the odds it rolled against, only what it received.
 * ---------------------------------------------------------------------- */

function pw_missions_loot_tiers(): array {
    return ['common', 'uncommon', 'rare', 'legendary'];
}

/** Percent chance expressed to two decimals, rolled against a 0-10000 range. */
function pw_missions_percent_roll(float $percent): bool {
    $percent = max(0.0, min(100.0, $percent));
    if ($percent <= 0) return false;
    if ($percent >= 100) return true;
    return random_int(1, 10000) <= (int)round($percent * 100);
}

function pw_missions_loot_roll_count(int $baseRolls, array $effects): int {
    if ($baseRolls < 1) return 0;
    $bonus = max(0.0, $effects['loot_percent']);
    $rolls = $baseRolls + (int)floor($bonus / 100);
    if (pw_missions_percent_roll(fmod($bonus, 100))) $rolls++;
    // A single mission cannot empty the pool however deep a crew's Cunning runs.
    return min(12, $rolls);
}

/**
 * Draw loot for one mission. Returns rows of definition id, name and tier, one
 * entry per item awarded, with the Science upgrade already applied.
 */
function pw_missions_roll_loot(PDO $db, string $worldKey, int $baseRolls, array $effects): array {
    $rolls = pw_missions_loot_roll_count($baseRolls, $effects);
    if ($rolls < 1) return [];

    $stmt = $db->prepare('SELECT id, name, slug, tier, drop_weight FROM game_loot_definitions WHERE world_key = ? AND is_enabled = 1');
    $stmt->execute([$worldKey]);
    $pool = $stmt->fetchAll();
    if (!$pool) return [];

    /* Normalise the weights on the pool itself, not on a copy: an administrator
     * may set a drop weight of 0, and an all-zero pool would otherwise reach
     * random_int(1, 0) and throw. Every item stays drawable at minimum weight. */
    $byTier = [];
    foreach ($pool as $index => $item) {
        $pool[$index]['drop_weight'] = max(1, (int)$item['drop_weight']);
        $byTier[$item['tier']][] = $pool[$index];
    }
    $tiers = pw_missions_loot_tiers();

    $pick = static function (array $candidates) {
        $total = 0;
        foreach ($candidates as $item) $total += max(1, (int)$item['drop_weight']);
        $target = random_int(1, $total);
        foreach ($candidates as $item) {
            $target -= max(1, (int)$item['drop_weight']);
            if ($target <= 0) return $item;
        }
        return $candidates[count($candidates) - 1];
    };

    $awarded = [];
    for ($i = 0; $i < $rolls; $i++) {
        $item = $pick($pool);
        // Science promotes the drop one tier when it hits and a higher tier
        // actually exists; otherwise the original item stands.
        if (pw_missions_percent_roll($effects['upgrade_percent'])) {
            $index = array_search($item['tier'], $tiers, true);
            $nextTier = $index !== false ? ($tiers[$index + 1] ?? null) : null;
            if ($nextTier !== null && !empty($byTier[$nextTier])) {
                $item = $pick($byTier[$nextTier]);
                $item['upgraded'] = true;
            }
        }
        $awarded[] = [
            'id' => (int)$item['id'],
            'name' => $item['name'],
            'tier' => $item['tier'],
            'upgraded' => !empty($item['upgraded']),
        ];
    }
    return $awarded;
}

function pw_missions_store_loot(PDO $db, int $userId, array $awarded): void {
    if (!$awarded) return;
    $counts = [];
    foreach ($awarded as $item) {
        $counts[$item['id']] = ($counts[$item['id']] ?? 0) + 1;
    }
    $stmt = $db->prepare(
        'INSERT INTO game_player_loot (user_id, loot_definition_id, quantity) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)'
    );
    foreach ($counts as $definitionId => $quantity) {
        $stmt->execute([$userId, $definitionId, $quantity]);
    }
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
