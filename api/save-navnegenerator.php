<?php
require_once __DIR__ . '/db.php';

$db = getDB();

// ─── GET ───
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $res = $db->query('SELECT * FROM navnegenerator_recipes ORDER BY grundenergi ASC');
    $recipes = mysqli_fetch_all($res, MYSQLI_ASSOC);
    $genRes = $db->query('SELECT navnegeneratorPrincipper FROM generelt WHERE id = 1');
    $gen = $genRes->num_rows ? $genRes->fetch_assoc() : [];
    jsonOut([
        'principper' => $gen['navnegeneratorPrincipper'] ?? '',
        'recipes' => $recipes
    ]);
}

// ─── POST ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = getBody();
    $principper = $b['principper'] ?? null;
    $recipes = $b['recipes'] ?? [];

    // Gem principper
    $r = $db->query('SELECT id FROM generelt WHERE id = 1');
    if ($r && $r->num_rows > 0) {
        $stmt = $db->prepare('UPDATE generelt SET navnegeneratorPrincipper=? WHERE id=1');
        $stmt->bind_param('s', $principper);
    } else {
        $stmt = $db->prepare('INSERT INTO generelt (id, navnegeneratorPrincipper) VALUES (1, ?)');
        $stmt->bind_param('s', $principper);
    }
    $stmt->execute();

    // Gem opskrifter
    if (is_array($recipes)) {
        $db->query('DELETE FROM navnegenerator_recipes');
        $stmt2 = $db->prepare('INSERT INTO navnegenerator_recipes (grundenergi,fornavn,mellemnavn1,mellemnavn2,mellemnavn3,mellemnavn4,mellemnavn5,efternavn,principper) VALUES (?,?,?,?,?,?,?,?,?)');
        foreach ($recipes as $r) {
            $stmt2->bind_param('sssssssss',
                $r['grundenergi'], $r['fornavn'], $r['mellemnavn1'], $r['mellemnavn2'],
                $r['mellemnavn3'], $r['mellemnavn4'], $r['mellemnavn5'], $r['efternavn'], $r['principper']);
            $stmt2->execute();
        }
    }
    jsonOut(['ok' => true]);
}

jsonOut(['error' => 'Method not allowed'], 405);
