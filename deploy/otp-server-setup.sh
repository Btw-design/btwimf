#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# BTW IMF — one-time server setup for Email OTP + Cloudflare Turnstile
#
# Creates (outside the web root, never in Git):
#   /etc/btwimf/secrets.php     root:www-data 640  — OTP signing key + Turnstile keys
#   /var/lib/btwimf-otp/        www-data 700       — OTP/rate-limit state (sessions/, rl/)
#
#   sudo bash otp-server-setup.sh              # first-time setup with Cloudflare's
#                                              # DUMMY Turnstile keys (always pass)
#   sudo bash otp-server-setup.sh --set-keys   # later: type your REAL Turnstile
#                                              # Site Key + Secret Key (secret is
#                                              # read silently, never echoed/logged)
#
# Safe to re-run: an existing OTP signing key is preserved; the previous
# secrets file is backed up (mode 600) before any change.
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

CONF_DIR=/etc/btwimf
CONF=$CONF_DIR/secrets.php
DATA=/var/lib/btwimf-otp
WEB_USER="${WEB_USER:-www-data}"
DUMMY_SITE='1x00000000000000000000AA'
DUMMY_SECRET='1x0000000000000000000000000000000AA'

[[ $EUID -eq 0 ]] || { echo "Run with sudo." >&2; exit 1; }
command -v php >/dev/null || { echo "php CLI not found." >&2; exit 1; }

SITE_KEY="$DUMMY_SITE"; SECRET="$DUMMY_SECRET"; HMAC=""

# keep the existing signing key + current Turnstile values when re-running
if [[ -f "$CONF" ]]; then
  cp -p "$CONF" "$CONF.bak.$(date +%Y%m%d-%H%M%S)"; chmod 600 "$CONF".bak.*
  HMAC="$(php -r '$c=include $argv[1]; echo $c["hmac_key"] ?? "";' "$CONF")"
  SITE_KEY="$(php -r '$c=include $argv[1]; echo $c["turnstile_site_key"] ?? "";' "$CONF")"
  SECRET="$(php -r '$c=include $argv[1]; echo $c["turnstile_secret"] ?? "";' "$CONF")"
  [[ -n "$SITE_KEY" ]] || SITE_KEY="$DUMMY_SITE"
  [[ -n "$SECRET"   ]] || SECRET="$DUMMY_SECRET"
fi
[[ -n "$HMAC" ]] || HMAC="$(head -c 48 /dev/urandom | od -An -tx1 | tr -d ' \n')"

if [[ "${1:-}" == "--set-keys" ]]; then
  read -r  -p  "Turnstile SITE key (public, starts with 0x…): " SITE_KEY
  read -r -s -p "Turnstile SECRET key (hidden as you type):    " SECRET; echo
  [[ -n "$SITE_KEY" && -n "$SECRET" ]] || { echo "Both keys are required." >&2; exit 1; }
  if [[ "$SECRET" == 1x0000* || "$SITE_KEY" == 1x0000* ]]; then
    echo "Note: these are Cloudflare's DUMMY test keys — real keys start with 0x… (site) / 0x… (secret)."
  fi
fi

install -d -m 750 -o root -g "$WEB_USER" "$CONF_DIR"
umask 077
TMP="$(mktemp "$CONF_DIR/.secrets.XXXXXX")"
{
  echo '<?php'
  echo '// BTW IMF OTP / Turnstile secrets — NOT in Git, NOT web accessible.'
  echo 'return ['
  printf "  'hmac_key'           => '%s',\n" "$HMAC"
  printf "  'turnstile_site_key' => '%s',\n" "$SITE_KEY"
  printf "  'turnstile_secret'   => '%s',\n" "$SECRET"
  printf "  'data_dir'           => '%s',\n" "$DATA"
  echo '];'
} > "$TMP"
php -l "$TMP" >/dev/null
chown root:"$WEB_USER" "$TMP"; chmod 640 "$TMP"
mv -f "$TMP" "$CONF"

install -d -m 700 -o "$WEB_USER" -g "$WEB_USER" "$DATA" "$DATA/sessions" "$DATA/rl"

echo
echo "OK  $CONF        $(stat -c '%U:%G %a' "$CONF")"
echo "OK  $DATA        $(stat -c '%U:%G %a' "$DATA")"
if [[ "$SECRET" == 1x0000* ]]; then
  echo "Turnstile: DUMMY test keys are active (always pass). Replace them with:  sudo bash $0 --set-keys"
else
  echo "Turnstile: real keys are active (site key $SITE_KEY)."
fi
