<?php
require_once __DIR__ . '/../helpers.php';

$period = isset($_GET['period']) ? trim($_GET['period']) : 'this_month';
if (!in_array($period, ['this_month', 'last_month'], true)) {
    $period = 'this_month';
}

$rangeStart = new DateTime('now');
if ($period === 'last_month') {
    $rangeStart->modify('first day of last month');
} else {
    $rangeStart->modify('first day of this month');
}
$rangeStart->setTime(0, 0, 0);
$rangeEnd = (clone $rangeStart)->modify('+1 month');

$start = $rangeStart->format('Y-m-d H:i:s');
$end = $rangeEnd->format('Y-m-d H:i:s');

$db = pw_db();
$stmt = $db->prepare(
    'SELECT t.id, t.title, t.board,
            COUNT(c.id) AS reply_count
     FROM topics t
     JOIN comments c ON c.topic_id = t.id AND c.is_deleted = 0
     WHERE t.is_deleted = 0 AND c.created_at >= ? AND c.created_at < ?
     GROUP BY t.id, t.title, t.board
     ORDER BY reply_count DESC, t.id DESC
     LIMIT 3'
);
$stmt->execute([$start, $end]);
$rows = $stmt->fetchAll();

$out = array_map(function ($r) {
    return [
        'id' => (int)$r['id'],
        'title' => $r['title'],
        'board' => $r['board'],
        'reply_count' => (int)$r['reply_count'],
    ];
}, $rows);

pw_json(['ok' => true, 'period' => $period, 'topics' => $out]);
