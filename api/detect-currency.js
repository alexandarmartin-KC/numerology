/* ============================================================
   /api/detect-currency.js — Vercel Serverless Function
   Returns the recommended default currency based on visitor's
   country (using Vercel's x-vercel-ip-country header).
   US visitors → usd, everyone else → eur.
   ============================================================ */

export default function handler(req, res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');
  if (req.method === 'OPTIONS') return res.status(200).end();

  const country = req.headers['x-vercel-ip-country'] || '';
  const currency = country === 'US' ? 'usd' : 'eur';

  return res.status(200).json({ currency, country });
}
