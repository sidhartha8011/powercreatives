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
import StarterKit from '@tiptap/starter-kit';
import type { Editor as TiptapEditor } from '@tiptap/core';
import {
  X, Sparkles, Loader2, Check, Undo2, Trash2, MessageSquarePlus,
  BoldIcon, ItalicIcon, UnderlineIcon, Link as LinkIcon,
  Heading1, Heading2, List, ExternalLink, Save, ImagePlus, MessageCircleQuestion, Eye, Plus, KeyRound,
  RefreshCw, CloudOff,
} from 'lucide-react';
import { toast } from 'sonner';
import { useQueryClient } from '@tanstack/react-query';

import { trpc } from '@/lib/trpc';
import { ModelDropdown, PillButton, PillSplitButton } from '@/components/shared';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import { useTextModels } from '@/modules/Copy/useTextModels';
import { stripDiffHtml } from './word-diff';
// THE DECOMPOSITION (S1-S3, gap 325d280/10a0233): the editor's laws,
// extensions, scales, contracts, the review rail, and THE RUN ENGINE all
// live in editor/ — this file only composes them.
import {
  DiffAdded, DiffRemoved, FaqItem, FaqSummary, LockedImage, ReviewControls, SectionBlocks,
} from './editor/extensions';
import { composeSectionHtml, escapeHtml, faqTemplate, normSrc, stripPcmAnchors } from './editor/content-laws';
import { BLOCK_STYLES, PAGE_TYPE_SCALE, TYPE_SCALE, WIDTH, pageCardWidth } from './editor/layout';
import { ToolButton } from './editor/ToolButton';
import type { ImgSelection, InsertData, SectionAnchor, SectionData, SectionParagraph } from './editor/types';
import { ReviewRail } from './editor/ReviewRail';
import { useAiReview } from './editor/useAiReview';
import { useImagePanel } from './editor/useImagePanel';
import { useSectionVersions } from './editor/useSectionVersions';

// The public contract other files import from here (HeadingsPanel) —
// unchanged by the decomposition.
export type { InsertData, SectionAnchor, SectionData, SectionParagraph } from './editor/types';
import { OptimizerRail } from './optimizer/OptimizerRail';
import { KeywordsDrawer, type TickedKeyword } from './optimizer/KeywordsDrawer';
import { useKeywordBucket } from './optimizer/useKeywordBucket';
import { keywordUses } from './optimizer/keywordStats';
import { Select, SelectContent, SelectItem, SelectTrigger } from '@/components/ui/select';
import { Pill } from '@/components/ui/pill';
import { statusPillVariant } from './types';
import { DROPDOWN_TRIGGER_STYLE } from '@/components/shared/ModelDropdown';

// The hub's native WP media library (wp_enqueue_media — same pattern as the
// table's featured-image picker).
declare const wp: any;

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

