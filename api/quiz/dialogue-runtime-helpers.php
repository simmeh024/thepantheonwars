<?php
/**
 * Runtime guardrails for published custom Overlord dialogue. Admin authors
 * describe the graph in JSON; this file owns the member-specific state and
 * validates every gated branch/effect before a browser can receive a reward.
 */
require_once __DIR__ . '/quiz-helpers.php';

function pw_dialogue_runtime_ready(PDO $db): bool {
    try {
        foreach (['user_overlord_dialogue_state', 'user_overlord_dialogue_effects'] as $table) {
            if (!(bool)$db->query("SHOW TABLES LIKE '" . $table . "'")->fetch()) return false;
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function pw_dialogue_runtime_key($value): ?string {
    $key = trim((string)$value);
    return preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/', $key) ? $key : null;
}

function pw_dialogue_runtime_default_state(): array {
    return ['flags' => [], 'variables' => [], 'node_id' => '', 'result_id' => 0];
}

function pw_dialogue_runtime_normalize_state($raw): array {
    $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($decoded)) return pw_dialogue_runtime_default_state();
    $flags = [];
    foreach ((array)($decoded['flags'] ?? []) as $flag) {
        $flag = pw_dialogue_runtime_key($flag);
        if ($flag !== null) $flags[$flag] = true;
    }
    $variables = [];
    foreach ((array)($decoded['variables'] ?? []) as $key => $value) {
        $key = pw_dialogue_runtime_key($key);
        $number = filter_var($value, FILTER_VALIDATE_INT);
        if ($key !== null && $number !== false) $variables[$key] = max(-9999, min(9999, (int)$number));
    }
    $nodeId = pw_dialogue_runtime_key($decoded['node_id'] ?? '') ?? '';
    $resultId = filter_var($decoded['result_id'] ?? 0, FILTER_VALIDATE_INT);
    return ['flags' => array_keys($flags), 'variables' => $variables, 'node_id' => $nodeId, 'result_id' => $resultId !== false && $resultId > 0 ? (int)$resultId : 0];
}

function pw_dialogue_runtime_load_state(PDO $db, int $userId, int $overlordId, bool $forUpdate = false): array {
    $stmt = $db->prepare('SELECT state_json FROM user_overlord_dialogue_state WHERE user_id = ? AND overlord_id = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->execute([$userId, $overlordId]);
    $row = $stmt->fetch();
    return $row ? pw_dialogue_runtime_normalize_state($row['state_json']) : pw_dialogue_runtime_default_state();
}

function pw_dialogue_runtime_published_tree(PDO $db, string $slug): ?array {
    try {
        if (!(bool)$db->query("SHOW TABLES LIKE 'overlord_dialogue_trees'")->fetch()) return null;
        $hasPublishedTree = (bool)$db->query("SHOW COLUMNS FROM overlord_dialogue_trees LIKE 'published_tree_json'")->fetch();
        $treeColumn = $hasPublishedTree ? 't.published_tree_json' : 't.tree_json';
        $wherePublished = $hasPublishedTree ? ' AND t.published_tree_json IS NOT NULL' : '';
        $stmt = $db->prepare(
            'SELECT o.id AS overlord_id, o.slug, ' . $treeColumn . ' AS tree_json
             FROM overlord_dialogue_trees t JOIN overlords o ON o.id = t.overlord_id
             WHERE o.slug = ? AND t.is_enabled = 1' . $wherePublished . ' LIMIT 1'
        );
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        $tree = $row ? json_decode($row['tree_json'], true) : null;
        return $row && is_array($tree) && isset($tree['nodes']) && is_array($tree['nodes'])
            ? ['overlord_id' => (int)$row['overlord_id'], 'slug' => $row['slug'], 'tree' => $tree] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function pw_dialogue_runtime_find_choice(array $tree, string $choiceId): ?array {
    foreach ((array)($tree['nodes'] ?? []) as $node) {
        foreach ((array)($node['choices'] ?? []) as $choice) {
            if (is_array($choice) && isset($choice['id']) && hash_equals((string)$choice['id'], $choiceId)) {
                return ['node' => $node, 'choice' => $choice];
            }
        }
    }
    return null;
}

function pw_dialogue_runtime_result_context(PDO $db, int $userId, string $slug): array {
    try {
        $stmt = $db->prepare('SELECT id, scores_json FROM quiz_results WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        $scores = $row ? json_decode((string)$row['scores_json'], true) : null;
        if (!is_array($scores) || count($scores) < 1) return ['matches' => false, 'resonance' => 0, 'result_id' => 0];
        $scores = array_map('intval', $scores);
        $winner = 0;
        for ($i = 1; $i < count($scores); $i++) if ($scores[$i] > $scores[$winner]) $winner = $i;
        $cast = pw_quiz_overlord_cast();
        $winnerSlug = isset($cast[$winner]['slug']) ? $cast[$winner]['slug'] : '';
        $total = array_sum($scores);
        return [
            'matches' => $winnerSlug !== '' && hash_equals($winnerSlug, $slug),
            'resonance' => $total > 0 ? (int)round((($scores[$winner] ?? 0) / $total) * 100) : 0,
            'result_id' => (int)$row['id'],
        ];
    } catch (Throwable $e) {
        return ['matches' => false, 'resonance' => 0, 'result_id' => 0];
    }
}

function pw_dialogue_runtime_lock_reasons(array $choice, array $state, array $result, array $reputation): array {
    $reasons = [];
    if (!empty($choice['requires_high_resonance']) && (int)$result['resonance'] < 75) $reasons[] = 'Requires high resonance';
    $requiredLevel = isset($choice['required_reputation_level']) ? (int)$choice['required_reputation_level'] : 0;
    if ($requiredLevel > 0 && (int)($reputation['level_number'] ?? 0) < $requiredLevel) $reasons[] = 'Requires Rep Level ' . $requiredLevel;
    $requiredFlag = pw_dialogue_runtime_key($choice['required_flag'] ?? '');
    if ($requiredFlag !== null && !in_array($requiredFlag, $state['flags'], true)) $reasons[] = 'Requires flag: ' . $requiredFlag;
    $variableKey = pw_dialogue_runtime_key($choice['required_variable_key'] ?? '');
    if ($variableKey !== null) {
        $minimum = isset($choice['required_variable_min']) ? (int)$choice['required_variable_min'] : 1;
        if ((int)($state['variables'][$variableKey] ?? 0) < $minimum) $reasons[] = 'Requires ' . $variableKey . ' ≥ ' . $minimum;
    }
    return $reasons;
}

function pw_dialogue_runtime_apply_choice(PDO $db, array $user, array $published, string $choiceId): array {
    if (!pw_dialogue_runtime_ready($db)) pw_error('The Dialogue Editor Workflow migration still needs to be run.', 503);
    $found = pw_dialogue_runtime_find_choice($published['tree'], $choiceId);
    if (!$found) pw_error('That dialogue branch is no longer available.', 404);
    $result = pw_dialogue_runtime_result_context($db, (int)$user['id'], (string)$published['slug']);
    if (empty($result['matches'])) pw_error('Complete this Overlord result before continuing the transmission.', 403);

    $db->beginTransaction();
    try {
        $empty = json_encode(pw_dialogue_runtime_default_state(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $insertState = $db->prepare('INSERT IGNORE INTO user_overlord_dialogue_state (user_id, overlord_id, state_json) VALUES (?, ?, ?)');
        $insertState->execute([(int)$user['id'], (int)$published['overlord_id'], $empty]);
        $state = pw_dialogue_runtime_load_state($db, (int)$user['id'], (int)$published['overlord_id'], true);
        // A fresh quiz result starts a fresh traversal while retaining the
        // editor-authored long-term flags/counters from earlier encounters.
        if ((int)$state['result_id'] !== (int)$result['result_id']) {
            $state['node_id'] = (string)($published['tree']['start_node_id'] ?? '');
            $state['result_id'] = (int)$result['result_id'];
        }
        $sourceNodeId = (string)($found['node']['id'] ?? '');
        if ($state['node_id'] === '') $state['node_id'] = (string)($published['tree']['start_node_id'] ?? '');
        if (!hash_equals($state['node_id'], $sourceNodeId)) {
            // If a network response was lost after a successful write, the
            // browser may retry the same click while the server has already
            // advanced the member to its target. Treat that exact retry as a
            // harmless success instead of trapping the reader on stale UI.
            $retryStmt = $db->prepare('SELECT 1 FROM user_overlord_dialogue_effects WHERE user_id = ? AND overlord_id = ? AND choice_id = ?');
            $retryStmt->execute([(int)$user['id'], (int)$published['overlord_id'], $choiceId]);
            if ($retryStmt->fetchColumn() && hash_equals($state['node_id'], (string)($found['choice']['target_node_id'] ?? ''))) {
                $db->commit();
                return ['state' => $state, 'already_applied' => true, 'reputation_awarded' => 0, 'reputation' => pw_reputation_info((int)$user['reputation'])];
            }
            pw_error('Continue from the dialogue currently open in this transmission.', 409);
        }
        $reputation = pw_reputation_info((int)$user['reputation']);
        $reasons = pw_dialogue_runtime_lock_reasons($found['choice'], $state, $result, $reputation);
        if ($reasons) pw_error(implode('. ', $reasons) . '.', 403);

        $effectStmt = $db->prepare('INSERT IGNORE INTO user_overlord_dialogue_effects (user_id, overlord_id, choice_id) VALUES (?, ?, ?)');
        $effectStmt->execute([(int)$user['id'], (int)$published['overlord_id'], $choiceId]);
        $newEffect = $effectStmt->rowCount() === 1;
        $reputationAwarded = 0;
        $state['node_id'] = (string)($found['choice']['target_node_id'] ?? '');
        if ($newEffect) {
            $setFlag = pw_dialogue_runtime_key($found['choice']['set_flag'] ?? '');
            if ($setFlag !== null && !in_array($setFlag, $state['flags'], true)) $state['flags'][] = $setFlag;
            $variableKey = pw_dialogue_runtime_key($found['choice']['variable_key'] ?? '');
            if ($variableKey !== null) {
                $delta = max(-9999, min(9999, (int)($found['choice']['variable_delta'] ?? 0)));
                $state['variables'][$variableKey] = max(-9999, min(9999, (int)($state['variables'][$variableKey] ?? 0) + $delta));
            }
            $reward = max(0, min(50, (int)($found['choice']['reputation_reward'] ?? 0)));
            if ($reward > 0) {
                $reputationAwarded = pw_award_reputation(
                    $db,
                    (int)$user['id'],
                    $reward,
                    'dialogue_' . (int)$published['overlord_id'] . '_' . substr($choiceId, 0, 48),
                    ['label' => 'Dialogue: ' . mb_substr((string)$found['choice']['label'], 0, 110), 'source_type' => 'overlord_dialogue']
                );
            }
        }
        $encodedState = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stateStmt = $db->prepare('UPDATE user_overlord_dialogue_state SET state_json = ? WHERE user_id = ? AND overlord_id = ?');
        $stateStmt->execute([$encodedState, (int)$user['id'], (int)$published['overlord_id']]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    $reputationStmt = $db->prepare('SELECT reputation FROM users WHERE id = ?');
    $reputationStmt->execute([(int)$user['id']]);
    return ['state' => $state, 'already_applied' => !$newEffect, 'reputation_awarded' => $reputationAwarded, 'reputation' => pw_reputation_info((int)$reputationStmt->fetchColumn())];
}
