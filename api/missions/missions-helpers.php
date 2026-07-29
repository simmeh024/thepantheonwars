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
const PW_MISSION_CONTESTED_PUSH_DURATION_PERCENT = 15;
const PW_MISSION_CONTESTED_PUSH_FATIGUE_PERCENT = 25;
const PW_MISSION_CONTESTED_SAFE_REWARD_PERCENT = 60;
const PW_MISSION_CONTESTED_NARROW_REWARD_PERCENT = 40;
const PW_MISSION_CONTESTED_DECISIVE_XP_PERCENT = 25;

function pw_mission_overlord_contracts_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!pw_missions_ready($db)) return $ready = false;
    return $ready = pw_schema_has($db, 'game_mission_definitions', ['overlord_id']);
}

/* -------------------------------------------------------------------------
 * Overlord standing.
 *
 * Contracts had no destination: a player who ran one every day for a month
 * looked exactly like one who ran their first today. Standing is that record --
 * points earned by completing contracts, climbing a five-stage ladder from
 * Unproven to Chosen.
 *
 * It is stored per Overlord rather than as one figure on the player, so a
 * future change of patron cannot silently erase the service already given to
 * the previous one.
 * ------------------------------------------------------------------------- */

/** The full ladder, and therefore the width of the bar. */
const PW_MISSION_OVERLORD_STANDING_MAX = 500;

function pw_mission_overlord_standing_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!pw_mission_overlord_contracts_ready($db)) return $ready = false;
    if (!pw_schema_has($db, 'game_mission_definitions', ['overlord_standing_reward'])) return $ready = false;
    return $ready = pw_schema_has($db, 'game_player_overlord_standing', ['user_id', 'overlord_id', 'points']);
}

/**
 * The five stages, in order, with the point at which each is reached.
 *
 * The gaps widen deliberately (75 / 100 / 150 / 175): the early stages are
 * there to show the bar responding to a first contract at all, and the last is
 * meant to be a standing worth having rather than a fifth thing that happens on
 * the way past. Chosen sits exactly at the ceiling.
 *
 * The colours are the same ladder the item tiers already use -- neutral for the
 * absence of standing, then blue, green, purple, gold -- so a player who has
 * learned what gold means on a crew card does not have to learn it twice. They
 * are shipped to the browser with the rest of the block rather than restated in
 * CSS, for the same reason the role rates are shipped: a retune applied on one
 * side only would have the bar disagreeing with the award that filled it.
 */
function pw_missions_overlord_standing_stages(): array {
    return [
        ['key' => 'unproven',   'label' => 'Unproven',   'at' => 0,   'color' => '#8fa3b5'],
        ['key' => 'recognized', 'label' => 'Recognized', 'at' => 75,  'color' => '#6fb7d8'],
        ['key' => 'trusted',    'label' => 'Trusted',    'at' => 175, 'color' => '#7ec98a'],
        ['key' => 'favoured',   'label' => 'Favoured',   'at' => 325, 'color' => '#b98cf0'],
        ['key' => 'chosen',     'label' => 'Chosen',     'at' => PW_MISSION_OVERLORD_STANDING_MAX, 'color' => '#e8c46a'],
    ];
}

/**
 * What each rung is worth, per Overlord.
 *
 * The governing decision: every benefit is expressed in the effect vocabulary
 * the research tree already uses, rather than as thirty bespoke mechanics.
 * Those keys are plumbed through the launch projection, the claim payout, the
 * mission card, the Sweep and the Market already, so a standing benefit reaches
 * all of them by being folded into the same array -- the same argument that
 * stopped stims needing their own pipeline. A benefit that needed its own
 * branch in six files would be a benefit that silently missed one of them.
 *
 * Effects are cumulative up the ladder: reaching Trusted keeps what Recognized
 * gave. Each patron's line follows their own character rather than being the
 * same bonus in six colours --
 *   Syn Dravus     knowledge   -> what you find, and what you can see coming
 *   Malric Thorne  control     -> endurance and resilience; nothing slips
 *   Korrus Vale    efficiency  -> the schedule, and room to hold the results
 *   Lysara Venthe  care        -> crew recovery and the kit to sustain it
 *   Zura Kaleth    patience    -> growth, in crew and in roster size
 *   Maerion Thal   reputation  -> standing and the terms you trade on
 *
 * Unproven is deliberately empty. The bottom rung has to mean "not yet", or the
 * ladder starts halfway up and the first climb is worth nothing.
 */
