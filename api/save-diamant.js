/* ============================================================
   /api/save-diamant.js
   Gemmer og henter data fra Input Diamant:
   - Energibeskrivelser (diamant_energies)
   - Positioner i diamanten (diamant_positions)
   - Specielle regler (diamant_rules)
   ============================================================ */

const pool = require('./db');

export default async function handler(req, res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  if (req.method === 'OPTIONS') return res.status(200).end();

  const { type } = req.query; // type = 'energies' | 'positions' | 'rules'

  // ─── GET: Hent data ───
  if (req.method === 'GET') {
    try {
      if (type === 'energies') {
        const [rows] = await pool.query('SELECT * FROM diamant_energies ORDER BY id ASC');
        // Returner billede_url som 'billede' så klienten kan bruge feltet direkte
        return res.status(200).json(rows.map(r => ({ ...r, billede: r.billede_url })));
      }
      if (type === 'positions') {
        const [rows] = await pool.query('SELECT * FROM diamant_positions ORDER BY id ASC');
        return res.status(200).json(rows);
      }
      if (type === 'rules') {
        const [rows] = await pool.query('SELECT * FROM diamant_rules ORDER BY id ASC');
        return res.status(200).json(rows);
      }
      return res.status(400).json({ error: 'Ukendt type. Brug: energies, positions eller rules' });
    } catch (err) {
      return res.status(500).json({ error: err.message });
    }
  }

  // ─── POST: Gem data ───
  if (req.method === 'POST') {
    try {
      if (type === 'energies') {
        // Forventer array af energier
        const energies = req.body;
        if (!Array.isArray(energies)) return res.status(400).json({ error: 'Array forventet' });

        for (const e of energies) {
          const existing = await pool.query('SELECT id FROM diamant_energies WHERE display = ?', [e.display]);
          if (existing[0].length > 0) {
            await pool.query(
              `UPDATE diamant_energies SET
                reduced = ?, keywords = ?, grundenergi = ?, ubalanceret_keywords = ?,
                beskrivelse = ?, planet = ?, kendte = ?, helheds_funktion = ?, billede_url = ?
              WHERE display = ?`,
              [e.reduced, e.keywords, e.grundenergi, e.ubalanceret_keywords,
               e.beskrivelse, e.planet, e.kendte, e.helheds_funktion, e.billede || e.billede_url || null, e.display]
            );
          } else {
            await pool.query(
              `INSERT INTO diamant_energies
                (display, reduced, keywords, grundenergi, ubalanceret_keywords, beskrivelse, planet, kendte, helheds_funktion, billede_url)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
              [e.display, e.reduced, e.keywords, e.grundenergi, e.ubalanceret_keywords,
               e.beskrivelse, e.planet, e.kendte, e.helheds_funktion, e.billede || e.billede_url || null]
            );
          }
        }
        return res.status(200).json({ ok: true });
      }

      if (type === 'positions') {
        // Forventer array af positioner
        const positions = req.body;
        if (!Array.isArray(positions)) return res.status(400).json({ error: 'Array forventet' });

        await pool.query('DELETE FROM diamant_positions');
        for (const p of positions) {
          await pool.query(
            'INSERT INTO diamant_positions (name, description) VALUES (?, ?)',
            [p.name, p.description]
          );
        }
        return res.status(200).json({ ok: true });
      }

      if (type === 'rules') {
        // Forventer array af regler
        const rules = req.body;
        if (!Array.isArray(rules)) return res.status(400).json({ error: 'Array forventet' });

        await pool.query('DELETE FROM diamant_rules');
        for (const r of rules) {
          await pool.query(
            'INSERT INTO diamant_rules (`condition`, description) VALUES (?, ?)',
            [r.condition, r.description]
          );
        }
        return res.status(200).json({ ok: true });
      }

      return res.status(400).json({ error: 'Ukendt type. Brug: energies, positions eller rules' });
    } catch (err) {
      return res.status(500).json({ error: err.message });
    }
  }

  return res.status(405).json({ error: 'Method not allowed' });
}
