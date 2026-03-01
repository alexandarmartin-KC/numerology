<?php
// Simpel diagnose - ingen DB afhængighed
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: application/json; charset=utf-8');

$_DB_HOST = ''; $_DB_USER = ''; $_DB_PASS = ''; $_DB_NAME = '';
$_ALLOWED_ORIGIN = ''; $_ADMIN_API_KEY = '';
$envFile = __DIR__ . '/.env.php';
if (file_exists($envFile)) include $envFile;

$dbHostViaGetenv = getenv('DB_HOST');
$dbHostViaPutenv = $_DB_HOST;
$resolved = $dbHostViaGetenv ?: ($_DB_HOST ?: '');

echo json_encode([
    'php_version'         => PHP_VERSION,
    'mysqli_loaded'       => extension_loaded('mysqli'),
    'env_file_exists'     => file_exists($envFile),
    'db_host_via_getenv'  => $dbHostViaGetenv !== false ? $dbHostViaGetenv : '(tom)',
    'db_host_via_var'     => $dbHostViaPutenv ?: '(tom)',
    'db_host_resolved'    => $resolved ?: '(tom - ingen af metoderne virkede)',
    'db_user_resolved'    => (getenv('DB_USER') ?: ($_DB_USER ?: '')) ? 'sat' : '(tom)',
    'db_name_resolved'    => (getenv('DB_NAME') ?: ($_DB_NAME ?: '')) ? 'sat' : '(tom)',
    'server_software'     => $_SERVER['SERVER_SOFTWARE'] ?? 'ukendt',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
