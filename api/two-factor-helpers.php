<?php
/**
 * Local time-based one-time password (TOTP) support for password sign-ins.
 *
 * Secrets are never stored in plaintext. The application encryption key lives
 * outside public_html in config.php, while the database contains only an
 * authenticated AES-256-GCM ciphertext. OAuth providers (Google, Apple) keep
 * their own provider security and deliberately do not enter this
 * password-login flow.
 */

const PW_TWO_FACTOR_PERIOD = 30;
const PW_TWO_FACTOR_DIGITS = 6;
const PW_TWO_FACTOR_PENDING_TTL = 300;

function pw_two_factor_encryption_key(): ?string {
    if (!defined('TWO_FACTOR_ENCRYPTION_KEY') || !extension_loaded('openssl')) {
        return null;
    }
    $key = base64_decode((string)TWO_FACTOR_ENCRYPTION_KEY, true);
    return is_string($key) && strlen($key) === 32 ? $key : null;
}

function pw_two_factor_crypto_available(): bool {
    return pw_two_factor_encryption_key() !== null;
}

function pw_two_factor_table_available(PDO $db): bool {
    static $available = null;
    if ($available !== null) return $available;
    try {
        $db->query('SELECT user_id FROM user_two_factor LIMIT 1');
        $available = true;
    } catch (Throwable $e) {
        $available = false;
    }
    return $available;
}

function pw_two_factor_get_row(PDO $db, int $userId, bool $forUpdate = false): ?array {
    if (!pw_two_factor_table_available($db)) return null;
    $stmt = $db->prepare(
        'SELECT user_id, secret_ciphertext, enabled_at, last_used_counter
         FROM user_two_factor WHERE user_id = ?' . ($forUpdate ? ' FOR UPDATE' : '')
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function pw_two_factor_is_enabled(PDO $db, int $userId): bool {
    $row = pw_two_factor_get_row($db, $userId);
    return $row !== null && !empty($row['enabled_at']);
}

function pw_two_factor_base32_encode(string $bytes): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $buffer = 0;
    $bits = 0;
    $out = '';
    for ($i = 0, $len = strlen($bytes); $i < $len; $i++) {
        $buffer = ($buffer << 8) | ord($bytes[$i]);
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $out .= $alphabet[($buffer >> $bits) & 31];
        }
    }
    if ($bits > 0) {
        $out .= $alphabet[($buffer << (5 - $bits)) & 31];
    }
    return $out;
}

function pw_two_factor_base32_decode(string $secret): ?string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret));
    if ($secret === '') return null;
    $buffer = 0;
    $bits = 0;
    $out = '';
    for ($i = 0, $len = strlen($secret); $i < $len; $i++) {
        $value = strpos($alphabet, $secret[$i]);
        if ($value === false) return null;
        $buffer = ($buffer << 5) | $value;
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $out .= chr(($buffer >> $bits) & 255);
        }
    }
    return $out === '' ? null : $out;
}

function pw_two_factor_generate_secret(): string {
    return pw_two_factor_base32_encode(random_bytes(20));
}

function pw_two_factor_encrypt_secret(string $secret): ?string {
    $key = pw_two_factor_encryption_key();
    if ($key === null) return null;
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ciphertext === false || strlen($tag) !== 16) return null;
    return 'v1.' . base64_encode($iv . $tag . $ciphertext);
}

function pw_two_factor_decrypt_secret(string $payload): ?string {
    $key = pw_two_factor_encryption_key();
    if ($key === null || strpos($payload, 'v1.') !== 0) return null;
    $raw = base64_decode(substr($payload, 3), true);
    if (!is_string($raw) || strlen($raw) < 29) return null;
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);
    $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return is_string($plain) && pw_two_factor_base32_decode($plain) !== null ? $plain : null;
}

function pw_two_factor_normalize_code($code): ?string {
    $code = preg_replace('/\s+/', '', (string)$code);
    return preg_match('/^\d{6}$/', $code) ? $code : null;
}

function pw_two_factor_code_for_counter(string $secret, int $counter): ?string {
    $key = pw_two_factor_base32_decode($secret);
    if ($key === null || $counter < 0) return null;
    $binaryCounter = pack('N2', 0, $counter);
    $hash = hash_hmac('sha1', $binaryCounter, $key, true);
    $offset = ord($hash[19]) & 15;
    $value = ((ord($hash[$offset]) & 127) << 24)
        | (ord($hash[$offset + 1]) << 16)
        | (ord($hash[$offset + 2]) << 8)
        | ord($hash[$offset + 3]);
    return str_pad((string)($value % (10 ** PW_TWO_FACTOR_DIGITS)), PW_TWO_FACTOR_DIGITS, '0', STR_PAD_LEFT);
}

// Permit one adjacent 30-second window for a user's device clock, but return
// the exact counter so callers can block a code being replayed in its window.
function pw_two_factor_matching_counter(string $secret, string $code, ?int $time = null): ?int {
    $code = pw_two_factor_normalize_code($code);
    if ($code === null) return null;
    $counter = intdiv($time ?? time(), PW_TWO_FACTOR_PERIOD);
    foreach ([-1, 0, 1] as $offset) {
        $candidate = $counter + $offset;
        $expected = pw_two_factor_code_for_counter($secret, $candidate);
        if ($expected !== null && hash_equals($expected, $code)) return $candidate;
    }
    return null;
}

function pw_two_factor_provisioning_uri(string $email, string $secret): string {
    $issuer = 'The Pantheon Wars';
    $label = rawurlencode($issuer . ':' . $email);
    return 'otpauth://totp/' . $label . '?secret=' . rawurlencode($secret)
        . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=' . PW_TWO_FACTOR_PERIOD;
}

function pw_two_factor_clear_pending_setup(): void {
    unset($_SESSION['pw_two_factor_setup_secret'], $_SESSION['pw_two_factor_setup_at']);
}

function pw_two_factor_clear_pending_login(): void {
    unset(
        $_SESSION['pw_two_factor_pending_user_id'],
        $_SESSION['pw_two_factor_pending_identifier'],
        $_SESSION['pw_two_factor_pending_at'],
        $_SESSION['pw_two_factor_pending_attempts'],
        $_SESSION['pw_two_factor_pending_remember']
    );
}
