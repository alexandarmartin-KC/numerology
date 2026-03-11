<?php
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonOut(['error' => 'Method not allowed'], 405);

$db = getDB();

// Hent alt parallelt (mysqli er synkront, men vi laver én query pr. tabel)
$rows = [];
$queries = [
    'energies'        => 'SELECT *, billede_url AS billede FROM diamant_energies ORDER BY id ASC',
    'positions'       => 'SELECT * FROM diamant_positions ORDER BY id ASC',
    'diamantRules'    => 'SELECT * FROM diamant_rules ORDER BY id ASC',
    'aarEnergies'     => 'SELECT * FROM aarstalsraekker_energies ORDER BY tal ASC',
    'cycles'          => 'SELECT * FROM aarstalsraekker_cycles ORDER BY id ASC',
    'aarRules'        => 'SELECT * FROM aarstalsraekker_rules ORDER BY id ASC',
    'generelt'        => 'SELECT * FROM generelt WHERE id = 1',
    'rapportSections' => 'SELECT * FROM rapport_sections ORDER BY id ASC',
    'navneRecipes'    => 'SELECT * FROM navnegenerator_recipes ORDER BY grundenergi ASC',
    'gratis'          => 'SELECT * FROM gratis_beregning WHERE id = 1',
    'meta'            => 'SELECT * FROM meta_data ORDER BY page ASC',
];

foreach ($queries as $key => $sql) {
    $res = $db->query($sql);
    $rows[$key] = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
}

$g = $rows['generelt'][0] ?? [];

// Byg cyklus-objekt
$cyclesObj = [];
foreach ($rows['cycles'] as $c) {
    $cyclesObj[$c['name']] = ['description' => $c['description'], 'style' => $c['style']];
}

// Byg aarEnergies indekseret på tal
$aarEnergiesObj = [];
foreach ($rows['aarEnergies'] as $e) {
    $aarEnergiesObj[$e['tal']] = ['keywords' => $e['keywords'], 'beskrivelse' => $e['beskrivelse']];
}

// Rapport sektioner med parsed sources
$parsedSections = array_map(function($s) {
    $s['sources'] = json_decode($s['sources'] ?? '[]', true) ?: [];
    return $s;
}, $rows['rapportSections']);

// Gratis beregning
$gratis = $rows['gratis'][0] ?? [];
if ($gratis) {
    $gratis['positions']    = json_decode($gratis['positions'] ?? '[]', true) ?: [];
    $gratis['focus']        = json_decode($gratis['focus'] ?? '[]', true) ?: [];
    $gratis['avoids']       = json_decode($gratis['avoids'] ?? '[]', true) ?: [];
    $gratis['customAvoids'] = json_decode($gratis['customAvoids'] ?? '[]', true) ?: [];
    if (isset($gratis['length'])) $gratis['length'] = (int)$gratis['length'];
    // Fjern DB-only felter der forvirrer JS
    unset($gratis['id']);
}

$knowledge = [
    // Generelt
    'aboutNumerology'          => $g['aboutNumerology'] ?? '',
    'defRent'                  => $g['defRent'] ?? '',
    'defUrent'                 => $g['defUrent'] ?? '',
    'blokkeAfTal'              => $g['blokkeAfTal'] ?? '',
    'diamantAar'               => $g['diamantAarstalsraekker'] ?? '',
    'udrensning'               => $g['udrensning'] ?? '',
    'numerologiAlder'          => $g['numerologiAlder'] ?? '',
    'rapportStil'              => $g['rapportStil'] ?? '',
    'eksempelRapport'          => $g['eksempelRapport'] ?? '',

    // Rapport
    'rapportGlobalInstruction' => $g['rapportGlobalInstruction'] ?? '',
    'rapportOmNumerologi'      => $g['rapportOmNumerologi'] ?? '',
    'rapportOmDiamanten'       => $g['rapportOmDiamanten'] ?? '',
    'rapportSections'          => $parsedSections,

    // Diamant
    'energies'                 => $rows['energies'],
    'positions'                => $rows['positions'],
    'diamondRules'             => array_map(fn($r) => ['condition' => $r['condition'], 'description' => $r['description']], $rows['diamantRules']),

    // Årstalsrækker
    'aarEnergies'              => $aarEnergiesObj,
    'cycles_about'             => $g['aboutCycles'] ?? '',
    'cycles_style'             => $g['cycleStyle'] ?? '',
    'cycles_124875'            => $cyclesObj['1-2-4-8-7-5']['description'] ?? '',
    'cycles_36'                => $cyclesObj['3-6']['description'] ?? '',
    'cycles_9'                 => $cyclesObj['9']['description'] ?? '',
    'aarRules'                 => array_map(fn($r) => ['condition' => $r['condition'], 'description' => $r['description']], $rows['aarRules']),

    // Astrologi
    'astrologyGenerelt'        => $g['astrologyGenerelt'] ?? '',
    'astrologySign'            => !empty($g['astrologySignName']) ? ['name' => $g['astrologySignName'], 'text' => $g['astrologySignText'] ?? ''] : null,

    // Navnegenerator
    'navnegeneratorPrincipper' => $g['navnegeneratorPrincipper'] ?? '',
    'navneRecipes'             => $rows['navneRecipes'],

    // Gratis beregning
    'gratisBeregning'          => $gratis ?: new stdClass(),

    // Meta
    'meta'                     => $rows['meta'],
];

jsonOut($knowledge);
