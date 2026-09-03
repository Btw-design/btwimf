# BTW IMF — Blog Admin

A tiny, dependency-free PHP admin for writing and publishing blog posts. It
**generates static HTML** into `/blogs/` so visitor pages stay fast and cacheable;
posts are also kept as source files in `/_blog-data/`.

## Requirements

- PHP **7.4+** (8.x fine) with `mbstring` (standard on Hostinger).
- The web-server user must be able to **write** to:
  `/_blog-data/`, `/blogs/`, `/assets/img/blog/`, `/sitemap.xml`, `/blogs/feed.xml`,
  and `/admin/config.php` (only during first-time setup).

## One-time setup

1. Upload the whole project to `public_html` (or your web root).
2. Visit **`/admin/setup.php`**. Enter your site URL and choose an admin password
   (10+ characters). This writes `admin/config.php` (a bcrypt hash — the plain
   password is never stored).
3. **Delete `admin/setup.php`** from the server afterwards.
4. Visit `/admin/` and sign in.

If `admin/config.php` can't be written automatically, copy `config.sample.php`
to `config.php` and fill in `password_hash` (run
`php -r 'echo password_hash("your-pass", PASSWORD_DEFAULT), "\n";'`).

## Using it

- **New post** → title, category, excerpt, body (Markdown), optional hero image.
- **Save draft** stores it privately (not linked anywhere).
- **Publish** writes `/blogs/<slug>/index.html`, rebuilds `/blogs/index.html`,
  updates `/sitemap.xml` and `/blogs/feed.xml`.
- **Unpublish** removes the live page but keeps the draft.
- **Rebuild site** (top bar) regenerates everything from `/_blog-data/` — use it
  after deploying code changes, or if a page looks stale.

### Markdown supported

`## Heading` `### Sub` · `**bold**` · `*italic*` · `` `code` `` · `[text](url)` ·
`![alt](url)` · `- ` / `1. ` lists · `> quote` · ` ``` ` code fences · `---` rule.
Raw HTML is not passed through. `javascript:` / `data:` URLs are rejected.

## Security notes

- `/admin/config.php`, `lib.php`, `render.php` and all `.json` are denied over HTTP
  by `admin/.htaccess`; `/_blog-data/` is denied by the root `.htaccess`.
- Sessions are http-only, `SameSite=Lax`, `Secure` over HTTPS, and bound to the
  browser UA. All write actions require a CSRF token. Login is rate-limited
  (8 tries / 15 min per IP).
- Serve the whole site over HTTPS. Consider adding HTTP Basic Auth on `/admin/`
  in Hostinger's control panel as a second layer.
- Keep PHP updated in hPanel.

## Git / deploy

Content is **server-side and git-ignored**: `/_blog-data/`, `/assets/img/blog/`,
`/blogs/<slug>/`, `/blogs/feed.xml`, `/admin/config.php`. The committed
`/blogs/index.html` is a placeholder — the admin overwrites it on the server.

When you deploy code updates, don't overwrite the server's `/blogs/` content
(deploy via FTP, or `git pull` then click **Rebuild site**). To move content
between environments, copy `/_blog-data/` and `/assets/img/blog/`, then Rebuild.
