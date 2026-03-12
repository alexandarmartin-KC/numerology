/* ============================================================
   diamondRendererCover.js — Navy/gold PDF-style diamond renderer
   Uses the same diamond data structure as diamondEngine.js.
   Call: renderDiamondCover(container, diamond)
   ============================================================ */

function renderDiamondCover(container, diamond) {
  const NS   = "http://www.w3.org/2000/svg";
  const GOLD = "#c9a84c";
  const WHITE = "#ffffff";
  const FONT  = "Cormorant Garamond,serif";

  // Style the wrapper div
  Object.assign(container.style, {
    background: "#1a1f2e",
    borderRadius: "4px",
    padding: "8pt 0",
    WebkitPrintColorAdjust: "exact",
    printColorAdjust: "exact"
  });

  const svg = document.createElementNS(NS, "svg");
  svg.setAttribute("viewBox", "-60 -95 1120 1250");
  svg.setAttribute("width", "100%");
  svg.setAttribute("xmlns", NS);
  svg.style.cssText = "display:block;-webkit-print-color-adjust:exact;print-color-adjust:exact";

  container.innerHTML = "";
  container.appendChild(svg);

  // ── Low-level helpers ──────────────────────────────────

  function node(tag, attrs) {
    const e = document.createElementNS(NS, tag);
    for (const [k, v] of Object.entries(attrs)) e.setAttribute(k, String(v));
    svg.appendChild(e);
    return e;
  }

  function txt(x, y, content, opts = {}) {
    const {
      size    = 24,
      weight  = "400",
      italic  = false,
      fill    = GOLD,
      opacity = "1",
      anchor  = "middle"
    } = opts;
    const e = node("text", {
      x, y,
      "text-anchor": anchor,
      "dominant-baseline": "middle",
      "font-family": FONT,
      "font-size": size,
      "font-weight": weight,
      ...(italic ? { "font-style": "italic" } : {}),
      fill, opacity
    });
    e.textContent = String(content ?? "");
  }

  function line(x1, y1, x2, y2, opts = {}) {
    node("line", {
      x1, y1, x2, y2,
      stroke: opts.stroke ?? GOLD,
      "stroke-width": opts.width ?? "1.5",
      opacity: opts.opacity ?? "1"
    });
  }

  function circ(cx, cy, r, opts = {}) {
    node("circle", {
      cx, cy, r,
      fill: opts.fill ?? "none",
      stroke: opts.stroke ?? GOLD,
      "stroke-width": opts.width ?? "1",
      opacity: opts.opacity ?? "1"
    });
  }

  // ── Diamond frame ──────────────────────────────────────

  line(500, 0,    920, 530,  { opacity: ".8" });
  line(920, 530,  500, 1060, { opacity: ".8" });
  line(500, 1060, 80,  530,  { opacity: ".8" });
  line(80,  530,  500, 0,    { opacity: ".8" });
  // Lifeline
  line(80, 530, 920, 530, { stroke: WHITE, width: "1", opacity: ".25" });

  // Double rings
  circ(500, 265, 124, { width: ".5",  opacity: ".3" });
  circ(500, 265, 110, { width: "1.2", opacity: ".7" });
  circ(500, 795, 124, { width: ".5",  opacity: ".3" });
  circ(500, 795, 110, { width: "1.2", opacity: ".7" });

  // Gold tip dots
  circ(500, 0,    5, { fill: GOLD, stroke: "none", opacity: ".9" });
  circ(920, 530,  5, { fill: GOLD, stroke: "none", opacity: ".9" });
  circ(500, 1060, 5, { fill: GOLD, stroke: "none", opacity: ".9" });
  circ(80,  530,  5, { fill: GOLD, stroke: "none", opacity: ".9" });

  // Rygrad arc
  const arc = document.createElementNS(NS, "path");
  arc.setAttribute("d", "M 864 460 A 90 90 0 0 0 830 530");
  arc.setAttribute("stroke", GOLD);
  arc.setAttribute("stroke-width", "1");
  arc.setAttribute("fill", "none");
  arc.setAttribute("opacity", ".4");
  svg.appendChild(arc);

  // ── Grundenergi / Bundtal (numbers at tips) ────────────

  txt(500, -52,  diamond.grundenergi?.display, { size: 38, weight: "300", opacity: ".9" });
  txt(500, 1112, diamond.bundtal?.display,      { size: 38, weight: "300", opacity: ".9" });

  // ── Aura (4 quadrants) ─────────────────────────────────

  const aura = diamond.aura ?? {};
  txt(251, 234, "Aura", { size: 24, italic: true, fill: WHITE, opacity: ".45" });
  txt(251, 264, aura.auraUpperLeft?.display,  { size: 38, weight: "300", opacity: ".85" });
  txt(749, 234, "Aura", { size: 24, italic: true, fill: WHITE, opacity: ".45" });
  txt(749, 264, aura.auraUpperRight?.display, { size: 38, weight: "300", opacity: ".85" });
  txt(219, 765, "Aura", { size: 24, italic: true, fill: WHITE, opacity: ".45" });
  txt(219, 795, aura.auraLowerLeft?.display,  { size: 38, weight: "300", opacity: ".85" });
  txt(781, 765, "Aura", { size: 24, italic: true, fill: WHITE, opacity: ".45" });
  txt(781, 795, aura.auraLowerRight?.display, { size: 38, weight: "300", opacity: ".85" });

  // ── Hjertecenter (cy=265) ──────────────────────────────
  // Layout: label → main result → extra rows (top of circle)

  const heart   = diamond.body?.hjertecenter ?? {};
  const hExtras = heart.mellemnavnsBidrag ?? [];
  const hRows   = [];
  for (let i = 0; i < hExtras.length; i += 3) hRows.push(hExtras.slice(i, i + 3));

  const LABEL_GAP = 38;  // label → main
  const MAIN_GAP  = 50;  // main  → first extras row
  const ROW_H     = 38;  // between extra rows

  const hBlockH = hRows.length === 0
    ? LABEL_GAP
    : LABEL_GAP + MAIN_GAP + (hRows.length - 1) * ROW_H;
  const hLabelY = 265 - hBlockH / 2 - 3;
  const hMainY  = hLabelY + LABEL_GAP;

  txt(500, hLabelY, "Hjertecenter", { size: 22, italic: true, fill: WHITE, opacity: ".65" });
  txt(500, hMainY,  heart.centerTal?.display, { size: 38, weight: "300", opacity: ".95" });

  hRows.forEach((row, ri) => {
    const rowY   = hMainY + MAIN_GAP + ri * ROW_H;
    const extraFs = hRows.length > 2 ? 20 : 26;
    const spacing = row.length <= 1 ? 0 : Math.min(73, 146 / (row.length - 1));
    row.forEach((item, ci) => {
      const x = row.length <= 1 ? 500 : 500 - spacing * (row.length - 1) / 2 + spacing * ci;
      txt(x, rowY, item?.display, { size: extraFs, weight: "300", opacity: ".65" });
    });
  });

  // ── Solarplexus (cy=795) — mirror of Hjertecenter ─────
  // Layout: extra rows → main result → label (bottom of circle)

  const solar   = diamond.body?.solarplexus ?? {};
  const sExtras = solar.mellemnavnsBidrag ?? [];
  const sRows   = [];
  for (let i = 0; i < sExtras.length; i += 3) sRows.push(sExtras.slice(i, i + 3));

  const sBlockH = sRows.length === 0
    ? LABEL_GAP
    : MAIN_GAP + (sRows.length - 1) * ROW_H + LABEL_GAP;
  const sLabelY = 795 + sBlockH / 2 + 3;
  const sMainY  = sLabelY - LABEL_GAP;

  txt(500, sLabelY, "Solarplexus-center", { size: 22, italic: true, fill: WHITE, opacity: ".65" });
  txt(500, sMainY,  solar.centerTal?.display, { size: 38, weight: "300", opacity: ".95" });

  sRows.forEach((row, ri) => {
    const rowY    = sMainY - MAIN_GAP - (sRows.length - 1 - ri) * ROW_H;
    const extraFs = sRows.length > 2 ? 20 : 26;
    const spacing = row.length <= 1 ? 0 : Math.min(73, 146 / (row.length - 1));
    row.forEach((item, ci) => {
      const x = row.length <= 1 ? 500 : 500 - spacing * (row.length - 1) / 2 + spacing * ci;
      txt(x, rowY, item?.display, { size: extraFs, weight: "300", opacity: ".65" });
    });
  });

  // ── Livslinje ──────────────────────────────────────────

  const livslinje = diamond.livslinje ?? [];
  const first = livslinje[0];
  const last  = livslinje.length > 1 ? livslinje[livslinje.length - 1] : null;
  const mids  = livslinje.slice(1, -1);

  if (first) {
    txt(62, 510, "Fornavn", { size: 24, italic: true, anchor: "end", fill: WHITE, opacity: ".7" });
    txt(62, 548, first.display, { size: 38, weight: "300", anchor: "end", opacity: ".9" });
  }
  if (last) {
    txt(938, 510, "Efternavn",  { size: 24, italic: true, anchor: "start", fill: WHITE, opacity: ".7" });
    txt(938, 548, last.display, { size: 38, weight: "300", anchor: "start", opacity: ".9" });
  }

  // Mellemnavne — symmetric positions for 1-5 names
  const MID_POS = {
    1: [500],
    2: [360, 640],
    3: [220, 500, 780],
    4: [220, 360, 640, 780],
    5: [220, 360, 500, 640, 780]
  };
  if (mids.length > 0) {
    const n         = mids.length;
    const positions = MID_POS[n] ?? Array.from({ length: n }, (_, i) => Math.round(220 + (560 / (n - 1)) * i));
    const midFs     = n > 3 ? 22 : 30;
    mids.forEach((m, i) => {
      const x = positions[i] ?? 500;
      txt(x, 494, "Mellemnavn", { size: 18, italic: true, fill: WHITE, opacity: ".7" });
      txt(x, 518, m.display,    { size: midFs, weight: "300", opacity: ".9" });
    });
  }

  // ── Rygrad ─────────────────────────────────────────────

  txt(848, 550, "Rygrad",                  { size: 20, italic: true, anchor: "end",    fill: WHITE, opacity: ".5" });
  txt(858, 505, diamond.rygraden?.display, { size: 38, weight: "300", anchor: "middle", opacity: ".8" });

  // ── Søjletal (trekant, nederst venstre — matcher bundtal Y) ──────────────
  const soej      = diamond.soejletal ?? {};
  const tx = 80, tw = 48, th = 65;
  const triBottom = 1112;           // samme Y som bundtal-tekst
  const ty        = triBottom - th;
  const triPath   = document.createElementNS(NS, "path");
  triPath.setAttribute("d", `M ${tx} ${ty} L ${tx + tw} ${triBottom} L ${tx - tw} ${triBottom} Z`);
  triPath.setAttribute("stroke", GOLD);
  triPath.setAttribute("stroke-width", "1.5");
  triPath.setAttribute("fill", "none");
  triPath.setAttribute("opacity", ".7");
  svg.appendChild(triPath);

  const triCenterY = ty + th * 2 / 3;
  txt(tx - tw - 10, triCenterY, soej.compound,  { size: 32, weight: "300", opacity: ".9", anchor: "end" });
  txt(tx,           triCenterY, soej.reduced,   { size: 30, weight: "300", opacity: ".9" });
  txt(tx,           ty - 32,    "Søjletal",     { size: 19, italic: true, fill: WHITE, opacity: ".5" });
}
