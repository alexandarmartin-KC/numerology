/* ============================================================
   /api/create-checkout.js — Vercel Serverless Function
   Creates a Stripe Checkout Session for the 35 EUR/USD analysis.
   Accepts fullName + birthDate + email + currency,
   stores them as metadata.
   Supports: Card (incl. Apple Pay / Google Pay) + PayPal.
   ============================================================ */

import Stripe from 'stripe';

export default async function handler(req, res) {
  // CORS
  const allowedOrigin = process.env.ALLOWED_ORIGIN || 'https://numerology-olive-kappa.vercel.app';
  res.setHeader('Access-Control-Allow-Origin', allowedOrigin);
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  if (req.method === 'OPTIONS') return res.status(200).end();
  if (req.method !== 'POST') return res.status(405).json({ error: 'Method not allowed' });

  const secretKey = process.env.STRIPE_SECRET_KEY;
  const baseUrl = process.env.BASE_URL || 'https://numerology-olive-kappa.vercel.app';

  try {
    const { fullName, birthDate, email, currency: rawCurrency } = req.body;
    const currency = rawCurrency === 'usd' ? 'usd' : 'eur';
    const unitAmount = currency === 'usd' ? 3500 : 3000; // USD 35.00 / EUR 30.00 in cents
    const currencySymbol = currency === 'usd' ? '$' : '€';

    if (!fullName || !fullName.trim()) {
      return res.status(400).json({ error: 'Fuldt navn er påkrævet.' });
    }
    if (!birthDate) {
      return res.status(400).json({ error: 'Fødselsdag er påkrævet.' });
    }
    if (!email || !email.trim()) {
      return res.status(400).json({ error: 'E-mail er påkrævet.' });
    }

    // --- Mock mode: no Stripe key configured ---
    if (!secretKey) {
      const mockUrl = `${baseUrl}/mock-checkout.html?name=${encodeURIComponent(fullName.trim())}&birth=${encodeURIComponent(birthDate)}&email=${encodeURIComponent(email.trim())}&currency=${currency}`;
      return res.status(200).json({ url: mockUrl });
    }

    // --- Live Stripe mode ---
    const stripe = new Stripe(secretKey);

    const session = await stripe.checkout.sessions.create({
      payment_method_types: ['card', 'paypal'],
      mode: 'payment',
      customer_email: email.trim(),
      line_items: [
        {
          price_data: {
            currency: currency,
            unit_amount: unitAmount,
            product_data: {
              name: 'Personlig numerologisk analyse',
              description: `Analyse for: ${fullName.trim()} (${birthDate})`,
            },
          },
          quantity: 1,
        },
      ],
      metadata: {
        fullName: fullName.trim(),
        birthDate: birthDate,
      },
      success_url: `${baseUrl}/tak.html?session_id={CHECKOUT_SESSION_ID}`,
      cancel_url: `${baseUrl}/landing-analyse-35usd.html`,
    });

    return res.status(200).json({ url: session.url });
  } catch (err) {
    console.error('Stripe error:', err);
    return res.status(500).json({ error: err.message || 'Checkout session kunne ikke oprettes.' });
  }
}
