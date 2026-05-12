# PowerCreatives — Architecture Vision

## Varför

Pluginet är idag byggt med en **lager-baserad** struktur (alla controllers i `rest/`, alla providers i `providers/`). Detta skapar tight coupling mellan features, gör det svårt att lägga till nya moduler, och kräver att man rör delade filer vid varje ändring.

**Målet:** En **modulär monolith med vertical slice architecture** — varje feature-modul (Video, Image, Copy, etc.) är ett självständigt, oberoende paket. Moduler kan läggas till, tas bort eller ändras utan att påverka andra.

---

## Arkitekturprinciper

Baserat på industry best practices (DDD Bounded Contexts, Vertical Slice Architecture, Plugin Architecture Pattern, Data-Driven Registries):

| Princip | Beskrivning |
|---------|-------------|
| **Feature-first** | Kod grupperas per feature, inte per tekniskt lager |
| **Loose coupling** | Moduler har inga direkta kodberoenden till varandra |
| **High cohesion** | Allt en modul behöver lever i sin mapp |
| **Contract-based communication** | Moduler kan dela data via definierade kontrakt — aldrig genom att importera varandras interna filer |
| **Data-driven config** | Nya modeller/providers registreras via JSON — ingen kodändring |
| **Graceful degradation** | Om en modul kraschar/tas bort fortsätter resten fungera |

---

## Målarkitektur

```
includes/
│
├── core/                              ← Delad infrastruktur (ägs inte av någon modul)
│   ├── auth/                          ← Autentisering, permissions
│   ├── db/                            ← Schema, migrations, query helpers
│   ├── storage/                       ← Filhantering, media library
│   ├── llm/                           ← LLM-abstraction (prompt, streaming)
│   ├── sse/                           ← Server-Sent Events
│   ├── settings/                      ← Globala inställningar
│   ├── integrations/                  ← API-nycklar, provider-kopplingar
│   ├── providers/                     ← Alla AI-providers
│   │   ├── provider-interface.php     ← Kontrakt
│   │   ├── provider-registry.php      ← Factory (JSON-driven)
│   │   ├── openai/                    ← OpenAI (DALL-E, GPT, etc.)
│   │   ├── google/                    ← Google (Imagen, Gemini, etc.)
│   │   └── kieai/                     ← Kie.ai (Marketplace + Dedicated)
│   │       ├── kieai-provider.php
│   │       ├── kieai-api.php
│   │       ├── kieai-marketplace.php
│   │       ├── kieai-input-mapper.php
│   │       └── models.json            ← Data-driven modellregister
│   └── base-controller.php            ← Delade REST-helpers
│
├── modules/                           ← Feature-moduler (oberoende)
│   ├── image/
│   │   ├── controller.php             ← REST-endpoints (tunn)
│   │   ├── service.php                ← Business-logik
│   │   ├── config.php                 ← Modul-specifik konfiguration
│   │   └── types.php                  ← Data-typer/strukturer
│   │
│   ├── video/
│   │   ├── controller.php
│   │   ├── service.php
│   │   ├── config.php
│   │   └── types.php
│   │
│   ├── copy/
│   │   ├── controller.php
│   │   ├── service.php
│   │   ├── prompts.php                ← Modul-specifik prompt-logik
│   │   ├── config.php
│   │   └── types.php
│   │
│   ├── brands/
│   │   ├── controller.php
│   │   ├── service.php
│   │   ├── scraper.php                ← Brand URL scraping
│   │   └── types.php
│   │
│   ├── templates/
│   │   ├── controller.php
│   │   ├── service.php
│   │   ├── resolver.php               ← Template resolution
│   │   └── types.php
│   │
│   └── assets/
│       ├── controller.php
│       ├── service.php
│       └── types.php
│
└── module-loader.php                  ← Auto-discovery: skannar modules/ och registrerar
```

---

## Hur moduler registreras (auto-discovery)

Varje modul har en `config.php` som returnerar metadata:

```php
// modules/video/config.php
return [
    'id'           => 'video',
    'name'         => 'Video Creation',
    'version'      => '1.0.0',
    'capabilities' => ['generate_video', 'check_status'],
    'rest_namespace' => 'pcm/v1/video',
    'controller'   => 'PCM_Module_Video_Controller',
];
```

