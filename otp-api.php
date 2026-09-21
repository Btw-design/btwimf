<?php
/**
 * BTW IMF — Email OTP API
 * ---------------------------------------------------------------------------
 *   GET  /otp-api.php?a=init    → sets the session cookie; returns CSRF token +
 *                                 the (public) Turnstile site key
 *   POST /otp-api.php  a=send   → validates, rate-limits, Turnstile-checks and
 *                                 e-mails a 6-digit OTP
 *   POST /otp-api.php  a=verify → checks the OTP; on success returns a
 *                                 single-use proof token
 *
 * The OTP itself is never returned by this API. Every state-changing call needs
 * a same-origin request, the HttpOnly session cookie and the CSRF header.
 */
require __DIR__ . '/lib/otp.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out(array $body, int $code = 200): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!btw_ready()) {
    out(['ok' => false, 'error' => 'Email verification is temporarily unavailable. Please call or WhatsApp us on 90043 83987.'], 503);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['a'] ?? $_POST['a'] ?? '');
$ip     = btw_client_ip();

// ── init ────────────────────────────────────────────────────────────────────
if ($action === 'init') {
    if ($method !== 'GET') out(['ok' => false, 'error' => 'Method not allowed.'], 405);
    $sid = btw_sid(true);
    out([
        'ok'      => true,
        'csrf'    => btw_csrf_token($sid),
        'siteKey' => (string) (btw_cfg()['turnstile_site_key'] ?? ''),
    ]);
}

// ── send / verify ─────────────────────────────────────────────────────────────
if ($method !== 'POST') out(['ok' => false, 'error' => 'Method not allowed.'], 405);
if ($action !== 'send' && $action !== 'verify') out(['ok' => false, 'error' => 'Unknown action.'], 400);
if (!btw_same_origin()) out(['ok' => false, 'error' => 'Bad origin.'], 403);

$sid = btw_sid(false);
if (!$sid || !btw_csrf_ok($sid)) {
    out(['ok' => false, 'error' => 'Your session has expired. Please refresh the page and try again.'], 403);
}
$sfile = btw_session_file($sid);

// ── verify ──────────────────────────────────────────────────────────────────
if ($action === 'verify') {
    [$allowed] = btw_rl_hit('otp-verify-ip', $ip, 40, 3600);
    if (!$allowed) out(['ok' => false, 'error' => 'Too many attempts. Please try again later.'], 429);

    $otp = preg_replace('/\s+/', '', (string) ($_POST['otp'] ?? ''));
    if (!preg_match('/^\d{6}$/', $otp)) {
        out(['ok' => false, 'error' => 'Invalid OTP. Please check the OTP and try again.'], 422);
    }

    $now = time();
    $res = btw_store_update($sfile, function (&$s) use ($sid, $otp, $now) {
        $o = $s['otp'] ?? null;
        if (!is_array($o)) {
            return [false, 410, 'OTP has expired. Please request a new OTP.'];
        }
        if ($o['exp'] <= $now) {
            unset($s['otp']);
            return [false, 410, 'OTP has expired. Please request a new OTP.'];
        }
        $o['attempts'] = (int) $o['attempts'] + 1;
        if (hash_equals((string) $o['h'], btw_otp_hash($sid, (string) $o['email'], $otp))) {
            $token = bin2hex(random_bytes(24));
            $s['proof'] = [
                'h'     => btw_proof_hash($sid, $token),
                'email' => $o['email'],
                'form'  => $o['form'],
                'exp'   => $now + OTP_PROOF_TTL,
            ];
            unset($s['otp']);                       // single use: the OTP is dead now
            return [true, 200, $token, $o['email']];
        }
        if ($o['attempts'] >= OTP_MAX_ATTEMPTS) {
            unset($s['otp']);                       // brute-force lock: must request a new OTP
            return [false, 429, 'Too many incorrect attempts. Please request a new OTP.', 'locked'];
        }
        $s['otp'] = $o;
        return [false, 422, 'Invalid OTP. Please check the OTP and try again.', 'left:' . (OTP_MAX_ATTEMPTS - $o['attempts'])];
    });

    if (!$res) out(['ok' => false, 'error' => 'Something went wrong. Please try again.'], 500);
    if ($res[0]) out(['ok' => true, 'token' => $res[2], 'email' => $res[3]]);
    $body = ['ok' => false, 'error' => $res[2]];
    if ($res[1] === 410) $body['expired'] = true;
    if (($res[3] ?? '') === 'locked') $body['locked'] = true;
    out($body, $res[1]);
}

