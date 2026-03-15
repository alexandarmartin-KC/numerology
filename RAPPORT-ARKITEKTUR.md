# Rapport-generering: Teknisk arkitektur

Dette dokument beskriver præcis, hvordan en numerologisk rapport bygges — fra brugerinput til færdigt PDF-klar output.

---

## 1. Overordnet flow

```
Bruger → rapport.html (browser)
           │
           ├─ computeDiamond(navn, fødselsdato)          [diamondEngine.js]
           ├─ computeYearEnergies(år, ...)                [aarstalsraekker-logik]
           ├─ gatherKnowledge()  →  GET /api/get-knowledge
           │
           └─ POST /api/generate-rapport
                  {diamond, aarstalsraekker, knowledge, language}
                        │
                        ├─ buildSystemPrompt(knowledge, lang)
                        ├─ buildUserPrompt(diamond, aarstalsraekker, knowledge)
                        │
                        └─ Claude API (claude-opus-4-5, max_tokens: 8000)
                                │
                                └─ Markdown-tekst → markdownToHTML() → sidevisning
```

---

## 2. Diamant-beregning (`diamondEngine.js`)

Input: `fullName` (string), `birthDateISO` (YYYY-MM-DD)

### Bogstavværdier (Chaldæisk system, 1-8)
```
A I J Q Y Å = 1
B K R       = 2
C G L S     = 3
D M T       = 4
E H N X     = 5
U V W Æ     = 6
O Z Ø       = 7
F P         = 8
```

