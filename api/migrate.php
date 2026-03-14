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
    'summary'            => "ALTER TABLE diamant_energies ADD COLUMN summary TEXT DEFAULT NULL AFTER keywords_urent_numeroskop",
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

// ─── gratis_beregning: tilføj customPrompt ───
$check = $db->query("SHOW COLUMNS FROM gratis_beregning LIKE 'customPrompt'");
if ($check && $check->num_rows > 0) {
    $results["gratis_beregning.customPrompt"] = "Eksisterer allerede";
} else {
    $ok = $db->query("ALTER TABLE gratis_beregning ADD COLUMN customPrompt TEXT DEFAULT NULL AFTER id");
    $results["gratis_beregning.customPrompt"] = $ok ? "TILFØJET" : "FEJL: " . $db->error;
}

// ─── content_generated: indholdsgenerator ───
$ok = $db->query("
    CREATE TABLE IF NOT EXISTS content_generated (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        title        VARCHAR(255)  NOT NULL,
        type         ENUM('artikel','nyhedsbrev','social') NOT NULL DEFAULT 'artikel',
        topic        VARCHAR(255)  DEFAULT NULL,
        keywords     TEXT          DEFAULT NULL,
        body         LONGTEXT      DEFAULT NULL,
        image_url    VARCHAR(500)  DEFAULT NULL,
        data_source  ENUM('db','general') DEFAULT 'db',
        created_at   DATETIME      DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$results['content_generated.create'] = $ok ? 'OK (oprettet eller eksisterede)' : 'FEJL: ' . $db->error;

// ─── gratis_rate_limits: rate limiting tabel ───
$ok = $db->query("
    CREATE TABLE IF NOT EXISTS gratis_rate_limits (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        ip         VARCHAR(45)     NOT NULL,
        created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ip_created (ip, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$results['gratis_rate_limits.create'] = $ok ? 'OK (oprettet eller eksisterede)' : 'FEJL: ' . $db->error;

// ─── orders: betalte ordrer + rapport-historik ───
$ok = $db->query("
    CREATE TABLE IF NOT EXISTS orders (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        stripe_session_id VARCHAR(255)  NOT NULL UNIQUE,
        full_name         VARCHAR(255)  NOT NULL,
        birth_date        DATE          NOT NULL,
        email             VARCHAR(255)  NOT NULL,
        plan              ENUM('foundation','direction','activation') NOT NULL DEFAULT 'foundation',
        status            ENUM('pending','generating','done','failed','sent') NOT NULL DEFAULT 'pending',
        rapport_html      LONGTEXT      DEFAULT NULL,
        error_msg         TEXT          DEFAULT NULL,
        created_at        DATETIME      DEFAULT CURRENT_TIMESTAMP,
        sent_at           DATETIME      DEFAULT NULL,
        INDEX idx_status (status),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$results['orders.create'] = $ok ? 'OK (oprettet eller eksisterede)' : 'FEJL: ' . $db->error;

// ─── diamant_energies: tilføj enabled (on/off til compound-numbers side) ───
$check = $db->query("SHOW COLUMNS FROM diamant_energies LIKE 'enabled'");
if ($check && $check->num_rows > 0) {
    $results["diamant_energies.enabled"] = "Eksisterer allerede";
} else {
    $ok = $db->query("ALTER TABLE diamant_energies ADD COLUMN enabled TINYINT(1) NOT NULL DEFAULT 1");
    $results["diamant_energies.enabled"] = $ok ? "TILFØJET" : "FEJL: " . $db->error;
}

// ─── generelt: tilføj compound_intro (indledning på compound-numbers siden) ───
$check = $db->query("SHOW COLUMNS FROM generelt LIKE 'compound_intro'");
if ($check && $check->num_rows > 0) {
    $results["generelt.compound_intro"] = "Eksisterer allerede";
} else {
    $ok = $db->query("ALTER TABLE generelt ADD COLUMN compound_intro LONGTEXT DEFAULT NULL");
    $results["generelt.compound_intro"] = $ok ? "TILFØJET" : "FEJL: " . $db->error;
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

// ─── generelt: tilføj rapportAfslutning ───
$check = $db->query("SHOW COLUMNS FROM generelt LIKE 'rapportAfslutning'");
if ($check && $check->num_rows > 0) {
    $results["generelt.rapportAfslutning"] = "Eksisterer allerede";
} else {
    $ok = $db->query("ALTER TABLE generelt ADD COLUMN rapportAfslutning LONGTEXT DEFAULT NULL");
    $results["generelt.rapportAfslutning"] = $ok ? "TILF\u00d8JET" : "FEJL: " . $db->error;
}

// ─── generelt: tilføj rapportOmNumerologi ───
$check = $db->query("SHOW COLUMNS FROM generelt LIKE 'rapportOmNumerologi'");
if ($check && $check->num_rows > 0) {
    $results["generelt.rapportOmNumerologi"] = "Eksisterer allerede";
} else {
    $ok = $db->query("ALTER TABLE generelt ADD COLUMN rapportOmNumerologi LONGTEXT DEFAULT NULL");
    $results["generelt.rapportOmNumerologi"] = $ok ? "TILFØJET" : "FEJL: " . $db->error;
}

// ─── generelt: tilføj rapportOmDiamanten ───
$check = $db->query("SHOW COLUMNS FROM generelt LIKE 'rapportOmDiamanten'");
if ($check && $check->num_rows > 0) {
    $results["generelt.rapportOmDiamanten"] = "Eksisterer allerede";
} else {
    $ok = $db->query("ALTER TABLE generelt ADD COLUMN rapportOmDiamanten LONGTEXT DEFAULT NULL");
    $results["generelt.rapportOmDiamanten"] = $ok ? "TILFØJET" : "FEJL: " . $db->error;
}

$colRes = $db->query("SHOW COLUMNS FROM content_generated");
$allCols = [];
while ($row = $colRes->fetch_assoc()) $allCols[] = $row['Field'];
$results['content_generated_kolonner'] = $allCols;

// ─── aarstalsraekker_energies: konverter 'tal' til VARCHAR og compound-format ───
// Problemet: hvis 'tal' er INT, gemmes "10/1" som 10 og JS kan ikke finde den
$aarColCheck = $db->query("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aarstalsraekker_energies' AND COLUMN_NAME='tal'");
if ($aarColCheck && $aarRow = $aarColCheck->fetch_assoc()) {
    $dataType = strtolower($aarRow['DATA_TYPE']);
    if (in_array($dataType, ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'])) {
        $ok = $db->query("ALTER TABLE aarstalsraekker_energies MODIFY COLUMN tal VARCHAR(10) NOT NULL DEFAULT ''");
        $results['aarstalsraekker_energies.tal_varchar'] = $ok ? 'KONVERTERET til VARCHAR(10)' : 'FEJL: ' . $db->error;
    } else {
        $results['aarstalsraekker_energies.tal_varchar'] = "Allerede $dataType — ingen ændring nødvendig";
    }
    // Opdater rækker der bruger rent numerisk nøgle (fx "10") til compound-format (fx "10/1")
    $updated = 0;
    for ($i = 10; $i <= 31; $i++) {
        $r = $i;
        while ($r > 9) {
            $s = (string)$r; $r = 0;
            for ($j = 0; $j < strlen($s); $j++) $r += (int)$s[$j];
        }
        $compound = $i . '/' . $r;
        $numStr   = (string)$i;
        // Tjek om der allerede eksisterer en række med compound-format
        $exists = $db->query("SELECT id FROM aarstalsraekker_energies WHERE tal='" . $db->real_escape_string($compound) . "' LIMIT 1");
        if ($exists && $exists->num_rows > 0) {
            // Compound-format eksisterer allerede — slet evt. numerisk dublet
            $db->query("DELETE FROM aarstalsraekker_energies WHERE tal='" . $db->real_escape_string($numStr) . "'");
        } else {
            // Ingen compound-række — konverter numerisk til compound hvis den findes
            $plain = $db->query("SELECT id FROM aarstalsraekker_energies WHERE tal='" . $db->real_escape_string($numStr) . "' LIMIT 1");
            if ($plain && $plain->num_rows > 0) {
                $db->query("UPDATE aarstalsraekker_energies SET tal='" . $db->real_escape_string($compound) . "' WHERE tal='" . $db->real_escape_string($numStr) . "'");
                $updated++;
            }
        }
    }
    $results['aarstalsraekker_energies.tal_update'] = "$updated rækker opdateret til compound-format (fx 10→10/1)";
} else {
    $results['aarstalsraekker_energies.tal'] = "Tabel/kolonne 'aarstalsraekker_energies.tal' ikke fundet";
}

echo json_encode(['ok' => true, 'migrations' => $results], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
