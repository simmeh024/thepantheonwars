<?php
require_once __DIR__ . '/../helpers.php';
$userId = (int)($_GET['u'] ?? 0); $token = (string)($_GET['t'] ?? '');
$stmt = pw_db()->prepare('SELECT id, email FROM users WHERE id = ?'); $stmt->execute([$userId]); $user = $stmt->fetch();
if (!$user || !hash_equals(pw_newsletter_unsubscribe_token($user['id'], $user['email']), $token)) { http_response_code(404); exit('This unsubscribe link is invalid.'); }
pw_db()->prepare('UPDATE users SET newsletter_subscribed = 0 WHERE id = ?')->execute([$userId]);
header('Content-Type: text/html; charset=UTF-8'); echo '<!doctype html><title>Unsubscribed</title><p>You have been unsubscribed from The Pantheon Wars mailings.</p>';
