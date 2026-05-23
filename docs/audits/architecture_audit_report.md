# PowerCreatives Comprehensive Architecture Audit Report

**Date**: May 23, 2026  
**Auditor Archetype**: Compliance Report Writer (`worker_report_1`)  
**Status**: COMPLETE  
**Target Core Standard**: Decoupled vertical-slice modular architecture, dumb declarative UIs, config-driven navigations, thin REST controllers with abstracted business logic, custom hooks for React lifecycle & API interaction, and strict usage of typed DB wrappers over raw database queries.

---

## 1. Executive Summary

This comprehensive audit was conducted across 100% of the files in the **PowerCreatives** codebase, covering both the React frontend application (`app/src/`) and the PHP WordPress backend plugin modules (`includes/modules/`). The primary objectives were to evaluate architectural alignment with vertical-slice modularity, check compliance with strict styling/layout rules, locate files exceeding 500 lines (which indicates severe architectural bloat), and identify critical leaks of business logic into UI components.

### Core Metrics

| Metric | Value |
| :--- | :--- |
| **Total PHP Backend Modules Audited** | 15 |
| **Backend Modules PASS Status** | 4 / 15 (26.7%) |
| **Backend Modules FAIL Status** | 11 / 15 (73.3%) |
| **Critical Frontend Violations** | 2 |
| **Warning Frontend Violations** | 2 |
| **Info Frontend Violations** | 2 |
| **Total Files Exceeding 500 Lines** | 19 (2 Core, 12 Backend Modules, 5 Frontend) |

### Key Findings & Architectural Assessment
1. **Severe Controller and Service Bloat (Backend)**: There is an alarming lack of delegation between controllers and services. Multiple modules lack a service layer entirely, placing hundreds of lines of complex database operations, LLM prompting, and external API orchestrations directly in the REST controller. Conversely, several service files (most notably `copy/service.php` at 1990 lines) have bloated far beyond standard thresholds by consolidating distinct domains (ads/organic copy, SSE parsing, target audience profiling, and semantic search) into single monolithic classes.
2. **Raw Database Pollution**: Direct `$wpdb` usage is widespread. It violates the core constraint of utilizing the custom typed DB layer `PCM_DB`. Controllers are directly drafting SQL strings and running queries, creating high risks of SQL injection, breaking schema abstractions, and bypassing security/validation routines.
3. **Leakage of Business Logic (Frontend)**: The React frontend fails the "Dumb UI" principle in multiple areas. In both the `Writer` and `Keywords` modules, components directly run HTTP fetches, manage complex SSE streams, handle JSONP fallbacks, and orchestrate complex local states. This tightly couples the rendering layer to specific implementation details and prevents modular unit testing.
4. **Hardcoded Configurations**: Important configurations such as navigation lists, module registries, redirect links, cookie access, and domain blacklists are hardcoded directly into components rather than being centralized inside config files.

---

## 2. PHP Backend Modules Compliance Matrix

The following table provides the binary compliance status of the 15 backend modules. Compliant modules must feature a thin REST controller extending `PCM_REST_Base` with routing/validation only, a decoupled service layer handling business logic, and database operations completely abstracted via `PCM_DB` or `PCM_Settings`.

