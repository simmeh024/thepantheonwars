<?php
require_once __DIR__ . '/research-helpers.php';

$user = pw_require_login();
$db = pw_db();
pw_research_require_ready($db);
$userId = (int)$user['id'];

try {
    $standing = $db->prepare('SELECT reputation FROM users WHERE id = ?');
    $standing->execute([$userId]);
    $reputation = pw_reputation_info((int)$standing->fetchColumn());
    $rank = max(0, (int)($reputation['level_number'] ?? 0));

    /* A disabled node remains visible only to a player who already owns it.
     * This makes a retired protocol explainable without offering it to a new
     * account or silently removing an earned benefit. */
    $lootTableLocksReady = pw_research_loot_table_locks_ready($db);
    $queueTransmissionsReady = pw_research_queue_transmissions_ready($db);
    $nodesStmt = $db->prepare(
        'SELECT n.id, n.name, n.slug, n.description, n.image_url, n.research_category_id,
                category.name AS category_name, category.slug AS category_slug, category.description AS category_description,
                n.effect_type, n.effect_value,
                n.target_mission_definition_id, target.name AS target_mission_name,
                ' . ($lootTableLocksReady ? 'n.target_loot_table_id, target_loot.name AS target_loot_table_name,' : 'NULL AS target_loot_table_id, "" AS target_loot_table_name,') . '
                n.required_reputation_level, n.credit_cost, n.salvage_loot_definition_id, n.salvage_quantity,
                salvage.name AS salvage_name, salvage.tier AS salvage_tier, salvage.icon_url AS salvage_icon_url,
                n.canvas_x, n.canvas_y, n.sort_order, n.is_enabled,
                pr.unlocked_at
         FROM game_research_nodes n
         LEFT JOIN game_player_research pr ON pr.research_node_id = n.id AND pr.user_id = ?
         LEFT JOIN game_research_categories category ON category.id = n.research_category_id
         LEFT JOIN game_loot_definitions salvage ON salvage.id = n.salvage_loot_definition_id
         LEFT JOIN game_mission_definitions target ON target.id = n.target_mission_definition_id
         ' . ($lootTableLocksReady ? 'LEFT JOIN game_loot_tables target_loot ON target_loot.id = n.target_loot_table_id' : '') . '
         WHERE n.is_enabled = 1 OR pr.user_id IS NOT NULL
         ORDER BY n.sort_order ASC, n.id ASC'
    );
    $nodesStmt->execute([$userId]);
    $nodes = $nodesStmt->fetchAll();
    $categories = $db->query('SELECT id, name, slug, description, sort_order, requires_all_other_unlocked FROM game_research_categories ORDER BY sort_order ASC, id ASC')->fetchAll();
    foreach ($categories as &$category) {
        $category['id'] = (int)$category['id'];
        $category['sort_order'] = (int)$category['sort_order'];
        $category['requires_all_other_unlocked'] = (bool)$category['requires_all_other_unlocked'];
    }
    unset($category);

    $unlocked = array_flip(pw_research_player_unlocked_ids($db, $userId));
    $finalCategoryIds = [];
    foreach ($categories as $category) {
        if (!empty($category['requires_all_other_unlocked'])) $finalCategoryIds[(int)$category['id']] = true;
    }
    /* The Final Neoh branch is intentionally evaluated against every enabled
     * non-final node, irrespective of canvas position or category filter. A
     * disabled protocol is retired from new progress and therefore cannot
     * permanently prevent the final branch from being revealed. */
    $normalEnabledNodes = array_values(array_filter($nodes, static function ($node) use ($finalCategoryIds) {
        $categoryId = $node['research_category_id'] !== null ? (int)$node['research_category_id'] : 0;
        return !empty($node['is_enabled']) && !isset($finalCategoryIds[$categoryId]);
    }));
    $allOtherProtocolsUnlocked = !empty($normalEnabledNodes) && !array_filter($normalEnabledNodes, static function ($node) use ($unlocked) {
        return !isset($unlocked[(int)$node['id']]);
    });
    /* The legendary branch used to vanish from the response entirely until its
     * gate opened, which is safe but tells the player nothing -- there was no
     * sign the branch existed at all. It is now announced but still sealed: the
     * tab is shipped with a requirement, and the nodes themselves are withheld
     * exactly as before. What is sent is a count and a rank, never a list, for
     * the same reason a locked timeline event ships only its position: naming
     * the protocols would hand the endgame to anyone who had not reached it. */
    $legendaryLocked = $finalCategoryIds && !$allOtherProtocolsUnlocked;
    $legendaryNodeCount = 0;
    $legendaryMinRank = 0;
    foreach ($nodes as $node) {
        $categoryId = $node['research_category_id'] !== null ? (int)$node['research_category_id'] : 0;
        if (!isset($finalCategoryIds[$categoryId]) || empty($node['is_enabled'])) continue;
        $legendaryNodeCount++;
        $nodeRank = (int)$node['required_reputation_level'];
        if ($legendaryMinRank === 0 || $nodeRank < $legendaryMinRank) $legendaryMinRank = $nodeRank;
    }
    $protocolsRemaining = 0;
    foreach ($normalEnabledNodes as $node) {
        if (!isset($unlocked[(int)$node['id']])) $protocolsRemaining++;
    }
    if ($legendaryLocked) {
        $nodes = array_values(array_filter($nodes, static function ($node) use ($finalCategoryIds, $unlocked) {
            $categoryId = $node['research_category_id'] !== null ? (int)$node['research_category_id'] : 0;
            return !isset($finalCategoryIds[$categoryId]) || isset($unlocked[(int)$node['id']]);
        }));
        $categories = array_values(array_filter($categories, static function ($category) {
            return empty($category['requires_all_other_unlocked']);
        }));
    }
    $nodeIds = array_map(static function ($row) { return (int)$row['id']; }, $nodes);

    $prerequisites = [];
    if ($nodeIds) {
        $links = $db->prepare(
            'SELECT p.research_node_id, p.prerequisite_node_id, n.name
             FROM game_research_prerequisites p
             JOIN game_research_nodes n ON n.id = p.prerequisite_node_id
             WHERE p.research_node_id IN (' . pw_missions_placeholders(count($nodeIds)) . ')
             ORDER BY p.research_node_id ASC, n.sort_order ASC, n.id ASC'
        );
        $links->execute($nodeIds);
        foreach ($links->fetchAll() as $link) {
            $prerequisites[(int)$link['research_node_id']][] = ['id' => (int)$link['prerequisite_node_id'], 'name' => (string)$link['name']];
        }
    }

    $salvageIds = array_values(array_unique(array_filter(array_map(static function ($row) {
        return $row['salvage_loot_definition_id'] !== null ? (int)$row['salvage_loot_definition_id'] : 0;
    }, $nodes))));
    $salvageHeld = [];
    if ($salvageIds) {
        $held = $db->prepare(
            'SELECT loot_definition_id, quantity FROM game_player_loot
             WHERE user_id = ? AND loot_definition_id IN (' . pw_missions_placeholders(count($salvageIds)) . ')'
        );
        $held->execute(array_merge([$userId], $salvageIds));
        foreach ($held->fetchAll() as $row) $salvageHeld[(int)$row['loot_definition_id']] = (int)$row['quantity'];
    }

    $credits = pw_missions_credit_balance($db, $userId);
    $queuedNodeId = null;
    $transmissions = [];
    if ($queueTransmissionsReady) {
        $queue = $db->prepare('SELECT research_node_id FROM game_player_research_queue WHERE user_id = ?');
        $queue->execute([$userId]);
        $queuedNodeId = $queue->fetchColumn();
        $queuedNodeId = $queuedNodeId === false ? null : (int)$queuedNodeId;
        $log = $db->prepare(
            'SELECT protocol_name, transmission_text, created_at
             FROM game_player_research_transmissions
             WHERE user_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT 8'
        );
        $log->execute([$userId]);
        foreach ($log->fetchAll() as $entry) {
            $transmissions[] = [
                'protocol_name' => (string)$entry['protocol_name'],
                'text' => (string)$entry['transmission_text'],
                'created_at' => (string)$entry['created_at'],
            ];
        }
    }
    /* The verdict comes from the shared helper so the alert badge on the
     * missions page cannot disagree with this page about what is ready. The
     * four conditions below are still computed individually, because a card has
     * to say which one is unmet -- only the conclusion is shared. */
    $unlockableIds = array_flip(pw_research_unlockable_node_ids($db, $userId));
    $publicNodes = [];
    foreach ($nodes as $node) {
        $id = (int)$node['id'];
        $isUnlocked = isset($unlocked[$id]);
        $required = $prerequisites[$id] ?? [];
        $missing = array_values(array_filter($required, static function ($item) use ($unlocked) {
            return !isset($unlocked[(int)$item['id']]);
        }));
        $salvageId = $node['salvage_loot_definition_id'] !== null ? (int)$node['salvage_loot_definition_id'] : null;
        $salvageQuantity = (int)$node['salvage_quantity'];
        $held = $salvageId !== null ? ($salvageHeld[$salvageId] ?? 0) : 0;
        $rankMet = $rank >= (int)$node['required_reputation_level'];
        $creditsMet = $credits >= (int)$node['credit_cost'];
        $salvageMet = $salvageId === null || $held >= $salvageQuantity;
        $canUnlock = isset($unlockableIds[$id]);
        $effect = pw_research_effect_types()[(string)$node['effect_type']] ?? null;
        if ($effect === null) continue;
        $categoryId = $node['research_category_id'] !== null ? (int)$node['research_category_id'] : null;
        // Each board clamps against its own size. Using the main board's bounds
        // for a legendary node would let it place outside the smaller canvas,
        // which clips its overflow and would simply lose the card.
        $isLegendary = $categoryId !== null && isset($finalCategoryIds[$categoryId]);
        $bounds = pw_research_board_size($isLegendary);
        $publicNodes[] = [
            'id' => $id,
            'name' => (string)$node['name'],
            'description' => (string)($node['description'] ?? ''),
            'image_url' => pw_research_image_url($node['image_url']),
            'effect_type' => (string)$node['effect_type'],
            'effect_label' => $effect['label'],
            'effect_short' => $effect['short'],
            // Present only on a count effect, and then it is the unit. The
            // browser branches on its presence rather than on a list of names.
            'effect_flat_unit' => $effect['flat'] ?? null,
            'effect_value' => (float)$node['effect_value'],
            'category' => $categoryId === null ? null : [
                'id' => $categoryId, 'name' => (string)($node['category_name'] ?? 'Uncategorised'),
                'slug' => (string)($node['category_slug'] ?? ''), 'description' => (string)($node['category_description'] ?? ''),
            ],
            'target_mission_name' => (string)($node['target_mission_name'] ?? ''),
            'target_loot_table_name' => (string)($node['target_loot_table_name'] ?? ''),
            'required_reputation_level' => (int)$node['required_reputation_level'],
            'credit_cost' => (int)$node['credit_cost'],
            'salvage' => $salvageId === null ? null : [
                'id' => $salvageId, 'name' => (string)($node['salvage_name'] ?? 'Recovered salvage'),
                'tier' => (string)($node['salvage_tier'] ?? 'common'), 'icon_url' => pw_missions_gear_icon_url($node['salvage_icon_url'] ?? ''),
                'quantity' => $salvageQuantity, 'held' => $held,
            ],
            'prerequisites' => $required,
            'missing_prerequisites' => $missing,
            'is_legendary' => $isLegendary,
            'canvas_x' => max(0, min($bounds['width'] - 196, (int)$node['canvas_x'])),
            'canvas_y' => max(0, min($bounds['height'] - 126, (int)$node['canvas_y'])),
            'is_enabled' => (bool)$node['is_enabled'],
            'is_unlocked' => $isUnlocked,
            'rank_met' => $rankMet,
            'prerequisites_met' => !$missing,
            'funds_met' => $creditsMet && $salvageMet,
            'can_unlock' => $canUnlock,
            'is_queued' => $queuedNodeId !== null && $queuedNodeId === $id,
            'unlocked_at' => $node['unlocked_at'],
        ];
    }

    $queueNode = null;
    if ($queuedNodeId !== null) {
        foreach ($publicNodes as $publicNode) {
            if ((int)$publicNode['id'] === $queuedNodeId && !$publicNode['is_unlocked']) {
                $queueNode = $publicNode;
                break;
            }
        }
        if ($queueNode === null) {
            $db->prepare('DELETE FROM game_player_research_queue WHERE user_id = ?')->execute([$userId]);
        }
    }

    $effects = pw_research_player_effects($db, $userId);
    /* The two boards are separated here rather than in the browser so the
     * legendary set is one thing on both sides of the request: the same flag
     * decides which board a node is clamped to, which board it is sent on, and
     * whether the tab is sealed. */
    $legendaryNodes = array_values(array_filter($publicNodes, static function ($node) { return $node['is_legendary']; }));
    $standardNodes = array_values(array_filter($publicNodes, static function ($node) { return !$node['is_legendary']; }));
    $rankMetForLegendary = $legendaryMinRank === 0 || $rank >= $legendaryMinRank;
    pw_json([
        'ok' => true,
        'credits' => $credits,
        'reputation' => array_merge($reputation, ['level_number' => $rank]),
        'effects' => $effects,
        'queue_transmissions_ready' => $queueTransmissionsReady,
        'queue' => $queueNode,
        'transmissions' => $transmissions,
        'categories' => $categories,
        'nodes' => $standardNodes,
        'board' => pw_research_board_size(false),
        // Present only once a legendary category actually exists, so a command
        // with no endgame branch authored yet shows no second tab at all rather
        // than an empty one.
        'legendary' => !$finalCategoryIds ? null : [
            /* Sealed unless the gate is open -- or unless the player already
             * owns something on this board. A protocol can only have been
             * bought while the gate was open, and an administrator adding one
             * more ordinary node afterwards closes it again; without this, that
             * edit would hide an already-earned benefit behind a tab the player
             * can no longer open. When locked, the filter above has already
             * reduced this list to exactly those owned protocols. */
            'locked' => $legendaryLocked && !$legendaryNodes,
            'nodes' => $legendaryNodes,
            'board' => pw_research_board_size(true),
            'node_count' => $legendaryNodeCount,
            'protocols_remaining' => $protocolsRemaining,
            'required_rank' => $legendaryMinRank,
            'rank_met' => $rankMetForLegendary,
        ],
        'summary' => ['unlocked_count' => count($unlocked), 'available_count' => count(array_filter($publicNodes, static function ($node) { return $node['can_unlock']; }))],
    ]);
} catch (Throwable $e) {
    pw_error('Could not load the Research Facility. Please try again.', 503);
}
