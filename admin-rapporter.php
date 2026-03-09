<?php
/* ============================================================
   admin-rapporter.php
   Oversigt over alle ordrer + mulighed for at se rapport
   og gensende email. Kræver admin-session.
   ============================================================ */
session_start();
if (empty($_SESSION['admin_auth'])) {
    header('Location: /admin-login.php?from=/admin-rapporter.php');
    exit;
}
require_once __DIR__ . '/api/db.php';
$db = getDB();

// ── Vis enkelt rapport ──
$viewId = isset($_GET['order']) ? (int)$_GET['order'] : 0;
if ($viewId) {
    $stmt = $db->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->bind_param('i', $viewId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($order && $order['rapport_html']) {
        $planNames = ['foundation'=>'Foundation Report','direction'=>'Direction Report','activation'=>'Activation Report'];
        $planName  = $planNames[$order['plan']] ?? ucfirst($order['plan']);
        $safeName  = htmlspecialchars($order['full_name'], ENT_QUOTES, 'UTF-8');
        ?>
<!doctype html>
<html lang="da">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Rapport — <?= $safeName ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
  <style>body{background:#f8f9fa;} .rapport-output h2{margin-top:1.5rem;font-size:1.1rem;} .rapport-output h3{margin-top:1.2rem;font-size:1rem;} @media print{.no-print{display:none!important}}</style>
</head>
<body class="py-4">
  <div class="container" style="max-width:820px">
    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
      <a href="/admin-rapporter.php" class="btn btn-sm btn-outline-secondary">← Tilbage</a>
      <button onclick="window.print()" class="btn btn-sm btn-dark">Print / PDF</button>
    </div>
    <div class="card shadow-sm">
      <div class="card-body">
        <h1 class="h4"><?= htmlspecialchars($planName) ?></h1>
        <p class="text-muted mb-1"><strong>Navn:</strong> <?= $safeName ?></p>
        <p class="text-muted mb-3"><strong>Fødselsdato:</strong> <?= htmlspecialchars($order['birth_date']) ?> &nbsp;|&nbsp; <strong>Email:</strong> <?= htmlspecialchars($order['email']) ?></p>
        <hr>
        <div class="rapport-output"><?= $order['rapport_html'] ?></div>
      </div>
    </div>
  </div>
</body>
</html>
        <?php
        exit;
    }
    // Ordre ikke fundet eller ingen rapport
    header('Location: /admin-rapporter.php');
    exit;
}

// ── Hent alle ordrer ──
$orders = mysqli_fetch_all(
    $db->query('SELECT id, stripe_session_id, full_name, birth_date, email, plan, status, created_at, sent_at, error_msg FROM orders ORDER BY created_at DESC'),
    MYSQLI_ASSOC
) ?: [];

$statusLabels = [
    'pending'    => ['label'=>'Afventer',    'class'=>'text-warning'],
    'generating' => ['label'=>'Genererer…',  'class'=>'text-info'],
    'done'       => ['label'=>'Klar',        'class'=>'text-primary'],
    'failed'     => ['label'=>'Fejlet',      'class'=>'text-danger fw-bold'],
    'sent'       => ['label'=>'Sendt',       'class'=>'text-success'],
];
$planLabels = ['foundation'=>'Foundation ($35)','direction'=>'Direction ($75)','activation'=>'Activation ($149)'];
?>
<!doctype html>
<html lang="da">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin — Rapporter</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
</head>
<body>
  <nav class="navbar navbar-expand-lg bg-body-tertiary border-bottom">
    <div class="container">
      <a class="navbar-brand" href="index.html">StrategicNumerology</a>
      <span class="navbar-text ms-auto">
        <a href="/input-generelt.html" class="text-muted text-decoration-none me-3" style="font-size:14px;">Admin</a>
        <a href="/admin-logout.php" class="text-danger text-decoration-none" style="font-size:14px;">Log ud</a>
      </span>
    </div>
  </nav>

  <main class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
      <h1 class="h3 mb-0">Ordrer &amp; Rapporter</h1>
      <span class="badge bg-secondary"><?= count($orders) ?> ordre<?= count($orders) !== 1 ? 'r' : '' ?></span>
    </div>

    <?php if (empty($orders)): ?>
      <div class="alert alert-info">Ingen ordrer endnu.</div>
    <?php else: ?>
      <div class="card shadow-sm">
        <div class="table-responsive">
          <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th style="width:40px">#</th>
                <th>Navn</th>
                <th>Email</th>
                <th>Pakke</th>
                <th>Status</th>
                <th>Oprettet</th>
                <th>Sendt</th>
                <th class="text-end">Handlinger</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($orders as $o): ?>
              <?php
                $st = $statusLabels[$o['status']] ?? ['label'=>$o['status'],'class'=>''];
                $pl = $planLabels[$o['plan']] ?? $o['plan'];
                $hasRapport = !empty($o['rapport_html'] ?? false);
                // rapport_html is not in select — we check via a quick query if needed
                // Actually we didn't select rapport_html to keep the list fast.
                // We'll show "Se rapport" for done/sent statuses.
                $canView    = in_array($o['status'], ['done','sent']);
                $canResend  = in_array($o['status'], ['done','sent']);
              ?>
              <tr>
                <td class="text-muted" style="font-size:13px;"><?= $o['id'] ?></td>
                <td>
                  <div class="fw-medium"><?= htmlspecialchars($o['full_name']) ?></div>
                  <div class="text-muted" style="font-size:12px;"><?= htmlspecialchars($o['birth_date']) ?></div>
                </td>
                <td style="font-size:13px;"><?= htmlspecialchars($o['email']) ?></td>
                <td style="font-size:13px;"><?= htmlspecialchars($pl) ?></td>
                <td>
                  <span class="<?= $st['class'] ?>" style="font-size:13px;"><?= $st['label'] ?></span>
                  <?php if ($o['status'] === 'failed' && $o['error_msg']): ?>
                    <br><small class="text-danger"><?= htmlspecialchars(substr($o['error_msg'], 0, 80)) ?>…</small>
                  <?php endif; ?>
                </td>
                <td style="font-size:13px;"><?= date('d.m.Y H:i', strtotime($o['created_at'])) ?></td>
                <td style="font-size:13px;"><?= $o['sent_at'] ? date('d.m.Y H:i', strtotime($o['sent_at'])) : '—' ?></td>
                <td class="text-end">
                  <?php if ($canView): ?>
                    <a href="/admin-rapporter.php?order=<?= $o['id'] ?>" class="btn btn-sm btn-outline-dark me-1" target="_blank">Se rapport</a>
                  <?php endif; ?>
                  <?php if ($canResend): ?>
                    <button class="btn btn-sm btn-outline-primary resend-btn" data-id="<?= $o['id'] ?>" data-name="<?= htmlspecialchars($o['full_name'], ENT_QUOTES) ?>">Gensend</button>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </main>

  <!-- Toast besked -->
  <div class="position-fixed bottom-0 end-0 p-3" style="z-index:9999">
    <div id="toast" class="toast align-items-center text-bg-success border-0" role="alert">
      <div class="d-flex">
        <div class="toast-body" id="toastMsg">Email sendt.</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
  <script>
    const toast    = new bootstrap.Toast(document.getElementById('toast'), { delay: 3500 });
    const toastMsg = document.getElementById('toastMsg');

    document.querySelectorAll('.resend-btn').forEach(btn => {
      btn.addEventListener('click', async () => {
        const id   = btn.dataset.id;
        const name = btn.dataset.name;
        if (!confirm(`Gensend rapport-email til ${name}?`)) return;
        btn.disabled = true;
        btn.textContent = 'Sender…';
        try {
          const res  = await fetch('/api/resend-rapport', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ orderId: parseInt(id) })
          });
          const data = await res.json();
          if (data.ok) {
            toastMsg.textContent = data.message || 'Email sendt.';
            document.getElementById('toast').className = 'toast align-items-center text-bg-success border-0';
            toast.show();
            btn.textContent = 'Gensendt ✓';
          } else {
            toastMsg.textContent = data.error || 'Fejl ved afsendelse.';
            document.getElementById('toast').className = 'toast align-items-center text-bg-danger border-0';
            toast.show();
            btn.disabled = false;
            btn.textContent = 'Gensend';
          }
        } catch (e) {
          toastMsg.textContent = 'Netværksfejl: ' + e.message;
          document.getElementById('toast').className = 'toast align-items-center text-bg-danger border-0';
          toast.show();
          btn.disabled = false;
          btn.textContent = 'Gensend';
        }
      });
    });
  </script>
</body>
</html>
