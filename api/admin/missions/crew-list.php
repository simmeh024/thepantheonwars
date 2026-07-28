<?php
require_once __DIR__ . '/missions-helpers.php';

pw_require_permission('missions.view');
$db = pw_db(); pw_admin_missions_require_ready($db);
$capacityReady = pw_mission_crew_capacity_ready($db);
$rows = $db->query(
    'SELECT c.id, c.name, c.slug, c.description, c.role, c.portrait_url, c.starting_level, c.world_affinity, '
    . ($capacityReady ? 'c.tier,' : '"common" AS tier,') . '
            c.is_starter, c.is_enabled, c.created_at, c.updated_at, COUNT(pc.id) AS player_count
     FROM game_crew_definitions c
     LEFT JOIN game_player_crew pc ON pc.crew_definition_id = c.id
     GROUP BY c.id
     ORDER BY c.is_starter DESC, c.role ASC, c.name ASC'
)->fetchAll();
/* Shared across the whole list, so one load resizes at most this many images
 * and the rest keep their full-size URL until the next one. */
$thumbnailBudget = 12;
$rows = array_map(static function ($row) use (&$thumbnailBudget) {
    $row['id'] = (int)$row['id']; $row['starting_level'] = (int)$row['starting_level']; $row['player_count'] = (int)$row['player_count'];
    $row['is_starter'] = (bool)$row['is_starter']; $row['is_enabled'] = (bool)$row['is_enabled'];
    $row['portrait_thumb_url'] = pw_mission_thumbnail_for((string)$row['portrait_url'], $thumbnailBudget);
    return $row;
}, $rows);
/* The engine's own role list, not the roles present in the roster: a role with
 * no crew yet should still offer a filter button, which is how an administrator
 * sees that the role exists and has nothing authored for it. Same source the
 * crew validator uses, so the two cannot drift. */
pw_json([
    'ok' => true,
    'crew' => $rows,
    'crew_capacity_ready' => $capacityReady,
    'roles' => array_keys(pw_missions_role_rates()),
]);
