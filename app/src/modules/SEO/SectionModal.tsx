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
  X, Loader2,
  BoldIcon, ItalicIcon, UnderlineIcon, Link as LinkIcon,
  Heading1, Heading2, List,
} from 'lucide-react';
import { toast } from 'sonner';
import { useQueryClient } from '@tanstack/react-query';

import { trpc } from '@/lib/trpc';
import { ModelDropdown } from '@/components/shared';
import { useTextModels } from '@/modules/Copy/useTextModels';
import { stripDiffHtml } from './word-diff';
// THE DECOMPOSITION (S1-S3, gap 325d280/10a0233): the editor's laws,
// extensions, scales, contracts, the review rail, and THE RUN ENGINE all
// live in editor/ — this file only composes them.
import {
  DiffAdded, DiffRemoved, FaqItem, FaqSummary, LockedImage, ReviewControls, SectionBlocks,
} from './editor/extensions';
import { composeSectionHtml, escapeHtml, faqTemplate, stripPcmAnchors } from './editor/content-laws';
import { BLOCK_STYLES, PAGE_TYPE_SCALE, TYPE_SCALE, WIDTH, pageCardWidth } from './editor/layout';
import { ToolButton } from './editor/ToolButton';
import type { InsertData, SectionAnchor, SectionData } from './editor/types';
import { ReviewRail } from './editor/ReviewRail';
import { useAiReview } from './editor/useAiReview';
import { useImagePanel } from './editor/useImagePanel';
import { useSectionVersions } from './editor/useSectionVersions';
import { VersionsMenu } from './editor/VersionsMenu';
import { EditorHeader } from './editor/EditorHeader';
import { EditorFooter } from './editor/EditorFooter';
import { ImagePanel } from './editor/ImagePanel';

// The public contract other files import from here (HeadingsPanel) —
// unchanged by the decomposition.
export type { InsertData, SectionAnchor, SectionData, SectionParagraph } from './editor/types';
import { OptimizerRail } from './optimizer/OptimizerRail';
import { KeywordsDrawer, type TickedKeyword } from './optimizer/KeywordsDrawer';
import { useKeywordBucket } from './optimizer/useKeywordBucket';
import { keywordUses } from './optimizer/keywordStats';

