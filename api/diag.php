<?php
// Diagnostik-endpoint – tester DB-forbindelse, tabeller og CRUD
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$result = ['ok' => true, 'tests' => []];

// 1. DB forbindelse
try {
    $db = getDB();
    $result['tests']['connection'] = 'OK – ' . $db->server_info;
} catch (Exception $ex) {
    $result['ok'] = false;
    $result['tests']['connection'] = 'FEJL: ' . $ex->getMessage();
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 2. Tabeller eksisterer?
$tables = [
    'diamant_energies', 'diamant_positions', 'diamant_rules',
    'generelt', 'aarstalsraekker_energies', 'aarstalsraekker_cycles',
    'aarstalsraekker_rules', 'meta_data', 'navnegenerator_recipes',
    'gratis_beregning', 'rapport_sections'
];
foreach ($tables as $t) {
    $r = $db->query("SHOW TABLES LIKE '$t'");
    $exists = $r && $r->num_rows > 0;
    $count = 0;
    $cols = [];
    if ($exists) {
        $cr = $db->query("SELECT COUNT(*) AS cnt FROM `$t`");
        $count = $cr ? $cr->fetch_assoc()['cnt'] : '?';
        $colR = $db->query("SHOW COLUMNS FROM `$t`");
        while ($colR && $row = $colR->fetch_assoc()) {
            $cols[] = $row['Field'] . ' (' . $row['Type'] . ')';
        }
    }
    $result['tests']["table_$t"] = [
        'exists' => $exists,
        'rows' => $count,
        'columns' => $cols
    ];
}

// 3. Test write + read for diamant_energies
try {
    $testDisplay = '__DIAG_TEST__';
    $db->query("DELETE FROM diamant_energies WHERE display='$testDisplay'");
    $stmt = $db->prepare("INSERT INTO diamant_energies (display, reduced, keywords) VALUES (?, ?, ?)");
    $r = 0; $kw = 'diagnostik_test';
    $stmt->bind_param('sis', $testDisplay, $r, $kw);
    $ok = $stmt->execute();
    if (!$ok) {
        $result['tests']['write'] = 'FEJL: ' . $stmt->error;
    } else {
        // Read back
        $res = $db->query("SELECT * FROM diamant_energies WHERE display='$testDisplay'");
        $row = $res ? $res->fetch_assoc() : null;
        $result['tests']['write'] = 'OK – inserted';
        $result['tests']['read'] = $row ? 'OK – read back: keywords=' . $row['keywords'] : 'FEJL: kan ikke læse tilbage';
        // Cleanup
        $db->query("DELETE FROM diamant_energies WHERE display='$testDisplay'");
        $result['tests']['cleanup'] = 'OK – slettet';
    }
} catch (Exception $ex) {
    $result['tests']['write'] = 'EXCEPTION: ' . $ex->getMessage();
}

// 4. Vis sample data (first 3 rows from diamant_energies)
$res = $db->query("SELECT * FROM diamant_energies ORDER BY id ASC LIMIT 3");
$sample = [];
if ($res) {
    while ($row = $res->fetch_assoc()) $sample[] = $row;
}
$result['tests']['sample_energies'] = $sample;

// 5. PHP error reporting info
$result['php'] = [
    'version' => PHP_VERSION,
    'error_reporting' => error_reporting(),
    'display_errors' => ini_get('display_errors')
];

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
