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
    return $ready = pw_schema_has($db, 'game_crew_definitions')
        && pw_schema_has($db, 'game_player_crew')
        && pw_schema_has($db, 'game_mission_definitions')
        && pw_schema_has($db, 'game_player_missions')
        && pw_schema_has($db, 'game_player_mission_crew');
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
    return $ready = pw_schema_has($db, 'game_mission_definitions', ['unlocks_after_mission_id', 'unlocks_after_completion_count']);
}

function pw_missions_require_successions_ready(PDO $db): void {
    if (!pw_mission_successions_ready($db)) {
        pw_error('Mission progressions are being prepared. Please try again after the Mission Successions migration has been run.', 503);
    }
}

/**
 * Research locks are an additive Mission Management migration. Keep the probe
 * separate so older installations retain the original Secret mission access
 * behaviour while the new explicit checkbox is unavailable.
 */
function pw_mission_research_locks_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!pw_missions_ready($db)) return $ready = false;
    return $ready = pw_schema_has($db, 'game_mission_definitions', ['requires_research_unlock']);
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
    return $ready = pw_schema_has($db, 'game_mission_definitions', ['is_campaign_final']);
}

/* ---------------------------------------------------------------------------
 * Daily Overlord contracts
 *
 * The quiz has always written users.overlord_affinity and nothing in the game
 * has ever read it -- a player declares an allegiance and it changes only how
 * their profile looks. A contract is the reward for that allegiance: one
 * administrator-authored operation a day, drawn from the pool belonging to the
 * player's own Overlord.
 *
 * Selection is deterministic per player per UTC day, seeded the same way the
 * Overlord decree rotation and the weather forecast are seeded, so refreshing
 * the page cannot reroll it and two players with the same patron do not
 * necessarily get the same contract.
 * ------------------------------------------------------------------------- */

/** Reputation rank at which contracts begin. */
const PW_MISSION_OVERLORD_CONTRACT_RANK = 10;

function pw_mission_overlord_contracts_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!pw_missions_ready($db)) return $ready = false;
    return $ready = pw_schema_has($db, 'game_mission_definitions', ['overlord_id']);
}

/**
 * The Overlord a player is aligned with, or null.
 *
 * users.overlord_affinity stores the Overlord's NAME, not a slug or an id --
 * that is how api/save-quiz-result.php has always written it, and
 * api/quiz/quiz-helpers.php resolves it the same way for its rarity figures.
 * Matched case-insensitively against the roster so an editorial capitalisation
 * change in Overlord Control cannot silently orphan every aligned player.
 */
function pw_missions_overlord_affinity(PDO $db, ?string $affinityName): ?array {
    $name = trim((string)$affinityName);
    if ($name === '') return null;
    try {
        $stmt = $db->prepare(
            'SELECT id, slug, name, epithet, accent_color, accent_glow, portrait_image_url
             FROM overlords WHERE LOWER(name) = LOWER(?) LIMIT 1'
        );
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['id'] = (int)$row['id'];
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Today's contract for one player, and the reason there is not one.
 *
 * Always returns a state rather than null, so the card can explain itself:
 * a locked rank, a missing quiz result and an Overlord with no authored
 * contracts are three different situations and all three used to look
 * identical from the browser (an absent card).
 */
function pw_missions_daily_overlord_contract(PDO $db, int $userId, int $rank, ?array $overlord): array {
    $state = [
        'ready' => pw_mission_overlord_contracts_ready($db),
        'rank_required' => PW_MISSION_OVERLORD_CONTRACT_RANK,
        'rank' => $rank,
        'unlocked' => $rank >= PW_MISSION_OVERLORD_CONTRACT_RANK,
        'overlord' => null,
        'contract' => null,
        'claimed_today' => false,
        'in_flight' => false,
        'reason' => '',
    ];
    if (!$state['ready']) { $state['reason'] = 'pending_migration'; return $state; }
    if (!$state['unlocked']) { $state['reason'] = 'rank'; return $state; }
    if ($overlord === null) { $state['reason'] = 'no_affinity'; return $state; }

    $state['overlord'] = [
        'id' => (int)$overlord['id'],
        'slug' => (string)$overlord['slug'],
        'name' => (string)$overlord['name'],
        'epithet' => (string)$overlord['epithet'],
        'accent_color' => (string)$overlord['accent_color'],
        'accent_glow' => (string)$overlord['accent_glow'],
    ];

    try {
        $pool = $db->prepare(
            'SELECT * FROM game_mission_definitions
             WHERE overlord_id = ? AND is_enabled = 1
             ORDER BY sort_order ASC, id ASC'
        );
        $pool->execute([(int)$overlord['id']]);
        $contracts = $pool->fetchAll();
    } catch (Throwable $e) {
        $state['reason'] = 'pending_migration';
        return $state;
    }
    if (!$contracts) { $state['reason'] = 'none_authored'; return $state; }

    /* Seeded from the player, the UTC date and the Overlord. Including the
     * player id means two commanders serving the same patron are not handed the
     * same operation every day; including the date is what makes it stable
     * across a refresh and new at midnight UTC. */
    $today = gmdate('Y-m-d');
    $seed = crc32($userId . ':' . $today . ':' . (string)$overlord['slug']);
    $contract = $contracts[$seed % count($contracts)];

    /* Once a day means once a day. A run claimed today closes the contract
     * until the next UTC date rather than letting it be repeated, which is the
     * whole difference between a contract and an ordinary mission. Counted by
     * claimed_at rather than by started_at so a run launched yesterday and
     * claimed today closes today's, not yesterday's. */
    try {
        $claimed = $db->prepare(
            'SELECT COUNT(*) FROM game_player_missions
             WHERE user_id = ? AND mission_definition_id = ? AND status = "claimed"
               AND claimed_at >= ? AND claimed_at < DATE_ADD(?, INTERVAL 1 DAY)'
        );
        $claimed->execute([$userId, (int)$contract['id'], $today . ' 00:00:00', $today . ' 00:00:00']);
        $state['claimed_today'] = (int)$claimed->fetchColumn() > 0;
    } catch (Throwable $e) {
        // A failed check must not hand out a second contract for the day.
        $state['claimed_today'] = true;
    }

    /* One at a time. The claimed count above closes the contract for the day
     * only once a run has been *claimed* -- so a run that is still active, or
     * finished but not yet collected, left the contract open and a player could
     * have several of the same operation running at once. That is not a daily
     * contract, it is an ordinary mission with a limit on collections.
     *
     * Deliberately any contract rather than only today's: a run launched
     * yesterday and never claimed is still a contract in progress, and issuing
     * a second one on top of it would be the same double-run through a slower
     * route. Ordinary missions are untouched -- they may still be stacked.
     *
     * 'completed' counts as in flight because it is an unclaimed run: the
     * rewards are still owed and claim.php will settle it. */
    try {
        $running = $db->prepare(
            'SELECT run.mission_definition_id
             FROM game_player_missions run
             JOIN game_mission_definitions definition ON definition.id = run.mission_definition_id
             WHERE run.user_id = ? AND run.status IN ("active", "completed")
               AND definition.overlord_id IS NOT NULL
             LIMIT 1'
        );
        $running->execute([$userId]);
        $state['in_flight'] = $running->fetch() !== false;
    } catch (Throwable $e) {
        // Same choice as the claimed check above: a failed lookup must not be
        // the thing that hands out a second concurrent contract.
        $state['in_flight'] = true;
    }

    $state['contract'] = $contract;
    $state['reason'] = $state['claimed_today'] ? 'claimed_today' : ($state['in_flight'] ? 'in_flight' : '');
    return $state;
}

/**
 * The gate start.php applies to a contract. Returns an error string, or null
 * when the launch is allowed.
 *
 * Re-derived here rather than trusting anything the browser sends: the daily
 * selection is a pure function of the player, the date and the pool, so the
 * server can recompute exactly which contract is today's and refuse any other.
 * Without this a player could read a contract id out of the network tab on any
 * day it happened to be offered and launch it every day afterwards.
 */
function pw_missions_overlord_contract_block(PDO $db, int $userId, array $mission, int $rank, ?array $overlord): ?string {
    if (!pw_mission_overlord_contracts_ready($db)) return null;
    $overlordId = isset($mission['overlord_id']) && $mission['overlord_id'] !== null ? (int)$mission['overlord_id'] : 0;
    if ($overlordId < 1) return null;
    if ($rank < PW_MISSION_OVERLORD_CONTRACT_RANK) {
        return 'Overlord contracts open at reputation rank ' . PW_MISSION_OVERLORD_CONTRACT_RANK . '.';
    }
    if ($overlord === null) {
        return 'Take the Overlord Affinity quiz before accepting a contract.';
    }
    if ((int)$overlord['id'] !== $overlordId) {
        return 'This contract belongs to another Overlord.';
    }
    $state = pw_missions_daily_overlord_contract($db, $userId, $rank, $overlord);
    if ($state['claimed_today']) {
        return 'You have already run today\'s contract. A new one is issued at 00:00 UTC.';
    }
    /* The launch-time half of the one-at-a-time rule. start.php is a separate
     * entry point from the card, so this is re-checked here rather than trusted
     * to a hidden button -- the same reason the daily selection is recomputed
     * above instead of taking the id the browser sent. */
    if ($state['in_flight']) {
        return 'A contract is already under way. Collect it before accepting another.';
    }
    if (!$state['contract'] || (int)$state['contract']['id'] !== (int)$mission['id']) {
        return 'That contract is not the one issued to you today.';
    }
    return null;
}

/**
 * Crew fatigue is an additive migration like every other optional mission
 * feature: a missing column is a hard SQL error rather than NULL, so every read
 * and write path probes first and falls back to the pre-fatigue behaviour.
 * Deploy order is therefore not load-bearing.
 */
function pw_mission_fatigue_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!pw_missions_ready($db)) return $ready = false;
    return $ready = pw_schema_has($db, 'game_player_crew', ['fatigue', 'fatigue_updated_at']);
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
    return $ready = pw_schema_has($db, 'game_player_crew', ['strength', 'cunning', 'science', 'charisma'])
        && pw_schema_has($db, 'game_mission_definitions', ['base_success_percent', 'loot_rolls'])
        && pw_schema_has($db, 'game_loot_definitions', ['id']);
}

/** Whether the per-player crew favourites migration has been applied. */
function pw_mission_crew_favorites_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!pw_missions_ready($db)) return $ready = false;
    return $ready = pw_schema_has($db, 'game_player_crew', ['is_favorite']);
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
    return $ready = pw_schema_has($db, 'game_mission_definitions', ['watermark_url', 'watermark_opacity']);
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
    return $ready = pw_schema_has($db, 'game_mission_definitions', ['credit_reward'])
        && pw_schema_has($db, 'game_player_wallet', ['user_id', 'credits']);
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
/* Levelling alone stops at PW_MISSION_MAX_STAT; equipment carries a crew member
 * past it, to here. Without the higher ceiling gear would be worthless on the
 * crew who have earned the most -- a level-50 specialist is already at 50 in
 * their primary stat, so every bonus would land on a clamp and vanish. This is
 * the only place the two ceilings differ, and pw_missions_stats_for_level()
 * still refuses to allocate past 50 from levels. */
const PW_MISSION_MAX_GEAR_STAT = 80;
/* Crew levelling is exponential: each level costs PW_MISSION_XP_GROWTH times
 * the one before it, starting from PW_MISSION_XP_BASE for level 1 -> 2. A flat
 * 100 per level made the last promotion cost exactly as much as the first, so a
 * veteran crew member kept advancing at recruit pace and the level ceiling
 * stopped meaning anything. At 8% the early game is almost unchanged (level 10
 * costs 1,249 XP against the old 900) while reaching level 50 costs 53,039
 * rather than 4,900. These two numbers are the only dials -- every threshold,
 * progress bar and level lookup is derived from them. */
