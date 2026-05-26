/**
 * SetsBoard — Approvals pipeline orchestrator.
 *
 * Owns:
 *   - The filter / sort bar (rendered inline with shadcn primitives so it
 *     matches every other module bar pixel-for-pixel — see Projects).
 *   - The two distinct empty states:
 *       * truly-empty data  → ad-hoc empty state, board hidden.
 *       * filter-empty data → notice + Clear-filters, board kept visible
 *         so the user keeps her mental model of the pipeline.
 *   - DnD column moves → status mutation via useApprovalSets.
 *
 * The shared kanban primitive renders the lanes and cards. The filter
 * engine (useListState, applyFilters, applySort, factories) provides the
 * derived list; the visual control surface here is plain Tailwind +
 * shadcn so we never fight a custom-CSS specificity ladder again.
 */

import { useCallback, useMemo, type ChangeEvent } from 'react';
import { KanbanSquare, Search, X } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  DefaultEmptyState,
  KanbanBoard,
  useListState,
  type FilterState,
  type KanbanMoveEvent,
} from '@/components/shared/Kanban';

import { FeedbackDialog } from './FeedbackDialog';
import { PreviewDialog } from './PreviewDialog';
import { SetCard } from './SetCard';
import { setColumns } from './setColumns';
import { setFilters } from './setFilters';
import { DEFAULT_SET_SORT, setSorts } from './setSorts';
import { useApprovalSets } from '../hooks/useApprovalSets';
import {
  APPROVAL_STATUSES,
  type ApprovalSet,
  type ApprovalStatus,
} from '../types';

/** Sentinel value used by single-select dropdowns to represent "no filter". */
const ALL_VALUE = '__all__';

/** Sentinel value used by the sort dropdown to represent "no sort". */
const NO_SORT_VALUE = '__none__';

function isApprovalStatus(value: string): value is ApprovalStatus {
  return (APPROVAL_STATUSES as ReadonlyArray<string>).includes(value);
}

/** Stable, sorted, de-duplicated list of values for a single-select dropdown. */
function uniqueValues(
  items: ReadonlyArray<ApprovalSet>,
  accessor: (s: ApprovalSet) => string | null | undefined
): string[] {
  const set = new Set<string>();
  for (const item of items) {
    const v = accessor(item);
    if (v) set.add(v);
  }
  return Array.from(set).sort((a, b) => a.localeCompare(b));
}

function readSearch(state: FilterState): string {
  const v = state['search'];
  return v?.kind === 'text' ? v.query : '';
}

function readSelect(state: FilterState, filterId: string): string {
  const v = state[filterId];
  if (v?.kind === 'searchableSelect' && v.selected.length > 0) {
    return v.selected[0];
  }
  return ALL_VALUE;
}

