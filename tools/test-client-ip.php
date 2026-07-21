<?php
/**
 * Regression checks for hardened client IP resolution.
 * Run on the server with: php tools/test-client-ip.php
 *
 * This has no database or network dependency -- api/client-ip.php is a
 * standalone file precisely so this script can require it directly, the
 * same reasoning tools/test-dispatch-translator.php requires
 * api/dispatch-translation-drafts.php directly instead of the full
 * api/helpers.php bootstrap (which needs a real database connection).
 */
require_once dirname(__DIR__) . '/api/client-ip.php';

// A real address inside Cloudflare's published edge ranges (173.245.48.0/20)
// and one inside their IPv6 range (2400:cb00::/32), used to simulate "this
// request actually came through Cloudflare's proxy."
const CF_EDGE_IPV4 = '173.245.48.1';
const CF_EDGE_IPV6 = '2400:cb00:1234:5678::1';
// A real, definitely-not-Cloudflare address (Google Public DNS), used to
// simulate a visitor connecting directly (or a spoofing attempt).
const NON_CF_IPV4 = '8.8.8.8';

$cases = [
    [
        'name' => 'plain IPv4 REMOTE_ADDR, no proxy headers',
        'server' => ['REMOTE_ADDR' => '203.0.113.42'],
        'expected' => '203.0.113.42',
    ],
    [
        'name' => 'plain IPv6 REMOTE_ADDR, no proxy headers',
        'server' => ['REMOTE_ADDR' => '2001:db8::1'],
        'expected' => '2001:db8::1',
    ],
    [
        'name' => 'malformed REMOTE_ADDR falls back to "unknown"',
        'server' => ['REMOTE_ADDR' => 'not-an-ip-address'],
        'expected' => 'unknown',
    ],
    [
        'name' => 'missing REMOTE_ADDR entirely falls back to "unknown"',
        'server' => [],
        'expected' => 'unknown',
    ],
    [
        'name' => 'spoofed CF-Connecting-IP is ignored when REMOTE_ADDR is not Cloudflare',
        'server' => ['REMOTE_ADDR' => NON_CF_IPV4, 'HTTP_CF_CONNECTING_IP' => '6.6.6.6'],
        'expected' => NON_CF_IPV4,
    ],
    [
        'name' => 'spoofed X-Forwarded-For is ignored when REMOTE_ADDR is not Cloudflare',
        'server' => ['REMOTE_ADDR' => NON_CF_IPV4, 'HTTP_X_FORWARDED_FOR' => '6.6.6.6'],
        'expected' => NON_CF_IPV4,
    ],
    [
        'name' => 'valid CF-Connecting-IP (IPv4) is trusted when REMOTE_ADDR is a real Cloudflare edge IP',
        'server' => ['REMOTE_ADDR' => CF_EDGE_IPV4, 'HTTP_CF_CONNECTING_IP' => '198.51.100.7'],
        'expected' => '198.51.100.7',
    ],
    [
        'name' => 'valid CF-Connecting-IP (IPv6) is trusted when REMOTE_ADDR is a real Cloudflare edge IP',
        'server' => ['REMOTE_ADDR' => CF_EDGE_IPV4, 'HTTP_CF_CONNECTING_IP' => '2001:db8::abcd'],
        'expected' => '2001:db8::abcd',
    ],
    [
        'name' => 'trusted request arriving over a Cloudflare IPv6 edge address too',
        'server' => ['REMOTE_ADDR' => CF_EDGE_IPV6, 'HTTP_CF_CONNECTING_IP' => '198.51.100.7'],
        'expected' => '198.51.100.7',
    ],
    [
        'name' => 'malformed CF-Connecting-IP behind Cloudflare falls through to a valid X-Forwarded-For',
        'server' => ['REMOTE_ADDR' => CF_EDGE_IPV4, 'HTTP_CF_CONNECTING_IP' => 'garbage', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9'],
        'expected' => '198.51.100.9',
    ],
    [
        'name' => 'X-Forwarded-For chain: only the first (client) entry is used',
        'server' => ['REMOTE_ADDR' => CF_EDGE_IPV4, 'HTTP_X_FORWARDED_FOR' => '198.51.100.9, 10.0.0.1, 10.0.0.2'],
        'expected' => '198.51.100.9',
    ],
    [
        'name' => 'malformed first X-Forwarded-For entry discards the whole header rather than reading past it',
        'server' => ['REMOTE_ADDR' => CF_EDGE_IPV4, 'HTTP_X_FORWARDED_FOR' => 'not-an-ip, 198.51.100.9'],
        'expected' => CF_EDGE_IPV4,
    ],
    [
        'name' => 'both proxy headers malformed behind Cloudflare falls back to REMOTE_ADDR',
        'server' => ['REMOTE_ADDR' => CF_EDGE_IPV4, 'HTTP_CF_CONNECTING_IP' => 'garbage', 'HTTP_X_FORWARDED_FOR' => 'also garbage'],
        'expected' => CF_EDGE_IPV4,
    ],
];

$failures = 0;
foreach ($cases as $case) {
    $_SERVER = array_diff_key($_SERVER, array_flip(['REMOTE_ADDR', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR']));
    foreach ($case['server'] as $key => $value) {
        $_SERVER[$key] = $value;
    }
    $actual = pw_client_ip();
    if ($actual !== $case['expected']) {
        $failures++;
        fwrite(STDERR, "FAIL: {$case['name']}\n  expected: {$case['expected']}\n  actual:   {$actual}\n");
    }
}

// Direct CIDR boundary checks, independent of pw_client_ip()'s header logic.
$cidrCases = [
    ['ip' => '173.245.48.1', 'cidr' => '173.245.48.0/20', 'expected' => true],
    ['ip' => '173.245.63.255', 'cidr' => '173.245.48.0/20', 'expected' => true],
    ['ip' => '173.245.64.1', 'cidr' => '173.245.48.0/20', 'expected' => false],
    ['ip' => '2400:cb00:ffff::1', 'cidr' => '2400:cb00::/32', 'expected' => true],
    ['ip' => '2400:cb01::1', 'cidr' => '2400:cb00::/32', 'expected' => false],
    ['ip' => 'not-an-ip', 'cidr' => '173.245.48.0/20', 'expected' => false],
];
foreach ($cidrCases as $case) {
    $actual = pw_ip_in_cidr($case['ip'], $case['cidr']);
    if ($actual !== $case['expected']) {
        $failures++;
        fwrite(STDERR, "FAIL: pw_ip_in_cidr({$case['ip']}, {$case['cidr']})\n  expected: " . var_export($case['expected'], true) . "\n  actual:   " . var_export($actual, true) . "\n");
    }
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} client IP test(s) failed.\n");
    exit(1);
}

echo "Client IP resolution regression checks passed.\n";
