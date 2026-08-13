/**
 * WRITER MODULE — Main Layout
 *
 * Full-bleed 4-column layout using react-resizable-panels.
 * Columns: Queue (15%) | SEO & Metadata (20%) | Editor (45%) | AI/Revisions (20%)
 *
 * All styling uses design-tokens — no hardcoded hex values.
 * Action bar shows active document title + status badge + quick actions.
 */

import {
  ResizablePanelGroup,
  ResizablePanel,
  ResizableHandle,
} from '@/components/ui/resizable';
import { useAtomValue, useSetAtom } from 'jotai';
import { activeDocumentAtom, activeDocumentIdAtom, addDocumentsAtom, writerSelectedModelAtom, writerDocumentsAtom, selectedDocumentIdsAtom, updateActiveDocumentAtom } from './store';
import type { WriterDocument } from './store';
import { DocumentQueuePanel } from './components/DocumentQueuePanel';
import { ContextGenerationPanel } from './components/ContextGenerationPanel';
import { ReviewEditorCanvas } from './components/ReviewEditorCanvas';
import { RevisionsPanel } from './components/RevisionsPanel';
import { AiReviewPanel } from './components/AiReviewPanel';
import { PillButton, StatusBadge, colors, typography } from '@/components/shared';
import { Link2, Send, PanelRightOpen, PanelLeftOpen, History, Share2, Copy, Check, Loader2, Rocket, Sparkles } from 'lucide-react';
import { useRef, useState, useEffect, useCallback, useMemo } from 'react';
import { ImperativePanelGroupHandle } from 'react-resizable-panels';
import { useApp } from '@/contexts/AppContext';
import { useSettings } from '@/contexts/AppContext';
import { buildDefaultWriterFormValues } from './writerConfig';
import { trpc } from '@/lib/trpc';
import { toast } from 'sonner';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useWriterPersistence } from './hooks/useWriterPersistence';

// ── Helpers ──

/** Slugify that handles international characters (Swedish, etc.) */
function slugify(text: string): string {
  return text
    .normalize('NFKD')                    // decompose accented chars (ä → a + ¨)
    .replace(/[\u0300-\u036f]/g, '')      // strip combining diacritics
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/(^-|-$)/g, '');
}

