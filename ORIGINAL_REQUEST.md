# Original User Request

## Initial Request — 2026-05-22T23:27:56-07:00

Run an automated, multi-agent comprehensive audit of the entire PowerCreatives codebase (both React frontend and PHP backend) to detect code smells, file size violations (>500 lines), and architectural misalignments against the standards in `docs/ARCHITECTURE.md` and global coding policies.

Working directory: c:\Users\dataadmin546\Desktop\PROJECTS\PowerCreatives\app\public\wp-content\plugins\power-creatives

Integrity mode: development

## Requirements

### R1. Architectural Compliance Scanner
Scan all PHP files in `includes/` and all TSX/TS/JS/JSX files in `app/src/` to verify they follow vertical-slice modularity. Confirm that:
- Every PHP backend controller module inherits `PCM_REST_Base` and utilizes standard routes.
- Frontend components are "dumb" and contain no direct business logic/tRPC mutations; all logic must live in custom hooks under the `hooks/` directories.
- No sidebars, tables, or menus are statically hardcoded (they must be config-driven).

### R2. Modularity & Code Quality Checker
Identify any file exceeding the strict senior-developer limit of 500 lines. Detect hardcoded URLs, directories, or keys, and locate any reinvention of shared libraries or helper utilities (such as duplicate database helpers or UI buttons).

### R3. Compliance Report Generation
Generate a unified, highly detailed markdown audit report at `docs/audits/architecture_audit_report.md` detailing every finding, file path, violating line, severity (Critical/Warning/Info), and a step-by-step technical remediation plan.

## Acceptance Criteria

### Audit Scope and Depth
- [ ] Scan 100% of files in `includes/modules/` and `app/src/`.
- [ ] List all files exceeding 500 lines (e.g., `Projects/index.tsx` at 725 lines, backend controllers at >500 lines).
- [ ] Verify if all table/sidebar components are driven by config files.

### Report Executability & Verification
- [ ] Report must be outputted as a valid markdown file at `docs/audits/architecture_audit_report.md`.
- [ ] Every violation in the report must include the absolute file link and exact line range.
- [ ] The report must contain a clear binary PASS/FAIL status for each module.
- [ ] Independent Verification: The verifying agent (Auditor) must randomly verify at least 3 flagged violations to confirm they are accurate (no false positives) and that the remediation plans are technically viable.

## Follow-up — 2026-05-23T08:53:51Z

Build a completely aligned and unified Ads Module by integrating the copywriting and image generation capabilities of the individual Copy and Image modules. The copy and visual outputs will remain display-wise decoupled in the board, but the user will be able to select and package them as a cohesive approval set.

**CRITICAL CONSTRAINT:** All modifications must be 100% confined to the Ads Module directory (`app/src/modules/Ads/`). There must be absolutely ZERO modifications to backend PHP files, databases, or other frontend modules (`Copy`, `Image`, etc.).

Working directory: c:\Users\dataadmin546\Desktop\PROJECTS\PowerCreatives\app\public\wp-content\plugins\power-creatives

Integrity mode: development

## Requirements

### R1. Dumb UI & Hook-Based Logic Separation
Follow the project's strict architecture:
- All business logic, tRPC mutations, and orchestration for copy and image generation must live strictly inside `useAdsOrchestration.ts` (the Hook).
- UI components (`AdsSidebar.tsx`, `AdsResultsGrid.tsx`, `index.tsx`) must be completely presentational ("dumb") and receive all state and callbacks as props.

### R2. AI Enhance & Slider UI Integration
Expose the "AI Enhance" (Brief Optimization) toggle and the "Angles (Scenes)" slider in `AdsSidebar.tsx` using the same visual patterns (styling, layout, range sliders) as the individual Image Sidebar. Pass these state variables and callbacks from `index.tsx` to `AdsSidebar.tsx`.

### R3. Ads Image Phase Alignment (Hook Logic)
Update `useAdsOrchestration.ts` to call the existing, decoupled tRPC image mutations:
- Call `optimizeBrief` mutation if "AI Enhance" is active.
- Call `generateConcepts` mutation if `numVersions` (Angles/Scenes) > 1 to generate distinct visual concepts/scenes.
- Execute parallel `generateImage` queries using these optimized prompts and concepts, matching the high-throughput parallel execution model of the Image module.

### R4. Cohesive Share & Approval Set Selection
Maintain the decoupled grid display rendering for visuals and copy cards in `AdsResultsGrid.tsx`. Ensure the user can select a combination of visuals and copy cards and package them cohesively via `CreateApprovalSetDialog.tsx` under a single public sharing link.

## Acceptance Criteria

### Architectural Separation
- [ ] No file outside `app/src/modules/Ads/` is modified.
- [ ] `AdsSidebar.tsx` contains no direct API requests or tRPC mutations; it only invokes callbacks passed down via props.
- [ ] `useAdsOrchestration.ts` contains all orchestration logic for the Copy phase, Image optimization/concepts phase, and Image generation phase.

### Visual Generation Quality & Features
- [ ] Toggling "AI Enhance" in the Ads Sidebar successfully calls `optimizeBrief` on the backend during generation.
- [ ] Changing the "Angles (Scenes)" slider to >1 successfully generates distinct visual concepts before image generation, matching the Image module's variation quality.
- [ ] Selection and client board packaging of copy and visuals under a single, unified link is fully verified.
