<?php
/**
 * BTW IMF blog admin — login + dashboard + editor.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

if (!is_configured()) { header('Location: setup.php'); exit; }
boot_session();

if (($_GET['do'] ?? '') === 'logout') { logout(); header('Location: index.php'); exit; }

$loginErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    if (!rl_check()) {
        $loginErr = 'Too many attempts. Wait 15 minutes and try again.';
    } elseif (login((string)($_POST['password'] ?? ''))) {
        header('Location: index.php'); exit;
    } else {
        rl_hit();
        $loginErr = 'Incorrect password.';
    }
}

$authed = is_authed();
$csrf = $authed ? csrf_token() : '';
$view = $_GET['view'] ?? 'list';
$editing = null;
if ($authed && $view === 'edit' && !empty($_GET['slug'])) {
    $editing = load_post((string) $_GET['slug']);
}
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= $authed ? 'Dashboard' : 'Sign in' ?> · BTW IMF Blog Admin</title>
<link rel="stylesheet" href="assets/admin.css">
</head>
<body class="<?= $authed ? 'admin-app' : 'admin-auth' ?>">
<?php if (!$authed): ?>

  <div class="auth-card">
    <div class="admin-brand"><span>BTW IMF</span> Blog Admin</div>
    <h1>Sign in</h1>
    <?php if ($loginErr): ?><p class="err"><?= h($loginErr) ?></p><?php endif; ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="action" value="login">
      <label>Password <input type="password" name="password" autofocus required></label>
      <button class="btn" type="submit">Sign in</button>
    </form>
  </div>

<?php else: ?>

  <header class="admin-top">
    <div class="admin-brand"><span>BTW IMF</span> Blog Admin</div>
    <nav>
      <a href="index.php"<?= $view === 'list' ? ' class="on"' : '' ?>>All posts</a>
      <a href="index.php?view=edit" class="btn btn-sm">New post</a>
      <button type="button" id="rebuildBtn" class="btn btn-ghost btn-sm">Rebuild site</button>
      <a href="index.php?do=logout" class="muted">Sign out</a>
    </nav>
  </header>

  <main class="admin-main" data-csrf="<?= h($csrf) ?>" data-site="<?= h(site_url()) ?>">

  <?php if ($view === 'edit'): ?>
    <?php
      $p = $editing ?? [];
      $val = fn($k, $d = '') => h($p[$k] ?? $d);
    ?>
    <div class="editor">
      <div class="ed-main">
        <a class="muted back" href="index.php">&larr; All posts</a>
        <h1><?= $editing ? 'Edit post' : 'New post' ?></h1>

        <label class="fld">Title
          <input type="text" id="f-title" value="<?= $val('title') ?>" placeholder="How zero-depreciation cover works" required>
        </label>

        <div class="row">
          <label class="fld">URL slug
            <input type="text" id="f-slug" value="<?= $val('slug') ?>" placeholder="auto from title">
          </label>
          <label class="fld">Category
            <select id="f-category">
              <?php foreach (CATEGORIES as $c): ?>
                <option<?= (($p['category'] ?? 'News') === $c) ? ' selected' : '' ?>><?= h($c) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="fld">Publish date
            <input type="date" id="f-date" value="<?= $val('date', date('Y-m-d')) ?>">
          </label>
        </div>

        <label class="fld">Excerpt <span class="hint">— 1–2 lines for cards, search and social. Auto-generated if blank.</span>
          <textarea id="f-excerpt" rows="2" placeholder="Short summary…"><?= $val('excerpt') ?></textarea>
        </label>

        <div class="fld">
          <div class="ed-toolbar">
            <span>Body <span class="hint">— Markdown: <code>## Heading</code>, <code>**bold**</code>, <code>[link](url)</code>, <code>- list</code>, <code>&gt; quote</code></span></span>
            <button type="button" id="previewToggle" class="btn btn-ghost btn-sm">Preview</button>
          </div>
          <textarea id="f-body" rows="22" class="mono" placeholder="Write your article in Markdown…"><?= $val('body_md') ?></textarea>
          <div id="preview" class="prose preview" hidden></div>
        </div>
      </div>

      <aside class="ed-side">
        <div class="card">
          <h2>Publish</h2>
          <p class="muted" id="statusLine">Status: <b><?= h($p['status'] ?? 'draft') ?></b></p>
          <div class="btn-col">
            <button type="button" id="publishBtn" class="btn">Publish</button>
            <button type="button" id="draftBtn" class="btn btn-ghost">Save draft</button>
            <?php if ($editing): ?>
              <a class="btn btn-ghost" id="viewLink" href="<?= h(site_url() . '/blogs/' . slugify($p['slug'] ?? '') . '/') ?>" target="_blank" rel="noopener"<?= (($p['status'] ?? '') === 'published') ? '' : ' hidden' ?>>View live &nearr;</a>
              <button type="button" id="unpublishBtn" class="btn btn-ghost"<?= (($p['status'] ?? '') === 'published') ? '' : ' hidden' ?>>Unpublish</button>
              <button type="button" id="deleteBtn" class="btn btn-danger">Delete</button>
            <?php endif; ?>
          </div>
          <p id="edMsg" class="ed-msg" hidden></p>
        </div>

        <div class="card">
          <h2>Hero image</h2>
          <div id="heroPreview" class="hero-prev<?= empty($p['hero']) ? ' empty' : '' ?>">
            <?php if (!empty($p['hero'])): ?><img src="<?= h('../' . ltrim($p['hero'], '/')) ?>" alt=""><?php endif; ?>
          </div>
          <input type="file" id="f-heroFile" accept="image/*">
          <input type="hidden" id="f-hero" value="<?= $val('hero') ?>">
          <label class="fld">Image alt text
            <input type="text" id="f-heroAlt" value="<?= $val('heroAlt') ?>" placeholder="Describe the image">
          </label>
        </div>

        <details class="card">
          <summary>SEO overrides</summary>
          <label class="fld">SEO title
            <input type="text" id="f-seo_title" value="<?= $val('seo_title') ?>" placeholder="defaults to: Title | BTW IMF">
          </label>
          <label class="fld">Meta description
            <textarea id="f-seo_desc" rows="2" placeholder="defaults to the excerpt"><?= $val('seo_desc') ?></textarea>
          </label>
          <label class="fld">Author
            <input type="text" id="f-author" value="<?= $val('author', (string) cfg('default_author')) ?>">
          </label>
        </details>
      </aside>
    </div>

  <?php else: ?>
    <?php $posts = all_posts(); ?>
    <div class="list-head">
      <h1>Posts <span class="muted">(<?= count($posts) ?>)</span></h1>
    </div>
    <?php if (!$posts): ?>
      <p class="empty">No posts yet. <a href="index.php?view=edit">Write your first one →</a></p>
    <?php else: ?>
      <table class="post-table">
        <thead><tr><th>Title</th><th>Category</th><th>Date</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($posts as $p): $s = $p['status'] ?? 'draft'; ?>
          <tr>
            <td><a href="index.php?view=edit&amp;slug=<?= h(slugify($p['slug'])) ?>"><?= h($p['title']) ?></a><br><span class="muted">/blogs/<?= h(slugify($p['slug'])) ?>/</span></td>
            <td><?= h($p['category'] ?? '—') ?></td>
            <td><?= h(nice_date($p['date'] ?? '')) ?></td>
            <td><span class="pill pill-<?= $s ?>"><?= h($s) ?></span></td>
            <td class="ta-r">
              <?php if ($s === 'published'): ?><a class="muted" href="<?= h(site_url() . '/blogs/' . slugify($p['slug']) . '/') ?>" target="_blank" rel="noopener">view &nearr;</a> &middot; <?php endif; ?>
              <a href="index.php?view=edit&amp;slug=<?= h(slugify($p['slug'])) ?>">edit</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?php endif; ?>

  </main>
  <script src="assets/admin.js"></script>

<?php endif; ?>
</body></html>
