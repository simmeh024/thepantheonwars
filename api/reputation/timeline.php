<?php
require_once __DIR__ . '/../helpers.php';

$user = pw_require_login();
$db = pw_db();

try {
    $daily = $db->prepare(
        'SELECT DATE(created_at) AS day, COALESCE(SUM(points), 0) AS points
         FROM reputation_ledger
         WHERE user_id = ? AND created_at >= UTC_TIMESTAMP() - INTERVAL 56 DAY
         GROUP BY DATE(created_at)
         ORDER BY day ASC'
    );
    $daily->execute([(int)$user['id']]);
    $highlight = $db->prepare(
        "SELECT label, points, multiplier, created_at
         FROM reputation_ledger
         WHERE user_id = ? AND (reward_key LIKE 'achievement_%' OR multiplier > 1)
         ORDER BY id DESC LIMIT 6"
    );
    $highlight->execute([(int)$user['id']]);
    pw_json(['ok' => true, 'daily' => $daily->fetchAll(), 'highlights' => $highlight->fetchAll()]);
} catch (Throwable $e) {
    pw_json(['ok' => true, 'daily' => [], 'highlights' => []]);
}
