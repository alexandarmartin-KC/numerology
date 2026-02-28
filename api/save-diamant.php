<?php
require_once __DIR__ . '/db.php';

$type = $_GET['type'] ?? '';
$db = getDB();

// ─── GET ───
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($type === 'energies') {
        $res = $db->query('SELECT *, billede_url AS billede FROM diamant_energies ORDER BY id ASC');
        jsonOut(mysqli_fetch_all($res, MYSQLI_ASSOC));
    }
    if ($type === 'positions') {
        $res = $db->query('SELECT * FROM diamant_positions ORDER BY id ASC');
        jsonOut(mysqli_fetch_all($res, MYSQLI_ASSOC));
    }
    if ($type === 'rules') {
        $res = $db->query('SELECT * FROM diamant_rules ORDER BY id ASC');
        jsonOut(mysqli_fetch_all($res, MYSQLI_ASSOC));
    }
    jsonOut(['error' => 'Ukendt type. Brug: energies, positions eller rules'], 400);
}

// ─── POST ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = getBody();

    if ($type === 'energies') {
        if (!is_array($body)) jsonOut(['error' => 'Array forventet'], 400);
        $stmt_upd = $db->prepare('UPDATE diamant_energies SET reduced=?,keywords=?,grundenergi=?,ubalanceret_keywords=?,beskrivelse=?,planet=?,kendte=?,helheds_funktion=?,billede_url=? WHERE display=?');
        $stmt_ins = $db->prepare('INSERT INTO diamant_energies (display,reduced,keywords,grundenergi,ubalanceret_keywords,beskrivelse,planet,kendte,helheds_funktion,billede_url) VALUES (?,?,?,?,?,?,?,?,?,?)');
        foreach ($body as $e) {
            $display = $e['display'] ?? '';
            $r = $db->query("SELECT id FROM diamant_energies WHERE display='" . $db->real_escape_string($display) . "'");
            $billede = $e['billede'] ?? $e['billede_url'] ?? null;
            if ($r && $r->num_rows > 0) {
                $stmt_upd->bind_param('ssssssssss',
                    $e['reduced'],$e['keywords'],$e['grundenergi'],$e['ubalanceret_keywords'],
                    $e['beskrivelse'],$e['planet'],$e['kendte'],$e['helheds_funktion'],$billede,$display);
                $stmt_upd->execute();
            } else {
                $stmt_ins->bind_param('ssssssssss',
                    $display,$e['reduced'],$e['keywords'],$e['grundenergi'],$e['ubalanceret_keywords'],
                    $e['beskrivelse'],$e['planet'],$e['kendte'],$e['helheds_funktion'],$billede);
                $stmt_ins->execute();
            }
        }
        jsonOut(['ok' => true]);
    }

    if ($type === 'positions') {
        if (!is_array($body)) jsonOut(['error' => 'Array forventet'], 400);
        $db->query('DELETE FROM diamant_positions');
        $stmt = $db->prepare('INSERT INTO diamant_positions (name, description) VALUES (?, ?)');
        foreach ($body as $p) {
            $stmt->bind_param('ss', $p['name'], $p['description']);
            $stmt->execute();
        }
        jsonOut(['ok' => true]);
    }

    if ($type === 'rules') {
        if (!is_array($body)) jsonOut(['error' => 'Array forventet'], 400);
        $db->query('DELETE FROM diamant_rules');
        $stmt = $db->prepare('INSERT INTO diamant_rules (`condition`, description) VALUES (?, ?)');
        foreach ($body as $r) {
            $stmt->bind_param('ss', $r['condition'], $r['description']);
            $stmt->execute();
        }
        jsonOut(['ok' => true]);
    }

    jsonOut(['error' => 'Ukendt type'], 400);
}

jsonOut(['error' => 'Method not allowed'], 405);
