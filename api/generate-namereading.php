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

if ($provider === 'claude' && !$claudeKey) {
    http_response_code(500); echo json_encode(['error' => 'ANTHROPIC_API_KEY ikke konfigureret']); exit;
}

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
        // Rens også forbudte generiske klichéer ud af energibeskrivelserne
        $banned = 'viljestyrke|handlekraft|beslutsomhed|naturlig leder|naturlig evne|medfødt evne|går foran|gøre en forskel|positive forandringer|karisma\w*|magnetisk tiltrækning|magnetisk udstråling|udstråling|tiltrækningskraft|skaber harmoni|dybe relationer|kærligt hjerte|æstetisk sans|livsrejse|fascinerende dybde|stort potentiale|indre ro|kunstnerisk sans|lederegenskaber';
        $t = preg_replace('/\b(' . $banned . ')\b/iu', '', $t);
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
            $etone   = 'direct';
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

// ─── Validator: tjek om output indeholder forbudte ord eller tal ───
function containsBannedContent(string $text): bool {
    $banned = [
        'viljestyrk','handlekraft','beslutsomhed','naturlig leder','naturlig evne',
        'medfødt evne','går foran','gøre en forskel','karisma','magnetisk',
        'udstråling','tiltrækk','charme','selvtillid','skaber harmoni',
        'dybe relation','kærligt hjerte','æstetisk sans','livsrejse','skæbne',
        'forudbestemt','dybere mening','fascinerende dybde','stort potentiale',
        'indre ro','finde balance','finde ro','kunstnerisk','åndelig','spirituel',
        'kosmisk','universet','intuition','mystik','healing','heale','indre lys',
        'energistrøm','harmoni','stærk vilje',
    ];
    foreach ($banned as $word) {
        if (mb_stripos($text, $word) !== false) return true;
    }
    // Tjek for tal/cifre i teksten (undtagen årstal der evt. er en del af sætninger)
    if (preg_match('/\b\d+\/\d+\b/', $text)) return true;
    return false;
}

// ─── Byg systemprompt fra customPrompt ───
$lo = 8; $hi = 10;
$temperature = 0.7;

