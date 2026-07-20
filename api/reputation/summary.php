<?php
require_once __DIR__ . '/../helpers.php';

$user = pw_require_login();
$db = pw_db();
try {
    // Backfill achievement badges for existing members on their first visit
    // after rollout; future awards run this same check immediately.
    pw_evaluate_reputation_achievements($db, (int)$user['id']);
    $stmt = $db->prepare('SELECT reputation FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $points = (int)$stmt->fetchColumn();
    $achievementStmt = $db->prepare('SELECT achievement_key, unlocked_at FROM user_reputation_achievements WHERE user_id = ? ORDER BY unlocked_at DESC');
    $achievementStmt->execute([$user['id']]);
    $unlocked = [];
    foreach ($achievementStmt->fetchAll() as $row) $unlocked[$row['achievement_key']] = $row['unlocked_at'];
    $topicStmt = $db->prepare('SELECT COUNT(*) FROM topics WHERE user_id = ? AND is_deleted = 0'); $topicStmt->execute([$user['id']]);
    $postStmt = $db->prepare('SELECT (SELECT COUNT(*) FROM topics WHERE user_id = ? AND is_deleted = 0) + (SELECT COUNT(*) FROM comments WHERE user_id = ? AND is_deleted = 0)'); $postStmt->execute([$user['id'], $user['id']]);
    $quizStmt = $db->prepare('SELECT COUNT(*) FROM quiz_results WHERE user_id = ?'); $quizStmt->execute([$user['id']]);
    $bookStmt = $db->prepare('SELECT COUNT(*) FROM user_book_progress WHERE user_id = ? AND started_at IS NOT NULL'); $bookStmt->execute([$user['id']]);
    $finishStmt = $db->prepare('SELECT COUNT(*) FROM user_book_progress WHERE user_id = ? AND finished_at IS NOT NULL'); $finishStmt->execute([$user['id']]);
    $progress = ['topics' => (int)$topicStmt->fetchColumn(), 'posts' => (int)$postStmt->fetchColumn(), 'quiz' => (int)$quizStmt->fetchColumn(), 'books_started' => (int)$bookStmt->fetchColumn(), 'books_finished' => (int)$finishStmt->fetchColumn()];
    $achievements = [];
    foreach (pw_reputation_achievement_catalog() as $achievement) {
        $achievement['unlocked_at'] = $unlocked[$achievement['key']] ?? null;
        $achievement['unlocked'] = isset($unlocked[$achievement['key']]);
        $achievement['progress'] = min((int)$achievement['target'], (int)($progress[$achievement['progress_type']] ?? 0));
        $achievements[] = $achievement;
    }
    $showcaseKeys = [];
    try {
        $showcaseStmt = $db->prepare('SELECT achievement_key FROM user_reputation_achievement_showcase WHERE user_id = ? ORDER BY position ASC, id ASC');
        $showcaseStmt->execute([(int)$user['id']]);
        $showcaseKeys = array_map(function ($row) { return $row['achievement_key']; }, $showcaseStmt->fetchAll());
    } catch (PDOException $e) {
        // The profile showcase migration can be applied independently.
    }
    pw_json(['ok' => true, 'reputation' => pw_reputation_info($points), 'achievements' => $achievements, 'showcase_keys' => $showcaseKeys]);
} catch (Throwable $e) {
    pw_json(['ok' => false, 'error' => 'Reputation history becomes available after the reputation expansion migration.', 'migration_required' => true], 503);
}
