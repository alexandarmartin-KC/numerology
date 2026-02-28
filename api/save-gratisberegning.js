/* ============================================================
   /api/save-gratisberegning.js
   Gemmer og henter data fra Input Gratis Beregning:
   - Positioner, tone, længde, fokus, undgå, ekstra instruktion
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
      const [rows] = await pool.query('SELECT * FROM gratis_beregning WHERE id = 1');
      return res.status(200).json(rows[0] || {});
    } catch (err) {
      return res.status(500).json({ error: err.message });
    }
  }

  // ─── POST ───
  if (req.method === 'POST') {
    try {
      const { positions, tone, length, focus, avoid, extraInstruction } = req.body;

      const [existing] = await pool.query('SELECT id FROM gratis_beregning WHERE id = 1');
      if (existing.length > 0) {
        await pool.query(
          `UPDATE gratis_beregning SET
            positions = ?, tone = ?, length = ?, focus = ?, avoid = ?, extraInstruction = ?
          WHERE id = 1`,
          [JSON.stringify(positions), tone, length, JSON.stringify(focus), avoid, extraInstruction]
        );
      } else {
        await pool.query(
          `INSERT INTO gratis_beregning (id, positions, tone, length, focus, avoid, extraInstruction)
          VALUES (1, ?, ?, ?, ?, ?, ?)`,
          [JSON.stringify(positions), tone, length, JSON.stringify(focus), avoid, extraInstruction]
        );
      }
      return res.status(200).json({ ok: true });
    } catch (err) {
      return res.status(500).json({ error: err.message });
    }
  }

  return res.status(405).json({ error: 'Method not allowed' });
}
