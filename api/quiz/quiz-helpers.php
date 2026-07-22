<?php
/**
 * Shared quiz data access and scoring.
 *
 * Required by the public quiz endpoints (api/quiz/questions.php,
 * api/save-quiz-result.php) and by Quiz Control's admin endpoints, so the
 * two can never drift on how an answer set is scored or which Overlord a
 * score index refers to.
 */

require_once __DIR__ . '/../helpers.php';

/**
 * Which parts of sql/migration_quiz_enhancements.sql have been applied.
 *
 * A missing column or table is a hard SQL error rather than a NULL, so every
 * read and write path checks here first and degrades to the pre-migration
 * behaviour when a piece is absent. Deploy order is therefore not
 * load-bearing -- the same approach as pw_dispatch_has_visibility_column().
 * Cached for the request; these are cheap metadata reads but they are on the
 * public quiz path.
 */
function pw_quiz_capabilities(): array {
    static $caps = null;
    if ($caps !== null) {
        return $caps;
    }
    $caps = ['managed' => false, 'weights' => false, 'sort_order' => false, 'answers' => false, 'blurb' => false];
    try {
        $db = pw_db();
        $caps['managed'] = (bool)$db->query("SHOW TABLES LIKE 'quiz_questions'")->fetch();
        if (!$caps['managed']) {
            return $caps;
        }
        $caps['weights'] = (bool)$db->query("SHOW TABLES LIKE 'quiz_option_weights'")->fetch();
        $caps['answers'] = (bool)$db->query("SHOW TABLES LIKE 'quiz_result_answers'")->fetch();
        $caps['sort_order'] = (bool)$db->query("SHOW COLUMNS FROM quiz_options LIKE 'sort_order'")->fetch();
        $caps['blurb'] = (bool)$db->query("SHOW COLUMNS FROM overlords LIKE 'quiz_result_blurb'")->fetch();
    } catch (Throwable $e) {
        // Quiz Control's own migration may not have been run yet either.
    }
    return $caps;
}

/**
 * Built-in Overlord copy, keyed by slug, used when a record is missing from
 * Overlord Control. Keeps the quiz renderable on an installation whose
 * overlords table is empty rather than showing six blank cards.
 *
 * The accent/glow pair are the same six colours the .affinity-themed rules in
 * css/community.css already use for this cast, repeated here rather than in a
 * second stylesheet so the quiz page (which is served by css/public.css and
 * never imports community.css) has exactly one source for them.
 */
function pw_quiz_overlord_defaults(): array {
    return [
        'syn-dravus'    => ['name' => 'Syn Dravus',    'epithet' => 'The Mindweaver',   'domain' => 'Overlord of Neoh',        'portrait' => 'images/char-syn.jpg',     'accent' => '#a279ec', 'glow' => 'rgba(162,121,236,0.4)', 'blurb' => 'You rule through knowledge no one else has. People fear what you might already know about them — and they’re right to.'],
        'malric-thorne' => ['name' => 'Malric Thorne', 'epithet' => 'The Black Regent',  'domain' => 'Overlord of Cerius',      'portrait' => 'images/char-malric.jpg',  'accent' => '#e05a4a', 'glow' => 'rgba(224,90,74,0.4)',   'blurb' => 'You rule through control, seized and never loosened. Order is the only mercy you believe in.'],
        'korrus-vale'   => ['name' => 'Korrus Vale',   'epithet' => 'The Reactor King',  'domain' => 'Overlord of Reanium',     'portrait' => 'images/char-korrus.jpg',  'accent' => '#8fe04a', 'glow' => 'rgba(143,224,74,0.4)',  'blurb' => 'You rule through efficiency, even when the numbers are grim. The system survives — that’s the point.'],
        'lysara-venthe' => ['name' => 'Lysara Venthe', 'epithet' => 'The Tidekeeper',    'domain' => 'Overlord of Asmecu',      'portrait' => 'images/char-lysara.jpg',  'accent' => '#4fb3e8', 'glow' => 'rgba(79,179,232,0.4)',  'blurb' => 'You rule through care that looks like softness and isn’t. Everyone feels safe near you. Not everyone should.'],
        'zura-kaleth'   => ['name' => 'Zura Kaleth',   'epithet' => 'The Rootbinder',    'domain' => 'Overlord of Babki Prime', 'portrait' => 'images/char-zura.jpg',    'accent' => '#4f9d5c', 'glow' => 'rgba(79,157,92,0.4)',   'blurb' => 'You rule through patience, letting things take the shape they need to. Nothing near you stays the same for long.'],
        'maerion-thal'  => ['name' => 'Maerion Thal',  'epithet' => 'The Sky Duke',      'domain' => 'Overlord of High Hammer', 'portrait' => 'images/char-maerion.jpg', 'accent' => '#f0c479', 'glow' => 'rgba(240,196,121,0.4)', 'blurb' => 'You rule through honor and reputation, staged as carefully as any duel. Your word is the one currency you never let devalue.'],
    ];
}