function pw_missions_overlord_standing_benefits(): array {
    return [
        'syn-dravus' => [
            ['title' => 'Unread', 'copy' => 'The Mindweaver has not yet read you. Run his contracts.', 'effects' => []],
            ['title' => 'Recognized', 'copy' => 'What comes back from an operation is worth more.', 'effects' => ['luck_percent' => 5.0]],
            ['title' => 'Trusted', 'copy' => 'His survey data reaches your salvage fields.', 'effects' => ['luck_percent' => 5.0, 'sweep_survey_percent' => 25.0]],
            ['title' => 'Favoured', 'copy' => 'You are told what is worth recovering before you go.', 'effects' => ['luck_percent' => 15.0, 'sweep_survey_percent' => 25.0]],
            ['title' => 'Chosen', 'copy' => 'You see a field the way he does.', 'effects' => ['luck_percent' => 25.0, 'sweep_survey_percent' => 40.0, 'sweep_recognition_percent' => 20.0]],
        ],
        'malric-thorne' => [
            ['title' => 'Unsworn', 'copy' => 'The Black Regent does not yet count you among his. Run his contracts.', 'effects' => []],
            ['title' => 'Recognized', 'copy' => 'Work done in his name is reported upward.', 'effects' => ['reputation_percent' => 10.0]],
            ['title' => 'Trusted', 'copy' => 'His people are not spent carelessly. Crew endure more.', 'effects' => ['reputation_percent' => 10.0, 'crew_fatigue' => 30]],
            ['title' => 'Favoured', 'copy' => 'A field that turns on you finds your crew braced for it.', 'effects' => ['reputation_percent' => 18.0, 'crew_fatigue' => 50, 'sweep_brace_percent' => 20.0]],
            ['title' => 'Chosen', 'copy' => 'Order holds wherever you are standing.', 'effects' => ['reputation_percent' => 30.0, 'crew_fatigue' => 80, 'sweep_brace_percent' => 35.0, 'sweep_stabiliser_points' => 2]],
        ],
        'korrus-vale' => [
            ['title' => 'Unrated', 'copy' => 'The Reactor King has no figures on you yet. Run his contracts.', 'effects' => []],
            ['title' => 'Recognized', 'copy' => 'Your operations are scheduled tighter.', 'effects' => ['mission_speed_percent' => 8.0]],
            ['title' => 'Trusted', 'copy' => 'Crew are cycled back to the line faster.', 'effects' => ['mission_speed_percent' => 8.0, 'fatigue_recovery_percent' => 30.0]],
            ['title' => 'Favoured', 'copy' => 'His depots are opened to you. Hold far more.', 'effects' => ['mission_speed_percent' => 14.0, 'fatigue_recovery_percent' => 40.0, 'inventory_capacity' => 50]],
            ['title' => 'Chosen', 'copy' => 'The system runs at your pace. That is the point.', 'effects' => ['mission_speed_percent' => 22.0, 'fatigue_recovery_percent' => 60.0, 'inventory_capacity' => 100]],
        ],
        'lysara-venthe' => [
            ['title' => 'Adrift', 'copy' => 'The Tidekeeper has not yet taken you in. Run her contracts.', 'effects' => []],
            ['title' => 'Recognized', 'copy' => 'Your crew rest better between operations.', 'effects' => ['fatigue_recovery_percent' => 20.0]],
            ['title' => 'Trusted', 'copy' => 'They are sent out with more in reserve.', 'effects' => ['fatigue_recovery_percent' => 20.0, 'crew_fatigue' => 30]],
            ['title' => 'Favoured', 'copy' => 'Her dispensary is yours. Two more slots on the belt.', 'effects' => ['fatigue_recovery_percent' => 35.0, 'crew_fatigue' => 50, 'stim_slots' => 2]],
            ['title' => 'Chosen', 'copy' => 'Nothing under your command is left where it fell.', 'effects' => ['fatigue_recovery_percent' => 60.0, 'crew_fatigue' => 80, 'stim_slots' => 3, 'sweep_tether_percent' => 25.0]],
        ],
        'zura-kaleth' => [
            ['title' => 'Unrooted', 'copy' => 'The Rootbinder is waiting to see what you become. Run her contracts.', 'effects' => []],
            ['title' => 'Recognized', 'copy' => 'Your crew learn faster from what they survive.', 'effects' => ['xp_percent' => 15.0]],
            ['title' => 'Trusted', 'copy' => 'A field gives up one more scan to patient hands.', 'effects' => ['xp_percent' => 15.0, 'sweep_scans' => 1]],
            ['title' => 'Favoured', 'copy' => 'Room for two more to grow under you.', 'effects' => ['xp_percent' => 25.0, 'sweep_scans' => 1, 'crew_capacity' => 2]],
            ['title' => 'Chosen', 'copy' => 'Nothing near you stays the same for long.', 'effects' => ['xp_percent' => 40.0, 'sweep_scans' => 2, 'crew_capacity' => 4, 'sweep_momentum_percent' => 10.0]],
        ],
        'maerion-thal' => [
            ['title' => 'Unnamed', 'copy' => 'The Sky Duke does not yet know your name. Run his contracts.', 'effects' => []],
            ['title' => 'Recognized', 'copy' => 'Your contracts are settled at a better rate.', 'effects' => ['credit_percent' => 20.0]],
            ['title' => 'Trusted', 'copy' => 'The market opens its books to you more often.', 'effects' => ['credit_percent' => 20.0, 'market_refresh_percent' => 50.0]],
            ['title' => 'Favoured', 'copy' => 'You trade on his terms, not the floor’s.', 'effects' => ['credit_percent' => 30.0, 'market_refresh_percent' => 50.0, 'market_discount_percent' => 20.0]],
            ['title' => 'Chosen', 'copy' => 'Your word carries as far as his does.', 'effects' => ['credit_percent' => 40.0, 'market_refresh_percent' => 50.0, 'market_discount_percent' => 30.0, 'reputation_percent' => 25.0]],
        ],
    ];
}

/**
 * Human wording for one effect contribution, used to list what a rung grants.
 *
 * Derived from the effect keys rather than authored a second time beside them:
 * a benefit whose sentence is written separately from its numbers is a benefit
 * whose sentence eventually stops being true.
 */
function pw_missions_overlord_standing_effect_labels(array $effects): array {
    $labels = [
        'mission_speed_percent' => ['%s%% faster operations', true],
        'xp_percent' => ['+%s%% crew XP', true],
        'reputation_percent' => ['+%s%% reputation', true],
        'credit_percent' => ['+%s%% credits', true],
        'luck_percent' => ['+%s%% loot quality', true],
        'fatigue_recovery_percent' => ['+%s%% crew recovery', true],
        'market_discount_percent' => ['%s%% off market prices', true],
        'market_refresh_percent' => ['+%s%% market refresh', true],
        'sweep_survey_percent' => ['+%s%% sweep survey', true],
        'sweep_brace_percent' => ['+%s%% sweep brace', true],
        'sweep_recognition_percent' => ['+%s%% sweep recognition', true],
        'sweep_momentum_percent' => ['+%s%% sweep momentum', true],
        'sweep_tether_percent' => ['+%s%% sweep tether', true],
        'crew_fatigue' => ['+%s crew fatigue ceiling', false],
        'crew_capacity' => ['+%s crew berths', false],
        'inventory_capacity' => ['+%s inventory capacity', false],
        'stim_slots' => ['+%s stim slots', false],
        'sweep_scans' => ['+%s sweep scan', false],
        'sweep_stabiliser_points' => ['+%s sweep stabiliser', false],
    ];
    $out = [];
    foreach ($effects as $key => $value) {
        if (!isset($labels[$key]) || $value <= 0) continue;
        [$format, $isPercent] = $labels[$key];
        $number = $isPercent ? rtrim(rtrim(number_format((float)$value, 1, '.', ''), '0'), '.') : (string)(int)$value;
        $out[] = sprintf($format, $number);
    }
    return $out;
}

/**
 * The effect deltas the player's current standing grants, ready to fold.
 *
 * Returns the reached stage's own totals rather than a sum across stages: the
 * table above is written cumulatively, so summing it would pay every earlier
 * rung a second time.
 */
