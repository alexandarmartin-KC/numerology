<?php
require_once __DIR__ . '/db.php';

$db = getDB();

// ─── GET ───
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $res = $db->query('SELECT customPrompt, teaserText FROM gratis_beregning WHERE id = 1');
    if (!$res || $res->num_rows === 0) { jsonOut(new stdClass()); }
    $row = $res->fetch_assoc();
    jsonOut($row);
}

// ─── POST ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdminKey();
    $b = getBody();
    $customPrompt = $b['customPrompt'] ?? null;
    $teaserText   = $b['teaserText']   ?? null;

    $r = $db->query('SELECT id FROM gratis_beregning WHERE id = 1');
    if ($r && $r->num_rows > 0) {
        $stmt = $db->prepare('UPDATE gratis_beregning SET customPrompt=?, teaserText=? WHERE id=1');
        $stmt->bind_param('ss', $customPrompt, $teaserText);
    } else {
        $stmt = $db->prepare('INSERT INTO gratis_beregning (id, customPrompt, teaserText) VALUES (1, ?, ?)');
        $stmt->bind_param('ss', $customPrompt, $teaserText);
    }
    $stmt->execute();
    jsonOut(['ok' => true]);
}

jsonOut(['error' => 'Method not allowed'], 405);