| Module | Controller Status | Service Layer | DB Layer Compliance | Overall Status |
| :--- | :--- | :--- | :--- | :--- |
| **assets** | FAIL (642 lines, bloated) | FAIL (Missing Service) | FAIL (Direct `$wpdb` usage) | **FAIL** |
| **brands** | PASS (Decoupled) | FAIL (532 lines, bloated) | PASS (Uses `PCM_DB`) | **FAIL** |
| **copy** | FAIL (740 lines, bloated) | FAIL (1990 lines, bloated) | PASS (Uses `PCM_DB`) | **FAIL** |
| **image** | PASS (Decoupled) | FAIL (926 lines, bloated) | PASS (Uses `PCM_DB`) | **FAIL** |
| **integrations** | FAIL (340 lines, bloated) | FAIL (Missing Service) | PASS (Uses wrappers) | **FAIL** |
| **keywords** | PASS (Decoupled) | FAIL (575 lines, bloated) | PASS (Uses `PCM_DB`) | **FAIL** |
| **models** | FAIL (556 lines, bloated) | FAIL (552 lines, bloated) | PASS (Uses `PCM_DB`) | **FAIL** |
| **prompts** | FAIL (623 lines, bloated) | FAIL (Missing Service) | FAIL (Direct `$wpdb` on 10 lines) | **FAIL** |
| **scraper** | PASS (Decoupled) | FAIL (584 lines, bloated) | FAIL (Manual prefix concat) | **FAIL** |
| **settings** | PASS (63 lines, thin) | PASS (Not required) | PASS (Uses `PCM_Settings`) | **PASS** |
| **sites** | PASS (Thin/Declarative) | PASS (Decoupled) | PASS (Uses `PCM_DB`) | **PASS** |
| **strategy** | PASS (Thin/Declarative) | PASS (Decoupled) | PASS (Uses `PCM_DB`) | **PASS** |
| **templates** | FAIL (543 lines, bloated) | FAIL (Missing Service) | FAIL (Direct `$wpdb` execution) | **FAIL** |
| **video** | FAIL (784 lines, bloated) | FAIL (Missing Service) | FAIL (Direct `$wpdb` operations) | **FAIL** |
| **writer** | PASS (Thin/Declarative) | PASS (Decoupled) | PASS (Uses `PCM_DB`) | **PASS** |

---

## 3. Module-by-Module Detailed Findings & Technical Remediation

### 3.1. assets (FAIL)
* **Violations**:
  * **No Service Layer**: The controller handles all business logic, direct image rendering, and download preparation.
  * **Controller Bloat**: `includes/modules/assets/controller.php` is 642 lines.
  * **Database Violation**: Bypasses the typed `PCM_DB` wrapper by mixing direct `$wpdb` transactions with structured `PCM_DB` calls.
* **Severity**: **Critical**
* **Technical Remediation Plan**:
  1. **Extract Business Logic**: Create `includes/modules/assets/service.php` and define `PCM_Assets_Service` class.
  2. **Refactor Controller**: Extract asset CRUD operations, ZIP generation, and image scaling routines into the service layer. Reduce `controller.php` to simple REST route validation and invocation of the service.
  3. **Standardize DB Queries**: Search `controller.php` for references to global `$wpdb`. Replace all direct queries (e.g. `$wpdb->get_results`) with unified `PCM_DB::select()` or relevant typed ORM constructs.

### 3.2. brands (FAIL)
* **Violations**:
  * **Service Bloat**: `includes/modules/brands/service.php` is 532 lines, containing excessive logic for brand assets, font loading, color palette validation, and metadata extraction.
* **Severity**: **Warning**
* **Technical Remediation Plan**:
  1. **Sub-domain Decomposition**: Split the `PCM_Brands_Service` into smaller utility helper classes.
  2. **Extract Branding Palette Helpers**: Move the HEX/RGBA color processing and palette generation algorithms to a dedicated static helper `class-pcm-brand-color-helper.php` under `includes/core/`.
  3. **Extract Fonts Processing**: Move external font-face parsing and local stylesheet generation into `class-pcm-brand-font-helper.php`.

### 3.3. copy (FAIL)
* **Violations**:
  * **Controller Bloat**: `includes/modules/copy/controller.php` is 740 lines.
  * **Extreme Service Bloat**: `includes/modules/copy/service.php` is 1990 lines (the largest file in the module architecture).
  * **Consolidated Bloat**: The service class performs multiple non-cohesive tasks: drafting ad copy (Facebook, Google, LinkedIn), writing organic blog posts, executing target audience research, parsing real-time SSE chunks, and performing semantic search queries.
* **Severity**: **Critical**
* **Technical Remediation Plan**:
  1. **Split the Service Layer**: Disassemble `service.php` into distinct domain-driven services:
     - `PCM_Copy_Ad_Service`: Deals only with ad platform copywriting.
     - `PCM_Copy_Organic_Service`: Focuses on long-form blogs and organic copy.
     - `PCM_Copy_Research_Service`: Handles audience persona building and SEO keyword clustering.
     - `PCM_Copy_SSE_Parser`: Dedicated class managing SSE streaming tokens and boundary parsing.
  2. **Deconstruct the Controller**: Move the streaming API configurations, LLM prompt templates, and direct token counters out of `controller.php` into their respective services.

