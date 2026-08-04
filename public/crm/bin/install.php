<?php

declare(strict_types=1);

// CLI installer: creates the DB schema (if missing), creates/updates the
// admin user, and seeds a starter set of spam rules (if none exist yet).
//
// Usage: php bin/install.php

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$pdo = db();

$hasAdminTable = (bool) $pdo->query(
    "SELECT name FROM sqlite_master WHERE type='table' AND name='admin_users'"
)->fetch();

if (!$hasAdminTable) {
    $schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
    $pdo->exec($schema);
    echo "Database schema created at " . app_config()['db_path'] . "\n";
} else {
    echo "Database schema already present, skipping.\n";
}

// --- Admin user -----------------------------------------------------------

fwrite(STDOUT, "Admin username: ");
$username = trim((string) fgets(STDIN));

fwrite(STDOUT, "Admin password: ");
if (stripos(PHP_OS, 'WIN') === 0) {
    $password = trim((string) fgets(STDIN));
} else {
    system('stty -echo');
    $password = trim((string) fgets(STDIN));
    system('stty echo');
    echo "\n";
}

if ($username === '' || $password === '') {
    fwrite(STDERR, "Username and password are required.\n");
    exit(1);
}

if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

$stmt = $pdo->prepare('SELECT id FROM admin_users WHERE username = ?');
$stmt->execute([$username]);
$existing = $stmt->fetch();

$hash = password_hash($password, PASSWORD_DEFAULT);

if ($existing) {
    $upd = $pdo->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?');
    $upd->execute([$hash, $existing['id']]);
    echo "Password updated for existing admin '{$username}'.\n";
} else {
    $ins = $pdo->prepare('INSERT INTO admin_users (username, password_hash) VALUES (?, ?)');
    $ins->execute([$username, $hash]);
    echo "Admin user '{$username}' created.\n";
}

// --- Default spam rules -----------------------------------------------------

$ruleCount = (int) $pdo->query('SELECT COUNT(*) FROM spam_rules')->fetchColumn();

if ($ruleCount === 0) {
    $disposableDomains = [
        'mailinator.com', 'guerrillamail.com', '10minutemail.com', 'tempmail.com',
        'yopmail.com', 'trashmail.com', 'throwawaymail.com', 'fakeinbox.com',
        'sharklasers.com', 'getnada.com', 'maildrop.cc', 'dispostable.com',
    ];
    $domainStmt = $pdo->prepare(
        "INSERT INTO spam_rules (type, pattern, field, action) VALUES ('email_domain', ?, 'email', 'block')"
    );
    foreach ($disposableDomains as $domain) {
        $domainStmt->execute([$domain]);
    }

    $spamKeywords = ['viagra', 'crypto airdrop', 'seo services', 'backlink packages', 'bitcoin investment'];
    $keywordStmt = $pdo->prepare(
        "INSERT INTO spam_rules (type, pattern, field, action) VALUES ('keyword', ?, 'message', 'flag')"
    );
    foreach ($spamKeywords as $keyword) {
        $keywordStmt->execute([$keyword]);
    }

    echo 'Seeded ' . (count($disposableDomains) + count($spamKeywords)) . " default spam rules.\n";
} else {
    echo "Spam rules already exist ({$ruleCount}), skipping seed.\n";
}

echo "Done. Start the server with: php -S localhost:8080 -t public\n";
