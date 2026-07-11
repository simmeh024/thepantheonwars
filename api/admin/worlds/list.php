<?php
/**
 * Admin listing for World Control (Lore Management > World Control).
 * Small, fixed-size dataset (a dozen worlds today) so this is a flat
 * unpaginated list, same pattern as Book Control -- but each row also
 * carries its full nested layers/sublocations/landmarks payload so the
 * admin list/modals never need a separate per-world fetch.
 */
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('worlds.view');
$db = pw_db();

$worlds = $db->query(
    'SELECT id, slug, name, tagline, card_blurb, thumb_image_url, portrait_image_url,
            overlord_name, overlord_title, overlord_page_slug, status, lore_status_label,
            intro_paragraph_1, intro_paragraph_2, layout_orientation,
            altitude_top_label, altitude_bottom_label,
            map_thumb_image_url, map_full_image_url, map_caption, sort_order
     FROM worlds ORDER BY sort_order ASC'
)->fetchAll();

$layers = $db->query(
    'SELECT id, world_id, sort_order, name, theme_tags, tagline, description, quote_text, quote_cite, tint_key
     FROM world_layers ORDER BY sort_order ASC'
)->fetchAll();

$sublocations = $db->query(
    'SELECT id, layer_id, sort_order, label FROM world_layer_sublocations ORDER BY sort_order ASC'
)->fetchAll();

$landmarks = $db->query(
    'SELECT id, world_id, layer_id, sort_order, kind, name, tag_label, description, quote_text, quote_cite
     FROM world_landmarks ORDER BY sort_order ASC'
)->fetchAll();

$subsByLayer = [];
foreach ($sublocations as $s) {
    $subsByLayer[(int)$s['layer_id']][] = [
        'id' => (int)$s['id'],
        'label' => $s['label'],
    ];
}

$landmarksByLayer = [];
$distantByWorld = [];
foreach ($landmarks as $lm) {
    $row = [
        'id' => (int)$lm['id'],
        'name' => $lm['name'],
        'tag_label' => $lm['tag_label'],
        'description' => $lm['description'],
        'quote_text' => $lm['quote_text'],
        'quote_cite' => $lm['quote_cite'],
    ];
    if ($lm['kind'] === 'distant' || $lm['layer_id'] === null) {
        $distantByWorld[(int)$lm['world_id']][] = $row;
    } else {
        $landmarksByLayer[(int)$lm['layer_id']][] = $row;
    }
}

$layersByWorld = [];
foreach ($layers as $l) {
    $id = (int)$l['id'];
    $layersByWorld[(int)$l['world_id']][] = [
        'id' => $id,
        'name' => $l['name'],
        'theme_tags' => $l['theme_tags'],
        'tagline' => $l['tagline'],
        'description' => $l['description'],
        'quote_text' => $l['quote_text'],
        'quote_cite' => $l['quote_cite'],
        'tint_key' => $l['tint_key'],
        'sublocations' => isset($subsByLayer[$id]) ? $subsByLayer[$id] : [],
        'landmarks' => isset($landmarksByLayer[$id]) ? $landmarksByLayer[$id] : [],
    ];
}

$out = array_map(function ($w) use ($layersByWorld, $distantByWorld) {
    $id = (int)$w['id'];
    $w['id'] = $id;
    $w['sort_order'] = (int)$w['sort_order'];
    $w['layers'] = isset($layersByWorld[$id]) ? $layersByWorld[$id] : [];
    $w['distant_landmarks'] = isset($distantByWorld[$id]) ? $distantByWorld[$id] : [];
    return $w;
}, $worlds);

pw_json(['ok' => true, 'worlds' => $out]);
