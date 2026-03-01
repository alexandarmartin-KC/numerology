<?php
require_once __DIR__ . '/db.php';

$db = getDB();

// Tøm alle tabeller
$tables = [
    'diamant_energies',
    'diamant_positions', 
    'diamant_rules',
    'aar_energies',
    'aar_cycles',
    'aar_rules',
    'aar_general',
    'generelt',
    'rapport_sections',
    'navnegenerator_recipes',
    'navnegenerator',
    'gratisberegning',
    'meta_pages'
];

$results = [];
foreach ($tables as $t) {
    $ok = $db->query("DELETE FROM `$t`");
    $results[$t] = $ok ? 'OK' : $db->error;
}

jsonOut(['cleared' => $results]);
