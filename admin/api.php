<?php
/**
 * BTW IMF blog admin — JSON action endpoint. All actions require an authenticated
 * session and a valid CSRF token. Called via fetch() from index.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/render.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function out(array $d): void { echo json_encode($d); exit; }
function fail(string $msg, int $code = 400): void { http_response_code($code); out(['ok' => false, 'error' => $msg]); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST only', 405);
if (!is_authed()) fail('Not signed in.', 401);
check_csrf();

$action = $_POST['action'] ?? '';

/* ── collect a post payload from the editor form ───────────────────────── */
function payload(): array {
    $title = trim((string)($_POST['title'] ?? ''));
    if ($title === '') fail('Title is required.', 422);
    $slug = trim((string)($_POST['slug'] ?? ''));
    $slug = slugify($slug !== '' ? $slug : $title);
    $cat = (string)($_POST['category'] ?? 'News');
    if (!in_array($cat, CATEGORIES, true)) $cat = 'News';
    $body = (string)($_POST['body_md'] ?? '');
    $date = trim((string)($_POST['date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

    $existing = load_post($slug) ?: [];
    return array_merge($existing, [
        'slug'      => $slug,
        'title'     => $title,
        'category'  => $cat,
        'excerpt'   => trim((string)($_POST['excerpt'] ?? '')),
        'hero'      => trim((string)($_POST['hero'] ?? '')),
        'heroAlt'   => trim((string)($_POST['heroAlt'] ?? '')),
        'body_md'   => $body,
        'author'    => trim((string)($_POST['author'] ?? '')) ?: (cfg('default_author') ?: 'BTW IMF Advisory Team'),
        'date'      => $date,
        'seo_title' => trim((string)($_POST['seo_title'] ?? '')),
        'seo_desc'  => trim((string)($_POST['seo_desc'] ?? '')),
    ]);
}

switch ($action) {

    case 'save': {
        $p = payload();
        $p['status'] = ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
        $p = save_post($p);
        if ($p['status'] === 'published') publish_all(); else render_listing();
        out(['ok' => true, 'slug' => $p['slug'], 'status' => $p['status']]);
    }

    case 'publish': {
        $p = payload();
        $p['status'] = 'published';
        $p = save_post($p);
        render_post($p);
        render_listing();
        update_sitemap();
        render_feed();
        out(['ok' => true, 'slug' => $p['slug'], 'url' => site_url() . '/blogs/' . $p['slug'] . '/']);
    }

    case 'unpublish': {
        $slug = slugify((string)($_POST['slug'] ?? ''));
        $p = load_post($slug);
        if (!$p) fail('Post not found.', 404);
        $p['status'] = 'draft';
        save_post($p);
        $dir = POSTS_DIR . '/' . $slug;
        if (is_dir($dir)) { @unlink($dir . '/index.html'); @rmdir($dir); }
        render_listing();
        update_sitemap();
        render_feed();
        out(['ok' => true]);
    }

    case 'delete': {
        $slug = slugify((string)($_POST['slug'] ?? ''));
        if (!load_post($slug)) fail('Post not found.', 404);
        delete_post($slug);
        render_listing();
        update_sitemap();
        render_feed();
        out(['ok' => true]);
    }

    case 'rebuild': {
        publish_all();
        out(['ok' => true, 'count' => count(published_posts())]);
    }

    case 'preview': {
        out(['ok' => true, 'html' => md_to_html((string)($_POST['body_md'] ?? ''))]);
    }

    case 'upload': {
        if (empty($_FILES['image']['name']) || !is_uploaded_file($_FILES['image']['tmp_name'])) fail('No file received.', 422);
        $f = $_FILES['image'];
        if ($f['size'] > 4 * 1024 * 1024) fail('Image must be under 4 MB.', 422);
        $info = @getimagesize($f['tmp_name']);
        $map = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
        if (!$info || !isset($map[$info[2]])) fail('Only JPG, PNG, WebP or GIF images are allowed.', 422);
        ensure_dir(UPLOAD_DIR);
        $base = slugify(pathinfo($f['name'], PATHINFO_FILENAME)) ?: 'img';
        $name = $base . '-' . substr(bin2hex(random_bytes(4)), 0, 6) . '.' . $map[$info[2]];
        if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $name)) fail('Could not save the upload.', 500);
        out(['ok' => true, 'path' => 'assets/img/blog/' . $name]);
    }

    default:
        fail('Unknown action.', 400);
}
