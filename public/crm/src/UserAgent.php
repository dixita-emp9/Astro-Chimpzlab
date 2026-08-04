<?php

declare(strict_types=1);

namespace App;

/**
 * Lightweight user-agent parser - turns the raw UA string already stored on
 * every lead into human-readable device / OS / browser labels for the admin
 * UI. Heuristic and best-effort; no external service or database.
 */
class UserAgent
{
    /** @return array{device:string, os:string, browser:string, bot:bool} */
    public static function parse(?string $ua): array
    {
        $ua = trim((string) $ua);
        if ($ua === '') {
            return ['device' => 'Unknown', 'os' => 'Unknown', 'browser' => 'Unknown', 'bot' => false];
        }

        $bot = (bool) preg_match('/bot|crawl|spider|slurp|curl|wget|python|http-client|headless|phantom/i', $ua);

        // OS
        $os = 'Unknown';
        foreach ([
            '/windows nt 10/i' => 'Windows 10/11',
            '/windows nt 6\.3/i' => 'Windows 8.1',
            '/windows/i' => 'Windows',
            '/iphone|ipad|ipod/i' => 'iOS',
            '/mac os x/i' => 'macOS',
            '/android/i' => 'Android',
            '/linux/i' => 'Linux',
        ] as $re => $label) {
            if (preg_match($re, $ua)) { $os = $label; break; }
        }

        // Browser (order matters: Edge/Chrome before Safari)
        $browser = 'Unknown';
        foreach ([
            '/edg(e|a|ios)?\//i' => 'Edge',
            '/opr\/|opera/i' => 'Opera',
            '/samsungbrowser/i' => 'Samsung Internet',
            '/chrome|crios/i' => 'Chrome',
            '/firefox|fxios/i' => 'Firefox',
            '/safari/i' => 'Safari',
        ] as $re => $label) {
            if (preg_match($re, $ua)) { $browser = $label; break; }
        }

        // Device class
        if (preg_match('/ipad|tablet/i', $ua)) {
            $device = 'Tablet';
        } elseif (preg_match('/mobi|iphone|android.*mobile/i', $ua)) {
            $device = 'Mobile';
        } elseif ($bot) {
            $device = 'Bot / script';
        } else {
            $device = 'Desktop';
        }

        return ['device' => $device, 'os' => $os, 'browser' => $browser, 'bot' => $bot];
    }

    /**
     * Derives a marketing "source" for a lead from its UTM parameters (if the
     * form captured them) or the referrer host, for at-a-glance attribution.
     */
    public static function source(?array $extra, ?string $referrer): string
    {
        $extra = $extra ?: [];
        $utmSource = trim((string) ($extra['utm_source'] ?? ''));
        $utmMedium = trim((string) ($extra['utm_medium'] ?? ''));
        if ($utmSource !== '') {
            return $utmMedium !== '' ? "{$utmSource} / {$utmMedium}" : $utmSource;
        }

        $referrer = trim((string) $referrer);
        if ($referrer === '') {
            return 'Direct / unknown';
        }
        $host = parse_url($referrer, PHP_URL_HOST) ?: $referrer;
        $host = preg_replace('/^www\./', '', $host);

        foreach ([
            'google' => 'Google', 'bing' => 'Bing', 'duckduckgo' => 'DuckDuckGo',
            'facebook' => 'Facebook', 'fb.com' => 'Facebook', 'instagram' => 'Instagram',
            'linkedin' => 'LinkedIn', 't.co' => 'X/Twitter', 'twitter' => 'X/Twitter',
            'youtube' => 'YouTube', 'reddit' => 'Reddit',
        ] as $needle => $label) {
            if (str_contains($host, $needle)) {
                return $label;
            }
        }
        return $host;
    }
}
