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
import { activeDocumentAtom, addDocumentsAtom, writerSelectedModelAtom } from './store';
import type { WriterDocument } from './store';
import { DocumentQueuePanel } from './components/DocumentQueuePanel';
import { ContextGenerationPanel } from './components/ContextGenerationPanel';
import { ReviewEditorCanvas } from './components/ReviewEditorCanvas';
import { AiRevisionsPanel } from './components/AiRevisionsPanel';
import { PillButton, StatusBadge, colors, typography } from '@/components/shared';
import { Link2, Send, PanelRightOpen, PanelLeftOpen, History } from 'lucide-react';
import { useRef, useState, useEffect } from 'react';
import { ImperativePanelHandle } from 'react-resizable-panels';
import { useApp } from '@/contexts/AppContext';
import { useSettings } from '@/contexts/AppContext';
import { buildDefaultWriterFormValues } from './writerConfig';

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
  const { state, dispatch } = useApp();
  const { settings } = useSettings();

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

  // ── Panel refs for collapsible behavior ──
  const queuePanelRef = useRef<ImperativePanelHandle>(null);
  const contextPanelRef = useRef<ImperativePanelHandle>(null);
  const aiPanelRef = useRef<ImperativePanelHandle>(null);
  const [isQueueCollapsed, setIsQueueCollapsed] = useState(false);
  const [isContextCollapsed, setIsContextCollapsed] = useState(false);
  const [isAiCollapsed, setIsAiCollapsed] = useState(false);
  const [isQueueListCollapsed, setIsQueueListCollapsed] = useState(false);

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
            onClick={() => {
              if (isQueueCollapsed) {
                queuePanelRef.current?.expand();
                setIsQueueCollapsed(false);
              } else {
                queuePanelRef.current?.collapse();
                setIsQueueCollapsed(true);
              }
            }}
          >
            {isQueueCollapsed ? 'Show Queue' : 'Hide Queue'}
          </PillButton>

          <PillButton
            variant={isContextCollapsed ? 'default' : 'subtle'}
            icon={<PanelRightOpen />}
            onClick={() => {
              if (isContextCollapsed) {
                contextPanelRef.current?.expand();
                setIsContextCollapsed(false);
              } else {
                contextPanelRef.current?.collapse();
                setIsContextCollapsed(true);
              }
            }}
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
          >
            Copy Link
          </PillButton>

          <PillButton
            variant={isAiCollapsed ? 'default' : 'subtle'}
            icon={<History />}
            onClick={() => {
              if (isAiCollapsed) {
                aiPanelRef.current?.expand();
                setIsAiCollapsed(false);
              } else {
                aiPanelRef.current?.collapse();
                setIsAiCollapsed(true);
              }
            }}
          >
            {isAiCollapsed ? 'Show Revisions' : 'Hide Revisions'}
          </PillButton>

          <PillButton
            variant="active"
            icon={<Send />}
          >
            Publish to Queue
          </PillButton>
        </div>
      </header>

      {/* ── Main 4-Column Layout ──────────────────────── */}
      <div className="flex-1 overflow-hidden w-full relative">
        <ResizablePanelGroup direction="horizontal" className="h-full w-full">

          {/* Column 1: Document Queue — collapsible */}
          <ResizablePanel
            ref={queuePanelRef}
            defaultSize={15}
            minSize={10}
            maxSize={20}
            collapsible={true}
            collapsedSize={0}
            onCollapse={() => setIsQueueCollapsed(true)}
            onExpand={() => setIsQueueCollapsed(false)}
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
            ref={contextPanelRef}
            defaultSize={22}
            minSize={18}
            maxSize={40}
            collapsible={true}
            collapsedSize={0}
            onCollapse={() => setIsContextCollapsed(true)}
            onExpand={() => setIsContextCollapsed(false)}
          >
            <ContextGenerationPanel onCollapse={() => contextPanelRef.current?.collapse()} />
          </ResizablePanel>

          <ResizableHandle
            withHandle
            className="w-[1px] border-none"
            style={{ background: colors.borderLight }}
          />

          {/* Column 3: Editor Canvas */}
          <ResizablePanel defaultSize={45} minSize={30}>
            <ReviewEditorCanvas />
          </ResizablePanel>

          <ResizableHandle
            withHandle
            className="w-[1px] border-none"
            style={{ background: colors.borderLight }}
          />

          {/* Column 4: Revisions — collapsible */}
          <ResizablePanel
            ref={aiPanelRef}
            defaultSize={18}
            minSize={12}
            maxSize={30}
            collapsible={true}
            collapsedSize={0}
            onCollapse={() => setIsAiCollapsed(true)}
            onExpand={() => setIsAiCollapsed(false)}
          >
            <AiRevisionsPanel onCollapse={() => aiPanelRef.current?.collapse()} />
          </ResizablePanel>

        </ResizablePanelGroup>
      </div>
    </div>
  );
}
