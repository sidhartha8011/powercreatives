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
 *
 * The px VALUES live in exactly one place: the `--text-card-*` tokens in
 * index.css `@theme` (which generate the semantic text-card-* utilities used
 * below). To resize the whole card system, edit those variables — never add
 * a px class here or in a component.
 */

/** The one shared value voice — every readable/editable value wears this. */
const BODY = 'text-card-body font-normal text-foreground';

export const CARD_TYPE = {
  /** The entity name. */
  TITLE: 'text-card-title font-bold tracking-tight text-foreground',
  /** Everything the user reads or edits: values, selects, inputs, notes. */
  BODY,
  /** Everything ABOUT the content: table headers, meta, counts, timestamps. */
  LABEL: 'text-card-label font-normal text-muted-foreground',
  /** A chip IS a body value in pill clothing — it derives from BODY and owns
   *  NO size of its own, ever. leading-none only: a pill's height comes from
   *  its padding, not the line box. */
  CHIP: `${BODY} leading-none`,
  /** Section headers (PROJECTS / LOG) only. */
  SECTION: 'text-card-section font-semibold uppercase tracking-wider text-muted-foreground',
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
