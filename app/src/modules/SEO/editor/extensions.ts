/**
 * THE EDITOR'S TIPTAP EXTENSIONS (decomposition S1, gap 325d280): the six
 * schema/decoration extensions the page editor mounts — moved verbatim
 * from SectionModal.tsx. Pure module-level definitions; the component
 * talks to them only through editor storage (flash/quote/review chips).
 */

import Image from '@tiptap/extension-image';
import { Extension, Mark, Node } from '@tiptap/core';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';
import type { Node as PMNode } from '@tiptap/pm/model';

/** Page-mode images: locked context — visible, atomic, never draggable; the
 *  hub strips every image from saves (F9 law), so the live page's images are
 *  untouched by construction. `data-pcm-locked` survives the round-trip only
 *  to style the lock. */
export const LockedImage = Image.extend({
  draggable: false,
  addAttributes() {
    return {
      ...this.parent?.(),
      'data-pcm-locked': { default: null },
      // Platform-added images (delivered into the client's own media library)
      // — the ONLY images that survive a save (marker + host, server-verified).
      'data-pcm-added': { default: null },
    };
  },
});

/** AI-review marks (page mode only): PRESENTATION of the inline diff — green
 *  added, red strikethrough removed. Never persisted: every page save runs
 *  stripDiffHtml first (a guard, not a convention). */
export const DiffAdded = Mark.create({
  name: 'diffAdded',
  parseHTML() { return [{ tag: 'span[data-diff-added]' }]; },
  // ONE green (owner 2026-07-13): the same green as the shared save/Accept
  // buttons — never a second green. Since card 11 that green is the shared
  // `success` token; the tint marks added text, the text keeps its own colour.
  renderHTML() { return ['span', { 'data-diff-added': '1', class: 'rounded-sm bg-success/15' }, 0]; },
});
export const DiffRemoved = Mark.create({
  name: 'diffRemoved',
  parseHTML() { return [{ tag: 'span[data-diff-removed]' }]; },
  renderHTML() { return ['span', { 'data-diff-removed': '1', class: 'rounded-sm bg-destructive/10 line-through decoration-destructive' }, 0]; },
});

/** SECTION BLOCKS (page mode, owner UX ruling 2026-07-13): every section is
 *  drawn as ONE always-visible bordered block — no hover states, no active
 *  highlight, no rails; a static structure the eye reads in one pass. The
 *  block's 3px LEFT EDGE carries the origin (slate = the site's own content,
 *  amber = platform-edited, sky = platform-added; a heading with no origin
 *  attribute — typed or pasted this session — is platform-added by
 *  definition). Pure ProseMirror decorations — they can never enter a save
 *  by construction. Blocks ABOVE the first heading are the server-refused
 *  dead zone and lock (contenteditable=false) so the refusal can never be
 *  reached; a doc with NO headings locks nothing — wiped pages must accept
 *  typing. The origin rides as a heading attribute (survives edits and
 *  reordering): the server emits it on load and strips it from every save.
 *  Version-row loads carry no origins, so their sections draw sky — a saved
 *  version IS platform-authored content, the color states a fact. */
