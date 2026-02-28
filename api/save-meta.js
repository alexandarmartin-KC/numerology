/* ============================================================
   /api/save-meta.js
   Gemmer og henter data fra Input Meta Data:
   - title, description, keywords per side
   ============================================================ */

const pool = require('./db');

export default async function handler(req, res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  if (req.method === 'OPTIONS') return res.status(200).end();

  const { page } = req.query; // fx 'forside', 'om-numerologi', 'rapport' osv.

  // ─── GET ───
  if (req.method === 'GET') {
    try {
      if (page) {
        const [rows] = await pool.query('SELECT * FROM meta_data WHERE page = ?', [page]);
        return res.status(200).json(rows[0] || {});
      }
      const [rows] = await pool.query('SELECT * FROM meta_data ORDER BY page ASC');
      return res.status(200).json(rows);
    } catch (err) {
      return res.status(500).json({ error: err.message });
    }
  }

  // ─── POST ───
  if (req.method === 'POST') {
    try {
      const { page: pageName, title, description, keywords, ogImage } = req.body;
      if (!pageName) return res.status(400).json({ error: 'page er påkrævet' });

      const [existing] = await pool.query('SELECT id FROM meta_data WHERE page = ?', [pageName]);
      if (existing.length > 0) {
        await pool.query(
          'UPDATE meta_data SET title = ?, description = ?, keywords = ?, ogImage = ? WHERE page = ?',
          [title, description, keywords, ogImage, pageName]
        );
      } else {
        await pool.query(
          'INSERT INTO meta_data (page, title, description, keywords, ogImage) VALUES (?, ?, ?, ?, ?)',
          [pageName, title, description, keywords, ogImage]
        );
      }
      return res.status(200).json({ ok: true });
    } catch (err) {
      return res.status(500).json({ error: err.message });
    }
  }

  return res.status(405).json({ error: 'Method not allowed' });
}
