/**
 * SetsBoard — thin orchestrator that wires
 *   useApprovalSets (data)  +  setColumns / setFilters / setSorts
 *   (declarations)          +  KanbanToolbar + KanbanBoard (chrome).
 *
 * Holds no domain logic of its own. Adding a new column / filter / sort
 * happens in the declaration files — this orchestrator does not change.
 */

import { useCallback } from 'react';
import { KanbanSquare } from 'lucide-react';

import {
  DefaultEmptyState,
  KanbanBoard,
  KanbanToolbar,
  useListState,
} from '@/components/shared/Kanban';

import { FeedbackDialog } from './FeedbackDialog';
import { SetCard } from './SetCard';
import { setColumns } from './setColumns';
import { setFilters } from './setFilters';
import { DEFAULT_SET_SORT, setSorts } from './setSorts';
import { useApprovalSets } from '../hooks/useApprovalSets';
import type { ApprovalSet } from '../types';

export function SetsBoard() {
  const {
    sets,
    isLoading,
    error,
    copyShareLink,
    getPublicBoardUrl,
    feedbackSet,
    openFeedback,
    closeFeedback,
  } = useApprovalSets();

  const listState = useListState<ApprovalSet>(sets, setFilters, setSorts, {
    persistKey: 'pcm.approvals.sets',
    defaultSortId: DEFAULT_SET_SORT,
  });

  // Stable identity — KanbanBoard memoizes grouping on these refs.
  const getColumnId = useCallback((set: ApprovalSet) => set.status, []);
  const renderCard = useCallback(
    (set: ApprovalSet) => (
      <SetCard
        set={set}
        getPublicBoardUrl={getPublicBoardUrl}
        onCopyLink={copyShareLink}
        onOpenFeedback={openFeedback}
      />
    ),
    [getPublicBoardUrl, copyShareLink, openFeedback]
  );

  return (
    <div className="flex-1 flex flex-col min-h-0">
      <KanbanToolbar
        items={sets}
        filters={setFilters}
        sorts={setSorts}
        state={listState}
        ariaLabel="Filter and sort approval sets"
      />

      <div className="flex-1 min-h-0 overflow-x-auto">
        <KanbanBoard<ApprovalSet>
          columns={setColumns}
          items={listState.filteredItems}
          getColumnId={getColumnId}
          renderCard={renderCard}
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
    </div>
  );
}
