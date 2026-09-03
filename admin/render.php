<?php
/**
 * BTW IMF blog admin — static site generator.
 * Renders published posts to blogs/<slug>/index.html, rebuilds blogs/index.html,
 * refreshes sitemap.xml and blogs/feed.xml. No templating engine — plain heredocs.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

/** Reference page whose header/drawer/footer we mirror so the blog stays in sync. */
function _ref_html(): string {
    foreach (['products/health-insurance/index.html', 'renewals/index.html', 'products/car-insurance/index.html'] as $p) {
        $f = ROOT_DIR . '/' . $p;
        if (is_file($f)) return file_get_contents($f);
    }
    return '';
}
function _slice(string $h, string $from, string $to, bool $incl = true): string {
    $a = strpos($h, $from);
    if ($a === false) return '';
    $b = strpos($h, $to, $a);
    if ($b === false) return '';
    return substr($h, $a, $b - $a + ($incl ? strlen($to) : 0));
}
/** Chrome parts, path-shifted for a given depth (2 = blogs/<slug>/, 1 = blogs/). */
function chrome(int $depth): array {
    static $cache = [];
    if (isset($cache[$depth])) return $cache[$depth];
    $h = _ref_html();
    $parts = [
        'topbar' => rtrim(_slice($h, '<div class="topbar">', '<header class="header"', false)),
        'header' => _slice($h, '<header class="header"', '</header>'),
        'drawer' => _slice($h, '<div class="scrim"', '</aside>'),
        'wafab'  => _slice($h, '<a class="wa-fab"', '</a>'),
        'footer' => _slice($h, '<footer class="footer">', '</footer>'),
        'mobilebar' => preg_match('#<div class="mobilebar".*?</div>#s', $h, $m) ? $m[0] : '',
    ];
    if ($depth === 1) {
        foreach ($parts as $k => $v) $parts[$k] = str_replace('../../', '../', $v);
    }
    return $cache[$depth] = $parts;
}

