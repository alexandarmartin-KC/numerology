<?php
require_once __DIR__ . '/db.php';
error_reporting(E_ERROR);

$origin = CFG_ALLOWED_ORIGIN;
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

$apiKey = getenv('OPENAI_API_KEY') ?: ($_OPENAI_API_KEY ?? '');
if (!$apiKey) {
    // Læs fra .env.php direkte hvis ikke sat som env
    $envFile = __DIR__ . '/.env.php';
    if (file_exists($envFile)) {
        $_OPENAI_API_KEY = '';
        include $envFile;
        $apiKey = $_OPENAI_API_KEY ?? '';
    }
}
if (!$apiKey) { http_response_code(500); echo json_encode(['error' => 'OPENAI_API_KEY ikke konfigureret']); exit; }

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$firstName          = $body['firstName'] ?? '';
$nameData           = $body['nameData'] ?? '';
$energyDescriptions = $body['energyDescriptions'] ?? '';
$customSystemPrompt = $body['customSystemPrompt'] ?? null;
$sentenceRange      = $body['sentenceRange'] ?? '8–10';

if (!$firstName || !$nameData) {
    http_response_code(400); echo json_encode(['error' => 'Manglende data']); exit;
}

if ($customSystemPrompt) {
    $systemPrompt = $customSystemPrompt . "\n\nNUMEROLOGISK VIDEN:\n" . ($energyDescriptions ?: 'Ingen energibeskrivelser tilgængelige.');
} else {
    $systemPrompt = "Du er en erfaren numerolog. Du laver en kort og personlig numerologisk analyse på dansk baseret på en persons fulde numerologiske diamant.

Du modtager de præcise diamantpositioner: grundenergi (top/fødselsdagstal), livslinje (navnedele), bundtal, aura (4 hjørner), hjertecenter, solarplexus, rygraden og søjletal.

REGLER:
- Skriv præcis 8-10 korte, personlige sætninger i ét samlet afsnit.
- Brug personens fornavn naturligt 1-2 gange.
- Basér analysen på de KONKRETE diamantpositioner du modtager.
- Grundenergien (top) er personens kerneenergi — vægt den tungest.
- Nævn kort samspillet mellem fx hjertecenter og grundenergi, eller aura og bundtal.
- Tonen skal være varm, indsigtsfuld og lidt mystisk — som om du kender dem.
- Skriv IKKE overskrifter, bullets eller formatering. Kun løbende tekst.
- Skriv IKKE \"dit tal er...\" eller tekniske forklaringer. Gå direkte til personlighed.
- Nævn ALDRIG planeter (Sol, Saturn, Jupiter osv.) — hold fokus rent på tallenes energi.
- Hold det positivt men ærligt — nævn gerne en mild udfordring.
- Slut med en sætning der antyder at den fulde diamant rummer endnu mere at udforske.

NUMEROLOGISK VIDEN:
" . ($energyDescriptions ?: 'Ingen energibeskrivelser tilgængelige.');
}

$userPrompt = "Personen hedder {$firstName}.\n\n{$nameData}\n\nSkriv en kort, personlig numerologisk analyse ({$sentenceRange} sætninger i ét afsnit).";

$payload = json_encode([
    'model'       => 'gpt-4o',
    'messages'    => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user',   'content' => $userPrompt]
    ],
    'temperature' => 0.8,
    'max_tokens'  => 600
], JSON_UNESCAPED_UNICODE);

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ],
    CURLOPT_TIMEOUT        => 60
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) { http_response_code(500); echo json_encode(['error' => 'cURL fejl: ' . $curlErr]); exit; }
if ($httpCode !== 200) { http_response_code(500); echo json_encode(['error' => 'OpenAI API fejl', 'details' => $response]); exit; }

$data    = json_decode($response, true);
$reading = $data['choices'][0]['message']['content'] ?? '';
echo json_encode(['reading' => $reading, 'usage' => $data['usage'] ?? null], JSON_UNESCAPED_UNICODE);
