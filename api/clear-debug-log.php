<?php
$log = __DIR__ . '/debug-prompt.log';
if (file_exists($log)) {
    file_put_contents($log, '');
    echo 'Debug-log tømt.';
} else {
    echo 'Ingen log-fil fundet.';
}
