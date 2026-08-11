<?php
// Same-origin proxy for the ChimpzLab WordPress REST API.
//
// The static site reads blog content live from WordPress. Some hosts inject a
// strict Content-Security-Policy that blocks cross-origin fetches to
// chimpzlab.com. This script relays the WP REST API (and WP media images)
// through the site's own origin, so blog content keeps working without JSON
// snapshots and without any hosting-config changes.
//
// Usage:
//   /wp-proxy.php?endpoint=insights&per_page=100&_embed&orderby=date&order=desc
//   /wp-proxy.php?img=https%3A%2F%2Fchimpzlab.com%2F...%2Fphoto.jpg
//
// WordPress stays the single source of truth: this file only forwards requests
// and never stores or edits content.

$WP_BASE = 'https://chimpzlab.com/chimpzlab-old/wp-json/wp/v2';

// ---- Image proxy ---------------------------------------------------------
if (isset($_GET['img'])) {
    $img = filter_var($_GET['img'], FILTER_VALIDATE_URL);
    $host = parse_url($img, PHP_URL_HOST);
    if (!$img || ($host !== 'chimpzlab.com' && $host !== 'www.chimpzlab.com')) {
        http_response_code(404);
        exit;
    }
    $ext = strtolower(pathinfo(parse_url($img, PHP_URL_PATH), PATHINFO_EXTENSION));
    $mime = array(
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'gif'  => 'image/gif',
        'avif' => 'image/avif',
        'svg'  => 'image/svg+xml',
    );
    $type = isset($mime[$ext]) ? $mime[$ext] : 'application/octet-stream';
    $ctx = stream_context_create(array('http' => array(
        'timeout' => 20,
        'header'  => "Accept: $type\r\n",
    )));
    $data = @file_get_contents($img, false, $ctx);
    if ($data === false) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . $type);
    header('Cache-Control: public, max-age=86400');
    echo $data;
    exit;
}

// ---- JSON API proxy -------------------------------------------------------
$endpoint = isset($_GET['endpoint']) ? $_GET['endpoint'] : 'insights';
if (!preg_match('/^[a-z0-9_\-\/]+$/i', $endpoint)) {
    http_response_code(400);
    exit;
}
unset($_GET['img'], $_GET['endpoint']);
$qs = http_build_query($_GET);
$url = $WP_BASE . '/' . $endpoint . ($qs !== '' ? '?' . $qs : '');

$ctx = stream_context_create(array('http' => array(
    'timeout'      => 25,
    'header'       => "Accept: application/json\r\nUser-Agent: ChimpzlabSite/1.0\r\n",
    'ignore_errors' => true,
)));
$json = @file_get_contents($url, false, $ctx);
$status = 200;
$total = null;
if (isset($http_response_header) && is_array($http_response_header)) {
    foreach ($http_response_header as $h) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $h, $m)) {
            $status = (int) $m[1];
        } elseif (stripos($h, 'X-WP-Total:') === 0) {
            $total = trim(substr($h, 12));
        }
    }
}
if ($json === false || $status >= 400) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo '{"error":"upstream ' . $status . '"}';
    exit;
}
header('Content-Type: application/json');
header('Cache-Control: no-cache');
if ($total !== null) {
    header('X-WP-Total: ' . $total);
}
echo $json;
