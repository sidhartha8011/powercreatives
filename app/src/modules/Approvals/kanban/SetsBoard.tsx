/**
 * SetsBoard — thin orchestrator wiring
 *   useApprovalSets (data + mutations)  +  setColumns / setFilters /
 *   setSorts (declarations)             +  KanbanToolbar + KanbanBoard
 *   (chrome + DnD).
 *
 * Handles drag-and-drop column moves by translating a KanbanMoveEvent
 * into a status mutation. Optimistic update lives in the hook.
 */

import { useCallback, type ReactNode } from 'react';
import { KanbanSquare } from 'lucide-react';

import {
  DefaultEmptyState,
  KanbanBoard,
  KanbanToolbar,
  useListState,
  type KanbanMoveEvent,
} from '@/components/shared/Kanban';

import { FeedbackDialog } from './FeedbackDialog';
import { PreviewDialog } from './PreviewDialog';
import { SetCard } from './SetCard';
import { setColumns } from './setColumns';
import { setFilters } from './setFilters';
import { DEFAULT_SET_SORT, setSorts } from './setSorts';
import { useApprovalSets } from '../hooks/useApprovalSets';
import { APPROVAL_STATUSES, type ApprovalSet, type ApprovalStatus } from '../types';

export interface SetsBoardProps {
  /** Optional content rendered at the start of the toolbar row. */
  toolbarLeadingSlot?: ReactNode;
}

function isApprovalStatus(value: string): value is ApprovalStatus {
  return (APPROVAL_STATUSES as ReadonlyArray<string>).includes(value);
}

export function SetsBoard({ toolbarLeadingSlot }: SetsBoardProps = {}) {
  const {
    sets,
    isLoading,
    error,
    copyShareLink,
    getPublicBoardUrl,
    updateStatus,
    feedbackSet,
    openFeedback,
    closeFeedback,
    previewSet,
    openPreview,
    closePreview,
  } = useApprovalSets();

  const listState = useListState<ApprovalSet>(sets, setFilters, setSorts, {
    persistKey: 'pcm.approvals.sets',
    defaultSortId: DEFAULT_SET_SORT,
  });

  const getColumnId = useCallback((set: ApprovalSet) => set.status, []);

  const renderCard = useCallback(
    (set: ApprovalSet) => (
      <SetCard
        set={set}
        onCopyLink={copyShareLink}
        onOpenFeedback={openFeedback}
        onOpenPreview={openPreview}
      />
    ),
    [copyShareLink, openFeedback, openPreview]
  );

  const handleMove = useCallback(
    (event: KanbanMoveEvent) => {
      if (!isApprovalStatus(event.toColumnId)) return;
      const id = Number(event.itemId);
      if (!Number.isFinite(id)) return;
      void updateStatus(id, event.toColumnId);
    },
    [updateStatus]
  );

  return (
    <div className="flex-1 flex flex-col min-h-0">
      <KanbanToolbar
        items={sets}
        filters={setFilters}
        sorts={setSorts}
        state={listState}
        ariaLabel="Filter and sort approval sets"
        leadingSlot={toolbarLeadingSlot}
      />

      <div className="flex-1 min-h-0 overflow-auto">
        <KanbanBoard<ApprovalSet>
          columns={setColumns}
          items={listState.filteredItems}
          getColumnId={getColumnId}
          renderCard={renderCard}
          onItemMove={handleMove}
          isLoading={isLoading}
          error={error}
          ariaLabel="Approval sets pipeline"
          emptyState={
            <DefaultEmptyState
              icon={<KanbanSquare className="h-8 w-8" />}
              title="No shared Ad Sets in pipeline"
              description='Go to the Ads module, select assets, and click "Share with Client" to create a set.'
            />
          }
        />
      </div>

      <FeedbackDialog set={feedbackSet} onClose={closeFeedback} />
      <PreviewDialog
        set={previewSet}
        url={previewSet ? getPublicBoardUrl(previewSet.token) : null}
        onClose={closePreview}
      />
    </div>
  );
}
