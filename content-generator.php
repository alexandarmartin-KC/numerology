<?php
/**
 * Content Generator Admin
 * Kræver admin-session (samme som input-*.html)
 *
 * DB-tabeller – kør én gang via /api/migrate-content.php
 */
session_start();
if (empty($_SESSION['admin_auth'])) {
    header('Location: /admin-login.php?from=/content-generator.php');
    exit;
}

require_once __DIR__ . '/api/db.php';
$db = getDB();

// ── API-nøgler ────────────────────────────────────────────────────────────────
$_ANTHROPIC_API_KEY = '';
$envFile = __DIR__ . '/api/.env.php';
if (file_exists($envFile)) include $envFile;
$claudeKey = getenv('ANTHROPIC_API_KEY') ?: ($_ANTHROPIC_API_KEY ?? '');

// ── Claude-kald ───────────────────────────────────────────────────────────────
function callClaude(string $system, string $user, string $apiKey, int $maxTokens = 2000): string {
    $payload = json_encode([
        'model'       => 'claude-sonnet-4-5',
        'max_tokens'  => $maxTokens,
        'temperature' => 0.7,
        'system'      => $system,
        'messages'    => [['role' => 'user', 'content' => $user]],
    ], JSON_UNESCAPED_UNICODE);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT => 90,
    ]);
    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return 'Fejl fra Claude API (HTTP ' . $code . '): ' . $response;
    $data = json_decode($response, true);
    return $data['content'][0]['text'] ?? 'Ingen tekst i svar.';
}