### 3.4. image (FAIL)
* **Violations**:
  * **Service Bloat**: `includes/modules/image/service.php` is 926 lines, containing heavy local file mutations, image generation, upscaling wrappers, and mask manipulations.
* **Severity**: **Warning**
* **Technical Remediation Plan**:
  1. **Isolate Media Storage Operations**: Extract image saving, Media Library registration, and folder permission handling to the core class `PCM_Storage`.
  2. **Sub-module Division**: Create sub-services inside the `image` module:
     - `service-generation.php`: Handles standard diffusion prompts (Text-to-Image).
     - `service-refine.php`: Upscaling, variations, and mask/inpainting logic.

### 3.5. integrations (FAIL)
* **Violations**:
  * **No Service Layer**: The controller handles API connection handshakes, secret encryption, and validation logic.
  * **Controller Bloat**: `includes/modules/integrations/controller.php` has 340 lines, violating the thin-controller design paradigm.
* **Severity**: **Warning**
* **Technical Remediation Plan**:
  1. **Build Service**: Create `includes/modules/integrations/service.php` with class `PCM_Integrations_Service`.
  2. **Move Connection Handshakes**: Migrate raw cURL requests, API key testing routines, and key encryption (utilizing WordPress Salts) from `controller.php` into the service.
  3. **Simplify Routes**: Restructure routes in `controller.php` to purely receive payloads, authenticate permissions, and call the service methods, returning JSON response structures.

### 3.6. keywords (FAIL)
* **Violations**:
  * **Service Bloat**: `includes/modules/keywords/service.php` has 575 lines, packing SEO volume calculations, SERP analysis, and web scraper fallback operations.
* **Severity**: **Warning**
* **Technical Remediation Plan**:
  1. **Decompose External Queries**: Extract Google Keyword / Autocomplete scrapers and external search APIs to a core provider interface or standard helper file.
  2. **Refactor Service**: Create `class-pcm-keyword-analyzer.php` for formatting metrics, separating raw API ingestion from the analytical scoring algorithm.

### 3.7. models (FAIL)
* **Violations**:
  * **Controller Bloat**: `includes/modules/models/controller.php` has 556 lines.
  * **Service Bloat**: `includes/modules/models/service.php` has 552 lines.
  * **Ineffective Decoupling**: High structural duplication between controller and service. The controller does duplicate data parsing and mapping of capabilities.
* **Severity**: **Warning**
* **Technical Remediation Plan**:
  1. **Re-align Responsibilities**: Ensure the controller only decodes incoming request queries. Strip all mapping of capabilities, categorization, and sorting logic from `controller.php`.
  2. **Extract LLM Definitions**: Move static provider model capabilities JSON-like array structures out of the active service class into a declarative config array or a JSON resource file under `includes/core/llm/`.

### 3.8. prompts (FAIL)
* **Violations**:
  * **No Service Layer**: All CRUD operations and database interactions occur directly in the REST controller.
  * **Controller Bloat**: `includes/modules/prompts/controller.php` is 623 lines.
  * **Direct Database Leaks**: Crucial DB compliance failure. Direct `$wpdb` operations exist on lines **79, 127, 173, 221, 252, 262, 311, 358, 369, and 400**.
* **Severity**: **Critical**
* **Technical Remediation Plan**:
  1. **Introduce Service Layer**: Create `includes/modules/prompts/service.php` to encapsulate standard operations.
  2. **Eradicate Raw SQL**: Remove all references to `$wpdb` from the controller. Port all operations to the typed `PCM_DB` helper (e.g. `PCM_DB::insert()`, `PCM_DB::update()`, `PCM_DB::delete()`).
  3. **Secure Input Binding**: Ensure the new Service layer sanitizes prompt tags, system roles, and model exclusions via dynamic argument sanitization.

### 3.9. scraper (FAIL)
* **Violations**:
  * **Service Bloat**: `includes/modules/scraper/service.php` is 584 lines.
  * **Database Namespace Violation**: Direct prefix manipulation using `PCM_Schema::prefix()` on lines **89-90** to manually build SQL queries (`$wpdb->prefix . 'pcm_scraped_collections'`) instead of using standard table bindings.
* **Severity**: **Critical**
* **Technical Remediation Plan**:
  1. **Abide by DB Schema Abstraction**: Replace manual string concats with the dynamic, standard method calls `PCM_Schema::table('scraped_collections')` and `PCM_Schema::table('scraped_images')`.
  2. **Move Scraper Engines Async**: Separate raw DOM parser loops and asset extraction engines from the core class, moving them into dedicated parser subclasses.

