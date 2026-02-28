<?php
require_once __DIR__ . '/db.php';

$db = getDB();

// ─── GET ───
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $res = $db->query('SELECT * FROM gratis_beregning WHERE id = 1');
    if ($res->num_rows === 0) { jsonOut(new stdClass()); }
    $row = $res->fetch_assoc();
    $row['positions'] = json_decode($row['positions'] ?? '[]', true) ?: [];
    $row['focus']     = json_decode($row['focus'] ?? '[]', true) ?: [];
    jsonOut($row);
}

// ─── POST ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = getBody();
    $positions        = json_encode($b['positions'] ?? [], JSON_UNESCAPED_UNICODE);
    $tone             = $b['tone'] ?? null;
    $length           = $b['length'] ?? null;
    $focus            = json_encode($b['focus'] ?? [], JSON_UNESCAPED_UNICODE);
    $avoid            = $b['avoid'] ?? null;
    $extraInstruction = $b['extraInstruction'] ?? null;

    $r = $db->query('SELECT id FROM gratis_beregning WHERE id = 1');
    if ($r && $r->num_rows > 0) {
        $stmt = $db->prepare('UPDATE gratis_beregning SET positions=?,tone=?,length=?,focus=?,avoid=?,extraInstruction=? WHERE id=1');
        $stmt->bind_param('ssssss', $positions, $tone, $length, $focus, $avoid, $extraInstruction);
    } else {
        $stmt = $db->prepare('INSERT INTO gratis_beregning (id,positions,tone,length,focus,avoid,extraInstruction) VALUES (1,?,?,?,?,?,?)');
        $stmt->bind_param('ssssss', $positions, $tone, $length, $focus, $avoid, $extraInstruction);
    }
    $stmt->execute();
    jsonOut(['ok' => true]);
}

jsonOut(['error' => 'Method not allowed'], 405);
