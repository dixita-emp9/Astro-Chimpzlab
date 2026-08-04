<?php

declare(strict_types=1);

// Procedural helpers shared by both the public capture routes and the admin
// backend. Loaded automatically via composer's "files" autoload entry.

/**
 * Minimal .env parser - no external dependency needed for a handful of keys.
 */
function env_load(string $path): array
{
    $vars = [];
    if (is_file($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            [$key, $value] = $parts;
            $vars[trim($key)] = trim(trim($value), "\"'");
        }
    }
    return $vars;
}

function app_config(): array
{
    static $config = null;
    if ($config === null) {
        $env = env_load(dirname(__DIR__) . '/.env');
        $dbPath = $env['DB_PATH'] ?? 'storage/database.sqlite';
        if (!str_starts_with($dbPath, '/')) {
            $dbPath = dirname(__DIR__) . '/' . $dbPath;
        }
        $config = [
            'app_key' => $env['APP_KEY'] ?? '',
            'db_path' => $dbPath,
            'rate_limit' => [
                'max_per_window' => (int) ($env['RATE_LIMIT_MAX'] ?? 5),
                'window_seconds' => (int) ($env['RATE_LIMIT_WINDOW'] ?? 600),
            ],
            'daily_limit_max' => (int) ($env['RATE_LIMIT_DAILY_MAX'] ?? 20),
            'blocked_countries' => array_values(array_filter(array_map(
                fn($c) => strtoupper(trim($c)),
                explode(',', $env['BLOCKED_COUNTRIES'] ?? '')
            ))),
            'check_email_dns' => ($env['CHECK_EMAIL_DNS'] ?? '1') === '1',
            'min_submit_seconds' => (int) ($env['MIN_SUBMIT_SECONDS'] ?? 3),
            'max_token_age_seconds' => (int) ($env['MAX_TOKEN_AGE_SECONDS'] ?? 3600),
            'trust_proxy' => ($env['TRUST_PROXY'] ?? '0') === '1',
        ];

        if ($config['app_key'] === '' && PHP_SAPI !== 'cli') {
            http_response_code(500);
            die('MicroCRM is not configured: set APP_KEY in .env (see .env.example).');
        }
    }
    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dbPath = app_config()['db_path'];
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
    }
    return $pdo;
}

/**
 * Auto-creates the schema on first request so `php -S ... -t public` works
 * immediately after `composer install`. Cheap no-op on every request after
 * that (single lightweight SELECT against sqlite_master).
 */
function bootstrap_database(): void
{
    $pdo = db();
    $exists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='leads'")->fetch();
    if (!$exists) {
        $schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
        $pdo->exec($schema);
    }

    $hasFieldsJson = $pdo->query("PRAGMA table_info(sites)")->fetchAll();
    $hasFieldsJson = in_array('fields_json', array_column($hasFieldsJson, 'name'), true);
    if (!$hasFieldsJson) {
        $pdo->exec('ALTER TABLE sites ADD COLUMN fields_json TEXT');
    }

    $leadCols = array_column($pdo->query("PRAGMA table_info(leads)")->fetchAll(), 'name');
    if (!in_array('country', $leadCols, true)) {
        $pdo->exec('ALTER TABLE leads ADD COLUMN country TEXT');
    }
    if (!in_array('language', $leadCols, true)) {
        $pdo->exec('ALTER TABLE leads ADD COLUMN language TEXT');
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS ip_country (
            ip_from INTEGER NOT NULL,
            ip_to   INTEGER NOT NULL,
            country TEXT NOT NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ip_country_from ON ip_country(ip_from)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)');

    // Per-site outbound connectors (SMTP email, webhook POST) and their
    // delivery log.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS connectors (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            site_id     INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
            type        TEXT NOT NULL,               -- 'smtp' | 'webhook'
            name        TEXT NOT NULL,
            config_json TEXT NOT NULL,               -- type-specific settings
            send_spam   INTEGER NOT NULL DEFAULT 0,  -- also forward spam leads?
            active      INTEGER NOT NULL DEFAULT 1,
            created_at  TEXT NOT NULL DEFAULT (datetime('now'))
        )"
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_connectors_site ON connectors(site_id)');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS connector_log (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            connector_id INTEGER NOT NULL REFERENCES connectors(id) ON DELETE CASCADE,
            lead_id      INTEGER,
            ok           INTEGER NOT NULL,
            detail       TEXT,
            created_at   TEXT NOT NULL DEFAULT (datetime('now'))
        )"
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_connlog_connector ON connector_log(connector_id, created_at)');

    // Per-lead activity feed (notes + auto-logged status changes).
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS lead_activity (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            lead_id    INTEGER NOT NULL REFERENCES leads(id) ON DELETE CASCADE,
            type       TEXT NOT NULL DEFAULT 'note',
            body       TEXT NOT NULL,
            author     TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )"
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lead_activity_lead ON lead_activity(lead_id, created_at)');

    // Per-site weekly report recipient (empty = reports disabled for the site).
    $siteCols = array_column($pdo->query("PRAGMA table_info(sites)")->fetchAll(), 'name');
    if (!in_array('report_email', $siteCols, true)) {
        $pdo->exec('ALTER TABLE sites ADD COLUMN report_email TEXT');
    }
    if (!in_array('success_message', $siteCols, true)) {
        $pdo->exec('ALTER TABLE sites ADD COLUMN success_message TEXT');
    }
}

