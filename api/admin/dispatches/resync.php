<?php
/**
 * Manual "force re-sync": pulls the full commit history for the repo straight
 * from the GitHub REST API and INSERT IGNOREs any commit whose sha isn't
 * already in dispatch_entries. Exists as a safety net for when the webhook
 * missed a push (e.g. it was down, or commits were force-pushed) -- the
 * webhook stays the primary path for new commits, this just catches up.
 */
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../dispatch-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('dispatches.edit');

$input = pw_input();
pw_require_csrf($input);

$repo = 'simmeh024/thepantheonwars';
$branch = 'main';

$db = pw_db();
$stmt = $db->prepare(
    'INSERT IGNORE INTO dispatch_entries (sha, subject, body, tag, author, committed_at, url)
     VALUES (:sha, :subject, :body, :tag, :author, :committed_at, :url)'
);

$fetched = 0;
$inserted = 0;
$page = 1;
$perPage = 100;
$maxPages = 20; // safety cap (2000 commits) so a stuck loop can't hang the request

while ($page <= $maxPages) {
    $url = 'https://api.github.com/repos/' . $repo . '/commits?sha=' . urlencode($branch)
        . '&per_page=' . $perPage . '&page=' . $page;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'User-Agent: ThePantheonWars-AdminConsole',
            'Accept: application/vnd.github+json',
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        pw_error('Could not reach GitHub: ' . $curlError, 502);
    }
    if ($httpCode !== 200) {
        pw_error('GitHub API returned an error (HTTP ' . $httpCode . ').', 502);
    }

    $commits = json_decode($response, true);
    if (!is_array($commits) || count($commits) === 0) {
        break;
    }

    foreach ($commits as $c) {
        if (empty($c['sha']) || empty($c['commit']['message'])) {
            continue;
        }
        $fetched++;

        $fullMessage = trim($c['commit']['message']);
        $lines = preg_split('/\r?\n/', $fullMessage, 2);
        $rawSubject = trim($lines[0]);
        $body = isset($lines[1]) ? trim($lines[1]) : '';
        $subject = pw_dispatch_clean_subject($rawSubject);
        $tag = pw_dispatch_tag($rawSubject, $body);
        $author = !empty($c['commit']['author']['name']) ? $c['commit']['author']['name'] : 'Unknown';
        $timestamp = !empty($c['commit']['committer']['date']) ? $c['commit']['committer']['date'] : gmdate('c');
        if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})/', $timestamp, $tsMatch)) {
            $committedAt = $tsMatch[1] . ' ' . $tsMatch[2];
        } else {
            $committedAt = date('Y-m-d H:i:s', strtotime($timestamp));
        }
        $htmlUrl = !empty($c['html_url']) ? $c['html_url'] : null;

        $stmt->execute([
            ':sha' => $c['sha'],
            ':subject' => $subject,
            ':body' => $body !== '' ? $body : null,
            ':tag' => $tag,
            ':author' => $author,
            ':committed_at' => $committedAt,
            ':url' => $htmlUrl,
        ]);
        if ($stmt->rowCount() > 0) {
            $inserted++;
        }
    }

    if (count($commits) < $perPage) {
        break;
    }
    $page++;
}

pw_log_admin_activity('dispatches_resynced', 'Force re-synced dispatches from GitHub: ' . $fetched . ' commits fetched, ' . $inserted . ' new.', $adminUser);

pw_json(['ok' => true, 'fetched' => $fetched, 'inserted' => $inserted]);