const PW_MISSION_XP_BASE = 100;
const PW_MISSION_XP_GROWTH = 1.08;

/* ---------------------------------------------------------------------------
 * Crew fatigue
 *
 * A spendable stamina pool rather than an accumulating debt: full at rest,
 * spent at launch, regenerated while a crew member is available. It exists so
 * roster breadth means something -- before it, the optimal play was to field
 * the same three best crew forever, and the 8 berths, the capacity research and
 * the whole keep-or-sell decision on a recruit offer had no pressure behind
 * them.
 *
 * A mission costs PW_MISSION_FATIGUE_PER_BLOCK for each whole
 * PW_MISSION_FATIGUE_BLOCK_SECONDS of its length, rounded down -- so an
 * operation under ten minutes is free and a fifteen-minute one costs the same
 * as a ten-minute one. The cost is read from the mission's authored
 * duration_seconds, NOT the effective duration a particular crew achieves:
 * charging the shortened time would make the cost move while the player was
 * still picking crew, and the figure shown on the mission card before any crew
 * is chosen has to be the figure charged.
 *
 * Regeneration is derived from those same two constants rather than declared
 * separately, so the two halves can never drift: a crew member rests for
 * exactly as long as the mission they just ran. That is what makes the maximum
 * matter -- the pool is the number of back-to-back operations a crew member can
 * absorb before the wait starts, and raising it is what turns a rested roster
 * into a deeper one.
 * ------------------------------------------------------------------------- */
const PW_MISSION_FATIGUE_BASE_MAX = 100;
const PW_MISSION_FATIGUE_PER_BLOCK = 10;
const PW_MISSION_FATIGUE_BLOCK_SECONDS = 600;
/* Every this many reputation ranks raises every crew member's ceiling by
 * PW_MISSION_FATIGUE_REPUTATION_BONUS. Ranks are the ladder position, so the
 * bonus arrives at ranks 5, 10, 15 and so on. */
const PW_MISSION_FATIGUE_REPUTATION_STEP = 5;
const PW_MISSION_FATIGUE_REPUTATION_BONUS = 10;
/* A ceiling on what research alone may add, in the same spirit as the caps
 * pw_research_player_effects() already applies to every other effect. */
const PW_MISSION_FATIGUE_RESEARCH_CAP = 200;

/**
 * Marks every run whose completion time has passed as completed, and tells the
 * player each one is waiting.
 *
 * A run used to reach "completed" only when the open browser tab's one-second
 * countdown posted to api/missions/complete.php, so closing the tab left a
 * finished operation sitting at "active" indefinitely -- the mechanic that
 * makes long missions interesting was the one the player could not be present
 * for. This is the settling path: api/cron/complete-missions.php sweeps every
 * player on a schedule, and api/missions/overview.php calls it for the current
 * player on load so the page is correct even if that cron is never scheduled.
 *
 * The status transition is the notification guard: the UPDATE names the old
 * status, so only the request that actually moved the row sends the message and
 * two concurrent sweeps cannot notify twice.
 *
 * @param int|null $userId One player, or null for every player (cron).
 * @return int Runs settled.
 */
function pw_missions_settle_due_runs(PDO $db, ?int $userId = null, int $limit = 500): int {
    if (!pw_missions_ready($db)) return 0;
    try {
        $stmt = $db->prepare(
            'SELECT pm.id, pm.user_id, md.name
             FROM game_player_missions pm
             JOIN game_mission_definitions md ON md.id = pm.mission_definition_id
             WHERE pm.status = "active" AND pm.completes_at <= UTC_TIMESTAMP()'
            . ($userId !== null ? ' AND pm.user_id = ?' : '') . '
             ORDER BY pm.completes_at ASC
             LIMIT ' . max(1, $limit)
        );
        $stmt->execute($userId !== null ? [$userId] : []);
        $due = $stmt->fetchAll();
    } catch (Throwable $e) {
        return 0;
    }
    if (!$due) return 0;

    $settle = $db->prepare('UPDATE game_player_missions SET status = "completed", completed_at = UTC_TIMESTAMP() WHERE id = ? AND status = "active"');
    $settled = 0;
    foreach ($due as $run) {
        try {
            $settle->execute([(int)$run['id']]);
            if ($settle->rowCount() !== 1) continue;
            $settled++;
            pw_missions_notify_run_ready((int)$run['user_id'], (string)$run['name']);
        } catch (Throwable $e) {
            // One unsettleable run must not abandon the rest of the sweep.
        }
    }
    return $settled;
}

/**
 * "Your operation has returned." Sent on the active -> completed transition
 * only, so it fires once per run whichever path settled it.
 */
function pw_missions_notify_run_ready(int $userId, string $missionName): void {
    try {
        // Positional: (userId, type, actorUserId, topicId, commentId, reportId,
        // excerpt). The mission name is the excerpt; there is no actor, because
        // nobody did this to the player -- a timer elapsed.
        pw_notify($userId, 'mission_ready', null, null, null, null, $missionName);
    } catch (Throwable $e) {
        // The notification type arrives with this feature's migration. Settling
        // a finished run must never depend on it having been run.
    }
}

/**
 * Fatigue restored per minute of rest. The base rate is derived, never declared
 * -- see above -- so a crew member rests for exactly as long as the mission they
 * just ran.
 *
 * A recovery bonus, from research or from a stim, shortens that wait. It is
 * expressed as a percentage of the base rate rather than as flat points so it
 * stays meaningful whichever way the two constants above are ever retuned.
 */
function pw_missions_fatigue_regen_per_minute(float $recoveryPercent = 0.0): float {
    $base = PW_MISSION_FATIGUE_PER_BLOCK / (PW_MISSION_FATIGUE_BLOCK_SECONDS / 60);
    return $base * (1 + max(0.0, $recoveryPercent) / 100);
}

/** Fatigue an operation of this authored length costs each crew member. */
function pw_missions_fatigue_cost(int $durationSeconds): int {
    $blocks = (int)floor(max(0, $durationSeconds) / PW_MISSION_FATIGUE_BLOCK_SECONDS);
    return $blocks * PW_MISSION_FATIGUE_PER_BLOCK;
}

/**
 * This player's fatigue ceiling, shared by every crew member they own.
 *
 * Research effects are passed in rather than looked up: research-helpers.php
 * requires this file, so calling back into it here would be circular. Every
 * caller that has the research layer loaded already computes the effects array
 * for other reasons and simply hands it over.
 */