function pw_missions_overlord_standing_effects(PDO $db, int $userId, ?array $overlord = null): array {
    if (!pw_mission_overlord_standing_ready($db)) return [];
    try {
        if ($overlord === null) {
            $stmt = $db->prepare('SELECT overlord_affinity FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $overlord = pw_missions_overlord_affinity($db, (string)($stmt->fetchColumn() ?: ''));
        }
        if ($overlord === null) return [];
        $table = pw_missions_overlord_standing_benefits();
        $rungs = $table[(string)$overlord['slug']] ?? null;
        if ($rungs === null) return [];
        $read = $db->prepare('SELECT points FROM game_player_overlord_standing WHERE user_id = ? AND overlord_id = ?');
        $read->execute([$userId, (int)$overlord['id']]);
        $points = (int)($read->fetchColumn() ?: 0);
        $index = pw_missions_overlord_standing_stage_index($points);
        return $rungs[$index]['effects'] ?? [];
    } catch (Throwable $e) {
        /* A standing that cannot be read grants nothing rather than taking the
         * whole effects pass -- and therefore every mission page -- down. */
        return [];
    }
}

/**
 * Add the standing deltas onto an effects array.
 *
 * Only keys the array already defines are touched, so a benefit naming an
 * effect this install's research tree does not have cannot introduce a stray
 * key that a consumer would later read as a number and find an array.
 */
function pw_missions_apply_overlord_standing_effects(PDO $db, int $userId, array $effects): array {
    foreach (pw_missions_overlord_standing_effects($db, $userId) as $key => $value) {
        if (!array_key_exists($key, $effects) || !is_numeric($effects[$key])) continue;
        $effects[$key] = is_int($effects[$key])
            ? $effects[$key] + (int)$value
            : $effects[$key] + (float)$value;
    }
    return $effects;
}

/** Index of the highest stage whose threshold the points have reached. */
function pw_missions_overlord_standing_stage_index(int $points): int {
    $index = 0;
    foreach (pw_missions_overlord_standing_stages() as $position => $stage) {
        if ($points >= (int)$stage['at']) $index = $position;
    }
    return $index;
}

/**
 * One player's standing with one Overlord, resolved for display.
 *
 * Always returns a block rather than null so the card can draw an empty bar:
 * a player who has never run a contract still has a standing, and it is the
 * first rung. A missing migration is the one case that returns not-ready, and
 * the card then hides the bar entirely rather than showing a zero that is
 * really an unknown.
 */
function pw_missions_overlord_standing(PDO $db, int $userId, int $overlordId, string $overlordSlug = ''): array {
    $stages = pw_missions_overlord_standing_rungs($overlordSlug);
    $block = [
        'ready' => pw_mission_overlord_standing_ready($db),
        'points' => 0,
        'max' => PW_MISSION_OVERLORD_STANDING_MAX,
        'stages' => $stages,
        'stage_index' => 0,
        'stage' => $stages[0],
        'next_stage' => $stages[1],
        'points_to_next' => (int)$stages[1]['at'],
    ];
    if (!$block['ready'] || $overlordId < 1) return $block;

    try {
        $stmt = $db->prepare('SELECT points FROM game_player_overlord_standing WHERE user_id = ? AND overlord_id = ?');
        $stmt->execute([$userId, $overlordId]);
        $points = (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        /* An unreadable standing is reported as unavailable rather than as
         * zero. A bar that has quietly lost a month of service is worse than a
         * bar that is briefly absent. */
        $block['ready'] = false;
        return $block;
    }

    return pw_missions_overlord_standing_view($points, $overlordSlug) + ['ready' => true, 'stages' => $stages];
}

/**
 * The stage table with this Overlord's own rung titles and benefit lines
 * merged onto it, so the card can name what each rung gives without holding a
 * second copy of the table. An unknown slug falls back to the plain ladder --
 * a seventh Overlord added in Overlord Control gets the standing bar and no
 * benefits rather than no bar at all.
 */
function pw_missions_overlord_standing_rungs(string $overlordSlug): array {
    $stages = pw_missions_overlord_standing_stages();
    $rungs = pw_missions_overlord_standing_benefits()[$overlordSlug] ?? null;
    foreach ($stages as $index => $stage) {
        $rung = $rungs[$index] ?? null;
        $stages[$index]['title'] = $rung['title'] ?? $stage['label'];
        $stages[$index]['copy'] = $rung['copy'] ?? '';
        $stages[$index]['grants'] = $rung === null ? [] : pw_missions_overlord_standing_effect_labels($rung['effects']);
    }
    return $stages;
}

/** The derived half of the block above, shared with the claim response. */
function pw_missions_overlord_standing_view(int $points, string $overlordSlug = ''): array {
    $stages = pw_missions_overlord_standing_rungs($overlordSlug);
    $points = max(0, min(PW_MISSION_OVERLORD_STANDING_MAX, $points));
    $index = pw_missions_overlord_standing_stage_index($points);
    $next = $stages[$index + 1] ?? null;
    return [
        'points' => $points,
        'max' => PW_MISSION_OVERLORD_STANDING_MAX,
        'stage_index' => $index,
        'stage' => $stages[$index],
        'next_stage' => $next,
        'points_to_next' => $next === null ? 0 : max(0, (int)$next['at'] - $points),
    ];
}

/**
 * Add standing, clamped to the ceiling, and report what moved.
 *
 * The clamp is applied to the stored total rather than to the award, so a
 * contract paying 40 into a standing of 480 grants 20 and says so -- the
 * alternative is a debrief promising 40 points that the bar does not show.
 *
 * Returns null when there is nothing to record, so a caller can skip the whole
 * block rather than reporting an award of zero.
 */
function pw_missions_award_overlord_standing(PDO $db, int $userId, int $overlordId, int $amount): ?array {
    if (!pw_mission_overlord_standing_ready($db) || $overlordId < 1 || $amount < 1) return null;
    try {
        /* Resolved here rather than passed in: the caller has a mission row,
         * which carries the Overlord's id and not its slug, and the debrief
         * wants the patron's own name for the rung ("Trusted" is the ladder,
         * "Unsworn" is what Malric Thorne calls the bottom of it). */
        $slugStmt = $db->prepare('SELECT slug FROM overlords WHERE id = ?');
        $slugStmt->execute([$overlordId]);
        $slug = (string)($slugStmt->fetchColumn() ?: '');
        $read = $db->prepare('SELECT points FROM game_player_overlord_standing WHERE user_id = ? AND overlord_id = ? FOR UPDATE');
        $read->execute([$userId, $overlordId]);
        $before = (int)($read->fetchColumn() ?: 0);
        $after = max(0, min(PW_MISSION_OVERLORD_STANDING_MAX, $before + $amount));
        if ($after === $before) {
            /* Already at the ceiling. Still reported, so the debrief can say the
             * standing is full instead of silently paying nothing. */
            return ['awarded' => 0, 'before' => pw_missions_overlord_standing_view($before, $slug), 'after' => pw_missions_overlord_standing_view($after, $slug)];
        }
        $write = $db->prepare(
            'INSERT INTO game_player_overlord_standing (user_id, overlord_id, points) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE points = VALUES(points)'
        );
        $write->execute([$userId, $overlordId, $after]);
        return ['awarded' => $after - $before, 'before' => pw_missions_overlord_standing_view($before, $slug), 'after' => pw_missions_overlord_standing_view($after, $slug)];
    } catch (Throwable $e) {
        /* Standing is a record of work already rewarded elsewhere, so a failure
         * here must never take the rest of the claim down with it. */
        return null;
    }
}

/**
 * Contested contracts extend daily Overlord work with a rival recovery team.
 * They deliberately remain additive: before the migration, a contract is the
 * exact same normal contract it was before, rather than a partially-rendered
 * race that can neither be configured nor settled.
 */
function pw_mission_contested_contracts_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!pw_mission_overlord_contracts_ready($db)) return $ready = false;
    return $ready = pw_schema_has($db, 'game_mission_definitions', ['is_contested', 'rival_faction_name'])
        && pw_schema_has($db, 'game_player_missions', [
            'is_contested', 'rival_faction_name', 'rival_approach',
            'rival_completes_at', 'rival_outcome', 'rival_bonus_credits',
        ]);
}