export function SectionModal({
  siteId, postId, type, model, provider, readOnly, mode, section, insert, page, sitePages, anchors, anchorPoint, onClose, onSaved,
}: SectionModalProps) {
  const isInsert = mode === 'insert';
  const isPage = mode === 'page';
  const rootRef = useRef<HTMLDivElement>(null);
  /** The editor instance's ref mirror — hooks that mount BEFORE useEditor
   *  (versions) resolve it at event time, never at render order. */
  const editorRef = useRef<TiptapEditor | null>(null);

  // ── THE VERSIONS MACHINERY (decomposition S4a): rows, the lazy Original,
  //    picks, labels, deletion — one owner in useSectionVersions. ──
  const {
    pageVersionsQuery, pageOriginalQuery, pageVersions, pageVersionsSettled,
    versionsOpen, setVersionsOpen, originalHtml,
    versionsQuery, versions, versionPick, setVersionPick, pickVersion,
    pageRowLabel, pageOriginalLabel, versionLabelFor,
    deleteVersion, versionSel, setVersionSel, bulkDeleting, toggleVersionSel,
    deletableVersionIds, deleteSelectedVersions,
  } = useSectionVersions({
    editorRef, isPage, isInsert, readOnly, siteId, postId, section,
    pageDate: page?.date,
    sectionOriginalHtml: !isInsert && section
      ? (section.heading.html || `<h${section.heading.level}>${escapeHtml(section.heading.text)}</h${section.heading.level}>`)
        + section.paragraphs.map((p) => p.html).join('')
      : '',
  });

  // ── INSTANT OPEN (gap e48b1ff): the heavy served-page assembly is LAZY —
  //    fetched only when there is no saved version to open from, or when the
  //    user explicitly asks for the live view. The open never waits for the
  //    client site to render a page it doesn't need. ──
  const [liveViewWanted, setLiveViewWanted] = useState(false);
  const inventoryNeeded = isPage && !readOnly
    && (liveViewWanted || (pageVersionsSettled && pageVersions.length === 0));
  const pageQuery = trpc.seo.remoteGetInventory.useQuery(
    { siteId: siteId as number, postId, type },
    { enabled: inventoryNeeded, staleTime: 0, refetchOnMount: 'always' },
  );
  const pageHtml = isPage ? String((pageQuery.data as any)?.contentHtml ?? '') : '';
  const pageReady = isPage && (pageQuery.data as any)?.view === 'served' && pageHtml !== '';
  const pageError = isPage && inventoryNeeded && !pageQuery.isLoading && !pageQuery.isPending && !pageReady
    ? ((pageQuery.data as any)?.error === 'loopback_blocked'
      ? 'Page editing unavailable — the site blocked the connector’s content fetch.'
      : 'Page editing needs the served page view (connector 3.0.1+ on this site) — update it from the Sites module, then re-open.')
    : null;

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

  /** One-time page-document load guard (2026-07-11 incident law: the editor
   *  must NEVER auto-replace its content after the first load). */
  const pageLoadedRef = useRef(false);
  /** THE document-on-screen state (reactive twin of the ref — a ref can't
   *  re-render; the 30s-white lesson, gap 02d3cb7 D2). E1b (gap
   *  EDITOR-OPEN-PIPELINE addendum): THIS — true on BOTH load paths (saved
   *  version AND inventory fallback) — is what the screen and every action
   *  gate on. pageReady is inventory-specific and gates only the fallback/
   *  live-view machinery that truly consumes inventory data. */
  const [docLoaded, setDocLoaded] = useState(false);

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
  const pageDirty = isPage && docLoaded && !!editor && editor.getHTML() !== savedHtml;
  /** The versions hook mounted before useEditor — keep its ref current. */
  editorRef.current = editor ?? null;
  const versionLabel = versionLabelFor(pageDirty);
  /** A real text selection — narrows the AI review's SCOPE (never its safety:
   *  every path shows red/green and waits for Accept/Reject). */
  const hasSelection = !!editor && !editor.state.selection.empty && !(editor.state.selection as any).node;

  // Page mode opens empty and loads its document ONCE (dirty-baseline = the
  // editor's normalized form of it, same law as onCreate). The ref guard is
  // the 2026-07-11 incident fix: the editor must NEVER auto-replace its
  // content afterwards — a degraded refetch once clobbered a full document
  // in front of the owner. Version picks load content explicitly.
  // W0 (2026-07-16): the SAVED version wins the open — versions[0] IS the
  // dropdown's Current row. INSTANT OPEN (gap e48b1ff): with versions the
  // open waits for NOTHING else — the site-side assembly is fetched and
  // waited for ONLY on the no-versions fallback path.
  useEffect(() => {
    if (!isPage || !editor || !pageVersionsSettled || pageLoadedRef.current) return;
    if (pageVersions.length > 0) {
      pageLoadedRef.current = true;
      editor.commands.setContent(stripPcmAnchors(pageVersions[0].replacement));
    } else {
      if (!pageReady) return; // fallback path — the assembly is still loading
      pageLoadedRef.current = true;
      editor.commands.setContent(pageHtml);
    }
    setDocLoaded(true);
    setSavedHtml(editor.getHTML());
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isPage, editor, pageReady, pageVersionsSettled, pageHtml, pageVersions]);

  // ── THE FEATHERWEIGHT CHECK (gap e48b1ff): "what version + fingerprint are
  //    you serving?" — powering the header glyph. It never render-blocks, but
  //    the hub call DOES hold one of the pool's PHP workers for the site's
  //    whole round-trip — so it fires only AFTER the document has painted
  //    (docLoaded; gap EDITOR-OPEN-PIPELINE E1: the check must never compete
  //    with the open; stored-first C2 is the end-state). An unanswered check
  //    is shown as unanswered, never as a verdict. A page that never loads
  //    keeps a neutral glyph — its own error line names the failure. ──
  const queryClient = useQueryClient();
  const stateQuery = trpc.seo.pageState.useQuery(
    { siteId: siteId as number, postId },
    { enabled: isPage && !readOnly && docLoaded, staleTime: 0, refetchOnMount: 'always' },
  );
  const pageState: any = isPage ? stateQuery.data : null;
  const pageDrifted = pageState?.drifted === true;
  // Unreachable = the site didn't answer (remote null) OR the hub call
  // itself failed — an unanswered check is SHOWN as unanswered, never as
  // a silent nothing.
  const stateUnreachable = (!!pageState && pageState.remote === null) || stateQuery.isError;

  // The deliberate "load live view" (drift glyph action): enable the heavy
  // fetch, then swap the doc in ONCE when it arrives — version-pick
  // semantics (the dropdown honestly flips to Draft, Save adopts it).
  const liveViewArmedRef = useRef(false);
  useEffect(() => {
    if (!liveViewWanted || !editor || !pageReady || liveViewArmedRef.current) return;
    liveViewArmedRef.current = true;
    editor.commands.setContent(pageHtml);
    setVersionPick('');
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [liveViewWanted, editor, pageReady, pageHtml]);

  const saveMutation = trpc.seo.remoteSaveSectionRule.useMutation();
  const savePageMutation = trpc.seo.remoteSavePageEdits.useMutation();

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
  // brandId/pageType arrive on the FEATHERWEIGHT check (instant-open path,
  // gap e48b1ff); the heavy inventory reply keeps carrying them for the
  // fallback path — whichever answers first fills the header context.
  useEffect(() => {
    const src: any = (isPage && stateQuery.data) || (isPage && pageQuery.data) || null;
    if (!src) return;
    setBrandId(Number(src.brandId ?? 0));
    setPageType(String(src.pageType ?? '') || 'general');
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isPage, stateQuery.data, pageQuery.data]);
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
  /** THE OUTSIDE RAIL HOST (gap e533bc5 F6): the analyze/review/image
   *  panels PORTAL here — a flex sibling on the card's right, the drawer's
   *  mirror. State (not only a ref) so the portals mount on first render;
   *  the ref mirror keeps the outside-click exemption closure-safe. */
  const [railHost, setRailHost] = useState<HTMLDivElement | null>(null);
  const railHostRef = useRef<HTMLDivElement | null>(null);
  const attachRailHost = (node: HTMLDivElement | null) => {
    railHostRef.current = node;
    setRailHost(node);
  };
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

  const isDirty = () => !readOnly && !!editor && editor.getHTML() !== savedHtml;

  // ── Save (save buttons / click outside): live rules, engine handles
  //    UPSERT/revert. `action` names the control that invoked it — that one
  //    alone shows the working state. ──
  const save = async (replacementOverride?: string, action: BusyAction = 'saveClose'): Promise<boolean> => {
    if (readOnly) return true;
    if (busy) return false; // one long action at a time — a guarded click is a no-op
    if (isPage) {
      if (!docLoaded) return true; // nothing loaded — nothing to save
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
        // THE CORNER'S TRUTH (gap ATOMIC-SAVE): a PUSHED save is the site's
        // confirmed state — the connector accepted the commit push and echoed
        // this exact record — so the verdict is seeded from the save itself
        // (no 15-30s re-ask of what was just confirmed). A no-op save
        // (pushed=false) confirmed nothing and must not touch the verdict.
        if (res?.pushed === true && res?.pageState) {
          queryClient.setQueryData(['seo', 'pageState', { siteId: siteId as number, postId }], (prev: any) => ({
            brandId: prev?.brandId ?? brandId,
            pageType: prev?.pageType ?? pageType,
            local: res.pageState,
            remote: { version: Number(res.pageState.version ?? 0), fingerprint: String(res.pageState.fingerprint ?? '') },
            drifted: false,
            error: null,
          }));
        }
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
      if (railHostRef.current?.contains(t)) return; // the outside rails are the drawer's mirror (F6)
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

  // ── THE RUN ENGINE (decomposition S3): every AI run + the whole review
  //    lifecycle live in useAiReview — one owner, one file (the 700 law).
  //    The composer only wires the live inputs in and the surface out. ──
  const {
    review, setReview, runDirectives, teacherById,
    reviseTarget, setReviseTarget, reviseNote, setReviseNote,
    runAi, startAiReview, runQuickOptimize, runKeywordInsert,
    resolveSection, acceptAllDiffs, finishReview,
    reviseSection, runRevise,
    focusSection, highlightQuote, setQuoteRange,
  } = useAiReview({
    editor, isPage, docLoaded, busy, siteId, postId, type, model, provider,
    aiPick: aiPick ?? null,
    instruction, hasSelection, primaryKw, supportingKw,
    bucketKeywords: kwBucket.keywords,
    tickedKw,
    closeAsk: () => setAskOpen(false),
    beginAiAction: () => setBusyAction('ai'),
    endAiAction: () => setBusyAction(null),
  });

  // ── THE IMAGE MACHINERY (decomposition S5): selection watcher, rule
  //    form, delivery, save/revert/hide — one owner in useImagePanel. ──
  const {
    imgSel, imgForm, setImgForm, imgHasRule, addImage, saveImageMeta,
  } = useImagePanel({
    editor, isPage, readOnly, busy, siteId, postId, setBusyAction,
  });

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
          {/* THE ORIGINAL ROW ALWAYS EXISTS (owner law 2026-07-19, gap
              e533bc5 C7): the original is the original on the site — an
              in-flight or failed fetch is a STATED state with a retry,
              never a vanishing row (the versions-delete "fix" was a race). */}
          {!isPage ? (
            <button
              type="button"
              onClick={() => pickVersion('original')}
              className="block w-full px-2 py-1 text-left text-[11px] text-slate-700 hover:bg-slate-50"
            >
              {versionPick === 'original' && <Check className="mr-1 inline h-3 w-3 text-primary" />}
              Original
            </button>
          ) : originalHtml !== '' ? (
            <button
              type="button"
              onClick={() => pickVersion('original')}
              className="block w-full px-2 py-1 text-left text-[11px] text-slate-700 hover:bg-slate-50"
            >
              {versionPick === 'original' && <Check className="mr-1 inline h-3 w-3 text-primary" />}
              {pageOriginalLabel}
            </button>
          ) : pageOriginalQuery.isFetching ? (
            <div className="flex w-full items-center gap-1 px-2 py-1 text-left text-[11px] text-slate-400">
              <Loader2 className="h-3 w-3 animate-spin text-primary" /> {pageOriginalLabel} — loading…
            </div>
          ) : (
            <button
              type="button"
              onClick={() => void pageOriginalQuery.refetch()}
              title="The site didn't answer with the original — press to retry"
              className="block w-full px-2 py-1 text-left text-[11px] text-amber-600 hover:bg-amber-50"
            >
              {pageOriginalLabel} — unavailable, retry
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
  const drawerVisible = isPage && !readOnly && keywordsOpen && docLoaded && typeof siteId === 'number';
  /** A rail is open — the union of the three outside-panel mount gates
   *  (review · analyze · image); drives the card's right taper (F6). */
  const railVisible = isPage && (
    !!review
    || (!readOnly && analyzeOpen && !imgSel && docLoaded)
    || (!readOnly && !!imgSel)
  );
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
        ? { width: pageCardWidth(drawerVisible, railVisible), height: '90vh', transition: 'width 300ms ease' }
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
            {/* THE IDENTITY-CORNER STATUS (gap d4aa30b, Jony DoD): the
                decorative document icon is gone — this square IS the page's
                one connection status. Four true states; no verdict = the
                quiet neutral square. Hover tells the truth, click acts. */}
            {!readOnly && (busyAction === 'save' || busyAction === 'saveClose') ? (
              // Saving = the only moment the verdict is genuinely in flux —
              // the corner says so instead of showing a stale answer.
              <span title="Saving — pushing to your site…" className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-slate-50">
                <RefreshCw className="h-3.5 w-3.5 animate-spin text-slate-400" />
              </span>
            ) : !readOnly && stateQuery.isFetching ? (
              <span title="Checking the site connection…" className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-slate-50">
                <RefreshCw className="h-3.5 w-3.5 animate-spin text-slate-400" />
              </span>
            ) : !readOnly && pageDrifted ? (
              <button
                type="button"
                onClick={() => setLiveViewWanted(true)}
                title="The live page differs from your saved version — click to load the live view"
                className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-amber-50 text-amber-500 hover:bg-amber-100"
              >
                <RefreshCw className="h-3.5 w-3.5" />
              </button>
            ) : !readOnly && stateUnreachable ? (
              <button
                type="button"
                onClick={() => { void stateQuery.refetch(); }}
                title={`Couldn't reach the site to verify — click to retry${pageState?.error ? ` (${pageState.error})` : ''}`}
                className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-red-50 text-red-400 hover:bg-red-100"
              >
                <CloudOff className="h-3.5 w-3.5" />
              </button>
            ) : !readOnly && pageState?.drifted === false ? (
              <span title="Live — the site serves your saved version" className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-green-50">
                <span className="h-2 w-2 rounded-full bg-green-500" />
              </span>
            ) : (
              <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-slate-50">
                <span className="h-2 w-2 rounded-full bg-slate-300" />
              </span>
            )}
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
        {isPage && !readOnly && docLoaded && !review && (
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
            {/* ONE AI entry point (gap eeec6b9 D1 — the Analyze pill died
                into this menu): EVERY run goes through the red/green review.
                Main click = Quick (the action matrix decides its shape);
                the caret menu holds the three modes. */}
            <PillSplitButton
              icon={<Sparkles />}
              onClick={runQuickOptimize}
              title={tickedKw.length > 0
                ? (hasSelection
                  ? 'Weave the ticked keywords into the selected sections by their roles — changes show as red/green'
                  : 'Weave the ticked keywords into the content by their roles — changes show as red/green')
                : (hasSelection
                  ? 'Rewrite the selected sections with AI — changes show as red/green for you to accept or reject'
                  : 'Rewrite the whole page with AI — every change shows as red/green for you to accept or reject')}
              caretTitle="Optimization modes"
              menu={
                <>
                  <DropdownMenuItem onClick={runQuickOptimize}>
                    Quick optimize
                  </DropdownMenuItem>
                  <DropdownMenuItem onClick={() => setAnalyzeOpen(true)}>
                    Super optimize…
                  </DropdownMenuItem>
                  <DropdownMenuItem onClick={() => setAskOpen(true)}>
                    Custom instruction…
                  </DropdownMenuItem>
                </>
              }
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
        {/* THE STAGED OPEN OVERLAY (gap 02d3cb7 D2): every pre-content phase
            SAYS what it is doing — a blank editor is never silent again. */}
        {isPage && !readOnly && !docLoaded && !pageError && (
          <div className="flex h-full flex-col items-center justify-center gap-2 text-xs text-slate-500">
            <Loader2 className="h-4 w-4 animate-spin text-primary" />
            {!pageVersionsSettled
              ? 'Loading your saved version…'
              : 'Pulling the page from your site — the first open takes longer while the local copy is built…'}
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
        {(!isPage || docLoaded) && (
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
             until every section is resolved. PORTALS to the OUTSIDE host
             (gap e533bc5 F6) — an add-on beside the card, the drawer's
             mirror; the JSX lives here, the DOM lives there. ── */}
      {isPage && review && railHost !== null && createPortal(
        <ReviewRail
          review={review}
          runDirectives={runDirectives}
          teacherById={teacherById}
          reviseTarget={reviseTarget}
          reviseNote={reviseNote}
          onReviseTargetChange={setReviseTarget}
          onReviseNoteChange={setReviseNote}
          onRunRevise={runRevise}
          onAcceptAll={acceptAllDiffs}
          onFinish={finishReview}
          onResolve={resolveSection}
          onToggleKept={(i, k) => setReview((cur) => cur?.map((x, xi) => (xi === i
            ? { ...x, kept: (x.changes ?? []).map((_, ki) => (ki === k ? !(x.kept?.[ki] ?? true) : (x.kept?.[ki] ?? true))) }
            : x)) ?? cur)}
          onUpdateProposal={(i, note) => void reviseSection(i, note)}
          onFocusSection={focusSection}
          onHoverQuote={highlightQuote}
          onHoverEnd={() => setQuoteRange(null)}
        />,
        railHost,
      )}

      {/* ── THE ANALYZE RAIL (optimizer spine): teachers' suggestions →
             basket → ONE optimize run through the normal red/green review.
             Yields to the review rail and the image panel. Outside host
             portal (F6), exactly like the review rail. ── */}
      {isPage && !readOnly && analyzeOpen && !review && !imgSel && docLoaded && railHost !== null && createPortal(
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
            // SUPER OPTIMIZE (gap eeec6b9): routed directives scope each
            // section's order inside startAiReview; this topic is only the
            // unrouted floor. Strict = the careful-editor contract.
            const topic = `Apply exactly these optimizations to the content:\n${directives
              .map((d, i) => `${i + 1}. ${d.text}`)
              .join('\n')}`;
            void startAiReview(topic, null, directives, { strict: true });
          }}
        />,
        railHost,
      )}

      {/* ── Image metadata panel (V3): alt/title as a dynamic rule — the image
             itself is locked (position/existence never change). Outside host
             portal (F6). ── */}
      {isPage && !review && !readOnly && imgSel && railHost !== null && createPortal(
        <aside className="flex h-full w-[250px] shrink-0 flex-col bg-slate-50/60">
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
        </aside>,
        railHost,
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
            disabled={isPage && (!docLoaded || !!review)}
            onClick={() => { void save().then((ok) => { if (ok) onClose(); }); }}
          >
            {isPage ? 'Save & close' : 'Save'}
          </PillButton>
          {isPage && (
            <button
              type="button"
              onClick={() => { void save(undefined, 'save'); }}
              disabled={!docLoaded || !!review}
              title="Save — the window stays open"
              className="inline-flex items-center gap-1 rounded-full border border-green-600 bg-white px-2.5 py-1 text-xs font-medium text-green-700 hover:bg-green-50 disabled:opacity-60"
            >
              {busyAction === 'save' ? <Loader2 className="h-3 w-3 animate-spin" /> : <Save className="h-3 w-3" />} Save
            </button>
          )}
          <button
            type="button"
            onClick={() => { if (!busy) editor?.commands.setContent(savedHtml); }}
            disabled={isPage && (!docLoaded || !!review)}
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
          {/* THE OUTSIDE RAIL HOST (F6): the drawer's mirror on the right —
              the analyze/review/image panels portal in; empty = invisible. */}
          <div
            ref={attachRailHost}
            className="h-[86vh] shrink-0 self-center overflow-hidden rounded-r-2xl bg-white shadow-2xl [&:empty]:hidden"
          />
        </div>
      ) : (
        cardEl
      )}
    </>,
    document.body,
  );
}