function pw_missions_fatigue_max(PDO $db, int $userId, array $researchEffects = []): int {
    $max = PW_MISSION_FATIGUE_BASE_MAX;
    try {
        $stmt = $db->prepare('SELECT reputation FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $rank = (int)(pw_reputation_info(max(0, (int)$stmt->fetchColumn()))['level_number'] ?? 0);
        $max += (int)floor($rank / PW_MISSION_FATIGUE_REPUTATION_STEP) * PW_MISSION_FATIGUE_REPUTATION_BONUS;
    } catch (Throwable $e) {
        // Reputation is a separate rollout. Its absence costs the bonus, never
        // the base pool.
    }
    $research = (int)floor(max(0.0, (float)($researchEffects['crew_fatigue'] ?? 0)));
    return $max + min(PW_MISSION_FATIGUE_RESEARCH_CAP, $research);
}

/**
 * Current fatigue for one crew row, catching up the rest they have earned since
 * the value was last written.
 *
 * Rest only accrues while a crew member is available. Without that condition a
 * long operation would regenerate more than it cost -- a sixty-minute mission
 * charges 60 and would hand back 60 while the crew were still out on it, making
 * every long operation free and the whole mechanic decorative.
 */
function pw_missions_resolve_fatigue(array $crew, int $max, DateTimeImmutable $now, float $recoveryPercent = 0.0): int {
    $current = max(0, min($max, (int)($crew['fatigue'] ?? $max)));
    if ((string)($crew['status'] ?? '') !== 'available') return $current;
    $since = $crew['fatigue_updated_at'] ?? null;
    if ($since === null || $since === '') return $current;
    try {
        $from = new DateTimeImmutable((string)$since, new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        return $current;
    }
    $minutes = (int)floor(max(0, $now->getTimestamp() - $from->getTimestamp()) / 60);
    if ($minutes < 1) return $current;
    return (int)min($max, $current + (int)floor($minutes * pw_missions_fatigue_regen_per_minute($recoveryPercent)));
}

/** Seconds of rest before this crew member can afford a cost, 0 if they can. */
function pw_missions_fatigue_recovery_seconds(int $current, int $needed, float $recoveryPercent = 0.0): int {
    if ($current >= $needed) return 0;
    $rate = pw_missions_fatigue_regen_per_minute($recoveryPercent);
    if ($rate <= 0) return 0;
    return (int)ceil(($needed - $current) / $rate * 60);
}

/**
 * Stats a crew member has automatically allocated by this level: two points per
 * level into the role's primary stat and one into Cunning, each capped.
 *
 * The two caps meet at different levels on purpose. A primary stat reaches 50
 * at level 25 and plateaus, while Cunning arrives at exactly 50 at level 50, so
 * the second half of a career trades raw specialism for the role bonus and
 * steadily better loot.
 */
/**
 * What a crew member's rarity is worth. One table, read by everything that
 * cares, because these three consequences must always describe the same
 * ladder -- a rarity that pays more on a duplicate than it is worth in the
 * field would be a reason not to recruit it.
 *
 *   stat_multiplier   applied to the level-derived stats, rounded up
 *   role_bonus_add    added to the role's own per-level rate
 *   duplicate_credits paid when a loot table awards crew already on the roster
 *
 * 'epic' is interpolated between rare and legendary. It is a real authorable
 * rarity in Crew Management (five options, not four) and was not covered by
 * the specification these numbers came from; falling through to common would
 * have made an epic recruit quietly the weakest of the four paid tiers.
 *
 * Distinct from pw_missions_crew_sale_value(), which prices a recruit the
 * roster had no berth for. That is a different decision -- turning down
 * someone you could have kept -- and it keeps its own longer-standing numbers.
 */
function pw_missions_crew_tier_profile(): array {
    return [
        'common' => ['stat_multiplier' => 1.00, 'role_bonus_add' => 0.00, 'duplicate_credits' => 100],
        'uncommon' => ['stat_multiplier' => 1.25, 'role_bonus_add' => 0.05, 'duplicate_credits' => 200],
        'rare' => ['stat_multiplier' => 1.50, 'role_bonus_add' => 0.15, 'duplicate_credits' => 400],
        'epic' => ['stat_multiplier' => 1.75, 'role_bonus_add' => 0.20, 'duplicate_credits' => 700],
        'legendary' => ['stat_multiplier' => 2.00, 'role_bonus_add' => 0.25, 'duplicate_credits' => 1000],
    ];
}

/** One rarity's profile, falling back to common for an unrecognised value. */
function pw_missions_crew_tier(?string $tier): array {
    $profile = pw_missions_crew_tier_profile();
    return $profile[strtolower(trim((string)$tier))] ?? $profile['common'];
}

/** Credits paid instead of a crew member the roster already holds. */
function pw_missions_crew_duplicate_credits(?string $tier): int {
    return (int)pw_missions_crew_tier($tier)['duplicate_credits'];
}

/**
 * A role's per-level rate for one crew member, rarity included.
 *
 * Read this rather than pw_missions_role_rates() wherever a specific crew
 * member is in hand: the base table describes the role, and a rarer recruit of
 * that role earns more per level. Returns the same shape as one row of the
 * base table, so every consumer keeps reading one rate per effect.
 */
function pw_missions_role_rate_for(string $role, ?string $tier): array {
    $rates = pw_missions_role_rates()[$role] ?? [];
    if (!$rates) return [];
    $add = (float)pw_missions_crew_tier($tier)['role_bonus_add'];
    if ($add <= 0) return $rates;
    foreach ($rates as $key => $value) $rates[$key] = $value + $add;
    return $rates;
}

function pw_missions_stat_plan(string $role): array {
    $primary = [
        'Vanguard' => 'strength',
        'Pathfinder' => 'charisma',
        'Engineer' => 'science',
        /* Cunning is also the stat every other role gains at half rate, so the
         * Fixer is the specialist in the one thing everyone else dabbles in --
         * the right shape for a role whose bonus is paid in credits. Note the
         * primary allocation overwrites the shared Cunning line rather than
         * adding to it, so a Fixer gains 2 per level like any other primary,
         * not 3. */
        'Fixer' => 'cunning',
    ][$role] ?? null;
    return ['primary' => $primary, 'primary_per_level' => 2, 'cunning_per_level' => 1];
}

function pw_missions_stats_for_level(string $role, int $level, ?string $tier = 'common'): array {
    $level = max(0, min(PW_MISSION_MAX_LEVEL, $level));
    $plan = pw_missions_stat_plan($role);
    /* Rarity multiplies what a level is worth, rounded up. Applied to the
     * whole total rather than per level so the result is a pure function of
     * the level reached -- rounding each level separately would make the same
     * crew member's stats depend on how many claims it took to get there. */
    $multiplier = (float)pw_missions_crew_tier($tier)['stat_multiplier'];
    $scale = static function (int $base) use ($multiplier) {
        return (int)min(PW_MISSION_MAX_STAT, (int)ceil($base * $multiplier));
    };
    $stats = ['strength' => 0, 'cunning' => 0, 'science' => 0, 'charisma' => 0];
    $stats['cunning'] = $scale($level * $plan['cunning_per_level']);
    if ($plan['primary'] !== null) {
        $stats[$plan['primary']] = $scale($level * $plan['primary_per_level']);
    }
    return $stats;
}

/**
 * Levels are the authoritative source for automatic crew stats. The database
 * columns are a cache maintained at level-up so they can support future
 * per-character modifiers, but a character recruited after the original
 * backfill could otherwise have a real level and a zeroed cache. Rebuilding
 * these four values at every mission boundary keeps display and resolution
 * correct while the next successful claim repairs the stored row too.
 */
function pw_missions_apply_level_stats(array $crewRows): array {
    foreach ($crewRows as $index => $row) {
        // 'tier' is absent before the crew-capacity migration and on any row
        // whose query does not select it; pw_missions_crew_tier() reads that
        // as common, which is the pre-rarity behaviour exactly.
        $stats = pw_missions_stats_for_level((string)($row['role'] ?? ''), (int)($row['level'] ?? 0), $row['tier'] ?? 'common');
        foreach ($stats as $stat => $value) $crewRows[$index][$stat] = $value;
    }
    return $crewRows;
}

/**
 * Cumulative XP required to reach each level, level 1 costing nothing. The
 * table is built once per request by stepping the growth rate and rounding each
 * individual step, rather than evaluating a closed-form power per lookup: a
 * rounded running total is exact and identical everywhere it is read, while
 * rounding a float sum could put a crew member one XP short of the threshold
 * their own progress bar just showed them cross.
 */
function pw_missions_xp_curve(): array {
    static $curve = null;
    if ($curve !== null) return $curve;
    $curve = [1 => 0];
    $step = (float)PW_MISSION_XP_BASE;
    $total = 0;
    for ($level = 2; $level <= PW_MISSION_MAX_LEVEL; $level++) {
        $total += (int)round($step);
        $curve[$level] = $total;
        $step *= PW_MISSION_XP_GROWTH;
    }
    return $curve;
}

function pw_missions_xp_for_level(int $level): int {
    return pw_missions_xp_curve()[max(1, min(PW_MISSION_MAX_LEVEL, $level))];
}

/**
 * Level implied by total XP against the exponential curve above. A crew
 * template may start above level 1, and levelling must never demote it, so the
 * definition's starting level acts as a floor.
 */
function pw_missions_level_for_xp(int $xp, int $startingLevel = 1): int {
    $xp = max(0, $xp);
    $earned = 1;
    foreach (pw_missions_xp_curve() as $level => $required) {
        if ($xp < $required) break;
        $earned = $level;
    }
    return max(1, min(PW_MISSION_MAX_LEVEL, max($earned, $startingLevel)));
}

/**
 * Progress through the current level, resolved server-side so the crew card
 * never has to know the curve. Two edge cases are handled here rather than in
 * the browser: at the ceiling the bar reads full because further XP buys
 * nothing, and a crew member whose starting level floors them above what their
 * XP has actually earned reads as zero into the level rather than negative.
 */
function pw_missions_xp_progress(int $xp, int $level): array {
    $level = max(1, min(PW_MISSION_MAX_LEVEL, $level));
    if ($level >= PW_MISSION_MAX_LEVEL) {
        return ['xp_into_level' => 0, 'xp_for_next_level' => 0, 'xp_percent' => 100];
    }
    $floor = pw_missions_xp_for_level($level);
    $span = max(1, pw_missions_xp_for_level($level + 1) - $floor);
    $into = max(0, min($span, max(0, $xp) - $floor));
    return [
        'xp_into_level' => $into,
        'xp_for_next_level' => $span,
        'xp_percent' => (int)round($into / $span * 100),
    ];
}

/**
 * Per-level role bonus one crew member contributes to a mission.
 * Engineer   0.25% shorter mission per level
 * Pathfinder 0.25% more XP for the whole crew per level
 * Vanguard   0.10 flat reputation per level
 * Fixer      0.50% more credits per level
 * These stack across every crew member assigned, so three level-2 Engineers
 * contribute 3 x (2 x 0.25%) = 1.50%.
 *
 * This array is the only place these figures are written. The reader-facing
 * descriptions on the crew card, the launch projection and Game Tuning's stat
 * reference are all generated from it -- api/missions/overview.php ships it to
 * the browser for exactly that reason, because a second copy in JavaScript is
 * what drifts the moment a rate is retuned.
 *
 * Adding a role here is most of what adding a role takes: the effect
 * accumulator in pw_missions_crew_effects() reads the key, the admin crew
 * editor validates against array_keys() of this, and the stat plan above
 * decides which stat the role invests in.
 */
function pw_missions_role_rates(): array {
    return [
        'Engineer' => ['duration_percent_per_level' => 0.25],
        'Pathfinder' => ['xp_percent_per_level' => 0.25],
        'Vanguard' => ['reputation_per_level' => 0.10],
        'Fixer' => ['credit_percent_per_level' => 0.50],
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
 * The Fixer is deliberately absent from every row. Its bonus is credits on any
 * operation, which is the trade: no type rewards fielding one in particular,
 * and a team of nothing but Fixers takes the mismatch penalty on all three --
 * the same rule a team of nothing but Vanguards already meets on a survey run.
 *
 * A matching member's bonus stacks, so two Vanguards on a recon run earn 10%
 * more credits.
 *
 * Assigning nobody from either preferred role is charged once -- not once per
 * mismatched member. A two-crew operation with a single wrong role would
 * otherwise be charged twice, and +40% duration on a compounding penalty makes
 * the mission not worth running rather than merely worse.
 * ---------------------------------------------------------------------- */

/* What one point of each stat is worth. These were the only tuning figures in
 * this file still written as literals inside pw_missions_crew_effects(), which
 * meant anything describing them to a reader -- the crew card, the launch
 * projection, Game Tuning's stat reference -- had to restate them and could
 * drift. Named here so every description is generated from the same number the
 * engine multiplies by. */
const PW_MISSION_STRENGTH_SUCCESS_PER_POINT = 0.5;
const PW_MISSION_CUNNING_LOOT_PER_POINT = 1.0;
const PW_MISSION_SCIENCE_UPGRADE_PER_POINT = 1.5;
const PW_MISSION_CHARISMA_XP_PER_POINT = 0.5;

const PW_MISSION_AFFINITY_PERCENT = 5.0;
const PW_MISSION_AFFINITY_PENALTY_DURATION = 20.0;
const PW_MISSION_AFFINITY_PENALTY_SUCCESS = 5.0;

/**
 * A plain-language reference for every figure that turns a crew member into a
 * mission outcome: the four stats, the three roles, and what a mismatch costs.
 *
 * Generated from the constants and rate tables above rather than written out,
 * so it cannot describe a rate the engine no longer uses. Anything that wants
 * to explain the system to a reader should read this rather than restate it.
 */
function pw_missions_stat_reference(): array {
    $roleRates = pw_missions_role_rates();
    $affinity = [];
    foreach (pw_missions_affinity_matrix() as $type => $roles) {
        $affinity[$type] = array_keys($roles);
    }
    return [
        'stats' => [
            [
                'key' => 'strength', 'label' => 'Strength', 'short' => 'STR',
                'per_point' => PW_MISSION_STRENGTH_SUCCESS_PER_POINT, 'unit' => '%',
                'affects' => 'Success chance',
                'detail' => 'Raises the chance the operation succeeds at all, added to its own base chance. Shared across the whole assigned crew, so it is the total that counts rather than any one member.',
            ],
            [
                'key' => 'cunning', 'label' => 'Cunning', 'short' => 'CUN',
                'per_point' => PW_MISSION_CUNNING_LOOT_PER_POINT, 'unit' => '%',
                'affects' => 'Loot draws',
                'detail' => 'Buys extra draws from the loot pool. Every whole 100% is one guaranteed additional item and the remainder is the chance of one more, capped at 12 draws for a single operation.',
            ],
            [
                'key' => 'science', 'label' => 'Science', 'short' => 'SCI',
                'per_point' => PW_MISSION_SCIENCE_UPGRADE_PER_POINT, 'unit' => '%',
                'affects' => 'Rarity promotion',
                'detail' => 'A separate roll on each item recovered for it to be promoted one rarity tier. Capped at 95%, and a storm takes its toll after that cap.',
            ],
            [
                'key' => 'charisma', 'label' => 'Charisma', 'short' => 'CHA',
                'per_point' => PW_MISSION_CHARISMA_XP_PER_POINT, 'unit' => '%',
                'affects' => 'Crew experience',
                'detail' => 'Adds to the experience the whole crew earns, stacking with the Pathfinder role bonus into one figure.',
            ],
        ],
        'roles' => [
            [
                'role' => 'Vanguard', 'stat' => 'strength',
                'per_level' => $roleRates['Vanguard']['reputation_per_level'] ?? 0,
                'unit' => ' reputation', 'affects' => 'Reputation',
                'detail' => 'Adds flat reputation to a successful operation, per level, floored to a whole number once the crew is summed.',
            ],
            [
                'role' => 'Pathfinder', 'stat' => 'charisma',
                'per_level' => $roleRates['Pathfinder']['xp_percent_per_level'] ?? 0,
                'unit' => '% XP', 'affects' => 'Crew experience',
                'detail' => 'Raises the experience the whole crew earns, per level, on top of their own Charisma.',
            ],
            [
                'role' => 'Engineer', 'stat' => 'science',
                'per_level' => $roleRates['Engineer']['duration_percent_per_level'] ?? 0,
                'unit' => '% faster', 'affects' => 'Duration',
                'detail' => 'Shortens every operation they join, per level. Locked in at launch, so a crew member who levels mid-mission does not shorten a run already under way.',
            ],
            [
                'role' => 'Fixer', 'stat' => 'cunning',
                'per_level' => $roleRates['Fixer']['credit_percent_per_level'] ?? 0,
                'unit' => '% credits', 'affects' => 'Credits',
                'detail' => 'Raises the credits a successful operation pays, per level. The only role with no operation-type affinity, so a Fixer earns the same on every kind of work rather than more on two kinds and nothing on the third.',
            ],
        ],
        'caps' => [
            'max_level' => PW_MISSION_MAX_LEVEL,
            'max_stat_from_levels' => PW_MISSION_MAX_STAT,
            'max_stat_with_gear' => PW_MISSION_MAX_GEAR_STAT,
            'primary_per_level' => 2,
            'cunning_per_level' => 1,
        ],
        'affinity' => [
            'percent' => PW_MISSION_AFFINITY_PERCENT,
            'penalty_duration_percent' => PW_MISSION_AFFINITY_PENALTY_DURATION,
            'penalty_success_percent' => PW_MISSION_AFFINITY_PENALTY_SUCCESS,
            'preferred_by_type' => $affinity,
            'detail' => 'Each crew member of a preferred role adds the bonus again, so two of the same role is a real choice. The penalty applies only when the team carries neither preferred role, and is charged once however many mismatched crew are assigned.',
        ],
    ];
}

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

/* ------------------------------------------------------------------------
 * Crew gear.
 *
 * Equipment is a loot definition that happens to carry a slot, so it drops
 * through the loot pipeline that already exists and is held in the inventory
 * that already exists. Its bonuses are the four crew stats and nothing else,
 * which means every effect downstream -- success, loot draws, tier promotion,
 * XP, and through them affinity and weather -- picks gear up without a single
 * new term anywhere in the effects engine.
 * ---------------------------------------------------------------------- */

/**
 * The seven slots, in the order the loadout draws them. A code constant rather
 * than a table: which slots exist is an authored game rule, not content an
 * administrator should be able to invent halfway through a season.
 */
function pw_missions_gear_slots(): array {
    return [
        'head' => 'Head',
        'chest' => 'Chest',
        'main_hand' => 'Main hand',
        'off_hand' => 'Off hand',
        'legs' => 'Legs',
        'feet' => 'Feet',
        'utility' => 'Utility',
    ];
}

function pw_missions_gear_stat_keys(): array {
    return ['strength', 'cunning', 'science', 'charisma'];
}

/**
 * Whether sql/migration_mission_gear.sql has been run. Until it has, the page
 * behaves exactly as it did before gear existed: no loadouts, no bonuses, and
 * every mission resolved from level-derived stats alone.
 */
function pw_mission_gear_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!pw_mission_stats_ready($db)) return $ready = false;
    return $ready = pw_schema_has($db, 'game_loot_definitions', ['slot', 'bonus_strength', 'bonus_cunning', 'bonus_science', 'bonus_charisma', 'required_level', 'required_role', 'icon_url'])
        && pw_schema_has($db, 'game_player_crew_gear', ['player_crew_id', 'slot', 'loot_definition_id']);
}

/**
 * What a player's crew are wearing, keyed by player_crew_id then slot.
 *
 * One query for the whole roster rather than one per crew member -- the same
 * N+1 avoidance the public worlds endpoint follows. Scoped by user_id as well
 * as by crew id so a crafted crew id from another account returns nothing.
 *
 * @param int[] $playerCrewIds
 */
function pw_missions_load_crew_gear(PDO $db, int $userId, array $playerCrewIds): array {
    $ids = array_values(array_unique(array_map('intval', $playerCrewIds)));
    if (!$ids || !pw_mission_gear_ready($db)) return [];
    $stmt = $db->prepare(
        'SELECT g.player_crew_id, g.slot, g.loot_definition_id,
                l.name, l.slug, l.tier, l.description, l.icon_url,
                l.bonus_strength, l.bonus_cunning, l.bonus_science, l.bonus_charisma,
                l.required_level, l.required_role
         FROM game_player_crew_gear g
         JOIN game_loot_definitions l ON l.id = g.loot_definition_id
         WHERE g.user_id = ? AND g.player_crew_id IN (' . pw_missions_placeholders(count($ids)) . ')'
    );
    $stmt->execute(array_merge([$userId], $ids));
    $slots = pw_missions_gear_slots();
    $byCrew = [];
    foreach ($stmt->fetchAll() as $row) {
        $slot = (string)$row['slot'];
        // A slot no longer in the code list is ignored rather than rendered:
        // removing a slot must not leave an item floating outside the loadout.
        if (!isset($slots[$slot])) continue;
        $byCrew[(int)$row['player_crew_id']][$slot] = [
            'loot_definition_id' => (int)$row['loot_definition_id'],
            'name' => (string)$row['name'],
            'slug' => (string)$row['slug'],
            'tier' => (string)$row['tier'],
            'description' => (string)$row['description'],
            'icon_url' => pw_missions_gear_icon_url($row['icon_url']),
            'slot' => $slot,
            'slot_label' => $slots[$slot],
            'bonus' => [
                'strength' => (int)$row['bonus_strength'],
                'cunning' => (int)$row['bonus_cunning'],
                'science' => (int)$row['bonus_science'],
                'charisma' => (int)$row['bonus_charisma'],
            ],
            'required_level' => (int)$row['required_level'],
            'required_role' => (string)$row['required_role'],
        ];
    }
    return $byCrew;
}

/**
 * Re-validated against the same allow-list the crew portraits use, since this
 * value goes into a CSS/img URL. An unrecognised path becomes empty and the
 * built-in slot glyph is drawn instead.
 */
function pw_missions_gear_icon_url($url): string {
    $url = (string)$url;
    return preg_match('/^(?:images\/[a-zA-Z0-9._-]{1,220}|\/uploads\/mission-crew-images\/img_[a-f0-9]{16}\.jpg)$/', $url) ? $url : '';
}

/**
 * Folds equipped gear into a set of crew rows.
 *
 * The four stat fields become the totals the crew member actually fights with,
 * so every existing consumer -- pw_missions_crew_effects() above, the launch
 * projection, the claim payout -- reads gear without knowing gear exists. The
 * pre-gear values stay available as base_<stat>, and gear_bonus carries the
 * difference for tooltips and comparisons while the roster prints one true
 * total for the stat the crew member will actually use.
 *
 * @param array $crewRows Rows carrying an `id` (player crew id) and the four stats.
 */
function pw_missions_apply_gear(PDO $db, int $userId, array $crewRows): array {
    return pw_missions_apply_gear_bonuses($crewRows, pw_missions_load_crew_gear($db, $userId, array_map(static function ($row) {
        return (int)($row['id'] ?? 0);
    }, $crewRows)));
}

/**
 * The arithmetic half of the above, with no database in it: fold a set of
 * equipped items into each crew row's stats.
 *
 * Split out so Game Tuning can simulate a hypothetical loadout on a
 * hypothetical crew member through the same code the live paths use. Without
 * the split a simulator would have to re-implement this summing, and a tuning
 * tool that disagrees with the game is worse than no tuning tool -- it is wrong
 * exactly when it is being trusted to find something wrong.
 *
 * @param array $gearByCrew Equipped items keyed by crew row id, then by slot.
 */
function pw_missions_apply_gear_bonuses(array $crewRows, array $gearByCrew): array {
    $stats = pw_missions_gear_stat_keys();
    foreach ($crewRows as $index => $row) {
        $bonus = array_fill_keys($stats, 0);
        $equipped = $gearByCrew[(int)($row['id'] ?? 0)] ?? [];
        foreach ($equipped as $item) {
            foreach ($stats as $stat) $bonus[$stat] += (int)$item['bonus'][$stat];
        }
        foreach ($stats as $stat) {
            $base = max(0, (int)($row[$stat] ?? 0));
            $crewRows[$index]['base_' . $stat] = $base;
            // Clamped at zero: a negative-bonus item may take a stat to nothing
            // but never below it, where a percentage would turn into a penalty
            // no part of the effects engine is written to express.
            $crewRows[$index][$stat] = max(0, $base + $bonus[$stat]);
        }
        $crewRows[$index]['gear'] = $equipped;
        $crewRows[$index]['gear_bonus'] = $bonus;
        $crewRows[$index]['gear_slots_filled'] = count($equipped);
    }
    return $crewRows;
}

/**
 * Whether this crew member may equip this item, and why not when they may not.
 * Returns an empty string when the item is allowed.
 */
function pw_missions_gear_requirement_error(array $item, array $crew): string {
    $requiredLevel = (int)($item['required_level'] ?? 1);
    if ((int)($crew['level'] ?? 0) < $requiredLevel) {
        return 'This equipment needs a level ' . $requiredLevel . ' crew member.';
    }
    $requiredRole = trim((string)($item['required_role'] ?? ''));
    if ($requiredRole !== '' && strcasecmp($requiredRole, (string)($crew['role'] ?? '')) !== 0) {
        return 'Only a ' . $requiredRole . ' can carry this equipment.';
    }
    return '';
}

/**
 * Whether sql/migration_mission_weather.sql has been run. Until it has, weather
 * still affects a launch -- the modifiers are computed from the live forecast,
 * not from a stored row -- but nothing is snapshotted, so a claim resolves with
 * no weather effect rather than against the wrong day's conditions.
 */
function pw_mission_weather_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!pw_missions_ready($db)) return $ready = false;
    return $ready = pw_schema_has($db, 'game_player_missions', ['weather_condition', 'weather_icon', 'weather_severe']);
}

