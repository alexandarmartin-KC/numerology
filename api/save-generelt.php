<?php
require_once __DIR__ . '/db.php';

$db = getDB();

// ─── GET ───
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $res = $db->query('SELECT * FROM generelt WHERE id = 1');
    jsonOut($res->num_rows ? $res->fetch_assoc() : new stdClass());
}

// ─── POST ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = getBody();
    $fields = [
        'aboutNumerology','defRent','defUrent','blokkeAfTal',
        'diamantAarstalsraekker','udrensning','numerologiAlder',
        'rapportStil','eksempelRapport',
        'astrologyGenerelt','astrologySignName','astrologySignText'
    ];
    $r = $db->query('SELECT id FROM generelt WHERE id = 1');
    if ($r && $r->num_rows > 0) {
        $sets = implode(', ', array_map(fn($f) => "`$f`=?", $fields));
        $stmt = $db->prepare("UPDATE generelt SET $sets WHERE id=1");
        $types = str_repeat('s', count($fields));
        $vals = array_map(fn($f) => $b[$f] ?? null, $fields);
        $stmt->bind_param($types, ...$vals);
    } else {
        $cols = implode(', ', array_map(fn($f) => "`$f`", $fields));
        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $stmt = $db->prepare("INSERT INTO generelt (id, $cols) VALUES (1, $placeholders)");
        $types = str_repeat('s', count($fields));
        $vals = array_map(fn($f) => $b[$f] ?? null, $fields);
        $stmt->bind_param($types, ...$vals);
    }
    $stmt->execute();
    jsonOut(['ok' => true]);
}

jsonOut(['error' => 'Method not allowed'], 405);
