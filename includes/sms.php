<?php
/**
 * SMS integration: Twilio (international & local) or Semaphore (PH local),
 * chosen with the CONDO_SMS_PROVIDER environment setting so the board can
 * switch providers without touching code. Used for phone verification
 * codes, due-date payment reminders, and parking/approval notices.
 *
 * Both providers are called directly over HTTPS with cURL instead of
 * through their SDKs. This project has no package-manager network access
 * in this build environment, and the existing codebase already favors
 * small, dependency-free helpers (see sendMail() in config.php, which
 * only pulls in PHPMailer because SMTP genuinely needs it).
 */

const SMS_PROVIDER_DEFAULT = 'semaphore'; // 'semaphore' or 'twilio'

const TWILIO_ACCOUNT_SID   = 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'; // sandbox: from console.twilio.com
const TWILIO_AUTH_TOKEN    = 'your_twilio_auth_token';
const TWILIO_FROM_NUMBER   = '+15005550006'; // Twilio's magic test number, or your purchased number

const SEMAPHORE_API_KEY      = '';
const SEMAPHORE_SENDER_NAME  = 'The Celandine Homes'; // must match a sender name approved on your Semaphore account

function smsProvider(): string {
    $value = strtolower(appSetting('CONDO_SMS_PROVIDER', SMS_PROVIDER_DEFAULT));
    return in_array($value, ['twilio', 'semaphore'], true) ? $value : SMS_PROVIDER_DEFAULT;
}

/**
 * Normalizes common Philippine mobile formats to digits-with-country-code,
 * e.g. "0917 123 4567" or "+63-917-123-4567" both become "639171234567".
 * Numbers that don't match a recognizable PH pattern are returned as
 * digits-only so international Twilio numbers still pass through.
 */
function normalizePhDigits(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
        return '63' . substr($digits, 1);
    }
    if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
        return '63' . $digits;
    }
    return $digits;
}

/** cURL POST helper shared by both providers. Returns [httpCode, body]. */
function smsHttpPost(string $url, array $fields, array $headers = [], ?string $basicAuth = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers),
    ]);
    if ($basicAuth !== null) {
        curl_setopt($ch, CURLOPT_USERPWD, $basicAuth);
    }
    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return [0, json_encode(['error' => $curlError ?: 'Connection failed'])];
    }
    return [$httpCode, $body];
}

function sendSmsViaTwilio(string $toDigits, string $message): array {
    $sid = appSetting('CONDO_TWILIO_SID', TWILIO_ACCOUNT_SID);
    $token = appSetting('CONDO_TWILIO_AUTH_TOKEN', TWILIO_AUTH_TOKEN);
    $from = appSetting('CONDO_TWILIO_FROM', TWILIO_FROM_NUMBER);

    $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";
    [$httpCode, $body] = smsHttpPost($url, [
        'To'   => '+' . $toDigits,
        'From' => $from,
        'Body' => $message,
    ], [], $sid . ':' . $token);

    $decoded = json_decode($body, true);
    $success = $httpCode >= 200 && $httpCode < 300;

    return [
        'success' => $success,
        'provider' => 'twilio',
        'error' => $success ? null : ($decoded['message'] ?? 'Twilio request failed (HTTP ' . $httpCode . ')'),
        'reference' => $decoded['sid'] ?? null,
        'raw' => $body,
    ];
}

function sendSmsViaSemaphore(string $toDigits, string $message): array {
    $apiKey = appSetting('CONDO_SEMAPHORE_API_KEY', SEMAPHORE_API_KEY);
    $senderName = appSetting('CONDO_SEMAPHORE_SENDER_NAME', SEMAPHORE_SENDER_NAME);

    if ($apiKey === '') {
        return [
            'success' => false,
            'provider' => 'semaphore',
            'error' => 'Semaphore API key is not configured.',
            'reference' => null,
            'raw' => null,
        ];
    }

    // Semaphore accepts the local 0-prefixed format for PH numbers.
    $localNumber = str_starts_with($toDigits, '63') ? '0' . substr($toDigits, 2) : $toDigits;

    $fields = [
        'apikey'  => $apiKey,
        'number'  => $localNumber,
        'message' => $message,
    ];
    if ($senderName !== '') {
        $fields['sendername'] = $senderName;
    }

    [$httpCode, $body] = smsHttpPost('https://api.semaphore.co/api/v4/messages', $fields);
    $decoded = json_decode($body, true);
    // Semaphore returns an array of message objects (one per recipient) on success.
    $first = is_array($decoded) && isset($decoded[0]) ? $decoded[0] : $decoded;
    $success = $httpCode >= 200 && $httpCode < 300 && !isset($decoded['error']) && !empty($first['message_id'] ?? null);

    return [
        'success' => $success,
        'provider' => 'semaphore',
        'error' => $success ? null : ($decoded['error'] ?? $decoded['message'] ?? 'Semaphore request failed (HTTP ' . $httpCode . ')'),
        'reference' => $first['message_id'] ?? null,
        'raw' => $body,
    ];
}

