(() => {
  const form       = document.getElementById("diamondForm");
  const fullNameEl = document.getElementById("fullName");
  const birthDateEl = document.getElementById("birthDate");
  const exampleBtn = document.getElementById("exampleBtn");
  const outputEl   = document.getElementById("diamondOutput");

  /* ---------- Output helpers ---------- */

  function setOutput(html) {
    outputEl.classList.remove("text-secondary");
    outputEl.innerHTML = html;
  }

  function setInfo(text) {
    outputEl.classList.add("text-secondary");
    outputEl.textContent = text;
  }

  function esc(str) {
    return String(str)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  /* ---------- Render diamond result ---------- */

  function renderResult(r) {
    const d = r.diamond;
    const middles = d.livslinje.filter(e => e.role === "mellemnavn");

    function item(label, value) {
      return `<li class="list-group-item d-flex justify-content-between align-items-center">
        <span>${label}</span><strong>${value}</strong></li>`;
    }
    function header(text) {
      return `<li class="list-group-item list-group-item-light text-uppercase small fw-bold">${text}</li>`;
    }

    let html = '<ul class="list-group list-group-flush">';

    // Grundenergi
    html += item("Grundenergi (fødselsdag)", d.grundenergi.display);

    // Livslinje
    html += header("Livslinje");
    d.livslinje.forEach(e => {
      const label = e.role === "fornavn" ? "Fornavn" : e.role === "efternavn" ? "Efternavn" : "Mellemnavn";
      html += item(`${label}: ${esc(e.name)}`, e.display);
    });

    // Bundtal
    html += item("Bundtal", d.bundtal.display);

    // Aura
    html += header("Aura");
    html += item("Øverst venstre", d.aura.auraUpperLeft.display);
    html += item("Øverst højre", d.aura.auraUpperRight.display);
    html += item("Nederst venstre", d.aura.auraLowerLeft.display);
    html += item("Nederst højre", d.aura.auraLowerRight.display);

    // Hjertecenter
    html += header("Hjertecenter");
    html += item("Hjertecenter", d.body.hjertecenter.centerTal.display);
    d.body.hjertecenter.mellemnavnsBidrag.forEach((ex, i) => {
      html += item(`Hjerte-ekstra (${esc(middles[i]?.name || "")})`, ex.display);
    });

    // Solarplexus
    html += header("Solarplexus");
    html += item("Solarplexus", d.body.solarplexus.centerTal.display);
    d.body.solarplexus.mellemnavnsBidrag.forEach((ex, i) => {
      html += item(`Solar-ekstra (${esc(middles[i]?.name || "")})`, ex.display);
    });

    // Rygraden
    html += header("Rygraden");
    html += item("Rygraden", d.rygraden.display);

    // Søjletal
    html += header("Søjletal");
    html += item("Søjletal", `${d.soejletal.compound}/${d.soejletal.reduced}`);

    html += '</ul>';
    setOutput(html);
  }

  /* ---------- Energy descriptions ---------- */

  let energiesData = null;

  fetch('energies.v1.json')
    .then(r => r.json())
    .then(async data => {
      const base = data.energies;
      // Merge any localStorage edits on top
      const saved = localStorage.getItem('energiesEdits');
      const edits = saved ? JSON.parse(saved) : {};
      energiesData = {};
      for (const [key, val] of Object.entries(base)) {
        energiesData[key] = { ...val, ...(edits[key] || {}) };
      }
      // Overlay med DB-data (så alle besøgende ser admin-redigeringer)
      try {
        const dbRes = await fetch('/api/save-diamant.php?type=energies');
        if (dbRes.ok) {
          const dbEnergies = await dbRes.json();
          if (Array.isArray(dbEnergies) && dbEnergies.length) {
            const freshEdits = JSON.parse(localStorage.getItem('energiesEdits') || '{}');
            dbEnergies.forEach(e => {
              if (!e.display) return;
              if (!freshEdits[e.display]) freshEdits[e.display] = {};
              Object.assign(freshEdits[e.display], e);
              if (!energiesData[e.display]) energiesData[e.display] = {};
              Object.assign(energiesData[e.display], e);
            });
            localStorage.setItem('energiesEdits', JSON.stringify(freshEdits));
          }
        }
      } catch (e) { console.warn('DB energier load fejlede:', e); }
    })
    .catch(() => { console.warn('Kunne ikke hente energies.v1.json'); });

  function renderEnergyDescriptions(d) {
    const container = document.getElementById('energyDescriptions');
    if (!container || !energiesData) { if (container) container.innerHTML = ''; return; }

    const soejDisplay = d.soejletal.compound < 10 ? `${d.soejletal.compound}` : `${d.soejletal.compound}/${d.soejletal.reduced}`;

    // Organize by category
    const sections = [
      {
        title: 'Grundenergi',
        items: [{ display: d.grundenergi.display, label: 'Fødselsdag' }]
      },
      {
        title: 'Navne',
        items: d.livslinje.map(e => ({
          display: e.display,
          label: e.role === 'fornavn' ? 'Fornavn' : e.role === 'efternavn' ? 'Efternavn' : 'Mellemnavn'
        })).concat([{ display: d.bundtal.display, label: 'Bundtal' }])
      },
      {
        title: 'Aura',
        items: [
          { display: d.aura.auraUpperLeft.display, label: 'Øverst venstre' },
          { display: d.aura.auraUpperRight.display, label: 'Øverst højre' },
          { display: d.aura.auraLowerLeft.display, label: 'Nederst venstre' },
          { display: d.aura.auraLowerRight.display, label: 'Nederst højre' }
        ]
      },
      {
        title: 'Hjertecenter',
        items: [{ display: d.body.hjertecenter.centerTal.display, label: 'Center' }]
          .concat(d.body.hjertecenter.mellemnavnsBidrag.map(ex => ({ display: ex.display, label: 'Ekstra' })))
      },
      {
        title: 'Solarplexus',
        items: [{ display: d.body.solarplexus.centerTal.display, label: 'Center' }]
          .concat(d.body.solarplexus.mellemnavnsBidrag.map(ex => ({ display: ex.display, label: 'Ekstra' })))
      },
      {
        title: 'Rygraden',
        items: [{ display: d.rygraden.display, label: 'Rygraden' }]
      },
      {
        title: 'Søjletal',
        items: [{ display: soejDisplay, label: 'Søjletal' }]
      }
    ];

    function energyCard(display, label) {
      const info = energiesData[display];
      if (!info) return '';
      const kw = info.keywords || '';
      if (!kw) return '';
      let h = `<div class="d-flex align-items-baseline gap-2 mb-1">`;
      h += `<span class="fw-bold" style="font-size:15px;">${esc(display)}</span>`;
      h += `<span class="text-muted" style="font-size:12px;">${esc(label)}</span>`;
      h += `</div>`;
      h += `<div class="text-muted" style="font-size:12px;margin-top:2px;"><em>${esc(kw)}</em></div>`;
      return h;
    }

    let html = '<h3 class="h6 mb-3">Energiforklaringer</h3>';

    // Deduplicate across all sections
    const seen = new Set();

    sections.forEach(sec => {
      const cards = [];
      sec.items.forEach(it => {
        if (seen.has(it.display)) return;
        const card = energyCard(it.display, it.label);
        if (card) {
          cards.push(card);
          seen.add(it.display);
        }
      });
      if (cards.length === 0) return;

      html += `<h6 class="text-uppercase text-muted small mt-3 mb-2" style="letter-spacing:0.5px;">${esc(sec.title)}</h6>`;
      html += `<div class="row g-2 mb-2">`;
      cards.forEach(c => {
        html += `<div class="col-md-6 col-lg-4"><div class="card h-100" style="background:#f8f9fa;"><div class="card-body py-2 px-3">${c}</div></div></div>`;
      });
      html += `</div>`;
    });

    container.innerHTML = html;
  }

  /* ---------- Example button ---------- */

  exampleBtn?.addEventListener("click", () => {
    fullNameEl.value  = "Anne-Marie Katje Jensen";
    birthDateEl.value = "1980-11-18";
    setInfo("Eksempeldata indsat. Tryk \u201CBeregn diamant\u201D.");
  });

  /* ---------- Persist data for Årstalsrækker ---------- */

  function persistDiamondData(result, fullName, birthDateISO) {
    const data = {
      fullName: fullName,
      birthDateISO: birthDateISO,
      birthDate: result.input.birthDate,
      birthYear: result.input.birthDate.year,
      grund: result.diamond.grundenergi.value,
      bund: result.diamond.bundtal.reduced,
      soejle: result.diamond.soejletal.reduced
    };
    localStorage.setItem("numerologyData", JSON.stringify(data));
  }

  /* ---------- Form submit ---------- */

  form?.addEventListener("submit", (e) => {
    e.preventDefault();

    const fullName  = (fullNameEl.value || "").trim();
    const birthDate = birthDateEl.value; // yyyy-mm-dd

    if (!fullName) {
      setOutput(`<div class="alert alert-danger mb-0">Indtast venligst et fulde navn.</div>`);
      return;
    }
    if (!birthDate) {
      setOutput(`<div class="alert alert-danger mb-0">Vælg venligst en fødselsdato.</div>`);
      return;
    }

    try {
      const result = computeDiamond(fullName, birthDate);
      renderDiamond(document.getElementById("diamondCanvas"), result.diamond);
      renderResult(result);
      renderEnergyDescriptions(result.diamond);
      persistDiamondData(result, fullName, birthDate);
    } catch (err) {
      setOutput(`<div class="alert alert-danger mb-0">${esc(err.message)}</div>`);
    }
  });
})();
