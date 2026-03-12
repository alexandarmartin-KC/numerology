<?php
require_once __DIR__ . '/db.php';

$db = getDB();

// ─── GET: hent intro + alle sammensatte tal ───
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    requireAdminKey();

    $g = $db->query("SELECT compound_intro FROM generelt WHERE id = 1");
    $intro = '';
    if ($g && $row = $g->fetch_assoc()) {
        $intro = $row['compound_intro'] ?? '';
    }

    $res = $db->query("
        SELECT display, label, beskrivelse, enabled
        FROM diamant_energies
        WHERE display LIKE '%/%'
        ORDER BY CAST(SUBSTRING_INDEX(display, '/', 1) AS UNSIGNED) ASC
    ");
    $numbers = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];

    jsonOut(['intro' => $intro, 'numbers' => $numbers]);
}

// ─── POST: gem intro + opdater beskrivelse + enabled pr. tal ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdminKey();
    $body = getBody();

    // Gem intro
    $intro = $body['intro'] ?? '';
    $stmt = $db->prepare("UPDATE generelt SET compound_intro = ? WHERE id = 1");
    if (!$stmt) jsonOut(['error' => 'Prepare fejl: ' . $db->error], 500);
    $stmt->bind_param('s', $intro);
    $stmt->execute();
    $stmt->close();

    // Opdater hvert tal
    $numbers = $body['numbers'] ?? [];
    if (!is_array($numbers)) jsonOut(['error' => 'numbers skal være et array'], 400);

    $stmt = $db->prepare("UPDATE diamant_energies SET beskrivelse = ?, enabled = ? WHERE display = ?");
    if (!$stmt) jsonOut(['error' => 'Prepare fejl: ' . $db->error], 500);

    foreach ($numbers as $n) {
        $display    = $n['display'] ?? '';
        $beskrivelse = $n['beskrivelse'] ?? '';
        $enabled    = isset($n['enabled']) ? (int)(bool)$n['enabled'] : 1;
        if ($display === '') continue;
        $stmt->bind_param('sis', $beskrivelse, $enabled, $display);
        $stmt->execute();
    }
    $stmt->close();

    jsonOut(['ok' => true]);
}

// OPTIONS pre-flight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonOut(['ok' => true]);
}

jsonOut(['error' => 'Method not allowed'], 405);
