# FRONTEND ARCHITECTURE & HOOK AUDIT REPORT

## Executive Summary
This comprehensive audit evaluates the compliance of the React frontend codebase under `app/src/` with the design and modularity specifications outlined in `docs/ARCHITECTURE.md` and the developer guidelines. The scan covered 100% of files in the directory and identified several architectural deviations related to inline business logic, non-config-driven sidebar elements, files exceeding 500 lines, and minor inline environment fallbacks.

---

## Audit Findings Overview

| # | File Path | Line Range | Deviation Type | Severity | Description |
|---|---|---|---|---|---|
| 1 | `app/src/modules/Writer/components/ReviewEditorCanvas.tsx` | 53, 149–214 | Business Logic in UI | **Critical** | Contains direct tRPC mutations (`trpc.writer.uploadImage`) and fetch-based SSE stream processing instead of abstraction in a hook. |
| 2 | `app/src/modules/Keywords/index.tsx` | 59–406 | Business Logic in UI | **Critical** | Implements the entire suggest-and-search pipeline, jsonp fallback, progressive search state, and trpc mutations inside the UI. |
| 3 | `app/src/components/layout/Sidebar.tsx` | 43–64 | Non-Config-Driven Navigation | **Warning** | Hardcodes navigation pill arrays (`mainNavItems`, `configNavItems`) instead of consuming a configuration file. |
| 4 | `app/src/components/layout/Shell.tsx` | 33–51 | Non-Config-Driven Registry | **Warning** | Hardcodes a static `moduleRegistry` linking component classes to module strings inside the Shell container. |
| 5 | `app/src/components/ModelRegistry/ModelRegistryTable.tsx` | N/A | Exceeds 500 Lines | **Warning** | File is 678 lines long, containing mixed layout rendering, search/filtering states, and bulk actions. |
| 6 | `app/src/index.css` | N/A | Exceeds 500 Lines | **Info** | Stylesheet is 912 lines long, containing isolation rules and typography styles. |
| 7 | `app/src/modules/Keywords/index.tsx` | N/A | Exceeds 500 Lines | **Warning** | Component file is 937 lines long due to massive inline hook-state integration. |
| 8 | `app/src/lib/trpc.ts` | N/A | Exceeds 500 Lines | **Warning** | Adapter translation file is 723 lines long. |
| 9 | `app/src/components/layout/Sidebar.tsx` | 94–106 | Hardcoded Logout Domain Logic | **Info** | Hardcoded redirect path `/wp-admin/` and local cookie strings. |
| 10 | `app/src/modules/Keywords/index.tsx` | 93–98 | Hardcoded SERP Blacklist | **Info** | Hardcoded domain names for search filtering in local state. |

---

## Detailed Investigation State

### 1. React UI Components & Hook Compliance
The architecture mandates that UI components must be "dumb" and business logic, tRPC mutations, and complex state transformations must live in custom hooks under a `hooks/` directory.

#### Violation 1.1: `ReviewEditorCanvas.tsx`
- **Location**: `app/src/modules/Writer/components/ReviewEditorCanvas.tsx` (Lines 53, 149–214)
- **Direct Observation**:
  The component imports and executes `trpc.writer.uploadImage.useMutation()` directly on line 53, and maps fetch-based server streaming inside `handleGenerate`:
  ```typescript
  53:   const uploadImageMutation = trpc.writer.uploadImage.useMutation();
  ...
  149:   const handleGenerate = useCallback(async () => {
  ...
  162:       const url = `${config.restUrl}articles/generate`;
  163: 
  164:       const response = await fetch(url, { ... });
  ...
  192:       await parseSSEStream(response, (event, payload) => { ... });
  ```
- **Remediation Plan**: Extract this logic into a custom hook `useReviewEditor` inside `app/src/modules/Writer/hooks/useReviewEditor.ts`. Move the SSE fetch, tRPC upload image mutation, and associated loading states inside the hook, returning only raw states (`isGenerating`, `editor`, `handleGenerate`) to the component.

#### Violation 1.2: `KeywordsModule (index.tsx)`
- **Location**: `app/src/modules/Keywords/index.tsx` (Lines 59–406)
- **Direct Observation**:
  The component contains massive search loops, fallback suggestion calls (`fetchSuggestionsJsonp`), progress states, and mutations.
