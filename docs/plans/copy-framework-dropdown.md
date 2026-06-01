# Implementation Plan — Copy Framework (template-driven, dropdown-selected, clean placeholder)

## 1. Context & problem

When a user applies a template in the Copy module, structural copywriting instructions (a
"framework" — AIDA, PAS, Hook-Story-Offer, Epiphany Bridge, Emotion-Speed-Fire) currently have
**nowhere clean to live**. They get jammed into the **Creative Brief** (`creativeBrief`) or the raw
prompt, which clutters the user's free-form directive and mixes two different concerns:

- **Creative Brief** = the user's intent for *this* piece ("push the summer offer, warm tone").
- **Copy Framework** = the *structure* the copy should follow (reusable, named, swappable).

We will make **copy framework a first-class, structured concept**: authored as a new template
**category**, surfaced as a **dropdown** (names only) in the Copy sidebar, and injected into the
prompt through its **own dedicated placeholder** `{{copyFramework}}` — keeping the Creative Brief
clean. Exactly one framework is active at a time (one variable).

### Why this design (verified facts that anchor it)

- `creativeBrief` is the **proven, fully-wired pattern** to mirror. It is read from form values into
  the prompt `$vars` and resolved by its own placeholder:
  - [service.php:1777](../../includes/modules/copy/service.php#L1777) `'creativeBrief' => trim($form_values['creativeBrief'] ?? '')`
  - excluded from the business-metadata `{{brief}}`: [service.php:1960](../../includes/modules/copy/service.php#L1960)
  - placeholders resolve with empty/unknown → `""` (vanishes), names match `\w+`:
    [service.php:2003-2008](../../includes/modules/copy/service.php#L2003)
- The generate call sends **raw `formValues`** ([useCopyGeneration.ts:373](../../app/src/modules/Copy/useCopyGeneration.ts#L373)),
  so anything we put in `formValues` reaches `$form_values` on the backend — for **both** Copy
  (`module='copy'`) and Ads (`module='ads'`), which share `/copy/generate`.
- **Do NOT mirror `template_tonality`.** It is a frontend-only field that the backend **never reads**
  (tone is resolved only from `tone_override`/`tone`: [service.php:1701](../../includes/modules/copy/service.php#L1701)).
  It is the cautionary "clutter" example, not a template to copy.
- The declarative placeholder registry was **built for exactly this**: "Adding future placeholders
  ({{templateTonality}}, {{copyFramework}}, …) is a matter of adding new entries to INJECTIONS"
  ([class-pcm-prompt-placeholders.php:26](../../includes/core/class-pcm-prompt-placeholders.php#L26)).
  This is its first real reuse.
- Template entry categories have a **single source of truth**: `TEMPLATE_CATEGORIES` in
  [templateTypes.ts:19](../../app/shared/templateTypes.ts#L19). The Creative Brief field is rendered
  **inline** in the sidebar (not via copyConfig/DynamicSection):
  [index.tsx:556-579](../../app/src/modules/Copy/index.tsx#L556) — the framework dropdown follows the
  same inline pattern.

## 2. Data model & end-to-end flow

A framework = **`{ name, text }`**. The dropdown shows `name`; `text` is the instruction block.

```
Template authoring (Templates module)
  formData.entries[] gets category:'framework' rows → { key, category:'framework', label:<name>, value:<text> }
        │
        ▼  apply template in Copy sidebar
templatePopulator.buildTemplatePopulation()
  - collects 'framework' entries → frameworks: {name,text}[]
  - default-selects the first → formUpdates.copyFrameworkName = <name>, formUpdates.copyFramework = <text>
        │
        ▼
Copy/index.tsx
  - frameworkOptions state = BUILT_IN_FRAMEWORKS ∪ template frameworks
  - inline <select> (names) bound to formValues.copyFrameworkName
  - onChange(name): resolve text from frameworkOptions → set copyFrameworkName + copyFramework in formValues
        │
        ▼  generate → raw formValues in payload
service.php build_copy_system_prompt()
  $vars['copyFramework'] = trim($form_values['copyFramework'] ?? '')
        │
        ▼
{{copyFramework}} resolved in system_prompt_ads / system_prompt_organic (Copy AND Ads namespaces)
```

**Two form values (both camelCase, both persist with the draft, mirroring `creativeBrief`):**
- `copyFramework` = the selected framework's **instruction text** → the only thing the backend reads.
- `copyFrameworkName` = the selected framework's **name** → drives the dropdown's controlled value,
  persistence, and the conditional-clear comparison.

Storing the resolved text in `formValues` (not just the name) is deliberate: drafts survive reloads
and the backend stays a verbatim mirror of the `creativeBrief` path (no name→text lookup server-side).

## 3. Changes

### A. Shared — new template category (single source of truth)
File: [templateTypes.ts](../../app/shared/templateTypes.ts)
- Add `"framework"` to `TEMPLATE_CATEGORIES`.
- Add `framework: "Framework"` to `CATEGORY_LABELS`.
- Add a `framework:` entry to `CATEGORY_DESCRIPTIONS` (e.g. "A named copywriting framework/structure
  (AIDA, PAS, Hook-Story-Offer…). Selected from a dropdown in the Copy module and injected as
  {{copyFramework}}.").

Because `CATEGORY_LABELS`/`CATEGORY_DESCRIPTIONS`/badge maps are `Record<TemplateCategory, …>`, the
TypeScript compiler will **force** every consumer below to handle `framework` — use that as the
checklist.

### B. Template authoring UI
File: [TemplateDialog.tsx](../../app/src/modules/Templates/TemplateDialog.tsx)
- Add `framework` to `CATEGORY_BADGE_COLORS` ([:118](../../app/src/modules/Templates/TemplateDialog.tsx#L118)).
- Add `framework` cases to `getLabelPlaceholder()` ([:145](../../app/src/modules/Templates/TemplateDialog.tsx#L145))
  and `getValuePlaceholder()` ([:169](../../app/src/modules/Templates/TemplateDialog.tsx#L169))
  (label e.g. "e.g. Hook-Story-Offer"; value e.g. "Describe the framework structure the copy must follow…").

### C. Built-in framework library (so the dropdown works without a template)
New file: `app/src/modules/Copy/frameworks.ts`
- Export `BUILT_IN_FRAMEWORKS: { name: string; text: string }[]`, content lifted from the existing
  framework docs in [docs/modules/copy/](../../docs/modules/copy/) — `hook-story-offer.md`,
  `epiphany-bridge-full.md`, `emotion-speed-fire.md` (plus classics like AIDA/PAS if desired).
- These are the canonical source; keep `text` in sync with those docs.

### D. Template → form mapping
File: [templatePopulator.ts](../../app/src/modules/Copy/templatePopulator.ts)
- `buildTemplatePopulation()`: add a `framework` branch that collects `category:'framework'` entries
  into `frameworks: {name,text}[]`; default-select the first by writing
  `formUpdates.copyFrameworkName` + `formUpdates.copyFramework`.
- Extend `TemplatePopulationResult` with `frameworks: {name,text}[]` and
  `summary.frameworkPopulated: boolean`.
- `buildTemplateClearUpdates()`: extend with a `frameworkWasModified` flag (same shape as the
  existing `briefWasModified` / `tonalityWasModified`), clearing `copyFramework` + `copyFrameworkName`
  only when the framework was template-applied AND not manually changed.

### E. Copy sidebar — the dropdown + clear tracking
File: [index.tsx](../../app/src/modules/Copy/index.tsx)
- Add `frameworkOptions` state = `BUILT_IN_FRAMEWORKS` merged with the template's frameworks
  (returned from `buildTemplatePopulation`). Always include the currently-selected
  `copyFrameworkName` as an option even if absent from the list (robust to persisted drafts).
- Render an **inline framework `<select>`** right after the Creative Brief block
  ([index.tsx:556](../../app/src/modules/Copy/index.tsx#L556)), bound to `formValues.copyFrameworkName`.
  On change: look up `text` in `frameworkOptions`, then
  `handleFieldChange('copyFrameworkName', name)` and `handleFieldChange('copyFramework', text)`.
  Empty/"none" selection clears both.
- Add `lastAppliedFrameworkRef` (alongside `lastAppliedBriefRef`/`lastAppliedTonalityRef` at
  [index.tsx:244](../../app/src/modules/Copy/index.tsx#L244)). In `handleTemplateEntries`:
  - apply branch: store template frameworks into state; set
    `lastAppliedFrameworkRef.current = formUpdates.copyFrameworkName ?? ''`.
  - clear branch: compute `frameworkWasModified = (prev.copyFrameworkName ?? '') !== lastAppliedFrameworkRef.current`,
    pass to `buildTemplateClearUpdates`, reset `frameworkOptions` to `BUILT_IN_FRAMEWORKS`, reset the ref.

**Conditional-clear behavior (agreed rule):** removing a template only undoes what that template set.
- Template **brought** a framework → clearing the template clears the framework…
- …**unless** the user manually changed the dropdown afterward → keep their pick (matches `creativeBrief`).
- Template brought **no** framework → clearing leaves the user's framework untouched.

### F. Generation backend — read the variable
File: [service.php](../../includes/modules/copy/service.php)
- In the `build_copy_system_prompt()` `$vars` array ([:1771](../../includes/modules/copy/service.php#L1771)),
  add `'copyFramework' => trim($form_values['copyFramework'] ?? '')`.
- Do **not** add it to the angle/audience-generation `$vars` (~[:448](../../includes/modules/copy/service.php#L448)) —
  a framework structures the final copy, not angle/audience ideation. Keep those prompts clean.
- `extract_brief()` already excludes such directives — no change; `copyFramework` is not business metadata.

### G. Default prompt templates — add the slot
File: [service.php `get_default_prompts()`](../../includes/modules/copy/service.php#L1464)
- In `system_prompt_ads` and `system_prompt_organic`, add a section just before `OUTPUT FORMAT:`:
  ```
  COPY FRAMEWORK (structure the copy using this framework when provided):
  {{copyFramework}}
  ```
  (Empty value vanishes via `resolve_prompt_placeholders`, so prompts without a framework are unaffected.)

### H. Self-healing migration for existing saved prompts
Files: [class-pcm-prompt-placeholders.php](../../includes/core/class-pcm-prompt-placeholders.php),
[power-creatives.php](../../power-creatives.php), [class-pcm-activator.php](../../includes/class-pcm-activator.php)
- Add one `INJECTIONS` entry `copyFramework_system`:
  `modules: ['copy','ads']`, `sections: ['system_prompt_ads','system_prompt_organic']`,
  `anchor: 'OUTPUT FORMAT:'`, `block: "COPY FRAMEWORK (structure the copy using this framework when provided):\n{{copyFramework}}\n\n"`.
- Bump `PCM_DB_VERSION` → `'1.13.0'`.
- Add a `maybe_upgrade()` step: `if (version_compare($installed_version,'1.13.0','<')) PCM_Prompt_Placeholders::sync_all();`
  Idempotent + customization-safe; the existing `{{creativeBrief}}` injection is skipped where present.

### I. Prompt Editor placeholder reference (docs in UI)
File: [PromptEditorSection.tsx](../../app/src/modules/Settings/PromptEditorSection.tsx)
- Add `{{copyFramework}}` to the `PLACEHOLDERS.copy` and `PLACEHOLDERS.ads` "Business" or a new
  "Structure" group, described as "Selected copywriting framework — chosen from the Framework dropdown
  in the Copy sidebar (Ads/Organic prompts)."

## 4. Scope notes & follow-ups

- **Ads module consumption:** backend support is automatic — Ads calls `/copy/generate` with the same
  `formValues`, and `{{copyFramework}}` is injected into the `ads` namespace too (modules `['copy','ads']`).
  Adding the **dropdown to the Ads sidebar** UI is a small parallel follow-up (Ads has its own sidebar
  via `useAdsOrchestration`); not required for Copy v1.
- **Writer:** `framework` is Copy/Ads-specific. Writer's `ContextGenerationPanel` only handles
  `prompt`/`tonality`/`reference_ad` and will simply ignore `framework` entries — acceptable, no change.
- **`template_tonality`** is intentionally untouched (separate, pre-existing gap).

## 5. Verification

1. **Build/typecheck:** `npm run check` — the `Record<TemplateCategory,…>` maps must compile with
   `framework` added (compiler-enforced completeness). `npm run build` to deploy `app/dist/`.
2. **Authoring:** Templates module → create a copy template with a `framework` entry (name + text);
   confirm the new badge/placeholders render.
3. **Dropdown (built-in):** Copy module with no template → Framework dropdown lists the built-in
   frameworks; selecting one sets `copyFrameworkName` + `copyFramework`.
4. **Dropdown (template):** apply the template from step 2 → its framework appears and is pre-selected.
5. **Generation:** generate copy; verify the chosen framework text appears in the resolved prompt
   (log/inspect) and influences output; verify the **Creative Brief stays clean** (framework not dumped there).
6. **Clear rules:** (a) template-with-framework → clear template → framework clears; (b) manually change
   the framework, then clear template → manual pick survives; (c) template-without-framework → clear
   template → existing framework untouched.
7. **Migration:** confirm DB bump to 1.13.0 runs `sync_all()` once; existing saved Copy/Ads "User/System"
   prompt overrides receive the `{{copyFramework}}` block before `OUTPUT FORMAT:` (idempotent on re-run).
8. **Empty path:** generate with no framework selected → `{{copyFramework}}` vanishes, prompt unchanged.
