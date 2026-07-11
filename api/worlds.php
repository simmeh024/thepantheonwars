<?php
/**
 * Public read for the world catalog. Powers worlds.html's card grid and, for
 * each "available" world, its cross-section detail section (layers,
 * sublocations, and landmarks). No auth required -- this is marketing copy,
 * same as api/books.php.
 */
require_once __DIR__ . '/helpers.php';

$db = pw_db();

$worldFields = 'id, slug, name, tagline, card_blurb, thumb_image_url, portrait_image_url,
                 overlord_name, overlord_title, overlord_page_slug, status, lore_status_label,
                 intro_paragraph_1, intro_paragraph_2, layout_orientation,
                 altitude_top_label, altitude_bottom_label,
                 map_thumb_image_url, map_full_image_url, map_caption, sort_order';

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
    return $out;
}

$slug = isset($_GET['slug']) ? trim($_GET['slug']) : '';

if ($slug !== '') {
    $stmt = $db->prepare("SELECT $worldFields FROM worlds WHERE slug = ?");
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

$stmt = $db->query("SELECT $worldFields FROM worlds ORDER BY sort_order ASC");
$rows = $stmt->fetchAll();

$out = array_map(function ($r) use ($db) {
    $world = pw_format_world($r);
    if ($world['status'] === 'available') {
        $world = array_merge($world, pw_load_world_detail($db, $world['id']));
    }
    return $world;
}, $rows);

pw_json(['ok' => true, 'worlds' => $out]);
