<?php
/** Open a board. Costs the chosen crew member's fatigue and deploys them. */
require_once __DIR__ . '/../sweep-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$db = pw_db();
pw_sweep_require_ready($db);
$userId = (int)$user['id'];
$crewId = filter_var($input['crew_id'] ?? null, FILTER_VALIDATE_INT);
if ($crewId === false || $crewId < 1) pw_error('Choose a crew member for the sweep.');

$db->beginTransaction();
try {
    /* One board at a time. Without this a player could open several, spend
     * every crew member's fatigue at once, and pick the luckiest to bank. */
    $open = $db->prepare('SELECT id FROM game_player_sweep_runs WHERE user_id = ? AND status = "active" FOR UPDATE');
    $open->execute([$userId]);
    if ($open->fetch()) throw new RuntimeException('A sweep is already under way. Finish or withdraw from it first.');

    $standing = $db->prepare('SELECT reputation FROM users WHERE id = ?');
    $standing->execute([$userId]);
    $rank = max(0, (int)(pw_reputation_info((int)$standing->fetchColumn())['level_number'] ?? 0));
    /* The tier is resolved from the rank held right now, never from anything
     * the browser sent -- otherwise a rank-1 player could name rank 40's board
     * and draw from its table. */
    $tier = pw_sweep_tier($db, $rank);
    if ($tier === null) throw new RuntimeException('No sweep sector is open at your current standing.');
    if ($tier['loot_table_id'] === null || !$tier['loot_table_enabled']) {
        throw new RuntimeException('This sector has no recovery manifest yet. Try again once command files one.');
    }

    $crewStmt = $db->prepare(
        'SELECT pc.id, pc.level, pc.status, pc.fatigue, pc.fatigue_updated_at, c.name, c.role,
                ' . (pw_mission_crew_capacity_ready($db) ? 'c.tier' : '"common" AS tier') . '
         FROM game_player_crew pc
         JOIN game_crew_definitions c ON c.id = pc.crew_definition_id AND c.is_enabled = 1
         WHERE pc.user_id = ? AND pc.id = ? FOR UPDATE'
    );
    $crewStmt->execute([$userId, $crewId]);
    $member = $crewStmt->fetch();
    if (!$member) throw new RuntimeException('That crew member does not belong to you.');
    if ($member['status'] !== 'available') throw new RuntimeException('That crew member is already deployed.');

    $rows = pw_missions_apply_gear($db, $userId, pw_missions_apply_level_stats([$member]));
    $member = $rows[0];
    $research = pw_research_player_effects($db, $userId);
    $fatigueMax = pw_missions_fatigue_max($db, $userId, $research);
    $now = pw_missions_utc_now($db);
    $fatigue = pw_missions_resolve_fatigue($member, $fatigueMax, $now, (float)($research['fatigue_recovery_percent'] ?? 0));
    if ($fatigue < $tier['fatigue_cost']) {
        throw new RuntimeException($member['name'] . ' needs ' . $tier['fatigue_cost'] . ' fatigue to run this sector and has ' . $fatigue . '.');
    }

    /* The board is fixed at launch, research included: unlocking a protocol
     * mid-sweep must not change a field already being walked. */
    $bonuses = pw_sweep_crew_bonuses($member, $tier, $research);
    $hazards = pw_sweep_effective_hazards($tier, $research);
    /* The seed is the whole secret of the board, so it comes from the CSPRNG
     * and is never returned by any endpoint. */
    $seed = random_int(1, 2147483646);

    $spend = $db->prepare(
        'UPDATE game_player_crew SET fatigue = ?, fatigue_updated_at = ?, status = "on_mission"
         WHERE id = ? AND user_id = ? AND status = "available"'
    );
    $spend->execute([$fatigue - $tier['fatigue_cost'], pw_missions_datetime($now), $crewId, $userId]);
    if ($spend->rowCount() !== 1) throw new RuntimeException('That crew member is no longer available.');

    $insert = $db->prepare(
        'INSERT INTO game_player_sweep_runs
            (user_id, player_crew_id, rank_number, loot_table_id, grid_rows, grid_cols, hazard_count,
             picks_total, hint_radius, shrug_percent, grid_seed, cache_credits, xp_reward,
             tether_percent, recognition_percent, momentum_percent, stabiliser_points, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "active")'
    );
    /* The sector's own rank, not the player's. With a sparse ladder those
     * differ, and counting a run against the rank the player happened to hold
     * would scatter one sector's history across every rank above it. */
    $insert->execute([
        $userId, $crewId, $tier['rank_number'], $tier['loot_table_id'], $tier['grid_rows'], $tier['grid_cols'],
        $hazards, $bonuses['picks_total'], $bonuses['hint_radius'], $bonuses['shrug_percent'],
        $seed, $tier['cache_credits'], $bonuses['xp_reward'],
        round((float)($research['sweep_tether_percent'] ?? 0), 2),
        round((float)($research['sweep_recognition_percent'] ?? 0), 2),
        round((float)($research['sweep_momentum_percent'] ?? 0), 2),
        round((float)($research['sweep_stabiliser_points'] ?? 0), 2),
    ]);
    $runId = (int)$db->lastInsertId();

    $runStmt = $db->prepare('SELECT * FROM game_player_sweep_runs WHERE id = ?');
    $runStmt->execute([$runId]);
    $run = $runStmt->fetch();
    $db->commit();
    pw_json(['ok' => true, 'run' => pw_sweep_run_payload($db, $run)]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    pw_error($e instanceof RuntimeException ? $e->getMessage() : 'The sweep could not be opened.', 400);
}