// The hub's native WP media library (wp_enqueue_media — same pattern as the
// table's featured-image picker).

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
  const versionsApi = useSectionVersions({
    editorRef, isPage, isInsert, readOnly, siteId, postId, section,
    pageDate: page?.date,
    sectionOriginalHtml: !isInsert && section
      ? (section.heading.html || `<h${section.heading.level}>${escapeHtml(section.heading.text)}</h${section.heading.level}>`)
        + section.paragraphs.map((p) => p.html).join('')
      : '',
  });
  // The composer's own consumers; everything else reaches the machinery
  // through <VersionsMenu v={versionsApi}> — one contract, zero drift.
  const {
    pageVersionsQuery, pageVersions, pageVersionsSettled,
    versionsQuery, versionLabelFor, setVersionPick,
  } = versionsApi;

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
  const [pageType, setPageType] = useState('general');
  // pageType arrives on the FEATHERWEIGHT check (instant-open path, gap
  // e48b1ff); the heavy inventory reply keeps carrying it for the fallback
  // path — whichever answers first fills the context. (The brand's twin
  // lives in EditorHeader — the header owns the business link end-to-end.)
  useEffect(() => {
    const src: any = (isPage && stateQuery.data) || (isPage && pageQuery.data) || null;
    if (!src) return;
    setPageType(String(src.pageType ?? '') || 'general');
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isPage, stateQuery.data, pageQuery.data]);
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
            brandId: prev?.brandId ?? 0,
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

  // (the old whole-doc rebuilder's orphan buffer died with it — the orphan
  // zone is simply never touched by surgery)

  // ── THE RUN ENGINE (decomposition S3): every AI run + the whole review
  //    lifecycle live in useAiReview — one owner, one file (the 700 law).
  //    The composer only wires the live inputs in and the surface out. ──
  const {
    review, setReview, runDirectives, teacherById,
    reviseTarget, setReviseTarget, reviseNote, setReviseNote,
    runAi, startAiReview, runQuickOptimize,
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
  //    form, delivery, save/revert/hide — one owner in useImagePanel;
  //    the panel consumes the whole api, the composer only two names. ──
  const imagePanelApi = useImagePanel({
    editor, isPage, readOnly, busy, siteId, postId, setBusyAction,
  });
  const { imgSel, addImage } = imagePanelApi;

  const title = isPage
    ? `📄 ${page?.title ?? 'Page'}`
    : isInsert
      ? (insert?.ruleId ? '¶ Added section' : '¶ New section')
      : `¶ ${section?.heading.text ?? ''}`;
  const served = !isInsert && !isPage && !!section?.sectionRuleReplacement;

  // ── Shared header controls: the page header's row 1 and the section
  //    header render the SAME nodes (one definition, two placements). ──
  const versionsControl = (!readOnly && !isInsert && !review) ? (
    <VersionsMenu isPage={isPage} pageDirty={pageDirty} versionLabel={versionLabel} v={versionsApi} />
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
      {/* ── THE HEADER STACK (decomposition final pair): both header rows,
             the Ask-AI box, and the placement row live in EditorHeader —
             the composer only wires. ── */}
      <EditorHeader
        isPage={isPage}
        isInsert={isInsert}
        readOnly={readOnly}
        docLoaded={docLoaded}
        reviewOpen={!!review}
        siteId={siteId}
        postId={postId}
        type={type}
        page={page}
        title={title}
        served={served}
        onDragStart={onDragStart}
        onDragMove={onDragMove}
        onDragEnd={onDragEnd}
        cornerSaving={busyAction === 'save' || busyAction === 'saveClose'}
        stateFetching={stateQuery.isFetching}
        pageDrifted={pageDrifted}
        stateUnreachable={stateUnreachable}
        stateErrorDetail={String(pageState?.error ?? '')}
        stateLive={pageState?.drifted === false}
        onLoadLiveView={() => setLiveViewWanted(true)}
        onRetryState={() => { void stateQuery.refetch(); }}
        versionsControl={versionsControl}
        closeButton={closeButton}
        aiModelSelect={aiModelSelect}
        pageType={pageType}
        onPageTypeChange={setPageType}
        primaryKw={primaryKw}
        kwExtraCount={kwExtraCount}
        primaryDensity={primaryDensity}
        onToggleKeywords={() => setKeywordsOpen((v) => !v)}
        busyImageAdd={busyAction === 'imageAdd'}
        onAddImage={addImage}
        onInsertFaq={() => editor?.chain().focus().insertContent(faqTemplate()).run()}
        tickedCount={tickedKw.length}
        hasSelection={hasSelection}
        runQuickOptimize={runQuickOptimize}
        onOpenAnalyze={() => setAnalyzeOpen(true)}
        onOpenAsk={() => setAskOpen(true)}
        askOpen={askOpen}
        onToggleAsk={() => setAskOpen((v) => !v)}
        busyAi={busyAction === 'ai'}
        onRunAi={() => { void runAi(''); }}
        instruction={instruction}
        onInstructionChange={setInstruction}
        onRunInstruction={() => {
          if (!isPage) { void runAi(instruction.trim()); return; }
          void startAiReview(
            instruction.trim(),
            hasSelection && editor ? { from: editor.state.selection.from, to: editor.state.selection.to } : null,
          );
        }}
        showPlacement={isInsert && !insert?.ruleId}
        position={position}
        onPositionChange={setPosition}
        anchorIdx={anchorIdx}
        onAnchorIdxChange={setAnchorIdx}
        anchors={anchors}
      />
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
        <ImagePanel img={imagePanelApi} busyAction={busyAction} />,
        railHost,
      )}
      </div>

      {/* ── The save bar (decomposition final pair): EditorFooter. ── */}
      {!readOnly && (
        <EditorFooter
          isPage={isPage}
          isInsert={isInsert}
          insertHasRule={!!insert?.ruleId}
          docLoaded={docLoaded}
          reviewOpen={!!review}
          busyAction={busyAction}
          onSaveClose={() => { void save().then((ok) => { if (ok) onClose(); }); }}
          onSave={() => { void save(undefined, 'save'); }}
          onUndo={() => { if (!busy) editor?.commands.setContent(savedHtml); }}
          onRemove={() => { void save('', 'remove').then((ok) => { if (ok) onClose(); }); }}
        />
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
