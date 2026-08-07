# Deferred Shared Document Surface Design System

> **Status:** Deferred — do not implement yet.
>
> **Recorded:** 2026-08-06
>
> **Prepared by:** ChatGPT
>
> **Initial consumer:** Approvals
>
> **Future consumers:** Projects and other document-based modules

## Summary

Create a reusable shared document-surface system that keeps Approvals visually
aligned with the application and can later be consumed by Projects and other
modules. This document records the intended architecture; it does not authorize
implementation.

## Verified current state

- The application establishes Inter as its shared interface font.
- The Approval document surface currently introduces a competing system-font
  stack through its own document tokens.
- Styling ownership is fragmented across global CSS, TypeScript token objects,
  Approval CSS, and inline React styles.
- Several locations act as partial sources of truth, so typography and control
  geometry can drift between the card, editor, and portalled overlays.

## Architecture

```text
Application design tokens
        ↓
Shared Document Surface
title · body · metadata · properties · lists · checkboxes · toolbar
        ↓
Approvals now · Projects later · other modules later
```

- Application design tokens remain authoritative for Inter, colors, spacing,
  radii, borders, and shadows.
- A shared `DocumentSurface` layer defines semantic roles for document titles,
  metadata, properties, body text, lists, checkboxes, and selection toolbars.
- Approvals consumes the shared layer without declaring competing font or
  typography values.
- Portalled editors and popovers receive the same shared styling contract.
- Static inline styling is removed from the Approval document/card UI. Runtime
  canvas geometry and drawing data remain outside the visual design-system
  contract because they represent user content rather than design values.
- H4 remains renderable for compatibility with existing documents but is not
  exposed through authoring controls.

## Implementation requirements

- Consolidate the conflicting `--pcm-doc-*` font contract with the established
  application typography foundation instead of adding another token source.
- Package document tokens and component classes under the shared component
  boundary so other modules can consume the same public contract.
- Migrate only Approval document/card consumers initially. Do not modify
  Projects, Deliveries, Brands, or unrelated modules during the first adoption.
- Preserve persistence, card geometry, editor behavior, public-review rendering,
  and previously stored document content.
- Document token ownership, permitted dependency direction, and the prohibition
  against module-local visual forks.
- Prefer semantic classes and shared components over duplicated selectors or
  component-local visual literals.

## Validation requirements

- Compare computed font family, size, weight, line height, color, checkbox
  geometry, and spacing against the approved visual reference.
- Test internal preview, public review, custom-card editing, read-only rendering,
  the selection toolbar, and all portalled overlays.
- Verify existing H4 content still renders while H4 cannot be newly selected.
- Confirm the production build succeeds and the change introduces no new
  type-check failures.
- Confirm no static `style` props remain in the migrated Approval document/card
  surface.
- Perform visual checks at narrow, normal, and wide viewport sizes.

## Scope boundary

This plan does not authorize an application-wide design-system migration. It
creates a shared, reusable contract and adopts it in Approvals first. Migrating
another module requires a separate, reviewed task.

## Research basis

The structure follows the established global-token → semantic-alias → component
pattern described by the Design Tokens Community Group and used by systems such
as Fluent, Adobe Spectrum, Carbon, Primer, USWDS, Radix Themes, and Atlassian.
Typography roles should own family, size, weight, line height, and related rhythm
together instead of scattering those values across component overrides.

Selected references:

- https://www.designtokens.org/tr/2025.10/format/
- https://fluent2.microsoft.design/design-tokens
- https://spectrum.adobe.com/page/design-tokens/
- https://carbondesignsystem.com/elements/themes/overview/
- https://primer.style/product/getting-started/foundations/typography/
- https://designsystem.digital.gov/design-tokens/
- https://www.radix-ui.com/themes/docs/theme/typography
- https://atlassian.design/foundations/design-tokens

## Re-entry condition

When this work is resumed, begin with a fresh fact-based audit of the current
token consumers and inline styles. Do not assume this 2026-08-06 inventory still
matches the codebase after other modules or developers have changed it.
