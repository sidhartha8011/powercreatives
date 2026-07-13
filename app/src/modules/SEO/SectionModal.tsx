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
import { Extension, Mark, Node } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Image from '@tiptap/extension-image';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';
import type { Node as PMNode } from '@tiptap/pm/model';
import {
  X, Sparkles, Loader2, Check, Undo2, Trash2, MessageSquarePlus,
  BoldIcon, ItalicIcon, UnderlineIcon, Link as LinkIcon,
  Heading1, Heading2, List, ExternalLink, Save, ImagePlus, MessageCircleQuestion, FileText,
} from 'lucide-react';
import { toast } from 'sonner';

import { trpc } from '@/lib/trpc';
import { useTextModels } from '@/modules/Copy/useTextModels';
import {
  diffBlocksHtml, splitDocSections, stripDiffHtml, type DocSection,
} from './word-diff';

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
   *  the WP-editor escape hatch + the live permalink. */
  page?: { title: string; editUrl?: string; date?: string; permalink?: string };
  /** Anchor choices when creating a NEW section. */
  anchors?: SectionAnchor[];
  /** Where the user clicked — the window opens right below it. */
  anchorPoint?: { x: number; y: number };
  onClose: () => void;
  onSaved: () => void;
}

const WIDTH = 440;

/** Page mode's OWN reading scale (owner order U2): the document must read
 *  like the live page — real paragraph air, stepped heading sizes — while
 *  section mode keeps the compact scale above, byte-identical. */
