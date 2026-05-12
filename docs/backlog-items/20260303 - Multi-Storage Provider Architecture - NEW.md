# Multi-Storage Provider Architecture

## Vision
Användaren ska kunna välja var varje modul lagrar genererade filer (WP Media Library, Cloudflare R2, Hetzner, etc.) via en dropdown i Module Defaults, med API-nyckel kopplad via Integrations.

## Nuläge
- Bilder/videos sparas **alltid** till WordPress Media Library via `PCM_Storage` (statisk klass)
- Ingen inställning för att välja lagringsplats
- Ingen dropdown i Module Defaults
- 6 hårdkodade `PCM_Storage::`-anrop i 4 filer

## Målbild
- [ ] Dropdown i Module Defaults: "Storage Provider" (WordPress / Cloudflare R2 / Hetzner / etc.)
- [ ] Vald storage-provider kopplas till API-nyckel i Integrations
- [ ] Image-modulen lagrar via vald provider
- [ ] Video-modulen använder samma mekanism
- [ ] Systemet redo att lägga till nya storage-providers med minimal kodändring

## Gap-analys

| Gap | Beskrivning |
|---|---|
| 1. Inget interface | `PCM_Storage` är statisk → behöver `PCM_Storage_Interface` |
| 2. Ingen factory/resolver | Ingen mekanism väljer backend baserat på inställning |
| 3. Ingen dropdown | Module Defaults saknar "Storage Provider"-val |
| 4. Ingen storage-setting i DB | Ingen `defaultStorageProvider`-inställning |
| 5. Hårdkodade anrop | 6 `PCM_Storage::`-anrop i 4 filer |
| 6. Google provider | Anropar `PCM_Storage::save_data()` direkt inuti `generate_image()` — bryter separation provider ↔ storage |

## Berörda filer
- `core/storage/class-pcm-storage.php`
- `core/providers/class-pcm-provider-google.php`
- `core/google/class-pcm-google-veo-api.php`
- `modules/image/service.php`
- `modules/video/service.php`
- Module Defaults frontend (ny dropdown)
- Settings backend (ny storage-setting)

## Referens
- `architecture-vision.md` → "Fas 2+ — Off-plugin Cloud Storage"
- Backlog #78 (🟢 Nice) → uppgraderat till 🟡 Important