export function WriterModule() {
  const activeDoc = useAtomValue(activeDocumentAtom);
  const addDocuments = useSetAtom(addDocumentsAtom);
  const setSelectedModel = useSetAtom(writerSelectedModelAtom);
  const writerDocs = useAtomValue(writerDocumentsAtom);
  const selectedIds = useAtomValue(selectedDocumentIdsAtom);
  const setActiveDocId = useSetAtom(activeDocumentIdAtom);
  const setSelectedIds = useSetAtom(selectedDocumentIdsAtom);
  const updateDoc = useSetAtom(updateActiveDocumentAtom);
  const setWriterDocs = useSetAtom(writerDocumentsAtom);
  const { state, dispatch, consumePendingWriterArticleId } = useApp();
  const { settings } = useSettings();

  // ── DB Persistence — loads from server, autosaves changes, syncs creates/deletes ──
  const { isLoading: isPersistenceLoading } = useWriterPersistence();

  // ── Cross-module: open one article (e.g. from the Strategies list "View"
  //    button). Reacts to `writerDocs` rather than the persistence hook's
  //    isLoading flag — that flag flips false one render before the loaded
  //    articles actually land in writerDocumentsAtom, which would otherwise
  //    consume (and lose) the one-shot pending id before the match could be
  //    found. Only consumes it at the exact moment the target doc is present;
  //    if it never appears the id just stays inert — no wrong selection. ──
  useEffect(() => {
    const pendingId = state.pendingWriterArticleId;
    if (pendingId === null) return;
    const targetId = String(pendingId);
    if (writerDocs.some((d) => d.id === targetId)) {
      consumePendingWriterArticleId();
      setActiveDocId(targetId);
      setSelectedIds([targetId]);
    }
  }, [state.pendingWriterArticleId, writerDocs, consumePendingWriterArticleId, setActiveDocId, setSelectedIds]);

  // ── Memoized calculations for approvals packaging ──
  const targetDocs = useMemo(() => {
    if (selectedIds.length === 0) return activeDoc ? [activeDoc] : [];
    return writerDocs.filter((d) => selectedIds.includes(d.id));
  }, [selectedIds, writerDocs, activeDoc]);

  const hasEmptyDoc = useMemo(() => {
    return targetDocs.some((d) => !d.content);
  }, [targetDocs]);

  const canSendApprovals = targetDocs.length > 0 && !hasEmptyDoc;

  // ── Send to Approvals dialog state ──
  const [showApprovalDialog, setShowApprovalDialog] = useState(false);
  const [approvalSetName, setApprovalSetName] = useState('');
  const [shareableLink, setShareableLink] = useState('');
  const [linkCopied, setLinkCopied] = useState(false);

  // ── Initialize model selection from Settings default ──
  useEffect(() => {
    if (settings.defaultWriterModel) {
      setSelectedModel(settings.defaultWriterModel);
    }
  }, [settings.defaultWriterModel, setSelectedModel]);

  // ── Consume cross-module data (Keywords → Writer) ──
  const pending = state.pendingWriterData;
  const consumedRef = useRef<string | null>(null);

  useEffect(() => {
    if (!pending || pending.documents.length === 0) return;
    const fingerprint = pending.documents.map((d) => d.primaryKeyword).join('|');
    if (consumedRef.current === fingerprint) return;
    consumedRef.current = fingerprint;

    dispatch({ type: 'SET_PENDING_WRITER_DATA', payload: null });

    const sharedSettings = {
      ...(pending.brandId && { brandId: pending.brandId }),
      ...(pending.templateId && { templateId: pending.templateId }),
      ...(pending.siteUrl && { targetSiteUrl: pending.siteUrl }),
    };

    const newDocs: WriterDocument[] = pending.documents.map((doc) => ({
      id: crypto.randomUUID(),
      title: doc.primaryKeyword,
      slug: slugify(doc.primaryKeyword),
      content: '',
      metaTitle: '',
      metaDescription: '',
      schemaType: 'Article' as const,
      status: 'draft' as const,
      featuredImage: '',
      projectId: '',
      projectName: '',
      generationSettings: {
        ...buildDefaultWriterFormValues(),
        primaryKeyword: doc.primaryKeyword,
        supportingKeywords: doc.supportingKeywords.join('\n'),
        ...sharedSettings,
      },
    }));

    addDocuments(newDocs);
  }, [pending, addDocuments, dispatch]);

  // ── Panel-group ref for collapsible behavior ──
  const panelGroupRef = useRef<ImperativePanelGroupHandle>(null);
  // Default to a distraction-free editor: both left panels (Queue, Context/
  // Settings) and both right panels (Revisions history, AI Review — separate
  // drawers per the 20260715 card) start collapsed. The panels below use
  // defaultSize={0} to match; these flags keep the toolbar toggles in sync
  // on first paint (onCollapse doesn't fire for an already-collapsed panel).
  const [isQueueCollapsed, setIsQueueCollapsed] = useState(true);
  const [isContextCollapsed, setIsContextCollapsed] = useState(true);
  const [isRevisionsCollapsed, setIsRevisionsCollapsed] = useState(true);
  const [isReviewCollapsed, setIsReviewCollapsed] = useState(true);
  const [isQueueListCollapsed, setIsQueueListCollapsed] = useState(false);

  // ── Desired collapsed state — the AUTHORITATIVE source of truth for each
  // panel's collapsed/expanded intent. Updated ONLY by explicit user actions:
  // the three toolbar toggle buttons and each panel's own header collapse
  // button. react-resizable-panels redistributes freed space across the
  // group whenever one panel collapses/expands or a handle is dragged; that
  // redistribution can push an already-collapsed sibling's size above (or an
  // expanded sibling's size below) its threshold, firing a spurious
  // onExpand/onCollapse that has nothing to do with user intent. The
  // onCollapse/onExpand handlers below compare against this ref before
  // trusting the event: if it disagrees with desired state, they immediately
  // re-assert the desired state via the imperative API instead of flipping
  // the visible flag. This is loop-free — reasserting collapse/expand only
  // ever produces an event that now MATCHES desired state (the matching
  // branch just syncs the visible flag and returns, calling nothing further)
  // — and the flex-1 editor panel (no maxSize) always absorbs whatever space
  // is displaced, so the group has somewhere to settle.
  const desiredCollapsed = useRef({ queue: true, context: true, revisions: true, review: true });

  // Reopen sizes (%) per panel. All four open at once still leaves the editor
  // its minSize of 30 (14+20+18+18 = 70).
  const PANEL_OPEN_SIZES = { queue: 14, context: 20, revisions: 18, review: 18 };

  /**
   * Impose the desired collapsed/expanded states as ONE whole-group layout.
   *
   * Why not panel.expand()? The library's expand() frees space only from the
   * panels AFTER the target (its pivot). The Revisions panel's only follower
   * is the AI Review panel — collapsed at 0, with nothing to give — so
   * expand() computed an unchanged layout and silently did NOTHING ("when i
   * click on show revision it didn't open"). setLayout() sizes every panel at
   * once, so the editor pays regardless of panel order.
   */
  const applyDesiredLayout = useCallback(() => {
    const d = desiredCollapsed.current;
    const queue = d.queue ? 0 : PANEL_OPEN_SIZES.queue;
    const context = d.context ? 0 : PANEL_OPEN_SIZES.context;
    const revisions = d.revisions ? 0 : PANEL_OPEN_SIZES.revisions;
    const review = d.review ? 0 : PANEL_OPEN_SIZES.review;
    const editor = 100 - queue - context - revisions - review;
    panelGroupRef.current?.setLayout([queue, context, editor, revisions, review]);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  /** Toggle Queue panel; the toolbar button is the sole non-drift source for this flag. */
  const toggleQueue = useCallback(() => {
    desiredCollapsed.current.queue = !desiredCollapsed.current.queue;
    applyDesiredLayout();
  }, [applyDesiredLayout]);

  /** Toggle Context/Settings panel from the toolbar button. */
  const toggleContext = useCallback(() => {
    desiredCollapsed.current.context = !desiredCollapsed.current.context;
    applyDesiredLayout();
  }, [applyDesiredLayout]);

  /** Toggle the Revisions (history) panel from the toolbar button. */
  const toggleRevisions = useCallback(() => {
    desiredCollapsed.current.revisions = !desiredCollapsed.current.revisions;
    applyDesiredLayout();
  }, [applyDesiredLayout]);

  /** Toggle the AI Review panel from the toolbar button. */
  const toggleReview = useCallback(() => {
    desiredCollapsed.current.review = !desiredCollapsed.current.review;
    applyDesiredLayout();
  }, [applyDesiredLayout]);

  /** Context panel's own header collapse button — also an explicit user action. */
  const collapseContextFromHeader = useCallback(() => {
    desiredCollapsed.current.context = true;
    applyDesiredLayout();
  }, [applyDesiredLayout]);

  /** Revisions panel's own header collapse button — also an explicit user action. */
  const collapseRevisionsFromHeader = useCallback(() => {
    desiredCollapsed.current.revisions = true;
    applyDesiredLayout();
  }, [applyDesiredLayout]);

  /** AI Review panel's own header collapse button — also an explicit user action. */
  const collapseReviewFromHeader = useCallback(() => {
    desiredCollapsed.current.review = true;
    applyDesiredLayout();
  }, [applyDesiredLayout]);

  // ── Approvals mutation — same pattern as Ads module ──
  const createApprovalMutation = trpc.approvals.createSet.useMutation({
    onSuccess: (data: any) => {
      const config = window.pcmConfig ?? { shortcodePageUrl: window.location.origin + '/' };
      const baseUrl = config.shortcodePageUrl || (window.location.origin + '/');
      const separator = baseUrl.includes('?') ? '&' : '?';
      setShareableLink(`${baseUrl}${separator}pcm_public_token=${data.token}`);
      toast.success('Article approval board created!');
    },
    onError: (err: any) => {
      toast.error(err.message || 'Failed to create approval set.');
    },
  });

  /** Freeze current article into a snapshot and create an approval set */
  const handleCreateApprovalSet = useCallback(() => {
    if (!approvalSetName.trim()) {
      toast.error('Please enter a name for the approval set.');
      return;
    }

    if (targetDocs.length === 0) {
      toast.error('No articles selected to send.');
      return;
    }

    if (hasEmptyDoc) {
      toast.error('Cannot send empty articles. Please generate or write content first.');
      return;
    }

    // Freeze snapshot data for all target articles
    const articleSnapshots = targetDocs.map((doc) => ({
      id: doc.id,
      title: doc.title || 'Untitled Article',
      slug: doc.slug,
      content: doc.content,
      metaTitle: doc.metaTitle,
      metaDescription: doc.metaDescription,
      schemaType: doc.schemaType,
      status: doc.status,
      featuredImage: doc.featuredImage,
    }));

    const sentIds = targetDocs.map((d) => d.id);
    createApprovalMutation.mutate({
      name: approvalSetName.trim(),
      brandId: null,
      projectId: null,
      snapshot: {
        media: [],
        copy: [],
        articles: articleSnapshots,
        brandName: 'PowerCreatives',
        brandLogoUrl: null,
      },
    }, {
      // "Sent to approval" is a visible STATE, not a lit button: mark the sent
      // articles as in review so their badge (and the queue) says so.
      onSuccess: () => {
        setWriterDocs((docs) => docs.map((d) =>
          sentIds.includes(d.id) && d.status !== 'published' ? { ...d, status: 'review' } : d,
        ));
      },
    });
  }, [targetDocs, hasEmptyDoc, approvalSetName, createApprovalMutation, setWriterDocs]);

  /** Open the dialog with a sensible default name */
  const handleOpenApprovalDialog = useCallback(() => {
    const dateStr = new Date().toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    if (selectedIds.length > 1) {
      setApprovalSetName(`Articles Campaign Review — (${selectedIds.length} articles) (${dateStr})`);
    } else {
      const singleDoc = selectedIds.length === 1
        ? writerDocs.find((d) => d.id === selectedIds[0])
        : activeDoc;
      setApprovalSetName(`Article Review — ${singleDoc?.title || 'Draft'} (${dateStr})`);
    }
    setShareableLink('');
    setLinkCopied(false);
    setShowApprovalDialog(true);
  }, [activeDoc, writerDocs, selectedIds]);

  // ── Publish to Target Site — publishes the active article to the WP site
  //    selected in its generation settings (Settings → Target Site). The
  //    handler needs only { articleId }; the site id rides in the URL via the
  //    sites.publish transform. Returns { postUrl } on success. ──
  const publishMutation = trpc.sites.publish.useMutation({
    onSuccess: (data: any) => {
      const link = data?.postUrl || '';
      updateDoc({ status: 'published' });
      toast.success(link ? `Published! View at ${link}` : 'Article published!');
    },
    onError: (err: any) => {
      toast.error(err.message || 'Failed to publish article.');
    },
  });

  // Target Site chosen in ContextGenerationPanel (numeric site id) or undefined.
  const publishSiteId = activeDoc?.generationSettings?.siteId;
  // Server-persisted docs carry a numeric id (house idiom, cf. AiRevisionsPanel);
  // UUID-id local drafts have nothing server-side to publish yet.
  const isDocPersisted = !!activeDoc && /^\d+$/.test(activeDoc.id);
  const publishDisabledReason = !activeDoc
    ? 'No document selected'
    : !isDocPersisted
      ? 'Save the article before publishing'
      : !activeDoc.content
        ? 'Add content before publishing'
        : !publishSiteId
          ? 'Select a Target Site in Settings before publishing'
          : '';
  const canPublish = publishDisabledReason === '';

  const handlePublish = useCallback(() => {
    if (!activeDoc || !publishSiteId || !/^\d+$/.test(activeDoc.id)) return;
    publishMutation.mutate({ id: publishSiteId, articleId: Number(activeDoc.id) });
  }, [activeDoc, publishSiteId, publishMutation]);

  return (
    <div
      className="flex flex-col h-full overflow-hidden w-full"
      style={{ background: colors.bgSurface }}
    >
      {/* ── Top Action Bar ─────────────────────────────── */}
      <header
        className="h-12 w-full flex items-center px-4 shrink-0 z-50"
        style={{
          borderBottom: `1px solid ${colors.borderLight}`,
          background: colors.bgSurface,
          boxShadow: '0 1px 2px rgba(0,0,0,0.04)',
        }}
      >
        {/* Left: collapsed panel buttons + document info */}
        <div className="flex items-center gap-3">
          {/* Always-visible toggle buttons for Queue and Context panels */}
          <PillButton
            variant={isQueueCollapsed ? 'default' : 'subtle'}
            icon={<PanelLeftOpen />}
            onClick={toggleQueue}
          >
            {isQueueCollapsed ? 'Show Queue' : 'Hide Queue'}
          </PillButton>

          <PillButton
            variant={isContextCollapsed ? 'default' : 'subtle'}
            icon={<PanelRightOpen />}
            onClick={toggleContext}
          >
            {isContextCollapsed ? 'Show Settings' : 'Hide Settings'}
          </PillButton>

          <div
            style={{
              width: 1,
              height: 14,
              borderLeft: `1px solid ${colors.borderMedium}`,
            }}
          />

          {activeDoc ? (
            <>
              <span
                style={{
                  fontSize: typography.sm,
                  fontWeight: typography.semibold,
                  color: colors.text,
                  letterSpacing: '-0.01em',
                }}
                className="truncate max-w-[300px] ml-1"
              >
                {activeDoc.title}
              </span>
              <StatusBadge status={activeDoc.status} variant="pill" />
            </>
          ) : (
            <span
              style={{
                fontSize: typography.sm,
                fontWeight: typography.medium,
                color: colors.textMuted,
              }}
              className="ml-1"
            >
              No document selected
            </span>
          )}
        </div>

        {/* Right: save status + actions */}
        <div className="ml-auto flex items-center gap-2">
          <PillButton
            variant="subtle"
            icon={<Link2 />}
            onClick={() => {
              navigator.clipboard.writeText(window.location.href).then(() => {
                toast.success('Link copied to clipboard!');
              });
            }}
          >
            Copy Link
          </PillButton>

          <PillButton
            variant={isRevisionsCollapsed ? 'default' : 'subtle'}
            icon={<History />}
            onClick={toggleRevisions}
          >
            {isRevisionsCollapsed ? 'Show Revisions' : 'Hide Revisions'}
          </PillButton>

          <PillButton
            variant={isReviewCollapsed ? 'default' : 'subtle'}
            icon={<Sparkles />}
            onClick={toggleReview}
          >
            AI Review
          </PillButton>

          {/* Never the lit-blue one — Publish is the only always-blue action.
              After sending, the state shows as the doc's "review" badge, and
              the label flips to "Sent to Approval". */}
          <PillButton
            variant="subtle"
            icon={<Share2 />}
            onClick={handleOpenApprovalDialog}
            disabled={!canSendApprovals}
          >
            {selectedIds.length > 1
              ? `Send ${selectedIds.length} Articles to Approvals`
              : targetDocs.length === 1 && targetDocs[0].status === 'review'
                ? 'Sent to Approval'
                : selectedIds.length === 1
                  ? 'Send 1 Article to Approvals'
                  : 'Send to Approvals'}
          </PillButton>

          <PillButton
            variant="active"
            icon={<Rocket />}
            onClick={handlePublish}
            disabled={!canPublish}
            loading={publishMutation.isPending}
            title={publishDisabledReason || 'Publish to the selected Target Site'}
          >
            Publish
          </PillButton>
        </div>
      </header>

      {/* ── Main 4-Column Layout ──────────────────────── */}
      <div className="flex-1 overflow-hidden w-full relative">
        <ResizablePanelGroup ref={panelGroupRef} direction="horizontal" className="h-full w-full">

          {/* Column 1: Document Queue — collapsible */}
          <ResizablePanel
            defaultSize={0}
            minSize={10}
            maxSize={20}
            collapsible={true}
            collapsedSize={0}
            onCollapse={() => {
              if (!desiredCollapsed.current.queue) {
                // Drift: layout redistribution squeezed an intentionally-open
                // panel shut. Re-assert the desired state instead of trusting
                // this event — loop-free because the resulting events match
                // desired and just sync the flags.
                applyDesiredLayout();
                return;
              }
              setIsQueueCollapsed(true);
            }}
            onExpand={() => {
              if (desiredCollapsed.current.queue) {
                // Drift: layout redistribution re-inflated an intentionally-
                // collapsed panel. Re-assert; do NOT flip the flag.
                applyDesiredLayout();
                return;
              }
              setIsQueueCollapsed(false);
            }}
          >
            <DocumentQueuePanel
              isCollapsed={isQueueListCollapsed}
              onToggleCollapse={() => setIsQueueListCollapsed((c) => !c)}
            />
          </ResizablePanel>

          <ResizableHandle
            withHandle
            className="w-[1px] border-none"
            style={{ background: colors.borderLight }}
          />

          {/* Column 2: Context & Generation */}
          <ResizablePanel
            defaultSize={0}
            minSize={18}
            maxSize={40}
            collapsible={true}
            collapsedSize={0}
            onCollapse={() => {
              if (!desiredCollapsed.current.context) {
                applyDesiredLayout();
                return;
              }
              setIsContextCollapsed(true);
            }}
            onExpand={() => {
              if (desiredCollapsed.current.context) {
                applyDesiredLayout();
                return;
              }
              setIsContextCollapsed(false);
            }}
          >
            <ContextGenerationPanel onCollapse={collapseContextFromHeader} />
          </ResizablePanel>

          <ResizableHandle
            withHandle
            className="w-[1px] border-none"
            style={{ background: colors.borderLight }}
          />

          {/* Column 3: Editor Canvas — takes the full width by default since the
              four side panels start collapsed (0+0+100+0+0 = 100). */}
          <ResizablePanel defaultSize={100} minSize={30}>
            <ReviewEditorCanvas />
          </ResizablePanel>

          <ResizableHandle
            withHandle
            className="w-[1px] border-none"
            style={{ background: colors.borderLight }}
          />

          {/* Column 4: Revisions history queue — collapsible */}
          <ResizablePanel
            defaultSize={0}
            minSize={12}
            maxSize={30}
            collapsible={true}
            collapsedSize={0}
            onCollapse={() => {
              if (!desiredCollapsed.current.revisions) {
                applyDesiredLayout();
                return;
              }
              setIsRevisionsCollapsed(true);
            }}
            onExpand={() => {
              if (desiredCollapsed.current.revisions) {
                applyDesiredLayout();
                return;
              }
              setIsRevisionsCollapsed(false);
            }}
          >
            <RevisionsPanel onCollapse={collapseRevisionsFromHeader} />
          </ResizablePanel>

          <ResizableHandle
            withHandle
            className="w-[1px] border-none"
            style={{ background: colors.borderLight }}
          />

          {/* Column 5: AI Review — its OWN drawer (20260715 card), collapsible */}
          <ResizablePanel
            defaultSize={0}
            minSize={12}
            maxSize={30}
            collapsible={true}
            collapsedSize={0}
            onCollapse={() => {
              if (!desiredCollapsed.current.review) {
                applyDesiredLayout();
                return;
              }
              setIsReviewCollapsed(true);
            }}
            onExpand={() => {
              if (desiredCollapsed.current.review) {
                applyDesiredLayout();
                return;
              }
              setIsReviewCollapsed(false);
            }}
          >
            <AiReviewPanel onCollapse={collapseReviewFromHeader} />
          </ResizablePanel>

        </ResizablePanelGroup>
      </div>

      {/* ── Send to Approvals Dialog ──────────────────── */}
      <Dialog open={showApprovalDialog} onOpenChange={setShowApprovalDialog}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Share2 className="w-5 h-5" style={{ color: colors.primary }} />
              Send Article to Client
            </DialogTitle>
            <DialogDescription>
              Package "{activeDoc?.title || 'Untitled'}" into a secure shareable client review board.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4 py-4">
            {!shareableLink ? (
              <div className="space-y-2">
                <label
                  htmlFor="approval-set-name"
                  style={{ fontSize: typography.xs, fontWeight: typography.semibold, color: colors.textSecondary }}
                >
                  Approval Set Name (Visible to Client)
                </label>
                <Input
                  id="approval-set-name"
                  value={approvalSetName}
                  onChange={(e) => setApprovalSetName(e.target.value)}
                  placeholder="e.g., Blog Post Review — May 29"
                  disabled={createApprovalMutation.isPending}
                />
              </div>
            ) : (
              <div className="space-y-3 rounded-lg p-4" style={{ background: colors.bgPage, border: `1px solid ${colors.border}` }}>
                <span style={{ fontSize: typography.xs, fontWeight: typography.semibold, color: colors.textSecondary }}>
                  Generated Shareable Client Board Link
                </span>
                <div className="flex items-center gap-2">
                  <Input
                    value={shareableLink}
                    readOnly
                    className="font-mono text-xs select-all shrink"
                    style={{ background: colors.bgSurface }}
                  />
                  <Button
                    size="icon"
                    className="shrink-0"
                    style={{ background: colors.primary }}
                    onClick={() => {
                      navigator.clipboard.writeText(shareableLink).then(() => {
                        setLinkCopied(true);
                        toast.success('Link copied!');
                        setTimeout(() => setLinkCopied(false), 2000);
                      });
                    }}
                  >
                    {linkCopied ? <Check className="w-4 h-4" /> : <Copy className="w-4 h-4" />}
                  </Button>
                </div>
              </div>
            )}
          </div>

          <DialogFooter className="gap-2 sm:gap-0">
            {!shareableLink ? (
              <>
                <Button variant="ghost" onClick={() => setShowApprovalDialog(false)} disabled={createApprovalMutation.isPending}>
                  Cancel
                </Button>
                <Button onClick={handleCreateApprovalSet} disabled={createApprovalMutation.isPending} className="gap-2" style={{ background: colors.primary }}>
                  {createApprovalMutation.isPending ? (
                    <><Loader2 className="w-4 h-4 animate-spin" /> Creating...</>
                  ) : (
                    <><Share2 className="w-4 h-4" /> Generate Share Link</>
                  )}
                </Button>
              </>
            ) : (
              <Button onClick={() => setShowApprovalDialog(false)} variant="secondary" className="w-full">
                Done & Close
              </Button>
            )}
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
