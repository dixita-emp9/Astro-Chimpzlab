<?php
// Dynamic sitemap.xml — always mirrors the WordPress-published blogs.
//
// A static sitemap.xml cannot react to WP publish/draft actions, so this
// script serves /sitemap.xml instead (see the rewrite rule in .htaccess and
// local-router.php for `php -S`). Blog URLs are fetched live from the WP REST
// API, which never exposes drafts, pending or private posts to unauthenticated
// requests: publishing a post adds its URL, drafting/trashing removes it —
// automatically, with no rebuild or manual step.
//
// Freshness: output is cached to sitemap.cache.xml for SITEMAP_TTL seconds
// (bots re-read the sitemap on their own schedule anyway). On WP failure the
// last good cache is served; with no cache yet, static URLs alone are served.
// This script never returns a 5xx for sitemap consumers.

define('SITEMAP_TTL', 1800); // 30 minutes
define('SITE_URL', 'https://chimpzlab.com');
define('WP_REST', 'https://chimpzlab.com/chimpzlab-old/?rest_route=/wp/v2/insights');
define('WP_PRETTY', 'https://chimpzlab.com/chimpzlab-old/wp-json/wp/v2/insights');
define('CACHE_FILE', __DIR__ . '/sitemap.cache.xml');

// Static (non-blog) URLs — curated list, unchanged by WP activity.
// Format: array(loc, lastmod, changefreq, priority).
$STATIC_URLS = array(
        array('https://chimpzlab.com/', '2026-08-17T12:45:17+00:00', 'daily', '1.0000'),
        array('https://chimpzlab.com/services/', '2026-08-17T12:45:18+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/services/reputation-communications/', '2026-08-17T12:45:29+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/services/visibility-search/', '2026-08-17T12:45:29+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/services/creative/', '2026-08-17T12:45:29+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/services/demand-generation/', '2026-08-17T12:45:29+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/services/digital-experiences/', '2026-08-17T12:45:29+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/case-studies/', '2026-08-17T12:45:17+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/about/', '2026-08-17T12:45:17+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/insights/', '2026-08-17T12:45:17+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/booking-form/', '2026-08-17T12:45:17+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/services/reputation-communications/thought-leadership-agency-in-thane/', '2026-08-17T12:45:31+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/services/reputation-communications/public-relations-agency-in-thane/', '2026-08-17T12:45:31+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/services/reputation-communications/corporate-communications-agency-in-thane/', '2026-08-17T12:45:31+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/services/visibility-search/seo-aeo-agency-in-thane/', '2026-08-17T12:45:32+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/services/visibility-search/social-media-marketing-agency-in-thane/', '2026-08-17T12:45:32+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/services/visibility-search/content-writing-services-in-thane/', '2026-08-17T12:45:32+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/services/visibility-search/influencer-marketing-agency-in-thane/', '2026-08-17T12:45:32+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/services/creative/branding-agency-in-thane/', '2026-08-17T12:45:32+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/services/creative/video-production-services-in-thane/', '2026-08-17T12:45:32+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/services/creative/design/', '2026-08-17T12:45:32+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/services/demand-generation/vetted-lead-generation-services-in-thane/', '2026-08-17T12:45:31+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/services/demand-generation/performance-marketing-agency-in-thane/', '2026-08-17T12:45:31+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/services/demand-generation/marketing-automation-services-in-thane/', '2026-08-17T12:45:31+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/services/demand-generation/email-marketing-services-in-thane/', '2026-08-17T12:45:31+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/services/digital-experiences/website-design-development-services-in-thane/', '2026-08-17T12:45:32+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/services/digital-experiences/chatbot-development-services-in-thane/', '2026-08-17T12:45:32+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/services/digital-experiences/landing-page-services-in-thane/', '2026-08-17T12:45:32+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/case-studies/ajmera-realty/', '2026-08-17T12:45:20+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/case-studies/blue-star/', '2026-08-17T12:45:20+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/case-studies/carnelian-capital/', '2026-08-17T12:45:20+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/case-studies/cisco-thingqbator/', '2026-08-17T12:45:20+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/case-studies/evershine-builders/', '2026-08-17T12:45:20+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/case-studies/forest-hills/', '2026-08-17T12:45:20+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/case-studies/ghci-2024/', '2026-08-17T12:45:20+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/case-studies/imbesharam/', '2026-08-17T12:45:20+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/case-studies/mos-world/', '2026-08-17T12:45:20+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/case-studies/munns-mars/', '2026-08-17T12:45:20+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/case-studies/nasscom-konnect/', '2026-08-17T12:45:20+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/case-studies/nasscom-member-connect/', '2026-08-17T12:45:20+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/case-studies/psiog/', '2026-08-17T12:45:20+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/case-studies/robust-petcare/', '2026-08-17T12:45:20+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/case-studies/stl-digital/', '2026-08-17T12:45:20+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/case-studies/tiger-analytics/', '2026-08-17T12:45:20+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/services/reputation-communications/employer-branding-agency-in-thane/', '2026-08-17T12:45:31+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/privacy-policy/', '2026-08-17T12:45:17+00:00', 'daily', '0.6400'),
        array('https://chimpzlab.com/index.html', '2026-08-17T12:45:17+00:00', 'daily', '0.5120'),
        array('https://chimpzlab.com/services/demand-generation/email-marketing-project/', '2026-08-17T12:45:31+00:00', 'daily', '0.4096'),
        array('https://chimpzlab.com/services/digital-experiences/website-design-project/', '2026-08-17T12:45:32+00:00', 'daily', '0.4096'),
        array('https://chimpzlab.com/services/digital-experiences/landing-page-project/', '2026-08-17T12:45:32+00:00', 'daily', '0.4096'),
);

