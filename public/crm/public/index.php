<?php

declare(strict_types=1);

// Hardened session cookie: HttpOnly, SameSite=Lax, Secure over HTTPS.
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();
date_default_timezone_set('Asia/Kolkata');

// Basic security headers so the CRM is never sniffed or framed even if the
// hosting Apache config is more permissive than the site root's .htaccess.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');

// Fix subdirectory routing - strip /{folder}/crm/public/ from REQUEST_URI
$scriptDir = dirname($_SERVER['SCRIPT_NAME']); // e.g., /chimpzlab-2/crm/public
$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($scriptDir !== '/' && $scriptDir !== '.' && strpos($requestPath, $scriptDir) === 0) {
    $newPath = substr($requestPath, strlen($scriptDir));
    $_SERVER['REQUEST_URI'] = $newPath ?: '/';
}

// Auto-fix hardcoded paths in HTML output (e.g., /leads → /chimpzlab-2/crm/public/leads)
$baseUrl = rtrim($scriptDir, '/');
if ($baseUrl && $baseUrl !== '.') {
    ob_start(function ($html) use ($baseUrl) {
        // Only fix paths that DON'T already have the prefix
        $quotedBase = preg_quote($baseUrl, '/');
        return preg_replace(
            '/(<(?:a|form|link|script)[^>]*\s(?:href|action|src)\s*=\s*["\'])(?!' . $quotedBase . ')\//i',
            '$1' . $baseUrl . '/',
            $html
        );
    });
}

require __DIR__ . '/../vendor/autoload.php';

bootstrap_database();

require __DIR__ . '/../src/routes/public.php';
require __DIR__ . '/../src/routes/admin.php';

Flight::start();
