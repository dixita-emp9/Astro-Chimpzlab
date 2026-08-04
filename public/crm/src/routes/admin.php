<?php

declare(strict_types=1);

// Session-protected admin backend: auth, dashboard, lead management +
// export, site management (embed codes), and spam rule management.

/**
 * Builds a WHERE clause + params from the leads list query string, shared
 * by both the on-screen list and the CSV export so they always agree.
 */
function leads_query_filters($req): array
{
    $where = [];
    $params = [];

    $siteId = (int) ($req->query->site_id ?? 0);
    if ($siteId > 0) {
        $where[] = 'site_id = ?';
        $params[] = $siteId;
    }

    $status = trim((string) ($req->query->status ?? ''));
    if ($status === '') {
        $where[] = "status != 'spam'";
    } elseif ($status !== 'all') {
        $where[] = 'status = ?';
        $params[] = $status;
    }

    $q = trim((string) ($req->query->q ?? ''));
    if ($q !== '') {
        $where[] = '(name LIKE ? OR email LIKE ? OR message LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like);
    }

    $from = trim((string) ($req->query->from ?? ''));
    if ($from !== '') {
        $where[] = 'created_at >= ?';
        $params[] = $from . ' 00:00:00';
    }

    $to = trim((string) ($req->query->to ?? ''));
    if ($to !== '') {
        $where[] = 'created_at <= ?';
        $params[] = $to . ' 23:59:59';
    }

    return [
        'sql' => $where ? ('WHERE ' . implode(' AND ', $where)) : '',
        'params' => $params,
        'raw' => ['site_id' => $siteId, 'status' => $status, 'q' => $q, 'from' => $from, 'to' => $to],
    ];
}

Flight::route('GET /', function () {
    Flight::redirect(current_user() ? '/dashboard' : '/login');
});

// --- Auth --------------------------------------------------------------

Flight::route('GET /login', function () {
    if (current_user()) {
        Flight::redirect('/dashboard');
        return;
    }
    render_view('login', ['error' => null], false);
});

Flight::route('POST /login', function () {
    csrf_check();
    $req = Flight::request();
    $username = trim((string) ($req->data->username ?? ''));
    $password = (string) ($req->data->password ?? '');

    $stmt = db()->prepare('SELECT * FROM admin_users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin_user_id'] = $user['id'];
        Flight::redirect('/dashboard');
        return;
    }

    render_view('login', ['error' => 'Invalid username or password.'], false);
});

Flight::route('GET /logout', function () {
    $_SESSION = [];
    session_destroy();
    Flight::redirect('/login');
});

// --- Dashboard -----------------------------------------------------------

Flight::route('GET /dashboard', function () {
    require_login();
    $pdo = db();

    $stats = [
        'total' => (int) $pdo->query("SELECT COUNT(*) FROM leads WHERE status != 'spam'")->fetchColumn(),
        'today' => (int) $pdo->query(
            "SELECT COUNT(*) FROM leads WHERE status != 'spam' AND date(created_at) = date('now')"
        )->fetchColumn(),
        'week' => (int) $pdo->query(
            "SELECT COUNT(*) FROM leads WHERE status != 'spam' AND created_at >= datetime('now', '-7 days')"
        )->fetchColumn(),
        'spam' => (int) $pdo->query("SELECT COUNT(*) FROM leads WHERE status = 'spam'")->fetchColumn(),
    ];

    $bySite = $pdo->query(
        "SELECT s.id, s.name, COUNT(l.id) AS lead_count
         FROM sites s
         LEFT JOIN leads l ON l.site_id = s.id AND l.status != 'spam'
         GROUP BY s.id
         ORDER BY lead_count DESC"
    )->fetchAll();

    $recent = $pdo->query(
        "SELECT l.*, s.name AS site_name
         FROM leads l JOIN sites s ON s.id = l.site_id
         WHERE l.status != 'spam'
         ORDER BY l.created_at DESC LIMIT 8"
    )->fetchAll();

    $statusBreakdown = $pdo->query(
        "SELECT status, COUNT(id) AS cnt 
         FROM leads WHERE status != 'spam' GROUP BY status"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    $dailyTrend = $pdo->query(
        "SELECT date(created_at) as d, COUNT(id) as cnt 
         FROM leads 
         WHERE created_at >= datetime('now', '-13 days') AND status != 'spam'
         GROUP BY d ORDER BY d"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    $recentFailures = $pdo->query(
        "SELECT cl.*, c.name AS connector_name, s.name AS site_name 
         FROM connector_log cl 
         JOIN connectors c ON c.id = cl.connector_id
         JOIN sites s ON s.id = c.site_id
         WHERE cl.ok = 0 ORDER BY cl.created_at DESC LIMIT 5"
    )->fetchAll();

    render_view('dashboard', [
        'title' => 'Dashboard · MicroCRM',
        'stats' => $stats,
        'bySite' => $bySite,
        'recent' => $recent,
        'statusBreakdown' => $statusBreakdown,
        'dailyTrend' => $dailyTrend,
        'recentFailures' => $recentFailures,
    ]);
});

