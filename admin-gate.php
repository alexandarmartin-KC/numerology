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

// Derive a friendly page title from filename
$pageTitles = [
    'input-generelt.html'        => 'Generelt',
    'input-diamant.html'         => 'Diamant',
    'input-grundenergier.html'   => 'Grundenergier',
    'input-aarstalsraekker.html' => 'Årstalsrækker',
    'input-rapport.html'         => 'Rapport',
    'input-navnegenerator.html'  => 'Navnegenerator',
    'input-gratisberegning.html' => 'Gratis beregning',
    'input-om-numerologi.html'   => 'Om numerologi',
    'input-om-os.html'           => 'Om os',
    'input-tak.html'             => 'Tak-side',
    'input-meta.html'            => 'Meta / SEO',
];
$pageTitle = $pageTitles[$page] ?? ucfirst(str_replace(['input-', '.html', '-'], ['', '', ' '], $page));

$html = file_get_contents($file);

// ── CSS: hide original navbar, push body right for sidebar ──────────────────
$injectCss = <<<'CSS'
<style>
  :root { --sidebar-w: 240px; --clr-gold: #c9a84c; --clr-sidebar: #1a1a28; }
  body { margin-left: var(--sidebar-w) !important; background: #f4f5f7 !important; padding-top: 0 !important; }

  /* Admin Sidebar */
  #admin-sidebar {
    position: fixed; top: 0; left: 0; bottom: 0;
    width: var(--sidebar-w);
    background: var(--clr-sidebar);
    display: flex; flex-direction: column;
    z-index: 1050;
    overflow-y: auto;
  }
  .asb-brand {
    padding: 1.4rem 1.25rem 1rem;
    border-bottom: 1px solid rgba(201,168,76,.15);
    flex-shrink: 0;
  }
  .asb-brand a {
    font-size: .95rem; font-weight: 700; color: var(--clr-gold);
    letter-spacing: .03em; text-decoration: none; display: block;
  }
  .asb-brand span { font-size: .65rem; color: #555; letter-spacing: 1px; text-transform: uppercase; display: block; margin-top: .15rem; }
  .asb-section {
    padding: .6rem 1rem .25rem;
    font-size: .62rem; font-weight: 600;
    text-transform: uppercase; letter-spacing: 1.5px; color: #555570;
    flex-shrink: 0;
  }
  .asb-nav { flex: 1; padding: .5rem 0; }
  .asb-nav a {
    display: flex; align-items: center; gap: .6rem;
    padding: .45rem 1.25rem;
    font-size: .83rem; color: #aab; text-decoration: none;
    border-left: 3px solid transparent;
    transition: all .15s;
    white-space: nowrap;
  }
  .asb-nav a:hover { color: #e8e4da; background: rgba(255,255,255,.04); }
  .asb-nav a.asb-active { color: var(--clr-gold); border-left-color: var(--clr-gold); background: rgba(201,168,76,.07); }
  .asb-nav .bi { font-size: .85rem; flex-shrink: 0; width: 16px; }
  .asb-footer {
    padding: .75rem 1.25rem;
    border-top: 1px solid rgba(255,255,255,.07);
    font-size: .78rem; flex-shrink: 0;
  }
  .asb-footer a { color: #777; text-decoration: none; display: flex; align-items: center; gap: .4rem; }
  .asb-footer a:hover { color: #f8d57e; }

  /* Admin Topbar */
  #admin-topbar {
    position: sticky; top: 0; z-index: 100;
    background: #fff;
    border-bottom: 1px solid #e8e8e8;
    padding: .65rem 1.5rem;
    display: flex; align-items: center;
    box-shadow: 0 1px 3px rgba(0,0,0,.06);
    margin-bottom: 0;
  }
  #admin-topbar .breadcrumb { margin: 0; font-size: .82rem; }
  #admin-topbar .breadcrumb-item + .breadcrumb-item::before { content: "›"; }

  /* Wrap page content */
  #admin-page-content { padding: 1.5rem 2rem 3rem; }
</style>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
CSS;

// ── Sidebar HTML ─────────────────────────────────────────────────────────────
$a = function(string $p) use ($page): string {
    return $page === $p ? ' asb-active' : '';
};

$sidebar = '
<aside id="admin-sidebar">
  <div class="asb-brand">
    <a href="/admin.php">StrategicNumerology</a>
    <span>Admin Panel</span>
  </div>
  <nav class="asb-nav">
    <div class="asb-section">Oversigt</div>
    <a href="/admin.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
    <a href="/admin-rapporter.php"><i class="bi bi-file-earmark-text"></i> Ordrer &amp; Rapporter</a>

    <div class="asb-section">Vidensbase</div>
    <a href="/input-generelt.html" class="' . $a('input-generelt.html') . '"><i class="bi bi-sliders"></i> Generelt</a>
    <a href="/input-diamant.html" class="' . $a('input-diamant.html') . '"><i class="bi bi-diamond"></i> Diamant</a>
    <a href="/input-grundenergier.html" class="' . $a('input-grundenergier.html') . '"><i class="bi bi-123"></i> Grundenergier</a>
    <a href="/input-aarstalsraekker.html" class="' . $a('input-aarstalsraekker.html') . '"><i class="bi bi-calendar3"></i> Årstalsrækker</a>
    <a href="/input-rapport.html" class="' . $a('input-rapport.html') . '"><i class="bi bi-card-text"></i> Rapport</a>
    <a href="/input-navnegenerator.html" class="' . $a('input-navnegenerator.html') . '"><i class="bi bi-person-badge"></i> Navnegenerator</a>
    <a href="/input-gratisberegning.html" class="' . $a('input-gratisberegning.html') . '"><i class="bi bi-gift"></i> Gratis beregning</a>

    <div class="asb-section">Indhold</div>
    <a href="/input-om-numerologi.html" class="' . $a('input-om-numerologi.html') . '"><i class="bi bi-book"></i> Om numerologi</a>
    <a href="/input-om-os.html" class="' . $a('input-om-os.html') . '"><i class="bi bi-people"></i> Om os</a>
    <a href="/input-tak.html" class="' . $a('input-tak.html') . '"><i class="bi bi-check-circle"></i> Tak-side</a>
    <a href="/input-meta.html" class="' . $a('input-meta.html') . '"><i class="bi bi-tags"></i> Meta / SEO</a>

    <div class="asb-section">Live sider</div>
    <a href="/diamant.html" target="_blank"><i class="bi bi-box-arrow-up-right"></i> Diamant</a>
    <a href="/rapport.html" target="_blank"><i class="bi bi-box-arrow-up-right"></i> Rapport</a>
    <a href="/landing.html" target="_blank"><i class="bi bi-house"></i> Landing</a>
    <a href="/index.html" target="_blank"><i class="bi bi-house-door"></i> Forside</a>
  </nav>
  <div class="asb-footer">
    <a href="/admin-logout.php"><i class="bi bi-box-arrow-left"></i> Log ud</a>
  </div>
</aside>
';

$topbar = '<div id="admin-topbar"><nav aria-label="breadcrumb"><ol class="breadcrumb">'
        . '<li class="breadcrumb-item"><a href="/admin.php" style="color:#c9a84c;text-decoration:none;">Dashboard</a></li>'
        . '<li class="breadcrumb-item active">' . htmlspecialchars($pageTitle) . '</li>'
        . '</ol></nav></div>';

// Inject CSS into <head>
$html = str_replace('</head>', $injectCss . '</head>', $html);

// Inject sidebar + topbar after opening <body>, wrap remaining content
$html = preg_replace(
    '/<body([^>]*)>/i',
    '<body$1>' . $sidebar . '<div id="admin-page-content">' . $topbar,
    $html,
    1
);

// Close the wrapper before </body>
$html = str_replace('</body>', '</div></body>', $html);

header('Content-Type: text/html; charset=utf-8');
echo $html;
