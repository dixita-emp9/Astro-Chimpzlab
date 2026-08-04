<?php

/**
 * HTML templates for lead notification and thank-you emails.
 */

function leadEmailHtml(string $siteName, array $lead, string $logo = '', array $extraFields = []): string
{
    $esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    if ($logo === '') $logo = 'https://www.chimpzlab.com/asset/chimpzlab-white.png';

    $rows = '';
    $fields = [
        'Name' => $lead['name'] ?? '',
        'Email' => $lead['email'] ?? '',
        'Phone' => $lead['phone'] ?? '',
    ];
    foreach ($fields as $label => $value) {
        if ($value === '') continue;
        $rows .= '<tr><td style="padding:10px 16px;background:#f9fafb;color:#0a0a0a;font-weight:700;white-space:nowrap;font-size:12px;text-transform:uppercase;letter-spacing:1px">' . $esc($label)
            . '</td><td style="padding:10px 16px;color:#1f2937;font-size:14px;line-height:1.6">' . nl2br($esc($value)) . '</td></tr>';
    }
    foreach ($extraFields as $label => $value) {
        if ($value === '') continue;
        $rows .= '<tr><td style="padding:10px 16px;background:#f9fafb;color:#0a0a0a;font-weight:700;white-space:nowrap;font-size:12px;text-transform:uppercase;letter-spacing:1px">' . $esc($label)
            . '</td><td style="padding:10px 16px;color:#1f2937;font-size:14px;line-height:1.6">' . nl2br($esc($value)) . '</td></tr>';
    }
    if (($lead['message'] ?? '') !== '') {
        $rows .= '<tr><td style="padding:10px 16px;background:#f9fafb;color:#0a0a0a;font-weight:700;white-space:nowrap;font-size:12px;text-transform:uppercase;letter-spacing:1px">Message</td>'
            . '<td style="padding:10px 16px;color:#1f2937;font-size:14px;line-height:1.6">' . nl2br($esc($lead['message'])) . '</td></tr>';
    }
    $rows .= '<tr><td style="padding:10px 16px;background:#f9fafb;color:#0a0a0a;font-weight:700;white-space:nowrap;font-size:12px;text-transform:uppercase;letter-spacing:1px">Received</td>'
        . '<td style="padding:10px 16px;color:#1f2937;font-size:14px;line-height:1.6">' . $esc(date('r')) . '</td></tr>';

    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>New Lead | ' . $esc($siteName) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#0a0a0a;font-family:Manrope,-apple-system,Segoe UI,Roboto,Arial,sans-serif">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0a0a0a"><tr><td align="left" style="padding:36px 48px"><a href="https://www.chimpzlab.com"><img src="' . $logo . '" alt="ChimpzLab" width="180" height="36" style="display:block;width:180px;height:36px;border:0"></a></td></tr></table>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff"><tr><td style="padding:48px">'
        . '<h1 style="margin:0 0 8px;font-size:28px;line-height:1.2;color:#0a0a0a;font-weight:800">New Lead</h1>'
        . '<p style="margin:0 0 28px;color:#6b7280;font-size:14px;line-height:1.6">New form submission from ' . $esc($siteName) . '</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #e5e7eb">' . $rows . '</table>'
        . '<div style="margin-top:28px;padding-top:24px;border-top:1px solid #e5e7eb">'
        . '<p style="margin:0 0 8px;color:#0a0a0a;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px">Disclaimer</p>'
        . '<p style="margin:0 0 8px;color:#9ca3af;font-size:11px;line-height:1.6">This email and any attachments are intended solely for the recipient(s) and may contain confidential or privileged information. If you are not the intended recipient, please notify the sender and delete this message. The views expressed are those of the sender and may not reflect those of Chimpzlab. Please ensure attachments are virus-checked before opening. Chimpzlab is not liable for any virus-related damages. Visit us at www.chimpzlab.com.</p>'

        . '</div>'
        . '</td></tr></table>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0a0a0a"><tr><td align="center" style="padding:28px 48px"><p style="margin:0;color:#9ca3af;font-size:12px;letter-spacing:1px;text-transform:uppercase">&copy; ' . date('Y') . ' ChimpzLab. All rights reserved.</p></td></tr></table>'
        . '</body></html>';
}

/**
 * Builds the themed thank-you email sent to the submitter.
 * Full-width, single-line HTML document using the site logo.
 */
function thankYouEmailHtml(string $siteName, string $submitterName, string $logo = ''): string
{
    $esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $firstName = trim(explode(' ', trim($submitterName))[0] ?? '');
    $greeting = $firstName !== '' ? 'Dear ' . $esc($firstName) . ',' : 'Dear Sir/Madam,';
    if ($logo === '') $logo = 'https://www.chimpzlab.com/asset/chimpzlab-white.png';

    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Thank You | ' . $esc($siteName) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#0a0a0a;font-family:Manrope,-apple-system,Segoe UI,Roboto,Arial,sans-serif">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0a0a0a"><tr><td align="left" style="padding:36px 48px"><a href="https://www.chimpzlab.com"><img src="' . $logo . '" alt="ChimpzLab" width="180" height="36" style="display:block;width:180px;height:36px;border:0"></a></td></tr></table>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff"><tr><td style="padding:56px 48px">'
        . '<h1 style="margin:0 0 24px;font-size:34px;line-height:1.2;color:#0a0a0a;font-weight:800">Thank You for Reaching Out.</h1>'
        . '<p style="margin:0 0 16px;color:#1f2937;font-size:16px;line-height:1.7">' . $greeting . '</p>'
        . '<p style="margin:0 0 16px;color:#1f2937;font-size:16px;line-height:1.7">We have received your enquiry. Our team will review it and get back to you within <strong>24 working hours</strong>.</p>'
        . '<p style="margin:0;color:#1f2937;font-size:16px;line-height:1.7">While you wait, explore our work at <a href="https://www.chimpzlab.com" style="color:#0a0a0a;font-weight:700;text-decoration:underline">www.chimpzlab.com</a>.</p>'
        . '</td></tr></table>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0a0a0a"><tr><td align="center" style="padding:28px 48px"><p style="margin:0;color:#9ca3af;font-size:12px;letter-spacing:1px;text-transform:uppercase">&copy; ' . date('Y') . ' ChimpzLab. All rights reserved.</p></td></tr></table>'
        . '</body></html>';
}
