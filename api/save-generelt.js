/* ============================================================
   /api/save-generelt.js
   Gemmer og henter data fra Input Generelt:
   - Om numerologi, definitioner, blokke, stil, eksempelrapport
   - Astrologi
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
      const [rows] = await pool.query('SELECT * FROM generelt WHERE id = 1');
      return res.status(200).json(rows[0] || {});
    } catch (err) {
      return res.status(500).json({ error: err.message });
    }
  }

  // ─── POST ───
  if (req.method === 'POST') {
    try {
      const {
        aboutNumerology, defRent, defUrent, blokkeAfTal,
        diamantAarstalsraekker, udrensning, numerologiAlder,
        rapportStil, eksempelRapport,
        astrologyGenerelt, astrologySignName, astrologySignText
      } = req.body;

      const [existing] = await pool.query('SELECT id FROM generelt WHERE id = 1');
      if (existing.length > 0) {
        await pool.query(
          `UPDATE generelt SET
            aboutNumerology = ?, defRent = ?, defUrent = ?, blokkeAfTal = ?,
            diamantAarstalsraekker = ?, udrensning = ?, numerologiAlder = ?,
            rapportStil = ?, eksempelRapport = ?,
            astrologyGenerelt = ?, astrologySignName = ?, astrologySignText = ?
          WHERE id = 1`,
          [aboutNumerology, defRent, defUrent, blokkeAfTal,
           diamantAarstalsraekker, udrensning, numerologiAlder,
           rapportStil, eksempelRapport,
           astrologyGenerelt, astrologySignName, astrologySignText]
        );
      } else {
        await pool.query(
          `INSERT INTO generelt
            (id, aboutNumerology, defRent, defUrent, blokkeAfTal,
             diamantAarstalsraekker, udrensning, numerologiAlder,
             rapportStil, eksempelRapport,
             astrologyGenerelt, astrologySignName, astrologySignText)
          VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
          [aboutNumerology, defRent, defUrent, blokkeAfTal,
           diamantAarstalsraekker, udrensning, numerologiAlder,
           rapportStil, eksempelRapport,
           astrologyGenerelt, astrologySignName, astrologySignText]
        );
      }
      return res.status(200).json({ ok: true });
    } catch (err) {
      return res.status(500).json({ error: err.message });
    }
  }

  return res.status(405).json({ error: 'Method not allowed' });
}