/**
 * The six quiz Overlords, in score-index order.
 *
 * The order is pw_overlord_icon_keys() and deliberately NOT overlords.sort_order:
 * a score index is baked into every stored quiz_results.scores_json, every
 * quiz_options.score_index and the fixed icon catalog, so reordering the roster
 * in Overlord Control must never change what an index means. (Same reasoning as
 * the Worlds atlas, which maps medallions by slug for exactly this reason.)
 *
 * Live values come from Overlord Control -- previously the quiz hardcoded its
 * own copy of all six, so an edited portrait or epithet never reached it.
 */
function pw_quiz_overlord_cast(): array {
    $slugs = pw_overlord_icon_keys();
    $defaults = pw_quiz_overlord_defaults();
    $records = [];

    try {
        $caps = pw_quiz_capabilities();
        $blurbCol = $caps['blurb'] ? 'o.quiz_result_blurb' : "''";
        $placeholders = implode(',', array_fill(0, count($slugs), '?'));
        $stmt = pw_db()->prepare(
            'SELECT o.slug, o.name, o.epithet, o.portrait_image_url, o.accent_color, o.accent_glow,
                    ' . $blurbCol . ' AS quiz_result_blurb, w.name AS world_name
             FROM overlords o
             LEFT JOIN worlds w ON w.id = o.world_id
             WHERE o.slug IN (' . $placeholders . ')'
        );
        $stmt->execute($slugs);
        foreach ($stmt->fetchAll() as $row) {
            $records[$row['slug']] = $row;
        }
    } catch (Throwable $e) {
        // Overlord Control's table may be missing; fall through to defaults.
    }

    $cast = [];
    foreach ($slugs as $index => $slug) {
        $fallback = $defaults[$slug] ?? ['name' => 'Overlord ' . ($index + 1), 'epithet' => '', 'domain' => '', 'portrait' => '', 'blurb' => '', 'accent' => '', 'glow' => ''];
        $row = $records[$slug] ?? null;
        $domain = $fallback['domain'];
        if ($row && !empty($row['world_name'])) {
            $domain = 'Overlord of ' . $row['world_name'];
        }
        $cast[] = [
            'score_index'  => $index,
            'slug'         => $slug,
            'name'         => ($row && $row['name'] !== '') ? $row['name'] : $fallback['name'],
            'epithet'      => ($row && $row['epithet'] !== '') ? $row['epithet'] : $fallback['epithet'],
            'domain'       => $domain,
            'portrait'     => ($row && $row['portrait_image_url'] !== '') ? $row['portrait_image_url'] : $fallback['portrait'],
            'blurb'        => ($row && $row['quiz_result_blurb'] !== '') ? $row['quiz_result_blurb'] : $fallback['blurb'],
            'accent_color' => ($row && $row['accent_color'] !== '') ? $row['accent_color'] : $fallback['accent'],
            'accent_glow'  => ($row && $row['accent_glow'] !== '') ? $row['accent_glow'] : $fallback['glow'],
            'href'         => 'overlord.html?slug=' . $slug,
        ];
    }
    return $cast;
}

