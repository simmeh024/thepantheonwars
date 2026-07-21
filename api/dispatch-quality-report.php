<?php
/**
 * Weekly self-tuning maintenance pass for Dispatch Translation quality.
 *
 * This is advisory only: it reads the accumulated Good/Bad feedback
 * (dispatch_translation_feedback) and edit-distance log
 * (dispatch_translation_edit_events), then produces a human-readable
 * summary an admin can act on. Nothing here ever rewrites the translator's
 * rules, weights, or thresholds automatically -- those live in PHP source
 * (api/dispatch-translation-drafts.php), not a database setting, so there
 * is no safe "Apply" action for them; a human reads the numbers and decides
 * whether a code change is warranted.
 *
 * The one thing this pipeline DOES change automatically is already done
 * elsewhere and unconditionally: pw_dispatch_nearest_embedding_match() in
 * api/dispatch-embeddings.php excludes any dispatch rated more Bad than
 * Good from ever being recommended as a reference again. That's a simple,
 * safe, permanent rule -- not something that needs a weekly report.
 */
require_once __DIR__ . '/dispatch-translation-drafts.php';

/**
 * Recomputes confidence evidence for a historical dispatch using only the
 * pure-PHP formatter -- no spaCy or embedding worker call, so this is cheap
 * and safe to run for every rated dispatch in a window without repeating
 * the process-count pressure a tight loop of one-shot Python calls caused
 * before (see CLAUDE.md's embeddings-service history). Diff context is
 * supplied (a plain DB lookup) so path_scope evidence is accurate; spaCy's
 * domain hint and the embedding match are deliberately NOT recomputed, so
 * semantic_context is undercounted here relative to what may have actually
 * applied when the translation was first drafted -- an accepted limitation
 * of this advisory report, not a correctness bug in the live translator.
 */
function pw_dispatch_evidence_labels_for(string $subject, string $body, string $tag, array $diffContext): array
{
    $result = pw_dispatch_end_user_draft($subject, $body, $tag, ['diff_context' => $diffContext]);
    return $result['confidence']['evidence'] ?? [];
}

/**
 * Threshold-based connected-components clustering over cached embedding
 * vectors -- deliberately not a general ML clustering algorithm. Any two
 * Bad-rated translations whose cached vectors have cosine similarity >=
 * 0.75 (the same threshold already used to surface a "similar past
 * Dispatch" reference elsewhere) are grouped together. Clusters of size 1
 * are dropped -- a lone bad translation with no similar sibling isn't a
 * "recurring pattern worth a new dictionary entry," just an isolated case.
 */
function pw_dispatch_cluster_bad_translations(PDO $db): array
{
    try {
        $stmt = $db->query(
            "SELECT dte.dispatch_id, dte.embedding_json, dt.translation, de.subject
             FROM dispatch_translation_embeddings dte
             INNER JOIN dispatch_translations dt ON dt.dispatch_id = dte.dispatch_id
             INNER JOIN dispatch_entries de ON de.id = dte.dispatch_id
             INNER JOIN dispatch_translation_feedback dtf ON dtf.dispatch_id = dte.dispatch_id
             GROUP BY dte.dispatch_id, dte.embedding_json, dt.translation, de.subject
             HAVING SUM(dtf.rating = 'bad') > SUM(dtf.rating = 'good')"
        );
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }

    $vectors = [];
    $meta = [];
    foreach ($rows as $row) {
        $vector = json_decode((string)$row['embedding_json'], true);
        if (!is_array($vector)) {
            continue;
        }
        $id = (int)$row['dispatch_id'];
        $vectors[$id] = $vector;
        $meta[$id] = ['dispatch_id' => $id, 'subject' => (string)$row['subject'], 'translation' => (string)$row['translation']];
    }

    $ids = array_keys($vectors);
    $parent = array_combine($ids, $ids);
    $find = function (int $x) use (&$parent, &$find): int {
        while ($parent[$x] !== $x) {
            $x = $parent[$x];
        }
        return $x;
    };
    $union = function (int $a, int $b) use (&$parent, $find): void {
        $rootA = $find($a);
        $rootB = $find($b);
        if ($rootA !== $rootB) {
            $parent[$rootA] = $rootB;
        }
    };

    $count = count($ids);
    for ($i = 0; $i < $count; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            $score = pw_dispatch_cosine_similarity($vectors[$ids[$i]], $vectors[$ids[$j]]);
            if ($score >= 0.75) {
                $union($ids[$i], $ids[$j]);
            }
        }
    }

    $grouped = [];
    foreach ($ids as $id) {
        $grouped[$find($id)][] = $meta[$id];
    }

    return array_values(array_filter($grouped, static function (array $group): bool {
        return count($group) >= 2;
    }));
}

