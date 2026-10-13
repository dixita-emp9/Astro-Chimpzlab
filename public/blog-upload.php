<?php
// Blog docx uploader backend — password-gated, creates WordPress drafts.
//
// Flow: the /blog-upload page logs in here (session), parses the .docx in the
// browser with mammoth.js, then POSTs structured JSON {blogs: [...]} which this
// script creates as `insight` drafts via the WP REST API. Editing/publishing
// happens in WordPress afterwards; the site + sitemap pick up published posts
// automatically. Text only — featured images are set manually in WordPress.
//
// Secrets (never in client JS, never hardcoded):
//   BLOG_UPLOAD_PASSWORD — login password for the upload page
//   WP_APP_USER / WP_APP_PASSWORD — WordPress user + Application Password
//     (WP Admin > Users > Profile > Application Passwords), all in crm/.env.

declare(strict_types=1);

require_once __DIR__ . '/capture-common.php';

// Persistent login: session cookie lives 7 days so a reload does not ask for
// the password again (a status check on page load resumes the session).
$buWeek = 7 * 24 * 3600;
@ini_set('session.gc_maxlifetime', (string) $buWeek);
session_set_cookie_params(array('lifetime' => $buWeek, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax'));
session_start();
header('Content-Type: application/json');

$WP_BASE = 'https://chimpzlab.com/chimpzlab-old';
$WP_ADMIN = $WP_BASE . '/wp-admin';
$WP_REST = $WP_BASE . '/?rest_route=/wp/v2';
$WP_PRETTY = $WP_BASE . '/wp-json/wp/v2';

function bu_fail(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(array('ok' => false, 'error' => $msg));
    exit;
}

// Human names for category slugs the site uses (used when auto-creating).
function bu_cat_name(string $slug): string
{
    $names = array(
        'seo-aeo' => 'SEO / AEO',
        'performance-marketing' => 'Performance Marketing',
        'video-production' => 'Video Production',
        'social-media-marketing' => 'Social Media Marketing',
        'content-writing' => 'Content Writing',
        'influencer-marketing' => 'Influencer Marketing',
        'public-relations' => 'Public Relations',
        'email-marketing' => 'Email Marketing',
        'chatbot-virtual-assistant' => 'Chatbot & Virtual Assistant',
        'branding' => 'Branding',
        'corporate-communications' => 'Corporate Communications',
        'employer-branding' => 'Employer Branding',
        'website-design-development' => 'Web Design & Development',
        'landing-pages' => 'Landing Pages',
        'vetted-lead-generation' => 'Vetted Lead Generation',
        'marketing-automation' => 'Marketing Automation',
        'thought-leadership' => 'Thought Leadership',
        'design' => 'Design',
        'strategy' => 'Strategy',
    );
    if (isset($names[$slug])) return $names[$slug];
    return ucwords(str_replace(array('-', '_'), ' ', $slug));
}

function bu_slugify(string $s): string
{
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

// Authenticated WP REST request. Tries rest_route first, then pretty permalinks.
// Returns array(body, status) with body decoded, or false on transport failure.
function bu_wp(string $auth, string $method, string $path, $body = null)
{
    global $WP_REST, $WP_PRETTY;
    foreach (array($WP_REST, $WP_PRETTY) as $base) {
        // $path looks like "/items?slug=x" — rest_route needs "&" style.
        $url = ($base === $WP_REST)
            ? $base . str_replace('?', '&', $path)
            : $base . $path;
        $headers = "Accept: application/json\r\nAuthorization: Basic " . $auth . "\r\n";
        $opts = array('http' => array(
            'method' => $method,
            'timeout' => 30,
            'ignore_errors' => true,
            'header' => $headers,
        ));
        if ($body !== null) {
            $opts['http']['header'] .= "Content-Type: application/json\r\n";
            $opts['http']['content'] = json_encode($body);
        }
        $raw = @file_get_contents($url, false, stream_context_create($opts));
        $status = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('/^HTTP\/\S+\s+(\d+)/', $h, $m)) $status = (int) $m[1];
            }
        }
        if ($raw === false || $status === 0) continue; // transport failed, try next base
        $decoded = json_decode($raw, true);
        return array('status' => $status, 'body' => $decoded);
    }
    return false;
}

