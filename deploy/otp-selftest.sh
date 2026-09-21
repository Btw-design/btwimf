#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# BTW IMF — backend self-test for Email OTP + Turnstile + form-handler.php
#
# Runs the site's PHP files in a THROW-AWAY copy behind PHP's built-in server,
# with dummy Turnstile keys, a private state directory and a FAKE sendmail that
# captures mail to files — so nothing is e-mailed and no production data
# (_submissions/, /var/lib/btwimf-otp, /etc/btwimf) is touched.
#
#   bash otp-selftest.sh /path/to/site-checkout
#   (the checkout must contain form-handler.php, otp-api.php, lib/otp.php)
#
# Needs: php (CLI 8.x with curl), curl, python3.  Takes ~2.5 minutes (it waits
# out the 60-second resend cooldown once).
# ─────────────────────────────────────────────────────────────────────────────
set -u
SRC="${1:?usage: otp-selftest.sh /path/to/site-checkout}"
PORT="${PORT:-8181}"
T="$(mktemp -d)"
SITE="$T/site"
BASE="http://127.0.0.1:$PORT"
ORIGIN="Origin: $BASE"
TOKEN='XXXX.DUMMY.TOKEN.XXXX'
PASS=0; FAIL=0

cleanup() { [[ -n "${SRV:-}" ]] && kill "$SRV" 2>/dev/null; rm -rf "$T"; }
trap cleanup EXIT

mkdir -p "$SITE/lib" "$T/data" "$T/mail"
cp "$SRC/form-handler.php" "$SRC/otp-api.php" "$SITE/"
cp "$SRC/lib/otp.php" "$SITE/lib/"

write_secrets() {   # $1 = turnstile secret
  cat > "$T/secrets.php" <<PHP
<?php return [
  'hmac_key' => '$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')',
  'turnstile_site_key' => '1x00000000000000000000AA',
  'turnstile_secret' => '$1',
  'data_dir' => '$T/data',
];
PHP
}
write_secrets '1x0000000000000000000000000000000AA'   # Cloudflare dummy: always passes

cat > "$T/fake-sendmail.sh" <<SH
#!/bin/sh
cat > "$T/mail/\$(date +%s%N).eml"
SH
chmod +x "$T/fake-sendmail.sh"

BTW_SECRETS_FILE="$T/secrets.php" php -d "sendmail_path=$T/fake-sendmail.sh -t -i" \
  -S "127.0.0.1:$PORT" -t "$SITE" >"$T/server.log" 2>&1 &
SRV=$!
sleep 1.5
curl -s -o /dev/null "$BASE/otp-api.php?a=init" || { echo "server did not start"; cat "$T/server.log"; exit 1; }

