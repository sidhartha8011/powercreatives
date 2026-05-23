# Backend Feature Modules Architecture Audit Report

**Prepared by**: `explorer_backend_1` (Backend Architecture Auditor)  
**Date**: May 23, 2026  
**Working Directory**: `.agents/explorer_backend_1/`

---

## Executive Summary
A comprehensive audit of **100% of the files** (40 files across 15 modules) was conducted within `includes/modules/` to verify compliance with `docs/ARCHITECTURE.md`. 
The architecture defines a strict vertical-slice modularity pattern: controllers (`controller.php`) should act as thin, declarative HTTP layers that delegate all core business, integration, and database operations to services (`service.php`) or unified models/helpers.

### Key Successes
- **100% Compliance in Controller Inheritance**: All 15 controller files successfully extend `PCM_REST_Base`. There are zero exceptions.
- **100% Compliance in Route Declaration**: All 15 controller files define routes via a declarative `routes()` method returning `[METHOD, path, callback, ...]` structures.
- **Credential Security**: There are zero hardcoded credentials, secret keys, or environment-specific URLs within any module. API key retrieval is correctly delegated to dynamic table lookups (such as `PCM_Schema::table('integrations')`) or WordPress meta storage.
- **Zero Reinvention of Standard Infrastructure**: Core integrations leverage robust shared classes such as `PCM_LLM` (for OpenAI/Gemini JSON invocations), `PCM_Storage` (for media sideloading), and `PCM_SSE` (for streaming event pipelines).

### Key Architectural Deviations & Technical Debt
1. **Vertical-Slice Modularity Violations (Missing Services)**:
   Five modules do not contain a `service.php` layer, embedding heavy business, REST parsing, and database transactions directly in `controller.php`:
   - `assets` (642 lines)
   - `integrations` (340 lines)
   - `prompts` (623 lines)
   - `settings` (63 lines - acceptable due to low logic complexity)
   - `templates` (543 lines)
2. **Bloated Files Exceeding the 500-Line Limit**:
   In total, **12 files** exceed the 500-line budget defined in `docs/ARCHITECTURE.md`:
   - **Controllers**: `assets/controller.php` (642), `copy/controller.php` (740), `models/controller.php` (556), `prompts/controller.php` (623), `templates/controller.php` (543), `video/controller.php` (784).
   - **Services**: `brands/service.php` (532), `copy/service.php` (1,990), `image/service.php` (926), `keywords/service.php` (575), `models/service.php` (552), `scraper/service.php` (584).
   - *Note: `copy/service.php` is critically bloated at 1,990 lines and must be split into dedicated service units.*
3. **Direct Database Contamination**:
   Many services and controllers execute direct `$wpdb` operations instead of using custom model structures or the centralized `PCM_DB` wrapper. Furthermore, some services (e.g. `scraper/service.php`) use manual prefix concatenation (`PCM_Schema::prefix() . 'scraped_images'`) instead of the standard dynamic table resolver `PCM_Schema::table('scraped_images')`.

---

## Detailed Module Audit Catalog

| Module | Files Present | Controller Extends `PCM_REST_Base` | routes() Declared | Service Layer Separation | Bloated (>500 lines) | DB/Schema Abstraction | Hardcoded Values/Secrets |
| :--- | :--- | :---: | :---: | :---: | :---: | :---: | :---: |
| **assets** | config, controller | Yes | Yes | ❌ (No Service) | ⚠️ Controller (642) | uses `PCM_DB` & `$wpdb` | None |
| **brands** | config, controller, service | Yes | Yes | Yes | ⚠️ Service (532) | uses `PCM_DB` & `$wpdb` | None |
| **copy** | config, controller, service | Yes | Yes | Yes | ⚠️ Controller (740)<br>⚠️ Service (1,990) | uses `PCM_DB` | None |
| **image** | config, controller, service | Yes | Yes | Yes | ⚠️ Service (926) | uses `PCM_DB` & `$wpdb` | None |
| **integrations**| config, controller | Yes | Yes | ❌ (No Service) | No (340) | uses `PCM_DB` & `$wpdb` | None |
| **keywords** | config, controller, service | Yes | Yes | Yes | ⚠️ Service (575) | uses `PCM_DB` & `$wpdb` | None |
| **models** | config, controller, service | Yes | Yes | Yes | ⚠️ Controller (556)<br>⚠️ Service (552) | uses `PCM_DB` & `$wpdb` | None |
| **prompts** | config, controller | Yes | Yes | ❌ (No Service) | ⚠️ Controller (623) | uses `$wpdb` | None |
| **scraper** | config, controller, service | Yes | Yes | Yes | ⚠️ Service (584) | uses `PCM_Schema::prefix()` | None |
| **settings** | config, controller | Yes | Yes | ❌ (Delegates) | No (63) | uses `PCM_Settings` | None |
| **sites** | config, controller, service | Yes | Yes | Yes | No | uses `PCM_DB` | None |
| **strategy** | config, controller, service | Yes | Yes | Yes | No | uses `PCM_DB` & `$wpdb` | None |
| **templates** | config, controller | Yes | Yes | ❌ (No Service) | ⚠️ Controller (543) | uses `$wpdb` | None |
| **video** | config, controller, service | Yes | Yes | Yes | ⚠️ Controller (784) | uses `$wpdb` & `PCM_DB` | None |
| **writer** | config, controller, service | Yes | Yes | Yes | No | uses `PCM_DB` & `$wpdb` | None |

