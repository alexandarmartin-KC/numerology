/* ============================================================
   /api/save-aarstalsraekker.js
   Gemmer og henter data fra Input Årstalsrækker:
   - Energibeskrivelser 1–31/4 (aarstalsraekker_energies)
   - Cyklusser (aarstalsraekker_cycles)
   - Specielle regler (aarstalsraekker_rules)
   - Generelt om årstalsrækker (generelt.aboutCycles, generelt.cycleStyle)
   ============================================================ */

const pool = require('./db');

export default async function handler(req, res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  if (req.method === 'OPTIONS') return res.status(200).end();

  const { type } = req.query; // energies | cycles | rules | general

  // ─── GET ───
  if (req.method === 'GET') {
    try {
      if (type === 'energies') {
        const [rows] = await pool.query('SELECT * FROM aarstalsraekker_energies ORDER BY tal ASC');
        return res.status(200).json(rows);
      }
      if (type === 'cycles') {
        const [rows] = await pool.query('SELECT * FROM aarstalsraekker_cycles ORDER BY id ASC');
        return res.status(200).json(rows);
      }
      if (type === 'rules') {
        const [rows] = await pool.query('SELECT * FROM aarstalsraekker_rules ORDER BY id ASC');
        return res.status(200).json(rows);
      }
      if (type === 'general') {
        const [rows] = await pool.query('SELECT aboutCycles, cycleStyle FROM generelt WHERE id = 1');
        return res.status(200).json(rows[0] || {});
      }
      return res.status(400).json({ error: 'Ukendt type' });
    } catch (err) {
      return res.status(500).json({ error: err.message });
    }
  }

  // ─── POST ───
  if (req.method === 'POST') {
    try {
      if (type === 'energies') {
        const energies = req.body;
        if (!Array.isArray(energies)) return res.status(400).json({ error: 'Array forventet' });

        for (const e of energies) {
          const [existing] = await pool.query('SELECT id FROM aarstalsraekker_energies WHERE tal = ?', [e.tal]);
          if (existing.length > 0) {
            await pool.query(
              'UPDATE aarstalsraekker_energies SET keywords = ?, beskrivelse = ? WHERE tal = ?',
              [e.keywords, e.beskrivelse, e.tal]
            );
          } else {
            await pool.query(
              'INSERT INTO aarstalsraekker_energies (tal, keywords, beskrivelse) VALUES (?, ?, ?)',
              [e.tal, e.keywords, e.beskrivelse]
            );
          }
        }
        return res.status(200).json({ ok: true });
      }

      if (type === 'cycles') {
        const cycles = req.body;
        if (!Array.isArray(cycles)) return res.status(400).json({ error: 'Array forventet' });

        await pool.query('DELETE FROM aarstalsraekker_cycles');
        for (const c of cycles) {
          await pool.query(
            'INSERT INTO aarstalsraekker_cycles (name, description, style) VALUES (?, ?, ?)',
            [c.name, c.description, c.style]
          );
        }
        return res.status(200).json({ ok: true });
      }

      if (type === 'rules') {
        const rules = req.body;
        if (!Array.isArray(rules)) return res.status(400).json({ error: 'Array forventet' });

        await pool.query('DELETE FROM aarstalsraekker_rules');
        for (const r of rules) {
          await pool.query(
            'INSERT INTO aarstalsraekker_rules (`condition`, description) VALUES (?, ?)',
            [r.condition, r.description]
          );
        }
        return res.status(200).json({ ok: true });
      }

      if (type === 'general') {
        const { aboutCycles, cycleStyle } = req.body;
        const [existing] = await pool.query('SELECT id FROM generelt WHERE id = 1');
        if (existing.length > 0) {
          await pool.query(
            'UPDATE generelt SET aboutCycles = ?, cycleStyle = ? WHERE id = 1',
            [aboutCycles, cycleStyle]
          );
        } else {
          await pool.query(
            'INSERT INTO generelt (id, aboutCycles, cycleStyle) VALUES (1, ?, ?)',
            [aboutCycles, cycleStyle]
          );
        }
        return res.status(200).json({ ok: true });
      }

      return res.status(400).json({ error: 'Ukendt type' });
    } catch (err) {
      return res.status(500).json({ error: err.message });
    }
  }

  return res.status(405).json({ error: 'Method not allowed' });
}
