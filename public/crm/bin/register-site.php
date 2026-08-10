<?php

declare(strict_types=1);

// One-shot, idempotent CRM setup: creates the SQLite schema (if missing) and
// registers the ChimpzLab site + API key used by the site's contact forms.
//
// Usage (CLI only, from anywhere):
//   php bin/register-site.php [api_key]
//
// The api_key resolves in this order: CLI argument -> PUBLIC_CRM_API_KEY env
// var -> PUBLIC_CRM_API_KEY in the Astro root .env -> the public site key the
// build falls back to (see src/config/site.ts). A fresh key is generated only
// when none of those exist. The key is a client-visible public identifier
// (not a secret): the CRM still rate-limits, spam-filters and reCAPTCHA-checks
// every submission server-side. Works both locally (public/crm) and on the
// deployed host (dist/crm, served as /crm). On the live host, set DB_PATH in
// crm/.env to an absolute path OUTSIDE the web root before running this, so
// the database is never downloadable and survives deploys.

// CLI guard: this script performs state-changing writes (creates the schema,
// upserts a site) and must never be reachable over HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

require __DIR__ . '/../vendor/autoload.php';

$apiKey = $argv[1] ?? '';
if ($apiKey === '') {
    $apiKey = getenv('PUBLIC_CRM_API_KEY') ?: '';
}
if ($apiKey === '') {
    // Share the key embedded in the Astro build (src/config/site.ts) so a
    // plain `npm run serve:php` registers a key the built forms accept.
    $rootEnv = dirname(__DIR__, 3) . '/.env';
    if (is_file($rootEnv)) {
        $vars = env_load($rootEnv);
        $apiKey = (string) ($vars['PUBLIC_CRM_API_KEY'] ?? '');
    }
}
if ($apiKey === '') {
    $apiKey = '3afc0840e1193e58397159d4af15cf4a15b5b35c';
}

$pdo = db();

$hasSitesTable = (bool) $pdo->query(
    "SELECT name FROM sqlite_master WHERE type='table' AND name='sites'"
)->fetch();

if (!$hasSitesTable) {
    $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
    $pdo->exec($schema);
    echo 'Database schema created at ' . app_config()['db_path'] . "\n";
} else {
    echo 'Database already initialised, skipping schema. (' . app_config()['db_path'] . ")\n";
}

$stmt = $pdo->prepare(
    "INSERT INTO sites (name, api_key, redirect_url, active)
     VALUES (?, ?, '/thanks', 1)
     ON CONFLICT(api_key) DO UPDATE SET active = 1, redirect_url = '/thanks'"
);
$stmt->execute(['ChimpzLab', $apiKey]);

echo 'Site "ChimpzLab" registered with API key ' . substr($apiKey, 0, 8) . "... (active=1)\n";
echo 'Copy this full key into the site build config as PUBLIC_CRM_API_KEY:\n';
echo $apiKey . "\n";
echo "Done. The contact forms will now accept submissions for this API key.\n";
