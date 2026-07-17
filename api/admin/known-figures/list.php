<?php
/**
 * Admin listing for Known Figures Control (Lore Management > Known Figures
 * Control). Small, fixed-size dataset, same flat unpaginated pattern as
 * Overlord Control's own list.php.
 */
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('known_figures.view');
$db = pw_db();

$rows = $db->query(
    'SELECT id, slug, name, eyebrow, status_line, portrait_image_url,
            body_paragraph_1, body_paragraph_2, quote_text, quote_cite,
            accent_color, motif, signature_label, is_published, sort_order
     FROM known_figures
     ORDER BY sort_order ASC'
)->fetchAll();

$out = array_map(function ($r) {
    $r['id'] = (int)$r['id'];
    $r['is_published'] = (bool)$r['is_published'];
    $r['sort_order'] = (int)$r['sort_order'];
    return $r;
}, $rows);

pw_json(['ok' => true, 'known_figures' => $out]);
