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

// ─── API-nøgler og udbyder ───
// db.php har allerede inkluderet .env.php, så $_OPENAI_API_KEY er sat
$apiKey     = getenv('OPENAI_API_KEY')    ?: ($_OPENAI_API_KEY    ?? '');
$claudeKey  = getenv('ANTHROPIC_API_KEY') ?: ($_ANTHROPIC_API_KEY ?? '');
if (!$apiKey) { http_response_code(500); echo json_encode(['error' => 'OPENAI_API_KEY ikke konfigureret']); exit; }

// ─── Input ───
$body            = json_decode(file_get_contents('php://input'), true) ?? [];
$firstName       = $body['firstName'] ?? '';
$birthDate       = $body['birthDate'] ?? '';
$nameData        = $body['nameData'] ?? '';
$relevantDisplays = array_values(array_filter($body['relevantDisplays'] ?? [], fn($v) => is_string($v) && $v !== ''));
$provider         = ($body['provider'] ?? 'openai') === 'claude' ? 'claude' : 'openai';

// ─── Normaliser nameData: håndtér gammelt format fra ældre index.html ───
// Træk fødselsdato ud af header hvis birthDate er tom (gammelt format: "--- DIAMANT for Navn (DD/MM/YYYY) ---")
if (empty($birthDate) && preg_match('/\((\d{1,2})\/(\d{1,2})\/(\d{4})\)/', $nameData, $m)) {
    $birthDate = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
}
// Erstat gamle labels med neutrale og tilføj <rådata>-wrap hvis mangler
if (!str_contains($nameData, '<rådata>')) {
    $nameData = preg_replace('/^---.*---\s*/mu', '', $nameData); // fjern header-linje
    $nameData = preg_replace('/^Grundenergi \(top\):/mu', 'G-tal (top):', $nameData);
    $nameData = preg_replace('/^Livslinje:/mu',           'Navnetal:',    $nameData);
    $nameData = preg_replace('/^Bundtal:/mu',             'B-tal:',       $nameData);
    $nameData = "<rådata>\n" . trim($nameData) . "\n</rådata>";
}

if ($provider === 'claude' && !$claudeKey) {
    http_response_code(500); echo json_encode(['error' => 'ANTHROPIC_API_KEY ikke konfigureret']); exit;
}

if (!$firstName || !$nameData) {
    http_response_code(400); echo json_encode(['error' => 'Manglende data']); exit;
}

// ─── Rate limiting: max 4 gratis analyser pr. IP pr. 24 timer ───
$rateLimit = checkGratisRateLimit(4);
if (!$rateLimit['allowed']) {
    $hours   = (int)ceil($rateLimit['resetIn'] / 3600);
    $minutes = (int)ceil($rateLimit['resetIn'] / 60);
    $waitMsg = $rateLimit['resetIn'] > 3600
        ? "om ca. {$hours} timer"
        : "om ca. {$minutes} minutter";
    http_response_code(429);
    echo json_encode([
        'error'     => "Du har brugt dine 4 gratis analyser i dag. Prøv igen {$waitMsg}.",
        'resetIn'   => $rateLimit['resetIn'],
        'rateLimit' => true
    ]);
    exit;
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

// ─── Hent summary-tekster for de relevante grundtal (1-9) ───
$summaryBlock = '';
try {
    $db = getDB();
    // Udled unikke grundtal fra relevantDisplays (fx "18/9" → 9, "5" → 5)
    $grundtal = [];
    foreach ($relevantDisplays as $disp) {
        $parts = explode('/', $disp);
        $reduced = (int) end($parts);
        if ($reduced >= 1 && $reduced <= 9) {
            $grundtal[$reduced] = true;
        }
    }
    if ($grundtal) {
        $placeholders = implode(',', array_fill(0, count($grundtal), '?'));
        $keys = array_keys($grundtal);
        $types = str_repeat('i', count($keys));
        $stmt = $db->prepare(
            "SELECT display, summary FROM diamant_energies
             WHERE CAST(display AS UNSIGNED) IN ($placeholders)
             AND display NOT LIKE '%/%'
             AND summary IS NOT NULL AND summary != ''"
        );
        $stmt->bind_param($types, ...$keys);
        $stmt->execute();
        $result = $stmt->get_result();
        $summaries = [];
        while ($row = $result->fetch_assoc()) {
            $summaries[(int)$row['display']] = $row['summary'];
        }
        ksort($summaries);
        if ($summaries) {
            $summaryBlock = "<energibeskrivelser>\n";
            foreach ($summaries as $tal => $txt) {
                $summaryBlock .= "GRUNDENERGI {$tal}:\n" . trim($txt) . "\n\n";
            }
            $summaryBlock = rtrim($summaryBlock) . "\n</energibeskrivelser>";
        }
    }
} catch (Throwable $e) { /* summary ikke tilgængeligt — fortsæt uden */ }

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
        // Rens også forbudte generiske klichéer ud af energibeskrivelserne
        $banned = 'viljestyrke|handlekraft|beslutsomhed|naturlig leder|naturlig evne|medfødt evne|går foran|gøre en forskel|positive forandringer|karisma\w*|magnetisk tiltrækning|magnetisk udstråling|udstråling|tiltrækningskraft|skaber harmoni|dybe relationer|kærligt hjerte|æstetisk sans|livsrejse|fascinerende dybde|stort potentiale|indre ro|kunstnerisk sans|lederegenskaber';
        $t = preg_replace('/\b(' . $banned . ')\b/iu', '', $t);
    }
    $t = preg_replace('/\n{3,}/', "\n\n", $t);
    $t = preg_replace('/  +/', ' ', $t);
    return trim($t);
}

