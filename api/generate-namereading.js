/* ============================================================
   /api/generate-namereading.js — Vercel Serverless Function
   Mini name reading: receives name number data + energy info,
   returns 8-10 personal lines as a teaser.
   ============================================================ */

export default async function handler(req, res) {
  // CORS
  res.setHeader('Access-Control-Allow-Origin', 'https://numerology-olive-kappa.vercel.app');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  if (req.method === 'OPTIONS') return res.status(200).end();
  if (req.method !== 'POST') return res.status(405).json({ error: 'Method not allowed' });

  const apiKey = process.env.OPENAI_API_KEY;
  if (!apiKey) return res.status(500).json({ error: 'OPENAI_API_KEY not configured' });

  try {
    const { firstName, nameData, energyDescriptions, customSystemPrompt } = req.body;
    if (!firstName || !nameData) {
      return res.status(400).json({ error: 'Missing name data' });
    }

    // Use custom system prompt from admin config if provided, otherwise fall back to default
    let systemPrompt;
    if (customSystemPrompt) {
      systemPrompt = customSystemPrompt + `\n\nNUMEROLOGISK VIDEN:\n${energyDescriptions || 'Ingen energibeskrivelser tilgængelige.'}`;
    } else {
      systemPrompt = `Du er en erfaren numerolog. Du laver en kort og personlig numerologisk analyse på dansk baseret på en persons fulde numerologiske diamant.

Du modtager de præcise diamantpositioner: grundenergi (top/fødselsdagstal), livslinje (navnedele), bundtal, aura (4 hjørner), hjertecenter, solarplexus, firkanttal og søjletal.

REGLER:
- Skriv præcis 8-10 korte, personlige sætninger i ét samlet afsnit.
- Brug personens fornavn naturligt 1-2 gange.
- Basér analysen på de KONKRETE diamantpositioner du modtager.
- Grundenergien (top) er personens kerneenergi — vægt den tungest.
- Nævn kort samspillet mellem fx hjertecenter og grundenergi, eller aura og bundtal.
- Tonen skal være varm, indsigtsfuld og lidt mystisk — som om du kender dem.
- Skriv IKKE overskrifter, bullets eller formatering. Kun løbende tekst.
- Skriv IKKE "dit tal er..." eller tekniske forklaringer. Gå direkte til personlighed.
- Nævn ALDRIG planeter (Sol, Saturn, Jupiter osv.) — hold fokus rent på tallenes energi.
- Hold det positivt men ærligt — nævn gerne en mild udfordring.
- Slut med en sætning der antyder at den fulde diamant rummer endnu mere at udforske.

NUMEROLOGISK VIDEN:
${energyDescriptions || 'Ingen energibeskrivelser tilgængelige.'}`;
    }

    const userPrompt = `Personen hedder ${firstName}.

${nameData}

Skriv en kort, personlig numerologisk analyse (8-10 sætninger i ét afsnit).`;

    const response = await fetch('https://api.openai.com/v1/chat/completions', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${apiKey}`
      },
      body: JSON.stringify({
        model: 'gpt-4o',
        messages: [
          { role: 'system', content: systemPrompt },
          { role: 'user', content: userPrompt }
        ],
        temperature: 0.8,
        max_tokens: 600
      })
    });

    if (!response.ok) {
      const err = await response.text();
      return res.status(500).json({ error: 'OpenAI API error', details: err });
    }

    const data = await response.json();
    const reading = data.choices?.[0]?.message?.content || '';

    return res.status(200).json({ reading, usage: data.usage });
  } catch (err) {
    return res.status(500).json({ error: err.message });
  }
}
