<?php
require_once __DIR__ . '/db.php';

// Undertrykker warnings i output (kan ødelægge JSON)
error_reporting(E_ERROR);

$type = $_GET['type'] ?? '';
$db = getDB();

// ─── Helper: hent felt fra energi-array med fallback ───
function ef(array $e, string $key, string $default = ''): string {
    return $e[$key] ?? $default;
}

// ─── GET ───
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($type === 'energies') {
        $res = $db->query('
            SELECT id, display, label, reduced,
                   keywords, keywords_urent_numeroskop,
                   grundenergi, beskrivelse,
                   ubalanceret_keywords AS ubalance_i_urent_numeroskop,
                   helheds_funktion, planet, kendte, kilde,
                   billede_url AS billede
            FROM diamant_energies ORDER BY id ASC
        ');
        if (!$res) jsonOut(['error' => 'Query fejl: ' . $db->error], 500);
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

        $stmt_upd = $db->prepare('UPDATE diamant_energies SET
            label=?, reduced=?, keywords=?, keywords_urent_numeroskop=?,
            grundenergi=?, ubalanceret_keywords=?, beskrivelse=?,
            planet=?, kendte=?, kilde=?, helheds_funktion=?, billede_url=?
            WHERE display=?');
        $stmt_ins = $db->prepare('INSERT INTO diamant_energies
            (display, label, reduced, keywords, keywords_urent_numeroskop,
             grundenergi, ubalanceret_keywords, beskrivelse,
             planet, kendte, kilde, helheds_funktion, billede_url)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');

        if (!$stmt_upd || !$stmt_ins) {
            jsonOut(['error' => 'Prepare fejl: ' . $db->error], 500);
        }

        $errors = [];
        foreach ($body as $i => $e) {
            $display = ef($e, 'display');
            if ($display === '') continue;

            $label        = ef($e, 'label', $display);
            $reduced      = ef($e, 'reduced', '0');
            $keywords     = ef($e, 'keywords');
            $kw_urent     = ef($e, 'keywords_urent_numeroskop');
            $grundenergi  = ef($e, 'grundenergi');
            $ubalanceret  = ef($e, 'ubalance_i_urent_numeroskop', ef($e, 'ubalanceret_keywords'));
            $beskrivelse  = ef($e, 'beskrivelse');
            $planet       = ef($e, 'planet');
            $kendte       = ef($e, 'kendte');
            $kilde        = ef($e, 'kilde');
            $helheds      = ef($e, 'helheds_funktion');
            $billede      = ef($e, 'billede', ef($e, 'billede_url'));

            $r = $db->query("SELECT id FROM diamant_energies WHERE display='" . $db->real_escape_string($display) . "'");
            if ($r && $r->num_rows > 0) {
                $stmt_upd->bind_param('sssssssssssss',
                    $label, $reduced, $keywords, $kw_urent,
                    $grundenergi, $ubalanceret, $beskrivelse,
                    $planet, $kendte, $kilde, $helheds, $billede, $display);
                if (!$stmt_upd->execute()) $errors[] = "UPD $display: " . $stmt_upd->error;
            } else {
                $stmt_ins->bind_param('sssssssssssss',
                    $display, $label, $reduced, $keywords, $kw_urent,
                    $grundenergi, $ubalanceret, $beskrivelse,
                    $planet, $kendte, $kilde, $helheds, $billede);
                if (!$stmt_ins->execute()) $errors[] = "INS $display: " . $stmt_ins->error;
            }
        }
        if ($errors) {
            jsonOut(['ok' => false, 'errors' => $errors], 500);
        }
        jsonOut(['ok' => true, 'saved' => count($body)]);
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
