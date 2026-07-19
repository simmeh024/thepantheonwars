<?php
require_once __DIR__ . '/../../helpers.php';
pw_require_permission('reputation.view');
$page = max(1, min(500, (int)($_GET['page'] ?? 1)));
$perPage = 30; $offset = ($page - 1) * $perPage;
$query = trim((string)($_GET['q'] ?? ''));
$db = pw_db();
try {
    $where = ''; $params = [];
    if ($query !== '') { $where = ' WHERE u.display_name LIKE ? OR l.label LIKE ?'; $params = ['%' . $query . '%', '%' . $query . '%']; }
    $count = $db->prepare('SELECT COUNT(*) FROM reputation_ledger l JOIN users u ON u.id = l.user_id' . $where); $count->execute($params); $total = (int)$count->fetchColumn();
    $sql = 'SELECT l.*, u.display_name, a.display_name AS actor_name FROM reputation_ledger l JOIN users u ON u.id = l.user_id LEFT JOIN users a ON a.id = l.actor_user_id' . $where . ' ORDER BY l.id DESC LIMIT ? OFFSET ?';
    $stmt = $db->prepare($sql); $position = 1; foreach ($params as $param) $stmt->bindValue($position++, $param); $stmt->bindValue($position++, $perPage, PDO::PARAM_INT); $stmt->bindValue($position, $offset, PDO::PARAM_INT); $stmt->execute();
    pw_json(['ok' => true, 'entries' => $stmt->fetchAll(), 'page' => $page, 'pages' => max(1, (int)ceil($total / $perPage))]);
} catch (Throwable $e) { pw_error('The reputation ledger is unavailable until its migration has been run.', 503); }
