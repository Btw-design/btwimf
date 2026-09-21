<?php
/**
 * BTW IMF — Email-OTP, Cloudflare Turnstile and rate-limit helpers.
 * ---------------------------------------------------------------------------
 * Included by /otp-api.php and /form-handler.php. This file is NOT web
 * accessible (blocked in .htaccess) and defines functions/constants only.
 *
 * Secrets + runtime state live OUTSIDE the web root:
 *   • /etc/btwimf/secrets.php      (root:www-data 640)  → returns an array:
 *         'hmac_key'           => 64+ hex chars  (signs OTP hashes / tokens)
 *         'turnstile_site_key' => public site key
 *         'turnstile_secret'   => Turnstile secret key
 *         'data_dir'           => optional, default /var/lib/btwimf-otp
 *   • /var/lib/btwimf-otp/         (www-data 700)  sessions/ + rl/ JSON state
 * The path of the secrets file can be overridden with the BTW_SECRETS_FILE
 * environment variable (used for local tests).
 *
 * OTPs are NEVER stored, logged or returned in plain text: only an HMAC of
 * (session id | email | otp) is kept, and it is deleted on success/expiry.
 */

const OTP_TTL          = 300;    // OTP validity, seconds (5 min)
const OTP_MAX_ATTEMPTS = 5;      // wrong-OTP attempts before the OTP is killed
const OTP_COOLDOWN     = 60;     // min seconds between OTP e-mails
const OTP_PROOF_TTL    = 1800;   // a verified e-mail stays usable for 30 min
const OTP_FROM         = 'leads@btwimf.com';   // existing authenticated Gmail relay
const OTP_FROM_NAME    = 'BTW IMF';

/** Forms that need a verified e-mail before they will be accepted. */
const OTP_FORMS      = ['contact', 'careers', 'product-quote', 'claim', 'renewal'];
/** Forms that need a Cloudflare Turnstile pass. */
const TURNSTILE_FORMS = ['careers', 'quick-quote', 'vehicle-lookup'];

// ─────────────────────────────────────────────────────────────────────────────
// Config / storage
// ─────────────────────────────────────────────────────────────────────────────
function btw_cfg(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $cfg = [];
    $path = getenv('BTW_SECRETS_FILE') ?: '/etc/btwimf/secrets.php';
    if (is_string($path) && is_readable($path)) {
        $r = include $path;
        if (is_array($r)) $cfg = $r;
    }
    return $cfg;
}

function btw_data_dir(): string {
    $cfg = btw_cfg();
    return rtrim((string) ($cfg['data_dir'] ?? '/var/lib/btwimf-otp'), '/');
}

/** True when secrets exist and the state directory is usable. */
function btw_ready(): bool {
    $cfg = btw_cfg();
    if (strlen((string) ($cfg['hmac_key'] ?? '')) < 32) return false;
    foreach (['', '/sessions', '/rl'] as $sub) {
        $d = btw_data_dir() . $sub;
        if (!is_dir($d)) @mkdir($d, 0700, true);
        if (!is_dir($d) || !is_writable($d)) return false;
    }
    return true;
}

function btw_hmac(string $data): string {
    return hash_hmac('sha256', $data, (string) btw_cfg()['hmac_key']);
}

/**
 * Read-modify-write a small JSON state file under an exclusive lock.
 * $fn receives the decoded state BY REFERENCE and returns the result.
 */
function btw_store_update(string $file, callable $fn) {
    $fh = @fopen($file, 'c+');
    if (!$fh) return null;
    @chmod($file, 0600);
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $state = $raw ? json_decode($raw, true) : [];
    if (!is_array($state)) $state = [];
    $result = $fn($state);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($state));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $result;
}

function btw_session_file(string $sid): string {
    return btw_data_dir() . '/sessions/' . btw_hmac('sid|' . $sid) . '.json';
}

