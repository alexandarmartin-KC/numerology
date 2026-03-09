<?php
/* ============================================================
   admin.php — Bootstrap Admin Dashboard
   ============================================================ */
session_start();
if (empty($_SESSION['admin_auth'])) {
    header('Location: /admin-login.php?from=/admin.php');
    exit;
}
require_once __DIR__ . '/api/db.php';
$db = getDB();

// ── Stats ──
$stats = [];
$res = $db->query("SELECT status, COUNT(*) AS n FROM orders GROUP BY status");
$statusCounts = [];
while ($row = $res ? $res->fetch_assoc() : null) $statusCounts[$row['status']] = (int)$row['n'];
$stats['total']      = array_sum($statusCounts);
$stats['sent']       = $statusCounts['sent']       ?? 0;
$stats['pending']    = ($statusCounts['pending'] ?? 0) + ($statusCounts['generating'] ?? 0);
$stats['failed']     = $statusCounts['failed']     ?? 0;

// ── Seneste 5 ordrer ──
$recent = mysqli_fetch_all(
    $db->query("SELECT id, full_name, email, plan, status, created_at FROM orders ORDER BY created_at DESC LIMIT 5"),
    MYSQLI_ASSOC
) ?: [];

$planLabels   = ['foundation'=>'Foundation','direction'=>'Direction','activation'=>'Activation'];
$statusConfig = [
    'pending'    => ['label'=>'Afventer',   'badge'=>'warning'],
    'generating' => ['label'=>'Genererer',  'badge'=>'info'],
    'done'       => ['label'=>'Klar',       'badge'=>'primary'],
    'failed'     => ['label'=>'Fejlet',     'badge'=>'danger'],
    'sent'       => ['label'=>'Sendt',      'badge'=>'success'],
];
?>
<!doctype html>
<html lang="da">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin — StrategicNumerology</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root {
      --sidebar-w: 240px;
      --clr-gold: #c9a84c;
      --clr-dark: #111318;
      --clr-sidebar: #1a1a28;
    }
    body { background: #f4f5f7; overflow-x: hidden; }

    /* ── Sidebar ── */
    #sidebar {
      position: fixed; top: 0; left: 0; bottom: 0;
      width: var(--sidebar-w);
      background: var(--clr-sidebar);
      display: flex; flex-direction: column;
      z-index: 100;
      transition: transform .25s ease;
    }
    .sidebar-brand {
      padding: 1.4rem 1.25rem 1rem;
      border-bottom: 1px solid rgba(201,168,76,.15);
    }
    .sidebar-brand .brand-name {
      font-size: .95rem; font-weight: 700;
      color: var(--clr-gold); letter-spacing: .03em;
      text-decoration: none; display: block;
    }
    .sidebar-brand .brand-sub {
      font-size: .65rem; color: #666; letter-spacing: 1px;
      text-transform: uppercase;
    }
    .sidebar-section {
      padding: .6rem 1rem .25rem;
      font-size: .62rem; font-weight: 600;
      text-transform: uppercase; letter-spacing: 1.5px;
      color: #555570;
    }
    .sidebar-nav { flex: 1; overflow-y: auto; padding: .5rem 0; }
    .sidebar-nav a {
      display: flex; align-items: center; gap: .6rem;
      padding: .45rem 1.25rem;
      font-size: .83rem; color: #aab; text-decoration: none;
      border-left: 3px solid transparent;
      transition: all .15s;
    }
    .sidebar-nav a:hover { color: #e8e4da; background: rgba(255,255,255,.04); }
    .sidebar-nav a.active { color: var(--clr-gold); border-left-color: var(--clr-gold); background: rgba(201,168,76,.07); }
    .sidebar-nav .bi { font-size: .85rem; flex-shrink: 0; width: 16px; }
    .sidebar-footer {
      padding: .75rem 1.25rem;
      border-top: 1px solid rgba(255,255,255,.07);
      font-size: .78rem;
    }
    .sidebar-footer a { color: #666; text-decoration: none; }
    .sidebar-footer a:hover { color: #f8d57e; }

    /* ── Main ── */
    #main {
      margin-left: var(--sidebar-w);
      min-height: 100vh;
      padding: 1.75rem 2rem;
    }
    .topbar {
      display: flex; align-items: center; justify-content: space-between;
      margin-bottom: 1.75rem;
    }
    .topbar h1 { font-size: 1.3rem; font-weight: 700; margin: 0; }

    /* ── Stat cards ── */
    .stat-card {
      background: #fff;
      border-radius: 10px;
      padding: 1.2rem 1.4rem;
      box-shadow: 0 1px 3px rgba(0,0,0,.07);
      display: flex; align-items: center; gap: 1rem;
    }
    .stat-icon {
      width: 44px; height: 44px; border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.2rem; flex-shrink: 0;
    }
    .stat-label { font-size: .72rem; color: #888; text-transform: uppercase; letter-spacing: .5px; }
    .stat-value { font-size: 1.7rem; font-weight: 700; line-height: 1.1; }

    /* ── Tables & cards ── */
    .admin-card {
      background: #fff; border-radius: 10px;
      box-shadow: 0 1px 3px rgba(0,0,0,.07);
      overflow: hidden;
    }
    .admin-card-header {
      padding: .9rem 1.25rem;
      border-bottom: 1px solid #f0f0f0;
      display: flex; align-items: center; justify-content: space-between;
    }
    .admin-card-header h2 { font-size: .95rem; font-weight: 600; margin: 0; }

    /* ── Quick links grid ── */
    .ql-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: .75rem; padding: 1rem; }
    .ql-item {
      display: flex; align-items: center; gap: .6rem;
      padding: .65rem .85rem; border-radius: 8px;
      background: #f8f9fa; text-decoration: none; color: #333;
      font-size: .82rem; font-weight: 500;
      border: 1px solid #eee;
      transition: all .15s;
    }
    .ql-item:hover { background: #fff; border-color: #c9a84c; color: #c9a84c; box-shadow: 0 2px 8px rgba(0,0,0,.06); }
    .ql-item .bi { font-size: .9rem; color: #c9a84c; }

    /* ── Mobile ── */
    #sidebarToggle { display: none; }
    @media (max-width: 768px) {
      #sidebar { transform: translateX(-100%); }
      #sidebar.open { transform: translateX(0); }
      #main { margin-left: 0; padding: 1rem; }
      #sidebarToggle { display: flex; }
    }
  </style>
</head>
<body>

<!-- Sidebar -->
<aside id="sidebar">
  <div class="sidebar-brand">
    <a class="brand-name" href="/admin.php">StrategicNumerology</a>
    <span class="brand-sub">Admin Panel</span>
  </div>

  <nav class="sidebar-nav">
    <div class="sidebar-section">Oversigt</div>
    <a href="/admin.php" class="active"><i class="bi bi-speedometer2"></i> Dashboard</a>
    <a href="/admin-rapporter.php"><i class="bi bi-file-earmark-text"></i> Ordrer &amp; Rapporter</a>

    <div class="sidebar-section mt-2">Vidensbase</div>
    <a href="/input-generelt.html"><i class="bi bi-sliders"></i> Generelt</a>
    <a href="/input-diamant.html"><i class="bi bi-diamond"></i> Diamant</a>
    <a href="/input-grundenergier.html"><i class="bi bi-123"></i> Grundenergier</a>
    <a href="/input-aarstalsraekker.html"><i class="bi bi-calendar3"></i> Årstalsrækker</a>
    <a href="/input-rapport.html"><i class="bi bi-card-text"></i> Rapport</a>
    <a href="/input-navnegenerator.html"><i class="bi bi-person-badge"></i> Navnegenerator</a>
    <a href="/input-gratisberegning.html"><i class="bi bi-gift"></i> Gratis beregning</a>

    <div class="sidebar-section mt-2">Indhold</div>
    <a href="/input-om-numerologi.html"><i class="bi bi-book"></i> Om numerologi</a>
    <a href="/input-om-os.html"><i class="bi bi-people"></i> Om os</a>
    <a href="/input-tak.html"><i class="bi bi-check-circle"></i> Tak-side</a>
    <a href="/input-meta.html"><i class="bi bi-tags"></i> Meta / SEO</a>

    <div class="sidebar-section mt-2">Live sider</div>
    <a href="/diamant.html" target="_blank"><i class="bi bi-box-arrow-up-right"></i> Diamant</a>
    <a href="/rapport.html" target="_blank"><i class="bi bi-box-arrow-up-right"></i> Rapport</a>
    <a href="/landing.html" target="_blank"><i class="bi bi-box-arrow-up-right"></i> Landing</a>
    <a href="/index.html" target="_blank"><i class="bi bi-house"></i> Forside</a>
  </nav>

  <div class="sidebar-footer">
    <a href="/admin-logout.php"><i class="bi bi-box-arrow-left"></i> Log ud</a>
  </div>
</aside>

<!-- Main -->
<div id="main">
  <div class="topbar">
    <div class="d-flex align-items-center gap-3">
      <button id="sidebarToggle" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('sidebar').classList.toggle('open')">
        <i class="bi bi-list"></i>
      </button>
      <h1>Dashboard</h1>
    </div>
    <a href="/admin-rapporter.php" class="btn btn-sm btn-dark">
      <i class="bi bi-file-earmark-text me-1"></i>Se alle ordrer
    </a>
  </div>

  <!-- Stat cards -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
      <div class="stat-card">
        <div class="stat-icon" style="background:#f0f9f0;color:#28a745;"><i class="bi bi-bag-check"></i></div>
        <div>
          <div class="stat-label">Ordrer i alt</div>
          <div class="stat-value"><?= $stats['total'] ?></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="stat-card">
        <div class="stat-icon" style="background:#f0f7ff;color:#0d6efd;"><i class="bi bi-send-check"></i></div>
        <div>
          <div class="stat-label">Sendt</div>
          <div class="stat-value"><?= $stats['sent'] ?></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="stat-card">
        <div class="stat-icon" style="background:#fffbf0;color:#ffc107;"><i class="bi bi-hourglass-split"></i></div>
        <div>
          <div class="stat-label">Afventer</div>
          <div class="stat-value"><?= $stats['pending'] ?></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="stat-card">
        <div class="stat-icon" style="background:#fff0f0;color:#dc3545;"><i class="bi bi-exclamation-circle"></i></div>
        <div>
          <div class="stat-label">Fejlet</div>
          <div class="stat-value"><?= $stats['failed'] ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3">

    <!-- Seneste ordrer -->
    <div class="col-lg-7">
      <div class="admin-card">
        <div class="admin-card-header">
          <h2><i class="bi bi-clock-history me-2 text-muted"></i>Seneste ordrer</h2>
          <a href="/admin-rapporter.php" class="btn btn-xs btn-outline-secondary" style="font-size:.75rem;padding:.2rem .6rem;">Se alle</a>
        </div>
        <?php if (empty($recent)): ?>
          <p class="text-muted p-3 mb-0" style="font-size:.85rem;">Ingen ordrer endnu.</p>
        <?php else: ?>
        <table class="table table-hover mb-0" style="font-size:.82rem;">
          <thead class="table-light">
            <tr>
              <th>Navn</th>
              <th>Pakke</th>
              <th>Status</th>
              <th>Dato</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent as $o):
              $sc = $statusConfig[$o['status']] ?? ['label'=>$o['status'],'badge'=>'secondary'];
              $pl = $planLabels[$o['plan']] ?? $o['plan'];
            ?>
            <tr>
              <td>
                <div class="fw-medium"><?= htmlspecialchars($o['full_name']) ?></div>
                <div class="text-muted" style="font-size:.75rem;"><?= htmlspecialchars($o['email']) ?></div>
              </td>
              <td><?= htmlspecialchars($pl) ?></td>
              <td><span class="badge text-bg-<?= $sc['badge'] ?>"><?= $sc['label'] ?></span></td>
              <td class="text-muted"><?= date('d.m.y', strtotime($o['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- Quick links -->
    <div class="col-lg-5">
      <div class="admin-card">
        <div class="admin-card-header">
          <h2><i class="bi bi-grid me-2 text-muted"></i>Genveje</h2>
        </div>
        <div class="ql-grid">
          <a class="ql-item" href="/input-generelt.html"><i class="bi bi-sliders"></i> Generelt</a>
          <a class="ql-item" href="/input-diamant.html"><i class="bi bi-diamond"></i> Diamant</a>
          <a class="ql-item" href="/input-grundenergier.html"><i class="bi bi-123"></i> Grundenergier</a>
          <a class="ql-item" href="/input-aarstalsraekker.html"><i class="bi bi-calendar3"></i> Årstalsrækker</a>
          <a class="ql-item" href="/input-rapport.html"><i class="bi bi-card-text"></i> Rapport</a>
          <a class="ql-item" href="/input-navnegenerator.html"><i class="bi bi-person-badge"></i> Navnegenerator</a>
          <a class="ql-item" href="/input-gratisberegning.html"><i class="bi bi-gift"></i> Gratis</a>
          <a class="ql-item" href="/input-om-numerologi.html"><i class="bi bi-book"></i> Om numerologi</a>
          <a class="ql-item" href="/input-om-os.html"><i class="bi bi-people"></i> Om os</a>
          <a class="ql-item" href="/input-tak.html"><i class="bi bi-check-circle"></i> Tak-side</a>
          <a class="ql-item" href="/input-meta.html"><i class="bi bi-tags"></i> Meta / SEO</a>
          <a class="ql-item" href="/admin-rapporter.php"><i class="bi bi-file-earmark-text"></i> Rapporter</a>
        </div>
      </div>
    </div>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>
