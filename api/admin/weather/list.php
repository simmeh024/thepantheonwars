<?php
/** Weather Control profile list. The UI currently exposes Neoh as a pilot. */
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('weather.view');
$db = pw_db();

// current_auto/tomorrow_auto arrive with sql/migration_weather_auto_forecast.sql,
// same guarded-optional-column pattern used by every other weather endpoint --
// a missing column is a hard SQL error, so Weather Control must keep loading
// before that migration has been run.
$autoColumns = ', p.current_auto, p.tomorrow_auto';
$baseSelect =
    'SELECT w.id AS world_id, w.slug, w.name, w.status AS world_status,
            p.id, p.enabled, p.location_label, p.climate_label,
            p.current_condition, p.current_secondary, p.current_temp_c,
            p.tomorrow_condition, p.tomorrow_temp_c%s,
            p.forecast_min_c, p.forecast_max_c,
            p.humidity_min, p.humidity_max,
            p.precipitation_min, p.precipitation_max,
            p.wind_min_kph, p.wind_max_kph,
            p.condition_pool_json, p.hazard_note, p.forecast_revision, p.updated_at
     FROM worlds w
     LEFT JOIN world_weather_profiles p ON p.world_id = w.id
     ORDER BY w.sort_order ASC';
try {
    $rows = $db->query(sprintf($baseSelect, $autoColumns))->fetchAll();
} catch (PDOException $e) {
    $rows = $db->query(sprintf($baseSelect, ''))->fetchAll();
}

$profiles = array_map(function ($row) {
    $numericFields = [
        'world_id', 'id', 'enabled', 'current_temp_c', 'tomorrow_temp_c',
        'current_auto', 'tomorrow_auto',
        'forecast_min_c', 'forecast_max_c', 'humidity_min', 'humidity_max',
        'precipitation_min', 'precipitation_max', 'wind_min_kph',
        'wind_max_kph', 'forecast_revision',
    ];
    foreach ($numericFields as $field) {
        if (isset($row[$field])) $row[$field] = (int)$row[$field];
    }
    $conditions = json_decode(isset($row['condition_pool_json']) ? $row['condition_pool_json'] : '[]', true);
    $row['conditions'] = is_array($conditions) ? array_values($conditions) : [];
    unset($row['condition_pool_json']);
    return $row;
}, $rows);

pw_json(['ok' => true, 'profiles' => $profiles]);
