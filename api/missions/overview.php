<?php
require_once __DIR__ . '/missions-helpers.php';
require_once __DIR__ . '/../research/research-helpers.php';

$user = pw_require_login();
$db = pw_db();
pw_missions_require_successions_ready($db);
$userId = (int)$user['id'];

try {
    pw_missions_grant_starter_crew($db, $userId);
    /* Settle anything that finished while this player was away, before any of
     * the queries below read run status. Without this the page would show a
     * run whose timer expired hours ago as still active, because completion
     * used to happen only in the open tab. */
    pw_missions_settle_due_runs($db, $userId);

    $statsReady = pw_mission_stats_ready($db);
    $crewFavoritesReady = pw_mission_crew_favorites_ready($db);
    $crewCapacityReady = pw_mission_crew_capacity_ready($db);
    $researchReady = pw_research_ready($db);
    $researchLocksReady = pw_mission_research_locks_ready($db);
    $fatigueReady = pw_mission_fatigue_ready($db);
    $contractsReady = pw_mission_overlord_contracts_ready($db);
    $contestedContractsReady = pw_mission_contested_contracts_ready($db);
    $salvageRecoveryContractsReady = pw_mission_salvage_recovery_contracts_ready($db);
    /* Always called: the helper returns defaults when the Research Facility has
     * not been migrated, and it is also where running stims are folded in, so
     * branching here would ignore a boost the player had already spent. */
    $researchEffects = pw_research_player_effects($db, $userId);
    $researchSecrets = ($researchReady || $researchLocksReady) ? pw_research_secret_missions($db, $userId) : ['locked' => [], 'unlocked' => []];
    $crewStmt = $db->prepare(
        'SELECT pc.id, pc.level, pc.xp, pc.status, pc.created_at,'
        . ($statsReady ? ' pc.strength, pc.cunning, pc.science, pc.charisma,' : '')
        . ($fatigueReady ? ' pc.fatigue, pc.fatigue_updated_at,' : '') . '
        ' . ($crewFavoritesReady ? ' pc.is_favorite,' : ' 0 AS is_favorite,') . '
                c.name, c.slug, c.description, c.role, c.portrait_url, c.world_affinity, '
        . ($crewCapacityReady ? 'c.tier,' : '"common" AS tier,') . ' c.is_enabled AS definition_enabled,
                active.id AS active_mission_id, active.status AS active_mission_status,
                active.completes_at AS active_mission_completes_at, active.active_mission_name
         FROM game_player_crew pc
         JOIN game_crew_definitions c ON c.id = pc.crew_definition_id
         LEFT JOIN (
             SELECT link.player_crew_id, pm.id, pm.status, pm.completes_at, md.name AS active_mission_name
             FROM game_player_mission_crew link
             JOIN game_player_missions pm ON pm.id = link.player_mission_id
             JOIN game_mission_definitions md ON md.id = pm.mission_definition_id
             WHERE pm.user_id = ? AND pm.status IN ("active", "completed")
         ) active ON active.player_crew_id = pc.id
         /* A crew member whose definition an administrator has switched off is
          * withdrawn from the roster entirely rather than listed as
          * unavailable. api/missions/start.php has always refused to send one on
          * an operation, so a visible row was a crew member the player could
          * look at and never use. Filtered here rather than hidden in the
          * browser, so the record does not travel to a page that will not show
          * it. Their game_player_crew row is untouched, and re-enabling the
          * definition restores them with their level and XP intact.
          *
          * A run already in the field is unaffected: claim.php joins the
          * definition without this condition, so an operation launched before
          * the switch still completes and still pays out. */
         WHERE pc.user_id = ? AND c.is_enabled = 1 AND pc.status <> "retired"
         ORDER BY c.is_starter DESC, c.role ASC, c.name ASC'
    );
    $crewStmt->execute([$userId, $userId]);
    /* Resolved once for the whole roster: the ceiling is a property of the
     * player (reputation rank plus research), not of the individual, and the
     * clock is read once so every card on one response agrees. */
    $fatigueNow = pw_missions_utc_now($db);
    $fatigueMax = $fatigueReady ? pw_missions_fatigue_max($db, $userId, $researchEffects) : 0;
    /* Research protocols and any running stim both shorten the wait; the rate
     * is resolved once for the whole roster because it is a property of the
     * player, exactly like the ceiling above. */
    $fatigueRecovery = (float)($researchEffects['fatigue_recovery_percent'] ?? 0);
    $crew = array_map(static function ($row) use ($crewFavoritesReady, $fatigueReady, $fatigueMax, $fatigueNow, $fatigueRecovery) {
        foreach (['id', 'level', 'xp'] as $field) $row[$field] = (int)$row[$field];
        $row['definition_enabled'] = (bool)$row['definition_enabled'];
        $row['is_favorite'] = $crewFavoritesReady && !empty($row['is_favorite']);
        $row['active_mission_id'] = $row['active_mission_id'] !== null ? (int)$row['active_mission_id'] : null;
        $row['max_level'] = PW_MISSION_MAX_LEVEL;
        $row['max_stat'] = PW_MISSION_MAX_STAT;
        /* The XP curve is exponential, so the crew card can no longer derive
         * its own progress from a fixed per-level figure. The server resolves
         * it against the same curve the claim path levels from. */
        foreach (pw_missions_xp_progress($row['xp'], $row['level']) as $field => $value) {
            $row[$field] = $value;
        }
        /* Fatigue is sent already caught up to now, with the regeneration rate
         * beside it so the page can run its own countdown between loads rather
         * than polling for a number it can derive. */
        $row['fatigue_ready'] = $fatigueReady;
        $row['fatigue'] = $fatigueReady ? pw_missions_resolve_fatigue($row, $fatigueMax, $fatigueNow, $fatigueRecovery) : 0;
        $row['fatigue_max'] = $fatigueMax;
        $row['fatigue_regen_per_minute'] = pw_missions_fatigue_regen_per_minute($fatigueRecovery);
        unset($row['fatigue_updated_at']);
        return $row;
    }, $crewStmt->fetchAll());

    /* Rebuild automatic stats from role and level before folding in gear. This
     * avoids treating the old stored stat cache as authority for a recruited or
     * previously migrated crew member whose cached values are still zero. */
    $crew = pw_missions_apply_level_stats($crew);
    /* Equipment is then folded into the level-derived values before any effect
     * is computed, so every card and mission projection reads the true total. */
    $crew = pw_missions_apply_gear($db, $userId, $crew);
    foreach ($crew as $index => $row) {
        $crew[$index]['role_effect'] = pw_missions_crew_effects([$row]);
    }
    $crewCapacityUsed = $crewCapacityReady ? pw_missions_active_crew_count($db, $userId) : count($crew);
    $crewCapacity = $crewCapacityReady ? pw_missions_crew_capacity($db, $userId) : 8;
    $pendingCrewOffers = $crewCapacityReady ? pw_missions_pending_crew_offers($db, $userId) : [];

    $claimedStmt = $db->prepare(
        'SELECT mission_definition_id, COUNT(*) AS claimed_count
         FROM game_player_missions
         WHERE user_id = ? AND status = "claimed"
         GROUP BY mission_definition_id'
    );
    $claimedStmt->execute([$userId]);
    $claimedCounts = [];
    foreach ($claimedStmt->fetchAll() as $row) {
        $claimedCounts[(int)$row['mission_definition_id']] = (int)$row['claimed_count'];
    }

    /* Who ran each operation most recently, so the launch screen can offer to
     * field the same team again. Read as one grouped query rather than per
     * mission: the roster reaches 32 with capacity research and the mission
     * list is unbounded, so a per-mission lookup is the N+1 this codebase
     * avoids everywhere else.
     *
     * The latest run is used whatever its outcome -- a failed attempt is still
     * the team the player last chose, and offering only successful ones would
     * quietly refuse to repeat the run they most likely want to retry. */
    $lastCrewByMission = [];
    $lastCrewStmt = $db->prepare(
        'SELECT pm.mission_definition_id, link.player_crew_id
         FROM game_player_mission_crew link
         JOIN game_player_missions pm ON pm.id = link.player_mission_id
         JOIN (
             SELECT mission_definition_id, MAX(id) AS last_id
             FROM game_player_missions WHERE user_id = ?
             GROUP BY mission_definition_id
         ) latest ON latest.last_id = pm.id'
    );
    $lastCrewStmt->execute([$userId]);
    foreach ($lastCrewStmt->fetchAll() as $row) {
        $lastCrewByMission[(int)$row['mission_definition_id']][] = (int)$row['player_crew_id'];
    }

    /* The whole world is loaded, including disabled missions, so the campaign
     * chain keeps its real length even while a mid-chain operation is switched
     * off in Mission Control. Card rendering filters to enabled missions below. */
    $campaignReady = pw_mission_campaign_ready($db);
    $creditsReady = pw_mission_credits_ready($db);
    $watermarkReady = pw_mission_watermark_ready($db);
    $missionsStmt = $db->prepare(
        'SELECT mission.id, mission.world_key, mission.name, mission.slug, mission.description, mission.mission_type, mission.duration_seconds,
                mission.min_crew, mission.max_crew, mission.xp_reward, mission.reputation_reward, mission.sort_order, mission.is_enabled,
                mission.unlocks_after_mission_id, mission.unlocks_after_completion_count,'
        . ($creditsReady ? ' mission.credit_reward,' : ' 0 AS credit_reward,')
        . ($watermarkReady ? ' mission.watermark_url, mission.watermark_opacity,' : ' "" AS watermark_url, 10 AS watermark_opacity,')
        . ($statsReady ? ' mission.base_success_percent, mission.loot_rolls,' : ' 100 AS base_success_percent, 0 AS loot_rolls,')
        . ($campaignReady ? ' mission.is_campaign_final,' : ' 0 AS is_campaign_final,') .
               ' prerequisite.name AS unlocks_after_mission_name
         FROM game_mission_definitions mission
         LEFT JOIN game_mission_definitions prerequisite ON prerequisite.id = mission.unlocks_after_mission_id
         WHERE mission.world_key = "neoh"'
        /* An Overlord contract is withdrawn from the ordinary board: it is only
         * ever offered as the daily contract, to the players it belongs to.
         * Filtered here rather than after the campaign tracks are built, so it
         * can never appear as a step in a chain it was never part of. */
        . ($contractsReady ? ' AND mission.overlord_id IS NULL' : '')
        /* Recovery-pool definitions are issued only after a qualifying Sweep
         * loss. Keeping them out of campaign tracks is the browser-side half
         * of start.php's stricter, offer-backed launch gate. */
        . ($salvageRecoveryContractsReady ? ' AND mission.is_salvage_recovery_contract = 0' : '') . '
         ORDER BY mission.sort_order ASC, mission.id ASC'
    );
    $missionsStmt->execute();
    $worldMissions = array_map(static function ($row) use ($claimedCounts, $fatigueReady, $lastCrewByMission) {
        foreach (['id', 'duration_seconds', 'min_crew', 'max_crew', 'xp_reward', 'reputation_reward', 'credit_reward', 'sort_order', 'unlocks_after_completion_count', 'base_success_percent', 'loot_rolls', 'watermark_opacity'] as $field) $row[$field] = (int)$row[$field];
        // Re-validated on the way out, not only on the way in: this reaches the
        // browser as a CSS url(), so a row edited straight in the database must
        // not be able to put an arbitrary path there.
        $row['watermark_url'] = pw_missions_watermark_url($row['watermark_url']);
        $row['unlocks_after_mission_id'] = $row['unlocks_after_mission_id'] !== null ? (int)$row['unlocks_after_mission_id'] : null;
        $row['is_enabled'] = (bool)$row['is_enabled'];
        $row['is_campaign_final'] = (bool)$row['is_campaign_final'];
        if ($row['unlocks_after_mission_id'] !== null) {
            $row['unlocks_after_completion_count'] = max(1, $row['unlocks_after_completion_count']);
        }
        /* A pure function of the authored duration, so the figure on the card
         * is the figure start.php charges -- see pw_missions_fatigue_cost().
         * Zero until the migration has run, because start.php charges nothing
         * then and a card must not advertise a cost that is not taken. */
        $row['fatigue_cost'] = $fatigueReady ? pw_missions_fatigue_cost($row['duration_seconds']) : 0;
        // Ids only. The launch screen resolves them against the roster it
        // already has, and silently skips anyone since retired or deployed.
        $row['last_crew_ids'] = $lastCrewByMission[(int)$row['id']] ?? [];
        return $row;
    }, $missionsStmt->fetchAll());

    $missionsById = [];
    foreach ($worldMissions as $mission) $missionsById[(int)$mission['id']] = $mission;

    /* Every mission belongs to exactly one track. A one-step track is an
     * ordinary standalone mission and carries no progress bar; a longer track
     * is a campaign that shows one card at a time.
     *
     * Only the current step of a track is ever sent. Every later mission is
     * omitted from the response entirely -- name, slug, description, rewards
     * and crew requirements included -- because sending them and hiding them in
     * CSS would hand every sealed operation to anyone opening the network tab,
     * the same reason api/timeline.php seals a gated event server-side rather
     * than dimming it in the browser. The bar is the only acknowledgement that
     * further operations exist. */
    $publicFields = ['id', 'world_key', 'name', 'slug', 'description', 'mission_type', 'duration_seconds',
        'min_crew', 'max_crew', 'xp_reward', 'reputation_reward', 'credit_reward', 'sort_order', 'base_success_percent', 'loot_rolls',
        'watermark_url', 'watermark_opacity', 'fatigue_cost', 'last_crew_ids'];
    $slots = [];
    foreach (pw_missions_build_campaign_tracks($missionsById) as $chain) {
        $progress = pw_missions_track_progress($chain, $claimedCounts);
        $isCampaign = count($chain) > 1;

        /* A disabled operation is never playable, but the track does not have to
         * go dark for it: it rolls back to the most recent earlier operation
         * that is still enabled, which the player can run again while the next
         * one is off the roster. Progress is unaffected -- that step is already
         * complete, so replaying it neither advances nor rewinds the campaign. */
        $playable = pw_missions_resolve_playable_step($chain, $progress);
        $progress['display_index'] = $playable['index'];
        $progress['rolled_back'] = $playable['rolled_back'];
        $progress['offline_index'] = $playable['offline_index'];
        // The bar marks the offline step so it does not read as the step the
        // player is being asked to run.
        if ($playable['offline_index'] !== null && isset($progress['steps'][$playable['offline_index']])) {
            $progress['steps'][$playable['offline_index']]['state'] = 'offline';
        }

        // Nothing left to offer: every step from the current one back to the
        // start is disabled. A standalone mission simply loses its card;
        // mid-campaign the bar stays so the player still sees where they are.
        if ($playable['index'] === null) {
            if ($isCampaign) {
                $slots[] = [
                    'sort_order' => (int)$chain[$progress['current_index']]['sort_order'],
                    'is_offline' => true,
                    'campaign' => $progress,
                ];
            }
            continue;
        }

        $current = $chain[$playable['index']];
        $slot = [];
        foreach ($publicFields as $field) $slot[$field] = $current[$field];
        $slot['is_offline'] = false;
        $slot['campaign'] = $isCampaign ? $progress : null;
        $slots[] = $slot;
    }
    usort($slots, static function ($a, $b) {
        return [$a['sort_order'], $a['id'] ?? 0] <=> [$b['sort_order'], $b['id'] ?? 0];
    });
    /* A classified operation does not reach the browser until its research
     * protocol is owned. The same check is repeated by start.php, so changing
     * an API payload in devtools cannot launch a sealed mission. */
    $missions = array_values(array_filter($slots, static function ($slot) use ($researchSecrets) {
        return !isset($slot['id']) || !in_array((int)$slot['id'], $researchSecrets['locked'], true);
    }));

    $playerMissionStmt = $db->prepare(
        'SELECT pm.id, pm.world_key, pm.status, pm.started_at, pm.completes_at, pm.completed_at, pm.claimed_at,
                pm.xp_reward, pm.reputation_reward, ' . ($creditsReady ? 'pm.credits_awarded,' : '0 AS credits_awarded,')
        . ($contestedContractsReady
            ? ' pm.is_contested, pm.rival_faction_name, pm.rival_approach, pm.rival_completes_at, pm.rival_outcome, pm.rival_bonus_credits,'
            : ' 0 AS is_contested, "" AS rival_faction_name, "" AS rival_approach, NULL AS rival_completes_at, "" AS rival_outcome, 0 AS rival_bonus_credits,')
        . ($watermarkReady ? ' md.watermark_url, md.watermark_opacity,' : ' "" AS watermark_url, 10 AS watermark_opacity,') . ' md.name, md.slug, md.mission_type,
                (pm.completes_at <= UTC_TIMESTAMP()) AS is_ready,
                GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR "|~|") AS crew_names
         FROM game_player_missions pm
         JOIN game_mission_definitions md ON md.id = pm.mission_definition_id
         LEFT JOIN game_player_mission_crew link ON link.player_mission_id = pm.id
         LEFT JOIN game_player_crew pc ON pc.id = link.player_crew_id
         LEFT JOIN game_crew_definitions c ON c.id = pc.crew_definition_id
         WHERE pm.user_id = ?
         GROUP BY pm.id
         ORDER BY CASE pm.status WHEN "active" THEN 0 WHEN "completed" THEN 1 ELSE 2 END, pm.started_at DESC, pm.id DESC'
    );
    $playerMissionStmt->execute([$userId]);
    $allPlayerMissions = array_map(static function ($row) use ($contestedContractsReady) {
        $row['id'] = (int)$row['id'];
        $row['xp_reward'] = (int)$row['xp_reward'];
        $row['reputation_reward'] = (int)$row['reputation_reward'];
        $row['credits_awarded'] = (int)$row['credits_awarded'];
        $row['is_contested'] = $contestedContractsReady && !empty($row['is_contested']);
        $row['rival_faction_name'] = $row['is_contested'] ? pw_missions_contested_contract_faction($row['rival_faction_name']) : '';
        $row['rival_approach'] = $row['is_contested'] ? (pw_missions_contested_contract_approach($row['rival_approach']) ?? 'secure') : '';
        $row['rival_outcome'] = $row['is_contested'] ? (string)($row['rival_outcome'] ?? '') : '';
        $row['rival_bonus_credits'] = (int)($row['rival_bonus_credits'] ?? 0);
        $row['watermark_opacity'] = (int)$row['watermark_opacity'];
        $row['watermark_url'] = pw_missions_watermark_url($row['watermark_url']);
        $row['is_ready'] = (bool)$row['is_ready'];
        $row['crew_names'] = $row['crew_names'] !== null && $row['crew_names'] !== '' ? explode('|~|', $row['crew_names']) : [];
        return $row;
    }, $playerMissionStmt->fetchAll());
    $active = array_values(array_filter($allPlayerMissions, static function ($mission) {
        return in_array($mission['status'], ['active', 'completed'], true);
    }));
    /* Failed runs belong in the archive too. Without them a mission that fails
     * simply disappears from the page moments after the player is told it
     * failed, which reads as a bug rather than an outcome. */
    $history = array_values(array_filter($allPlayerMissions, static function ($mission) {
        return in_array($mission['status'], ['claimed', 'failed'], true);
    }));

    /* Loot the player already holds. Only ever their own rows, and only the
     * item's public name and tier -- drop weights stay server-side so the pool's
     * odds are never readable from the browser. */
    $loot = [];
    $gearReady = pw_mission_gear_ready($db);
    $stimsReady = pw_mission_stims_ready($db);
    $inventoryWorkbenchReady = pw_mission_inventory_workbench_ready($db);
    /* Preferences are optional metadata. Select neutral values before the
     * migration so the browser has one stable item shape and a cached deploy
     * can never turn a missing table into an overview failure. */
    $workbenchFields = $inventoryWorkbenchReady
        ? ', COALESCE(pref.is_favorite, 0) AS is_favorite, COALESCE(pref.tag_key, "") AS tag_key'
        : ', 0 AS is_favorite, "" AS tag_key';
    $workbenchJoin = $inventoryWorkbenchReady
        ? ' LEFT JOIN game_player_loot_preferences pref ON pref.user_id = pl.user_id AND pref.loot_definition_id = pl.loot_definition_id'
        : '';
    if ($statsReady) {
        /* The gear columns and the equipped count arrive with the gear migration;
         * selected in a separate branch because a missing column is a hard SQL
         * error, not a NULL, so the pre-migration shape has to stay reachable.
         *
         * equipped_count is a correlated subquery rather than a join: a LEFT JOIN
         * on to the gear table would multiply the row per equipped copy and the
         * quantity would have to be de-duplicated back out again. */
        $lootStmt = $db->prepare($gearReady
            ? 'SELECT l.id, l.name, l.slug, l.description, l.tier, pl.quantity, pl.first_acquired_at,
                      l.slot, l.bonus_strength, l.bonus_cunning, l.bonus_science, l.bonus_charisma,
                      l.required_level, l.required_role, l.icon_url,'
               . ($stimsReady ? ' l.stim_effect, l.stim_value, l.stim_duration_seconds,' : ' "" AS stim_effect, 0 AS stim_value, 0 AS stim_duration_seconds,') . '
                      (SELECT COUNT(*) FROM game_player_crew_gear g
                        WHERE g.user_id = pl.user_id AND g.loot_definition_id = l.id) AS equipped_count'
               . $workbenchFields . '
               FROM game_player_loot pl
               JOIN game_loot_definitions l ON l.id = pl.loot_definition_id
               ' . $workbenchJoin . '
               WHERE pl.user_id = ? AND pl.quantity > 0
               ORDER BY FIELD(l.tier, "legendary", "rare", "uncommon", "common"), l.name ASC'
            : 'SELECT l.id, l.name, l.slug, l.description, l.tier, pl.quantity, pl.first_acquired_at'
               . $workbenchFields . '
               FROM game_player_loot pl
               JOIN game_loot_definitions l ON l.id = pl.loot_definition_id
               ' . $workbenchJoin . '
               WHERE pl.user_id = ? AND pl.quantity > 0
               ORDER BY FIELD(l.tier, "legendary", "rare", "uncommon", "common"), l.name ASC');
        $lootStmt->execute([$userId]);
        $loot = array_map(static function ($row) use ($gearReady, $stimsReady, $inventoryWorkbenchReady) {
            $row['id'] = (int)$row['id'];
            $row['quantity'] = (int)$row['quantity'];
            /* One shape either way, so the browser never has to know which
             * migrations have run -- before the gear migration every item simply
             * has no slot and no bonuses. */
            $row['slot'] = $gearReady ? (string)$row['slot'] : '';
            $row['icon_url'] = $gearReady ? pw_missions_gear_icon_url($row['icon_url']) : '';
            $row['required_level'] = $gearReady ? (int)$row['required_level'] : 1;
            $row['required_role'] = $gearReady ? (string)$row['required_role'] : '';
            $row['equipped_count'] = $gearReady ? (int)$row['equipped_count'] : 0;
            /* One shape whichever migrations have run, so the panel never has
             * to branch: before the inventory migration nothing is a stim. */
            $row['stim_effect'] = $stimsReady ? (string)$row['stim_effect'] : '';
            $row['stim_value'] = $stimsReady ? (float)$row['stim_value'] : 0.0;
            $row['stim_duration_seconds'] = $stimsReady ? (int)$row['stim_duration_seconds'] : 0;
            $row['is_favorite'] = $inventoryWorkbenchReady && !empty($row['is_favorite']);
            $row['tag_key'] = $inventoryWorkbenchReady ? (string)$row['tag_key'] : '';
            $row['history'] = [];
            // Resolved on the server so the panel, the caps and the destroy and
            // use endpoints all agree on what each item is.
            $row['category'] = pw_missions_inventory_category($row);
            $row['bonus'] = [
                'strength' => $gearReady ? (int)$row['bonus_strength'] : 0,
                'cunning' => $gearReady ? (int)$row['bonus_cunning'] : 0,
                'science' => $gearReady ? (int)$row['bonus_science'] : 0,
                'charisma' => $gearReady ? (int)$row['bonus_charisma'] : 0,
            ];
            foreach (['bonus_strength', 'bonus_cunning', 'bonus_science', 'bonus_charisma'] as $key) unset($row[$key]);
            return $row;
        }, $lootStmt->fetchAll());
        /* Five newest events per held item is enough to explain provenance
         * without making a long-lived account ship its entire audit trail on
         * every Mission page refresh. Items predating the migration still have
         * first_acquired_at, which the browser labels as legacy stock. */
        if ($inventoryWorkbenchReady && $loot) {
            $lootIds = array_map(static function (array $item) { return (int)$item['id']; }, $loot);
            $historyStmt = $db->prepare(
                'SELECT loot_definition_id, event_type, source_type, source_id, quantity, note, created_at
                 FROM game_player_loot_history
                 WHERE user_id = ? AND loot_definition_id IN (' . pw_missions_placeholders(count($lootIds)) . ')
                 ORDER BY created_at DESC, id DESC
                 LIMIT 1200'
            );
            $historyStmt->execute(array_merge([$userId], $lootIds));
            $historyByLoot = [];
            foreach ($historyStmt->fetchAll() as $event) {
                $definitionId = (int)$event['loot_definition_id'];
                if (count($historyByLoot[$definitionId] ?? []) >= 5) continue;
                $historyByLoot[$definitionId][] = [
                    'event_type' => (string)$event['event_type'],
                    'source_type' => (string)$event['source_type'],
                    'source_id' => $event['source_id'] === null ? null : (int)$event['source_id'],
                    'quantity' => (int)$event['quantity'],
                    'note' => (string)$event['note'],
                    'created_at' => (string)$event['created_at'],
                ];
            }
            foreach ($loot as &$item) $item['history'] = $historyByLoot[(int)$item['id']] ?? [];
            unset($item);
        }
    }

    // What the full available roster would contribute if sent out together --
    // the headline the crew section shows above the individual stat cards.
    $rosterEffects = pw_missions_crew_effects(array_filter($crew, static function ($member) {
        return $member['status'] === 'available' && $member['definition_enabled'];
    }));

    /* The commander card in the right rail. Only ever this player's own record,
     * and only what the card actually draws -- name, reputation standing and
     * balance. The avatar is not sent: it is the site-wide
     * /uploads/avatars/<id>.jpg convention the header chip already uses, so
     * there is nothing here for the server to resolve. */
    $playerReputation = pw_reputation_info((int)($user['reputation'] ?? 0));
    /* The Overlord the quiz aligned this player with. Resolved once and used
     * twice: the command card names them, and the contract pool is drawn from
     * them. Null when the quiz has never been taken. */
    $playerOverlord = pw_missions_overlord_affinity($db, $user['overlord_affinity'] ?? null);
    $player = [
        'id' => $userId,
        'display_name' => $user['display_name'] ?? $user['username'],
        'reputation' => $playerReputation,
        'credits' => $creditsReady ? pw_missions_credit_balance($db, $userId) : 0,
        'credits_ready' => $creditsReady,
        'overlord' => $playerOverlord === null ? null : [
            'slug' => (string)$playerOverlord['slug'],
            'name' => (string)$playerOverlord['name'],
            'epithet' => (string)$playerOverlord['epithet'],
            'accent_color' => (string)$playerOverlord['accent_color'],
            'accent_glow' => (string)$playerOverlord['accent_glow'],
        ],
    ];

    /* Today's contract, with the reason there is not one. A locked rank, a
     * missing quiz result and an Overlord with nothing authored are three
     * different situations, and the card explains which rather than being
     * absent for all three. */
    $overlordContract = pw_missions_daily_overlord_contract(
        $db,
        $userId,
        (int)($playerReputation['level_number'] ?? 0),
        $playerOverlord
    );
    if ($overlordContract['contract']) {
        $row = $overlordContract['contract'];
        foreach (['id', 'duration_seconds', 'min_crew', 'max_crew', 'xp_reward', 'reputation_reward', 'sort_order'] as $field) {
            $row[$field] = (int)($row[$field] ?? 0);
        }
        $row['credit_reward'] = (int)($row['credit_reward'] ?? 0);
        $row['base_success_percent'] = (int)($row['base_success_percent'] ?? 100);
        $row['loot_rolls'] = (int)($row['loot_rolls'] ?? 0);
        $row['watermark_url'] = pw_missions_watermark_url($row['watermark_url'] ?? '');
        $row['watermark_opacity'] = (int)($row['watermark_opacity'] ?? 10);
        $row['is_enabled'] = true;
        $row['is_campaign_final'] = false;
        $row['unlocks_after_mission_id'] = null;
        $row['fatigue_cost'] = $fatigueReady ? pw_missions_fatigue_cost($row['duration_seconds']) : 0;
        $row['last_crew_ids'] = $lastCrewByMission[(int)$row['id']] ?? [];
        $row['is_overlord_contract'] = true;
        $row['is_contested'] = pw_missions_contested_contract_is_enabled($row, $contestedContractsReady);
        $row['rival_faction_name'] = $row['is_contested']
            ? pw_missions_contested_contract_faction($row['rival_faction_name'] ?? '')
            : '';
        /* The same public field list the ordinary board is trimmed to, so a
         * contract cannot leak a column the mission cards never expose. */
        $contractPublic = [];
        $row['requires_overlord_clearance'] = !empty($row['requires_overlord_clearance']);
        foreach (array_merge($publicFields, ['is_overlord_contract', 'is_contested', 'rival_faction_name', 'requires_overlord_clearance']) as $field) {
            if (array_key_exists($field, $row)) $contractPublic[$field] = $row[$field];
        }
        $overlordContract['contract'] = $contractPublic;
        $overlordContract['clearance'] = !empty($row['requires_overlord_clearance'])
            ? pw_missions_overlord_clearance_state($db, $userId, (int)$row['id'])
            : null;
    }

    /* A Sweep lead is its own private card rather than an ordinary board slot.
     * The item information belongs beside the contract because that is the
     * promise the player is deciding whether to risk a crew for. */
    $salvageRecovery = pw_missions_salvage_recovery_contract($db, $userId);
    if ($salvageRecovery['contract']) {
        $row = $salvageRecovery['contract'];
        foreach (['id', 'duration_seconds', 'min_crew', 'max_crew', 'xp_reward', 'reputation_reward', 'sort_order'] as $field) {
            $row[$field] = (int)($row[$field] ?? 0);
        }
        $row['credit_reward'] = (int)($row['credit_reward'] ?? 0);
        $row['base_success_percent'] = (int)($row['base_success_percent'] ?? 100);
        $row['loot_rolls'] = (int)($row['loot_rolls'] ?? 0);
        $row['watermark_url'] = pw_missions_watermark_url($row['watermark_url'] ?? '');
        $row['watermark_opacity'] = (int)($row['watermark_opacity'] ?? 10);
        $row['is_enabled'] = true;
        $row['fatigue_cost'] = $fatigueReady ? pw_missions_fatigue_cost($row['duration_seconds']) : 0;
        $row['last_crew_ids'] = $lastCrewByMission[(int)$row['id']] ?? [];
        $row['is_salvage_recovery_contract'] = true;
        $recoveryPublic = [];
        foreach (array_merge($publicFields, ['is_salvage_recovery_contract', 'recovery_contract_id']) as $field) {
            if (array_key_exists($field, $row)) $recoveryPublic[$field] = $row[$field];
        }
        $salvageRecovery['contract'] = $recoveryPublic;
    }

    // Neoh is the only world with operations today; the helper is world-generic.
    $weatherNow = pw_missions_world_weather($db, 'neoh');
    /* Correct by construction now that the roster query excludes switched-off
     * definitions. It used to count them, so this tile could report crew the
     * launch screen already refused to offer. */
    $availableCrew = count(array_filter($crew, static function ($member) { return $member['status'] === 'available'; }));
    $serverTime = $db->query('SELECT UTC_TIMESTAMP() AS value')->fetch();
    pw_json([
        'ok' => true,
        'world' => ['key' => 'neoh', 'name' => 'Neoh', 'background' => 'images/world-neoh.jpg'],
        /* Today's conditions on Neoh and what they do to an operation, from the
         * same generator the World Record card reads. Null when the world is
         * locked, its profile disabled, or the weather tables absent -- the page
         * simply shows no conditions card and every mission runs unmodified. */
        'weather' => $weatherNow ? array_merge($weatherNow, ['effects' => pw_missions_weather_modifiers($weatherNow)]) : null,
        'server_time' => $serverTime['value'],
        'player' => $player,
        'overlord_contract' => $overlordContract,
        'salvage_recovery_contract' => $salvageRecovery,
        'watermark' => pw_missions_watermark_settings(),
        // Null until the dailies migration has been run; the card stays hidden.
        'daily' => pw_missions_daily_state($db, $userId),
        'stats' => [
            'active_missions' => count($active),
            'available_crew' => $availableCrew,
            // Claimed only. History now also carries failed runs, so counting it
            // here would report a failure as a completed mission.
            'completed_missions' => count(array_filter($allPlayerMissions, static function ($mission) {
                return $mission['status'] === 'claimed';
            })),
            'total_missions' => count($allPlayerMissions),
            'crew_capacity' => $crewCapacity,
        ],
        'crew' => $crew,
        'crew_capacity' => ['ready' => $crewCapacityReady, 'used' => $crewCapacityUsed, 'capacity' => $crewCapacity, 'offers' => $pendingCrewOffers],
        'roster_effects' => $rosterEffects,
        /* The affinity matrix, so the launch screen can label each crew member
         * for the operation being launched and project the result. The rates
         * live on the server; the browser only ever displays them, and every
         * figure it shows is recomputed here at launch and again at claim. */
        'affinity_rules' => pw_missions_affinity_rules(),
        /* The per-level role bonuses, so the crew card and the launch
         * projection read the server's own figures instead of a second copy of
         * them. js/missions.js deliberately re-implements the projection maths
         * -- that copy is documented and intentional -- but the rates it
         * multiplies by must not also be duplicated, because a retune then has
         * to be applied twice and the browser silently disagrees with the
         * server until it is. */
        'role_rates' => pw_missions_role_rates(),
        // Rarity's addition to those rates, shipped for the same reason: the
        // browser re-implements the projection and must not hold its own copy
        // of a number a retune would move on the server only.
        'crew_tier_bonus' => array_map(static function (array $profile) { return $profile['role_bonus_add']; }, pw_missions_crew_tier_profile()),
        'stat_reference' => pw_missions_stat_reference(),
        /* The loadout's slots, in the order it draws them, and the ceiling a
         * stat can reach with equipment on. Sent rather than hardcoded in the
         * browser so the seven slots have one definition, on the server that
         * enforces them. Empty until the gear migration has been run, which is
         * how the page knows to leave loadouts out entirely. */
        'gear_slots' => pw_mission_gear_ready($db)
            ? array_map(static function ($key, $label) { return ['key' => $key, 'label' => $label]; },
                array_keys(pw_missions_gear_slots()), array_values(pw_missions_gear_slots()))
            : [],
        'gear_ready' => pw_mission_gear_ready($db),
        'max_gear_stat' => PW_MISSION_MAX_GEAR_STAT,
        'loot' => $loot,
        'inventory_workbench_ready' => $inventoryWorkbenchReady,
        /* Two independent ceilings and how full each is, plus the running
         * boosts. Sent even when nothing is held so the quartermaster card can
         * always state the limits it is about to enforce. */
        'inventory' => array_merge(pw_missions_inventory_usage($db, $userId, $researchEffects), [
            'stims_ready' => $stimsReady,
            'stim_effect_types' => pw_missions_stim_effect_types(),
            'active_stims' => pw_missions_active_stims($db, $userId),
        ]),
        // The quick-slot belt in the right rail, always the full grid so the
        // player can see what a Quick slots protocol bought them.
        'stim_slots' => pw_missions_stim_slots($db, $userId, $researchEffects),
        'stats_ready' => $statsReady,
        'crew_favorites_ready' => $crewFavoritesReady,
        'research' => [
            'ready' => $researchReady,
            'effects' => $researchEffects,
            /* How many protocols the player could activate right now, for the
             * alert on the Research Facility card. Only a count -- which nodes
             * they are is the Research Facility's own business, and sending the
             * list here would leak the final branch's contents to a player who
             * has not opened it. */
            'unlockable_count' => $researchReady ? count(pw_research_unlockable_node_ids($db, $userId)) : 0,
            'unlocked_secret_mission_count' => count($researchSecrets['unlocked']),
        ],
        'missions' => $missions,
        'active_missions' => $active,
        'history' => array_slice($history, 0, 30),
    ]);
} catch (Throwable $e) {
    pw_error('Could not load your mission command view.', 500);
}
