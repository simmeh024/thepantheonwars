<?php
/**
 * Admin listing for Reputation Levels (Community > Reputation Levels).
 * Small, fixed-size dataset ordered by threshold ascending -- the same flat
 * unpaginated pattern as Soundtrack Control / Known Figures Control.
 */
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('reputation.view');
$db = pw_db();

$rows = $db->query('SELECT id, name, threshold, color FROM reputation_levels ORDER BY threshold ASC')->fetchAll();

$out = array_map(function ($r, $index) use ($db) {
    $r['id'] = (int)$r['id'];
    $r['threshold'] = (int)$r['threshold'];
    $r['unlocks'] = pw_reputation_level_unlocks($db, $r, $index + 1);
    $r['unlock_count'] = count($r['unlocks']);
    return $r;
}, $rows, array_keys($rows));

pw_json(['ok' => true, 'levels' => $out]);