// --- Leads -----------------------------------------------------------------

Flight::route('GET /leads', function () {
    require_login();
    $pdo = db();
    $req = Flight::request();

    $filters = leads_query_filters($req);
    $perPage = 25;
    $page = max(1, (int) ($req->query->page ?? 1));

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM leads {$filters['sql']}");
    $countStmt->execute($filters['params']);
    $total = (int) $countStmt->fetchColumn();

    $pg = paginate($total, $page, $perPage);

    $stmt = $pdo->prepare(
        "SELECT l.*, s.name AS site_name
         FROM leads l JOIN sites s ON s.id = l.site_id
         {$filters['sql']}
         ORDER BY l.created_at DESC
         LIMIT {$pg['per_page']} OFFSET {$pg['offset']}"
    );
    $stmt->execute($filters['params']);
    $leads = $stmt->fetchAll();

    $sites = $pdo->query('SELECT id, name FROM sites ORDER BY name')->fetchAll();

    render_view('leads', [
        'title' => 'Leads · MicroCRM',
        'leads' => $leads,
        'sites' => $sites,
        'filters' => $filters['raw'],
        'pg' => $pg,
    ]);
});

Flight::route('GET /leads/export', function () {
    require_login();
    $pdo = db();
    $req = Flight::request();

    $filters = leads_query_filters($req);
    $stmt = $pdo->prepare(
        "SELECT l.id, l.created_at, s.name AS site_name, l.name, l.email, l.phone, l.message,
                l.status, l.is_spam, l.spam_reason, l.ip_address, l.country, l.language, l.referrer, l.extra_json
         FROM leads l JOIN sites s ON s.id = l.site_id
         {$filters['sql']}
         ORDER BY l.created_at DESC"
    );
    $stmt->execute($filters['params']);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="leads-' . date('Y-m-d-His') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'ID', 'Date', 'Site', 'Name', 'Email', 'Phone', 'Message',
        'Status', 'Spam', 'Spam Reason', 'IP Address', 'Country', 'Language', 'Referrer', 'Extra Fields',
    ]);
    while ($row = $stmt->fetch()) {
        fputcsv($out, [
            $row['id'], $row['created_at'], $row['site_name'], $row['name'], $row['email'],
            $row['phone'], $row['message'], $row['status'], $row['is_spam'] ? 'yes' : 'no',
            $row['spam_reason'], $row['ip_address'], $row['country'], $row['language'],
            $row['referrer'], $row['extra_json'],
        ]);
    }
    fclose($out);
    exit;
});

Flight::route('GET /leads/@id', function (string $id) {
    require_login();
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT l.*, s.name AS site_name FROM leads l JOIN sites s ON s.id = l.site_id WHERE l.id = ?'
    );
    $stmt->execute([$id]);
    $lead = $stmt->fetch();

    if (!$lead) {
        flash('error', 'Lead not found.');
        Flight::redirect('/leads');
        return;
    }

    $delStmt = $pdo->prepare(
        'SELECT cl.*, c.name AS connector_name FROM connector_log cl
         JOIN connectors c ON c.id = cl.connector_id
         WHERE cl.lead_id = ? ORDER BY cl.created_at DESC'
    );
    $delStmt->execute([$lead['id']]);
    $deliveries = $delStmt->fetchAll();

    $actStmt = $pdo->prepare(
        'SELECT * FROM lead_activity WHERE lead_id = ? ORDER BY created_at DESC, id DESC'
    );
    $actStmt->execute([$lead['id']]);
    $activity = $actStmt->fetchAll();

    render_view('lead_detail', [
        'title' => 'Lead #' . $lead['id'] . ' · MicroCRM',
        'lead' => $lead,
        'deliveries' => $deliveries,
        'activity' => $activity,
    ]);
});

