<?php
/* ============================================================
   /api/stripe-webhook.php
   Håndterer Stripe checkout.session.completed webhook.
   Flow:
     1. Validér Stripe-signatur
     2. Gem ordre i DB (idempotent)
     3. Returnér 200 til Stripe
     4. Beregn diamant + årstalsrækker (PHP-port)
     5. Hent vidensbase fra DB
     6. Kald Claude API (generer rapport)
     7. Send HTML-email til kunden
     8. Opdatér ordre-status
   ============================================================ */

error_reporting(E_ERROR);
ini_set('display_errors', '0');
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$rawBody = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$webhookSecret = CFG_STRIPE_WEBHOOK_SECRET;

// ── Validér Stripe-signatur ──
if ($webhookSecret) {
    if (!verifyStripeSignature($rawBody, $sigHeader, $webhookSecret)) {
        http_response_code(400);
        echo 'Webhook signature verification failed';
        exit;
    }
}

$event = json_decode($rawBody, true);
if (!$event || ($event['type'] ?? '') !== 'checkout.session.completed') {
    http_response_code(200);
    echo json_encode(['received' => true]);
    exit;
}

$session   = $event['data']['object'];
$sessionId = $session['id'] ?? '';
$email     = $session['customer_email'] ?? '';
$meta      = $session['metadata'] ?? [];
$fullName  = trim($meta['fullName'] ?? '');
$birthDate = trim($meta['birthDate'] ?? '');
$plan      = $meta['plan'] ?? 'foundation';
$validPlans = ['foundation', 'direction', 'activation'];
if (!in_array($plan, $validPlans)) $plan = 'foundation';

if (!$sessionId || !$email || !$fullName || !$birthDate) {
    http_response_code(200);
    echo json_encode(['received' => true, 'warning' => 'Missing metadata']);
    exit;
}

$db = getDB();

// ── Idempotency: spring over hvis allerede færdigbehandlet ──
$stmt = $db->prepare('SELECT id, status FROM orders WHERE stripe_session_id = ?');
$stmt->bind_param('s', $sessionId);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing && in_array($existing['status'], ['done', 'sent'])) {
    http_response_code(200);
    echo json_encode(['received' => true, 'note' => 'Already processed']);
    exit;
}

// ── Gem ordre (eller brug eksisterende) ──
if (!$existing) {
    $stmt = $db->prepare('INSERT INTO orders (stripe_session_id, full_name, birth_date, email, plan, status) VALUES (?,?,?,?,?,?)');
    $pendingStatus = 'pending';
    $stmt->bind_param('ssssss', $sessionId, $fullName, $birthDate, $email, $plan, $pendingStatus);
    $stmt->execute();
    $orderId = $db->insert_id;
    $stmt->close();
} else {
    $orderId = $existing['id'];
}

// ── Svar 200 til Stripe straks — behandl i baggrunden ──
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['received' => true]);
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    if (ob_get_level()) ob_flush();
    flush();
}

ignore_user_abort(true);
set_time_limit(300);

// ── Opdatér status → generating ──
$db->query("UPDATE orders SET status='generating' WHERE id=" . (int)$orderId);

try {
    // 1. Beregn diamant
    $diamondResult = computeDiamond($fullName, $birthDate);

    // 2. Beregn årstalsrækker (foundation har ingen)
    $aarstalsraekker = [];
    if ($plan !== 'foundation') {
        $currentYear = (int)date('Y');
        $fromYear    = $currentYear - 1;          // sidste år
        $numYears    = 11;                         // -1 + nuværende + 9 frem
        $birthYear   = $diamondResult['input']['birthDate']['year'];
        $grund       = $diamondResult['diamond']['grundenergi']['value'];
        $bund        = $diamondResult['diamond']['bundtal']['reduced'];
        $soejle      = $diamondResult['diamond']['soejletal']['reduced'];
        for ($i = 0; $i < $numYears; $i++) {
            $aarstalsraekker[] = computeYearEnergies($fromYear + $i, $birthYear, $grund, $bund, $soejle);
        }
    }

    // 3. Hent vidensbase fra DB
    $k = getKnowledgeForRapport($db, $plan, $diamondResult);

    // 4. Build prompts + kald Claude
    $rapportText = callClaudeForRapport($diamondResult, $aarstalsraekker, $k);

    // 5. Konvertér til HTML
    $rapportHtml = markdownToHtml($rapportText);

    // 6. Gem rapport i DB
    $stmt = $db->prepare("UPDATE orders SET status='done', rapport_html=? WHERE id=?");
    $stmt->bind_param('si', $rapportHtml, $orderId);
    $stmt->execute();
    $stmt->close();

    // 7. Send email
    $planNames = ['foundation' => 'Foundation Report', 'direction' => 'Direction Report', 'activation' => 'Activation Report'];
    $planName  = $planNames[$plan] ?? 'Numerological Reading';
    $emailHtml = buildEmailHtml($fullName, $planName, $rapportHtml, $k['rapportOmNumerologi'] ?? '', $k['rapportOmDiamanten'] ?? '', $k['rapportAfslutning'] ?? '');
    $sent = sendEmail($email, "Your {$planName} — StrategicNumerology", $emailHtml);

    // 8. Opdatér status
    if ($sent) {
        $db->query("UPDATE orders SET status='sent', sent_at=NOW() WHERE id=" . (int)$orderId);
    }
    // Hvis !$sent forbliver status 'done' (synlig for admin → gensend)

} catch (Throwable $e) {
    $msg = substr($e->getMessage(), 0, 1000);
    $stmt = $db->prepare("UPDATE orders SET status='failed', error_msg=? WHERE id=?");
    $stmt->bind_param('si', $msg, $orderId);
    $stmt->execute();
    $stmt->close();
}

