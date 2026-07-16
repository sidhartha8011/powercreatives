/**
 * SectionModal — the floating section editor (owner-spec v2, 2026-07-09).
 *
 * EXACTLY the owner's sketch — one constant shape, nothing else:
 *
 *   ┌──────────────────────────────────────┐
 *   │ ¶ Heading……            [Ask AI] [Re-write] [X] │  ← draggable header
 *   ├──────────────────────────────────────┤
 *   │ (optional Ask-AI instruction input)  │
 *   │ formatted, DIRECTLY editable text    │  ← fixed-height TipTap, white
 *   │ [B][I][U][Link] [H1][H2][•]          │  ← persistent toolbar
 *   │ [✓ Save]  [↶ Undo]             │
 *   └──────────────────────────────────────┘
 *
 * Laws (owner-set): opens BELOW the clicked row · editable on FIRST click
 * (no Edit button, no read mode, the shape never changes) · white background,
 * compact text · Save OR clicking outside SAVES · Undo restores the
 * last saved state · Esc closes without saving.
 *
 * SECTION IDENTITY (the root-cause fix): the paragraphs this modal saves
 * against come from the CONNECTOR SCAN's anchors — the same section
 * definition the serving engine verifies — never from display layout.
 * See DYNAMIC-OPTIMIZATION-ARCHITECTURE.md → "Section membership".
 *
 * Saving creates/updates ONE dynamic rule (UPSERT; editing back to the
 * original deletes it; push-fail rolls back — phase-1 engine). LOCAL tab =
 * read-only formatted view (no rule engine on the hub's own site).
 *
 * PAGE MODE (full-page editor V1, 2026-07-10): the SAME component maximized
 * (~90vw/85vh, centered) — self-fetches the inventory's `contentHtml` (served
 * content region, hub-assembled) and saves the whole document through
 * `seo.remoteSavePageEdits`, which slices it back into sections server-side
 * and routes each change through the EXISTING rule paths. Images render as
 * locked context (never persisted — the live page's images are untouched
 * between-content by construction). Versions + Ask AI are section-mode only
 * (V2 brings AI to page mode). Section/insert behavior is byte-identical.
 */