if (!empty($cfg['customPrompt'])) {
    $rawPrompt = $cfg['customPrompt'];
    // Erstat alle kendte placeholders (både {NAVN} og {{NAVN}}-format)
    $hasDataPlaceholder = str_contains($rawPrompt, '{{NUMEROSKOP_DATA}}');
    $systemPrompt = str_replace(
        ['{{NAVN}}', '{NAVN}', '{{FØDSELSDATO}}', '{{NUMEROSKOP_DATA}}'],
        [$firstName, $firstName, $birthDate, $nameData],
        $rawPrompt
    );
    // Debug: advar hvis der stadig er uerstattede placeholders
    if (str_contains($systemPrompt, '{{')) {
        error_log('ADVARSEL: Uerstattede placeholders i systemPrompt: ' . substr($systemPrompt, 0, 500));
    }
} else {
    // Fallback-prompt
    $systemPrompt  = "Du er en erfaren numerolog med psykologisk modenhed.\n";
    $systemPrompt .= "Du skriver i et lavmælt, nøgternt og præcist sprog.\n\n";
    $systemPrompt .= "Din opgave er at omsætte personens numerologiske diamant til en personlig analyse på dansk.\n\n";
    $systemPrompt .= "Du må bruge tallene til forståelse, men du må aldrig nævne tal, brøker eller positionsnavne i teksten.\n\n";
    $systemPrompt .= "Du beskriver ikke egenskaber, styrker eller kompetencer.\n";
    $systemPrompt .= "Du beskriver en gennemgående indre mekanisme og den spænding, den skaber i personens liv.\n\n";
    $systemPrompt .= "STILANKER (efterlign tone og temperatur – kopier ikke formuleringer)\n\n";
    $systemPrompt .= "Alexx,\n\nGrundenergi\n\nDu reagerer hurtigt, når noget mangler retning, og i møder er det ofte dig, der tager ordet først. Det giver fremdrift og klarhed, men kan også få andre til at føle sig overhalet. Du presser tempoet, når du mærker tøven, og det skaber resultater – samtidig risikerer du at lukke for input, der kunne have styrket helheden.\n\nLivslinje\n\nI relationer og arbejde ender du ofte som den drivende kraft, også når det ikke formelt er dit ansvar. Du tager over, hvis du fornemmer usikkerhed, og strammer grebet, når tingene skrider. Det giver stabilitet og gennemslagskraft, men kan skabe modstand hos dem, der ønsker mere plads. Din indflydelse vokser, når du deler kontrollen; den svækkes, når du fastholder den.\n\nBundtal\n\nUnder pres stiger dit tempo mærkbart. Tankerne accelererer, kroppen spænder, og stilstand føles som tab af kontrol. Du kan blive mere kontant i tonen eller kaste dig over nye opgaver for at undgå følelsen af at miste grebet. Den bevægelse beskytter din selvstændighed – men kan også holde uroen i gang.\n\n";
    $systemPrompt .= "FORMAT\n";
    $systemPrompt .= "Brug fornavnet alene på første linje efterfulgt af komma.\n";
    $systemPrompt .= "Derefter tre sektioner med overskrifterne: Grundenergi, Livslinje, Bundtal.\n";
    $systemPrompt .= "Hver overskrift står alene på sin linje. Hvert afsnit er 3–4 sætninger. Ingen bullets. Ingen tal eller cifre.\n\n";
    $systemPrompt .= "INDHOLD\nHver sektion skal:\n";
    $systemPrompt .= "- Beskrive én konkret mekanisme eller adfærdsmønster\n";
    $systemPrompt .= "- Vise hvad den giver\n";
    $systemPrompt .= "- Vise hvad den koster\n";
    $systemPrompt .= "Bundtal skal beskrive reaktion under pres: krop, tanker, tempo.\n\n";
    $systemPrompt .= "SPROGLIGE KRAV\nBrug verber og konkrete formuleringer frem for abstrakte begreber.\n";
    $systemPrompt .= "Undgå at skrive:\n";
    $systemPrompt .= "\"du har\", \"du er\", \"du søger\", \"du værdsætter\", \"du er kendt for\"\n";
    $systemPrompt .= "\"evne til\", \"initiativ\", \"leder\", \"ambitiøs\", \"målrettet\", \"succes\"\n";
    $systemPrompt .= "\"styrke\", \"respekt\", \"beundring\", \"inspirere\", \"motivere\"\n";
    $systemPrompt .= "\"balance\", \"udvikling\", \"personlighed\", \"indre kerne\"\n";
    $systemPrompt .= "spirituelle ord som \"sjæl\", \"universet\", \"intuition\"\n";
    $systemPrompt .= "Undgå højtidelige eller ophøjede substantiver som: \"længsel\", \"forandring\", \"indsigt\", \"formål\", \"retfærdighed\", \"kompleksitet\".\n";
    $systemPrompt .= "Sproget skal være jordnært og konstaterende – ikke rosende og ikke dramatisk.\n";
    $systemPrompt .= "Hvis teksten lyder som en jobprofil eller personlig udviklingsartikel, skal den omskrives mere nøgternt.\n\n";
    $systemPrompt .= "SELVTJEK FØR DU AFSLUTTER\n";
    $systemPrompt .= "Fjern alle kompetence- eller statusord.\n";
    $systemPrompt .= "Fjern abstrakte begreber og erstat dem med mere konkrete formuleringer.\n";
    $systemPrompt .= "Sikr at tonen er rolig, ikke ophøjende.\n";
    $systemPrompt .= "Sikr at teksten kunne læses højt uden at virke højtidelig.\n";
}

