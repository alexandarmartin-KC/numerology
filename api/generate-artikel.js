/* ============================================================
   /api/generate-artikel.js — Vercel Serverless Function
   Receives knowledge base + free-text description,
   builds a prompt, calls OpenAI, returns article text.
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
    const { prompt: userDescription, knowledge } = req.body;
    if (!userDescription) {
      return res.status(400).json({ error: 'Missing article description' });
    }

    const systemPrompt = buildSystemPrompt(knowledge || {});
    const userPrompt = `Skriv følgende artikel/tekst:\n\n${userDescription}`;

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
        temperature: 0.7,
        max_tokens: 8000
      })
    });

    if (!response.ok) {
      const err = await response.text();
      return res.status(500).json({ error: 'OpenAI API error', details: err });
    }

    const data = await response.json();
    const artikel = data.choices?.[0]?.message?.content || '';

    return res.status(200).json({ artikel, usage: data.usage });
  } catch (err) {
    return res.status(500).json({ error: err.message });
  }
}

/* ────────────────────────────────────────────
   System Prompt — numerology knowledge base
   ──────────────────────────────────────────── */

function buildSystemPrompt(k) {
  let prompt = `Du er en erfaren numerolog og skribent. Du skriver artikler, blogindlæg og tekster om numerologi på dansk.

VIGTIGE REGLER:
- Du må KUN bruge den numerologiske viden der er givet nedenfor. Opfind IKKE ny numerologisk viden.
- Skriv i et engagerende, varmt og professionelt sprog.
- Brug formatering med overskrifter (##), afsnit og eventuelt punktlister.
- Teksten skal føles som skrevet af en ekspert der brænder for faget.
`;

  // Om numerologi
  if (k.aboutNumerology) prompt += `\n## Om numerologi\n${k.aboutNumerology}\n`;
  if (k.defRent) prompt += `\n## Definition: Rent numeroskop\n${k.defRent}\n`;
  if (k.defUrent) prompt += `\n## Definition: Urent numeroskop\n${k.defUrent}\n`;
  if (k.blokkeAfTal) prompt += `\n## Blokke af tal\n${k.blokkeAfTal}\n`;
  if (k.diamantAar) prompt += `\n## Diamant og årstalsrækker\n${k.diamantAar}\n`;
  if (k.udrensning) prompt += `\n## Udrensning\n${k.udrensning}\n`;
  if (k.numerologiAlder) prompt += `\n## Numerologi og alder\n${k.numerologiAlder}\n`;

  // Energibeskrivelser (diamant)
  if (k.energies && k.energies.length > 0) {
    prompt += `\n## Energibeskrivelser\n`;
    k.energies.forEach(e => {
      prompt += `\n### Energi ${e.display || e.id}\n`;
      if (e.keywords) prompt += `Nøgleord (rent): ${e.keywords}\n`;
      if (e.keywords_urent_numeroskop) prompt += `Nøgleord (urent): ${e.keywords_urent_numeroskop}\n`;
      if (e.grundenergi) prompt += `Grundenergi: ${e.grundenergi}\n`;
      if (e.beskrivelse) prompt += `Beskrivelse: ${e.beskrivelse}\n`;
      if (e.ubalance_i_urent_numeroskop) prompt += `Ubalance: ${e.ubalance_i_urent_numeroskop}\n`;
      if (e.helheds_funktion) prompt += `Helhedsfunktion: ${e.helheds_funktion}\n`;
      if (e.planet) prompt += `Planet: ${e.planet}\n`;
    });
  }

  // Positioner i diamanten
  if (k.positions && k.positions.length > 0) {
    prompt += `\n## Positioner i diamanten\n`;
    k.positions.forEach(p => {
      if (p.description) prompt += `- ${p.name}: ${p.description}\n`;
    });
  }

  // Cyklusser
  if (k.cycles_about) prompt += `\n## Om cyklusser\n${k.cycles_about}\n`;
  if (k.cycles_124875) prompt += `\n## Cyklus 1-2-4-8-7-5\n${k.cycles_124875}\n`;
  if (k.cycles_36) prompt += `\n## Cyklus 3-6\n${k.cycles_36}\n`;
  if (k.cycles_9) prompt += `\n## Cyklus 9\n${k.cycles_9}\n`;

  // Astrologi
  if (k.astrologyGenerelt) prompt += `\n## Astrologi generelt\n${k.astrologyGenerelt}\n`;

  return prompt;
}
