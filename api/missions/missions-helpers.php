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
 * Decide which step of a track is actually playable right now.
 *
 * Normally that is the current step. When an administrator disables the current
 * operation, the track rolls back to the most recent earlier step that is still
 * enabled, so the campaign keeps something to run instead of going dark -- an
 * earlier operation the player has already cleared is replayable, and offering
 * it back is far better than an offline card with no action on it.
 *
 * Real progress is untouched: the rolled-back step is already complete, so
 * replaying it cannot advance or rewind the campaign, and the bar still shows
 * the true position. Only if every step from the current one back to the start
 * is disabled does the track have nothing to offer.
 *
 * @return array{index: ?int, rolled_back: bool, offline_index: ?int}
 */
function pw_missions_resolve_playable_step(array $chain, array $progress): array {
    $currentIndex = (int)$progress['current_index'];
    if (!empty($chain[$currentIndex]['is_enabled'])) {
        return ['index' => $currentIndex, 'rolled_back' => false, 'offline_index' => null];
    }
    for ($index = $currentIndex - 1; $index >= 0; $index--) {
        if (!empty($chain[$index]['is_enabled'])) {
            return ['index' => $index, 'rolled_back' => true, 'offline_index' => $currentIndex];
        }
    }
    return ['index' => null, 'rolled_back' => false, 'offline_index' => $currentIndex];
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
 * Mission presentation.
 *
 * The watermark is a single site-wide image drawn softly behind the Missions
 * page for every player. It lives in app_settings rather than a new table --
 * there is exactly one of it, with no per-row identity to key on, which is the
 * same reason Site Settings and Mail Settings live there.
 * ---------------------------------------------------------------------- */

/** Every path a watermark may point at. Anything else is discarded. */
function pw_missions_watermark_url($value): string {
    $url = trim((string)$value);
    if ($url === '') return '';
    return preg_match('~^(?:images/[a-zA-Z0-9._-]{1,220}|/uploads/mission-images/img_[a-f0-9]{16}\.(?:jpg|png))$~', $url) ? $url : '';
}

/**
 * Read the watermark configuration.
 *
 * The stored URL is re-validated on the way out, not only on the way in: this
 * value is written straight into a CSS url() on every player's page, so a row
 * edited directly in the database must not be able to reach the browser.
 */
function pw_missions_watermark_settings(): array {
    $settings = ['url' => '', 'enabled' => false, 'opacity' => 8];
    try {
        $stmt = pw_db()->prepare('SELECT `key`, value FROM app_settings WHERE `key` IN (?, ?, ?)');
        $stmt->execute(['missions_watermark_url', 'missions_watermark_enabled', 'missions_watermark_opacity']);
        foreach ($stmt->fetchAll() as $row) {
            if ($row['key'] === 'missions_watermark_url') $settings['url'] = pw_missions_watermark_url($row['value']);
            if ($row['key'] === 'missions_watermark_enabled') $settings['enabled'] = $row['value'] === '1';
            if ($row['key'] === 'missions_watermark_opacity') $settings['opacity'] = max(1, min(40, (int)$row['value']));
        }
    } catch (Throwable $e) {
        // app_settings is long-established, but a read failure here must never
        // take the mission view down over a decorative background.
    }
    if ($settings['url'] === '') $settings['enabled'] = false;
    return $settings;
}

/**
 * Per-mission watermark is a further additive migration, separate from the
 * page-wide one above: that is one image behind the whole Missions page, this
 * belongs to a single operation and travels with it onto its active card.
 */
function pw_mission_watermark_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!pw_missions_ready($db)) return $ready = false;
    try {
        $db->query('SELECT watermark_url, watermark_opacity FROM `game_mission_definitions` LIMIT 1');
        return $ready = true;
    } catch (Throwable $e) {
        return $ready = false;
    }
}

/**
 * Mission Credits is a further additive migration. Same guarded-probe rule as
 * the migrations above: a missing column is a hard SQL error rather than NULL,
 * so every read and write path falls back to "this world has no currency yet"
 * and missions stay fully playable while the migration is pending.
 */
function pw_mission_credits_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!pw_missions_ready($db)) return $ready = false;
    try {
        $db->query('SELECT credit_reward FROM `game_mission_definitions` LIMIT 1');
        $db->query('SELECT user_id, credits FROM `game_player_wallet` LIMIT 1');
        return $ready = true;
    } catch (Throwable $e) {
        return $ready = false;
    }
}

