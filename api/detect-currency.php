<?php
/* ============================================================
   /api/detect-currency.php
   Returnerer anbefalet valuta baseret på besøgendes land.
   US-besøgende → usd, alle andre → eur.
   ============================================================ */
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// Prøv Cloudflare country header (fungerer hvis hosting er bag Cloudflare)
$country = $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '';

// Prøv andre kendte country-headers
if (!$country) $country = $_SERVER['HTTP_X_COUNTRY_CODE'] ?? '';
if (!$country) $country = $_SERVER['HTTP_X_VERCEL_IP_COUNTRY'] ?? '';

// Fallback: hent via gratis ipapi.co (maks 1000 req/dag gratis)
if (!$country) {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
        ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]
        : ($_SERVER['REMOTE_ADDR'] ?? '');
    $ip = trim($ip);
    if ($ip && !in_array($ip, ['127.0.0.1', '::1'])) {
        $ch = curl_init("https://ipapi.co/{$ip}/country/");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_USERAGENT      => 'numerology-app/1.0'
        ]);
        $result = curl_exec($ch);
        curl_close($ch);
        if ($result && strlen($result) === 2) {
            $country = strtoupper(trim($result));
        }
    }
}

$currency = 'usd';
echo json_encode(['currency' => $currency, 'country' => $country ?: 'unknown']);
