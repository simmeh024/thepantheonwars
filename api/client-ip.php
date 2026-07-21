<?php
/**
 * Client IP resolution -- deliberately kept in its own file with zero
 * dependencies (no db.php, no session/header side effects) so it can be
 * required directly by tools/test-client-ip.php without needing a database
 * connection, the same reasoning api/dispatch-translation-drafts.php is kept
 * standalone for tools/test-dispatch-translator.php.
 *
 * The real, un-spoofable source of a request is REMOTE_ADDR -- everything
 * else is a header the client (or a proxy relaying for the client) chose to
 * send, and is only meaningful when that proxy is one this server actually
 * trusts. Proxy-supplied headers are validated as real IP addresses
 * (FILTER_VALIDATE_IP) and only consulted at all when REMOTE_ADDR itself
 * falls inside Cloudflare's published edge ranges -- otherwise anyone could
 * set CF-Connecting-IP or X-Forwarded-For directly and spoof rate limiting,
 * audit logs, and visitor stats. An invalid/malformed value at any stage is
 * ignored rather than trusted, falling through to the next, safer source.
 */

// Cloudflare's published edge IP ranges (https://www.cloudflare.com/ips-v4
// and /ips-v6). Hardcoded rather than fetched live: these change extremely
// rarely, and a security check that trusts a proxy header must never depend
// on a live third-party HTTP call that could time out, get poisoned, or
// silently fail open. Re-check against Cloudflare's published lists
// occasionally.
const PW_CLOUDFLARE_IP_RANGES = [
    '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
    '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
    '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
    '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
    '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
];

// Binary (inet_pton) CIDR containment check -- works for IPv4 and IPv6
// alike, since both produce a fixed-length byte string and the comparison
// is the same bitwise operation either way.
function pw_ip_in_cidr($ip, $cidr) {
    $parts = explode('/', $cidr, 2);
    if (count($parts) !== 2) {
        return false;
    }
    [$subnet, $bits] = $parts;
    $ipBin = @inet_pton($ip);
    $subnetBin = @inet_pton($subnet);
    if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
        return false;
    }
    $bits = (int)$bits;
    $bytes = intdiv($bits, 8);
    $remainderBits = $bits % 8;
    if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
        return false;
    }
    if ($remainderBits === 0) {
        return true;
    }
    $mask = chr((0xFF << (8 - $remainderBits)) & 0xFF);
    return (substr($ipBin, $bytes, 1) & $mask) === (substr($subnetBin, $bytes, 1) & $mask);
}

function pw_ip_in_ranges($ip, array $ranges) {
    foreach ($ranges as $cidr) {
        if (pw_ip_in_cidr($ip, $cidr)) {
            return true;
        }
    }
    return false;
}

function pw_client_ip() {
    $remoteAddr = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
    $remoteAddrValid = filter_var($remoteAddr, FILTER_VALIDATE_IP) !== false;

    if ($remoteAddrValid && pw_ip_in_ranges($remoteAddr, PW_CLOUDFLARE_IP_RANGES)) {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'] as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }
            $value = (string)$_SERVER[$key];
            if ($key === 'HTTP_X_FORWARDED_FOR') {
                // A client,proxy1,proxy2 chain -- only the first (client) entry
                // is ever considered; a malformed first entry means the whole
                // header is untrusted, not a cue to look further down the chain.
                $value = trim(explode(',', $value)[0]);
            }
            $value = substr($value, 0, 64);
            if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
                return $value;
            }
        }
    }

    return $remoteAddrValid ? $remoteAddr : 'unknown';
}
