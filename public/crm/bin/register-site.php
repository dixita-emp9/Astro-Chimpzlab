<?php

declare(strict_types=1);

// One-shot, idempotent CRM setup: creates the SQLite schema (if missing) and
// registers the ChimpzLab site + API key used by the site's contact forms.
//
// Usage (from anywhere):
//   php bin/register-site.php [api_key]
//
// Works both locally (public/crm) and on the deployed host (dist/crm, served
// as /crm). On the live host, set DB_PATH in crm/.env to an absolute path
// OUTSIDE the web root before running this, so the database is never
// downloadable and survives deploys.

require __DIR__ . '/../vendor/autoload.php';

$apiKey = $argv[1] ?? '3afc0840e1193e58397159d4af15cf4a15b5b35c';

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
echo "Done. The contact forms will now accept submissions for this API key.\n";