// ════════════════════════════════════════════════════════════════
// STRIPE SIGNATUR-VALIDERING
// ════════════════════════════════════════════════════════════════

function verifyStripeSignature(string $payload, string $sigHeader, string $secret): bool {
    if (!$sigHeader) return false;
    $parts = [];
    foreach (explode(',', $sigHeader) as $part) {
        [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
        $parts[$k] = $v;
    }
    $timestamp = $parts['t'] ?? '';
    $signature = $parts['v1'] ?? '';
    if (!$timestamp || !$signature) return false;
    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    return hash_equals($expected, $signature);
}

// ════════════════════════════════════════════════════════════════
// PHP DIAMOND ENGINE  (port af assets/js/diamondEngine.js)
// ════════════════════════════════════════════════════════════════

function digitReduce(int $n): int {
    if ($n <= 0) return 0;
    while ($n >= 10) {
        $sum = 0;
        foreach (str_split((string)$n) as $d) $sum += (int)$d;
        $n = $sum;
    }
    return $n;
}

function displayValue(int $compound): string {
    $r = digitReduce($compound);
    return $compound < 10 ? (string)$compound : "{$compound}/{$r}";
}

function computeDiamond(string $fullName, string $birthDateISO): array {
    $LETTER_VALUES = [
        'A'=>1,'I'=>1,'J'=>1,'Q'=>1,'Y'=>1,'Å'=>1,
        'B'=>2,'K'=>2,'R'=>2,
        'C'=>3,'G'=>3,'L'=>3,'S'=>3,
        'D'=>4,'M'=>4,'T'=>4,
        'E'=>5,'H'=>5,'N'=>5,'X'=>5,
        'U'=>6,'V'=>6,'W'=>6,'Æ'=>6,
        'O'=>7,'Z'=>7,'Ø'=>7,
        'F'=>8,'P'=>8,
    ];

    // Normalisering
    $norm = mb_strtoupper(trim($fullName), 'UTF-8');
    $norm = preg_replace('/[\-\x{2011}\x{2013}\x{2014}]/u', ' ', $norm);
    $norm = preg_replace("/['\x{2018}\x{2019}\x{02BC}\x{00B4}`]/u", ' ', $norm);
    // Bevar Æ Ø Å under NFD-normalisering
    $norm = str_replace(['Æ','Ø','Å'], ["\x01","\x02","\x03"], $norm);
    if (class_exists('Normalizer')) {
        $norm = \Normalizer::normalize($norm, \Normalizer::NFD);
        $norm = preg_replace('/[\x{0300}-\x{036f}]/u', '', $norm);
    }
    $norm = str_replace(["\x01","\x02","\x03"], ['Æ','Ø','Å'], $norm);
    $parts = array_values(array_filter(preg_split('/\s+/', $norm)));

    if (count($parts) < 2) {
        throw new \InvalidArgumentException('Full name requires at least first and last name.');
    }

    // Top = reduceret fødselsdag
    [$yyyy, $mm, $dd] = array_map('intval', explode('-', $birthDateISO));
    $top = digitReduce($dd);

    // Navne-energi
    $nameEnergy = function(string $part) use ($LETTER_VALUES, $top): array {
        $letters  = mb_str_split($part, 1, 'UTF-8');
        $values   = array_map(fn($ch) => $LETTER_VALUES[$ch] ?? 0, $letters);
        $compound = array_sum($values);
        if ($compound < 10) {
            $compound += $top;
            if ($compound < 10) $compound += 9;
        }
        $reduced = digitReduce($compound);
        return ['part'=>$part,'compound'=>$compound,'reduced'=>$reduced,'display'=>displayValue($compound)];
    };

    $energies = array_map($nameEnergy, $parts);
    $first    = $energies[0];
    $last     = $energies[count($energies)-1];
    $middles  = array_slice($energies, 1, -1);

    // Bundtal
    $bottomCompound = array_sum(array_column($energies, 'reduced'));
    $bottomReduced  = digitReduce($bottomCompound);

    // Aura
    $auraCalc = fn(int $a, int $b) => ['compound'=>$a+$b,'reduced'=>digitReduce($a+$b),'display'=>displayValue($a+$b)];
    $upperLeft  = $auraCalc($top, $first['reduced']);
    $upperRight = $auraCalc($top, $last['reduced']);
    $lowerLeft  = $auraCalc($bottomReduced, $first['reduced']);
    $lowerRight = $auraCalc($bottomReduced, $last['reduced']);

    // Hjertecenter
    $heartCompound = $upperLeft['reduced'] + $upperRight['reduced'];
    $heartReduced  = digitReduce($heartCompound);
    $heartExtras   = array_map(fn($m) => ['compound'=>$m['reduced']+$heartReduced,'reduced'=>digitReduce($m['reduced']+$heartReduced),'display'=>displayValue($m['reduced']+$heartReduced)], $middles);

    // Solarplexus
    $solarCompound = $lowerLeft['reduced'] + $lowerRight['reduced'];
    $solarReduced  = digitReduce($solarCompound);
    $solarExtras   = array_map(fn($m) => ['compound'=>$m['reduced']+$solarReduced,'reduced'=>digitReduce($m['reduced']+$solarReduced),'display'=>displayValue($m['reduced']+$solarReduced)], $middles);

    // Rygraden
    $rygradCompound = $top + $bottomReduced;
    $rygradReduced  = digitReduce($rygradCompound);

    // Søjletal (trekant)
    $triangleCompound = $top + $bottomReduced + $heartReduced + $solarReduced
        + array_sum(array_column($heartExtras, 'reduced'))
        + array_sum(array_column($solarExtras, 'reduced'))
        + array_sum(array_column($middles, 'reduced'));
    $triangleReduced = digitReduce($triangleCompound);

    // Livslinje
    $livslinje = array_map(function($e, $i) use ($energies) {
        $n = count($energies);
        $role = $i === 0 ? 'fornavn' : ($i === $n-1 ? 'efternavn' : 'mellemnavn');
        return ['role'=>$role,'name'=>$e['part'],'compound'=>$e['compound'],'reduced'=>$e['reduced'],'display'=>$e['display']];
    }, $energies, array_keys($energies));

    return [
        'input' => [
            'fullName'  => trim($fullName),
            'birthDate' => ['day'=>$dd,'month'=>$mm,'year'=>$yyyy],
        ],
        'diamond' => [
            'grundenergi' => ['value'=>$top,'display'=>(string)$top],
            'livslinje'   => $livslinje,
            'bundtal'     => ['compound'=>$bottomCompound,'reduced'=>$bottomReduced,'display'=>displayValue($bottomCompound)],
            'aura'        => ['auraUpperLeft'=>$upperLeft,'auraUpperRight'=>$upperRight,'auraLowerLeft'=>$lowerLeft,'auraLowerRight'=>$lowerRight],
            'body'        => [
                'hjertecenter' => ['centerTal'=>['compound'=>$heartCompound,'reduced'=>$heartReduced,'display'=>displayValue($heartCompound)],'mellemnavnsBidrag'=>$heartExtras],
                'solarplexus'  => ['centerTal'=>['compound'=>$solarCompound,'reduced'=>$solarReduced,'display'=>displayValue($solarCompound)],'mellemnavnsBidrag'=>$solarExtras],
            ],
            'rygraden'  => ['compound'=>$rygradCompound,'reduced'=>$rygradReduced,'display'=>displayValue($rygradCompound)],
            'soejletal' => ['compound'=>$triangleCompound,'reduced'=>$triangleReduced],
        ],
    ];
}

// ════════════════════════════════════════════════════════════════
// ÅRSTALSRÆKKER BEREGNING  (port af rapport.html JS)
// ════════════════════════════════════════════════════════════════

function getCycleType(int $birthYear): string {
    $r = digitReduce($birthYear);
    if ($r === 9) return '9';
    if ($r === 3 || $r === 6) return '3-6';
    return '1-2-4-8-7-5';
}

function getChapterInfo(int $year, int $birthYear): ?array {
    $cycleSeq        = [1,2,4,8,7,5];
    $birthYearReduced = digitReduce($birthYear);
    $startIdx        = array_search($birthYearReduced, $cycleSeq, true);
    if ($startIdx === false) return null;
    $yearsFromBirth  = $year - $birthYear;
    $pos             = (($yearsFromBirth % 27) + 27) % 27;
    $accumulated     = 0;
    for ($i = 0; $i < 6; $i++) {
        $seqIdx       = ($startIdx + $i) % 6;
        $chapterValue = $cycleSeq[$seqIdx];
        if ($pos < $accumulated + $chapterValue) {
            $prevSeqIdx = ($seqIdx - 1 + 6) % 6;
            return [
                'chapterValue'     => $chapterValue,
                'isFirstYear'      => $pos === $accumulated,
                'isLastYear'       => $pos === $accumulated + $chapterValue - 1,
                'prevChapterValue' => $cycleSeq[$prevSeqIdx],
                'isBirthChapter'   => $i === 0,
            ];
        }
        $accumulated += $chapterValue;
    }
    return null;
}

function computeYearEnergies(int $year, int $birthYear, int $grund, int $bund, int $soejle): array {
    $yearReduced      = digitReduce($year);
    $birthYearReduced = digitReduce($birthYear);
    $ageReduced       = digitReduce($year - $birthYear);
    $cycleType        = getCycleType($birthYear);

    $energies  = [$yearReduced];
    $compound  = $yearReduced;

    $step = function(int $val) use (&$compound, &$energies): void {
        $base     = digitReduce($compound);
        $compound = $base + $val;
        $energies[] = $val;
        $energies[] = $compound;
    };

    $step($grund); // Grundenergi
    $step($bund);  // Bundtal

    // Cyklus-regler
    if ($cycleType === '9') {
        if ($yearReduced === 8) { $step(9); $step($soejle); }
        if ($yearReduced === 9) { $step(9); $step(9); $step($soejle); }
    }
    if ($cycleType === '3-6') {
        $key = $birthYearReduced === 3 ? [3,6] : [6,3];
        if ($yearReduced === $key[1]) { $step($key[0]); $step($key[1]); $step($soejle); }
        if ($yearReduced === $key[0]) { $step($key[1]); $step($soejle); }
    }
    if ($cycleType === '1-2-4-8-7-5') {
        if ($year !== $birthYear) {
            $info = getChapterInfo($year, $birthYear);
            if ($info && $info['isFirstYear']) {
                $step($info['prevChapterValue']);
                $step($info['chapterValue']);
                if ($info['chapterValue'] === 1) $step(1);
                $step($soejle);
            } elseif ($info && $info['isLastYear']) {
                $step($info['chapterValue']);
                $step($soejle);
            }
        }
    }

    $step($ageReduced); // Alder

    return [
        'year'        => $year,
        'yearReduced' => $yearReduced,
        'cycleType'   => $cycleType,
        'energies'    => array_map('displayValue', $energies),
    ];
}

// ════════════════════════════════════════════════════════════════
// VIDENSBASE FRA DB
// ════════════════════════════════════════════════════════════════

function getKnowledgeForRapport(mysqli $db, string $plan, array $diamondResult): array {
    $g   = $db->query('SELECT * FROM generelt WHERE id=1')->fetch_assoc() ?? [];

    $energies  = mysqli_fetch_all($db->query('SELECT * FROM diamant_energies ORDER BY id ASC'), MYSQLI_ASSOC);
    $positions = mysqli_fetch_all($db->query('SELECT * FROM diamant_positions ORDER BY id ASC'), MYSQLI_ASSOC);
    $dRules    = mysqli_fetch_all($db->query('SELECT * FROM diamant_rules ORDER BY id ASC'), MYSQLI_ASSOC);
    $aarERows  = mysqli_fetch_all($db->query('SELECT * FROM aarstalsraekker_energies ORDER BY tal ASC'), MYSQLI_ASSOC);
    $cycleRows = mysqli_fetch_all($db->query('SELECT * FROM aarstalsraekker_cycles ORDER BY id ASC'), MYSQLI_ASSOC);
    $aarRules  = mysqli_fetch_all($db->query('SELECT * FROM aarstalsraekker_rules ORDER BY id ASC'), MYSQLI_ASSOC);
    $sections  = mysqli_fetch_all($db->query('SELECT * FROM rapport_sections ORDER BY id ASC'), MYSQLI_ASSOC);

    $aarEnergiesObj = [];
    foreach ($aarERows as $e) {
        $aarEnergiesObj[$e['tal']] = ['keywords'=>$e['keywords'],'beskrivelse'=>$e['beskrivelse']];
    }
    $cyclesObj = [];
    foreach ($cycleRows as $c) {
        $cyclesObj[$c['name']] = $c['description'];
    }
    $parsedSections = array_map(function($s) {
        $s['sources'] = json_decode($s['sources'] ?? '[]', true) ?: [];
        return $s;
    }, $sections);

    // Stjernetegn for personen
    $astrologySign = null;
    $day   = $diamondResult['input']['birthDate']['day'];
    $month = $diamondResult['input']['birthDate']['month'];
    $sign  = getZodiacSign($day, $month);
    if ($sign && !empty($g['astrologyGenerelt'])) {
        // Forsøg at slå stjernetegn-tekst op fra astrologiData JSON-felt
        $astroData = json_decode($g['astrologyData'] ?? '{}', true) ?: [];
        $signText  = $astroData[$sign['id']] ?? '';
        if ($signText) $astrologySign = ['name'=>$sign['name'],'text'=>$signText];
    }

    return [
        'aboutNumerology'          => $g['aboutNumerology'] ?? '',
        'defRent'                  => $g['defRent'] ?? '',
        'defUrent'                 => $g['defUrent'] ?? '',
        'blokkeAfTal'              => $g['blokkeAfTal'] ?? '',
        'diamantAar'               => $g['diamantAarstalsraekker'] ?? '',
        'udrensning'               => $g['udrensning'] ?? '',
        'numerologiAlder'          => $g['numerologiAlder'] ?? '',
        'rapportStil'              => $g['rapportStil'] ?? '',
        'eksempelRapport'          => $g['eksempelRapport'] ?? '',
        'rapportGlobalInstruction' => $g['rapportGlobalInstruction'] ?? '',
        'rapportOmNumerologi'      => $g['rapportOmNumerologi'] ?? '',
        'rapportOmDiamanten'       => $g['rapportOmDiamanten'] ?? '',
        'rapportAfslutning'        => $g['rapportAfslutning'] ?? '',
        'rapportSections'          => $parsedSections,
        'energies'                 => $energies,
        'energiesWithImages'       => [], // billeder er localStorage-only, ingen i email
        'positions'                => $positions,
        'diamondRules'             => array_map(fn($r)=>['condition'=>$r['condition'],'description'=>$r['description']], $dRules),
        'aarEnergies'              => $aarEnergiesObj,
        'cycles_about'             => $g['aboutCycles'] ?? '',
        'cycles_style'             => $g['cycleStyle'] ?? '',
        'cycles_124875'            => $cyclesObj['1-2-4-8-7-5'] ?? '',
        'cycles_36'                => $cyclesObj['3-6'] ?? '',
        'cycles_9'                 => $cyclesObj['9'] ?? '',
        'aarRules'                 => array_map(fn($r)=>['condition'=>$r['condition'],'description'=>$r['description']], $aarRules),
        'astrologyGenerelt'        => $g['astrologyGenerelt'] ?? '',
        'astrologySign'            => $astrologySign,
    ];
}

function getZodiacSign(int $day, int $month): ?array {
    $signs = [
        ['name'=>'Stenbukken','id'=>'stenbukken','start'=>[12,22],'end'=>[1,19]],
        ['name'=>'Vandmanden','id'=>'vandmanden','start'=>[1,20],'end'=>[2,18]],
        ['name'=>'Fiskene',   'id'=>'fiskene',   'start'=>[2,19],'end'=>[3,20]],
        ['name'=>'Vædderen',  'id'=>'vaedder',   'start'=>[3,21],'end'=>[4,19]],
        ['name'=>'Tyren',     'id'=>'tyren',     'start'=>[4,20],'end'=>[5,20]],
        ['name'=>'Tvillingen','id'=>'tvillingen','start'=>[5,21],'end'=>[6,20]],
        ['name'=>'Krebsen',   'id'=>'krebsen',   'start'=>[6,21],'end'=>[7,22]],
        ['name'=>'Løven',     'id'=>'loeven',    'start'=>[7,23],'end'=>[8,22]],
        ['name'=>'Jomfruen',  'id'=>'jomfruen',  'start'=>[8,23],'end'=>[9,22]],
        ['name'=>'Vægten',    'id'=>'vaegten',   'start'=>[9,23],'end'=>[10,22]],
        ['name'=>'Skorpionen','id'=>'skorpionen','start'=>[10,23],'end'=>[11,21]],
        ['name'=>'Skytten',   'id'=>'skytten',   'start'=>[11,22],'end'=>[12,21]],
    ];
    foreach ($signs as $s) {
        if ($s['start'][0] > $s['end'][0]) {
            if (($month===$s['start'][0] && $day>=$s['start'][1]) || ($month===$s['end'][0] && $day<=$s['end'][1])) return $s;
        } elseif ($s['start'][0]===$s['end'][0]) {
            if ($month===$s['start'][0] && $day>=$s['start'][1] && $day<=$s['end'][1]) return $s;
        } else {
            if (($month===$s['start'][0] && $day>=$s['start'][1]) || ($month===$s['end'][0] && $day<=$s['end'][1])) return $s;
        }
    }
    return null;
}

// ════════════════════════════════════════════════════════════════
// PROMPT-BYGNING + CLAUDE API  (fra generate-rapport.php)
// ════════════════════════════════════════════════════════════════

function buildSystemPrompt(array $k): string {
    $p  = "Du er en erfaren numerolog. Du skriver en personlig numerologisk rapport på dansk.\n\n";
    $p .= "VIGTIGE REGLER:\n- Skriv udelukkende på dansk.\n";
    $p .= "- Du må KUN bruge den viden der er givet nedenfor. Opfind IKKE ny numerologisk viden.\n";
    $p .= "- Specielle regler skal fortolkes isoleret ud fra deres egen beskrivelse.\n";
    $p .= "- Livslinjen er IKKE en bevægelse. Alle energier er til stede samtidig.\n";
    $p .= "- Skriv i et varmt, personligt, men professionelt sprog.\n";

    if (!empty($k['rapportGlobalInstruction'])) $p .= "\n## Overordnet instruktion\n{$k['rapportGlobalInstruction']}\n";
    if (!empty($k['aboutNumerology']))          $p .= "\n## Om numerologi\n{$k['aboutNumerology']}\n";
    if (!empty($k['defRent']))                  $p .= "\n## Definition: Rent numeroskop\n{$k['defRent']}\n";
    if (!empty($k['defUrent']))                 $p .= "\n## Definition: Urent numeroskop\n{$k['defUrent']}\n";
    if (!empty($k['blokkeAfTal']))              $p .= "\n## Blokke af tal\n{$k['blokkeAfTal']}\n";
    if (!empty($k['diamantAar']))               $p .= "\n## Diamant og årstalsrækker\n{$k['diamantAar']}\n";
    if (!empty($k['udrensning']))               $p .= "\n## Udrensning\n{$k['udrensning']}\n";
    if (!empty($k['numerologiAlder']))          $p .= "\n## Numerologi og alder\n{$k['numerologiAlder']}\n";
    if (!empty($k['rapportStil']))              $p .= "\n## Rapportens stil\n{$k['rapportStil']}\n";
    if (!empty($k['eksempelRapport']))          $p .= "\n## Eksempelrapport\n{$k['eksempelRapport']}\n";

    if (!empty($k['energies'])) {
        $p .= "\n## Energibeskrivelser (diamant)\n";
        foreach ($k['energies'] as $e) {
            $p .= "\n### Energi " . ($e['display'] ?? $e['id'] ?? '') . "\n";
            if (!empty($e['keywords']))                    $p .= "Nøgleord (rent): {$e['keywords']}\n";
            if (!empty($e['keywords_urent_numeroskop']))   $p .= "Nøgleord (urent): {$e['keywords_urent_numeroskop']}\n";
            $display = $e['display'] ?? '';
            $isGrundtal = is_numeric($display) && (int)$display >= 1 && (int)$display <= 9;
            if ($isGrundtal && !empty($e['summary'])) {
                $p .= "Grundenergi: {$e['summary']}\n";
            } elseif (!$isGrundtal && !empty($e['grundenergi'])) {
                $p .= "Grundenergi: {$e['grundenergi']}\n";
            }
            if (!empty($e['beskrivelse']))                 $p .= "Beskrivelse: {$e['beskrivelse']}\n";
            if (!empty($e['ubalance_i_urent_numeroskop'])) $p .= "Ubalance: {$e['ubalance_i_urent_numeroskop']}\n";
            if (!empty($e['helheds_funktion']))            $p .= "Helhedsfunktion: {$e['helheds_funktion']}\n";
            if (!empty($e['planet']))                      $p .= "Planet: {$e['planet']}\n";
            if (!empty($e['kendte']))                      $p .= "Kendte: {$e['kendte']}\n";
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
            if (!empty($val['keywords']))   $p .= "Nøgleord: {$val['keywords']}\n";
            if (!empty($val['beskrivelse'])) $p .= "{$val['beskrivelse']}\n";
        }
    }
    if (!empty($k['cycles_about']))  $p .= "\n## Om cyklusser\n{$k['cycles_about']}\n";
    if (!empty($k['cycles_style']))  $p .= "\n## Rapportens stil (årstalsrækker)\n{$k['cycles_style']}\n";
    if (!empty($k['cycles_124875'])) $p .= "\n## Cyklus 1-2-4-8-7-5\n{$k['cycles_124875']}\n";
    if (!empty($k['cycles_36']))     $p .= "\n## Cyklus 3-6\n{$k['cycles_36']}\n";
    if (!empty($k['cycles_9']))      $p .= "\n## Cyklus 9\n{$k['cycles_9']}\n";
    if (!empty($k['aarRules'])) {
        $p .= "\n## Specielle regler (årstalsrækker)\n";
        foreach ($k['aarRules'] as $i => $r) {
            $p .= "\nRegel " . ($i+1) . ":\nBetingelse: {$r['condition']}\nBetydning: {$r['description']}\n";
        }
    }
    if (!empty($k['astrologyGenerelt'])) $p .= "\n## Astrologi generelt\n{$k['astrologyGenerelt']}\n";
    if (!empty($k['astrologySign']))     $p .= "\n## Stjernetegn: {$k['astrologySign']['name']}\n{$k['astrologySign']['text']}\n";
    return $p;
}

function buildUserPrompt(array $diamondResult, array $aar, array $k): string {
    $d   = $diamondResult['diamond'];
    $inp = $diamondResult['input'];
    $bd  = $inp['birthDate'];
    $p   = "Skriv en komplet numerologisk rapport for denne person:\n\n## Persondata\n";
    $p  .= "Navn: {$inp['fullName']}\n";
    $p  .= "Fødselsdato: {$bd['day']}/{$bd['month']}/{$bd['year']}\n\n## Diamant\n";
    $p  .= "Grundenergi: {$d['grundenergi']['display']}\n";
    $livslinje = array_map(fn($e) => "{$e['name']} ({$e['display']})", $d['livslinje']);
    $p  .= "Livslinje: " . implode(' → ', $livslinje) . "\n";
    $p  .= "Bundtal: {$d['bundtal']['display']}\n";
    $p  .= "Aura øvre venstre: {$d['aura']['auraUpperLeft']['display']}\n";
    $p  .= "Aura øvre højre: {$d['aura']['auraUpperRight']['display']}\n";
    $p  .= "Aura nedre venstre: {$d['aura']['auraLowerLeft']['display']}\n";
    $p  .= "Aura nedre højre: {$d['aura']['auraLowerRight']['display']}\n";
    $p  .= "Hjertecenter: {$d['body']['hjertecenter']['centerTal']['display']}\n";
    if (!empty($d['body']['hjertecenter']['mellemnavnsBidrag'])) {
        $extra = array_map(fn($e)=>$e['display'], $d['body']['hjertecenter']['mellemnavnsBidrag']);
        $p .= "Hjerte-ekstra: " . implode(', ', $extra) . "\n";
    }
    $p .= "Solarplexus: {$d['body']['solarplexus']['centerTal']['display']}\n";
    if (!empty($d['body']['solarplexus']['mellemnavnsBidrag'])) {
        $extra = array_map(fn($e)=>$e['display'], $d['body']['solarplexus']['mellemnavnsBidrag']);
        $p .= "Solar-ekstra: " . implode(', ', $extra) . "\n";
    }
    $p .= "Rygraden: {$d['rygraden']['display']}\n";
    $p .= "Søjletal: {$d['soejletal']['compound']}/{$d['soejletal']['reduced']}\n";

    if (!empty($aar)) {
        $p .= "\n## Årstalsrækker\nCyklus-type: " . ($aar[0]['cycleType'] ?? 'ukendt') . "\n\n";
        foreach ($aar as $year) {
            $p .= "### {$year['year']} (grundtal: {$year['yearReduced']})\n";
            $p .= "Energier: " . implode(', ', $year['energies']) . "\n\n";
        }
    }

    $labels = ['grundenergi'=>'Grundenergi','livslinje'=>'Livslinje','bundtal'=>'Bundtal','aura'=>'Aura','hjerte_solar'=>'Hjertecenter + Solarplexus','rygraden'=>'Rygraden','soejletal'=>'Søjletal','specielle_diamant'=>'Specielle regler (diamant)','helhedsvurdering'=>'Helhedsvurdering','aarstalsraekker'=>'Årstalsrækker','cyklusser'=>'Cyklusser','specielle_aar'=>'Specielle regler (årstalsrækker)','stjernetegn'=>'Stjernetegn'];
    if (!empty($k['rapportSections'])) {
        $p .= "\n## Rapportstruktur\nOrganiser rapporten i PRÆCIS følgende sektioner:\n\n";
        foreach ($k['rapportSections'] as $i => $sec) {
            $p .= "### Sektion " . ($i+1) . ": " . ($sec['title'] ?? 'Unavngivet') . "\n";
            if (!empty($sec['sources'])) {
                $srcLabels = array_map(fn($s) => $labels[$s] ?? $s, $sec['sources']);
                $p .= "Datakilder: " . implode(', ', $srcLabels) . "\n";
            }
            if (!empty($sec['instruction'])) $p .= "Instruktion: {$sec['instruction']}\n";
            $p .= "\n";
        }
        $p .= "Brug formatering med overskrifter (##) for hver sektion.";
    } else {
        $p .= "\nSkriv rapporten med to hoveddele:\n1. DIAMANTEN\n2. ÅRSTALSRÆKKER (hvis relevant)\n\nBrug formatering med overskrifter (##) og afsnit.";
    }
    return $p;
}

function callClaudeForRapport(array $diamondResult, array $aar, array $k): string {
    $apiKey = getenv('ANTHROPIC_API_KEY') ?: ($GLOBALS['_ANTHROPIC_API_KEY'] ?? '');
    if (!$apiKey) throw new \RuntimeException('ANTHROPIC_API_KEY ikke konfigureret');

    $payload = json_encode([
        'model'       => 'claude-opus-4-5',
        'system'      => buildSystemPrompt($k),
        'messages'    => [['role'=>'user','content'=>buildUserPrompt($diamondResult, $aar, $k)]],
        'temperature' => 1,
        'max_tokens'  => 8000,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT => 180,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr)         throw new \RuntimeException('cURL fejl: ' . $curlErr);
    if ($httpCode !== 200) throw new \RuntimeException('Claude API fejl ' . $httpCode . ': ' . $response);

    $data = json_decode($response, true);
    return $data['content'][0]['text'] ?? '';
}

// ════════════════════════════════════════════════════════════════
// MARKDOWN → HTML + EMAIL TEMPLATE
// ════════════════════════════════════════════════════════════════

function markdownToHtml(string $md): string {
    if (!$md) return '';
    // Fjern billede-pladsholdere (billeder er ikke tilgængelige i email)
    $html = preg_replace('/\[BILLEDE[:\s]*\d\]/i', '', $md);
    $html = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $html);
    $html = preg_replace('/^## (.+)$/m',  '<h2>$1</h2>', $html);
    $html = preg_replace('/^# (.+)$/m',   '<h1>$1</h1>', $html);
    $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);
    $html = preg_replace('/\*(.+?)\*/s',     '<em>$1</em>',         $html);
    $html = preg_replace('/\n\n/', '</p><p>', $html);
    $html = preg_replace('/\n/',   '<br>',    $html);
    return '<p>' . $html . '</p>';
}

