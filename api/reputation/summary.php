<?php
require_once __DIR__ . '/../helpers.php';

$user = pw_require_login();
$db = pw_db();
try {
    $stmt = $db->prepare('SELECT reputation FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $points = (int)$stmt->fetchColumn();
    $achievementStmt = $db->prepare('SELECT achievement_key, unlocked_at FROM user_reputation_achievements WHERE user_id = ? ORDER BY unlocked_at DESC');
    $achievementStmt->execute([$user['id']]);
    $unlocked = [];
    foreach ($achievementStmt->fetchAll() as $row) $unlocked[$row['achievement_key']] = $row['unlocked_at'];
    $achievements = [];
    foreach (pw_reputation_achievement_catalog() as $achievement) {
        $achievement['unlocked_at'] = $unlocked[$achievement['key']] ?? null;
        $achievement['unlocked'] = isset($unlocked[$achievement['key']]);
        $achievements[] = $achievement;
    }
    pw_json(['ok' => true, 'reputation' => pw_reputation_info($points), 'achievements' => $achievements]);
} catch (Throwable $e) {
    pw_json(['ok' => false, 'error' => 'Reputation history becomes available after the reputation expansion migration.', 'migration_required' => true], 503);
}
