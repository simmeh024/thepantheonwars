<?php
/**
 * Public read for the Known Figures chronicle. Powers known-figures.html's
 * cinematic vertical scroll (js/known-figures.js). No auth required and no
 * single-slug lookup -- unlike Overlords/Worlds there is no separate
 * per-figure detail page, only this one list, same as api/overlords.php's
 * unfiltered branch.
 */
require_once __DIR__ . '/helpers.php';

$db = pw_db();

$stmt = $db->query(
    'SELECT id, slug, name, eyebrow, status_line, portrait_image_url,
            body_paragraph_1, body_paragraph_2, quote_text, quote_cite,
            accent_color, motif, signature_label, sort_order
     FROM known_figures
     WHERE is_published = 1
     ORDER BY sort_order ASC'
);
$rows = $stmt->fetchAll();

$out = array_map(function ($r) {
    $r['id'] = (int)$r['id'];
    $r['sort_order'] = (int)$r['sort_order'];
    return $r;
}, $rows);

pw_json(['ok' => true, 'known_figures' => $out]);
