/* ============================================================
   /api/save-navnegenerator.js
   Gemmer og henter data fra Input Navnegenerator:
   - Principper
   - Opskrifter (recipes) per grundenergi
   ============================================================ */

const pool = require('./db');

export default async function handler(req, res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  if (req.method === 'OPTIONS') return res.status(200).end();

  // ─── GET ───
  if (req.method === 'GET') {
    try {
      const [recipes] = await pool.query('SELECT * FROM navnegenerator_recipes ORDER BY grundenergi ASC');
      const [generelt] = await pool.query('SELECT navnegeneratorPrincipper FROM generelt WHERE id = 1');
      return res.status(200).json({
        principper: generelt[0]?.navnegeneratorPrincipper || '',
        recipes
      });
    } catch (err) {
      return res.status(500).json({ error: err.message });
    }
  }

  // ─── POST ───
  if (req.method === 'POST') {
    try {
      const { principper, recipes } = req.body;

      // Gem principper
      const [existing] = await pool.query('SELECT id FROM generelt WHERE id = 1');
      if (existing.length > 0) {
        await pool.query('UPDATE generelt SET navnegeneratorPrincipper = ? WHERE id = 1', [principper]);
      } else {
        await pool.query('INSERT INTO generelt (id, navnegeneratorPrincipper) VALUES (1, ?)', [principper]);
      }

      // Gem opskrifter
      if (Array.isArray(recipes)) {
        await pool.query('DELETE FROM navnegenerator_recipes');
        for (const r of recipes) {
          await pool.query(
            `INSERT INTO navnegenerator_recipes
              (grundenergi, fornavn, mellemnavn1, mellemnavn2, mellemnavn3, mellemnavn4, mellemnavn5, efternavn, principper)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)`,
            [r.grundenergi, r.fornavn, r.mellemnavn1, r.mellemnavn2, r.mellemnavn3, r.mellemnavn4, r.mellemnavn5, r.efternavn, r.principper]
          );
        }
      }
      return res.status(200).json({ ok: true });
    } catch (err) {
      return res.status(500).json({ error: err.message });
    }
  }

  return res.status(405).json({ error: 'Method not allowed' });
}
