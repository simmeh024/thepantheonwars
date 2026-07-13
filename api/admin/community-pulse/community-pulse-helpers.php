<?php
/**
 * Forum activity grouped by board for the Admin Home Community Pulse card.
 * Topics and replies are both posts; deleted rows never contribute. The two
 * grouped source scans avoid the former per-board query pattern.
 */
function pw_get_community_pulse(PDO $db): array
{
    $rows = $db->query(
        "SELECT b.slug, b.name, COALESCE(SUM(post_totals.post_count), 0) AS post_count
         FROM forum_boards b
         LEFT JOIN (
             SELECT board, COUNT(*) AS post_count
             FROM topics
             WHERE is_deleted = 0 AND created_at >= NOW() - INTERVAL 7 DAY
             GROUP BY board
             UNION ALL
             SELECT t.board, COUNT(*) AS post_count
             FROM comments c
             INNER JOIN topics t ON t.id = c.topic_id
             WHERE c.is_deleted = 0
               AND t.is_deleted = 0
               AND c.created_at >= NOW() - INTERVAL 7 DAY
             GROUP BY t.board
         ) post_totals ON post_totals.board = b.slug
         GROUP BY b.id, b.slug, b.name, b.sort_order
         ORDER BY post_count DESC, b.sort_order ASC, b.name ASC"
    )->fetchAll();

    return [
        'ok' => true,
        'period_days' => 7,
        'boards' => array_map(static function (array $row): array {
            return [
                'slug' => $row['slug'],
                'name' => $row['name'],
                'posts' => (int)$row['post_count'],
            ];
        }, $rows),
    ];
}
