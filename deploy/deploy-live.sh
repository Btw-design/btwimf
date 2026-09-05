#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# BTW IMF — LIVE deployment for the already-enabled btwimf.com docroot
# (AWS EC2, Ubuntu + Apache). Formalizes the manual procedure used for every
# deploy so far (WordPress->static cutover, favicon update): download a
# commit from GitHub codeload, build it in a NEW directory, carry forward
# Blog Admin / runtime content, then atomically swap it into place. The
# previous live directory is renamed aside, never deleted, so every deploy
# is instantly reversible.
#
# SAFE BY DESIGN:
#   • never extracts on top of the live directory — always builds fresh in
#     a sibling directory, then does one atomic `mv` swap
#   • never deletes anything: the pre-deploy directory is kept as
#     ${DOCROOT}.pre-deploy-<git-sha>-<timestamp>
#   • carries forward the same server-side, git-ignored paths documented in
#     admin/README.md ("Git / deploy") and .gitignore, so a Git deployment
#     can never remove existing Blog Admin content:
#       - blogs/*/          (published article directories)
#       - _blog-data/        (Blog Admin source-of-truth post data)
#       - assets/img/blog/   (uploaded post hero images)
#       - blogs/feed.xml     (generated RSS feed)
#       - admin/config.php   (admin password hash)
#       - _submissions/      (saved form-handler.php leads)
#   • the committed blogs/index.html is NOT carried forward — it stays the
#     Git version from the new commit, but this script then AUTOMATICALLY
#     runs Blog Admin's own "Rebuild site" function (publish_all(), from
#     admin/render.php) as the web user, before the swap, so blogs/index.html,
#     sitemap.xml (blog entries only) and blogs/feed.xml are regenerated from
#     the just-restored _blog-data/ — no manual "Rebuild site" click needed,
#     and the live site is never shown a stale blog listing after a deploy.
#     If this rebuild fails, the deploy aborts before the swap — the current
#     live site is left completely untouched.
#   • aborts if $DOCROOT is not already an enabled Apache site (use
#     staging-setup.sh for a brand-new / non-live deploy instead)
#
# Usage on the EC2 box (a user with sudo):
#   sudo bash deploy-live.sh <git-sha-or-ref>          # e.g. main, or a full SHA
#   sudo bash deploy-live.sh <git-sha-or-ref> --dry-run # preview only, no changes
#
# Requires network access to codeload.github.com (repo is public).
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

DOCROOT="${DOCROOT:-/var/www/btwimf-new}"
WEB_USER="${WEB_USER:-www-data}"
REPO="${REPO:-Btw-design/btwimf}"

DRY=0
REF=""
for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY=1 ;;
    *)         REF="$arg" ;;
  esac
done

