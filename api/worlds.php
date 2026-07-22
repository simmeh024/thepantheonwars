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

// Bulk-load all public detail records. This keeps the catalog endpoint at a
// fixed four queries (worlds + layers + landmarks + sublocations), instead of
// three extra queries for every available world.
function pw_load_world_details($db, $worldIds) {
    if (empty($worldIds)) {
        return [];
    }

    $worldPlaceholders = implode(',', array_fill(0, count($worldIds), '?'));
    $layerStmt = $db->prepare(
        "SELECT id, world_id, sort_order, name, theme_tags, tagline, description, quote_text, quote_cite, tint_key
         FROM world_layers WHERE world_id IN ($worldPlaceholders) ORDER BY world_id ASC, sort_order ASC"
    );
    $layerStmt->execute($worldIds);
    $layerRows = $layerStmt->fetchAll();

    $landmarkStmt = $db->prepare(
        "SELECT world_id, layer_id, sort_order, kind, name, tag_label, description, quote_text, quote_cite
         FROM world_landmarks WHERE world_id IN ($worldPlaceholders) ORDER BY world_id ASC, sort_order ASC"
    );
    $landmarkStmt->execute($worldIds);
    $landmarkRows = $landmarkStmt->fetchAll();

    $layerIds = array_map(function ($row) { return (int)$row['id']; }, $layerRows);
    $subsByLayer = [];
    if (!empty($layerIds)) {
        $layerPlaceholders = implode(',', array_fill(0, count($layerIds), '?'));
        $subStmt = $db->prepare(
            "SELECT layer_id, label FROM world_layer_sublocations
             WHERE layer_id IN ($layerPlaceholders) ORDER BY layer_id ASC, sort_order ASC"
        );
        $subStmt->execute($layerIds);
        foreach ($subStmt->fetchAll() as $sub) {
            $subsByLayer[(int)$sub['layer_id']][] = $sub['label'];
        }
    }

    $landmarksByLayer = [];
    $distantByWorld = [];
    foreach ($landmarkRows as $landmark) {
        $out = [
            'name' => $landmark['name'],
            'tag_label' => $landmark['tag_label'],
            'description' => $landmark['description'],
            'quote_text' => $landmark['quote_text'],
            'quote_cite' => $landmark['quote_cite'],
        ];
        if ($landmark['kind'] === 'distant' || $landmark['layer_id'] === null) {
            $distantByWorld[(int)$landmark['world_id']][] = $out;
        } else {
            $landmarksByLayer[(int)$landmark['layer_id']][] = $out;
        }
    }

    $detailsByWorld = [];
    foreach ($worldIds as $worldId) {
        $detailsByWorld[(int)$worldId] = ['layers' => [], 'distant_landmarks' => []];
    }
    foreach ($distantByWorld as $worldId => $landmarks) {
        $detailsByWorld[$worldId]['distant_landmarks'] = $landmarks;
    }
    foreach ($layerRows as $layer) {
        $layerId = (int)$layer['id'];
        $worldId = (int)$layer['world_id'];
        $detailsByWorld[$worldId]['layers'][] = [
            'name' => $layer['name'],
            'theme_tags' => $layer['theme_tags'],
            'tagline' => $layer['tagline'],
            'description' => $layer['description'],
            'quote_text' => $layer['quote_text'],
            'quote_cite' => $layer['quote_cite'],
            'tint_key' => $layer['tint_key'],
            'sublocations' => isset($subsByLayer[$layerId]) ? $subsByLayer[$layerId] : [],
            'landmarks' => isset($landmarksByLayer[$layerId]) ? $landmarksByLayer[$layerId] : [],
        ];
    }

    return $detailsByWorld;
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

/**
 * Swaps each district's pull quote for the variant matching today's weather.
 *
 * Resolved here, server-side, rather than on the client after the separate
 * weather request lands -- otherwise the record would paint one quote and
 * visibly replace it a moment later.
 *
 * Deliberately only called on the single-world path. The twelve-world atlas
 * would have to run the generator once per world for quotes nothing on that
 * page displays.
 *
 * Silent by design: a reader who returns on a different day simply finds a
 * different remark. A layer with no variant for today keeps its own
 * quote_text, so these can be written one at a time.
 */
function pw_apply_layer_quote_variants(PDO $db, array $world) {
    if (empty($world['layers'])) {
        return $world;
    }
    $layerIds = array_map(function ($layer) { return (int)$layer['id']; }, $world['layers']);
    if (!$layerIds) {
        return $world;
    }

    try {
        // Today's condition for this world, from the same generator the weather
        // card uses, so the quote and the card can never disagree.
        $profileStmt = $db->prepare(
            'SELECT p.* FROM world_weather_profiles p
             WHERE p.world_id = ? AND p.enabled = 1'
        );
        $profileStmt->execute([(int)$world['id']]);
        $profile = $profileStmt->fetch();
        if (!$profile) {
            return $world;
        }
        require_once __DIR__ . '/weather-forecast.php';
        $conditionKey = pw_weather_icon_key($profile['current_condition']);

        $placeholders = implode(',', array_fill(0, count($layerIds), '?'));
        $variantStmt = $db->prepare(
            'SELECT entity_id, quote_text, quote_cite FROM world_quote_variants
             WHERE entity_type = \'layer\' AND condition_key = ?
               AND entity_id IN (' . $placeholders . ')'
        );
        $variantStmt->execute(array_merge([$conditionKey], $layerIds));
        $variants = [];
        foreach ($variantStmt->fetchAll() as $variant) {
            $variants[(int)$variant['entity_id']] = $variant;
        }
    } catch (PDOException $e) {
        // sql/migration_world_quote_variants.sql may not have been run, or this
        // world may have no weather profile. Either way the authored quotes
        // stand on their own.
        return $world;
    }

    foreach ($world['layers'] as $index => $layer) {
        $variant = isset($variants[(int)$layer['id']]) ? $variants[(int)$layer['id']] : null;
        if (!$variant || trim((string)$variant['quote_text']) === '') {
            continue;
        }
        $world['layers'][$index]['quote_text'] = $variant['quote_text'];
        // An empty cite on the variant keeps the layer's own attribution, so a
        // variant only has to restate the speaker when it is a different one.
        if (trim((string)$variant['quote_cite']) !== '') {
            $world['layers'][$index]['quote_cite'] = $variant['quote_cite'];
        }
    }
    return $world;
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
    $detailsByWorld = $world['status'] === 'available' ? pw_load_world_details($db, [$world['id']]) : [];
    if (isset($detailsByWorld[$world['id']])) {
        $world = array_merge($world, $detailsByWorld[$world['id']]);
        $world = pw_apply_layer_quote_variants($db, $world);
    }
    pw_json(['ok' => true, 'world' => $world]);
}

$stmt = $db->query("SELECT $worldFields FROM $worldFrom ORDER BY w.sort_order ASC");
$rows = $stmt->fetchAll();

$worlds = array_map('pw_format_world', $rows);
$availableIds = array_values(array_map(function ($world) { return $world['id']; }, array_filter($worlds, function ($world) {
    return $world['status'] === 'available';
})));
$detailsByWorld = pw_load_world_details($db, $availableIds);

$out = array_map(function ($world) use ($detailsByWorld) {
    return isset($detailsByWorld[$world['id']]) ? array_merge($world, $detailsByWorld[$world['id']]) : $world;
}, $worlds);

pw_json(['ok' => true, 'worlds' => $out]);