$env = capture_env();
$action = (string) ($_GET['action'] ?? '');

// ---- AI takeaways (one blog at a time, session-gated) ---------------------
// Uses an OpenAI-compatible chat API (OpenAI, OpenRouter, …) with the key kept
// server-side in crm/.env: LLM_API_KEY, optional LLM_API_URL / LLM_MODEL.
function bu_llm_items(string $title, string $text)
{
    global $env;
    $key = trim((string) ($env['LLM_API_KEY'] ?? ''));
    if ($key === '') return array('ok' => false, 'error' => 'AI is not configured (LLM_API_KEY).');
    $url = trim((string) ($env['LLM_API_URL'] ?? 'https://api.openai.com/v1/chat/completions'));
    $model = trim((string) ($env['LLM_MODEL'] ?? 'gpt-4o-mini'));
    $text = mb_substr($text, 0, 6000);
    $prompt = "You write sidebar \"Key Takeaways\" for a business blog post.\n"
        . "Title: " . $title . "\n\n" . $text . "\n\n"
        . "Return EXACTLY 4 takeaways, one per line, plain text, no numbering, no bullets, "
        . "each a single punchy sentence under 140 characters stating the most useful fact, "
        . "statistic or action — never generic filler, never repeat the title.";
    $payload = json_encode(array(
        'model' => $model,
        'temperature' => 0.4,
        'max_tokens' => 400,
        'messages' => array(
            array('role' => 'system', 'content' => 'You write concise, specific blog takeaways.'),
            array('role' => 'user', 'content' => $prompt),
        ),
    ));
    $ctx = stream_context_create(array('http' => array(
        'method' => 'POST',
        'timeout' => 60,
        'ignore_errors' => true,
        'header' => "Content-Type: application/json\r\nAuthorization: Bearer " . $key . "\r\n",
        'content' => $payload,
    )));
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return array('ok' => false, 'error' => 'AI request failed.');
    $data = json_decode($raw, true);
    $out = '';
    if (is_array($data) && isset($data['choices'][0]['message']['content'])) {
        $out = (string) $data['choices'][0]['message']['content'];
    } elseif (is_array($data) && isset($data['error']['message'])) {
        return array('ok' => false, 'error' => 'AI error: ' . mb_substr((string) $data['error']['message'], 0, 160));
    }
    $items = array();
    foreach (preg_split('/\r?\n/', $out) as $line) {
        $line = trim($line);
        $line = preg_replace('/^(\d+[.)\-:]|[-•*–—])\s*/u', '', $line);
        $line = trim($line, "\"' ");
        if (mb_strlen($line) < 20) continue;
        $items[] = mb_substr($line, 0, 200);
        if (count($items) >= 4) break;
    }
    if (count($items) < 2) return array('ok' => false, 'error' => 'AI returned too little.');
    return array('ok' => true, 'items' => $items);
}

if ($action === 'takeaways') {
    if (empty($_SESSION['bu_auth'])) bu_fail('Login required.', 401);
    $data = json_decode((string) file_get_contents('php://input'), true);
    $title = mb_substr(trim((string) ($data['title'] ?? '')), 0, 200);
    $text = mb_substr(trim((string) ($data['text'] ?? '')), 0, 8000);
    if ($title === '' || $text === '') bu_fail('Title and text are required.', 400);
    $res = bu_llm_items($title, $text);
    if (!$res['ok']) bu_fail($res['error'], 502);
    echo json_encode(array('ok' => true, 'items' => $res['items']));
    exit;
}

