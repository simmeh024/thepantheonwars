<?php
require_once __DIR__ . '/missions-helpers.php';
require_once __DIR__ . '/../research/research-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$missionId = filter_var($input['mission_id'] ?? null, FILTER_VALIDATE_INT);
if ($missionId === false || $missionId < 1) pw_error('Choose a valid mission.');
$crewIds = pw_missions_normalize_crew_ids($input['crew_ids'] ?? null);
$db = pw_db();
pw_missions_require_successions_ready($db);
$userId = (int)$user['id'];

try {
    $db->beginTransaction();
    pw_missions_grant_starter_crew($db, $userId);

    /* One operation at a time, checked before anything else is read or locked.
     * This is the real boundary -- the page withholds the launch controls while
     * a run is in flight, but a stale tab or a hand-made request reaches here,
     * and the rule has to hold for those too. */
    $singleRunBlock = pw_missions_single_run_block($db, $userId);
    if ($singleRunBlock !== null) throw new RuntimeException($singleRunBlock);

    $missionStmt = $db->prepare('SELECT * FROM game_mission_definitions WHERE id = ? FOR UPDATE');
    $missionStmt->execute([$missionId]);
    $mission = $missionStmt->fetch();
    if (!$mission || !(bool)$mission['is_enabled'] || $mission['world_key'] !== 'neoh') {
        throw new RuntimeException('That mission is no longer available.');
    }
    /* Pool definitions are never normal board missions. Their private offer is
     * locked here before any crew is touched, which prevents a guessed id from
     * launching an item-recovery run without first losing that item in a Sweep. */
    $salvageRecoveryReady = pw_mission_salvage_recovery_contracts_ready($db);
    $salvageRecovery = null;
    if ($salvageRecoveryReady && !empty($mission['is_salvage_recovery_contract'])) {
        $recoveryStmt = $db->prepare(
            'SELECT * FROM game_player_salvage_recovery_contracts
             WHERE user_id = ? AND mission_definition_id = ? AND status = "available" FOR UPDATE'
        );
        $recoveryStmt->execute([$userId, (int)$mission['id']]);
        $salvageRecovery = $recoveryStmt->fetch();
        if (!$salvageRecovery) {
            throw new RuntimeException('This salvage recovery contract is no longer available.');
        }
    }
    if ((pw_mission_research_locks_ready($db) || pw_research_ready($db))
        && !pw_research_mission_is_unlocked($db, $userId, (int)$mission['id'])) {
        throw new RuntimeException('This classified mission requires its Research Facility protocol first.');
    }
    /* An Overlord contract is gated on rank, on the player's own allegiance,
     * and on being the operation actually issued to them today. All three are
     * recomputed here rather than trusted: the daily selection is a pure
     * function of the player, the date and the pool, so the server can name
     * today's contract itself and refuse every other id. Without this, reading
     * a contract id out of the network tab on the one day it appeared would let
     * it be launched on every day after. */
    $contractBlock = pw_missions_overlord_contract_block(
        $db,
        $userId,
        $mission,
        (int)(pw_reputation_info((int)($user['reputation'] ?? 0))['level_number'] ?? 0),
        pw_missions_overlord_affinity($db, $user['overlord_affinity'] ?? null)
    );
    if ($contractBlock !== null) throw new RuntimeException($contractBlock);
    /* A contested recovery plan is chosen exactly once, at launch. The browser
     * may only name one of the three authored approaches; whether this is a
     * contest and which faction is involved always comes from the locked
     * contract definition. */
    $contestedReady = pw_mission_contested_contracts_ready($db);
    $isContested = pw_missions_contested_contract_is_enabled($mission, $contestedReady);
    $rivalApproach = null;
    if ($isContested) {
        $rivalApproach = pw_missions_contested_contract_approach($input['rival_approach'] ?? null);
        if ($rivalApproach === null) {
            throw new RuntimeException('Choose how your crew will handle the rival recovery team.');
        }
    }
    if ($mission['unlocks_after_mission_id'] !== null) {
        $requiredCompletions = max(1, (int)$mission['unlocks_after_completion_count']);
        $completedStmt = $db->prepare(
            'SELECT COUNT(*) FROM game_player_missions
             WHERE user_id = ? AND mission_definition_id = ? AND status = "claimed"'
        );
        $completedStmt->execute([$userId, (int)$mission['unlocks_after_mission_id']]);
        $completedCount = (int)$completedStmt->fetchColumn();
        if ($completedCount < $requiredCompletions) {
            $prerequisiteStmt = $db->prepare('SELECT name FROM game_mission_definitions WHERE id = ?');
            $prerequisiteStmt->execute([(int)$mission['unlocks_after_mission_id']]);
            $prerequisite = $prerequisiteStmt->fetch();
            $remaining = $requiredCompletions - $completedCount;
            $name = $prerequisite ? $prerequisite['name'] : 'the prerequisite mission';
            throw new RuntimeException('Complete ' . $name . ' ' . $remaining . ' more ' . ($remaining === 1 ? 'time' : 'times') . ' to unlock this mission.');
        }
    }
    if (count($crewIds) < (int)$mission['min_crew'] || count($crewIds) > (int)$mission['max_crew']) {
        throw new RuntimeException('This mission requires between ' . (int)$mission['min_crew'] . ' and ' . (int)$mission['max_crew'] . ' crew members.');
    }

    $statsReady = pw_mission_stats_ready($db);
    $placeholders = pw_missions_placeholders(count($crewIds));
    $fatigueReady = pw_mission_fatigue_ready($db);
    $crewStmt = $db->prepare(
        'SELECT pc.id, pc.status, pc.level, c.role, c.name'
        . (pw_mission_crew_capacity_ready($db) ? ', c.tier' : ', "common" AS tier')
        . ($statsReady ? ', pc.strength, pc.cunning, pc.science, pc.charisma' : '')
        . ($fatigueReady ? ', pc.fatigue, pc.fatigue_updated_at' : '') .
        ' FROM game_player_crew pc
         JOIN game_crew_definitions c ON c.id = pc.crew_definition_id AND c.is_enabled = 1
         WHERE pc.user_id = ? AND pc.id IN (' . $placeholders . ') FOR UPDATE'
    );
    $crewStmt->execute(array_merge([$userId], $crewIds));
    $selectedCrew = $crewStmt->fetchAll();
    if (count($selectedCrew) !== count($crewIds)) throw new RuntimeException('One selected crew member does not belong to you.');
    /* Rebuild automatic stats from role and level before equipment is applied.
     * This makes a levelled recruit with an old zeroed stat cache contribute
     * their real values to the duration and mission calculations. */
    $selectedCrew = pw_missions_apply_level_stats($selectedCrew);
    /* Equipment counts towards the duration this launch locks in. Loadouts are
     * frozen while a crew member is deployed, so what is read here is what the
     * claim will read again. */
    $selectedCrew = pw_missions_apply_gear($db, $userId, $selectedCrew);
    foreach ($selectedCrew as $member) {
        if ($member['status'] !== 'available') throw new RuntimeException('Every selected crew member must be available.');
    }

    /* The Engineer bonus, and the affinity adjustment for this operation type,
     * are applied to the completion time at launch, so the countdown a player
     * watches is the real one. Every other bonus is resolved at claim instead --
     * a crew that levels up mid-mission should benefit, and the duration is
     * already fixed by then. Affinity itself is a pure function of role and type,
     * so resolving its time half here and its reward half at claim cannot
     * disagree: neither input can change while the crew is out. */
    /* Today's conditions on the world this operation runs on. Read once here and
     * recorded against the run, so the weather the crew launched into is the
     * weather that judges them at claim -- a long operation can cross a UTC day
     * boundary, and the forecast deliberately turns over with the date. */
    $weather = pw_missions_world_weather($db, (string)$mission['world_key']);
    $effects = $statsReady
        ? pw_missions_crew_effects($selectedCrew, (string)$mission['mission_type'], $weather, (int)($mission['contract_tier'] ?? 1))
        : ['duration_percent' => 0.0, 'duration_penalty_percent' => 0.0, 'success_percent' => 0.0];
    /* Called unconditionally: the helper returns zeroed defaults when the
     * Research Facility has not been migrated, and it is also where a running
     * stim is folded in -- guarding on the tree would ignore a boost the player
     * had already spent. Read once and reused by the fatigue check below. */
    $research = pw_research_player_effects($db, $userId);
    $effects['duration_percent'] = min(90.0, (float)$effects['duration_percent'] + (float)$research['mission_speed_percent']);
    $duration = pw_missions_effective_duration((int)$mission['duration_seconds'], $effects);

    /* Push Ahead cuts the crew's real clock after every ordinary duration
     * modifier has been resolved. This is not a client-only preview: the
     * stored completion time is the time judged at claim, and is what the
     * active-contract race renders. */
    if ($isContested && $rivalApproach === 'push') {
        $duration = max(1, (int)round($duration * (100 - PW_MISSION_CONTESTED_PUSH_DURATION_PERCENT) / 100));
    }

    $now = pw_missions_utc_now($db);

    /* Fatigue is charged here, at launch, from the mission's authored length
     * rather than the effective duration computed just above -- the cost the
     * player was shown on the card before choosing any crew has to be the cost
     * they pay, and an effective figure would move as they added an Engineer.
     *
     * Re-checked server-side even though the browser disables a crew member it
     * believes is too tired: this is a separate entry point, and a crafted POST
     * must not be able to field an exhausted roster. Every row was locked FOR
     * UPDATE above, so two concurrent launches cannot both spend the same
     * crew member's pool. */
    if ($fatigueReady) {
        $fatigueCost = pw_missions_fatigue_cost((int)$mission['duration_seconds']);
        if ($isContested && $rivalApproach === 'push' && $fatigueCost > 0) {
            $fatigueCost = (int)ceil($fatigueCost * (100 + PW_MISSION_CONTESTED_PUSH_FATIGUE_PERCENT) / 100);
        }
        if ($fatigueCost > 0) {
            $fatigueMax = pw_missions_fatigue_max($db, $userId, $research);
            $fatigueRecovery = (float)($research['fatigue_recovery_percent'] ?? 0);
            $spend = $db->prepare('UPDATE game_player_crew SET fatigue = ?, fatigue_updated_at = ? WHERE id = ? AND user_id = ?');
            foreach ($selectedCrew as $member) {
                $current = pw_missions_resolve_fatigue($member, $fatigueMax, $now, $fatigueRecovery);
                if ($current < $fatigueCost) {
                    $wait = pw_missions_fatigue_recovery_seconds($current, $fatigueCost, $fatigueRecovery);
                    $minutes = max(1, (int)ceil($wait / 60));
                    throw new RuntimeException(
                        $member['name'] . ' is too fatigued for this operation. '
                        . $minutes . ' more ' . ($minutes === 1 ? 'minute' : 'minutes') . ' of rest needed.'
                    );
                }
                $spend->execute([$current - $fatigueCost, pw_missions_datetime($now), (int)$member['id'], $userId]);
            }
        }
    }

    $completesAt = $now->modify('+' . $duration . ' seconds');
    $rivalFaction = $isContested ? pw_missions_contested_contract_faction($mission['rival_faction_name'] ?? '') : '';
    /* The rival's clock is picked server-side and persisted with the run. It
     * is based on the unmodified authored duration so crew composition and the
     * Push Ahead choice meaningfully change the race; a later editor change
     * cannot rewrite a recovery team already in the field. Safe Cut declines
     * the race altogether and has no rival deadline. */
    $rivalCompletesAt = null;
    if ($isContested && $rivalApproach !== 'safe') {
        $rivalSeconds = max(60, (int)round((int)$mission['duration_seconds'] * random_int(92, 108) / 100));
        $rivalCompletesAt = $now->modify('+' . $rivalSeconds . ' seconds');
    }
    $weatherReady = pw_mission_weather_ready($db);
    $columns = ['user_id', 'mission_definition_id', 'world_key', 'status', 'started_at', 'completes_at', 'xp_reward', 'reputation_reward'];
    $values = [
        $userId, (int)$mission['id'], $mission['world_key'], 'active', pw_missions_datetime($now), pw_missions_datetime($completesAt),
        (int)$mission['xp_reward'], (int)$mission['reputation_reward'],
    ];
    if ($weatherReady) {
        array_push($columns, 'weather_condition', 'weather_icon', 'weather_severe');
        array_push($values,
            $weather ? $weather['condition'] : null,
            $weather ? $weather['icon'] : null,
            $weather && $weather['severe'] ? 1 : 0
        );
    }
    if ($contestedReady) {
        array_push($columns, 'is_contested', 'rival_faction_name', 'rival_approach', 'rival_completes_at');
        array_push($values,
            $isContested ? 1 : 0,
            $isContested ? $rivalFaction : null,
            $isContested ? $rivalApproach : null,
            $rivalCompletesAt ? pw_missions_datetime($rivalCompletesAt) : null
        );
    }
    $insert = $db->prepare(
        'INSERT INTO game_player_missions (' . implode(', ', $columns) . ')
         VALUES (' . pw_missions_placeholders(count($columns)) . ')'
    );
    $insert->execute($values);
    $playerMissionId = (int)$db->lastInsertId();
    if ($salvageRecovery) {
        $recoveryUpdate = $db->prepare(
            'UPDATE game_player_salvage_recovery_contracts
             SET status = "active", player_mission_id = ?
             WHERE id = ? AND user_id = ? AND status = "available"'
        );
        $recoveryUpdate->execute([$playerMissionId, (int)$salvageRecovery['id'], $userId]);
        if ($recoveryUpdate->rowCount() !== 1) throw new RuntimeException('This salvage recovery contract changed before launch.');
    }
    $linkStmt = $db->prepare('INSERT INTO game_player_mission_crew (player_mission_id, player_crew_id) VALUES (?, ?)');
    foreach ($crewIds as $crewId) $linkStmt->execute([$playerMissionId, $crewId]);

    $statusUpdate = $db->prepare(
        'UPDATE game_player_crew SET status = "on_mission"
         WHERE user_id = ? AND status = "available" AND id IN (' . $placeholders . ')'
    );
    $statusUpdate->execute(array_merge([$userId], $crewIds));
    if ($statusUpdate->rowCount() !== count($crewIds)) {
        throw new RuntimeException('Crew availability changed while the mission was launching.');
    }
    $db->commit();
    pw_json([
        'ok' => true,
        'mission_id' => $playerMissionId,
        'completes_at' => pw_missions_datetime($completesAt),
        'rival' => $isContested ? [
            'contested' => true,
            'faction' => $rivalFaction,
            'approach' => $rivalApproach,
            'rival_completes_at' => $rivalCompletesAt ? pw_missions_datetime($rivalCompletesAt) : null,
        ] : null,
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    pw_error($e instanceof RuntimeException ? $e->getMessage() : 'Could not launch this mission. Please try again.', 409);
}
