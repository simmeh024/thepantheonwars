<?php
require_once __DIR__ . '/../helpers.php';

$user = pw_require_login();
$stmt = pw_db()->prepare('SELECT provider, provider_email, linked_at, last_used_at FROM oauth_identities WHERE user_id = ? ORDER BY provider');
$stmt->execute([$user['id']]);
$identities = array_map(function ($row) {
    return [
        'provider' => $row['provider'],
        'email' => $row['provider_email'],
        'linked_at' => $row['linked_at'],
        'last_used_at' => $row['last_used_at'],
    ];
}, $stmt->fetchAll());

pw_json(['ok' => true, 'identities' => $identities]);
