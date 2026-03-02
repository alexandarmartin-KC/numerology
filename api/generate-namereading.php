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
$temperature = 0.3;

if (!empty($cfg['customPrompt'])) {
    $systemPrompt = $cfg['customPrompt'];
} else {
    // Fallback-prompt
    $systemPrompt  = "Du er en erfaren numerolog med psykologisk modenhed.\n";
    $systemPrompt .= "Du skriver i et lavmælt, nøgternt og præcist sprog.\n\n";
    $systemPrompt .= "Din opgave er at omsætte personens numerologiske diamant til en personlig analyse på dansk.\n\n";
    $systemPrompt .= "Du må bruge tallene til forståelse, men du må aldrig nævne tal, brøker eller positionsnavne i teksten.\n\n";
    $systemPrompt .= "Du beskriver ikke egenskaber, styrker eller kompetencer.\n";
    $systemPrompt .= "Du beskriver en gennemgående indre mekanisme og den spænding, den skaber i personens liv.\n\n";
    $systemPrompt .= "STILANKER (efterlign tone og temperatur – kopier ikke formuleringer)\n\n";
    $systemPrompt .= "Alexx, du er drevet af en indre bevægelse, som gør det svært for dig at være ligeglad med det, du går ind i. Når noget betyder noget for dig, investerer du både energi og forventning, og det gælder i arbejde såvel som i venskaber. Du trives bedst, når der er retning og vilje omkring dig, og du kan mærke det med det samme, hvis ambitionen halter – også selv om du ikke altid siger det højt. Indeni har du en høj standard for dig selv, som sjældent bliver formuleret direkte, men som alligevel styrer mange af dine valg. Det er derfor, du reagerer med handling, når noget går skævt, mens du først senere mærker, hvad det faktisk gjorde ved dig. Du kan fremstå robust og fremadrettet, men du registrerer nøje, om du bliver taget alvorligt og mødt med samme engagement, som du selv lægger. Den spænding mellem ydre drivkraft og indre selvvurdering er en rød tråd i dit liv. Den giver dig fremdrift og loyalitet, men kan også gøre dig hårdere ved dig selv, end andre forstår. Når du bliver bevidst om den mekanisme, får du ikke mindre kraft – du får mere frihed i, hvordan du bruger den – og det er kun én del af det større mønster, din diamant tegner.\n\n";
    $systemPrompt .= "FORMAT\nÉt samlet afsnit. 8–9 sætninger. Brug fornavnet én gang naturligt (i starten). Ingen overskrifter. Ingen bullets. Ingen linjeskift. Ingen tal eller cifre.\n\n";
    $systemPrompt .= "INDHOLD\nTeksten skal:\n";
    $systemPrompt .= "- Beskrive én gennemgående indre bevægelse\n";
    $systemPrompt .= "- Vise hvordan den viser sig i arbejde\n";
    $systemPrompt .= "- Vise hvordan den viser sig i venskaber\n";
    $systemPrompt .= "- Beskrive en indre standard personen holder sig selv op imod\n";
    $systemPrompt .= "- Vise hvordan personen registrerer andres reaktioner\n";
    $systemPrompt .= "- Beskrive spændingen mellem ydre handling og indre selvvurdering\n";
    $systemPrompt .= "- Vise hvad mekanismen giver\n";
    $systemPrompt .= "- Vise hvad den koster\n";
    $systemPrompt .= "- Afslutte roligt med at dette kun er én del af det større mønster\n\n";
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
$systemPrompt .= "\nNUMEROLOGISK VIDEN (råmateriale — kun til forståelse. Kopiér aldrig formuleringer herfra):\n" . ($maskedEnergy ?: 'Ingen energibeskrivelser tilgængelige.');

$userPrompt  = "Personen hedder {$firstName}.\n\n";
$userPrompt .= "DIAMANTDATA (kun til orientering — må ikke citeres):\n";
$userPrompt .= "{$nameData}\n\n";
$userPrompt .= "Skriv nu teksten.";

function callOpenAI(string $systemPrompt, string $userPrompt, string $apiKey, float $temp): array {
    $payload = json_encode([
        'model'       => 'gpt-4o',
        'messages'    => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userPrompt]
        ],
        'temperature' => $temp,
        'max_tokens'  => 700
    ], JSON_UNESCAPED_UNICODE);

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

// ─── Første kald ───
$result = callOpenAI($systemPrompt, $userPrompt, $apiKey, $temperature);
if ($result['curlErr']) { http_response_code(500); echo json_encode(['error' => 'cURL fejl: ' . $result['curlErr']]); exit; }
if ($result['httpCode'] !== 200) { http_response_code(500); echo json_encode(['error' => 'OpenAI API fejl', 'details' => $result['response']]); exit; }

$data    = json_decode($result['response'], true);
$reading = $data['choices'][0]['message']['content'] ?? '';
$rewritten = false;

// ─── Trin 2: Stil-nedkøling (kører altid) ───
$rewritePrompt  = "Omskriv teksten nedenfor i mere jordnært dansk.\n";
$rewritePrompt .= "Fjern abstrakte begreber, statusord og personlig-udviklingssprog.\n";
$rewritePrompt .= "Gør sproget enklere og mere konkret.\n";
$rewritePrompt .= "Bevar strukturen og meningen.\n";
$rewritePrompt .= "Fjern ophøjet tone.\n";
$rewritePrompt .= "Fjern alle tal, cifre og brøker hvis de optræder.\n";
$rewritePrompt .= "Returner kun den omskrevne version — ingen forklaringer.\n\n";
$rewritePrompt .= "TEKST:\n{$reading}";

$r2 = callOpenAI("Du er en præcis dansk tekstredigerer. Du skriver nøgternt og konkret.", $rewritePrompt, $apiKey, 0.2);
if ($r2['httpCode'] === 200) {
    $d2 = json_decode($r2['response'], true);
    $reading = $d2['choices'][0]['message']['content'] ?? $reading;
    $rewritten = true;
    $data['usage']['rewrite'] = $d2['usage'] ?? null;
}

$debug = !empty($body['debug']);
$out = ['reading' => $reading, 'usage' => $data['usage'] ?? null];
if ($debug) {
    $out['debug'] = [
        'systemPrompt'             => $systemPrompt,
        'userPrompt'               => $userPrompt,
        'relevantDisplays'         => $relevantDisplays,
        'energyDescriptionsLength' => strlen($energyDescriptions),
        'rewritten'                => $rewritten,
    ];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