### Navnenormalisering
1. Trim + store bogstaver
2. Bindestreg og tankestreg → mellemrum
3. Apostrof-varianter → mellemrum (O'Connor → O Connor)
4. Fjern diakritiske tegn, men bevar Æ, Ø, Å
5. Split på whitespace → navnedele (minimum 2 krævet)

### Livslinje-regel (minimum compound)
Hvert navnedels bogstavsum (compound) **skal** være ≥ 10:
- Hvis compound < 10: tilføj grundenergi (top = fødselsdagens ciffer-sum)
- Stadig < 10: tilføj yderligere 9 (ophøjet form)

### Positioner og hvad de beregnes fra

| Position | Beregning |
|---|---|
| **Grundenergi** (top) | `digitReduce(fødselsdag)` |
| **Livslinje** | Hvert navnedel → compound/reduced (med minimum-regel) |
| **Bundtal** | Sum af alle livslinjes *reduced*-værdier → reduce |
| **Aura øvre venstre** | grundenergi.reduced + fornavn.reduced |
| **Aura øvre højre** | grundenergi.reduced + efternavn.reduced |
| **Aura nedre venstre** | bundtal.reduced + fornavn.reduced |
| **Aura nedre højre** | bundtal.reduced + efternavn.reduced |
| **Hjertecenter** | auraØvreVenstre.reduced + auraØvreHøjre.reduced |
| **Hjerte-ekstra** | (for hvert mellemnavn) mellemnavn.reduced + hjertecenter.reduced |
| **Solarplexus** | auraNedreVenstre.reduced + auraNedreHøjre.reduced |
| **Solar-ekstra** | (for hvert mellemnavn) mellemnavn.reduced + solarplexus.reduced |
| **Rygraden** | grundenergi.value + bundtal.reduced → reduce |
| **Søjletal** | Sum af alle *reduced* i diamanten (top + bund + hjerte + hjerte-ekstra + solar + solar-ekstra + mellemnavne) |

### Output-struktur (`diamond`-objektet)
```json
{
  "input": {
    "fullName": "Anne-Marie Katje Jensen",
    "birthDate": { "day": 18, "month": 11, "year": 1980 }
  },
  "diamond": {
    "grundenergi":  { "value": 9, "display": "9" },
    "livslinje": [
      { "role": "fornavn",    "name": "ANNE",   "compound": 16, "reduced": 7, "display": "16/7" },
      { "role": "mellemnavn", "name": "MARIE",  "compound": 22, "reduced": 4, "display": "22/4" },
      { "role": "mellemnavn", "name": "KATJE",  "compound": 22, "reduced": 4, "display": "22/4" },
      { "role": "efternavn",  "name": "JENSEN", "compound": 24, "reduced": 6, "display": "24/6" }
    ],
    "bundtal":   { "compound": 21, "reduced": 3, "display": "21/3" },
    "aura": {
      "auraUpperLeft":  { "compound": 16, "reduced": 7, "display": "16/7" },
      "auraUpperRight": { "compound": 15, "reduced": 6, "display": "15/6" },
      "auraLowerLeft":  { "compound": 10, "reduced": 1, "display": "10/1" },
      "auraLowerRight": { "compound": 9,  "reduced": 9, "display": "9" }
    },
    "body": {
      "hjertecenter": {
        "centerTal": { "compound": 13, "reduced": 4, "display": "13/4" },
        "mellemnavnsBidrag": [
          { "compound": 8, "reduced": 8, "display": "8" },
          { "compound": 8, "reduced": 8, "display": "8" }
        ]
      },
      "solarplexus": {
        "centerTal": { "compound": 10, "reduced": 1, "display": "10/1" },
        "mellemnavnsBidrag": [
          { "compound": 5, "reduced": 5, "display": "5" },
          { "compound": 5, "reduced": 5, "display": "5" }
        ]
      }
    },
    "rygraden":  { "compound": 12, "reduced": 3, "display": "12/3" },
    "soejletal": { "compound": 51, "reduced": 6 }
  }
}
```

---

## 3. Årstalsrækker

Fra `rapport.html` beregnes årstalsrækkerne i browseren:
```js
for (let i = 0; i < numYears; i++) {
  aarstalsraekker.push(
    computeYearEnergies(fromYear + i, birthYear, grund, bund, soejle)
  );
}
```

Hvert år-objekt i arrayet har formen:
```json
{
  "year": 2025,
  "yearReduced": 9,
  "cycleType": "1-2-4-8-7-5",
  "energies": [9, 3, 6],
  "specialRulesMatched": ["Regel X: ..."]
}
```

---

## 4. Knowledge-data (`/api/get-knowledge`)

Alt den numerologiske viden hentes fra databasen og samles i ét objekt. Det bruges til at bygge system-prompten:

| Felt | Datakilde (DB-tabel/kolonne) | Bruges i |
|---|---|---|
| `aboutNumerology` | `generelt.aboutNumerology` | System-prompt |
| `defRent` | `generelt.defRent` | System-prompt |
| `defUrent` | `generelt.defUrent` | System-prompt |
| `blokkeAfTal` | `generelt.blokkeAfTal` | System-prompt |
| `diamantAar` | `generelt.diamantAarstalsraekker` | System-prompt |
| `udrensning` | `generelt.udrensning` | System-prompt |
| `numerologiAlder` | `generelt.numerologiAlder` | System-prompt |
| `rapportStil` | `generelt.rapportStil` | System-prompt |
| `eksempelRapport` | `generelt.eksempelRapport` | System-prompt |
| `rapportGlobalInstruction` | `generelt.rapportGlobalInstruction` | System-prompt |
| `rapportSections` | `rapport_sections` (tabel) | User-prompt (struktur) |
| `energies` | `diamant_energies` (tabel) | System-prompt (energibeskrivelser) |
| `positions` | `diamant_positions` (tabel) | System-prompt (positionsbeskrivelser) |
| `diamondRules` | `diamant_rules` (tabel) | System-prompt (specielle regler) |
| `aarEnergies` | `aarstalsraekker_energies` (tabel) | System-prompt |
| `cycles_*` | `aarstalsraekker_cycles` (tabel) | System-prompt |
| `aarRules` | `aarstalsraekker_rules` (tabel) | System-prompt |
| `astrologySign` | Beregnes i browser ud fra fødselsdato | System-prompt |
| `energiesWithImages` | `diamant_energies.billede_url` | Billedpladsholdere i system-prompt |

---

## 5. System-prompt opbygning (`buildSystemPrompt`)

Prompt'en samles i denne rækkefølge:

```
1. Rolle-introduktion (sprogstyret: da/en/de/sv/no)
2. VIGTIGE REGLER
   - Kun brug given viden
   - Specielle regler fortolkes isoleret
   - Livslinjen er IKKE sekventiel/rejse
   - Varmt, personligt, professionelt sprog
3. [Hvis billeder] BILLEDER: liste + pladsholder-instruktion [BILLEDE:X]
4. ## Overordnet instruktion         (rapportGlobalInstruction)
5. ## Om numerologi                  (aboutNumerology)
6. ## Definition: Rent numeroskop    (defRent)
7. ## Definition: Urent numeroskop   (defUrent)
8. ## Blokke af tal                  (blokkeAfTal)
9. ## Diamant og årstalsrækker       (diamantAar)
10. ## Udrensning                    (udrensning)
11. ## Numerologi og alder           (numerologiAlder)
12. ## Rapportens stil               (rapportStil)
13. ## Eksempelrapport               (eksempelRapport)
14. ## Energibeskrivelser (diamant) — for hver energi:
    - Nøgleord rent
    - Nøgleord urent
    - Grundenergi (summary hvis grundtal 1-9, ellers grundenergi-felt)
    - Beskrivelse
    - Ubalance
    - Helhedsfunktion
    - Planet
    - Kendte
15. ## Positioner i diamanten        (positions)
16. ## Specielle regler (diamant)    (diamondRules: condition + description)
17. ## Energibeskrivelser (årstalsrækker) (aarEnergies: keywords + beskrivelse)
18. ## Om cyklusser                  (cycles_about)
19. ## Rapportens stil (årstalsrækker) (cycles_style)
20. ## Cyklus 1-2-4-8-7-5           (cycles_124875)
21. ## Cyklus 3-6                   (cycles_36)
22. ## Cyklus 9                     (cycles_9)
23. ## Specielle regler (årstalsrækker) (aarRules)
24. ## Astrologi generelt           (astrologyGenerelt)
25. ## Stjernetegn: [navn]          (astrologySign)
```

---

## 6. User-prompt opbygning (`buildUserPrompt`)

```
"Skriv en komplet numerologisk rapport for denne person:"

## Persondata
Navn: [fullName]
Fødselsdato: DD/MM/YYYY

## Diamant
Grundenergi: [display]
Livslinje: Fornavn (display) → Mellemnavn (display) → Efternavn (display)
Bundtal: [display]
Aura øvre venstre: [display]
Aura øvre højre: [display]
Aura nedre venstre: [display]
Aura nedre højre: [display]
Hjertecenter: [display]
[Hjerte-ekstra: ... (hvis mellemnavne)]
Solarplexus: [display]
[Solar-ekstra: ... (hvis mellemnavne)]
Rygraden: [display]
Søjletal: compound/reduced

## Årstalsrækker
Cyklus-type: [typ]
### YYYY (grundtal: N)
Energier: X, Y, Z
[Matchede specielle regler: ...]

## Rapportstruktur
[Hvis rapportSections er defineret:]
"Organiser rapporten i PRÆCIS følgende sektioner, i denne rækkefølge:"

### Sektion 1: [title]
Datakilder at bruge: [sources → human-readable labels]
Instruktion: [instruction]

### Sektion 2: ...
...
"Brug formatering med overskrifter (##) for hver sektion."

[Ellers fallback:]
"Skriv rapporten med to hoveddele:
1. DIAMANTEN ...
2. ÅRSTALSRÆKKER ..."
```

### Kildelabels (section `sources`-felter)
```
grundenergi        → "Grundenergi"
livslinje          → "Livslinje"
bundtal            → "Bundtal"
aura               → "Aura"
hjerte_solar       → "Hjertecenter + Solarplexus"
rygraden           → "Rygraden"
soejletal          → "Søjletal"
specielle_diamant  → "Specielle regler (diamant)"
helhedsvurdering   → "Helhedsvurdering"
aarstalsraekker    → "Årstalsrækker"
cyklusser          → "Cyklusser"
specielle_aar      → "Specielle regler (årstalsrækker)"
stjernetegn        → "Stjernetegn"
```

---

## 7. API-kald til Claude

```
Model:       claude-opus-4-5
Max tokens:  8000
Endpoint:    POST https://api.anthropic.com/v1/messages
Timeout:     120 sekunder

Payload:
{
  "model":   "claude-opus-4-5",
  "system":  <systemPrompt>,
  "messages": [{ "role": "user", "content": <userPrompt> }],
  "max_tokens": 8000
}
```

Claude returnerer ren Markdown-tekst i `content[0].text`.

---

## 8. Post-processing og sidevisning

1. Fjern `<intern_analyse>...</intern_analyse>`-blokke (hvis AI bruger dem til intern ræsonnering)
2. Fjern evt. H1-titel som AI sætter øverst
3. `markdownToHTML()` — konverterer Markdown til HTML
4. `injectEnergyImages()` — erstatter `[BILLEDE:X]`-pladsholdere med `<img>` tags

### Sidestruktur i output (i rækkefølge)
```
1. Forside           buildCoverPageDOM()   — navn, plan, diamant-visual
2. Indholdsfortegnelse buildTocPage()      — h2-overskrifter fra rapporten
3. "Om numerologi"-side                   — rapportOmNumerologi-tekst
4. Rapport-tekst     (pagineret i .rapport-page bokse)
5. Diamant-visualiseringsside
```

---

## 9. Admin-konfiguration (hvad kan ændres)

Alt indhold styres fra admin-panelet og gemmes i databasen:

| Admin-side | Hvad det påvirker |
|---|---|
| `input-rapport.html` | Rapportens sektioner (titel, datakilder, instrukser) + global instruktion |
| `input-generelt.html` | Al generel viden (defRent/Urent, blokke, stile, eksempler etc.) |
| `input-diamant.html` | Energibeskrivelser, positioner, specielle regler for diamanten |
| `input-aarstalsraekker.html` | Årsenergi-beskrivelser, cyklus-tekster, specielle regler for år |
| `input-grundenergier.html` | Grundenergi-resuméer (summary-felt) + billeder |

---

## 10. Databasetabeller (relevante for rapport)

```
generelt              — 1 række, alle konfigurationsfelter
rapport_sections      — N rækker: title, instruction, sources (JSON-array)
diamant_energies      — En række pr. energi/tal
diamant_positions     — Positionsbeskrivelser
diamant_rules         — Specielle regler med condition + description
aarstalsraekker_energies — Tal → keywords/beskrivelse
aarstalsraekker_cycles   — Cyklus-typer
aarstalsraekker_rules    — Specielle årsregler
```