/** Current balance. A player with no wallet row simply holds nothing. */
function pw_missions_credit_balance(PDO $db, int $userId): int {
    $stmt = $db->prepare('SELECT credits FROM game_player_wallet WHERE user_id = ?');
    $stmt->execute([$userId]);
    $credits = $stmt->fetchColumn();
    return $credits === false ? 0 : (int)$credits;
}

/**
 * Add to a player's balance and return the new total. The upsert is what makes
 * the wallet row appear on first payment, so nothing has to create one at
 * registration and an account that predates this feature needs no backfill.
 */
function pw_missions_add_credits(PDO $db, int $userId, int $amount): int {
    if ($amount <= 0) return pw_missions_credit_balance($db, $userId);
    $stmt = $db->prepare(
        'INSERT INTO game_player_wallet (user_id, credits) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE credits = credits + VALUES(credits)'
    );
    $stmt->execute([$userId, $amount]);
    return pw_missions_credit_balance($db, $userId);
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

/* ------------------------------------------------------------------------
 * Mission-type role affinity.
 *
 * The role bonuses above are the same on every operation. Affinity is the
 * opposite: it asks whether this crew member is the right specialist for this
 * kind of work, so who you send matters as much as how experienced they are.
 *
 * Every role earns its keep on exactly two of the three operation types, and
 * every type has two ways in -- no role is dead weight and no type is a trap:
 *
 *   recon    Vanguard  +5% credits      Pathfinder +5% XP
 *   survey   Engineer  -5% duration     Pathfinder +5% reputation
 *   salvage  Engineer  +5% loot upgrade Vanguard   +5% success
 *
 * A matching member's bonus stacks, so two Vanguards on a recon run earn 10%
 * more credits.
 *
 * Assigning nobody from either preferred role is charged once -- not once per
 * mismatched member. A two-crew operation with a single wrong role would
 * otherwise be charged twice, and +40% duration on a compounding penalty makes
 * the mission not worth running rather than merely worse.
 * ---------------------------------------------------------------------- */

const PW_MISSION_AFFINITY_PERCENT = 5.0;
const PW_MISSION_AFFINITY_PENALTY_DURATION = 20.0;
const PW_MISSION_AFFINITY_PENALTY_SUCCESS = 5.0;

/**
 * Which role earns which bonus on which operation type. Keyed by the lowercase
 * mission_type value Mission Control stores, and by the exact role strings the
 * crew definitions use.
 */
function pw_missions_affinity_matrix(): array {
    return [
        'recon' => ['Vanguard' => 'credit_percent', 'Pathfinder' => 'xp_percent'],
        'survey' => ['Engineer' => 'duration_percent', 'Pathfinder' => 'reputation_percent'],
        'salvage' => ['Engineer' => 'upgrade_percent', 'Vanguard' => 'success_percent'],
    ];
}

/**
 * The matrix in the shape the browser draws it: one entry per operation type,
 * each preferred role carrying the effect it feeds and a ready reader label.
 * Sent to the launch screen so the tags and the projection are driven by the
 * server's own rates rather than by a second copy of them in JavaScript.
 */
function pw_missions_affinity_rules(): array {
    $labels = [
        'credit_percent' => 'credits',
        'xp_percent' => 'XP',
        'reputation_percent' => 'reputation',
        'duration_percent' => 'faster',
        'upgrade_percent' => 'loot quality',
        'success_percent' => 'success',
    ];
    $rules = [];
    foreach (pw_missions_affinity_matrix() as $type => $roles) {
        $preferred = [];
        foreach ($roles as $role => $effect) {
            $preferred[$role] = [
                'effect' => $effect,
                'percent' => PW_MISSION_AFFINITY_PERCENT,
                'label' => '+' . rtrim(rtrim(number_format(PW_MISSION_AFFINITY_PERCENT, 1, '.', ''), '0'), '.') . '% ' . $labels[$effect],
            ];
        }
        $rules[$type] = [
            'preferred' => $preferred,
            'penalty' => [
                'duration_percent' => PW_MISSION_AFFINITY_PENALTY_DURATION,
                'success_percent' => PW_MISSION_AFFINITY_PENALTY_SUCCESS,
            ],
        ];
    }
    return $rules;
}

/**
 * Affinity a set of assigned crew brings to one operation type.
 *
 * An unrecognized type earns no bonus and, deliberately, takes no penalty
 * either: a type added straight to the database would otherwise silently punish
 * every crew sent to it.
 *
 * @param array $crew Rows carrying at least a role.
 */
function pw_missions_affinity(?string $missionType, array $crew): array {
    $type = strtolower(trim((string)$missionType));
    $result = [
        'type' => $type,
        'preferred_roles' => [],
        'matched_roles' => [],
        'matched_count' => 0,
        'penalty' => false,
        'credit_percent' => 0.0,
        'xp_percent' => 0.0,
        'reputation_percent' => 0.0,
        'duration_percent' => 0.0,
        'upgrade_percent' => 0.0,
        'success_percent' => 0.0,
        'penalty_duration_percent' => 0.0,
        'penalty_success_percent' => 0.0,
    ];
    $map = pw_missions_affinity_matrix()[$type] ?? null;
    if ($map === null) return $result;
    $result['preferred_roles'] = array_keys($map);

    foreach ($crew as $member) {
        $role = (string)($member['role'] ?? '');
        if (!isset($map[$role])) continue;
        $result[$map[$role]] += PW_MISSION_AFFINITY_PERCENT;
        $result['matched_count']++;
        if (!in_array($role, $result['matched_roles'], true)) $result['matched_roles'][] = $role;
    }

    // Charged only on a crew that was actually assigned: an empty selection is
    // a preview of nothing, not a mismatch.
    if ($result['matched_count'] === 0 && count($crew) > 0) {
        $result['penalty'] = true;
        $result['penalty_duration_percent'] = PW_MISSION_AFFINITY_PENALTY_DURATION;
        $result['penalty_success_percent'] = PW_MISSION_AFFINITY_PENALTY_SUCCESS;
    }
    return $result;
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
 * @param string|null $missionType Operation type, when the effects are being
 *        computed for a specific mission. Omitted where there is no mission in
 *        context -- a single crew card, or the whole roster's headline -- in
 *        which case no affinity bonus or penalty applies and the result is
 *        exactly what it was before affinity existed.
 */
function pw_missions_crew_effects(array $crew, ?string $missionType = null): array {
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

    /* Affinity is added to the same pools the stats and role bonuses feed, so
     * every consumer downstream keeps reading one figure per effect rather than
     * having to know that a second bonus system exists. */
    $affinity = pw_missions_affinity($missionType, $crew);
    $durationPercent += $affinity['duration_percent'];
    $xpPercent += $affinity['xp_percent'];

    return [
        // Clamped well short of a free mission: a duration multiplier must stay
        // positive however large a future crew or rate becomes.
        'duration_percent' => round(min(90.0, $durationPercent), 2),
        // A mismatched crew is slower. Kept separate from the reduction above
        // rather than subtracted from it, so a crew that is both experienced and
        // wrong for the job is still slower than the same crew sent where it
        // belongs -- netting the two would let deep Engineer levels cancel the
        // penalty out entirely.
        'duration_penalty_percent' => round($affinity['penalty_duration_percent'], 2),
        'xp_percent' => round($xpPercent, 2),
        'reputation_flat' => (int)floor($reputationFlat),
        'reputation_percent' => round($affinity['reputation_percent'], 2),
        'credit_percent' => round($affinity['credit_percent'], 2),
        'success_percent' => round(($totals['strength'] * 0.5) + $affinity['success_percent'] - $affinity['penalty_success_percent'], 2),
        'loot_percent' => round($totals['cunning'] * 1.0, 2),
        'upgrade_percent' => round(min(95.0, ($totals['science'] * 1.5) + $affinity['upgrade_percent']), 2),
        'stat_totals' => $totals,
        'affinity' => $affinity,
    ];
}

/**
 * Mission duration after the Engineer bonus and any affinity adjustment, floored
 * so an operation can never become instant however deep a crew's experience
 * runs.
 */
function pw_missions_effective_duration(int $baseSeconds, array $effects): int {
    $penalty = 1 + (($effects['duration_penalty_percent'] ?? 0) / 100);
    $seconds = (int)round($baseSeconds * (1 - ($effects['duration_percent'] / 100)) * $penalty);
    /* The ceiling rises with the penalty. Left at the base duration it would
     * have silently swallowed the mismatch charge, since a penalised run is
     * meant to take longer than the operation's listed time. With no penalty
     * this is the base duration exactly, as it always was. */
    return max(30, min((int)round($baseSeconds * $penalty), $seconds));
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

/* ------------------------------------------------------------------------
 * Loot tables.
 *
 * A loot table is a reusable named group of possible rewards. Two independent
 * chances decide an award: the mission -> table link says how often the table
 * is opened at all, and each entry inside says how often it drops. Entries are
 * therefore independent rolls rather than a weighted pick -- one successful
 * mission can award several characters, or none.
 *
 * Only characters are supported as loot for now. entry_type carries 'crew' on
 * every row so items or credits can join later without a structural change.
 * ---------------------------------------------------------------------- */

function pw_mission_loot_tables_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!pw_missions_ready($db)) return $ready = false;
    try {
        $db->query('SELECT id FROM `game_loot_tables` LIMIT 1');
        $db->query('SELECT id FROM `game_loot_table_entries` LIMIT 1');
        $db->query('SELECT id FROM `game_mission_loot_tables` LIMIT 1');
        return $ready = true;
    } catch (Throwable $e) {
        return $ready = false;
    }
}

function pw_missions_require_loot_tables_ready(PDO $db): void {
    if (!pw_mission_loot_tables_ready($db)) {
        pw_error('Loot tables are being prepared. Please try again after the Mission Loot Tables migration has been run.', 503);
    }
}

/**
 * Roll every loot table attached to one mission and grant what drops.
 *
 * Called only on a successful claim, inside the claim transaction. A character
 * the player already owns is skipped rather than granted again -- crew is a
 * roster, not a stack -- and is reported separately so the debrief can say the
 * roll hit but changed nothing, instead of silently showing no reward.
 *
 * @return array{granted: array, duplicates: array}
 */
function pw_missions_roll_loot_tables(PDO $db, int $userId, int $missionDefinitionId): array {
    $result = ['granted' => [], 'duplicates' => []];
    if (!pw_mission_loot_tables_ready($db)) return $result;

    $linkStmt = $db->prepare(
        'SELECT link.loot_table_id, link.chance_percent
         FROM game_mission_loot_tables link
         JOIN game_loot_tables lt ON lt.id = link.loot_table_id
         WHERE link.mission_definition_id = ? AND lt.is_enabled = 1
         ORDER BY link.sort_order ASC, link.id ASC'
    );
    $linkStmt->execute([$missionDefinitionId]);
    $links = $linkStmt->fetchAll();
    if (!$links) return $result;

    $entryStmt = $db->prepare(
        'SELECT entry.crew_definition_id, entry.chance_percent, crew.name, crew.role, crew.portrait_url
         FROM game_loot_table_entries entry
         JOIN game_crew_definitions crew ON crew.id = entry.crew_definition_id
         WHERE entry.loot_table_id = ? AND entry.entry_type = "crew" AND crew.is_enabled = 1
         ORDER BY entry.sort_order ASC, entry.id ASC'
    );
    // A player's existing roster, read once: the duplicate check runs against
    // every entry of every table and must not become a query per roll.
    $ownedStmt = $db->prepare('SELECT crew_definition_id FROM game_player_crew WHERE user_id = ?');
    $ownedStmt->execute([$userId]);
    $owned = [];
    foreach ($ownedStmt->fetchAll() as $row) $owned[(int)$row['crew_definition_id']] = true;

    $grantStmt = $db->prepare(
        'INSERT IGNORE INTO game_player_crew (user_id, crew_definition_id, level, xp, status)
         SELECT ?, c.id, c.starting_level, 0, "available" FROM game_crew_definitions c WHERE c.id = ?'
    );

    foreach ($links as $link) {
        if (!pw_missions_percent_roll((float)$link['chance_percent'])) continue;
        $entryStmt->execute([(int)$link['loot_table_id']]);
        foreach ($entryStmt->fetchAll() as $entry) {
            if (!pw_missions_percent_roll((float)$entry['chance_percent'])) continue;
            $crewId = (int)$entry['crew_definition_id'];
            $award = ['id' => $crewId, 'name' => $entry['name'], 'role' => $entry['role'], 'portrait_url' => $entry['portrait_url']];
            if (isset($owned[$crewId])) { $result['duplicates'][] = $award; continue; }
            $grantStmt->execute([$userId, $crewId]);
            // INSERT IGNORE is the real guard against a concurrent claim
            // granting the same character twice; the in-memory set above only
            // saves the query.
            if ($grantStmt->rowCount() < 1) { $result['duplicates'][] = $award; continue; }
            $owned[$crewId] = true;
            $result['granted'][] = $award;
        }
    }
    return $result;
}

/* ------------------------------------------------------------------------
 * Daily objectives.
 *
 * One objective per player per UTC day, chosen deterministically so a refresh
 * cannot reroll it. Progress is counted forward as missions resolve rather than
 * derived on read: a crew level-up leaves no record anywhere (levels are
 * recomputed from total XP), so there would be nothing to count after the fact.
 * ---------------------------------------------------------------------- */

function pw_mission_dailies_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!pw_missions_ready($db)) return $ready = false;
    try {
        $db->query('SELECT user_id FROM `game_player_daily_progress` LIMIT 1');
        $db->query('SELECT user_id FROM `game_player_daily_claims` LIMIT 1');
        return $ready = true;
    } catch (Throwable $e) {
        return $ready = false;
    }
}

