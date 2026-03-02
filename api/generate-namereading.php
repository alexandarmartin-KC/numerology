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
    $systemPrompt  = "Du er en erfaren numerolog, der skriver som en adfærdsanalytiker. Du har adgang til en intern database med tal-betydninger (råmaterialet nedenfor). Du må bruge det til forståelse, men du må ikke efterligne eller kopiere ordvalg, fraser eller tone fra råmaterialet. Du skal oversætte til konkrete, observerbare mønstre i adfærd og valg.\n\n";
    $systemPrompt .= "DIN OPGAVE\nOversæt denne persons diamantkombination til en kort, personlig tekst, som føles specifik og genkendelig for netop denne person. Undgå alt generisk horoskop-sprog.\n\n";
    $systemPrompt .= "FORMAT\nÉt samlet afsnit på 8–10 sætninger. Ingen overskrifter, ingen bullets, ingen linjeskift. Skriv direkte til personen (brug fornavnet 1–2 gange naturligt).\n\n";
    $systemPrompt .= "HÅRDE KRAV (skal være med, ellers er svaret forkert)\n";
    $systemPrompt .= "1. Beskriv personens standardreaktion under pres som konkret handling (hvad gør de først).\n";
    $systemPrompt .= "2. Beskriv én konfliktmekanik: hvad gør de typisk i en uenighed, og hvad er deres typiske linje (en kort realistisk sætning de kunne sige).\n";
    $systemPrompt .= "3. Beskriv én blind vinkel (noget de konsekvent overser), og hvordan den viser sig i praksis.\n";
    $systemPrompt .= "4. Beskriv én pris: hvad det mønster koster dem (socialt, i parforhold, på job eller mentalt).\n";
    $systemPrompt .= "5. Indsæt én konkret hverdagsscene med detaljer (tidspunkt/situation/valg) — fx en travl arbejdsdag, en beskedtråd, et familiemøde, økonomi, planlægning, deadlines.\n";
    $systemPrompt .= "6. Hele teksten skal hænge sammen om én gennemgående mekanisme (fx kontrol, tempo, undgåelse, rastløshed, stolthed, behov for at afslutte, osv.). Ingen buffet af modsatrettede typer.\n\n";
    $systemPrompt .= "SÅDAN SKRIVER DU\nSkriv om adfærd i små beslutninger: afbrydelser, beskeder, deadlines, reaktionstid, tone, timing, undvigemanøvrer, overkompensering. Brug konkrete verber og konkrete situationer. Undgå abstrakte værdier og pyntesprog. Hvis en sætning kunne passe på 500+ personer, omskriv den til noget mere specifikt.\n\n";
    $systemPrompt .= "ABSOLUT FORBUD — disse ord og vendinger må IKKE forekomme (heller ikke i bøjet form):\n";
    $systemPrompt .= "karisma, magnetisk, udstråling, tiltrække, charme, selvtillid\n";
    $systemPrompt .= "stærk vilje, viljestyrke, handlekraft, beslutsomhed, naturlig leder, naturlig evne, medfødt evne, går foran, gøre en forskel, positive forandringer\n";
    $systemPrompt .= "harmoni, skaber harmoni, dybe relationer, kærligt hjerte, æstetisk sans, skønhed\n";
    $systemPrompt .= "sjæl, åndelig, kosmisk, universet, intuition, intuitiv, mystik, mystisk, heale, healing, indre lys, energistrøm\n";
    $systemPrompt .= "livsrejse, skæbne, forudbestemt, dybere mening, fascinerende dybde, stort potentiale\n";
    $systemPrompt .= "indre ro, finde balance, finde ro, kunstnerisk sans, indre konflikt\n";
    $systemPrompt .= "Tal, brøker eller positions-labels (ikke cifre overhovedet, ikke 'top', 'bund', 'center' osv.)\n\n";
    $systemPrompt .= "STOP-REGEL (meget vigtig)\nInden du afleverer teksten: scan din egen tekst. Hvis den indeholder ét eneste ord fra forbudslisten, eller et tal/ciffer, skal du omskrive og fjerne det — ikke erstatte med et synonym der stadig lyder som numerologi-floskel.\n\n";
    $systemPrompt .= "AFSLUTNING\nSlut med én enkelt sætning der antyder at hele diamanten rummer flere konkrete mønstre, uden at bruge ordene 'nuancer', 'mange lag' eller 'kompleks'.\n";
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

// ─── Automatisk rewrite hvis forbudte ord opdages ───
if (containsBannedContent($reading)) {
    $rewritePrompt  = "Teksten nedenfor indeholder forbudte ord eller tal. Omskriv den så alle forbudte vendinger er væk.\n\n";
    $rewritePrompt .= "FORBUDTE: karisma, magnetisk, udstråling, tiltrække, charme, selvtillid, harmoni, stærk vilje, viljestyrke, handlekraft, naturlig leder, naturlig evne, sjæl, åndelig, kosmisk, universet, intuition, mystik, heale, healing, indre lys, livsrejse, skæbne, dybere mening, indre ro, finde balance, tal/cifre, brøker.\n\n";
    $rewritePrompt .= "Bevar alle konkrete adfærdsbeskrivelser. Bevar hverdagsscenen. Ændre KUN de forbudte formuleringer.\n\n";
    $rewritePrompt .= "TEKST:\n{$reading}";

    $r2 = callOpenAI("Du er en præcis tekstredigerer.", $rewritePrompt, $apiKey, 0.2);
    if ($r2['httpCode'] === 200) {
        $d2 = json_decode($r2['response'], true);
        $reading = $d2['choices'][0]['message']['content'] ?? $reading;
        $rewritten = true;
        $data['usage']['rewrite'] = $d2['usage'] ?? null;
    }
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