### 3.10. settings (PASS)
* **Compliance Notes**: An excellent model of clean, declarative design. Features a thin controller containing only **63 lines**, which delegates settings management to the core wrapper `PCM_Settings` utilizing standard WordPress Options API safely.

### 3.11. sites (PASS)
* **Compliance Notes**: High architectural compliance. Correctly implements thin controllers, leverages modular routing, decouples logic to `service.php`, and operates on data strictly through the typed `PCM_DB` abstraction.

### 3.12. strategy (PASS)
* **Compliance Notes**: Fully decoupled. Follows the vertical slice architecture pattern: thin REST routing controller, business rules encapsulated cleanly in a lightweight service class, and fully compliant database utilization.

### 3.13. templates (FAIL)
* **Violations**:
  * **No Service Layer**: All batch actions, parsing, and data validation are nested directly in the REST controller.
  * **Controller Bloat**: `includes/modules/templates/controller.php` is 543 lines.
  * **Direct Database Leaks**: Directly invokes raw `$wpdb` operations for executing bulk deletion of template files and inserting seeding recipes.
* **Severity**: **Critical**
* **Technical Remediation Plan**:
  1. **Introduce Service Class**: Build `includes/modules/templates/service.php` containing `PCM_Templates_Service`.
  2. **Re-route Database Interactions**: Migrate raw bulk SQL queries to secure, parametrized `PCM_DB` transactions or helper functions.
  3. **Port Seeding Logic**: Move the initial JSON templates seeding logic into a separate activation migration script or a dedicated loader class inside core.

### 3.14. video (FAIL)
* **Violations**:
  * **No Service Layer**: Handles asynchronous generation queues, webhook processing, and external API polling directly in the controller.
  * **Controller Bloat**: `includes/modules/video/controller.php` is 784 lines.
  * **Excessive Business Logic Leak**: Contains prompt enhancement LLM constructor logic, API authorization flow, and direct `$wpdb` operations to update jobs state.
* **Severity**: **Critical**
* **Technical Remediation Plan**:
  1. **Create Service Layer**: Extract all webhook verifications, poll queues, and generation jobs tracking into `includes/modules/video/service.php`.
  2. **Refactor LLM Construction**: Delegate the prompt enhancement LLM logic to the core framework class `PCM_LLM`.
  3. **Purge Raw SQL Queries**: Refactor direct database updates of video statuses to run through standard status update hooks using the safe `PCM_DB` wrapper.

### 3.15. writer (PASS)
* **Compliance Notes**: Outstanding modular design. Exhibits a thin declarative controller, isolates content writing and semantic indexing to the service, and correctly integrates with standard DB abstractions.

---

## 4. React Frontend Compliance Audit

### 4.1. Tabular Summary of Violations

| File Path | Line(s) | Severity | Violation Type | Summary of Violation |
| :--- | :--- | :--- | :--- | :--- |
| `app/src/modules/Writer/components/ReviewEditorCanvas.tsx` | 53, 149–214 | **Critical** | Business Logic in UI | Direct tRPC mutations (`trpc.writer.uploadImage`) and raw fetch-based SSE chunk processing inside UI rendering code. |
| `app/src/modules/Keywords/index.tsx` | 59–406 | **Critical** | Business Logic in UI | Implements SEO keyword search loop, progressive search state, JSONP fallback routines, and tRPC mutations directly inside the component. |
| `app/src/components/layout/Sidebar.tsx` | 43–64 | **Warning** | Hardcoded Navigation | Navigation configuration array (`mainNavItems`, `configNavItems`) defined statically inside UI components. |
| `app/src/components/layout/Shell.tsx` | 33–51 | **Warning** | Static Module Mapping | Static mapping `moduleRegistry` maps components to strings inside Shell, preventing dynamic config. |
| `app/src/components/layout/Sidebar.tsx` | 94–106 | **Info** | Hardcoded Environment | Hardcoded WP administrative redirect path (`/wp-admin/`) and direct cookie parsing. |
| `app/src/modules/Keywords/index.tsx` | 93–98 | **Info** | Hardcoded Configuration | Hardcoded domain blacklists for SERP filtering declared inside component. |