Flight::route('POST /leads/@id/status', function (string $id) {
    require_login();
    csrf_check();
    $status = (string) (Flight::request()->data->status ?? '');
    $allowed = ['new', 'contacted', 'qualified', 'converted', 'archived', 'spam'];
    if (!in_array($status, $allowed, true)) {
        flash('error', 'Invalid status.');
        Flight::redirect('/leads/' . $id);
        return;
    }
    
    $pdo = db();
    $prevStmt = $pdo->prepare('SELECT status FROM leads WHERE id = ?');
    $prevStmt->execute([$id]);
    $prevStatus = $prevStmt->fetchColumn();

    $isSpam = $status === 'spam' ? 1 : 0;
    $stmt = $pdo->prepare('UPDATE leads SET status = ?, is_spam = ? WHERE id = ?');
    $stmt->execute([$status, $isSpam, $id]);

    if ($prevStatus !== false && $prevStatus !== $status) {
        log_activity(
            $pdo,
            (int) $id,
            'status',
            'Status changed from ' . ucfirst((string) $prevStatus) . ' to ' . ucfirst($status),
            current_user()['username'] ?? null
        );
    }

    if (!$isSpam) {
        $leadStmt = $pdo->prepare('SELECT * FROM leads WHERE id = ?');
        $leadStmt->execute([$id]);
        $lead = $leadStmt->fetch();
        if ($lead) {
            $siteStmt = $pdo->prepare('SELECT * FROM sites WHERE id = ?');
            $siteStmt->execute([$lead['site_id']]);
            $site = $siteStmt->fetch();
            if ($site) {
                \App\Connectors::dispatchLead($pdo, $site, $lead);
            }
        }
    }
    
    flash('success', 'Lead status updated.');
    Flight::redirect('/leads/' . $id);
});

Flight::route('POST /leads/@id/note', function (string $id) {
    require_login();
    csrf_check();
    $pdo = db();

    $exists = $pdo->prepare('SELECT id FROM leads WHERE id = ?');
    $exists->execute([$id]);
    if (!$exists->fetchColumn()) {
        flash('error', 'Lead not found.');
        Flight::redirect('/leads');
        return;
    }

    $body = trim((string) (Flight::request()->data->body ?? ''));
    if ($body === '') {
        flash('error', 'Note cannot be empty.');
        Flight::redirect('/leads/' . $id);
        return;
    }

    log_activity($pdo, (int) $id, 'note', mb_substr($body, 0, 5000), current_user()['username'] ?? null);
    flash('success', 'Note added.');
    Flight::redirect('/leads/' . $id);
});

Flight::route('POST /leads/@id/delete', function (string $id) {
    require_login();
    csrf_check();
    $stmt = db()->prepare('DELETE FROM leads WHERE id = ?');
    $stmt->execute([$id]);
    flash('success', 'Lead deleted.');
    Flight::redirect('/leads');
});

/**
 * Bulk actions from the leads list: change status or delete a set of leads
 * selected via checkboxes. Redirects back to the originating filtered view.
 */
