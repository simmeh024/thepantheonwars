<?php
/**
 * Persistent outcomes for Syn Dravus's post-quiz dialogue.
 *
 * The client owns the presentation and branching copy, but this endpoint owns
 * the reward boundary: it only accepts known terminal outcomes, records each
 * one once per member, and derives the high-resonance lock from the member's
 * most recent server-scored quiz result rather than from browser input.
 */

require_once __DIR__ . '/../helpers.php';

function pw_syn_dialogue_tables_ready(PDO $db): bool {
    foreach (['user_overlord_dialogue_outcomes', 'user_story_flags', 'user_codex_fragments'] as $table) {
        $stmt = $db->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        if (!$stmt->fetchColumn()) {
            return false;
        }
    }
    return true;
}

function pw_syn_dialogue_flags(PDO $db, int $userId): array {
    $stmt = $db->prepare('SELECT flag_key FROM user_story_flags WHERE user_id = ? ORDER BY flag_key ASC');
    $stmt->execute([$userId]);
    return array_map(static function ($row) { return (string)$row['flag_key']; }, $stmt->fetchAll());
}

function pw_syn_dialogue_high_resonance(PDO $db, int $userId): bool {
    $stmt = $db->prepare('SELECT scores_json FROM quiz_results WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$userId]);
    $raw = $stmt->fetchColumn();
    if (!is_string($raw) || $raw === '') {
        return false;
    }
    $scores = json_decode($raw, true);
    if (!is_array($scores)) {
        return false;
    }
    $scores = array_map('intval', $scores);
    $total = array_sum($scores);
    return $total > 0 && isset($scores[0]) && ((int)$scores[0] / $total) >= 0.75;
}

function pw_syn_dialogue_latest_result_is_syn(PDO $db, int $userId): bool {
    $stmt = $db->prepare('SELECT scores_json FROM quiz_results WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$userId]);
    $raw = $stmt->fetchColumn();
    $scores = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($scores) || !isset($scores[0])) {
        return false;
    }
    $winner = 0;
    for ($i = 1; $i < count($scores); $i++) {
        if ((int)$scores[$i] > (int)$scores[$winner]) {
            $winner = $i;
        }
    }
    return $winner === 0;
}

function pw_syn_dialogue_definitions(): array {
    return [
        'encountered' => [
            'label' => 'Syn dialogue: Encountered', 'points' => 0,
            'flags' => ['syn_encountered'],
        ],
        'observer' => [
            'label' => 'Syn dialogue: The Observer', 'points' => 2,
            'flags' => ['syn_questions_method', 'syn_encountered'],
        ],
        'anomalous_subject' => [
            'label' => 'Syn dialogue: Anomalous Subject', 'points' => 5,
            'flags' => ['syn_anomalous_subject', 'syn_encountered'],
        ],
        'defiant' => [
            'label' => 'Syn dialogue: Defiant', 'points' => 3,
            'flags' => ['syn_defied', 'syn_encountered'],
        ],
        'analysis_denied' => [
            'label' => 'Syn dialogue: Analysis Denied', 'points' => 0,
            'flags' => ['syn_denied_analysis', 'syn_encountered'],
        ],
        'analysis_recognized' => [
            'label' => 'Syn dialogue: Recognition', 'points' => 2,
            'flags' => ['syn_recognized_analysis', 'syn_encountered'],
        ],
        'unexpected_pattern' => [
            'label' => 'Syn dialogue: Unexpected Pattern', 'points' => 5,
            'flags' => ['syn_unexpected_pattern', 'syn_encountered'],
            'fragment' => 'unexpected_pattern',
        ],
        'self_aware' => [
            'label' => 'Syn dialogue: Self-Aware', 'points' => 3,
            'flags' => ['syn_self_aware', 'syn_encountered'],
        ],
        'memory_fragment_01' => [
            'label' => 'Syn dialogue: Memory Fragment 01', 'points' => 10,
            'flags' => ['syn_memory_fragment_01', 'syn_encountered'],
            'fragment' => 'memory_fragment_01', 'achievement' => 'something_missing',
        ],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = pw_current_user();
    if (!$user) {
        pw_json(['ok' => true, 'authenticated' => false, 'flags' => [], 'high_syn_resonance' => false]);
    }

    $db = pw_db();
    try {
        $ready = pw_syn_dialogue_tables_ready($db);
        $flags = $ready ? pw_syn_dialogue_flags($db, (int)$user['id']) : [];
        pw_json([
            'ok' => true,
            'authenticated' => true,
            'flags' => $flags,
            'high_syn_resonance' => pw_syn_dialogue_high_resonance($db, (int)$user['id']),
            'migration_required' => !$ready,
        ]);
    } catch (Throwable $e) {
        pw_json(['ok' => true, 'authenticated' => true, 'flags' => [], 'high_syn_resonance' => false, 'migration_required' => true]);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$outcomeKey = isset($input['outcome']) ? trim((string)$input['outcome']) : '';
$definitions = pw_syn_dialogue_definitions();
if (!isset($definitions[$outcomeKey])) {
    pw_error('That dialogue outcome is not recognized.');
}

$db = pw_db();
try {
    if (!pw_syn_dialogue_tables_ready($db)) {
        pw_error('The Syn dialogue migration still needs to be run.', 503);
    }

    $userId = (int)$user['id'];
    if (!pw_syn_dialogue_latest_result_is_syn($db, $userId)) {
        pw_error('Complete a Syn Dravus quiz result before continuing this transmission.', 403);
    }
    $existingFlags = pw_syn_dialogue_flags($db, $userId);
    $isHighResonance = pw_syn_dialogue_high_resonance($db, $userId);
    if ($outcomeKey === 'memory_fragment_01' && !$isHighResonance && !in_array('syn_encountered', $existingFlags, true)) {
        pw_error('That memory has not opened to you yet.', 403);
    }

    $definition = $definitions[$outcomeKey];
    $db->beginTransaction();
    $outcomeStmt = $db->prepare(
        'INSERT IGNORE INTO user_overlord_dialogue_outcomes (user_id, dialogue_key, outcome_key) VALUES (?, ?, ?)'
    );
    $outcomeStmt->execute([$userId, 'syn_dravus_intro', $outcomeKey]);
    $newOutcome = $outcomeStmt->rowCount() === 1;
    $outcomeId = $newOutcome ? (int)$db->lastInsertId() : null;
    $reputationAwarded = 0;

    if ($newOutcome) {
        $flagStmt = $db->prepare('INSERT IGNORE INTO user_story_flags (user_id, flag_key) VALUES (?, ?)');
        foreach ($definition['flags'] as $flag) {
            $flagStmt->execute([$userId, $flag]);
        }
        if (!empty($definition['fragment'])) {
            $fragmentStmt = $db->prepare('INSERT IGNORE INTO user_codex_fragments (user_id, fragment_key) VALUES (?, ?)');
            $fragmentStmt->execute([$userId, $definition['fragment']]);
        }
        if (!empty($definition['achievement'])) {
            $achievementStmt = $db->prepare('INSERT IGNORE INTO user_reputation_achievements (user_id, achievement_key) VALUES (?, ?)');
            $achievementStmt->execute([$userId, $definition['achievement']]);
        }
        if (!empty($definition['points'])) {
            $reputationAwarded = pw_award_reputation(
                $db,
                $userId,
                (int)$definition['points'],
                'syn_' . $outcomeKey,
                ['label' => $definition['label'], 'source_type' => 'syn_dialogue', 'source_id' => $outcomeId]
            );
        }
    }
    $db->commit();

    pw_json([
        'ok' => true,
        'already_completed' => !$newOutcome,
        'reputation_awarded' => $reputationAwarded,
        'flags' => pw_syn_dialogue_flags($db, $userId),
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    pw_error('This outcome could not be preserved right now. Please try again.', 503);
}