// --- Runtime settings -------------------------------------------------------
//
// Tunables the admin can change from the Settings page. Stored in the
// settings table; .env values act as defaults for fresh installs. Secrets
// and infrastructure config (APP_KEY, DB_PATH, TRUST_PROXY) stay .env-only.

function settings(): array
{
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }

    $env = app_config();
    $settings = [
        'rate_limit_max' => $env['rate_limit']['max_per_window'],
        'rate_limit_window' => $env['rate_limit']['window_seconds'],
        'daily_limit_max' => $env['daily_limit_max'],
        'min_submit_seconds' => $env['min_submit_seconds'],
        'max_token_age_seconds' => $env['max_token_age_seconds'],
        'check_email_dns' => $env['check_email_dns'] ? 1 : 0,
        'check_heuristics' => 1,
        'check_duplicates' => 1,
        'flag_direct_api' => 1,
        'blocked_countries' => implode(',', $env['blocked_countries']),
        'spam_retention_days' => 30,
        'geoip_last_sync' => '',
    ];

    foreach (db()->query('SELECT key, value FROM settings')->fetchAll() as $row) {
        if (array_key_exists($row['key'], $settings)) {
            $settings[$row['key']] = is_int($settings[$row['key']]) ? (int) $row['value'] : $row['value'];
        }
    }

    return $settings;
}

function setting_save(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $stmt->execute([$key, $value]);
}

function blocked_countries_list(): array
{
    return array_values(array_filter(array_map(
        fn($c) => strtoupper(trim($c)),
        explode(',', settings()['blocked_countries'])
    )));
}

/**
 * Downloads the DB-IP country dataset and rebuilds the ip_country table.
 * Shared by bin/update-geoip.php and the Settings page "Sync now" button.
 * Returns [true, rangeCount] or [false, errorMessage].
 */
function geoip_sync(PDO $pdo): array
{
    $url = 'https://raw.githubusercontent.com/sapics/ip-location-db/master/dbip-country/dbip-country-ipv4.csv';
    $context = stream_context_create(['http' => ['timeout' => 120, 'user_agent' => 'MicroCRM-geoip-updater']]);
    $csv = @file_get_contents($url, false, $context);
    if ($csv === false || $csv === '') {
        return [false, 'Download failed - check the server\'s network connection.'];
    }

    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM ip_country');
    $ins = $pdo->prepare('INSERT INTO ip_country (ip_from, ip_to, country) VALUES (?, ?, ?)');

    $count = 0;
    foreach (explode("\n", $csv) as $line) {
        $parts = explode(',', trim($line));
        if (count($parts) !== 3) {
            continue;
        }
        $from = ip2long(trim($parts[0]));
        $to = ip2long(trim($parts[1]));
        $country = strtoupper(trim($parts[2]));
        if ($from === false || $to === false || !preg_match('/^[A-Z]{2}$/', $country)) {
            continue;
        }
        $ins->execute([$from, $to, $country]);
        $count++;
    }
    $pdo->commit();

    setting_save($pdo, 'geoip_last_sync', date('Y-m-d H:i:s'));
    return [true, $count];
}

