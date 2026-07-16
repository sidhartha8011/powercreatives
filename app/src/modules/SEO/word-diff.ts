/**
 * word-diff — in-house inline diff for the page editor's AI review (V2).
 * Zero dependencies by law (owner: senior grade, no diff lib for ~80 lines).
 *
 * The diff is PRESENTATION ONLY: review markup lives in `data-diff-added` /
 * `data-diff-removed` spans (rendered by the TipTap marks of the same name)
 * and is stripped by `stripDiffHtml` before anything is ever saved — a
 * guard, not a convention.
 */

export type DiffRun = { type: 'equal' | 'added' | 'removed'; text: string };

/** Tokenize into words + whitespace runs (whitespace rides with the diff). */
const tokenize = (s: string): string[] => s.match(/\S+|\s+/g) ?? [];

/** Word-level LCS diff of two plain texts, adjacent runs merged. */
export function wordDiff(oldText: string, newText: string): DiffRun[] {
  const a = tokenize(oldText);
  const b = tokenize(newText);
  const n = a.length;
  const m = b.length;
  // LCS length table (sections are prompt-bounded, O(n·m) is fine here).
  const dp: number[][] = Array.from({ length: n + 1 }, () => new Array<number>(m + 1).fill(0));
  for (let i = n - 1; i >= 0; i--) {
    for (let j = m - 1; j >= 0; j--) {
      dp[i][j] = a[i] === b[j] ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
    }
  }
  const runs: DiffRun[] = [];
  const push = (type: DiffRun['type'], text: string) => {
    const last = runs[runs.length - 1];
    if (last && last.type === type) last.text += text;
    else runs.push({ type, text });
  };
  let i = 0;
  let j = 0;
  while (i < n && j < m) {
    if (a[i] === b[j]) { push('equal', a[i]); i++; j++; }
    else if (dp[i + 1][j] >= dp[i][j + 1]) { push('removed', a[i]); i++; }
    else { push('added', b[j]); j++; }
  }
  while (i < n) { push('removed', a[i]); i++; }
  while (j < m) { push('added', b[j]); j++; }
  return runs;
}

const escapeHtml = (s: string): string =>
  s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

const runsToHtml = (runs: DiffRun[]): string =>
  runs.map((r) => {
    const text = escapeHtml(r.text);
    if (r.type === 'added') return `<span data-diff-added="1">${text}</span>`;
    if (r.type === 'removed') return `<span data-diff-removed="1">${text}</span>`;
    return text;
  }).join('');

const parseBlocks = (html: string): HTMLElement[] => {
  const el = document.createElement('div');
  el.innerHTML = html;
  return Array.from(el.children) as HTMLElement[];
};

const blockText = (b: HTMLElement): string => (b.textContent ?? '').replace(/\s+/g, ' ').trim();

/** Tags whose content model is inline text — safe wrappers for diff runs. */
const isTextTag = (t: string): boolean => t === 'p' || /^h[1-6]$/.test(t);

/** A whole block rendered in ITS OWN shape with every text-bearing LEAF
 *  wrapped in the given diff span — ONE renderer for both sides, only the
 *  color says added or removed (fidelity law: a removed list is a red
 *  list, bullet by bullet — the view must show what you HAD; the old
 *  flatten-to-red-paragraph destroyed exactly that). Leaves are the
 *  deepest text wrappers (p/h/summary, or a bare <li>); anything holding
 *  an image stays verbatim — the view never destroys an image. The
 *  block's OWN attributes always survive (the identity law). */
const markBlock = (b: HTMLElement, kind: 'added' | 'removed'): string => {
  const attr = kind === 'added' ? 'data-diff-added' : 'data-diff-removed';
  const el = b.cloneNode(true) as HTMLElement;
  const leaves = (Array.from(el.querySelectorAll('p, h1, h2, h3, h4, h5, h6, summary, li')) as HTMLElement[])
    .filter((leaf) => leaf.querySelector('p, h1, h2, h3, h4, h5, h6, summary, li') === null);
  const targets = leaves.length > 0 ? leaves : [el];
  targets.forEach((t) => {
    if (t.querySelector('img') !== null) return;
    t.innerHTML = `<span ${attr}="1">${escapeHtml(blockText(t))}</span>`;
  });
  return el.outerHTML;
};

