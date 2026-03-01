<?php
require_once __DIR__ . '/db.php';

$type = $_GET['type'] ?? '';
$db = getDB();

// ─── GET ───
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($type === 'energies') {
        $res = $db->query('SELECT * FROM aarstalsraekker_energies ORDER BY tal ASC');
        jsonOut(mysqli_fetch_all($res, MYSQLI_ASSOC));
    }
    if ($type === 'cycles') {
        $res = $db->query('SELECT * FROM aarstalsraekker_cycles ORDER BY id ASC');
        jsonOut(mysqli_fetch_all($res, MYSQLI_ASSOC));
    }
    if ($type === 'rules') {
        $res = $db->query('SELECT * FROM aarstalsraekker_rules ORDER BY id ASC');
        jsonOut(mysqli_fetch_all($res, MYSQLI_ASSOC));
    }
    if ($type === 'general') {
        $res = $db->query('SELECT aboutCycles, cycleStyle FROM generelt WHERE id = 1');
        jsonOut($res->num_rows ? $res->fetch_assoc() : new stdClass());
    }
    jsonOut(['error' => 'Ukendt type'], 400);
}

// ─── POST ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdminKey();
    $body = getBody();

    if ($type === 'energies') {
        if (!is_array($body)) jsonOut(['error' => 'Array forventet'], 400);
        $stmt_upd = $db->prepare('UPDATE aarstalsraekker_energies SET keywords=?, beskrivelse=? WHERE tal=?');
        $stmt_ins = $db->prepare('INSERT INTO aarstalsraekker_energies (tal, keywords, beskrivelse) VALUES (?, ?, ?)');
        foreach ($body as $e) {
            $r = $db->query("SELECT id FROM aarstalsraekker_energies WHERE tal='" . $db->real_escape_string($e['tal'] ?? '') . "'");
            if ($r && $r->num_rows > 0) {
                $stmt_upd->bind_param('sss', $e['keywords'], $e['beskrivelse'], $e['tal']);
                $stmt_upd->execute();
            } else {
                $stmt_ins->bind_param('sss', $e['tal'], $e['keywords'], $e['beskrivelse']);
                $stmt_ins->execute();
            }
        }
        jsonOut(['ok' => true]);
    }

    if ($type === 'cycles') {
        if (!is_array($body)) jsonOut(['error' => 'Array forventet'], 400);
        $db->query('DELETE FROM aarstalsraekker_cycles');
        $stmt = $db->prepare('INSERT INTO aarstalsraekker_cycles (name, description, style) VALUES (?, ?, ?)');
        foreach ($body as $c) {
            $stmt->bind_param('sss', $c['name'], $c['description'], $c['style']);
            $stmt->execute();
        }
        jsonOut(['ok' => true]);
    }

    if ($type === 'rules') {
        if (!is_array($body)) jsonOut(['error' => 'Array forventet'], 400);
        $db->query('DELETE FROM aarstalsraekker_rules');
        $stmt = $db->prepare('INSERT INTO aarstalsraekker_rules (`condition`, description) VALUES (?, ?)');
        foreach ($body as $r) {
            $stmt->bind_param('ss', $r['condition'], $r['description']);
            $stmt->execute();
        }
        jsonOut(['ok' => true]);
    }

    if ($type === 'general') {
        $aboutCycles = $body['aboutCycles'] ?? null;
        $cycleStyle  = $body['cycleStyle'] ?? null;
        $r = $db->query('SELECT id FROM generelt WHERE id = 1');
        if ($r && $r->num_rows > 0) {
            $stmt = $db->prepare('UPDATE generelt SET aboutCycles=?, cycleStyle=? WHERE id=1');
            $stmt->bind_param('ss', $aboutCycles, $cycleStyle);
        } else {
            $stmt = $db->prepare('INSERT INTO generelt (id, aboutCycles, cycleStyle) VALUES (1, ?, ?)');
            $stmt->bind_param('ss', $aboutCycles, $cycleStyle);
        }
        $stmt->execute();
        jsonOut(['ok' => true]);
    }

    jsonOut(['error' => 'Ukendt type'], 400);
}

jsonOut(['error' => 'Method not allowed'], 405);