const BLOCK_ORIGIN_CLASS: Record<string, string> = {
  original: 'pcm-blk-original',
  owned: 'pcm-blk-owned',
  insert: 'pcm-blk-added',
};
function blockDecorations(doc: PMNode, flash: number | null, quote: { from: number; to: number } | null = null): DecorationSet {
  const blocks: Array<{ pos: number; end: number; heading: boolean; origin: string | null }> = [];
  doc.forEach((node, pos) => {
    blocks.push({
      pos,
      end: pos + node.nodeSize,
      heading: node.type.name === 'heading',
      origin: node.type.name === 'heading' ? ((node.attrs['data-pcm-origin'] as string | null) ?? null) : null,
    });
  });
  const ranges: Array<{ from: number; to: number; origin: string }> = [];
  for (const b of blocks) {
    if (b.heading) ranges.push({ from: b.pos, to: b.end, origin: b.origin ?? 'new' });
    else if (ranges.length > 0) ranges[ranges.length - 1].to = b.end;
  }
  if (ranges.length === 0) return DecorationSet.empty;
  const decos: Decoration[] = [];
  for (const b of blocks) {
    if (b.end <= ranges[0].from) {
      decos.push(Decoration.node(b.pos, b.end, { class: 'pcm-deadzone', contenteditable: 'false' }));
    }
  }
  ranges.forEach((r, ri) => {
    const origin = BLOCK_ORIGIN_CLASS[r.origin] ?? 'pcm-blk-added';
    // FOCUS FLASH (owner 2026-07-13): a clicked rail card scrolls here and the
    // section pulses (dark-blue lane + soft wash) so you always see the landing.
    const pulse = ri === flash ? ' pcm-blk-flash' : '';
    const inside = blocks.filter((b) => b.pos >= r.from && b.end <= r.to);
    inside.forEach((b, i) => {
      const role = `${i === 0 ? ' pcm-blk-start' : ''}${i === inside.length - 1 ? ' pcm-blk-end' : ''}`;
      decos.push(Decoration.node(b.pos, b.end, { class: `pcm-blk ${origin}${role}${pulse}` }));
    });
  });
  // The quote highlight (gap 0a0a3c3): one inline decoration on the exact
  // words a hovered change card produced.
  if (quote && quote.to > quote.from) {
    decos.push(Decoration.inline(quote.from, quote.to, { class: 'pcm-quote-flash' }));
  }
  return DecorationSet.create(doc, decos);
}
export const SectionBlocks = Extension.create({
  name: 'pcmSectionBlocks',
  addStorage() {
    return { flash: null as number | null, quote: null as { from: number; to: number } | null };
  },
  addGlobalAttributes() {
    return [
      {
        types: ['heading'],
        attributes: {
          'data-pcm-origin': {
            default: null,
            keepOnSplit: false,
            parseHTML: (el: HTMLElement) => el.getAttribute('data-pcm-origin'),
            renderHTML: (attrs: Record<string, unknown>) =>
              attrs['data-pcm-origin'] ? { 'data-pcm-origin': String(attrs['data-pcm-origin']) } : {},
          },
          // REVIEW IDENTITY (2026-07-14): a section is identified by this
          // anchor, never by counting headings — the count changes when the
          // AI legitimately ADDS sections mid-review (subtopic coverage) and
          // counted indexes then write to shifted ranges. Stamped at review
          // start, kept alive by the one writer, removed at review end
          // (+ server strip belt) — it can never reach a save.
          'data-pcm-review-id': {
            default: null,
            keepOnSplit: false,
            parseHTML: (el: HTMLElement) => el.getAttribute('data-pcm-review-id'),
            renderHTML: (attrs: Record<string, unknown>) =>
              attrs['data-pcm-review-id'] != null ? { 'data-pcm-review-id': String(attrs['data-pcm-review-id']) } : {},
          },
        },
      },
    ];
  },
  addProseMirrorPlugins() {
    const ext = this;
    return [
      new Plugin({
        key: new PluginKey('pcmSectionBlocks'),
        props: {
          decorations: (state) => blockDecorations(state.doc, ext.storage.flash, ext.storage.quote),
        },
      }),
    ];
  },
});

/** INLINE REVIEW CONTROLS (owner-picked GitHub/Cursor pattern, 2026-07-13):
 *  during an AI review every CHANGED section carries its own floating
 *  ✓ Accept / ✕ chip at the top-right of its heading — you decide where you
 *  read; the rail stays as overview + bulk. Widget decorations only (never
 *  content); handlers ride the SAME resolveSection the rail uses. */