/**
 * Resolves an IP to a two-letter country code using the local ip_country
 * table. Returns null for IPv6, private/loopback addresses, or when the
 * table hasn't been populated yet (see bin/update-geoip.php) - GeoIP is
 * strictly best-effort and must never break lead capture.
 */
function geoip_country(PDO $pdo, string $ip): ?string
{
    // Unwrap IPv4-mapped IPv6 (::ffff:1.2.3.4).
    if (str_starts_with($ip, '::ffff:')) {
        $ip = substr($ip, 7);
    }
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return null;
    }
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return null;
    }

    $long = ip2long($ip);
    // Ranges are non-overlapping, so the row with the closest ip_from below
    // the address is the only candidate; verify it actually contains the IP.
    $stmt = $pdo->prepare(
        'SELECT country, ip_to FROM ip_country WHERE ip_from <= ? ORDER BY ip_from DESC LIMIT 1'
    );
    $stmt->execute([$long]);
    $row = $stmt->fetch();

    return ($row && $long <= (int) $row['ip_to']) ? $row['country'] : null;
}

/**
 * Renders the plain, no-JS <form> embed snippet for a site, using its
 * custom form-builder fields if any are defined, otherwise the default
 * Name / Email / Phone / Message set.
 *
 * Returns raw (unescaped) HTML markup - the caller is responsible for
 * escaping it once via h() when displaying it inside a <pre> block, since
 * this is also literally the code an admin copy-pastes onto their site.
 */
function render_lead_form_snippet(array $site, array $fields): string
{
    $base = site_base_url();
    $out = '<form method="post" action="' . $base . '/api/leads">' . "\n";
    $out .= '  <input type="hidden" name="api_key" value="' . $site['api_key'] . '">' . "\n";
    if (!empty($site['redirect_url'])) {
        $out .= '  <input type="hidden" name="_redirect" value="' . $site['redirect_url'] . '">' . "\n";
    }

    $hasCustomFields = false;
    foreach ($fields as $f) {
        if (($f['name'] ?? '') !== '') {
            $hasCustomFields = true;
            break;
        }
    }

    if (!$hasCustomFields) {
        $fields = [
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
            ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'required' => false],
            ['name' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => false],
        ];
    }

    foreach ($fields as $f) {
        if (($f['name'] ?? '') === '') {
            continue;
        }
        $name = $f['name'];
        $label = $f['label'] ?: $f['name'];
        $required = !empty($f['required']) ? ' required' : '';

        if (($f['type'] ?? 'text') === 'hidden') {
            $out .= '  <input type="hidden" name="' . $name . '" value="' . ($f['value'] ?? '') . '">' . "\n";
        } elseif (($f['type'] ?? 'text') === 'textarea') {
            $out .= '  <label>' . $label . ' <textarea name="' . $name . '"' . $required . '></textarea></label>' . "\n";
        } elseif (($f['type'] ?? 'text') === 'select') {
            $out .= '  <label>' . $label . ' <select name="' . $name . '"' . $required . '>' . "\n";
            $out .= '    <option value="">Select…</option>' . "\n";
            foreach (($f['options'] ?? []) as $o) {
                $out .= '    <option value="' . ($o['value'] ?? '') . '">' . ($o['label'] ?? ($o['value'] ?? '')) . '</option>' . "\n";
            }
            $out .= '  </select></label>' . "\n";
        } else {
            $out .= '  <label>' . $label . ' <input type="' . ($f['type'] ?: 'text') . '" name="' . $name . '"' . $required . '></label>' . "\n";
        }
    }

    $out .= '  <!-- honeypot: keep hidden via CSS, real visitors never fill it in -->' . "\n";
    $out .= '  <input type="text" name="_hp" style="display:none" tabindex="-1" autocomplete="off">' . "\n";
    $out .= '  <button type="submit">Send</button>' . "\n";
    $out .= '</form>';

    return $out;
}

