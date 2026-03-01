<?php
require_once __DIR__ . '/db.php';

$db = getDB();
$page = $_GET['page'] ?? null;

// ─── GET ───
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($page) {
        $stmt = $db->prepare('SELECT * FROM meta_data WHERE page = ?');
        $stmt->bind_param('s', $page);
        $stmt->execute();
        $res = $stmt->get_result();
        jsonOut($res->num_rows ? $res->fetch_assoc() : new stdClass());
    }
    $res = $db->query('SELECT * FROM meta_data ORDER BY page ASC');
    jsonOut(mysqli_fetch_all($res, MYSQLI_ASSOC));
}

// ─── POST ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdminKey();
    $b = getBody();
    $pageName    = $b['page'] ?? null;
    $title       = $b['title'] ?? null;
    $description = $b['description'] ?? null;
    $keywords    = $b['keywords'] ?? null;
    $ogImage     = $b['ogImage'] ?? null;
    $seoImage    = $b['seoImage'] ?? null;

    if (!$pageName) jsonOut(['error' => 'page er påkrævet'], 400);

    $stmt = $db->prepare('SELECT id FROM meta_data WHERE page = ?');
    $stmt->bind_param('s', $pageName);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;

    if ($exists) {
        $stmt2 = $db->prepare('UPDATE meta_data SET title=?,description=?,keywords=?,ogImage=?,seoImage=? WHERE page=?');
        $stmt2->bind_param('ssssss', $title, $description, $keywords, $ogImage, $seoImage, $pageName);
    } else {
        $stmt2 = $db->prepare('INSERT INTO meta_data (page,title,description,keywords,ogImage,seoImage) VALUES (?,?,?,?,?,?)');
        $stmt2->bind_param('ssssss', $pageName, $title, $description, $keywords, $ogImage, $seoImage);
    }
    $stmt2->execute();
    jsonOut(['ok' => true]);
}

jsonOut(['error' => 'Method not allowed'], 405);
