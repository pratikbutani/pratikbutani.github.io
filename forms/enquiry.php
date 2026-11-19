<?php
/**
 * Enquiry form handler for pratikbutani.com.
 *
 * GET  ?action=captcha  -> issues a new arithmetic question, kept in the session
 * POST                  -> validates the enquiry and emails it
 *
 * Sends through the site's own cPanel mailbox over SMTP (PHP mail() is disabled on this host).
 * No database and no outside services. Spam protection:
 * captcha, hidden honeypot field, minimum fill time, same-site check and a per-IP rate limit.
 * Every response is JSON.
 */

declare(strict_types=1);

const MAIL_TO        = 'pratik13butani@gmail.com';
const MAIL_FROM_NAME = 'pratikbutani.com';
const MIN_FILL_SECS  = 4;        // humans need longer than this to fill the form
const MAX_FORM_AGE   = 7200;     // captcha expires after 2 hours
const RATE_LIMIT     = 5;        // successful sends per IP...
const RATE_WINDOW    = 3600;     // ...per hour
const MAX_CAPTCHA_TRIES = 5;     // wrong answers before a new question is required

const HELP_TYPES = [
    'leadership'   => 'Leadership role',
    'advisory'     => 'Advisory or consulting',
    'build'        => 'Product or app build',
    'review'       => 'Architecture or code review',
    'talk'         => 'Talk or workshop',
    'mentoring'    => 'Mentoring',
    'other'        => 'Something else',
];

const TIMELINES = [
    ''           => 'Not specified',
    'asap'       => 'As soon as possible',
    '1-3-months' => 'In the next 1 to 3 months',
    'later'      => 'Later than 3 months',
    'exploring'  => 'Just exploring',
];

// Any fatal error still gets a JSON reply, with a short code for diagnosis (no error text is exposed).
register_shutdown_function(function (): void {
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'ok'    => false,
            'error' => 'Your message could not be sent just now. Please email me directly at ' . MAIL_TO . '.',
            'code'  => 'fatal_' . $e['type'] . '_line_' . $e['line'],
        ]);
    }
});

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');

function respond(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(int $status, string $message, ?string $field = null, bool $newCaptcha = false): void
{
    $data = ['ok' => false, 'error' => $message];
    if ($field !== null) {
        $data['field'] = $field;
    }
    if ($newCaptcha) {
        $data['captcha'] = issue_captcha();
    }
    respond($status, $data);
}

function start_session(): void
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('pb_enquiry');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/forms/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function issue_captcha(): string
{
    $a = random_int(2, 9);
    $b = random_int(1, 9);
    if (random_int(0, 1) === 1 && $a > $b) {
        $question = "$a − $b";
        $answer = $a - $b;
    } else {
        $question = "$a + $b";
        $answer = $a + $b;
    }
    $_SESSION['captcha_answer'] = $answer;
    $_SESSION['captcha_issued'] = time();
    $_SESSION['captcha_tries']  = 0;
    return "What is $question?";
}

/** Rejects requests that were not sent from this site's own pages. */
function same_site(): bool
{
    $source = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    if ($source === '') {
        return false;
    }
    $sourceHost = strtolower((string) parse_url($source, PHP_URL_HOST));
    $serverHost = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
    return $sourceHost !== '' && $sourceHost === $serverHost;
}

function client_ip(): string
{
    // The site sits behind Cloudflare, which passes the visitor's address in this header.
    return $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function rate_limit_file(): string
{
    return sys_get_temp_dir() . '/pb_enquiry_' . hash('sha256', client_ip() . __FILE__) . '.json';
}

/** Returns the send times within the current window for this IP. */
function recent_sends(): array
{
    $file = rate_limit_file();
    if (!is_file($file)) {
        return [];
    }
    $times = json_decode((string) file_get_contents($file), true);
    if (!is_array($times)) {
        return [];
    }
    $cutoff = time() - RATE_WINDOW;
    return array_values(array_filter($times, fn($t) => is_int($t) && $t > $cutoff));
}

function record_send(array $times): void
{
    $times[] = time();
    @file_put_contents(rate_limit_file(), json_encode($times), LOCK_EX);
}

/** Single-line text: trims, removes control characters (so nothing can be added to mail headers). */
function clean_line(string $value, int $max): string
{
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    return mb_substr($value, 0, $max);
}

/** Multi-line text: keeps line breaks, removes other control characters. */
function clean_text(string $value, int $max): string
{
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', $value) ?? '';
    return mb_substr(trim($value), 0, $max);
}

function post(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? $value : '';
}

/**
 * SMTP settings come from forms/smtp-config.php, which the deploy workflow writes from the
 * SMTP_PASSWORD secret (never committed, and blocked from the web by forms/.htaccess).
 * The file returns an array: host, port, user, pass, from.
 */
function load_smtp_config(): array
{
    $candidates = [
        __DIR__ . '/smtp-config.php',              // written by the deploy workflow from a GitHub secret
        dirname(__DIR__, 2) . '/enquiry-smtp.php', // or placed by hand above public_html
    ];
    $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
    if ($docRoot !== '') {
        $candidates[] = dirname($docRoot) . '/enquiry-smtp.php';
    }
    $file = null;
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            $file = $candidate;
            break;
        }
    }
    if ($file === null) {
        send_failed('smtp_config_missing');
    }
    if (!is_readable($file)) {
        send_failed('smtp_config_unreadable');
    }
    $config = include $file;
    if (!is_array($config) || empty($config['user']) || empty($config['pass'])) {
        send_failed('smtp_config_invalid');
    }
    return [
        'host' => (string) ($config['host'] ?? '127.0.0.1'),
        'port' => (int) ($config['port'] ?? 465),
        'user' => (string) $config['user'],
        'pass' => (string) $config['pass'],
        'from' => (string) ($config['from'] ?? $config['user']),
    ];
}

