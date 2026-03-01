<?php
// Simpel diagnose - ingen DB afhængighed
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'php_version'      => PHP_VERSION,
    'mysqli_loaded'    => extension_loaded('mysqli'),
    'db_host_set'      => (getenv('DB_HOST') !== false && getenv('DB_HOST') !== ''),
    'db_user_set'      => (getenv('DB_USER') !== false && getenv('DB_USER') !== ''),
    'db_name_set'      => (getenv('DB_NAME') !== false && getenv('DB_NAME') !== ''),
    'env_file_exists'  => file_exists(__DIR__ . '/.env.php'),
    'server_software'  => $_SERVER['SERVER_SOFTWARE'] ?? 'ukendt',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
