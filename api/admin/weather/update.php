<?php
/** Create or update a deterministic fictional weather profile. */
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('weather.edit');
$input = pw_input();
pw_require_csrf($input);

function pw_weather_text_input($input, $key, $maximum, $required = true) {
    $value = isset($input[$key]) ? trim((string)$input[$key]) : '';
    if (($required && $value === '') || mb_strlen($value) > $maximum) {
        pw_error('Check the ' . str_replace('_', ' ', $key) . ' field.');
    }
    return $value;
}

function pw_weather_number_input($input, $key, $minimum, $maximum) {
    if (!isset($input[$key]) || filter_var($input[$key], FILTER_VALIDATE_INT) === false) {
        pw_error('Check the ' . str_replace('_', ' ', $key) . ' value.');
    }
    $value = (int)$input[$key];
    if ($value < $minimum || $value > $maximum) {
        pw_error(ucfirst(str_replace('_', ' ', $key)) . ' must be between ' . $minimum . ' and ' . $maximum . '.');
    }
    return $value;
}

$worldId = isset($input['world_id']) ? (int)$input['world_id'] : 0;
if ($worldId <= 0) pw_error('Choose a world.');

$db = pw_db();
$worldStmt = $db->prepare('SELECT id, name, slug FROM worlds WHERE id = ?');
$worldStmt->execute([$worldId]);
$world = $worldStmt->fetch();
if (!$world) pw_error('World not found.', 404);

$location = pw_weather_text_input($input, 'location_label', 120);
$climate = pw_weather_text_input($input, 'climate_label', 160);
$currentCondition = pw_weather_text_input($input, 'current_condition', 80);
$currentSecondary = pw_weather_text_input($input, 'current_secondary', 120, false);
$tomorrowCondition = pw_weather_text_input($input, 'tomorrow_condition', 80);
$hazardNote = pw_weather_text_input($input, 'hazard_note', 255, false);
$currentTemp = pw_weather_number_input($input, 'current_temp_c', -100, 100);
$tomorrowTemp = pw_weather_number_input($input, 'tomorrow_temp_c', -100, 100);
$forecastMin = pw_weather_number_input($input, 'forecast_min_c', -100, 100);
$forecastMax = pw_weather_number_input($input, 'forecast_max_c', -100, 100);
$humidityMin = pw_weather_number_input($input, 'humidity_min', 0, 100);
$humidityMax = pw_weather_number_input($input, 'humidity_max', 0, 100);
$precipitationMin = pw_weather_number_input($input, 'precipitation_min', 0, 100);
$precipitationMax = pw_weather_number_input($input, 'precipitation_max', 0, 100);
$windMin = pw_weather_number_input($input, 'wind_min_kph', 0, 500);
$windMax = pw_weather_number_input($input, 'wind_max_kph', 0, 500);

if ($forecastMin > $forecastMax || $humidityMin > $humidityMax || $precipitationMin > $precipitationMax || $windMin > $windMax) {
    pw_error('Every minimum value must be lower than or equal to its maximum.');
}

$rawConditions = isset($input['conditions']) && is_array($input['conditions']) ? $input['conditions'] : [];
$conditions = [];
foreach ($rawConditions as $condition) {
    $condition = trim((string)$condition);
    if ($condition !== '' && mb_strlen($condition) <= 80 && !in_array($condition, $conditions, true)) {
        $conditions[] = $condition;
    }
}
if (count($conditions) < 2 || count($conditions) > 12) {
    pw_error('Add between 2 and 12 distinct forecast conditions.');
}

$stmt = $db->prepare(
    'INSERT INTO world_weather_profiles (
        world_id, enabled, location_label, climate_label,
        current_condition, current_secondary, current_temp_c,
        tomorrow_condition, tomorrow_temp_c,
        forecast_min_c, forecast_max_c,
        humidity_min, humidity_max, precipitation_min, precipitation_max,
        wind_min_kph, wind_max_kph, condition_pool_json, hazard_note,
        forecast_revision, updated_by
     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
     ON DUPLICATE KEY UPDATE
        enabled = VALUES(enabled), location_label = VALUES(location_label),
        climate_label = VALUES(climate_label), current_condition = VALUES(current_condition),
        current_secondary = VALUES(current_secondary), current_temp_c = VALUES(current_temp_c),
        tomorrow_condition = VALUES(tomorrow_condition), tomorrow_temp_c = VALUES(tomorrow_temp_c),
        forecast_min_c = VALUES(forecast_min_c), forecast_max_c = VALUES(forecast_max_c),
        humidity_min = VALUES(humidity_min), humidity_max = VALUES(humidity_max),
        precipitation_min = VALUES(precipitation_min), precipitation_max = VALUES(precipitation_max),
        wind_min_kph = VALUES(wind_min_kph), wind_max_kph = VALUES(wind_max_kph),
        condition_pool_json = VALUES(condition_pool_json), hazard_note = VALUES(hazard_note),
        forecast_revision = forecast_revision + 1, updated_by = VALUES(updated_by)'
);
$stmt->execute([
    $worldId, !empty($input['enabled']) ? 1 : 0, $location, $climate,
    $currentCondition, $currentSecondary, $currentTemp,
    $tomorrowCondition, $tomorrowTemp, $forecastMin, $forecastMax,
    $humidityMin, $humidityMax, $precipitationMin, $precipitationMax,
    $windMin, $windMax, json_encode($conditions, JSON_UNESCAPED_UNICODE),
    $hazardNote, (int)$adminUser['id'],
]);

pw_log_admin_activity('weather_profile_updated', 'Updated the weather profile for ' . $world['name'] . '.', $adminUser);
pw_json(['ok' => true]);