/**
 * Decodes a site's custom form-builder field definitions. Each field is
 * ['name' => ..., 'label' => ..., 'type' => text|email|tel|textarea|select,
 * 'required' => bool, 'options' => [[value, label], ...] (select only)].
 */
function site_form_fields(array $site): array
{
    $raw = $site['fields_json'] ?? null;
    if (!$raw) {
        return [];
    }
    $decoded = json_decode((string) $raw, true);
    return is_array($decoded) ? $decoded : [];
}

function generate_api_key(): string
{
    return bin2hex(random_bytes(20));
}

function get_site_by_key(PDO $pdo, string $key): ?array
{
    if ($key === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM sites WHERE api_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function client_ip(): string
{
    $config = app_config();
    if ($config['trust_proxy'] && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($parts[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function site_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = dirname($_SERVER['SCRIPT_NAME']);
    $path = $path !== '/' && $path !== '.' ? $path : '';
    return $scheme . '://' . $host . $path;
}

function json_error(string $message, int $code = 400): void
{
    Flight::json(['success' => false, 'error' => $message], $code);
}

// --- Auth -------------------------------------------------------------------

function current_user(): ?array
{
    if (empty($_SESSION['admin_user_id'])) {
        return null;
    }
    static $user = null;
    if ($user === null) {
        $stmt = db()->prepare('SELECT id, username, created_at FROM admin_users WHERE id = ?');
        $stmt->execute([$_SESSION['admin_user_id']]);
        $user = $stmt->fetch() ?: false;
    }
    return $user ?: null;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        Flight::redirect('/login');
        exit;
    }
    return $user;
}

// --- CSRF ---------------------------------------------------------------

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_check(): void
{
    $token = Flight::request()->data->_csrf ?? '';
    $expected = $_SESSION['_csrf'] ?? '';
    if ($expected === '' || !is_string($token) || !hash_equals((string) $expected, (string) $token)) {
        Flight::halt(419, 'Invalid or expired form token. Go back and try again.');
    }
}

// --- Flash messages -------------------------------------------------------

function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['_flash'][$key] = $message;
        return null;
    }
    $value = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return $value;
}

// --- Views ----------------------------------------------------------------

function render_view(string $name, array $data = [], bool $useLayout = true): void
{
    extract($data);
    $viewsDir = dirname(__DIR__) . '/views';

    if (!$useLayout) {
        include $viewsDir . "/{$name}.php";
        return;
    }

    ob_start();
    include $viewsDir . "/{$name}.php";
    $content = ob_get_clean();
    $title = $data['title'] ?? 'MicroCRM';
    $currentUser = current_user();
    include $viewsDir . '/layout.php';
}

// --- Small view-layer utilities --------------------------------------------

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Appends one entry to a lead's activity feed. `type` is one of note,
 * status, or created; system events pass author = null.
 */
function log_activity(PDO $pdo, int $leadId, string $type, string $body, ?string $author = null): void
{
    $stmt = $pdo->prepare('INSERT INTO lead_activity (lead_id, type, body, author) VALUES (?, ?, ?, ?)');
    $stmt->execute([$leadId, $type, $body, $author]);
}

/**
 * Human-friendly relative time ("3 min ago") for a stored UTC datetime,
 * falling back to an absolute date past a week. Returns '' for empty input.
 */
function time_ago(?string $datetime): string
{
    if (!$datetime) {
        return '';
    }
    $ts = strtotime($datetime . ' UTC');
    if ($ts === false) {
        return (string) $datetime;
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return 'just now';
    }
    foreach ([[31536000, 'year'], [2592000, 'month'], [604800, 'week'], [86400, 'day'], [3600, 'hour'], [60, 'min']] as [$secs, $label]) {
        if ($diff >= $secs) {
            $n = (int) floor($diff / $secs);
            return $n . ' ' . $label . ($n > 1 ? 's' : '') . ' ago';
        }
    }
    return 'just now';
}

function paginate(int $totalRows, int $page, int $perPage): array
{
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    $page = max(1, min($page, $totalPages));
    return [
        'page' => $page,
        'per_page' => $perPage,
        'total_rows' => $totalRows,
        'total_pages' => $totalPages,
        'offset' => ($page - 1) * $perPage,
    ];
}
