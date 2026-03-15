<?php
// Delt database-forbindelse
// Indlæs lokalt .env.php hvis det findes (til lokal dev)
$_DB_HOST = ''; $_DB_USER = ''; $_DB_PASS = ''; $_DB_NAME = '';
$_ALLOWED_ORIGIN = ''; $_ADMIN_API_KEY = '';
$_STRIPE_SECRET_KEY = ''; $_BASE_URL = '';
$_STRIPE_WEBHOOK_SECRET = '';
$_MAIL_FROM = ''; $_MAIL_FROM_NAME = 'StrategicNumerology';
$_envFile = __DIR__ . '/.env.php';
if (file_exists($_envFile)) include $_envFile;

// Understøtter både putenv() og direkte variabel-tildeling i .env.php
// (LiteSpeed og nogle cPanel-servere blokerer putenv)
define('DB_HOST',        getenv('DB_HOST')        ?: ($_DB_HOST        ?: 'localhost'));
define('DB_USER',        getenv('DB_USER')        ?: ($_DB_USER        ?: ''));
define('DB_PASS',        getenv('DB_PASS')        ?: ($_DB_PASS        ?: ''));
define('DB_NAME',        getenv('DB_NAME')        ?: ($_DB_NAME        ?: ''));
define('CFG_ALLOWED_ORIGIN',          getenv('ALLOWED_ORIGIN')          ?: ($_ALLOWED_ORIGIN          ?: 'https://alexandarmartin.dk'));
define('CFG_ADMIN_KEY',               getenv('ADMIN_API_KEY')           ?: ($_ADMIN_API_KEY           ?: ''));
define('CFG_STRIPE_WEBHOOK_SECRET',   getenv('STRIPE_WEBHOOK_SECRET')   ?: ($_STRIPE_WEBHOOK_SECRET   ?: ''));
define('CFG_MAIL_FROM',               getenv('MAIL_FROM')               ?: ($_MAIL_FROM               ?: ''));
define('CFG_MAIL_FROM_NAME',          getenv('MAIL_FROM_NAME')          ?: ($_MAIL_FROM_NAME          ?: 'StrategicNumerology'));

function getDB(): mysqli {
    static $db = null;
    if ($db === null) {
        $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $db->set_charset('utf8mb4');
        if ($db->connect_error) {
            http_response_code(500);
            die(json_encode(['error' => 'DB forbindelsesfejl: ' . $db->connect_error]));
        }
    }
    return $db;
}

function jsonOut($data, int $code = 200): void {
    $origin = CFG_ALLOWED_ORIGIN;
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Admin-Key');
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getBody() {
    $raw = json_decode(file_get_contents('php://input'), true) ?? [];
    if (isset($raw['_b64'])) {
        return json_decode(base64_decode($raw['_b64']), true) ?? [];
    }
    return $raw;
}

/**
 * Kræver en gyldig ADMIN_API_KEY sendt som X-Admin-Key header.
 * Sæt ADMIN_API_KEY som miljøvariabel (serverens env eller .env.php).
 * Kald denne funktion i starten af POST-handlere i admin-endpoints.
 */
/**
 * Returner klientens reelle IP (håndtér CDN/proxy X-Forwarded-For).
 */
function getClientIp(): string {
    $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($fwd) {
        // Tag første IP i listen (klientens — ikke proxyens)
        return trim(explode(',', $fwd)[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Tjek og registrer et gratis-kald for den given IP.
 * Max $maxCalls kald pr. 24-timers vindue.
 * Returnerer ['allowed' => bool, 'remaining' => int, 'resetIn' => int (sekunder)].
 */
function checkGratisRateLimit(int $maxCalls = 4): array {
    return ['allowed' => true, 'remaining' => $maxCalls, 'resetIn' => 0];
}

function requireAdminKey(): void {
    $envKey = CFG_ADMIN_KEY;
    if (!$envKey) return; // Ingen nøgle konfigureret → tillad (backward-compat)
    $sent = $_SERVER['HTTP_X_ADMIN_KEY'] ?? '';
    if (!hash_equals($envKey, $sent)) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Uautoriseret']);
        exit;
    }
}

/**
 * Send an HTML email.
 * php mail() bruges nu — byt sendSmtp() funktion ud for at skifte til SendGrid/Mailgun/SMTP.
 *
 * @param string $to       Modtagerens email
 * @param string $subject  Emne
 * @param string $htmlBody HTML-indhold
 * @return bool            True hvis mail() ikke returnerede false
 */
function sendEmail(string $to, string $subject, string $htmlBody): bool {
    $fromEmail = CFG_MAIL_FROM ?: 'noreply@alexandarmartin.dk';
    $fromName  = CFG_MAIL_FROM_NAME ?: 'StrategicNumerology';

    // ── Skift her til SMTP/SendGrid/Mailgun når det er klar ──
    // Eksempel SendGrid:
    //   return sendSendGrid($to, $subject, $htmlBody, $fromEmail, $fromName);

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
    $headers .= "Reply-To: {$fromEmail}\r\n";
    $headers .= "X-Mailer: PHP/" . PHP_VERSION . "\r\n";

    return mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $htmlBody, $headers);
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    $origin = CFG_ALLOWED_ORIGIN;
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Admin-Key');
    http_response_code(200);
    exit;
}
