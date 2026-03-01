<?php
require_once __DIR__ . '/db.php';

$origin = getenv('ALLOWED_ORIGIN') ?: 'https://numerology-olive-kappa.vercel.app';
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Key');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Kun POST tilladt']); exit; }

requireAdminKey();

$body = json_decode(file_get_contents('php://input'), true);
$filename = $body['filename'] ?? '';
$dataUrl  = $body['dataUrl']  ?? '';

if (!$filename || !$dataUrl) {
    http_response_code(400);
    echo json_encode(['error' => 'Mangler filename eller dataUrl']);
    exit;
}

if (!preg_match('/^data:(image\/(jpeg|png));base64,(.+)$/', $dataUrl, $m)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ugyldig dataUrl format']);
    exit;
}

$mime    = $m[1]; // image/jpeg eller image/png
$ext     = $m[2] === 'png' ? '.png' : '.jpg';
$data    = base64_decode($m[3]);
$safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename) . '-' . time() . $ext;

// Gem i assets/images/ relativt til dette scripts placering
$uploadDir = dirname(__DIR__) . '/assets/images/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

file_put_contents($uploadDir . $safeName, $data);

echo json_encode(['url' => 'assets/images/' . $safeName]);