import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useEditor, EditorContent } from '@tiptap/react';
import { BubbleMenu } from '@tiptap/react/menus';
import { Extension, Mark, Node, createNodeFromContent, getHTMLFromFragment, type Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Image from '@tiptap/extension-image';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';
import { Fragment, type Node as PMNode } from '@tiptap/pm/model';
import {
  X, Sparkles, Loader2, Check, Undo2, Trash2, MessageSquarePlus,
  BoldIcon, ItalicIcon, UnderlineIcon, Link as LinkIcon,
  Heading1, Heading2, List, ExternalLink, Save, ImagePlus, MessageCircleQuestion, FileText, Eye, Plus, ScanSearch, KeyRound,
} from 'lucide-react';
import { toast } from 'sonner';

import { trpc } from '@/lib/trpc';
import { ModelDropdown, PillButton, PillSplitButton } from '@/components/shared';
import { useTextModels } from '@/modules/Copy/useTextModels';
import {
  diffBlocksHtml, splitDocSections, splitReviewSections, stripDiffHtml, type DocSection,
} from './word-diff';
import { OptimizerRail } from './optimizer/OptimizerRail';
import { KeywordsDrawer, type TickedKeyword } from './optimizer/KeywordsDrawer';
import { useKeywordBucket } from './optimizer/useKeywordBucket';
import { keywordUses } from './optimizer/keywordStats';
import { GROUP_PILLS, TEACHER_PILLS, type CompiledDirective, type TeacherMeta } from './optimizer/types';
import { Select, SelectContent, SelectItem, SelectTrigger } from '@/components/ui/select';
import { Pill } from '@/components/ui/pill';
import { statusPillVariant } from './types';
import { DROPDOWN_TRIGGER_STYLE } from '@/components/shared/ModelDropdown';

// The hub's native WP media library (wp_enqueue_media — same pattern as the
// table's featured-image picker).
declare const wp: any;

/** One paragraph of the section, from the SCAN (original text = rule identity). */
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

export interface SectionModalProps {
  siteId: number | 'local';
  postId: number;
  type: 'post' | 'page';
  model?: string;
  provider?: string;
  /** Local tab: read-only formatted view. */
  readOnly: boolean;
  mode: 'section' | 'insert' | 'page';
  section?: SectionData;
  insert?: InsertData;
  /** Page mode: the row's title, publish date (the Original row's label),
   *  the WP-editor escape hatch, the live permalink + the inline preview
   *  opener (the table's own preview window). */
  page?: {
    title: string; editUrl?: string; date?: string; permalink?: string; onPreview?: () => void;
    /** The row's keyword fields — the keyword drawer's initial values. */
    primaryKeyword?: string; supportingKeyword?: string;
    /** The row's WP status — drafts open as previews, never dead links. */
    status?: string;
  };
  /** The site's pages — the keyword drawer's page picker (the table's rows). */
  sitePages?: Array<{ id: number; title: string; permalink: string }>;
  /** Anchor choices when creating a NEW section. */
  anchors?: SectionAnchor[];
  /** Where the user clicked — the window opens right below it. */
  anchorPoint?: { x: number; y: number };
  onClose: () => void;
  onSaved: () => void;
}

const WIDTH = 440;

/** The page card's effective width — ONE source of truth. Provably equal
 *  to the former width/minWidth/maxWidth trio (CSS resolves width → the
 *  max-width cap → the min-width floor, and min-width wins). While the
 *  keyword drawer is open the card TAPERS by a fixed amount (owner UX
 *  2026-07-14) and tapers back on close — a plain width transition; the
 *  drawer/card PAIR is centered by the page-mode flex wrapper, so no
 *  anchor math exists anywhere. */
const PAGE_CARD_WIDTH = 'max(720px, min(980px, 94vw, 100vw - 32px))';
const PAGE_CARD_TAPER_PX = 280;
const pageCardWidth = (tapered: boolean): string =>
  (tapered ? `calc(${PAGE_CARD_WIDTH} - ${PAGE_CARD_TAPER_PX}px)` : PAGE_CARD_WIDTH);

/** Page mode's OWN reading scale (owner order U2): the document must read
 *  like the live page — real paragraph air, stepped heading sizes — while
 *  section mode keeps the compact scale above, byte-identical. */
const PAGE_TYPE_SCALE =
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

/** Page-mode images: locked context — visible, atomic, never draggable; the
 *  hub strips every image from saves (F9 law), so the live page's images are
 *  untouched by construction. `data-pcm-locked` survives the round-trip only
 *  to style the lock. */
const LockedImage = Image.extend({
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
const DiffAdded = Mark.create({
  name: 'diffAdded',
  parseHTML() { return [{ tag: 'span[data-diff-added]' }]; },
  // ONE green (owner 2026-07-13): the same green-600 family as the shared
  // save/Accept buttons — never a second green.
  renderHTML() { return ['span', { 'data-diff-added': '1', class: 'rounded-sm bg-green-600/15 text-green-800' }, 0]; },
});
const DiffRemoved = Mark.create({
  name: 'diffRemoved',
  parseHTML() { return [{ tag: 'span[data-diff-removed]' }]; },
  renderHTML() { return ['span', { 'data-diff-removed': '1', class: 'rounded-sm bg-red-50 text-red-800 line-through decoration-red-400' }, 0]; },
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
function blockDecorations(doc: PMNode, flash: number | null): DecorationSet {
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
  return DecorationSet.create(doc, decos);
}
const SectionBlocks = Extension.create({
  name: 'pcmSectionBlocks',
  addStorage() {
    return { flash: null as number | null };
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
          decorations: (state) => blockDecorations(state.doc, ext.storage.flash),
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
const ReviewControls = Extension.create({
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
const FaqItem = Node.create({
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
const FaqSummary = Node.create({
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
const FAQ_ITEM_STYLE = 'border:1px solid #dcdcdc;border-radius:8px;padding:10px 16px;margin:10px 0';
const FAQ_SUMMARY_STYLE = 'font-weight:600;cursor:pointer';
/** The inserted skeleton — a normal section (heading + items) the user edits in place. */
const faqTemplate = (): string =>
  '<h2>Frequently asked questions</h2>' +
  [1, 2, 3].map((n) =>
    `<details style="${FAQ_ITEM_STYLE}"><summary style="${FAQ_SUMMARY_STYLE}">Question ${n}</summary><p>Answer ${n}.</p></details>`,
  ).join('');

/** The blocks' looks, scoped to the page editor: one continuous bordered
 *  block per section (start/middle/end roles join their borders; inter-block
 *  margins become inner padding so the box never breaks), 3px origin left
 *  edge, air between sections. Images pause the side borders (they keep
 *  their natural width) but keep the origin edge. Summary markers hidden +
 *  fold cursor neutralized: FAQ items EDIT as plain open text. */
const BLOCK_STYLES =
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
  // Inline review chips: float on the changed section's first line. Hover
  // law (owner 2026-07-13): SELF-hover only (`.pcm-chip-x:hover`, never a
  // container-hover — that darkened every chip from anywhere in the text)
  // and hover LIGHTENS. Colors state the action: green = keep (save
  // family), blue = asks the AI (generate family), grey ghost = decline.
  '[&_.pcm-review-chip]:float-right [&_.pcm-review-chip]:ml-2 [&_.pcm-review-chip]:inline-flex [&_.pcm-review-chip]:gap-1 [&_.pcm-review-chip]:align-middle ' +
  '[&_.pcm-review-chip_button]:rounded-full [&_.pcm-review-chip_button]:px-2 [&_.pcm-review-chip_button]:py-0.5 [&_.pcm-review-chip_button]:text-[10px] ' +
  '[&_.pcm-chip-accept]:bg-green-600 [&_.pcm-chip-accept]:font-medium [&_.pcm-chip-accept]:text-white [&_.pcm-chip-accept:hover]:bg-green-500 ' +
  '[&_.pcm-chip-revise]:border [&_.pcm-chip-revise]:border-primary/40 [&_.pcm-chip-revise]:bg-white [&_.pcm-chip-revise]:text-primary [&_.pcm-chip-revise:hover]:bg-[#e7f5ff] ' +
  '[&_.pcm-chip-ghost]:border [&_.pcm-chip-ghost]:border-slate-200 [&_.pcm-chip-ghost]:bg-white [&_.pcm-chip-ghost]:text-slate-500 [&_.pcm-chip-ghost:hover]:bg-slate-50';

/** One section under AI review. pending/diff block saving; the rest are resolved. */
type ReviewStatus = 'pending' | 'diff' | 'accepted' | 'rejected' | 'clean' | 'failed';
interface ReviewSection extends DocSection {
  status: ReviewStatus;
  ai?: string;
  error?: string;
  /** What ACTUALLY generated this suggestion — the API's own report. */
  genModel?: string;
  /** The landed diff VIEW read back from the editor (same serializer as the
   *  live compare) — effectiveContent's untouched-detector: live == baseline
   *  ⇔ the user typed nothing in this section since the suggestion landed. */
  baseline?: string;
}

/** Visible text of an HTML fragment (whitespace-collapsed) — clean-result check. */
function htmlText(html: string): string {
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
function canonicalAiHtml(editor: Editor, html: string): string {
  if (html === '') return '';
  const content = createNodeFromContent(html, editor.schema, { parseOptions: { preserveWhitespace: false } });
  return getHTMLFromFragment(Fragment.from(content), editor.schema);
}

/** Restate a section's lane identity on the content that will be KEPT.
 *  The pre-review capture (`sourceHtml`) is the only reliable origin carrier
 *  — the AI's clean value carries no attributes. Accepting a CHANGED section
 *  flips `original` → `owned`, so the lane reads amber the moment it lands
 *  (owner law: amber = edited, sky = added — matches what the server emits
 *  on the next load). `insert` stays platform-added; a version-loaded doc
 *  carries no origins and keeps stating that fact. */
/** Stamp the review anchor onto the FIRST heading of a section's html.
 *  Called only by the ONE writer — identity survives every landing and
 *  resolution no matter how many headings the content carries. */
function stampReviewId(html: string, i: number): string {
  const el = document.createElement('div');
  el.innerHTML = html;
  const heading = el.querySelector('h1,h2,h3,h4,h5,h6');
  if (heading === null) return html;
  heading.setAttribute('data-pcm-review-id', String(i));
  return el.innerHTML;
}

function restateOrigin(html: string, sourceHtml: string, changed: boolean): string {
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
function normSrc(src: string): string {
  const el = document.createElement('textarea');
  el.innerHTML = src;
  return el.value.trim();
}

/** The selected image's identity + attrs as found (= originals when unruled). */
interface ImgSelection {
  src: string;
  occurrence: number;
  alt: string;
  title: string;
}
/** Compact readable scale (no `prose` plugin in this build). */
const TYPE_SCALE =
  'text-xs leading-relaxed text-slate-800 break-words ' +
  '[&_h1]:text-sm [&_h1]:font-semibold [&_h1]:mt-2 [&_h1]:mb-1 ' +
  '[&_h2]:text-sm [&_h2]:font-semibold [&_h2]:mt-2 [&_h2]:mb-1 ' +
  '[&_h3]:text-xs [&_h3]:font-semibold [&_h3]:mt-1.5 [&_h3]:mb-0.5 ' +
  '[&_h4]:text-xs [&_h4]:font-medium [&_h4]:mt-1.5 [&_h4]:mb-0.5 ' +
  '[&_p]:my-1 [&_ul]:my-1 [&_ul]:pl-4 [&_ul]:list-disc [&_ol]:my-1 [&_ol]:pl-4 [&_ol]:list-decimal ' +
  '[&_li]:my-0.5 [&_a]:text-primary [&_a]:underline [&_a]:decoration-dotted';

/** The section's CURRENT html: served rule > original with paragraph rules folded in. */
export function composeSectionHtml(section: SectionData): string {
  if (section.sectionRuleReplacement) return section.sectionRuleReplacement;
  const h = section.heading.html
    || `<h${section.heading.level}>${escapeHtml(section.heading.text)}</h${section.heading.level}>`;
  return h + section.paragraphs
    .map((p) => (p.servedHtml != null ? `<p>${p.servedHtml}</p>` : p.html))
    .join('');
}

function escapeHtml(s: string): string {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/** Strip the platform's transient heading anchors (origin colors, review ids)
 *  from stored html — a saved version opens as plain content, exactly what a
 *  dropdown version pick shows (the server strips them on save; this is the
 *  belt for any row that predates that law). */
function stripPcmAnchors(html: string): string {
  return html.replace(/\s*data-pcm-(?:origin|review-id)="[^"]*"/g, '');
}

function ToolButton({ onClick, active, title, children }: {
  onClick: () => void; active?: boolean; title: string; children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      onMouseDown={(e) => e.preventDefault() /* keep the selection */}
      onClick={onClick}
      title={title}
      className={`rounded p-1 transition-colors hover:bg-slate-100 ${active ? 'bg-slate-100 text-slate-900' : 'text-slate-500'}`}
    >
      {children}
    </button>
  );
}

export function SectionModal({
  siteId, postId, type, model, provider, readOnly, mode, section, insert, page, sitePages, anchors, anchorPoint, onClose, onSaved,
}: SectionModalProps) {
  const isInsert = mode === 'insert';
  const isPage = mode === 'page';
  const rootRef = useRef<HTMLDivElement>(null);

  // ── Page mode: self-fetch the hub-assembled served content document. ──
  const pageQuery = trpc.seo.remoteGetInventory.useQuery(
    { siteId: siteId as number, postId, type },
    { enabled: isPage && !readOnly, staleTime: 0, refetchOnMount: 'always' },
  );
  const pageHtml = isPage ? String((pageQuery.data as any)?.contentHtml ?? '') : '';
  const pageReady = isPage && (pageQuery.data as any)?.view === 'served' && pageHtml !== '';
  const pageError = isPage && !pageQuery.isLoading && !pageReady
    ? ((pageQuery.data as any)?.error === 'loopback_blocked'
      ? 'Page editing unavailable — the site blocked the connector’s content fetch.'
      : 'Page editing needs the served page view (connector 3.0.1+ on this site) — update it from the Sites module, then re-open.')
    : null;
  // Page versions: saved page documents + the true no-rules Original
  // (rules-input snapshot, hub-assembled) — one read.
  const pageVersionsQuery = trpc.seo.remotePageVersions.useQuery(
    { siteId: siteId as number, postId },
    { enabled: isPage && !readOnly, staleTime: 0 },
  );
  /** Page mode's saved documents, newest first — W0 (2026-07-16): the open
   *  loads [0]; the versions dropdown lists the same rows. */
  const pageVersions: Array<{ id: number; replacement: string; createdAt: string }> =
    isPage && Array.isArray((pageVersionsQuery.data as any)?.versions)
      ? (pageVersionsQuery.data as any).versions
      : [];
  /** Settled = answered, either way — the one-time load must not wait forever
   *  on a failed versions read (the assembly is then the honest fallback). */
  const pageVersionsSettled = pageVersionsQuery.isSuccess || pageVersionsQuery.isError;
  /** W4 drift contract (2026-07-16, optional until every side ships): the
   *  inventory reply may carry pageState {version, fingerprint, drifted} —
   *  the hub compared the site's echoed state against its own record.
   *  No pageState → no drift knowledge → nothing is claimed. */
  const pageDrifted = isPage && (pageQuery.data as any)?.pageState?.drifted === true;

  // ── Position: right below the click, draggable from the header. ──
  const [pos, setPos] = useState(() => ({
    x: Math.min(Math.max(8, (anchorPoint?.x ?? 120)), Math.max(8, window.innerWidth - WIDTH - 12)),
    y: Math.min(Math.max(8, (anchorPoint?.y ?? 80) + 6), Math.max(8, window.innerHeight - 200)),
  }));
  const dragRef = useRef<{ dx: number; dy: number } | null>(null);
  const onDragStart = (e: React.PointerEvent) => {
    if ((e.target as HTMLElement).closest('button')) return; // buttons click, never drag
    dragRef.current = { dx: e.clientX - pos.x, dy: e.clientY - pos.y };
    (e.target as HTMLElement).setPointerCapture?.(e.pointerId);
  };
  const onDragMove = (e: React.PointerEvent) => {
    if (!dragRef.current) return;
    setPos({
      x: Math.min(Math.max(8, e.clientX - dragRef.current.dx), window.innerWidth - 120),
      y: Math.min(Math.max(8, e.clientY - dragRef.current.dy), window.innerHeight - 60),
    });
  };
  const onDragEnd = () => { dragRef.current = null; };

  // ── Content: ONE state — the editor. `savedHtml` = last saved/opened state. ──
  const openedHtml = isInsert ? (insert?.replacement ?? '') : (section ? composeSectionHtml(section) : '');
  /** The TRUE original (no rules applied): section mode = live from the scan;
   *  page mode = the hub-assembled rules-input document ('' = honest unavailable). */
  const originalHtml = isPage
    ? String((pageVersionsQuery.data as any)?.originalHtml ?? '')
    : !isInsert && section
      ? (section.heading.html || `<h${section.heading.level}>${escapeHtml(section.heading.text)}</h${section.heading.level}>`)
        + section.paragraphs.map((p) => p.html).join('')
      : '';
  const [savedHtml, setSavedHtml] = useState(openedHtml);
  /** WHICH control is running (owner law 2026-07-13): every long action
   *  names itself here and only THAT control may look busy — the rest keep
   *  their resting face while `busy` still guards them functionally (one
   *  long action at a time, modal-wide; guarded clicks simply do nothing). */
  type BusyAction = 'save' | 'saveClose' | 'remove' | 'ai' | 'imageAdd' | 'imageSave' | 'imageHide' | 'imageRevert';
  const [busyAction, setBusyAction] = useState<BusyAction | null>(null);
  const busy = busyAction !== null;
  const [askOpen, setAskOpen] = useState(false);
  const [instruction, setInstruction] = useState('');
  const [position, setPosition] = useState<'before' | 'after'>(insert?.position ?? 'after');
  const [anchorIdx, setAnchorIdx] = useState<number>(() => Math.max(0, (anchors?.length ?? 1) - 1));

  const editor = useEditor({
    editable: !readOnly,
    extensions: [
      StarterKit.configure({
        // Page mode edits the whole served document — every legal level.
        heading: { levels: isPage ? [1, 2, 3, 4, 5, 6] : [1, 2, 3, 4] },
        link: {
          openOnClick: false, autolink: true, defaultProtocol: 'https',
          HTMLAttributes: { rel: 'noopener noreferrer', target: '_blank' },
        },
        codeBlock: false, blockquote: false, horizontalRule: false,
      }),
      ...(isPage ? [LockedImage, DiffAdded, DiffRemoved, SectionBlocks, ReviewControls, FaqItem, FaqSummary] : []),
    ],
    content: openedHtml,
    // Baseline for dirty-checks must be the EDITOR's normalized form of the
    // opened content (TipTap reorders attrs etc.) — otherwise an untouched
    // window would "save" on every outside click.
    onCreate: ({ editor: ed }) => setSavedHtml(ed.getHTML()),
    // Re-render on edits + selection moves: the Draft (unsaved) label and the
    // Optimize button's scope wording are live states.
    onUpdate: () => setEditorTick((t) => t + 1),
    onSelectionUpdate: () => setEditorTick((t) => t + 1),
  });
  const [, setEditorTick] = useState(0);
  /** Unsaved edits in page mode — the versions dropdown's Draft state. */
  const pageDirty = isPage && pageReady && !!editor && editor.getHTML() !== savedHtml;
  /** A real text selection — narrows the AI review's SCOPE (never its safety:
   *  every path shows red/green and waits for Accept/Reject). */
  const hasSelection = !!editor && !editor.state.selection.empty && !(editor.state.selection as any).node;

  // Page mode opens empty and loads its document ONCE (dirty-baseline = the
  // editor's normalized form of it, same law as onCreate). The ref guard is
  // the 2026-07-11 incident fix: the editor must NEVER auto-replace its
  // content afterwards — a degraded refetch once clobbered a full document
  // in front of the owner. Version picks load content explicitly.
  // W0 (2026-07-16): the SAVED version wins the open — versions[0] IS the
  // dropdown's Current row; the site-side assembly is the fallback only when
  // no saved versions exist (and the drift detector's reference either way).
  // The versions read may land after the page fetch, so the one-time load
  // waits for BOTH to settle.
  const pageLoadedRef = useRef(false);
  useEffect(() => {
    if (!isPage || !editor || !pageReady || !pageVersionsSettled || pageLoadedRef.current) return;
    pageLoadedRef.current = true;
    editor.commands.setContent(
      pageVersions.length > 0 ? stripPcmAnchors(pageVersions[0].replacement) : pageHtml,
    );
    setSavedHtml(editor.getHTML());
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isPage, editor, pageReady, pageVersionsSettled, pageHtml, pageVersions]);

  const saveMutation = trpc.seo.remoteSaveSectionRule.useMutation();
  const savePageMutation = trpc.seo.remoteSavePageEdits.useMutation();
  const optimizeMutation = trpc.seo.remoteOptimizeSection.useMutation();

  // ── AI model choice (owner order 2026-07-13): ONE dropdown in the modal;
  //    every AI action here uses it. Resolution: the user's saved pick →
  //    the prop-passed model → the FIRST registry model (registry = models
  //    with active keys, so the resolved model always WORKS — the old
  //    hardcoded server default pointed at a provider without a key and
  //    failed every call). Persisted per browser; visible in the select. ──
  const { textModels, groups: modelGroups } = useTextModels();
  const [aiModelId, setAiModelId] = useState<string>(() => {
    try { return localStorage.getItem('pcm-seo-ai-model') ?? ''; } catch { return ''; }
  });
  const aiPick = textModels.find((m) => m.id === aiModelId)
    ?? textModels.find((m) => m.id === model)
    ?? textModels[0];
  const pickAiModel = (id: string) => {
    setAiModelId(id);
    try { localStorage.setItem('pcm-seo-ai-model', id); } catch { /* private mode */ }
  };
  // THE shared picker (owner order: one dropdown everywhere — same component
  // Copy uses, never a bland native select). Never busy-disabled: the pick
  // is configuration for the NEXT run, harmless at any moment.
  const aiModelSelect = textModels.length > 0 ? (
    <ModelDropdown
      modelGroups={modelGroups}
      selectedModel={aiPick?.id ?? ''}
      onModelChange={pickAiModel}
    />
  ) : null;

  // ── Business + page type (owner order 2026-07-13): the site's linked brand
  //    feeds real business details (phone/address/…) into every AI run, and
  //    the page type tells the AI WHAT it's optimizing (local/blog/…). Both
  //    visible in the header — you SEE the context the AI actually gets. ──
  const brandsQuery = trpc.brands.list.useQuery(undefined, { enabled: isPage && !readOnly });
  const brands = Array.isArray(brandsQuery.data)
    ? (brandsQuery.data as any[]).map((b) => ({ id: Number(b.id), name: String(b.name) }))
    : [];
  const [brandId, setBrandId] = useState(0);
  const [pageType, setPageType] = useState('general');
  useEffect(() => {
    if (!isPage || !pageQuery.data) return;
    setBrandId(Number((pageQuery.data as any).brandId ?? 0));
    setPageType(String((pageQuery.data as any).pageType ?? '') || 'general');
  }, [isPage, pageQuery.data]);
  const [insertOpen, setInsertOpen] = useState(false);
  /** THE OPTIMIZER's rail (Analyze) — opening runs every teacher; closing
   *  discards the run (Analyze always means a FRESH analysis). */
  const [analyzeOpen, setAnalyzeOpen] = useState(false);
  // ── THE KEYWORD DRAWER (left side): primary/supporting live here so the
  //    drawer edits and the optimize runs read ONE state; the bucket rides
  //    EVERY run (owner law 2026-07-13). ──
  const [keywordsOpen, setKeywordsOpen] = useState(false);
  const [primaryKw, setPrimaryKw] = useState(page?.primaryKeyword ?? '');
  const [supportingKw, setSupportingKw] = useState(page?.supportingKeyword ?? '');
  const kwBucket = useKeywordBucket(typeof siteId === 'number' ? siteId : 0, postId, isPage && !readOnly);
  // THE BACKBONE (owner ruling 2026-07-15): the drawer's ticked keywords —
  // the selection every action button acts on. [] = no selection = act on
  // ALL keywords; the drawer reports [] when it closes.
  const [tickedKw, setTickedKw] = useState<TickedKeyword[]>([]);
  /** The drawer floats OUTSIDE the card — the outside-click save must know it. */
  const drawerRef = useRef<HTMLDivElement>(null);
  // The smart button's LIVE values — recomputed on the existing edit tick.
  const contentText = isPage && editor ? editor.getText() : '';
  const kwExtraCount = new Set([
    ...supportingKw.split(',').map((s) => s.trim()).filter(Boolean),
    ...kwBucket.keywords,
  ].filter((k) => k !== primaryKw.trim())).size;
  const primaryDensity = keywordUses(contentText, primaryKw).density;
  const brandMutation = trpc.sites.update.useMutation();
  const pageTypeMutation = trpc.seo.remoteSavePageType.useMutation();
  const pickBrand = (id: string) => {
    const n = Number(id);
    setBrandId(n);
    brandMutation.mutateAsync({ id: siteId, brandId: n })
      .then(() => toast.success(n > 0 ? 'Business linked — the AI now uses its details' : 'Business unlinked'))
      .catch((err: unknown) => toast.error(err instanceof Error ? err.message : 'Failed to link the business'));
  };
  const pickPageType = (t: string) => {
    setPageType(t);
    pageTypeMutation.mutateAsync({ siteId, postId, type: t === 'general' ? '' : t })
      .catch((err: unknown) => toast.error(err instanceof Error ? err.message : 'Failed to save the page type'));
  };
  const PAGE_TYPE_OPTIONS = [
    { id: 'general', name: 'General' },
    { id: 'local', name: 'Local search' },
    { id: 'blog', name: 'Blog article' },
    { id: 'product', name: 'Product' },
    { id: 'service', name: 'Service' },
    { id: 'landing', name: 'Landing page' },
  ];

  // ── Version history (replace-sections only — inserts have no Original). ──
  const versionsQuery = trpc.seo.remoteSectionVersions.useQuery(
    {
      siteId: siteId as number, postId,
      text: section?.heading.text ?? '', occurrence: section?.heading.occurrence ?? 0,
    },
    { enabled: !readOnly && !isInsert && !!section, staleTime: 0 },
  );
  const versions: Array<{ id: number; replacement: string; createdAt: string }> = isPage
    ? pageVersions
    : Array.isArray((versionsQuery.data as any)?.versions) ? (versionsQuery.data as any).versions : [];
  /** '' = viewing the current state; 'original' | version id as string. */
  const [versionPick, setVersionPick] = useState('');
  const [versionsOpen, setVersionsOpen] = useState(false);
  const deleteVersionMutation = trpc.seo.remoteDeleteSectionVersion.useMutation();
  const pickVersion = (v: string) => {
    setVersionPick(v);
    setVersionsOpen(false);
    if (v === 'original') editor?.commands.setContent(originalHtml);
    else if (v !== '') {
      const row = versions.find((x) => String(x.id) === v);
      if (row) editor?.commands.setContent(row.replacement);
    }
  };
  /** Page rows (owner order 2026-07-11): NO separate Current choice — the
   *  newest saved version IS what the site serves and carries the suffix;
   *  the Original row shows the page's own date. When the newest save IS a
   *  restore of the original, the label SAYS so (2026-07-13, live-caught:
   *  a perfect restore looked like "another version" and read as a bug). */
  const normDoc = (h: string) => h.replace(/\s*data-pcm-origin="[^"]*"/g, '').replace(/\s+/g, ' ').trim();
  const currentIsOriginal = versions.length > 0 && originalHtml !== ''
    && normDoc(String((versions[0] as any).replacement ?? '')) === normDoc(originalHtml);
  const pageRowLabel = (v: { createdAt: string }, idx: number) =>
    `${v.createdAt.slice(0, 16)}${idx === 0 ? (currentIsOriginal ? ' (Current — original)' : ' (Current)') : ''}`;
  const pageOriginalLabel = page?.date ? `${String(page.date).slice(0, 16)} (original)` : 'Original';
  /** The dropdown ALWAYS names a state (owner law — never a counter). */
  const hasActiveRule = !isInsert && !isPage && !!section?.sectionRuleReplacement;
  const versionLabel = versionPick === 'original'
    ? (isPage ? pageOriginalLabel : 'Original')
    : versionPick !== ''
      ? (isPage
        ? (versions.some((x) => String(x.id) === versionPick)
          ? pageRowLabel(
            versions[versions.findIndex((x) => String(x.id) === versionPick)],
            versions.findIndex((x) => String(x.id) === versionPick),
          )
          : 'Version')
        : (versions.find((v) => String(v.id) === versionPick)?.createdAt.slice(0, 16) ?? 'Version'))
      : isPage && pageDirty
        ? 'Draft (unsaved)'
      : isPage
        ? (versions.length > 0 ? pageRowLabel(versions[0], 0) : pageOriginalLabel)
        : (hasActiveRule && versions.length > 0 ? versions[0].createdAt.slice(0, 16) : 'Original');
  const deleteVersion = async (id: number) => {
    try {
      await deleteVersionMutation.mutateAsync({ siteId: siteId as number, postId, versionId: id });
      if (versionPick === String(id)) setVersionPick('');
      await (isPage ? pageVersionsQuery : versionsQuery).refetch();
    } catch (e: any) {
      toast.error(e?.message ?? 'Could not delete the version');
    }
  };
  // ── Bulk version cleanup (owner order 2026-07-16): tick many / select
  //    all, ONE delete. The CURRENT version (idx 0) is excluded from
  //    select-all and carries no checkbox — deleting what the editor opens
  //    from would recreate the old-version trap (W0). Sequential deletes
  //    (the bulk-status precedent), one refetch, one honest summary. ──
  const [versionSel, setVersionSel] = useState<Set<number>>(new Set());
  const [bulkDeleting, setBulkDeleting] = useState(false);
  const toggleVersionSel = (id: number) => setVersionSel((cur) => {
    const next = new Set(cur);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    return next;
  });
  const deletableVersionIds = versions.slice(1).map((v) => Number(v.id));
  const deleteSelectedVersions = async () => {
    const ids = Array.from(versionSel);
    if (ids.length === 0 || bulkDeleting) return;
    setBulkDeleting(true);
    let failed = 0;
    for (const id of ids) {
      try {
        await deleteVersionMutation.mutateAsync({ siteId: siteId as number, postId, versionId: id });
      } catch {
        failed++;
      }
    }
    setBulkDeleting(false);
    setVersionSel(new Set());
    if (ids.some((id) => versionPick === String(id))) setVersionPick('');
    await (isPage ? pageVersionsQuery : versionsQuery).refetch();
    if (failed > 0) toast.error(`${failed} of ${ids.length} versions could not be deleted — the rest are gone.`);
    else toast.success(`${ids.length} version${ids.length === 1 ? '' : 's'} deleted.`);
  };

  const isDirty = () => !readOnly && !!editor && editor.getHTML() !== savedHtml;

  // ── Save (save buttons / click outside): live rules, engine handles
  //    UPSERT/revert. `action` names the control that invoked it — that one
  //    alone shows the working state. ──
  const save = async (replacementOverride?: string, action: BusyAction = 'saveClose'): Promise<boolean> => {
    if (readOnly) return true;
    if (busy) return false; // one long action at a time — a guarded click is a no-op
    if (isPage) {
      if (!pageReady) return true; // nothing loaded — nothing to save
      if (review) {
        toast.info('Finish the AI review first — accept or reject each change.');
        return false;
      }
      // Guard: diff marks are presentation and must NEVER reach a save.
      const html = stripDiffHtml(replacementOverride ?? (editor?.getHTML() ?? ''));
      setBusyAction(action);
      try {
        // The hub slices the document back into sections and routes each
        // change through the existing rule paths — page-level editing,
        // section-level storage. Removals/hides are reversible via versions.
        const res: any = await savePageMutation.mutateAsync({ siteId: siteId as number, postId, html });
        onSaved();
        const parts: string[] = [];
        const n = (k: string) => Number(res?.[k] ?? 0);
        if (n('saved') > 0) parts.push(`${n('saved')} section${n('saved') === 1 ? '' : 's'} updated`);
        if (n('inserted') > 0) parts.push(`${n('inserted')} added`);
        if (n('removed') > 0) parts.push(`${n('removed')} removed`);
        if (n('restored') > 0) parts.push(`${n('restored')} restored`);
        if (n('hidden') > 0) parts.push(`${n('hidden')} image${n('hidden') === 1 ? '' : 's'} hidden`);
        if (n('unhidden') > 0) parts.push(`${n('unhidden')} image${n('unhidden') === 1 ? '' : 's'} back`);
        toast.success(parts.length > 0
          ? `Saved — ${parts.join(', ')} (page/CDN caches may need a purge).`
          : 'No content changes to save.');
        (Array.isArray(res?.notes) ? res.notes : []).forEach((nn: string) => toast.info(nn));
        setSavedHtml(html);
        setVersionPick('');
        // Versions list only — the editor content is NEVER auto-replaced
        // (incident fix 2026-07-11: a degraded refetch must not clobber the doc).
        void pageVersionsQuery.refetch();
        return true;
      } catch (e: any) {
        toast.error(e?.message ?? 'Could not save the page');
        return false;
      } finally {
        setBusyAction(null);
      }
    }
    if (!isInsert && !section) return true;
    const replacement = replacementOverride ?? (editor?.getHTML() ?? '');
    setBusyAction(action);
    try {
      let res: any;
      if (isInsert) {
        const anchor = insert?.ruleId
          ? { text: insert.anchorText, level: insert.anchorLevel, occurrence: insert.anchorOccurrence }
          : anchors?.[anchorIdx];
        if (!anchor) { toast.error('Pick a section to anchor the new one to.'); return false; }
        res = await saveMutation.mutateAsync({
          siteId: siteId as number, postId, kind: 'insert',
          anchorText: anchor.text, anchorLevel: anchor.level, anchorOccurrence: anchor.occurrence,
          position, replacement, ruleId: insert?.ruleId,
        });
      } else if (section?.slice) {
        // Rule-born section (served-truth): the edit splices the owning rule.
        res = await saveMutation.mutateAsync({
          siteId: siteId as number, postId, kind: 'slice',
          ruleId: section.slice.ruleId,
          unitFrom: section.slice.unitFrom,
          unitTo: section.slice.unitTo,
          replacement,
        });
      } else if (section) {
        res = await saveMutation.mutateAsync({
          siteId: siteId as number, postId, kind: 'replace',
          headingText: section.heading.text, // ALWAYS the scan's ORIGINAL — rule identity
          headingLevel: section.heading.level,
          headingOccurrence: section.heading.occurrence,
          // Identity = the SCAN's section membership (anchors) — the fix that
          // makes the serving-side verify agree with what we saved.
          paragraphs: section.paragraphs.map((p) => ({ text: p.text, occurrence: p.occurrence })),
          replacement,
        });
      }
      onSaved();
      if (res?.removed) toast.success('Section removed — the page serves without it again.');
      else if (res?.reverted) toast.success('Reverted — the original section serves again.');
      else toast.success('Saved — the site serves it now (page/CDN caches may need a purge).');
      setSavedHtml(replacement);
      setVersionPick('');
      if (!isInsert) void versionsQuery.refetch(); // the accepted state is a new version
      return true;
    } catch (e: any) {
      toast.error(e?.message ?? 'Could not save the section');
      return false;
    } finally {
      setBusyAction(null);
    }
  };

  // ── Click OUTSIDE = save (when changed) then close. Esc = close without saving. ──
  useEffect(() => {
    const onDown = (e: PointerEvent) => {
      const t = e.target as HTMLElement;
      if (rootRef.current?.contains(t)) return;
      if (drawerRef.current?.contains(t)) return; // the keyword drawer floats outside the card
      if (t.closest('[data-sonner-toaster]')) return; // toasts are not "outside"
      // Radix portals every popper to document.body — the drawer's role
      // menu and the column filter menus are INSIDE the editor by intent,
      // now also by law (the toast exception's exact precedent).
      if (t.closest('[data-radix-popper-content-wrapper]')) return;
      // And while ANY such menu is OPEN, Radix sets pointer-events:none on
      // the body — a click then targets bare <html>, outside every ref
      // (the owner's "random" closes). An open menu owns that click: it is
      // a menu dismissal, never an outside click.
      if (document.querySelector('[data-radix-popper-content-wrapper]')) return;
      if (isDirty()) void save().then((ok) => { if (ok) onClose(); });
      else onClose();
    };
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('pointerdown', onDown, true);
    window.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('pointerdown', onDown, true);
      window.removeEventListener('keydown', onKey);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [editor, savedHtml, busy, position, anchorIdx]);

  // ── AI: Re-write = whole-section rewrite into the editor; Ask AI adds an instruction. ──
  const runAi = async (withInstruction: string) => {
    if (busy) return;
    setBusyAction('ai');
    try {
      const current = editor?.getText().trim() ? (editor?.getHTML() ?? '') : '';
      const res: any = await optimizeMutation.mutateAsync({
        siteId: siteId as number, postId, type,
        html: current, topic: withInstruction,
        model: aiPick?.id ?? model, provider: aiPick?.provider ?? provider,
      });
      const value = String(res?.value ?? '').trim();
      if (value) { editor?.commands.setContent(value); setAskOpen(false); }
      else toast.info('The section already looks optimized.');
    } catch (e: any) {
      toast.error(e?.message ?? 'Could not run the AI on this section');
    } finally {
      setBusyAction(null);
    }
  };

  // ── AI review (page mode, V2): per-section rewrites shown as an inline
  //    red/green diff; Accept applies the AI's clean HTML, Reject restores the
  //    original — the diff view itself is never what gets kept. The editor is
  //    read-only while the review runs; saving is blocked until every section
  //    is resolved. Locked images are lifted out per section (never sent to
  //    the AI) and ride along untouched.
  const [review, setReview] = useState<ReviewSection[] | null>(null);
  // Live mirror for async workers (their results must be dropped when the
  // user already decided a section — e.g. OK'd the review mid-flight).
  const reviewRef = useRef<ReviewSection[] | null>(null);
  /** The compiled order behind the CURRENT review (basket runs only) — the
   *  purpose bullets + pills the user verifies suggestions against. Plain
   *  Optimize runs carry none, honestly. */
  const [runDirectives, setRunDirectives] = useState<CompiledDirective[] | null>(null);
  // Purpose → group/plain-name map for the review pills (same cached query
  // the rail uses; enabled only while a directive run is showing).
  const teachersMetaQuery = trpc.optimizer.teachers.useQuery(undefined, {
    staleTime: 60_000,
    enabled: isPage && runDirectives !== null,
  });
  const teacherById: Record<string, TeacherMeta> = Array.isArray((teachersMetaQuery.data as any)?.teachers)
    ? Object.fromEntries(((teachersMetaQuery.data as any).teachers as TeacherMeta[]).map((t) => [t.id, t]))
    : {};
  const historyStampMutation = trpc.optimizer.historyStamp.useMutation();
  // ── Status dropdown in the header (owner order 2026-07-15): the SAME
  //    control + save path as the table's status cell — optimistic by law,
  //    failure reverts AND says so. Draft honesty: the status is now
  //    changeable exactly where the draft/preview confusion happened. ──
  const [pageStatus, setPageStatus] = useState(page?.status ?? '');
  useEffect(() => { setPageStatus(page?.status ?? ''); }, [page?.status]);
  const statusMutation = trpc.seo.remoteSaveCell.useMutation();
  const pickStatus = (v: string) => {
    const prev = pageStatus;
    setPageStatus(v); // the UI moves NOW
    statusMutation.mutateAsync({ siteId: siteId as number, postId, field: 'status', value: v, type })
      .then(() => toast.success(v === 'publish' ? 'Published — saved changes now render on the live page.' : `Status: ${v}`))
      .catch((e: unknown) => {
        setPageStatus(prev); // revert to the truth
        toast.error(`Could not change the status — ${e instanceof Error ? e.message : 'the save failed'}`);
      });
  };
  // (the old whole-doc rebuilder's orphan buffer died with it — the orphan
  // zone is simply never touched by surgery)

  // ── SURGERY ENGINE (review-edit-revise, 2026-07-13): during a review the
  //    DOCUMENT is the single source of truth for content; review state owns
  //    only statuses + originals. ONE writer touches the doc — every
  //    transition (suggestion lands / accept / reject / revise result) goes
  //    through applySection on its OWN range. The old whole-doc rebuilder is
  //    dead: it overwrote the source of truth from stale copies, which would
  //    wipe the user's manual edits (and reset cursor/scroll on every event).
  /** Section i's live range BY ANCHOR (identity law 2026-07-14): from its
   *  id-stamped heading to the NEXT id-carrying heading — id-LESS headings
   *  (sections the AI added mid-review) belong to the section that produced
   *  them. Counting headings is banned here: the count changes mid-review. */
  const sectionRange = (i: number): { from: number; to: number } | null => {
    if (!editor) return null;
    const id = String(i);
    let from = -1;
    let to = -1;
    editor.state.doc.forEach((node, pos) => {
      if (node.type.name !== 'heading') return;
      const rid = (node.attrs['data-pcm-review-id'] as string | null) ?? null;
      if (from < 0) {
        if (rid === id) from = pos;
      } else if (to < 0 && rid !== null) {
        to = pos;
      }
    });
    if (from < 0) return null;
    return { from, to: to < 0 ? editor.state.doc.content.size : to };
  };
  const applySection = (i: number, html: string) => {
    const r = sectionRange(i);
    if (!editor || !r) return;
    // No .focus(): a landing suggestion must never steal the user's caret.
    // The anchor rides EVERY write — identity survives by construction.
    editor.chain().insertContentAt({ from: r.from, to: r.to }, stampReviewId(html, i)).run();
  };
  /** THE content oracle (review-integrity, 2026-07-13): what a section's
   *  decision keeps. UNTOUCHED since the suggestion landed (live == baseline,
   *  exact compare, same serializer both sides) → the AI's stored CLEAN
   *  `s.ai` — full formatting, links, lists, zero distortion for every
   *  element type. EDITED → the stripped live content: the user is rewriting
   *  that text and their words trump the AI's formatting (word-diffed
   *  paragraphs show plain — the named, bounded edge). Accept AND the Revise
   *  draft consume this one function — no second content path exists.
   *  Images ride separately: the live lifted set, or the captured one while
   *  none are live. */
  const effectiveContent = (i: number): { html: string; imgs: string } => {
    const s = reviewRef.current?.[i];
    const live = editor && s ? splitReviewSections(editor.getHTML())[String(i)] : undefined;
    const imgs = (live && live.imgs.length > 0 ? live.imgs : s?.imgs ?? []).join('');
    if (!s || !live) return { html: s?.ai ?? s?.html ?? '', imgs };
    const untouched = s.baseline !== undefined && live.html === s.baseline;
    return { html: untouched ? (s.ai ?? s.html) : stripDiffHtml(live.html), imgs };
  };

  const startAiReview = async (
    topic: string,
    scope?: { from: number; to: number } | null,
    directives?: CompiledDirective[],
    opts?: { suppressKeywordRide?: boolean },
  ) => {
    if (!editor || !pageReady || busy || review) return;
    setRunDirectives(directives && directives.length > 0 ? directives : null);
    // THE KEYWORD RIDE (owner law 2026-07-15): ALL the page's keywords —
    // primary + supporting + additional — join EVERY run as context,
    // never as stuffing orders. An injection run suppresses the ride:
    // its topic IS the keyword order (one order per prompt, never two).
    const kwTargets = [...new Set([
      primaryKw.trim(),
      ...supportingKw.split(',').map((s) => s.trim()),
      ...kwBucket.keywords,
    ].filter((k) => k !== ''))];
    if (!opts?.suppressKeywordRide && kwTargets.length > 0) {
      topic = `${topic}\n\nTarget keywords — incorporate them naturally where they genuinely fit, never force or stuff: ${kwTargets.join(', ')}`;
    }
    const { sections } = splitDocSections(editor.getHTML());
    if (sections.length === 0) {
      toast.info('No sections to optimize on this page.');
      return;
    }
    // SCOPE (owner order 2026-07-13): a text selection narrows the SAME
    // review to the sections it touches — out-of-scope sections resolve
    // 'clean' up front (never sent to the AI, invisible in the rail). One
    // pipeline: rail, red/green, Accept/Reject/OK are shared by construction.
    const inScope = new Set<number>();
    if (scope) {
      let idx = -1;
      editor.state.doc.forEach((node, pos) => {
        if (node.type.name === 'heading') idx++;
        if (idx >= 0 && pos < scope.to && pos + node.nodeSize > scope.from) inScope.add(idx);
      });
      if (inScope.size === 0) {
        toast.info('Select text inside a section to optimize it.');
        return;
      }
    }
    const skip = (i: number): boolean => !!scope && !inScope.has(i);
    // IDENTITY ANCHORS (2026-07-14): stamp every section heading with its
    // review id in ONE transaction — the heading ORDINAL is trusted only
    // HERE, at t0, where it still equals the captured section index. From
    // now on the AI may add sections freely; identity never counts again.
    {
      const tr = editor.state.tr;
      let h = -1;
      editor.state.doc.forEach((node, pos) => {
        if (node.type.name !== 'heading') return;
        h++;
        tr.setNodeMarkup(pos, undefined, { ...node.attrs, 'data-pcm-review-id': String(h) });
      });
      editor.view.dispatch(tr);
    }
    setAskOpen(false);
    const initial = sections.map((s, i) => ({ ...s, status: (skip(i) ? 'clean' : 'pending') as ReviewStatus }));
    reviewRef.current = initial; // workers may resolve before the sync effect runs
    setReview(initial);
    // The editor stays EDITABLE (owner F1): the doc is the source of truth,
    // suggestions land by surgery — nothing rebuilds, nothing locks.
    // Bounded pool: 4 sections in flight; a slow/failed section fails ALONE.
    let next = 0;
    const worker = async () => {
      while (next < sections.length) {
        const i = next++;
        if (skip(i)) continue;
        try {
          const res: any = await optimizeMutation.mutateAsync({
            siteId: siteId as number, postId, type,
            html: sections[i].html, topic,
            model: aiPick?.id ?? model, provider: aiPick?.provider ?? provider,
          });
          const value = canonicalAiHtml(editor, String(res?.value ?? '').trim());
          const genModel = String(res?.model ?? '');
          const changed = value !== '' && htmlText(value) !== htmlText(sections[i].html);
          if (reviewRef.current?.[i]?.status !== 'pending') continue; // user already finished — drop the result
          if (changed) {
            applySection(i, diffBlocksHtml(sections[i].html, value) + sections[i].imgs.join(''));
          }
          // Baseline = the landed view read back from the editor (surgery is
          // synchronous) — effectiveContent's untouched-detector.
          const baseline = changed ? splitReviewSections(editor.getHTML())[String(i)]?.html : undefined;
          setReview((cur) => cur?.map((s, k) => (k === i && s.status === 'pending'
            ? (changed ? { ...s, status: 'diff' as ReviewStatus, ai: value, genModel, baseline } : { ...s, status: 'clean' as ReviewStatus })
            : s)) ?? cur);
        } catch (e: any) {
          setReview((cur) => cur?.map((s, k) => (k === i && s.status === 'pending'
            ? { ...s, status: 'failed' as ReviewStatus, error: e?.message ?? 'AI failed on this section' }
            : s)) ?? cur);
        }
      }
    };
    await Promise.all(Array.from({ length: Math.min(4, sections.length) }, worker));
  };

  /** THE INJECTION RUN (owner spec d135e3c + the action matrix 2026-07-15):
   *  weave EXACTLY the ticked keywords into the existing content per THE
   *  HIERARCHY LAW — the frequent light touch, red/green like every run.
   *  A text selection narrows it (the matrix); the generic ride is
   *  suppressed because this topic IS the keyword order. */
  const runKeywordInsert = () => {
    const byRole = (role: TickedKeyword['role']): string[] =>
      tickedKw.filter((t) => t.role === role).map((t) => t.kw);
    const lines = ([
      ['primary', "PRIMARY — thread straight through the page (headings + body, the page's spine)"],
      ['supporting', 'SUPPORTING — present in some headers and some text (structural, not everywhere)'],
      ['additional', 'ADDITIONAL — light touch, mentioned naturally, roughly ONE paragraph each, never more'],
    ] as const).flatMap(([role, law]) => {
      const kws = byRole(role);
      return kws.length > 0 ? [`${law}: ${kws.join(', ')}`] : [];
    });
    const topic = 'Weave the following keywords into the existing content — keep the page\'s structure '
      + 'and message, no full rework. Placement follows each keyword\'s ROLE; natural inclusion always, '
      + `keyword stuffing never.\n${lines.join('\n')}`;
    void startAiReview(
      topic,
      hasSelection && editor ? { from: editor.state.selection.from, to: editor.state.selection.to } : null,
      [{ text: `Insert the selected keywords by role: ${tickedKw.map((t) => t.kw).join(', ')}`, purposes: ['keywords'], sources: [0] }],
      { suppressKeywordRide: true },
    );
  };

  /** THE single decision path (chips, rail rows, Accept all, OK — all of
   *  them). Accept = the oracle's answer (untouched → the AI's clean
   *  formatted HTML; edited → the user's live words, marks stripped) with
   *  the lane identity restated — a changed section reads amber the moment
   *  it's accepted. Reject = the original back, byte-identical. */
  const resolveSection = (i: number, action: 'accept' | 'reject') => {
    const s = reviewRef.current?.[i];
    if (!s || s.status !== 'diff') return;
    if (sectionRange(i) === null) {
      // The user deleted the section (its anchor is gone): resolve honestly
      // — never write to a guessed range.
      toast.info('That section no longer exists in the document — nothing to apply.');
      setReview((cur) => cur?.map((x, k) => (k === i && x.status === 'diff'
        ? { ...x, status: 'rejected' as ReviewStatus }
        : x)) ?? cur);
      return;
    }
    const { html: kept, imgs } = effectiveContent(i);
    if (action === 'accept') {
      applySection(i, restateOrigin(kept, s.html, htmlText(kept) !== htmlText(s.html)) + imgs);
    } else {
      applySection(i, s.html + imgs);
    }
    setReview((cur) => cur?.map((x, k) => (k === i && x.status === 'diff'
      ? { ...x, status: (action === 'accept' ? 'accepted' : 'rejected') as ReviewStatus }
      : x)) ?? cur);
  };
  const acceptAllDiffs = () =>
    (reviewRef.current ?? []).forEach((s, i) => { if (s.status === 'diff') resolveSection(i, 'accept'); });
  /** OK — finish the review NOW: decisions already made stay, every undecided
   *  section keeps its original (still-generating ones too — late results are
   *  dropped by the workers' status guard). */
  const finishReview = () => {
    (reviewRef.current ?? []).forEach((s, i) => { if (s.status === 'diff') resolveSection(i, 'reject'); });
    setReview((cur) => cur?.map((s) => (s.status === 'pending' ? { ...s, status: 'rejected' as ReviewStatus } : s)) ?? cur);
    // THE RESULTS LOOP stamp (gap e8fcae5 D5): a review that ends with at
    // least one ACCEPTED section = an optimization event — the before/after
    // measurement anchors here. Failure is stated, never silent.
    const accepted = (reviewRef.current ?? []).filter((s) => s.status === 'accepted').length;
    if (accepted > 0 && typeof siteId === 'number') {
      historyStampMutation
        .mutateAsync({ siteId, postId, purposes: Array.from(new Set((runDirectives ?? []).flatMap((d) => d.purposes))) })
        .catch((e: unknown) => toast.error(`The optimization was applied but could not be logged for results tracking — ${e instanceof Error ? e.message : 'save failed'}`));
    }
  };

  // ── REVISE (owner F2): send a section BACK to the AI with an adjustment
  //    note. The AI receives the ORIGINAL (as the optimize target), the
  //    CURRENT draft — including the user's manual edits — and the note.
  //    Revise-all runs the same path for every still-undecided section. ──
  const [reviseTarget, setReviseTarget] = useState<number | 'all' | null>(null);
  const [reviseNote, setReviseNote] = useState('');
  const reviseSection = async (i: number, note: string) => {
    const s = reviewRef.current?.[i];
    if (!editor || !s || s.status !== 'diff') return;
    if (sectionRange(i) === null) {
      toast.info('That section no longer exists in the document — nothing to revise.');
      setReview((cur) => cur?.map((x, k) => (k === i && x.status === 'diff'
        ? { ...x, status: 'rejected' as ReviewStatus }
        : x)) ?? cur);
      return;
    }
    // The draft the AI builds on = the SAME oracle Accept uses: clean
    // formatted for untouched sections, the user's words for edited ones.
    const draft = effectiveContent(i).html;
    setReview((cur) => cur?.map((x, k) => (k === i ? { ...x, status: 'pending' as ReviewStatus } : x)) ?? cur);
    try {
      // REVISE FIDELITY (gap e8fcae5 D3): the note and the draft travel
      // SEPARATELY — the server enforces the human-editor contract and a
      // retention check against the draft (a targeted note may never
      // silently rewrite the whole text).
      const res: any = await optimizeMutation.mutateAsync({
        siteId: siteId as number, postId, type,
        html: s.html,
        topic: note,
        draft,
        model: aiPick?.id ?? model, provider: aiPick?.provider ?? provider,
      });
      const value = canonicalAiHtml(editor, String(res?.value ?? '').trim());
      const genModel = String(res?.model ?? '');
      if (reviewRef.current?.[i]?.status !== 'pending') return; // decided meanwhile — drop
      if (value && htmlText(value) !== htmlText(s.html)) {
        applySection(i, diffBlocksHtml(s.html, value) + s.imgs.join(''));
        // Every landing re-arms the untouched-detector (initial + each revise).
        const baseline = splitReviewSections(editor.getHTML())[String(i)]?.html;
        setReview((cur) => cur?.map((x, k) => (k === i ? { ...x, status: 'diff' as ReviewStatus, ai: value, genModel, baseline } : x)) ?? cur);
      } else {
        applySection(i, s.html + s.imgs.join(''));
        setReview((cur) => cur?.map((x, k) => (k === i ? { ...x, status: 'clean' as ReviewStatus } : x)) ?? cur);
      }
    } catch (e: any) {
      // The draft stays on screen — only the status returns to reviewable.
      setReview((cur) => cur?.map((x, k) => (k === i && x.status === 'pending' ? { ...x, status: 'diff' as ReviewStatus } : x)) ?? cur);
      toast.error(e?.message ?? 'Revise failed — the current draft was kept.');
    }
  };
  const reviseAll = async (note: string) => {
    const targets = (reviewRef.current ?? []).map((s, i) => (s.status === 'diff' ? i : -1)).filter((i) => i >= 0);
    let next = 0;
    const worker = async () => {
      while (next < targets.length) {
        await reviseSection(targets[next++], note);
      }
    };
    await Promise.all(Array.from({ length: Math.min(4, targets.length) }, worker));
  };
  const runRevise = () => {
    const note = reviseNote.trim();
    const target = reviseTarget;
    if (!note || target === null) return;
    setReviseTarget(null);
    setReviseNote('');
    if (target === 'all') void reviseAll(note);
    else void reviseSection(target, note);
  };

  // ── Inline chips + focus flash (owner-picked combo 2026-07-13): the chips
  //    ride the SAME resolveSection as the rail (one machinery); clicking a
  //    rail card scrolls to its section and pulses it for ~2s. ──
  const [flashIdx, setFlashIdx] = useState<number | null>(null);
  useEffect(() => {
    if (!editor || !isPage) return;
    (editor.storage as any).pcmReviewControls.sections = review?.map((s) => s.status) ?? [];
    (editor.storage as any).pcmReviewControls.resolve = resolveSection;
    (editor.storage as any).pcmReviewControls.revise = (i: number) => { setReviseTarget(i); setReviseNote(''); };
    editor.view.dispatch(editor.state.tr); // refresh widget decorations
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [editor, isPage, review]);
  useEffect(() => {
    if (!editor || !isPage) return;
    (editor.storage as any).pcmSectionBlocks.flash = flashIdx;
    editor.view.dispatch(editor.state.tr); // refresh block decorations
  }, [editor, isPage, flashIdx]);
  const focusSection = (i: number) => {
    if (!editor) return;
    // Locate by ANCHOR (identity law), then translate to the heading's
    // CURRENT ordinal — the flash decoration indexes every heading.
    const id = String(i);
    let target: number | null = null;
    let ordinal = -1;
    let flashOrdinal: number | null = null;
    editor.state.doc.forEach((node, pos) => {
      if (node.type.name !== 'heading') return;
      ordinal++;
      if (target === null && (((node.attrs['data-pcm-review-id'] as string | null) ?? null) === id)) {
        target = pos;
        flashOrdinal = ordinal;
      }
    });
    if (target === null || flashOrdinal === null) return;
    (editor.view.nodeDOM(target) as HTMLElement | null)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    const f = flashOrdinal;
    setFlashIdx(f);
    window.setTimeout(() => setFlashIdx((cur) => (cur === f ? null : cur)), 2000);
  };

  // Completion watcher (the surgery engine replaced the old whole-doc
  // rebuilder here — the doc is already correct at every moment): keep the
  // workers' live mirror in sync and close the review when every section is
  // resolved. Editing was never locked, so nothing to unlock.
  useEffect(() => {
    reviewRef.current = review;
    if (!editor || !review) return;
    if (review.every((s) => s.status !== 'pending' && s.status !== 'diff')) {
      // The anchors die WITH the review (one transaction) — a save can never
      // carry them; the server strip is only the belt.
      const tr = editor.state.tr;
      let stamped = false;
      editor.state.doc.forEach((node, pos) => {
        if (node.type.name === 'heading' && node.attrs['data-pcm-review-id'] != null) {
          tr.setNodeMarkup(pos, undefined, { ...node.attrs, 'data-pcm-review-id': null });
          stamped = true;
        }
      });
      if (stamped) editor.view.dispatch(tr);
      const accepted = review.filter((s) => s.status === 'accepted').length;
      setReview(null);
      if (accepted > 0) toast.success(`AI review done — ${accepted} section${accepted === 1 ? '' : 's'} updated. Press Save to make it live.`);
      else if (review.some((s) => s.status === 'rejected' || s.status === 'failed')) toast.info('AI review closed — nothing was changed.');
      else toast.info('The page already looks optimized.');
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [review]);

  // ── Image metadata (page mode, V3): click a locked image → side panel
  //    edits its alt/title as a dynamic IMAGE rule (attr rewrite at render
  //    time — the image itself never moves). Server keeps the ORIGINAL attrs
  //    from rule creation; editing back (or Revert) deletes the rule.
  const [imgSel, setImgSel] = useState<ImgSelection | null>(null);
  const [imgForm, setImgForm] = useState({ alt: '', title: '' });
  const imageRulesQuery = trpc.seo.remoteGetParagraphRules.useQuery(
    { siteId: siteId as number, postId },
    { enabled: isPage && !readOnly, staleTime: 0 },
  );
  const imageRules: Array<{ target: string; matchText: string; occurrence: number; active: boolean }> =
    Array.isArray((imageRulesQuery.data as any)?.rules) ? (imageRulesQuery.data as any).rules : [];
  const imgHasRule = imgSel != null && imageRules.some((r) =>
    r.target === 'image' && r.active && r.occurrence === imgSel.occurrence && r.matchText === normSrc(imgSel.src));
  const saveImageMutation = trpc.seo.remoteSaveImageRule.useMutation();

  useEffect(() => {
    if (!isPage || !editor) return;
    const onSel = () => {
      const sel: any = editor.state.selection;
      const node = sel?.node;
      if (node?.type?.name !== 'image') {
        setImgSel(null);
        return;
      }
      const src = String(node.attrs?.src ?? '');
      // Occurrence among same-src images in document order — the rule identity.
      let occ = 0;
      editor.state.doc.descendants((n: any, pos: number) => {
        if (n.type?.name === 'image' && pos < sel.from && normSrc(String(n.attrs?.src ?? '')) === normSrc(src)) occ++;
        return true;
      });
      const alt = String(node.attrs?.alt ?? '');
      const nodeTitle = String(node.attrs?.title ?? '');
      setImgSel({ src, occurrence: occ, alt, title: nodeTitle });
      setImgForm({ alt, title: nodeTitle });
    };
    editor.on('selectionUpdate', onSel);
    return () => { editor.off('selectionUpdate', onSel); };
  }, [isPage, editor]);

  // ── Add image (phase 1): pick from the hub's media library → the file is
  //    delivered into the CLIENT site's own library (autonomy law) → the
  //    client-native URL is inserted at the cursor, marked platform-added. ──
  const uploadMediaMutation = trpc.seo.remoteUploadMedia.useMutation();
  const addImage = () => {
    if (busy) return;
    if (typeof wp === 'undefined' || !wp.media) {
      toast.error('The media library isn’t available on this screen.');
      return;
    }
    const frame = wp.media({ title: 'Add image', multiple: false, library: { type: 'image' } });
    frame.on('select', () => {
      const att = frame.state().get('selection').first().toJSON();
      void (async () => {
        setBusyAction('imageAdd');
        try {
          const res: any = await uploadMediaMutation.mutateAsync({ siteId: siteId as number, url: String(att.url ?? '') });
          const clientUrl = String(res?.url ?? '');
          if (!clientUrl) throw new Error('The site did not return the delivered image’s URL.');
          editor?.chain().focus().insertContent({
            type: 'image',
            attrs: {
              src: clientUrl,
              alt: String(att.alt || att.title || ''),
              'data-pcm-added': String(res?.id ?? '1'),
            },
          }).run();
          toast.success('Image added — it now lives in the site’s own media library.');
        } catch (e: any) {
          toast.error(e?.message ?? 'Could not deliver the image to the site');
        } finally {
          setBusyAction(null);
        }
      })();
    });
    frame.open();
  };

  const saveImageMeta = async (mode: 'save' | 'revert' | 'hide') => {
    if (!imgSel || busy) return;
    setBusyAction(mode === 'save' ? 'imageSave' : mode === 'hide' ? 'imageHide' : 'imageRevert');
    try {
      const res: any = await saveImageMutation.mutateAsync({
        siteId: siteId as number, postId,
        src: imgSel.src, occurrence: imgSel.occurrence,
        alt: imgForm.alt, title: imgForm.title,
        // For a NEW rule the attrs as selected ARE the originals; for an
        // existing rule the server keeps its stored originals regardless.
        originalAlt: imgSel.alt, originalTitle: imgSel.title,
        revert: mode === 'revert',
        hidden: mode === 'hide',
      });
      if (mode === 'hide') {
        // Reflect the hide in the doc — the live page stops serving the tag;
        // the media library is untouched. Un-hide = restore a version.
        editor?.chain().focus().deleteSelection().run();
        toast.success('Image hidden — the site serves without it (the file stays in the media library).');
      } else if (res?.reverted) {
        // Apply the server-returned originals in place — the document is
        // never auto-replaced (incident fix 2026-07-11).
        editor?.commands.updateAttributes('image', {
          alt: String(res?.original?.alt ?? ''),
          title: String(res?.original?.title ?? '') || null,
        });
        toast.success('Image metadata reverted — the original serves again.');
      } else {
        editor?.commands.updateAttributes('image', { alt: imgForm.alt, title: imgForm.title });
        toast.success('Saved — the site serves the new image metadata.');
      }
      void imageRulesQuery.refetch();
      setImgSel(null);
    } catch (e: any) {
      toast.error(e?.message ?? 'Could not save the image metadata');
    } finally {
      setBusyAction(null);
    }
  };

  const title = isPage
    ? `📄 ${page?.title ?? 'Page'}`
    : isInsert
      ? (insert?.ruleId ? '¶ Added section' : '¶ New section')
      : `¶ ${section?.heading.text ?? ''}`;
  const served = !isInsert && !isPage && !!section?.sectionRuleReplacement;

  // ── Shared header controls: the page header's row 1 and the section
  //    header render the SAME nodes (one definition, two placements). ──
  const versionsControl = (!readOnly && !isInsert && !review) ? (
    <div className="relative shrink-0">
      <button
        type="button"
        onClick={() => setVersionsOpen((v) => !v)}
        title="Versions — pick one to view it — saving makes it live"
        className="inline-flex max-w-[190px] items-center gap-1.5 truncate rounded-full border border-slate-200 bg-white px-2.5 py-[3px] text-xs font-medium text-slate-500 hover:bg-slate-50"
      >
        <span className="truncate">{versionLabel}</span>
        <span className="text-slate-400">▾</span>
      </button>
      {versionsOpen && (
        <div className="absolute right-0 top-full z-10 mt-1 w-[230px] overflow-hidden rounded-md border border-slate-200 bg-white py-0.5 shadow-md">
          {isPage && pageDirty && (
            <button
              type="button"
              onClick={() => { setVersionPick(''); setVersionsOpen(false); }}
              className="block w-full px-2 py-1 text-left text-[11px] font-medium text-slate-800 hover:bg-slate-50"
            >
              {versionPick === '' && <Check className="mr-1 inline h-3 w-3 text-primary" />}
              Draft (unsaved)
            </button>
          )}
          {(!isPage || originalHtml !== '') && (
            <button
              type="button"
              onClick={() => pickVersion('original')}
              className="block w-full px-2 py-1 text-left text-[11px] text-slate-700 hover:bg-slate-50"
            >
              {versionPick === 'original' && <Check className="mr-1 inline h-3 w-3 text-primary" />}
              {isPage ? pageOriginalLabel : 'Original'}
            </button>
          )}
          {/* Bulk cleanup bar — shown when there is history to clean. The
              Current version never joins select-all (W0 opens from it). */}
          {deletableVersionIds.length > 0 && (
            <div className="flex items-center gap-1.5 border-b border-slate-100 px-2 py-1">
              <input
                type="checkbox"
                checked={versionSel.size > 0 && versionSel.size === deletableVersionIds.length}
                onChange={() => setVersionSel(versionSel.size === deletableVersionIds.length ? new Set() : new Set(deletableVersionIds))}
                title="Select all versions except the current one"
                className="h-3 w-3 shrink-0 accent-[#007bff]"
              />
              <span className="min-w-0 flex-1 truncate text-[10px] text-slate-400">
                {versionSel.size > 0 ? `${versionSel.size} selected` : 'Select versions'}
              </span>
              {versionSel.size > 0 && (
                <button
                  type="button"
                  onClick={() => { void deleteSelectedVersions(); }}
                  disabled={bulkDeleting}
                  className="inline-flex shrink-0 items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-medium text-destructive hover:bg-red-50 disabled:opacity-50"
                >
                  {bulkDeleting ? <Loader2 className="h-3 w-3 animate-spin" /> : <Trash2 className="h-3 w-3" />}
                  Delete ({versionSel.size})
                </button>
              )}
            </div>
          )}
          {versions.map((v, idx) => (
            <div key={v.id} className="flex items-center hover:bg-slate-50">
              {/* idx 0 = Current — deliberately no checkbox (single delete stays). */}
              {idx > 0 ? (
                <input
                  type="checkbox"
                  checked={versionSel.has(Number(v.id))}
                  onChange={() => toggleVersionSel(Number(v.id))}
                  className="ml-2 h-3 w-3 shrink-0 accent-[#007bff]"
                />
              ) : (
                <span className="ml-2 h-3 w-3 shrink-0" />
              )}
              <button
                type="button"
                onClick={() => pickVersion(String(v.id))}
                className="min-w-0 flex-1 truncate px-2 py-1 text-left text-[11px] text-slate-700"
              >
                {(versionPick === String(v.id) || (versionPick === '' && !pageDirty && isPage && idx === 0)) && (
                  <Check className="mr-1 inline h-3 w-3 text-primary" />
                )}
                {isPage ? pageRowLabel(v, idx) : v.createdAt.slice(0, 16)}
              </button>
              <button
                type="button"
                onClick={() => { void deleteVersion(v.id); }}
                title="Delete this version"
                className="shrink-0 rounded p-1 text-slate-400 hover:text-destructive"
              >
                <Trash2 className="h-3 w-3" />
              </button>
            </div>
          ))}
          {versions.length === 0 && (
            <div className="px-2 py-1 text-[11px] text-slate-400">No saved versions yet</div>
          )}
        </div>
      )}
    </div>
  ) : null;
  const closeButton = (
    <button type="button" onClick={onClose} title="Close (Esc) — closes without saving" className="shrink-0 rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700">
      <X className="h-3.5 w-3.5" />
    </button>
  );

  /** THE KEYWORD DRAWER — page mode's flex sibling: the wrapper centers
   *  the [drawer][card] PAIR as one unit (no anchor math anywhere), the
   *  card tapers while it is open. drawerRef exempts it from the
   *  outside-click save. */
  const drawerVisible = isPage && !readOnly && keywordsOpen && pageReady && typeof siteId === 'number';
  const drawerEl = drawerVisible ? (
    <div
      ref={drawerRef}
      className="h-[86vh] shrink-0 self-center overflow-hidden rounded-l-2xl bg-white shadow-2xl animate-in fade-in slide-in-from-right-10 duration-300"
    >
      <KeywordsDrawer
        siteId={siteId as number}
        postId={postId}
        type={type}
        pageUrl={page?.permalink ?? ''}
        pages={sitePages ?? []}
        primaryKeyword={primaryKw}
        onPrimaryChange={setPrimaryKw}
        supportingKeywords={supportingKw}
        onSupportingChange={setSupportingKw}
        bucket={kwBucket}
        contentText={contentText}
        onKeywordSelection={setTickedKw}
        onClose={() => setKeywordsOpen(false)}
      />
    </div>
  ) : null;

  const cardEl = (
    <div
      ref={rootRef}
      className={`flex flex-col overflow-hidden bg-white ${isPage ? 'rounded-2xl shadow-2xl' : 'fixed z-40 rounded-lg border border-slate-200 shadow-xl'}`}
      style={isPage
        ? { width: pageCardWidth(drawerVisible), height: '90vh', transition: 'width 300ms ease' }
        : { left: pos.x, top: pos.y, width: WIDTH, maxWidth: 'calc(100vw - 16px)' }}
      role="dialog"
      aria-label={title}
    >
      {/* ── Header. PAGE mode (owner UX 2026-07-13): TWO rows — row 1 = the
             document (identity, escape hatches, versions, close), row 2 = the
             workbench (AI context left, tools right). The title keeps its
             size; only the DATE is small, stacked underneath (owner order —
             never on the same line). SECTION mode: the original draggable
             single row. ── */}
      <div
        className={`select-none border-b bg-white ${isPage ? 'border-slate-100' : 'flex items-center gap-1.5 border-slate-200 px-2.5 py-1.5 cursor-grab active:cursor-grabbing'}`}
        onPointerDown={isPage ? undefined : onDragStart}
        onPointerMove={isPage ? undefined : onDragMove}
        onPointerUp={isPage ? undefined : onDragEnd}
      >
        {isPage && (
          <div className="flex items-center gap-2 px-5 pb-2 pt-3">
            <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-blue-50">
              <FileText className="h-4 w-4 text-blue-600" />
            </span>
            <span className="min-w-0">
              <span className="block truncate text-[13px] font-medium leading-[1.4] text-slate-800" title={page?.title ?? 'Page'}>
                {page?.title ?? 'Page'}
              </span>
              {page?.date && (
                <span className="mt-0.5 block truncate text-[10px] leading-none text-slate-400">{String(page.date).slice(0, 10)}</span>
              )}
            </span>
            {/* Status — the table's exact control, same save path (owner
                order 2026-07-15). Sits right of the title by design. */}
            {!readOnly && pageStatus !== '' && (
              <Select value={pageStatus} onValueChange={pickStatus}>
                <SelectTrigger className="h-auto w-auto shrink-0 border-0 bg-transparent p-0 text-xs shadow-none focus:ring-0 focus:ring-offset-0">
                  <Pill variant={statusPillVariant(pageStatus)} className="capitalize">{pageStatus}</Pill>
                </SelectTrigger>
                <SelectContent>
                  {['publish', 'draft', 'pending', 'private', 'future'].map((s) => (
                    <SelectItem key={s} value={s} className="text-xs capitalize">{s}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
            {page?.editUrl && (
              <a
                href={page.editUrl}
                target="_blank"
                rel="noopener noreferrer"
                title="Open this page in the site’s WP editor (source editing)"
                className="inline-flex shrink-0 items-center gap-1 rounded px-1.5 py-0.5 text-[11px] text-slate-500 hover:bg-slate-100 hover:text-slate-800"
              >
                <ExternalLink className="h-3 w-3" /> Edit
              </a>
            )}
            {page?.permalink && (
              <a
                // A DRAFT has no public URL (WP hands drafts a ?page_id= link
                // that shows nothing to a visitor) — Open carries preview=true
                // so the REAL page renders as a logged-in preview. The true
                // permalink itself stays untouched (the GSC drawer filters on it).
                href={page.status && page.status !== 'publish'
                  ? `${page.permalink}${page.permalink.includes('?') ? '&' : '?'}preview=true`
                  : page.permalink}
                target="_blank"
                rel="noopener noreferrer"
                title={page.status && page.status !== 'publish' ? 'Open this draft as a preview in a new tab' : 'Open the live page in a new tab'}
                className="inline-flex shrink-0 items-center gap-1 rounded px-1.5 py-0.5 text-[11px] text-slate-500 hover:bg-slate-100 hover:text-slate-800"
              >
                <ExternalLink className="h-3 w-3" /> Open
              </a>
            )}
            {page?.onPreview && (
              <button
                type="button"
                onClick={page.onPreview}
                title="Preview the live page here, in the inline preview window"
                className="inline-flex shrink-0 items-center gap-1 rounded px-1.5 py-0.5 text-[11px] text-slate-500 hover:bg-slate-100 hover:text-slate-800"
              >
                <Eye className="h-3 w-3" /> Preview
              </button>
            )}
            <div className="flex-1" />
            {versionsControl}
            {closeButton}
          </div>
        )}
        {/* Row 2 — the workbench: the AI's context left (business, page
            type), the tools right (Insert ▸ model ▸ Optimize — reads like a
            sentence: insert things; optimize with this model). */}
        {isPage && !readOnly && pageReady && !review && (
          <div className="flex items-center gap-1.5 border-t border-slate-100 bg-white px-5 py-1.5">
            <ModelDropdown
              modelGroups={[{
                label: 'Business',
                models: [{ id: '0', name: 'No business' }, ...brands.map((b) => ({ id: String(b.id), name: b.name }))],
              }]}
              selectedModel={String(brandId)}
              onModelChange={pickBrand}
            />
            <ModelDropdown
              modelGroups={[{ label: 'Page type', models: PAGE_TYPE_OPTIONS }]}
              selectedModel={pageType}
              onModelChange={pickPageType}
            />
            {/* THE SMART KEYWORDS BUTTON (owner 2026-07-14): the hierarchy's
                third value — Business → Page type → Keywords. A LIVE display
                in the dropdown-family look: primary · +count · density%. */}
            <button
              type="button"
              onClick={() => setKeywordsOpen((v) => !v)}
              title="The page's keywords — they ride every optimization; click to manage"
              style={DROPDOWN_TRIGGER_STYLE}
              className="flex items-center gap-1.5"
            >
              <KeyRound className="h-3 w-3 shrink-0" />
              <span className="max-w-[140px] truncate">{primaryKw.trim() || 'Keywords'}</span>
              {kwExtraCount > 0 && <span className="shrink-0 text-slate-400">+{kwExtraCount}</span>}
              {primaryKw.trim() !== '' && (
                <span className="shrink-0 font-semibold text-primary">{primaryDensity}%</span>
              )}
            </button>
            <div className="flex-1" />
            <div className="relative shrink-0">
              <button
                type="button"
                onClick={() => setInsertOpen((v) => !v)}
                title="Insert content at the cursor"
                className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium hover:bg-slate-100 ${insertOpen ? 'bg-slate-100 text-slate-800' : 'text-slate-500 hover:text-slate-800'}`}
              >
                {busyAction === 'imageAdd'
                  ? <Loader2 className="h-3 w-3 animate-spin text-primary" />
                  : <Plus className="h-3 w-3" />} Insert <span className="text-slate-400">▾</span>
              </button>
              {insertOpen && (
                <div className="absolute right-0 top-full z-10 mt-1 w-[150px] overflow-hidden rounded-md border border-slate-200 bg-white py-0.5 shadow-md">
                  <button
                    type="button"
                    onClick={() => { setInsertOpen(false); addImage(); }}
                    className="flex w-full items-center gap-1.5 px-2 py-1 text-left text-[11px] text-slate-700 hover:bg-slate-50"
                  >
                    <ImagePlus className="h-3 w-3 text-slate-400" /> Image
                  </button>
                  <button
                    type="button"
                    onClick={() => { setInsertOpen(false); editor?.chain().focus().insertContent(faqTemplate()).run(); }}
                    className="flex w-full items-center gap-1.5 px-2 py-1 text-left text-[11px] text-slate-700 hover:bg-slate-50"
                  >
                    <MessageCircleQuestion className="h-3 w-3 text-slate-400" /> FAQ
                  </button>
                </div>
              )}
            </div>
            {aiModelSelect}
            {/* ANALYZE (the optimizer spine): blue OUTLINE pill — the
                generate family, visually distinct from the filled Optimize
                beside it. Opens the rail and runs every teacher. */}
            <PillButton
              variant="outline"
              icon={<ScanSearch />}
              onClick={() => setAnalyzeOpen((v) => !v)}
              title="Analyze this page — every purpose contributes suggestions you pick from"
            >
              Analyze
            </PillButton>
            {/* ONE AI entry point (the shared blue generate pill, split): EVERY
                run goes through the red/green review — no AI text ever lands
                without Accept/Reject. A text selection only narrows the SCOPE;
                the label always states it. The caret opens the instruction
                field that steers the run. */}
            <PillSplitButton
              icon={<Sparkles />}
              onClick={() => {
                // THE ACTION MATRIX (owner law 2026-07-15): ticked keywords
                // transform the run into the injection; a text selection
                // narrows either run; nothing selected = everything.
                if (tickedKw.length > 0) {
                  runKeywordInsert();
                  return;
                }
                void startAiReview(
                  instruction.trim(),
                  hasSelection && editor ? { from: editor.state.selection.from, to: editor.state.selection.to } : null,
                );
              }}
              onCaretClick={() => setAskOpen((v) => !v)}
              caretActive={askOpen}
              title={tickedKw.length > 0
                ? (hasSelection
                  ? 'Weave the ticked keywords into the selected sections by their roles — changes show as red/green'
                  : 'Weave the ticked keywords into the content by their roles — changes show as red/green')
                : (hasSelection
                  ? 'Rewrite the selected sections with AI — changes show as red/green for you to accept or reject'
                  : 'Rewrite the whole page with AI — every change shows as red/green for you to accept or reject')}
              caretTitle="Write instructions for the AI (e.g. “optimize for keyword X”)"
            >
              {tickedKw.length > 0
                ? `Insert keywords (${tickedKw.length})`
                : hasSelection ? 'Optimize (selected text)' : 'Optimize page'}
            </PillSplitButton>
          </div>
        )}
        {!isPage && (
          <>
            <div className="flex min-w-0 flex-1 items-center gap-2">
              <span className="min-w-0 truncate text-xs font-medium text-slate-800" title={title}>
                {served && <span className="mr-1.5 inline-block h-1.5 w-1.5 rounded-full bg-primary align-middle" title="Optimized — a section rule serves this content" />}
                {title}
              </span>
            </div>
            {versionsControl}
            {!readOnly && (
              <>
                {aiModelSelect}
                <button
                  type="button"
                  onClick={() => setAskOpen((v) => !v)}
                  title="Tell the AI what to do with this section"
                  className={`inline-flex shrink-0 items-center gap-1 rounded border border-slate-200 px-1.5 py-0.5 text-[11px] hover:bg-slate-50 ${askOpen ? 'text-primary border-primary/40' : 'text-slate-600'}`}
                >
                  <MessageSquarePlus className="h-3 w-3" /> Ask AI
                </button>
                <button
                  type="button"
                  onClick={() => runAi('')}
                  title={isInsert ? 'Draft this section with AI' : 'Rewrite this section with AI'}
                  className="inline-flex shrink-0 items-center gap-1 rounded border border-slate-200 px-1.5 py-0.5 text-[11px] text-slate-600 hover:bg-slate-50 hover:text-primary"
                >
                  {busyAction === 'ai' ? <Loader2 className="h-3 w-3 animate-spin text-primary" /> : <Sparkles className="h-3 w-3" />}
                  {isInsert ? 'Generate' : 'Re-write'}
                </button>
              </>
            )}
            {closeButton}
          </>
        )}
      </div>

      {/* ── W4 drift notice (2026-07-16): a state statement — the hub's saved
             version and the site's served state disagree. Load live view swaps
             in the assembly EXPLICITLY (a user action, never an auto-replace —
             the 2026-07-11 law holds) and behaves like a version pick: the
             baseline stays, so the dropdown honestly reads Draft (unsaved)
             and saving makes the live view the saved version. ── */}
      {isPage && !readOnly && pageReady && pageDrifted && (
        <div className="border-b border-slate-100 bg-white px-5 py-1 text-[11px] text-slate-400">
          The live page has drifted from the saved version.{' '}
          <button
            type="button"
            onClick={() => {
              editor?.commands.setContent(pageHtml);
              setVersionPick('');
            }}
            className="text-slate-500 underline underline-offset-2 hover:text-slate-700"
          >
            Load live view
          </button>
        </div>
      )}

      {/* ── Ask-AI instruction (Enter runs it) ── */}
      {askOpen && !readOnly && (
        <div className="border-b border-slate-200 px-2.5 py-1.5">
          <input
            autoFocus
            value={instruction}
            onChange={(e) => setInstruction(e.target.value)}
            onKeyDown={(e) => {
              if (e.key !== 'Enter' || !instruction.trim()) return;
              if (!isPage) { void runAi(instruction.trim()); return; }
              void startAiReview(
                instruction.trim(),
                hasSelection && editor ? { from: editor.state.selection.from, to: editor.state.selection.to } : null,
              );
            }}
            placeholder="e.g. “optimize for keyword X” or “inject keyword Y five times” — Enter to run"
            className="h-6 w-full rounded border border-slate-200 bg-white px-2 text-[11px] text-slate-800 outline-none focus:border-primary"
          />
        </div>
      )}

      {/* ── New-section placement (create mode only) ── */}
      {isInsert && !insert?.ruleId && (
        <div className="flex items-center gap-1.5 border-b border-slate-200 px-2.5 py-1.5 text-[11px]">
          <span className="shrink-0 text-slate-500">Place</span>
          <select value={position} onChange={(e) => setPosition(e.target.value === 'before' ? 'before' : 'after')} className="h-6 rounded border border-slate-200 bg-white px-1 text-[11px]">
            <option value="after">after</option>
            <option value="before">before</option>
          </select>
          <select value={anchorIdx} onChange={(e) => setAnchorIdx(Number(e.target.value))} className="h-6 min-w-0 flex-1 truncate rounded border border-slate-200 bg-white px-1 text-[11px]">
            {(anchors ?? []).map((a, i) => (
              <option key={`${a.text}-${i}`} value={i}>{`H${a.level}: ${a.text}`}</option>
            ))}
          </select>
        </div>
      )}

      {/* ── The text: ONE fixed-shape, directly editable surface. Formatting
             lives in the SELECT-TEXT popover (owner correction — no permanent
             toolbar): select text → the floating B/I/U/Link/H1/H2/• menu. ── */}
      <div className={isPage ? 'flex min-h-0 flex-1' : 'contents'}>
      <div
        className={`${isPage ? 'min-h-0 flex-1 px-8 py-4' : 'h-[280px] px-3 py-2'} overflow-auto bg-white`}
        title={readOnly ? 'Read-only here — section editing runs via dynamic rules on connected sites.' : undefined}
      >
        {isPage && pageQuery.isLoading && (
          <div className="flex h-full items-center justify-center gap-2 text-xs text-slate-500">
            <Loader2 className="h-4 w-4 animate-spin text-primary" /> Loading the served page…
          </div>
        )}
        {isPage && pageError && (
          <div className="flex h-full items-center justify-center px-8 text-center text-xs text-slate-500">{pageError}</div>
        )}
        {!readOnly && editor && (
          <BubbleMenu
            editor={editor}
            shouldShow={({ state }: { state: any }) => !state.selection.empty && !state.selection.node}
            className="flex items-center gap-0.5 rounded-md border border-slate-200 bg-white p-0.5 shadow-md"
          >
            <ToolButton title="Bold" active={editor.isActive('bold')} onClick={() => editor.chain().focus().toggleBold().run()}><BoldIcon className="h-3.5 w-3.5" /></ToolButton>
            <ToolButton title="Italic" active={editor.isActive('italic')} onClick={() => editor.chain().focus().toggleItalic().run()}><ItalicIcon className="h-3.5 w-3.5" /></ToolButton>
            <ToolButton title="Underline" active={editor.isActive('underline')} onClick={() => editor.chain().focus().toggleUnderline().run()}><UnderlineIcon className="h-3.5 w-3.5" /></ToolButton>
            <ToolButton
              title={editor.isActive('link') ? 'Remove link' : 'Add link'}
              active={editor.isActive('link')}
              onClick={() => {
                if (editor.isActive('link')) { editor.chain().focus().unsetLink().run(); return; }
                // eslint-disable-next-line no-alert
                const url = window.prompt('Link URL');
                if (url) editor.chain().focus().setLink({ href: url }).run();
              }}
            ><LinkIcon className="h-3.5 w-3.5" /></ToolButton>
            <div className="mx-0.5 h-4 w-px bg-slate-200" />
            <ToolButton title="Heading 1" active={editor.isActive('heading', { level: 1 })} onClick={() => editor.chain().focus().toggleHeading({ level: 1 }).run()}><Heading1 className="h-3.5 w-3.5" /></ToolButton>
            <ToolButton title="Heading 2" active={editor.isActive('heading', { level: 2 })} onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()}><Heading2 className="h-3.5 w-3.5" /></ToolButton>
            <ToolButton title="Bullet list" active={editor.isActive('bulletList')} onClick={() => editor.chain().focus().toggleBulletList().run()}><List className="h-3.5 w-3.5" /></ToolButton>
          </BubbleMenu>
        )}
        {(!isPage || pageReady) && (
          <div className={isPage ? 'mx-auto w-full max-w-[820px] px-8 pb-16 pt-6' : 'contents'}>
            <EditorContent
              editor={editor}
              className={`${isPage ? `${PAGE_TYPE_SCALE} ${BLOCK_STYLES}` : TYPE_SCALE} [&_.ProseMirror]:outline-none [&_.ProseMirror]:min-h-[250px]`
                // Locked context images: visible, clearly not editable.
                + (isPage ? ' [&_img]:my-2 [&_img]:max-w-full [&_img]:rounded [&_img[data-pcm-locked]]:cursor-not-allowed [&_img[data-pcm-locked]]:opacity-90' : '')}
            />
          </div>
        )}
      </div>

      {/* ── AI review rail (code-review style): one row per section — Accept /
             Reject per diff, Accept all, Reject all. The editor is read-only
             until every section is resolved. ── */}
      {isPage && review && (
        <aside className="flex w-[250px] shrink-0 flex-col border-l border-slate-200 bg-slate-50/60">
          <div className="border-b border-slate-200 px-2.5 py-1.5">
            <div className="text-[11px] font-medium text-slate-700">
              AI review — {review.filter((s) => s.status === 'pending' || s.status === 'diff').length} of {review.filter((s) => s.status !== 'clean').length} left
            </div>
            <div className="mt-1 flex items-center gap-1.5">
              <button
                type="button"
                onClick={acceptAllDiffs}
                disabled={!review.some((s) => s.status === 'diff')}
                className="inline-flex items-center gap-1 rounded bg-green-600 px-1.5 py-0.5 text-[10px] font-medium text-white hover:bg-green-500 disabled:opacity-50"
              >
                <Check className="h-3 w-3" /> Accept all
              </button>
              <button
                type="button"
                onClick={() => { setReviseTarget('all'); setReviseNote(''); }}
                disabled={!review.some((s) => s.status === 'diff')}
                title="Send every undecided section back to the AI with ONE adjustment"
                className="inline-flex items-center gap-1 rounded border border-primary/40 px-1.5 py-0.5 text-[10px] text-primary hover:bg-[#e7f5ff] disabled:opacity-50"
              >
                ↻ Revise all
              </button>
              <button
                type="button"
                onClick={finishReview}
                title="Finish — accepted changes stay, everything undecided keeps its original"
                className="inline-flex items-center gap-1 rounded border border-slate-200 px-1.5 py-0.5 text-[10px] font-medium text-slate-700 hover:bg-slate-100"
              >
                <Check className="h-3 w-3" /> OK
              </button>
            </div>
          </div>
          {/* The Revise box (owner F2): one note, Adjust sends it. Serves both
              a single section (chip or rail Revise) and Revise-all. */}
          {reviseTarget !== null && (
            <div className="border-b border-slate-200 bg-white px-2.5 py-1.5">
              <div className="mb-1 truncate text-[10px] font-medium text-slate-500">
                {reviseTarget === 'all'
                  ? 'Revise all undecided sections'
                  : `Revise: ${review[reviseTarget]?.heading || '(untitled section)'}`}
              </div>
              <input
                autoFocus
                value={reviseNote}
                onChange={(e) => setReviseNote(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') runRevise();
                  if (e.key === 'Escape') setReviseTarget(null);
                }}
                placeholder="e.g. “shorter, and mention the guarantee”"
                className="h-6 w-full rounded border border-slate-200 bg-white px-1.5 text-[11px] text-slate-800 outline-none focus:border-primary"
              />
              <div className="mt-1 flex items-center gap-1.5">
                <button
                  type="button"
                  onClick={runRevise}
                  disabled={!reviseNote.trim()}
                  className="inline-flex items-center gap-1 rounded-full bg-green-600 px-2 py-0.5 text-[10px] font-medium text-white hover:bg-green-500 disabled:opacity-50"
                >
                  Adjust
                </button>
                <button
                  type="button"
                  onClick={() => setReviseTarget(null)}
                  className="inline-flex items-center rounded-full border border-slate-200 bg-white px-2 py-0.5 text-[10px] text-slate-500 hover:bg-slate-100"
                >
                  Cancel
                </button>
              </div>
            </div>
          )}
          <div className="min-h-0 flex-1 overflow-auto py-1">
            {review.map((s, i) => s.status === 'clean' ? null : (
              <div
                key={`${s.heading}-${i}`}
                onClick={() => focusSection(i)}
                title="Click to jump to this section"
                className="cursor-pointer border-b border-slate-100 px-2.5 py-1.5 hover:bg-slate-100/60"
              >
                <div className="truncate text-[11px] font-medium text-slate-700" title={s.heading}>{s.heading || '(untitled section)'}</div>
                {/* PURPOSE VERIFICATION (owner law 2026-07-13): the suggestion
                    shows WHAT IT WAS ORDERED TO DO — pills + the compiled
                    to-do — so fulfillment is judged before Accept/Revise.
                    The model moves into the tooltip; plain runs (no basket)
                    keep the model line, honestly. */}
                {runDirectives ? (
                  <div title={s.genModel ? `Generated by ${s.genModel}` : undefined}>
                    {/* THE TWO PURPOSES the user knows (owner ruling
                        2026-07-15): one SEO / one AI pill max, then the
                        plain category names as a quiet italic line — the
                        WHY next to the WHAT. */}
                    <div className="mt-0.5 flex flex-wrap items-center gap-1">
                      {(['search', 'ai'] as const)
                        .filter((g) => runDirectives.some((d) => d.purposes.some((p) => (teacherById[p]?.group ?? 'search') === g)))
                        .map((g) => (
                          <span key={g} className={`rounded-full px-1.5 py-px text-[8px] font-semibold ${GROUP_PILLS[g].className}`}>
                            {GROUP_PILLS[g].label}
                          </span>
                        ))}
                    </div>
                    <div className="mt-0.5 text-[9px] italic leading-tight text-slate-400">
                      {Array.from(new Set(runDirectives.flatMap((d) => d.purposes)))
                        .map((p) => (teacherById[p]?.label ?? TEACHER_PILLS[p] ?? p).toLowerCase())
                        .join(', ')}
                    </div>
                    <div className="mt-0.5 space-y-px" title={runDirectives.map((d, k) => `${k + 1}. ${d.text}`).join('\n')}>
                      {runDirectives.slice(0, 3).map((d, k) => (
                        <div key={k} className="text-[9px] leading-tight text-slate-400">• {d.text}</div>
                      ))}
                      {runDirectives.length > 3 && (
                        <div className="text-[9px] leading-tight text-slate-400">+{runDirectives.length - 3} more…</div>
                      )}
                    </div>
                  </div>
                ) : s.genModel ? (
                  <div className="truncate text-[9px] text-slate-400" title={`Generated by ${s.genModel}`}>{s.genModel}</div>
                ) : null}
                {s.status === 'pending' && (
                  <div className="mt-0.5 inline-flex items-center gap-1 text-[10px] text-slate-500">
                    <Loader2 className="h-3 w-3 animate-spin text-primary" /> Rewriting…
                  </div>
                )}
                {s.status === 'diff' && (
                  <div className="mt-1 flex items-center gap-1.5">
                    <button
                      type="button"
                      onClick={(e) => { e.stopPropagation(); resolveSection(i, 'accept'); }}
                      className="inline-flex items-center gap-1 rounded-full bg-green-600 px-1.5 py-0.5 text-[10px] font-medium text-white hover:bg-green-500"
                    >
                      <Check className="h-3 w-3" /> Accept
                    </button>
                    <button
                      type="button"
                      onClick={(e) => { e.stopPropagation(); setReviseTarget(i); setReviseNote(''); }}
                      title="Send this section back to the AI with an adjustment"
                      className="inline-flex items-center gap-1 rounded-full border border-primary/40 bg-white px-1.5 py-0.5 text-[10px] text-primary hover:bg-[#e7f5ff]"
                    >
                      ↻ Revise
                    </button>
                    <button
                      type="button"
                      onClick={(e) => { e.stopPropagation(); resolveSection(i, 'reject'); }}
                      className="inline-flex items-center gap-1 rounded-full border border-slate-200 bg-white px-1.5 py-0.5 text-[10px] text-slate-600 hover:bg-slate-50"
                    >
                      <X className="h-3 w-3" /> Reject
                    </button>
                  </div>
                )}
                {s.status === 'accepted' && <div className="mt-0.5 text-[10px] font-medium text-green-700">✓ Accepted</div>}
                {s.status === 'rejected' && <div className="mt-0.5 text-[10px] text-slate-500">Rejected — original kept</div>}
                {s.status === 'failed' && (
                  <div className="mt-0.5 text-[10px] text-red-600" title={s.error}>AI failed — original kept</div>
                )}
              </div>
            ))}
          </div>
        </aside>
      )}

      {/* ── THE ANALYZE RAIL (optimizer spine): teachers' suggestions →
             basket → ONE optimize run through the normal red/green review.
             Yields to the review rail and the image panel. ── */}
      {isPage && !readOnly && analyzeOpen && !review && !imgSel && pageReady && (
        <OptimizerRail
          siteId={siteId as number}
          postId={postId}
          pageType={pageType}
          model={aiPick?.id ?? model}
          provider={aiPick?.provider ?? provider}
          getHtml={() => stripDiffHtml(editor?.getHTML() ?? '')}
          // THE KEYWORD PACKAGE — the drawer's LIVE state rides every
          // analysis (the html's source-of-truth law, same reasons).
          getKeywords={() => ({
            primary: primaryKw.trim(),
            supporting: supportingKw.split(',').map((s) => s.trim()).filter(Boolean),
            additional: kwBucket.keywords,
          })}
          pages={sitePages ?? []}
          onClose={() => setAnalyzeOpen(false)}
          onOptimize={(directives) => {
            setAnalyzeOpen(false);
            const topic = `Apply exactly these optimizations to the content:\n${directives
              .map((d, i) => `${i + 1}. ${d.text}`)
              .join('\n')}`;
            void startAiReview(topic, null, directives);
          }}
        />
      )}

      {/* ── Image metadata panel (V3): alt/title as a dynamic rule — the image
             itself is locked (position/existence never change). ── */}
      {isPage && !review && !readOnly && imgSel && (
        <aside className="flex w-[250px] shrink-0 flex-col border-l border-slate-200 bg-slate-50/60">
          <div className="border-b border-slate-200 px-2.5 py-1.5">
            <div className="text-[11px] font-medium text-slate-700">Image metadata</div>
            <div className="truncate text-[10px] text-slate-400" title={imgSel.src}>{imgSel.src}</div>
          </div>
          <div className="space-y-2 px-2.5 py-2">
            <label className="block">
              <span className="text-[10px] font-medium text-slate-500">Alt text</span>
              <input
                value={imgForm.alt}
                onChange={(e) => setImgForm((f) => ({ ...f, alt: e.target.value }))}
                placeholder="Describe the image"
                className="mt-0.5 h-6 w-full rounded border border-slate-200 bg-white px-1.5 text-[11px] text-slate-800 outline-none focus:border-primary"
              />
            </label>
            <label className="block">
              <span className="text-[10px] font-medium text-slate-500">Title</span>
              <input
                value={imgForm.title}
                onChange={(e) => setImgForm((f) => ({ ...f, title: e.target.value }))}
                placeholder="Tooltip title (optional)"
                className="mt-0.5 h-6 w-full rounded border border-slate-200 bg-white px-1.5 text-[11px] text-slate-800 outline-none focus:border-primary"
              />
            </label>
            <div className="flex flex-wrap items-center gap-1.5 pt-0.5">
              <button
                type="button"
                onClick={() => { void saveImageMeta('save'); }}
                className="inline-flex items-center gap-1 rounded bg-green-600 px-2 py-1 text-[10px] font-medium text-white hover:bg-green-500"
              >
                {busyAction === 'imageSave' ? <Loader2 className="h-3 w-3 animate-spin" /> : <Check className="h-3 w-3" />} Save
              </button>
              <button
                type="button"
                onClick={() => { void saveImageMeta('hide'); }}
                title="Stop serving this image — it stays in the media library; restore it via the versions dropdown"
                className="inline-flex items-center gap-1 rounded border border-red-200 bg-white px-2 py-1 text-[10px] text-red-600 hover:bg-red-50"
              >
                {busyAction === 'imageHide' ? <Loader2 className="h-3 w-3 animate-spin" /> : <Trash2 className="h-3 w-3" />} Hide image
              </button>
              {imgHasRule && (
                <button
                  type="button"
                  onClick={() => { void saveImageMeta('revert'); }}
                  title="Delete this image's metadata rule — the original alt/title serve again"
                  className="inline-flex items-center gap-1 rounded border border-slate-200 bg-white px-2 py-1 text-[10px] text-slate-600 hover:bg-slate-50"
                >
                  {busyAction === 'imageRevert' ? <Loader2 className="h-3 w-3 animate-spin" /> : <Undo2 className="h-3 w-3" />} Revert to original
                </button>
              )}
            </div>
            <p className="text-[10px] leading-relaxed text-slate-400">
              Served dynamically — the image file is never touched. Hiding stops it serving; deleting it in the text (or with its section) does the same on save.
            </p>
          </div>
        </aside>
      )}
      </div>

      {/* ── [✓ Save] [↶ Undo] (+ Remove for existing added sections) ── */}
      {!readOnly && (
        <div className="flex items-center gap-1.5 border-t border-slate-200 bg-white px-2.5 py-1.5">
          {/* Order (owner): Save & close → Save (page mode, stays open) → Undo.
              The pill family (owner 2026-07-13): Save & close = the SHARED
              green success pill; the rest are rounded siblings, same height. */}
          <PillButton
            variant="success"
            icon={<Check />}
            loading={busyAction === 'saveClose'}
            disabled={isPage && (!pageReady || !!review)}
            onClick={() => { void save().then((ok) => { if (ok) onClose(); }); }}
          >
            {isPage ? 'Save & close' : 'Save'}
          </PillButton>
          {isPage && (
            <button
              type="button"
              onClick={() => { void save(undefined, 'save'); }}
              disabled={!pageReady || !!review}
              title="Save — the window stays open"
              className="inline-flex items-center gap-1 rounded-full border border-green-600 bg-white px-2.5 py-1 text-xs font-medium text-green-700 hover:bg-green-50 disabled:opacity-60"
            >
              {busyAction === 'save' ? <Loader2 className="h-3 w-3 animate-spin" /> : <Save className="h-3 w-3" />} Save
            </button>
          )}
          <button
            type="button"
            onClick={() => { if (!busy) editor?.commands.setContent(savedHtml); }}
            disabled={isPage && (!pageReady || !!review)}
            title="Restore the last saved state"
            className="inline-flex items-center gap-1 rounded-full border border-slate-200 px-2.5 py-1 text-xs text-slate-600 hover:bg-slate-50 disabled:opacity-60"
          >
            <Undo2 className="h-3 w-3" /> Undo
          </button>
          <div className="flex-1" />
          {isInsert && !!insert?.ruleId && (
            <button
              type="button"
              onClick={() => { void save('', 'remove').then((ok) => { if (ok) onClose(); }); }}
              title="Remove this added section from the live page"
              className="inline-flex items-center gap-1 rounded border border-slate-200 px-2 py-1 text-[11px] text-slate-600 hover:bg-slate-50 hover:text-destructive"
            >
              {busyAction === 'remove' ? <Loader2 className="h-3 w-3 animate-spin" /> : <Trash2 className="h-3 w-3" />} Remove
            </button>
          )}
        </div>
      )}

    </div>
  );

  return createPortal(
    <>
      {/* Page mode: dimmed + blurred backdrop (clicks bubble to the document —
          the existing outside-click flow is untouched). */}
      {isPage && <div className="fixed inset-0 z-30 bg-slate-900/30 backdrop-blur-sm" />}
      {isPage ? (
        // The centered PAIR: layout derives all geometry — the card tapers
        // while the drawer is open, the pair stays centered as one unit.
        <div className="fixed inset-0 z-40 flex items-center justify-center">
          {drawerEl}
          {cardEl}
        </div>
      ) : (
        cardEl
      )}
    </>,
    document.body,
  );
}
