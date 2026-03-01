# Numerology

Numerologisk analyse- og rapportgenereringsværktøj bygget med Vercel (Node.js serverless) + PHP (shared hosting) + MySQL + Stripe + OpenAI.

---

## Opsætning

### 1. Node.js (Vercel)

```bash
npm install
```

Sæt følgende miljøvariabler i Vercel-dashboard (eller `.env.local` til lokal dev):

| Variabel | Beskrivelse |
|---|---|
| `STRIPE_SECRET_KEY` | Din Stripe hemmelige nøgle |
| `ALLOWED_ORIGIN` | Tilladt frontend-origin (f.eks. `https://dit-domæne.dk`) |
| `OPENAI_API_KEY` | Din OpenAI API-nøgle |
| `BASE_URL` | Basis-URL til success/cancel redirect |

### 2. PHP (shared hosting)

Kopier `api/.env.example.php` til `api/.env.php` og udfyld med dine værdier:

```bash
cp api/.env.example.php api/.env.php
```

> **VIGTIGT:** `api/.env.php` er i `.gitignore` og må aldrig committes til git.

#### Nødvendige PHP-miljøvariabler

| Variabel | Beskrivelse |
|---|---|
| `DB_HOST` | Database-host (typisk `localhost`) |
| `DB_USER` | Database-brugernavn |
| `DB_PASS` | Database-kodeord |
| `DB_NAME` | Database-navn |
| `ALLOWED_ORIGIN` | Tilladt CORS-origin |
| `ADMIN_API_KEY` | Stærk hemmelig nøgle til admin POST-endpoints |

---

## Sikkerhed

- Admin-endpoints (`/api/save-*.php`, `/api/upload-image.php`) kræver `X-Admin-Key` header med `ADMIN_API_KEY`-værdien.
- CORS er begrænset til `ALLOWED_ORIGIN` i både PHP og JS-endpoints.
- Alle SQL-forespørgsler bruger prepared statements.
- DB-credentials og API-nøgler gemmes KUN i miljøvariabler — aldrig i kildekoden.
