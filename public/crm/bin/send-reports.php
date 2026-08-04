<?php

declare(strict_types=1);

// Weekly per-site lead digest. For each site that has a report recipient
// (Connectors → Weekly report) AND at least one active SMTP connector, emails
// a summary of the last 7 days. Schedule via cron, e.g. Mondays at 08:00:
//
//   0 8 * * 1  php /path/to/MicroCRM/bin/send-reports.php
//
// Pass --days=N to change the window (default 7).

require __DIR__ . '/../vendor/autoload.php';

use App\Mailer;
use App\Report;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$days = 7;
foreach ($argv as $arg) {
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) {
        $days = max(1, (int) $m[1]);
    }
}

$pdo = db();
bootstrap_database();

$sites = $pdo->query('SELECT * FROM sites WHERE report_email IS NOT NULL AND report_email != ""')->fetchAll();

if (!$sites) {
    echo "No sites have a weekly report recipient configured. Nothing to do.\n";
    exit(0);
}

$sent = 0;
$skipped = 0;

foreach ($sites as $site) {
    // Need an active SMTP connector on this site to deliver through.
    $smtpStmt = $pdo->prepare("SELECT * FROM connectors WHERE site_id = ? AND type = 'smtp' AND active = 1 ORDER BY id LIMIT 1");
    $smtpStmt->execute([$site['id']]);
    $smtp = $smtpStmt->fetch();

    if (!$smtp) {
        echo "Skip \"{$site['name']}\": no active SMTP connector to send through.\n";
        $skipped++;
        continue;
    }

    $stats = Report::stats($pdo, (int) $site['id'], $days);
    $html = Report::html($site, $stats, $days);
    $config = json_decode($smtp['config_json'], true) ?: [];
    $subject = Report::subject($site, $stats);

    try {
        Mailer::send($config, $site['report_email'], $subject, $html);
        echo "Sent report for \"{$site['name']}\" to {$site['report_email']} ({$stats['total']} leads).\n";
        $sent++;
    } catch (Throwable $e) {
        fwrite(STDERR, "Failed for \"{$site['name']}\": " . $e->getMessage() . "\n");
        $skipped++;
    }
}

echo "Done. {$sent} sent, {$skipped} skipped.\n";