/** The NEW block with the ORIGINAL heading's `data-pcm-origin` carried over
 *  (unless it already states its own). The origin is the section's lane
 *  identity and the ORIGINAL block is its only reliable carrier — the AI
 *  reply doesn't echo attributes; losing it here repainted every reviewed
 *  lane blue. Self-guarding: only headings ever carry an origin. */
const withOrigin = (o: HTMLElement, nw: HTMLElement): HTMLElement => {
  const el = nw.cloneNode(true) as HTMLElement;
  const origin = o.getAttribute('data-pcm-origin');
  if (origin !== null && /^h[1-6]$/i.test(el.tagName) && !el.hasAttribute('data-pcm-origin')) {
    el.setAttribute('data-pcm-origin', origin);
  }
  return el;
};

/** Pairing class: every heading level is ONE class — a level tweak must
 *  not unpair a heading from itself (the origin rides on that pair). */
const pairClass = (el: HTMLElement): string =>
  (/^H[1-6]$/.test(el.tagName) ? 'h' : el.tagName.toLowerCase());

/** Pair old/new blocks by an LCS over their pairing classes (the same DP
 *  family as the word diff — zero dependencies by law): equal-class blocks
 *  pair up, the rest render added/removed in their own shape. One inserted
 *  paragraph can never misalign the rest of the section again; identical
 *  class sequences pair exactly as the old positional walk did. */
const pairByClass = (a: HTMLElement[], b: HTMLElement[]): Array<[HTMLElement | null, HTMLElement | null]> => {
  const n = a.length;
  const m = b.length;
  const dp: number[][] = Array.from({ length: n + 1 }, () => new Array<number>(m + 1).fill(0));
  for (let i = n - 1; i >= 0; i--) {
    for (let j = m - 1; j >= 0; j--) {
      dp[i][j] = pairClass(a[i]) === pairClass(b[j]) ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
    }
  }
  const pairs: Array<[HTMLElement | null, HTMLElement | null]> = [];
  let i = 0;
  let j = 0;
  while (i < n && j < m) {
    if (pairClass(a[i]) === pairClass(b[j])) { pairs.push([a[i], b[j]]); i++; j++; }
    else if (dp[i + 1][j] >= dp[i][j + 1]) { pairs.push([a[i], null]); i++; }
    else { pairs.push([null, b[j]]); j++; }
  }
  while (i < n) { pairs.push([a[i], null]); i++; }
  while (j < m) { pairs.push([null, b[j]]); j++; }
  return pairs;
};

/**
 * The inline red/green VIEW of one section: original vs AI blocks paired by
 * an LCS over their block classes; paired text blocks (p/h) diff word-wise
 * inside the NEW block's tag; paired structural blocks (list↔list, FAQ↔FAQ)
 * and unmatched blocks render in THEIR OWN SHAPE with per-leaf marks — a
 * removed list is a red list (fidelity law: the view shows what you HAD).
 * (Formatting shows plain during review — the view is presentation, never
 * the content source: Accept keeps the AI's clean HTML for untouched
 * sections / the user's stripped live text for edited ones, Reject restores
 * the original verbatim.)
 */
export function diffBlocksHtml(originalHtml: string, aiHtml: string): string {
  const out: string[] = [];
  for (const [o, nw] of pairByClass(parseBlocks(originalHtml), parseBlocks(aiHtml))) {
    if (o && nw) {
      const oText = blockText(o);
      const nText = blockText(nw);
      if (oText === nText) {
        out.push(withOrigin(o, nw).outerHTML); // untouched block — real formatting + identity kept
      } else if (isTextTag(nw.tagName.toLowerCase())) {
        const el = withOrigin(o, nw);
        el.innerHTML = runsToHtml(wordDiff(oText, nText));
        out.push(el.outerHTML);
      } else {
        // Same-class structural pair (list↔list, FAQ↔FAQ): both sides in shape.
        out.push(markBlock(o, 'removed') + markBlock(withOrigin(o, nw), 'added'));
      }
    } else if (nw) {
      out.push(markBlock(nw, 'added'));
    } else if (o) {
      out.push(markBlock(o, 'removed'));
    }
  }
  return out.join('');
}