/* ------------------------------------------------------------------------
 * Neoh's live weather, as an operating condition.
 *
 * The world already generates a deterministic forecast for its World Record
 * card, including a severity judgement made against that world's own
 * configured bounds. This reads the same generator -- never a second copy of
 * it -- and turns today's conditions into two effects:
 *
 *   Severe weather slows an operation down. Any severity reason counts: a
 *   storm front, extreme wind, torrential fall, peak heat or deep cold.
 *
 *   A static storm additionally ruins the luck -- the success roll and the
 *   loot-tier promotion roll, which are the two chance rolls a mission makes.
 *   Storms are themselves a severity reason, so a static storm is both slower
 *   and less lucky, while extreme heat is only slower.
 *
 * Weather is a property of the world and the day, not of the crew, so it is
 * never something a player can select around -- only something to wait out.
 * ---------------------------------------------------------------------- */

const PW_MISSION_WEATHER_SEVERE_DURATION = 15.0;
const PW_MISSION_WEATHER_STORM_SUCCESS = 5.0;
const PW_MISSION_WEATHER_STORM_UPGRADE = 15.0;

/**
 * Today's conditions for a world, or null when there is nothing to read.
 *
 * Gated exactly as api/world-weather.php gates the public card: the world must
 * be available in World Control and its weather profile enabled. A world with
 * no profile, a disabled profile, or a database without the weather tables at
 * all simply has no weather, and every operation runs unmodified -- so this can
 * never become a reason a mission cannot be launched.
 */