`module-loader.php` skannar `modules/*/config.php` automatiskt → registrerar REST-routes → klart. **Ingen manuell registration behövs.**

---

## Hur nya modeller läggs till (zero-code)

Provider-modellregistret är JSON-drivet:

```json
// core/providers/kieai/models.json
{
  "kling-2.7-t2v": {
    "name": "Kling 2.7 Text-to-Video",
    "type": "video",
    "inputFields": ["prompt", "duration", "aspect_ratio"],
    "endpoint": "marketplace"
  }
}
```

**Workflow:** Användaren klistrar in Kie.ai-docs → en rad läggs till i JSON → klart. Noll PHP-kod ändras.

---

## Hur moduler kommunicerar

Moduler kommunicerar **ALDRIG** genom att importera varandras filer. Istället:

1. **Via core-tjänster:** Alla moduler har tillgång till `core/storage`, `core/llm`, `core/providers` etc.
2. **Via WordPress hooks:** `do_action('pcm_image_generated', $data)` → andra moduler kan lyssna utan koppling
3. **Via delad databas:** Moduler läser/skriver till sina egna tabeller, delade tabeller (integrations, settings) ägs av core

---

## Skala-scenarion

| Scenario | Åtgärd | Filer att röra |
|----------|--------|---------------|
| Ny AI-modell (ex. Kling 3.0) | Lägg till rad i `models.json` | **0 kod, 1 JSON** |
| Ny provider (ex. Runway) | Ny mapp `core/providers/runway/` | **1–2 filer** |
| Ny feature-modul (ex. Audio) | Ny mapp `modules/audio/` med config | **3–4 filer, 0 ändringar i befintlig kod** |
| Ändra en modul (ex. Video-flöde) | Ändra filer i `modules/video/` | **Bara den modulens filer** |
| Ta bort en modul | Radera mappen | **0 ändringar i befintlig kod** |

> **Steg-för-steg-rutiner:** Se `docs/routines/video/adding-provider.md` och `docs/routines/video/adding-model.md`

---

## Provider Routing — Data-Driven

> **Princip:** Provider bestäms av **data** (modellregistret i DB), inte av kod. Ingen heuristik, inga prefix-gissningar, inga tysta fallbacks.

### Dataflöde

```
DB (wp_pcm_models)  →  Frontend (tRPC models.list)  →  Backend ($params['provider'])
         ↑                       ↓                              ↓
    Synkat vid              model.provider               Registry::get($provider)
    integration-setup       skickas alltid               → provider-klass
```

### Regler

1. **Frontend skickar `model.provider` alltid** — varje modell i tRPC-queryn har redan provider-fältet från DB. Inga ternaries, inga gissningar.
2. **Backend kräver provider** — om `$params['provider']` saknas → fail fast med tydligt felmeddelande. Inga tysta defaults.
3. **`detect_provider()` är deprecated** — behålls temporärt som defense-in-depth men ska tas bort. Ny kod ska aldrig använda den.
4. **Ny provider = 0 routing-ändringar** — provider-klassen + 1 rad i Registry. Routing fungerar automatiskt via DB → Frontend → Backend.

### Kontrast med anti-patterns

| Anti-pattern | Problem | Korrekt |
|---|---|---|
| `provider: 'kieai'` (hårdkodad) | Alla modeller routas till samma provider | `provider: model.provider` |
| `str_starts_with('veo')` (prefix-heuristik) | Missar nya prefixes, kräver kodändring | DB-lookup via model registry |
| `?? 'kieai'` (tyst fallback) | Döljer data-integritetsbuggar | Fail fast med tydligt fel |

---

## Asset Storage — Genererade filer

> **Princip:** Inga genererade filer lagras i plugin-mappen. Plugin-mappen är kod — inte data.

### Idag (fas 1)
Genererade bilder/videos sparas i **WordPress Media Library** (`wp-content/uploads/`) via `core/storage/`. Detta är WP-standard, backup-säkert och CDN-kompatibelt.

### Fas 2+ — Off-plugin Cloud Storage
När produktionen skalas upp introduceras externa storage-backends med API-nyckel-konfiguration via `core/integrations/`:

| Provider | Typ | Användningsfall |
|----------|-----|----------------|
| **Hetzner Object Storage** | S3-kompatibel | Kostnadseffektiv EU-lagring |
| **Cloudflare R2** | S3-kompatibel | Gratis egress, CDN inbyggt |
| **AWS S3** | Standard | Maximal skalbarhet |
| **Cloudinary** | Media-optimerad | Auto-resize, transformationer |