/** Any duration at or above this counts as a long operation. */
const PW_MISSION_LONG_SECONDS = 1800;

/**
 * The fixed catalogue. Order is load-bearing: the daily is picked by index, so
 * inserting an objective in the middle would change which one a player is
 * shown mid-day. Append only.
 */
function pw_missions_daily_catalogue(): array {
    return [
        [
            'key' => 'five_missions',
            'label' => 'Complete 5 missions',
            'detail' => 'Any operation counts, and a failed run still counts as run.',
            'metric' => 'missions_completed',
            'target' => 5,
            'reward_type' => 'reputation',
            'reward_amount' => 50,
        ],
        [
            'key' => 'two_level_ups',
            'label' => 'Level up a crew member twice',
            'detail' => 'Two levels in total, across any of your crew.',
            'metric' => 'crew_level_ups',
            'target' => 2,
            'reward_type' => 'credits',
            'reward_amount' => 100,
        ],
        [
            'key' => 'long_mission',
            'label' => 'Complete one 30-minute mission',
            'detail' => 'Measured by the operation\'s listed duration, so an Engineer shortening the clock does not disqualify it.',
            'metric' => 'long_missions',
            'target' => 1,
            'reward_type' => 'credits',
            'reward_amount' => 50,
        ],
    ];
}

