<?php
ini_set('display_errors', '0');
error_reporting(0);
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonOut(['error' => 'Method not allowed'], 405);

$jobId = $_GET['jobId'] ?? '';
if (!$jobId || !preg_match('/^[a-f0-9]{32}$/', $jobId)) {
    jsonOut(['error' => 'Ugyldigt jobId'], 400);
}

$db   = getDB();
$stmt = $db->prepare("SELECT status, result, error FROM rapport_jobs WHERE id = ?");
$stmt->bind_param('s', $jobId);
$stmt->execute();
$res = $stmt->get_result();
$job = $res->fetch_assoc();
$stmt->close();

if (!$job) jsonOut(['error' => 'Job ikke fundet'], 404);

if ($job['status'] === 'done') {
    $usage = json_decode($job['error'] ?? '{}', true) ?: [];
    jsonOut(['status' => 'done', 'rapport' => $job['result'], 'usage' => $usage]);
} elseif ($job['status'] === 'error') {
    // 'error'-feltet bruges også til diagnostik-info når status='processing'
    jsonOut(['status' => 'error', 'error' => $job['error'] ?: 'Ukendt fejl']);
} else {
    // Returner launch-metode som diagnostik mens vi venter
    jsonOut(['status' => 'processing', 'debug' => $job['error'] ?? null]);
}