// ── AJAX-handlers ─────────────────────────────────────────────────────────────
header('X-Content-Type-Options: nosniff');
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action) {
    header('Content-Type: application/json; charset=utf-8');

    // ─ Emneforslag ─
    if ($action === 'get_suggestions') {
        if (!$claudeKey) { echo json_encode(['success' => false, 'error' => 'ANTHROPIC_API_KEY ikke konfigureret']); exit; }
        $topic    = mb_substr(trim($_POST['topic'] ?? 'numerologi'), 0, 200);
        $language = ($_POST['language'] ?? 'da') === 'en' ? 'en' : 'da';
        if ($language === 'en') {
            $system = "You are a content strategist specialized in numerology. Generate topic suggestions in English only.";
            $user   = "Generate 8 specific content ideas for the topic: \"{$topic}\".\n\nRespond ONLY with a JSON array (no markdown):\n[{\"title\": \"...\", \"keywords\": \"...\", \"type\": \"artikel|nyhedsbrev|social\"}]";
        } else {
            $system = "Du er en indholdsstrateg specialiseret i numerologi. Generer emneforslag på dansk.";
            $user   = "Generer 8 konkrete indholdforslag til emnet: \"{$topic}\".\n\nSvar KUN med et JSON-array (ingen markdown):\n[{\"title\": \"...\", \"keywords\": \"...\", \"type\": \"artikel|nyhedsbrev|social\"}]";
        }
        $raw   = callClaude($system, $user, $claudeKey, 600);
        $raw   = preg_replace('/```json|```/', '', $raw);
        $items = json_decode(trim($raw), true) ?? [];
        echo json_encode(['success' => true, 'suggestions' => $items]);
        exit;
    }

    // ─ Google Trends USA (RSS, ingen API-nøgle) ─
    if ($action === 'get_trends') {
        $rssUrl  = 'https://trends.google.com/trends/trendingsearches/daily/rss?geo=US';
        $ch      = curl_init($rssUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; numerology-admin/1.0)',
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $xml  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!$xml || $code !== 200) {
            echo json_encode(['success' => false, 'error' => 'Kunne ikke hente Trends-feed (HTTP ' . $code . ')']);
            exit;
        }
        // Suppres libxml warnings for namespaced elements
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        if (!$doc) { echo json_encode(['success' => false, 'error' => 'XML-parsing fejlede']); exit; }
        $items = [];
        foreach ($doc->channel->item as $item) {
            $title = (string)$item->title;
            // ht:approx_traffic lives in the ht namespace
            $ht = $item->children('ht', true);
            $traffic = isset($ht->approx_traffic) ? (string)$ht->approx_traffic : '';
            if ($title) $items[] = ['title' => $title, 'traffic' => $traffic];
            if (count($items) >= 20) break;
        }
        echo json_encode(['success' => true, 'items' => $items]);
        exit;
    }

    // ─ Hent gemt indhold ─
    if ($action === 'get_saved') {
        $res   = $db->query("SELECT id, title, type, topic, created_at FROM content_generated ORDER BY created_at DESC LIMIT 50");
        $rows  = [];
        if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
        echo json_encode(['success' => true, 'items' => $rows]);
        exit;
    }

    // ─ Hent ét stykke gemt indhold ─
    if ($action === 'get_item') {
        $id   = (int)($_GET['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'error' => 'Mangler id']); exit; }
        $stmt = $db->prepare("SELECT * FROM content_generated WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row  = $stmt->get_result()->fetch_assoc();
        echo json_encode($row ? ['success' => true, 'item' => $row] : ['success' => false, 'error' => 'Ikke fundet']);
        exit;
    }

    // ─ Slet ─
    if ($action === 'delete') {
        $id   = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'error' => 'Mangler id']); exit; }
        $stmt = $db->prepare("DELETE FROM content_generated WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Ukendt handling']);
    exit;
}

// ── Sidedata ──────────────────────────────────────────────────────────────────
$hasClaude = !empty($claudeKey);
?>
<!DOCTYPE html>
<html lang="da">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Indhold — Admin</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f2f5; color: #1a1a2e; font-size: 14px; }
.layout { display: grid; grid-template-columns: 220px 1fr; min-height: 100vh; }

/* Sidebar */
.sidebar { background: #1a1a2e; color: #ccc; position: sticky; top: 0; height: 100vh; overflow-y: auto; }
.sidebar-logo { padding: 18px 20px; border-bottom: 1px solid #2a2a3e; }
.sidebar-logo h2 { color: #c9a84c; font-size: 11px; letter-spacing: 1.5px; text-transform: uppercase; }
.sidebar-logo p  { color: #555; font-size: 11px; margin-top: 2px; }
.sidebar-section { padding: 14px 20px 4px; font-size: 10px; color: #444; text-transform: uppercase; letter-spacing: 1px; }
.sidebar-nav a { display: flex; align-items: center; gap: 8px; padding: 9px 20px; color: #888; text-decoration: none; font-size: 13px; transition: all .15s; border-left: 3px solid transparent; }
.sidebar-nav a:hover { color: #ddd; background: #1f2338; }
.sidebar-nav a.active { color: #c9a84c; background: #1f2338; border-left-color: #c9a84c; }
.sidebar-back { display: block; padding: 8px 20px; color: #555; font-size: 11px; text-decoration: none; border-top: 1px solid #2a2a3e; margin-top: auto; }
.sidebar-back:hover { color: #888; }

/* Main */
.main { padding: 24px; overflow-y: auto; }
.page-title { font-size: 20px; font-weight: 700; color: #1a1a2e; margin-bottom: 20px; }

/* Cards */
.card { background: white; border-radius: 8px; padding: 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.07); }
.card-title { font-size: 13px; font-weight: 700; color: #1a1a2e; margin-bottom: 16px; display: flex; align-items: center; gap: 6px; }

/* Form */
label { display: block; font-size: 11px; font-weight: 600; color: #666; margin-bottom: 5px; text-transform: uppercase; letter-spacing: .5px; }
input[type=text], textarea, select {
    width: 100%; padding: 9px 12px; border: 1px solid #e0e0e0; border-radius: 6px;
    font-size: 13px; background: #fafafa; transition: border .2s; font-family: inherit;
}
input[type=text]:focus, textarea:focus, select:focus { outline: none; border-color: #c9a84c; background: white; }
textarea { resize: vertical; min-height: 90px; }
.form-row { margin-bottom: 14px; }
.form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.form-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; }

/* Toggle */
.toggle-group { display: flex; border: 1px solid #e0e0e0; border-radius: 6px; overflow: hidden; }
.toggle-group input { display: none; }
.toggle-group label { flex: 1; padding: 8px 10px; cursor: pointer; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; color: #999; background: #fafafa; text-align: center; margin: 0; transition: all .15s; border-right: 1px solid #e0e0e0; }
.toggle-group label:last-of-type { border-right: none; }
.toggle-group input:checked + label { background: #1a1a2e; color: #c9a84c; }

/* Buttons */
.btn { padding: 8px 16px; border: none; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all .15s; display: inline-flex; align-items: center; gap: 5px; }
.btn-gold   { background: #c9a84c; color: white; }
.btn-gold:hover { background: #b8962a; }
.btn-dark   { background: #1a1a2e; color: #c9a84c; }
.btn-dark:hover { background: #2a2a40; }
.btn-ghost  { background: #f0f2f5; color: #555; }
.btn-ghost:hover { background: #e2e4e8; }
.btn-danger { background: #fee2e2; color: #dc2626; }
.btn-danger:hover { background: #fecaca; }
.btn-sm { padding: 5px 10px; font-size: 11px; }
.btn-group { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
.btn:disabled { opacity: .5; cursor: not-allowed; }

/* Chips */
.chip-wrap { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
.chip { padding: 5px 13px; background: #f5f5f5; border: 1px solid #e0e0e0; border-radius: 20px; font-size: 12px; cursor: pointer; transition: all .15s; user-select: none; }
.chip:hover, .chip.active { background: #1a1a2e; color: #c9a84c; border-color: #1a1a2e; }
.chip small { color: inherit; opacity: .7; font-size: 10px; }

/* Table */
table { width: 100%; border-collapse: collapse; }
th { text-align: left; padding: 8px 12px; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: #999; border-bottom: 2px solid #f0f0f0; }
td { padding: 10px 12px; border-bottom: 1px solid #f5f5f5; font-size: 13px; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: #fafafa; }
.badge { padding: 3px 9px; border-radius: 12px; font-size: 11px; font-weight: 600; }
.badge-artikel     { background: #dbeafe; color: #1d4ed8; }
.badge-nyhedsbrev  { background: #dcfce7; color: #166534; }
.badge-social      { background: #fce7f3; color: #9d174d; }

/* Status */
.status { padding: 10px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; display: none; }
.status-ok    { background: #dcfce7; color: #166534; display: block; }
.status-err   { background: #fee2e2; color: #dc2626; display: block; }
.status-info  { background: #dbeafe; color: #1d4ed8; display: block; }

/* Loader */
.spin { display: inline-block; width: 13px; height: 13px; border: 2px solid rgba(255,255,255,.3); border-top-color: white; border-radius: 50%; animation: spin .7s linear infinite; }
.spin-dark { border-color: #ddd; border-top-color: #c9a84c; }
@keyframes spin { to { transform: rotate(360deg); } }

/* Alert banner */
.alert-warn { background: #fef9c3; color: #854d0e; padding: 10px 14px; border-radius: 6px; font-size: 12px; margin-bottom: 14px; }

@media (max-width: 768px) {
    .layout { grid-template-columns: 1fr; }
    .sidebar { position: static; height: auto; }
    .form-grid-2, .form-grid-3 { grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<div class="layout">

<!-- SIDEBAR -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <h2>Strategic Numerology</h2>
    <p>Indholdsgenerator</p>
  </div>
  <nav class="sidebar-nav">
    <div class="sidebar-section">Opret</div>
    <a href="#" id="nav-ideas"    onclick="showTab('ideas',this);return false">💡 Emneforslag</a>
    <div class="sidebar-section">Administrer</div>
    <a href="#" id="nav-saved"    onclick="showTab('saved',this);return false">📁 Gemt indhold</a>
  </nav>
  <a class="sidebar-back" href="/input-generelt.html">← Tilbage til admin</a>
</aside>

<!-- MAIN -->
<main class="main">
  <div id="status" class="status"></div>

  <?php if (!$hasClaude): ?>
  <div class="alert-warn">⚠️ ANTHROPIC_API_KEY er ikke konfigureret — emneforslag er ikke tilgængeligt.</div>
  <?php endif; ?>

  <!-- ══ EMNEFORSLAG ══════════════════════════════════════════════ -->
  <div id="tab-ideas" style="display:none">
    <div class="card">
      <div class="card-title">💡 Emneforslag fra din vidensbase</div>
      <div class="form-row">
        <label>Startpunkt</label>
        <input type="text" id="idea-seed" placeholder="Fx kærlighed, karriere, personligt år" value="numerologi">
      </div>
      <button class="btn btn-gold" onclick="loadIdeas()" <?= $hasClaude ? '' : 'disabled' ?>>Generer forslag</button>
      <div id="idea-results" style="margin-top:16px"></div>
    </div>

    <div class="card">
      <div class="card-title" style="justify-content:space-between">
        <span>🇺🇸 Trending i USA lige nu</span>
        <button class="btn btn-ghost btn-sm" id="btn-trends" onclick="loadTrends()">↻ Opdatér</button>
      </div>
      <p style="font-size:12px;color:#aaa;margin-bottom:12px">Klik på et trend for at kopiere det til udklipsholderen som inspiration.</p>
      <div id="trends-list"><span class="spin spin-dark"></span></div>
    </div>
  </div>

  <!-- ══ GEMT INDHOLD ══════════════════════════════════════════════ -->
  <div id="tab-saved" style="display:none">
    <div class="card">
      <div class="card-title">📁 Gemt indhold</div>
      <div id="saved-list"><p style="color:#aaa;font-size:13px">Indlæser...</p></div>
    </div>
  </div>

</main>
</div>

<script>

// ── Tab-skift ─────────────────────────────────────────────────────────────────
function showTab(name, el) {
    ['ideas','saved'].forEach(t => {
        document.getElementById('tab-' + t).style.display = t === name ? 'block' : 'none';
    });
    document.querySelectorAll('.sidebar-nav a').forEach(a => a.classList.remove('active'));
    if (el) el.classList.add('active');
    if (name === 'saved') loadSaved();
    if (name === 'ideas' && document.getElementById('trends-list').querySelector('.spin-dark')) loadTrends();
}

// ── Status ────────────────────────────────────────────────────────────────────
function setStatus(msg, type = 'info') {
    const el = document.getElementById('status');
    el.textContent = msg;
    el.className = 'status status-' + type;
    if (type !== 'err') setTimeout(() => el.className = 'status', 4000);
}

// ── POST helper ───────────────────────────────────────────────────────────────
async function post(data, isFile = false) {
    const opts = { method: 'POST' };
    if (isFile) {
        opts.body = data; // FormData
    } else {
        opts.headers = { 'Content-Type': 'application/x-www-form-urlencoded' };
        opts.body = Object.entries(data).map(([k,v]) => `${k}=${encodeURIComponent(v)}`).join('&');
    }
    const res = await fetch(window.location.pathname, opts);
    return res.json();
}

// ── Google Trends USA ────────────────────────────────────────────────────────────────
async function loadTrends() {
    const box = document.getElementById('trends-list');
    const btn = document.getElementById('btn-trends');
    box.innerHTML = '<span class="spin spin-dark"></span>';
    if (btn) btn.disabled = true;
    try {
        const data = await fetch(window.location.pathname + '?action=get_trends').then(r => r.json());
        if (!data.success) {
            box.innerHTML = '<p style="color:#dc2626;font-size:12px">Fejl: ' + escHtml(data.error || 'Ukendt') + '</p>';
            return;
        }
        const wrap = document.createElement('div');
        wrap.className = 'chip-wrap';
        (data.items || []).forEach(item => {
            const c = document.createElement('div');
            c.className = 'chip';
            c.innerHTML = escHtml(item.title) + (item.traffic ? ' <small>' + escHtml(item.traffic) + ' søgn.</small>' : '');
            c.title = 'Klik for at kopiere til udklipsholder';
            c.onclick = () => {
                navigator.clipboard.writeText(item.title).then(() =>
                    setStatus('Kopieret: "' + escHtml(item.title) + '"', 'ok')
                );
            };
            wrap.appendChild(c);
        });
        box.innerHTML = '';
        box.appendChild(wrap);
    } finally {
        if (btn) btn.disabled = false;
    }
}
// ── Emneforslag-tab ───────────────────────────────────────────────────────────
async function loadIdeas() {
    const seed     = document.getElementById('idea-seed').value || 'numerologi';
    const language = document.querySelector('input[name=lang]:checked').value;
    const box      = document.getElementById('idea-results');
    box.innerHTML = '<span class="spin spin-dark"></span> Genererer...';
    const data = await post({ action: 'get_suggestions', topic: seed, language });
    if (!data.success) { box.innerHTML = '<p style="color:#dc2626">Fejl: ' + (data.error || 'Ukendt') + '</p>'; return; }
    const wrap = document.createElement('div');
    wrap.className = 'chip-wrap';
    (data.suggestions || []).forEach(s => {
        const c = document.createElement('div');
        c.className = 'chip';
        c.innerHTML = s.title + ' <small>' + (s.type || '') + '</small>';
        c.onclick = () => {
            document.getElementById('topic').value = s.title;
            document.getElementById('keywords').value = s.keywords || '';
            const r = document.querySelector(`input[name=ctype][value="${s.type}"]`);
            if (r) r.checked = true;
            showTab('generate', document.getElementById('nav-generate'));
        };
        wrap.appendChild(c);
    });
    box.innerHTML = '';
    box.appendChild(wrap);
}

// ── Gemt indhold ──────────────────────────────────────────────────────────────
async function loadSaved() {
    const box = document.getElementById('saved-list');
    box.innerHTML = '<p style="color:#aaa;font-size:13px">Indlæser...</p>';
    const data = await fetch(window.location.pathname + '?action=get_saved').then(r => r.json());
    if (!data.success || !data.items.length) {
        box.innerHTML = '<p style="color:#aaa;font-size:13px">Intet gemt indhold endnu.</p>';
        return;
    }
    const table = document.createElement('table');
    table.innerHTML = `<thead><tr><th>Titel</th><th>Type</th><th>Dato</th><th></th></tr></thead>`;
    const tbody = document.createElement('tbody');
    data.items.forEach(row => {
        const tr = document.createElement('tr');
        const date = new Date(row.created_at).toLocaleDateString('da-DK', { day:'2-digit', month:'short', year:'numeric' });
        tr.innerHTML = `
            <td>${escHtml(row.title)}</td>
            <td><span class="badge badge-${row.type}">${row.type}</span></td>
            <td style="color:#aaa">${date}</td>
            <td>
                <button class="btn btn-ghost btn-sm" onclick="viewItem(${row.id})">Vis</button>
                <button class="btn btn-danger btn-sm" onclick="deleteItem(${row.id}, this)">Slet</button>
            </td>`;
        tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    box.innerHTML = '';
    box.appendChild(table);
}

async function viewItem(id) {
    const data = await fetch(window.location.pathname + '?action=get_item&id=' + id).then(r => r.json());
    if (!data.success) { setStatus('Kunne ikke hente indhold.', 'err'); return; }
    const item = data.item;
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:flex;align-items:flex-start;justify-content:center;padding:40px 20px;overflow-y:auto';
    const box = document.createElement('div');
    box.style.cssText = 'background:white;border-radius:10px;padding:24px;max-width:720px;width:100%;position:relative';
    const closeBtn = document.createElement('button');
    closeBtn.textContent = '✕';
    closeBtn.style.cssText = 'position:absolute;top:12px;right:14px;background:none;border:none;font-size:20px;cursor:pointer;color:#888';
    closeBtn.onclick = () => overlay.remove();
    const h = document.createElement('h2');
    h.style.cssText = 'font-size:16px;margin-bottom:16px;color:#1a1a2e';
    h.textContent = item.title || '';
    const pre = document.createElement('pre');
    pre.style.cssText = 'white-space:pre-wrap;font-family:inherit;font-size:13px;line-height:1.7;color:#333;max-height:60vh;overflow-y:auto';
    pre.textContent = item.body || '';
    const footer = document.createElement('div');
    footer.style.cssText = 'margin-top:16px;text-align:right';
    const copyBtn = document.createElement('button');
    copyBtn.className = 'btn btn-gold';
    copyBtn.textContent = '📋 Kopiér';
    copyBtn.onclick = () => navigator.clipboard.writeText(item.body || '').then(() => setStatus('Kopiéret!', 'ok'));
    footer.appendChild(copyBtn);
    box.append(closeBtn, h, pre, footer);
    overlay.appendChild(box);
    overlay.onclick = e => { if (e.target === overlay) overlay.remove(); };
    document.body.appendChild(overlay);
}

async function deleteItem(id, btn) {
    if (!confirm('Slet dette indhold?')) return;
    btn.disabled = true;
    const data = await post({ action: 'delete', id });
    if (data.success) { btn.closest('tr').remove(); setStatus('Slettet.', 'ok'); }
    else { btn.disabled = false; setStatus('Sletning fejlede.', 'err'); }
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>
