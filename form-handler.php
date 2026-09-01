<?php
/**
 * BTW IMF — universal form handler
 * ---------------------------------------------------------------------------
 * Receives every site form (contact, careers, product quote, claim, renewal,
 * quick-quote, vehicle lookup), validates it server-side, STORES it to a
 * newline-delimited JSON log, and emails it to the office inbox.
 *
 * Deployment (Hostinger / any PHP 7.4+ Apache host):
 *   1. Upload this file to the web root so it answers at  /form-handler.php
 *   2. Set RECIPIENT below to the inbox that should receive leads.
 *   3. Make sure the web root is writable so  _submissions/  can be created,
 *      OR create  _submissions/  manually and chmod it 755/775.
 *   4. The bundled .htaccess already denies public access to _submissions/.
 *
 * Response: JSON  { "ok": true }  or  { "ok": false, "error": "..." }
 */

// ─────────────────────────────────────────────────────────────────────────────
// CONFIG  — edit these two lines for production
// ─────────────────────────────────────────────────────────────────────────────
const RECIPIENT   = 'info@btwimf.com';          // where leads are emailed
const FROM_ADDR   = 'website@btwimf.com';       // must be a mailbox/alias on the domain
const SITE_NAME   = 'BTW IMF website';
const STORE_DIR   = __DIR__ . '/_submissions';
const MAX_PER_10MIN = 6;                        // per-IP rate limit

// ─────────────────────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function fail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
function done() {
    echo json_encode(['ok' => true]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Method not allowed.', 405);
}

// Same-origin guard (best-effort; static hosts still send Origin/Referer)
$host = $_SERVER['HTTP_HOST'] ?? '';
foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $h) {
    if (!empty($_SERVER[$h])) {
        $p = parse_url($_SERVER[$h], PHP_URL_HOST);
        if ($p && $host && stripos($p, $host) === false && stripos($host, $p) === false) {
            fail('Bad origin.', 403);
        }
    }
}

// ── Form registry: type => [label, required fields] ─────────────────────────
$FORMS = [
    'contact'        => ['Contact enquiry',        ['name', 'phone', 'email', 'topic']],
    'careers'        => ['Career application',      ['name', 'phone', 'email', 'city', 'role']],
    'product-quote'  => ['Product quote request',  ['name', 'phone', 'email']],
    'claim'          => ['Claim assistance',       ['name', 'phone', 'email', 'type']],
    'renewal'        => ['Policy renewal',         ['name', 'phone', 'email', 'type']],
    'quick-quote'    => ['Quick quote (quote bar)',['phone']],
    'vehicle-lookup' => ['Vehicle renewal lookup', ['vehreg']],
];

$type = isset($_POST['_form']) ? preg_replace('/[^a-z\-]/', '', $_POST['_form']) : '';
if (!isset($FORMS[$type])) fail('Unknown form.');
[$label, $required] = $FORMS[$type];

// ── Anti-spam ──────────────────────────────────────────────────────────────
if (!empty($_POST['_hp'])) done();                       // honeypot filled → silently accept & drop
$ts = isset($_POST['_ts']) ? (int) $_POST['_ts'] : 0;    // ms since epoch, set by JS on page load
$ageMs = (int) round(microtime(true) * 1000) - $ts;
if ($ts && $ageMs > 6 * 60 * 60 * 1000) {
    fail('This page has been open too long — please reload and try again.', 422);
}
if ($ts && $ageMs < 1200) {
    fail('That was too quick — please try again.', 422);
}

// Rate limit (per IP, file-based)
$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ip = trim(explode(',', $ip)[0]);
@mkdir(STORE_DIR, 0775, true);
$rlFile = STORE_DIR . '/.rate-' . md5($ip) . '.json';
$now = time();
$hits = [];
if (is_file($rlFile)) {
    $hits = json_decode((string) file_get_contents($rlFile), true) ?: [];
    $hits = array_values(array_filter($hits, fn($t) => $t > $now - 600));
}
if (count($hits) >= MAX_PER_10MIN) {
    fail('Too many submissions from this connection. Please try again in a few minutes.', 429);
}