/** Opportunistic clean-up of stale state files. */
function btw_gc(): void {
    $now = time();
    foreach ([['/sessions/*.json', 172800], ['/rl/*.json', 86400]] as [$glob, $age]) {
        foreach ((array) glob(btw_data_dir() . $glob) as $f) {
            if (is_file($f) && filemtime($f) < $now - $age) @unlink($f);
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Client IP (Cloudflare-aware, not spoofable)
// ─────────────────────────────────────────────────────────────────────────────
function btw_ip_in_cidr(string $ip, string $cidr): bool {
    [$net, $bits] = explode('/', $cidr);
    $bits = (int) $bits;
    $a = @inet_pton($ip);
    $b = @inet_pton($net);
    if ($a === false || $b === false || strlen($a) !== strlen($b)) return false;
    $full = intdiv($bits, 8);
    $rem  = $bits % 8;
    if ($full && substr($a, 0, $full) !== substr($b, 0, $full)) return false;
    if ($rem) {
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        if ((ord($a[$full]) & $mask) !== (ord($b[$full]) & $mask)) return false;
    }
    return true;
}

function btw_is_cloudflare(string $ip): bool {
    static $ranges = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];
    foreach ($ranges as $r) if (btw_ip_in_cidr($ip, $r)) return true;
    return false;
}

/**
 * The visitor's IP. CF-Connecting-IP is honoured ONLY when the request really
 * arrived from a Cloudflare edge address, so it cannot be spoofed by hitting
 * the origin directly.
 */
function btw_client_ip(): string {
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP']) && btw_is_cloudflare($remote)) {
        $ip = trim((string) $_SERVER['HTTP_CF_CONNECTING_IP']);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return $remote;
}

// ─────────────────────────────────────────────────────────────────────────────
// Origin / session / CSRF
// ─────────────────────────────────────────────────────────────────────────────
/** Request must carry an Origin or Referer whose host is this site. */
function btw_same_origin(): bool {
    $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    if ($host === '') return false;
    $seen = false;
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $h) {
        if (empty($_SERVER[$h])) continue;
        $seen = true;
        $p = strtolower((string) parse_url((string) $_SERVER[$h], PHP_URL_HOST));
        $strip = fn($x) => preg_replace('/^www\./', '', $x);
        if ($p === '' || $strip($p) !== $strip($host)) return false;
    }
    return $seen;
}

function btw_is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || !empty($_SERVER['HTTP_CF_VISITOR']);
}

/** Session id from the HttpOnly cookie; optionally creates it. */
function btw_sid(bool $create = false): ?string {
    $c = $_COOKIE['btw_sid'] ?? '';
    if (is_string($c) && preg_match('/^[a-f0-9]{48}$/', $c)) return $c;
    if (!$create) return null;
    $sid = bin2hex(random_bytes(24));
    setcookie('btw_sid', $sid, [
        'expires'  => time() + 86400,
        'path'     => '/',
        'secure'   => btw_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE['btw_sid'] = $sid;
    return $sid;
}

function btw_csrf_token(string $sid): string {
    return btw_hmac('csrf|' . $sid);
}

function btw_csrf_ok(string $sid): bool {
    $t = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return $t !== '' && hash_equals(btw_csrf_token($sid), $t);
}

// ─────────────────────────────────────────────────────────────────────────────
// Rate limiting (sliding window, file based)
// ─────────────────────────────────────────────────────────────────────────────
/**
 * Record a hit and report whether it is allowed.
 * @return array [bool allowed, int retryAfterSeconds]
 */
function btw_rl_hit(string $scope, string $id, int $max, int $window): array {
    $file = btw_data_dir() . '/rl/' . btw_hmac('rl|' . $scope . '|' . $id) . '.json';
    $now = time();
    $r = btw_store_update($file, function (&$s) use ($now, $max, $window) {
        $t = array_values(array_filter($s['t'] ?? [], fn($x) => $x > $now - $window));
        if (count($t) >= $max) {
            $s['t'] = $t;
            return [false, max(1, $t[0] + $window - $now)];
        }
        $t[] = $now;
        $s['t'] = $t;
        return [true, 0];
    });
    return $r ?? [true, 0];   // storage hiccup must not lock everyone out
}

/** Duplicate-submission guard: true if the same fingerprint was seen recently. */
function btw_dup_seen(string $fingerprint, int $window = 600): bool {
    $file = btw_data_dir() . '/rl/' . btw_hmac('dup|' . $fingerprint) . '.json';
    $now = time();
    return (bool) btw_store_update($file, function (&$s) use ($now, $window) {
        $seen = isset($s['t']) && $s['t'] > $now - $window;
        if (!$seen) $s['t'] = $now;
        return $seen;
    });
}

// ─────────────────────────────────────────────────────────────────────────────
// Cloudflare Turnstile
// ─────────────────────────────────────────────────────────────────────────────
function btw_turnstile_verify(string $token, string $ip): bool {
    $secret = (string) (btw_cfg()['turnstile_secret'] ?? '');
    if ($secret === '' || $token === '' || strlen($token) > 2048) return false;
    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['secret' => $secret, 'response' => $token, 'remoteip' => $ip]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT        => 6,
    ]);
    $out = curl_exec($ch);
    curl_close($ch);
    $j = json_decode((string) $out, true);
    if (!is_array($j) || empty($j['success'])) return false;
    // Cloudflare's dummy secrets ("1x0000…") report a fake hostname — only
    // enforce the hostname with real keys.
    if (strpos($secret, '1x0000') !== 0) {
        $h = strtolower((string) ($j['hostname'] ?? ''));
        if ($h !== 'btwimf.com' && $h !== 'www.btwimf.com') return false;
    }
    return true;
}

