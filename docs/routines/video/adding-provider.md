# Rutin: Lägga till ny video-provider

> **Förutsättning:** Provider-klassen implementerar `PCM_Provider_Interface`.
> **Resultat:** Modeller från den nya providern fungerar i Video-modulen utan routing-ändringar.

## Arkitekturmönster

> [!IMPORTANT]
> **Provider-klassen ska vara tunn.** All API-specifik logik (submit, poll, download)
> ska ligga i en separat API-klass (`PCM_{Provider}_Api`), inte inline i providern.
> Se `PCM_Kie_Api` som referens — Kie.ai-providern delegerar all logik dit.

```
PCM_Provider_{Namn}        ← tunn, implementerar PCM_Provider_Interface
    ↓ delegerar till
PCM_{Namn}_Api             ← API-logik (submit, poll, download)
```

## Steg

### 1. Skapa API-klass

Skapa `includes/core/{namn}/class-pcm-{namn}-api.php`:

```php
class PCM_{Namn}_Api
{
    // All API-specifik logik här: submit, poll, download, etc.
    public static function generate_video(string $api_key, string $model_id, array $params): array
    {
        $task = self::submit_task($api_key, $model_id, $params);
        $result = self::wait_for_completion($api_key, $task['operation']);
        return ['url' => $result['url']];
    }
}
```

### 2. Skapa provider-klass (tunn wrapper)

Skapa `includes/core/providers/class-pcm-provider-{namn}.php`:

```php
class PCM_Provider_{Namn} implements PCM_Provider_Interface
{
    public function get_id(): string { return '{namn}'; }

    public function supports(string $capability): bool {
        return in_array($capability, ['video'], true);
    }

    public function generate_video(array $params): array {
        $model_id = $params['model_id'] ?? $params['modelId'];
        // Delegera till API-klassen — ingen logik här
        return PCM_{Namn}_Api::generate_video($this->api_key, $model_id, $params);
    }

    // ... övriga interface-metoder
}
```

### 3. Registrera i Provider Registry

Lägg till 1 rad i `class-pcm-provider-registry.php` PROVIDERS-konstanten:

```php
'{namn}' => 'PCM_Provider_{Namn}',
```

### 4. Verifiera

- [ ] API-klass hanterar all extern kommunikation
- [ ] Provider-klassen delegerar, ingen API-logik inline
- [ ] Provider-klassen implementerar alla 6 interface-metoder
- [ ] `supports()` returnerar `true` för rätt capabilities
- [ ] `get_id()` returnerar exakt samma sträng som registret
- [ ] Modeller synkade från providern har `provider: '{namn}'` i DB
- [ ] Frontend skickar `model.provider` → backend → `Registry::get('{namn}')` → din klass

### Vad du INTE ska göra

- ❌ Lägga API-logik direkt i provider-klassen (ska vara tunn)
- ❌ Lägga till prefix i `detect_provider()` — funktionen är deprecated
- ❌ Hårdkoda provider-namn i frontend-kod
- ❌ Lägga till tysta fallbacks (`?? 'defaultProvider'`)

> **Arkitekturreferens:** Se `docs/architecture-vision.md`, sektion "Provider Routing — Data-Driven"

