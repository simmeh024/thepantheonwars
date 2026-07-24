<?php
require_once __DIR__ . '/../../missions/missions-helpers.php';

function pw_admin_missions_require_ready(PDO $db): void {
    pw_missions_require_ready($db);
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
    $sortOrder = filter_var($input['sort_order'] ?? 0, FILTER_VALIDATE_INT);
    if ($sortOrder === false || $sortOrder < 0 || $sortOrder > 100000) pw_error('Sort order must be between 0 and 100000.');
    return [
        'name' => $name, 'slug' => $slug, 'world_key' => $worldKey, 'description' => $description,
        'mission_type' => $missionType, 'duration_seconds' => $duration, 'min_crew' => $minCrew, 'max_crew' => $maxCrew,
        'xp_reward' => $xpReward, 'reputation_reward' => $reputationReward,
        'is_enabled' => !empty($input['is_enabled']) ? 1 : 0, 'sort_order' => $sortOrder,
    ];
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
