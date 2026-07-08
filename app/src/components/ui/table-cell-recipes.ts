/**
 * Table cell recipes — the SEO table's in-cell design language, extracted so
 * every spreadsheet-style table (SEO, Deliveries, future) renders identical
 * cell internals. Values sourced VERBATIM from the SEO module (seo-table.tsx
 * EditableTextCell + SEO/index.tsx type pill) — change them HERE and every
 * consumer follows; never re-hardcode per table (PO 2026-07-08).
 */

/** View state of an editable text cell: plain truncated text, dotted
 *  underline on hover as the "click to edit" affordance. */
export const CELL_VIEW_TEXT =
  'block w-full min-w-0 truncate text-left text-xs leading-snug hover:underline decoration-dotted';

/** Edit state: compact bordered input, same row height. */
export const CELL_EDIT_INPUT = 'h-7 text-xs';

/** Pill/tag shape — square-ish corners, compact (NOT rounded-full). Color
 *  comes from the consumer (semantic bg/text or the neutral variant below). */
export const CELL_PILL = 'inline-flex items-center rounded px-1.5 py-0.5 text-[11px]';

/** The neutral pill (SEO's type pill). */
export const CELL_PILL_NEUTRAL = `${CELL_PILL} bg-muted/60 text-muted-foreground`;

/** Empty-value ink inside view-state cells. */
export const CELL_EMPTY_TEXT = 'text-muted-foreground/60';
