<?php
/* ============================================================
   /api/create-checkout.php
   Opretter en Stripe Checkout Session (USD).
   Kalder Stripe REST API direkte via cURL (ingen composer).
   ============================================================ */
error_reporting(0);
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . CFG_ALLOWED_ORIGIN);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

// ─── Konfiguration (sæt i .env.php) ───
$secretKey = $_STRIPE_SECRET_KEY ?? '';
$baseUrl   = $_BASE_URL ?? CFG_ALLOWED_ORIGIN;

// ─── Input ───
$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$fullName  = trim($body['fullName'] ?? '');
$birthDate = trim($body['birthDate'] ?? '');
$email     = trim($body['email'] ?? '');
$currency  = 'usd';
$plan      = $body['plan'] ?? 'foundation';

$planConfig = [
    'foundation' => ['name' => 'Foundation Report', 'usd' => 3500],
    'direction'  => ['name' => 'Direction Report',  'usd' => 7500],
    'activation' => ['name' => 'Activation Report', 'usd' => 14900],
];
if (!isset($planConfig[$plan])) $plan = 'foundation';
$planName   = $planConfig[$plan]['name'];
$unitAmount = $planConfig[$plan][$currency];

if (!$fullName)  { http_response_code(400); echo json_encode(['error' => 'Fuldt navn er påkrævet.']); exit; }
if (!$birthDate) { http_response_code(400); echo json_encode(['error' => 'Fødselsdag er påkrævet.']); exit; }
if (!$email)     { http_response_code(400); echo json_encode(['error' => 'E-mail er påkrævet.']); exit; }

// ─── Mock mode (ingen Stripe-nøgle) ───
if (!$secretKey) {
    $mockUrl = $baseUrl . '/mock-checkout.html?name=' . urlencode($fullName)
             . '&birth=' . urlencode($birthDate)
             . '&email=' . urlencode($email)
             . '&currency=' . $currency
             . '&plan=' . urlencode($plan);
    echo json_encode(['url' => $mockUrl]);
    exit;
}

// ─── Stripe API via cURL ───
$params = http_build_query([
    'payment_method_types[0]'                            => 'card',
    'payment_method_types[1]'                            => 'paypal',
    'mode'                                               => 'payment',
    'customer_email'                                     => $email,
    'line_items[0][price_data][currency]'                => $currency,
    'line_items[0][price_data][unit_amount]'             => $unitAmount,
    'line_items[0][price_data][product_data][name]'      => $planName,
    'line_items[0][price_data][product_data][description]' => "Analyse for: {$fullName} ({$birthDate})",
    'line_items[0][quantity]'                            => 1,
    'metadata[fullName]'                                 => $fullName,
    'metadata[birthDate]'                                => $birthDate,
    'success_url'                                        => $baseUrl . '/tak.html?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url'                                         => $baseUrl . '/landing.html',
]);

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $params,
    CURLOPT_USERPWD        => $secretKey . ':',
    CURLOPT_TIMEOUT        => 30,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) { http_response_code(500); echo json_encode(['error' => 'cURL fejl: ' . $curlErr]); exit; }

$data = json_decode($response, true);
if ($httpCode !== 200) {
    $msg = $data['error']['message'] ?? 'Checkout session kunne ikke oprettes.';
    http_response_code(500);
    echo json_encode(['error' => $msg]);
    exit;
}

echo json_encode(['url' => $data['url']]);
