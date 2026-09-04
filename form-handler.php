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
const RECIPIENT   = 'insurance@btwimf.com, harshad@btwvisas.com, info@btwimf.com, leads@btwimf.com';  // notification recipients
const FROM_ADDR   = 'leads@btwimf.com';        // authenticated Gmail relay account
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

// ── Notification email copy: type => [heading, subject label] ───────────────
// "heading" appears in the HTML email body; "subject" builds the inbox subject
// line as "<subject> | BTW IMF Website". Falls back to the form's own label
// (above) for any type not listed here, so nothing silently loses its subject.
$EMAIL_COPY = [
    'contact'        => ['New Contact Enquiry',        'New Contact Enquiry'],
    'careers'        => ['New Job Application',        'New Job Application'],
    'product-quote'  => ['New Product Quote Enquiry',  'New Product Quote Enquiry'],
    'claim'          => ['New Claims Enquiry',          'New Claims Enquiry'],
    'renewal'        => ['New Policy Renewal Enquiry',  'New Policy Renewal Enquiry'],
    'quick-quote'    => ['New Quick Quote Enquiry',     'New Quick Quote Enquiry'],
    'vehicle-lookup' => ['New Vehicle Renewal Lookup',  'New Vehicle Renewal Lookup'],
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
// Friendlier labels for known field keys; anything else falls back to a
// title-cased version of the raw key (e.g. "vehreg" -> "Vehreg").
$FIELD_LABELS = [
    'name' => 'Name', 'phone' => 'Phone', 'email' => 'Email', 'topic' => 'Topic',
    'message' => 'Message', 'city' => 'City', 'role' => 'Role', 'type' => 'Type',
    'product' => 'Product', 'vehreg' => 'Vehicle Reg. No.',
];
function field_label($k, $FIELD_LABELS) {
    if (isset($FIELD_LABELS[$k])) return $FIELD_LABELS[$k];
    return ucwords(str_replace(['_', '-'], ' ', $k));
}

$receivedAt = date('d M Y, H:i');
[$emailHeading, $subjectLabel] = $EMAIL_COPY[$type] ?? [$label, $label];
$subject = $subjectLabel . ' | BTW IMF Website';

// ── Plain-text part (kept for clients that don't render HTML) ───────────────
$lines = [$emailHeading, str_repeat('-', 48)];
foreach ($data as $k => $v) {
    if ($v === '') continue;
    $lines[] = str_pad(field_label($k, $FIELD_LABELS) . ':', 16) . $v;
}
if ($storedFile)             $lines[] = str_pad('Résumé file:', 16) . $storedFile;
$lines[] = str_pad('Form type:', 16) . $label;
if (!empty($record['page'])) $lines[] = str_pad('Source URL:', 16) . $record['page'];
$lines[] = str_pad('Received:', 16) . $receivedAt;
$lines[] = str_pad('IP:', 16) . $ip;
$lines[] = '';
$lines[] = 'BTW IMF - Insurance & Wealth Management';
$lines[] = 'Website: https://btwimf.com';
$textBody = implode("\n", $lines) . "\n";

// ── HTML part — BTW IMF branded notification ────────────────────────────────
function e($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

$rowsHtml = '';
$zebra = false;
foreach ($data as $k => $v) {
    if ($v === '') continue;
    $zebra = !$zebra;
    $bg = $zebra ? '#FAFBFE' : '#FFFFFF';
    $rowsHtml .= '<tr style="background:' . $bg . ';">'
        . '<td style="padding:11px 16px;border-bottom:1px solid #E3E9F2;font:600 13px/1.4 Arial,Helvetica,sans-serif;color:#182450;width:150px;vertical-align:top;white-space:nowrap;">' . e(field_label($k, $FIELD_LABELS)) . '</td>'
        . '<td style="padding:11px 16px;border-bottom:1px solid #E3E9F2;font:400 13px/1.5 Arial,Helvetica,sans-serif;color:#0F1B33;word-break:break-word;">' . nl2br(e($v)) . '</td>'
        . '</tr>';
}
if ($storedFile) {
    $rowsHtml .= '<tr style="background:#FFFFFF;">'
        . '<td style="padding:11px 16px;border-bottom:1px solid #E3E9F2;font:600 13px/1.4 Arial,Helvetica,sans-serif;color:#182450;width:150px;vertical-align:top;white-space:nowrap;">Résumé file</td>'
        . '<td style="padding:11px 16px;border-bottom:1px solid #E3E9F2;font:400 13px/1.5 Arial,Helvetica,sans-serif;color:#0F1B33;word-break:break-word;">' . e($storedFile) . '</td>'
        . '</tr>';
}

$metaHtml = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font:400 12.5px/1.7 Arial,Helvetica,sans-serif;color:#48566B;">'
    . '<tr><td style="padding:2px 0;"><strong style="color:#182450;">Form type:</strong> ' . e($label) . '</td></tr>'
    . '<tr><td style="padding:2px 0;"><strong style="color:#182450;">Received:</strong> ' . e($receivedAt) . '</td></tr>'
    . (!empty($record['page']) ? '<tr><td style="padding:2px 0;word-break:break-all;"><strong style="color:#182450;">Source URL:</strong> ' . e($record['page']) . '</td></tr>' : '')
    . '</table>';

$logoUrl = 'https://btwimf.com/assets/img/btw-imf-logo.png';
$htmlBody = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e($subject) . '</title></head>'
. '<body style="margin:0;padding:0;background:#F5F7FC;">'
. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F5F7FC;padding:24px 12px;">'
. '<tr><td align="center">'
. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:100%;background:#FFFFFF;border-radius:12px;overflow:hidden;border:1px solid #E3E9F2;">'

// Header — navy, logo + brand name
. '<tr><td style="background:#101A38;padding:28px 24px 22px;text-align:center;">'
. '<img src="' . e($logoUrl) . '" width="150" alt="BTW IMF" style="display:block;margin:0 auto 12px;max-width:150px;height:auto;border:0;">'
. '<div style="font:700 20px/1.2 Arial,Helvetica,sans-serif;color:#FFFFFF;letter-spacing:.3px;">BTW IMF</div>'
. '<div style="font:400 12px/1.4 Arial,Helvetica,sans-serif;color:#B9C4E2;letter-spacing:.6px;text-transform:uppercase;margin-top:4px;">Insurance &amp; Wealth Management</div>'
. '</td></tr>'

// Accent bar — heading
. '<tr><td style="background:#2F7A63;padding:14px 24px;">'
. '<div style="font:700 16px/1.3 Arial,Helvetica,sans-serif;color:#FFFFFF;">' . e($emailHeading) . '</div>'
. '</td></tr>'

// Meta strip
. '<tr><td style="padding:18px 24px 4px;">' . $metaHtml . '</td></tr>'

// Details table
. '<tr><td style="padding:14px 24px 22px;">'
. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #E3E9F2;border-radius:8px;overflow:hidden;">'
. $rowsHtml
. '</table>'
. '</td></tr>'

// Footer
. '<tr><td style="background:#101A38;padding:18px 24px;text-align:center;">'
. '<div style="font:600 12.5px/1.5 Arial,Helvetica,sans-serif;color:#FFFFFF;">BTW IMF &ndash; Insurance &amp; Wealth Management</div>'
. '<div style="font:400 12px/1.6 Arial,Helvetica,sans-serif;color:#8FA0C5;">Website: <a href="https://btwimf.com" style="color:#7BBFA7;text-decoration:none;">https://btwimf.com</a></div>'
. '</td></tr>'

. '</table>'
. '</td></tr>'
. '</table>'
. '</body></html>';

$replyTo = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: FROM_ADDR;
$boundary = 'btwimf-' . bin2hex(random_bytes(12));
$headers = implode("\r\n", [
    'From: ' . SITE_NAME . ' <' . FROM_ADDR . '>',
    'Reply-To: ' . header_safe($replyTo),
    'X-Mailer: btwimf-form-handler',
    'MIME-Version: 1.0',
    'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
]);

$mimeBody = "--{$boundary}\r\n"
    . "Content-Type: text/plain; charset=utf-8\r\n"
    . "Content-Transfer-Encoding: 8bit\r\n\r\n"
    . $textBody . "\r\n\r\n"
    . "--{$boundary}\r\n"
    . "Content-Type: text/html; charset=utf-8\r\n"
    . "Content-Transfer-Encoding: 8bit\r\n\r\n"
    . $htmlBody . "\r\n\r\n"
    . "--{$boundary}--";

$mailOk = @mail(RECIPIENT, header_safe($subject), $mimeBody, $headers, '-f' . FROM_ADDR);

// Success as long as we captured the lead somewhere.
if ($logOk === false && !$mailOk) {
    fail('We could not save your request just now. Please call or WhatsApp us instead.', 500);
}
done();
