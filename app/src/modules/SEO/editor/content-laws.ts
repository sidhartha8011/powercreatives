/**
 * THE EDITOR'S CONTENT LAWS (decomposition S1, gap 325d280): the pure
 * html-in/html-out functions every editor flow stands on — the AI-content
 * gate, identity stamping, origin restating, section composition. Moved
 * verbatim from SectionModal.tsx; no React, no state, fully testable.
 */

import { createNodeFromContent, getHTMLFromFragment, type Editor } from '@tiptap/core';
import { Fragment } from '@tiptap/pm/model';
import type { SectionData } from './types';

/** Visible text of an HTML fragment (whitespace-collapsed) — clean-result check. */
export function htmlText(html: string): string {
  const el = document.createElement('div');
  el.innerHTML = html;
  return (el.textContent ?? '').replace(/\s+/g, ' ').trim();
}

/** THE AI-CONTENT GATE (law, 2026-07-13): no raw model output ever enters
 *  the pipeline. Every AI reply is parsed through the editor's OWN schema
 *  with normal whitespace rules and re-serialized, so everything stored or
 *  inserted downstream (diff view, `s.ai`, Accept, Revise draft, baseline)
 *  speaks the editor's canonical dialect BY CONSTRUCTION. Without it, a
 *  pretty-printed reply (`<ul>` with newlines between items) rides verbatim
 *  into `insertContentAt` — whose whitespace-preserving parse must coerce
 *  each stray newline into a listItem: one empty bullet before every real
 *  one. Content the schema can't represent is dropped HERE, once, visibly
 *  — exactly what the editor would do on insert, never mid-review. */
export function canonicalAiHtml(editor: Editor, html: string): string {
  if (html === '') return '';
  const content = createNodeFromContent(html, editor.schema, { parseOptions: { preserveWhitespace: false } });
  return getHTMLFromFragment(Fragment.from(content), editor.schema);
}

/** Stamp the review anchor onto the FIRST heading of a section's html.
 *  Called only by the ONE writer — identity survives every landing and
 *  resolution no matter how many headings the content carries. */
export function stampReviewId(html: string, i: number): string {
  const el = document.createElement('div');
  el.innerHTML = html;
  const heading = el.querySelector('h1,h2,h3,h4,h5,h6');
  if (heading === null) return html;
  heading.setAttribute('data-pcm-review-id', String(i));
  return el.innerHTML;
}

/** Restate a section's lane identity on the content that will be KEPT.
 *  The pre-review capture (`sourceHtml`) is the only reliable origin carrier
 *  — the AI's clean value carries no attributes. Accepting a CHANGED section
 *  flips `original` → `owned`, so the lane reads amber the moment it lands
 *  (owner law: amber = edited, sky = added — matches what the server emits
 *  on the next load). `insert` stays platform-added; a version-loaded doc
 *  carries no origins and keeps stating that fact. */
export function restateOrigin(html: string, sourceHtml: string, changed: boolean): string {
  const src = document.createElement('div');
  src.innerHTML = sourceHtml;
  const origin = src.querySelector('h1,h2,h3,h4,h5,h6')?.getAttribute('data-pcm-origin') ?? null;
  if (origin === null) return html;
  const out = document.createElement('div');
  out.innerHTML = html;
  const heading = out.querySelector('h1,h2,h3,h4,h5,h6');
  if (heading === null) return html;
  heading.setAttribute('data-pcm-origin', changed && origin === 'original' ? 'owned' : origin);
  return out.innerHTML;
}

/** Image-src identity (mirrors PCM_Text_Matcher::normalize_src): entity-decode
 *  + trim only — no case fold, query kept. */
export function normSrc(src: string): string {
  const el = document.createElement('textarea');
  el.innerHTML = src;
  return el.value.trim();
}

/** The section's CURRENT html: served rule > original with paragraph rules folded in. */
export function composeSectionHtml(section: SectionData): string {
  if (section.sectionRuleReplacement) return section.sectionRuleReplacement;
  const h = section.heading.html
    || `<h${section.heading.level}>${escapeHtml(section.heading.text)}</h${section.heading.level}>`;
  return h + section.paragraphs
    .map((p) => (p.servedHtml != null ? `<p>${p.servedHtml}</p>` : p.html))
    .join('');
}

export function escapeHtml(s: string): string {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/** Strip the platform's transient heading anchors (origin colors, review ids)
 *  from stored html — a saved version opens as plain content, exactly what a
 *  dropdown version pick shows (the server strips them on save; this is the
 *  belt for any row that predates that law). */
export function stripPcmAnchors(html: string): string {
  return html.replace(/\s*data-pcm-(?:origin|review-id)="[^"]*"/g, '');
}

const FAQ_ITEM_STYLE = 'border:1px solid #dcdcdc;border-radius:8px;padding:10px 16px;margin:10px 0';
const FAQ_SUMMARY_STYLE = 'font-weight:600;cursor:pointer';
/** The inserted skeleton — a normal section (heading + items) the user edits in place. */
export const faqTemplate = (): string =>
  '<h2>Frequently asked questions</h2>' +
  [1, 2, 3].map((n) =>
    `<details style="${FAQ_ITEM_STYLE}"><summary style="${FAQ_SUMMARY_STYLE}">Question ${n}</summary><p>Answer ${n}.</p></details>`,
  ).join('');
