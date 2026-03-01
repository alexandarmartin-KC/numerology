<?php
require_once __DIR__ . '/db.php';

$db = getDB();

// ─── GET ───
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $res = $db->query('SELECT * FROM gratis_beregning WHERE id = 1');
    if ($res->num_rows === 0) { jsonOut(new stdClass()); }
    $row = $res->fetch_assoc();
    $row['positions']    = json_decode($row['positions'] ?? '[]', true) ?: [];
    $row['focus']        = json_decode($row['focus'] ?? '[]', true) ?: [];
    $row['avoids']       = json_decode($row['avoids'] ?? '[]', true) ?: [];
    $row['customAvoids'] = json_decode($row['customAvoids'] ?? '[]', true) ?: [];
    // Cast length to int so JS gets a number
    if (isset($row['length'])) $row['length'] = (int)$row['length'];
    jsonOut($row);
}

// ─── POST ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdminKey();
    $b = getBody();
    $positions        = json_encode($b['positions'] ?? [], JSON_UNESCAPED_UNICODE);
    $tone             = $b['tone'] ?? null;
    $length           = $b['length'] ?? null;
    $focus            = json_encode($b['focus'] ?? [], JSON_UNESCAPED_UNICODE);
    $avoids           = json_encode($b['avoids'] ?? [], JSON_UNESCAPED_UNICODE);
    $customAvoids     = json_encode($b['customAvoids'] ?? [], JSON_UNESCAPED_UNICODE);
    $extraInstruction = $b['extraInstruction'] ?? null;
    $teaserText       = $b['teaserText'] ?? null;

    $r = $db->query('SELECT id FROM gratis_beregning WHERE id = 1');
    if ($r && $r->num_rows > 0) {
        $stmt = $db->prepare('UPDATE gratis_beregning SET positions=?,tone=?,length=?,focus=?,avoids=?,customAvoids=?,extraInstruction=?,teaserText=? WHERE id=1');
        $stmt->bind_param('ssssssss', $positions, $tone, $length, $focus, $avoids, $customAvoids, $extraInstruction, $teaserText);
    } else {
        $stmt = $db->prepare('INSERT INTO gratis_beregning (id,positions,tone,length,focus,avoids,customAvoids,extraInstruction,teaserText) VALUES (1,?,?,?,?,?,?,?,?)');
        $stmt->bind_param('ssssssss', $positions, $tone, $length, $focus, $avoids, $customAvoids, $extraInstruction, $teaserText);
    }
    $stmt->execute();
    jsonOut(['ok' => true]);
}

jsonOut(['error' => 'Method not allowed'], 405);
