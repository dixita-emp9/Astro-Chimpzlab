<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Minimal, dependency-free SMTP client - enough to deliver lead
 * notifications and weekly reports through a configured mail server
 * (host/port/credentials), keeping MicroCRM's "copy files, no services"
 * deployment story intact.
 *
 * Supports implicit TLS (secure=ssl, usually port 465), STARTTLS
 * (secure=tls, usually port 587), and unencrypted (secure='', port 25).
 * Authentication is AUTH LOGIN when a username is supplied.
 */
class Mailer
{
    /**
     * @param array $cfg host, port, secure ('', 'ssl', 'tls'), username,
     *                    password, from_email, from_name
     * @throws RuntimeException on any protocol/connection failure
     */
    public static function send(array $cfg, string $toEmail, string $subject, string $htmlBody, array $inline = []): void
    {
        $host = (string) ($cfg['host'] ?? '');
        $port = (int) ($cfg['port'] ?? 587);
        $secure = (string) ($cfg['secure'] ?? 'tls');
        $username = (string) ($cfg['username'] ?? '');
        $password = (string) ($cfg['password'] ?? '');
        $fromEmail = (string) ($cfg['from_email'] ?? $username);
        $fromName = (string) ($cfg['from_name'] ?? 'MicroCRM');

        // to / cc / bcc each may be a comma-separated list.
        $recipients = self::splitRecipients($toEmail);
        $cc = self::splitRecipients((string) ($cfg['cc'] ?? ''));
        $bcc = self::splitRecipients((string) ($cfg['bcc'] ?? ''));

        if ($host === '' || $fromEmail === '' || $recipients === []) {
            throw new RuntimeException('SMTP config incomplete (host/from/to required).');
        }

        // Envelope = everyone who receives it (bcc included, deduped).
        $envelope = array_values(array_unique(array_merge($recipients, $cc, $bcc)));

        $transport = $secure === 'ssl' ? "ssl://{$host}" : $host;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $conn = @stream_socket_client(
            "{$transport}:{$port}",
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT,
            $ctx
        );
        if (!$conn) {
            throw new RuntimeException("Connect failed: {$errstr} ({$errno})");
        }
        stream_set_timeout($conn, 15);

        try {
            self::expect($conn, 220);

            $ehloHost = self::ehloName();
            self::cmd($conn, "EHLO {$ehloHost}", 250);

            if ($secure === 'tls') {
                self::cmd($conn, 'STARTTLS', 220);
                if (!@stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('STARTTLS negotiation failed.');
                }
                self::cmd($conn, "EHLO {$ehloHost}", 250);
            }

            if ($username !== '') {
                self::cmd($conn, 'AUTH LOGIN', 334);
                self::cmd($conn, base64_encode($username), 334);
                self::cmd($conn, base64_encode($password), 235);
            }

            self::cmd($conn, "MAIL FROM:<{$fromEmail}>", 250);
            foreach ($envelope as $rcpt) {
                self::cmd($conn, "RCPT TO:<{$rcpt}>", [250, 251]);
            }
            self::cmd($conn, 'DATA', 354);

            // Cc is shown in headers; Bcc is intentionally omitted.
            $replyTo = (string) ($cfg['reply_to'] ?? '');
            $boundary = '----=_Part_' . bin2hex(random_bytes(10));
            $headers = self::buildHeaders($fromEmail, $fromName, $recipients, $cc, $subject, $replyTo, $boundary, $inline !== []);
            $body = self::buildBody($htmlBody, $boundary, $inline);
            $message = $headers . self::dotStuff($body) . "\r\n.";
            self::cmd($conn, $message, 250);

            self::cmd($conn, 'QUIT', [221], false);
        } finally {
            @fclose($conn);
        }
    }

