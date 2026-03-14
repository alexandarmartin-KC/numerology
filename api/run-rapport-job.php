<?php
// Worker-script: kan køres som CLI-process (via exec) eller HTTP POST (fallback).
// Kører uafhængigt af browser-forbindelsen og gemmer resultatet i DB.
ini_set('display_errors', '0');
error_reporting(0);
ignore_user_abort(true);
@set_time_limit(300);

require_once __DIR__ . '/db.php';

// ─── Hent jobId afhængigt af SAPI ───
if (PHP_SAPI === 'cli') {
    // Kaldt via exec() — jobId som første CLI-argument
    $jobId = $argv[1] ?? '';
} else {
    // Kaldt via HTTP (cURL fallback)
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
    $body  = json_decode(file_get_contents('php://input'), true) ?? [];
    $jobId = $body['jobId'] ?? '';
    // Svar hurtigt og fortsæt i baggrunden
    http_response_code(200);
    header('Content-Type: text/plain');
    echo 'ok';
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    else { while (ob_get_level()) ob_end_flush(); flush(); }
}

if (!$jobId || !preg_match('/^[a-f0-9]{32}$/', $jobId)) exit;

// API-nøgle fra miljø (db.php loader .env.php)
$apiKey = getenv('ANTHROPIC_API_KEY') ?: ($_ANTHROPIC_API_KEY ?? '');
if (!$apiKey) exit;

// ─── Hent payload fra DB ───
$db   = getDB();
$stmt = $db->prepare("SELECT payload FROM rapport_jobs WHERE id = ? AND status = 'processing'");
$stmt->bind_param('s', $jobId);
$stmt->execute();
$res  = $stmt->get_result();
$job  = $res->fetch_assoc();
$stmt->close();

if (!$job || !$job['payload']) {
    $err = 'Job ikke fundet eller mangler payload';
    $stmt = $db->prepare("UPDATE rapport_jobs SET status='error', error=? WHERE id=?");
    $stmt->bind_param('ss', $err, $jobId);
    $stmt->execute();
    exit;
}

// ─── Kald Claude API ───
$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $job['payload'],
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01'
    ],
    CURLOPT_TIMEOUT        => 280,
    CURLOPT_CONNECTTIMEOUT => 15,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    $errMsg = 'cURL fejl: ' . $curlErr;
    $stmt = $db->prepare("UPDATE rapport_jobs SET status='error', error=? WHERE id=?");
    $stmt->bind_param('ss', $errMsg, $jobId);
    $stmt->execute();
    exit;
}

$data = json_decode($response, true);
if ($httpCode !== 200) {
    $errMsg = 'Claude API fejl (HTTP ' . $httpCode . '): ' . ($data['error']['message'] ?? substr($response, 0, 300));
    $stmt = $db->prepare("UPDATE rapport_jobs SET status='error', error=? WHERE id=?");
    $stmt->bind_param('ss', $errMsg, $jobId);
    $stmt->execute();
    exit;
}

$rapport = $data['content'][0]['text'] ?? '';
$stmt = $db->prepare("UPDATE rapport_jobs SET status='done', result=?, payload=NULL WHERE id=?");
$stmt->bind_param('ss', $rapport, $jobId);
$stmt->execute();
