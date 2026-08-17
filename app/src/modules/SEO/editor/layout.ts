/**
 * THE EDITOR'S LAYOUT + TYPE SCALES (decomposition S1, gap 325d280):
 * every geometry constant and Tailwind scale the editor draws with —
 * moved verbatim from SectionModal.tsx. ONE source of truth per number.
 */

export const WIDTH = 440;

/** The page card's effective width — ONE source of truth. Provably equal
 *  to the former width/minWidth/maxWidth trio (CSS resolves width → the
 *  max-width cap → the min-width floor, and min-width wins). While the
 *  keyword drawer is open the card TAPERS by a fixed amount (owner UX
 *  2026-07-14) and tapers back on close — a plain width transition; the
 *  drawer/card PAIR is centered by the page-mode flex wrapper, so no
 *  anchor math exists anywhere. */
const PAGE_CARD_WIDTH = 'max(720px, min(980px, 94vw, 100vw - 32px))';
const PAGE_CARD_TAPER_PX = 280;
/** The outside rail's width — the card cedes exactly this when a rail is
 *  open (gap e533bc5 F6), the drawer's taper law mirrored right. */
const RAIL_TAPER_PX = 250;

export const pageCardWidth = (drawerOpen: boolean, railOpen: boolean): string => {
  const cut = (drawerOpen ? PAGE_CARD_TAPER_PX : 0) + (railOpen ? RAIL_TAPER_PX : 0);
  return cut > 0 ? `calc(${PAGE_CARD_WIDTH} - ${cut}px)` : PAGE_CARD_WIDTH;
};

/** THE LANGUAGE LAW (owner ruling 2026-07-19, gap e533bc5 F1): appended
 *  LAST to EVERY AI order so it outranks everything before it — the
 *  content's own language is the truth, translation is forbidden. Interim
 *  guard until the server's hub-locale masquerade dies (the held group);
 *  the root fix removes the broken variable, never this law. */
export const LANGUAGE_LAW = '\n\nTHE LANGUAGE LAW (overrides everything above): write in the SAME language as the current content. NEVER translate it to another language.';

/** THE CONTENT-INTEGRITY LAW (owner report 2026-07-20, gap 795298f):
 *  WordPress shortcodes, markup, and code carry MEANING the model must
 *  never "improve". Appended beside the language law on EVERY order —
 *  one source; the server-side echo lands with the held Group D pair. */
export const CONTENT_INTEGRITY_LAW = '\n\nTHE CONTENT-INTEGRITY LAW: square-bracket shortcodes like [contact-form id="1"], HTML tags and their attributes/classes/ids, HTML entities, and any embedded code are UNTOUCHABLE tokens — reproduce each one byte-identically, in place, unless the order explicitly names it. Never rewrite, translate, reformat, or delete them.';

/** Page mode's OWN reading scale (owner order U2): the document must read
 *  like the live page — real paragraph air, stepped heading sizes — while
 *  section mode keeps the compact scale below, byte-identical. */
export const PAGE_TYPE_SCALE =
  'text-sm leading-relaxed text-slate-800 break-words ' +
  '[&_h1]:font-serif [&_h1]:text-[22px] [&_h1]:leading-8 [&_h1]:font-bold [&_h1]:mt-7 [&_h1]:mb-2 ' +
  '[&_h2]:text-lg [&_h2]:font-semibold [&_h2]:mt-6 [&_h2]:mb-2 ' +
  '[&_h3]:text-base [&_h3]:font-semibold [&_h3]:mt-5 [&_h3]:mb-1.5 ' +
  '[&_h4]:text-sm [&_h4]:font-semibold [&_h4]:mt-4 [&_h4]:mb-1 ' +
  '[&_h5]:text-sm [&_h5]:font-medium [&_h5]:mt-3 [&_h5]:mb-1 ' +
  '[&_h6]:text-sm [&_h6]:font-medium [&_h6]:mt-3 [&_h6]:mb-1 ' +
  '[&_p]:my-3 [&_ul]:my-3 [&_ul]:pl-5 [&_ul]:list-disc [&_ol]:my-3 [&_ol]:pl-5 [&_ol]:list-decimal ' +
  '[&_li]:my-1 [&_a]:text-primary [&_a]:underline [&_a]:decoration-dotted ' +
  '[&_.ProseMirror>*:first-child]:mt-0';

