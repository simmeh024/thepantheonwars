<?php
require_once __DIR__ . '/../../market/market-helpers.php';

function pw_admin_market_require_ready(PDO $db): void {
    pw_market_require_ready($db);
}

function pw_admin_market_input(array $input): array {
    $type = trim((string)($input['offer_type'] ?? ''));
    if (!in_array($type, ['gear', 'character'], true)) pw_error('Choose equipment or a character for this market entry.');
    $definitionId = filter_var($input['definition_id'] ?? null, FILTER_VALIDATE_INT);
    if ($definitionId === false || $definitionId < 1) pw_error('Choose an item from the matching catalogue.');
    $price = filter_var($input['credit_price'] ?? null, FILTER_VALIDATE_INT);
    if ($price === false || $price < 1 || $price > 1000000) pw_error('Price must be between 1 and 1,000,000 credits.');
    $rank = filter_var($input['required_reputation_level'] ?? null, FILTER_VALIDATE_INT);
    if ($rank === false || $rank < 1 || $rank > 999) pw_error('Required reputation rank must be between 1 and 999.');
    $weight = filter_var($input['rotation_weight'] ?? null, FILTER_VALIDATE_INT);
    if ($weight === false || $weight < 1 || $weight > 10000) pw_error('Rotation weight must be between 1 and 10,000.');
    $stock = filter_var($input['stock_per_rotation'] ?? null, FILTER_VALIDATE_INT);
    if ($stock === false || $stock < 1 || $stock > 1000) pw_error('Stock per rotation must be between 1 and 1,000.');
    return [
        'offer_type' => $type,
        'definition_id' => $definitionId,
        'credit_price' => $price,
        'required_reputation_level' => $rank,
        'rotation_weight' => $weight,
        'stock_per_rotation' => $stock,
        'is_enabled' => !empty($input['is_enabled']) ? 1 : 0,
    ];
}

function pw_admin_market_source(PDO $db, string $type, int $definitionId): array {
    if ($type === 'gear') {
        $stmt = $db->prepare('SELECT id, name FROM game_loot_definitions WHERE id = ? AND slot <> ""');
    } else {
        $stmt = $db->prepare('SELECT id, name FROM game_crew_definitions WHERE id = ? AND is_starter = 0');
    }
    $stmt->execute([$definitionId]);
    $source = $stmt->fetch();
    if (!$source) pw_error('The selected catalogue entry no longer exists.', 404);
    return $source;
}
