/**
 * SetsBoard — Approvals pipeline orchestrator.
 *
 * Owns:
 *   - The filter / sort bar (rendered inline with shadcn primitives so it
 *     matches every other module bar pixel-for-pixel — see Projects).
 *   - The bulk-action bar — replaces the filter bar when any card is
 *     selected. Hosts Delete + Cancel; selection state lives in the
 *     useApprovalSets hook so multiple consumers stay in sync.
 *   - Single-card + bulk-delete confirmation via shadcn AlertDialog.
 *   - The two distinct empty states:
 *       * truly-empty data  → ad-hoc empty state, board hidden.
 *       * filter-empty data → notice + Clear-filters, board kept visible
 *         so the user keeps her mental model of the pipeline.
 *   - DnD column moves → status mutation via useApprovalSets.
 */

import { useCallback, useEffect, useMemo, useState, type ChangeEvent } from 'react';
import { KanbanSquare, Search, Trash2, X } from 'lucide-react';

import { trpc } from '@/lib/trpc';
import { useApp } from '@/contexts/AppContext';

import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
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

/**
 * Pending-deletion intent. Drives the AlertDialog and, on confirm,
 * which mutation to invoke. `null` = no dialog open.
 */
type PendingDelete =
  | { kind: 'single'; set: ApprovalSet }
  | { kind: 'bulk'; ids: ReadonlyArray<number> }
  | null;

