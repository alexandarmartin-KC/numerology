/* ============================================================
   /api/get-knowledge.js
   Henter al viden fra databasen til brug ved rapportgenerering.
   Kombinerer data fra ALLE tabeller og returnerer ét samlet objekt.
   ============================================================ */

const pool = require('./db');

export default async function handler(req, res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  if (req.method === 'OPTIONS') return res.status(200).end();
  if (req.method !== 'GET') return res.status(405).json({ error: 'Method not allowed' });

  try {
    // Hent alt data fra alle tabeller parallelt
    const [
      [energies],
      [positions],
      [diamantRules],
      [aarEnergies],
      [cycles],
      [aarRules],
      [generelt],
      [rapportSections],
      [navneRecipes],
      [gratis],
      [meta]
    ] = await Promise.all([
      pool.query('SELECT * FROM diamant_energies ORDER BY id ASC'),
      pool.query('SELECT * FROM diamant_positions ORDER BY id ASC'),
      pool.query('SELECT * FROM diamant_rules ORDER BY id ASC'),
      pool.query('SELECT * FROM aarstalsraekker_energies ORDER BY tal ASC'),
      pool.query('SELECT * FROM aarstalsraekker_cycles ORDER BY id ASC'),
      pool.query('SELECT * FROM aarstalsraekker_rules ORDER BY id ASC'),
      pool.query('SELECT * FROM generelt WHERE id = 1'),
      pool.query('SELECT * FROM rapport_sections ORDER BY id ASC'),
      pool.query('SELECT * FROM navnegenerator_recipes ORDER BY grundenergi ASC'),
      pool.query('SELECT * FROM gratis_beregning WHERE id = 1'),
      pool.query('SELECT * FROM meta_data ORDER BY page ASC')
    ]);

    const g = generelt[0] || {};

    // Byg cyklus-objekt fra cycles-tabel
    const cyclesObj = {};
    for (const c of cycles) {
      cyclesObj[c.name] = { description: c.description, style: c.style };
    }

    // Byg aarEnergies-objekt indekseret på tal
    const aarEnergiesObj = {};
    for (const e of aarEnergies) {
      aarEnergiesObj[e.tal] = { keywords: e.keywords, beskrivelse: e.beskrivelse };
    }

    // Byg rapport sektioner med parsed sources
    const parsedSections = rapportSections.map(s => ({
      ...s,
      sources: (() => { try { return JSON.parse(s.sources || '[]'); } catch { return []; } })()
    }));

    // Saml alt i ét knowledge-objekt (samme format som generate-rapport.js forventer)
    const knowledge = {
      // Generelt
      aboutNumerology: g.aboutNumerology || '',
      defRent: g.defRent || '',
      defUrent: g.defUrent || '',
      blokkeAfTal: g.blokkeAfTal || '',
      diamantAar: g.diamantAarstalsraekker || '',
      udrensning: g.udrensning || '',
      numerologiAlder: g.numerologiAlder || '',
      rapportStil: g.rapportStil || '',
      eksempelRapport: g.eksempelRapport || '',

      // Rapport
      rapportGlobalInstruction: g.rapportGlobalInstruction || '',
      rapportSections: parsedSections,

      // Diamant energier
      energies,
      energiesWithImages: energies.filter(e => e.billede_url).map(e => e.display),

      // Diamant positioner
      positions,

      // Diamant regler
      diamondRules: diamantRules.map(r => ({ condition: r.condition, description: r.description })),

      // Årstalsrækker energier
      aarEnergies: aarEnergiesObj,

      // Cyklusser
      cycles_about: g.aboutCycles || '',
      cycles_style: g.cycleStyle || '',
      cycles_124875: cyclesObj['1-2-4-8-7-5']?.description || '',
      cycles_36: cyclesObj['3-6']?.description || '',
      cycles_9: cyclesObj['9']?.description || '',

      // Årstalsrækker regler
      aarRules: aarRules.map(r => ({ condition: r.condition, description: r.description })),

      // Astrologi
      astrologyGenerelt: g.astrologyGenerelt || '',
      astrologySign: g.astrologySignName ? { name: g.astrologySignName, text: g.astrologySignText || '' } : null,

      // Navnegenerator
      navnegeneratorPrincipper: g.navnegeneratorPrincipper || '',
      navneRecipes,

      // Gratis beregning
      gratisBeregning: gratis[0] ? {
        ...gratis[0],
        positions: (() => { try { return JSON.parse(gratis[0].positions || '[]'); } catch { return []; } })(),
        focus: (() => { try { return JSON.parse(gratis[0].focus || '[]'); } catch { return []; } })()
      } : {},

      // Meta data
      meta
    };

    return res.status(200).json(knowledge);
  } catch (err) {
    return res.status(500).json({ error: err.message });
  }
}