/*
 * Salvage recovery contracts are regular, administrator-authored missions that
 * are withheld from the ordinary board. A Sweep collapse can issue one of the
 * checked definitions to recover a specific rare-or-better item from the lost
 * field. The offer table is deliberately separate from player missions: the
 * item has to survive until claim time, and a player must never be able to
 * launch a pool definition merely by learning its id.
 */
function pw_mission_salvage_recovery_contracts_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!pw_mission_overlord_contracts_ready($db) || !pw_mission_gear_ready($db)) return $ready = false;
    return $ready = pw_schema_has($db, 'game_mission_definitions', ['is_salvage_recovery_contract'])
        && pw_schema_has($db, 'game_player_salvage_recovery_contracts', [
            'user_id', 'source_sweep_run_id', 'source_sweep_find_id',
            'mission_definition_id', 'loot_definition_id', 'issued_date',
            'player_mission_id', 'status',
        ]);
}

/** An Overlord's daily contract is preceded by a small, persistent access puzzle. */
function pw_mission_overlord_clearances_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!pw_mission_overlord_contracts_ready($db)) return $ready = false;
    return $ready = pw_schema_has($db, 'game_mission_definitions', ['requires_overlord_clearance'])
        && pw_schema_has($db, 'game_player_overlord_contract_clearances', [
        'user_id', 'mission_definition_id', 'issued_date', 'collapse_index', 'safe_picks', 'status',
    ]);
}

function pw_missions_overlord_clearance_picks($raw): array {
    $picks = [];
    foreach (explode(',', trim((string)$raw)) as $value) {
        if ($value === '' || !ctype_digit($value)) continue;
        $cell = (int)$value;
        if ($cell >= 0 && $cell < 4) $picks[$cell] = true;
    }
    return array_keys($picks);
}

/** Never disclose the collapse cell while an access tile is still live. */
function pw_missions_overlord_clearance_public(?array $row, bool $ready): array {
    $status = $row ? (string)$row['status'] : 'blocked';
    $safePicks = $row ? pw_missions_overlord_clearance_picks($row['safe_picks'] ?? '') : [];
    $payload = [
        'ready' => $ready,
        'status' => in_array($status, ['blocked', 'cleared', 'collapsed'], true) ? $status : 'blocked',
        'safe_picks' => $safePicks,
        'required_safe_picks' => 2,
    ];
    if ($row && $status === 'collapsed') $payload['collapse_index'] = (int)$row['collapse_index'];
    return $payload;
}

function pw_missions_overlord_clearance_state(PDO $db, int $userId, int $missionId): array {
    $ready = pw_mission_overlord_clearances_ready($db);
    if (!$ready) return pw_missions_overlord_clearance_public(null, false);
    $stmt = $db->prepare(
        'SELECT collapse_index, safe_picks, status
         FROM game_player_overlord_contract_clearances
         WHERE user_id = ? AND mission_definition_id = ? AND issued_date = UTC_DATE()'
    );
    $stmt->execute([$userId, $missionId]);
    return pw_missions_overlord_clearance_public($stmt->fetch() ?: null, true);
}

