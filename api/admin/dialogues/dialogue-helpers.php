<?php
/**
 * Shared normalization for the Dialogue Tree Control endpoints. Trees stay
 * intentionally small and acyclic: every authored node must be reachable from
 * the opening node, so a visitor can never be stranded in an orphaned branch.
 */
require_once __DIR__ . '/../../helpers.php';

function pw_dialogue_trees_ready(PDO $db): bool {
    return (bool)$db->query("SHOW TABLES LIKE 'overlord_dialogue_trees'")->fetch();
}

function pw_dialogue_default_tree(array $transmission = []): array {
    $messages = array_values(array_filter([
        trim((string)($transmission['opening_message'] ?? '')),
        trim((string)($transmission['followup_message'] ?? '')),
    ], static function ($message) { return $message !== ''; }));
    if (!$messages) {
        $messages = ['A new transmission has begun.'];
    }
    return [
        'version' => 1,
        'start_node_id' => 'opening',
        'nodes' => [[
            'id' => 'opening',
            'title' => 'Opening transmission',
            'messages' => $messages,
            'choices' => [],
        ]],
    ];
}

function pw_validate_dialogue_tree($tree): array {
    if (!is_array($tree) || !isset($tree['nodes']) || !is_array($tree['nodes'])) {
        pw_error('A dialogue tree must contain at least one dialogue.');
    }
    $nodes = $tree['nodes'];
    if (count($nodes) < 1 || count($nodes) > 60) {
        pw_error('A dialogue tree may contain between 1 and 60 dialogues.');
    }

    $normalized = [];
    $nodeIds = [];
    foreach ($nodes as $node) {
        if (!is_array($node)) pw_error('Each dialogue is invalid.');
        $id = trim((string)($node['id'] ?? ''));
        $title = trim((string)($node['title'] ?? ''));
        if (!preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/', $id)) {
            pw_error('Dialogue IDs may only use lowercase letters, numbers, hyphens, and underscores.');
        }
        if (isset($nodeIds[$id])) pw_error('Every dialogue needs a unique ID.');
        if ($title === '' || mb_strlen($title) > 100) {
            pw_error('Every dialogue needs a title of at most 100 characters.');
        }
        $rawMessages = isset($node['messages']) && is_array($node['messages']) ? $node['messages'] : [];
        $messages = [];
        foreach ($rawMessages as $message) {
            $message = trim((string)$message);
            if ($message === '') continue;
            if (mb_strlen($message) > 500) pw_error('A dialogue line may be at most 500 characters.');
            $messages[] = $message;
        }
        if (!$messages || count($messages) > 8) {
            pw_error('Every dialogue needs 1 to 8 message lines.');
        }
        // Position is optional presentation metadata for the admin canvas. It
        // never affects public playback, but retaining it lets editors keep a
        // deliberately arranged tree after dragging cards around.
        $position = null;
        if (isset($node['position']) && $node['position'] !== null) {
            if (!is_array($node['position'])) pw_error('A dialogue canvas position is invalid.');
            $positionX = filter_var($node['position']['x'] ?? null, FILTER_VALIDATE_INT);
            $positionY = filter_var($node['position']['y'] ?? null, FILTER_VALIDATE_INT);
            if ($positionX === false || $positionY === false || $positionX < 0 || $positionX > 10000 || $positionY < 0 || $positionY > 20000) {
                pw_error('A dialogue canvas position is outside the allowed workspace.');
            }
            $position = ['x' => $positionX, 'y' => $positionY];
        }
        $rawChoices = isset($node['choices']) && is_array($node['choices']) ? $node['choices'] : [];
        if (count($rawChoices) > 10) pw_error('A dialogue may have at most 10 branches.');
        $choices = [];
        foreach ($rawChoices as $choice) {
            if (!is_array($choice)) pw_error('A dialogue branch is invalid.');
            $label = trim((string)($choice['label'] ?? ''));
            $target = trim((string)($choice['target_node_id'] ?? ''));
            // This deliberately gates on the member's reputation LEVEL number
            // (the number shown in the reputation bar), never on raw points.
            // A blank value keeps the branch open to every reputation level.
            $rawRequiredLevel = $choice['required_reputation_level'] ?? null;
            $requiredLevel = null;
            if ($rawRequiredLevel !== null && $rawRequiredLevel !== '') {
                $requiredLevel = filter_var($rawRequiredLevel, FILTER_VALIDATE_INT);
                if ($requiredLevel === false || $requiredLevel < 1 || $requiredLevel > 99) {
                    pw_error('A branch reputation requirement must be a level between 1 and 99.');
                }
            }
            if ($label === '' || mb_strlen($label) > 180) pw_error('Each branch needs a label of at most 180 characters.');
            if (!preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/', $target)) pw_error('Each branch must target a dialogue.');
            $choices[] = [
                'label' => $label,
                'target_node_id' => $target,
                'requires_high_resonance' => !empty($choice['requires_high_resonance']),
                'required_reputation_level' => $requiredLevel,
            ];
        }
        $nodeIds[$id] = true;
        $normalizedNode = ['id' => $id, 'title' => $title, 'messages' => $messages, 'choices' => $choices];
        if ($position !== null) $normalizedNode['position'] = $position;
        $normalized[] = $normalizedNode;
    }

    $start = trim((string)($tree['start_node_id'] ?? ''));
    if (!isset($nodeIds[$start])) pw_error('Choose an opening dialogue from this tree.');

    $byId = [];
    foreach ($normalized as $node) $byId[$node['id']] = $node;
    foreach ($normalized as $node) {
        foreach ($node['choices'] as $choice) {
            if (!isset($nodeIds[$choice['target_node_id']])) {
                pw_error('Every branch must point to a dialogue in this tree.');
            }
        }
    }

    $visited = [];
    $visiting = [];
    $walk = function ($id) use (&$walk, &$visited, &$visiting, $byId) {
        if (!empty($visiting[$id])) pw_error('Dialogue branches cannot loop back to an earlier dialogue.');
        if (!empty($visited[$id])) return;
        $visiting[$id] = true;
        foreach ($byId[$id]['choices'] as $choice) $walk($choice['target_node_id']);
        unset($visiting[$id]);
        $visited[$id] = true;
    };
    $walk($start);
    if (count($visited) !== count($normalized)) {
        pw_error('Every dialogue must be connected to the opening dialogue. Add a branch or remove the orphaned dialogue.');
    }

    return ['version' => 1, 'start_node_id' => $start, 'nodes' => $normalized];
}
