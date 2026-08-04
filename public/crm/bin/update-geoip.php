<?php

declare(strict_types=1);

// Downloads and imports the IPv4 → country dataset from
// https://github.com/sapics/ip-location-db (dbip-country variant, licensed
// CC-BY-4.0 by DB-IP.com - see https://db-ip.com) into the local ip_country
// table. Also available from the admin Settings page ("Sync now").
//
//   php bin/update-geoip.php
//
// While the table is empty, GeoIP checks are silently skipped, so running
// this is optional - it only enables the country capture + country blocking.

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$pdo = db();
bootstrap_database();

echo "Downloading GeoIP country data...\n";
[$ok, $result] = geoip_sync($pdo);

if (!$ok) {
    fwrite(STDERR, $result . "\n");
    exit(1);
}

echo "Imported " . number_format($result) . " IP ranges.\n";
echo "Country capture and blocked-country filtering are now active.\n";