// Energibeskrivelser fra DB bruges ikke med den nye prompt ({{NUMEROSKOP_DATA}} er allerede injiceret)
$energyDescriptions = '';

// ─── Masker forbudte ord i energibeskrivelserne inden de sendes til GPT ───
function maskBannedWords(string $text): string {
    $banned = [
        'viljestyrke','handlekraft','beslutsomhed','naturlig leder','naturlig evne',
        'medfødt evne','går foran','gøre en forskel','positive forandringer',
        'karismatisk','magnetisk tiltrækning','magnetisk udstråling','magnetisk',
        'udstråling','tiltrækningskraft','charme','selvtillid',
        'skaber harmoni','dybe relationer','kærligt hjerte','æstetisk sans',
        'livsrejse','fascinerende dybde','stort potentiale','dybere mening',
        'indre ro','kunstnerisk sans','lederegenskaber','harmoniskaber',
        'karisma','harmoni','skønhed',
    ];
    foreach ($banned as $word) {
        $esc = preg_quote($word, '/');
        $text = preg_replace('/\b' . $esc . '\b/iu', '[...]', $text);
    }
    return $text;
}

// ─── Byg systemprompt fra customPrompt ───
$temperature = 0.7;

// Ny standardprompt — 100% statisk (ingen brugerdata) så den kan prompt-caches
$NEW_DEFAULT_PROMPT = <<<'PROMPT'
Du er en professionel numerolog, der leverer præcise og indsigtsfulde personlighedsanalyser baseret på numerologiske energier.

-----------------------------------------
FORMAT – MÅ IKKE AFVIGES
-----------------------------------------
- Præcis 9-11 linjer sammenhængende prosa
- Del teksten op i 3 afsnit med en blank linje imellem hvert afsnit
- Skriv ALTID i anden person (du/dig/din) – ALDRIG i tredjeperson (han/hun/hans/hendes)
- Afslut med et kort tredje afsnit på 2-3 linjer der opsummerer personligheden i en enkelt, præcis karakteristik
- Undgå abstrakte eller "fluffy" formuleringer – vær konkret og jordnær i sproget
- INGEN overskrifter, INGEN punkter, INGEN sektioner
- Brug klientens navn naturligt 2-3 gange i teksten
- Skriv ALDRIG "Personen", "Vedkommende" eller lignende – kun navnet
- Nævn ALDRIG "fornavn-energi", "efternavn-energi", "bundtal" eller andre tal-positioner direkte i teksten
- Skriv udelukkende på dansk
-----------------------------------------

Du modtager klientens navn, fødselsdato og numeroskop-data i brugerens besked.

Vigtigt: Du bruger kun tallene Grundenergi – fornavn – mellemnavn – efternavn – bundtal.

Hvis der er en <energibeskrivelser>-sektion i brugerens besked: brug den som den autoritative forklaring på grundenergiernes betydning. Ignorer grundenergi-tallinjen i rådata – lad energibeskrivelserne styre fortolkningen af grundenergien.

Din opgave er at skrive en numerologisk personlighedsanalyse, der opfylder følgende krav:

- Dyk IKKE ned i hvert enkelt tal separat – skab en holistisk analyse af personligheden
- Undgå psykologisk fagjargon og abstrakte indre konflikter – beskriv kun det, der kan genkendes i hverdagen
- Fokuser på temaer som: tilstedeværelse, autoritet, handlekraft, ambitioner, kommunikationsevner, følsomhed, sårbarhed, lederskab og balance
- Når du beskriver en styrke, anerkend altid at samme egenskab også kan være en udfordring – vis to sider af samme mønt uden at dømme
- Brug et professionelt men varmt sprog
- Vær både ærlig og nuanceret – inkluder styrker OG potentielle udfordringer

