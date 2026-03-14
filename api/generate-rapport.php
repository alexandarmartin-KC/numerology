<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(0);
ob_start();

// Shutdown handler: fanger fatale PHP-fejl og returnerer JSON
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_end_clean();
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['error' => 'PHP fejl: ' . $err['message'] . ' i ' . basename($err['file']) . ':' . $err['line']]);
    }
});

require_once __DIR__ . '/db.php';
@set_time_limit(300);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { ob_end_clean(); http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { ob_end_clean(); http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

// db.php har allerede inkluderet .env.php, så $_ANTHROPIC_API_KEY er sat
$apiKey = getenv('ANTHROPIC_API_KEY') ?: ($_ANTHROPIC_API_KEY ?? '');
if (!$apiKey) { ob_end_clean(); http_response_code(500); echo json_encode(['error' => 'ANTHROPIC_API_KEY ikke konfigureret']); exit; }

$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$diamond   = $body['diamond'] ?? null;
$aar       = $body['aarstalsraekker'] ?? [];
$k         = $body['knowledge'] ?? [];
$language  = in_array($body['language'] ?? '', ['da','en','de','sv','no']) ? $body['language'] : 'da';

if (!$diamond || !$k) { ob_end_clean(); http_response_code(400); echo json_encode(['error' => 'Manglende data']); exit; }

// ─── Language config ───
$langConfig = [
    'da' => ['intro' => 'Du er en erfaren numerolog. Du skriver en personlig numerologisk rapport på dansk.', 'rule' => '- Skriv udelukkende på dansk.'],
    'en' => ['intro' => 'You are an experienced numerologist. You write a personal numerological reading in English.', 'rule' => '- Write exclusively in English.'],
    'de' => ['intro' => 'Du bist ein erfahrener Numerologe. Du schreibst einen persönlichen numerologischen Bericht auf Deutsch.', 'rule' => '- Schreibe ausschließlich auf Deutsch.'],
    'sv' => ['intro' => 'Du är en erfaren numerolog. Du skriver en personlig numerologisk rapport på svenska.', 'rule' => '- Skriv uteslutande på svenska.'],
    'no' => ['intro' => 'Du er en erfaren numerolog. Du skriver en personlig numerologisk rapport på norsk.', 'rule' => '- Skriv utelukkende på norsk.'],
];
$lang = $langConfig[$language];

// ─── Build system prompt ───
function buildSystemPrompt(array $k, array $lang): string {
    $p = "{$lang['intro']}\n\nVIGTIGE REGLER:\n{$lang['rule']}\n- Du må KUN bruge den viden der er givet nedenfor. Opfind IKKE ny numerologisk viden.\n- Specielle regler skal fortolkes isoleret ud fra deres egen beskrivelse — bland IKKE energibeskrivelser ind i fortolkningen af specielle regler.\n- Livslinjen er IKKE en bevægelse eller et sekventielt forløb. Fornavn, mellemnavne og efternavn bidrager hver med deres energi, men alle energier er til stede samtidig. Fortolk dem IKKE som en rejse fra fornavn til efternavn.\n- Skriv i et varmt, personligt, men professionelt sprog.\n";

    if (!empty($k['energiesWithImages'])) {
        $p .= "\nBILLEDER:\nFølgende grundtal-energier har et tilknyttet billede: " . implode(', ', $k['energiesWithImages']) . ".\n";
        $p .= "Når du første gang omtaler en af disse energier i rapporten, indsæt pladsholder [BILLEDE:X] på en ny linje (hvor X er grundtallet). Fx [BILLEDE:1] for energi 1. Brug kun hver pladsholder én gang.\n";
    }
    if (!empty($k['rapportGlobalInstruction'])) $p .= "\n## Overordnet instruktion\n{$k['rapportGlobalInstruction']}\n";
    if (!empty($k['aboutNumerology'])) $p .= "\n## Om numerologi\n{$k['aboutNumerology']}\n";
    if (!empty($k['defRent'])) $p .= "\n## Definition: Rent numeroskop\n{$k['defRent']}\n";
    if (!empty($k['defUrent'])) $p .= "\n## Definition: Urent numeroskop\n{$k['defUrent']}\n";
    if (!empty($k['blokkeAfTal'])) $p .= "\n## Blokke af tal\n{$k['blokkeAfTal']}\n";
    if (!empty($k['diamantAar'])) $p .= "\n## Diamant og årstalsrækker\n{$k['diamantAar']}\n";
    if (!empty($k['udrensning'])) $p .= "\n## Udrensning\n{$k['udrensning']}\n";
    if (!empty($k['numerologiAlder'])) $p .= "\n## Numerologi og alder\n{$k['numerologiAlder']}\n";
    if (!empty($k['rapportStil'])) $p .= "\n## Rapportens stil\n{$k['rapportStil']}\n";
    if (!empty($k['eksempelRapport'])) $p .= "\n## Eksempelrapport\n{$k['eksempelRapport']}\n";

    if (!empty($k['energies'])) {
        $p .= "\n## Energibeskrivelser (diamant)\n";
        foreach ($k['energies'] as $e) {
            $p .= "\n### Energi " . ($e['display'] ?? $e['id'] ?? '') . "\n";
            if (!empty($e['keywords'])) $p .= "Nøgleord (rent): {$e['keywords']}\n";
            if (!empty($e['keywords_urent_numeroskop'])) $p .= "Nøgleord (urent): {$e['keywords_urent_numeroskop']}\n";
            $display = $e['display'] ?? '';
            $isGrundtal = is_numeric($display) && (int)$display >= 1 && (int)$display <= 9;
            if ($isGrundtal && !empty($e['summary'])) {
                $p .= "Grundenergi: {$e['summary']}\n";
            } elseif (!$isGrundtal && !empty($e['grundenergi'])) {
                $p .= "Grundenergi: {$e['grundenergi']}\n";
            }
            if (!empty($e['beskrivelse'])) $p .= "Beskrivelse: {$e['beskrivelse']}\n";
            if (!empty($e['ubalance_i_urent_numeroskop'])) $p .= "Ubalance: {$e['ubalance_i_urent_numeroskop']}\n";
            if (!empty($e['helheds_funktion'])) $p .= "Helhedsfunktion: {$e['helheds_funktion']}\n";
            if (!empty($e['planet'])) $p .= "Planet: {$e['planet']}\n";
            if (!empty($e['kendte'])) $p .= "Kendte: {$e['kendte']}\n";
        }
    }
    if (!empty($k['positions'])) {
        $p .= "\n## Positioner i diamanten\n";
        foreach ($k['positions'] as $pos) {
            if (!empty($pos['description'])) $p .= "- {$pos['name']}: {$pos['description']}\n";
        }
    }
    if (!empty($k['diamondRules'])) {
        $p .= "\n## Specielle regler (diamant)\n";
        foreach ($k['diamondRules'] as $i => $r) {
            $p .= "\nRegel " . ($i+1) . ":\nBetingelse: {$r['condition']}\nBetydning: {$r['description']}\n";
        }
    }
    if (!empty($k['aarEnergies'])) {
        $p .= "\n## Energibeskrivelser (årstalsrækker)\n";
        foreach ($k['aarEnergies'] as $key => $val) {
            $p .= "\n### Tal $key\n";
            if (!empty($val['keywords'])) $p .= "Nøgleord: {$val['keywords']}\n";
            if (!empty($val['beskrivelse'])) $p .= "{$val['beskrivelse']}\n";
        }
    }
    if (!empty($k['cycles_about'])) $p .= "\n## Om cyklusser\n{$k['cycles_about']}\n";
    if (!empty($k['cycles_style'])) $p .= "\n## Rapportens stil (årstalsrækker)\n{$k['cycles_style']}\n";
    if (!empty($k['cycles_124875'])) $p .= "\n## Cyklus 1-2-4-8-7-5\n{$k['cycles_124875']}\n";
    if (!empty($k['cycles_36'])) $p .= "\n## Cyklus 3-6\n{$k['cycles_36']}\n";
    if (!empty($k['cycles_9'])) $p .= "\n## Cyklus 9\n{$k['cycles_9']}\n";
    if (!empty($k['aarRules'])) {
        $p .= "\n## Specielle regler (årstalsrækker)\n";
        foreach ($k['aarRules'] as $i => $r) {
            $p .= "\nRegel " . ($i+1) . ":\nBetingelse: {$r['condition']}\nBetydning: {$r['description']}\n";
        }
    }
    if (!empty($k['astrologyGenerelt'])) $p .= "\n## Astrologi generelt\n{$k['astrologyGenerelt']}\n";
    if (!empty($k['astrologySign'])) $p .= "\n## Stjernetegn: {$k['astrologySign']['name']}\n{$k['astrologySign']['text']}\n";
    return $p;
}

// ─── Build user prompt ───
function buildUserPrompt(array $diamond, array $aar, array $k): string {
    $d    = $diamond['diamond'];
    $inp  = $diamond['input'];
    $bd   = $inp['birthDate'];
    $p    = "Skriv en komplet numerologisk rapport for denne person:\n\n";
    $p   .= "## Persondata\n";
    $p   .= "Navn: {$inp['fullName']}\n";
    $p   .= "Fødselsdato: {$bd['day']}/{$bd['month']}/{$bd['year']}\n\n";
    $p   .= "## Diamant\n";
    $p   .= "Grundenergi: {$d['grundenergi']['display']}\n";
    $livslinje = array_map(fn($e) => "{$e['name']} ({$e['display']})", $d['livslinje']);
    $p   .= "Livslinje: " . implode(' → ', $livslinje) . "\n";
    $p   .= "Bundtal: {$d['bundtal']['display']}\n";
    $p   .= "Aura øvre venstre: {$d['aura']['auraUpperLeft']['display']}\n";
    $p   .= "Aura øvre højre: {$d['aura']['auraUpperRight']['display']}\n";
    $p   .= "Aura nedre venstre: {$d['aura']['auraLowerLeft']['display']}\n";
    $p   .= "Aura nedre højre: {$d['aura']['auraLowerRight']['display']}\n";
    $p   .= "Hjertecenter: {$d['body']['hjertecenter']['centerTal']['display']}\n";
    if (!empty($d['body']['hjertecenter']['mellemnavnsBidrag'])) {
        $extra = array_map(fn($e) => $e['display'], $d['body']['hjertecenter']['mellemnavnsBidrag']);
        $p .= "Hjerte-ekstra: " . implode(', ', $extra) . "\n";
    }
    $p   .= "Solarplexus: {$d['body']['solarplexus']['centerTal']['display']}\n";
    if (!empty($d['body']['solarplexus']['mellemnavnsBidrag'])) {
        $extra = array_map(fn($e) => $e['display'], $d['body']['solarplexus']['mellemnavnsBidrag']);
        $p .= "Solar-ekstra: " . implode(', ', $extra) . "\n";
    }
    $p   .= "Rygraden: {$d['rygraden']['display']}\n";
    $p   .= "Søjletal: {$d['soejletal']['compound']}/{$d['soejletal']['reduced']}\n";

    if (!empty($aar)) {
        $p .= "\n## Årstalsrækker\n";
        $p .= "Cyklus-type: " . ($aar[0]['cycleType'] ?? 'ukendt') . "\n\n";
        foreach ($aar as $year) {
            $p .= "### {$year['year']} (grundtal: {$year['yearReduced']})\n";
            $p .= "Energier: " . implode(', ', $year['energies']) . "\n";
            if (!empty($year['specialRulesMatched'])) {
                $p .= "Matchede specielle regler: " . implode('; ', $year['specialRulesMatched']) . "\n";
            }
            $p .= "\n";
        }
    }

    $labels = ['grundenergi'=>'Grundenergi','livslinje'=>'Livslinje','bundtal'=>'Bundtal','aura'=>'Aura','hjerte_solar'=>'Hjertecenter + Solarplexus','rygraden'=>'Rygraden','soejletal'=>'Søjletal','specielle_diamant'=>'Specielle regler (diamant)','helhedsvurdering'=>'Helhedsvurdering','aarstalsraekker'=>'Årstalsrækker','cyklusser'=>'Cyklusser','specielle_aar'=>'Specielle regler (årstalsrækker)','stjernetegn'=>'Stjernetegn'];

    if (!empty($k['rapportSections'])) {
        $p .= "\n## Rapportstruktur\nOrganiser rapporten i PRÆCIS følgende sektioner, i denne rækkefølge:\n\n";
        foreach ($k['rapportSections'] as $i => $sec) {
            $p .= "### Sektion " . ($i+1) . ": " . ($sec['title'] ?? 'Unavngivet') . "\n";
            if (!empty($sec['sources'])) {
                $srcLabels = array_map(fn($s) => $labels[$s] ?? $s, $sec['sources']);
                $p .= "Datakilder at bruge: " . implode(', ', $srcLabels) . "\n";
            }
            if (!empty($sec['instruction'])) $p .= "Instruktion: {$sec['instruction']}\n";
            $p .= "\n";
        }
        $p .= "Brug formatering med overskrifter (##) for hver sektion.";
    } else {
        $p .= "\nSkriv rapporten med to hoveddele:\n1. DIAMANTEN — beskriv personen baseret på diamantens energier og positioner\n2. ÅRSTALSRÆKKER — beskriv hvert år, de energier der optræder, og hvad personen skal være opmærksom på\n\nBrug formatering med overskrifter (##) og afsnit.";
    }
    return $p;
}

$systemPrompt = buildSystemPrompt($k, $lang);
$userPrompt   = buildUserPrompt($diamond, $aar, $k);

$payload = json_encode([
    'model'      => 'claude-opus-4-5',
    'system'     => $systemPrompt,
    'messages'   => [['role' => 'user', 'content' => $userPrompt]],
    'max_tokens' => 8000
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
    CURLOPT_TIMEOUT        => 280,
    CURLOPT_CONNECTTIMEOUT => 15
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) { ob_end_clean(); http_response_code(500); echo json_encode(['error' => 'cURL fejl: ' . $curlErr]); exit; }
$data = json_decode($response, true);
if ($httpCode !== 200) {
    $apiMsg = $data['error']['message'] ?? $response;
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['error' => 'Claude API fejl (HTTP ' . $httpCode . '): ' . $apiMsg]);
    exit;
}
$rapport = $data['content'][0]['text'] ?? '';
ob_end_clean();
echo json_encode(['rapport' => $rapport], JSON_UNESCAPED_UNICODE);
