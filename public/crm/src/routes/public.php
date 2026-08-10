<?php

declare(strict_types=1);

// Public, unauthenticated routes: the embeddable widget script and the lead
// capture endpoint it (or a plain HTML form) posts to.

use App\SpamFilter;

// Fallback asset server for environments where URL rewriting sends static files to index.php
Flight::route('GET /assets/@file', function (string $file) {
    $path = dirname(__DIR__, 2) . '/public/assets/' . basename($file);
    if (!file_exists($path)) {
        Flight::notFound();
        return;
    }
    
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    $mimeTypes = [
        'css' => 'text/css',
        'js' => 'application/javascript',
    ];
    
    $mime = $mimeTypes[$ext] ?? 'text/plain';
    
    // Add cache headers so it doesn't slow down the app too much if heavily relied upon
    Flight::response()
        ->header('Content-Type', $mime)
        ->header('Cache-Control', 'max-age=86400, public')
        ->write(file_get_contents($path))
        ->send();
});

/**
 * Serves a per-site JS widget that renders a spam-hardened lead form into
 * any <div data-microcrm="KEY"></div> placeholder on the host page.
 *
 * The signed timestamp token is generated fresh on every load of this file,
 * which is what lets SpamFilter::checkTimeTrap() catch instant/replayed bot
 * submissions without ever needing a captcha.
 */
Flight::route('GET /embed/@key/widget.js', function (string $key) {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: no-store');
    header('Access-Control-Allow-Origin: *');

    $pdo = db();
    $site = get_site_by_key($pdo, $key);

    if (!$site || !(int) $site['active']) {
        echo "console.error('MicroCRM: unknown or inactive form key.');";
        return;
    }

    $config = app_config();
    $ts = (string) time();
    $sig = hash_hmac('sha256', $site['api_key'] . '|' . $ts, $config['app_key']);

    render_view('embed_script', [
        'apiKey' => $site['api_key'],
        'ts' => $ts,
        'sig' => $sig,
        'endpoint' => site_base_url() . '/api/leads',
    ], false);
});