function pw_missions_world_weather(PDO $db, string $worldKey): ?array {
    static $cache = [];
    if (array_key_exists($worldKey, $cache)) return $cache[$worldKey];
    if (!preg_match('/^[a-z0-9-]{1,50}$/', $worldKey)) return $cache[$worldKey] = null;

    require_once __DIR__ . '/../weather-forecast.php';
    /* current_auto/tomorrow_auto arrive with a later migration than the profile
     * table itself, so they are selected separately and fall back to the
     * pre-migration column list -- the same guarded pattern the public weather
     * endpoint uses, for the same reason: a missing column is a hard SQL error,
     * not a NULL. */
    $select =
        'SELECT w.slug, w.status, p.enabled, p.current_condition, p.current_secondary, p.current_temp_c,
                p.tomorrow_condition, p.tomorrow_temp_c%s, p.forecast_min_c, p.forecast_max_c,
                p.humidity_min, p.humidity_max, p.precipitation_min, p.precipitation_max,
                p.wind_min_kph, p.wind_max_kph, p.condition_pool_json, p.hazard_note, p.forecast_revision
         FROM worlds w
         LEFT JOIN world_weather_profiles p ON p.world_id = w.id
         WHERE w.slug = ?';
    try {
        try {
            $stmt = $db->prepare(sprintf($select, ', p.current_auto, p.tomorrow_auto'));
            $stmt->execute([$worldKey]);
            $profile = $stmt->fetch();
        } catch (PDOException $e) {
            $stmt = $db->prepare(sprintf($select, ''));
            $stmt->execute([$worldKey]);
            $profile = $stmt->fetch();
        }
    } catch (Throwable $e) {
        return $cache[$worldKey] = null;
    }
    if (!$profile || $profile['status'] !== 'available' || $profile['enabled'] === null || (int)$profile['enabled'] !== 1) {
        return $cache[$worldKey] = null;
    }

    $forecast = pw_build_weather_forecast($profile, $worldKey);
    $current = $forecast['current'];
    return $cache[$worldKey] = [
        'condition' => (string)$current['condition'],
        'icon' => (string)$current['icon'],
        'severe' => !empty($current['severity']['severe']),
        'severity_label' => (string)($current['severity']['label'] ?? ''),
        'temperature_c' => (int)$current['temperature_c'],
        'wind_kph' => (int)$current['wind_kph'],
        'hazard_note' => (string)$profile['hazard_note'],
    ];
}

/**
 * The two effects above, from a conditions snapshot. Accepts either a live
 * reading from pw_missions_world_weather() or the row stored against a launched
 * mission -- both carry an icon and a severe flag, which is all this needs.
 */
function pw_missions_weather_modifiers(?array $weather): array {
    $result = [
        'active' => false,
        'condition' => '',
        'icon' => '',
        'severe' => false,
        'storm' => false,
        'duration_percent' => 0.0,
        'success_percent' => 0.0,
        'upgrade_percent' => 0.0,
    ];
    if (!$weather || ($weather['icon'] ?? '') === '') return $result;

    $result['active'] = true;
    $result['condition'] = (string)($weather['condition'] ?? '');
    $result['icon'] = (string)$weather['icon'];
    $result['severe'] = !empty($weather['severe']);
    $result['storm'] = $result['icon'] === 'storm';
    if ($result['severe']) $result['duration_percent'] = PW_MISSION_WEATHER_SEVERE_DURATION;
    if ($result['storm']) {
        $result['success_percent'] = PW_MISSION_WEATHER_STORM_SUCCESS;
        $result['upgrade_percent'] = PW_MISSION_WEATHER_STORM_UPGRADE;
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
 * @param array|null $weather Conditions the operation runs in, from
 *        pw_missions_world_weather() at launch or the snapshot stored against
 *        the run at claim. Omitted wherever there is no operation in context,
 *        for the same reason as $missionType.
 */
function pw_missions_crew_effects(array $crew, ?string $missionType = null, ?array $weather = null): array {
    $totals = ['strength' => 0, 'cunning' => 0, 'science' => 0, 'charisma' => 0];
    $durationPercent = 0.0;
    $xpPercent = 0.0;
    $reputationFlat = 0.0;
    $creditPercent = 0.0;

    foreach ($crew as $member) {
        $level = max(0, min(PW_MISSION_MAX_LEVEL, (int)($member['level'] ?? 0)));
        foreach ($totals as $stat => $_) {
            // Clamped at the gear ceiling, not the levelling one: by the time a
            // row reaches here pw_missions_apply_gear() may have raised a stat
            // past 50, and clamping at 50 would silently discard the equipment.
            $totals[$stat] += max(0, min(PW_MISSION_MAX_GEAR_STAT, (int)($member[$stat] ?? 0)));
        }
        $role = (string)($member['role'] ?? '');
        /* Per member, not per role: rarity raises the rate, so two Engineers of
         * different rarity at the same level do not contribute equally. */
        $rate = pw_missions_role_rate_for($role, $member['tier'] ?? 'common');
        if (isset($rate['duration_percent_per_level'])) $durationPercent += $level * $rate['duration_percent_per_level'];
        if (isset($rate['xp_percent_per_level'])) $xpPercent += $level * $rate['xp_percent_per_level'];
        if (isset($rate['reputation_per_level'])) $reputationFlat += $level * $rate['reputation_per_level'];
        if (isset($rate['credit_percent_per_level'])) $creditPercent += $level * $rate['credit_percent_per_level'];
    }

    // Charisma adds to the same XP pool the Pathfinder role bonus feeds.
    $xpPercent += $totals['charisma'] * PW_MISSION_CHARISMA_XP_PER_POINT;

    /* Affinity is added to the same pools the stats and role bonuses feed, so
     * every consumer downstream keeps reading one figure per effect rather than
     * having to know that a second bonus system exists. */
    $affinity = pw_missions_affinity($missionType, $crew);
    $durationPercent += $affinity['duration_percent'];
    $xpPercent += $affinity['xp_percent'];

    /* Weather joins the same pools, for the same reason: one figure per effect,
     * whatever produced it. Its slowdown is added to the affinity penalty rather
     * than netted against the Engineer reduction -- bad weather should still cost
     * time on a crew experienced enough to have outrun it. */
    $conditions = pw_missions_weather_modifiers($weather);

    return [
        // Clamped well short of a free mission: a duration multiplier must stay
        // positive however large a future crew or rate becomes.
        'duration_percent' => round(min(90.0, $durationPercent), 2),
        // A mismatched crew is slower. Kept separate from the reduction above
        // rather than subtracted from it, so a crew that is both experienced and
        // wrong for the job is still slower than the same crew sent where it
        // belongs -- netting the two would let deep Engineer levels cancel the
        // penalty out entirely.
        'duration_penalty_percent' => round($affinity['penalty_duration_percent'] + $conditions['duration_percent'], 2),
        'xp_percent' => round($xpPercent, 2),
        'reputation_flat' => (int)floor($reputationFlat),
        'reputation_percent' => round($affinity['reputation_percent'], 2),
        /* The Fixer's per-level bonus joins the same pool recon affinity feeds,
         * so claim.php and the launch projection keep reading one credit figure
         * whatever produced it. Deliberately uncapped, matching the XP and
         * reputation pools rather than the duration one -- credits buy from a
         * stocked, rank-gated Market, so a large payout cannot break anything
         * the way a near-zero mission clock would. */
        'credit_percent' => round($affinity['credit_percent'] + $creditPercent, 2),
        'success_percent' => round(($totals['strength'] * PW_MISSION_STRENGTH_SUCCESS_PER_POINT) + $affinity['success_percent']
            - $affinity['penalty_success_percent'] - $conditions['success_percent'], 2),
        'loot_percent' => round($totals['cunning'] * PW_MISSION_CUNNING_LOOT_PER_POINT, 2),
        // The storm's toll on the promotion roll comes off after the cap, and is
        // floored at zero: a storm can take the whole bonus away, never turn it
        // into a penalty on a crew that had none to begin with.
        'upgrade_percent' => round(max(0.0, min(95.0, ($totals['science'] * PW_MISSION_SCIENCE_UPGRADE_PER_POINT) + $affinity['upgrade_percent']) - $conditions['upgrade_percent']), 2),
        'stat_totals' => $totals,
        'affinity' => $affinity,
        'weather' => $conditions,
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
    return pw_missions_percent_roll_detail($percent)['hit'];
}

/**
 * The same roll, with the number it produced. The mission debrief reports what
 * was actually rolled against the odds, so a loss at 90% reads as bad luck
 * rather than as the game having lied about the odds -- and a win at 40% reads
 * as the escape it was.
 *
 * Kept as one implementation with pw_missions_percent_roll() delegating to it,
 * so the reported roll can never be a second, differently-behaved draw from the
 * one that decided the outcome.
 *
 * @return array{hit: bool, roll: float} roll is a percentage to two decimals.
 */
function pw_missions_percent_roll_detail(float $percent): array {
    $percent = max(0.0, min(100.0, $percent));
    $roll = random_int(1, 10000);
    if ($percent <= 0) return ['hit' => false, 'roll' => $roll / 100];
    if ($percent >= 100) return ['hit' => true, 'roll' => $roll / 100];
    return ['hit' => $roll <= (int)round($percent * 100), 'roll' => $roll / 100];
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
 * Draw loot for one mission. Returns the player-safe reward fields needed by
 * the debrief -- including equipment art and bonuses when gear is available --
 * one entry per item awarded, with the Science upgrade already applied.
 */
function pw_missions_roll_loot(PDO $db, string $worldKey, int $baseRolls, array $effects): array {
    $rolls = pw_missions_loot_roll_count($baseRolls, $effects);
    if ($rolls < 1) return [];

    $gearReady = pw_mission_gear_ready($db);
    $gearColumns = $gearReady
        ? ', slot, bonus_strength, bonus_cunning, bonus_science, bonus_charisma, required_level, required_role, icon_url'
        : '';
    /* Without this an awarded stim reaches pw_missions_store_loot() with no
     * stim_effect and is counted against the salvage ceiling instead of its
     * own. */
    $stimsReady = pw_mission_stims_ready($db);
    if ($stimsReady) $gearColumns .= ', stim_effect, stim_value, stim_duration_seconds';
    $stmt = $db->prepare('SELECT id, name, slug, tier, drop_weight' . $gearColumns . ' FROM game_loot_definitions WHERE world_key = ? AND is_enabled = 1');
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
            'slot' => $gearReady ? (string)($item['slot'] ?? '') : '',
            'icon_url' => $gearReady ? pw_missions_gear_icon_url($item['icon_url'] ?? '') : '',
            'required_level' => $gearReady ? (int)($item['required_level'] ?? 1) : 1,
            'required_role' => $gearReady ? (string)($item['required_role'] ?? '') : '',
            'stim_effect' => $stimsReady ? (string)($item['stim_effect'] ?? '') : '',
            'stim_value' => $stimsReady ? (float)($item['stim_value'] ?? 0) : 0.0,
            'stim_duration_seconds' => $stimsReady ? (int)($item['stim_duration_seconds'] ?? 0) : 0,
            'bonus' => [
                'strength' => $gearReady ? (int)($item['bonus_strength'] ?? 0) : 0,
                'cunning' => $gearReady ? (int)($item['bonus_cunning'] ?? 0) : 0,
                'science' => $gearReady ? (int)($item['bonus_science'] ?? 0) : 0,
                'charisma' => $gearReady ? (int)($item['bonus_charisma'] ?? 0) : 0,
            ],
        ];
    }
    return $awarded;
}

/**
 * Adds awarded items to the player's inventory, dropping anything that will not
 * fit and reporting it.
 *
 * The caps are enforced here rather than by a database constraint because they
 * are totals across rows, which no column constraint can express, and because
 * a full inventory must not fail a mission claim -- the operation succeeded and
 * the rest of its payout is owed. Overflow is returned so the debrief can say
 * plainly that a reward was left behind.
 *
 * Counted once up front and then tracked locally: awarded items arrive in one
 * batch and each insert would otherwise need its own re-count.
 *
 * @return array{stored: array<int,int>, skipped: array<int,int>} Quantities by definition id.
 */
function pw_missions_store_loot(PDO $db, int $userId, array $awarded, array $researchEffects = []): array {
    $result = ['stored' => [], 'skipped' => []];
    if (!$awarded) return $result;
    $counts = [];
    $categories = [];
    foreach ($awarded as $item) {
        $id = (int)$item['id'];
        $counts[$id] = ($counts[$id] ?? 0) + 1;
        $categories[$id] = pw_missions_inventory_category($item);
    }

    $usage = pw_missions_inventory_usage($db, $userId, $researchEffects);
    $room = [
        'salvage' => max(0, $usage['salvage_cap'] - $usage['salvage']),
        'inventory' => max(0, $usage['inventory_cap'] - $usage['inventory']),
    ];

    $stmt = $db->prepare(
        'INSERT INTO game_player_loot (user_id, loot_definition_id, quantity) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)'
    );
    foreach ($counts as $definitionId => $quantity) {
        $bucket = pw_missions_inventory_bucket($categories[$definitionId]);
        $fits = min($quantity, $room[$bucket]);
        if ($fits > 0) {
            $stmt->execute([$userId, $definitionId, $fits]);
            $room[$bucket] -= $fits;
            $result['stored'][$definitionId] = $fits;
        }
        if ($fits < $quantity) $result['skipped'][$definitionId] = $quantity - $fits;
    }
    return $result;
}

/* ---------------------------------------------------------------------------
 * Inventory limits
 *
 * Two ceilings, counted independently. Salvage is separate from everything else
 * because api/research/unlock.php spends it -- if a hoard of equipment could
 * crowd salvage out, a full inventory would quietly block the research tree,
 * which is a failure the player has no way to diagnose.
 *
 * Stims are counted with equipment rather than with salvage: they are acquired
 * and disposed of through the same actions, and a stockpile of boosts competing
 * for space with the gear they support is the trade-off that makes them a
 * decision rather than a free bonus.
 *
 * Both figures are sums of game_player_loot.quantity, so a stack of ten counts
 * as ten. Counting distinct rows instead would let one item type absorb an
 * unbounded hoard, which is the exact thing the cap exists to prevent.
 * ------------------------------------------------------------------------- */
const PW_MISSION_INVENTORY_CAP = 100;
const PW_MISSION_SALVAGE_CAP = 100;
/* What research may add to each of the two ceilings. Applied to both, by the
 * same amount, from one effect: which of the two a given node raises is not a
 * distinction the player can act on, and leaving the salvage ceiling
 * un-raisable would mean the tree could eventually be blocked by the storage
 * limit on the currency that buys it. */
const PW_MISSION_INVENTORY_RESEARCH_CAP = 200;

/* ---------------------------------------------------------------------------
 * The stim belt
 *
 * A fixed grid of quick slots on the command view, four across. Two rows to
 * begin with; research adds slots a row at a time.
 *
 * Slots are assigned rather than auto-filled. Auto-filling from whatever the
 * player happens to hold would need no table and could not desync -- but then
 * the count would only ever bind on someone carrying more distinct stims than
 * slots, which is nobody early on, and a limit that never binds is not a limit.
 * Assignment makes the belt a decision from the first stim onwards.
 * ------------------------------------------------------------------------- */
const PW_MISSION_STIM_SLOT_COLUMNS = 4;
const PW_MISSION_STIM_SLOT_BASE = 8;
/* Two more rows. Sixteen keeps the grid a readable 4x4 at its widest; beyond
 * that the belt stops being a belt and becomes a second inventory panel. */
const PW_MISSION_STIM_SLOT_RESEARCH_CAP = 8;

/**
 * What an item is, from the columns alone: 'gear', 'stim' or 'salvage'.
 *
 * One classifier, used by the caps, the inventory panel, the destroy endpoint
 * and the stim endpoint, so those five places can never disagree about which
 * bucket an item belongs to. A stim never has a slot -- see the migration.
 */
function pw_missions_inventory_category(array $item): string {
    if (trim((string)($item['slot'] ?? '')) !== '') return 'gear';
    if (trim((string)($item['stim_effect'] ?? '')) !== '') return 'stim';
    return 'salvage';
}

/** Which of the two ceilings a category is counted against. */
function pw_missions_inventory_bucket(string $category): string {
    return $category === 'salvage' ? 'salvage' : 'inventory';
}

/**
 * How full each of the two ceilings is right now.
 *
 * Grouped in SQL rather than by reading every row: the panel needs the totals
 * on every page load and a player at the cap holds 200 units across an
 * unbounded number of rows.
 */
function pw_missions_inventory_usage(PDO $db, int $userId, array $researchEffects = []): array {
    /* Research raises both ceilings by the same amount. Effects are passed in
     * rather than looked up, for the same reason pw_missions_fatigue_max() takes
     * them: research-helpers.php requires this file, so calling back into it
     * here would be circular, and every caller already has the array. */
    $bonus = (int)min(PW_MISSION_INVENTORY_RESEARCH_CAP, max(0, (int)($researchEffects['inventory_capacity'] ?? 0)));
    $usage = [
        'inventory' => 0,
        'salvage' => 0,
        'inventory_cap' => PW_MISSION_INVENTORY_CAP + $bonus,
        'salvage_cap' => PW_MISSION_SALVAGE_CAP + $bonus,
        'capacity_bonus' => $bonus,
    ];
    $stimsReady = pw_mission_stims_ready($db);
    try {
        $stmt = $db->prepare(
            'SELECT l.slot, ' . ($stimsReady ? 'l.stim_effect' : '"" AS stim_effect') . ', SUM(pl.quantity) AS held
             FROM game_player_loot pl
             JOIN game_loot_definitions l ON l.id = pl.loot_definition_id
             WHERE pl.user_id = ? AND pl.quantity > 0
             GROUP BY l.slot, ' . ($stimsReady ? 'l.stim_effect' : 'l.slot')
        );
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $row) {
            $bucket = pw_missions_inventory_bucket(pw_missions_inventory_category($row));
            $usage[$bucket] += (int)$row['held'];
        }
    } catch (Throwable $e) {
        // Pre-gear databases have no slot column. Nothing is capped until the
        // columns the classifier reads actually exist.
    }
    return $usage;
}

/* ---------------------------------------------------------------------------
 * Stims
 *
 * A consumable. Everything else a player acquires is permanent, which makes
 * every acquisition strictly additive -- a stim is the first item whose value
 * is decided by when it is spent.
 *
 * Three effects, deliberately mapped onto figures the engine already resolves
 * rather than onto new plumbing: fatigue is restored directly to one crew
 * member, while mission speed and loot luck are folded into the same account
 * effect totals research already contributes to, so a boost composes with the
 * tree instead of running beside it.
 *
 * The two timed effects have a combined ceiling above the research-only cap.
 * Sharing the research ceiling would mean a fully-researched player's stim did
 * nothing at all, which is worse than a smaller benefit; the headroom is what
 * makes a boost worth carrying at every point of the game.
 * ------------------------------------------------------------------------- */
const PW_MISSION_STIM_SPEED_COMBINED_CAP = 75.0;
const PW_MISSION_STIM_LUCK_COMBINED_CAP = 90.0;
const PW_MISSION_STIM_RECOVERY_COMBINED_CAP = 300.0;

/**
 * SQL predicate for "an item the Market and loot tables may carry".
 *
 * Equipment has always qualified by having a slot. A stim has no slot -- it is
 * consumed, never worn -- so every one of those filters would exclude it, and
 * the user-facing consequence would be a stim that can drop from the world pool
 * but can never be authored into an offer or a table. One fragment rather than
 * six edited predicates, so a future item kind is added in one place.
 */
function pw_missions_carryable_item_sql(PDO $db, string $alias = 'l'): string {
    $slot = $alias . '.slot <> ""';
    return pw_mission_stims_ready($db) ? '(' . $slot . ' OR ' . $alias . '.stim_effect <> "")' : $slot;
}

/**
 * Whether sql/migration_mission_stim_belt.sql has been run. Independent of the
 * stim probe: stims themselves work without a belt, so a deployment waiting on
 * this one-off migration keeps everything else.
 */
function pw_mission_stim_slots_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!pw_mission_stims_ready($db)) return $ready = false;
    return $ready = pw_schema_has($db, 'game_player_stim_slots', ['user_id', 'slot_index', 'loot_definition_id']);
}

