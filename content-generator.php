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

// ── API-nøgler (samme mønster som generate-namereading.php) ──────────────────
$_ANTHROPIC_API_KEY = '';
$_OPENAI_API_KEY    = '';
$envFile = __DIR__ . '/api/.env.php';
if (file_exists($envFile)) include $envFile;
$claudeKey = getenv('ANTHROPIC_API_KEY') ?: ($_ANTHROPIC_API_KEY ?? '');
$openaiKey = getenv('OPENAI_API_KEY')    ?: ($_OPENAI_API_KEY    ?? '');

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

// ── DALL-E 3 billedgenerering ─────────────────────────────────────────────────
function generateDalle(string $prompt, string $apiKey): array {
    $payload = json_encode([
        'model'   => 'dall-e-3',
        'prompt'  => $prompt,
        'n'       => 1,
        'size'    => '1024x1024',
        'quality' => 'standard',
    ]);
    $ch = curl_init('https://api.openai.com/v1/images/generations');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => 60,
    ]);
    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return ['success' => false, 'error' => 'DALL-E fejl (HTTP ' . $code . ')'];
    $data     = json_decode($response, true);
    $imageUrl = $data['data'][0]['url'] ?? null;
    if (!$imageUrl) return ['success' => false, 'error' => 'Intet billede i svar'];

    // Gem lokalt i assets/images/content/
    $dir = __DIR__ . '/assets/images/content/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $imageData = file_get_contents($imageUrl);
    $filename  = 'dalle_' . time() . '_' . substr(md5(uniqid()), 0, 8) . '.png';
    file_put_contents($dir . $filename, $imageData);
    return ['success' => true, 'url' => 'assets/images/content/' . $filename];
}