Flight::route('POST /leads/bulk', function () {
    require_login();
    csrf_check();
    $req = Flight::request();
    $pdo = db();

    $ids = array_values(array_filter(array_map('intval', (array) ($req->data->ids ?? [])), fn($n) => $n > 0));
    $action = (string) ($req->data->bulk_action ?? '');

    $back = (string) ($req->data->_back ?? '/leads');
    if (!str_starts_with($back, '/') || str_starts_with($back, '//')) {
        $back = '/leads';
    }

    if (!$ids) {
        flash('error', 'No leads selected.');
        Flight::redirect($back);
        return;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    if ($action === 'delete') {
        $stmt = $pdo->prepare("DELETE FROM leads WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        flash('success', count($ids) . ' lead(s) deleted.');
        Flight::redirect($back);
        return;
    }

    if (str_starts_with($action, 'status:')) {
        $status = substr($action, 7);
        $allowed = ['new', 'contacted', 'qualified', 'converted', 'archived', 'spam'];
        if (!in_array($status, $allowed, true)) {
            flash('error', 'Invalid status.');
            Flight::redirect($back);
            return;
        }
        $isSpam = $status === 'spam' ? 1 : 0;
        $author = current_user()['username'] ?? null;

        $prev = [];
        $stmtSel = $pdo->prepare("SELECT id, status FROM leads WHERE id IN ($placeholders)");
        $stmtSel->execute($ids);
        foreach ($stmtSel->fetchAll() as $row) {
            $prev[(int) $row['id']] = $row['status'];
        }

        $stmt = $pdo->prepare("UPDATE leads SET status = ?, is_spam = ? WHERE id IN ($placeholders)");
        $stmt->execute(array_merge([$status, $isSpam], $ids));

        foreach ($ids as $leadId) {
            if (isset($prev[$leadId]) && $prev[$leadId] !== $status) {
                log_activity(
                    $pdo,
                    $leadId,
                    'status',
                    'Status changed from ' . ucfirst((string) $prev[$leadId]) . ' to ' . ucfirst($status) . ' (bulk)',
                    $author
                );
            }
        }

        flash('success', count($ids) . ' lead(s) moved to ' . ucfirst($status) . '.');
        Flight::redirect($back);
        return;
    }

    flash('error', 'Unknown bulk action.');
    Flight::redirect($back);
});

// --- Sites -------------------------------------------------------------

Flight::route('GET /sites', function () {
    require_login();
    $sites = db()->query('SELECT * FROM sites ORDER BY created_at DESC')->fetchAll();
    render_view('sites', ['title' => 'Sites · MicroCRM', 'sites' => $sites]);
});

Flight::route('POST /sites', function () {
    require_login();
    csrf_check();
    $req = Flight::request();
    $name = trim((string) ($req->data->name ?? ''));
    $allowedDomain = trim((string) ($req->data->allowed_domain ?? ''));
    $redirectUrl = trim((string) ($req->data->redirect_url ?? ''));
    $successMessage = trim((string) ($req->data->success_message ?? ''));

    if ($name === '') {
        flash('error', 'Site name is required.');
        Flight::redirect('/sites');
        return;
    }

    $stmt = db()->prepare(
        'INSERT INTO sites (name, api_key, allowed_domain, redirect_url, success_message) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$name, generate_api_key(), $allowedDomain ?: null, $redirectUrl ?: null, $successMessage ?: null]);

    flash('success', "Site \"{$name}\" created. Copy its embed code below.");
    Flight::redirect('/sites');
});

Flight::route('POST /sites/@id/update', function (string $id) {
    require_login();
    csrf_check();
    $req = Flight::request();
    $name = trim((string) ($req->data->name ?? ''));
    $allowedDomain = trim((string) ($req->data->allowed_domain ?? ''));
    $redirectUrl = trim((string) ($req->data->redirect_url ?? ''));
    $successMessage = trim((string) ($req->data->success_message ?? ''));

    if ($name === '') {
        flash('error', 'Site name is required.');
        Flight::redirect('/sites');
        return;
    }

    $stmt = db()->prepare(
        'UPDATE sites SET name = ?, allowed_domain = ?, redirect_url = ?, success_message = ? WHERE id = ?'
    );
    $stmt->execute([$name, $allowedDomain ?: null, $redirectUrl ?: null, $successMessage ?: null, $id]);

    flash('success', "Site \"{$name}\" settings saved.");
    Flight::redirect('/sites');
});

Flight::route('POST /sites/@id/fields', function (string $id) {
    require_login();
    csrf_check();
    $req = Flight::request();

    $names = (array) ($req->data->field_name ?? []);
    $labels = (array) ($req->data->field_label ?? []);
    $types = (array) ($req->data->field_type ?? []);
    $required = (array) ($req->data->field_required ?? []);
    $optionsRaw = (array) ($req->data->field_options ?? []);

    $allowedTypes = ['text', 'email', 'tel', 'textarea', 'select', 'hidden'];
    $fields = [];
    $seenNames = [];

    foreach ($names as $i => $name) {
        $name = preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string) $name)));
        $label = trim((string) ($labels[$i] ?? ''));
        $type = in_array($types[$i] ?? '', $allowedTypes, true) ? $types[$i] : 'text';

        if ($name === '' || isset($seenNames[$name])) {
            continue;
        }
        if ($type !== 'hidden' && $label === '') {
            continue;
        }
        $seenNames[$name] = true;

        $field = [
            'name' => $name,
            'label' => $label ?: $name,
            'type' => $type,
            'required' => !empty($required[$i]),
        ];

        if ($type === 'select') {
            $options = [];
            $lines = preg_split('/\r\n|\r|\n/', (string) ($optionsRaw[$i] ?? ''));
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                [$value, $optLabel] = array_pad(explode('|', $line, 2), 2, null);
                $options[] = ['value' => trim($value), 'label' => trim($optLabel ?? $value)];
            }
            $field['options'] = $options;
        }

        if ($type === 'hidden') {
            $field['value'] = trim((string) ($optionsRaw[$i] ?? ''));
        }

        $fields[] = $field;
    }

    $stmt = db()->prepare('UPDATE sites SET fields_json = ? WHERE id = ?');
    $stmt->execute([$fields ? json_encode($fields) : null, $id]);

    flash('success', 'Form fields updated. The embed code below now reflects your changes.');
    Flight::redirect('/sites');
});

