/**
 * THE EDITOR'S DATA CONTRACTS (decomposition S1, gap 325d280): every shape
 * the page/section editor speaks — moved verbatim from SectionModal.tsx,
 * one concern one file. SectionModalProps stays with its component.
 */

import type { DocSection } from '../word-diff';
import type { CompiledDirective } from '../optimizer/types';

export interface SectionParagraph {
  text: string;
  occurrence: number;
  html: string;
  /** An active paragraph rule's replacement (folded into the shown state). */
  servedHtml?: string;
}

export interface SectionData {
  heading: { text: string; level: number; occurrence: number; html: string };
  paragraphs: SectionParagraph[];
  /** Active `section` rule serving this section, when one exists. */
  sectionRuleReplacement?: string | null;
  /** Served-truth editor (contracts v2.2): this section is a SLICE of an
   *  owning rule's replacement — saves splice that unit range of the rule. */
  slice?: { ruleId: number; unitFrom: number; unitTo: number };
}

export interface SectionAnchor { text: string; level: number; occurrence: number }

export interface InsertData {
  ruleId?: number;
  anchorText: string;
  anchorLevel: number;
  anchorOccurrence: number;
  position: 'before' | 'after';
  replacement: string;
}

/** The selected image's identity + attrs as found (= originals when unruled). */
export interface ImgSelection {
  src: string;
  occurrence: number;
  alt: string;
  title: string;
}

/** One section under AI review. pending/diff block saving; the rest are resolved. */
export type ReviewStatus = 'pending' | 'diff' | 'accepted' | 'rejected' | 'clean' | 'failed';

export interface ReviewSection extends DocSection {
  status: ReviewStatus;
  ai?: string;
  error?: string;
  /** What ACTUALLY generated this suggestion — the API's own report. */
  genModel?: string;
  /** THE CHANGE CARDS (gap 0a0a3c3): the section's OWN verified changes —
   *  server-checked claims (quote found in the produced text, why ∈ the
   *  run's purposes). Absent/empty = the honest floor: the card falls back
   *  to the global run summary exactly as before. */
  changes?: Array<{ what: string; why: string; quote: string }>;
  /** Server verdict: the section was substantially rewritten — present it
   *  as calm Before/After blocks instead of word confetti. */
  rewritten?: boolean;
  /** T2 (gap e1eb677): per-change ticks, parallel to `changes`, all true on
   *  every landing. A partial selection routes through ONE bounded
   *  recomposition (the existing revise path) — never a splice guess. */
  kept?: boolean[];
  /** The landed diff VIEW read back from the editor (same serializer as the
   *  live compare) — effectiveContent's untouched-detector: live == baseline
   *  ⇔ the user typed nothing in this section since the suggestion landed. */
  baseline?: string;
  /** THE SECTION'S OWN ORDERS (gap e533bc5 F4): the routed directives this
   *  section was sent — shown while pending and as the card-less floor.
   *  The run-level dump under every card died with this field. */
  directives?: CompiledDirective[];
}
