/**
 * Card tokens — THE single source of typography and spacing for every
 * EntityCard.
 *
 * THE CONTRACT: components inside a card may not carry raw text-*, font-*,
 * leading-*, or layout-padding classes. Every text node picks one CARD_TYPE
 * role; every structural gap comes from CARD_SPACE. Change a value here and
 * every card in the app follows; add an inline override and you have broken
 * the system (and the review should catch it).
 *
 * Each type role sets size + ink + weight + line-height EXPLICITLY. This is
 * also the WordPress-bleed fix: cards render inside Radix portals mounted in
 * wp-admin's body, where WP's global input/select CSS fills any property we
 * leave unspecified. Fully specified roles leave it nothing to style.
 */

export const CARD_TYPE = {
  /**
   * The entity name. The `!` marks are required: the title renders inside the
   * Input component whose base variant carries `md:text-sm` — a responsive
   * class tailwind-merge does NOT treat as conflicting with a plain size, so
   * without `!important` the title would shrink to 14px on desktop.
   */
  TITLE: '!text-[32px] !font-bold !leading-tight tracking-tight text-foreground',
  /** Everything the user reads or edits: values, selects, inputs, notes. */
  BODY: 'text-[13px] font-normal leading-normal text-foreground',
  /** Everything ABOUT the content: table headers, meta, counts, timestamps, chips. */
  LABEL: 'text-xs font-normal leading-normal text-muted-foreground',
  /** Section headers (PROJECTS / LOG) only. */
  SECTION: 'text-[11px] font-semibold uppercase tracking-wider text-muted-foreground',
} as const;

export const CARD_SPACE = {
  /** The single centered content column inside the card shell. */
  COLUMN: 'mx-auto max-w-[760px] px-4 pt-10 pb-14',
  /** Title block → properties. */
  TITLE_GAP: 'mb-6',
  /** Between sections. */
  SECTION_GAP: 'mt-8',
  /** Section header → section content. */
  SECTION_HEADER_GAP: 'mb-2',
} as const;
