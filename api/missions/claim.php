<?php
require_once __DIR__ . '/missions-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$missionId = filter_var($input['mission_id'] ?? null, FILTER_VALIDATE_INT);
if ($missionId === false || $missionId < 1) pw_error('Choose a valid mission.');
$db = pw_db();
pw_missions_require_ready($db);
$userId = (int)$user['id'];

try {
    $db->beginTransaction();
    $statsColumns = pw_mission_stats_ready($db) ? ', md.base_success_percent, md.loot_rolls' : '';
    $creditsReady = pw_mission_credits_ready($db);
    if ($creditsReady) $statsColumns .= ', md.credit_reward';
    $missionStmt = $db->prepare(
        'SELECT pm.*, md.name AS mission_name' . $statsColumns . '
         FROM game_player_missions pm
         JOIN game_mission_definitions md ON md.id = pm.mission_definition_id
         WHERE pm.id = ? AND pm.user_id = ? FOR UPDATE'
    );
    $missionStmt->execute([$missionId, $userId]);
    $mission = $missionStmt->fetch();
    if (!$mission) throw new RuntimeException('Mission not found.');
    if ($mission['status'] === 'claimed') throw new RuntimeException('This mission has already been claimed.');
    if ($mission['status'] !== 'completed') throw new RuntimeException('Complete this mission before claiming its rewards.');

    $statsReady = pw_mission_stats_ready($db);
    $crewStmt = $db->prepare(
        'SELECT pc.id, pc.status, pc.level, pc.xp, c.name, c.role, c.starting_level'
        . ($statsReady ? ', pc.strength, pc.cunning, pc.science, pc.charisma' : '') .
        ' FROM game_player_mission_crew link
         JOIN game_player_crew pc ON pc.id = link.player_crew_id
         JOIN game_crew_definitions c ON c.id = pc.crew_definition_id
         WHERE link.player_mission_id = ? AND pc.user_id = ? FOR UPDATE'
    );
    $crewStmt->execute([$missionId, $userId]);
    $crew = $crewStmt->fetchAll();
    if (!$crew) throw new RuntimeException('This mission has no assigned crew.');
    foreach ($crew as $member) {
        if ($member['status'] !== 'on_mission') throw new RuntimeException('Crew status no longer matches this mission.');
    }
    $crewIds = array_map(static function ($member) { return (int)$member['id']; }, $crew);

    $effects = $statsReady ? pw_missions_crew_effects($crew) : [
        'duration_percent' => 0.0, 'xp_percent' => 0.0, 'reputation_flat' => 0,
        'success_percent' => 0.0, 'loot_percent' => 0.0, 'upgrade_percent' => 0.0,
    ];

    /* The outcome is rolled here, on the server, from the mission's own base
     * chance plus the crew's Strength -- never from anything the client sends.
     * base_success_percent defaults to 100, so a mission only carries real risk
     * once an administrator lowers it. */
    $baseSuccess = $statsReady ? (int)($mission['base_success_percent'] ?? 100) : 100;
    $successPercent = pw_missions_effective_success($baseSuccess, $effects);
    $succeeded = $successPercent >= 100 ? true : pw_missions_percent_roll((float)$successPercent);

    $xpAwarded = 0;
    $reputationAwarded = 0;
    $creditsAwarded = 0;
    $loot = [];
    if ($succeeded) {
        $xpAwarded = (int)round((int)$mission['xp_reward'] * (1 + ($effects['xp_percent'] / 100)));
        $reputationAwarded = (int)$mission['reputation_reward'] + (int)$effects['reputation_flat'];
        /* Credits are the operation's flat contract fee. Deliberately not scaled
         * by any crew stat: Cunning already buys extra loot draws, and paying a
         * second bonus off the same roster would compound one advantage twice. */
        $creditsAwarded = $creditsReady ? (int)($mission['credit_reward'] ?? 0) : 0;
    }

    /* A failed mission returns its crew with no XP, no reputation and no loot,
     * and is recorded as "failed" rather than "claimed" -- the campaign unlock
     * gate counts claimed runs only, so a failure can never advance a chain. */
    $crewUpdate = $db->prepare(
        'UPDATE game_player_crew SET xp = xp + ?, status = "available"
         WHERE user_id = ? AND id IN (' . pw_missions_placeholders(count($crewIds)) . ') AND status = "on_mission"'
    );
    $crewUpdate->execute(array_merge([$xpAwarded, $userId], $crewIds));
    if ($crewUpdate->rowCount() !== count($crewIds)) throw new RuntimeException('Crew status no longer matches this mission.');

    // Levelling is re-derived from the crew member's new XP total, so it is
    // correct even if a past award was applied while the migration was pending.
    $levelUps = [];
    if ($statsReady && $xpAwarded > 0) {
        $levelStmt = $db->prepare('UPDATE game_player_crew SET level = ?, strength = ?, cunning = ?, science = ?, charisma = ? WHERE id = ? AND user_id = ?');
        foreach ($crew as $member) {
            $newXp = (int)$member['xp'] + $xpAwarded;
            $newLevel = pw_missions_level_for_xp($newXp, (int)$member['starting_level']);
            if ($newLevel === (int)$member['level']) continue;
            $stats = pw_missions_stats_for_level((string)$member['role'], $newLevel);
            $levelStmt->execute([$newLevel, $stats['strength'], $stats['cunning'], $stats['science'], $stats['charisma'], (int)$member['id'], $userId]);
            $levelUps[] = ['id' => (int)$member['id'], 'name' => $member['name'], 'level' => $newLevel];
        }
    }

    if ($reputationAwarded > 0) {
        $reputationAwarded = pw_award_reputation(
            $db,
            $userId,
            $reputationAwarded,
            'mission_completed',
            [
                'label' => 'Mission: ' . $mission['mission_name'],
                'source_type' => 'mission',
                'source_id' => $missionId,
                'note' => 'Neoh expedition reward',
            ]
        );
    }

    $creditBalance = $creditsReady ? pw_missions_add_credits($db, $userId, $creditsAwarded) : 0;

    if ($succeeded && $statsReady) {
        $loot = pw_missions_roll_loot($db, (string)$mission['world_key'], (int)($mission['loot_rolls'] ?? 0), $effects);
        pw_missions_store_loot($db, $userId, $loot);
    }

    $now = pw_missions_utc_now($db);
    $status = $succeeded ? 'claimed' : 'failed';
    /* Two independent migrations decide which columns exist here, so the SET
     * clause and its values are built from one list rather than as a matrix of
     * hand-written branches -- that is exactly how a placeholder/value count
     * silently desyncs, and PDO only reports it at execute() against the live
     * database. */
    $sets = ['status = ?', 'claimed_at = ?'];
    $values = [$status, pw_missions_datetime($now)];
    if ($statsReady) {
        array_push($sets, 'success_percent = ?', 'xp_bonus_percent = ?', 'reputation_bonus = ?');
        array_push($values, $successPercent, (int)round($effects['xp_percent']), (int)$effects['reputation_flat']);
    }
    if ($creditsReady) {
        $sets[] = 'credits_awarded = ?';
        $values[] = $creditsAwarded;
    }
    $values[] = $missionId;
    $missionUpdate = $db->prepare('UPDATE game_player_missions SET ' . implode(', ', $sets) . ' WHERE id = ? AND status = "completed"');
    $missionUpdate->execute($values);
    if ($missionUpdate->rowCount() !== 1) throw new RuntimeException('This mission reward was already claimed.');
    $db->commit();
    pw_json([
        'ok' => true,
        'succeeded' => $succeeded,
        'mission_name' => $mission['mission_name'],
        'success_percent' => $successPercent,
        'xp_awarded_per_crew' => $xpAwarded,
        'reputation_awarded' => $reputationAwarded,
        'xp_bonus_percent' => $effects['xp_percent'],
        'credits_awarded' => $creditsAwarded,
        'credits_total' => $creditBalance,
        'credits_ready' => $creditsReady,
        'level_ups' => $levelUps,
        'loot' => $loot,
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    pw_error($e instanceof RuntimeException ? $e->getMessage() : 'Could not claim mission rewards. Please try again.', 409);
}