// CORS preflight for /api/leads. Flight short-circuits OPTIONS requests to a
// bare 204 before any route handler runs, so we handle the headers here, before
// the framework starts. The origin is reflected ONLY when it matches a site's
// configured allowed_domain; when no domain-restricted sites exist the endpoint
// is deliberately wildcard (write-only + server-side validation + rate limits).
// Always sets Vary: Origin so shared caches never serve a cross-origin answer.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS'
    && str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/leads')) {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = capture_allowed_origins(db());

    if ($allowed === []) {
        header('Access-Control-Allow-Origin: *');
    } elseif ($origin !== '' && in_array(rtrim($origin, '/'), $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

Flight::route('POST /api/leads', function () {
    $pdo = db();
    $config = app_config();
    $req = Flight::request();

    $data = $req->data->getData();
    if (empty($data)) {
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
    }

    $apiKey = trim((string) ($data['api_key'] ?? $req->query->api_key ?? ''));
    $site = get_site_by_key($pdo, $apiKey);

    if (!$site) {
        json_error('Invalid form key.', 403);
        return;
    }
    if (!(int) $site['active']) {
        json_error('This form is currently disabled.', 403);
        return;
    }

    // CORS: emit ONLY after the site is validated. If the site has a domain
    // configured, reflect exactly that origin; otherwise allow any origin
    // (write-only endpoint, every submission validated + rate limited).
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($site && !empty($site['allowed_domain'])) {
        if ($origin !== '') {
            $allowedDomains = array_map('trim', explode(',', (string) $site['allowed_domain']));
            $originClean = rtrim($origin, '/');
            foreach ($allowedDomains as $domain) {
                if ($originClean === rtrim($domain, '/')) {
                    header('Access-Control-Allow-Origin: ' . $origin);
                    header('Vary: Origin');
                    break;
                }
            }
        }
    } else {
        header('Access-Control-Allow-Origin: *');
    }

    $ip = client_ip();
    $settings = settings();

    SpamFilter::pruneRateLog($pdo);
    if ((int) $settings['spam_retention_days'] > 0) {
        $pdo->prepare("DELETE FROM leads WHERE is_spam = 1 AND created_at < datetime('now', ?)")
            ->execute(['-' . (int) $settings['spam_retention_days'] . ' days']);
    }

    $withinLimit = SpamFilter::withinRateLimit(
        $pdo,
        $ip,
        (int) $settings['rate_limit_max'],
        (int) $settings['rate_limit_window']
    );
    if (!$withinLimit || !SpamFilter::withinDailyLimit($pdo, $ip, (int) $settings['daily_limit_max'])) {
        SpamFilter::logRequest($pdo, $ip, (int) $site['id']);
        json_error('Too many submissions from your network. Please try again later.', 429);
        return;
    }

    // Hard length caps: nothing legitimate needs more, and oversized fields
    // are a spam/abuse signature in themselves.
    // Custom form-builder templates use common aliases for the core columns
    // (e.g. fullname/fname for name, mobile for phone) - fold them in so
    // those leads still populate the name/phone columns and pass validation.
    $name = trim((string) ($data['name'] ?? $data['fullname'] ?? $data['fname'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $phone = trim((string) ($data['phone'] ?? $data['mobile'] ?? ''));
    $message = trim((string) ($data['message'] ?? ''));

    $name = mb_substr($name, 0, 200);
    $email = mb_substr($email, 0, 254);
    $phone = mb_substr($phone, 0, 30);
    $message = mb_substr($message, 0, 5000);

    if ($name === '' && $email === '') {
        SpamFilter::logRequest($pdo, $ip, (int) $site['id']);
        json_error('Please provide at least a name or an email address.', 422);
        return;
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        SpamFilter::logRequest($pdo, $ip, (int) $site['id']);
        json_error('Please enter a valid email address.', 422);
        return;
    }

    $honeypotTripped = SpamFilter::checkHoneypot($data);
    $timeCheck = SpamFilter::checkTimeTrap(
        $data['_ts'] ?? null,
        $data['_sig'] ?? null,
        $site['api_key'],
        $config['app_key'],
        (int) $settings['min_submit_seconds'],
        (int) $settings['max_token_age_seconds']
    );
    $contentCheck = SpamFilter::evaluateContent($pdo, [
        'name' => $name,
        'email' => $email,
        'message' => $message,
    ]);

    $reasons = [];
    if ($honeypotTripped) {
        $reasons[] = 'honeypot';
    }
    if ($settings['flag_direct_api'] && SpamFilter::honeypotFieldMissing($data)) {
        $reasons[] = 'direct_api_post';
    }
    if (!$timeCheck['valid']) {
        $reasons[] = $timeCheck['reason'];
    }
    if ($contentCheck['flagged']) {
        $reasons[] = $contentCheck['reason'];
    }
    if ($settings['check_heuristics']) {
        foreach (SpamFilter::checkHeuristics($name, $email, $message, (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')) as $r) {
            $reasons[] = $r;
        }
    }
    if ($email !== '' && $settings['check_email_dns'] && !SpamFilter::emailDomainResolves($email)) {
        $reasons[] = 'email_domain_unresolvable';
    }
    if ($settings['check_duplicates'] && SpamFilter::isDuplicate($pdo, (int) $site['id'], $email, $phone)) {
        $reasons[] = 'duplicate';
    }

    // GeoIP: best-effort country resolution (null when the ip_country table
    // hasn't been populated via Settings → Sync now or bin/update-geoip.php).
    $country = geoip_country($pdo, $ip);
    if ($country !== null && in_array($country, blocked_countries_list(), true)) {
        $reasons[] = 'blocked_country:' . $country;
    }

    $isSpam = $reasons !== [];

    $known = ['name', 'fullname', 'fname', 'email', 'phone', 'mobile', 'message', 'api_key', '_hp', '_ts', '_sig', '_csrf', '_redirect'];
    $extra = array_diff_key($data, array_flip($known));

    // Cap the size and count of extra fields so nobody can bloat the DB
    // through arbitrary form keys.
    $extra = array_slice($extra, 0, 20, true);
    $extra = array_map(
        fn($v) => is_scalar($v) ? mb_substr((string) $v, 0, 1000) : null,
        $extra
    );

    SpamFilter::logRequest($pdo, $ip, (int) $site['id']);

    $stmt = $pdo->prepare(
        'INSERT INTO leads
            (site_id, name, email, phone, message, extra_json, ip_address, country, language, user_agent, referrer, status, is_spam, spam_reason)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $site['id'],
        $name,
        $email,
        $phone,
        $message,
        $extra ? json_encode($extra) : null,
        $ip,
        $country,
        substr((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 0, 100) ?: null,
        substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 500),
        $isSpam ? 'spam' : 'new',
        $isSpam ? 1 : 0,
        $reasons ? implode(',', $reasons) : null,
    ]);
    $leadId = (int) $pdo->lastInsertId();

    log_activity(
        $pdo,
        $leadId,
        'created',
        $isSpam ? 'Lead captured and flagged as spam' : 'Lead captured from ' . $site['name'],
        null
    );

    // Forward to the site's active connectors (SMTP / webhook). Wrapped so a
    // slow or failing connector never affects the capture response; every
    // attempt is recorded in connector_log.
    try {
        $leadRow = [
            'id' => $leadId, 'site_id' => (int) $site['id'],
            'name' => $name, 'email' => $email, 'phone' => $phone, 'message' => $message,
            'extra_json' => $extra ? json_encode($extra) : null,
            'ip_address' => $ip, 'country' => $country, 'referrer' => (string) ($_SERVER['HTTP_REFERER'] ?? ''),
            'status' => $isSpam ? 'spam' : 'new', 'is_spam' => $isSpam ? 1 : 0,
            'spam_reason' => $reasons ? implode(',', $reasons) : null,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        \App\Connectors::dispatchLead($pdo, $site, $leadRow);
    } catch (\Throwable $e) {
        // Best-effort; never surface to the submitter.
    }

    $successMessage = !empty($site['success_message']) ? $site['success_message'] : 'your enquiry sent successfully';

    if (!empty($data['_redirect'])) {
        // Never trust a client-supplied redirect verbatim (open redirect).
        Flight::redirect(safe_local_url((string) $data['_redirect'], '/thanks'));
        return;
    }

    $isAjax = !empty($data['_ajax']) || !empty($_GET['_ajax']) || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));
    
    if ($isAjax) {
        // Always report success, spam or not - the response shouldn't help bots
        // learn which submissions got flagged.
        Flight::json(['success' => true, 'message' => $successMessage]);
        return;
    }

    render_view('thanks', ['message' => $successMessage], false);
});
