# Rutin: Lägga till ny AI-provider (Image / Video / Båda)

> **Gäller:** Alla generativa providers (Kie.ai, Fal.ai, Replicate, OpenAI, Google, etc.)
> **Resultat:** Modeller från den nya providern fungerar sömlöst i Image- och/eller Video-modulen utan routing-ändringar.

## Före du börjar

- [ ] Läs `docs/architecture-vision.md` — förstå data-driven routing
- [ ] Identifiera providerns API-mönster: Sync (svar direkt) eller Queue (submit → poll → result)?
- [ ] Skaffa API-nyckel och testa manuellt med curl/Postman
- [ ] Bestäm capabilities: `image`, `video`, `edit`, eller kombination
- [ ] Jämför providerns input-fält mot **Platform Input Standard** nedan — identifiera vilka fält som behöver mapping

---

## Platform Input Standard (kontrakt)

> **Alla providers tar emot samma normaliserade params från våra controllers.**
> Providerns API-klass (eller input-mapper) ansvarar för att översätta dessa till providerns specifika fältnamn och format.
> Se `class-pcm-provider-interface.php` för det formella kontraktet.

### generate_image(string $model_id, array $params)

| Param | Typ | Krävs | Beskrivning | Alla providers måste hantera |
|---|---|---|---|---|
| `prompt` | string | ✅ | Generation prompt | Direkt — alla har `prompt` |
| `aspectRatio` | string | — | Format `"16:9"`, `"9:16"`, `"1:1"` etc. | ⚠️ Måste mappas. Se tabell nedan |
| `inputUrls` | string[] | — | Referensbilder (HTTP URLs eller base64 data URIs) | ⚠️ Fältnamn och format varierar per provider |
| `format` | string | — | `"portrait"` / `"landscape"` (legacy alternativ till aspectRatio) | Translate: `portrait` → `9:16`, `landscape` → `16:9` |
| `width` | int | — | Bredd i pixlar | Bara om modellen stödjer custom size |
| `height` | int | — | Höjd i pixlar | Bara om modellen stödjer custom size |
| `style` | string | — | Style preset | Provider-specifikt |
| `quality` | string | — | Quality level | Provider-specifikt |

### generate_video(string $model_id, array $params)

| Param | Typ | Krävs | Beskrivning |
|---|---|---|---|
| `prompt` | string | ✅ | Generation prompt |
| `aspectRatio` | string | — | `"16:9"`, `"9:16"`, `"1:1"` |
| `duration` | string | — | Duration i sekunder (`"5"`, `"10"`) |
| `inputUrls` | string[] | — | Referensbilder/startframes |

### edit_image(string $model_id, array $params)

| Param | Typ | Krävs | Beskrivning |
|---|---|---|---|
| `prompt` | string | ✅ | Edit-instruktion |
| `image_url` | string | ✅ | Käll-bildens URL |
| `mask` | string | — | Mask-data för in-painting |

### Output (alltid samma format)

```php
// Alla providers MÅSTE returnera:
['url' => 'https://...']  // Direkt URL till genererad bild/video
```

### Aspect Ratio Mapping Checklist

Varje provider har sitt eget format. Input-mappern måste hantera minst dessa:

| Vårt format | Typ A: Ratio-string | Typ B: Named enum | Typ C: Custom pixels |
|---|---|---|---|
| `1:1` | `"1:1"` | `"square_hd"` | `{ width: 1024, height: 1024 }` |
| `16:9` | `"16:9"` | `"landscape_16_9"` | `{ width: 1280, height: 720 }` |
| `9:16` | `"9:16"` | `"portrait_16_9"` | `{ width: 720, height: 1280 }` |
| `4:3` | `"4:3"` | `"landscape_4_3"` | `{ width: 1024, height: 768 }` |
| `3:4` | `"3:4"` | `"portrait_4_3"` | `{ width: 768, height: 1024 }` |
| `3:2` | `"3:2"` | — | `{ width: 1200, height: 800 }` |
| `2:3` | `"2:3"` | — | `{ width: 800, height: 1200 }` |
| `21:9` | `"21:9"` | — | `{ width: 1344, height: 576 }` |