function send_failed(string $code): void
{
    respond(500, [
        'ok'    => false,
        'error' => 'Your message could not be sent just now. Please email me directly at ' . MAIL_TO . '.',
        'code'  => $code,
    ]);
}

/** Reads one SMTP reply (which may span several lines) and returns its status code. */
function smtp_read($socket): int
{
    $code = 0;
    while (($line = fgets($socket, 1024)) !== false) {
        $code = (int) substr($line, 0, 3);
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }
    return $code;
}

function smtp_cmd($socket, string $command, array $expect): bool
{
    fwrite($socket, $command . "\r\n");
    return in_array(smtp_read($socket), $expect, true);
}

/**
 * Sends one plain-text message through the cPanel mail server.
 * Returns null on success, or a short error code (no server text is exposed).
 */
function smtp_send(array $c, string $to, string $headers, string $body): ?string
{
    $local = in_array($c['host'], ['127.0.0.1', 'localhost'], true);
    $context = stream_context_create(['ssl' => [
        // The certificate on the loopback address is issued for the hostname, not 127.0.0.1.
        'verify_peer'      => !$local,
        'verify_peer_name' => !$local,
    ]]);
    $scheme = $c['port'] === 465 ? 'ssl://' : 'tcp://';
    $socket = @stream_socket_client($scheme . $c['host'] . ':' . $c['port'], $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        return 'smtp_connect';
    }
    stream_set_timeout($socket, 15);

    if (smtp_read($socket) !== 220) {
        return 'smtp_greeting';
    }
    if (!smtp_cmd($socket, 'EHLO pratikbutani.com', [250])) {
        return 'smtp_ehlo';
    }
    if ($c['port'] === 587) {
        if (!smtp_cmd($socket, 'STARTTLS', [220])
            || !stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)
            || !smtp_cmd($socket, 'EHLO pratikbutani.com', [250])) {
            return 'smtp_tls';
        }
    }
    if (!smtp_cmd($socket, 'AUTH LOGIN', [334])
        || !smtp_cmd($socket, base64_encode($c['user']), [334])
        || !smtp_cmd($socket, base64_encode($c['pass']), [235])) {
        return 'smtp_auth';
    }
    if (!smtp_cmd($socket, 'MAIL FROM:<' . $c['from'] . '>', [250])) {
        return 'smtp_from';
    }
    if (!smtp_cmd($socket, 'RCPT TO:<' . $to . '>', [250, 251])) {
        return 'smtp_rcpt';
    }
    if (!smtp_cmd($socket, 'DATA', [354])) {
        return 'smtp_data';
    }
    // Lines starting with a dot get an extra dot, as SMTP requires.
    $data = $headers . "\r\n\r\n" . preg_replace('/^\./m', '..', $body);
    if (!smtp_cmd($socket, $data . "\r\n.", [250])) {
        return 'smtp_rejected';
    }
    smtp_cmd($socket, 'QUIT', [221]);
    fclose($socket);
    return null;
}