export function SetsBoard() {
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

  // Option lists for the three single-select dropdowns — derived from the
  // current dataset so they stay in sync when sets are added or removed.
  const brandOptions = useMemo(
    () => uniqueValues(sets, (s) => s.snapshot.brandName),
    [sets]
  );
  const projectOptions = useMemo(
    () => uniqueValues(sets, (s) => s.snapshot.projectName),
    [sets]
  );
  const setNameOptions = useMemo(
    () => uniqueValues(sets, (s) => s.name),
    [sets]
  );

  const searchQuery = readSearch(listState.filterState);
  const brandValue = readSelect(listState.filterState, 'brand');
  const projectValue = readSelect(listState.filterState, 'project');
  const setValue = readSelect(listState.filterState, 'set');
  const sortValue = listState.sortId ?? NO_SORT_VALUE;

  const handleSearchChange = useCallback(
    (event: ChangeEvent<HTMLInputElement>) => {
      const next = event.target.value;
      listState.setFilterValue(
        'search',
        next.trim() ? { kind: 'text', query: next } : undefined
      );
    },
    [listState]
  );

  const handleSelectChange = useCallback(
    (filterId: string, value: string) => {
      listState.setFilterValue(
        filterId,
        value === ALL_VALUE
          ? undefined
          : { kind: 'searchableSelect', selected: [value] }
      );
    },
    [listState]
  );

  const handleSortChange = useCallback(
    (value: string) => {
      listState.setSortId(value === NO_SORT_VALUE ? null : value);
    },
    [listState]
  );

  const getColumnId = useCallback((s: ApprovalSet) => s.status, []);

  const renderCard = useCallback(
    (s: ApprovalSet) => (
      <SetCard
        set={s}
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

  const hasActiveFilter = listState.activeFilterCount > 0;
  // Two empty states. They are mutually exclusive: data-empty hides the
  // board, filter-empty keeps it visible with empty lanes so the user
  // does not lose her mental model of the pipeline columns.
  const isDataEmpty = !isLoading && sets.length === 0;
  const isFilterEmpty =
    !isLoading && sets.length > 0 && listState.filteredItems.length === 0;

  return (
    <div className="flex-1 flex flex-col min-h-0">
      {/* Global filter / sort bar — mirrors modules/Projects/index.tsx:518 */}
      <div className="flex flex-wrap items-center gap-3 mb-6 bg-slate-50/50 p-2 rounded-lg border border-slate-100">
        <div className="relative flex-1 min-w-[200px] max-w-[300px]">
          <Search
            className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground"
            aria-hidden="true"
          />
          <Input
            placeholder="Search approval sets..."
            value={searchQuery}
            onChange={handleSearchChange}
            className="pl-9 h-9 bg-white"
            aria-label="Search approval sets"
          />
        </div>

        <Select
          value={brandValue}
          onValueChange={(v) => handleSelectChange('brand', v)}
        >
          <SelectTrigger className="w-[160px] h-9 bg-white" aria-label="Brand">
            <SelectValue placeholder="Brand" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL_VALUE}>All brands</SelectItem>
            {brandOptions.map((b) => (
              <SelectItem key={b} value={b}>
                {b}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        <Select
          value={projectValue}
          onValueChange={(v) => handleSelectChange('project', v)}
        >
          <SelectTrigger
            className="w-[160px] h-9 bg-white"
            aria-label="Project"
          >
            <SelectValue placeholder="Project" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL_VALUE}>All projects</SelectItem>
            {projectOptions.map((p) => (
              <SelectItem key={p} value={p}>
                {p}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        <Select
          value={setValue}
          onValueChange={(v) => handleSelectChange('set', v)}
        >
          <SelectTrigger
            className="w-[160px] h-9 bg-white"
            aria-label="Approval set"
          >
            <SelectValue placeholder="Approval set" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL_VALUE}>All sets</SelectItem>
            {setNameOptions.map((n) => (
              <SelectItem key={n} value={n}>
                {n}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        {hasActiveFilter && (
          <Button
            variant="ghost"
            size="sm"
            onClick={listState.clearAll}
            className="h-9 px-2 text-slate-500"
          >
            <X className="w-4 h-4 mr-1" aria-hidden="true" />
            Clear filters
          </Button>
        )}

        <div className="ml-auto flex items-center gap-3">
          <div className="text-xs text-slate-500 font-medium">
            Showing {listState.filteredItems.length} set
            {listState.filteredItems.length === 1 ? '' : 's'}
          </div>
          <Select value={sortValue} onValueChange={handleSortChange}>
            <SelectTrigger className="w-[170px] h-9 bg-white" aria-label="Sort">
              <SelectValue placeholder="Sort" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={NO_SORT_VALUE}>No sort</SelectItem>
              {setSorts.map((s) => (
                <SelectItem key={s.id} value={s.id}>
                  {s.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </div>

      {/* Body: data-empty hides the board; filter-empty keeps it with a notice. */}
      {isDataEmpty ? (
        <DefaultEmptyState
          icon={<KanbanSquare className="h-8 w-8" />}
          title="No shared Ad Sets in pipeline"
          description='Go to the Ads module, select assets, and click "Share with Client" to create a set.'
        />
      ) : (
        <div className="flex-1 min-h-0 overflow-auto flex flex-col">
          {isFilterEmpty && (
            <div className="flex items-center gap-2 text-sm text-slate-500 mb-3 shrink-0">
              <span>No sets match your filters.</span>
              <button
                type="button"
                onClick={listState.clearAll}
                className="text-slate-700 underline underline-offset-2 hover:text-slate-900"
              >
                Clear filters
              </button>
            </div>
          )}
          <KanbanBoard<ApprovalSet>
            columns={setColumns}
            items={listState.filteredItems}
            getColumnId={getColumnId}
            renderCard={renderCard}
            onItemMove={handleMove}
            isLoading={isLoading}
            error={error}
            ariaLabel="Approval sets pipeline"
          />
        </div>
      )}

      <FeedbackDialog set={feedbackSet} onClose={closeFeedback} />
      <PreviewDialog
        set={previewSet}
        url={previewSet ? getPublicBoardUrl(previewSet.token) : null}
        onClose={closePreview}
      />
    </div>
  );
}