/**
 * Sends an SMS through whichever provider CONDO_SMS_PROVIDER selects.
 * Always returns an array with at least 'success' and 'error' keys so
 * callers can decide whether to fall back to email or just log it.
 */
function sendSms(string $to, string $message): array {
    $digits = normalizePhDigits($to);
    if ($digits === '') {
        return ['success' => false, 'provider' => smsProvider(), 'error' => 'No phone number on file.', 'reference' => null, 'raw' => null];
    }

    $result = smsProvider() === 'twilio'
        ? sendSmsViaTwilio($digits, $message)
        : sendSmsViaSemaphore($digits, $message);

    trackEvent($result['success'] ? 'sms_sent' : 'sms_failed', $result['provider'] . ($result['success'] ? '' : ': ' . $result['error']));

    return $result;
}

/* ---------------------------------------------------------------------
 * Phone number verification (OTP over SMS)
 * ------------------------------------------------------------------- */

function ensurePhoneVerificationColumns(mysqli $connection): void {
    $checks = [
        'phone_verified'    => "ALTER TABLE users ADD COLUMN phone_verified TINYINT(1) NOT NULL DEFAULT 0",
        'phone_otp'         => "ALTER TABLE users ADD COLUMN phone_otp VARCHAR(10) DEFAULT NULL",
        'phone_otp_expires' => "ALTER TABLE users ADD COLUMN phone_otp_expires DATETIME DEFAULT NULL",
    ];
    foreach ($checks as $column => $alterSql) {
        $columnCheck = $connection->query("SHOW COLUMNS FROM users LIKE '{$column}'");
        if (!$columnCheck || $columnCheck->num_rows === 0) {
            $connection->query($alterSql);
        }
    }
}

/**
 * Generates a 6-digit code, stores it against the user (valid 10 minutes),
 * and texts it to their contact_number. Returns the sendSms() result so
 * the caller can show a specific error if delivery failed.
 */
function generateAndSendPhoneOtp(int $userId): array {
    $connection = connectDb();
    ensurePhoneVerificationColumns($connection);

    $stmt = $connection->prepare('SELECT contact_number FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user || trim($user['contact_number']) === '') {
        return ['success' => false, 'provider' => smsProvider(), 'error' => 'No contact number on file.', 'reference' => null, 'raw' => null];
    }

    $code = generateVerificationCode();
    $expiresAt = (new DateTime())->modify('+10 minutes')->format('Y-m-d H:i:s');

    $update = $connection->prepare('UPDATE users SET phone_otp = ?, phone_otp_expires = ? WHERE id = ?');
    $update->bind_param('ssi', $code, $expiresAt, $userId);
    $update->execute();

    $message = "Celandine Residences: your phone verification code is {$code}. It expires in 10 minutes.";
    return sendSms($user['contact_number'], $message);
}

/**
 * Checks a submitted code against the stored OTP for a user. On success,
 * marks the phone as verified and clears the code so it can't be reused.
 */
function verifyPhoneOtp(int $userId, string $code): bool {
    $connection = connectDb();
    ensurePhoneVerificationColumns($connection);

    $stmt = $connection->prepare('SELECT phone_otp, phone_otp_expires FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user || $user['phone_otp'] === null || $code === '') {
        return false;
    }
    if (!hash_equals((string)$user['phone_otp'], $code)) {
        return false;
    }
    if ($user['phone_otp_expires'] === null || new DateTime($user['phone_otp_expires']) < new DateTime()) {
        return false;
    }

    $update = $connection->prepare('UPDATE users SET phone_verified = 1, phone_otp = NULL, phone_otp_expires = NULL WHERE id = ?');
    $update->bind_param('i', $userId);
    $update->execute();
    trackEvent('phone_verified', '', $userId);

    return true;
}
