<?php
error_reporting(E_ERROR);
ini_set('display_errors', '0');
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

// ─── API-nøgle ───
// db.php har allerede inkluderet .env.php, så $_OPENAI_API_KEY er sat
$apiKey = getenv('OPENAI_API_KEY') ?: ($_OPENAI_API_KEY ?? '');
if (!$apiKey) { http_response_code(500); echo json_encode(['error' => 'OPENAI_API_KEY ikke konfigureret']); exit; }

// ─── Input ───
$body            = json_decode(file_get_contents('php://input'), true) ?? [];
$firstName       = $body['firstName'] ?? '';
$nameData        = $body['nameData'] ?? '';
$relevantDisplays = array_values(array_filter($body['relevantDisplays'] ?? [], fn($v) => is_string($v) && $v !== ''));

if (!$firstName || !$nameData) {
    http_response_code(400); echo json_encode(['error' => 'Manglende data']); exit;
}

// ─── Hent gratis-konfiguration direkte fra DB ───
$cfg = [];
try {
    $db  = getDB();
    $res = $db->query('SELECT customPrompt FROM gratis_beregning WHERE id = 1');
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $cfg = ['customPrompt' => $row['customPrompt'] ?? ''];
    }
} catch (Throwable $e) { /* tabel eksisterer måske ikke endnu — fortsæt med fallback */ }

// ─── Rens energitekst (fjern planeter, horoskop etc. baseret på avoids-config) ───
function cleanEnergyText(string $text, array $avoids, array $customAvoids, string $tone = 'warm'): string {
    $t = $text;
    if (in_array('planeter', $avoids)) {
        $planets = 'Solen|Månen|Mars|Venus|Jupiter|Saturn|Merkur|Neptun|Uranus|Pluto';
        $t = preg_replace('/^\d+\s+(' . $planets . ')\s*$/mu', '', $t);
        $t = preg_replace('/\b(' . $planets . ')\b/iu', '', $t);
        $t = preg_replace('/vibrerer med\s*,?\s*/iu', '', $t);
        $t = preg_replace('/Tallet \d+\s*,?\s*som repræsenterer/iu', 'Tallet repræsenterer', $t);
    }
    if (in_array('horoskop', $avoids)) {
        $zodiac = 'Vædder\w*|Tyr\w*|Tvilling\w*|Krebs\w*|Løv\w*|Jomfru\w*|Vægt\w*|Skorpion\w*|Skytt\w*|Stenbuk\w*|Vandmand\w*|Fisk\w*';
        $t = preg_replace('/\b(' . $zodiac . ')\b/iu', '', $t);
        $t = preg_replace('/soltegn\w*/iu', '', $t);
        $t = preg_replace('/horoskop\w*/iu', '', $t);
        $t = preg_replace('/stjernetegn\w*/iu', '', $t);
        $t = preg_replace('/zodiak\w*/iu', '', $t);
    }
    if (in_array('teknisk', $avoids)) {
        $t = preg_replace('/^Lykketal:.*$/mu', '', $t);
        $t = preg_replace('/^Lykkedage:.*$/mu', '', $t);
        $t = preg_replace('/\d+\s*\+\s*\d+\s*=\s*\d+/u', '', $t);
    }
    foreach ($customAvoids as $phrase) {
        if ($phrase && trim($phrase) !== '') {
            $esc = preg_quote(trim($phrase), '/');
            $t = preg_replace('/' . $esc . '/iu', '', $t);
        }
    }
    // For professionel og direkte tone: rens åndelige/spirituelle ord ud af energiteksten
    if (in_array($tone, ['professional', 'direct'])) {
        $spiritual = 'åndelig\w*|spirituel\w*|sjæl\w*|kosmisk\w*|universet|intuition\w*|energistrøm\w*|nærvær\w*|det høje selv|indre lys|højere bevidsthed|hellig\w*|mystisk\w*|mystik\w*|poet\w*|guddommelig\w*';
        $t = preg_replace('/\b(' . $spiritual . ')\b/iu', '', $t);
    }
    $t = preg_replace('/\n{3,}/', "\n\n", $t);
    $t = preg_replace('/  +/', ' ', $t);
    return trim($t);
}

// ─── Hent energibeskrivelser fra DB (virker for alle besøgende) ───
$energyDescriptions = '';
if (!empty($relevantDisplays)) {
    try {
        $dbE  = getDB();
        $ph   = implode(',', array_fill(0, count($relevantDisplays), '?'));
        $types = str_repeat('s', count($relevantDisplays));
        $stmtE = $dbE->prepare("SELECT display, grundenergi, beskrivelse, keywords, keywords_urent_numeroskop, ubalanceret_keywords, helheds_funktion FROM diamant_energies WHERE display IN ($ph) ORDER BY id ASC");
        if ($stmtE) {
            $stmtE->bind_param($types, ...$relevantDisplays);
            $stmtE->execute();
            $resE = $stmtE->get_result();
            $eavoids = [];
            $ecustom = [];
            $etone   = 'warm';
            while ($erow = $resE->fetch_assoc()) {
                $isCompound = str_contains($erow['display'], '/');

                // Grundtal (1-9) bruger grundenergi; sammensatte tal bruger beskrivelse
                $mainText = $isCompound
                    ? ($erow['beskrivelse'] ?: $erow['grundenergi'])
                    : ($erow['grundenergi'] ?: $erow['beskrivelse']);

                // Keywords: positive først, ellers ubalancerede
                $kw = $erow['keywords'] ?: $erow['keywords_urent_numeroskop'];

                // Ubalanceret beskrivelse (opmærksomhedspunkt)
                $ubalance = $erow['ubalanceret_keywords'] ?? '';

                // Helhedsfunktion
                $helhed = $erow['helheds_funktion'] ?? '';

                $energyDescriptions .= "\n--- Energi {$erow['display']} ---\n";
                if ($mainText) $energyDescriptions .= 'Beskrivelse: ' . cleanEnergyText($mainText, $eavoids, $ecustom, $etone) . "\n";
                if ($kw)       $energyDescriptions .= 'Nøgleord: '    . cleanEnergyText($kw,       $eavoids, $ecustom, $etone) . "\n";
                if ($ubalance && $ubalance !== $mainText)
                               $energyDescriptions .= 'Udfordrende aspekter: ' . cleanEnergyText($ubalance, $eavoids, $ecustom, $etone) . "\n";
                if ($helhed)   $energyDescriptions .= 'Helhedsfunktion: '  . cleanEnergyText($helhed,   $eavoids, $ecustom, $etone) . "\n";
            }
            $stmtE->close();
        }
    } catch (Throwable $e) { /* energier utilgængelige — GPT bruger egne viden */ }
}

