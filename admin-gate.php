<?php
/**
 * Admin Gate — checks PHP session, serves the requested input-*.html page.
 * All input-*.html requests are rewritten here via .htaccess.
 */
session_start();

if (empty($_SESSION['admin_auth'])) {
    $from = urlencode('/' . ltrim($_SERVER['REQUEST_URI'], '/'));
    header('Location: /admin-login.php?from=' . $from);
    exit;
}

// Only allow filenames matching input-*.html (prevent path traversal)
$page = basename($_GET['p'] ?? '');
if (!preg_match('/^input-[a-z0-9-]+\.html$/', $page)) {
    http_response_code(404);
    exit;
}

$file = __DIR__ . '/' . $page;
if (!is_file($file)) {
    http_response_code(404);
    exit;
}

// Serve the file and inject a small logout bar
$html = file_get_contents($file);

$logoutBar = '<div style="position:fixed;bottom:0;right:0;padding:.4rem .9rem;'
           . 'background:rgba(0,0,0,.75);backdrop-filter:blur(6px);'
           . 'font-size:.72rem;z-index:9999;border-top-left-radius:8px;display:flex;gap:.8rem;align-items:center;">'
           . '<a href="/admin.php" '
           . 'style="color:#a78bfa;text-decoration:none;letter-spacing:.04em;">'
           . '&#9783;&nbsp;Dashboard</a>'
           . '<a href="/admin-rapporter.php" '
           . 'style="color:#93c5fd;text-decoration:none;letter-spacing:.04em;">'
           . '&#128196;&nbsp;Rapporter</a>'
           . '<a href="/admin-logout.php" '
           . 'style="color:#f8d57e;text-decoration:none;letter-spacing:.04em;">'
           . '&#x2715;&nbsp;Log ud</a></div>';

$html = str_replace('</body>', $logoutBar . "\n</body>", $html);

header('Content-Type: text/html; charset=utf-8');
echo $html;
