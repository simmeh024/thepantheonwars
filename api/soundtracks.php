<?php
/**
 * Public read for the Soundtracks page. Powers soundtracks.html's repeated
 * .soundtrack-panel blocks (js/soundtracks.js). No auth required, no
 * single-record lookup -- same as api/known-figures.php.
 */
require_once __DIR__ . '/helpers.php';

$db = pw_db();

$stmt = $db->query(
    'SELECT id, eyebrow, heading, description, spotify_url,
            spotify_embed_type, spotify_embed_id, sort_order
     FROM soundtracks
     WHERE is_published = 1
     ORDER BY sort_order ASC'
);
$rows = $stmt->fetchAll();

$out = array_map(function ($r) {
    $r['id'] = (int)$r['id'];
    $r['sort_order'] = (int)$r['sort_order'];
    return $r;
}, $rows);

pw_json(['ok' => true, 'soundtracks' => $out]);