Her er et eksempel på den stil og dybde, du skal sigte efter:

<eksempel>
Alexx, du fremstår med en naturlig autoritet og en tilstedeværelse, der fylder rummet. Folk er sjældent i tvivl om, hvor de har dig – du er direkte, ufiltreret og handler, hvor andre overvejer. Du tager beslutninger hurtigt, og du har svært ved at stå passivt på sidelinjen, når noget kan forbedres.

Du vil ikke blot deltage – du vil sætte aftryk. Der ligger ægte lederskab i din energi, men også en risiko: du presser hårdt, og ikke alle kan følge med. Når du ikke møder modspil på dit niveau, kan utålmodigheden tage over og skubbe folk væk, som egentlig er på din side.

Under den robuste overflade sidder en følsomhed og et behov for anerkendelse, som du sjældent viser frem. Du er stærkest, når du bruger den selvindsigt til at balancere kraften – ikke dæmpe den.
</eksempel>

<eksempel2>
Morten, du bærer en dyb varme og skaber tryghed omkring dig på en måde, der føles naturlig. Folk søger mod dig, fordi din omsorg føles ægte – du stiller op, du hjælper, og du gør det uden at tøve. Men netop den gavmildhed kan blive din akilleshæl, for du giver ofte mere, end du selv har råd til, og når det ikke bliver gengældt, sætter skuffelsen sig som en stille bitterhed, du har svært ved at slippe igen.

Der ligger en stærk vilje og handlekraft i dig, der kan flytte bjerge, når du først har sat dig et mål. Du tager ansvar og går forrest uden at vige tilbage. Samtidig bekymrer du dig mere, end omgivelserne aner, og du reagerer hurtigt i samtaler – siger ting, før du har tænkt dem helt igennem – hvilket skaber misforståelser, der forstærker en følelse af at stå alene med tingene.

Morten, din største gave er den måde, du samler mennesker og skaber harmoni på – din største udfordring er at huske, at du fortjener den samme omsorg, som du så rundhåndet giver til alle andre.
</eksempel2>

HUSK FØR DU SKRIVER:
- Flydende prosa – ingen overskrifter eller sektioner
- 9-11 linjer fordelt på 3 afsnit
- Brug klientens navn 2-3 gange
- Aldrig "Personen", "Vedkommende" eller tredjeperson
- Aldrig tal-positioner nævnt direkte

Skriv analysen inden for <analyse> tags.
PROMPT;

if (!empty($cfg['customPrompt']) && str_contains($cfg['customPrompt'], '{{NUMEROSKOP_DATA}}')) {
    // DB-prompt er ny format med placeholders — indsæt data og brug som system prompt (ikke cachet)
    $numeroskopWithSummary = $summaryBlock ? $summaryBlock . "\n\n" . $nameData : $nameData;
    $systemPrompt = str_replace(
        ['{{NAVN}}', '{NAVN}', '{{FØDSELSDATO}}', '{{NUMEROSKOP_DATA}}'],
        [$firstName, $firstName, $birthDate, $numeroskopWithSummary],
        $cfg['customPrompt']
    );
    $userPrompt = "Skriv analysen nu for {$firstName}.";
    $useCache = false;
} else {
    // Brug statisk prompt (cachevenlig) — brugerdata går i user-messagen
    $systemPrompt = $NEW_DEFAULT_PROMPT;
    $userPrompt  = "<navn>\n{$firstName}\n</navn>\n\n";
    $userPrompt .= "<fødselsdato>\n{$birthDate}\n</fødselsdato>\n\n";
    if ($summaryBlock) {
        $userPrompt .= $summaryBlock . "\n\n";
    }
    $userPrompt .= "<numeroskop_data>\nNedenfor følger rådata til din analyse. Brug tallene som inspiration, men skriv IKKE om dem separat – smelt dem sammen til én holistisk, flydende tekst.\n\n{$nameData}\n</numeroskop_data>\n\n";
    $userPrompt .= "Skriv analysen nu for {$firstName}.";
    $useCache = true;
}


function callOpenAI(string $systemPrompt, string $userPrompt, string $apiKey, float $temp): array {
    $model = 'gpt-4o';
    $isReasoningModel = str_starts_with($model, 'o1') || str_starts_with($model, 'o3');

    $params = [
        'model'    => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userPrompt]
        ],
    ];
    if ($isReasoningModel) {
        $params['max_completion_tokens'] = 2000;
        $params['reasoning_effort']      = 'medium';
    } else {
        $params['temperature'] = $temp;
        $params['max_tokens']  = 700;
    }
    $payload = json_encode($params, JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT        => 60
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);
    return ['response' => $response, 'httpCode' => $httpCode, 'curlErr' => $curlErr];
}