- **Remediation Plan**: Define a custom hook `useKeywordExplorer` inside `app/src/modules/Keywords/hooks/useKeywordExplorer.ts` to manage progressive search state, JSONP fallback coordination, and tRPC calls.

---

### 2. Config-Driven Menus & Tables Compliance
The guideline dictates: *Sidebars and Tables must be 100% config-driven via the Config folder.*

#### Violation 2.1: `Sidebar.tsx`
- **Location**: `app/src/components/layout/Sidebar.tsx` (Lines 43–64)
- **Direct Observation**:
  `mainNavItems` and `configNavItems` are hardcoded directly into the component file:
  ```typescript
  43: const mainNavItems: NavItem[] = [
  44:   { id: 'brands', label: 'Brands', icon: <Building2 className="w-[1.2rem] h-[1.2rem]" /> },
  ...
  ```
- **Remediation Plan**: Move navigation item declarations into a new config file `app/src/components/layout/sidebarConfig.tsx` (or similar under a centralized Config folder) and import it.

#### Violation 2.2: `Shell.tsx`
- **Location**: `app/src/components/layout/Shell.tsx` (Lines 33–51)
- **Direct Observation**:
  Statically declared `moduleRegistry` which maps component classes:
  ```typescript
  33: const moduleRegistry: Record<ModuleId, React.ComponentType> = {
  34:   brands: BrandsModule,
  ...
  ```
- **Remediation Plan**: Refactor module listing into a config file `app/src/components/layout/moduleConfig.ts` to decouple navigation definitions from main layout orchestration.

---

### 3. File Size Audit (>500 lines)
We scanned all files in `app/src/` to identify files exceeding 500 lines. The exact list with counts:

1. `app/src/modules/Keywords/index.tsx` — **937 lines**
   - *Reason*: Conflation of UI layout structure and multi-step progressive keyword exploration hooks.
   - *Remediation*: Extract the progressive search controller into a hook `useKeywordSearch.ts`.

2. `app/src/index.css` — **912 lines**
   - *Reason*: Contains Tailwind rules, WordPress admin isolation resets, design tokens, and rich text typography classes.
   - *Remediation*: Keep as-is or split WP-specific resets into a dedicated `wp-resets.css` file.

3. `app/src/lib/trpc.ts` — **723 lines**
   - *Reason*: Contains tRPC-to-REST proxy adapters with a massive Route Map (`ROUTE_MAP`) for all 10+ modules.
   - *Remediation*: Route Map can be split into modular route files under a `routes/` directory and merged dynamically in `trpc.ts`.

4. `app/src/components/ModelRegistry/ModelRegistryTable.tsx` — **678 lines**
   - *Reason*: Mixed table headers, selection checkboxes, search inputs, and bulk processing handlers.
   - *Remediation*: Extract table sub-panels (filters, legend, skeleton) into smaller modular components.

---

### 4. Hardcoded Parameters & Environment Check
The codebase was scanned for hardcoded URLs, directories, API keys, or domain-specific parameters in the React source code.

#### Log 4.1: `Sidebar.tsx` Redirects
- **Location**: `app/src/components/layout/Sidebar.tsx` (Lines 94–106)
- **Verbatim Code**:
  ```typescript
  96:   document.cookie = 'pcm_shortcode_auth=; path=/; max-age=0';
  ...
  101:     window.location.href = '/wp-admin/';
  ```
- **Explanation**: The gate cookie name and redirect path `/wp-admin/` are hardcoded. While standard for WordPress, they should ideally be read from the globally localized variable `window.pcmConfig`.

#### Log 4.2: Keywords SERP Blacklist
- **Location**: `app/src/modules/Keywords/index.tsx` (Lines 97–98)
- **Verbatim Code**:
  ```typescript
  97:       if (stored) return JSON.parse(stored);
  98:     } catch {}
  99:     return ['youtube.com', 'facebook.com', 'instagram.com', 'twitter.com', 'pinterest.com', 'tiktok.com', 'linkedin.com'];
  ```
- **Explanation**: Standard sites excluded from domain rating calculation are hardcoded locally. They should be extracted to `keywordsConfig.ts` to improve flexibility.

---

## Conclusion
The React frontend codebase is highly uniform and adheres well to styling and type definitions, but exhibits significant modularity violations by embedding complex async state handling (SSE parser loops and trpc mutations) directly inside UI components. Refactoring these procedures into custom hooks and centralizing the sidebar navigation into configuration files will align the codebase with the project's target architecture.
