<?php
// Midlertidigt debug-hjælpescript — SLET efter brug
$token = $_GET['t'] ?? '';
if ($token !== 'num2026') { http_response_code(403); exit('Forbidden'); }

$log = __DIR__ . '/debug-prompt.log';
if (!file_exists($log)) { echo "Log-fil ikke fundet."; exit; }

// Returnér de seneste 200 linjer
$lines = file($log);
$tail  = array_slice($lines, -200);

header('Content-Type: text/plain; charset=utf-8');
echo implode('', $tail);