// ─── Byg systemprompt fra customPrompt ───
$lo = 8; $hi = 10;
$temperature = 0.75;

if (!empty($cfg['customPrompt'])) {
    $systemPrompt = $cfg['customPrompt'];
} else {
    // Fallback-prompt med fulde regler (bruges når DB-kolonnen mangler eller er tom)
    $systemPrompt  = "Du er en erfaren numerolog. Lav en kort, personlig analyse på dansk baseret på personens numerologiske diamant.\n\n";
    $systemPrompt .= "DIN OPGAVE er at oversætte et specifikt sæt af tal til en konkret beskrivelse af DENNE persons mønstre — ikke at skrive en generisk karakter-skitse der kunne passe på mange mennesker.\n\n";
    $systemPrompt .= "FORMAT: ét samlet afsnit på 8–10 sætninger. Ingen overskrifter, ingen bullets.\n\n";
    $systemPrompt .= "BRUG personens fornavn 1-2 gange naturligt i teksten.\n\n";
    $systemPrompt .= "GRUNDENERGIEN (top-tallet) er kernen — vægt den tungest. De øvrige energier modificerer og nuancerer — brug dem aktivt så to personer med samme grundenergi lyder FORSKELLIGT.\n\n";
    $systemPrompt .= "SKRIV ALDRIG:\n";
    $systemPrompt .= "· Tal eller positions-labels i teksten (ikke \"9\", ikke \"14/5\", ikke \"bundtal\", ikke \"hjertecenter\")\n";
    $systemPrompt .= "· Abstrakt eller åndeligt sprog: sjæl, åndelig, kosmisk, universet, intuition, mystik, heale, indre lys\n";
    $systemPrompt .= "· Generiske fraser der passer på alle: karisma, naturlig leder, stærk vilje, gøre en forskel, går foran med et godt eksempel, magnetisk, skaber harmoni, dybe relationer, kunstnerisk sans, indre ro, finde balance, livsrejse\n\n";
    $systemPrompt .= "SKRIV I STEDET konkret: Hvad gør denne specifikke talkombination ved den måde personen håndterer modgang? Hvad er et typisk mønster i konflikter? Hvad er en blind vinkel denne person typisk har? Vær specifik nok til at personen nikker genkendende — ikke bare \"ja, det passer på mig\", men \"how did you know that?\"\n\n";
    $systemPrompt .= "EKSEMPEL PÅ HVAD DU IKKE MÅ SKRIVE:\n";
    $systemPrompt .= "\"…en naturlig leder der motiverer sine omgivelser og søger harmoni i relationer …\"\n\n";
    $systemPrompt .= "EKSEMPEL PÅ HVAD DU SKAL SIGTE EFTER:\n";
    $systemPrompt .= "\"… når noget går galt, er [fornavn]s første bevægelse at handle — ikke at bearbejde. Det kan være en styrke i kaos, men efterlader ham/hende med et efterslæb af ting der aldrig rigtig er landet …\"\n\n";
    $systemPrompt .= "BALANCE: Vær ærlig. Ikke alt er en styrke. Udfordringer er reelle — præsenter dem som mønstre der kan genkendes og arbejdes med, ikke som dom.\n\n";
    $systemPrompt .= "SLUT med én sætning der antyder at den fulde diamant rummer mere.\n";
}

$systemPrompt .= "\nNUMEROLOGISK VIDEN:\n" . ($energyDescriptions ?: 'Ingen energibeskrivelser tilgængelige.');

$userPrompt  = "Personen hedder {$firstName}.\n\n";
$userPrompt .= "DIAMANTDATA (kun til din orientering — tallene og labels herunder må ALDRIG citeres eller nævnes i teksten):\n";
$userPrompt .= "{$nameData}\n\n";
$userPrompt .= "Skriv nu en kort, personlig numerologisk analyse ({$lo}–{$hi} sætninger i ét afsnit).\n";
$userPrompt .= "HUSK: Nævn IKKE et eneste tal (ikke '9', '19/1', '14/5', ingenting). Nævn IKKE positionsnavne (ikke 'bundtal', 'hjertecenter', 'solarplexus', ingenting). Oversæt ALT til konkrete menneskelige egenskaber og adfærd.";

// Brug tone-baseret temperature, fallback 0.7
$temperature = $temperature ?? 0.7;

$payload = json_encode([
    'model'       => 'gpt-4o',
    'messages'    => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user',   'content' => $userPrompt]
    ],
    'temperature' => $temperature,
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