// ─────────────────────────────────────────────────────────────────────────────
// E-mail + OTP
// ─────────────────────────────────────────────────────────────────────────────
/** Trim + lower-case + validate. Returns '' when not a usable address. */
function btw_norm_email($e): string {
    $e = strtolower(trim((string) $e));
    if ($e === '' || strlen($e) > 254 || preg_match('/[\s,;<>"\'\\\\]/', $e)) return '';
    return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '';
}

function btw_mask_email(string $e): string {
    [$u, $d] = explode('@', $e, 2) + ['', ''];
    return substr($u, 0, 1) . str_repeat('*', max(1, min(6, strlen($u) - 1))) . '@' . $d;
}

function btw_generate_otp(): string {
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function btw_otp_hash(string $sid, string $email, string $otp): string {
    return btw_hmac('otp|' . $sid . '|' . $email . '|' . $otp);
}

function btw_proof_hash(string $sid, string $token): string {
    return btw_hmac('proof|' . $sid . '|' . $token);
}

/** Branded OTP e-mail. Returns true when the mail was handed to the relay. */
function btw_send_otp_mail(string $email, string $name, string $otp): bool {
    $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $name = trim((string) preg_replace('/[\x00-\x1F\x7F<>]+/u', ' ', $name));
    $name = mb_substr($name, 0, 60);
    $hello = $name !== '' ? 'Hello ' . $name . ',' : 'Hello,';
    $mins = (int) (OTP_TTL / 60);

    $text = $hello . "\n\n"
        . "Your BTW IMF email verification code is: " . $otp . "\n\n"
        . "This code is valid for " . $mins . " minutes.\n"
        . "For your security, do not share this OTP with anyone. BTW IMF will never ask you for it.\n\n"
        . "If you did not request this, you can safely ignore this email.\n\n"
        . "BTW IMF - Insurance & Wealth Management\nhttps://btwimf.com\n";

    $digits = '';
    foreach (str_split($otp) as $d) {
        $digits .= '<td style="width:44px;height:54px;background:#EDF6F2;border:1px solid #CFE4DA;border-radius:8px;text-align:center;font:700 28px/54px Arial,Helvetica,sans-serif;color:#182450;">' . $e($d) . '</td><td style="width:6px;"></td>';
    }
    $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Verify Your Email &ndash; BTW IMF</title></head>'
    . '<body style="margin:0;padding:0;background:#F5F7FC;">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F5F7FC;padding:24px 12px;"><tr><td align="center">'
    . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="width:560px;max-width:100%;background:#FFFFFF;border-radius:12px;overflow:hidden;border:1px solid #E3E9F2;">'
    . '<tr><td style="background:#101A38;padding:26px 24px 20px;text-align:center;">'
    . '<img src="https://btwimf.com/assets/img/btw-imf-logo.png" width="140" alt="BTW IMF" style="display:block;margin:0 auto 10px;max-width:140px;height:auto;border:0;">'
    . '<div style="font:700 19px/1.2 Arial,Helvetica,sans-serif;color:#FFFFFF;letter-spacing:.3px;">BTW IMF</div>'
    . '<div style="font:400 12px/1.4 Arial,Helvetica,sans-serif;color:#B9C4E2;letter-spacing:.6px;text-transform:uppercase;margin-top:4px;">Insurance &amp; Wealth Management</div>'
    . '</td></tr>'
    . '<tr><td style="background:#2F7A63;padding:13px 24px;"><div style="font:700 16px/1.3 Arial,Helvetica,sans-serif;color:#FFFFFF;">Verify your email address</div></td></tr>'
    . '<tr><td style="padding:26px 28px 8px;font:400 15px/1.6 Arial,Helvetica,sans-serif;color:#0F1B33;">'
    . '<p style="margin:0 0 14px;">' . $e($hello) . '</p>'
    . '<p style="margin:0 0 18px;">Use this one-time password (OTP) to verify your email address on the BTW IMF website:</p>'
    . '<table role="presentation" cellpadding="0" cellspacing="0" align="center" style="margin:0 auto 18px;"><tr>' . $digits . '</tr></table>'
    . '<p style="margin:0 0 10px;text-align:center;font:600 14px/1.5 Arial,Helvetica,sans-serif;color:#182450;">This OTP is valid for ' . $mins . ' minutes.</p>'
    . '</td></tr>'
    . '<tr><td style="padding:6px 28px 24px;"><div style="background:#FFF7E8;border:1px solid #F0DDB2;border-radius:8px;padding:12px 14px;font:400 13px/1.6 Arial,Helvetica,sans-serif;color:#6B5312;">'
    . '<strong>Security note:</strong> Do not share this OTP with anyone. BTW IMF will never ask you for it. If you did not request this code, you can safely ignore this email.'
    . '</div></td></tr>'
    . '<tr><td style="background:#101A38;padding:16px 24px;text-align:center;">'
    . '<div style="font:600 12.5px/1.5 Arial,Helvetica,sans-serif;color:#FFFFFF;">BTW IMF &ndash; Insurance &amp; Wealth Management</div>'
    . '<div style="font:400 12px/1.6 Arial,Helvetica,sans-serif;color:#8FA0C5;">This is an automated message &middot; <a href="https://btwimf.com" style="color:#7BBFA7;text-decoration:none;">btwimf.com</a></div>'
    . '</td></tr></table></td></tr></table></body></html>';

    $boundary = 'btwimf-otp-' . bin2hex(random_bytes(10));
    $headers = implode("\r\n", [
        'From: ' . OTP_FROM_NAME . ' <' . OTP_FROM . '>',
        'X-Mailer: btwimf-otp',
        'Auto-Submitted: auto-generated',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ]);
    $body = "--{$boundary}\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $text . "\r\n\r\n"
          . "--{$boundary}\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $html . "\r\n\r\n"
          . "--{$boundary}--";
    $subject = '=?UTF-8?B?' . base64_encode('Verify Your Email – BTW IMF') . '?=';
    return (bool) @mail($email, $subject, $body, $headers, '-f' . OTP_FROM);
}

// ─────────────────────────────────────────────────────────────────────────────
// Verified-email proof (single use, bound to session + email + form type)
// ─────────────────────────────────────────────────────────────────────────────
/** Non-consuming check used early in form-handler.php. */
function btw_proof_check(string $sid, string $token, string $email, string $form): bool {
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) return false;
    $now = time();
    return (bool) btw_store_update(btw_session_file($sid), function (&$s) use ($sid, $token, $email, $form, $now) {
        $p = $s['proof'] ?? null;
        return is_array($p) && empty($p['used']) && $p['exp'] > $now
            && $p['email'] === $email && $p['form'] === $form
            && hash_equals((string) $p['h'], btw_proof_hash($sid, $token));
    });
}

/** Atomically burn the proof. Returns true exactly once per verification. */
function btw_proof_consume(string $sid, string $token, string $email, string $form): bool {
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) return false;
    $now = time();
    return (bool) btw_store_update(btw_session_file($sid), function (&$s) use ($sid, $token, $email, $form, $now) {
        $p = $s['proof'] ?? null;
        if (!is_array($p) || !empty($p['used']) || $p['exp'] <= $now
            || $p['email'] !== $email || $p['form'] !== $form
            || !hash_equals((string) $p['h'], btw_proof_hash($sid, $token))) return false;
        unset($s['proof']);     // gone for good — cannot be replayed
        return true;
    });
}