/** The currently actionable recovery lead, if a Sweep collapse issued one. */
function pw_missions_salvage_recovery_contract(PDO $db, int $userId): array {
    $state = ['ready' => pw_mission_salvage_recovery_contracts_ready($db), 'contract' => null, 'lost_item' => null];
    if (!$state['ready']) return $state;
    $stmt = $db->prepare(
        'SELECT offer.id AS recovery_contract_id, offer.issued_date, offer.mission_definition_id,
                offer.loot_definition_id, mission.*, loot.name AS lost_item_name,
                LOWER(loot.tier) AS lost_item_tier, loot.icon_url AS lost_item_icon_url
         FROM game_player_salvage_recovery_contracts offer
         JOIN game_mission_definitions mission ON mission.id = offer.mission_definition_id
         LEFT JOIN game_loot_definitions loot ON loot.id = offer.loot_definition_id
         WHERE offer.user_id = ? AND offer.status = "available"
         ORDER BY offer.id DESC LIMIT 1'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) return $state;
    $state['contract'] = $row;
    $state['lost_item'] = [
        'id' => (int)$row['loot_definition_id'],
        'name' => (string)($row['lost_item_name'] ?? 'Important recovery'),
        'tier' => strtolower((string)($row['lost_item_tier'] ?? 'rare')),
        'icon_url' => pw_missions_gear_icon_url($row['lost_item_icon_url'] ?? ''),
    ];
    return $state;
}

/**
 * Issue at most one actionable lead on a UTC date. This is called inside the
 * Sweep collapse transaction after the emergency tether has had its chance.
 */
function pw_missions_issue_salvage_recovery_contract(PDO $db, int $userId, int $runId, ?array $tether): ?array {
    if (!pw_mission_salvage_recovery_contracts_ready($db)) return null;
    /* Do not stack private leads behind one another. Besides keeping the card
     * honest, this means a player can never have an older lost item silently
     * displaced by a newer collapse before deciding whether to pursue it. */
    $open = $db->prepare(
        'SELECT id FROM game_player_salvage_recovery_contracts
         WHERE user_id = ? AND status IN ("available", "active") LIMIT 1 FOR UPDATE'
    );
    $open->execute([$userId]);
    if ($open->fetch()) return null;
    $lost = $db->prepare(
        'SELECT find.id, find.cell_index, find.loot_definition_id, loot.name, LOWER(loot.tier) AS tier, loot.icon_url
         FROM game_player_sweep_finds find
         JOIN game_loot_definitions loot ON loot.id = find.loot_definition_id
         WHERE find.run_id = ? AND LOWER(loot.tier) IN ("rare", "epic", "legendary")
         ORDER BY find.id ASC'
    );
    $lost->execute([$runId]);
    $candidates = array_values(array_filter($lost->fetchAll(), static function (array $find) use ($tether): bool {
        return !($tether
            && ($tether['kind'] ?? '') === 'gear'
            && ($tether['state'] ?? '') !== 'no_room'
            && (int)($tether['cell_index'] ?? -1) === (int)$find['cell_index']);
    }));
    if (!$candidates) return null;

    $pool = $db->query(
        'SELECT id FROM game_mission_definitions
         WHERE is_enabled = 1 AND is_salvage_recovery_contract = 1 AND overlord_id IS NULL
         ORDER BY sort_order ASC, id ASC'
    )->fetchAll();
    if (!$pool) return null;
    $find = $candidates[random_int(0, count($candidates) - 1)];
    $mission = $pool[random_int(0, count($pool) - 1)];
    /* The unique user/date key is the concurrency guard. INSERT IGNORE makes a
     * simultaneous collapse harmless: only its first issued lead is retained. */
    $issue = $db->prepare(
        'INSERT IGNORE INTO game_player_salvage_recovery_contracts
         (user_id, source_sweep_run_id, source_sweep_find_id, mission_definition_id, loot_definition_id, issued_date, status)
         VALUES (?, ?, ?, ?, ?, UTC_DATE(), "available")'
    );
    $issue->execute([$userId, $runId, (int)$find['id'], (int)$mission['id'], (int)$find['loot_definition_id']]);
    if ($issue->rowCount() !== 1) return null;
    return [
        'mission_id' => (int)$mission['id'],
        'lost_item' => [
            'id' => (int)$find['loot_definition_id'], 'name' => (string)$find['name'],
            'tier' => (string)$find['tier'], 'icon_url' => pw_missions_gear_icon_url($find['icon_url'] ?? ''),
        ],
    ];
}

function pw_missions_contested_contract_is_enabled(array $mission, bool $ready): bool {
    return $ready && !empty($mission['is_contested'])
        && isset($mission['overlord_id']) && $mission['overlord_id'] !== null
        && (int)$mission['overlord_id'] > 0;
}

function pw_missions_contested_contract_faction($value): string {
    $name = trim((string)$value);
    return $name !== '' ? $name : 'Independent recovery team';
}

function pw_missions_contested_contract_approach($value): ?string {
    $approach = strtolower(trim((string)$value));
    return in_array($approach, ['push', 'secure', 'safe'], true) ? $approach : null;
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
function pw_missions_overlord_contract_daily_block(PDO $db, int $userId, array $mission, int $rank, ?array $overlord): ?string {
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

function pw_missions_overlord_contract_block(PDO $db, int $userId, array $mission, int $rank, ?array $overlord): ?string {
    $block = pw_missions_overlord_contract_daily_block($db, $userId, $mission, $rank, $overlord);
    if ($block !== null) return $block;
    /* A blocked tile is an authored complication, not a tax on every daily
     * contract. Definitions created before this option intentionally stay
     * clear, and the admin can opt an individual Overlord operation in. */
    if (empty($mission['requires_overlord_clearance'])) return null;
    if (!pw_mission_overlord_clearances_ready($db)) return null;
    $clearance = pw_missions_overlord_clearance_state($db, $userId, (int)$mission['id']);
    if ($clearance['status'] === 'cleared') return null;
    if ($clearance['status'] === 'collapsed') {
        return 'The blocked access tile collapsed. This Overlord contract is unavailable until the next UTC reset.';
    }
    return 'Clear the blocked access tile before accepting this Overlord contract.';
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
 * Item levels are additive content metadata layered on top of normal gear.
 * Keep this readiness separate from pw_mission_gear_ready(): a deploy that
 * arrives before its manual migration must leave existing equipment usable,
 * merely without iLvl readouts, rather than taking the whole loadout system
 * offline.
 */
function pw_mission_item_levels_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    return $ready = pw_mission_gear_ready($db)
        && pw_schema_has($db, 'game_loot_definitions', ['item_level']);
}

/* Field Grade is a small hidden reliability value authored only in Mission
 * Control. Readiness stays separate so a code deploy that arrives before its
 * migration leaves every existing mission calculation unchanged. */
const PW_MISSION_FIELD_GRADE_SUCCESS_PER_POINT = 0.10;

function pw_mission_field_grade_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    return $ready = pw_mission_item_levels_ready($db)
        && pw_schema_has($db, 'game_loot_definitions', ['field_grade']);
}

/* Contract progression turns a mission's item table into a deliberate next
 * step. It remains additive: before the manual migration is applied, missions
 * continue to read every configured reward exactly as they always have. */
const PW_MISSION_MAX_CONTRACT_TIER = 10;
const PW_MISSION_FEATURED_SLOT_CHANCE_MULTIPLIER = 2.0;

function pw_mission_contract_progression_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    return $ready = pw_mission_item_levels_ready($db)
        && pw_schema_has($db, 'game_mission_definitions', [
            'contract_tier', 'recommended_item_level',
            'reward_item_level_min', 'reward_item_level_max', 'featured_slots',
        ]);
}

/** Decode the small, closed featured-slot list stored on a mission definition. */
function pw_missions_featured_slots($raw): array {
    $values = is_array($raw) ? $raw : explode(',', (string)$raw);
    $slots = pw_missions_gear_slots();
    $result = [];
    foreach ($values as $value) {
        $slot = strtolower(trim((string)$value));
        if ($slot !== '' && isset($slots[$slot])) $result[$slot] = true;
    }
    return array_keys($result);
}

/** One normalized shape for the live reward paths and both admin readers. */
function pw_missions_contract_progression(array $mission): array {
    $minimum = max(0, min(9999, (int)($mission['reward_item_level_min'] ?? 0)));
    $maximum = max(0, min(9999, (int)($mission['reward_item_level_max'] ?? 0)));
    if ($minimum > 0 && $maximum > 0 && $maximum < $minimum) $maximum = $minimum;
    return [
        'tier' => max(1, min(PW_MISSION_MAX_CONTRACT_TIER, (int)($mission['contract_tier'] ?? 1))),
        'recommended_item_level' => max(0, min(9999, (int)($mission['recommended_item_level'] ?? 0))),
        'reward_item_level_min' => $minimum,
        'reward_item_level_max' => $maximum,
        'featured_slots' => pw_missions_featured_slots($mission['featured_slots'] ?? ''),
    ];
}

/** Progression bands govern wearable gear only; salvage, stims and crew stay authored per table. */
function pw_missions_contract_gear_is_eligible(array $gear, array $progression): bool {
    $slot = strtolower(trim((string)($gear['slot'] ?? $gear['gear_slot'] ?? '')));
    if ($slot === '') return true;
    $itemLevel = max(0, (int)($gear['item_level'] ?? $gear['gear_item_level'] ?? 0));
    $minimum = (int)($progression['reward_item_level_min'] ?? 0);
    $maximum = (int)($progression['reward_item_level_max'] ?? 0);
    return !($minimum > 0 && $itemLevel < $minimum)
        && !($maximum > 0 && $itemLevel > $maximum);
}

function pw_missions_contract_gear_is_featured(array $gear, array $progression): bool {
    $slot = strtolower(trim((string)($gear['slot'] ?? $gear['gear_slot'] ?? '')));
    return $slot !== '' && in_array($slot, $progression['featured_slots'] ?? [], true);
}

/** The same multiplier is used for weighted world-pool loot and independent table rolls. */
function pw_missions_contract_gear_chance(float $chance, array $gear, array $progression): float {
    if (pw_missions_contract_gear_is_featured($gear, $progression)) {
        $chance *= PW_MISSION_FEATURED_SLOT_CHANCE_MULTIPLIER;
    }
    return min(100.0, max(0.0, $chance));
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
    $itemLevelColumn = pw_mission_item_levels_ready($db) ? ', l.item_level' : ', 0 AS item_level';
    $fieldGradeColumn = pw_mission_field_grade_ready($db) ? ', l.field_grade' : ', 0 AS field_grade';
    $stmt = $db->prepare(
        'SELECT g.player_crew_id, g.slot, g.loot_definition_id,
                l.name, l.slug, l.tier, l.description, l.icon_url,
                l.bonus_strength, l.bonus_cunning, l.bonus_science, l.bonus_charisma,
                l.required_level, l.required_role' . $itemLevelColumn . $fieldGradeColumn . '
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
            'item_level' => max(0, (int)$row['item_level']),
            'field_grade' => max(0, (int)$row['field_grade']),
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
 * The published equipment ceiling for every role. The target intentionally
 * ignores required_level: an item's level gate controls when it can be worn,
 * but it must not make a low-level crew member look endgame-maxed. Generic gear
 * contributes to every role; a role-locked piece contributes only to its role.
 *
 * This is shared by the crew display and Game Tuning so both surfaces answer
 * the same question when an administrator compares the role ladders.
 */
function pw_missions_item_level_role_ceilings(PDO $db): array {
    $slots = pw_missions_gear_slots();
    $slotKeys = array_keys($slots);
    $ready = pw_mission_item_levels_ready($db);
    $roles = array_keys(pw_missions_role_rates());
    $result = [
        'ready' => $ready,
        'slot_count' => count($slotKeys),
        'roles' => [],
        'leader_total' => 0,
        'leader_average' => 0.0,
    ];
    foreach ($roles as $role) {
        $result['roles'][$role] = [
            'role' => $role,
            'slots' => array_fill_keys($slotKeys, 0),
            'total' => 0,
            'average' => 0.0,
            'slots_covered' => 0,
        ];
    }
    if (!$ready) return $result;

    $items = $db->query(
        'SELECT slot, item_level, required_role
         FROM game_loot_definitions
         WHERE is_enabled = 1 AND slot <> "" AND item_level > 0'
    )->fetchAll();
    foreach ($items as $item) {
        $slot = (string)$item['slot'];
        $itemLevel = max(0, (int)$item['item_level']);
        $requiredRole = trim((string)($item['required_role'] ?? ''));
        if (!isset($slots[$slot]) || $itemLevel < 1) continue;
        foreach ($roles as $role) {
            if ($requiredRole !== '' && strcasecmp($requiredRole, $role) !== 0) continue;
            $result['roles'][$role]['slots'][$slot] = max($result['roles'][$role]['slots'][$slot], $itemLevel);
        }
    }
    foreach ($roles as $role) {
        $total = 0;
        $covered = 0;
        foreach ($slotKeys as $slot) {
            $level = (int)$result['roles'][$role]['slots'][$slot];
            $total += $level;
            if ($level > 0) $covered++;
        }
        $result['roles'][$role]['total'] = $total;
        $result['roles'][$role]['average'] = round($total / max(1, count($slotKeys)), 1);
        $result['roles'][$role]['slots_covered'] = $covered;
        $result['leader_total'] = max($result['leader_total'], $total);
    }
    $result['leader_average'] = round($result['leader_total'] / max(1, count($slotKeys)), 1);
    return $result;
}

/**
 * Add display-only iLvl progress to an already-equipped roster. The average is
 * always the equipped total divided by all seven slots, so an empty slot is a
 * visible gap instead of disappearing from the denominator. The ceiling is the
 * full enabled catalogue for the crew's role, even when a higher-level piece
 * cannot yet be equipped: progression should not be labelled as complete just
 * because the next release is still level-gated.
 */
function pw_missions_apply_item_levels(PDO $db, array $crewRows): array {
    $slots = pw_missions_gear_slots();
    $slotKeys = array_keys($slots);
    $denominator = max(1, count($slotKeys));
    $catalogue = pw_missions_item_level_role_ceilings($db);
    $ready = !empty($catalogue['ready']);

    if (!$ready) {
        foreach ($crewRows as $index => $row) {
            $crewRows[$index]['item_level_ready'] = false;
            $crewRows[$index]['item_level_total'] = 0;
            $crewRows[$index]['item_level_average'] = 0.0;
            $crewRows[$index]['item_level_max_total'] = 0;
            $crewRows[$index]['item_level_max_average'] = 0.0;
            $crewRows[$index]['item_level_maxed'] = false;
            $crewRows[$index]['item_level_catalogue_slots'] = 0;
            $crewRows[$index]['item_level_slots_at_max'] = 0;
        }
        return $crewRows;
    }

    foreach ($crewRows as $index => $row) {
        $role = (string)($row['role'] ?? '');
        $roleCeiling = $catalogue['roles'][$role] ?? ['slots' => []];
        $equipped = is_array($row['gear'] ?? null) ? $row['gear'] : [];
        $currentTotal = 0;
        foreach ($equipped as $item) $currentTotal += max(0, (int)($item['item_level'] ?? 0));

        $maxTotal = 0;
        $catalogueSlots = 0;
        $slotsAtMax = 0;
        foreach ($slotKeys as $slot) {
            $slotMax = max(0, (int)($roleCeiling['slots'][$slot] ?? 0));
            if ($slotMax < 1) continue;
            $catalogueSlots++;
            $maxTotal += $slotMax;
            if (max(0, (int)($equipped[$slot]['item_level'] ?? 0)) >= $slotMax) $slotsAtMax++;
        }

        $crewRows[$index]['item_level_ready'] = true;
        $crewRows[$index]['item_level_total'] = $currentTotal;
        $crewRows[$index]['item_level_average'] = round($currentTotal / $denominator, 1);
        $crewRows[$index]['item_level_max_total'] = $maxTotal;
        $crewRows[$index]['item_level_max_average'] = round($maxTotal / $denominator, 1);
        $crewRows[$index]['item_level_catalogue_slots'] = $catalogueSlots;
        $crewRows[$index]['item_level_slots_at_max'] = $slotsAtMax;
        /* A crew is legendary only when every enabled role ceiling is equipped.
         * Missing catalogue slots remain zero in the fixed seven-slot average
         * but do not make a release with fewer authored slots impossible to
         * complete. Required level gates are deliberately not a shortcut to
         * this state -- they are the visible next step in the progression. */
        $crewRows[$index]['item_level_maxed'] = $catalogueSlots > 0 && $slotsAtMax === $catalogueSlots && $currentTotal >= $maxTotal;
    }
    return $crewRows;
}

/**
 * The account-wide counterpart to a crew card's average iLvl. Every active
 * crew member contributes all seven equipment slots, including empty ones, so
 * recruiting a crew member creates a real new progression lane instead of
 * inflating a commander's power by silently omitting their gaps.
 */
function pw_missions_crew_power_empty(): array {
    return [
        'ready' => false,
        'crew_count' => 0,
        'item_level_total' => 0,
        'item_level_average' => 0.0,
        'item_level_max_total' => 0,
        'item_level_max_average' => 0.0,
        'progress_percent' => 0.0,
        'item_level_maxed' => false,
    ];
}

/**
 * @param array<int, array<string, mixed>> $crewRows Rows already enriched by
 * pw_missions_apply_item_levels(). This is intentionally arithmetic-only so
 * Mission Command and Sweep can reuse their roster query without another SQL
 * read just to paint the commander card.
 */
function pw_missions_crew_power_from_roster(array $crewRows): array {
    $summary = pw_missions_crew_power_empty();
    if (!$crewRows) return $summary;

    $allReady = true;
    $allMaxed = true;
    foreach ($crewRows as $crew) {
        if (empty($crew['item_level_ready'])) {
            $allReady = false;
            break;
        }
        $summary['crew_count']++;
        $summary['item_level_total'] += max(0, (int)($crew['item_level_total'] ?? 0));
        $summary['item_level_max_total'] += max(0, (int)($crew['item_level_max_total'] ?? 0));
        if (empty($crew['item_level_maxed'])) $allMaxed = false;
    }
    if (!$allReady || $summary['crew_count'] < 1) return pw_missions_crew_power_empty();

    $slots = max(1, count(pw_missions_gear_slots()));
    $denominator = $summary['crew_count'] * $slots;
    $summary['ready'] = true;
    $summary['item_level_average'] = round($summary['item_level_total'] / $denominator, 1);
    $summary['item_level_max_average'] = round($summary['item_level_max_total'] / $denominator, 1);
    $summary['progress_percent'] = $summary['item_level_max_total'] > 0
        ? round(min(100, ($summary['item_level_total'] / $summary['item_level_max_total']) * 100), 1)
        : 0.0;
    $summary['item_level_maxed'] = $summary['item_level_max_total'] > 0 && $allMaxed;
    return $summary;
}

/**
 * Public profiles and forum posts can show this harmless progression signal,
 * but a discussion can carry hundreds of authors. Resolve every requested
 * commander's equipped total in one grouped query rather than turning a forum
 * page into an N+1 gear lookup.
 *
 * @param array<int, int> $userIds
 * @return array<int, array<string, int|float|bool>> keyed by user id
 */
function pw_missions_crew_power_summaries(PDO $db, array $userIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), static function ($id) {
        return $id > 0;
    })));
    $summaries = [];
    foreach ($ids as $id) $summaries[$id] = pw_missions_crew_power_empty();
    if (!$ids || !pw_mission_item_levels_ready($db)) return $summaries;

    $catalogue = pw_missions_item_level_role_ceilings($db);
    $slots = array_keys(pw_missions_gear_slots());
    if (empty($catalogue['ready']) || !$slots) return $summaries;

    $slotPlaceholders = pw_missions_placeholders(count($slots));
    $userPlaceholders = pw_missions_placeholders(count($ids));
    $stmt = $db->prepare(
        'SELECT pc.user_id, pc.id AS player_crew_id, c.role,
                COALESCE(SUM(GREATEST(0, l.item_level)), 0) AS item_level_total
         FROM game_player_crew pc
         JOIN game_crew_definitions c ON c.id = pc.crew_definition_id AND c.is_enabled = 1
         LEFT JOIN game_player_crew_gear g
           ON g.player_crew_id = pc.id AND g.user_id = pc.user_id AND g.slot IN (' . $slotPlaceholders . ')
         LEFT JOIN game_loot_definitions l ON l.id = g.loot_definition_id
         WHERE pc.user_id IN (' . $userPlaceholders . ') AND pc.status <> "retired"
         GROUP BY pc.user_id, pc.id, c.role'
    );
    $stmt->execute(array_merge($slots, $ids));

    $rosters = [];
    foreach ($stmt->fetchAll() as $row) {
        $userId = (int)$row['user_id'];
        $roleCeiling = $catalogue['roles'][(string)$row['role']] ?? ['total' => 0, 'slots_covered' => 0];
        $currentTotal = max(0, (int)$row['item_level_total']);
        $maxTotal = max(0, (int)($roleCeiling['total'] ?? 0));
        $rosters[$userId][] = [
            'item_level_ready' => true,
            'item_level_total' => $currentTotal,
            'item_level_max_total' => $maxTotal,
            'item_level_maxed' => (int)($roleCeiling['slots_covered'] ?? 0) > 0 && $currentTotal >= $maxTotal,
        ];
    }
    foreach ($ids as $id) $summaries[$id] = pw_missions_crew_power_from_roster($rosters[$id] ?? []);
    return $summaries;
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
        $fieldGrade = 0;
        $equipped = $gearByCrew[(int)($row['id'] ?? 0)] ?? [];
        foreach ($equipped as $item) {
            foreach ($stats as $stat) $bonus[$stat] += (int)$item['bonus'][$stat];
            $fieldGrade += max(0, (int)($item['field_grade'] ?? 0));
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
        $crewRows[$index]['field_grade_success_percent'] = round($fieldGrade * PW_MISSION_FIELD_GRADE_SUCCESS_PER_POINT, 2);
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
    $fieldGradeSuccessPercent = 0.0;

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
        $fieldGradeSuccessPercent += max(0.0, (float)($member['field_grade_success_percent'] ?? 0));
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
        // Field Grade joins the same success pool as Strength but has no
        // player-facing breakdown; it is the intentional hidden edge between
        // otherwise matching pieces of equipment.
        'success_percent' => round(($totals['strength'] * PW_MISSION_STRENGTH_SUCCESS_PER_POINT) + $fieldGradeSuccessPercent + $affinity['success_percent']
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

function pw_missions_effective_success(int $baseSuccessPercent, array $effects): float {
    /* Percent rolls already use hundredths, so preserve them here. Field Grade
     * is deliberately only 0.10 percentage points per authored value; rounding
     * at this boundary would make its first four points statistically inert. */
    $percent = round($baseSuccessPercent + (float)($effects['success_percent'] ?? 0), 2);
    return max(5.0, min(100.0, $percent));
}

/* ------------------------------------------------------------------------
 * Inventory workbench.
 *
 * These tables extend the quantity ledger; they never replace it. A deploy
 * can therefore land before the manual migration without making the existing
 * inventory unavailable. The UI receives one readiness flag and leaves the
 * new controls out until both the preference and event tables exist.
 * ---------------------------------------------------------------------- */

function pw_mission_inventory_workbench_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!pw_mission_gear_ready($db) || !pw_mission_credits_ready($db)) return $ready = false;
    return $ready = pw_schema_has($db, 'game_player_loot_preferences', ['user_id', 'loot_definition_id', 'is_favorite', 'tag_key'])
        && pw_schema_has($db, 'game_player_loot_history', ['user_id', 'loot_definition_id', 'event_type', 'source_type', 'created_at']);
}