$maskedEnergy = maskBannedWords($energyDescriptions);
$systemPrompt .= "\nNedenstående er udelukkende rådata til din fortolkning. Lad dig IKKE påvirke af labelnavne eller struktur i dataet.\n";
$systemPrompt .= "\nNUMEROLOGISK VIDEN (råmateriale — kun til forståelse. Kopiér aldrig formuleringer herfra):\n" . ($maskedEnergy ?: 'Ingen energibeskrivelser tilgængelige.');

// Hvis prompten bruger {{NUMEROSKOP_DATA}}-placeholder er data allerede injiceret i systemprompt
if (!empty($hasDataPlaceholder)) {
    $userPrompt = "Skriv analysen nu for {$firstName}.";
} else {
    $userPrompt  = "Personen hedder {$firstName}.\n\n";
    $userPrompt .= "DIAMANTDATA (kun til orientering — må ikke citeres):\n";
    $userPrompt .= "{$nameData}\n\n";
    $userPrompt .= "Skriv nu analysen for {$firstName}.";
}

// ─── DEBUG: Gem prompt til fil (fjern i produktion) ───
$debugLog  = "=== " . date('Y-m-d H:i:s') . " ===\n";
$debugLog .= "--- SYSTEM PROMPT ---\n" . $systemPrompt . "\n\n";
$debugLog .= "--- USER PROMPT ---\n" . $userPrompt . "\n\n";
file_put_contents(__DIR__ . '/debug-prompt.log', $debugLog, FILE_APPEND);
// ─────────────────────────────────────────────────────

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

function callClaude(string $systemPrompt, string $userPrompt, string $apiKey, float $temp): array {
    $payload = json_encode([
        'model'       => 'claude-opus-4-6',
        'max_tokens'  => 1024,
        'temperature' => $temp,
        'system'      => $systemPrompt,
        'messages'    => [
            ['role' => 'user', 'content' => $userPrompt]
        ]
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01'
        ],
        CURLOPT_TIMEOUT        => 60
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);
    return ['response' => $response, 'httpCode' => $httpCode, 'curlErr' => $curlErr];
}