/** Today in UTC, the boundary every daily in this project already uses. */
function pw_missions_daily_date(PDO $db): string {
    return pw_missions_utc_now($db)->format('Y-m-d');
}

/**
 * Which objective a player sees on a given day.
 *
 * Seeded from the player and the date together, the same technique the weather
 * forecast and the Overlord decree-of-the-day already use: stable for the whole
 * UTC day, different per player, and impossible to reroll by refreshing.
 */
function pw_missions_daily_for_user(int $userId, string $date): array {
    $catalogue = pw_missions_daily_catalogue();
    return $catalogue[crc32($userId . '|' . $date) % count($catalogue)];
}

/**
 * Add to a daily counter. Safe to call before the migration has run -- the
 * whole objective feature is decorative until then, and a mission claim must
 * never fail over it.
 */
function pw_missions_record_daily_progress(PDO $db, int $userId, string $metric, int $amount): void {
    if ($amount < 1 || !pw_mission_dailies_ready($db)) return;
    try {
        $stmt = $db->prepare(
            'INSERT INTO game_player_daily_progress (user_id, stat_date, metric_key, progress)
             VALUES (?, UTC_DATE(), ?, ?)
             ON DUPLICATE KEY UPDATE progress = progress + VALUES(progress)'
        );
        $stmt->execute([$userId, $metric, $amount]);
    } catch (Throwable $e) {
        // Counting is not worth losing a mission reward over.
    }
}