/** How many quick slots this player has, base plus research. */
function pw_missions_stim_slot_capacity(array $researchEffects = []): int {
    $bonus = (int)min(PW_MISSION_STIM_SLOT_RESEARCH_CAP, max(0, (int)($researchEffects['stim_slots'] ?? 0)));
    return PW_MISSION_STIM_SLOT_BASE + $bonus;
}

/**
 * The belt, as one entry per slot from 0 to capacity - 1.
 *
 * Always the full grid, empty slots included, because the grid is the point --
 * a belt that only rendered its filled slots would not show the player what
 * research bought them. Rows beyond the current capacity are left in place
 * rather than deleted: capacity only ever grows in normal play, and silently
 * discarding an assignment because a probe briefly read a smaller number would
 * be worse than an unreachable row that reappears when it should.
 */
function pw_missions_stim_slots(PDO $db, int $userId, array $researchEffects = []): array {
    $capacity = pw_missions_stim_slot_capacity($researchEffects);
    $slots = [];
    for ($i = 0; $i < $capacity; $i++) $slots[$i] = ['slot_index' => $i, 'item' => null];
    if (!pw_mission_stim_slots_ready($db)) {
        return ['ready' => false, 'capacity' => $capacity, 'columns' => PW_MISSION_STIM_SLOT_COLUMNS, 'slots' => array_values($slots)];
    }
    try {
        /* Joined to the holdings rather than read alone: a slot whose stim has
         * been used up or destroyed shows as empty rather than as an item the
         * player no longer has. The row is left alone -- reacquiring the same
         * stim should put it back where it was. */
        $stmt = $db->prepare(
            'SELECT sl.slot_index, l.id, l.name, l.tier, l.icon_url, l.description,
                    l.stim_effect, l.stim_value, l.stim_duration_seconds, l.is_enabled,
                    COALESCE(pl.quantity, 0) AS quantity
             FROM game_player_stim_slots sl
             JOIN game_loot_definitions l ON l.id = sl.loot_definition_id
             LEFT JOIN game_player_loot pl ON pl.user_id = sl.user_id AND pl.loot_definition_id = sl.loot_definition_id
             WHERE sl.user_id = ?
             ORDER BY sl.slot_index ASC'
        );
        $stmt->execute([$userId]);
    } catch (Throwable $e) {
        return ['ready' => false, 'capacity' => $capacity, 'columns' => PW_MISSION_STIM_SLOT_COLUMNS, 'slots' => array_values($slots)];
    }
    foreach ($stmt->fetchAll() as $row) {
        $index = (int)$row['slot_index'];
        if (!isset($slots[$index])) continue;
        $slots[$index]['item'] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'tier' => (string)$row['tier'],
            'icon_url' => pw_missions_gear_icon_url($row['icon_url']),
            'description' => (string)($row['description'] ?? ''),
            'stim_effect' => (string)$row['stim_effect'],
            'stim_value' => (float)$row['stim_value'],
            'stim_duration_seconds' => (int)$row['stim_duration_seconds'],
            'quantity' => (int)$row['quantity'],
            // A stim an administrator has withdrawn keeps its slot but cannot be
            // used, exactly as an exhausted one cannot.
            'is_enabled' => (bool)$row['is_enabled'],
        ];
    }
    return [
        'ready' => true,
        'capacity' => $capacity,
        'columns' => PW_MISSION_STIM_SLOT_COLUMNS,
        'slots' => array_values($slots),
    ];
}

