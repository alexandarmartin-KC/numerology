<?php
require_once __DIR__ . '/db.php';

$db = getDB();

// ─── GET ───
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $res = $db->query('SELECT * FROM rapport_sections ORDER BY id ASC');
    $sections = mysqli_fetch_all($res, MYSQLI_ASSOC);
    $genRes = $db->query('SELECT rapportGlobalInstruction FROM generelt WHERE id = 1');
    $gen = $genRes->num_rows ? $genRes->fetch_assoc() : [];
    jsonOut([
        'globalInstruction' => $gen['rapportGlobalInstruction'] ?? '',
        'sections' => array_map(function($s) {
            $s['sources'] = json_decode($s['sources'] ?? '[]', true) ?: [];
            return $s;
        }, $sections)
    ]);
}

// ─── POST ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = getBody();
    $globalInstruction = $b['globalInstruction'] ?? null;
    $sections = $b['sections'] ?? [];

    // Gem global instruktion
    $r = $db->query('SELECT id FROM generelt WHERE id = 1');
    if ($r && $r->num_rows > 0) {
        $stmt = $db->prepare('UPDATE generelt SET rapportGlobalInstruction=? WHERE id=1');
        $stmt->bind_param('s', $globalInstruction);
    } else {
        $stmt = $db->prepare('INSERT INTO generelt (id, rapportGlobalInstruction) VALUES (1, ?)');
        $stmt->bind_param('s', $globalInstruction);
    }
    $stmt->execute();

    // Gem sektioner
    $db->query('DELETE FROM rapport_sections');
    if (is_array($sections)) {
        $stmt2 = $db->prepare('INSERT INTO rapport_sections (title, instruction, sources) VALUES (?, ?, ?)');
        foreach ($sections as $s) {
            $src = json_encode($s['sources'] ?? [], JSON_UNESCAPED_UNICODE);
            $stmt2->bind_param('sss', $s['title'], $s['instruction'], $src);
            $stmt2->execute();
        }
    }
    jsonOut(['ok' => true]);
}

jsonOut(['error' => 'Method not allowed'], 405);