say() { printf '\n\033[1;36m▶ %s\033[0m\n' "$*"; }
ok()  { printf '  \033[32m✓\033[0m %s\n' "$*"; }
warn(){ printf '  \033[33m!\033[0m %s\n' "$*"; }
run() { if [[ $DRY -eq 1 ]]; then printf '  [dry-run] %s\n' "$*"; else eval "$@"; fi; }
die() { printf '\n\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

[[ -n "$REF" ]] || die "Usage: sudo bash deploy-live.sh <git-sha-or-ref> [--dry-run]"
[[ $EUID -eq 0 ]] || die "Run with sudo (need to chown/reload apache)."
command -v apache2 >/dev/null || die "apache2 not found."
command -v curl >/dev/null || die "curl not found."
command -v php >/dev/null || die "php (CLI) not found — required to auto-rebuild Blog Admin pages (publish_all())."

# ── guard rails ─────────────────────────────────────────────────────────────
say "Pre-flight checks"
# -R (not -r): sites-enabled/ holds only symlinks to sites-available/, and
# plain -r does not follow symlinks encountered during directory recursion
# (only ones passed directly as arguments) — with -r this always matches
# nothing and the check below would incorrectly refuse every real deploy.
LIVE_ROOTS="$(grep -RhoP 'DocumentRoot\s+\K\S+' /etc/apache2/sites-enabled/ 2>/dev/null | sort -u || true)"
grep -qxF "$DOCROOT" <<<"$LIVE_ROOTS" \
  || die "$DOCROOT is not an ENABLED site's DocumentRoot. This script is for redeploying the LIVE site only — use staging-setup.sh for a fresh/non-live docroot."
[[ -d "$DOCROOT" ]] || die "$DOCROOT does not exist."
ok "$DOCROOT confirmed as the live, enabled DocumentRoot."

TS="$(date +%Y%m%d-%H%M%S)"
NEW_DIR="${DOCROOT}.new-${REF//\//_}-${TS}"
OLD_DIR="${DOCROOT}.pre-deploy-${REF//\//_}-${TS}"
TARBALL="/tmp/btwimf-${REF//\//_}-${TS}.tar.gz"

if [[ -e "$NEW_DIR" ]]; then die "$NEW_DIR already exists. Remove it or wait a second and re-run."; fi

# ── download the commit ─────────────────────────────────────────────────────
say "Downloading $REF from GitHub codeload"
run "curl -sL 'https://codeload.github.com/${REPO}/tar.gz/${REF}' -o '$TARBALL'"
if [[ $DRY -eq 0 ]]; then
  [[ -s "$TARBALL" ]] || die "Download failed or empty: $TARBALL"
fi
ok "Downloaded to $TARBALL"

# ── build the new tree in a sibling directory ───────────────────────────────
say "Building new deployment in $NEW_DIR"
run "mkdir -p '$NEW_DIR'"
run "tar -xzf '$TARBALL' -C '$NEW_DIR' --strip-components=1"
ok "Extracted"

# ── carry forward Blog Admin / runtime content from the CURRENT live dir ───
# (see header comment for the exact list and the blogs/index.html exception)
say "Carrying forward Blog Admin / runtime content from current live docroot"
if [[ $DRY -eq 1 ]]; then
  printf '  [dry-run] copy blogs/*/, _blog-data/, assets/img/blog/, blogs/feed.xml, admin/config.php, _submissions/\n'
  printf '  [dry-run]   from %s -> %s (blogs/index.html left as the new Git version)\n' "$DOCROOT" "$NEW_DIR"
else
  if compgen -G "$DOCROOT/blogs/*/" > /dev/null; then
    mkdir -p "$NEW_DIR/blogs"
    for d in "$DOCROOT"/blogs/*/; do cp -a "$d" "$NEW_DIR/blogs/"; done
  fi
  for p in _blog-data assets/img/blog blogs/feed.xml admin/config.php _submissions; do
    if [[ -e "$DOCROOT/$p" ]]; then
      mkdir -p "$NEW_DIR/$(dirname "$p")"
      cp -a "$DOCROOT/$p" "$NEW_DIR/$p"
    fi
  done
fi
ok "Carried forward — blogs/index.html stayed the new commit's Git version"

# ── permissions ─────────────────────────────────────────────────────────────
say "Setting ownership + permissions on $NEW_DIR"
run "chown -R ${WEB_USER}:${WEB_USER} '$NEW_DIR'"
run "find '$NEW_DIR' -type d -exec chmod 755 {} +"
run "find '$NEW_DIR' -type f -exec chmod 644 {} +"
run "mkdir -p '$NEW_DIR/_submissions' '$NEW_DIR/_blog-data' '$NEW_DIR/assets/img/blog'"
run "chmod 775 '$NEW_DIR/_submissions' '$NEW_DIR/_blog-data' '$NEW_DIR/assets/img/blog' '$NEW_DIR/admin'"
ok "Owned by ${WEB_USER}; dirs 755 / files 644; runtime dirs + admin/ group-writable"

# ── auto-rebuild Blog Admin generated pages from the preserved _blog-data/ ──
# Runs the exact same publish_all() function Blog Admin's own "Rebuild site"
# button calls (admin/render.php), as $WEB_USER, directly on the staged new
# tree — BEFORE the swap, so a failure here aborts with the live site still
# fully intact. Regenerates blogs/index.html, sitemap.xml (blog entries only,
# via update_sitemap()'s targeted replace — nothing else in it is touched),
# and blogs/feed.xml, all from the _blog-data/ this run just carried forward.
say "Auto-rebuilding Blog Admin pages (blogs/index.html, sitemap.xml, blogs/feed.xml)"
REBUILD_CMD="sudo -u '$WEB_USER' php -r \"require '$NEW_DIR/admin/render.php'; publish_all(); echo 'rebuilt ' . count(published_posts()) . ' post(s)' . PHP_EOL;\""
if [[ $DRY -eq 1 ]]; then
  printf '  [dry-run] %s\n' "$REBUILD_CMD"
else
  eval "$REBUILD_CMD" || die "Blog Admin rebuild (publish_all) failed — aborting before swap. The current live site is untouched."
fi
ok "Blog listing / sitemap / feed regenerated from preserved _blog-data/"

# ── sanity check before the swap ────────────────────────────────────────────
say "Sanity-checking the new tree"
run "test -f '$NEW_DIR/index.html'" || die "New tree has no index.html — aborting before swap."
if command -v php >/dev/null 2>&1 && [[ $DRY -eq 0 ]]; then
  php -l "$NEW_DIR/form-handler.php" >/dev/null || die "form-handler.php failed php -l — aborting before swap."
fi
ok "Looks good."

# ── atomic swap ──────────────────────────────────────────────────────────────
say "Swapping into place (old docroot kept at $OLD_DIR — nothing is deleted)"
run "mv '$DOCROOT' '$OLD_DIR'"
run "mv '$NEW_DIR' '$DOCROOT'"
ok "Swapped."

run "rm -f '$TARBALL'"

say "Reloading Apache"
run "apache2ctl configtest"
run "systemctl reload apache2"
ok "Apache reloaded (no vhost/config changes made — DocumentRoot path is unchanged)."

cat <<EOF

=============================================================
 DEPLOYED ${REF} to ${DOCROOT}
=============================================================

 Previous live directory kept at:  ${OLD_DIR}
 Rollback:  sudo rm -rf '${DOCROOT}' && sudo mv '${OLD_DIR}' '${DOCROOT}' && sudo systemctl reload apache2

 Verify:
   - Spot-check a page or two on the live site.
   - Check that existing /blogs/<slug>/ articles still 200 (not 404).
   - /blogs/ should already show every published post — this run auto-ran
     Blog Admin's rebuild, no manual "Rebuild site" click needed.
   - If you published new blog posts SINCE this deploy started, they were
     NOT part of this run's carry-forward (it snapshotted at deploy time) —
     verify those are still present, and if not, restore from ${OLD_DIR}.

 Once you're satisfied, the old directory can be removed manually whenever
 you're ready — this script never deletes it for you:
   sudo rm -rf '${OLD_DIR}'
EOF
