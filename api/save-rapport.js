/* ============================================================
   /api/save-rapport.js
   Gemmer og henter data fra Input Rapport:
   - Overordnet instruktion
   - Rapport sektioner (titel, instruktion, datakilder)
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
      const [sections] = await pool.query('SELECT * FROM rapport_sections ORDER BY id ASC');
      const [generelt] = await pool.query('SELECT rapportGlobalInstruction FROM generelt WHERE id = 1');
      return res.status(200).json({
        globalInstruction: generelt[0]?.rapportGlobalInstruction || '',
        sections
      });
    } catch (err) {
      return res.status(500).json({ error: err.message });
    }
  }

  // ─── POST ───
  if (req.method === 'POST') {
    try {
      const { globalInstruction, sections } = req.body;

      // Gem overordnet instruktion
      const [existing] = await pool.query('SELECT id FROM generelt WHERE id = 1');
      if (existing.length > 0) {
        await pool.query('UPDATE generelt SET rapportGlobalInstruction = ? WHERE id = 1', [globalInstruction]);
      } else {
        await pool.query('INSERT INTO generelt (id, rapportGlobalInstruction) VALUES (1, ?)', [globalInstruction]);
      }

      // Gem sektioner — slet eksisterende og indsæt nye
      await pool.query('DELETE FROM rapport_sections');
      if (Array.isArray(sections)) {
        for (const s of sections) {
          await pool.query(
            'INSERT INTO rapport_sections (title, instruction, sources) VALUES (?, ?, ?)',
            [s.title, s.instruction, JSON.stringify(s.sources || [])]
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