export const ReviewControls = Extension.create({
  name: 'pcmReviewControls',
  addStorage() {
    return {
      sections: [] as string[],
      resolve: null as null | ((i: number, action: 'accept' | 'reject') => void),
      revise: null as null | ((i: number) => void),
    };
  },
  addProseMirrorPlugins() {
    const ext = this;
    return [
      new Plugin({
        key: new PluginKey('pcmReviewControls'),
        props: {
          decorations: (state) => {
            const { sections, resolve } = ext.storage;
            if (!sections.length || !resolve) return DecorationSet.empty;
            const decos: Decoration[] = [];
            state.doc.forEach((node, pos) => {
              if (node.type.name !== 'heading') return;
              // Identity law (2026-07-14): the chip belongs to the heading's
              // ANCHOR, never to its ordinal — the count changes mid-review.
              const rid = (node.attrs['data-pcm-review-id'] as string | null) ?? null;
              if (rid === null) return;
              const i = Number(rid);
              if (sections[i] !== 'diff') return;
              decos.push(Decoration.widget(pos + 1, () => {
                const wrap = document.createElement('span');
                wrap.className = 'pcm-review-chip';
                const mk = (label: string, cls: string, title: string, onClick: () => void) => {
                  const b = document.createElement('button');
                  b.type = 'button';
                  b.textContent = label;
                  b.className = cls;
                  b.title = title;
                  b.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    onClick();
                  });
                  return b;
                };
                wrap.append(
                  mk('✓ Accept', 'pcm-chip-accept', 'Keep this section as it reads right now (your edits included)', () => ext.storage.resolve?.(i, 'accept')),
                  mk('↻ Revise', 'pcm-chip-revise', 'Send this section back to the AI with an adjustment', () => ext.storage.revise?.(i)),
                  mk('✕', 'pcm-chip-ghost', 'Keep the original', () => ext.storage.resolve?.(i, 'reject')),
                );
                return wrap;
              }, { side: 1 }));
            });
            return DecorationSet.create(state.doc, decos);
          },
        },
      }),
    ];
  },
});

/** FAQ BLOCK (native <details>/<summary>): the browser provides the
 *  accordion — ZERO JavaScript ships to client sites, and both tags survive
 *  content sanitization on the hub AND the connector (core-allowlisted,
 *  verified). The skeleton carries STRUCTURAL inline styles only —
 *  border/spacing/weight, sanitizer-safe, no CSS functions — while fonts
 *  and colors INHERIT from the site: that inheritance is what makes it look
 *  native anywhere. To the engine it is ordinary section content. */
export const FaqItem = Node.create({
  name: 'faqItem',
  group: 'block',
  content: 'faqSummary block+',
  defining: true,
  addAttributes() { return { style: { default: null } }; },
  parseHTML() { return [{ tag: 'details' }]; },
  // Serialized WITHOUT `open` — the LIVE page starts COLLAPSED (a real
  // accordion). The node view below is the EDITOR's display: always open,
  // fold neutralized — FAQ items edit as plain visible text (owner ruling).
  renderHTML({ HTMLAttributes }) { return ['details', HTMLAttributes, 0]; },
  addNodeView() {
    return ({ node }) => {
      const dom = document.createElement('details');
      if (node.attrs.style) dom.setAttribute('style', String(node.attrs.style));
      dom.open = true;
      dom.addEventListener('toggle', () => { if (!dom.open) dom.open = true; });
      return {
        dom,
        contentDOM: dom,
        update: (n) => {
          if (n.type.name !== 'faqItem') return false;
          if (n.attrs.style) dom.setAttribute('style', String(n.attrs.style));
          else dom.removeAttribute('style');
          dom.open = true;
          return true;
        },
      };
    };
  },
});
export const FaqSummary = Node.create({
  name: 'faqSummary',
  content: 'inline*',
  defining: true,
  addAttributes() { return { style: { default: null } }; },
  parseHTML() { return [{ tag: 'summary' }]; },
  renderHTML({ HTMLAttributes }) { return ['summary', HTMLAttributes, 0]; },
  addKeyboardShortcuts() {
    return {
      // Enter inside a question moves into its answer — a summary never splits.
      Enter: () => {
        const { $from } = this.editor.state.selection;
        if ($from.parent.type.name !== 'faqSummary') return false;
        return this.editor.commands.setTextSelection($from.after() + 1);
      },
    };
  },
});
