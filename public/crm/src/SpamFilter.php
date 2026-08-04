<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * All spam-detection logic for the public capture endpoint, in one place so
 * the rules are easy to audit and extend without touching route code.
 */
class SpamFilter
{
    /**
     * Honeypot: a hidden field bots fill in but humans never see or touch.
     */
    public static function checkHoneypot(array $data, string $field = '_hp'): bool
    {
        return trim((string) ($data[$field] ?? '')) !== '';
    }

    /**
     * Every embed snippet (JS widget and plain HTML) includes the honeypot
     * field, so a submission without the key at all didn't come through a
     * real form - it was POSTed straight at the API.
     */
    public static function honeypotFieldMissing(array $data, string $field = '_hp'): bool
    {
        return !array_key_exists($field, $data);
    }

    /**
     * Content heuristics that need no configuration: URLs where they don't
     * belong, link-stuffed messages, and absent/robotic user agents.
     * Returns a list of reason strings (empty = clean).
     */
    public static function checkHeuristics(string $name, string $email, string $message, string $userAgent): array
    {
        $reasons = [];

        if (preg_match('~https?://|www\.~i', $name)) {
            $reasons[] = 'url_in_name';
        }

        if (preg_match_all('~https?://~i', $message) > 2) {
            $reasons[] = 'too_many_links';
        }

        // BBCode/markdown link syntax is a classic comment-spam signature.
        if (preg_match('~\[url=|\[link=~i', $message)) {
            $reasons[] = 'bbcode_links';
        }

        if (trim($userAgent) === '') {
            $reasons[] = 'no_user_agent';
        }

        return $reasons;
    }

    /**
     * DNS sanity check: the email's domain must have an MX or A record.
     * Fails open (returns true) on lookup errors so a DNS hiccup never
     * blocks real leads.
     */
    public static function emailDomainResolves(string $email): bool
    {
        $at = strrchr($email, '@');
        if ($at === false) {
            return true;
        }
        $domain = substr($at, 1);
        if ($domain === '' || !preg_match('/^[a-z0-9.-]+$/i', $domain)) {
            return false;
        }
        $fqdn = rtrim($domain, '.') . '.';
        return @checkdnsrr($fqdn, 'MX') || @checkdnsrr($fqdn, 'A');
    }

    /**
     * Same email or phone already submitted to this site recently - usually
     * a bot hammering the form, occasionally an impatient human, so it flags
     * rather than blocks.
     */
    public static function isDuplicate(PDO $pdo, int $siteId, string $email, string $phone): bool
    {
        $conditions = [];
        $params = [$siteId];
        if ($email !== '') {
            $conditions[] = 'email = ?';
            $params[] = $email;
        }
        if ($phone !== '') {
            $conditions[] = 'phone = ?';
            $params[] = $phone;
        }
        if (!$conditions) {
            return false;
        }
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM leads
             WHERE site_id = ? AND (" . implode(' OR ', $conditions) . ")
               AND created_at >= datetime('now', '-1 day')"
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Verifies the signed timestamp issued by the JS embed widget on page
     * load. Rejects submissions that are suspiciously fast (bot filled the
     * form instantly), tampered with, or replaying a stale token.
     *
     * Returns valid=true when no token was supplied at all, since the no-JS
     * HTML form fallback has no way to carry a freshly-signed token.
     */
    public static function checkTimeTrap(
        ?string $ts,
        ?string $sig,
        string $apiKey,
        string $appKey,
        int $minSeconds,
        int $maxAgeSeconds
    ): array {
        if ($ts === null || $sig === null || $ts === '' || $sig === '') {
            return ['valid' => true, 'reason' => null];
        }

        $expected = hash_hmac('sha256', $apiKey . '|' . $ts, $appKey);
        if (!hash_equals($expected, $sig)) {
            return ['valid' => false, 'reason' => 'invalid_token'];
        }

        $elapsed = time() - (int) $ts;
        if ($elapsed < $minSeconds) {
            return ['valid' => false, 'reason' => 'submitted_too_fast'];
        }
        if ($elapsed > $maxAgeSeconds) {
            return ['valid' => false, 'reason' => 'token_expired'];
        }

        return ['valid' => true, 'reason' => null];
    }

    public static function pruneRateLog(PDO $pdo): void
    {
        // Cheap, run on every request: keep the table from growing forever.
        $pdo->exec("DELETE FROM rate_log WHERE created_at < datetime('now', '-1 day')");
    }

    public static function withinRateLimit(
        PDO $pdo,
        string $ip,
        int $maxPerWindow,
        int $windowSeconds
    ): bool {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM rate_log WHERE ip_address = ? AND created_at >= datetime('now', ?)"
        );
        $stmt->execute([$ip, "-{$windowSeconds} seconds"]);
        return (int) $stmt->fetchColumn() < $maxPerWindow;
    }

    /**
     * Coarser backstop on top of the sliding window: total submissions per
     * IP per day, across all sites. rate_log only retains one day of rows
     * (see pruneRateLog), so a plain count is enough.
     */
    public static function withinDailyLimit(PDO $pdo, string $ip, int $maxPerDay): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM rate_log WHERE ip_address = ?');
        $stmt->execute([$ip]);
        return (int) $stmt->fetchColumn() < $maxPerDay;
    }

    public static function logRequest(PDO $pdo, string $ip, int $siteId): void
    {
        $stmt = $pdo->prepare('INSERT INTO rate_log (ip_address, site_id) VALUES (?, ?)');
        $stmt->execute([$ip, $siteId]);
    }

    /**
     * Runs the admin-editable keyword / email_domain / regex rules against
     * a candidate lead. Returns the first match (rules are evaluated in
     * insertion order).
     */
    public static function evaluateContent(PDO $pdo, array $lead): array
    {
        $rules = $pdo->query('SELECT * FROM spam_rules WHERE active = 1')->fetchAll();

        $fields = [
            'name' => (string) ($lead['name'] ?? ''),
            'email' => (string) ($lead['email'] ?? ''),
            'message' => (string) ($lead['message'] ?? ''),
        ];

        foreach ($rules as $rule) {
            $targets = $rule['field'] === 'any'
                ? $fields
                : [$rule['field'] => $fields[$rule['field']] ?? ''];

            foreach ($targets as $fieldName => $value) {
                if ($value === '') {
                    continue;
                }

                $match = match ($rule['type']) {
                    'keyword' => stripos($value, $rule['pattern']) !== false,
                    'email_domain' => $fieldName === 'email' && self::emailDomainMatches($value, $rule['pattern']),
                    'regex' => self::regexMatches($value, $rule['pattern']),
                    default => false,
                };

                if ($match) {
                    return [
                        'flagged' => true,
                        'block' => $rule['action'] === 'block',
                        'reason' => "{$rule['type']}:{$rule['pattern']}",
                    ];
                }
            }
        }

        return ['flagged' => false, 'block' => false, 'reason' => null];
    }

    private static function emailDomainMatches(string $email, string $pattern): bool
    {
        $at = strrchr($email, '@');
        if ($at === false) {
            return false;
        }
        return strtolower(substr($at, 1)) === strtolower(trim($pattern));
    }

    private static function regexMatches(string $value, string $pattern): bool
    {
        // Rules come from the trusted admin panel, but a malformed pattern
        // shouldn't be able to 500 the public capture endpoint.
        return @preg_match($pattern, $value) === 1;
    }
}
