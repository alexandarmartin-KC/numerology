<?php
require_once __DIR__ . '/db.php';
error_reporting(E_ERROR);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

$_OPENAI_API_KEY = '';
$apiKey = getenv('OPENAI_API_KEY') ?: '';
if (!$apiKey) {
    $envFile = __DIR__ . '/.env.php';
    if (file_exists($envFile)) include $envFile;
    $apiKey = $_OPENAI_API_KEY ?? '';
}
if (!$apiKey) { http_response_code(500); echo json_encode(['error' => 'OPENAI_API_KEY ikke konfigureret']); exit; }

$body            = json_decode(file_get_contents('php://input'), true) ?? [];
$userDescription = $body['prompt'] ?? '';
$k               = $body['knowledge'] ?? [];

if (!$userDescription) { http_response_code(400); echo json_encode(['error' => 'Manglende beskrivelse']); exit; }

function buildSystemPrompt(array $k): string {
    $p = "Du er en erfaren numerolog og skribent. Du skriver artikler, blogindlæg og tekster om numerologi på dansk.\n\nVIGTIGE REGLER:\n- Du må KUN bruge den numerologiske viden der er givet nedenfor. Opfind IKKE ny numerologisk viden.\n- Skriv i et engagerende, varmt og professionelt sprog.\n- Brug formatering med overskrifter (##), afsnit og eventuelt punktlister.\n- Teksten skal føles som skrevet af en ekspert der brænder for faget.\n";

    if (!empty($k['aboutNumerology'])) $p .= "\n## Om numerologi\n{$k['aboutNumerology']}\n";
    if (!empty($k['defRent'])) $p .= "\n## Definition: Rent numeroskop\n{$k['defRent']}\n";
    if (!empty($k['defUrent'])) $p .= "\n## Definition: Urent numeroskop\n{$k['defUrent']}\n";
    if (!empty($k['blokkeAfTal'])) $p .= "\n## Blokke af tal\n{$k['blokkeAfTal']}\n";
    if (!empty($k['diamantAar'])) $p .= "\n## Diamant og årstalsrækker\n{$k['diamantAar']}\n";
    if (!empty($k['udrensning'])) $p .= "\n## Udrensning\n{$k['udrensning']}\n";
    if (!empty($k['numerologiAlder'])) $p .= "\n## Numerologi og alder\n{$k['numerologiAlder']}\n";

    if (!empty($k['energies'])) {
        $p .= "\n## Energibeskrivelser\n";
        foreach ($k['energies'] as $e) {
            $p .= "\n### Energi " . ($e['display'] ?? $e['id'] ?? '') . "\n";
            if (!empty($e['keywords'])) $p .= "Nøgleord (rent): {$e['keywords']}\n";
            if (!empty($e['keywords_urent_numeroskop'])) $p .= "Nøgleord (urent): {$e['keywords_urent_numeroskop']}\n";
            if (!empty($e['grundenergi'])) $p .= "Grundenergi: {$e['grundenergi']}\n";
            if (!empty($e['beskrivelse'])) $p .= "Beskrivelse: {$e['beskrivelse']}\n";
            if (!empty($e['ubalance_i_urent_numeroskop'])) $p .= "Ubalance: {$e['ubalance_i_urent_numeroskop']}\n";
            if (!empty($e['helheds_funktion'])) $p .= "Helhedsfunktion: {$e['helheds_funktion']}\n";
            if (!empty($e['planet'])) $p .= "Planet: {$e['planet']}\n";
        }
    }
    if (!empty($k['positions'])) {
        $p .= "\n## Positioner i diamanten\n";
        foreach ($k['positions'] as $pos) {
            if (!empty($pos['description'])) $p .= "- {$pos['name']}: {$pos['description']}\n";
        }
    }
    if (!empty($k['cycles_about'])) $p .= "\n## Om cyklusser\n{$k['cycles_about']}\n";
    if (!empty($k['cycles_124875'])) $p .= "\n## Cyklus 1-2-4-8-7-5\n{$k['cycles_124875']}\n";
    if (!empty($k['cycles_36'])) $p .= "\n## Cyklus 3-6\n{$k['cycles_36']}\n";
    if (!empty($k['cycles_9'])) $p .= "\n## Cyklus 9\n{$k['cycles_9']}\n";
    if (!empty($k['astrologyGenerelt'])) $p .= "\n## Astrologi generelt\n{$k['astrologyGenerelt']}\n";
    return $p;
}

$systemPrompt = buildSystemPrompt($k);
$userPrompt   = "Skriv følgende artikel/tekst:\n\n{$userDescription}";

$payload = json_encode([
    'model'       => 'gpt-4o',
    'messages'    => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user',   'content' => $userPrompt]
    ],
    'temperature' => 0.7,
    'max_tokens'  => 8000
], JSON_UNESCAPED_UNICODE);

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
    CURLOPT_TIMEOUT        => 120
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) { http_response_code(500); echo json_encode(['error' => 'cURL fejl: ' . $curlErr]); exit; }
if ($httpCode !== 200) { http_response_code(500); echo json_encode(['error' => 'OpenAI API fejl', 'details' => $response]); exit; }

$data    = json_decode($response, true);
$artikel = $data['choices'][0]['message']['content'] ?? '';
echo json_encode(['artikel' => $artikel, 'usage' => $data['usage'] ?? null], JSON_UNESCAPED_UNICODE);