function _head(string $title, string $desc, string $canon, string $ogtype, string $image, array $ld = []): string {
    $t = h($title); $d = h($desc); $c = h($canon); $img = h($image);
    $ldb = '';
    foreach ($ld as $obj) {
        $ldb .= '<script type="application/ld+json">' . "\n"
              . json_encode($obj, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n</script>\n";
    }
    $icon = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%23263972'/%3E%3Cpath d='M32 12l14 6v10c0 9-6 15.5-14 18-8-2.5-14-9-14-18V18z' fill='none' stroke='%237BBFA7' stroke-width='4'/%3E%3Cpath d='M25 32l5 5 10-11' fill='none' stroke='%23fff' stroke-width='4' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E";
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>$t</title>
<meta name="description" content="$d">
<meta name="theme-color" content="#263972">
<link rel="canonical" href="$c">
<meta property="og:title" content="$t">
<meta property="og:description" content="$d">
<meta property="og:type" content="$ogtype">
<meta property="og:url" content="$c">
<meta property="og:image" content="$img">
<meta property="og:site_name" content="BTW IMF">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="$t">
<meta name="twitter:description" content="$d">
<meta name="twitter:image" content="$img">
<link rel="icon" href="$icon">
<link rel="stylesheet" href="{{CSS}}assets/css/fonts.css">
<link rel="stylesheet" href="{{CSS}}assets/css/style.css">
$ldb</head>
HTML;
}

/* ── render one post ────────────────────────────────────────────────────── */
function render_post(array $post): string {
    $depth = 2;
    $rel = str_repeat('../', $depth);
    $c = chrome($depth);
    $slug = slugify($post['slug']);
    $url = site_url() . '/blogs/' . $slug . '/';
    $title = trim($post['title'] ?? 'Untitled');
    $cat = in_array(($post['category'] ?? ''), CATEGORIES, true) ? $post['category'] : 'News';
    $author = trim($post['author'] ?? '') ?: (cfg('default_author') ?: 'BTW IMF Advisory Team');
    $date = $post['date'] ?? date('Y-m-d');
    $updated = substr((string)($post['updated'] ?? $date), 0, 10);
    $excerpt = trim($post['excerpt'] ?? '') ?: excerpt_from($post['body_md'] ?? '');
    $seoTitle = trim($post['seo_title'] ?? '') ?: ($title . ' | BTW IMF');
    $seoDesc  = trim($post['seo_desc'] ?? '') ?: $excerpt;
    $hero = trim($post['hero'] ?? '');
    $heroAbs = $hero ? (preg_match('#^https?://#', $hero) ? $hero : site_url() . '/' . ltrim($hero, '/')) : site_url() . '/assets/img/btw-imf-logo.png';
    $bodyHtml = md_to_html($post['body_md'] ?? '');

    $ld = [
        [
            '@context' => 'https://schema.org', '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => site_url() . '/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Blog', 'item' => site_url() . '/blogs/'],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $title, 'item' => $url],
            ],
        ],
        [
            '@context' => 'https://schema.org', '@type' => 'Article',
            'headline' => $title, 'description' => $excerpt,
            'image' => $heroAbs, 'inLanguage' => 'en-IN',
            'datePublished' => $date, 'dateModified' => $updated,
            'author' => ['@type' => 'Organization', 'name' => $author, 'url' => site_url()],
            'publisher' => [
                '@type' => 'Organization', 'name' => 'BTW Financial Services & IMF Pvt Ltd',
                'url' => site_url(),
                'logo' => ['@type' => 'ImageObject', 'url' => site_url() . '/assets/img/btw-imf-logo.png'],
            ],
            'mainEntityOfPage' => $url,
        ],
    ];

    $head = str_replace('{{CSS}}', $rel, _head($seoTitle, $seoDesc, $url, 'article', $heroAbs, $ld));
    $niceDate = nice_date($date);
    $catH = h($cat); $titleH = h($title); $authorH = h($author); $excerptH = h($excerpt);
    $waShare = 'https://wa.me/?text=' . rawurlencode($title . ' — ' . $url);
    $heroSrc = $hero ? (preg_match('#^https?://#', $hero) ? $hero : $rel . ltrim($hero, "/")) : '';
    $heroFig = $heroSrc
        ? '<div class="post-hero-img"><img src="' . h($heroSrc) . '" alt="' . h($post['heroAlt'] ?? $title) . '" loading="eager"></div>'
        : '';

    $related = '';
    $others = array_values(array_filter(published_posts(), fn($p) => $p['slug'] !== $slug));
    if ($others) {
        usort($others, fn($a, $b) => (($b['category'] ?? '') === $cat) <=> (($a['category'] ?? '') === $cat));
        $cards = '';
        foreach (array_slice($others, 0, 3) as $p) {
            $pu = '../' . slugify($p['slug']) . '/';
            $cards .= '<a class="post-rel-card" href="' . h($pu) . '"><span class="blog-cat">' . h($p['category'] ?? 'News') . '</span><b>' . h($p['title']) . '</b><span class="prc-date">' . h(nice_date($p['date'] ?? '')) . '</span></a>';
        }
        $related = '<section class="section" style="background:var(--canvas)"><div class="container"><header class="sec-head center"><span class="eyebrow rv">Keep reading</span><h2 class="rv">More from the blog</h2></header><div class="post-rel-grid rv">' . $cards . '</div></div></section>';
    }

    $bodyPage = <<<HTML
<body id="top">
<a class="skip" href="#main">Skip to main content</a>
{$c['topbar']}
{$c['header']}

{$c['drawer']}

<main id="main">

<article>
<section class="page-hero product-hero post-hero">
  <div class="hero-shade" aria-hidden="true"></div>
  <div class="container">
    <nav class="crumb rv" aria-label="Breadcrumb">
      <a href="{$rel}index.html">Home</a>
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
      <a href="{$rel}blogs/">Blog</a>
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
      <span aria-current="page">{$catH}</span>
    </nav>
    <span class="eyebrow eyebrow-light rv">{$catH}</span>
    <h1 class="rv">{$titleH}</h1>
    <p class="post-byline rv"><span>{$authorH}</span><span class="dot"></span><time datetime="{$date}">{$niceDate}</time></p>
  </div>
</section>

<section class="section" style="background:#fff">
  <div class="container post-wrap">
    {$heroFig}
    <div class="prose post-body rv">
{$bodyHtml}
    </div>
    <div class="post-share rv">
      <span>Share</span>
      <a href="{$waShare}" target="_blank" rel="noopener" aria-label="Share on WhatsApp">WhatsApp</a>
      <a href="https://www.linkedin.com/sharing/share-offsite/?url={$url}" target="_blank" rel="noopener" aria-label="Share on LinkedIn">LinkedIn</a>
      <a href="mailto:?subject={$titleH}&amp;body={$url}" aria-label="Share by email">Email</a>
    </div>
  </div>
</section>

$related

<section class="lead-band section">
  <div class="container">
    <div>
      <h2 class="rv">Questions about your cover or investments?</h2>
      <p class="rv">Talk to an IRDAI-licensed advisor — free, and with no obligation.</p>
    </div>
    <a class="btn btn-accent btn-lg rv" href="{$rel}contact/#contact-form">Book a Free Consultation
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
  </div>
</section>
</article>

</main>

{$c['footer']}

{$c['wafab']}

{$c['mobilebar']}

<script src="{$rel}assets/js/main.js" defer></script>
</body>
</html>
HTML;

    $out = str_replace("\r\n", "\n", $head . "\n" . $bodyPage . "\n");
    $dir = POSTS_DIR . '/' . $slug;
    ensure_dir($dir);
    file_put_contents($dir . '/index.html', $out, LOCK_EX);
    return $dir . '/index.html';
}

