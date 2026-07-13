<?php
/**
 * Shared GitHub language-snapshot helpers for public metrics and the admin
 * Home summary. Snapshots are deliberately refreshed at most once per day.
 */

const PW_LANG_SNAPSHOT_TTL = 86400;

function pw_fetch_github_languages($userAgent = 'ThePantheonWars-Site', $timeout = 6) {
    $url = 'https://api.github.com/repos/simmeh024/thepantheonwars/languages';
    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => pw_github_stream_header($userAgent),
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
    ];
    $raw = @file_get_contents($url, false, stream_context_create($opts));
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function pw_langs_to_out($langs) {
    $total = array_sum($langs);
    $out = [];
    if ($total > 0) {
        arsort($langs);
        foreach ($langs as $name => $bytes) {
            $out[] = [
                'name' => $name,
                'bytes' => $bytes,
                'pct' => round(($bytes / $total) * 1000) / 10,
            ];
        }
    }
    return [$out, $total];
}

function pw_ensure_repo_language_snapshot($db) {
    $latest = $db->query(
        'SELECT captured_at FROM repo_language_snapshots ORDER BY captured_at DESC LIMIT 1'
    )->fetch();
    if ($latest && (time() - strtotime($latest['captured_at'] . ' UTC')) < PW_LANG_SNAPSHOT_TTL) {
        return;
    }

    $fresh = pw_fetch_github_languages();
    if ($fresh === null || empty($fresh)) {
        return;
    }

    list($out, $total) = pw_langs_to_out($fresh);
    $stmt = $db->prepare(
        'INSERT INTO repo_language_snapshots (captured_at, total_bytes, languages_json) VALUES (:captured_at, :total, :json)'
    );
    $stmt->execute([
        ':captured_at' => gmdate('Y-m-d H:i:s'),
        ':total' => $total,
        ':json' => json_encode($out),
    ]);
}

function pw_latest_repo_language_snapshot($db) {
    $stmt = $db->query(
        'SELECT captured_at, total_bytes, languages_json FROM repo_language_snapshots ORDER BY captured_at DESC LIMIT 1'
    );
    $row = $stmt->fetch();
    if (!$row) {
        return [
            'found' => false,
            'languages' => [],
            'captured_at' => null,
            'total_bytes' => 0,
        ];
    }

    $languages = json_decode($row['languages_json'], true);
    if (!is_array($languages)) {
        $languages = [];
    }
    return [
        'found' => true,
        'languages' => $languages,
        'captured_at' => $row['captured_at'],
        'next_sync_at' => gmdate('Y-m-d H:i:s', strtotime($row['captured_at'] . ' UTC') + PW_LANG_SNAPSHOT_TTL),
        'total_bytes' => (int)$row['total_bytes'],
    ];
}
