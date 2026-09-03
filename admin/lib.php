<?php
/**
 * BTW IMF blog admin — shared library.
 * PHP 7.4+ . No external dependencies.
 */
declare(strict_types=1);

define('ADMIN_DIR', __DIR__);
define('ROOT_DIR', dirname(__DIR__));
define('DATA_DIR', ROOT_DIR . '/_blog-data');
define('POSTS_DIR', ROOT_DIR . '/blogs');
define('UPLOAD_DIR', ROOT_DIR . '/assets/img/blog');
define('CONFIG_FILE', ADMIN_DIR . '/config.php');

const CATEGORIES = ['Insurance', 'Finance', 'Travel & Aviation', 'Visa & Immigration', 'News'];

/* ── config ──────────────────────────────────────────────────────────────── */
function cfg(?string $key = null) {
    static $c = null;
    if ($c === null) {
        $c = is_file(CONFIG_FILE) ? (require CONFIG_FILE) : [];
        if (!is_array($c)) $c = [];
    }
    if ($key === null) return $c;
    return $c[$key] ?? null;
}
function is_configured(): bool {
    return is_file(CONFIG_FILE) && !empty(cfg('password_hash'));
}
function site_url(): string {
    return rtrim((string)(cfg('site_url') ?: 'https://btwimf.com'), '/');
}

/* ── session / auth ──────────────────────────────────────────────────────── */
function boot_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/admin/', 'httponly' => true,
        'samesite' => 'Lax', 'secure' => $https,
    ]);
    session_name('btwadmin');
    session_start();
}
function is_authed(): bool {
    boot_session();
    return !empty($_SESSION['auth']) && ($_SESSION['auth_ua'] ?? '') === ua_hash();
}
function ua_hash(): string {
    return substr(hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|btwimf'), 0, 24);
}
function login(string $password): bool {
    boot_session();
    $hash = (string) cfg('password_hash');
    if ($hash === '' || !password_verify($password, $hash)) return false;
    session_regenerate_id(true);
    $_SESSION['auth'] = true;
    $_SESSION['auth_ua'] = ua_hash();
    $_SESSION['csrf'] = bin2hex(random_bytes(20));
    return true;
}
function logout(): void {
    boot_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
    }
    session_destroy();
}
function require_auth(): void {
    if (!is_authed()) { header('Location: index.php'); exit; }
}
function csrf_token(): string {
    boot_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(20));
    return $_SESSION['csrf'];
}
function check_csrf(): void {
    boot_session();
    $t = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)$t)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Session expired — reload the page and sign in again.']);
        exit;
    }
}

/* ── login rate limit (per IP, file-based) ───────────────────────────────── */
function rl_check(): bool {
    $ip = client_ip();
    $f = DATA_DIR . '/.rl-' . md5($ip) . '.json';
    $now = time();
    $hits = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    $hits = array_values(array_filter($hits, fn($t) => $t > $now - 900));
    return count($hits) < 8;
}
function rl_hit(): void {
    ensure_dir(DATA_DIR);
    $ip = client_ip();
    $f = DATA_DIR . '/.rl-' . md5($ip) . '.json';
    $now = time();
    $hits = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    $hits[] = $now;
    @file_put_contents($f, json_encode(array_slice($hits, -20)), LOCK_EX);
}
function client_ip(): string {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return trim(explode(',', $ip)[0]);
}

/* ── fs helpers ──────────────────────────────────────────────────────────── */
function ensure_dir(string $d): void { if (!is_dir($d)) @mkdir($d, 0775, true); }
function h($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

/* ── slug / dates ────────────────────────────────────────────────────────── */
function slugify(string $s): string {
    $s = strtolower(trim($s));
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($t !== false) $s = $t;
    }
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim((string) $s, '-');
    return $s !== '' ? substr($s, 0, 80) : 'post-' . date('Ymd-His');
}
function nice_date(string $iso): string {
    $t = strtotime($iso) ?: time();
    return date('d M Y', $t);
}

/* ── post storage ────────────────────────────────────────────────────────── */
function post_path(string $slug): string { return DATA_DIR . '/' . $slug . '.json'; }

