<?php
/** The sweep screen: the tier ladder, the eligible crew, and any run in play. */
require_once __DIR__ . '/../sweep-helpers.php';

$user = pw_require_login();
$db = pw_db();
pw_sweep_require_ready($db);
$userId = (int)$user['id'];

try {
    /* Keep this roster truthful when a commander comes straight from another
     * page after a timer expires. Completed runs retain their crew until their
     * rewards are claimed, so the Sweep page needs the same settlement pass as
     * Mission Command before it decides why a crew member is unavailable. */
    pw_missions_settle_due_runs($db, $userId);

    $standing = $db->prepare('SELECT reputation FROM users WHERE id = ?');
    $standing->execute([$userId]);
    $reputation = pw_reputation_info((int)$standing->fetchColumn());
    $rank = max(0, (int)($reputation['level_number'] ?? 0));

    /* The whole ladder, so a player can see what their standing is worth now
     * and what the next rungs hold. Sealed rungs deliberately carry their tier
     * name and board shape but NOT their loot table's contents -- what is in
     * the table is the reward for getting there. */
    $ladder = [];
    $rows = $db->query(
        'SELECT tier.*, lt.name AS loot_table_name, lt.is_enabled AS loot_table_enabled
         FROM game_sweep_tiers tier
         LEFT JOIN game_loot_tables lt ON lt.id = tier.loot_table_id
         WHERE tier.is_enabled = 1
         ORDER BY tier.rank_number ASC'
    )->fetchAll();
    $completed = [];
    $countStmt = $db->prepare(
        'SELECT rank_number, COUNT(*) AS runs FROM game_player_sweep_runs
         WHERE user_id = ? AND status = "banked" GROUP BY rank_number'
    );
    $countStmt->execute([$userId]);
    foreach ($countStmt->fetchAll() as $row) $completed[(int)$row['rank_number']] = (int)$row['runs'];

    /* Which rung is actually in play, so the ladder can mark it. With a sparse
     * ladder that is rarely the rung matching the rank -- a rank-12 commander
     * on a ladder whose highest sector is rank 1 is playing rank 1's. */
    $activeTier = pw_sweep_tier($db, $rank);
    $activeRank = $activeTier !== null ? $activeTier['rank_number'] : 0;
    foreach ($rows as $row) {
        $tier = pw_sweep_normalise_tier($row);
        $unlocked = $rank >= $tier['rank_number'];
        $ladder[] = [
            'rank_number' => $tier['rank_number'],
            'name' => $tier['name'] !== '' ? $tier['name'] : 'Sector ' . $tier['rank_number'],
            'grid_rows' => $tier['grid_rows'],
            'grid_cols' => $tier['grid_cols'],
            'base_picks' => $tier['base_picks'],
            'hazard_count' => $tier['hazard_count'],
            'fatigue_cost' => $tier['fatigue_cost'],
            'cache_credits' => $tier['cache_credits'],
            'xp_reward' => $tier['xp_reward'],
            'condition' => pw_sweep_condition_public($tier['condition_key']),
            'unlocked' => $unlocked,
            'is_current' => $tier['rank_number'] === $activeRank,
            'sweeps_completed' => $completed[$tier['rank_number']] ?? 0,
            // Named only once reachable; before that it is the thing you climb for.
            'loot_table_name' => $unlocked ? $tier['loot_table_name'] : '',
            'ready' => $tier['loot_table_id'] !== null && $tier['loot_table_enabled'],
        ];
    }

    $tier = $activeTier;
    /* Read once. It was called twice here, which is two identical queries for
     * one answer -- and the sweep projections below need it as well. */
    $research = pw_research_player_effects($db, $userId);
    $fatigueMax = pw_missions_fatigue_max($db, $userId, $research);
    $recovery = (float)($research['fatigue_recovery_percent'] ?? 0);
    $now = pw_missions_utc_now($db);

    /* The same favourites the missions roster uses -- one flag, one meaning.
     * Guarded, because the column arrives with its own migration. */
    $favoritesReady = pw_mission_crew_favorites_ready($db);
    $contractsReady = pw_mission_overlord_contracts_ready($db);
    $crewStmt = $db->prepare(
        'SELECT pc.id, pc.level, pc.status, pc.fatigue, pc.fatigue_updated_at, c.name, c.role, c.portrait_url,'
        . ($favoritesReady ? ' pc.is_favorite,' : ' 0 AS is_favorite,') . '
                ' . (pw_mission_crew_capacity_ready($db) ? 'c.tier,' : '"common" AS tier,') . '
                assignment.mission_id AS assignment_mission_id,
                assignment.mission_status AS assignment_mission_status,
                assignment.mission_name AS assignment_mission_name,
                assignment.is_contract AS assignment_is_contract
         FROM game_player_crew pc
         JOIN game_crew_definitions c ON c.id = pc.crew_definition_id AND c.is_enabled = 1
         LEFT JOIN (
             SELECT link.player_crew_id, pm.id AS mission_id, pm.status AS mission_status,
                    md.name AS mission_name, '
            . ($contractsReady ? 'md.overlord_id IS NOT NULL' : '0') . ' AS is_contract
             FROM game_player_mission_crew link
             JOIN game_player_missions pm ON pm.id = link.player_mission_id
             JOIN game_mission_definitions md ON md.id = pm.mission_definition_id
             WHERE pm.user_id = ? AND pm.status IN ("active", "completed")
         ) assignment ON assignment.player_crew_id = pc.id
         WHERE pc.user_id = ? AND pc.status <> "retired"
         ORDER BY c.name ASC'
    );
    $crewStmt->execute([$userId, $userId]);
    $crew = pw_missions_apply_level_stats($crewStmt->fetchAll());
    $crew = pw_missions_apply_gear($db, $userId, $crew);
    $roster = [];
    foreach ($crew as $member) {
        $fatigue = pw_missions_resolve_fatigue($member, $fatigueMax, $now, $recovery);
        $bonuses = $tier ? pw_sweep_crew_bonuses($member, $tier, $research) : ['picks_total' => 0, 'hint_radius' => 0, 'shrug_percent' => 0, 'xp_reward' => 0];
        if ($tier) {
            /* Dense debris changes the deployed crew's scan count, so the
             * roster projection must use the same launch helper as start.php
             * instead of promising a scan that the board will take away. */
            $conditionRun = pw_sweep_apply_condition(
                $tier,
                $bonuses,
                pw_sweep_effective_hazards($tier, $research),
                (float)($research['sweep_recognition_percent'] ?? 0)
            );
            $bonuses = $conditionRun['bonuses'];
        }
        $roster[] = [
            'id' => (int)$member['id'],
            'name' => (string)$member['name'],
            'role' => (string)$member['role'],
            'tier' => (string)($member['tier'] ?? 'common'),
            'portrait_url' => (string)($member['portrait_url'] ?? ''),
            'is_favorite' => $favoritesReady && !empty($member['is_favorite']),
            'level' => (int)$member['level'],
            'status' => (string)$member['status'],
            'assignment_mission_id' => $member['assignment_mission_id'] !== null ? (int)$member['assignment_mission_id'] : null,
            'assignment_mission_status' => (string)($member['assignment_mission_status'] ?? ''),
            'assignment_mission_name' => (string)($member['assignment_mission_name'] ?? ''),
            'assignment_is_contract' => $contractsReady && !empty($member['assignment_is_contract']),
            'fatigue' => $fatigue,
            'fatigue_max' => $fatigueMax,
            'strength' => (int)($member['strength'] ?? 0),
            'cunning' => (int)($member['cunning'] ?? 0),
            'science' => (int)($member['science'] ?? 0),
            'charisma' => (int)($member['charisma'] ?? 0),
            'can_deploy' => $tier !== null && $member['status'] === 'available' && $fatigue >= $tier['fatigue_cost'],
            'projection' => $bonuses,
        ];
    }

    /* The same commander block the missions page renders, in the same shape,
     * so one card definition serves both. Deliberately not a shared function:
     * these are two endpoints assembling from the same helpers, which is how
     * every other pair of pages here works. */
    $player = [
        'id' => $userId,
        'display_name' => $user['display_name'] ?? $user['username'],
        'reputation' => array_merge($reputation, ['level_number' => $rank]),
        'credits' => pw_missions_credit_balance($db, $userId),
        'credits_ready' => true,
    ];

    $tierPayload = $tier ? array_merge($tier, ['condition' => pw_sweep_condition_public($tier['condition_key'])]) : null;
    pw_json([
        'ok' => true,
        'player' => $player,
        'trophies' => pw_sweep_recent_trophies($db, $userId),
        'reputation' => array_merge($reputation, ['level_number' => $rank]),
        'credits' => pw_missions_credit_balance($db, $userId),
        'tier' => $tierPayload,
        'ladder' => $ladder,
        'crew' => $roster,
        'run' => pw_sweep_public_run($db, $userId),
        'sweeps_at_rank' => $completed[$activeRank] ?? 0,
        'crew_favorites_ready' => $favoritesReady,
        /* The rates behind every figure on a crew card, shipped rather than
         * restated in the browser. A tooltip that explains "one scan per 12
         * Cunning" has to read the same 12 the engine used, or it becomes a
         * confident lie the first time the number is retuned. */
        'tuning' => [
            'cunning_per_pick' => PW_SWEEP_CUNNING_PER_PICK,
            'science_per_ring' => PW_SWEEP_SCIENCE_PER_RING,
            'strength_shrug_per_point' => PW_SWEEP_STRENGTH_SHRUG_PER_POINT,
            'shrug_cap' => PW_SWEEP_SHRUG_CAP,
            'charisma_xp_per_point' => PW_SWEEP_CHARISMA_XP_PER_POINT,
            'max_hint_radius' => 2,
        ],
    ]);
} catch (Throwable $e) {
    /* The message is passed through rather than swallowed. A blanket "please
     * try again" on a read-only, login-gated endpoint costs the one thing that
     * makes a new feature debuggable, and it is what turned a first failure
     * here into a hunt: the page kept its loading placeholders and said
     * nothing about why. */
    pw_error('The Salvage Sweep could not be read: ' . $e->getMessage(), 503);
}
