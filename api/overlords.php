<?php
/**
 * Public read for the overlord roster. Powers overlords.html's card grid and
 * overlord.html's single-record detail template. No auth required -- this is
 * marketing copy, same as api/worlds.php and api/books.php.
 */
require_once __DIR__ . '/helpers.php';

$db = pw_db();

$fields = 'o.id, o.slug, o.name, o.epithet, o.pronoun_possessive, o.status,
           o.portrait_image_url, o.card_teaser, o.bio_paragraph_1, o.bio_paragraph_2,
           o.bio_paragraph_3, o.quote_text, o.quote_cite, o.accent_color, o.accent_glow,
           o.meta_title, o.meta_description, o.sort_order,
           w.slug AS world_slug, w.name AS world_name, w.thumb_image_url AS world_thumb_image_url';

function pw_format_overlord($r) {
    $out = $r;
    $out['id'] = (int)$r['id'];
    $out['sort_order'] = (int)$r['sort_order'];
    $out['world'] = $r['world_slug'] ? [
        'slug' => $r['world_slug'],
        'name' => $r['world_name'],
        'thumb_image_url' => $r['world_thumb_image_url'],
    ] : null;
    unset($out['world_slug'], $out['world_name'], $out['world_thumb_image_url']);
    return $out;
}

$slug = isset($_GET['slug']) ? trim($_GET['slug']) : '';

if ($slug !== '') {
    $stmt = $db->prepare(
        "SELECT $fields FROM overlords o LEFT JOIN worlds w ON w.id = o.world_id WHERE o.slug = ?"
    );
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    if (!$row) {
        pw_error('Overlord not found.', 404);
    }
    pw_json(['ok' => true, 'overlord' => pw_format_overlord($row)]);
}

$stmt = $db->query("SELECT $fields FROM overlords o LEFT JOIN worlds w ON w.id = o.world_id ORDER BY o.sort_order ASC");
$rows = $stmt->fetchAll();

pw_json(['ok' => true, 'overlords' => array_map('pw_format_overlord', $rows)]);