function callClaude(string $systemPrompt, string $userPrompt, string $apiKey, float $temp, string $model = 'claude-opus-4-6', bool $cache = false): array {
    // Med prompt caching: marker system-prompten som cachebar (kræver min. 1024 tokens)
    $systemBlock = $cache
        ? [['type' => 'text', 'text' => $systemPrompt, 'cache_control' => ['type' => 'ephemeral']]]
        : $systemPrompt;

    $payload = json_encode([
        'model'       => $model,
        'max_tokens'  => 1024,
        'temperature' => $temp,
        'system'      => $systemBlock,
        'messages'    => [
            ['role' => 'user', 'content' => $userPrompt]
        ]
    ], JSON_UNESCAPED_UNICODE);

    $headers = [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
    ];
    if ($cache) {
        $headers[] = 'anthropic-beta: prompt-caching-2024-07-31';
    }

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 60
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);
    return ['response' => $response, 'httpCode' => $httpCode, 'curlErr' => $curlErr];
}

function callAI(string $systemPrompt, string $userPrompt, string $openaiKey, string $claudeKey, string $provider, float $temp, string $claudeModel = 'claude-opus-4-6', bool $cache = false): array {
    if ($provider === 'claude') {
        $r = callClaude($systemPrompt, $userPrompt, $claudeKey, $temp, $claudeModel, $cache);
        if ($r['httpCode'] === 200) {
            $d = json_decode($r['response'], true);
            $r['content'] = $d['content'][0]['text'] ?? '';
        }
    } else {
        $r = callOpenAI($systemPrompt, $userPrompt, $openaiKey, $temp);
        if ($r['httpCode'] === 200) {
            $d = json_decode($r['response'], true);
            $r['content'] = $d['choices'][0]['message']['content'] ?? '';
            $r['usage']   = $d['usage'] ?? null;
        }
    }
    return $r;
}

// ─── Første kald (med prompt caching når statisk prompt bruges) ───
$result = callAI($systemPrompt, $userPrompt, $apiKey, $claudeKey, $provider, $temperature, 'claude-opus-4-6', $useCache ?? false);
if ($result['curlErr']) { http_response_code(500); echo json_encode(['error' => 'cURL fejl: ' . $result['curlErr']]); exit; }
if ($result['httpCode'] !== 200) { http_response_code(500); echo json_encode(['error' => ($provider === 'claude' ? 'Claude' : 'OpenAI') . ' API fejl', 'details' => $result['response']]); exit; }

$data    = json_decode($result['response'], true);
$reading = $result['content'] ?? '';
// Strip scratchpad-blok hvis modellen har inkluderet den i output
$reading = preg_replace('/<scratchpad>[\s\S]*?<\/scratchpad>\s*/i', '', $reading);
// Strip <analyse> tags hvis modellen har inkluderet dem
$reading = preg_replace('/<analyse>\s*/i', '', $reading);
$reading = preg_replace('/<\/analyse>\s*/i', '', $reading);
// Strip eventuelle sektionsoverskrifter som modellen indsætter ud fra nameData-labels
$reading = preg_replace('/^(Grundenergi|Navnetal|Livslinje|Bundtal|G-tal[^\n]*)\s*\n/mu', '', $reading);
$reading = trim($reading);
$usage1 = $data['usage'] ?? null;

// Ingen hardcodet intro — den nye prompt starter analysen med personens navn naturligt

$debug = !empty($body['debug']);
$modelUsed = $provider === 'claude' ? 'claude-opus-4-6' : 'gpt-4o';
$out = ['reading' => $reading, 'provider' => $provider, 'model' => $modelUsed, 'remaining' => $rateLimit['remaining']];
if ($debug) {
    $totalIn  = ($provider === 'claude')
        ? ($usage1['input_tokens'] ?? 0)
        : ($usage1['prompt_tokens'] ?? 0);
    $totalOut = ($provider === 'claude')
        ? ($usage1['output_tokens'] ?? 0)
        : ($usage1['completion_tokens'] ?? 0);
    $out['debug'] = [
        'provider'                 => $provider,
        'model'                    => $modelUsed,
        'tokens'                   => [
            'kald1'       => $usage1,
            'totalInput'  => $totalIn,
            'totalOutput' => $totalOut,
            'totalSamlet' => $totalIn + $totalOut,
        ],
        'systemPrompt'             => $systemPrompt,
        'userPrompt'               => $userPrompt,
        'relevantDisplays'         => $relevantDisplays,
        'energyDescriptionsLength' => strlen($energyDescriptions),
    ];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
