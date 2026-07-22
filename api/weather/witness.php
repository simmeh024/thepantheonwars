<?php
/**
 * Records that a member was present for severe weather on a world.
 *
 * The severity is re-computed here from the world's own profile rather than
 * accepted from the caller. This is a separate entry point from the public
 * weather feed, so without that re-check a crafted POST could claim the award
 * on a calm day -- the same reasoning as the Timeline discovery endpoint
 * re-checking its reputation gate rather than trusting the list response.
 *
 * Awarded once per world, not once per storm: witnessing a world at its worst
 * is the collectible moment, and re-awarding every roll would make it an
 * attendance prize.
 */
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../weather-forecast.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);

$slug = isset($input['slug']) ? trim((string)$input['slug']) : '';
if (!preg_match('/^[a-z0-9-]{1,50}$/', $slug)) {
    pw_error('Choose a valid world.', 400);
}

$db = pw_db();
$stmt = $db->prepare(
    'SELECT w.id, w.name, w.status,
            p.enabled, p.location_label, p.climate_label,
            p.current_condition, p.current_secondary, p.current_temp_c,
            p.tomorrow_condition, p.tomorrow_temp_c,
            p.forecast_min_c, p.forecast_max_c,
            p.humidity_min, p.humidity_max,
            p.precipitation_min, p.precipitation_max,
            p.wind_min_kph, p.wind_max_kph,
            p.condition_pool_json, p.hazard_note, p.forecast_revision
     FROM worlds w
     LEFT JOIN world_weather_profiles p ON p.world_id = w.id
     WHERE w.slug = ?'
);
$stmt->execute([$slug]);
$profile = $stmt->fetch();

// A locked world or a disabled profile is not witnessable, same gate as every
// other public weather read.
if (!$profile || $profile['status'] !== 'available' || $profile['enabled'] === null || (int)$profile['enabled'] !== 1) {
    pw_error('That world is unavailable.', 404);
}

$forecast = pw_build_weather_forecast($profile, $slug);
$severity = $forecast['current']['severity'];
if (empty($severity['severe'])) {
    // Not an error: the visitor simply arrived on a calm day.
    pw_json(['ok' => true, 'severe' => false, 'awarded' => 0]);
}

$worldId = (int)$profile['id'];
try {
    $db->beginTransaction();
    $discover = $db->prepare('INSERT IGNORE INTO user_lore_discoveries (user_id, entity_type, entity_id) VALUES (?, ?, ?)');
    $discover->execute([(int)$user['id'], 'severe_weather', $worldId]);
    $first = $discover->rowCount() === 1;

    $awarded = 0;
    if ($first) {
        $awarded = pw_award_reputation($db, (int)$user['id'], 4, 'severe_weather_witnessed', [
            'source_type' => 'severe_weather',
            'source_id' => $worldId,
            'note' => $severity['label'] . ' on ' . $profile['name'],
        ]);
    }
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    // sql/migration_weather_severe.sql may not have been run yet. The alert on
    // the card is the reader-facing part and stands on its own, so this stays
    // quiet rather than surfacing an error over a decorative award.
    pw_json(['ok' => true, 'severe' => true, 'awarded' => 0, 'recorded' => false]);
}

if ($first) {
    // Names the world and what was severe, but is only ever sent to the member
    // who was actually there -- it is a record of their own visit.
    // Positional: (userId, type, actorUserId, topicId, commentId, reportId,
    // excerpt, worldId). The world goes in the eighth slot, not the sixth.
    pw_notify(
        (int)$user['id'],
        'weather_alert',
        null,
        null,
        null,
        null,
        $severity['label'] . ' on ' . $profile['name'],
        $worldId
    );
}

pw_json([
    'ok' => true,
    'severe' => true,
    'awarded' => $awarded,
    'first_witness' => $first,
    'label' => $severity['label'],
]);