function sitemap_fetch($url, &$total_pages) {
    $ctx = stream_context_create(array('http' => array(
        'timeout'       => 25,
        'header'        => "Accept: application/json\r\nUser-Agent: ChimpzlabSite/1.0\r\n",
        'ignore_errors' => true,
    )));
    $json = @file_get_contents($url, false, $ctx);
    $status = 0;
    $total_pages = 1;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $h, $m)) {
                $status = (int) $m[1];
            } elseif (stripos($h, 'X-WP-TotalPages:') === 0) {
                $total_pages = max(1, (int) trim(substr($h, 16)));
            }
        }
    }
    if ($json === false || $status >= 400) return false;
    $data = json_decode($json, true);
    return is_array($data) ? $data : false;
}

// Returns published posts across all pages, or false on failure.
// rest_route first (works even when pretty permalinks 404), then pretty.
function sitemap_wp_posts() {
    $bases = array(
        array(WP_REST, '&'),
        array(WP_PRETTY, '?'),
    );
    foreach ($bases as $b) {
        list($base, $sep) = $b;
        $all = array();
        $pages = 1;
        $ok = true;
        for ($p = 1; $p <= $pages; $p++) {
            $url = $base . $sep . 'per_page=100&orderby=modified&order=desc&_fields=slug,modified&page=' . $p;
            $data = sitemap_fetch($url, $pages);
            if ($data === false) { $ok = false; break; }
            $all = array_merge($all, $data);
            if (count($data) < 100 && $p >= $pages) break;
        }
        if ($ok) return $all;
    }
    return false;
}

function sitemap_xml_escape($s) {
    return htmlspecialchars($s, ENT_XML1, 'UTF-8');
}

function sitemap_build($posts) {
    global $STATIC_URLS;
    $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n\n";
    foreach ($STATIC_URLS as $u) {
        $out .= '  <url>' . "\n"
            . '       <loc>' . sitemap_xml_escape($u[0]) . '</loc>' . "\n"
            . '       <lastmod>' . sitemap_xml_escape($u[1]) . '</lastmod>' . "\n"
            . '       <changefreq>' . sitemap_xml_escape($u[2]) . '</changefreq>' . "\n"
            . '       <priority>' . sitemap_xml_escape($u[3]) . '</priority>' . "\n"
            . '  </url>' . "\n";
    }
    $seen = array();
    if (is_array($posts)) {
        foreach ($posts as $p) {
            if (!isset($p['slug']) || isset($seen[$p['slug']])) continue;
            $seen[$p['slug']] = true;
            $mod = isset($p['modified']) ? $p['modified'] : gmdate('Y-m-d\TH:i:s+00:00');
            $out .= '  <url>' . "\n"
                . '       <loc>' . sitemap_xml_escape(SITE_URL . '/blog-insights/' . $p['slug']) . '</loc>' . "\n"
                . '       <lastmod>' . sitemap_xml_escape($mod) . '</lastmod>' . "\n"
                . '       <changefreq>weekly</changefreq>' . "\n"
                . '       <priority>0.6400</priority>' . "\n"
                . '  </url>' . "\n";
        }
    }
    $out .= '</urlset>' . "\n";
    return $out;
}

function sitemap_serve($xml, $cached) {
    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    header('X-Sitemap-Cache: ' . ($cached ? 'HIT' : 'MISS'));
    echo $xml;
    exit;
}

// Fresh cache? Serve it without touching WordPress.
if (is_file(CACHE_FILE) && (time() - filemtime(CACHE_FILE)) < SITEMAP_TTL) {
    sitemap_serve(file_get_contents(CACHE_FILE), true);
}

$posts = sitemap_wp_posts();
if ($posts === false) {
    // WP unreachable: stale cache is better than nothing; else static URLs only.
    if (is_file(CACHE_FILE)) sitemap_serve(file_get_contents(CACHE_FILE), true);
    sitemap_serve(sitemap_build(false), false);
}

$xml = sitemap_build($posts);
@file_put_contents(CACHE_FILE, $xml);
sitemap_serve($xml, false);
