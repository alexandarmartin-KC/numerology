/* ============================================================
   /api/generate-rapport.js — Vercel Serverless Function
   Receives computed diamond + årstalsrækker + knowledge base,
   builds a structured prompt, calls OpenAI, returns rapport text.
   ============================================================ */

export default async function handler(req, res) {
  // CORS
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  if (req.method === 'OPTIONS') return res.status(200).end();
  if (req.method !== 'POST') return res.status(405).json({ error: 'Method not allowed' });

  const apiKey = process.env.OPENAI_API_KEY;
  if (!apiKey) return res.status(500).json({ error: 'OPENAI_API_KEY not configured' });

  try {
    const { diamond, aarstalsraekker, knowledge } = req.body;
    if (!diamond || !knowledge) {
      return res.status(400).json({ error: 'Missing diamond or knowledge data' });
    }

    const systemPrompt = buildSystemPrompt(knowledge);
    const userPrompt = buildUserPrompt(diamond, aarstalsraekker);

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
    const rapport = data.choices?.[0]?.message?.content || '';

    return res.status(200).json({ rapport, usage: data.usage });
  } catch (err) {
    return res.status(500).json({ error: err.message });
  }
}

/* ────────────────────────────────────────────
   System Prompt — all knowledge injected here
   ──────────────────────────────────────────── */

function buildSystemPrompt(k) {
  let prompt = `Du er en erfaren numerolog. Du skriver en personlig numerologisk rapport på dansk.

VIGTIGE REGLER:
- Du må KUN bruge den viden der er givet nedenfor. Opfind IKKE ny numerologisk viden.
- Specielle regler skal fortolkes isoleret ud fra deres egen beskrivelse — bland IKKE energibeskrivelser ind i fortolkningen af specielle regler.
- Rapporten har to hoveddele: (1) Diamanten — beskrivelse af personen, (2) Årstalsrækker — beskrivelse af de enkelte år.
- Skriv i et varmt, personligt, men professionelt sprog.
`;

  // Om numerologi
  if (k.aboutNumerology) prompt += `\n## Om numerologi\n${k.aboutNumerology}\n`;
  if (k.defRent) prompt += `\n## Definition: Rent numeroskop\n${k.defRent}\n`;
  if (k.defUrent) prompt += `\n## Definition: Urent numeroskop\n${k.defUrent}\n`;
  if (k.blokkeAfTal) prompt += `\n## Blokke af tal\n${k.blokkeAfTal}\n`;
  if (k.diamantAar) prompt += `\n## Diamant og årstalsrækker\n${k.diamantAar}\n`;
  if (k.udrensning) prompt += `\n## Udrensning\n${k.udrensning}\n`;
  if (k.numerologiAlder) prompt += `\n## Numerologi og alder\n${k.numerologiAlder}\n`;
  if (k.rapportStil) prompt += `\n## Rapportens stil\n${k.rapportStil}\n`;
  if (k.eksempelRapport) prompt += `\n## Eksempelrapport\n${k.eksempelRapport}\n`;

  // Energibeskrivelser (diamant)
  if (k.energies && k.energies.length > 0) {
    prompt += `\n## Energibeskrivelser (diamant)\n`;
    k.energies.forEach(e => {
      prompt += `\n### Energi ${e.display || e.id}\n`;
      if (e.keywords) prompt += `Nøgleord (rent): ${e.keywords}\n`;
      if (e.keywords_urent_numeroskop) prompt += `Nøgleord (urent): ${e.keywords_urent_numeroskop}\n`;
      if (e.grundenergi) prompt += `Grundenergi: ${e.grundenergi}\n`;
      if (e.beskrivelse) prompt += `Beskrivelse: ${e.beskrivelse}\n`;
      if (e.ubalance_i_urent_numeroskop) prompt += `Ubalance: ${e.ubalance_i_urent_numeroskop}\n`;
      if (e.helheds_funktion) prompt += `Helhedsfunktion: ${e.helheds_funktion}\n`;
      if (e.planet) prompt += `Planet: ${e.planet}\n`;
      if (e.kendte) prompt += `Kendte: ${e.kendte}\n`;
    });
  }

  // Positioner i diamanten
  if (k.positions && k.positions.length > 0) {
    prompt += `\n## Positioner i diamanten\n`;
    k.positions.forEach(p => {
      if (p.description) prompt += `- ${p.name}: ${p.description}\n`;
    });
  }

  // Specielle regler (diamant)
  if (k.diamondRules && k.diamondRules.length > 0) {
    prompt += `\n## Specielle regler (diamant)\n`;
    k.diamondRules.forEach((r, i) => {
      prompt += `\nRegel ${i + 1}:\n`;
      prompt += `Betingelse: ${r.condition}\n`;
      prompt += `Betydning: ${r.description}\n`;
    });
  }

  // Energibeskrivelser (årstalsrækker 1-31/4)
  if (k.aarEnergies && Object.keys(k.aarEnergies).length > 0) {
    prompt += `\n## Energibeskrivelser (årstalsrækker)\n`;
    Object.entries(k.aarEnergies).forEach(([key, val]) => {
      prompt += `\n### Tal ${key}\n`;
      if (val.keywords) prompt += `Nøgleord: ${val.keywords}\n`;
      if (val.beskrivelse) prompt += `${val.beskrivelse}\n`;
    });
  }

  // Cyklusser
  if (k.cycles_about) prompt += `\n## Om cyklusser\n${k.cycles_about}\n`;
  if (k.cycles_style) prompt += `\n## Rapportens stil (årstalsrækker)\n${k.cycles_style}\n`;
  if (k.cycles_124875) prompt += `\n## Cyklus 1-2-4-8-7-5\n${k.cycles_124875}\n`;
  if (k.cycles_36) prompt += `\n## Cyklus 3-6\n${k.cycles_36}\n`;
  if (k.cycles_9) prompt += `\n## Cyklus 9\n${k.cycles_9}\n`;

  // Specielle regler (årstalsrækker)
  if (k.aarRules && k.aarRules.length > 0) {
    prompt += `\n## Specielle regler (årstalsrækker)\n`;
    k.aarRules.forEach((r, i) => {
      prompt += `\nRegel ${i + 1}:\n`;
      prompt += `Betingelse: ${r.condition}\n`;
      prompt += `Betydning: ${r.description}\n`;
    });
  }

  // Astrologi
  if (k.astrologyGenerelt) prompt += `\n## Astrologi generelt\n${k.astrologyGenerelt}\n`;
  if (k.astrologySign) prompt += `\n## Stjernetegn: ${k.astrologySign.name}\n${k.astrologySign.text}\n`;

  return prompt;
}

