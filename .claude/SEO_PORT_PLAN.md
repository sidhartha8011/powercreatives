# SEO Suite Port — Optimizer Simple v0.9.1 → Power Creatives

Goal: replicate **all 9 modules** of the standalone "Optimizer Simple" SEO
plugin as **native Power Creatives modules** (React/TS SPA + `pcm/v1` REST,
`PCM_REST_Base` nonce+capability), reusing PC infra (models/provider routing,
prompts, integrations, settings, brands, sites) instead of porting the
source's jQuery+admin-ajax stack. Source extracted at `/tmp/seo_inspect`
(reference only — not shipped). Faithful behavior + verbatim prompts.

## Source → PC mapping (what's reuse vs genuinely new)

| Source module | Core behavior | PC home | Reuse / New |
|---|---|---|---|
| **content** | WP posts/pages SEO table, inline edit, bulk, schema, AI-optimize | new `seo` module | New module; **port `seo-integration.php` verbatim** (Yoast/RankMath/SEOPress/AIOSEO detect + key-map + dual-write) |
| **templates** + prompts | 15+ AI prompt templates, `{{var}}` substitution | PC `prompts` + `templates` | Reuse — **seed the verbatim prompts** (see Prompt Inventory below) |
| core **ai-provider** | OpenAI/Anthropic/Google unified call | PC `models` + provider routing | Reuse PC router; port token-budget + dynamic-timeout heuristics |
| **integrations** | encrypted AI keys, n8n webhook, GBP | PC `integrations` + automations (n8n) | Reuse; add AES-256-CBC secret helper if PC lacks one |
| **ai-bulk** | bulk generate + staging buffer (accept/reject diff) | PC `writer`/new `seo` | Reuse writer gen; **build the staging-buffer review UX** |
| **site** | schema, robots.txt, language, timezone, permalinks, icon | new `seo` (Site tab) + `settings` | New surface; schema JSON-LD + robots filter |
| **ai-readiness** | virtual routes `/llms.txt` `/llms-full.txt` `/{slug}.md`, html→md | new `seo` (AI-Readiness tab) | **Fully new** — rewrite rules, llms builders, markdown converter |
| **business** | GBP fetch/normalize/override, logo | PC `brands` (brand≈client) + `integrations` | Map identity to brands; GBP normalize is new |
| **hub** | multi-site connector ZIP, HMAC hello, app-password proxy | PC `sites` | Connector-provisioning is new; reuse PC HMAC + `PCM_Schema` tables |
| **export-import** | agency config JSON export/import | PC `settings` | Thin new feature |

Net genuinely-new domains: **AI-readiness** (virtual routes/llms.txt/markdown),
**schema JSON-LD**, **connector-provisioning** half of hub, **GBP normalize**,
the **staging-buffer** review UX. Everything else overlays PC modules.

## Conventions for every new piece
- Backend: `includes/modules/{name}/config.php` + `controller.php`
  (extends `PCM_REST_Base`, routes `[METHOD,path,callback,args,cap]`) +
  `service.php`. DB via `PCM_Schema` dbDelta + version gate (never a new
  installer). Nonce+capability+sanitize+escape on every handler; `$wpdb->prepare`.
- Frontend: `app/src/modules/{Name}/` React, register in
  `app/src/lib/trpc-routes.ts`, add nav item in `Sidebar.tsx`.
- Reuse existing PC AI/provider/prompt/integration/brand infra — do NOT
  re-port `opt_simple_call_ai_provider`, the encryption, or the AJAX layer.

## Phases (each independently shippable + verified)

**Phase 1 — Content SEO core (THIS BUILD).** New `seo` module backend:
SEO-plugin detection + key-map + dual-write (verbatim port of
`seo-integration.php`); content list (posts+pages with SEO fields, row
builder); inline cell save (whitelist + dual-write + native fields). REST:
`GET /seo/content`, `GET /seo/content/options`, `POST /seo/content/{id}/cell`,
`POST /seo/content` (quick-create), `POST /seo/content/bulk-delete`. Unit-test
the key-map/detect/dual-write. Live-verify list+save+dual-write.

**Phase 2 — Content SEO frontend.** React `SEO` module: content table
(sortable/filterable, column manager, inline editors incl. status/author/
terms/featured-image/date), bulk select+delete, quick-create. Nav entry.

**Phase 3 — AI-Optimize + prompt seeding.** Seed the verbatim prompts into PC
`prompts`/`templates`; `{{var}}` substitution engine (business/site/post
vars); per-field AI sparkle → staging-buffer diff (accept/reject/commit);
"Optimize Content" modal (SEO/AEO prompt + Before/After + scorecard:
word-count, KW density, KW-in-first-100, KW-in-headings, headings, FAQ, lists,
avg sentence length); bulk AI generate. Reuse PC provider routing.

**Phase 4 — Schema.org + sameAs.** `seo_schema`/`seo_schema_sameas`/`_faq`/
`_howto` post meta; JSON-LD renderer on `wp_head` (Article/WebPage/Breadcrumb/
FAQPage/HowTo/Product, publisher-logo fallback chain — pull Organization/logo
from PC `brands`); Wikidata `wbsearchentities`+`wbgetentities` resolver with
AI disambiguation; schema cell editor (type checkboxes, presets, conflicts).