**Vilken typ din provider använder:**
- **Typ A** (ratio-string): Nano Banana, Flux Kontext, Kie.ai marketplace
- **Typ B** (named enum): Flux 2 Flex, Recraft V4, Ideogram v3
- **Typ C** (pixels): OpenAI DALL-E, custom-endpoints

### Image Input Mapping Checklist

Providers tar emot referensbilder på olika sätt:

| Vårt format | Provider-format | Exempel |
|---|---|---|
| `inputUrls[0]` | `image_url` (string) | Flux Kontext |
| `inputUrls` | `input_urls` (array) | Kie.ai marketplace |
| `inputUrls` | `image_urls` (array) | Ideogram v3 (style refs) |
| `inputUrls[0]` | `image` (string) | Recraft |
| — | base64 inline | Vissa providers kräver upload → URL först |

> **Regel:** Om providern inte accepterar base64 data URIs, skapa en upload-hjälpklass som konverterar till HTTP URLs (se `PCM_Kie_Upload` som referens).

## Steg 1 — Skapa API-klass (all extern kommunikation)

**Plats:** `includes/core/{providernamn}/class-pcm-{providernamn}-api.php`

```php
class PCM_{Namn}_Api
{
    private const BASE_URL = 'https://api.{provider}.ai';

    // === Sync-mönster (svar direkt) ===
    public static function generate_image(string $api_key, string $model_id, array $params): array
    {
        $response = wp_remote_post(self::BASE_URL . '/endpoint', [...]);
        // Returnera alltid: ['url' => 'https://...']
    }

    // === Queue-mönster (submit → poll → result) ===
    // Använd om providern har async-jobb:
    // 1. submit_task() → returnerar job_id/request_id
    // 2. poll_status() → kollar status tills klar
    // 3. extract_result() → plockar ut URL från svaret
}
```

### Tänk på:
- **Logga alla API-anrop:** `error_log("[{Provider}] POST {url} model={model_id}")`
- **Tidsgräns:** `'timeout' => 300` för long-running jobb (polling)
- **Felhantering:** Kasta `RuntimeException` med tydligt meddelande inkl. HTTP-status
- **Input-mapping:** Om providern har annorlunda fältnamn → skapa `PCM_{Namn}_Input_Mapper`
- **Referensbilder/Upload:** Om providern kräver HTTP-URLs (inte base64) → skapa upload-hjälpklass

---

## Steg 2 — Skapa provider-klass (tunn wrapper)

**Plats:** `includes/core/providers/class-pcm-provider-{providernamn}.php`

```php
class PCM_Provider_{Namn} implements PCM_Provider_Interface
{
    private string $api_key;

    public function __construct(string $api_key) { $this->api_key = $api_key; }
    public function get_id(): string { return '{providernamn}'; }
    public function supports(string $capability): bool {
        return in_array($capability, ['image', 'video'], true); // anpassa
    }

    public function generate_image(string $model_id, array $params): array {
        return PCM_{Namn}_Api::generate_image($this->api_key, $model_id, $params);
    }

    public function generate_video(string $model_id, array $params): array {
        return PCM_{Namn}_Api::generate_video($this->api_key, $model_id, $params);
    }

    public function edit_image(string $model_id, array $params): array { /* ... */ }

    public function validate_key(string $api_key): array {
        // Anropa providerns "credits" eller "whoami" endpoint
        // Returnera: ['valid' => bool, 'error' => string]
    }
}
```

> **Regeln:** Provider-klassen ska vara **tunn**. Ingen API-logik, ingen input-mapping, ingen polling. Allt delegeras till API-klassen.

---

## Steg 3 — Registrera i Provider Registry

Lägg till **1 rad** i `class-pcm-provider-registry.php` → `PROVIDERS`:

```php
private const PROVIDERS = [
    'openai' => 'PCM_Provider_OpenAI',
    'google' => 'PCM_Provider_Google',
    'kieai'  => 'PCM_Provider_KieAI',
    '{providernamn}' => 'PCM_Provider_{Namn}',  // ← NY RAD
];
```

