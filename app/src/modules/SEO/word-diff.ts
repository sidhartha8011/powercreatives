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

/** A whole block shown as ADDED — lists mark per <li> (text directly inside
 *  <ul> is invalid and the editor would drop it); other structural blocks
 *  render verbatim (the resolved states carry the truth, never this view). */
const addedBlock = (b: HTMLElement): string => {
  const tag = b.tagName.toLowerCase();
  if (tag === 'ul' || tag === 'ol') {
    const el = b.cloneNode(true) as HTMLElement;
    el.querySelectorAll('li').forEach((li) => {
      li.innerHTML = `<span data-diff-added="1">${escapeHtml((li.textContent ?? '').replace(/\s+/g, ' ').trim())}</span>`;
    });
    return el.outerHTML;
  }
  if (isTextTag(tag)) return `<${tag}><span data-diff-added="1">${escapeHtml(blockText(b))}</span></${tag}>`;
  return b.outerHTML;
};

/** A whole block shown as REMOVED — always a valid paragraph wrapper. */
const removedBlock = (b: HTMLElement): string =>
  `<p><span data-diff-removed="1">${escapeHtml(blockText(b))}</span></p>`;

/**
 * The inline red/green VIEW of one section: original vs AI blocks paired 1:1
 * by order; SAME-TAG text blocks (p/h) diff word-wise inside their tag —
 * structural pairs (list↔paragraph etc.) render as a removed line + the new
 * block with valid added marks, never text nodes inside list wrappers.
 * (Formatting shows plain during review — Accept applies the AI's clean
 * HTML, Reject restores the original verbatim; this view is never kept.)
 */
export function diffBlocksHtml(originalHtml: string, aiHtml: string): string {
  const oldBlocks = parseBlocks(originalHtml);
  const newBlocks = parseBlocks(aiHtml);
  const out: string[] = [];
  const count = Math.max(oldBlocks.length, newBlocks.length);
  for (let k = 0; k < count; k++) {
    const o = oldBlocks[k];
    const nw = newBlocks[k];
    if (o && nw) {
      const oTag = o.tagName.toLowerCase();
      const nTag = nw.tagName.toLowerCase();
      const oText = blockText(o);
      const nText = blockText(nw);
      if (oText === nText && oTag === nTag) {
        out.push(nw.outerHTML); // untouched block — keep its real formatting
      } else if (oTag === nTag && isTextTag(nTag)) {
        out.push(`<${nTag}>${runsToHtml(wordDiff(oText, nText))}</${nTag}>`);
      } else {
        out.push(removedBlock(o) + addedBlock(nw));
      }
    } else if (nw) {
      out.push(addedBlock(nw));
    } else if (o) {
      out.push(removedBlock(o));
    }
  }
  return out.join('');
}

/** The save guard: removed runs die, added runs unwrap — marks NEVER persist. */
export function stripDiffHtml(html: string): string {
  const el = document.createElement('div');
  el.innerHTML = html;
  el.querySelectorAll('span[data-diff-removed]').forEach((s) => s.remove());
  el.querySelectorAll('span[data-diff-added]').forEach((s) => s.replaceWith(...Array.from(s.childNodes)));
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
