<?php
/**
 * SMS helper for the Laptop Tracking System.
 *
 * Supports two providers, configured in the `settings` table or env vars:
 *   - twilio          (Twilio REST API)
 *   - africastalking  (Africa's Talking REST API — popular in Uganda/Kenya)
 *
 * Settings keys (table: settings { setting_key, setting_value }):
 *   sms_provider          twilio | africastalking | disabled
 *   sms_from              Sender ID / Twilio phone number
 *   sms_account_sid       Twilio Account SID  (twilio only)
 *   sms_auth_token        Twilio Auth Token   (twilio only)
 *   sms_at_username       Africa's Talking username (at only)
 *   sms_at_api_key        Africa's Talking API key  (at only)
 *
 * Usage:
 *   require_once __DIR__ . '/sms.php';
 *   $r = lts_send_sms($conn, '+256700000000', 'Hello from school');
 *   if ($r['success']) { ... } else { error_log($r['message']); }
 */

if (!function_exists('lts_ensure_sms_tables')) {
    function lts_ensure_sms_tables(mysqli $conn) {
        $conn->query("CREATE TABLE IF NOT EXISTS settings (
            setting_key   VARCHAR(64) PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $conn->query("CREATE TABLE IF NOT EXISTS notifications_log (
            notif_id    INT AUTO_INCREMENT PRIMARY KEY,
            recipient   VARCHAR(32) NOT NULL,
            message     TEXT NOT NULL,
            channel     VARCHAR(24) NOT NULL DEFAULT 'sms',
            provider    VARCHAR(32) NULL,
            status      VARCHAR(24) NOT NULL DEFAULT 'pending',
            response    TEXT NULL,
            laptop_id   INT NULL,
            student_id  INT NULL,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (recipient),
            INDEX (status),
            INDEX (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

if (!function_exists('lts_get_setting')) {
    function lts_get_setting(mysqli $conn, string $key, ?string $default = null): ?string {
        // Prefer environment variable if set (e.g. SMS_PROVIDER, SMS_FROM, etc.)
        $envKey = strtoupper($key);
        $env = getenv($envKey);
        if ($env !== false && $env !== '') return $env;

        lts_ensure_sms_tables($conn);
        $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        if (!$stmt) return $default;
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $row = $res->fetch_assoc()) {
            $stmt->close();
            return $row['setting_value'];
        }
        $stmt->close();
        return $default;
    }
}

if (!function_exists('lts_set_setting')) {
    function lts_set_setting(mysqli $conn, string $key, string $value): bool {
        lts_ensure_sms_tables($conn);
        $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        if (!$stmt) return false;
        $stmt->bind_param('ss', $key, $value);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('lts_normalize_phone')) {
    function lts_normalize_phone(string $phone): string {
        $phone = trim($phone);
        // Strip everything except digits and leading +
        $phone = preg_replace('/[^\d+]/', '', $phone);
        // Uganda fallback: 07xx → +2567xx (only if no country prefix)
        if (preg_match('/^0\d{9}$/', $phone)) {
            $phone = '+256' . substr($phone, 1);
        }
        // Add leading + if missing and length looks international
        if ($phone && $phone[0] !== '+' && strlen($phone) >= 11) {
            $phone = '+' . $phone;
        }
        return $phone;
    }
}

if (!function_exists('lts_log_notification')) {
    function lts_log_notification(mysqli $conn, string $to, string $msg, string $provider, string $status, string $response = '', ?int $laptop_id = null, ?int $student_id = null): void {
        lts_ensure_sms_tables($conn);
        $stmt = $conn->prepare("INSERT INTO notifications_log (recipient, message, channel, provider, status, response, laptop_id, student_id)
                                VALUES (?, ?, 'sms', ?, ?, ?, ?, ?)");
        if (!$stmt) return;
        $stmt->bind_param('sssssii', $to, $msg, $provider, $status, $response, $laptop_id, $student_id);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('lts_send_sms')) {
    function lts_send_sms(mysqli $conn, string $to, string $message, ?int $laptop_id = null, ?int $student_id = null): array {
        $provider = strtolower(lts_get_setting($conn, 'sms_provider', 'disabled') ?? 'disabled');
        $from     = lts_get_setting($conn, 'sms_from', 'School') ?? 'School';
        $to       = lts_normalize_phone($to);

        if ($to === '' || strlen($to) < 8) {
            return ['success' => false, 'message' => 'Invalid recipient phone number'];
        }
        if ($provider === 'disabled' || $provider === '') {
            lts_log_notification($conn, $to, $message, 'disabled', 'skipped', 'SMS disabled in settings', $laptop_id, $student_id);
            return ['success' => false, 'message' => 'SMS provider not configured. Configure it in SMS Settings.'];
        }

        if ($provider === 'twilio') {
            $sid   = lts_get_setting($conn, 'sms_account_sid');
            $token = lts_get_setting($conn, 'sms_auth_token');
            if (!$sid || !$token) {
                lts_log_notification($conn, $to, $message, 'twilio', 'failed', 'Missing Twilio credentials', $laptop_id, $student_id);
                return ['success' => false, 'message' => 'Twilio credentials missing'];
            }
            $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";
            $post = http_build_query(['From' => $from, 'To' => $to, 'Body' => $message]);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD        => "$sid:$token",
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $post,
                CURLOPT_TIMEOUT        => 12,
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            $ok = ($code >= 200 && $code < 300);
            lts_log_notification($conn, $to, $message, 'twilio', $ok ? 'sent' : 'failed', $err ?: substr((string)$resp, 0, 500), $laptop_id, $student_id);
            return ['success' => $ok, 'message' => $ok ? 'Sent' : ('Twilio error: ' . ($err ?: $resp))];
        }

        if ($provider === 'africastalking') {
            $username = lts_get_setting($conn, 'sms_at_username');
            $apiKey   = lts_get_setting($conn, 'sms_at_api_key');
            if (!$username || !$apiKey) {
                lts_log_notification($conn, $to, $message, 'africastalking', 'failed', 'Missing AT credentials', $laptop_id, $student_id);
                return ['success' => false, 'message' => "Africa's Talking credentials missing"];
            }
            $url = ($username === 'sandbox')
                ? 'https://api.sandbox.africastalking.com/version1/messaging'
                : 'https://api.africastalking.com/version1/messaging';
            $post = http_build_query(['username' => $username, 'to' => $to, 'message' => $message, 'from' => $from]);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    'apiKey: ' . $apiKey,
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: application/json',
                ],
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $post,
                CURLOPT_TIMEOUT        => 12,
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            $ok = ($code >= 200 && $code < 300);
            lts_log_notification($conn, $to, $message, 'africastalking', $ok ? 'sent' : 'failed', $err ?: substr((string)$resp, 0, 500), $laptop_id, $student_id);
            return ['success' => $ok, 'message' => $ok ? 'Sent' : ("AT error: " . ($err ?: $resp))];
        }

        lts_log_notification($conn, $to, $message, $provider, 'failed', 'Unknown provider', $laptop_id, $student_id);
        return ['success' => false, 'message' => 'Unknown SMS provider: ' . htmlspecialchars($provider)];
    }
}
