<?php

declare(strict_types=1);

namespace App;

use PDO;
use Throwable;

/**
 * Per-site outbound delivery: forwards captured leads to each of a site's
 * active connectors (SMTP email, webhook POST). Every attempt is recorded in
 * connector_log so failures are visible in the admin without breaking capture.
 */
class Connectors
{
    /** Fetch active connectors for a site. */
    public static function forSite(PDO $pdo, int $siteId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM connectors WHERE site_id = ? AND active = 1 ORDER BY id');
        $stmt->execute([$siteId]);
        return $stmt->fetchAll();
    }

    /**
     * Dispatches a freshly-captured lead to all matching connectors. Never
     * throws - any connector error is logged and swallowed so lead capture
     * always succeeds.
     *
     * @param array $lead  associative lead row (must include id, site_id, is_spam)
     * @param array $site  site row (for name/context in messages)
     */
    public static function dispatchLead(PDO $pdo, array $site, array $lead): void
    {
        $isSpam = !empty($lead['is_spam']);
        
        $alreadySent = [];
        if (!empty($lead['id'])) {
            $stmt = $pdo->prepare('SELECT connector_id FROM connector_log WHERE lead_id = ? AND ok = 1');
            $stmt->execute([$lead['id']]);
            $alreadySent = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        foreach (self::forSite($pdo, (int) $lead['site_id']) as $connector) {
            if ($isSpam && !$connector['send_spam']) {
                continue;
            }
            if (in_array((int)$connector['id'], $alreadySent, false)) {
                continue;
            }
            self::deliver($pdo, $connector, $site, $lead);
        }
    }

    /** Runs one connector against one lead and logs the outcome. */
    public static function deliver(PDO $pdo, array $connector, array $site, array $lead): bool
    {
        $config = json_decode((string) $connector['config_json'], true) ?: [];
        try {
            if ($connector['type'] === 'smtp') {
                self::deliverSmtp($config, $site, $lead);
            } elseif ($connector['type'] === 'webhook') {
                self::deliverWebhook($config, $site, $lead);
            } else {
                throw new \RuntimeException("Unknown connector type: {$connector['type']}");
            }
            self::log($pdo, (int) $connector['id'], $lead['id'] ?? null, true, 'Delivered');
            return true;
        } catch (Throwable $e) {
            self::log($pdo, (int) $connector['id'], $lead['id'] ?? null, false, $e->getMessage());
            return false;
        }
    }

    private static function deliverSmtp(array $config, array $site, array $lead): void
    {
        $to = trim((string) ($config['to'] ?? ''));
        if ($to === '') {
            throw new \RuntimeException('SMTP connector has no recipient (to) configured.');
        }
        $subject = sprintf(
            '[%s] New lead: %s',
            $site['name'] ?? 'MicroCRM',
            $lead['name'] ?: ($lead['email'] ?: 'no name')
        );
        Mailer::send($config, $to, $subject, self::leadHtml($site, $lead));
    }

    private static function deliverWebhook(array $config, array $site, array $lead): void
    {
        $url = trim((string) ($config['url'] ?? ''));
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('Webhook connector has an invalid URL.');
        }

        $payload = json_encode([
            'site' => $site['name'] ?? null,
            'site_id' => $lead['site_id'] ?? null,
            'lead' => self::leadPayload($lead),
            'sent_at' => date('c'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $headers = ['Content-Type: application/json', 'User-Agent: MicroCRM-Webhook/1.0'];
        // Optional shared secret → HMAC signature header the receiver can verify.
        $secret = (string) ($config['secret'] ?? '');
        if ($secret !== '') {
            $headers[] = 'X-MicroCRM-Signature: sha256=' . hash_hmac('sha256', $payload, $secret);
        }

        self::httpPost($url, $payload, $headers);
    }

    /** POST helper: prefers cURL, falls back to a stream context. */
    private static function httpPost(string $url, string $body, array $headers): void
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $resp = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($resp === false) {
                throw new \RuntimeException("Webhook request failed: {$err}");
            }
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException("Webhook returned HTTP {$status}");
            }
            return;
        }

        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => 15,
            'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) {
            throw new \RuntimeException('Webhook request failed (no response).');
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("Webhook returned HTTP {$status}");
        }
    }

    /** Structured lead data for webhook consumers. */
    public static function leadPayload(array $lead): array
    {
        $extra = !empty($lead['extra_json']) ? json_decode((string) $lead['extra_json'], true) : null;
        return [
            'id' => $lead['id'] ?? null,
            'name' => $lead['name'] ?? null,
            'email' => $lead['email'] ?? null,
            'phone' => $lead['phone'] ?? null,
            'message' => $lead['message'] ?? null,
            'status' => $lead['status'] ?? null,
            'is_spam' => (bool) ($lead['is_spam'] ?? false),
            'spam_reason' => $lead['spam_reason'] ?? null,
            'country' => $lead['country'] ?? null,
            'ip_address' => $lead['ip_address'] ?? null,
            'referrer' => $lead['referrer'] ?? null,
            'extra' => $extra,
            'created_at' => $lead['created_at'] ?? null,
        ];
    }

    /** Human-readable HTML email body for a single lead. */
    public static function leadHtml(array $site, array $lead): string
    {
        $esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $rows = '';
        $add = function (string $label, $value) use (&$rows, $esc) {
            if ($value === null || $value === '') {
                return;
            }
            $rows .= '<tr><td style="padding:6px 12px;color:#666;white-space:nowrap">' . $esc($label)
                . '</td><td style="padding:6px 12px;font-weight:600">' . nl2br($esc($value)) . '</td></tr>';
        };

        $add('Name', $lead['name'] ?? '');
        $add('Email', $lead['email'] ?? '');
        $add('Phone', $lead['phone'] ?? '');
        $add('Message', $lead['message'] ?? '');

        $extra = !empty($lead['extra_json']) ? json_decode((string) $lead['extra_json'], true) : null;
        if (is_array($extra)) {
            foreach ($extra as $k => $v) {
                if (is_scalar($v)) {
                    $add(ucwords(str_replace('_', ' ', (string) $k)), (string) $v);
                }
            }
        }
        $add('Country', $lead['country'] ?? '');
        $add('Source IP', $lead['ip_address'] ?? '');
        $add('Received', $lead['created_at'] ?? '');
        if (!empty($lead['is_spam'])) {
            $add('⚠ Flagged spam', $lead['spam_reason'] ?? 'yes');
        }

        return '<div style="font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:560px">'
            . '<h2 style="margin:0 0 4px">New lead - ' . $esc($site['name'] ?? 'MicroCRM') . '</h2>'
            . '<p style="color:#666;margin:0 0 16px">Captured by MicroCRM</p>'
            . '<table style="border-collapse:collapse;width:100%;border:1px solid #eee">' . $rows . '</table>'
            . '</div>';
    }

    public static function log(PDO $pdo, int $connectorId, $leadId, bool $ok, string $detail): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO connector_log (connector_id, lead_id, ok, detail) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$connectorId, $leadId, $ok ? 1 : 0, mb_substr($detail, 0, 500)]);
    }
}
