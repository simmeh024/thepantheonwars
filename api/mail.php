<?php
/**
 * Transactional email foundation.
 *
 * Transport credentials never live in the database or admin UI. When
 * MAILERSEND_API_TOKEN is configured in the outside-webroot secrets config,
 * outbound mail is sent through the MailerSend HTTP API (see
 * pw_mail_send_via_mailersend()); otherwise it falls back to this shared
 * host's native PHP mail() transport. The sender identity and the deliberate
 * enabled/disabled switch are stored in app_settings either way. Templates
 * are database records managed through the permissioned admin console.
 * Calling code receives a result object rather than an exception so mail
 * delivery can never make account, moderation, or sign-in flows fail.
 */

const PW_MAIL_SETTING_KEYS = [
    'mail_enabled',
    'mail_from_name',
    'mail_from_email',
    'mail_reply_to',
];

const PW_MAIL_TEMPLATE_KEYS = [
    'password_reset',
    'welcome',
    'account_banned',
    'verify_account',
];

function pw_mail_uses_mailersend() {
    return defined('MAILERSEND_API_TOKEN') && MAILERSEND_API_TOKEN !== '' && function_exists('curl_init');
}

function pw_mail_default_settings() {
    $phpMailAvailable = function_exists('mail');
    $mailersendAvailable = pw_mail_uses_mailersend();
    return [
        'enabled' => false,
        'from_name' => defined('MAIL_FROM_NAME') ? (string)MAIL_FROM_NAME : 'The Pantheon Wars',
        'from_email' => defined('MAIL_FROM_EMAIL') ? (string)MAIL_FROM_EMAIL : '',
        'reply_to' => defined('MAIL_REPLY_TO') ? (string)MAIL_REPLY_TO : '',
        'transport' => $mailersendAvailable ? 'MailerSend API' : ($phpMailAvailable ? 'PHP mail' : 'Unavailable'),
        'transport_available' => $mailersendAvailable || $phpMailAvailable,
    ];
}

function pw_mail_settings() {
    $settings = pw_mail_default_settings();
    try {
        $stmt = pw_db()->query("SELECT `key`, value FROM app_settings WHERE `key` IN ('mail_enabled', 'mail_from_name', 'mail_from_email', 'mail_reply_to')");
        foreach ($stmt->fetchAll() as $row) {
            switch ($row['key']) {
                case 'mail_enabled': $settings['enabled'] = $row['value'] === '1'; break;
                case 'mail_from_name': $settings['from_name'] = (string)$row['value']; break;
                case 'mail_from_email': $settings['from_email'] = (string)$row['value']; break;
                case 'mail_reply_to': $settings['reply_to'] = (string)$row['value']; break;
            }
        }
    } catch (Throwable $e) {
        // An older database may not have app_settings yet. The disabled
        // defaults are intentional and keep all request paths safe.
    }
    return $settings;
}

function pw_mail_public_settings() {
    $settings = pw_mail_settings();
    return [
        'enabled' => $settings['enabled'],
        'from_name' => $settings['from_name'],
        'from_email' => $settings['from_email'],
        'reply_to' => $settings['reply_to'],
        'transport' => $settings['transport'],
        'transport_available' => $settings['transport_available'],
        'ready' => $settings['enabled'] && $settings['transport_available'] && filter_var($settings['from_email'], FILTER_VALIDATE_EMAIL),
    ];
}

function pw_mail_template_keys() {
    return PW_MAIL_TEMPLATE_KEYS;
}

function pw_mail_variables() {
    return [
        'site_name' => 'The Pantheon Wars',
        'site_url' => 'https://thepantheonwars.com',
        'login_url' => 'https://thepantheonwars.com',
        'support_email' => 'privacy@thepantheonwars.com',
        'year' => gmdate('Y'),
        'recipient_name' => 'Reader',
        'recipient_email' => '',
        // Reset tokens are deliberately placed in the URL fragment by the
        // password-reset flow. Fragments are never sent in HTTP requests, so
        // the credential cannot end up in web-server logs or Referer headers.
        'reset_url' => 'https://thepantheonwars.com/password-reset.html',
        'verify_url' => 'https://thepantheonwars.com',
        'ban_reason' => 'Please contact support if you believe this is a mistake.',
    ];
}

function pw_mail_render($source, $variables, $html = false) {
    $values = array_merge(pw_mail_variables(), is_array($variables) ? $variables : []);
    return preg_replace_callback('/{{\s*([a-z_]+)\s*}}/i', function ($match) use ($values, $html) {
        $key = strtolower($match[1]);
        if (!array_key_exists($key, $values)) return '';
        $value = (string)$values[$key];
        return $html ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $value;
    }, (string)$source);
}

