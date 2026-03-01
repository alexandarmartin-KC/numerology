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
$body               = json_decode(file_get_contents('php://input'), true) ?? [];
$firstName          = $body['firstName'] ?? '';
$nameData           = $body['nameData'] ?? '';
$energyDescriptions = $body['energyDescriptions'] ?? '';

if (!$firstName || !$nameData) {
    http_response_code(400); echo json_encode(['error' => 'Manglende data']); exit;
}

// ─── Hent gratis-konfiguration direkte fra DB ───
$cfg = [];
try {
    $db  = getDB();
    $res = $db->query('SELECT * FROM gratis_beregning WHERE id = 1');
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $cfg = [
            'positions'        => json_decode($row['positions']    ?? '[]', true) ?: [],
            'tone'             => $row['tone']             ?? 'warm',
            'length'           => (int)($row['length']     ?? 8),
            'focus'            => json_decode($row['focus']        ?? '[]', true) ?: [],
            'avoids'           => json_decode($row['avoids']       ?? '[]', true) ?: [],
            'customAvoids'     => json_decode($row['customAvoids'] ?? '[]', true) ?: [],
            'extraInstruction' => $row['extraInstruction'] ?? '',
        ];
    }
} catch (Throwable $e) { /* tabel eksisterer måske ikke endnu — fortsæt med fallback */ }

// ─── Label-tabeller ───
$TONE_LABELS = [
    'warm'         => 'Varm, indsigtsfuld og lidt poetisk — som om du kender personen personligt',
    'mystical'     => 'Mystisk og poetisk — brug billeder, symbolik og dybde',
    'direct'       => 'Direkte og konkret — hold det kortfattet og faktuelt, ingen blomstrende sprog',
    'motivational' => 'Motiverende og opløftende — fokuser på muligheder og styrker, inspirer til handling',
    'professional' => 'Professionel og saglig — neutral eksperttone, ingen mystik, ingen åndelighed, ingen poetiske vendinger',
];

// Tone-specifikke ekstra regler
$TONE_RULES = [
    'warm'         => [],
    'mystical'     => ['Brug gerne poetiske billeder og symbolik.'],
    'direct'       => ['Ingen blomstrende eller poetiske vendinger.', 'Ingen mystiske eller åndelige referencer overhovedet.'],
    'motivational' => ['Fokuser på handlingsorienteret sprog.', 'Undgå det negative — vend det til muligheder.'],
    'professional' => [
        'Ingen mystiske, åndelige eller poetiske vendinger overhovedet.',
        'Ingen referencer til intuition, sjæl, ånd, universet, energistrømme eller lignende.',
        'Skriv som en faglig karriere- eller personlighedsrådgiver — ikke som en spirituel guide.',
        'Hold sproget neutralt og jordnært.',
    ],
];
$FOCUS_LABELS = [
    'personlighed'  => 'Personlighed & kerneenergi',
    'styrker'       => 'Styrker & talenter',
    'udfordringer'  => 'Udfordringer & skyggesider',
    'relationer'    => 'Relationer & kærlighed',
    'karriere'      => 'Karriere & livsretning',
    'spiritualitet' => 'Spiritualitet & indre vækst',
    'samspil'       => 'Samspil mellem tallene',
];
$AVOID_LABELS = [
    'planeter'       => 'Planeter (Sol, Saturn osv.)',
    'horoskop'       => 'Horoskop / stjernetegn',
    'teknisk'        => 'Tekniske beregningsforklaringer',
    'deterministisk' => 'Deterministiske udsagn',
    'negativt'       => 'Stærkt negativt sprog',
];
$POSITION_LABELS = [
    'grundenergi'  => 'Grundenergi (top)',
    'livslinje'    => 'Livslinje',
    'bundtal'      => 'Bundtal',
    'aura'         => 'Aura (4 hjørner)',
    'hjertecenter' => 'Hjertecenter',
    'solarplexus'  => 'Solarplexus',
    'rygraden'     => 'Rygraden',
    'soejletal'    => 'Søjletal',
];