---

## Steg 4 — API-nyckel i Settings

1. **Backend:** Lägg till fält i `includes/modules/settings/controller.php` → `get_integration_fields()`
2. **Frontend:** Lägg till input i `app/src/modules/Settings/IntegrationsSection.tsx`
3. **Validate-knapp:** Koppla `validate_key()` till frontend

---

## Steg 5 — Registrera modeller i DB

Modeller **måste** finnas i `wp_pcm_models` med korrekt `provider`-fält. Välj en av:

| Metod | När | Hur |
|-------|-----|-----|
| **JSON-seeding** | Kända modeller vid release | Skapa `models.json` i provider-mappen → seed vid plugin-aktivering |
| **API-synk** | Provider har list-endpoint | Anropa vid `validate_key()` → infoga automatiskt |
| **Manuell** | Admin lägger till | Via Model Registry UI |

Varje modell behöver minst:
```json
{
  "model_id": "fal-flux-pro",
  "name": "FLUX Pro",
  "provider": "fal",
  "type": "image",
  "canGenerateImage": true,
  "isEnabled": true,
  "costTier": "standard"
}
```

---

## Steg 6 — Autoload

Lägg till `require_once` i `power-creatives.php` → provider-laddningssektionen:
```php
require_once __DIR__ . '/includes/core/{providernamn}/class-pcm-{providernamn}-api.php';
require_once __DIR__ . '/includes/core/providers/class-pcm-provider-{providernamn}.php';
```

---

## Steg 7 — Dokumentera i API References

**Obligatoriskt.** Skapa en mapp i `docs/api-references/{providernamn}/` med:

1. **API-referens** — endpoints, auth-mönster, request/response-format
2. **Modellspecifika docs** — per modell eller modellgrupp (input-fält, aspect ratios, limits)
3. **Pricing/limits** — rate limits, kostnader, kvoter

Följ befintlig struktur (se `docs/api-references/kie/` som referens):

```
docs/api-references/
├── kie/                    ← Befintlig
│   ├── _architecture/
│   ├── _general/
│   ├── flux2/
│   ├── google/
│   └── ...
├── google/                 ← Befintlig
│   └── veo-api-reference.md
└── {providernamn}/         ← NY
    ├── api-reference.md    ← Endpoints, auth, polling
    └── {modell}/           ← Per modell-docs
```

> **Detta är den enda platsen att kolla** när du behöver förstå en providers API. Håll den uppdaterad.

---

## Verifierings-checklista

### Arkitektur
- [ ] API-klass hanterar ALL extern kommunikation (submit, poll, download)
- [ ] Provider-klassen är tunn — ingen API-logik inline
- [ ] Provider implementerar alla 6 interface-metoder
- [ ] `get_id()` returnerar exakt samma sträng som i Registry
- [ ] `supports()` returnerar `true` för rätt capabilities

### Data-driven routing
- [ ] Modeller i `wp_pcm_models` har `provider: '{providernamn}'` (inte prefix-heuristik)
- [ ] Frontend skickar `model.provider` → backend → `Registry::get('{providernamn}')` → rätt klass
- [ ] Ingen ny kod i `detect_provider()` (deprecated)

### Settings
- [ ] API-nyckelfält synligt i Settings → Integrations
- [ ] Validate-knappen fungerar (grön/röd indikator)

### End-to-end
- [ ] Modellen visas i modulens modellväljare (Image eller Video)
- [ ] Generering producerar en bild/video och visas i resultaten
- [ ] Felmeddelanden från providern propageras korrekt till frontend

---

## Vad du INTE ska göra

- ❌ Lägga API-logik direkt i provider-klassen
- ❌ Lägga till prefix i `detect_provider()` — funktionen är **deprecated**
- ❌ Hårdkoda provider-namn i frontend-kod
- ❌ Lägga till tysta fallbacks (`?? 'defaultProvider'`)
- ❌ Skapa provider-specifik routing-logik i controllers

> **Referens:** `docs/architecture-vision.md` → "Provider Routing — Data-Driven"