// ---------------------------------------------------------------------------

start_session();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && ($_GET['action'] ?? '') === 'captcha') {
    respond(200, ['ok' => true, 'captcha' => issue_captcha()]);
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    fail(405, 'Method not allowed.');
}

if (!same_site()) {
    fail(403, 'Please send the form from pratikbutani.com.');
}

// Honeypot: real visitors never see this field. Bots that fill it get a quiet "success".
if (post('website') !== '') {
    respond(200, ['ok' => true]);
}

if (!isset($_SESSION['captcha_answer'], $_SESSION['captcha_issued'])) {
    fail(400, 'Your session expired. Please answer the new question and send again.', 'captcha', true);
}

$age = time() - (int) $_SESSION['captcha_issued'];
if ($age > MAX_FORM_AGE) {
    fail(400, 'The form was open too long. Please answer the new question and send again.', 'captcha', true);
}
if ($age < MIN_FILL_SECS) {
    fail(400, 'That was quicker than a person can type. Please wait a moment and send again.');
}

if (count(recent_sends()) >= RATE_LIMIT) {
    fail(429, 'Too many messages from your connection in the last hour. Please email me directly at ' . MAIL_TO . '.');
}

// Fields
$name     = clean_line(post('name'), 80);
$email    = clean_line(post('email'), 120);
$company  = clean_line(post('company'), 120);
$helpKey  = post('help_type');
$timeKey  = post('timeline');
$message  = clean_text(post('message'), 4000);
$answer   = trim(post('captcha'));

if (mb_strlen($name) < 2) {
    fail(400, 'Please enter your name.', 'name');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail(400, 'Please enter a valid email address.', 'email');
}
if (!array_key_exists($helpKey, HELP_TYPES)) {
    fail(400, 'Please choose what you need help with.', 'help_type');
}
if (!array_key_exists($timeKey, TIMELINES)) {
    $timeKey = '';
}
if (mb_strlen($message) < 20) {
    fail(400, 'Please tell me a little more, at least 20 characters.', 'message');
}

// Captcha last, so a wrong answer never wipes the visitor's other input.
if (!preg_match('/^-?\d{1,3}$/', $answer) || (int) $answer !== (int) $_SESSION['captcha_answer']) {
    $_SESSION['captcha_tries'] = (int) ($_SESSION['captcha_tries'] ?? 0) + 1;
    $fresh = $_SESSION['captcha_tries'] >= MAX_CAPTCHA_TRIES;
    fail(400, $fresh ? 'Too many wrong answers. Here is a new question.' : 'That answer is not right. Please try again.', 'captcha', $fresh);
}

// Build the email
$helpLabel = HELP_TYPES[$helpKey];
$subject   = 'Website enquiry: ' . $helpLabel . ' from ' . $name;

$lines = [
    'New enquiry from the contact form on pratikbutani.com',
    '',
    'Name:      ' . $name,
    'Email:     ' . $email,
    'Company:   ' . ($company !== '' ? $company : '-'),
    'Help with: ' . $helpLabel,
    'Timeline:  ' . TIMELINES[$timeKey],
    '',
    'Message:',
    $message,
    '',
    '--',
    'Sent ' . gmdate('Y-m-d H:i') . ' UTC. Reply to this email to answer ' . $name . ' directly.',
];
// SMTP needs CRLF line endings throughout, including inside the visitor's message.
$body = str_replace("\n", "\r\n", implode("\n", $lines));

$encodedSubject = function_exists('mb_encode_mimeheader')
    ? mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n")
    : '=?UTF-8?B?' . base64_encode($subject) . '?=';

$smtp = load_smtp_config();

$headers = [
    'Date: ' . date('r'),
    'From: ' . MAIL_FROM_NAME . ' <' . $smtp['from'] . '>',
    'To: <' . MAIL_TO . '>',
    'Reply-To: <' . $email . '>',
    'Subject: ' . $encodedSubject,
    'Message-ID: <' . bin2hex(random_bytes(12)) . '@pratikbutani.com>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
    'X-Mailer: pratikbutani.com enquiry form',
];

$error = smtp_send($smtp, MAIL_TO, implode("\r\n", $headers), $body);
if ($error !== null) {
    send_failed($error);
}

record_send(recent_sends());
unset($_SESSION['captcha_answer'], $_SESSION['captcha_issued'], $_SESSION['captcha_tries']);

respond(200, ['ok' => true]);
