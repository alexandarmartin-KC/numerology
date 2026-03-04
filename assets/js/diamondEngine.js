/* ============================================================
   diamondEngine.js — Numerology Diamond Calculator
   Pure vanilla JS, no dependencies.
   ============================================================ */

/**
 * Compute the full numerology diamond for a given name + birth date.
 * @param {string} fullName   – Full name (e.g. "Anne-Marie Katje Jensen")
 * @param {string} birthDateISO – ISO date string "YYYY-MM-DD"
 * @returns {object} diamond result object
 */
function computeDiamond(fullName, birthDateISO) {

  /* ---------- Letter-value mapping (1-8) ---------- */
  const LETTER_VALUES = {
    A: 1, I: 1, J: 1, Q: 1, Y: 1, Å: 1,
    B: 2, K: 2, R: 2,
    C: 3, G: 3, L: 3, S: 3,
    D: 4, M: 4, T: 4,
    E: 5, H: 5, N: 5, X: 5,
    U: 6, V: 6, W: 6, Æ: 6,
    O: 7, Z: 7, Ø: 7,
    F: 8, P: 8
  };

  /* ---------- Helpers ---------- */

  /** Sum digits repeatedly until single digit 1-9 */
  function digitReduce(n) {
    while (n >= 10) {
      n = String(n).split("").reduce((s, d) => s + Number(d), 0);
    }
    return n;
  }

  /** Display rule: compound < 10 → just number, else "compound/reduced" */
  function displayValue(compound) {
    const reduced = digitReduce(compound);
    return compound < 10 ? `${compound}` : `${compound}/${reduced}`;
  }

  /* ---------- 1. Name normalisation ---------- */
  let norm = fullName.trim().toUpperCase();        // 1) trim + uppercase
  norm = norm.replace(/[-\u2011\u2013\u2014]/g, " "); // 2) hyphen + en-dash + em-dash + nb-hyphen → space
  norm = norm.replace(/['\u2018\u2019\u02BC\u00B4`]/g, " "); // 3) all apostrophe/quote variants → space (O'Connor → O Connor)

  // 3) Remove diacritics but preserve Æ Ø Å
  norm = norm
    .replace(/Æ/g, "\x01")
    .replace(/Ø/g, "\x02")
    .replace(/Å/g, "\x03");
  norm = norm.normalize("NFD").replace(/[\u0300-\u036f]/g, "");
  norm = norm
    .replace(/\x01/g, "Æ")
    .replace(/\x02/g, "Ø")
    .replace(/\x03/g, "Å");

  // 4) Split on whitespace, drop empties
  const parts = norm.split(/\s+/).filter(Boolean);

  // 5) Validate
  if (parts.length < 2) {
    throw new Error("Mindst 2 navnedele (fornavn + efternavn) kræves.");
  }

  /* ---------- 2. Name energies ---------- */

  function nameEnergy(part) {
    const letters = part.split("");
    const values  = letters.map(ch => LETTER_VALUES[ch] || 0);
    const compound = values.reduce((a, b) => a + b, 0);
    const reduced  = digitReduce(compound);
    return {
      part,
      letters,
      values,
      compound,
      reduced,
      display: `${compound}/${reduced}`
    };
  }

  const energies = parts.map(nameEnergy);
  const first    = energies[0];
  const last     = energies[energies.length - 1];
  const middles  = energies.slice(1, -1);

  /* ---------- 3. Top (birth day) ---------- */
  const day = parseInt(birthDateISO.split("-")[2], 10);
  const top = digitReduce(day);

  /* ---------- 4. Bottom ---------- */
  const bottomCompound = energies.reduce((s, e) => s + e.reduced, 0);
  const bottomReduced  = digitReduce(bottomCompound);
  const bottomDisplay  = displayValue(bottomCompound);

  /* ---------- 5. Aura (four corners) ---------- */
  function auraCalc(a, b) {
    const compound = a + b;
    const reduced  = digitReduce(compound);
    return { compound, reduced, display: displayValue(compound) };
  }

  const upperLeft  = auraCalc(top, first.reduced);
  const upperRight = auraCalc(top, last.reduced);
  const lowerLeft  = auraCalc(bottomReduced, first.reduced);
  const lowerRight = auraCalc(bottomReduced, last.reduced);

  /* ---------- 6. Heart center ---------- */
  const heartCompound = upperLeft.reduced + upperRight.reduced;
  const heartReduced  = digitReduce(heartCompound);
  const heartDisplay  = displayValue(heartCompound);

  const heartExtras = middles.map(m => {
    const hc = m.reduced + heartReduced;
    const hr = digitReduce(hc);
    return { compound: hc, reduced: hr, display: displayValue(hc) };
  });

  /* ---------- 7. Solar plexus ---------- */
  const solarCompound = lowerLeft.reduced + lowerRight.reduced;
  const solarReduced  = digitReduce(solarCompound);
  const solarDisplay  = displayValue(solarCompound);

  const solarExtras = middles.map(m => {
    const sc = m.reduced + solarReduced;
    const sr = digitReduce(sc);
    return { compound: sc, reduced: sr, display: displayValue(sc) };
  });

  /* ---------- 8. Rygraden ---------- */
  const rygradCompound = top + bottomReduced;
  const rygradReduced  = digitReduce(rygradCompound);
  const rygradDisplay  = displayValue(rygradCompound);

  /* ---------- 9. Triangle ---------- */
  const triangleCompound =
      top
    + bottomReduced
    + heartReduced
    + heartExtras.reduce((s, e) => s + e.reduced, 0)
    + solarReduced
    + solarExtras.reduce((s, e) => s + e.reduced, 0)
    + middles.reduce((s, m) => s + m.reduced, 0);

  const triangleReduced = digitReduce(triangleCompound);

  /* ---------- Parse birth date parts ---------- */
  const [yyyy, mm, dd] = birthDateISO.split("-").map(Number);

  /* ---------- Build livslinje with roles ---------- */
  const livslinje = energies.map((e, i) => {
    let role;
    if (i === 0) role = "fornavn";
    else if (i === energies.length - 1) role = "efternavn";
    else role = "mellemnavn";
    return { role, name: e.part, compound: e.compound, reduced: e.reduced, display: e.display };
  });

  /* ---------- Return ---------- */
  return {
    input: {
      fullName: fullName.trim(),
      birthDate: { day: dd, month: mm, year: yyyy }
    },
    diamond: {
      grundenergi:  { value: top, display: `${top}` },
      livslinje,
      bundtal:      { compound: bottomCompound, reduced: bottomReduced, display: bottomDisplay },
      aura: {
        auraUpperLeft:  upperLeft,
        auraUpperRight: upperRight,
        auraLowerLeft:  lowerLeft,
        auraLowerRight: lowerRight
      },
      body: {
        hjertecenter: {
          centerTal: { compound: heartCompound, reduced: heartReduced, display: heartDisplay },
          mellemnavnsBidrag: heartExtras
        },
        solarplexus: {
          centerTal: { compound: solarCompound, reduced: solarReduced, display: solarDisplay },
          mellemnavnsBidrag: solarExtras
        }
      },
      rygraden: { compound: rygradCompound, reduced: rygradReduced, display: rygradDisplay },
      soejletal:  { compound: triangleCompound, reduced: triangleReduced }
    }
  };
}

/* ---------- Quick test (console) ---------- */
(() => {
  try {
    const r = computeDiamond("Anne-Marie Katje Jensen", "1980-11-18");
    const d = r.diamond;
    console.log("=== Diamond Engine Test ===");
    console.log("Top:", d.grundenergi.value, "(expected 9)");
    console.log("NameLine reduced:", d.livslinje.map(e => e.reduced).join(","), "(expected 7,4,4,6)");
    console.log("Bottom:", d.bundtal.display, "(expected 21/3)");
    console.log("Aura UR:", d.aura.auraUpperRight.display, "(expected 15/6)");
    console.log("Rygraden:", d.rygraden.display, "(expected 12/3)");
    console.log("Soejletal:", d.soejletal.compound, "(" + d.soejletal.reduced + ")", "(expected 51 (6))");
  } catch (err) {
    console.error("Diamond test failed:", err);
  }
})();
