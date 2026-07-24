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
        $achievement['progress'] = $achievement['unlocked']
            ? (int)$achievement['target']
            : min((int)$achievement['target'], (int)($progress[$achievement['progress_type']] ?? 0));
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
    $reputation = pw_reputation_info($points);
    $nextRankUnlocks = [];
    if (!empty($reputation['next_level_id'])) {
        $nextName = (string)$reputation['next_level_name'];
        $nextThreshold = (int)$reputation['next_level_threshold'];
        $nextColor = (string)($reputation['next_level_color'] ?? '#a279ec');
        // These rank distinctions are immediate, built-in rank effects. They
        // appear before content unlocks so every next rank has a complete,
        // truthful preview even if no timeline entry has been assigned yet.
        $nextRankUnlocks[] = [
            'type' => 'forum_rank',
            'title' => 'Forum rank title',
            'eyebrow' => 'Community identity',
            'description' => 'Your forum standing will display as ' . $nextName . '.',
            'accent' => $nextColor,
        ];
        $nextRankUnlocks[] = [
            'type' => 'profile_aura',
            'title' => 'Profile aura',
            'eyebrow' => 'Profile distinction',
            'description' => 'Your reputation bar and profile rank signal will adopt the ' . $nextName . ' aura.',
            'accent' => $nextColor,
        ];
        $nextRankUnlocks[] = [
            'type' => 'rank_marker',
            'title' => 'Standing marker',
            'eyebrow' => 'Community standing',
            'description' => 'Your reputation badge advances to rank ' . (int)$reputation['next_level_number'] . ' in the community ladder.',
            'accent' => $nextColor,
        ];
        try {
            $unlockStmt = $db->prepare(
                'SELECT title, era_label, date_label, summary, accent_color
                 FROM timeline_events
                 WHERE is_published = 1 AND required_level_id = ?
                 ORDER BY sort_order ASC, id ASC'
            );
            $unlockStmt->execute([(int)$reputation['next_level_id']]);
            foreach ($unlockStmt->fetchAll() as $event) {
                $when = array_filter([trim((string)$event['era_label']), trim((string)$event['date_label'])]);
                $detail = trim((string)$event['summary']);
                $nextRankUnlocks[] = [
                    'type' => 'timeline',
                    'title' => (string)$event['title'],
                    'eyebrow' => 'Timeline record',
                    'description' => trim(($when ? implode(' · ', $when) . ' — ' : '') . $detail),
                    'accent' => preg_match('/\A#[a-fA-F0-9]{6}\z/', (string)$event['accent_color']) ? $event['accent_color'] : $nextColor,
                ];
            }
        } catch (PDOException $e) {
            // Timeline Control may be deployed after Reputation; rank identity
            // previews remain useful while that optional unlock source is absent.
        }
        foreach ($nextRankUnlocks as &$unlock) {
            $unlock['threshold'] = $nextThreshold;
            $unlock['rank_name'] = $nextName;
        }
        unset($unlock);
    }
    pw_json(['ok' => true, 'reputation' => $reputation, 'next_rank_unlocks' => $nextRankUnlocks, 'achievements' => $achievements, 'showcase_keys' => $showcaseKeys]);
} catch (Throwable $e) {
    pw_json(['ok' => false, 'error' => 'Reputation history becomes available after the reputation expansion migration.', 'migration_required' => true], 503);
}