function pw_missions_stim_effect_types(): array {
    return [
        'fatigue' => [
            'label' => 'Fatigue recovery',
            'value_label' => 'Fatigue restored',
            'unit' => 'points',
            'timed' => false,
            'description' => 'Restores fatigue to one resting crew member immediately.',
        ],
        'mission_speed' => [
            'label' => 'Speed boost',
            'value_label' => 'Speed boost (%)',
            'unit' => '%',
            'timed' => true,
            'description' => 'Shortens every operation launched while it is running.',
        ],
        'luck' => [
            'label' => 'Luck boost',
            'value_label' => 'Rarity promotion (%)',
            'unit' => '%',
            'timed' => true,
            'description' => 'Raises the chance a recovered item is promoted one rarity tier.',
        ],
    ];
}

/**
 * Whether sql/migration_mission_inventory.sql has been run. Until it has, no
 * item can be a stim, the inventory caps count equipment and salvage exactly as
 * before, and every stim endpoint refuses politely.
 */
function pw_mission_stims_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!pw_mission_gear_ready($db)) return $ready = false;
    return $ready = pw_schema_has($db, 'game_loot_definitions', ['stim_effect', 'stim_value', 'stim_duration_seconds'])
        && pw_schema_has($db, 'game_player_stim_effects', ['user_id', 'effect_type', 'effect_value', 'expires_at']);
}

/**
 * Boosts currently running for this player, newest first.
 *
 * Filtered on expires_at rather than relying on a prune, so a row nobody has
 * cleaned up yet is already inert and a missed prune can never grant a benefit
 * past its own expiry.
 */
function pw_missions_active_stims(PDO $db, int $userId): array {
    if (!pw_mission_stims_ready($db)) return [];
    try {
        $stmt = $db->prepare(
            'SELECT s.id, s.effect_type, s.effect_value, s.started_at, s.expires_at, l.name, l.tier
             FROM game_player_stim_effects s
             JOIN game_loot_definitions l ON l.id = s.loot_definition_id
             WHERE s.user_id = ? AND s.expires_at > UTC_TIMESTAMP()
             ORDER BY s.expires_at ASC'
        );
        $stmt->execute([$userId]);
    } catch (Throwable $e) {
        return [];
    }
    return array_map(static function ($row) {
        $row['id'] = (int)$row['id'];
        $row['effect_value'] = (float)$row['effect_value'];
        return $row;
    }, $stmt->fetchAll());
}

/**
 * Folds running boosts into an effects array, re-applying a combined ceiling.
 *
 * Called by pw_research_player_effects() so every existing consumer -- the
 * launch projection, the claim payout, the mission card -- picks a stim up with
 * no change of its own. The alternative, a second effects array threaded
 * through those paths, is how one of them ends up quietly ignoring boosts.
 */
function pw_missions_apply_stim_effects(PDO $db, int $userId, array $effects): array {
    $active = pw_missions_active_stims($db, $userId);
    if (!$active) return $effects;
    $totals = ['mission_speed' => 0.0, 'luck' => 0.0, 'fatigue_recovery' => 0.0];
    foreach ($active as $stim) {
        $type = (string)$stim['effect_type'];
        if (isset($totals[$type])) $totals[$type] += max(0.0, (float)$stim['effect_value']);
    }
    $effects['mission_speed_percent'] = round(min(PW_MISSION_STIM_SPEED_COMBINED_CAP,
        (float)($effects['mission_speed_percent'] ?? 0) + $totals['mission_speed']), 2);
    $effects['luck_percent'] = round(min(PW_MISSION_STIM_LUCK_COMBINED_CAP,
        (float)($effects['luck_percent'] ?? 0) + $totals['luck']), 2);
    $effects['fatigue_recovery_percent'] = round(min(PW_MISSION_STIM_RECOVERY_COMBINED_CAP,
        (float)($effects['fatigue_recovery_percent'] ?? 0) + $totals['fatigue_recovery']), 2);
    return $effects;
}

/* ------------------------------------------------------------------------
 * Loot tables.
 *
 * A loot table is a reusable named group of possible rewards. Two independent
 * chances decide an award: the mission -> table link says how often the table
 * is opened at all, and each entry inside says how often it drops. Entries are
 * therefore independent rolls rather than a weighted pick -- one successful
 * mission can award several rewards, or none. Characters remain unique roster
 * additions, while gear is stored as stackable player loot.
 * ---------------------------------------------------------------------- */

function pw_mission_loot_tables_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!pw_missions_ready($db)) return $ready = false;
    return $ready = pw_schema_has($db, 'game_loot_tables', ['id'])
        && pw_schema_has($db, 'game_loot_table_entries', ['id'])
        && pw_schema_has($db, 'game_mission_loot_tables', ['id']);
}

function pw_missions_require_loot_tables_ready(PDO $db): void {
    if (!pw_mission_loot_tables_ready($db)) {
        pw_error('Loot tables are being prepared. Please try again after the Mission Loot Tables migration has been run.', 503);
    }
}

/**
 * Whether sql/migration_mission_loot_table_gear.sql has been run. The loot
 * table base migration deliberately remains usable on sites that have not yet
 * opted into gear entries, so the claim roller can still award character rows
 * during a staged deployment.
 */
function pw_mission_loot_table_gear_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!pw_mission_loot_tables_ready($db) || !pw_mission_gear_ready($db)) return $ready = false;
    return $ready = pw_schema_has($db, 'game_loot_table_entries', ['loot_definition_id']);
}

/** Rare research-table locks are an optional, additive layer on Loot Tables.
 * The flag is deliberately probed separately so ordinary tables still work
 * while a site is waiting to run the matching one-off migration. */
function pw_mission_loot_table_research_locks_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!pw_mission_loot_tables_ready($db)) return $ready = false;
    return $ready = pw_schema_has($db, 'game_loot_tables', ['is_research_rare', 'requires_research_unlock']);
}

/** Crew capacity is opt-in until its migration has run. The probe covers both
 * the author-facing rarity column and the player-facing holding queue. */
function pw_mission_crew_capacity_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!pw_missions_ready($db)) return $ready = false;
    return $ready = pw_schema_has($db, 'game_crew_definitions', ['tier'])
        && pw_schema_has($db, 'game_player_crew_offers', ['id', 'status']);
}

function pw_missions_crew_sale_value(string $tier): int {
    return [
        'common' => 100,
        'uncommon' => 200,
        'rare' => 500,
        'epic' => 800,
        'legendary' => 1250,
    ][strtolower($tier)] ?? 100;
}

function pw_missions_crew_capacity(PDO $db, int $userId): int {
    if (!pw_mission_crew_capacity_ready($db) || !function_exists('pw_research_crew_capacity')) return 8;
    return max(8, min(32, pw_research_crew_capacity($db, $userId)));
}

function pw_missions_active_crew_count(PDO $db, int $userId): int {
    /* A disabled definition is intentionally absent from the public roster;
     * it must not occupy one of the command's purchasable berths either. */
    $count = $db->prepare(
        'SELECT COUNT(*)
         FROM game_player_crew player_crew
         JOIN game_crew_definitions crew ON crew.id = player_crew.crew_definition_id AND crew.is_enabled = 1
         WHERE player_crew.user_id = ? AND player_crew.status <> "retired"'
    );
    $count->execute([$userId]);
    return (int)$count->fetchColumn();
}

/** Player-safe held recruits for the command page. Offer state remains on the
 * server; this response contains only the three actions the player can take. */
function pw_missions_pending_crew_offers(PDO $db, int $userId): array {
    if (!pw_mission_crew_capacity_ready($db)) return [];
    $stmt = $db->prepare(
        'SELECT offer.id, offer.crew_definition_id, offer.sale_credits, offer.created_at,
                crew.name, crew.role, crew.portrait_url, crew.tier
         FROM game_player_crew_offers offer
         JOIN game_crew_definitions crew ON crew.id = offer.crew_definition_id
         WHERE offer.user_id = ? AND offer.status = "pending"
         ORDER BY offer.created_at ASC, offer.id ASC'
    );
    $stmt->execute([$userId]);
    $count = pw_missions_active_crew_count($db, $userId);
    $capacity = pw_missions_crew_capacity($db, $userId);
    return array_map(static function ($offer) use ($count, $capacity) {
        return [
            'id' => (int)$offer['id'], 'crew_definition_id' => (int)$offer['crew_definition_id'],
            'name' => (string)$offer['name'], 'role' => (string)$offer['role'], 'portrait_url' => (string)$offer['portrait_url'],
            'tier' => (string)$offer['tier'], 'sale_credits' => (int)$offer['sale_credits'], 'created_at' => (string)$offer['created_at'],
            'roster_count' => $count, 'capacity' => $capacity, 'can_accept' => $count < $capacity,
        ];
    }, $stmt->fetchAll());
}

/**
 * Receive a crew definition without ever exceeding the command's berth cap.
 * At capacity the recruit becomes a pending offer rather than being silently
 * discarded; the player can free a berth, buy research capacity, or sell it.
 * Callers already run inside a transaction, so the check and queue creation
 * are atomic with the mission reward or Market purchase that produced it.
 */
function pw_missions_receive_crew(PDO $db, int $userId, int $crewDefinitionId, string $sourceType = 'mission', ?int $sourceId = null): array {
    $capacityReady = pw_mission_crew_capacity_ready($db);
    $definition = $db->prepare(
        'SELECT id, name, role, portrait_url, ' . ($capacityReady ? 'tier' : '"common" AS tier') . ', starting_level
         FROM game_crew_definitions WHERE id = ? AND is_enabled = 1'
    );
    $definition->execute([$crewDefinitionId]);
    $crew = $definition->fetch();
    if (!$crew) throw new RuntimeException('That crew member is no longer available.');
    $award = [
        'id' => (int)$crew['id'], 'name' => (string)$crew['name'], 'role' => (string)$crew['role'],
        'portrait_url' => (string)$crew['portrait_url'], 'tier' => (string)$crew['tier'],
        'sale_credits' => pw_missions_crew_sale_value((string)$crew['tier']),
    ];
    if (!$capacityReady) {
        $grant = $db->prepare('INSERT IGNORE INTO game_player_crew (user_id, crew_definition_id, level, xp, status) VALUES (?, ?, ?, 0, "available")');
        $grant->execute([$userId, $crewDefinitionId, (int)$crew['starting_level']]);
        return ['state' => $grant->rowCount() === 1 ? 'granted' : 'duplicate', 'crew' => $award];
    }

    $owned = $db->prepare('SELECT id FROM game_player_crew WHERE user_id = ? AND crew_definition_id = ? AND status <> "retired" FOR UPDATE');
    $owned->execute([$userId, $crewDefinitionId]);
    if ($owned->fetch()) return ['state' => 'duplicate', 'crew' => $award];

    $roster = $db->prepare(
        'SELECT player_crew.id
         FROM game_player_crew player_crew
         JOIN game_crew_definitions crew ON crew.id = player_crew.crew_definition_id AND crew.is_enabled = 1
         WHERE player_crew.user_id = ? AND player_crew.status <> "retired" FOR UPDATE'
    );
    $roster->execute([$userId]);
    $rosterCount = count($roster->fetchAll());
    $capacity = pw_missions_crew_capacity($db, $userId);
    if ($rosterCount < $capacity) {
        $grant = $db->prepare('INSERT IGNORE INTO game_player_crew (user_id, crew_definition_id, level, xp, status) VALUES (?, ?, ?, 0, "available")');
        $grant->execute([$userId, $crewDefinitionId, (int)$crew['starting_level']]);
        return ['state' => $grant->rowCount() === 1 ? 'granted' : 'duplicate', 'crew' => $award, 'capacity' => $capacity];
    }

    $pending = $db->prepare('SELECT id FROM game_player_crew_offers WHERE user_id = ? AND crew_definition_id = ? AND status = "pending" FOR UPDATE');
    $pending->execute([$userId, $crewDefinitionId]);
    $offerId = $pending->fetchColumn();
    if ($offerId === false) {
        $insert = $db->prepare('INSERT INTO game_player_crew_offers (user_id, crew_definition_id, source_type, source_id, sale_credits, status) VALUES (?, ?, ?, ?, ?, "pending")');
        $insert->execute([$userId, $crewDefinitionId, substr($sourceType, 0, 32), $sourceId, $award['sale_credits']]);
        $offerId = (int)$db->lastInsertId();
    }
    return ['state' => 'pending', 'crew' => array_merge($award, ['offer_id' => (int)$offerId, 'capacity' => $capacity, 'roster_count' => $rosterCount])];
}