/**
 * The active question set, with each option's Overlord weights resolved.
 *
 * Returns [] when Quiz Control has no publishable questions, which is the
 * signal for the caller to fall back to the client's built-in list.
 *
 * @return array<int,array{id:int,text:string,options:array<int,array{id:int,text:string,weights:array<int,int>}>}>
 */
function pw_quiz_active_questions(): array {
    $caps = pw_quiz_capabilities();
    if (!$caps['managed']) {
        return [];
    }

    $order = $caps['sort_order'] ? 'o.sort_order ASC, o.id ASC' : 'o.score_index ASC, o.id ASC';
    try {
        $rows = pw_db()->query(
            'SELECT q.id AS question_id, q.question_text, o.id AS option_id, o.option_text, o.score_index
             FROM quiz_questions q
             JOIN quiz_options o ON o.question_id = q.id
             WHERE q.is_active = 1
             ORDER BY q.sort_order ASC, q.id ASC, ' . $order
        )->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
    if (!$rows) {
        return [];
    }

    $weights = pw_quiz_option_weights(array_map(function ($row) { return (int)$row['option_id']; }, $rows));

    $questions = [];
    foreach ($rows as $row) {
        $qid = (int)$row['question_id'];
        $oid = (int)$row['option_id'];
        if (!isset($questions[$qid])) {
            $questions[$qid] = ['id' => $qid, 'text' => $row['question_text'], 'options' => []];
        }
        // An option with no weight at all scores nothing; it is still a valid
        // answer, so it is kept rather than silently dropped.
        $questions[$qid]['options'][$oid] = [
            'id'      => $oid,
            'text'    => $row['option_text'],
            'weights' => $weights[$oid] ?? ($row['score_index'] !== null ? [(int)$row['score_index'] => 1] : []),
        ];
    }

    // Drop any question left with no options at all.
    return array_filter($questions, function ($question) {
        return count($question['options']) > 0;
    });
}

/**
 * Overlord weights for the given option ids, as [option_id => [score_index => weight]].
 * Empty before the weights migration has run, which leaves callers on the
 * legacy one-point-per-score_index behaviour.
 */
function pw_quiz_option_weights(array $optionIds): array {
    $optionIds = array_values(array_unique(array_filter(array_map('intval', $optionIds))));
    if (!$optionIds || !pw_quiz_capabilities()['weights']) {
        return [];
    }
    try {
        $placeholders = implode(',', array_fill(0, count($optionIds), '?'));
        $stmt = pw_db()->prepare(
            'SELECT option_id, score_index, weight FROM quiz_option_weights
             WHERE option_id IN (' . $placeholders . ') AND weight > 0'
        );
        $stmt->execute($optionIds);
    } catch (PDOException $e) {
        return [];
    }
    $weights = [];
    foreach ($stmt->fetchAll() as $row) {
        $index = (int)$row['score_index'];
        if ($index < 0 || $index > 5) {
            continue;
        }
        $weights[(int)$row['option_id']][$index] = (int)$row['weight'];
    }
    return $weights;
}

/**
 * Scores a submitted answer set against the active questions.
 *
 * This is a security boundary, not a convenience. A Pure Resonance result
 * unlocks an Overlord icon through pw_unlock_overlord_icon() and overwrites
 * users.overlord_affinity, so the client is never trusted to report its own
 * totals or its own winning Overlord -- previously it sent both and the server
 * stored them unchecked. This mirrors api/timeline.php's discovery endpoint,
 * which re-checks its own gate rather than trusting the list response.
 *
 * Errors exit through pw_error(); callers do not need to handle a failure.
 *
 * @param array $submitted  [['question_id' => int, 'option_id' => int], ...]
 * @return array{scores:array<int,int>,winner:int,total:int,answers:array<int,int>}
 */
function pw_quiz_score_answers(array $submitted): array {
    $questions = pw_quiz_active_questions();
    if (!$questions) {
        pw_error('The quiz is not accepting results right now.', 503);
    }

    // Collapse to question_id => option_id, rejecting a repeated question
    // rather than letting a later entry silently overwrite an earlier one.
    $answers = [];
    foreach ($submitted as $entry) {
        if (!is_array($entry)) {
            pw_error('Malformed answer.');
        }
        $qid = isset($entry['question_id']) ? (int)$entry['question_id'] : 0;
        $oid = isset($entry['option_id']) ? (int)$entry['option_id'] : 0;
        if ($qid <= 0 || $oid <= 0) {
            pw_error('Malformed answer.');
        }
        if (isset($answers[$qid])) {
            pw_error('A question was answered more than once.');
        }
        if (!isset($questions[$qid])) {
            pw_error('Answer refers to a question that is no longer active.');
        }
        if (!isset($questions[$qid]['options'][$oid])) {
            pw_error('Answer refers to an option that does not belong to its question.');
        }
        $answers[$qid] = $oid;
    }

    if (count($answers) !== count($questions)) {
        pw_error('Every question must be answered.');
    }

    $scores = array_fill(0, 6, 0);
    foreach ($answers as $qid => $oid) {
        foreach ($questions[$qid]['options'][$oid]['weights'] as $index => $weight) {
            if ($index >= 0 && $index <= 5) {
                $scores[$index] += $weight;
            }
        }
    }

    $total = array_sum($scores);
    if ($total <= 0) {
        pw_error('The quiz could not be scored. Please try again.', 503);
    }

    // Ties resolve to the lowest score index, matching the quiz's original
    // strictly-greater-than comparison so an unchanged answer set keeps
    // producing an unchanged Overlord.
    $winner = 0;
    for ($i = 1; $i < 6; $i++) {
        if ($scores[$i] > $scores[$winner]) {
            $winner = $i;
        }
    }

    return ['scores' => $scores, 'winner' => $winner, 'total' => $total, 'answers' => $answers];
}

/**
 * Share of members currently resonating with each Overlord, as
 * [slug => ['count' => int, 'pct' => int]] plus a 'total' member count.
 *
 * Reads users.overlord_affinity rather than counting quiz_results rows: that
 * column already holds one current answer per member, so a member who retakes
 * the quiz ten times counts once instead of skewing the distribution.
 */
function pw_quiz_affinity_distribution(): array {
    $cast = pw_quiz_overlord_cast();
    $byName = [];
    foreach ($cast as $overlord) {
        $byName[$overlord['name']] = $overlord['slug'];
    }

    $counts = [];
    foreach ($cast as $overlord) {
        $counts[$overlord['slug']] = 0;
    }
    $total = 0;
    try {
        $rows = pw_db()->query(
            "SELECT overlord_affinity, COUNT(*) AS cnt FROM users
             WHERE overlord_affinity IS NOT NULL AND overlord_affinity <> ''
             GROUP BY overlord_affinity"
        )->fetchAll();
        foreach ($rows as $row) {
            $slug = $byName[$row['overlord_affinity']] ?? null;
            if ($slug === null) {
                continue;
            }
            $counts[$slug] = (int)$row['cnt'];
            $total += (int)$row['cnt'];
        }
    } catch (PDOException $e) {
        return ['total' => 0, 'shares' => []];
    }

    $shares = [];
    foreach ($counts as $slug => $count) {
        $shares[$slug] = [
            'count' => $count,
            'pct'   => $total > 0 ? (int)round(($count / $total) * 100) : 0,
        ];
    }
    return ['total' => $total, 'shares' => $shares];
}
