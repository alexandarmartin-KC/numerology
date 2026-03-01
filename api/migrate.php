<?php
// Kør dette ÉN gang for at tilføje manglende kolonner
// Åbn i browseren: https://alexandarmartin.dk/api/migrate.php
error_reporting(E_ERROR);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
$db = getDB();
$results = [];

// ─── gratis_beregning: opret tabel hvis den ikke eksisterer ───
$ok = $db->query("
    CREATE TABLE IF NOT EXISTS gratis_beregning (
        id INT PRIMARY KEY,
        positions TEXT DEFAULT NULL,
        tone VARCHAR(50) DEFAULT 'warm',
        length INT DEFAULT 8,
        focus TEXT DEFAULT NULL,
        avoids TEXT DEFAULT NULL,
        customAvoids TEXT DEFAULT NULL,
        extraInstruction TEXT DEFAULT NULL,
        teaserText TEXT DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$results['gratis_beregning.create'] = $ok ? 'OK (oprettet eller eksisterede)' : 'FEJL: ' . $db->error;

// ─── diamant_energies: tilføj manglende kolonner ───
$cols = [
    'keywords_urent_numeroskop' => "ALTER TABLE diamant_energies ADD COLUMN keywords_urent_numeroskop TEXT DEFAULT NULL AFTER keywords",
    'kilde'              => "ALTER TABLE diamant_energies ADD COLUMN kilde TEXT DEFAULT NULL AFTER kendte",
    'helheds_funktion'   => "ALTER TABLE diamant_energies ADD COLUMN helheds_funktion TEXT DEFAULT NULL AFTER kilde",
    'label_col'          => "ALTER TABLE diamant_energies ADD COLUMN label VARCHAR(100) DEFAULT '' AFTER display",
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

// ─── meta_data: tilføj manglende seoImage ───
$check = $db->query("SHOW COLUMNS FROM meta_data LIKE 'seoImage'");
if ($check && $check->num_rows > 0) {
    $results["meta_data.seoImage"] = "Eksisterer allerede";
} else {
    $ok = $db->query("ALTER TABLE meta_data ADD COLUMN seoImage VARCHAR(255) DEFAULT NULL AFTER ogImage");
    $results["meta_data.seoImage"] = $ok ? "TILFØJET" : "FEJL: " . $db->error;
}

// ─── gratis_beregning: omdøb avoid → avoids ───
$check = $db->query("SHOW COLUMNS FROM gratis_beregning LIKE 'avoid'");
if ($check && $check->num_rows > 0) {
    // Kolonne hedder stadig 'avoid' — omdøb til 'avoids'
    $ok = $db->query("ALTER TABLE gratis_beregning CHANGE COLUMN avoid avoids TEXT DEFAULT NULL");
    $results["gratis_beregning.avoid→avoids"] = $ok ? "OMDØBT" : "FEJL: " . $db->error;
} else {
    // Tjek om 'avoids' allerede eksisterer
    $check2 = $db->query("SHOW COLUMNS FROM gratis_beregning LIKE 'avoids'");
    if ($check2 && $check2->num_rows > 0) {
        $results["gratis_beregning.avoids"] = "Eksisterer allerede";
    } else {
        $ok = $db->query("ALTER TABLE gratis_beregning ADD COLUMN avoids TEXT DEFAULT NULL AFTER focus");
        $results["gratis_beregning.avoids"] = $ok ? "TILFØJET" : "FEJL: " . $db->error;
    }
}

// ─── gratis_beregning: tilføj customAvoids ───
$check = $db->query("SHOW COLUMNS FROM gratis_beregning LIKE 'customAvoids'");
if ($check && $check->num_rows > 0) {
    $results["gratis_beregning.customAvoids"] = "Eksisterer allerede";
} else {
    $ok = $db->query("ALTER TABLE gratis_beregning ADD COLUMN customAvoids TEXT DEFAULT NULL AFTER avoids");
    $results["gratis_beregning.customAvoids"] = $ok ? "TILFØJET" : "FEJL: " . $db->error;
}

// ─── gratis_beregning: tilføj teaserText ───
$check = $db->query("SHOW COLUMNS FROM gratis_beregning LIKE 'teaserText'");
if ($check && $check->num_rows > 0) {
    $results["gratis_beregning.teaserText"] = "Eksisterer allerede";
} else {
    $ok = $db->query("ALTER TABLE gratis_beregning ADD COLUMN teaserText TEXT DEFAULT NULL AFTER extraInstruction");
    $results["gratis_beregning.teaserText"] = $ok ? "TILFØJET" : "FEJL: " . $db->error;
}

// ─── Vis nuværende kolonner ───
$colRes = $db->query("SHOW COLUMNS FROM diamant_energies");
$allCols = [];
while ($row = $colRes->fetch_assoc()) $allCols[] = $row['Field'];
$results['diamant_energies_kolonner'] = $allCols;

$colRes = $db->query("SHOW COLUMNS FROM gratis_beregning");
$allCols = [];
while ($row = $colRes->fetch_assoc()) $allCols[] = $row['Field'];
$results['gratis_beregning_kolonner'] = $allCols;

$colRes = $db->query("SHOW COLUMNS FROM meta_data");
$allCols = [];
while ($row = $colRes->fetch_assoc()) $allCols[] = $row['Field'];
$results['meta_data_kolonner'] = $allCols;

echo json_encode(['ok' => true, 'migrations' => $results], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