### Abstraktionslager
Modulerna anropar aldrig storage-providern direkt:
```php
// Modulkod — bryr sig inte om var filen hamnar
Storage::save($image_data, 'image', $user_id);

// core/storage/storage.php väljer backend baserat på inställning
// idag: wp-uploads / imorgon: Hetzner/R2/S3
```

**Att lägga till ny storage-provider = 1 ny fil i `core/storage/` + API-nyckel i integrations-inställningar.**

---

## Frontend (redan löst ✅)

Frontend-modulerna (`app/src/modules/`) följer redan exakt detta mönster — 1:1 kopierat från SOURCE-appen. Varje feature har sin egen mapp. **Ingen ändring behövs på frontend.**

---

## Migrationsstrategi

Migrering sker modul-för-modul utan att bryta befintlig funktionalitet:

1. Skapa `core/` — flytta infra-filer dit
2. Skapa `modules/` — flytta en modul i taget (börja med den enklaste)
3. Uppdatera `module-loader.php` och `power-creatives.php`
4. Verifiera efter varje modul
5. Ta bort gamla `rest/`-mapper när alla moduler är migrerade

---

## Jarvis — Det självmodifierande pluginet (Fas 2+)

> **Vision:** Pluginet är inte en statisk produkt — det är en levande organism som kan förstå sig självt, uppdatera sig självt och växa på kommando.

### Vad Jarvis är

Jarvis är en inbyggd AI-agent som **chattbaserat kan modifiera pluginet i realtid** — lägga till funktioner, uppdatera moduler, registrera nya modeller — direkt via WordPress-admin-UI. Den modulära arkitekturen är en förutsättning för att detta ska fungera utan risk.

### Hur det fungerar

```
Användare: "Lägg till stöd för Kling 3.0"
Jarvis → förstår intent → identifierar rätt fil (models.json) → gör ändringen → återrapporterar

Användare: "Skapa en ny Audio-modul"
Jarvis → skapar modules/audio/ → skriver config.php, controller.php, service.php → registrerar → klart
```

### Säkerhetsgränser (hard limits)

| Gräns | Regel |
|-------|-------|
| **Filsystem** | Jarvis kan BARA röra filer inuti plugin-mappen — ingenting utanför |
| **Persondata** | Jarvis har noll tillgång till user-data, posts, WP-options utanför plugin-scope |
| **Git-säkerhet** | Varje ändring föregås av en automatisk git commit (`BEFORE: [action]`). Vid fel: automatisk `git revert` |
| **Sub-agenter** | Research-agenter kan BARA scrapa nätet (read-only). De kan inte ändra filer eller databas |
| **Scope-lock** | Jarvis kan inte installera plugins, ändra WP-core, eller röra andra plugins |

### Git-säkerhetsflöde

```
1. Användare ger kommando
2. Jarvis: git commit "BEFORE: Add Kling 3.0 model"
3. Jarvis utför ändringen
4. Jarvis: git commit "AFTER: Add Kling 3.0 model - UNVERIFIED"
5. Jarvis testar/verifierar
6. Om OK: markerar som VERIFIED
7. Om FAIL: git revert → tillbaka till BEFORE-state → rapporterar felet
```

### Sub-agent arkitektur

```
Jarvis (Orchestrator)
├── File-Agent         ← Skriver/läser plugin-filer (isolerat scope)
├── Research-Agent     ← Scrapar internet för API-docs, modell-info (read-only)
├── Git-Agent          ← Hanterar commits och rollbacks
└── Verify-Agent       ← Kör sanity-checks efter varje ändring
```

### Varför den modulära arkitekturen är en förutsättning

Jarvis kan bara fungera säkert om arkitekturen är modulär:
- **Isolerade moduler** → Jarvis vet exakt vilka filer som tillhör vilken feature
- **config.php-manifest** → Jarvis förstår en moduls kapabiliteter utan att läsa all kod
- **JSON-driven registry** → Jarvis kan lägga till modeller utan att röra PHP-logik
- **WP hooks** → Jarvis kan koppla in ny funktionalitet utan att bryta befintlig kod

> Jarvis implementeras **efter** att modulär arkitektur är på plats och appen är produktionsklar.
