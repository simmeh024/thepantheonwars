<?php
/**
 * Admin listing for Soundtrack Control (Lore Management > Soundtrack
 * Control). Small, fixed-size dataset, same flat unpaginated pattern as
 * Known Figures Control / Overlord Control's own list.php.
 */
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('soundtracks.view');
$db = pw_db();

$rows = $db->query(
    'SELECT id, eyebrow, heading, description, spotify_url,
            spotify_embed_type, spotify_embed_id, is_published, sort_order
     FROM soundtracks
     ORDER BY sort_order ASC'
)->fetchAll();

$out = array_map(function ($r) {
    $r['id'] = (int)$r['id'];
    $r['is_published'] = (bool)$r['is_published'];
    $r['sort_order'] = (int)$r['sort_order'];
    return $r;
}, $rows);

pw_json(['ok' => true, 'soundtracks' => $out]);