# ── helpers ──────────────────────────────────────────────────────────────────
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s   -> %s\n' "$1" "${2:-}"; }
check(){ if [[ "$2" == "$3" ]]; then ok "$1"; else bad "$1" "expected [$3] got [$2]"; fi; }
has()  { if grep -qF -- "$3" <<<"$2"; then ok "$1"; else bad "$1" "missing [$3] in: ${2:0:160}"; fi; }
jget() { python3 -c 'import sys,json
try: print(json.load(sys.stdin).get(sys.argv[1],""))
except Exception: print("")' "$1"; }
newjar() { JAR="$T/jar-$RANDOM$RANDOM"; CSRF="$(curl -s -c "$JAR" -H "$ORIGIN" "$BASE/otp-api.php?a=init" | jget csrf)"; }
api()  { curl -s -b "$JAR" -c "$JAR" -H "$ORIGIN" -H "X-CSRF-Token: $CSRF" "$@" "$BASE/otp-api.php"; }
send_otp() { api -F a=send -F "form=${3:-contact}" --form-string "email=$1" --form-string "name=${2:-Test User}" -F "_cft=$TOKEN"; }
verify_otp() { api -F a=verify -F "otp=$1"; }
last_otp() { local f; f="$(ls -1t "$T"/mail/*.eml 2>/dev/null | head -1)"; [[ -n "$f" ]] && grep -o 'code is: [0-9]\{6\}' "$f" | head -1 | grep -o '[0-9]\{6\}'; }
mails() { ls -1 "$T"/mail/*.eml 2>/dev/null | wc -l; }
ts_ms() { echo $(( $(date +%s) * 1000 - 5000 )); }
handler() { curl -s -b "$JAR" -c "$JAR" -H "$ORIGIN" -X POST "$BASE/form-handler.php" "$@"; }

echo "== 1. init / CSRF / origin =="
newjar
[[ -n "$CSRF" ]] && ok "init returns a CSRF token" || bad "init CSRF"
has "init returns the (dummy) Turnstile site key" "$(curl -s -b "$JAR" "$BASE/otp-api.php?a=init")" '1x00000000000000000000AA'
r="$(curl -s -b "$JAR" -H "X-CSRF-Token: $CSRF" -F a=send -F form=contact -F email=a@b.co -F "_cft=$TOKEN" "$BASE/otp-api.php")"
has "send without Origin/Referer is rejected" "$r" 'Bad origin'
r="$(curl -s -b "$JAR" -H "$ORIGIN" -F a=send -F form=contact -F email=a@b.co -F "_cft=$TOKEN" "$BASE/otp-api.php")"
has "send without CSRF header is rejected" "$r" 'session has expired'
r="$(curl -s -H "$ORIGIN" -H "X-CSRF-Token: $CSRF" -F a=send -F form=contact -F email=a@b.co -F "_cft=$TOKEN" "$BASE/otp-api.php")"
has "send without the session cookie is rejected" "$r" 'session has expired'
r="$(api -F a=send -F form=nonsense -F email=a@b.co -F "_cft=$TOKEN")"
has "send for a non-OTP form type is rejected" "$r" 'Unknown form'

echo "== 2. invalid email =="
for e in "" "not-an-email" "a@b" "a b@c.com" "x@y.com,z@w.com" "<x>@y.com"; do
  r="$(send_otp "$e")"; has "invalid email [$e] rejected" "$r" 'valid email'
done
check "no OTP mail was sent for invalid emails" "$(mails)" "0"

echo "== 3. valid email -> OTP mail =="
newjar
r="$(send_otp '  Tester@Example.COM ' 'Asha Mehta')"
check "send ok (trim + normalise)" "$(jget ok <<<"$r")" "True"
check "cooldown reported = 60" "$(jget cooldown <<<"$r")" "60"
check "expiry reported = 300" "$(jget expires <<<"$r")" "300"
OTP="$(last_otp)"
[[ "$OTP" =~ ^[0-9]{6}$ ]] && ok "mail contains a 6-digit OTP" || bad "OTP in mail" "$OTP"
grep -q 'Subject: =?UTF-8?B?' "$T"/mail/*.eml && ok "subject is MIME-encoded" || bad "subject header"
[[ "$(base64 -d <<<"$(grep -o 'Subject: =?UTF-8?B?[^?]*' "$(ls -1t "$T"/mail/*.eml | head -1)" | sed 's/.*B?//')" 2>/dev/null)" == 'Verify Your Email – BTW IMF' ]] && ok "subject text = Verify Your Email – BTW IMF" || bad "subject text"
has "mail greets the user by name" "$(cat "$(ls -1t "$T"/mail/*.eml | head -1)")" 'Hello Asha Mehta'
has "mail states 5-minute validity" "$(cat "$(ls -1t "$T"/mail/*.eml | head -1)")" '5 minutes'
has "mail has the do-not-share note" "$(cat "$(ls -1t "$T"/mail/*.eml | head -1)")" 'Do not share this OTP'
[[ "$r" != *"$OTP"* ]] && ok "OTP is NOT in the API response" || bad "OTP leaked in response"
! grep -rqF -- "\"$OTP\"" "$T/data" && ok "OTP is NOT stored in plain text (state files)" || bad "OTP found in state files"
! grep -qF -- "$OTP" "$T/server.log" && ok "OTP is NOT in the server log" || bad "OTP in server log"

echo "== 4. resend cooldown =="
r="$(send_otp 'tester@example.com')"
has "resend before 60s -> 'Please wait before requesting a new OTP.'" "$r" 'Please wait before requesting a new OTP.'
check "cooldown response carries retry seconds" "$( [[ "$(jget retry <<<"$r")" =~ ^[0-9]+$ ]] && echo yes )" "yes"
check "no second mail was sent" "$(mails)" "1"
newjar   # fresh session, SAME email: the per-email cooldown still applies
r="$(send_otp 'tester@example.com')"
has "per-EMAIL cooldown holds across sessions" "$r" 'Please wait'

echo "== 5. wrong OTP / expiry / attempts / reuse (session 1) =="
newjar; R="$(send_otp 'wrong@example.com')"; OTPW="$(last_otp)"
BADOTP="$(( (10#$OTPW + 1) % 1000000 ))"; BADOTP="$(printf '%06d' "$BADOTP")"
r="$(verify_otp "$BADOTP")"; has "wrong OTP -> Invalid OTP message" "$r" 'Invalid OTP. Please check the OTP and try again.'
r="$(verify_otp 12ab)";      has "malformed OTP rejected" "$r" 'Invalid OTP'
for i in 1 2 3; do r="$(verify_otp "$BADOTP")"; done
r="$(verify_otp "$BADOTP")"
has "5th wrong attempt locks the OTP" "$r" 'Too many incorrect attempts'
r="$(verify_otp "$OTPW")"
has "even the CORRECT OTP is dead after lock-out" "$r" 'expired'

echo "== 6. expiry =="
newjar; send_otp 'exp@example.com' >/dev/null; OTPE="$(last_otp)"
F="$(ls -1t "$T"/data/sessions/*.json | head -1)"
python3 - "$F" <<'PY'
import json,sys,time
p=sys.argv[1]; s=json.load(open(p)); s['otp']['exp']=int(time.time())-1; json.dump(s,open(p,'w'))
PY
r="$(verify_otp "$OTPE")"; has "expired OTP rejected: 'OTP has expired…'" "$r" 'OTP has expired. Please request a new OTP.'
check "expired flag set" "$(jget expired <<<"$r")" "True"

echo "== 7. success, single use, new OTP invalidates old =="
newjar; send_otp 'ok@example.com' >/dev/null; OTP1="$(last_otp)"
python3 - "$(ls -1t "$T"/data/sessions/*.json | head -1)" <<'PY'
import json,sys,time
p=sys.argv[1]; s=json.load(open(p)); s['last_sent']=0; json.dump(s,open(p,'w'))
PY
rm -f "$T"/data/rl/*    # lift the per-email/IP counters so we can request a 2nd OTP now
send_otp 'ok@example.com' >/dev/null; OTP2="$(last_otp)"
if [[ "$OTP1" != "$OTP2" ]]; then
  r="$(verify_otp "$OTP1")"; has "OLD OTP is invalid once a new one is issued" "$r" 'Invalid OTP'
else ok "(old==new OTP by chance; skipped)"; fi
r="$(verify_otp "$OTP2")"
check "correct OTP verifies" "$(jget ok <<<"$r")" "True"
TOK="$(jget token <<<"$r")"
[[ "$TOK" =~ ^[a-f0-9]{48}$ ]] && ok "single-use proof token issued" || bad "proof token" "$TOK"
r="$(verify_otp "$OTP2")"; has "OTP cannot be reused after success" "$r" 'expired'

echo "== 8. final submission =="
r="$(handler -F _form=contact -F "_ts=$(ts_ms)" -F 'name=Test User' -F phone=9876543210 -F email=ok@example.com -F topic=Other -F message=hello)"
has "submit WITHOUT token -> blocked (otp_required)" "$r" 'otp_required'
r="$(handler -F _form=contact -F "_ts=$(ts_ms)" -F "_otp_token=$TOK" -F 'name=Test User' -F phone=9876543210 -F email=other@example.com -F topic=Other)"
has "token + DIFFERENT email -> blocked" "$r" 'otp_required'
r="$(handler -F _form=claim -F "_ts=$(ts_ms)" -F "_otp_token=$TOK" -F 'name=Test User' -F phone=9876543210 -F email=ok@example.com -F type=Health)"
has "token used on a DIFFERENT form -> blocked" "$r" 'otp_required'
JAR2="$T/jar-other"; CSRF2="$(curl -s -c "$JAR2" -H "$ORIGIN" "$BASE/otp-api.php?a=init" | jget csrf)"
r="$(curl -s -b "$JAR2" -H "$ORIGIN" -X POST "$BASE/form-handler.php" -F _form=contact -F "_ts=$(ts_ms)" -F "_otp_token=$TOK" -F 'name=T' -F phone=9876543210 -F email=ok@example.com -F topic=Other)"
has "token stolen into ANOTHER session -> blocked" "$r" 'otp_required'
before="$(mails)"
r="$(handler -F _form=contact -F "_ts=$(ts_ms)" -F "_otp_token=$TOK" -F 'name=Test User' -F phone=9876543210 -F email=ok@example.com -F topic=Other -F message=hello)"
check "valid email + OTP -> lead accepted" "$(jget ok <<<"$r")" "True"
sleep 1
check "existing notification email still sent" "$(( $(mails) - before ))" "1"
N="$(ls -1t "$T"/mail/*.eml | head -1)"
has "notification subject unchanged" "$(cat "$N")" 'New Contact Enquiry | BTW IMF Website'
has "notification carries the lead details" "$(cat "$N")" 'ok@example.com'
! grep -q 'turnstile\|_otp_token' "$N" && ok "no token/meta fields leaked into the notification" || bad "meta leaked into mail"
LOG="$(cat "$SITE"/_submissions/*.jsonl 2>/dev/null)"
has "lead persisted to _submissions log" "$LOG" 'ok@example.com'
has "log marks the e-mail as verified" "$LOG" '"email_verified":true'
r="$(handler -F _form=contact -F "_ts=$(ts_ms)" -F "_otp_token=$TOK" -F 'name=Test User' -F phone=9876543210 -F email=ok@example.com -F topic=Other -F message=hello)"
has "REPLAY of the same token -> blocked (single use)" "$r" 'otp_required'

echo "== 9. direct / bot requests to form-handler.php =="
r="$(curl -s -X POST "$BASE/form-handler.php" -F _form=contact -F "_ts=$(ts_ms)" -F name=Bot -F phone=9876543210 -F email=bot@example.com -F topic=Other)"
has "no Origin/Referer -> Bad origin" "$r" 'Bad origin'
r="$(curl -s -H 'Origin: https://evil.example' -X POST "$BASE/form-handler.php" -F _form=contact -F "_ts=$(ts_ms)" -F name=Bot -F phone=9876543210 -F email=bot@example.com -F topic=Other)"
has "foreign Origin -> Bad origin" "$r" 'Bad origin'
newjar
r="$(handler -F _form=contact -F name=Bot -F phone=9876543210 -F email=bot@example.com -F topic=Other)"
has "missing _ts -> rejected" "$r" 'reload the page'
r="$(handler -F _form=contact -F "_ts=$(ts_ms)" -F _hp=spam -F name=Bot -F phone=9876543210 -F email=bot@example.com -F topic=Other)"
has "honeypot still silently swallowed (ok)" "$r" '"ok":true'
r="$(handler -F _form=product-quote -F "_ts=$(ts_ms)" -F name=Bot -F phone=9876543210 -F email=bot@example.com)"
has "product-quote without OTP -> blocked" "$r" 'otp_required'
r="$(handler -F _form=renewal -F "_ts=$(ts_ms)" -F name=Bot -F phone=9876543210 -F email=bot@example.com -F type=Car)"
has "renewal without OTP -> blocked" "$r" 'otp_required'
r="$(handler -F _form=claim -F "_ts=$(ts_ms)" -F name=Bot -F phone=9876543210 -F email=bot@example.com -F type=Car)"
has "claim without OTP -> blocked" "$r" 'otp_required'
r="$(handler -F _form=careers -F "_ts=$(ts_ms)" -F "_cft=$TOKEN" -F name=Bot -F phone=9876543210 -F email=bot@example.com -F city=X -F role=Y)"
has "careers with Turnstile but no OTP -> blocked" "$r" 'otp_required'
r="$(handler -F _form=careers -F "_ts=$(ts_ms)" -F name=Bot -F phone=9876543210 -F email=bot@example.com -F city=X -F role=Y)"
has "careers without Turnstile -> blocked" "$r" 'Security check failed'

echo "== 10. Turnstile-only forms (quick-quote, vehicle-lookup) =="
newjar
r="$(handler -F _form=quick-quote -F "_ts=$(ts_ms)" -F phone=9876543210)"
has "quick-quote without Turnstile token -> blocked" "$r" 'Security check failed'
r="$(handler -F _form=quick-quote -F "_ts=$(ts_ms)" -F "_cft=$TOKEN" -F phone=9876543211)"
has "quick-quote with Turnstile token -> ok" "$r" '"ok":true'
r="$(handler -F _form=vehicle-lookup -F "_ts=$(ts_ms)" -F vehreg=MH12AB1234)"
has "vehicle-lookup without Turnstile token -> blocked" "$r" 'Security check failed'
r="$(handler -F _form=vehicle-lookup -F "_ts=$(ts_ms)" -F "_cft=$TOKEN" -F vehreg=MH12AB1234)"
has "vehicle-lookup with Turnstile token -> ok" "$r" '"ok":true'
before="$(mails)"
r="$(handler -F _form=quick-quote -F "_ts=$(ts_ms)" -F "_cft=$TOKEN" -F phone=9876543211)"
has "duplicate quick-quote is swallowed (ok)" "$r" '"ok":true'
sleep 1; check "…without a second e-mail" "$(( $(mails) - before ))" "0"
write_secrets '2x0000000000000000000000000000000AA'   # Cloudflare dummy: always FAILS
r="$(handler -F _form=quick-quote -F "_ts=$(ts_ms)" -F "_cft=$TOKEN" -F phone=9876543222)"
has "failing Turnstile (dummy 'always fail' secret) -> blocked" "$r" 'Security check failed'
newjar; r="$(send_otp 'ts@example.com')"
has "OTP send with failing Turnstile -> blocked" "$r" 'Security check failed'
write_secrets '1x0000000000000000000000000000000AA'

echo "== 11. rate limiting =="
rm -f "$T"/data/rl/*
n429=0; for i in $(seq 1 14); do newjar; r="$(send_otp "flood$i@example.com")"; [[ "$r" == *'Too many OTP requests from this connection'* ]] && n429=$((n429+1)); done
check "13th+ OTP request from one IP in an hour is throttled (2 of 14)" "$n429" "2"
rm -f "$T"/data/rl/*
sent=0; blocked=0
for i in 1 2 3 4 5 6 7; do
  newjar; r="$(send_otp 'same@example.com')"
  if [[ "$(jget ok <<<"$r")" == "True" ]]; then sent=$((sent+1)); else blocked=$((blocked+1)); fi
done
check "only 1 OTP mail per 60s to the same address (1 sent, 6 blocked)" "$sent/$blocked" "1/6"
rm -f "$T"/data/rl/*
ok_cnt=0; for i in $(seq 1 32); do newjar; r="$(handler -F _form=contact -F "_ts=$(ts_ms)" -F name=Flood -F phone=9876543210 -F email=flood@example.com -F topic=Other)"; [[ "$r" == *'Too many requests'* ]] && ok_cnt=$((ok_cnt+1)); done
[[ "$ok_cnt" -ge 1 ]] && ok "per-IP burst limit on form-handler engages ($ok_cnt blocked of 32)" || bad "burst limit did not engage"
rm -f "$T"/data/rl/*

echo "== 12. verify brute-force across sessions (per-IP) =="
rm -f "$T"/data/rl/*
blocked=0; for i in $(seq 1 45); do newjar; r="$(verify_otp 000000)"; [[ "$r" == *'Too many attempts'* ]] && blocked=$((blocked+1)); done
[[ "$blocked" -ge 1 ]] && ok "per-IP verify limit engages ($blocked blocked of 45)" || bad "verify limit did not engage"
rm -f "$T"/data/rl/*

echo "== 13. resend AFTER the cooldown works (waits ~62s) =="
newjar; send_otp 'later@example.com' >/dev/null; A="$(last_otp)"
sleep 62
r="$(send_otp 'later@example.com')"
check "resend after 60s succeeds" "$(jget ok <<<"$r")" "True"
B="$(last_otp)"
if [[ "$A" != "$B" ]]; then r="$(verify_otp "$A")"; has "first OTP dead after resend" "$r" 'Invalid OTP'; fi
r="$(verify_otp "$B")"; check "resent OTP verifies" "$(jget ok <<<"$r")" "True"

echo "== 14. error responses do not echo user input (XSS) =="
newjar; r="$(send_otp '"><script>alert(1)</script>@x.com')"
[[ "$r" != *"<script>"* ]] && ok "no reflected markup in API errors" || bad "reflected markup"

echo
echo "RESULT: $PASS passed, $FAIL failed"
[[ "$FAIL" -eq 0 ]]