Flight::route('POST /sites/@id/toggle', function (string $id) {
    require_login();
    csrf_check();
    db()->prepare('UPDATE sites SET active = 1 - active WHERE id = ?')->execute([$id]);
    Flight::redirect('/sites');
});

Flight::route('POST /sites/@id/regenerate', function (string $id) {
    require_login();
    csrf_check();
    db()->prepare('UPDATE sites SET api_key = ? WHERE id = ?')->execute([generate_api_key(), $id]);
    flash('success', 'New API key generated - update the embed code on the site.');
    Flight::redirect('/sites');
});

Flight::route('POST /sites/@id/delete', function (string $id) {
    require_login();
    csrf_check();
    db()->prepare('DELETE FROM sites WHERE id = ?')->execute([$id]);
    flash('success', 'Site and its leads deleted.');
    Flight::redirect('/sites');
});

// --- Connectors ----------------------------------------------------------

/**
 * Builds the type-specific config array from the connector add/edit form,
 * validating per type. Returns [config, errorMessage|null].
 */
function connector_config_from_request($req, string $type): array
{
    if ($type === 'smtp') {
        $config = [
            'host' => trim((string) ($req->data->host ?? '')),
            'port' => (int) ($req->data->port ?? 587),
            'secure' => in_array($req->data->secure ?? 'tls', ['', 'ssl', 'tls'], true) ? $req->data->secure : 'tls',
            'username' => trim((string) ($req->data->username ?? '')),
            'password' => (string) ($req->data->password ?? ''),
            'from_email' => trim((string) ($req->data->from_email ?? '')),
            'from_name' => trim((string) ($req->data->from_name ?? 'MicroCRM')),
            'to' => trim((string) ($req->data->to ?? '')),
            'cc' => trim((string) ($req->data->cc ?? '')),
            'bcc' => trim((string) ($req->data->bcc ?? '')),
        ];
        if ($config['host'] === '' || $config['from_email'] === '' || $config['to'] === '') {
            return [null, 'SMTP needs at least a host, from-address, and recipient.'];
        }
        // `to` accepts one or more comma-separated recipients.
        $recipients = array_values(array_filter(
            array_map('trim', explode(',', $config['to'])),
            fn($e) => $e !== ''
        ));
        if ($recipients === []) {
            return [null, 'SMTP needs at least one recipient.'];
        }
        foreach ($recipients as $rcpt) {
            if (!filter_var($rcpt, FILTER_VALIDATE_EMAIL)) {
                return [null, 'Each SMTP recipient must be a valid email (comma-separated).'];
            }
        }
        $config['to'] = implode(', ', $recipients);
        if (!filter_var($config['from_email'], FILTER_VALIDATE_EMAIL)) {
            return [null, 'SMTP from-address must be a valid email.'];
        }
        // cc / bcc are optional; validate + normalize each when present.
        foreach (['cc', 'bcc'] as $field) {
            $list = array_values(array_filter(
                array_map('trim', explode(',', $config[$field])),
                fn($e) => $e !== ''
            ));
            foreach ($list as $addr) {
                if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    return [null, 'Each ' . strtoupper($field) . ' address must be a valid email (comma-separated).'];
                }
            }
            $config[$field] = implode(', ', $list);
        }
        return [$config, null];
    }

    if ($type === 'webhook') {
        $url = trim((string) ($req->data->url ?? ''));
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            return [null, 'Webhook needs a valid http(s) URL.'];
        }
        return [['url' => $url, 'secret' => trim((string) ($req->data->secret ?? ''))], null];
    }

    return [null, 'Unknown connector type.'];
}

