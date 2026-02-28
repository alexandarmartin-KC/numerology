/* ============================================================
   /api/db.js — Delt MySQL-forbindelse
   Bruges af alle API-filer til at få adgang til databasen.
   ============================================================ */

const mysql = require('mysql2/promise');

const pool = mysql.createPool({
  host: 'localhost',
  user: 'alexanda_numerology',
  password: 'Mabber0700',
  database: 'alexanda_numerology',
  waitForConnections: true,
  connectionLimit: 10,
  queueLimit: 0
});

module.exports = pool;