    /** Splits a comma-separated recipient string into a clean list. */
    private static function splitRecipients(string $raw): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            fn($e) => $e !== ''
        ));
    }

    private static function buildHeaders(string $fromEmail, string $fromName, array $recipients, array $cc, string $subject, string $replyTo, string $boundary, bool $related): string
    {
        $encodedName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $toHeader = implode(', ', array_map(fn($e) => "<{$e}>", $recipients));
        $domain = strstr($fromEmail, '@') !== false ? substr(strstr($fromEmail, '@'), 1) : 'localhost';
        $lines = [
            "Date: " . date('r'),
            "From: {$encodedName} <{$fromEmail}>",
            "To: {$toHeader}",
        ];
        if ($replyTo !== '') {
            $lines[] = 'Reply-To: <' . $replyTo . '>';
        }
        if ($cc !== []) {
            $lines[] = 'Cc: ' . implode(', ', array_map(fn($e) => "<{$e}>", $cc));
        }
        $type = $related ? 'multipart/related' : 'multipart/alternative';
        $lines = array_merge($lines, [
            "Subject: {$encodedSubject}",
            "MIME-Version: 1.0",
            "Content-Type: {$type}; boundary=\"{$boundary}\"",
            "Message-ID: <" . bin2hex(random_bytes(12)) . "@{$domain}>",
            "X-Mailer: " . self::mailerName(),
        ]);
        return implode("\r\n", $lines) . "\r\n\r\n";
    }

    private static function mailerName(): string
    {
        return (defined('APP_NAME') ? (string) APP_NAME : 'MicroCRM') . '/1.0 (SMTP)';
    }

    private static function buildBody(string $html, string $boundary, array $inline): string
    {
        $plain = self::htmlToPlain($html);
        $altBoundary = $boundary . '_alt';
        $parts = [];

        $parts[] = "--{$boundary}";
        $parts[] = 'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"';
        $parts[] = '';
        $parts[] = "--{$altBoundary}";
        $parts[] = 'Content-Type: text/plain; charset=UTF-8';
        $parts[] = 'Content-Transfer-Encoding: base64';
        $parts[] = '';
        $parts[] = self::base64Lines($plain);
        $parts[] = "--{$altBoundary}";
        $parts[] = 'Content-Type: text/html; charset=UTF-8';
        $parts[] = 'Content-Transfer-Encoding: base64';
        $parts[] = '';
        $parts[] = self::base64Lines($html);
        $parts[] = "--{$altBoundary}--";

        foreach ($inline as $att) {
            $data = @file_get_contents($att['file'] ?? '');
            if ($data === false) continue;
            $mime = $att['mime'] ?? 'application/octet-stream';
            $cid = $att['cid'] ?? 'logo';
            $name = basename($att['file']);
            $parts[] = "--{$boundary}";
            $parts[] = 'Content-Type: ' . $mime . '; name="' . $name . '"';
            $parts[] = 'Content-Transfer-Encoding: base64';
            $parts[] = 'Content-ID: <' . $cid . '>';
            $parts[] = 'Content-Disposition: inline; filename="' . $name . '"';
            $parts[] = '';
            $parts[] = self::base64Lines($data);
        }

        $parts[] = "--{$boundary}--";
        return implode("\r\n", $parts);
    }

    private static function base64Lines(string $s): string
    {
        return rtrim(chunk_split(base64_encode($s), 76, "\r\n"));
    }

    private static function htmlToPlain(string $html): string
    {
        $text = preg_replace('#<br\s*/?>#i', "\n", $html);
        $text = preg_replace('#</(p|div|tr|h[1-6]|li|table)>#i', "\n", $text);
        $text = preg_replace('#<[^>]+>#', '', $text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace("/\n\s*\n+/", "\n\n", $text);
        return trim($text);
    }

    /** SMTP requires a leading dot on a line to be doubled. */
    private static function dotStuff(string $body): string
    {
        $body = preg_replace("/\r\n|\r|\n/", "\r\n", $body);
        return preg_replace('/^\./m', '..', $body);
    }

    private static function ehloName(): string
    {
        $host = $_SERVER['SERVER_NAME'] ?? (gethostname() ?: 'localhost');
        return preg_match('/^[a-z0-9.\-]+$/i', $host) ? $host : 'localhost';
    }

    private static function cmd($conn, string $line, $expected, bool $read = true): void
    {
        fwrite($conn, $line . "\r\n");
        if ($read) {
            self::expect($conn, $expected);
        }
    }

    /** @param int|int[] $expected */
    private static function expect($conn, $expected): void
    {
        $expected = (array) $expected;
        $response = '';
        // Read the (possibly multi-line) reply; continuation lines have a
        // hyphen after the code, the final line a space.
        while (($line = fgets($conn, 515)) !== false) {
            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr(ltrim($response), 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new RuntimeException('SMTP error: ' . trim($response));
        }
    }
}