// ---- Session status (lets the page skip login after a reload) ------------
if ($action === 'status') {
    echo json_encode(array('ok' => true, 'authed' => !empty($_SESSION['bu_auth']), 'ai' => trim((string) ($env['LLM_API_KEY'] ?? '')) !== ''));
    exit;
}

// ---- Logout ---------------------------------------------------------------
if ($action === 'logout') {
    $_SESSION = array();
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    echo json_encode(array('ok' => true));
    exit;
}

// ---- Login ---------------------------------------------------------------
if ($action === 'login') {
    $now = time();
    $attempts = (int) ($_SESSION['bu_attempts'] ?? 0);
    $first = (int) ($_SESSION['bu_first'] ?? 0);
    if ($first === 0 || ($now - $first) > 600) {
        $attempts = 0;
        $first = $now;
    }
    if ($attempts >= 10) bu_fail('Too many attempts. Please retry after 10 minutes.', 429);
    $_SESSION['bu_first'] = $first;

    $user = (string) ($_POST['username'] ?? '');
    $pw = (string) ($_POST['password'] ?? '');
    $expectedUser = (string) ($env['BLOG_UPLOAD_USER'] ?? '');
    $expectedPw = (string) ($env['BLOG_UPLOAD_PASSWORD'] ?? '');
    if ($expectedUser === '' || $expectedPw === '') bu_fail('Uploader is not configured (BLOG_UPLOAD_USER / BLOG_UPLOAD_PASSWORD).', 500);
    if ($user === '' || $pw === '' || !hash_equals($expectedUser, $user) || !hash_equals($expectedPw, $pw)) {
        $_SESSION['bu_attempts'] = $attempts + 1;
        sleep(1);
        bu_fail('Incorrect username or password.', 401);
    }
    $_SESSION['bu_attempts'] = 0;
    $_SESSION['bu_auth'] = true;
    session_regenerate_id(true);
    echo json_encode(array('ok' => true));
    exit;
}

if (empty($_SESSION['bu_auth'])) bu_fail('Login required.', 401);