---

## Detailed Findings and In-Depth Code Audits

### 1. `copy` Module: Extreme Service Bloat
- **Path**: `includes/modules/copy/service.php` (1,990 lines)
- **Problem**: This single file handles too many concerns: prompt templates, ads generation, organic copy generation, audience generation, research pipelines, SSE chunk parsing, and JSON mapping.
- **Modularity Score**: 3/10 (High technical debt).
- **Recommendation**: Refactor `PCM_Copy_Service` by extracting pipelines into separate class handlers in a new `pipelines/` subfolder:
  - `PCM_Copy_Ads_Pipeline`: Manages ad copy formatting, hook logic, and brand angle mappings.
  - `PCM_Copy_Organic_Pipeline`: Handles social media network-specific constraints (LinkedIn, Facebook, Instagram) and emoji densities.
  - `PCM_Copy_Audience_Pipeline`: Manages audience segmentation, search intent analysis, and Google Search grounding.

### 2. Missing Service Layers (Vertical-Slice Violations)
#### `prompts/controller.php`
- **Path**: `includes/modules/prompts/controller.php` (623 lines)
- **Problem**: Directly reads/writes prompt variants from/to database using direct `$wpdb` execution (lines 79, 127, 173, 221, 252, 262, 311, 358, 369, 400).
- **Proposed Architecture**: Create `prompts/service.php` with a `PCM_Prompts_Service` class, moving all SQL operations, built-in defaults lookup, and duplicate/set-default logic into it. Make the controller a thin REST routing envelope.

#### `templates/controller.php`
- **Path**: `includes/modules/templates/controller.php` (543 lines)
- **Problem**: Directly manages reusable recipe templates and handles system-level seed operations using `$wpdb` calls.
- **Proposed Architecture**: Extract a `PCM_Templates_Service` class to handle duplicate, default resolution, and bulk operation transactions, reducing `PCM_REST_Templates` to under 200 lines.

#### `assets/controller.php`
- **Path**: `includes/modules/assets/controller.php` (642 lines)
- **Problem**: Contains heavy business logic for AI prompt refinement, variation generation, file exports, and project associations.
- **Proposed Architecture**: Introduce `PCM_Assets_Service` to decouple these concerns.

### 3. Database Abstraction Deviations
- **Manual Prefix Concatenation in `scraper/service.php`**:
  ```php
  $col_table = PCM_Schema::prefix() . 'scraped_collections';
  $img_table = PCM_Schema::prefix() . 'scraped_images';
  ```
  This bypasses `PCM_Schema::table()` which is the designated dynamic resolver that registers active schema bindings.
  - *Fix*: Update to:
    ```php
    $col_table = PCM_Schema::table('scraped_collections');
    $img_table = PCM_Schema::table('scraped_images');
    ```
- **Widespread Direct `$wpdb` Operations**:
  Instead of utilizing modular repositories or standard active-record models, core modules (`models`, `keywords`, `video`, `writer`) routinely execute direct `$wpdb->get_results` or `$wpdb->insert` queries. While SQL safety (via `$wpdb->prepare`) is perfectly maintained, this pattern makes unit testing impossible and introduces heavy structural coupling to database tables.

---

## Action Plan & Refactoring Recommendations

### Phase 1: High Priority (Vertical Modularity Refactoring)
1. **Prompts Refactoring**:
   - Extract a new `includes/modules/prompts/service.php` class (`PCM_Prompts_Service`).
   - Move database queries (`$wpdb->get_col`, `$wpdb->get_results`, `$wpdb->insert`, `$wpdb->delete`) into the service.
   - Inject the service into `PCM_REST_Prompts`.
2. **Templates Refactoring**:
   - Extract a new `includes/modules/templates/service.php` class (`PCM_Templates_Service`).
   - Relocate seed restore, bulk deletion, duplication, and default clearing SQL transactions.

### Phase 2: Moderate Priority (Line Budget Resolution)
1. **De-bloat `copy/service.php`**:
   - Extract `PCM_Copy_Ad_Helper` and `PCM_Copy_Audience_Helper` to offload template data arrays and static prompts.
2. **De-bloat `image/service.php` and `video/controller.php`**:
   - Move prompt enhancement LLM constructions out of `video/controller.php` into `video/service.php`.
   - Separate Vision analysis handlers in `image/service.php` into a dedicated helper.

### Phase 3: Technical Debt Cleanup
- Standardize all table lookups across `scraper/service.php` to use `PCM_Schema::table('scraped_collections')` instead of dynamic `$wpdb->prefix` manual joins.