// ── send ────────────────────────────────────────────────────────────────────
$form  = (string) ($_POST['form'] ?? '');
$email = btw_norm_email($_POST['email'] ?? '');
$name  = (string) ($_POST['name'] ?? '');

if (!in_array($form, OTP_FORMS, true)) out(['ok' => false, 'error' => 'Unknown form.'], 400);
if ($email === '') out(['ok' => false, 'error' => 'Please enter a valid email address.'], 422);

// 1. cheap per-IP throttle (also protects the Turnstile call below)
[$allowed, $retry] = btw_rl_hit('otp-send-ip', $ip, 12, 3600);
if (!$allowed) out(['ok' => false, 'error' => 'Too many OTP requests from this connection. Please try again later.', 'retry' => $retry], 429);

// 2. cooldown for this session, checked without consuming anything
$now = time();
$cd = btw_store_update($sfile, function (&$s) use ($now, $email) {
    $wait = (int) ($s['last_sent'] ?? 0) + OTP_COOLDOWN - $now;
    if ($wait > 0) {
        return ['retry' => $wait, 'pending' => isset($s['otp']) && ($s['otp']['email'] ?? '') === $email && $s['otp']['exp'] > $now];
    }
    return null;
});
if ($cd) {
    out(['ok' => false, 'error' => 'Please wait before requesting a new OTP.', 'retry' => $cd['retry'], 'pending' => $cd['pending']], 429);
}

// 3. Cloudflare Turnstile (a fresh token is needed for every request)
if (!btw_turnstile_verify((string) ($_POST['_cft'] ?? ''), $ip)) {
    out(['ok' => false, 'error' => 'Security check failed. Please refresh the page and try again.'], 403);
}

// 4. per-email limits: 60s between mails and 5 per hour, across all sessions
[$allowed, $retry] = btw_rl_hit('otp-email-cd', $email, 1, OTP_COOLDOWN);
if (!$allowed) out(['ok' => false, 'error' => 'Please wait before requesting a new OTP.', 'retry' => $retry], 429);
[$allowed, $retry] = btw_rl_hit('otp-email-hr', $email, 5, 3600);
if (!$allowed) out(['ok' => false, 'error' => 'Too many OTP requests for this email address. Please try again later.', 'retry' => $retry], 429);

// 5. per-session hourly cap
$capped = btw_store_update($sfile, function (&$s) use ($now) {
    $sends = array_values(array_filter($s['sends'] ?? [], fn($t) => $t > $now - 3600));
    $s['sends'] = $sends;
    return count($sends) >= 8;
});
if ($capped) out(['ok' => false, 'error' => 'Too many OTP requests. Please try again later.'], 429);

// 6. generate + store the HASH (replaces — and so invalidates — any earlier OTP)
$otp = btw_generate_otp();
btw_store_update($sfile, function (&$s) use ($sid, $email, $form, $otp, $now) {
    $s['otp'] = [
        'h'        => btw_otp_hash($sid, $email, $otp),
        'email'    => $email,
        'form'     => $form,
        'exp'      => $now + OTP_TTL,
        'attempts' => 0,
    ];
    unset($s['proof']);                     // a new OTP also revokes any earlier verification
    $s['last_sent'] = $now;
    $s['sends'][] = $now;
});

// 7. send it
if (!btw_send_otp_mail($email, $name, $otp)) {
    btw_store_update($sfile, function (&$s) { unset($s['otp']); });
    error_log('btwimf otp: mail() failed for form ' . $form);
    out(['ok' => false, 'error' => 'We could not send the OTP right now. Please try again in a moment.'], 502);
}
$otp = null;

if (random_int(1, 40) === 1) btw_gc();

out(['ok' => true, 'expires' => OTP_TTL, 'cooldown' => OTP_COOLDOWN, 'to' => btw_mask_email($email)]);