---

### 4.2. Detailed Breakdown & Technical Remediation Plans

#### A. ReviewEditorCanvas.tsx (Lines 53, 149–214) — **Critical**
* **The Problem**: The component directly orchestrates data synchronization, makes upload calls, and sets up manual fetch streams for SSE parsing. This completely violates the **"NO THINKING IN UI"** standard (UI components must be "dumb" renderers).
* **Technical Remediation Plan**:
  1. **Build Custom Hook**: Create `app/src/modules/Writer/hooks/useReviewEditor.ts`.
  2. **Migrate Logic**: Move the tRPC mutation `trpc.writer.uploadImage.useMutation()` and the fetch-based SSE buffer reading block into this new custom hook.
  3. **Expose Minimal API**: Expose simple states (`isUploading`, `streamingContent`, `error`) and actions (`handleImageUpload`, `startStream`) from the hook. Keep the UI component completely declarative.

#### B. Keywords/index.tsx (Lines 59–406) — **Critical**
* **The Problem**: This component contains a massive block of business logic. It handles the progressive states of keyword loops, manages JSONP fail-safes, makes direct tRPC mutations, and processes results. The component is 937 lines long, mostly filled with logic.
* **Technical Remediation Plan**:
  1. **Build Custom Hook**: Create `app/src/modules/Keywords/hooks/useKeywordSearch.ts`.
  2. **Encapsulate State & Operations**: Move the keyword search loop, fallback fetch loops, progress trackers, and API mutations into `useKeywordSearch`.
  3. **Reduce UI Responsibility**: Refactor `app/src/modules/Keywords/index.tsx` to serve as a pure visual template that calls `useKeywordSearch()` to obtain the search state and triggers.

#### C. Sidebar.tsx (Lines 43–64) — **Warning**
* **The Problem**: Violates the **"NO STATIC MENUS"** rule. Navigation items are hardcoded arrays. Changing the side navigation requires changing core component files.
* **Technical Remediation Plan**:
  1. **Create Navigation Config**: Create `app/src/config/navigation.ts`.
  2. **Define Config Schema**: Declare standard types for nav items (icon, text, badge, permission, paths).
  3. **Import Statically**: Move `mainNavItems` and `configNavItems` to the config file and import them into `Sidebar.tsx`.

#### D. Shell.tsx (Lines 33–51) — **Warning**
* **The Problem**: Hardcodes a static `moduleRegistry` mapping names to component structures inside the layout wrapper.
* **Technical Remediation Plan**:
  1. **Establish Module Registry**: Move the registry mapping to a dedicated config file `app/src/config/modules.ts`.
  2. **Dynamically Import Components**: Leverage dynamic React imports if necessary, or registry exports to allow side-effect-free expansion of active feature slices.

#### E. Sidebar.tsx (Lines 94–106) — **Info**
* **The Problem**: Hardcoded cookie operations and relative administrative URLs (`/wp-admin/`) degrade environmental portability.
* **Technical Remediation Plan**:
  1. **Leverage Constants/Globals**: Use the global configurations `app/src/shared/const.ts` or a localized utility `app/src/lib/wp-utils.ts` to derive the safe root WP admin address.

#### F. Keywords/index.tsx (Lines 93–98) — **Info**
* **The Problem**: Hardcodes specific domains in a blacklist array locally. Blacklist domains should be configurable dynamically.
* **Technical Remediation Plan**:
  1. **Move to Config/Settings**: Extract the list of domain blacklists into `app/src/modules/Keywords/config/blacklist.ts` or fetch it dynamically from the WordPress Settings API.

---

## 5. File Size Compliance & Bloat Registry (> 500 Lines)

Files exceeding 500 lines are in direct violation of basic clean code metrics, representing high cohesion/coupling issues. They must be aggressively refactored.

### 5.1. Core Infrastructure Registry

| File Absolute Path | Line Count | Architectural Refactoring Plan |
| :--- | :--- | :--- |
| `includes/core/db/class-pcm-db.php` | 1039 | Split database connection, validation schemas, and query compilers into a decoupled helper suite. Move specific tables metadata mapping out of this class. |
| `includes/core/class-pcm-providers.php` | 967 | Decouple the monolithic provider manager. Split validation structures and registry features to `class-pcm-provider-validator.php` and use individual provider interfaces to handle details. |

