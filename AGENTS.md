# AGENTS.md — Power Creatives Onboarding

> **Läs detta först.** Den här filen är din karta om du är en AI-agent eller utvecklare som ska arbeta med Power Creatives.

---

## Vad är Power Creatives?

Ett WordPress-plugin som ger användare en AI-driven kreativ studio direkt i WP Admin.
Genererar bilder, video och annonstext via OpenAI, Google och Kie.ai.

- **Backend:** PHP 8.1+ (WordPress REST API)
- **Frontend:** React + TypeScript (Vite SPA, mountad i WP Admin)
- **Arkitektur:** Modulär monolith med vertical slices

---

## Viktiga dokument (läs i denna ordning)

| # | Dokument | Vad det täcker |
|---|----------|---------------|
| 1 | [ARCHITECTURE-PHILOSOPHY.md](docs/ARCHITECTURE-PHILOSOPHY.md) | Domänens gemensamma språk: Brand → Delivery → Project → Asset samt Approval Sets som review-paket |
| 2 | [ARCHITECTURE.md](docs/ARCHITECTURE.md) | Fullständig mappstruktur, mönster (Module Loader, REST Base, Provider System), dataflöde |
| 3 | [CONTRIBUTING.md](docs/CONTRIBUTING.md) | Hur du lägger till en ny modul, provider eller modell — steg för steg |
| 4 | [UPGRADE-SAFETY.md](docs/UPGRADE-SAFETY.md) | Hur update-mekanismen fungerar (dbDelta, data i DB inte i plugin-mappen) |
| 5 | [architecture-vision.md](docs/architecture-vision.md) | Ursprunglig vision och långsiktiga mål |
| 6 | [backlog.md](docs/backlog.md) | Projektets backlog — featurewishlist och teknisk skuld |

---

## Mappstruktur (snabb orientering)

```
power-creatives/
├── power-creatives.php        ← Bootstrap: constants → core requires → module-loader → init
├── includes/
│   ├── module-loader.php      ← Auto-discovers modules/{name}/config.php
│   ├── core/                  ← Delad infrastruktur (DB, LLM, Storage, SSE, Providers)
│   └── modules/               ← Feature-moduler (10 st) — varje har config.php + controller.php
└── app/src/                   ← React frontend (mirrors backend modules)
```

Se [ARCHITECTURE.md](docs/ARCHITECTURE.md) för fullständig träd.

---

## Kodmönster du MÅSTE följa

### 1. Ny modul → Auto-discovery
Skapa `includes/modules/{namn}/config.php` + `controller.php`.
Module Loader hittar dem automatiskt. **Ingen** manuell registration i `power-creatives.php`.
→ Se workflow: [add-module.md](.agent/workflows/add-module.md)

### 2. Ny provider → Interface + Registry
Implementera `PCM_Provider_Interface`, lägg till i `PCM_Provider_Registry::PROVIDERS`.
→ Se workflow: [add-provider.md](.agent/workflows/add-provider.md)

### 3. REST-endpoints → Extend PCM_REST_Base
Alla controllers ärver `PCM_REST_Base` som ger:
- Deklarativ `routes()` — definiera `[METHOD, path, callback]`
- Auto-registration, nonce/capability-checks
- `$this->success()`, `$this->error()`, `$this->not_found()`
- Delade helpers: `get_provider_api_key()`, `get_prompt_override()`, `get_brand_logo_url()`

### 4. Databas → PCM_DB + PCM_Schema
Alla queries via `PCM_DB` (typed CRUD) eller `$wpdb->prepare()`.
Schema-ändringar i `PCM_Schema` — `dbDelta()` hanterar migrering automatiskt.

### 5. Ingen duplicering
Om base-klassen redan har metoden → använd `$this->method()`. Kopiera aldrig.

---

## Regler för AI-agenter

1. **Läs ARCHITECTURE.md innan du ändrar något**
2. **Följ befintliga mönster** — kopiera strukturen från en liknande modul
3. **En modul = en vertikal slice** — controller + config i samma mapp
4. **Ingen kod i `power-creatives.php`** för nya moduler — module-loader hanterar det
5. **Testa alltid** att pluginet aktiveras utan PHP-fel efter ändringar
6. **Git commit** före och efter varje implementation (se user rules)
7. **Backlog** lever i `docs/backlog.md` — inte i knowledge-mappen

---

## Testning

```bash
# Verif 1: Plugin aktiverar utan fatal error
# Öppna http://powercreatives.local/wp-admin/?localwp_auto_login=1

# Verif 2: REST-endpoints svarar
# Öppna Power Creatives i sidomenyn → alla moduler renderar

# Verif 3: Skapa/redigera i valfri modul (Brands, Image, etc.)
```

---

## Var ligger vad?

| Jag vill... | Gå till |
|-------------|---------|
| Förstå arkitekturen | `docs/ARCHITECTURE.md` |
| Lägga till en modul | `.agent/workflows/add-module.md` |
| Lägga till en provider | `.agent/workflows/add-provider.md` |
| Ändra DB-schema | `includes/core/db/class-pcm-schema.php` |
| Ändra en REST-endpoint | `includes/modules/{modul}/controller.php` |
| Ändra frontend | `app/src/modules/{modul}/` |
| Se backlog | `docs/backlog.md` |
| Se granskningshistorik | `documentation/audits/` |