// ---- Upload (create drafts) ----------------------------------------------
if ($action === 'upload') {
    $wpUser = trim((string) ($env['WP_APP_USER'] ?? ''));
    $wpAppPw = str_replace(' ', '', (string) ($env['WP_APP_PASSWORD'] ?? ''));
    if ($wpUser === '' || $wpAppPw === '') {
        bu_fail('WordPress is not configured (WP_APP_USER / WP_APP_PASSWORD).', 500);
    }
    $auth = base64_encode($wpUser . ':' . $wpAppPw);

    // Quick credential check before processing the batch.
    $me = bu_wp($auth, 'GET', '/users/me');
    if ($me === false || $me['status'] === 401 || $me['status'] === 403) {
        bu_fail('WordPress authentication failed — check WP_APP_USER / WP_APP_PASSWORD.', 502);
    }
    if ($me === false || $me['status'] >= 400) {
        bu_fail('Cannot reach WordPress (HTTP ' . $me['status'] . ').', 502);
    }

    $data = json_decode((string) file_get_contents('php://input'), true);
    $blogs = (is_array($data) && isset($data['blogs']) && is_array($data['blogs'])) ? $data['blogs'] : array();
    if (count($blogs) === 0 || count($blogs) > 30) bu_fail('Send 1–30 blogs.', 400);

    $catCache = array();
    $tagCache = array();
    $results = array();

    foreach ($blogs as $bi => $b) {
        $title = mb_substr(trim((string) ($b['title'] ?? '')), 0, 200);
        $content = (string) ($b['content'] ?? '');
        $excerpt = mb_substr(trim((string) ($b['excerpt'] ?? '')), 0, 2000);
        $catSlug = bu_slugify((string) ($b['category'] ?? ''));
        $slug = substr(bu_slugify((string) ($b['slug'] ?? '')), 0, 60);
        $tagNames = array();
        if (isset($b['tags']) && is_array($b['tags'])) {
            foreach ($b['tags'] as $t) {
                $t = trim((string) $t);
                if ($t !== '') $tagNames[] = mb_substr($t, 0, 60);
            }
            $tagNames = array_values(array_unique($tagNames));
            $tagNames = array_slice($tagNames, 0, 12);
        }
        if ($title === '' || $content === '' || strlen($content) > 300000 || $catSlug === '') {
            $results[] = array('ok' => false, 'title' => $title, 'error' => 'Data invalid (title/content/category).');
            continue;
        }

        // Category: match by slug, else create it.
        if (!isset($catCache[$catSlug])) {
            $found = bu_wp($auth, 'GET', '/categories?slug=' . urlencode($catSlug) . '&per_page=1');
            $catId = 0;
            if ($found !== false && $found['status'] < 400 && is_array($found['body']) && count($found['body']) > 0) {
                $catId = (int) $found['body'][0]['id'];
            } else {
                $created = bu_wp($auth, 'POST', '/categories', array('name' => bu_cat_name($catSlug), 'slug' => $catSlug));
                if ($created !== false && ($created['status'] === 200 || $created['status'] === 201) && isset($created['body']['id'])) {
                    $catId = (int) $created['body']['id'];
                }
            }
            $catCache[$catSlug] = $catId;
        }
        if ($catCache[$catSlug] <= 0) {
            $results[] = array('ok' => false, 'title' => $title, 'error' => 'Could not set category: ' . $catSlug);
            continue;
        }

        // Tags: match by slug, else create.
        $tagIds = array();
        foreach ($tagNames as $tname) {
            $tslug = bu_slugify($tname);
            if ($tslug === '') continue;
            if (!isset($tagCache[$tslug])) {
                $tagCache[$tslug] = 0;
                $found = bu_wp($auth, 'GET', '/tags?slug=' . urlencode($tslug) . '&per_page=1');
                if ($found !== false && $found['status'] < 400 && is_array($found['body']) && count($found['body']) > 0) {
                    $tagCache[$tslug] = (int) $found['body'][0]['id'];
                } else {
                    $created = bu_wp($auth, 'POST', '/tags', array('name' => $tname, 'slug' => $tslug));
                    if ($created !== false && ($created['status'] === 200 || $created['status'] === 201) && isset($created['body']['id'])) {
                        $tagCache[$tslug] = (int) $created['body']['id'];
                    }
                }
            }
            if ($tagCache[$tslug] > 0) $tagIds[] = $tagCache[$tslug];
        }

        $newPost = array(
            'title' => $title,
            'content' => $content,
            'excerpt' => $excerpt,
            'status' => 'draft',
            'categories' => array($catCache[$catSlug]),
            'tags' => $tagIds,
        );
        if ($slug !== '') $newPost['slug'] = $slug;
        $post = bu_wp($auth, 'POST', '/insights', $newPost);
        if ($post === false) {
            $results[] = array('ok' => false, 'title' => $title, 'error' => 'WordPress connection failed.');
            continue;
        }
        if (($post['status'] === 200 || $post['status'] === 201) && isset($post['body']['id'])) {
            $id = (int) $post['body']['id'];
            $results[] = array(
                'ok' => true,
                'title' => $title,
                'id' => $id,
                'slug' => isset($post['body']['slug']) ? (string) $post['body']['slug'] : $slug,
                'edit' => $WP_ADMIN . '/post.php?post=' . $id . '&action=edit',
            );
        } else {
            $msg = 'WordPress error ' . $post['status'];
            if (isset($post['body']['message'])) $msg .= ': ' . $post['body']['message'];
            $results[] = array('ok' => false, 'title' => $title, 'error' => $msg);
        }
    }

    echo json_encode(array('ok' => true, 'results' => $results));
    exit;
}

bu_fail('Unknown action.', 404);
