<?php
require_once __DIR__ . '/../../missions/missions-helpers.php';

function pw_admin_missions_require_ready(PDO $db): void {
    pw_missions_require_ready($db);
}

function pw_admin_mission_successions_require_ready(PDO $db): void {
    pw_missions_require_successions_ready($db);
}

function pw_admin_mission_definition_input(array $input): array {
    $name = trim((string)($input['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 150) pw_error('Mission name must be between 1 and 150 characters.');
    $slug = trim((string)($input['slug'] ?? ''));
    if (!preg_match('/\A[a-z0-9][a-z0-9-]{0,149}\z/', $slug)) pw_error('Mission slug may use lowercase letters, numbers, and hyphens only.');
    $worldKey = trim((string)($input['world_key'] ?? 'neoh'));
    if ($worldKey !== 'neoh') pw_error('Neoh is the only playable mission world in V0.');
    $description = trim((string)($input['description'] ?? ''));
    if ($description === '' || mb_strlen($description) > 2000) pw_error('Mission description must be between 1 and 2,000 characters.');
    $missionType = strtolower(trim((string)($input['mission_type'] ?? '')));
    if (!in_array($missionType, ['survey', 'salvage', 'recon'], true)) pw_error('Choose survey, salvage, or recon as the mission type.');
    $duration = filter_var($input['duration_seconds'] ?? null, FILTER_VALIDATE_INT);
    if ($duration === false || $duration < 60 || $duration > 86400) pw_error('Mission duration must be between one minute and 24 hours.');
    $minCrew = filter_var($input['min_crew'] ?? null, FILTER_VALIDATE_INT);
    $maxCrew = filter_var($input['max_crew'] ?? null, FILTER_VALIDATE_INT);
    if ($minCrew === false || $maxCrew === false || $minCrew < 1 || $maxCrew < $minCrew || $maxCrew > 8) {
        pw_error('Crew limits must be between 1 and 8, with a maximum no lower than the minimum.');
    }
    $xpReward = filter_var($input['xp_reward'] ?? 0, FILTER_VALIDATE_INT);
    $reputationReward = filter_var($input['reputation_reward'] ?? 0, FILTER_VALIDATE_INT);
    if ($xpReward === false || $xpReward < 0 || $xpReward > 10000 || $reputationReward === false || $reputationReward < 0 || $reputationReward > 1000) {
        pw_error('Mission rewards are outside the allowed range.');
    }
    $creditReward = filter_var($input['credit_reward'] ?? 0, FILTER_VALIDATE_INT);
    if ($creditReward === false || $creditReward < 0 || $creditReward > 1000000) pw_error('Credit reward must be between 0 and 1,000,000.');
    /* Same closed allow-list and the same image library as the page-wide
     * watermark. An unrecognised path is rejected rather than silently blanked,
     * so a mistyped filename is reported instead of appearing to save. */
    $rawWatermark = trim((string)($input['watermark_url'] ?? ''));
    $watermarkUrl = pw_missions_watermark_url($rawWatermark);
    if ($rawWatermark !== '' && $watermarkUrl === '') {
        pw_error('Choose a mission watermark from the image library, or upload a new one.');
    }
    $watermarkOpacity = filter_var($input['watermark_opacity'] ?? 10, FILTER_VALIDATE_INT);
    if ($watermarkOpacity === false || $watermarkOpacity < 1 || $watermarkOpacity > 40) pw_error('Watermark strength must be between 1% and 40%.');
    $sortOrder = filter_var($input['sort_order'] ?? 0, FILTER_VALIDATE_INT);
    if ($sortOrder === false || $sortOrder < 0 || $sortOrder > 100000) pw_error('Sort order must be between 0 and 100000.');
    $baseSuccess = filter_var($input['base_success_percent'] ?? 100, FILTER_VALIDATE_INT);
    if ($baseSuccess === false || $baseSuccess < 1 || $baseSuccess > 100) pw_error('Base success chance must be between 1% and 100%.');
    $lootRolls = filter_var($input['loot_rolls'] ?? 0, FILTER_VALIDATE_INT);
    if ($lootRolls === false || $lootRolls < 0 || $lootRolls > 10) pw_error('Loot rolls must be between 0 and 10.');
    $unlocksAfterRaw = trim((string)($input['unlocks_after_mission_id'] ?? ''));
    $unlocksAfterMissionId = null;
    if ($unlocksAfterRaw !== '') {
        $unlocksAfterMissionId = filter_var($unlocksAfterRaw, FILTER_VALIDATE_INT);
        if ($unlocksAfterMissionId === false || $unlocksAfterMissionId < 1) pw_error('Choose a valid mission prerequisite.');
    }
    $unlocksAfterCompletionCount = $unlocksAfterMissionId === null ? 0 : filter_var($input['unlocks_after_completion_count'] ?? null, FILTER_VALIDATE_INT);
    if ($unlocksAfterMissionId !== null && ($unlocksAfterCompletionCount === false || $unlocksAfterCompletionCount < 1 || $unlocksAfterCompletionCount > 999)) {
        pw_error('A mission succession requires between 1 and 999 completed runs.');
    }
    return [
        'name' => $name, 'slug' => $slug, 'world_key' => $worldKey, 'description' => $description,
        'mission_type' => $missionType, 'duration_seconds' => $duration, 'min_crew' => $minCrew, 'max_crew' => $maxCrew,
        'xp_reward' => $xpReward, 'reputation_reward' => $reputationReward, 'credit_reward' => $creditReward,
        'watermark_url' => $watermarkUrl, 'watermark_opacity' => $watermarkOpacity,
        'is_enabled' => !empty($input['is_enabled']) ? 1 : 0, 'sort_order' => $sortOrder,
        'unlocks_after_mission_id' => $unlocksAfterMissionId,
        'unlocks_after_completion_count' => $unlocksAfterCompletionCount,
        'is_campaign_final' => !empty($input['is_campaign_final']) ? 1 : 0,
        'base_success_percent' => $baseSuccess,
        'loot_rolls' => $lootRolls,
    ];
}

/**
 * A finale ends the succession chain it sits on, and a world may run several
 * independent chains, so the flag is unique per *chain* rather than per world.
 * Flagging a new finale clears any other flag on the same chain instead of
 * rejecting the save -- last write wins, which is how an administrator
 * actually re-plans an ending. A finale on an unrelated chain is untouched.
 */
function pw_admin_apply_campaign_final(PDO $db, int $missionId, array $data): void {
    if (!$data['is_campaign_final']) return;

    // Walk back to the chain root, then forward to its tip, collecting every
    // mission that shares this chain. Both walks guard against a malformed
    // cycle so a damaged row can never spin here.
    $chainIds = [];
    $back = $db->prepare('SELECT unlocks_after_mission_id FROM game_mission_definitions WHERE id = ?');
    $cursor = $missionId;
    while ($cursor !== null && !isset($chainIds[$cursor])) {
        $chainIds[$cursor] = true;
        $back->execute([$cursor]);
        $row = $back->fetch();
        $cursor = $row && $row['unlocks_after_mission_id'] !== null ? (int)$row['unlocks_after_mission_id'] : null;
    }
    $forward = $db->prepare('SELECT id FROM game_mission_definitions WHERE unlocks_after_mission_id = ? ORDER BY sort_order ASC, id ASC');
    $pending = [$missionId];
    while ($pending) {
        $current = array_shift($pending);
        $forward->execute([$current]);
        foreach ($forward->fetchAll() as $row) {
            $id = (int)$row['id'];
            if (isset($chainIds[$id])) continue;
            $chainIds[$id] = true;
            $pending[] = $id;
        }
    }

    unset($chainIds[$missionId]);
    if (!$chainIds) return;
    $ids = array_keys($chainIds);
    $stmt = $db->prepare(
        'UPDATE game_mission_definitions SET is_campaign_final = 0
         WHERE is_campaign_final = 1 AND id IN (' . pw_missions_placeholders(count($ids)) . ')'
    );
    $stmt->execute($ids);
}

/**
 * Successor links form a directed chain. A mission may not use itself, a
 * different world, or any of its own successors as the prerequisite.
 */
function pw_admin_validate_mission_succession(PDO $db, ?int $missionId, array $data): void {
    $prerequisiteId = $data['unlocks_after_mission_id'];
    if ($prerequisiteId === null) return;
    if ($missionId !== null && $prerequisiteId === $missionId) pw_error('A mission cannot unlock after itself.');

    $lookup = $db->prepare('SELECT id, world_key, unlocks_after_mission_id FROM game_mission_definitions WHERE id = ?');
    $cursor = $prerequisiteId;
    $visited = [];
    while ($cursor !== null) {
        if (isset($visited[$cursor])) pw_error('Mission succession cannot contain a loop.');
        $visited[$cursor] = true;
        if ($missionId !== null && $cursor === $missionId) pw_error('A mission cannot unlock after one of its own successors.');
        $lookup->execute([$cursor]);
        $mission = $lookup->fetch();
        if (!$mission) pw_error('The selected prerequisite mission no longer exists.', 404);
        if ($mission['world_key'] !== $data['world_key']) pw_error('A mission can only follow another mission in the same world.');
        $cursor = $mission['unlocks_after_mission_id'] !== null ? (int)$mission['unlocks_after_mission_id'] : null;
    }
}

function pw_admin_mission_crew_portrait($value): string {
    $portrait = trim((string)$value);
    if ($portrait === '') return '';
    if (!preg_match('~^(?:images/[a-zA-Z0-9._-]{1,220}|/uploads/mission-crew-images/img_[a-f0-9]{16}\.jpg)$~', $portrait)) {
        pw_error('Choose a portrait from the mission crew image library.');
    }
    return $portrait;
}

function pw_admin_mission_crew_input(array $input): array {
    $name = trim((string)($input['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 100) pw_error('Crew name must be between 1 and 100 characters.');
    $slug = trim((string)($input['slug'] ?? ''));
    if (!preg_match('/\A[a-z0-9][a-z0-9-]{0,99}\z/', $slug)) pw_error('Crew slug may use lowercase letters, numbers, and hyphens only.');
    $role = trim((string)($input['role'] ?? ''));
    if (!in_array($role, ['Vanguard', 'Pathfinder', 'Engineer'], true)) pw_error('Choose Vanguard, Pathfinder, or Engineer as the crew role.');
    $description = trim((string)($input['description'] ?? ''));
    if ($description === '' || mb_strlen($description) > 2000) pw_error('Crew description must be between 1 and 2,000 characters.');
    $startingLevel = filter_var($input['starting_level'] ?? 1, FILTER_VALIDATE_INT);
    if ($startingLevel === false || $startingLevel < 1 || $startingLevel > 99) pw_error('Starting level must be between 1 and 99.');
    $worldAffinity = trim((string)($input['world_affinity'] ?? 'neoh'));
    if ($worldAffinity !== 'neoh') pw_error('Neoh is the only crew affinity in V0.');
    return [
        'name' => $name, 'slug' => $slug, 'role' => $role, 'description' => $description,
        'portrait_url' => pw_admin_mission_crew_portrait($input['portrait_url'] ?? ''),
        'starting_level' => $startingLevel, 'world_affinity' => $worldAffinity,
        'is_starter' => !empty($input['is_starter']) ? 1 : 0,
        'is_enabled' => !empty($input['is_enabled']) ? 1 : 0,
    ];
}
