<?php
// Delt database-forbindelse
// Indlæs lokalt .env.php hvis det findes (til lokal dev)
$_envFile = __DIR__ . '/.env.php';
if (file_exists($_envFile)) include $_envFile;

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: '');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: '');

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
    $origin = getenv('ALLOWED_ORIGIN') ?: 'https://numerology-olive-kappa.vercel.app';
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
function requireAdminKey(): void {
    $envKey = getenv('ADMIN_API_KEY');
    if (!$envKey) return; // Ingen nøgle konfigureret → tillad (backward-compat)
    $sent = $_SERVER['HTTP_X_ADMIN_KEY'] ?? '';
    if (!hash_equals($envKey, $sent)) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Uautoriseret']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    $origin = getenv('ALLOWED_ORIGIN') ?: 'https://numerology-olive-kappa.vercel.app';
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Admin-Key');
    http_response_code(200);
    exit;
}