function buildEmailHtml(string $fullName, string $planName, string $rapportHtml, string $omNumerologi = '', string $omDiamanten = '', string $afslutning = ''): string {
    $safeName = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
    $safePlan = htmlspecialchars($planName, ENT_QUOTES, 'UTF-8');
    $fornavn  = htmlspecialchars(explode(' ', trim($fullName))[0], ENT_QUOTES, 'UTF-8');

    $sectionStyle = 'background:#13131f;border:1px solid rgba(201,168,76,0.15);border-radius:12px;padding:36px 40px;margin-bottom:16px;';
    $textStyle    = 'font-size:15px;line-height:1.8;color:#ddd8ca;';
    $h2Style      = 'font-size:11px;letter-spacing:2px;text-transform:uppercase;color:#c9a84c;font-weight:600;margin:0 0 16px;';

    $omNumBlock  = $omNumerologi ? "<tr><td style=\"{$sectionStyle}\"><h2 style=\"{$h2Style}\">Om numerologi</h2><div style=\"{$textStyle}\">" . markdownToHtml(str_replace('{{fornavn}}', $fornavn, $omNumerologi)) . "</div></td></tr>" : '';
    $omDiaBlock  = $omDiamanten  ? "<tr><td style=\"{$sectionStyle}\"><h2 style=\"{$h2Style}\">Om diamanten</h2><div style=\"{$textStyle}\">" . markdownToHtml(str_replace('{{fornavn}}', $fornavn, $omDiamanten))  . "</div></td></tr>" : '';
    $afslBlock   = $afslutning   ? "<tr><td style=\"{$sectionStyle}\"><h2 style=\"{$h2Style}\">Afslutning</h2><div style=\"{$textStyle}\">"    . markdownToHtml(str_replace('{{fornavn}}', $fornavn, $afslutning))   . "</div></td></tr>" : '';
    return <<<HTML
<!DOCTYPE html>
<html lang="da">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$safePlan} — StrategicNumerology</title>
</head>
<body style="margin:0;padding:0;background:#0b0b12;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;color:#e8e4da;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#0b0b12;padding:40px 20px;">
    <tr><td align="center">
      <table width="620" cellpadding="0" cellspacing="0" style="max-width:620px;width:100%;">

        <!-- Header -->
        <tr>
          <td style="padding:0 0 32px 0;text-align:center;">
            <p style="margin:0;font-size:11px;letter-spacing:2px;text-transform:uppercase;color:#c9a84c;font-weight:600;">StrategicNumerology</p>
            <h1 style="margin:12px 0 0;font-size:28px;font-weight:700;color:#e8d5a3;line-height:1.2;">{$safePlan}</h1>
            <p style="margin:8px 0 0;font-size:14px;color:#8c8c9b;">Udarbejdet til {$safeName}</p>
            <hr style="border:none;border-top:1px solid rgba(201,168,76,0.2);margin:24px 0 0;">
          </td>
        </tr>

        <!-- Rapport indhold -->
        {$omNumBlock}
        {$omDiaBlock}
        <tr>
          <td style="background:#13131f;border:1px solid rgba(201,168,76,0.15);border-radius:12px;padding:36px 40px;margin-bottom:16px;">
            <div style="font-size:15px;line-height:1.8;color:#ddd8ca;">
              {$rapportHtml}
            </div>
          </td>
        </tr>
        {$afslBlock}

        <!-- Footer -->
        <tr>
          <td style="padding:32px 0 0;text-align:center;">
            <p style="margin:0;font-size:12px;color:#555566;">
              Du modtager denne email fordi du har købt en rapport hos StrategicNumerology.<br>
              &copy; StrategicNumerology — <a href="https://alexandarmartin.dk" style="color:#c9a84c;text-decoration:none;">alexandarmartin.dk</a>
            </p>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}
