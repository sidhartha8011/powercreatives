# Upgrade Safety

Power Creatives is designed to be safely updated by installing a new version over the existing one.
All user data is preserved — nothing is lost during an upgrade.

## How It Works

### Database
- All tables use WordPress `dbDelta()` for schema management
- `dbDelta()` is **additive** — it adds new columns/tables but never drops existing ones
- On every page load, `PCM_Activator::maybe_upgrade()` checks the stored DB version
  against the plugin's `PCM_DB_VERSION` constant and re-runs `dbDelta()` if needed
- Custom tables are prefixed with `wp_pcm_` (users, integrations, models, brands, etc.)

### User Data Storage
| Data Type | Where Stored | Survives Update? |
|-----------|-------------|-----------------|
| API keys & integrations | `wp_pcm_integrations` table | ✅ |
| AI models & capabilities | `wp_pcm_models` table | ✅ |
| Brands & brand assets | `wp_pcm_brands` + `wp_pcm_brand_assets` | ✅ |
| Templates | `wp_pcm_templates` table | ✅ |
| Generated assets | `wp_pcm_assets` + WP Media Library | ✅ |
| Plugin settings | `wp_options` (keyed `pcm_*`) | ✅ |
| Uploaded files | `wp-content/uploads/` | ✅ |

### What Lives in the Plugin Directory (replaced on update)
- PHP source code (`includes/`)
- Built React app (`app/dist/`)
- Documentation (`docs/`)

**None of these contain user data.** They are pure code.

### Deactivation vs Uninstall
- **Deactivate**: Only calls `flush_rewrite_rules()`. All data preserved.
- **Uninstall** (delete plugin): Runs `uninstall.php` which drops all `wp_pcm_*` tables
  and removes plugin options. This is intentional and irreversible.

## Testing an Upgrade

1. Back up your database (always recommended)
2. Download the new plugin zip
3. In WP Admin → Plugins → "Upload Plugin" → choose the zip → "Install Now"
4. WordPress will ask to replace the existing plugin — confirm
5. Activate the plugin
6. Check: all brands, templates, integrations, and models are intact
