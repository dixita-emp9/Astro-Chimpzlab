<?php
// Direct lead capture - no FlightPHP, no .htaccess needed.
// All configuration (reCAPTCHA secrets, SMTP, DB_PATH, rate limits) comes
// from crm/.env at request time. No secrets are hardcoded in this file.

declare(strict_types=1);

require_once __DIR__ . '/capture-common.php';

$env = capture_env();

// Honeypot check — bots that fill the invisible field get redirected away
// without any further work.
if (!empty($_POST['_hp'])) {
    header('Location: ' . capture_safe_redirect((string) ($_POST['_redirect'] ?? '')));
    exit;
}

// Google reCAPTCHA Verification (secret from crm/.env, test key on localhost)
$recaptchaSecret = capture_recaptcha_secret($env);
if ($recaptchaSecret === null) {
    http_response_code(500);
    die('The form is not configured for this host. Please contact the site owner.');
}
if (!capture_verify_recaptcha($recaptchaSecret, (string) ($_POST['g-recaptcha-response'] ?? ''))) {
    die('CAPTCHA verification failed. Please try again.');
}

$dbPath = $env['DB_PATH'] ?? 'crm/storage/database.sqlite';
if (!str_starts_with($dbPath, '/')) {
    $dbPath = __DIR__ . '/crm/' . $dbPath;
}

// Rate limit (per-IP) BEFORE doing any expensive work.
if (!capture_within_rate_limit($env, $dbPath, capture_client_ip($env))) {
    http_response_code(429);
    die('Too many submissions from your network. Please try again later.');
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$apiKey = capture_text($_POST['api_key'] ?? '', 64);
$name = capture_text($_POST['name'] ?? '', 200);
$email = capture_text($_POST['email'] ?? '', 254);
$phone = capture_text($_POST['phone'] ?? '', 30);
$message = capture_text($_POST['message'] ?? '', 5000);
$redirect = capture_safe_redirect((string) ($_POST['_redirect'] ?? ''));

// Validate API key
$stmt = $pdo->prepare('SELECT id, name, success_message FROM sites WHERE api_key = ? AND active = 1');
$stmt->execute([$apiKey]);
$site = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$site) {
    http_response_code(403);
    die('Invalid form key.');
}

// Validate required fields
if ($name === '' || $email === '' || $phone === '' || $message === '') {
    die('All fields are required.');
}
if (!capture_valid_email($email)) {
    die('Please enter a valid email address.');
}

// Insert lead
$stmt = $pdo->prepare('INSERT INTO leads (site_id, name, email, phone, message, ip_address, user_agent, referrer, status, is_spam, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, datetime(\'now\'))');
$stmt->execute([
    $site['id'],
    $name,
    $email,
    $phone,
    $message,
    $_SERVER['REMOTE_ADDR'] ?? '',
    substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
    substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 500),
    'new'
]);

$leadId = (int) $pdo->lastInsertId();

// Log activity
$stmt = $pdo->prepare('INSERT INTO lead_activity (lead_id, type, body) VALUES (?, \'created\', ?)');
$stmt->execute([$leadId, 'Lead captured from ' . $site['name']]);

// Send email notification via SMTP
try {
    require_once __DIR__ . '/crm/src/Mailer.php';
    require_once __DIR__ . '/crm/src/email-templates.php';

    $smtpCfg = [
        'host' => $env['SMTP_HOST'] ?? 'smtp.zoho.com',
        'port' => (int) ($env['SMTP_PORT'] ?? 465),
        'secure' => $env['SMTP_SECURE'] ?? 'ssl',
        'username' => $env['SMTP_USERNAME'] ?? '',
        'password' => $env['SMTP_PASSWORD'] ?? '',
        'from_email' => $env['SMTP_FROM'] ?? '',
        'from_name' => $env['SMTP_FROM_NAME'] ?? 'ChimpzLab',
    ];

    // Embed the logo inline (cid:chimpzlab-logo) so it always displays
    $logoFile = __DIR__ . '/asset/chimpzlab-white.png';
    $inlineLogo = [];
    $logo = 'https://www.chimpzlab.com/asset/chimpzlab-white.png';
    if (is_file($logoFile)) {
        $logo = 'cid:chimpzlab-logo';
        $inlineLogo[] = ['file' => $logoFile, 'cid' => 'chimpzlab-logo', 'mime' => 'image/png'];
    }

    $to = $env['SMTP_TO'] ?? '';
    if ($to !== '') {
        $smtpCfg['reply_to'] = $email;
        $subject = 'New lead from ' . ($site['name'] ?? 'ChimpzLab') . ': ' . $name;
        \App\Mailer::send($smtpCfg, $to, $subject, leadEmailHtml($site['name'] ?? 'ChimpzLab', [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'message' => $message,
        ], $logo), $inlineLogo);
    }

    // Thank-you email to the submitter
    if ($email !== '') {
        $smtpCfg['reply_to'] = $env['SMTP_FROM'] ?? '';
        \App\Mailer::send(
            $smtpCfg,
            $email,
            'Thank you for contacting ' . ($site['name'] ?? 'ChimpzLab'),
            thankYouEmailHtml($site['name'] ?? 'ChimpzLab', $name, $logo),
            $inlineLogo
        );
    }
} catch (Throwable $e) {
    error_log('Lead email notification failed: ' . $e->getMessage());
}

// Redirect
header('Location: ' . $redirect);
exit;
