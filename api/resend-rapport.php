<?php
/* ============================================================
   /api/resend-rapport.php
   Admin-endpoint: gensend rapport-email for en given ordre.
   Kræver aktiv admin-session eller X-Admin-Key header.
   POST { "orderId": 42 }
   ============================================================ */

error_reporting(E_ERROR);
ini_set('display_errors', '0');
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit;
}

// ── Auth: session ELLER X-Admin-Key ──
session_start();
$hasSession = !empty($_SESSION['admin_auth']);
$adminKey   = CFG_ADMIN_KEY;
$sentKey    = $_SERVER['HTTP_X_ADMIN_KEY'] ?? '';
$hasKey     = $adminKey && hash_equals($adminKey, $sentKey);

if (!$hasSession && !$hasKey) {
    http_response_code(401);
    echo json_encode(['error' => 'Uautoriseret']);
    exit;
}

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$orderId = (int)($body['orderId'] ?? 0);

if (!$orderId) {
    http_response_code(400);
    echo json_encode(['error' => 'orderId mangler']);
    exit;
}

$db   = getDB();
$stmt = $db->prepare('SELECT * FROM orders WHERE id = ?');
$stmt->bind_param('i', $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    http_response_code(404);
    echo json_encode(['error' => 'Ordre ikke fundet']);
    exit;
}
if (empty($order['rapport_html'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Rapport ikke genereret endnu']);
    exit;
}

$planNames = ['foundation' => 'Foundation Report', 'direction' => 'Direction Report', 'activation' => 'Activation Report'];
$planName  = $planNames[$order['plan']] ?? 'Numerological Reading';

// buildEmailHtml er defineret i stripe-webhook.php — definer den her inline
function buildEmailHtml(string $fullName, string $planName, string $rapportHtml): string {
    $safeName = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
    $safePlan = htmlspecialchars($planName, ENT_QUOTES, 'UTF-8');
    return <<<HTML
<!DOCTYPE html>
<html lang="da">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$safePlan} — StrategicNumerology</title>
</head>
<body style="margin:0;padding:0;background:#0b0b12;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;color:#e8e4da;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#0b0b12;padding:40px 20px;">
    <tr><td align="center">
      <table width="620" cellpadding="0" cellspacing="0" style="max-width:620px;width:100%;">
        <tr>
          <td style="padding:0 0 32px 0;text-align:center;">
            <p style="margin:0;font-size:11px;letter-spacing:2px;text-transform:uppercase;color:#c9a84c;font-weight:600;">StrategicNumerology</p>
            <h1 style="margin:12px 0 0;font-size:28px;font-weight:700;color:#e8d5a3;line-height:1.2;">{$safePlan}</h1>
            <p style="margin:8px 0 0;font-size:14px;color:#8c8c9b;">Udarbejdet til {$safeName}</p>
            <hr style="border:none;border-top:1px solid rgba(201,168,76,0.2);margin:24px 0 0;">
          </td>
        </tr>
        <tr>
          <td style="background:#13131f;border:1px solid rgba(201,168,76,0.15);border-radius:12px;padding:36px 40px;">
            <div style="font-size:15px;line-height:1.8;color:#ddd8ca;">
              {$rapportHtml}
            </div>
          </td>
        </tr>
        <tr>
          <td style="padding:32px 0 0;text-align:center;">
            <p style="margin:0;font-size:12px;color:#555566;">
              Du modtager denne email fordi du har købt en rapport hos StrategicNumerology.<br>
              &copy; StrategicNumerology — <a href="https://alexandarmartin.dk" style="color:#c9a84c;text-decoration:none;">alexandarmartin.dk</a>
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}

$emailHtml = buildEmailHtml($order['full_name'], $planName, $order['rapport_html']);
$sent      = sendEmail($order['email'], "Your {$planName} — StrategicNumerology", $emailHtml);

if ($sent) {
    $db->query("UPDATE orders SET status='sent', sent_at=NOW() WHERE id=" . (int)$orderId);
    echo json_encode(['ok' => true, 'message' => 'Email sendt til ' . $order['email']]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Email-afsendelse fejlede (kontrollér mailkonfiguration)']);
}
