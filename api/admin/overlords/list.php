<?php
/**
 * Admin listing for Overlord Control (Lore Management > Overlord Control).
 * Small, fixed-size dataset, same flat unpaginated pattern as Book/World
 * Control's own list.php.
 */
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('overlords.view');
$db = pw_db();

$rows = $db->query(
    'SELECT o.id, o.slug, o.name, o.epithet, o.world_id, o.pronoun_possessive, o.status,
            o.portrait_image_url, o.card_teaser, o.bio_paragraph_1, o.bio_paragraph_2, o.bio_paragraph_3,
            o.quote_text, o.quote_cite, o.decrees, o.accent_color, o.accent_glow,
            o.meta_title, o.meta_description, o.sort_order,
            w.slug AS world_slug, w.name AS world_name
     FROM overlords o LEFT JOIN worlds w ON w.id = o.world_id
     ORDER BY o.sort_order ASC'
)->fetchAll();

$out = array_map(function ($r) {
    $r['id'] = (int)$r['id'];
    $r['world_id'] = $r['world_id'] !== null ? (int)$r['world_id'] : null;
    $r['sort_order'] = (int)$r['sort_order'];
    return $r;
}, $rows);

pw_json(['ok' => true, 'overlords' => $out]);