/** The small fixed tag vocabulary keeps filters useful and avoids free-text labels becoming another moderation surface. */
function pw_missions_inventory_tags(): array {
    return ['keep', 'contract', 'sweep', 'sell'];
}

/**
 * Salvage conversion is intentionally a pressure valve, not a second income
 * loop. Rare and legendary discoveries are never convertible, and even a full
 * stack of ordinary debris pays markedly less than a mission or Sweep.
 */
function pw_missions_salvage_conversion_value(string $tier): int {
    $values = ['common' => 2, 'uncommon' => 5];
    return $values[strtolower(trim($tier))] ?? 0;
}

/**
 * Append a player-safe inventory event. Source ids deliberately have no
 * foreign key because one item can originate from a mission, a Sweep run, a
 * market rotation or a recovery offer; the typed id and readable note keep the
 * trail useful without inventing a fragile polymorphic relation.
 *
 * @param array<int,int> $quantities definition id => quantity
 */
function pw_missions_record_loot_history(PDO $db, int $userId, array $quantities, string $eventType, string $sourceType = '', ?int $sourceId = null, string $note = ''): void {
    if (!$quantities || !pw_mission_inventory_workbench_ready($db)) return;
    $eventType = preg_match('/\A[a-z_]{1,24}\z/', $eventType) ? $eventType : 'updated';
    $sourceType = preg_match('/\A[a-z_]{1,32}\z/', $sourceType) ? $sourceType : 'unknown';
    $sourceId = $sourceId !== null && $sourceId > 0 ? $sourceId : null;
    $note = trim(substr($note, 0, 180));
    $stmt = $db->prepare(
        'INSERT INTO game_player_loot_history
             (user_id, loot_definition_id, event_type, source_type, source_id, quantity, note)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($quantities as $definitionId => $quantity) {
        $definitionId = (int)$definitionId;
        $quantity = (int)$quantity;
        if ($definitionId < 1 || $quantity < 1) continue;
        $stmt->execute([$userId, $definitionId, $eventType, $sourceType, $sourceId, $quantity, $note]);
    }
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
function pw_missions_roll_loot(PDO $db, string $worldKey, int $baseRolls, array $effects, ?array $progression = null): array {
    $rolls = pw_missions_loot_roll_count($baseRolls, $effects);
    if ($rolls < 1) return [];

    $progression = $progression ?? pw_missions_contract_progression([]);

    $gearReady = pw_mission_gear_ready($db);
    $itemLevelsReady = pw_mission_item_levels_ready($db);
    $gearColumns = $gearReady
        ? ', slot, bonus_strength, bonus_cunning, bonus_science, bonus_charisma, required_level, required_role, icon_url'
        : '';
    if ($itemLevelsReady) $gearColumns .= ', item_level';
    /* Without this an awarded stim reaches pw_missions_store_loot() with no
     * stim_effect and is counted against the salvage ceiling instead of its
     * own. */
    $stimsReady = pw_mission_stims_ready($db);
    if ($stimsReady) $gearColumns .= ', stim_effect, stim_value, stim_duration_seconds';
    $stmt = $db->prepare('SELECT id, name, slug, tier, drop_weight' . $gearColumns . ' FROM game_loot_definitions WHERE world_key = ? AND is_enabled = 1');
    $stmt->execute([$worldKey]);
    $pool = $stmt->fetchAll();
    $pool = array_values(array_filter($pool, static function (array $item) use ($progression): bool {
        return pw_missions_contract_gear_is_eligible($item, $progression);
    }));
    if (!$pool) return [];

    /* Normalise the weights on the pool itself, not on a copy: an administrator
     * may set a drop weight of 0, and an all-zero pool would otherwise reach
     * random_int(1, 0) and throw. Every item stays drawable at minimum weight. */
    $byTier = [];
    foreach ($pool as $index => $item) {
        $weight = max(1, (int)$item['drop_weight']);
        if (pw_missions_contract_gear_is_featured($item, $progression)) {
            $weight = (int)round($weight * PW_MISSION_FEATURED_SLOT_CHANCE_MULTIPLIER);
        }
        $pool[$index]['drop_weight'] = max(1, $weight);
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
            'item_level' => $itemLevelsReady ? max(0, (int)($item['item_level'] ?? 0)) : 0,
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
function pw_missions_store_loot(PDO $db, int $userId, array $awarded, array $researchEffects = [], array $provenance = []): array {
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
    /* The item ledger remains the source of truth for what a player owns; this
     * append-only log only explains how the stock arrived. Keeping it here
     * means a mission, a Sweep haul and a tether rescue cannot drift into three
     * subtly different provenance implementations. The readiness guard makes
     * a code deploy ahead of the manual migration harmless. */
    if ($result['stored']) {
        pw_missions_record_loot_history(
            $db,
            $userId,
            $result['stored'],
            'acquired',
            (string)($provenance['source_type'] ?? 'unknown'),
            isset($provenance['source_id']) ? (int)$provenance['source_id'] : null,
            (string)($provenance['note'] ?? '')
        );
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
/* The quick clean-up action deliberately stops at the first two equipment
 * levels. It is a safe way to clear opening-kit duplicates, never a shortcut
 * that can quietly eat gear a player has only just grown into. */
const PW_MISSION_BULK_LOW_GEAR_MAX_LEVEL = 2;
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
    $itemLevelsReady = pw_mission_item_levels_ready($db);
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
            . ($itemLevelsReady ? ', gear.item_level AS gear_item_level' : ', 0 AS gear_item_level')
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
function pw_missions_roll_loot_tables(PDO $db, int $userId, int $missionDefinitionId, ?array $progression = null): array {
    $result = ['granted' => [], 'duplicates' => [], 'pending' => [], 'gear' => []];
    if (!pw_mission_loot_tables_ready($db)) return $result;

    $progression = $progression ?? pw_missions_contract_progression([]);

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
    $itemLevelsReady = pw_mission_item_levels_ready($db);
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
            if (($entry['entry_type'] ?? 'crew') === 'gear') {
                if (!pw_missions_contract_gear_is_eligible($entry, $progression)) continue;
                if (!pw_missions_percent_roll(pw_missions_contract_gear_chance((float)$entry['chance_percent'], $entry, $progression))) continue;
                $result['gear'][] = [
                    'id' => (int)$entry['loot_definition_id'],
                    'name' => $entry['gear_name'],
                    'tier' => $entry['tier'],
                    'upgraded' => false,
                    'slot' => (string)($entry['gear_slot'] ?? ''),
                    'icon_url' => pw_missions_gear_icon_url($entry['gear_icon_url'] ?? ''),
                    'required_level' => (int)($entry['gear_required_level'] ?? 1),
                    'required_role' => (string)($entry['gear_required_role'] ?? ''),
                    'item_level' => $itemLevelsReady ? max(0, (int)($entry['gear_item_level'] ?? 0)) : 0,
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
            if (!pw_missions_percent_roll((float)$entry['chance_percent'])) continue;
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
