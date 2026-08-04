<?php
// CRM entry point for subdirectory hosting - call as: /chimpzlab-2/crm.php?route=/login
// Bypasses the need for .htaccess rewriting

$route = $_GET['route'] ?? '/';
$_SERVER['REQUEST_URI'] = $route;
$_SERVER['SCRIPT_NAME'] = '/crm/public/index.php';

require __DIR__ . '/crm/public/index.php';