/* ────────────────────────────────────────────
   User Prompt — the specific person's data
   ──────────────────────────────────────────── */

function buildUserPrompt(diamond, aarstalsraekker) {
  let prompt = `Skriv en komplet numerologisk rapport for denne person:\n\n`;

  prompt += `## Persondata\n`;
  prompt += `Navn: ${diamond.input.fullName}\n`;
  prompt += `Fødselsdato: ${diamond.input.birthDate.day}/${diamond.input.birthDate.month}/${diamond.input.birthDate.year}\n\n`;

  prompt += `## Diamant\n`;
  prompt += `Grundenergi: ${diamond.diamond.grundenergi.display}\n`;
  prompt += `Livslinje: ${diamond.diamond.livslinje.map(e => `${e.name} (${e.display})`).join(' → ')}\n`;
  prompt += `Bundtal: ${diamond.diamond.bundtal.display}\n`;
  prompt += `Aura øvre venstre: ${diamond.diamond.aura.auraUpperLeft.display}\n`;
  prompt += `Aura øvre højre: ${diamond.diamond.aura.auraUpperRight.display}\n`;
  prompt += `Aura nedre venstre: ${diamond.diamond.aura.auraLowerLeft.display}\n`;
  prompt += `Aura nedre højre: ${diamond.diamond.aura.auraLowerRight.display}\n`;
  prompt += `Hjertecenter: ${diamond.diamond.body.hjertecenter.centerTal.display}\n`;
  if (diamond.diamond.body.hjertecenter.mellemnavnsBidrag.length > 0) {
    prompt += `Hjerte-ekstra: ${diamond.diamond.body.hjertecenter.mellemnavnsBidrag.map(e => e.display).join(', ')}\n`;
  }
  prompt += `Solarplexus: ${diamond.diamond.body.solarplexus.centerTal.display}\n`;
  if (diamond.diamond.body.solarplexus.mellemnavnsBidrag.length > 0) {
    prompt += `Solar-ekstra: ${diamond.diamond.body.solarplexus.mellemnavnsBidrag.map(e => e.display).join(', ')}\n`;
  }
  prompt += `Firkanttal (rygrad): ${diamond.diamond.firkanttal.display}\n`;
  prompt += `Søjletal: ${diamond.diamond.soejletal.compound}/${diamond.diamond.soejletal.reduced}\n`;

  if (aarstalsraekker && aarstalsraekker.length > 0) {
    prompt += `\n## Årstalsrækker\n`;
    prompt += `Cyklus-type: ${aarstalsraekker[0]?.cycleType || 'ukendt'}\n\n`;
    aarstalsraekker.forEach(year => {
      prompt += `### ${year.year} (grundtal: ${year.yearReduced})\n`;
      prompt += `Energier: ${year.energies.join(', ')}\n`;
      if (year.specialRulesMatched && year.specialRulesMatched.length > 0) {
        prompt += `Matchede specielle regler: ${year.specialRulesMatched.join('; ')}\n`;
      }
      prompt += `\n`;
    });
  }

  prompt += `\nSkriv rapporten med to hoveddele:\n`;
  prompt += `1. DIAMANTEN — beskriv personen baseret på diamantens energier og positioner\n`;
  prompt += `2. ÅRSTALSRÆKKER — beskriv hvert år, de energier der optræder, og hvad personen skal være opmærksom på\n`;
  prompt += `\nBrug formatering med overskrifter (##) og afsnit.`;

  return prompt;
}