function load_post(string $slug): ?array {
    $slug = slugify($slug);
    $p = post_path($slug);
    if (!is_file($p)) return null;
    $d = json_decode((string) file_get_contents($p), true);
    return is_array($d) ? $d : null;
}
function save_post(array $post): array {
    ensure_dir(DATA_DIR);
    $post['slug'] = slugify($post['slug'] ?? $post['title'] ?? '');
    $post['updated'] = date('c');
    if (empty($post['date'])) $post['date'] = date('Y-m-d');
    file_put_contents(post_path($post['slug']), json_encode($post, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $post;
}
function delete_post(string $slug): void {
    $slug = slugify($slug);
    @unlink(post_path($slug));
    $dir = POSTS_DIR . '/' . $slug;
    if (is_dir($dir)) { @unlink($dir . '/index.html'); @rmdir($dir); }
}
function all_posts(): array {
    ensure_dir(DATA_DIR);
    $out = [];
    foreach (glob(DATA_DIR . '/*.json') ?: [] as $f) {
        $d = json_decode((string) file_get_contents($f), true);
        if (is_array($d) && !empty($d['slug'])) $out[] = $d;
    }
    usort($out, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));
    return $out;
}
function published_posts(): array {
    return array_values(array_filter(all_posts(), fn($p) => ($p['status'] ?? 'draft') === 'published'));
}

/* ── minimal, safe Markdown → HTML (subset) ─────────────────────────────── */
function md_to_html(string $md): string {
    $md = str_replace(["\r\n", "\r"], "\n", $md);
    $lines = explode("\n", $md);
    $html = '';
    $para = [];
    $list = null;          // 'ul' | 'ol' | null
    $listItems = [];
    $inCode = false;
    $code = [];
    $quote = [];

    $flushPara = function () use (&$para, &$html) {
        if ($para) { $html .= '<p>' . md_inline(implode(' ', $para)) . "</p>\n"; $para = []; }
    };
    $flushList = function () use (&$list, &$listItems, &$html) {
        if ($list) {
            $html .= "<$list>\n";
            foreach ($listItems as $li) $html .= '<li>' . md_inline($li) . "</li>\n";
            $html .= "</$list>\n";
            $list = null; $listItems = [];
        }
    };
    $flushQuote = function () use (&$quote, &$html) {
        if ($quote) {
            $html .= '<blockquote>' . md_inline(implode(' ', $quote)) . "</blockquote>\n";
            $quote = [];
        }
    };

    foreach ($lines as $line) {
        if (preg_match('/^```/', $line)) {
            if ($inCode) { $html .= '<pre><code>' . h(implode("\n", $code)) . "</code></pre>\n"; $code = []; $inCode = false; }
            else { $flushPara(); $flushList(); $flushQuote(); $inCode = true; }
            continue;
        }
        if ($inCode) { $code[] = $line; continue; }

        $t = trim($line);

        if ($t === '') { $flushPara(); $flushList(); $flushQuote(); continue; }

        if (preg_match('/^(#{1,4})\s+(.*)$/', $t, $m)) {
            $flushPara(); $flushList(); $flushQuote();
            $lvl = max(2, min(4, strlen($m[1])));      // '#' and '##' -> h2, '###' -> h3, '####' -> h4
            $html .= "<h$lvl>" . md_inline($m[2]) . "</h$lvl>\n";
            continue;
        }
        if (preg_match('/^(\-\-\-+|\*\*\*+)$/', $t)) {
            $flushPara(); $flushList(); $flushQuote();
            $html .= "<hr>\n";
            continue;
        }
        if (preg_match('/^>\s?(.*)$/', $t, $m)) {
            $flushPara(); $flushList();
            $quote[] = $m[1];
            continue;
        }
        if (preg_match('/^[\-\*]\s+(.*)$/', $t, $m)) {
            $flushPara(); $flushQuote();
            if ($list !== 'ul') { $flushList(); $list = 'ul'; }
            $listItems[] = $m[1];
            continue;
        }
        if (preg_match('/^\d+[\.\)]\s+(.*)$/', $t, $m)) {
            $flushPara(); $flushQuote();
            if ($list !== 'ol') { $flushList(); $list = 'ol'; }
            $listItems[] = $m[1];
            continue;
        }
        // standalone image line
        if (preg_match('/^!\[([^\]]*)\]\(([^)\s]+)\)$/', $t, $m)) {
            $flushPara(); $flushList(); $flushQuote();
            $src = md_safe_url($m[2]);
            if ($src) $html .= '<figure><img src="' . h($src) . '" alt="' . h($m[1]) . '" loading="lazy"></figure>' . "\n";
            continue;
        }
        $flushList(); $flushQuote();
        $para[] = $t;
    }
    if ($inCode && $code) $html .= '<pre><code>' . h(implode("\n", $code)) . "</code></pre>\n";
    $flushPara(); $flushList(); $flushQuote();
    return $html;
}
function md_inline(string $s): string {
    $s = h($s);                                        // escape first — everything below is safe
    // images
    $s = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', function ($m) {
        $u = md_safe_url(html_entity_decode($m[2], ENT_QUOTES));
        return $u ? '<img src="' . h($u) . '" alt="' . $m[1] . '" loading="lazy">' : $m[1];
    }, $s);
    // links
    $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
        $u = md_safe_url(html_entity_decode($m[2], ENT_QUOTES));
        if (!$u) return $m[1];
        $ext = preg_match('#^https?://#i', $u) ? ' target="_blank" rel="noopener"' : '';
        return '<a href="' . h($u) . '"' . $ext . '>' . $m[1] . '</a>';
    }, $s);
    $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s);
    $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s);
    $s = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $s);
    $s = preg_replace('/(?<!_)_([^_\n]+)_(?!_)/', '<em>$1</em>', $s);
    return $s;
}
function md_safe_url(string $u): ?string {
    $u = trim($u);
    if ($u === '') return null;
    if (preg_match('#^(https?:)?//#i', $u) || strpos($u, '/') === 0 || strpos($u, '#') === 0 || stripos($u, 'mailto:') === 0) {
        if (preg_match('/^\s*javascript:/i', $u) || preg_match('/^\s*data:/i', $u)) return null;
        return $u;
    }
    return null;
}
function excerpt_from(string $md, int $len = 165): string {
    $t = preg_replace('/[#>*_`\-\[\]!]|\(https?:[^)]*\)/', '', $md);
    $t = trim(preg_replace('/\s+/', ' ', (string) $t));
    return mb_strlen($t) > $len ? mb_substr($t, 0, $len - 1) . '…' : $t;
}
