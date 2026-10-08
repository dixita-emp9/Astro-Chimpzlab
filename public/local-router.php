<?php
// Router for local testing via `npm run serve:php` (PHP built-in server does
// not read .htaccess, so the /sitemap.xml -> /sitemap.php rewrite is mapped
// here to mirror live behavior). Not used on Apache/LiteSpeed hosts.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/sitemap.xml' || $path === '/sitemap.xml/') {
    require __DIR__ . '/sitemap.php';
    exit;
}
return false;
