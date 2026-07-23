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

## Prompt-Variable Behaviour Change (no migration)

Five template variables now exist: `{{ keyword }}`, `{{ brand_context }}`,
`{{ research }}`, `{{ output_format }}`, and `{{ media_instructions }}`. Each maps
to a prompt fragment that `build_prompt()` used to inject automatically (the
keyword/output-format user message, the brand block, the research block, the
in-content-media block). A fragment is now auto-injected **only when the template
does not reference its variable**; placing one hands the author control of where it
lands and suppresses the hidden duplicate — so nothing is appended invisibly.

**Who is affected.** A user template that *already* contained one of these five
token names — for example, an author who had typed `{{ keyword }}` as a literal
before this version — will, after upgrade, **stop receiving the matching
auto-appended fragment**, because the template is now considered to "reference"
that variable. The fragment instead renders at the token's position. Templates
that contain no `{{ }}` variables at all produce a byte-identical prompt to the one
they produced before the variables existed. No data is changed on upgrade and
no migration runs; the only effect is in how the prompt is assembled at generation
time. If you have a custom template that contained one of these tokens and you
want the old auto-appended behaviour back, remove the token from the template.

## Testing an Upgrade

1. Back up your database (always recommended)
2. Download the new plugin zip
3. In WP Admin → Plugins → "Upload Plugin" → choose the zip → "Install Now"
4. WordPress will ask to replace the existing plugin — confirm
5. Activate the plugin
6. Check: all brands, templates, integrations, and models are intact

**Two further constraints worth knowing.**

1. Only entries whose category is `prompt` are scanned. A token placed in a
   *guidance*, *title* or other non-prompt entry does NOT suppress the automatic
   injection — the fragment will then appear twice (once where you typed it, once
   appended). Keep the tokens in the Generation Prompt entry.
2. The reseeded **SEO Pillar Article** template does NOT reach existing installs.
   `PCM_Template_Seeds::seed()` inserts only when no template with the same
   name+module exists, so an install that already has that template keeps its old
   text — and therefore keeps the hidden auto-injection. To adopt the new
   five-variable prompt on an existing site, edit that template by hand (or delete
   it so the seeder recreates it).
