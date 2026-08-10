# Power Creatives — Architecture

## Overview

Power Creatives is a WordPress plugin providing an AI-powered creative generation platform.
It runs as a React SPA embedded in the WordPress admin panel, backed by a PHP REST API.

The authoritative business-domain language and target hierarchy are defined in
[ARCHITECTURE-PHILOSOPHY.md](ARCHITECTURE-PHILOSOPHY.md). This document describes
the technical structure; it must not redefine Brand, Delivery, Project, Asset, or
Approval Set ownership independently.

## Directory Structure

```
power-creatives/
├── power-creatives.php          ← Plugin bootstrap (constants, core requires, init)
├── uninstall.php                ← Cleanup on explicit uninstall (drops tables)
│
├── includes/
│   ├── module-loader.php        ← Auto-discovery system (scans modules/*/config.php)
│   ├── class-pcm-admin.php      ← WP admin menu + React SPA enqueue
│   ├── class-pcm-shortcode.php  ← [power_creatives] shortcode renderer
│   ├── class-pcm-activator.php  ← Activation/deactivation/upgrade hooks
│   │
│   ├── core/                    ← Shared infrastructure (no module owns this)
│   │   ├── base-controller.php          ← Abstract REST base (auto-routing, auth, helpers)
│   │   ├── class-pcm-settings.php       ← Global settings (wp_options)
│   │   ├── class-pcm-providers.php      ← Provider metadata & API key validation
│   │   ├── class-pcm-image-utils.php    ← Image processing utilities
│   │   ├── db/
│   │   │   ├── class-pcm-schema.php     ← DB schema (dbDelta migrations)
│   │   │   └── class-pcm-db.php         ← Typed CRUD abstraction
│   │   ├── llm/
│   │   │   └── class-pcm-llm.php        ← Multi-provider LLM invocation
│   │   ├── storage/
│   │   │   └── class-pcm-storage.php    ← WP Media Library adapter
│   │   ├── sse/
│   │   │   └── class-pcm-sse.php        ← Server-Sent Events helper
│   │   ├── providers/
│   │   │   ├── class-pcm-provider-interface.php  ← Provider contract
│   │   │   ├── class-pcm-provider-registry.php   ← Factory (get by ID)
│   │   │   ├── class-pcm-provider-openai.php
│   │   │   ├── class-pcm-provider-google.php
│   │   │   └── class-pcm-provider-kieai.php
│   │   └── kie/
│   │       ├── class-pcm-kie-api.php
│   │       ├── class-pcm-kie-input-mapper.php
│   │       └── class-pcm-kie-marketplace.php
│   │
│   └── modules/                 ← Feature modules (vertical slices)
│       ├── assets/              ← Generated image/video CRUD, refine, export
│       ├── brands/              ← Business profiles, logos, colors
│       ├── copy/                ← AI ad copy generation (SSE streaming)
│       ├── image/               ← AI image generation, edit, upscale
│       ├── integrations/        ← Provider API key management
│       ├── models/              ← AI model CRUD, sync, capabilities
│       ├── prompts/             ← User system prompt overrides
│       ├── scraper/             ← URL scraping, AI vision analysis
│       ├── templates/           ← Reusable form templates
│       └── video/               ← AI video generation (async)
│
├── app/                         ← React frontend (Vite + TypeScript)
│   ├── src/
│   │   ├── modules/             ← Frontend modules (mirrors backend)
│   │   ├── components/          ← Shared UI components
│   │   ├── contexts/            ← React contexts (App, Theme)
│   │   ├── hooks/               ← Custom hooks
│   │   ├── lib/                 ← tRPC client, utils
│   │   └── types/               ← TypeScript type definitions
│   └── dist/                    ← Built assets (index.js, index.css)
│
└── docs/                        ← Project documentation
```

## Key Patterns

### Module System
Every module lives in `includes/modules/{name}/` and contains:
- **config.php** — Returns array with `id`, `name`, `controller`, `rest_namespace`
- **controller.php** — REST controller extending `PCM_REST_Base`
- **service.php** (optional) — Business logic extracted from controller

The `PCM_Module_Loader` auto-discovers modules at boot time — no manual registration needed.

### REST API
All controllers extend `PCM_REST_Base` which provides:
- Declarative `routes()` method — define routes as `[METHOD, path, callback, ?args, ?capability]`
- Auto-registration via `register()`
- Nonce + capability permission checks
- Standardized `success()`, `error()`, `not_found()` responses
- Shared helpers: `get_provider_api_key()`, `get_prompt_override()`, `get_brand_logo_url()`

### Provider System
- `PCM_Provider_Interface` — contract all providers implement
- `PCM_Provider_Registry` — factory: `PCM_Provider_Registry::get('openai', $key)`
- Adding a provider = 1 class + 1 line in the registry

### Data Flow
```
React SPA → fetch(/wp-json/pcm/v1/...) → PCM_REST_{Module} → PCM_DB / PCM_LLM / PCM_Storage
```

### Update Safety
- DB schema via `dbDelta()` (additive, non-destructive)
- User data in `wp_pcm_*` tables + `wp_options` — never in the plugin directory
- Assets in `wp-content/uploads/` via WP Media Library
- Deactivation preserves all data; only explicit uninstall drops tables