/* ── render the blog listing ───────────────────────────────────────────── */
function render_listing(): void {
    $depth = 1;
    $rel = '../';
    $c = chrome($depth);
    $posts = published_posts();
    $url = site_url() . '/blogs/';

    $cards = '';
    foreach ($posts as $p) {
        $slug = slugify($p['slug']);
        $cat = in_array(($p['category'] ?? ''), CATEGORIES, true) ? $p['category'] : 'News';
        $ex = trim($p['excerpt'] ?? '') ?: excerpt_from($p['body_md'] ?? '');
        $pu = $slug . '/';   // listing lives at blogs/index.html, posts at blogs/<slug>/
        $cards .= '<article class="blog-card rv" data-cat="' . h($cat) . '">'
            . '<span class="blog-cat">' . h($cat) . '</span>'
            . '<h3><a href="' . h($pu) . '">' . h($p['title']) . '</a></h3>'
            . '<p>' . h($ex) . '</p>'
            . '<div class="blog-meta"><time datetime="' . h($p['date'] ?? '') . '">' . h(nice_date($p['date'] ?? '')) . '</time>'
            . '<span class="dot"></span>'
            . '<a class="blog-more" href="' . h($pu) . '">Read <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a></div>'
            . "</article>\n";
    }
    if ($cards === '') {
        $cards = '<p class="blog-note">No articles published yet — check back soon.</p>';
    }

    $cats = ['all' => 'All'];
    foreach (CATEGORIES as $x) $cats[$x] = $x;
    $filter = '';
    $first = true;
    foreach ($cats as $val => $label) {
        $filter .= '<button type="button"' . ($first ? ' class="is-active"' : '') . ' data-filter="' . h($val) . '" aria-pressed="' . ($first ? 'true' : 'false') . '">' . h($label) . '</button>';
        $first = false;
    }

    $ld = [[
        '@context' => 'https://schema.org', '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => site_url() . '/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Blog', 'item' => $url],
        ],
    ]];
    $head = str_replace('{{CSS}}', $rel, _head(
        'Blog — Insurance, Finance & Travel Updates | BTW IMF',
        'Plain-language updates on insurance and finance, plus travel and visa news that affects Indian families — from the BTW IMF advisory team.',
        $url, 'website', site_url() . '/assets/img/btw-imf-logo.png', $ld
    ));

    $body = <<<HTML
<body id="top">
<a class="skip" href="#main">Skip to main content</a>
{$c['topbar']}
{$c['header']}

{$c['drawer']}

<main id="main">

<section class="page-hero product-hero">
  <div class="hero-shade" aria-hidden="true"></div>
  <svg class="deco deco-dots" width="240" height="160" aria-hidden="true" focusable="false">
    <defs><pattern id="dg" width="22" height="22" patternUnits="userSpaceOnUse"><circle cx="2.2" cy="2.2" r="2.2" fill="rgba(123,191,167,.4)"/></pattern></defs>
    <rect width="240" height="160" fill="url(#dg)"/>
  </svg>
  <div class="container">
    <nav class="crumb rv" aria-label="Breadcrumb">
      <a href="{$rel}index.html">Home</a>
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
      <span aria-current="page">Blog</span>
    </nav>
    <span class="eyebrow eyebrow-light rv">Insights &amp; Updates</span>
    <h1 class="rv">News that affects your<br class="br-d"> money and your travel</h1>
    <p class="page-hero-sub rv">Plain-language updates on insurance and finance, plus the travel and visa news that matters if you or your family travel — written by the BTW IMF team.</p>
  </div>