**Phase 5 — AI-Readiness.** DB-gated rewrite rules `^llms\.txt$`,
`^llms-full\.txt$`, `^(.+)\.md$` (+ query vars, `redirect_canonical` guard,
`template_redirect` server); `html_to_markdown` (page-builder aware:
Elementor/Divi/Brizy), `post_to_markdown`; llms.txt index + full builders
(Answer.AI spec); per-post status (md5 hash ready/stale/none); diagnostics +
publish toggle (flush rules). Meta: `_optimizer_md_*` → `_pcm_md_*`.

**Phase 6 — Site settings.** Schema enable+json, robots enable+text
(`robots_txt` filter), language/timezone/permalinks/icon with backups +
restore; content-map (`{{site.pages/posts/categories}}`); generate-from-
business. Local-vs-remote via PC `sites`.

**Phase 7 — Business/GBP.** GBP integration records (place_id, name, address,
phone, lat/lng, hours, rating…), `gbp_normalize` (v1+v2 Places shapes), N8N
fetch (`get_business_details`/`search_places`) via PC automations/integrations,
manual-override store that survives refresh, logo. Map business identity onto
PC `brands`.

**Phase 8 — Hub (multi-site connectors).** `pcm_tenants` + `pcm_hmac_nonces`
tables (PCM_Schema); connector-plugin ZIP generator (baked client_id/secret,
4 files incl. meta-registrar for SEO meta over REST + site-endpoints);
`/connector/hello` HMAC handshake (reuse PC's webhook HMAC); Application-
Password capture; site-proxy transport (Basic auth) → drives Remote content
repository. Overlay PC `sites`.

**Phase 9 — Export/Import + Integrations polish.** Agency config JSON
(`{plugin,version,exported,modules:{slug:{label,data}}}`), redaction-on-export
of secrets, merge/replace/skip import strategies; n8n webhook config; AES-256-
CBC secret encryption helper if PC lacks one; AI key detect/validate/list-
models (prefix sniff `sk-ant-`/`sk-`/`AIzaSy`, `/models` probe, Gemini
`generateContent` filter).

## Prompt Inventory (seed verbatim in Phase 3)
Captured in full from `core/settings/configs/prompt-templates.php` (option
`optimizer_site_prompt_templates`). 15 templates keyed by `use`: `title`,
`tagline`, `page_title` (×2 generate/optimize), `meta_title` (×2),
`meta_description` (×2), `slug` (×2), `robots`, `llms`, `schema`,
`content` (humanizer), `meta_keywords`. Service-default fallbacks add a clean
`content` (300–600w HTML) and a richer `meta_keywords`. **Seed fixes:**
`{{primary_kw}}`→`{{primary_keyword}}` (meta_keywords); the humanizer `content`
has a duplicate "Business phone:" label mapped to `{{business.address}}` and a
blank `TARGET AUDIENCE:` — seed the clean service-default as default and offer
the humanizer as an optional preset with these corrected. Full verbatim text
lives in the source at that path; re-extract when seeding.
Substitution vars: `{{business.name|tagline|category|address|phone|website|
lat|lng|hours|types|rating}}`, `{{site.lang}}`, `{{website.url}}`, `{{today}}`,
`{{title}}`, `{{current_value}}`, `{{primary_keyword}}`, `{{supporting_keyword}}`,
`{{meta_title}}`, `{{meta_description}}`, `{{post_type}}`, special
`{{business.website|hostname}}`.

## SEO plugin dual-write key-map (Phase 1 — port verbatim)
Detection priority: `WPSEO_VERSION`→yoast, `class_exists('RankMath')`→rankmath,
`function_exists('seopress_init')`→seopress, else simple. Keys per field:
| provider | title | description | keyword | meta_keywords |
|---|---|---|---|---|
| yoast | `_yoast_wpseo_title` | `_yoast_wpseo_metadesc` | `_yoast_wpseo_focuskw` | `pcm_seo_meta_keywords` |
| rankmath | `rank_math_title` | `rank_math_description` | `rank_math_focus_keyword` | `pcm_seo_meta_keywords` |
| seopress | `_seopress_titles_title` | `_seopress_titles_desc` | `_seopress_analysis_target_kw` | `pcm_seo_meta_keywords` |
| simple | `pcm_seo_meta_title` | `pcm_seo_meta_description` | `pcm_seo_primary_keyword` | `pcm_seo_meta_keywords` |
`get`: active key → simple backup → ''. `update`: write active key AND simple
backup (survives plugin switches). PC prefix `pcm_seo_` replaces
`optimizer_simple_`.

## Verification per phase
`composer test` (PHPUnit + wp_mock) green; `cd app && npm run check && npm run
build` clean; live wp-cli eval against `~/Desktop/wordpress-local` (throwaway
scripts, deleted). Append a SESSION_LOG entry per phase.
