#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# BTW IMF — STAGING deployment for Ubuntu + Apache (AWS EC2)
#
# SAFE BY DESIGN. This script:
#   • only writes under $DOCROOT (default /var/www/btwimf-new)
#   • only adds a NEW Apache vhost on a NEW port (default 8090)
#   • never edits the btwimf.com vhost, WordPress files, the database, DNS,
#     backups, or anything under the current live DocumentRoot
#   • aborts instead of overwriting an existing vhost or a non-empty docroot
#
# Usage on the EC2 box (a user with sudo):
#   1) upload  btwimf-<hash>.tar.gz  and this script to the server
#   2) sudo DOCROOT=/var/www/btwimf-new PORT=8090 \
#        bash staging-setup.sh ./btwimf-<hash>.tar.gz
#
# Re-runnable. Pass  --dry-run  to print actions without doing anything.
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

DOCROOT="${DOCROOT:-/var/www/btwimf-new}"
PORT="${PORT:-8090}"
SITE_NAME="${SITE_NAME:-btwimf-staging}"
WEB_USER="${WEB_USER:-www-data}"

DRY=0
BUNDLE=""
for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY=1 ;;
    *)         BUNDLE="$arg" ;;
  esac
done

say() { printf '\n\033[1;36m▶ %s\033[0m\n' "$*"; }
ok()  { printf '  \033[32m✓\033[0m %s\n' "$*"; }
warn(){ printf '  \033[33m!\033[0m %s\n' "$*"; }
run() { if [[ $DRY -eq 1 ]]; then printf '  [dry-run] %s\n' "$*"; else eval "$@"; fi; }
die() { printf '\n\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Run with sudo (need to write vhost + reload apache)."
command -v apache2 >/dev/null || die "apache2 not found."
command -v php >/dev/null || die "php (CLI) not found — required to auto-rebuild Blog Admin pages (publish_all())."
VHOST_FILE="/etc/apache2/sites-available/${SITE_NAME}.conf"
PORTS_SNIPPET="/etc/apache2/conf-available/${SITE_NAME}-listen.conf"

# ── guard rails ─────────────────────────────────────────────────────────────
say "Pre-flight checks"
# -R (not -r): sites-enabled/ holds only symlinks to sites-available/, and
# plain -r does not follow symlinks encountered during directory recursion
# (only ones passed directly as arguments) — with -r this always matched
# nothing, so the "is this already a live site?" check below silently never
# fired and this script could be pointed at an enabled (live) docroot without
# being stopped.
LIVE_ROOTS="$(grep -RhoP 'DocumentRoot\s+\K\S+' /etc/apache2/sites-enabled/ 2>/dev/null | sort -u || true)"
if grep -qxF "$DOCROOT" <<<"$LIVE_ROOTS"; then
  die "$DOCROOT is already an ENABLED site's DocumentRoot. Refusing to touch it."
fi
if [[ -e "$DOCROOT" && -n "$(ls -A "$DOCROOT" 2>/dev/null || true)" ]]; then
  warn "$DOCROOT already exists and is not empty — its contents will be replaced with the bundle."
  read -rp "  Continue? [y/N] " a; [[ "$a" == "y" || "$a" == "Y" ]] || die "Aborted by user."
fi
if [[ -e "$VHOST_FILE" ]]; then
  die "$VHOST_FILE already exists. Remove it first if you want to recreate it (this script won't overwrite a vhost)."
fi
if [[ "$PORT" == "80" || "$PORT" == "443" ]]; then
  die "PORT must not be 80/443 for staging. Pick something like 8090."
fi
if ss -ltn 2>/dev/null | grep -q ":${PORT}\b"; then
  die "Port ${PORT} is already in use. Choose a different PORT=."
fi
ok "No collision with live sites, docroot, vhost or port."

# ── preserve Blog Admin / runtime content across a redeploy ─────────────────
# These paths are server-side and git-ignored (see admin/README.md "Git /
# deploy"): the tarball extracted below is a `git archive` of a commit, so it
# never contains them. If $DOCROOT already has content (a redeploy, not a
# first-time setup), snapshot these paths BEFORE the wipe and restore them
# AFTER extraction, so a redeploy can never delete existing Blog Admin
# articles, form submissions, or the admin password.
#   - blogs/*/        : published article directories (NOT blogs/index.html —
#                        that file is the committed Git placeholder until the
#                        auto-rebuild step further down regenerates it)
#   - _blog-data/      : Blog Admin's source-of-truth post data
#   - assets/img/blog/ : uploaded post hero images
#   - blogs/feed.xml   : generated RSS feed
#   - admin/config.php : admin password hash
#   - _submissions/    : saved form-handler.php leads
PRESERVE_TMP=""
if [[ -n "$BUNDLE" && -f "$BUNDLE" && -e "$DOCROOT" && -n "$(ls -A "$DOCROOT" 2>/dev/null || true)" ]]; then
  say "Preserving existing Blog Admin / runtime content"
  PRESERVE_TMP="$(mktemp -d)"
  if [[ $DRY -eq 1 ]]; then
    printf '  [dry-run] snapshot blogs/*/, _blog-data/, assets/img/blog/, blogs/feed.xml, admin/config.php, _submissions/ -> %s\n' "$PRESERVE_TMP"
  else
    if compgen -G "$DOCROOT/blogs/*/" > /dev/null; then
      mkdir -p "$PRESERVE_TMP/blogs"
      for d in "$DOCROOT"/blogs/*/; do cp -a "$d" "$PRESERVE_TMP/blogs/"; done
    fi
    for p in _blog-data assets/img/blog blogs/feed.xml admin/config.php _submissions; do
      if [[ -e "$DOCROOT/$p" ]]; then
        mkdir -p "$PRESERVE_TMP/$(dirname "$p")"
        cp -a "$DOCROOT/$p" "$PRESERVE_TMP/$p"
      fi
    done
  fi
  ok "Snapshotted to $PRESERVE_TMP"
fi

# ── extract bundle ──────────────────────────────────────────────────────────
say "Deploying files to $DOCROOT"
if [[ -n "$BUNDLE" && -f "$BUNDLE" ]]; then
  run "mkdir -p '$DOCROOT'"
  run "rm -rf '${DOCROOT:?}/'* '${DOCROOT}/.htaccess' 2>/dev/null || true"
  run "tar -xzf '$BUNDLE' -C '$DOCROOT'"
  ok "Extracted $(basename "$BUNDLE")"
elif [[ -f "$DOCROOT/index.html" ]]; then
  warn "No bundle given; using files already in $DOCROOT."
else
  die "No bundle passed and $DOCROOT has no index.html. Pass the tar.gz as arg 1."
fi

# ── restore preserved Blog Admin / runtime content ──────────────────────────
if [[ -n "$PRESERVE_TMP" ]]; then
  say "Restoring preserved Blog Admin / runtime content"
  if [[ $DRY -eq 1 ]]; then
    printf '  [dry-run] restore from %s (blogs/index.html left as the Git placeholder)\n' "$PRESERVE_TMP"
  else
    [[ -d "$PRESERVE_TMP/blogs" ]] && cp -a "$PRESERVE_TMP/blogs/." "$DOCROOT/blogs/"
    for p in _blog-data assets/img/blog blogs/feed.xml admin/config.php _submissions; do
      if [[ -e "$PRESERVE_TMP/$p" ]]; then
        mkdir -p "$DOCROOT/$(dirname "$p")"
        cp -a "$PRESERVE_TMP/$p" "$DOCROOT/$p"
      fi
    done
    rm -rf "$PRESERVE_TMP"
  fi
  ok "Restored — blogs/index.html still the Git placeholder here; the auto-rebuild step below fixes it"
fi

# ── runtime-writable dirs (server-managed content) ──────────────────────────
say "Creating writable runtime folders"
for d in _submissions _blog-data assets/img/blog; do
  run "mkdir -p '$DOCROOT/$d'"
done
ok "_submissions, _blog-data, assets/img/blog"

# ── permissions ─────────────────────────────────────────────────────────────
say "Setting ownership + permissions"
run "chown -R ${WEB_USER}:${WEB_USER} '$DOCROOT'"
run "find '$DOCROOT' -type d -exec chmod 755 {} +"
run "find '$DOCROOT' -type f -exec chmod 644 {} +"
run "chmod 775 '$DOCROOT/_submissions' '$DOCROOT/_blog-data' '$DOCROOT/assets/img/blog' '$DOCROOT/admin'"
ok "Owned by ${WEB_USER}; dirs 755 / files 644; runtime dirs + admin/ group-writable"

# ── auto-rebuild Blog Admin generated pages from the preserved _blog-data/ ──
# Runs the exact same publish_all() function Blog Admin's own "Rebuild site"
# button calls (admin/render.php), as $WEB_USER. Regenerates blogs/index.html,
# sitemap.xml (blog entries only — everything else in it is left alone), and
# blogs/feed.xml from whatever is in _blog-data/ (empty on a first-time setup,
# or the just-restored posts on a redeploy) — no manual "Rebuild site" click
# needed either way.
say "Auto-rebuilding Blog Admin pages (blogs/index.html, sitemap.xml, blogs/feed.xml)"
REBUILD_CMD="sudo -u '$WEB_USER' php -r \"require '$DOCROOT/admin/render.php'; publish_all(); echo 'rebuilt ' . count(published_posts()) . ' post(s)' . PHP_EOL;\""
if [[ $DRY -eq 1 ]]; then
  printf '  [dry-run] %s\n' "$REBUILD_CMD"
else
  eval "$REBUILD_CMD" || warn "Blog Admin rebuild (publish_all) failed — blogs/index.html may be the Git placeholder. Check admin/config.php exists and PHP can read _blog-data/."
fi
ok "Blog listing / sitemap / feed regenerated"

# ── apache modules ──────────────────────────────────────────────────────────
say "Enabling Apache modules (rewrite headers deflate expires)"
run "a2enmod -q rewrite headers deflate expires || true"
if ! apache2ctl -M 2>/dev/null | grep -q 'php'; then
  warn "No PHP module detected in Apache. form-handler.php and /admin/ need PHP."
  warn "Install one, e.g.:  sudo apt install -y libapache2-mod-php php-mbstring"
fi

# ── vhost on a dedicated port ──────────────────────────────────────────────
say "Writing staging vhost -> $VHOST_FILE  (port $PORT)"
if [[ $DRY -eq 0 ]]; then
  cat > "$PORTS_SNIPPET" <<EOF
# added by ${SITE_NAME} staging — safe to remove with: sudo a2disconf ${SITE_NAME}-listen
Listen ${PORT}
EOF
  cat > "$VHOST_FILE" <<EOF
# BTW IMF staging — DELETE-SAFE. Does not affect the btwimf.com vhost.
<VirtualHost *:${PORT}>
    ServerName btwimf-staging.local
    DocumentRoot ${DOCROOT}

    <Directory ${DOCROOT}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # keep staging out of search engines regardless of robots.txt
    Header always set X-Robots-Tag "noindex, nofollow"

    ErrorLog  \${APACHE_LOG_DIR}/${SITE_NAME}-error.log
    CustomLog \${APACHE_LOG_DIR}/${SITE_NAME}-access.log combined
</VirtualHost>
EOF
fi
run "a2enconf -q ${SITE_NAME}-listen"
run "a2ensite -q ${SITE_NAME}"
say "Testing Apache config"
run "apache2ctl configtest"
run "systemctl reload apache2"
ok "Apache reloaded"

# ── done ───────────────────────────────────────────────────────────────────
IP="$(curl -s --max-time 3 http://169.254.169.254/latest/meta-data/public-ipv4 2>/dev/null || true)"
[[ -z "$IP" ]] && IP="<your-ec2-public-ip>"
cat <<EOF

=============================================================
 STAGING IS UP.  Staging URL:   http://${IP}:${PORT}/
=============================================================

Next (all inside ${DOCROOT}, nothing live is touched):

 1. Open inbound TCP ${PORT} in the EC2 security group (My IP only is fine).

 2. Form emails — edit  form-handler.php  and set RECIPIENT + FROM_ADDR.
    For mail() to work:  sudo apt install -y postfix   (choose 'Internet Site').
    Every submission is also saved to _submissions/*.jsonl regardless.

 3. Blog admin — visit  http://${IP}:${PORT}/admin/setup.php , set a password,
    then DELETE admin/setup.php on the server. Sign in at /admin/.

 4. Smoke test:  /  ·  /products/car-insurance/  ·  /calculator/  ·  /blogs/  ·
    a bad URL -> /404.html  ·  submit the homepage contact form.

To remove staging completely later:
  sudo a2dissite ${SITE_NAME}; sudo a2disconf ${SITE_NAME}-listen
  sudo rm ${VHOST_FILE} ${PORTS_SNIPPET}; sudo systemctl reload apache2
  sudo rm -rf ${DOCROOT}
EOF
