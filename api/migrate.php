<?php
// Kør dette ÉN gang for at tilføje manglende kolonner
// Åbn i browseren: https://alexandarmartin.dk/api/migrate.php
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
$db = getDB();
$results = [];

// ─── diamant_energies: tilføj manglende kolonner ───
$cols = [
    'keywords_urent_numeroskop' => "ALTER TABLE diamant_energies ADD COLUMN keywords_urent_numeroskop TEXT DEFAULT NULL AFTER keywords",
    'kilde' => "ALTER TABLE diamant_energies ADD COLUMN kilde TEXT DEFAULT NULL AFTER kendte",
    'label_col' => "ALTER TABLE diamant_energies ADD COLUMN label VARCHAR(100) DEFAULT '' AFTER display",
];

foreach ($cols as $name => $sql) {
    $colName = $name === 'label_col' ? 'label' : $name;
    $check = $db->query("SHOW COLUMNS FROM diamant_energies LIKE '$colName'");
    if ($check && $check->num_rows > 0) {
        $results["diamant_energies.$colName"] = "Eksisterer allerede";
    } else {
        $ok = $db->query($sql);
        $results["diamant_energies.$colName"] = $ok ? "TILFØJET" : "FEJL: " . $db->error;
    }
}

// ─── meta_data: tilføj manglende ogImage ───
$check = $db->query("SHOW COLUMNS FROM meta_data LIKE 'ogImage'");
if ($check && $check->num_rows > 0) {
    $results["meta_data.ogImage"] = "Eksisterer allerede";
} else {
    $ok = $db->query("ALTER TABLE meta_data ADD COLUMN ogImage VARCHAR(255) DEFAULT NULL AFTER keywords");
    $results["meta_data.ogImage"] = $ok ? "TILFØJET" : "FEJL: " . $db->error;
}

// ─── Vis nuværende kolonner ───
$colRes = $db->query("SHOW COLUMNS FROM diamant_energies");
$allCols = [];
while ($row = $colRes->fetch_assoc()) $allCols[] = $row['Field'];
$results['diamant_energies_kolonner'] = $allCols;

echo json_encode(['ok' => true, 'migrations' => $results], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
