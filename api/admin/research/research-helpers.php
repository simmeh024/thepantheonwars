<?php
require_once __DIR__ . '/../../research/research-helpers.php';

function pw_admin_research_require_ready(PDO $db): void {
    pw_research_require_ready($db);
}

function pw_admin_research_category_input(array $input): array {
    $name = trim((string)($input['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 80) pw_error('Category name must be between 1 and 80 characters.');
    $slug = trim((string)($input['slug'] ?? ''));
    if (!preg_match('/\A[a-z0-9][a-z0-9-]{0,79}\z/', $slug)) pw_error('Category slug may use lowercase letters, numbers, and hyphens only.');
    $description = trim((string)($input['description'] ?? ''));
    if (mb_strlen($description) > 255) pw_error('Category description may not exceed 255 characters.');
    $sortOrder = filter_var($input['sort_order'] ?? 0, FILTER_VALIDATE_INT);
    if ($sortOrder === false || $sortOrder < 0 || $sortOrder > 100000) pw_error('Category sort order must be between 0 and 100000.');
    return ['name' => $name, 'slug' => $slug, 'description' => $description, 'sort_order' => $sortOrder];
}

function pw_admin_research_node_input(PDO $db, array $input): array {
    $name = trim((string)($input['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 120) pw_error('Research name must be between 1 and 120 characters.');
    $slug = trim((string)($input['slug'] ?? ''));
    if (!preg_match('/\A[a-z0-9][a-z0-9-]{0,119}\z/', $slug)) pw_error('Research slug may use lowercase letters, numbers, and hyphens only.');
    $description = trim((string)($input['description'] ?? ''));
    if ($description === '' || mb_strlen($description) > 2000) pw_error('Research description must be between 1 and 2,000 characters.');
    $categoryId = null;
    $categoryRaw = trim((string)($input['research_category_id'] ?? ''));
    if ($categoryRaw !== '') {
        $categoryId = filter_var($categoryRaw, FILTER_VALIDATE_INT);
        if ($categoryId === false || $categoryId < 1) pw_error('Choose a valid research category.');
        $category = $db->prepare('SELECT id FROM game_research_categories WHERE id = ?');
        $category->execute([$categoryId]);
        if (!$category->fetch()) pw_error('The selected research category no longer exists.', 404);
    }
    $effectType = trim((string)($input['effect_type'] ?? ''));
    if (!isset(pw_research_effect_types()[$effectType])) pw_error('Choose a valid research effect.');

    $rawValue = $input['effect_value'] ?? 0;
    if ($effectType === 'secret_mission') {
        $effectValue = 0.0;
    } elseif (!is_numeric($rawValue) || (float)$rawValue <= 0 || (float)$rawValue > 50) {
        pw_error('A percentage research effect must be greater than 0% and no higher than 50%.');
    } else {
        $effectValue = round((float)$rawValue, 2);
    }

    $targetMissionId = null;
    $targetRaw = trim((string)($input['target_mission_definition_id'] ?? ''));
    if ($effectType === 'secret_mission') {
        $targetMissionId = filter_var($targetRaw, FILTER_VALIDATE_INT);
        if ($targetMissionId === false || $targetMissionId < 1) pw_error('Choose the secret mission this research should reveal.');
        $target = $db->prepare('SELECT id FROM game_mission_definitions WHERE id = ? AND world_key = "neoh"');
        $target->execute([$targetMissionId]);
        if (!$target->fetch()) pw_error('Choose a valid Neoh mission for this secret research.', 404);
    } elseif ($targetRaw !== '') {
        pw_error('Only Secret mission access research may target a mission.');
    }

    $rank = filter_var($input['required_reputation_level'] ?? null, FILTER_VALIDATE_INT);
    if ($rank === false || $rank < 1 || $rank > 99) pw_error('Required reputation rank must be between 1 and 99.');
    $creditCost = filter_var($input['credit_cost'] ?? 0, FILTER_VALIDATE_INT);
    if ($creditCost === false || $creditCost < 0 || $creditCost > 1000000) pw_error('Credit cost must be between 0 and 1,000,000.');

    $salvageId = null;
    $salvageRaw = trim((string)($input['salvage_loot_definition_id'] ?? ''));
    $salvageQuantity = filter_var($input['salvage_quantity'] ?? 0, FILTER_VALIDATE_INT);
    if ($salvageQuantity === false || $salvageQuantity < 0 || $salvageQuantity > 999) pw_error('Salvage quantity must be between 0 and 999.');
    if ($salvageRaw !== '') {
        $salvageId = filter_var($salvageRaw, FILTER_VALIDATE_INT);
        if ($salvageId === false || $salvageId < 1 || $salvageQuantity < 1) pw_error('A salvage cost needs a valid item and quantity.');
        $salvage = $db->prepare('SELECT id FROM game_loot_definitions WHERE id = ? AND slot = ""');
        $salvage->execute([$salvageId]);
        if (!$salvage->fetch()) pw_error('Research costs must use a slotless salvage item.', 404);
    } elseif ($salvageQuantity !== 0) {
        pw_error('Choose a salvage item before setting its quantity.');
    }

    $canvasX = filter_var($input['canvas_x'] ?? 80, FILTER_VALIDATE_INT);
    $canvasY = filter_var($input['canvas_y'] ?? 80, FILTER_VALIDATE_INT);
    $sortOrder = filter_var($input['sort_order'] ?? 0, FILTER_VALIDATE_INT);
    if ($canvasX === false || $canvasX < 0 || $canvasX > PW_RESEARCH_BOARD_WIDTH - 196) pw_error('Canvas X must stay inside the research board.');
    if ($canvasY === false || $canvasY < 0 || $canvasY > PW_RESEARCH_BOARD_HEIGHT - 126) pw_error('Canvas Y must stay inside the research board.');
    if ($sortOrder === false || $sortOrder < 0 || $sortOrder > 100000) pw_error('Sort order must be between 0 and 100000.');

    $imageUrl = trim((string)($input['image_url'] ?? ''));
    if ($imageUrl !== '' && pw_research_image_url($imageUrl) === '') pw_error('Choose a research image from the uploaded image library.');
    return [
        'name' => $name, 'slug' => $slug, 'description' => $description, 'image_url' => pw_research_image_url($imageUrl), 'research_category_id' => $categoryId,
        'effect_type' => $effectType, 'effect_value' => $effectValue, 'target_mission_definition_id' => $targetMissionId,
        'required_reputation_level' => $rank, 'credit_cost' => $creditCost,
        'salvage_loot_definition_id' => $salvageId, 'salvage_quantity' => $salvageQuantity,
        'canvas_x' => $canvasX, 'canvas_y' => $canvasY, 'sort_order' => $sortOrder,
        'is_enabled' => !empty($input['is_enabled']) ? 1 : 0,
    ];
}

/** Ensure each selected prerequisite exists and cannot point back to the node
 * being saved. The compact breadth-first walk makes loops impossible without
 * relying on MariaDB recursive-query support. */
function pw_admin_research_prerequisites(PDO $db, ?int $nodeId, $raw): array {
    if (!is_array($raw)) $raw = [];
    $ids = [];
    foreach ($raw as $value) {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) pw_error('Every prerequisite must be a valid research node.');
        if ($nodeId !== null && $id === $nodeId) pw_error('A research node cannot require itself.');
        $ids[$id] = true;
    }
    $ids = array_keys($ids);
    if (count($ids) > 12) pw_error('A research node can have at most 12 direct prerequisites.');
    if (!$ids) return [];
    $exists = $db->prepare('SELECT id FROM game_research_nodes WHERE id IN (' . pw_missions_placeholders(count($ids)) . ')');
    $exists->execute($ids);
    if (count($exists->fetchAll()) !== count($ids)) pw_error('One selected prerequisite no longer exists.', 404);
    if ($nodeId === null) return $ids;

    $pending = $ids;
    $visited = [];
    $children = $db->prepare('SELECT prerequisite_node_id FROM game_research_prerequisites WHERE research_node_id = ?');
    while ($pending) {
        $current = array_shift($pending);
        if (isset($visited[$current])) continue;
        if ($current === $nodeId) pw_error('Research prerequisites cannot contain a loop.');
        $visited[$current] = true;
        $children->execute([$current]);
        foreach ($children->fetchAll(PDO::FETCH_COLUMN) as $child) $pending[] = (int)$child;
    }
    return $ids;
}
