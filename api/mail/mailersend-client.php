<?php
/**
 * Shared read-only MailerSend API client. Used by the admin-only domain
 * verification and suppression list endpoints -- sending itself stays in
 * pw_mail_send_via_mailersend() (api/mail.php), which this file does not
 * touch. Every call is best-effort: a MailerSend outage or unexpected
 * response shape returns null rather than throwing, so these diagnostic
 * views degrade to "unavailable" instead of breaking the admin console.
 */
require_once __DIR__ . '/../mail.php';

/**
 * GETs a MailerSend API path (e.g. 'domains' or 'suppressions/hard-bounces')
 * with the configured bearer token. Returns the decoded JSON body, or null
 * if the token isn't configured, the request failed, or the response wasn't
 * valid JSON.
 */
function pw_mailersend_api_get($path, $query = []) {
    if (!pw_mail_uses_mailersend()) return null;

    $url = 'https://api.mailersend.com/v1/' . ltrim($path, '/');
    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . MAILERSEND_API_TOKEN,
            'Accept: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $status < 200 || $status >= 300) return null;

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Looks up MailerSend's verification status for a domain by name (matched
 * against the domains this account has added). Returns
 * ['name'=>, 'is_verified'=>, 'dkim'=>, 'spf'=>, 'mx'=>, 'cname'=>, 'rp_cname'=>]
 * or null if the domain isn't found or the token lacks Domains: Read.
 */
function pw_mailersend_domain_status($domainName) {
    $domainName = strtolower(trim((string)$domainName));
    if ($domainName === '') return null;

    $list = pw_mailersend_api_get('domains', ['limit' => 100]);
    $domains = is_array($list['data'] ?? null) ? $list['data'] : null;
    if ($domains === null) return null;

    $match = null;
    foreach ($domains as $domain) {
        if (strtolower((string)($domain['name'] ?? '')) === $domainName) {
            $match = $domain;
            break;
        }
    }
    if (!$match || empty($match['id'])) return null;

    $out = [
        'name' => $match['name'],
        'is_verified' => !empty($match['is_verified']),
        'dkim' => null, 'spf' => null, 'mx' => null, 'cname' => null, 'rp_cname' => null,
    ];

    $verify = pw_mailersend_api_get('domains/' . rawurlencode($match['id']) . '/verify');
    $records = is_array($verify['data'] ?? null) ? $verify['data'] : null;
    if ($records) {
        foreach (['dkim', 'spf', 'mx', 'cname', 'rp_cname'] as $key) {
            if (array_key_exists($key, $records)) $out[$key] = !empty($records[$key]);
        }
    }

    return $out;
}

/**
 * Fetches one page of a MailerSend suppression list. $type must be one of
 * hard-bounces / spam-complaints / unsubscribes. Returns
 * ['entries' => [['email'=>,'reason'=>,'created_at'=>], ...], 'total' => int|null]
 * or null on failure.
 */
function pw_mailersend_suppressions($type, $page = 1) {
    $allowed = ['hard-bounces', 'spam-complaints', 'unsubscribes'];
    if (!in_array($type, $allowed, true)) return null;

    $result = pw_mailersend_api_get('suppressions/' . $type, ['page' => max(1, (int)$page), 'limit' => 25]);
    $rows = is_array($result['data'] ?? null) ? $result['data'] : null;
    if ($rows === null) return null;

    $entries = array_map(function ($row) {
        return [
            'email' => (string)($row['recipient']['email'] ?? ''),
            'reason' => (string)($row['reason'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
        ];
    }, $rows);

    $total = isset($result['meta']['total']) ? (int)$result['meta']['total'] : null;

    return ['entries' => $entries, 'total' => $total];
}