/** The save guard: removed runs die, added runs unwrap — marks NEVER persist.
 *  Wrappers the strip itself EMPTIES die with their content: a leftover
 *  <li></li> or <p></p> is a phantom bullet/gap, never content. Only the
 *  ancestors of removed runs are candidates — the user's own blank
 *  paragraphs are untouchable — pruned deepest-first so a fully emptied list
 *  collapses with its items, and anything still holding an image survives. */
export function stripDiffHtml(html: string): string {
  const el = document.createElement('div');
  el.innerHTML = html;
  const candidates = new Set<HTMLElement>();
  el.querySelectorAll('span[data-diff-removed]').forEach((s) => {
    for (let p = s.parentElement; p !== null && p !== el; p = p.parentElement) candidates.add(p);
    s.remove();
  });
  el.querySelectorAll('span[data-diff-added]').forEach((s) => s.replaceWith(...Array.from(s.childNodes)));
  const depth = (n: HTMLElement): number => {
    let d = 0;
    for (let p = n.parentElement; p !== null; p = p.parentElement) d++;
    return d;
  };
  Array.from(candidates)
    .sort((a, b) => depth(b) - depth(a))
    .forEach((w) => {
      if ((w.textContent ?? '').trim() === '' && w.querySelector('img') === null) w.remove();
    });
  return el.innerHTML;
}

export interface DocSection {
  /** Visible heading text — the review rail's label. */
  heading: string;
  /** The section's content html (heading + body), images extracted. */
  html: string;
  /** Locked context images lifted out before AI sees the section. */
  imgs: string[];
}

/**
 * Split a TipTap page document into the leading no-heading zone + heading
 * sections — the SAME grouping law as the server's split_unit_sections.
 * Top-level images are lifted out per section (context, never sent to AI).
 */
/**
 * Split by REVIEW-IDENTITY headings only (`data-pcm-review-id`) — the
 * review engine's identity law: a section spans from its anchored heading
 * to the NEXT anchored heading, so id-LESS headings (sections the AI added
 * mid-review) belong to the section that produced them. Returned keyed by
 * the anchor id; top-level images lift out exactly like splitDocSections.
 */
export function splitReviewSections(html: string): Record<string, DocSection> {
  const blocks = parseBlocks(html);
  const out: Record<string, DocSection> = {};
  let cur: DocSection | null = null;
  for (const b of blocks) {
    const tag = b.tagName.toLowerCase();
    const rid = /^h[1-6]$/.test(tag) ? b.getAttribute('data-pcm-review-id') : null;
    if (rid !== null) {
      cur = { heading: blockText(b), html: b.outerHTML, imgs: [] };
      out[rid] = cur;
    } else if (cur) {
      if (tag === 'img') cur.imgs.push(b.outerHTML);
      else cur.html += b.outerHTML;
    }
  }
  return out;
}

export function splitDocSections(html: string): { orphanHtml: string; sections: DocSection[] } {
  const blocks = parseBlocks(html);
  let orphanHtml = '';
  const sections: DocSection[] = [];
  let cur: DocSection | null = null;
  for (const b of blocks) {
    const tag = b.tagName.toLowerCase();
    if (/^h[1-6]$/.test(tag)) {
      if (cur) sections.push(cur);
      cur = { heading: blockText(b), html: b.outerHTML, imgs: [] };
    } else if (tag === 'img') {
      if (cur) cur.imgs.push(b.outerHTML);
      else orphanHtml += b.outerHTML;
    } else if (cur) {
      cur.html += b.outerHTML;
    } else {
      orphanHtml += b.outerHTML;
    }
  }
  if (cur) sections.push(cur);
  return { orphanHtml, sections };
}
