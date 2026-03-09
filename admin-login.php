<?php
/**
 * Admin Login
 *
 * DEFAULT PASSWORD: Numerologi2025
 *
 * To change the password, generate a new hash and replace ADMIN_PASSWORD_HASH:
 *   php -r "echo password_hash('DitNyeKodeord', PASSWORD_BCRYPT);"
 */
session_start();

define('ADMIN_PASSWORD_HASH', '$2y$10$L9KbSmnP.Wva/8MalfWMHuT9DZiUYbNF9jgJeEkZdG1SyUm588f8m');

// Already logged in → redirect
if (!empty($_SESSION['admin_auth'])) {
    header('Location: /admin.php');
    exit;
}

$error = false;
$from  = '/admin.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw   = $_POST['password'] ?? '';
    $from = $_POST['from']     ?? '/admin.php';

    // Validate redirect target (only allow /input-*.html, /admin.php or /admin-rapporter.php)
    if (!preg_match('/^\/input-[a-z0-9-]+\.html$/', $from) && !in_array($from, ['/admin.php', '/admin-rapporter.php'])) {
        $from = '/admin.php';
    }

    if (password_verify($pw, ADMIN_PASSWORD_HASH)) {
        session_regenerate_id(true);
        $_SESSION['admin_auth'] = true;
        header('Location: ' . $from);
        exit;
    }

    $error = true;
    sleep(1); // slow brute-force
} else {
    $from = $_GET['from'] ?? '/admin.php';
    if (!preg_match('/^\/input-[a-z0-9-]+\.html$/', $from) && !in_array($from, ['/admin.php', '/admin-rapporter.php'])) {
        $from = '/admin.php';
    }
}
?>
<!doctype html>
<html lang="da">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin Login — StrategicNumerology</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
        crossorigin="anonymous">
  <style>
    body {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #111318;
      font-family: 'Inter', system-ui, sans-serif;
    }
    .login-card {
      width: 100%;
      max-width: 380px;
      background: #1a1a28;
      border: 1px solid rgba(201,168,76,.18);
      border-radius: 14px;
      padding: 2.5rem 2rem;
      box-shadow: 0 16px 48px rgba(0,0,0,.45);
    }
    .brand {
      font-size: 1.25rem;
      font-weight: 700;
      color: #c9a84c;
      letter-spacing: .03em;
      margin-bottom: .25rem;
    }
    .brand-sub {
      font-size: .68rem;
      letter-spacing: .2em;
      text-transform: uppercase;
      color: #6b6b80;
      margin-bottom: 2rem;
    }
    h1 { font-size: 1.1rem; color: #e8e4da; margin-bottom: 1.5rem; font-weight: 500; }
    label { color: #a0a0b0; font-size: .85rem; margin-bottom: .3rem; display: block; }
    input[type="password"] {
      width: 100%;
      padding: .75rem 1rem;
      background: #0e0e18;
      border: 1px solid rgba(201,168,76,.2);
      border-radius: 8px;
      color: #e8e4da;
      font-size: 1rem;
      outline: none;
      transition: border-color .2s;
    }
    input[type="password"]:focus { border-color: #c9a84c; }
    .btn-login {
      width: 100%;
      padding: .8rem;
      background: linear-gradient(135deg, #c9a84c, #b8943e);
      color: #0b0b12;
      font-weight: 600;
      font-size: .88rem;
      letter-spacing: .06em;
      text-transform: uppercase;
      border: none;
      border-radius: 50px;
      cursor: pointer;
      margin-top: 1.25rem;
      transition: box-shadow .2s, transform .15s;
    }
    .btn-login:hover {
      box-shadow: 0 6px 24px rgba(201,168,76,.3);
      transform: translateY(-1px);
    }
    .alert-error {
      background: rgba(220,53,69,.12);
      border: 1px solid rgba(220,53,69,.3);
      color: #f08080;
      padding: .65rem 1rem;
      border-radius: 8px;
      font-size: .85rem;
      margin-top: 1rem;
    }
    @media (max-width: 420px) {
      .login-card { margin: 1rem; padding: 2rem 1.4rem; }
    }
  </style>
</head>
<body>
  <div class="login-card">
    <div class="brand">StrategicNumerology</div>
    <div class="brand-sub">Admin</div>
    <h1>Log ind for at fortsætte</h1>

    <form method="post" action="/admin-login.php" autocomplete="off">
      <input type="hidden" name="from" value="<?= htmlspecialchars($from) ?>">
      <label for="pw">Kodeord</label>
      <input type="password" id="pw" name="password" autofocus autocomplete="current-password" placeholder="••••••••••">
      <?php if ($error): ?>
        <div class="alert-error">Forkert kodeord. Prøv igen.</div>
      <?php endif; ?>
      <button type="submit" class="btn-login">Log ind</button>
    </form>
  </div>
</body>
</html>