// ── DB kontekst fra dine egne tabeller ───────────────────────────────────────
function getDbContext(mysqli $db, string $language = 'da'): string {
    if ($language === 'en') {
        $ctx = "NUMEROLOGY KNOWLEDGE BASE:\n\n";
        $res = $db->query("SELECT id, keywords, grundenergi, beskrivelse FROM diamant_energies ORDER BY id ASC LIMIT 9");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $ctx .= "Number {$row['id']}:\n";
                if (!empty($row['keywords']))    $ctx .= "  Keywords: {$row['keywords']}\n";
                if (!empty($row['grundenergi'])) $ctx .= "  Core energy: {$row['grundenergi']}\n";
                if (!empty($row['beskrivelse'])) $ctx .= "  Description: {$row['beskrivelse']}\n";
                $ctx .= "\n";
            }
        }
        $gen = $db->query("SELECT aboutNumerology, defRent, defUrent FROM generelt WHERE id = 1");
        if ($gen && $gen->num_rows) {
            $g = $gen->fetch_assoc();
            if (!empty($g['aboutNumerology'])) $ctx .= "## About numerology\n{$g['aboutNumerology']}\n\n";
            if (!empty($g['defRent']))         $ctx .= "## Pure numeroscope\n{$g['defRent']}\n\n";
            if (!empty($g['defUrent']))        $ctx .= "## Impure numeroscope\n{$g['defUrent']}\n\n";
        }
    } else {
        $ctx = "NUMEROLOGISK VIDENSBASE:\n\n";
        $res = $db->query("SELECT id, keywords, grundenergi, beskrivelse FROM diamant_energies ORDER BY id ASC LIMIT 9");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $ctx .= "Tal {$row['id']}:\n";
                if (!empty($row['keywords']))    $ctx .= "  Nøgleord: {$row['keywords']}\n";
                if (!empty($row['grundenergi'])) $ctx .= "  Grundenergi: {$row['grundenergi']}\n";
                if (!empty($row['beskrivelse'])) $ctx .= "  Beskrivelse: {$row['beskrivelse']}\n";
                $ctx .= "\n";
            }
        }
        $gen = $db->query("SELECT aboutNumerology, defRent, defUrent FROM generelt WHERE id = 1");
        if ($gen && $gen->num_rows) {
            $g = $gen->fetch_assoc();
            if (!empty($g['aboutNumerology'])) $ctx .= "## Om numerologi\n{$g['aboutNumerology']}\n\n";
            if (!empty($g['defRent']))         $ctx .= "## Rent numeroskop\n{$g['defRent']}\n\n";
            if (!empty($g['defUrent']))        $ctx .= "## Urent numeroskop\n{$g['defUrent']}\n\n";
        }
    }
    return $ctx;
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

    // ─ Generer indhold ─
    if ($action === 'generate') {
        if (!$claudeKey) { echo json_encode(['success' => false, 'error' => 'ANTHROPIC_API_KEY ikke konfigureret']); exit; }
        $topic      = trim($_POST['topic'] ?? '');
        $type       = in_array($_POST['type'] ?? '', ['artikel','nyhedsbrev','social']) ? $_POST['type'] : 'artikel';
        $keywords   = mb_substr(trim($_POST['keywords'] ?? ''), 0, 300);
        $dataSource = ($_POST['data_source'] ?? 'db') === 'db' ? 'db' : 'general';
        $language   = in_array($_POST['language'] ?? 'da', ['da','en']) ? $_POST['language'] : 'da';
        if (empty($topic)) { echo json_encode(['success' => false, 'error' => 'Intet emne angivet']); exit; }
        $topic = mb_substr($topic, 0, 300);

        $dbCtx = getDbContext($db, $language);

        if ($language === 'en') {
            $langNote   = "You MUST write exclusively in English. Do not use any Danish words.";
            $sourceRule = $dataSource === 'db'
                ? "You MUST only use the numerological knowledge from the knowledge base below. Do NOT add knowledge from other sources."
                : "Use the knowledge base as the primary source — you may supplement with general numerological knowledge.";
            $typeInstr = match($type) {
                'artikel'    => "Write a complete SEO article (800-1200 words). Include: H1 headline, meta description (max 160 chars), introduction, 3-4 H2 sections and conclusion. Use HTML tags (h1, h2, p, strong). Place the keyword naturally.",
                'nyhedsbrev' => "Write an engaging newsletter (400-600 words). Include: subject line, preview text, warm greeting, 2-3 insight sections and a clear call-to-action.",
                'social'     => "Write 3 social media variants:\n1. LinkedIn (200 words, professional tone)\n2. Instagram caption (150 words + 10 hashtags)\n3. Facebook post (100 words, casual tone)\nSeparate with ---",
                default      => "Write engaging content about the topic.",
            };
            $system = "{$langNote}\n{$sourceRule}\n\n{$dbCtx}";
            $user   = "Topic: {$topic}\nKeywords: {$keywords}\n\nTask: {$typeInstr}";
        } else {
            $langNote   = "Du SKAL skrive udelukkende på dansk. Brug ikke engelske ord.";
            $sourceRule = $dataSource === 'db'
                ? "Du MÅ KUN bruge den numerologiske viden fra vidensbasen nedenfor. Tilføj IKKE viden fra andre kilder."
                : "Brug vidensbasen som primær kilde — du må supplere med generel numerologisk viden.";
            $typeInstr = match($type) {
                'artikel'    => "Skriv en komplet SEO-artikel (800-1200 ord). Inkluder: H1-overskrift, metabeskrivelse (maks 160 tegn), indledning, 3-4 H2-sektioner og konklusion. Brug HTML-tags (h1, h2, p, strong). Placer søgeordet naturligt.",
                'nyhedsbrev' => "Skriv et engagerende nyhedsbrev (400-600 ord). Inkluder: Emnelinjen, preview-tekst, varm hilsen, 2-3 sektioner med indsigt og en tydelig call-to-action.",
                'social'     => "Skriv 3 sociale medie-varianter:\n1. LinkedIn (200 ord, professionel tone)\n2. Instagram caption (150 ord + 10 hashtags)\n3. Facebook-opslag (100 ord, uformel tone)\nAdskil med ---",
                default      => "Skriv engagerende indhold om emnet.",
            };
            $system = "{$langNote}\n{$sourceRule}\n\n{$dbCtx}";
            $user   = "Emne: {$topic}\nSøgeord: {$keywords}\n\nOpgave: {$typeInstr}";
        }
        $text   = callClaude($system, $user, $claudeKey, 2500);
        echo json_encode(['success' => true, 'content' => $text]);
        exit;
    }

    // ─ Generer billede ─
    if ($action === 'generate_image') {
        if (!$openaiKey) { echo json_encode(['success' => false, 'error' => 'OPENAI_API_KEY ikke konfigureret']); exit; }
        $topic  = mb_substr(trim($_POST['topic'] ?? 'numerologi mystisk'), 0, 200);
        $prompt = "Mystisk numerologi-illustration til: {$topic}. Mørk baggrund, gyldne hellige geometrier, kosmisk og spirituel æstetik, ingen tekst, professionel og elegant.";
        $result = generateDalle($prompt, $openaiKey);
        echo json_encode($result);
        exit;
    }

    // ─ Upload billede ─
    if ($action === 'upload_image') {
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'error' => 'Upload fejlede']); exit;
        }
        $file    = $_FILES['image'];
        // Tjek MIME via fileinfo (ikke kun filendelse)
        $finfo   = finfo_open(FILEINFO_MIME_TYPE);
        $mime    = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mime, $allowed, true)) {
            echo json_encode(['success' => false, 'error' => 'Filtype ikke tilladt']); exit;
        }
        $ext      = ['image/jpeg' => '.jpg', 'image/png' => '.png', 'image/webp' => '.webp'][$mime];
        $dir      = __DIR__ . '/assets/images/content/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $filename = 'upload_' . time() . '_' . substr(md5(uniqid()), 0, 8) . $ext;
        if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
            echo json_encode(['success' => false, 'error' => 'Kunne ikke gemme fil']); exit;
        }
        echo json_encode(['success' => true, 'url' => 'assets/images/content/' . $filename]);
        exit;
    }

    // ─ Gem indhold ─
    if ($action === 'save') {
        $title   = mb_substr(trim($_POST['title']   ?? ''), 0, 255);
        $topic   = mb_substr(trim($_POST['topic']   ?? ''), 0, 255);
        $type    = in_array($_POST['type'] ?? '', ['artikel','nyhedsbrev','social']) ? $_POST['type'] : 'artikel';
        $kw      = mb_substr(trim($_POST['keywords'] ?? ''), 0, 500);
        $body    = trim($_POST['body'] ?? '');
        $imgUrl  = mb_substr(trim($_POST['image_url'] ?? ''), 0, 500);
        $src     = ($_POST['data_source'] ?? 'db') === 'db' ? 'db' : 'general';
        if (empty($title) || empty($body)) { echo json_encode(['success' => false, 'error' => 'Titel og indhold er påkrævet']); exit; }
        $stmt = $db->prepare("INSERT INTO content_generated (title, type, topic, keywords, body, image_url, data_source, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param('sssssss', $title, $type, $topic, $kw, $body, $imgUrl, $src);
        $stmt->execute();
        echo json_encode(['success' => true, 'id' => $db->insert_id]);
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

    // ─ Google Trends USA (RSS, ingen API-nøgle) ─
    if ($action === 'get_trends') {
        $rssUrl = 'https://trends.google.com/trends/trendingsearches/daily/rss?geo=US';
        $ch = curl_init($rssUrl);
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
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        if (!$doc) { echo json_encode(['success' => false, 'error' => 'XML-parsing fejlede']); exit; }
        $items = [];
        foreach ($doc->channel->item as $item) {
            $title = (string)$item->title;
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
$hasOpenai = !empty($openaiKey);
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

/* Editor / Preview */
.view-toggle { display: flex; gap: 6px; margin-bottom: 12px; }
.view-toggle button { padding: 5px 12px; border: 1px solid #e0e0e0; border-radius: 5px; background: #f5f5f5; color: #777; font-size: 11px; font-weight: 600; cursor: pointer; transition: all .15s; }
.view-toggle button.active { background: #1a1a2e; color: #c9a84c; border-color: #1a1a2e; }
#editor-box { width: 100%; min-height: 320px; padding: 16px; border: 1px solid #e8e8e8; border-radius: 6px; background: #fafafa; font-size: 13px; font-family: 'Courier New', monospace; line-height: 1.7; resize: vertical; color: #1a1a2e; }
#editor-box:focus { outline: none; border-color: #c9a84c; background: white; }
#preview-box { background: #fafafa; border: 1px solid #e8e8e8; border-radius: 6px; padding: 20px; min-height: 150px; line-height: 1.8; font-size: 14px; display: none; }
#preview-box h1 { font-size: 20px; color: #1a1a2e; margin-bottom: 12px; }
#preview-box h2 { font-size: 16px; color: #1a1a2e; margin: 16px 0 8px; }
#preview-box h3 { font-size: 14px; color: #1a1a2e; margin: 12px 0 6px; }
#preview-box p  { margin-bottom: 10px; }
#preview-box strong { font-weight: 700; }

/* Image */
.img-slot { border: 1px dashed #ddd; border-radius: 6px; padding: 12px; min-height: 70px; display: flex; align-items: center; gap: 10px; background: #fafafa; margin-bottom: 8px; }
.img-slot img { height: 60px; border-radius: 4px; object-fit: cover; }
.img-slot p { color: #bbb; font-size: 12px; }

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
  <div class="alert-warn">⚠️ ANTHROPIC_API_KEY er ikke konfigureret — tekstgenerering er ikke tilgængeligt.</div>
  <?php endif; ?>
  <?php if (!$hasOpenai): ?>
  <div class="alert-warn">⚠️ OPENAI_API_KEY er ikke konfigureret — DALL-E billedgenerering er ikke tilgængeligt.</div>
  <?php endif; ?>

  <!-- ══ GENERER ══════════════════════════════════════════════════ -->
  <div id="tab-generate">

    <div class="card">
      <div class="card-title">✍️ Generer indhold</div>

      <div class="form-grid-2">
        <div class="form-row">
          <label>Emne / Titel</label>
          <input type="text" id="topic" placeholder="Fx Livsstien 9 — den vise sjæl">
        </div>
        <div class="form-row">
          <label>Søgeord</label>
          <input type="text" id="keywords" placeholder="Fx livsstien 9, numerologi personlighed">
        </div>
      </div>

      <div class="form-grid-3">
        <div class="form-row">
          <label>Indholdstype</label>
          <div class="toggle-group">
            <input type="radio" name="ctype" id="t-artikel"    value="artikel"    checked>
            <label for="t-artikel">Artikel</label>
            <input type="radio" name="ctype" id="t-nyhedsbrev" value="nyhedsbrev">
            <label for="t-nyhedsbrev">Nyhedsbrev</label>
            <input type="radio" name="ctype" id="t-social"     value="social">
            <label for="t-social">Social</label>
          </div>
        </div>
        <div class="form-row">
          <label>Datakilde</label>
          <div class="toggle-group">
            <input type="radio" name="dsrc" id="d-db"      value="db"      checked>
            <label for="d-db">🔒 Min DB</label>
            <input type="radio" name="dsrc" id="d-general" value="general">
            <label for="d-general">🌐 Generel</label>
          </div>
        </div>
        <div class="form-row">
          <label>Sprog</label>
          <div class="toggle-group">
            <input type="radio" name="lang" id="l-da" value="da" checked>
            <label for="l-da">🇩🇰 Dansk</label>
            <input type="radio" name="lang" id="l-en" value="en">
            <label for="l-en">🇬🇧 English</label>
          </div>
        </div>
      </div>

      <div class="btn-group">
        <button class="btn btn-ghost" onclick="getSuggestions()">💡 Emneforslag</button>
        <button class="btn btn-gold" id="btn-gen" onclick="generateContent()" <?= $hasClaude ? '' : 'disabled' ?>>⚡ Generer</button>
      </div>
    </div>

    <!-- Billede -->
    <div class="card">
      <div class="card-title">🖼 Billede</div>
      <div class="form-row">
        <label>Featured billede</label>
        <div class="img-slot" id="img-slot"><p>Intet billede valgt</p></div>
        <input type="hidden" id="img-url" value="">
        <div class="btn-group">
          <?php if ($hasOpenai): ?>
          <button class="btn btn-ghost btn-sm" id="btn-dalle" onclick="genDalle()">✨ AI-billede (DALL-E)</button>
          <?php endif; ?>
          <button class="btn btn-ghost btn-sm" onclick="document.getElementById('img-file').click()">⬆ Upload</button>
          <input type="file" id="img-file" accept="image/*" style="display:none" onchange="uploadImg(this)">
        </div>
      </div>
    </div>

    <!-- Preview / Editor -->
    <div class="card" id="preview-card" style="display:none">
      <div class="card-title">✏️ Rediger indhold</div>
      <div class="view-toggle">
        <button id="btn-view-edit" class="active" onclick="switchView('edit')">✏️ Redigér</button>
        <button id="btn-view-render" onclick="switchView('render')">👁 Vis formateret</button>
      </div>
      <textarea id="editor-box" placeholder="Genereret tekst vises her — du kan redigere direkte..."></textarea>
      <div id="preview-box"></div>
      <div class="btn-group">
        <button class="btn btn-gold"  onclick="saveContent()">💾 Gem i database</button>
        <button class="btn btn-ghost" onclick="copyContent()">📋 Kopiér</button>
        <button class="btn btn-ghost" onclick="generateContent()">🔄 Generer igen</button>
      </div>
    </div>

  </div>

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
      <p style="font-size:12px;color:#aaa;margin-bottom:12px">Klik på et trend for at bruge det som emneinspiration — genererer et numerologi-vinklet indholdsforslag.</p>
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
let generatedText = '';

// ── Tab-skift ─────────────────────────────────────────────────────────────────
function showTab(name, el) {
    ['generate','ideas','saved'].forEach(t => {
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

// ── Emneforslag ───────────────────────────────────────────────────────────────
async function getSuggestions() {
    const topic    = document.getElementById('topic').value || 'numerologi';
    const language = document.querySelector('input[name=lang]:checked').value;
    setStatus('Henter forslag...', 'info');
    const data = await post({ action: 'get_suggestions', topic, language });
    if (!data.success) { setStatus(data.error || 'Fejl', 'err'); return; }
    const wrap = document.createElement('div');
    wrap.className = 'chip-wrap';
    wrap.style.marginTop = '10px';
    (data.suggestions || []).forEach(s => {
        const c = document.createElement('div');
        c.className = 'chip';
        c.innerHTML = s.title + ' <small>' + (s.type || '') + '</small>';
        c.onclick = () => {
            document.getElementById('topic').value = s.title;
            document.getElementById('keywords').value = s.keywords || '';
            const r = document.querySelector(`input[name=ctype][value="${s.type}"]`);
            if (r) r.checked = true;
            document.querySelectorAll('.chip').forEach(x => x.classList.remove('active'));
            c.classList.add('active');
        };
        wrap.appendChild(c);
    });
    // Insert after btn-group if not already there
    const card = document.querySelector('#tab-generate .card');
    const existing = card.querySelector('.chip-wrap');
    if (existing) existing.replaceWith(wrap); else card.appendChild(wrap);
    setStatus('Forslag klar!', 'ok');
}

// ── Generer indhold ───────────────────────────────────────────────────────────
async function generateContent() {
    const topic = document.getElementById('topic').value.trim();
    if (!topic) { setStatus('Angiv et emne først.', 'err'); return; }
    const btn = document.getElementById('btn-gen');
    btn.innerHTML = '<span class="spin"></span> Genererer...';
    btn.disabled = true;
    setStatus('Genererer indhold — det tager 15–30 sekunder...', 'info');
    try {
        const data = await post({
            action:      'generate',
            topic,
            keywords:    document.getElementById('keywords').value,
            type:        document.querySelector('input[name=ctype]:checked').value,
            data_source: document.querySelector('input[name=dsrc]:checked').value,
            language:    document.querySelector('input[name=lang]:checked').value,
        });
        if (!data.success) { setStatus(data.error || 'Generering fejlede.', 'err'); return; }
        generatedText = data.content;
        loadIntoEditor(generatedText);
        setStatus('Indhold genereret!', 'ok');
    } finally {
        btn.innerHTML = '⚡ Generer';
        btn.disabled = false;
    }
}

// ── AI-billede ────────────────────────────────────────────────────────────────
async function genDalle() {
    const topic = document.getElementById('topic').value || 'numerologi mystik';
    const btn = document.getElementById('btn-dalle');
    if (btn) { btn.innerHTML = '<span class="spin spin-dark"></span> Genererer...'; btn.disabled = true; }
    setStatus('Genererer AI-billede...', 'info');
    try {
        const data = await post({ action: 'generate_image', topic });
        if (!data.success) { setStatus(data.error || 'Billedgenerering fejlede.', 'err'); return; }
        setImgSlot(data.url);
        setStatus('Billede klar!', 'ok');
    } finally {
        if (btn) { btn.innerHTML = '✨ AI-billede (DALL-E)'; btn.disabled = false; }
    }
}

// ── Upload billede ────────────────────────────────────────────────────────────
async function uploadImg(input) {
    const file = input.files[0];
    if (!file) return;
    const fd = new FormData();
    fd.append('action', 'upload_image');
    fd.append('image', file);
    setStatus('Uploader...', 'info');
    const data = await post(fd, true);
    if (!data.success) { setStatus(data.error || 'Upload fejlede.', 'err'); return; }
    setImgSlot(data.url);
    setStatus('Billede uploadet!', 'ok');
    input.value = '';
}

function setImgSlot(url) {
    document.getElementById('img-url').value = url;
    document.getElementById('img-slot').innerHTML = `<img src="${url}" alt=""> <span style="font-size:11px;color:#888">Billede valgt</span>`;
}

// ── View-skift (edit ↔ render) ────────────────────────────────────────────────
function switchView(mode) {
    const editor  = document.getElementById('editor-box');
    const preview = document.getElementById('preview-box');
    const btnEdit = document.getElementById('btn-view-edit');
    const btnRend = document.getElementById('btn-view-render');
    if (mode === 'render') {
        const text = editor.value;
        preview.innerHTML = text
            .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
            .replace(/^### (.+)$/gm, '<h3>$1</h3>')
            .replace(/^## (.+)$/gm,  '<h2>$1</h2>')
            .replace(/^# (.+)$/gm,   '<h1>$1</h1>')
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/\n/g, '<br>');
        editor.style.display  = 'none';
        preview.style.display = 'block';
        btnEdit.classList.remove('active');
        btnRend.classList.add('active');
    } else {
        editor.style.display  = 'block';
        preview.style.display = 'none';
        btnRend.classList.remove('active');
        btnEdit.classList.add('active');
    }
}

// ── Indlæs tekst i editoren ────────────────────────────────────────────────────
function loadIntoEditor(text) {
    document.getElementById('editor-box').value = text;
    switchView('edit');
    document.getElementById('preview-card').style.display = 'block';
    document.getElementById('preview-card').scrollIntoView({ behavior: 'smooth' });
}

// ── Gem ───────────────────────────────────────────────────────────────────────
async function saveContent() {
    const body = document.getElementById('editor-box').value.trim();
    if (!body) { setStatus('Intet indhold at gemme.', 'err'); return; }
    const topic = document.getElementById('topic').value;
    const firstLine = body.split('\n')[0].replace(/^#+\s*/, '').trim();
    const data = await post({
        action:      'save',
        title:       firstLine || topic,
        topic,
        keywords:    document.getElementById('keywords').value,
        type:        document.querySelector('input[name=ctype]:checked').value,
        data_source: document.querySelector('input[name=dsrc]:checked').value,
        body,
        image_url:   document.getElementById('img-url').value,
    });
    if (data.success) setStatus('Gemt! (ID: ' + data.id + ')', 'ok');
    else setStatus(data.error || 'Gem fejlede.', 'err');
}

function copyContent() {
    const body = document.getElementById('editor-box').value.trim();
    if (!body) return;
    navigator.clipboard.writeText(body).then(() => setStatus('Kopieret til udklipsholder!', 'ok'));
}
// ── Google Trends USA ───────────────────────────────────────────────────────────────
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
            c.title = 'Brug som emne med numerologi-vinkel';
            c.onclick = () => {
                document.getElementById('topic').value    = item.title + ' numerology';
                document.getElementById('keywords').value = item.title.toLowerCase() + ', numerology, spiritual meaning';
                document.querySelector('input[name=dsrc][value="general"]').checked = true;
                document.querySelector('input[name=lang][value="en"]').checked = true;
                showTab('generate', document.getElementById('nav-generate'));
                setStatus('Trend valgt — tryk ⋆ Generer for at skrive om "' + item.title + '"', 'info');
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
    document.getElementById('topic').value    = item.topic || '';
    document.getElementById('keywords').value = item.keywords || '';
    generatedText = item.body || '';
    if (item.image_url) setImgSlot(item.image_url);
    showTab('generate', document.getElementById('nav-generate'));
    loadIntoEditor(generatedText);
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
