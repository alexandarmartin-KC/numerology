<?php
// Delt database-forbindelse
define('DB_HOST', 'localhost');
define('DB_USER', 'alexanda_numerology');
define('DB_PASS', 'Mabber0700');
define('DB_NAME', 'alexanda_numerology');

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

function jsonOut(mixed $data, int $code = 200): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getBody(): mixed {
    $raw = json_decode(file_get_contents('php://input'), true) ?? [];
    if (isset($raw['_b64'])) {
        return json_decode(base64_decode($raw['_b64']), true) ?? [];
    }
    return $raw;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(200);
    exit;
}
