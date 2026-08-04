<?php
// Strips subdirectory prefix so FlightPHP routes match correctly in subdirectories
$scriptName = dirname($_SERVER['SCRIPT_NAME']);
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);

// Strip script directory prefix (e.g., /chimpzlab-2)
if ($scriptName !== '/' && $scriptName !== '.' && strpos($path, $scriptName) === 0) {
    $path = substr($path, strlen($scriptName));
}

// Strip /crm/public prefix for admin routes
if (strpos($path, '/crm/public') === 0) {
    $path = substr($path, 11); // length of '/crm/public'
}

$_SERVER['REQUEST_URI'] = $path . (strpos($requestUri, '?') !== false ? '?' . parse_url($requestUri, PHP_URL_QUERY) : '');

require __DIR__ . '/crm/public/index.php';
