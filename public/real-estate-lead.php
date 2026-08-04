<?php
// Real-estate landing page lead capture - no email field, uses company/challenge/description

// Honeypot check
if (!empty($_POST['_hp'])) {
    header('Location: ' . ($_POST['_redirect'] ?? 'thanks.html'));
    exit;
}

$env = [];
// Google reCAPTCHA Verification
$recaptchaSecret = '6Ld5s24tAAAAAH4MDkioXeo7QcWR5mE-3oYyBUUs';
$recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';

if (empty($recaptchaResponse)) {
    die('Please complete the CAPTCHA.');
}

$verifyUrl = 'https://www.google.com/recaptcha/api/siteverify';
$verifyData = [
    'secret' => $recaptchaSecret,
    'response' => $recaptchaResponse,
    'remoteip' => $_SERVER['REMOTE_ADDR']
];

$options = [
    'http' => [
        'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
        'method'  => 'POST',
        'content' => http_build_query($verifyData)
    ]
];
$context  = stream_context_create($options);
$result = file_get_contents($verifyUrl, false, $context);
$responseData = json_decode($result, true);

if (!$responseData['success']) {
    die('CAPTCHA verification failed. Please try again.');
}
if (is_file(__DIR__ . '/crm/.env')) {
    foreach (file(__DIR__ . '/crm/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) $env[trim($parts[0])] = trim(trim($parts[1]), "\"'");
    }
}

$dbPath = $env['DB_PATH'] ?? 'crm/storage/database.sqlite';
if (!str_starts_with($dbPath, '/')) $dbPath = __DIR__ . '/crm/' . $dbPath;

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$apiKey = $_POST['api_key'] ?? '';
$name = trim($_POST['name'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$company = trim($_POST['company'] ?? '');
$challenge = trim($_POST['challenge'] ?? '');
$description = trim($_POST['description'] ?? '');
$redirect = $_POST['_redirect'] ?? 'thanks.html';

// Validate API key
$stmt = $pdo->prepare('SELECT id, name, success_message FROM sites WHERE api_key = ? AND active = 1');
$stmt->execute([$apiKey]);
$site = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$site) {
    die('Invalid form key.');
}

// Validate required fields
if (empty($name) || empty($phone) || empty($company) || empty($challenge)) {
    die('Please fill in all required fields.');
}

// Compose the message from the real-estate specific fields
$challengeLabels = [
    'seo' => 'Not ranking on Google for our project',
    'leads' => 'Too many junk leads from portals',
    'new' => 'No online presence for a new project launch',
    'pr' => 'Need social media and PR support',
    'other' => 'Something else',
];
$challengeLabel = $challengeLabels[$challenge] ?? $challenge;

$message = "Company / Project: {$company}\n"
    . "Biggest challenge right now: {$challengeLabel}\n"
    . ($description !== '' ? "Requirements:\n{$description}" : '');$extra = [
    'company' => $company,
    'challenge' => $challengeLabel,
    'description' => $description,
];

// Insert lead
$stmt = $pdo->prepare('INSERT INTO leads (site_id, name, email, phone, message, extra_json, ip_address, user_agent, referrer, status, is_spam, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, datetime(\'now\'))');
$stmt->execute([
    $site['id'],
    $name,
    '',
    $phone,
    $message,
    json_encode($extra),
    $_SERVER['REMOTE_ADDR'] ?? '',
    $_SERVER['HTTP_USER_AGENT'] ?? '',
    $_SERVER['HTTP_REFERER'] ?? '',
    'new'
]);

$leadId = $pdo->lastInsertId();

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
        $smtpCfg['reply_to'] = $phone;
        $subject = 'New lead from ' . ($site['name'] ?? 'ChimpzLab') . ': ' . $name;
        \App\Mailer::send($smtpCfg, $to, $subject, leadEmailHtml($site['name'] ?? 'ChimpzLab', [
            'name' => $name,
            'email' => '',
            'phone' => $phone,
            'message' => $description,
        ], $logo, [
            'Company / Project' => $company,
            'Biggest challenge right now' => $challengeLabel,
        ]), $inlineLogo);
    }
} catch (Throwable $e) {
    error_log('Real-estate lead email notification failed: ' . $e->getMessage());
}

// Redirect
header('Location: ' . $redirect);
exit;
