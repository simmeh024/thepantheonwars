<?php
require_once __DIR__ . '/../helpers.php';

$user = pw_require_login();
$page = max(1, min(500, (int)($_GET['page'] ?? 1)));
$perPage = 12;
$offset = ($page - 1) * $perPage;
$db = pw_db();
try {
    $count = $db->prepare('SELECT COUNT(*) FROM reputation_ledger WHERE user_id = ?');
    $count->execute([$user['id']]);
    $total = (int)$count->fetchColumn();
    $stmt = $db->prepare('SELECT id, label, points, multiplier, note, created_at FROM reputation_ledger WHERE user_id = ? ORDER BY id DESC LIMIT ? OFFSET ?');
    $stmt->bindValue(1, (int)$user['id'], PDO::PARAM_INT);
    $stmt->bindValue(2, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    $stmt->execute();
    pw_json(['ok' => true, 'entries' => $stmt->fetchAll(), 'page' => $page, 'pages' => max(1, (int)ceil($total / $perPage)), 'total' => $total]);
} catch (Throwable $e) {
    pw_json(['ok' => true, 'entries' => [], 'page' => 1, 'pages' => 1, 'total' => 0, 'migration_required' => true]);
}