const PAGE_TYPE_SCALE =
  'text-[15px] leading-7 text-slate-800 break-words ' +
  '[&_h1]:font-serif [&_h1]:text-[27px] [&_h1]:leading-9 [&_h1]:font-bold [&_h1]:mt-10 [&_h1]:mb-3 ' +
  '[&_h2]:text-[21px] [&_h2]:leading-8 [&_h2]:font-semibold [&_h2]:mt-9 [&_h2]:mb-2.5 ' +
  '[&_h3]:text-[17px] [&_h3]:font-semibold [&_h3]:mt-7 [&_h3]:mb-2 ' +
  '[&_h4]:text-[15px] [&_h4]:font-semibold [&_h4]:mt-6 [&_h4]:mb-1.5 ' +
  '[&_h5]:text-[15px] [&_h5]:font-medium [&_h5]:mt-5 [&_h5]:mb-1 ' +
  '[&_h6]:text-[15px] [&_h6]:font-medium [&_h6]:mt-5 [&_h6]:mb-1 ' +
  '[&_p]:my-4 [&_ul]:my-4 [&_ul]:pl-6 [&_ul]:list-disc [&_ol]:my-4 [&_ol]:pl-6 [&_ol]:list-decimal ' +
  '[&_li]:my-1.5 [&_a]:text-primary [&_a]:underline [&_a]:decoration-dotted ' +
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
  renderHTML() { return ['span', { 'data-diff-added': '1', class: 'rounded-sm bg-green-100 text-green-900' }, 0]; },
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
function blockDecorations(doc: PMNode): DecorationSet {
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
  for (const r of ranges) {
    const origin = BLOCK_ORIGIN_CLASS[r.origin] ?? 'pcm-blk-added';
    const inside = blocks.filter((b) => b.pos >= r.from && b.end <= r.to);
    inside.forEach((b, i) => {
      const role = `${i === 0 ? ' pcm-blk-start' : ''}${i === inside.length - 1 ? ' pcm-blk-end' : ''}`;
      decos.push(Decoration.node(b.pos, b.end, { class: `pcm-blk ${origin}${role}` }));
    });
  }
  return DecorationSet.create(doc, decos);
}
const SectionBlocks = Extension.create({
  name: 'pcmSectionBlocks',
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
        },
      },
    ];
  },
  addProseMirrorPlugins() {
    return [
      new Plugin({
        key: new PluginKey('pcmSectionBlocks'),
        props: {
          decorations: (state) => blockDecorations(state.doc),
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
  '[&_.pcm-blk]:px-6 [&_.pcm-blk]:my-0 [&_.pcm-blk]:py-2 ' +
  '[&_.pcm-blk-start]:border-t [&_.pcm-blk-start]:border-t-slate-100 [&_.pcm-blk-start]:rounded-tr-lg [&_.pcm-blk-start]:pt-5 [&_.pcm-blk-start]:mt-8 ' +
  '[&_.pcm-blk-end]:border-b [&_.pcm-blk-end]:border-b-slate-100 [&_.pcm-blk-end]:rounded-br-lg [&_.pcm-blk-end]:pb-5 ' +
  '[&_.pcm-blk-original]:border-l-slate-300 [&_.pcm-blk-owned]:border-l-amber-400 [&_.pcm-blk-added]:border-l-sky-400 ' +
  '[&_img.pcm-blk]:block [&_img.pcm-blk]:border-y-0 [&_img.pcm-blk]:border-r-0 [&_img.pcm-blk]:rounded-none ' +
  '[&_summary]:list-none [&_summary]:!cursor-text [&_.pcm-deadzone]:opacity-60';

/** One section under AI review. pending/diff block saving; the rest are resolved. */
type ReviewStatus = 'pending' | 'diff' | 'accepted' | 'rejected' | 'clean' | 'failed';
interface ReviewSection extends DocSection {
  status: ReviewStatus;
  ai?: string;
  error?: string;
}

/** Visible text of an HTML fragment (whitespace-collapsed) — clean-result check. */
function htmlText(html: string): string {
  const el = document.createElement('div');
  el.innerHTML = html;
  return (el.textContent ?? '').replace(/\s+/g, ' ').trim();
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
  siteId, postId, type, model, provider, readOnly, mode, section, insert, page, anchors, anchorPoint, onClose, onSaved,
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
  const [busy, setBusy] = useState(false);
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
      ...(isPage ? [LockedImage, DiffAdded, DiffRemoved, SectionBlocks, FaqItem, FaqSummary] : []),
    ],
    content: openedHtml,
    // Baseline for dirty-checks must be the EDITOR's normalized form of the
    // opened content (TipTap reorders attrs etc.) — otherwise an untouched
    // window would "save" on every outside click.
    onCreate: ({ editor: ed }) => setSavedHtml(ed.getHTML()),
  });

  // Page mode opens empty and loads the fetched document ONCE (dirty-baseline
  // = the editor's normalized form of it, same law as onCreate). The ref
  // guard is the 2026-07-11 incident fix: the editor must NEVER auto-replace
  // its content afterwards — a degraded refetch once clobbered a full
  // document in front of the owner. Version picks load content explicitly.
  const pageLoadedRef = useRef(false);
  useEffect(() => {
    if (!isPage || !editor || !pageReady || pageLoadedRef.current) return;
    pageLoadedRef.current = true;
    editor.commands.setContent(pageHtml);
    setSavedHtml(editor.getHTML());
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isPage, editor, pageReady, pageHtml]);

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
  const aiModelSelect = textModels.length > 0 ? (
    <select
      value={aiPick?.id ?? ''}
      onChange={(e) => pickAiModel(e.target.value)}
      disabled={busy}
      title="AI model used by this editor's AI actions"
      className="h-[23px] max-w-[150px] shrink-0 truncate rounded border border-slate-200 bg-white px-1 text-[11px] text-slate-600 outline-none hover:bg-slate-50 disabled:opacity-60"
    >
      {modelGroups.map((g) => (
        <optgroup key={g.tier} label={g.label}>
          {g.models.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
        </optgroup>
      ))}
    </select>
  ) : null;

  // ── Version history (replace-sections only — inserts have no Original). ──
  const versionsQuery = trpc.seo.remoteSectionVersions.useQuery(
    {
      siteId: siteId as number, postId,
      text: section?.heading.text ?? '', occurrence: section?.heading.occurrence ?? 0,
    },
    { enabled: !readOnly && !isInsert && !!section, staleTime: 0 },
  );
  const versionsData: any = isPage ? pageVersionsQuery.data : versionsQuery.data;
  const versions: Array<{ id: number; replacement: string; createdAt: string }> =
    Array.isArray(versionsData?.versions) ? versionsData.versions : [];
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
   *  the Original row shows the page's own date. */
  const pageRowLabel = (v: { createdAt: string }, idx: number) =>
    `${v.createdAt.slice(0, 16)}${idx === 0 ? ' (Current)' : ''}`;
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

  const isDirty = () => !readOnly && !!editor && editor.getHTML() !== savedHtml;

  // ── Save (save buttons / click outside): live rules, engine handles UPSERT/revert. ──
  const save = async (replacementOverride?: string): Promise<boolean> => {
    if (readOnly) return true;
    if (isPage) {
      if (!pageReady) return true; // nothing loaded — nothing to save
      if (review) {
        toast.info('Finish the AI review first — accept or reject each change.');
        return false;
      }
      // Guard: diff marks are presentation and must NEVER reach a save.
      const html = stripDiffHtml(replacementOverride ?? (editor?.getHTML() ?? ''));
      setBusy(true);
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
        setBusy(false);
      }
    }
    if (!isInsert && !section) return true;
    const replacement = replacementOverride ?? (editor?.getHTML() ?? '');
    setBusy(true);
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
      setBusy(false);
    }
  };

  // ── Click OUTSIDE = save (when changed) then close. Esc = close without saving. ──
  useEffect(() => {
    const onDown = (e: PointerEvent) => {
      const t = e.target as HTMLElement;
      if (rootRef.current?.contains(t)) return;
      if (t.closest('[data-sonner-toaster]')) return; // toasts are not "outside"
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
    setBusy(true);
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
      setBusy(false);
    }
  };

  // ── AI review (page mode, V2): per-section rewrites shown as an inline
  //    red/green diff; Accept applies the AI's clean HTML, Reject restores the
  //    original — the diff view itself is never what gets kept. The editor is
  //    read-only while the review runs; saving is blocked until every section
  //    is resolved. Locked images are lifted out per section (never sent to
  //    the AI) and ride along untouched.
  const [review, setReview] = useState<ReviewSection[] | null>(null);
  const [reviewOrphan, setReviewOrphan] = useState('');

  const startAiReview = async (topic: string) => {
    if (!editor || !pageReady || busy || review) return;
    const { orphanHtml, sections } = splitDocSections(editor.getHTML());
    if (sections.length === 0) {
      toast.info('No sections to optimize on this page.');
      return;
    }
    setAskOpen(false);
    setReviewOrphan(orphanHtml);
    setReview(sections.map((s) => ({ ...s, status: 'pending' as ReviewStatus })));
    editor.setEditable(false);
    // Bounded pool: 4 sections in flight; a slow/failed section fails ALONE.
    let next = 0;
    const worker = async () => {
      while (next < sections.length) {
        const i = next++;
        try {
          const res: any = await optimizeMutation.mutateAsync({
            siteId: siteId as number, postId, type,
            html: sections[i].html, topic,
            model: aiPick?.id ?? model, provider: aiPick?.provider ?? provider,
          });
          const value = String(res?.value ?? '').trim();
          const changed = value !== '' && htmlText(value) !== htmlText(sections[i].html);
          setReview((cur) => cur?.map((s, k) => (k === i && s.status === 'pending'
            ? (changed ? { ...s, status: 'diff' as ReviewStatus, ai: value } : { ...s, status: 'clean' as ReviewStatus })
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

  const resolveSection = (i: number, action: 'accept' | 'reject') =>
    setReview((cur) => cur?.map((s, k) => (k === i && s.status === 'diff'
      ? { ...s, status: (action === 'accept' && s.ai ? 'accepted' : 'rejected') as ReviewStatus }
      : s)) ?? cur);
  const acceptAllDiffs = () =>
    setReview((cur) => cur?.map((s) => (s.status === 'diff' && s.ai ? { ...s, status: 'accepted' as ReviewStatus } : s)) ?? cur);
  const cancelReview = () =>
    setReview((cur) => cur?.map((s) => (s.status === 'diff' || s.status === 'pending' ? { ...s, status: 'rejected' as ReviewStatus } : s)) ?? cur);

  // Rebuild the document from the review state; when every section is
  // resolved the review ends — the doc is final content, editing returns.
  useEffect(() => {
    if (!editor || !review) return;
    const doc = reviewOrphan + review.map((s) => {
      const content = s.status === 'diff' && s.ai
        ? diffBlocksHtml(s.html, s.ai)
        : (s.status === 'accepted' && s.ai ? s.ai : s.html);
      return content + s.imgs.join('');
    }).join('');
    editor.commands.setContent(doc);
    if (review.every((s) => s.status !== 'pending' && s.status !== 'diff')) {
      const accepted = review.filter((s) => s.status === 'accepted').length;
      setReview(null);
      editor.setEditable(true);
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
    if (typeof wp === 'undefined' || !wp.media) {
      toast.error('The media library isn’t available on this screen.');
      return;
    }
    const frame = wp.media({ title: 'Add image', multiple: false, library: { type: 'image' } });
    frame.on('select', () => {
      const att = frame.state().get('selection').first().toJSON();
      void (async () => {
        setBusy(true);
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
          setBusy(false);
        }
      })();
    });
    frame.open();
  };

  const saveImageMeta = async (mode: 'save' | 'revert' | 'hide') => {
    if (!imgSel || busy) return;
    setBusy(true);
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
      setBusy(false);
    }
  };

  const title = isPage
    ? `📄 ${page?.title ?? 'Page'}`
    : isInsert
      ? (insert?.ruleId ? '¶ Added section' : '¶ New section')
      : `¶ ${section?.heading.text ?? ''}`;
  const served = !isInsert && !isPage && !!section?.sectionRuleReplacement;

  return createPortal(
    <div
      ref={rootRef}
      className={`fixed z-40 flex flex-col overflow-hidden border border-slate-200 bg-white ${isPage ? 'rounded-xl shadow-2xl' : 'rounded-lg shadow-xl'}`}
      style={isPage
        ? { left: '50%', top: '50%', transform: 'translate(-50%, -50%)', width: 'min(980px, 94vw)', minWidth: 720, height: '90vh', maxWidth: 'calc(100vw - 32px)' }
        : { left: pos.x, top: pos.y, width: WIDTH, maxWidth: 'calc(100vw - 16px)' }}
      role="dialog"
      aria-label={title}
    >
      {/* ── Header: ¶ title + [Ask AI] [Re-write] [X] — draggable (page mode: fixed, centered) ── */}
      <div
        className={`flex select-none items-center border-b border-slate-100 bg-white ${isPage ? 'gap-2 px-4 py-2.5' : 'gap-1.5 border-slate-200 px-2.5 py-1.5 cursor-grab active:cursor-grabbing'}`}
        onPointerDown={isPage ? undefined : onDragStart}
        onPointerMove={isPage ? undefined : onDragMove}
        onPointerUp={isPage ? undefined : onDragEnd}
      >
        <div className="flex min-w-0 flex-1 items-center gap-2">
          {isPage ? (
            <>
              <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-blue-50">
                <FileText className="h-4 w-4 text-blue-600" />
              </span>
              <span className="min-w-0">
                <span className="block truncate text-[13px] font-medium leading-4 text-slate-800" title={page?.title ?? 'Page'}>
                  {page?.title ?? 'Page'}
                </span>
                {page?.date && (
                  <span className="block truncate text-[11px] leading-4 text-slate-400">{String(page.date).slice(0, 16)}</span>
                )}
              </span>
            </>
          ) : (
            <span className="min-w-0 truncate text-xs font-medium text-slate-800" title={title}>
              {served && <span className="mr-1.5 inline-block h-1.5 w-1.5 rounded-full bg-primary align-middle" title="Optimized — a section rule serves this content" />}
              {title}
            </span>
          )}
          {/* Escape hatches live LEFT, beside the name (owner order):
              Edit = the site's WP editor, Open = the live page. */}
          {isPage && page?.editUrl && (
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
          {isPage && page?.permalink && (
            <a
              href={page.permalink}
              target="_blank"
              rel="noopener noreferrer"
              title="Open the live page in a new tab"
              className="inline-flex shrink-0 items-center gap-1 rounded px-1.5 py-0.5 text-[11px] text-slate-500 hover:bg-slate-100 hover:text-slate-800"
            >
              <ExternalLink className="h-3 w-3" /> Open
            </a>
          )}
        </div>
        {/* Version history: the button ALWAYS names the shown state (picked/
            Current/latest/Original — never a counter). The list: page mode adds
            Current (the live served document); Original (light-grey,
            undeletable — page mode: the rules-input document, hidden when the
            input view is unavailable); each accepted save with date/time and a
            delete button. Picking one loads it in the editor; saving makes
            it live (page mode: through the normal per-section save). */}
        {!readOnly && !isInsert && !review && (
          <div className="relative shrink-0">
            <button
              type="button"
              onClick={() => setVersionsOpen((v) => !v)}
              title="Versions — pick one to view it — saving makes it live"
              className="inline-flex h-6 max-w-[140px] items-center gap-1 truncate rounded border border-slate-200 bg-white px-1.5 text-[11px] text-slate-600 hover:bg-slate-50"
            >
              <span className="truncate">{versionLabel}</span>
              <span className="text-slate-400">▾</span>
            </button>
            {versionsOpen && (
              <div className="absolute right-0 top-full z-10 mt-1 w-[210px] overflow-hidden rounded-md border border-slate-200 bg-white py-0.5 shadow-md">
                {(!isPage || originalHtml !== '') && (
                <button
                  type="button"
                  onClick={() => pickVersion('original')}
                  className="block w-full bg-slate-50 px-2 py-1 text-left text-[11px] text-slate-700 hover:bg-slate-100"
                >
                  {isPage ? pageOriginalLabel : 'Original'}
                </button>
                )}
                {versions.map((v, idx) => (
                  <div key={v.id} className="flex items-center hover:bg-slate-50">
                    <button
                      type="button"
                      onClick={() => pickVersion(String(v.id))}
                      className="min-w-0 flex-1 truncate px-2 py-1 text-left text-[11px] text-slate-700"
                    >
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
        )}
        {/* Page mode AI (V2): Ask AI feeds one instruction to every section;
            AI Optimize runs the whole-page review. Hidden while reviewing. */}
        {!readOnly && isPage && pageReady && !review && (
          <>
            {aiModelSelect}
            <button
              type="button"
              onClick={addImage}
              disabled={busy}
              title="Add an image — delivered into the site’s own media library, placed at the cursor"
              className="inline-flex shrink-0 items-center gap-1 rounded px-1.5 py-0.5 text-[11px] text-slate-500 hover:bg-slate-100 hover:text-slate-800 disabled:opacity-60"
            >
              <ImagePlus className="h-3 w-3" /> Add image
            </button>
            <button
              type="button"
              onClick={() => editor?.chain().focus().insertContent(faqTemplate()).run()}
              disabled={busy}
              title="Add an FAQ section — a native accordion styled by the site itself"
              className="inline-flex shrink-0 items-center gap-1 rounded px-1.5 py-0.5 text-[11px] text-slate-500 hover:bg-slate-100 hover:text-slate-800 disabled:opacity-60"
            >
              <MessageCircleQuestion className="h-3 w-3" /> Add FAQ
            </button>
            <button
              type="button"
              onClick={() => setAskOpen((v) => !v)}
              disabled={busy}
              title="Tell the AI what to do with this page"
              className={`inline-flex shrink-0 items-center gap-1 rounded px-1.5 py-0.5 text-[11px] hover:bg-slate-100 disabled:opacity-60 ${askOpen ? 'text-primary bg-primary/5' : 'text-slate-500 hover:text-slate-800'}`}
            >
              <MessageSquarePlus className="h-3 w-3" /> Ask AI
            </button>
            <button
              type="button"
              onClick={() => { void startAiReview(instruction.trim()); }}
              disabled={busy}
              title="Rewrite the whole page with AI — every change shows as red/green for you to accept or reject"
              className="inline-flex shrink-0 items-center gap-1 rounded px-1.5 py-0.5 text-[11px] text-slate-500 hover:bg-slate-100 hover:text-slate-800 disabled:opacity-60"
            >
              <Sparkles className="h-3 w-3" /> AI Optimize
            </button>
          </>
        )}
        {!readOnly && !isPage && (
          <>
            {aiModelSelect}
            <button
              type="button"
              onClick={() => setAskOpen((v) => !v)}
              disabled={busy}
              title="Tell the AI what to do with this section"
              className={`inline-flex shrink-0 items-center gap-1 rounded border border-slate-200 px-1.5 py-0.5 text-[11px] hover:bg-slate-50 disabled:opacity-60 ${askOpen ? 'text-primary border-primary/40' : 'text-slate-600'}`}
            >
              <MessageSquarePlus className="h-3 w-3" /> Ask AI
            </button>
            <button
              type="button"
              onClick={() => runAi('')}
              disabled={busy}
              title={isInsert ? 'Draft this section with AI' : 'Rewrite this section with AI'}
              className="inline-flex shrink-0 items-center gap-1 rounded border border-slate-200 px-1.5 py-0.5 text-[11px] text-slate-600 hover:bg-slate-50 hover:text-primary disabled:opacity-60"
            >
              {busy ? <Loader2 className="h-3 w-3 animate-spin text-primary" /> : <Sparkles className="h-3 w-3" />}
              {isInsert ? 'Generate' : 'Re-write'}
            </button>
          </>
        )}
        <button type="button" onClick={onClose} title="Close (Esc) — closes without saving" className="shrink-0 rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700">
          <X className="h-3.5 w-3.5" />
        </button>
      </div>

      {/* ── Ask-AI instruction (Enter runs it) ── */}
      {askOpen && !readOnly && (
        <div className="border-b border-slate-200 px-2.5 py-1.5">
          <input
            autoFocus
            value={instruction}
            onChange={(e) => setInstruction(e.target.value)}
            onKeyDown={(e) => {
              if (e.key !== 'Enter' || !instruction.trim()) return;
              if (isPage) void startAiReview(instruction.trim());
              else void runAi(instruction.trim());
            }}
            placeholder="e.g. “make it shorter and add a price example” — Enter to run"
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
              AI review — {review.filter((s) => s.status === 'pending' || s.status === 'diff').length} of {review.length} left
            </div>
            <div className="mt-1 flex items-center gap-1.5">
              <button
                type="button"
                onClick={acceptAllDiffs}
                disabled={!review.some((s) => s.status === 'diff')}
                className="inline-flex items-center gap-1 rounded bg-green-600 px-1.5 py-0.5 text-[10px] font-medium text-white hover:bg-green-700 disabled:opacity-50"
              >
                <Check className="h-3 w-3" /> Accept all
              </button>
              <button
                type="button"
                onClick={cancelReview}
                className="inline-flex items-center gap-1 rounded border border-slate-200 px-1.5 py-0.5 text-[10px] text-slate-600 hover:bg-slate-100"
              >
                <X className="h-3 w-3" /> Reject all
              </button>
            </div>
          </div>
          <div className="min-h-0 flex-1 overflow-auto py-1">
            {review.map((s, i) => (
              <div key={`${s.heading}-${i}`} className="border-b border-slate-100 px-2.5 py-1.5">
                <div className="truncate text-[11px] font-medium text-slate-700" title={s.heading}>{s.heading || '(untitled section)'}</div>
                {s.status === 'pending' && (
                  <div className="mt-0.5 inline-flex items-center gap-1 text-[10px] text-slate-500">
                    <Loader2 className="h-3 w-3 animate-spin text-primary" /> Rewriting…
                  </div>
                )}
                {s.status === 'diff' && (
                  <div className="mt-1 flex items-center gap-1.5">
                    <button
                      type="button"
                      onClick={() => resolveSection(i, 'accept')}
                      className="inline-flex items-center gap-1 rounded bg-green-600 px-1.5 py-0.5 text-[10px] font-medium text-white hover:bg-green-700"
                    >
                      <Check className="h-3 w-3" /> Accept
                    </button>
                    <button
                      type="button"
                      onClick={() => resolveSection(i, 'reject')}
                      className="inline-flex items-center gap-1 rounded border border-slate-200 bg-white px-1.5 py-0.5 text-[10px] text-slate-600 hover:bg-slate-100"
                    >
                      <X className="h-3 w-3" /> Reject
                    </button>
                  </div>
                )}
                {s.status === 'accepted' && <div className="mt-0.5 text-[10px] font-medium text-green-700">✓ Accepted</div>}
                {s.status === 'rejected' && <div className="mt-0.5 text-[10px] text-slate-500">Rejected — original kept</div>}
                {s.status === 'clean' && <div className="mt-0.5 text-[10px] text-slate-500">No change suggested</div>}
                {s.status === 'failed' && (
                  <div className="mt-0.5 text-[10px] text-red-600" title={s.error}>AI failed — original kept</div>
                )}
              </div>
            ))}
          </div>
        </aside>
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
                disabled={busy}
                className="inline-flex items-center gap-1 rounded bg-green-600 px-2 py-1 text-[10px] font-medium text-white hover:bg-green-700 disabled:opacity-60"
              >
                {busy ? <Loader2 className="h-3 w-3 animate-spin" /> : <Check className="h-3 w-3" />} Save
              </button>
              <button
                type="button"
                onClick={() => { void saveImageMeta('hide'); }}
                disabled={busy}
                title="Stop serving this image — it stays in the media library; restore it via the versions dropdown"
                className="inline-flex items-center gap-1 rounded border border-red-200 bg-white px-2 py-1 text-[10px] text-red-600 hover:bg-red-50 disabled:opacity-60"
              >
                <Trash2 className="h-3 w-3" /> Hide image
              </button>
              {imgHasRule && (
                <button
                  type="button"
                  onClick={() => { void saveImageMeta('revert'); }}
                  disabled={busy}
                  title="Delete this image's metadata rule — the original alt/title serve again"
                  className="inline-flex items-center gap-1 rounded border border-slate-200 bg-white px-2 py-1 text-[10px] text-slate-600 hover:bg-slate-100 disabled:opacity-60"
                >
                  <Undo2 className="h-3 w-3" /> Revert to original
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
          {/* Order (owner): Save & close → Save (page mode, stays open) → Undo. */}
          <button
            type="button"
            onClick={() => { void save().then((ok) => { if (ok) onClose(); }); }}
            disabled={busy || (isPage && (!pageReady || !!review))}
            title="Save and close"
            className="inline-flex items-center gap-1 rounded bg-green-600 px-2 py-1 text-[11px] font-medium text-white hover:bg-green-700 disabled:opacity-60"
          >
            {busy ? <Loader2 className="h-3 w-3 animate-spin" /> : <Check className="h-3 w-3" />} {isPage ? 'Save & close' : 'Save'}
          </button>
          {isPage && (
            <button
              type="button"
              onClick={() => { void save(); }}
              disabled={busy || !pageReady || !!review}
              title="Save — the window stays open"
              className="inline-flex items-center gap-1 rounded border border-green-600 bg-white px-2 py-1 text-[11px] font-medium text-green-700 hover:bg-green-50 disabled:opacity-60"
            >
              {busy ? <Loader2 className="h-3 w-3 animate-spin" /> : <Save className="h-3 w-3" />} Save
            </button>
          )}
          <button
            type="button"
            onClick={() => editor?.commands.setContent(savedHtml)}
            disabled={busy || (isPage && (!pageReady || !!review))}
            title="Restore the last saved state"
            className="inline-flex items-center gap-1 rounded border border-slate-200 px-2 py-1 text-[11px] text-slate-600 hover:bg-slate-50 disabled:opacity-60"
          >
            <Undo2 className="h-3 w-3" /> Undo
          </button>
          <div className="flex-1" />
          {isInsert && !!insert?.ruleId && (
            <button
              type="button"
              onClick={() => { void save('').then((ok) => { if (ok) onClose(); }); }}
              disabled={busy}
              title="Remove this added section from the live page"
              className="inline-flex items-center gap-1 rounded border border-slate-200 px-2 py-1 text-[11px] text-slate-600 hover:bg-slate-50 hover:text-destructive disabled:opacity-60"
            >
              <Trash2 className="h-3 w-3" /> Remove
            </button>
          )}
        </div>
      )}

    </div>,
    document.body,
  );
}