function pw_dispatch_generate_quality_report(PDO $db, string $windowStart, string $windowEnd): array
{
    $ratingStmt = $db->prepare(
        "SELECT rating, COUNT(*) AS c FROM dispatch_translation_feedback
         WHERE created_at >= ? AND created_at < ? GROUP BY rating"
    );
    $ratingStmt->execute([$windowStart, $windowEnd]);
    $good = 0;
    $bad = 0;
    foreach ($ratingStmt->fetchAll() as $row) {
        if ($row['rating'] === 'good') {
            $good = (int)$row['c'];
        } elseif ($row['rating'] === 'bad') {
            $bad = (int)$row['c'];
        }
    }

    $simStmt = $db->prepare(
        "SELECT AVG(similarity_pct) AS avg_sim, COUNT(*) AS c FROM dispatch_translation_edit_events
         WHERE created_at >= ? AND created_at < ? AND similarity_pct IS NOT NULL"
    );
    $simStmt->execute([$windowStart, $windowEnd]);
    $simRow = $simStmt->fetch();

    $overall = [
        'good' => $good,
        'bad' => $bad,
        'total_ratings' => $good + $bad,
        'good_ratio' => ($good + $bad) > 0 ? round($good / ($good + $bad) * 100, 1) : null,
        'avg_similarity' => $simRow && $simRow['avg_sim'] !== null ? round((float)$simRow['avg_sim'], 2) : null,
        'edit_events' => (int)($simRow['c'] ?? 0),
    ];

    $tagStmt = $db->prepare(
        "SELECT de.tag,
                SUM(dtf.rating = 'good') AS good,
                SUM(dtf.rating = 'bad') AS bad
         FROM dispatch_translation_feedback dtf
         INNER JOIN dispatch_entries de ON de.id = dtf.dispatch_id
         WHERE dtf.created_at >= ? AND dtf.created_at < ?
         GROUP BY de.tag
         ORDER BY bad DESC, good DESC"
    );
    $tagStmt->execute([$windowStart, $windowEnd]);
    $byTag = [];
    foreach ($tagStmt->fetchAll() as $row) {
        $g = (int)$row['good'];
        $b = (int)$row['bad'];
        $byTag[] = [
            'tag' => $row['tag'],
            'good' => $g,
            'bad' => $b,
            'bad_rate' => ($g + $b) > 0 ? round($b / ($g + $b) * 100, 1) : 0.0,
        ];
    }

    $ratedStmt = $db->prepare(
        "SELECT dtf.dispatch_id, dtf.rating, de.subject, de.body, de.tag
         FROM dispatch_translation_feedback dtf
         INNER JOIN dispatch_entries de ON de.id = dtf.dispatch_id
         WHERE dtf.created_at >= ? AND dtf.created_at < ?"
    );
    $ratedStmt->execute([$windowStart, $windowEnd]);
    $ratedRows = $ratedStmt->fetchAll();

    $diffContexts = pw_get_dispatch_diff_contexts($db, array_column($ratedRows, 'dispatch_id'));
    $evidenceKeys = ['recognized subject', 'reader safe dictionary', 'commit intent', 'body context', 'path scope', 'semantic context'];
    $byEvidence = [];
    foreach ($evidenceKeys as $key) {
        $byEvidence[$key] = ['fired_good' => 0, 'fired_bad' => 0, 'unfired_good' => 0, 'unfired_bad' => 0];
    }
    foreach ($ratedRows as $row) {
        $labels = pw_dispatch_evidence_labels_for(
            (string)$row['subject'],
            (string)$row['body'],
            (string)$row['tag'],
            $diffContexts[(int)$row['dispatch_id']] ?? []
        );
        $isBad = $row['rating'] === 'bad';
        foreach ($evidenceKeys as $key) {
            $fired = in_array($key, $labels, true);
            $bucket = ($fired ? 'fired_' : 'unfired_') . ($isBad ? 'bad' : 'good');
            $byEvidence[$key][$bucket]++;
        }
    }
    foreach ($byEvidence as $key => $stats) {
        $firedTotal = $stats['fired_good'] + $stats['fired_bad'];
        $unfiredTotal = $stats['unfired_good'] + $stats['unfired_bad'];
        $byEvidence[$key]['fired_bad_rate'] = $firedTotal > 0 ? round($stats['fired_bad'] / $firedTotal * 100, 1) : null;
        $byEvidence[$key]['unfired_bad_rate'] = $unfiredTotal > 0 ? round($stats['unfired_bad'] / $unfiredTotal * 100, 1) : null;
    }

    // Clustering looks at ALL-TIME bad ratings, not just this window -- a
    // meaningful recurring pattern rarely shows up within a single week's
    // handful of ratings, so this deliberately has a different scope than
    // the trend stats above.
    $weakClusters = pw_dispatch_cluster_bad_translations($db);

    return [
        'window_start' => $windowStart,
        'window_end' => $windowEnd,
        'overall' => $overall,
        'by_tag' => $byTag,
        'by_evidence' => $byEvidence,
        'weak_clusters' => $weakClusters,
    ];
}
