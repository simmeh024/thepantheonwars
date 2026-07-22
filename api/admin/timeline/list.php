<?php
/**
 * Admin listing for Timeline Control (Lore Management > Timeline Control).
 * Small, fixed-size dataset, same flat unpaginated pattern as Known Figures
 * Control's own list.php. Joins the reputation level so the list can show
 * which events are gated without a second request.
 */
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('timeline.view');
$db = pw_db();

$rows = $db->query(
    'SELECT t.id, t.slug, t.title, t.era_label, t.date_label, t.summary, t.body,
            t.image_url, t.accent_color, t.required_level_id, t.is_published, t.sort_order,
            r.name AS required_level_name, r.threshold AS required_level_threshold
     FROM timeline_events t
     LEFT JOIN reputation_levels r ON r.id = t.required_level_id
     ORDER BY t.sort_order ASC, t.id ASC'
)->fetchAll();

$out = array_map(function ($r) {
    $r['id'] = (int)$r['id'];
    $r['is_published'] = (bool)$r['is_published'];
    $r['sort_order'] = (int)$r['sort_order'];
    $r['required_level_id'] = $r['required_level_id'] !== null ? (int)$r['required_level_id'] : null;
    $r['required_level_threshold'] = $r['required_level_threshold'] !== null ? (int)$r['required_level_threshold'] : null;
    return $r;
}, $rows);

pw_json(['ok' => true, 'events' => $out]);