// ─── Byg systemprompt fra DB-konfiguration ───
if (!empty($cfg)) {
    $lo          = $cfg['length'];
    $hi          = min($lo + 2, 16);
    $toneKey     = $cfg['tone'] ?? 'warm';
    $tone        = $TONE_LABELS[$toneKey] ?? $TONE_LABELS['warm'];
    $toneRules   = $TONE_RULES[$toneKey] ?? [];
    $posLabels   = array_values(array_filter(array_map(fn($id) => $POSITION_LABELS[$id] ?? null, $cfg['positions'])));
    $focusLabels = array_values(array_filter(array_map(fn($id) => $FOCUS_LABELS[$id]    ?? null, $cfg['focus'])));
    $avoidLabels = array_values(array_filter(array_map(fn($id) => $AVOID_LABELS[$id]    ?? null, $cfg['avoids'])));
    $allAvoids   = array_merge($avoidLabels, $cfg['customAvoids'] ?? []);

    // Temperature afhænger af tone: strenge toner = lavere kreativitet
    $temperature = match($toneKey) {
        'professional' => 0.4,
        'direct'       => 0.5,
        'motivational' => 0.6,
        'warm'         => 0.75,
        'mystical'     => 0.85,
        default        => 0.7,
    };

    $systemPrompt  = "Du er en erfaren numerolog. Du laver en kort og personlig numerologisk analyse på dansk.\n\n";
    if ($posLabels) {
        $systemPrompt .= "DIAMANTPOSITIONER DU MODTAGER:\n";
        foreach ($posLabels as $l) $systemPrompt .= "- $l\n";
        $systemPrompt .= "\n";
    }
    $systemPrompt .= "TONE: {$tone}\n\n";
    $systemPrompt .= "LAENGDE: Skriv præcis {$lo}–{$hi} sætninger i ét samlet afsnit.\n\n";
    if ($focusLabels) {
        $systemPrompt .= "FOKUS — skriv KUN om disse emner, intet andet:\n";
        foreach ($focusLabels as $l) $systemPrompt .= "- $l\n";
        $systemPrompt .= "\n";
    }
    $systemPrompt .= "REGLER:\n";
    $systemPrompt .= "- Brug personens fornavn naturligt 1-2 gange.\n";
    $systemPrompt .= "- Grundenergien (top) er kerneenergien — vægt den tungest.\n";
    $systemPrompt .= "- Skriv IKKE overskrifter, bullets eller formatering. Kun løbende tekst.\n";
    $systemPrompt .= "- Skriv IKKE 'dit tal er...' eller tekniske forklaringer. Gå direkte til personlighed.\n";
    $systemPrompt .= "- NÆVN ALDRIG specifikke tal direkte (fx IKKE: '9', '9'er energi', '5'eren'). Oversæt tallene til menneskelige egenskaber.\n";
    $systemPrompt .= "- Hold det positivt men ærligt — nævn gerne en mild udfordring.\n";
    $systemPrompt .= "- Slut med en sætning der antyder at den fulde diamant rummer mere at udforske.\n";
    foreach ($toneRules as $rule) {
        $systemPrompt .= "- {$rule}\n";
    }
    if ($allAvoids) {
        $systemPrompt .= "\nUNDGAA (nævn ALDRIG):\n";
        foreach ($allAvoids as $a) $systemPrompt .= "- $a\n";
    }
    if (!empty($cfg['extraInstruction'])) {
        $systemPrompt .= "\nEKSTRA INSTRUKTION:\n{$cfg['extraInstruction']}\n";
    }
} else {
    // Fallback hvis ingen DB-konfiguration endnu
    $lo = 8; $hi = 10;
    $systemPrompt  = "Du er en erfaren numerolog. Du laver en kort og personlig numerologisk analyse på dansk baseret på en persons numerologiske diamant.\n\n";
    $systemPrompt .= "REGLER:\n";
    $systemPrompt .= "- Skriv præcis 8-10 korte, personlige sætninger i ét samlet afsnit.\n";
    $systemPrompt .= "- Brug personens fornavn naturligt 1-2 gange.\n";
    $systemPrompt .= "- Grundenergien (top) er personens kerneenergi — vægt den tungest.\n";
    $systemPrompt .= "- Tonen skal være varm, indsigtsfuld og lidt mystisk.\n";
    $systemPrompt .= "- Skriv IKKE overskrifter, bullets eller formatering. Kun løbende tekst.\n";
    $systemPrompt .= "- NÆVN ALDRIG specifikke tal eller talenergier direkte (fx IKKE: '9', '9'er energi', 'tal 5', '5'eren', 'med din 3'er'). Tallene er baggrundsviden for dig — oversæt dem til menneskelige egenskaber og personlighed.\n";
    $systemPrompt .= "- Nævn ALDRIG planeter. Hold fokus på energi og personlighed.\n";
    $systemPrompt .= "- Slut med en sætning der antyder at den fulde diamant rummer mere at udforske.\n";
}

$systemPrompt .= "\nNUMEROLOGISK VIDEN:\n" . ($energyDescriptions ?: 'Ingen energibeskrivelser tilgængelige.');
$userPrompt    = "Personen hedder {$firstName}.\n\n{$nameData}\n\nSkriv en kort, personlig numerologisk analyse ({$lo}–{$hi} sætninger i ét afsnit).";

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
