# Handoff Report — Frontend UI & Hook Auditor (explorer_frontend_1)

This report details the observations, logic, and conclusions of the comprehensive audit performed across all files in `app/src/`.

---

## 1. Observation
A meticulous file-by-file search was carried out under `app/src/`. The following concrete violations of modularity, configuration isolation, file size guidelines, and runtime environments were directly observed:

### A. Dumb Component Modularity (Business Logic / Mutations)
1. **`app/src/modules/Writer/components/ReviewEditorCanvas.tsx`**:
   - Imports and initializes a tRPC mutation directly inside the UI component:
     ```typescript
     53:   const uploadImageMutation = trpc.writer.uploadImage.useMutation();
     ```
   - Implements native fetch and event stream (SSE) streaming state logic directly within a UI event handler:
     ```typescript
     162:       const url = `${config.restUrl}articles/generate`;
     163: 
     164:       const response = await fetch(url, { ... });
     ...
     192:       await parseSSEStream(response, (event, payload) => { ... });
     ```
2. **`app/src/modules/Keywords/index.tsx`**:
   - Implements local suggestion fetch loops, JSONP state triggers, progress intervals, multiple tRPC queries and mutations (`trpc.keywords.prefixes.useQuery`, `search.useMutation`, `enrich.useMutation`), and direct `localStorage` persistence.

### B. Config-Driven Menus & Navigation
1. **`app/src/components/layout/Sidebar.tsx`**:
   - Hardcodes navigation items:
     ```typescript
     43: const mainNavItems: NavItem[] = [
     44:   { id: 'brands', label: 'Brands', icon: <Building2 className="w-[1.2rem] h-[1.2rem]" /> },
     ```
2. **`app/src/components/layout/Shell.tsx`**:
   - Hardcodes module components dynamically in layout code instead of dynamically referencing a config:
     ```typescript
     33: const moduleRegistry: Record<ModuleId, React.ComponentType> = {
     34:   brands: BrandsModule,
     ```

### C. Large Files Exceeding 500 Lines
1. `app/src/modules/Keywords/index.tsx` — **937 lines**
2. `app/src/index.css` — **912 lines**
3. `app/src/lib/trpc.ts` — **723 lines**
4. `app/src/components/ModelRegistry/ModelRegistryTable.tsx` — **678 lines**

### D. Hardcoded URLs & Fallbacks
1. `app/src/components/layout/Sidebar.tsx` hardcodes `/wp-admin/` redirect logic on line 101.
2. `app/src/modules/Keywords/index.tsx` hardcodes the initial domain blacklist array on line 99.

---

## 2. Logic Chain
1. **Dumb Components**:
   - *Premise*: Modularity rules dictate React components must purely render layout and delegate all business decisions, async streams, and tRPC operations to custom Hooks.
   - *Reasoning*: Because `ReviewEditorCanvas.tsx` directly performs an SSE parser loop (`parseSSEStream`) and invokes tRPC upload mutations, and `Keywords/index.tsx` manages a multi-stage progressive search loop, both components are tightly coupled to REST and tRPC network state.
   - *Conclusion*: These components are architectural deviations and their state/mutations must be moved to custom hooks (e.g. `useReviewEditor` and `useKeywordSearch`).

2. **Config-Driven Elements**:
   - *Premise*: Sidebars and layout structures should be completely configurable without modifying TypeScript UI component files.
   - *Reasoning*: Because the sidebars in `Sidebar.tsx` and the modules in `Shell.tsx` define arrays of metadata, titles, and paths inside layout component scripts, updating navigation is blocked by source code changes.
   - *Conclusion*: Navigation structure and module catalogs must be externalized into a configuration folder or centralized registry files.

---

## 3. Caveats
- Checked and verified that `app/src/const.ts` resolves OAuth URLs dynamically using origin environments instead of static definitions.
- `index.css` exceeds 500 lines primarily due to global resets isolating Tailwind styles to `#pcm-root` to protect against WordPress admin pollution. While it violates length guidelines, splitting it may break stylesheet cascade structure.

---

## 4. Conclusion
The frontend UI audit is **Complete**. The React frontend has highly clean components but displays standard design anti-patterns concerning mixed presentation and controller layers. Remediating these areas by establishing custom hooks (`useReviewEditor`, `useKeywordSearch`) and externalizing sidebar routes will fulfill the criteria set forth in `@architecture.md`.

---

## 5. Verification Method
To verify the audit findings:
1. Open the detailed report at `.agents/explorer_frontend_1/analysis.md` and read the code segments mapped to each log.
2. Inspect the file lengths of `ModelRegistryTable.tsx`, `index.css`, `trpc.ts`, and `Keywords/index.tsx` using any line-counting utility.
3. Validate compilation of code using:
   ```bash
   # From /app directory:
   npm run check
   npm run build
   ```
   Ensure no TypeScript errors or missing imports occur.