export function SetsBoard() {
  const {
    sets,
    isLoading,
    error,
    copyShareLink,
    getPublicBoardUrl,
    updateStatus,
    deleteSet,
    bulkDeleteSets,
    feedbackSet,
    openFeedback,
    closeFeedback,
    previewSet,
    openPreview,
    closePreview,
    selectedIds,
    hasSelection,
    isSelected,
    toggleSelection,
    clearSelection,
  } = useApprovalSets();

  // Resolve delivery names onto the set rows (sets only carry deliveryId) so
  // the Delivery filter + search work on human-readable names.
  const { data: deliveriesRaw } = trpc.deliveries.list.useQuery();
  const deliveryNameById = useMemo(() => {
    const m = new Map<number, string>();
    (Array.isArray(deliveriesRaw) ? deliveriesRaw : []).forEach((d: any) =>
      m.set(Number(d.id), String(d.name))
    );
    return m;
  }, [deliveriesRaw]);
  const setsWithDelivery = useMemo<ApprovalSet[]>(
    () =>
      sets.map((s) => ({
        ...s,
        deliveryName:
          s.deliveryId != null ? deliveryNameById.get(s.deliveryId) ?? null : null,
      })),
    [sets, deliveryNameById]
  );

  const listState = useListState<ApprovalSet>(setsWithDelivery, setFilters, setSorts, {
    persistKey: 'pcm.approvals.sets',
    defaultSortId: DEFAULT_SET_SORT,
  });

  // Notification → Approvals: consume the one-shot focus request and apply the
  // existing Set filter so only that card shows (Clear filters resets).
  // Depends on the PENDING VALUE itself (not just sets.length) so the jump
  // also works when the board is already mounted — clicking the panel button
  // while ON the Approvals tab changes no module and loads no new sets.
  const { consumePendingApprovalSetId, state: appState } = useApp();
  const pendingFocusId = appState.pendingApprovalSetId;
  useEffect(() => {
    if (pendingFocusId === null || sets.length === 0) return;
    const focusId = consumePendingApprovalSetId();
    if (focusId === null) return;
    // Number(): notification setIds arrive as strings (wpdb BIGINT JSON).
    const target = sets.find((s) => Number(s.id) === Number(focusId));
    if (target) {
      listState.setFilterValue('set', {
        kind: 'searchableSelect',
        selected: [target.name],
      });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pendingFocusId, sets.length]);

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
  const deliveryOptions = useMemo(
    () => uniqueValues(setsWithDelivery, (s) => s.deliveryName ?? undefined),
    [setsWithDelivery]
  );

  const searchQuery = readSearch(listState.filterState);
  const brandValue = readSelect(listState.filterState, 'brand');
  const projectValue = readSelect(listState.filterState, 'project');
  const deliveryValue = readSelect(listState.filterState, 'delivery');
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

  // ─── Delete confirmation flow ────────────────────────────────
  const [pendingDelete, setPendingDelete] = useState<PendingDelete>(null);


  const requestSingleDelete = useCallback((s: ApprovalSet) => {
    setPendingDelete({ kind: 'single', set: s });
  }, []);

  const requestBulkDelete = useCallback(() => {
    if (selectedIds.size === 0) return;
    setPendingDelete({ kind: 'bulk', ids: Array.from(selectedIds) });
  }, [selectedIds]);

  const cancelDelete = useCallback(() => setPendingDelete(null), []);

  const confirmDelete = useCallback(() => {
    if (!pendingDelete) return;
    if (pendingDelete.kind === 'single') {
      void deleteSet(pendingDelete.set.id);
    } else {
      void bulkDeleteSets(pendingDelete.ids);
      clearSelection();
    }
    setPendingDelete(null);
  }, [pendingDelete, deleteSet, bulkDeleteSets, clearSelection]);

  const handleToggleSelect = useCallback(
    (s: ApprovalSet) => toggleSelection(s.id),
    [toggleSelection]
  );

  const renderCard = useCallback(
    (s: ApprovalSet) => (
      <SetCard
        set={s}
        onCopyLink={copyShareLink}
        onOpenFeedback={openFeedback}
        onOpenPreview={openPreview}
        onRequestDelete={requestSingleDelete}
        onToggleSelect={handleToggleSelect}
        isSelected={isSelected(s.id)}
        selectMode={hasSelection}
      />
    ),
    [
      copyShareLink,
      openFeedback,
      openPreview,
      requestSingleDelete,
      handleToggleSelect,
      isSelected,
      hasSelection,
    ]
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
  const isDataEmpty = !isLoading && sets.length === 0;
  const isFilterEmpty =
    !isLoading && sets.length > 0 && listState.filteredItems.length === 0;

  return (
    <div className="flex-1 flex flex-col min-h-0">
      {/* Top bar: bulk-action bar when any card is selected, otherwise
          the standard filter/sort bar. They occupy the same slot so the
          layout below never shifts. */}
      {hasSelection ? (
        <div
          className="flex flex-wrap items-center gap-3 mb-6 bg-blue-50 p-2 rounded-lg border border-blue-200"
          role="region"
          aria-label="Bulk actions"
        >
          <div className="text-sm font-medium text-blue-900 pl-2">
            {selectedIds.size} selected
          </div>
          <Button
            type="button"
            variant="destructive"
            size="sm"
            onClick={requestBulkDelete}
            className="h-9"
          >
            <Trash2 className="w-4 h-4 mr-1.5" aria-hidden="true" />
            Delete
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={clearSelection}
            className="h-9 ml-auto text-slate-600"
          >
            <X className="w-4 h-4 mr-1" aria-hidden="true" />
            Cancel
          </Button>
        </div>
      ) : (
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
            value={deliveryValue}
            onValueChange={(v) => handleSelectChange('delivery', v)}
          >
            <SelectTrigger
              className="w-[160px] h-9 bg-white"
              aria-label="Delivery"
            >
              <SelectValue placeholder="Delivery" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ALL_VALUE}>All deliveries</SelectItem>
              {deliveryOptions.map((d) => (
                <SelectItem key={d} value={d}>
                  {d}
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
      )}

      {/* Body */}
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

      {/* Delete confirmation — used for both single and bulk. The
          AlertDialog primitive blocks interaction until confirmed or
          cancelled, which is the correct UX for a destructive action. */}
      <AlertDialog
        open={pendingDelete !== null}
        onOpenChange={(open) => { if (!open) cancelDelete(); }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {pendingDelete?.kind === 'single'
                ? `Delete "${pendingDelete.set.name}"?`
                : pendingDelete?.kind === 'bulk'
                  ? `Delete ${pendingDelete.ids.length} approval set${pendingDelete.ids.length === 1 ? '' : 's'}?`
                  : 'Delete'}
            </AlertDialogTitle>
            <AlertDialogDescription>
              This action cannot be undone. The set
              {pendingDelete?.kind === 'bulk' ? 's' : ''} will be permanently
              removed along with all of its review feedback.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel onClick={cancelDelete}>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={confirmDelete}
              className="bg-destructive text-white hover:bg-destructive/90"
            >
              Delete
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