</section>

<section class="section" style="background:var(--canvas)" id="all">
  <div class="container">
    <header class="sec-head center">
      <span class="eyebrow rv">All articles</span>
      <h2 class="rv">Latest from the blog</h2>
      <p class="lead rv">Filter by topic, or browse everything below.</p>
    </header>
    <div class="blog-filter rv" role="group" aria-label="Filter articles by topic">{$filter}</div>
    <div class="blog-grid">
{$cards}
    </div>
  </div>
</section>

</main>

{$c['footer']}

{$c['wafab']}

{$c['mobilebar']}

<script src="{$rel}assets/js/main.js" defer></script>
</body>
</html>
HTML;

    ensure_dir(POSTS_DIR);
    file_put_contents(POSTS_DIR . '/index.html', str_replace("\r\n", "\n", $head . "\n" . $body . "\n"), LOCK_EX);
}

/* ── sitemap + feed ────────────────────────────────────────────────────── */
function update_sitemap(): void {
    $f = ROOT_DIR . '/sitemap.xml';
    if (!is_file($f)) return;
    $xml = file_get_contents($f);
    // drop existing blog-post entries, keep everything else
    $xml = preg_replace('#\s*<url>\s*<loc>' . preg_quote(site_url(), '#') . '/blogs/[^<]+/</loc>.*?</url>#s', '', $xml);
    $today = date('Y-m-d');
    $entries = '';
    foreach (published_posts() as $p) {
        $loc = site_url() . '/blogs/' . slugify($p['slug']) . '/';
        $lm = substr((string)($p['updated'] ?? $p['date'] ?? $today), 0, 10);
        $entries .= "  <url>\n    <loc>{$loc}</loc>\n    <lastmod>{$lm}</lastmod>\n    <changefreq>monthly</changefreq>\n    <priority>0.60</priority>\n  </url>\n";
    }
    if (strpos($xml, '/blogs/</loc>') === false) {
        $entries = "  <url>\n    <loc>" . site_url() . "/blogs/</loc>\n    <lastmod>{$today}</lastmod>\n    <changefreq>weekly</changefreq>\n    <priority>0.70</priority>\n  </url>\n" . $entries;
    }
    $xml = preg_replace('#\n?</urlset>#', "\n" . $entries . "</urlset>", $xml, 1);
    file_put_contents($f, $xml, LOCK_EX);
}

function render_feed(): void {
    $items = '';
    foreach (array_slice(published_posts(), 0, 20) as $p) {
        $loc = site_url() . '/blogs/' . slugify($p['slug']) . '/';
        $ex = trim($p['excerpt'] ?? '') ?: excerpt_from($p['body_md'] ?? '');
        $pub = date(DATE_RSS, strtotime($p['date'] ?? 'now') ?: time());
        $items .= "  <item>\n    <title>" . h($p['title']) . "</title>\n    <link>{$loc}</link>\n    <guid>{$loc}</guid>\n    <pubDate>{$pub}</pubDate>\n    <description>" . h($ex) . "</description>\n  </item>\n";
    }
    $now = date(DATE_RSS);
    $self = site_url() . '/blogs/feed.xml';
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<rss version=\"2.0\">\n<channel>\n  <title>BTW IMF Blog</title>\n  <link>" . site_url() . "/blogs/</link>\n  <description>Insurance, finance and travel updates from BTW IMF.</description>\n  <lastBuildDate>{$now}</lastBuildDate>\n{$items}</channel>\n</rss>\n";
    ensure_dir(POSTS_DIR);
    file_put_contents(POSTS_DIR . '/feed.xml', $xml, LOCK_EX);
}

/* ── orchestration ─────────────────────────────────────────────────────── */
function publish_all(): void {
    foreach (published_posts() as $p) render_post($p);
    render_listing();
    update_sitemap();
    render_feed();
}
