<?php
/**
 * Lokal udviklingskonfiguration til PHP-endpoints.
 * KOP DENNE FIL til api/.env.php og udfyld med dine faktiske værdier.
 * api/.env.php er i .gitignore og må ALDRIG committes til git.
 *
 * I produktion sættes disse som server-miljøvariabler (f.eks. via .htaccess SetEnv
 * eller hostingpanel), IKKE via denne fil.
 */

// Database
putenv('DB_HOST=localhost');
putenv('DB_USER=din_db_bruger');
putenv('DB_PASS=dit_db_kodeord');
putenv('DB_NAME=dit_db_navn');

// CORS: Angiv den tilladte origin for API-kald fra browseren
putenv('ALLOWED_ORIGIN=http://localhost:3000');

// Admin API-nøgle: Beskytter POST-endpoints mod uautoriserede skrivninger.
// Sæt til en stærk, tilfældig streng og send den som X-Admin-Key header fra frontend.
putenv('ADMIN_API_KEY=skift-denne-til-en-stærk-hemmelig-nøgle');