function callAI(string $systemPrompt, string $userPrompt, string $openaiKey, string $claudeKey, string $provider, float $temp): array {
    if ($provider === 'claude') {
        $r = callClaude($systemPrompt, $userPrompt, $claudeKey, $temp);
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

// ─── Første kald ───
$result = callAI($systemPrompt, $userPrompt, $apiKey, $claudeKey, $provider, $temperature);
if ($result['curlErr']) { http_response_code(500); echo json_encode(['error' => 'cURL fejl: ' . $result['curlErr']]); exit; }
if ($result['httpCode'] !== 200) { http_response_code(500); echo json_encode(['error' => ($provider === 'claude' ? 'Claude' : 'OpenAI') . ' API fejl', 'details' => $result['response']]); exit; }

$data    = json_decode($result['response'], true);
$reading = $result['content'] ?? '';
// Strip scratchpad-blok hvis modellen har inkluderet den i output
$reading = preg_replace('/<scratchpad>[\s\S]*?<\/scratchpad>\s*/i', '', $reading);
$reading = trim($reading);
$rewritten = false;
$usage1 = $provider === 'claude'
    ? ($data['usage'] ?? null)   // input_tokens, output_tokens
    : ($data['usage'] ?? null);  // prompt_tokens, completion_tokens, total_tokens

// ─── Trin 2: Stil-nedkøling (kører altid) ───
$rewritePrompt  = "Omskriv teksten nedenfor strengt efter disse regler.\n\n";
$rewritePrompt .= "FORBUDTE ORD OG FRASER – disse må IKKE optræde i output. Erstat dem med konkrete adfærdsbeskrivelser:\n";
$rewritePrompt .= "stærk, stærk vilje, trang til, tendens til, ligefremhed, initiativ, initiativer, tager styringen, tager initiativ, sætter pris på, ";
$rewritePrompt .= "karisma, karismatisk, magnetisk, balance, udvikling, personlighed, indre kerne, sjæl, universet, intuition, ";
$rewritePrompt .= "evne til, evner, styrke, respekt, beundring, inspirere, motivere, leder, lederskab, ambitiøs, målrettet, succes, ";
$rewritePrompt .= "vision, passion, passioneret, potentiale, formål, retfærdighed, kompleksitet, indsigt, forandring, ";
$rewritePrompt .= "længsel, fremdrift, integritet, autenticitet, medfølelse, empati, selvtillid, selvværd, mod, drivkraft, opmærksom.\n\n";
$rewritePrompt .= "FORBUDTE SÆTNINGSSTRUKTURER:\n";
$rewritePrompt .= "- 'du har [egenskab]' → beskriv i stedet hvad personen konkret gør\n";
$rewritePrompt .= "- 'din [egenskab] gør at' → omskriv til konkret handling\n";
$rewritePrompt .= "- 'du kan opleve' → erstat med konstaterende nutid\n";
$rewritePrompt .= "- 'du er god til', 'du er opmærksom på', 'du er bevidst om'\n\n";
$rewritePrompt .= "REGLER:\n";
$rewritePrompt .= "- Bevar overskrifterne Grundenergi, Livslinje, Bundtal præcis som de er.\n";
$rewritePrompt .= "- Bevar strukturen i hvert afsnit.\n";
$rewritePrompt .= "- Fjern alle tal, cifre og brøker.\n";
$rewritePrompt .= "- Sproget skal være jordnært og konstaterende – ikke rosende, ikke coaching.\n";
$rewritePrompt .= "- Returner kun den omskrevne tekst — ingen forklaringer.\n\n";
$rewritePrompt .= "TEKST:\n{$reading}";

$r2 = callAI("Du er en præcis dansk tekstredigerer. Du omskriver på dansk og returnerer kun den færdige tekst.", $rewritePrompt, $apiKey, $claudeKey, $provider, 0.2);
$usage2 = null;
if ($r2['httpCode'] === 200) {
    $d2 = json_decode($r2['response'], true);
    $reading = $r2['content'] ?? $reading;
    $rewritten = true;
    $usage2 = $d2['usage'] ?? null;
}

// ─── Tilføj personlig intro-linje øverst ───
// Strip modellens navn-linje øverst (fx "Alexx,") da intro allerede indeholder navnet
$reading = preg_replace('/^\s*\S+,\s*\n+/u', '', $reading);
$intro = "Kære {$firstName}, her har du en kort numerologisk analyse af dit navn.\n\n";
$reading = $intro . $reading;

$debug = !empty($body['debug']);
$modelUsed = $provider === 'claude' ? 'claude-3-haiku-20240307' : 'gpt-4o';
$out = ['reading' => $reading, 'provider' => $provider, 'model' => $modelUsed];
if ($debug) {
    $totalIn  = ($provider === 'claude')
        ? (($usage1['input_tokens'] ?? 0)  + ($usage2['input_tokens'] ?? 0))
        : (($usage1['prompt_tokens'] ?? 0) + ($usage2['prompt_tokens'] ?? 0));
    $totalOut = ($provider === 'claude')
        ? (($usage1['output_tokens'] ?? 0)  + ($usage2['output_tokens'] ?? 0))
        : (($usage1['completion_tokens'] ?? 0) + ($usage2['completion_tokens'] ?? 0));
    $out['debug'] = [
        'provider'                 => $provider,
        'model'                    => $modelUsed,
        'tokens'                   => [
            'kald1'  => $usage1,
            'kald2'  => $usage2,
            'totalInput'  => $totalIn,
            'totalOutput' => $totalOut,
            'totalSamlet' => $totalIn + $totalOut,
        ],
        'systemPrompt'             => $systemPrompt,
        'userPrompt'               => $userPrompt,
        'relevantDisplays'         => $relevantDisplays,
        'energyDescriptionsLength' => strlen($energyDescriptions),
        'rewritten'                => $rewritten,
    ];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
