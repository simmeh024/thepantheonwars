<?php
// Reputation Control's temporary multiplier and its read-only reward ledger.
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    pw_require_permission('reputation.view');
    pw_json([
        'ok' => true,
        'rewards' => pw_reputation_reward_catalog(),
        'multiplier' => pw_reputation_multiplier_config(),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('reputation.edit');
$input = pw_input();
pw_require_csrf($input);

$multiplier = isset($input['multiplier']) ? (int)$input['multiplier'] : 1;
$endsAtInput = isset($input['ends_at']) ? trim((string)$input['ends_at']) : '';
if (!in_array($multiplier, [1, 2, 3, 4], true)) {
    pw_error('Choose a 1x, 2x, 3x, or 4x multiplier.');
}

$endsAt = null;
if ($multiplier > 1) {
    if ($endsAtInput === '') {
        pw_error('Choose an end date and time for the reputation event.');
    }
    try {
        $date = new DateTimeImmutable($endsAtInput);
        $date = $date->setTimezone(new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        pw_error('Choose a valid end date and time.');
    }
    if ($date->getTimestamp() <= time()) {
        pw_error('The event end time must be in the future.');
    }
    $endsAt = $date->format('Y-m-d H:i:s');
}

$db = pw_db();
$save = $db->prepare(
    'INSERT INTO app_settings (`key`, value) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = CURRENT_TIMESTAMP'
);
$save->execute(['reputation_multiplier', (string)$multiplier]);
$save->execute(['reputation_multiplier_ends_at', $endsAt]);

$description = $multiplier > 1
    ? 'Enabled ' . $multiplier . 'x reputation rewards until ' . $endsAt . ' UTC.'
    : 'Disabled the temporary reputation multiplier.';
pw_log_admin_activity('reputation_multiplier_updated', $description, $adminUser);

pw_json([
    'ok' => true,
    'rewards' => pw_reputation_reward_catalog(),
    'multiplier' => pw_reputation_multiplier_config(),
]);