Flight::route('GET /connectors', function () {
    require_login();
    $pdo = db();
    $sites = $pdo->query('SELECT * FROM sites ORDER BY name')->fetchAll();

    $connStmt = $pdo->prepare('SELECT * FROM connectors WHERE site_id = ? ORDER BY id');
    $logStmt = $pdo->prepare(
        'SELECT cl.*, c.name AS connector_name, c.type AS connector_type
         FROM connector_log cl JOIN connectors c ON c.id = cl.connector_id
         WHERE c.site_id = ? ORDER BY cl.created_at DESC LIMIT 8'
    );
    foreach ($sites as &$site) {
        $connStmt->execute([$site['id']]);
        $site['connectors'] = $connStmt->fetchAll();
        $logStmt->execute([$site['id']]);
        $site['recent_log'] = $logStmt->fetchAll();
    }
    unset($site);

    render_view('connectors', ['title' => 'Connectors · MicroCRM', 'sites' => $sites]);
});

Flight::route('POST /connectors', function () {
    require_login();
    csrf_check();
    $req = Flight::request();
    $siteId = (int) ($req->data->site_id ?? 0);
    $type = (string) ($req->data->type ?? '');
    $name = trim((string) ($req->data->name ?? '')) ?: ucfirst($type) . ' connector';
    $sendSpam = !empty($req->data->send_spam) ? 1 : 0;

    [$config, $error] = connector_config_from_request($req, $type);
    if ($error !== null) {
        flash('error', $error);
        Flight::redirect('/connectors');
        return;
    }

    $stmt = db()->prepare(
        'INSERT INTO connectors (site_id, type, name, config_json, send_spam) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$siteId, $type, $name, json_encode($config), $sendSpam]);
    flash('success', 'Connector added.');
    Flight::redirect('/connectors');
});

Flight::route('POST /connectors/@id/update', function (string $id) {
    require_login();
    csrf_check();
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM connectors WHERE id = ?');
    $stmt->execute([$id]);
    $connector = $stmt->fetch();
    if (!$connector) {
        flash('error', 'Connector not found.');
        Flight::redirect('/connectors');
        return;
    }

    $req = Flight::request();
    $type = (string) $connector['type']; // type is immutable once created
    $name = trim((string) ($req->data->name ?? '')) ?: ucfirst($type) . ' connector';
    $sendSpam = !empty($req->data->send_spam) ? 1 : 0;

    [$config, $error] = connector_config_from_request($req, $type);
    if ($error !== null) {
        flash('error', $error);
        Flight::redirect('/connectors');
        return;
    }

    // Blank SMTP password on edit = keep the stored one (never shown in the form).
    if ($type === 'smtp' && ($config['password'] ?? '') === '') {
        $old = json_decode((string) $connector['config_json'], true) ?: [];
        $config['password'] = $old['password'] ?? '';
    }

    $upd = $pdo->prepare('UPDATE connectors SET name = ?, config_json = ?, send_spam = ? WHERE id = ?');
    $upd->execute([$name, json_encode($config), $sendSpam, $id]);
    flash('success', 'Connector updated.');
    Flight::redirect('/connectors');
});

Flight::route('POST /connectors/@id/toggle', function (string $id) {
    require_login();
    csrf_check();
    db()->prepare('UPDATE connectors SET active = 1 - active WHERE id = ?')->execute([$id]);
    Flight::redirect('/connectors');
});

Flight::route('POST /connectors/@id/delete', function (string $id) {
    require_login();
    csrf_check();
    db()->prepare('DELETE FROM connectors WHERE id = ?')->execute([$id]);
    flash('success', 'Connector deleted.');
    Flight::redirect('/connectors');
});

Flight::route('POST /connectors/@id/test', function (string $id) {
    require_login();
    csrf_check();
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM connectors WHERE id = ?');
    $stmt->execute([$id]);
    $connector = $stmt->fetch();
    if (!$connector) {
        flash('error', 'Connector not found.');
        Flight::redirect('/connectors');
        return;
    }
    $siteStmt = $pdo->prepare('SELECT * FROM sites WHERE id = ?');
    $siteStmt->execute([$connector['site_id']]);
    $site = $siteStmt->fetch();

    $sampleLead = [
        'id' => 0, 'site_id' => (int) $connector['site_id'],
        'name' => 'Test Lead', 'email' => 'test@example.com', 'phone' => '+1 555 0100',
        'message' => 'This is a MicroCRM connector test.', 'extra_json' => null,
        'ip_address' => '203.0.113.10', 'country' => 'US', 'referrer' => '',
        'status' => 'new', 'is_spam' => 0, 'spam_reason' => null,
        'created_at' => date('Y-m-d H:i:s'),
    ];
    $ok = \App\Connectors::deliver($pdo, $connector, $site ?: [], $sampleLead);
    if ($ok) {
        flash('success', 'Test delivery succeeded for "' . $connector['name'] . '".');
    } else {
        $last = $pdo->query('SELECT detail FROM connector_log WHERE connector_id = ' . (int) $id . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
        flash('error', 'Test delivery failed: ' . $last);
    }
    Flight::redirect('/connectors');
});

Flight::route('POST /sites/@id/report-email', function (string $id) {
    require_login();
    csrf_check();
    $email = trim((string) (Flight::request()->data->report_email ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Enter a valid report email address, or leave it blank to disable.');
        Flight::redirect('/connectors');
        return;
    }
    db()->prepare('UPDATE sites SET report_email = ? WHERE id = ?')->execute([$email ?: null, $id]);
    flash('success', $email !== '' ? 'Weekly report recipient saved.' : 'Weekly reports disabled for this site.');
    Flight::redirect('/connectors');
});

Flight::route('GET /reports/preview/@id', function (string $id) {
    require_login();
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM sites WHERE id = ?');
    $stmt->execute([$id]);
    $site = $stmt->fetch();
    if (!$site) {
        Flight::halt(404, 'Site not found.');
        return;
    }

    $days = 7;
    $stats = \App\Report::stats($pdo, (int) $site['id'], $days);
    $emailHtml = \App\Report::html($site, $stats, $days);
    $recipient = $site['report_email'] ?: '(no recipient set - reports disabled for this site)';

    // Render the exact email body inside a lightweight "email client" frame.
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Report preview · ' . h($site['name']) . '</title>'
        . '<style>body{margin:0;background:#e9e9ee;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif}'
        . '.bar{background:#14152b;color:#fff;padding:12px 20px;font-size:14px}'
        . '.bar a{color:#a5b4fc;text-decoration:none}'
        . '.meta{max-width:680px;margin:20px auto 0;padding:12px 20px;background:#fff;border:1px solid #ddd;border-radius:10px;font-size:13px;color:#555}'
        . '.frame{max-width:680px;margin:16px auto 40px;padding:28px;background:#fff;border:1px solid #ddd;border-radius:10px}</style>'
        . '</head><body>'
        . '<div class="bar">Weekly report preview &mdash; <a href="/connectors">&laquo; back to Connectors</a></div>'
        . '<div class="meta"><strong>Subject:</strong> ' . h(\App\Report::subject($site, $stats))
        . '<br><strong>To:</strong> ' . h($recipient)
        . '<br><strong>Window:</strong> last ' . $days . ' days (live data)</div>'
        . '<div class="frame">' . $emailHtml . '</div>'
        . '</body></html>';
});

// --- Settings ------------------------------------------------------------

Flight::route('GET /settings', function () {
    require_login();
    $pdo = db();
    $geoipRanges = (int) $pdo->query('SELECT COUNT(*) FROM ip_country')->fetchColumn();
    render_view('settings', [
        'title' => 'Settings · MicroCRM',
        'settings' => settings(),
        'geoipRanges' => $geoipRanges,
    ]);
});

Flight::route('POST /settings', function () {
    require_login();
    csrf_check();
    $pdo = db();
    $req = Flight::request();

    $intKeys = [
        'rate_limit_max' => [1, 1000],
        'rate_limit_window' => [10, 86400],
        'daily_limit_max' => [1, 10000],
        'min_submit_seconds' => [0, 120],
        'max_token_age_seconds' => [60, 86400],
        'spam_retention_days' => [0, 3650],
    ];
    foreach ($intKeys as $key => [$min, $max]) {
        $value = max($min, min($max, (int) ($req->data->$key ?? 0)));
        setting_save($pdo, $key, (string) $value);
    }

    foreach (['check_email_dns', 'check_heuristics', 'check_duplicates', 'flag_direct_api'] as $key) {
        setting_save($pdo, $key, !empty($req->data->$key) ? '1' : '0');
    }

    $countries = strtoupper(preg_replace('/[^a-zA-Z,]/', '', (string) ($req->data->blocked_countries ?? '')));
    $countries = implode(',', array_filter(
        array_map('trim', explode(',', $countries)),
        fn($c) => preg_match('/^[A-Z]{2}$/', $c)
    ));
    setting_save($pdo, 'blocked_countries', $countries);

    flash('success', 'Settings saved.');
    Flight::redirect('/settings');
});

Flight::route('POST /settings/geoip-sync', function () {
    require_login();
    csrf_check();
    [$ok, $result] = geoip_sync(db());
    if ($ok) {
        flash('success', 'GeoIP data updated: ' . number_format($result) . ' IP ranges imported.');
    } else {
        flash('error', 'GeoIP sync failed: ' . $result);
    }
    Flight::redirect('/settings');
});

// --- Spam bulk actions -----------------------------------------------------

Flight::route('POST /leads/@id/not-spam', function (string $id) {
    require_login();
    csrf_check();
    $pdo = db();
    $pdo->prepare("UPDATE leads SET status = 'new', is_spam = 0, spam_reason = NULL WHERE id = ?")->execute([$id]);
    
    $stmt = $pdo->prepare('SELECT * FROM leads WHERE id = ?');
    $stmt->execute([$id]);
    $lead = $stmt->fetch();
    if ($lead) {
        $siteStmt = $pdo->prepare('SELECT * FROM sites WHERE id = ?');
        $siteStmt->execute([$lead['site_id']]);
        $site = $siteStmt->fetch();
        if ($site) {
            \App\Connectors::dispatchLead($pdo, $site, $lead);
        }
    }

    flash('success', 'Lead #' . (int) $id . ' restored to the inbox.');
    $back = (string) (Flight::request()->data->_back ?? '');
    Flight::redirect(str_starts_with($back, '/') && !str_starts_with($back, '//') ? $back : '/leads?status=spam');
});

Flight::route('POST /leads/spam/empty', function () {
    require_login();
    csrf_check();
    $count = db()->exec('DELETE FROM leads WHERE is_spam = 1');
    flash('success', "Deleted {$count} spam lead(s).");
    Flight::redirect('/leads?status=spam');
});

// --- Spam rules --------------------------------------------------------

Flight::route('GET /spam-rules', function () {
    require_login();
    $pdo = db();
    $rules = $pdo->query('SELECT * FROM spam_rules ORDER BY created_at DESC')->fetchAll();

    $hitStmt = $pdo->prepare('SELECT COUNT(*) FROM leads WHERE spam_reason IS NOT NULL AND instr(spam_reason, ?) > 0');
    foreach ($rules as &$rule) {
        $hitStmt->execute(["{$rule['type']}:{$rule['pattern']}"]);
        $rule['hits'] = (int) $hitStmt->fetchColumn();
    }
    unset($rule);

    render_view('spam_rules', ['title' => 'Spam Rules · MicroCRM', 'rules' => $rules]);
});

Flight::route('POST /spam-rules', function () {
    require_login();
    csrf_check();
    $req = Flight::request();
    $type = (string) ($req->data->type ?? '');
    $pattern = trim((string) ($req->data->pattern ?? ''));
    $field = (string) ($req->data->field ?? 'any');
    $action = (string) ($req->data->action ?? 'flag');

    if (!in_array($type, ['keyword', 'email_domain', 'regex'], true) || $pattern === '') {
        flash('error', 'Please choose a rule type and provide a pattern.');
        Flight::redirect('/spam-rules');
        return;
    }
    if ($type === 'regex' && @preg_match($pattern, '') === false) {
        flash('error', 'That regex pattern is invalid.');
        Flight::redirect('/spam-rules');
        return;
    }
    if (!in_array($field, ['name', 'email', 'message', 'any'], true)) {
        $field = 'any';
    }
    if (!in_array($action, ['flag', 'block'], true)) {
        $action = 'flag';
    }

    $stmt = db()->prepare('INSERT INTO spam_rules (type, pattern, field, action) VALUES (?, ?, ?, ?)');
    $stmt->execute([$type, $pattern, $field, $action]);

    flash('success', 'Spam rule added.');
    Flight::redirect('/spam-rules');
});

Flight::route('POST /spam-rules/@id/toggle', function (string $id) {
    require_login();
    csrf_check();
    db()->prepare('UPDATE spam_rules SET active = 1 - active WHERE id = ?')->execute([$id]);
    Flight::redirect('/spam-rules');
});

Flight::route('POST /spam-rules/@id/delete', function (string $id) {
    require_login();
    csrf_check();
    db()->prepare('DELETE FROM spam_rules WHERE id = ?')->execute([$id]);
    flash('success', 'Spam rule deleted.');
    Flight::redirect('/spam-rules');
});
