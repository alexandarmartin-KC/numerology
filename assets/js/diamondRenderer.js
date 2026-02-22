/* ============================================================
   diamondRenderer.js — SVG rendering of the numerology diamond
   Pure vanilla JS, no dependencies.
   ============================================================ */

function renderDiamond(container, diamond) {
  if (!container) return;

  container.innerHTML = "";

  const svgNS = "http://www.w3.org/2000/svg";
  const svg = document.createElementNS(svgNS, "svg");
  svg.setAttribute("xmlns", svgNS);
  svg.setAttribute("viewBox", "0 -120 1000 1290");
  svg.setAttribute("preserveAspectRatio", "xMidYMid meet");
  svg.setAttribute("width", "100%");
  svg.setAttribute("height", "100%");
  svg.style.display = "block";

  const CLR = "#222";

  const addLine = (x1, y1, x2, y2, sw = 1.5) => {
    const el = document.createElementNS(svgNS, "line");
    el.setAttribute("x1", x1); el.setAttribute("y1", y1);
    el.setAttribute("x2", x2); el.setAttribute("y2", y2);
    el.setAttribute("stroke", CLR); el.setAttribute("stroke-width", sw);
    svg.appendChild(el);
  };

  const addCircle = (cx, cy, r) => {
    const el = document.createElementNS(svgNS, "circle");
    el.setAttribute("cx", cx); el.setAttribute("cy", cy); el.setAttribute("r", r);
    el.setAttribute("stroke", CLR); el.setAttribute("stroke-width", 1.5);
    el.setAttribute("fill", "none");
    svg.appendChild(el);
  };

  const addRect = (x, y, w, h) => {
    const el = document.createElementNS(svgNS, "rect");
    el.setAttribute("x", x); el.setAttribute("y", y);
    el.setAttribute("width", w); el.setAttribute("height", h);
    el.setAttribute("stroke", CLR); el.setAttribute("stroke-width", 1.5);
    el.setAttribute("fill", "none");
    svg.appendChild(el);
  };

  const txt = (x, y, text, size = 24, anchor = "middle") => {
    const el = document.createElementNS(svgNS, "text");
    el.setAttribute("x", x); el.setAttribute("y", y);
    el.setAttribute("text-anchor", anchor);
    el.setAttribute("dominant-baseline", "middle");
    el.setAttribute("font-size", size);
    el.setAttribute("font-family", "system-ui, -apple-system, sans-serif");
    el.setAttribute("fill", CLR);
    el.textContent = String(text ?? "");
    svg.appendChild(el);
  };

  // ── Geometri ──
  const TOP    = { x: 500, y: 0 };
  const LEFT   = { x: 80,  y: 530 };
  const RIGHT  = { x: 920, y: 530 };
  const BOTTOM = { x: 500, y: 1060 };
  const LIFE_Y = 530;

  // Ydre diamant
  addLine(TOP.x, TOP.y, RIGHT.x, RIGHT.y);
  addLine(RIGHT.x, RIGHT.y, BOTTOM.x, BOTTOM.y);
  addLine(BOTTOM.x, BOTTOM.y, LEFT.x, LEFT.y);
  addLine(LEFT.x, LEFT.y, TOP.x, TOP.y);

  // Livslinje
  addLine(LEFT.x, LIFE_Y, RIGHT.x, LIFE_Y);

  // Hjertecirkel (øverst)
  addCircle(500, 330, 110);

  // Solarcirkel (nederst)
  addCircle(500, 740, 110);

  // Rygraden (bue i hjørnet hvor diagonal møder livslinjen)
  const arcR = 90;
  // Punkt på diagonalen (TOP→RIGHT), arcR px fra RIGHT
  const dxTR = TOP.x - RIGHT.x, dyTR = TOP.y - RIGHT.y;
  const lenTR = Math.sqrt(dxTR * dxTR + dyTR * dyTR);
  const arcDiagX = RIGHT.x + (dxTR / lenTR) * arcR;
  const arcDiagY = RIGHT.y + (dyTR / lenTR) * arcR;
  // Punkt på livslinjen, arcR px til venstre for RIGHT
  const arcLineX = RIGHT.x - arcR;
  const arcLineY = LIFE_Y;
  // SVG arc path
  const arcPath = document.createElementNS(svgNS, "path");
  arcPath.setAttribute("d", `M ${arcDiagX} ${arcDiagY} A ${arcR} ${arcR} 0 0 0 ${arcLineX} ${arcLineY}`);
  arcPath.setAttribute("stroke", CLR);
  arcPath.setAttribute("stroke-width", 1.5);
  arcPath.setAttribute("fill", "none");
  svg.appendChild(arcPath);

  // ── Grundenergi (top) ──
  txt(TOP.x, TOP.y - 35, diamond.grundenergi?.display, 26);

  // ── Bundtal (bund) ──
  txt(BOTTOM.x, BOTTOM.y + 35, diamond.bundtal?.display, 26);

  // ── Aura (langs diagonalerne) ──
  txt(235, 265, diamond.aura?.auraUpperLeft?.display, 26);
  txt(765, 265, diamond.aura?.auraUpperRight?.display, 26);
  txt(245, 815, diamond.aura?.auraLowerLeft?.display, 26);
  txt(755, 815, diamond.aura?.auraLowerRight?.display, 26);

  // ── Livslinje: fornavn / mellemnavne / efternavn ──
  const line  = diamond.livslinje ?? [];
  const first = line[0];
  const last  = line.length > 1 ? line[line.length - 1] : null;
  const mids  = line.slice(1, -1);

  // Fornavn (venstre, udenfor diamant)
  if (first) txt(LEFT.x - 15, LIFE_Y, first.display, 26, "end");

  // Efternavn (højre, udenfor diamant)
  if (last) txt(RIGHT.x + 15, LIFE_Y, last.display, 26, "start");

  // Mellemnavne (jævnt fordelt langs linjen)
  if (mids.length > 0) {
    const fs = mids.length > 6 ? 20 : mids.length > 3 ? 22 : 26;
    const mx0 = 280, mx1 = 720;
    const mStep = mids.length === 1 ? 0 : (mx1 - mx0) / (mids.length - 1);
    mids.forEach((m, i) => {
      const x = mids.length === 1 ? 500 : mx0 + mStep * i;
      txt(x, LIFE_Y - 25, m.display, fs);
    });
  }

  // ── Hjertecenter ──
  const heart = diamond.body?.hjertecenter;
  const hExtras = heart?.mellemnavnsBidrag ?? [];
  const hRows = [];
  const hMaxPerRow = 3;
  for (let i = 0; i < hExtras.length; i += hMaxPerRow) hRows.push(hExtras.slice(i, i + hMaxPerRow));
  const hTotalRows = hRows.length;
  const hFs = hTotalRows > 2 ? 18 : hTotalRows > 1 ? 20 : 22;
  const hRowH = hTotalRows > 2 ? 24 : 28;
  const hGap = 30; // gap between centerTal and first extras row
  // Heart: centerTal on top, extras below — center vertically in circle(500,330,r=110)
  if (hTotalRows > 0) {
    const hContentH = hGap + (hTotalRows - 1) * hRowH; // from centerTal to last row
    const hTopY = 330 - hContentH / 2;
    txt(500, hTopY, heart?.centerTal?.display, 26);
    const hStartY = hTopY + hGap;
    hRows.forEach((row, ri) => {
      const spacing = Math.min(60, 160 / row.length);
      const w = row.length <= 1 ? 0 : (row.length - 1) * spacing;
      const x0 = 500 - w / 2;
      const step = row.length <= 1 ? 0 : w / (row.length - 1);
      row.forEach((t, ci) => txt(row.length === 1 ? 500 : x0 + step * ci, hStartY + ri * hRowH, t?.display, hFs));
    });
  } else {
    txt(500, 330, heart?.centerTal?.display, 26);
  }

  // ── Solarplexus ──
  const solar = diamond.body?.solarplexus;
  const sExtras = solar?.mellemnavnsBidrag ?? [];
  if (sExtras.length > 0) {
    const maxPerRow = 3;
    const rows = [];
    for (let i = 0; i < sExtras.length; i += maxPerRow) rows.push(sExtras.slice(i, i + maxPerRow));
    const sFs = rows.length > 2 ? 18 : rows.length > 1 ? 20 : 22;
    const sRowH = rows.length > 2 ? 24 : 28;
    const sGap = 30; // same gap as heart
    // Solar: extras on top, centerTal below — center vertically in circle(500,740,r=110)
    const sContentH = (rows.length - 1) * sRowH + sGap; // from first row to centerTal
    const sTopY = 740 - sContentH / 2;
    rows.forEach((row, ri) => {
      const spacing = Math.min(60, 160 / row.length);
      const w = row.length <= 1 ? 0 : (row.length - 1) * spacing;
      const x0 = 500 - w / 2;
      const step = row.length <= 1 ? 0 : w / (row.length - 1);
      row.forEach((t, ci) => txt(row.length === 1 ? 500 : x0 + step * ci, sTopY + ri * sRowH, t?.display, sFs));
    });
    txt(500, sTopY + (rows.length - 1) * sRowH + sGap, solar?.centerTal?.display, 26);
  } else {
    txt(500, 740, solar?.centerTal?.display, 26);
  }

  // ── Rygraden (inde i buen) ──
  txt(RIGHT.x - 55, LIFE_Y - 20, diamond.firkanttal?.display, 20);

  // ── Søjletal (lille trekant, nederst venstre) ──
  const tx = 120, th = 60, tw = 45;
  const triBottom = BOTTOM.y + 35;          // alligner med bundtal
  const ty = triBottom - th;                // top af trekant
  addLine(tx, ty, tx + tw, triBottom);
  addLine(tx + tw, triBottom, tx - tw, triBottom);
  addLine(tx - tw, triBottom, tx, ty);

  const soej = diamond.soejletal;
  const triCenterY = ty + th * 2 / 3;
  txt(tx - tw - 12, triCenterY, soej?.compound, 26, "end");
  txt(tx, triCenterY, soej?.reduced, 26);

  container.appendChild(svg);
}
