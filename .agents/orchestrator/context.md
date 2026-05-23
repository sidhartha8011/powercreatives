# State Context: PowerCreatives Architecture Audit

## Task Overview
Perform a comprehensive code and architecture audit of PowerCreatives codebase (both React frontend and PHP backend) to detect:
- Architectural misalignments (vertical-slice modularity, REST controller inheritance, config-driven tables/sidebars)
- Modularity / Code smells (business logic in dumb components)
- File size violations (>500 lines)
- Reinvention of shared libraries/helpers
- Hardcoded URLs/directories/keys

## Directory Roots to Audit
1. `includes/modules/` (40 PHP files across modules)
2. `app/src/` (122 TS/TSX files across UI)

## Key Compliance Checkpoints
- PHP Backend controllers must inherit `PCM_REST_Base`.
- Frontend React components must be "dumb" (no business logic/tRPC mutations; all logic belongs in hooks).
- Tables and sidebars must be 100% config-driven.
- Files must be <= 500 lines.
- No duplicate database helpers or UI elements.

## Target Output Path
`docs/audits/architecture_audit_report.md`