/**
 * Today's objective with the player's progress against it, or null when the
 * migration has not been run.
 */
function pw_missions_daily_state(PDO $db, int $userId): ?array {
    if (!pw_mission_dailies_ready($db)) return null;
    $now = pw_missions_utc_now($db);
    $date = $now->format('Y-m-d');
    $daily = pw_missions_daily_for_user($userId, $date);

    $progressStmt = $db->prepare('SELECT progress FROM game_player_daily_progress WHERE user_id = ? AND stat_date = ? AND metric_key = ?');
    $progressStmt->execute([$userId, $date, $daily['metric']]);
    $progress = (int)($progressStmt->fetchColumn() ?: 0);

    $claimStmt = $db->prepare('SELECT claimed_at FROM game_player_daily_claims WHERE user_id = ? AND stat_date = ? AND daily_key = ?');
    $claimStmt->execute([$userId, $date, $daily['key']]);
    $claimedAt = $claimStmt->fetchColumn();

    return [
        'key' => $daily['key'],
        'label' => $daily['label'],
        'detail' => $daily['detail'],
        'target' => $daily['target'],
        'progress' => min($progress, $daily['target']),
        'raw_progress' => $progress,
        'reward_type' => $daily['reward_type'],
        'reward_amount' => $daily['reward_amount'],
        'is_complete' => $progress >= $daily['target'],
        'claimed' => $claimedAt !== false && $claimedAt !== null,
        'claimed_at' => $claimedAt !== false ? $claimedAt : null,
        // Next UTC midnight, so the card can count down to its own reset.
        'resets_at' => $now->modify('tomorrow midnight')->format('Y-m-d H:i:s'),
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
