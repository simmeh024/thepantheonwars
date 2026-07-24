<?php
/**
 * Admin listing for Lore Management > Overlord Transmission Control.
 * Every Overlord is returned, including one created after the initial seed,
 * so an editor can configure a transmission without first creating a row.
 */
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('transmissions.view');
$db = pw_db();

try {
    $ready = (bool)$db->query("SHOW TABLES LIKE 'overlord_transmissions'")->fetch();
    if (!$ready) {
        pw_error('Overlord Transmission Control needs its database migration before it can be used.', 503);
    }

    $rows = $db->query(
        'SELECT o.id AS overlord_id, o.slug, o.name, o.epithet, o.status AS overlord_status,
                t.id AS transmission_id, COALESCE(t.is_enabled, 1) AS is_enabled,
                COALESCE(t.opening_message, \'\') AS opening_message,
                COALESCE(t.followup_message, \'\') AS followup_message,
                COALESCE(t.typing_delay_ms, 700) AS typing_delay_ms
         FROM overlords o
         LEFT JOIN overlord_transmissions t ON t.overlord_id = o.id
         ORDER BY o.sort_order ASC, o.id ASC'
    )->fetchAll();
} catch (Throwable $e) {
    pw_error('Could not load Overlord transmissions.', 500);
}

$out = array_map(function ($row) {
    $row['overlord_id'] = (int)$row['overlord_id'];
    $row['transmission_id'] = $row['transmission_id'] !== null ? (int)$row['transmission_id'] : null;
    $row['is_enabled'] = (bool)$row['is_enabled'];
    $row['typing_delay_ms'] = max(250, min(4000, (int)$row['typing_delay_ms']));
    return $row;
}, $rows);

pw_json(['ok' => true, 'transmissions' => $out]);
