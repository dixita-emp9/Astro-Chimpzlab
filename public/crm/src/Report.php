<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Builds the per-site weekly lead digest - both the stat aggregation and the
 * HTML email body. Shared by bin/send-reports.php (delivery) and the admin
 * "/reports/preview" route (in-browser preview) so the preview is always
 * byte-for-byte what recipients receive.
 */
class Report
{
    /** @return array{total:int, spam:int, by_status:array, recent:array} */
    public static function stats(PDO $pdo, int $siteId, int $days): array
    {
        $window = "-{$days} days";

        $total = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE site_id = ? AND is_spam = 0 AND created_at >= datetime('now', ?)");
        $total->execute([$siteId, $window]);

        $spam = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE site_id = ? AND is_spam = 1 AND created_at >= datetime('now', ?)");
        $spam->execute([$siteId, $window]);

        $byStatus = $pdo->prepare(
            "SELECT status, COUNT(*) AS n FROM leads WHERE site_id = ? AND is_spam = 0 AND created_at >= datetime('now', ?) GROUP BY status"
        );
        $byStatus->execute([$siteId, $window]);

        $recent = $pdo->prepare(
            "SELECT name, email, phone, created_at FROM leads
             WHERE site_id = ? AND is_spam = 0 AND created_at >= datetime('now', ?)
             ORDER BY created_at DESC LIMIT 15"
        );
        $recent->execute([$siteId, $window]);

        return [
            'total' => (int) $total->fetchColumn(),
            'spam' => (int) $spam->fetchColumn(),
            'by_status' => $byStatus->fetchAll(PDO::FETCH_KEY_PAIR),
            'recent' => $recent->fetchAll(),
        ];
    }

    public static function subject(array $site, array $stats): string
    {
        return "[{$site['name']}] Weekly lead report - {$stats['total']} new lead(s)";
    }

    public static function html(array $site, array $stats, int $days): string
    {
        $esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $accent = '#4f46e5';

        $statusColors = [
            'new' => '#1d4ed8', 'contacted' => '#b45309', 'qualified' => '#6d28d9',
            'converted' => '#15803d', 'archived' => '#6b7280', 'spam' => '#b91c1c',
        ];
        $statusRows = '';
        foreach ($stats['by_status'] as $status => $n) {
            $color = $statusColors[$status] ?? '#111';
            $statusRows .= '<tr>'
                . '<td style="padding:6px 12px;border-bottom:1px solid #f0f0f2">'
                . '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' . $color . ';margin-right:8px"></span>'
                . $esc(ucfirst($status)) . '</td>'
                . '<td style="padding:6px 12px;border-bottom:1px solid #f0f0f2;font-weight:700;text-align:right">' . (int) $n . '</td></tr>';
        }
        if ($statusRows === '') {
            $statusRows = '<tr><td colspan="2" style="padding:6px 12px;color:#888">No leads this period.</td></tr>';
        }

        $recentRows = '';
        foreach ($stats['recent'] as $i => $l) {
            $bg = $i % 2 ? '#fafafc' : '#ffffff';
            $recentRows .= '<tr style="background:' . $bg . '">'
                . '<td style="padding:8px 12px">' . $esc($l['name'] ?: '(no name)') . '</td>'
                . '<td style="padding:8px 12px;color:' . $accent . '">' . $esc($l['email']) . '</td>'
                . '<td style="padding:8px 12px">' . $esc($l['phone']) . '</td>'
                . '<td style="padding:8px 12px;color:#999;white-space:nowrap">' . $esc($l['created_at']) . '</td>'
                . '</tr>';
        }
        if ($recentRows === '') {
            $recentRows = '<tr><td colspan="4" style="padding:8px 12px;color:#888">No leads this period.</td></tr>';
        }

        $tile = fn(int $n, string $label, string $color) =>
            '<td style="padding:0 8px 0 0">'
            . '<div style="border:1px solid #ececf0;border-top:3px solid ' . $color . ';border-radius:10px;padding:16px 22px">'
            . '<div style="font-size:30px;font-weight:800;color:' . $color . '">' . $n . '</div>'
            . '<div style="color:#666;font-size:13px">' . $label . '</div></div></td>';

        return '<div style="font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:640px;color:#14152b">'
            . '<div style="background:' . $accent . ';color:#fff;border-radius:12px;padding:20px 24px;margin-bottom:22px">'
            . '<div style="font-size:20px;font-weight:800;letter-spacing:-.02em">' . $esc($site['name']) . '</div>'
            . '<div style="opacity:.85;font-size:13px">Weekly lead report · last ' . (int) $days . ' days</div></div>'
            . '<table style="border-collapse:separate;margin-bottom:24px"><tr>'
            . $tile($stats['total'], 'New leads', $accent)
            . $tile($stats['spam'], 'Blocked spam', '#b91c1c')
            . '</tr></table>'
            . '<h3 style="margin:0 0 8px;font-size:15px">Pipeline</h3>'
            . '<table style="border-collapse:collapse;border:1px solid #ececf0;border-radius:8px;margin-bottom:24px;min-width:240px">' . $statusRows . '</table>'
            . '<h3 style="margin:0 0 8px;font-size:15px">Recent leads</h3>'
            . '<table style="border-collapse:collapse;width:100%;border:1px solid #ececf0;border-radius:8px;overflow:hidden">'
            . '<tr style="background:#14152b;color:#fff">'
            . '<th style="padding:8px 12px;text-align:left;font-size:12px">Name</th>'
            . '<th style="padding:8px 12px;text-align:left;font-size:12px">Email</th>'
            . '<th style="padding:8px 12px;text-align:left;font-size:12px">Phone</th>'
            . '<th style="padding:8px 12px;text-align:left;font-size:12px">When</th></tr>'
            . $recentRows . '</table>'
            . '<p style="color:#999;font-size:12px;margin-top:24px">Sent by MicroCRM</p>'
            . '</div>';
    }
}