/** Whether the sweep-only flag exists yet on loot tables. */
function pw_mission_loot_table_sweep_flag_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    return $ready = pw_schema_has($db, 'game_loot_tables', ['is_sweep_only']);
}

function pw_missions_require_loot_table_gear_ready(PDO $db): void {
    if (!pw_mission_loot_table_gear_ready($db)) {
        pw_error('Gear loot tables are being prepared. Please run the Mission Loot Table Gear migration first.', 503);
    }
}

/**
 * The one query that reads a loot table's entries, with every optional column
 * the migrations may or may not have added.
 *
 * Extracted so the Salvage Sweep draws from the same rows in the same shape a
 * mission does. A second copy of this SELECT is how a table entry ends up
 * meaning one thing on one surface and something else on another -- a stim, for
 * instance, is an ordinary gear entry that only its own columns distinguish.
 *
 * Takes one parameter when executed: the loot table id.
 */
function pw_missions_loot_entry_statement(PDO $db): PDOStatement {
    $gearEnabled = pw_mission_loot_table_gear_ready($db);
    $crewCapacityReady = pw_mission_crew_capacity_ready($db);
    /* A stim entry is an ordinary "gear" table entry that happens to be
     * consumable, so it needs no new entry_type -- only its own columns, so the
     * award is classified into the right inventory ceiling. */
    $stimsReady = pw_mission_stims_ready($db);
    $statement = $gearEnabled
        ? $db->prepare(
            'SELECT entry.entry_type, entry.crew_definition_id, entry.loot_definition_id, entry.chance_percent,
                    crew.name AS crew_name, crew.role, crew.portrait_url, ' . ($crewCapacityReady ? 'crew.tier AS crew_tier,' : '"common" AS crew_tier,') . '
                    gear.name AS gear_name, gear.tier, gear.slot AS gear_slot,
                    gear.bonus_strength AS gear_bonus_strength, gear.bonus_cunning AS gear_bonus_cunning,
                    gear.bonus_science AS gear_bonus_science, gear.bonus_charisma AS gear_bonus_charisma,
                    gear.required_level AS gear_required_level, gear.required_role AS gear_required_role,
                    gear.icon_url AS gear_icon_url'
            . ($stimsReady ? ', gear.stim_effect AS gear_stim_effect, gear.stim_value AS gear_stim_value, gear.stim_duration_seconds AS gear_stim_duration' : '') . '
             FROM game_loot_table_entries entry
             LEFT JOIN game_crew_definitions crew ON crew.id = entry.crew_definition_id
             LEFT JOIN game_loot_definitions gear ON gear.id = entry.loot_definition_id
             WHERE entry.loot_table_id = ? AND (
                (entry.entry_type = "crew" AND crew.is_enabled = 1)
                OR (entry.entry_type = "gear" AND gear.is_enabled = 1)
             )
             ORDER BY entry.sort_order ASC, entry.id ASC'
        )
        : $db->prepare(
            'SELECT entry.entry_type, entry.crew_definition_id, entry.chance_percent,
                    crew.name AS crew_name, crew.role, crew.portrait_url, ' . ($crewCapacityReady ? 'crew.tier AS crew_tier' : '"common" AS crew_tier') . '
             FROM game_loot_table_entries entry
             JOIN game_crew_definitions crew ON crew.id = entry.crew_definition_id
             WHERE entry.loot_table_id = ? AND entry.entry_type = "crew" AND crew.is_enabled = 1
             ORDER BY entry.sort_order ASC, entry.id ASC'
        );
    return $statement;
}

/**
 * Roll every loot table attached to one mission and grant what drops.
 *
 * Called only on a successful claim, inside the claim transaction. A character
 * the player already owns is skipped rather than granted again -- crew is a
 * roster, not a stack -- and is reported separately so the debrief can say the
 * roll hit but changed nothing, instead of silently showing no reward. Gear
 * rolls are returned for claim.php to add to game_player_loot in that same
 * transaction.
 *
 * @return array{granted: array, duplicates: array, pending: array, gear: array}
 */
function pw_missions_roll_loot_tables(PDO $db, int $userId, int $missionDefinitionId): array {
    $result = ['granted' => [], 'duplicates' => [], 'pending' => [], 'gear' => []];
    if (!pw_mission_loot_tables_ready($db)) return $result;

    /* A locked rare table is absent from the roll unless its exact access
     * protocol is owned. The check is server-side and happens at claim time,
     * so changing a response or a mission card in the browser cannot open it. */
    $researchLocksReady = pw_mission_loot_table_research_locks_ready($db);
    $unlockedRareTableIds = [];
    if ($researchLocksReady && function_exists('pw_research_player_effects')) {
        $unlockedRareTableIds = pw_research_player_effects($db, $userId)['rare_loot_table_ids'] ?? [];
        $unlockedRareTableIds = array_values(array_unique(array_filter(array_map('intval', $unlockedRareTableIds), static function ($id) { return $id > 0; })));
    }
    $researchAccessSql = '';
    $researchAccessValues = [];
    if ($researchLocksReady) {
        $researchAccessSql = ' AND (lt.requires_research_unlock = 0';
        if ($unlockedRareTableIds) {
            $researchAccessSql .= ' OR lt.id IN (' . pw_missions_placeholders(count($unlockedRareTableIds)) . ')';
            $researchAccessValues = $unlockedRareTableIds;
        }
        $researchAccessSql .= ')';
    }

    $linkStmt = $db->prepare(
        'SELECT link.loot_table_id, link.chance_percent
         FROM game_mission_loot_tables link
         JOIN game_loot_tables lt ON lt.id = link.loot_table_id
         WHERE link.mission_definition_id = ? AND lt.is_enabled = 1'
         . (pw_mission_loot_table_sweep_flag_ready($db) ? ' AND lt.is_sweep_only = 0' : '')
         . $researchAccessSql . '
         ORDER BY link.sort_order ASC, link.id ASC'
    );
    $linkStmt->execute(array_merge([$missionDefinitionId], $researchAccessValues));
    $links = $linkStmt->fetchAll();
    if (!$links) return $result;

    $stimsReady = pw_mission_stims_ready($db);
    $crewCapacityReady = pw_mission_crew_capacity_ready($db);
    $entryStmt = pw_missions_loot_entry_statement($db);
    // A player's existing roster, read once: the duplicate check runs against
    // every entry of every table and must not become a query per roll.
    $ownedStmt = $db->prepare('SELECT crew_definition_id FROM game_player_crew WHERE user_id = ? AND status <> "retired"');
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
            if (($entry['entry_type'] ?? 'crew') === 'gear') {
                $result['gear'][] = [
                    'id' => (int)$entry['loot_definition_id'],
                    'name' => $entry['gear_name'],
                    'tier' => $entry['tier'],
                    'upgraded' => false,
                    'slot' => (string)($entry['gear_slot'] ?? ''),
                    'icon_url' => pw_missions_gear_icon_url($entry['gear_icon_url'] ?? ''),
                    'required_level' => (int)($entry['gear_required_level'] ?? 1),
                    'required_role' => (string)($entry['gear_required_role'] ?? ''),
                    'stim_effect' => $stimsReady ? (string)($entry['gear_stim_effect'] ?? '') : '',
                    'stim_value' => $stimsReady ? (float)($entry['gear_stim_value'] ?? 0) : 0.0,
                    'stim_duration_seconds' => $stimsReady ? (int)($entry['gear_stim_duration'] ?? 0) : 0,
                    'bonus' => [
                        'strength' => (int)($entry['gear_bonus_strength'] ?? 0),
                        'cunning' => (int)($entry['gear_bonus_cunning'] ?? 0),
                        'science' => (int)($entry['gear_bonus_science'] ?? 0),
                        'charisma' => (int)($entry['gear_bonus_charisma'] ?? 0),
                    ],
                ];
                continue;
            }
            $crewId = (int)$entry['crew_definition_id'];
            $award = ['id' => $crewId, 'name' => $entry['crew_name'], 'role' => $entry['role'], 'portrait_url' => $entry['portrait_url'], 'tier' => (string)($entry['crew_tier'] ?? 'common')];
            /* There is never a second copy of a crew member, so a duplicate
             * award used to be worth nothing at all -- a roll that hit and paid
             * out silence. It converts to credits at the rarity's rate now, and
             * the payout is attached to the award itself so the debrief can say
             * which recruit it stood in for rather than showing a bare sum. */
            $award['duplicate_credits'] = pw_missions_crew_duplicate_credits($award['tier']);
            if (isset($owned[$crewId])) { $result['duplicates'][] = $award; continue; }
            if ($crewCapacityReady) {
                $received = pw_missions_receive_crew($db, $userId, $crewId, 'mission', $missionDefinitionId);
                $award = $received['crew'];
                if ($received['state'] === 'granted') {
                    $owned[$crewId] = true;
                    $result['granted'][] = $award;
                } elseif ($received['state'] === 'pending') {
                    // Held for a berth, not a duplicate: the player still has a
                    // decision to make and pw_missions_crew_sale_value() prices
                    // that one. No duplicate payout here.
                    $result['pending'][] = $award;
                } else {
                    $award['duplicate_credits'] = pw_missions_crew_duplicate_credits($award['tier']);
                    $result['duplicates'][] = $award;
                }
                continue;
            }
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
    return $ready = pw_schema_has($db, 'game_player_daily_progress', ['user_id'])
        && pw_schema_has($db, 'game_player_daily_claims', ['user_id']);
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
    if (pw_mission_crew_capacity_ready($db)) {
        $existing = pw_missions_active_crew_count($db, $userId);
        $capacity = pw_missions_crew_capacity($db, $userId);
        if ($existing >= $capacity) return;
        $starters = $db->prepare('SELECT id FROM game_crew_definitions WHERE is_starter = 1 AND is_enabled = 1 ORDER BY id ASC');
        $starters->execute();
        foreach ($starters->fetchAll(PDO::FETCH_COLUMN) as $crewDefinitionId) {
            if ($existing >= $capacity) break;
            $received = pw_missions_receive_crew($db, $userId, (int)$crewDefinitionId, 'starter');
            if ($received['state'] === 'granted') $existing++;
        }
        return;
    }
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