// ── Collect + validate fields ──────────────────────────────────────────────
function clean($v) {
    $v = is_array($v) ? implode(', ', $v) : (string) $v;
    $v = trim($v);
    return mb_substr($v, 0, 5000);
}
function header_safe($v) { return str_replace(["\r", "\n", "%0a", "%0d"], ' ', $v); }

$data = [];
foreach ($_POST as $k => $v) {
    if ($k[0] === '_') continue;                         // skip meta fields
    $data[$k] = clean($v);
}

$errors = [];
foreach ($required as $r) {
    if ($r === 'email') {
        if (!filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
    } elseif ($r === 'phone') {
        if (strlen(preg_replace('/\D/', '', $data['phone'] ?? '')) < 10) $errors[] = 'A valid mobile number is required.';
    } elseif (empty($data[$r])) {
        $errors[] = 'Please complete all required fields.';
    }
}
if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'A valid email address is required.';
}
if ($errors) fail(implode(' ', array_unique($errors)), 422);

// ── Optional résumé upload (careers) ───────────────────────────────────────
$storedFile = '';
if ($type === 'careers' && !empty($_FILES['resume']['name']) && is_uploaded_file($_FILES['resume']['tmp_name'])) {
    $f = $_FILES['resume'];
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'doc', 'docx'], true)) fail('Résumé must be a PDF or Word document.', 422);
    if ($f['size'] > 5 * 1024 * 1024) fail('Résumé must be under 5 MB.', 422);
    $dir = STORE_DIR . '/resumes';
    @mkdir($dir, 0775, true);
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($f['name'], PATHINFO_FILENAME));
    $storedFile = date('Ymd-His') . '-' . substr(md5(uniqid('', true)), 0, 6) . '-' . $safe . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $storedFile)) $storedFile = '(upload failed)';
}

// ── Persist (this is the "stored" guarantee — independent of email) ─────────
$record = [
    'ts'      => date('c'),
    'form'    => $type,
    'label'   => $label,
    'ip'      => $ip,
    'ua'      => header_safe(mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300)),
    'page'    => header_safe(mb_substr($_POST['_page'] ?? '', 0, 300)),
    'fields'  => $data,
    'file'    => $storedFile,
];
$logOk = @file_put_contents(
    STORE_DIR . '/' . date('Y-m') . '.log.jsonl',
    json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
    FILE_APPEND | LOCK_EX
);
$hits[] = $now;
@file_put_contents($rlFile, json_encode($hits), LOCK_EX);

// ── Email the office ───────────────────────────────────────────────────────
$lines = ["New {$label} from the BTW IMF website", str_repeat('-', 48)];
foreach ($data as $k => $v) {
    if ($v === '') continue;
    $lines[] = str_pad(ucfirst($k) . ':', 14) . $v;
}
if ($storedFile)         $lines[] = str_pad('Résumé file:', 14) . $storedFile;
if (!empty($record['page'])) $lines[] = str_pad('Submitted on:', 14) . $record['page'];
$lines[] = str_pad('Received:', 14) . date('d M Y, H:i');
$lines[] = str_pad('IP:', 14) . $ip;
$body = implode("\n", $lines) . "\n";

$replyTo = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: FROM_ADDR;
$subject = "[Website] {$label}" . (!empty($data['name']) ? ' — ' . header_safe($data['name']) : '');
$headers = implode("\r\n", [
    'From: ' . SITE_NAME . ' <' . FROM_ADDR . '>',
    'Reply-To: ' . header_safe($replyTo),
    'X-Mailer: btwimf-form-handler',
    'Content-Type: text/plain; charset=utf-8',
]);

$mailOk = @mail(RECIPIENT, header_safe($subject), $body, $headers, '-f' . FROM_ADDR);

// Success as long as we captured the lead somewhere.
if ($logOk === false && !$mailOk) {
    fail('We could not save your request just now. Please call or WhatsApp us instead.', 500);
}
done();