/** Compact readable scale (no `prose` plugin in this build). */
export const TYPE_SCALE =
  'text-xs leading-relaxed text-slate-800 break-words ' +
  '[&_h1]:text-sm [&_h1]:font-semibold [&_h1]:mt-2 [&_h1]:mb-1 ' +
  '[&_h2]:text-sm [&_h2]:font-semibold [&_h2]:mt-2 [&_h2]:mb-1 ' +
  '[&_h3]:text-xs [&_h3]:font-semibold [&_h3]:mt-1.5 [&_h3]:mb-0.5 ' +
  '[&_h4]:text-xs [&_h4]:font-medium [&_h4]:mt-1.5 [&_h4]:mb-0.5 ' +
  '[&_p]:my-1 [&_ul]:my-1 [&_ul]:pl-4 [&_ul]:list-disc [&_ol]:my-1 [&_ol]:pl-4 [&_ol]:list-decimal ' +
  '[&_li]:my-0.5 [&_a]:text-primary [&_a]:underline [&_a]:decoration-dotted';

/** The blocks' looks, scoped to the page editor: one continuous bordered
 *  block per section (start/middle/end roles join their borders; inter-block
 *  margins become inner padding so the box never breaks), 3px origin left
 *  edge, air between sections. Images pause the side borders (they keep
 *  their natural width) but keep the origin edge. Summary markers hidden +
 *  fold cursor neutralized: FAQ items EDIT as plain open text. */
export const BLOCK_STYLES =
  '[&_.pcm-blk]:border-l-[3px] [&_.pcm-blk]:border-r [&_.pcm-blk]:border-r-slate-100 ' +
  '[&_.pcm-blk]:px-4 [&_.pcm-blk]:my-0 [&_.pcm-blk]:py-1 ' +
  '[&_.pcm-blk-start]:border-t [&_.pcm-blk-start]:border-t-slate-100 [&_.pcm-blk-start]:rounded-tr-xl [&_.pcm-blk-start]:pt-3 [&_.pcm-blk-start]:mt-5 ' +
  '[&_.pcm-blk-end]:border-b [&_.pcm-blk-end]:border-b-slate-100 [&_.pcm-blk-end]:rounded-br-xl [&_.pcm-blk-end]:pb-3 ' +
  '[&_.pcm-blk-original]:border-l-slate-300 [&_.pcm-blk-owned]:border-l-amber-400 [&_.pcm-blk-added]:border-l-sky-400 ' +
  '[&_img.pcm-blk]:block [&_img.pcm-blk]:border-y-0 [&_img.pcm-blk]:border-r-0 [&_img.pcm-blk]:rounded-none ' +
  '[&_summary]:list-none [&_summary]:!cursor-text [&_.pcm-deadzone]:opacity-60 ' +
  // Focus flash (click a rail card): dark-blue lane + soft wash, eased both ways.
  '[&_.pcm-blk]:transition-colors [&_.pcm-blk]:duration-500 ' +
  '[&_.pcm-blk-flash]:!border-l-blue-600 [&_.pcm-blk-flash]:bg-blue-50/70 ' +
  // Quote highlight (gap 0a0a3c3): hovering a change card lights the exact
  // words that change produced — the claim-to-text verification.
  '[&_.pcm-quote-flash]:bg-blue-100 [&_.pcm-quote-flash]:rounded-sm ' +
  // Inline review chips: float on the changed section's first line. Hover
  // law (owner 2026-07-13): SELF-hover only (`.pcm-chip-x:hover`, never a
  // container-hover — that darkened every chip from anywhere in the text)
  // and hover LIGHTENS. Colors state the action: green = keep (save
  // family), blue = asks the AI (generate family), grey ghost = decline —
  // every colour a SHARED token (success / primary / accent / border / muted),
  // never an inline palette (owner card 11).
  '[&_.pcm-review-chip]:float-right [&_.pcm-review-chip]:ml-2 [&_.pcm-review-chip]:inline-flex [&_.pcm-review-chip]:gap-1 [&_.pcm-review-chip]:align-middle ' +
  '[&_.pcm-review-chip_button]:rounded-full [&_.pcm-review-chip_button]:px-2 [&_.pcm-review-chip_button]:py-0.5 [&_.pcm-review-chip_button]:text-[10px] ' +
  '[&_.pcm-chip-accept]:bg-success [&_.pcm-chip-accept]:font-medium [&_.pcm-chip-accept]:text-success-foreground [&_.pcm-chip-accept:hover]:bg-success/85 ' +
  '[&_.pcm-chip-revise]:border [&_.pcm-chip-revise]:border-primary/40 [&_.pcm-chip-revise]:bg-card [&_.pcm-chip-revise]:text-primary [&_.pcm-chip-revise:hover]:bg-accent ' +
  '[&_.pcm-chip-ghost]:border [&_.pcm-chip-ghost]:border-border [&_.pcm-chip-ghost]:bg-card [&_.pcm-chip-ghost]:text-muted-foreground [&_.pcm-chip-ghost:hover]:bg-muted';