function pw_mail_template($key) {
    if (!in_array($key, pw_mail_template_keys(), true)) return null;
    try {
        $stmt = pw_db()->prepare('SELECT template_key, label, description, subject, html_body, text_body, is_enabled FROM mail_templates WHERE template_key = ?');
        $stmt->execute([$key]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Store troubleshooting metadata for mail attempts without retaining message
 * content. Logging is intentionally best-effort: an unavailable log table or
 * a transient database error must never prevent an account flow from working.
 */
function pw_mail_log_event($direction, $status, $fields = []) {
    $direction = $direction === 'inbound' ? 'inbound' : 'outbound';
    $status = substr(trim((string)$status), 0, 32);
    if ($status === '') $status = 'unknown';

    $clip = function ($value, $length) {
        return substr(trim((string)$value), 0, $length);
    };

    try {
        $stmt = pw_db()->prepare(
            'INSERT INTO mail_delivery_logs
                (direction, status, template_key, sender_email, recipient_email, subject, provider_message_id, detail, body_bytes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $direction,
            $status,
            $clip($fields['template_key'] ?? '', 40) ?: null,
            $clip($fields['sender_email'] ?? '', 255) ?: null,
            $clip($fields['recipient_email'] ?? '', 255) ?: null,
            $clip($fields['subject'] ?? '', 255) ?: null,
            $clip($fields['provider_message_id'] ?? '', 255) ?: null,
            $clip($fields['detail'] ?? '', 255) ?: null,
            max(0, (int)($fields['body_bytes'] ?? 0)),
        ]);

        // Keep this troubleshooting trail bounded without making every send
        // pay for a cleanup query. One in one hundred events prunes old rows.
        if (random_int(1, 100) === 1) {
            pw_db()->exec('DELETE FROM mail_delivery_logs WHERE created_at < UTC_TIMESTAMP() - INTERVAL 90 DAY');
        }
    } catch (Throwable $e) {
        // The log migration may not be installed yet, or logging may be
        // temporarily unavailable. Mail itself remains non-blocking by design.
    }
}

function pw_mail_log_outbound($status, $key, $recipientEmail, $settings, $subject = '', $detail = '', $bodyBytes = 0, $providerMessageId = null) {
    pw_mail_log_event('outbound', $status, [
        'template_key' => $key,
        'sender_email' => $settings['from_email'] ?? '',
        'recipient_email' => $recipientEmail,
        'subject' => $subject,
        'detail' => $detail,
        'body_bytes' => $bodyBytes,
        'provider_message_id' => $providerMessageId,
    ]);
}

/**
 * Sends one message through the MailerSend HTTP API. Used automatically by
 * pw_send_template_email() whenever MAILERSEND_API_TOKEN is configured (see
 * api/config.sample.php). A short connect/total timeout keeps a MailerSend
 * outage from ever hanging the sign-in/registration/ban flow that triggered
 * the send -- the caller always gets a result object back, never a fatal.
 */
function pw_mail_send_via_mailersend($recipientEmail, $subject, $html, $text, $settings) {
    $fromName = str_replace(["\r", "\n", '"'], '', $settings['from_name']);
    $payload = [
        'from' => array_filter([
            'email' => $settings['from_email'],
            'name' => $fromName !== '' ? $fromName : null,
        ]),
        'to' => [['email' => $recipientEmail]],
        'subject' => $subject,
        'html' => $html,
        'text' => $text,
    ];
    $replyTo = filter_var($settings['reply_to'], FILTER_VALIDATE_EMAIL) ? $settings['reply_to'] : $settings['from_email'];
    if (filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $payload['reply_to'] = ['email' => $replyTo];
    }

    $ch = curl_init('https://api.mailersend.com/v1/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . MAILERSEND_API_TOKEN,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    if ($raw === false) {
        curl_close($ch);
        return ['sent' => false, 'provider_message_id' => null, 'detail' => 'MailerSend request failed: ' . $curlError];
    }

    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headerText = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    $messageId = null;
    if (preg_match('/^x-message-id:\s*(.+)$/mi', $headerText, $m)) {
        $messageId = trim($m[1]);
    }

    if ($status >= 200 && $status < 300) {
        return ['sent' => true, 'provider_message_id' => $messageId, 'detail' => 'Accepted by the MailerSend API (HTTP ' . $status . ').'];
    }

    $decoded = json_decode($body, true);
    $message = is_array($decoded) && isset($decoded['message']) ? $decoded['message'] : ('HTTP ' . $status);
    return ['sent' => false, 'provider_message_id' => null, 'detail' => 'MailerSend rejected the message: ' . $message];
}

function pw_send_template_email($key, $recipientEmail, $variables = [], $options = []) {
    $recipientEmail = trim((string)$recipientEmail);
    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        return ['sent' => false, 'reason' => 'invalid_recipient'];
    }
    $settings = pw_mail_settings();
    if (!$settings['enabled']) {
        pw_mail_log_outbound('skipped', $key, $recipientEmail, $settings, '', 'Transactional delivery is disabled.');
        return ['sent' => false, 'reason' => 'disabled'];
    }
    if (!$settings['transport_available']) {
        pw_mail_log_outbound('skipped', $key, $recipientEmail, $settings, '', 'No mail transport is configured.');
        return ['sent' => false, 'reason' => 'transport_unavailable'];
    }
    if (!filter_var($settings['from_email'], FILTER_VALIDATE_EMAIL)) {
        pw_mail_log_outbound('skipped', $key, $recipientEmail, $settings, '', 'A valid sender address is not configured.');
        return ['sent' => false, 'reason' => 'sender_not_configured'];
    }

    $template = pw_mail_template($key);
    $allowPausedTemplate = !empty($options['allow_paused_template']);
    if (!$template || (!$template['is_enabled'] && !$allowPausedTemplate)) {
        pw_mail_log_outbound('skipped', $key, $recipientEmail, $settings, '', 'The selected template is unavailable or paused.');
        return ['sent' => false, 'reason' => 'template_unavailable'];
    }

    $subject = str_replace(["\r", "\n"], '', pw_mail_render($template['subject'], $variables));
    $html = pw_mail_render($template['html_body'], $variables, true);
    $text = pw_mail_render($template['text_body'], $variables);

    if (pw_mail_uses_mailersend()) {
        $result = pw_mail_send_via_mailersend($recipientEmail, $subject, $html, $text, $settings);
        pw_mail_log_outbound(
            $result['sent'] ? 'accepted' : 'failed',
            $key, $recipientEmail, $settings, $subject, $result['detail'],
            strlen($html) + strlen($text), $result['provider_message_id']
        );
        return ['sent' => $result['sent'], 'reason' => $result['sent'] ? 'sent' : 'transport_rejected'];
    }

    $boundary = 'pw-' . bin2hex(random_bytes(12));
    $fromName = str_replace(["\r", "\n", '"'], '', $settings['from_name']);
    $headers = [
        'MIME-Version: 1.0',
        'From: ' . ($fromName !== '' ? '"' . $fromName . '" ' : '') . '<' . $settings['from_email'] . '>',
        'Reply-To: ' . (filter_var($settings['reply_to'], FILTER_VALIDATE_EMAIL) ? $settings['reply_to'] : $settings['from_email']),
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];
    $body = '--' . $boundary . "\r\n" .
        "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $text . "\r\n" .
        '--' . $boundary . "\r\n" .
        "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $html . "\r\n" .
        '--' . $boundary . '--';
    $sent = @mail($recipientEmail, $subject, $body, implode("\r\n", $headers));
    pw_mail_log_outbound(
        $sent ? 'accepted' : 'failed',
        $key,
        $recipientEmail,
        $settings,
        $subject,
        $sent ? 'Accepted by the PHP mail transport; inbox delivery is not yet confirmed.' : 'The PHP mail transport rejected the message.',
        strlen($body)
    );
    return ['sent' => (bool)$sent, 'reason' => $sent ? 'sent' : 'transport_rejected'];
}

function pw_send_campaign_email($recipientEmail, $subject, $html, $text) {
    $settings = pw_mail_settings();
    if (!$settings['enabled'] || !$settings['transport_available'] || !filter_var($settings['from_email'], FILTER_VALIDATE_EMAIL)) return ['sent' => false, 'reason' => 'not_ready'];
    if (pw_mail_uses_mailersend()) $result = pw_mail_send_via_mailersend($recipientEmail, $subject, $html, $text, $settings);
    else {
        $headers = ['MIME-Version: 1.0','From: "'.str_replace('"','',$settings['from_name']).'" <'.$settings['from_email'].'>','Reply-To: '.(filter_var($settings['reply_to'],FILTER_VALIDATE_EMAIL)?$settings['reply_to']:$settings['from_email']),'Content-Type: text/html; charset=UTF-8'];
        $result = ['sent' => @mail($recipientEmail, $subject, $html, implode("\r\n", $headers)), 'provider_message_id' => null, 'detail' => 'Submitted through PHP mail.'];
    }
    pw_mail_log_outbound($result['sent']?'accepted':'failed','campaign',$recipientEmail,$settings,$subject,$result['detail'],strlen($html)+strlen($text),$result['provider_message_id']??null);
    return ['sent'=>(bool)$result['sent'],'reason'=>$result['sent']?'sent':'transport_rejected'];
}
