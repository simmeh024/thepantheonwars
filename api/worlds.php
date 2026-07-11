<?php
/**
 * Public read for the world catalog. Powers worlds.html's card grid and, for
 * each "available" world, its cross-section detail section (layers,
 * sublocations, and landmarks). No auth required -- this is marketing copy,
 * same as api/books.php.
 */
require_once __DIR__ . '/helpers.php';

$db = pw_db();

$worldFields = 'w.id, w.slug, w.name, w.tagline, w.card_blurb, w.thumb_image_url, w.portrait_image_url,
                 w.status, w.lore_status_label,
                 w.intro_paragraph_1, w.intro_paragraph_2, w.layout_orientation,
                 w.altitude_top_label, w.altitude_bottom_label,
                 w.map_thumb_image_url, w.map_full_image_url, w.map_caption, w.sort_order,
                 o.name AS overlord_name, o.epithet AS overlord_epithet, o.slug AS overlord_slug';
$worldFrom = 'worlds w LEFT JOIN overlords o ON o.id = w.overlord_id';

function pw_load_world_detail($db, $worldId) {
    $stmt = $db->prepare(
        'SELECT id, sort_order, name, theme_tags, tagline, description, quote_text, quote_cite, tint_key
         FROM world_layers WHERE world_id = ? ORDER BY sort_order ASC'
    );
    $stmt->execute([$worldId]);
    $layerRows = $stmt->fetchAll();

    $landmarkStmt = $db->prepare(
        'SELECT id, layer_id, sort_order, kind, name, tag_label, description, quote_text, quote_cite
         FROM world_landmarks WHERE world_id = ? ORDER BY sort_order ASC'
    );
    $landmarkStmt->execute([$worldId]);
    $landmarkRows = $landmarkStmt->fetchAll();

    $distantLandmarks = [];
    $landmarksByLayer = [];
    foreach ($landmarkRows as $lm) {
        $out = [
            'name' => $lm['name'],
            'tag_label' => $lm['tag_label'],
            'description' => $lm['description'],
            'quote_text' => $lm['quote_text'],
            'quote_cite' => $lm['quote_cite'],
        ];
        if ($lm['kind'] === 'distant' || $lm['layer_id'] === null) {
            $distantLandmarks[] = $out;
        } else {
            $landmarksByLayer[(int)$lm['layer_id']][] = $out;
        }
    }

    $layerIds = array_map(function ($r) { return (int)$r['id']; }, $layerRows);
    $subsByLayer = [];
    if (!empty($layerIds)) {
        $placeholders = implode(',', array_fill(0, count($layerIds), '?'));
        $subStmt = $db->prepare(
            "SELECT layer_id, label FROM world_layer_sublocations
             WHERE layer_id IN ($placeholders) ORDER BY sort_order ASC"
        );
        $subStmt->execute($layerIds);
        foreach ($subStmt->fetchAll() as $sub) {
            $subsByLayer[(int)$sub['layer_id']][] = $sub['label'];
        }
    }

    $layers = array_map(function ($r) use ($subsByLayer, $landmarksByLayer) {
        $id = (int)$r['id'];
        return [
            'name' => $r['name'],
            'theme_tags' => $r['theme_tags'],
            'tagline' => $r['tagline'],
            'description' => $r['description'],
            'quote_text' => $r['quote_text'],
            'quote_cite' => $r['quote_cite'],
            'tint_key' => $r['tint_key'],
            'sublocations' => isset($subsByLayer[$id]) ? $subsByLayer[$id] : [],
            'landmarks' => isset($landmarksByLayer[$id]) ? $landmarksByLayer[$id] : [],
        ];
    }, $layerRows);

    return ['layers' => $layers, 'distant_landmarks' => $distantLandmarks];
}

function pw_format_world($r) {
    $out = $r;
    $out['id'] = (int)$r['id'];
    $out['sort_order'] = (int)$r['sort_order'];
    $out['overlord'] = $r['overlord_name'] ? [
        'name' => $r['overlord_name'],
        'epithet' => $r['overlord_epithet'],
        'slug' => $r['overlord_slug'],
    ] : null;
    unset($out['overlord_name'], $out['overlord_epithet'], $out['overlord_slug']);
    return $out;
}

$slug = isset($_GET['slug']) ? trim($_GET['slug']) : '';

if ($slug !== '') {
    $stmt = $db->prepare("SELECT $worldFields FROM $worldFrom WHERE w.slug = ?");
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    if (!$row) {
        pw_error('World not found.', 404);
    }
    $world = pw_format_world($row);
    if ($world['status'] === 'available') {
        $world = array_merge($world, pw_load_world_detail($db, $world['id']));
    }
    pw_json(['ok' => true, 'world' => $world]);
}

$stmt = $db->query("SELECT $worldFields FROM $worldFrom ORDER BY w.sort_order ASC");
$rows = $stmt->fetchAll();

$out = array_map(function ($r) use ($db) {
    $world = pw_format_world($r);
    if ($world['status'] === 'available') {
        $world = array_merge($world, pw_load_world_detail($db, $world['id']));
    }
    return $world;
}, $rows);

pw_json(['ok' => true, 'worlds' => $out]);