### 5.2. Backend Modules Registry

| File Absolute Path | Line Count | Architectural Refactoring Plan |
| :--- | :--- | :--- |
| `includes/modules/copy/service.php` | 1990 | **Highest Critical Priority**: Extract ads copywriting, blog structures, SEO keyword analyses, persona generations, and stream parsers to completely separate service classes. |
| `includes/modules/image/service.php` | 926 | Separate raw canvas masks manipulations, Fal.ai or DALL-E integration wrappers, and Media Library storage tasks into dedicated utility services. |
| `includes/modules/video/controller.php` | 784 | Create the missing service layer. Move external APIs integrations and job queues status updates to `service.php`. |
| `includes/modules/copy/controller.php` | 740 | Reduce size by porting parameters validations and prompt models definitions to specialized validator hooks and config layouts. |
| `includes/modules/assets/controller.php` | 642 | Introduce a dedicated Service Layer (`service.php`) and migrate raw file/image actions and ZIP compressors into it. |
| `includes/modules/prompts/controller.php` | 623 | Eradicate `$wpdb` operations. Delegate prompts CRUD and models overrides to a brand new service layer `service.php`. |
| `includes/modules/scraper/service.php` | 584 | Move URL HTTP-client calls, text content parsers, and custom image extractors to a sub-directory containing dedicated parser strategies. |
| `includes/modules/keywords/service.php` | 575 | Migrate SERP details parsing and Google Autocomplete scraper fallbacks into individual decoupled search utility packages. |
| `includes/modules/models/controller.php` | 556 | Simplify validation processes. Delegate REST route structures mapping and parameter constraints definitions to external schemas. |
| `includes/modules/models/service.php` | 552 | Extract static model databases metadata and capability arrays to dynamic database definitions or specialized config sheets. |
| `includes/modules/templates/controller.php` | 543 | Introduce `service.php` class. Extract bulk actions, recipes loading, and custom JSON templating parser into the service. |
| `includes/modules/brands/service.php` | 532 | Separate business profiles mapping and asset storage from advanced font parsers and custom brand colors processing helpers. |

### 5.3. React Frontend Registry

| File Absolute Path | Line Count | Architectural Refactoring Plan |
| :--- | :--- | :--- |
| `app/src/modules/Keywords/index.tsx` | 937 | Move all logic (JSONP loops, progressive steps, mutations) into a custom hook. Separate rendering components to smaller, single-responsibility sub-components. |
| `app/src/index.css` | 912 | Componentize visual styles. Move module-specific Tailwind custom utilities to isolated stylesheet modules (e.g. CSS Modules) or standard styling files. |
| `app/src/lib/trpc.ts` | 723 | Move custom endpoint bindings, mutations overrides, and specific caching configs to separate modules-specific tRPC sub-modules files. |
| `app/src/modules/Copy/index.tsx` | 687 | Separate ad layout templates, input forms, and dynamic response sections into small visual components under `Copy/components/`. |
| `app/src/components/ModelRegistry/ModelRegistryTable.tsx` | 678 | Delegate grid operations, bulk selection state, filters, and tables rendering sorting to hooks like `useSortableTable.ts` or custom hooks. |

---

## 6. Verification and Maintenance Procedures

To ensure that the codebase achieves and maintains structural compliance, the following automated routines must be integrated into the development workflow:

1. **Service Layer Assertion**: Reject any REST controller pull requests that exceed **150 lines** or contain direct SQL statements (`$wpdb`).
2. **Dumb UI Verification**: Ensure React UI components are strictly presentational. If a component contains `fetch`, `useMutation`, `useEffect` managing external data synchronization, or local processing routines, it must be refactored into a custom hook.
3. **Continuous File Size Scan**: Add a pre-commit hook that triggers a shell search script asserting:
   ```bash
   find includes/modules app/src -type f -not -path "*/node_modules/*" | while read file; do
     lines=$(wc -l < "$file")
     if [ "$lines" -gt 500 ]; then
       echo "CRITICAL ARCHITECTURAL ERROR: $file has $lines lines, exceeding the 500-line compliance threshold!"
       exit 1
     fi
   done
   ```
4. **Binary PASS/FAIL Re-evaluation**: Re-run the compliance matrix checking system weekly to guarantee modular safety and architectural integrity.
