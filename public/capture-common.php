<?php

// Shared helpers for the standalone PHP lead endpoints (lead-capture.php and
// real-estate-lead.php). Removes the duplicated reCAPTCHA / env / redirect /
// rate-limit logic and keeps ALL server-side secrets (reCAPTCHA secret keys,
// SMTP credentials, APP_KEY) out of web-served PHP source — they are read from
// crm/.env at request time, exactly like the MicroCRM API does.

declare(strict_types=1);

/**
 * Loads crm/.env into an array. Returns [] when the file is missing.
 */
function capture_env(): array
{
    static $env = null;
    if ($env !== null) {
        return $env;
    }
    $env = [];
    $path = __DIR__ . '/crm/.env';
    if (!is_file($path)) {
        return $env;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $env[trim($parts[0])] = trim(trim($parts[1]), "\"'");
        }
    }
    return $env;
}

/**
 * Resolves the reCAPTCHA secret key for this request.
 *
 * Security: the production secret is NEVER hardcoded in source — it must be
 * configured as RECAPTCHA_SECRET_KEY in crm/.env. Google's always-pass TEST
 * key is only used on localhost for local development. On any other host a
 * missing secret fails closed (returns null), so the form simply rejects
 * submissions instead of silently accepting everything.
 */
function capture_recaptcha_secret(array $env): ?string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $host = preg_replace('/:\d+$/', '', $host) ?? $host; // strip port
    $isLocalhost = in_array($host, ['localhost', '127.0.0.1'], true);

    if ($isLocalhost) {
        return $env['RECAPTCHA_SECRET_KEY_TEST'] ?? '6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe';
    }
    $secret = $env['RECAPTCHA_SECRET_KEY'] ?? '';
    return $secret !== '' ? $secret : null;
}

/**
 * Verifies a reCAPTCHA v2/v3 token server-side. Returns true on success.
 */
function capture_verify_recaptcha(string $secret, string $response): bool
{
    if ($response === '') {
        return false;
    }
    $verifyData = [
        'secret' => $secret,
        'response' => $response,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ];
    $context = stream_context_create([
        'http' => [
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'timeout' => 10,
            'content' => http_build_query($verifyData),
        ],
    ]);
    $result = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $context);
    if ($result === false) {
        return false;
    }
    $data = json_decode($result, true);
    return is_array($data) && !empty($data['success']);
}

/**
 * Resolves the client IP. When the CRM is configured with TRUST_PROXY=1 the
 * first X-Forwarded-For value is used (only valid behind a real proxy).
 */
function capture_client_ip(array $env): string
{
    $trustProxy = ($env['TRUST_PROXY'] ?? '0') === '1';
    if ($trustProxy && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($parts[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Enforces the CRM's per-IP sliding-window rate limit before a submission is
 * processed. Returns true when the request is allowed, false to reject (429).
 */
function capture_within_rate_limit(array $env, string $dbPath, string $ip): bool
{
    try {
        require_once __DIR__ . '/crm/vendor/autoload.php';
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode = WAL');

        $max = (int) ($env['RATE_LIMIT_MAX'] ?? 5);
        $window = (int) ($env['RATE_LIMIT_WINDOW'] ?? 600);
        $daily = (int) ($env['RATE_LIMIT_DAILY_MAX'] ?? 20);

        \App\SpamFilter::pruneRateLog($pdo);
        $ok = \App\SpamFilter::withinRateLimit($pdo, $ip, $max, $window)
            && \App\SpamFilter::withinDailyLimit($pdo, $ip, $daily);
        \App\SpamFilter::logRequest($pdo, $ip, 0);
        return $ok;
    } catch (Throwable $e) {
        // Never let a rate-limit failure break the form; the reCAPTCHA gate
        // still applies.
        return true;
    }
}

/**
 * Only allow same-origin, relative redirect targets. Blocks protocol-relative
 * (//evil.com), scheme (https://evil.com) and backslash (\) tricks.
 */
function capture_safe_redirect(string $url, string $fallback = '/thanks'): string
{
    $url = trim($url);
    if (
        $url === ''
        || !str_starts_with($url, '/')
        || str_starts_with($url, '//')
        || str_contains($url, '\\')
        || str_contains($url, '://')
    ) {
        return $fallback;
    }
    return $url;
}

/**
 * Trims and caps a text field. Returns '' for non-string input.
 */
function capture_text(mixed $value, int $max): string
{
    if (!is_scalar($value)) {
        return '';
    }
    $value = trim((string) $value);
    return mb_substr($value, 0, $max);
}

/**
 * Validates an email address (when present). Returns false for invalid.
 */
function capture_valid_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}
