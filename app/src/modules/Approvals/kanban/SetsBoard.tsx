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

import { useCallback, useEffect, useMemo, useState } from 'react';
import { KanbanSquare, Trash2, X } from 'lucide-react';

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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { SearchableSelect, type SearchableSelectOption } from '@/components/shared';
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

/** Sentinel value used by the sort dropdown to represent "no sort". */
const NO_SORT_VALUE = '__none__';

function isApprovalStatus(value: string): value is ApprovalStatus {
  return (APPROVAL_STATUSES as ReadonlyArray<string>).includes(value);
}

/** A registry row reduced to what a dropdown needs. */
type NamedRow = { id: number; name: string };

/** Normalize a registry response (wpdb serializes ids as strings) to id + name. */
function toNamedRows(raw: unknown): NamedRow[] {
  if (!Array.isArray(raw)) return [];
  return raw
    .map((r) => {
      const row = r as { id?: unknown; name?: unknown };
      return { id: Number(row.id), name: String(row.name ?? '') };
    })
    .filter((r) => Number.isFinite(r.id) && r.name !== '');
}

function nameMap(rows: ReadonlyArray<NamedRow>): Map<number, string> {
  return new Map(rows.map((r) => [r.id, r.name]));
}

/**
 * Dropdown options for one link: only ids that actually occur on the board, each
 * labelled from its registry. Narrowing to present ids means every choice returns
 * at least one card — no dead options. An id with no registry row (deleted brand,
 * project outside the caller's scope) is labelled honestly rather than hidden, so
 * the card it belongs to stays reachable.
 */
function linkOptions(
  sets: ReadonlyArray<ApprovalSet>,
  accessor: (s: ApprovalSet) => number | null | undefined,
  names: Map<number, string>,
  unknownLabel: string
): SearchableSelectOption[] {
  const present = new Set<number>();
  for (const s of sets) {
    const id = accessor(s);
    if (id != null) present.add(Number(id));
  }
  return Array.from(present)
    .map((id) => ({ value: String(id), label: names.get(id) ?? `${unknownLabel} #${id}` }))
    .sort((a, b) => a.label.localeCompare(b.label));
}

/** Currently-selected value of a searchable filter, or null when unset. */
function readSelect(state: FilterState, filterId: string): string | null {
  const v = state[filterId];
  if (v?.kind === 'searchableSelect' && v.selected.length > 0) {
    return v.selected[0];
  }
  return null;
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

  // The three dropdowns filter by ID and read their labels from the registries
  // that own those names — the set row carries only the ids.
  const { data: brandsRaw } = trpc.brands.list.useQuery();
  const { data: deliveriesRaw } = trpc.deliveries.list.useQuery();
  const { data: projectsRaw } = trpc.assets.getProjects.useQuery();

  const brandNameById = useMemo(() => nameMap(toNamedRows(brandsRaw)), [brandsRaw]);
  const deliveryNameById = useMemo(() => nameMap(toNamedRows(deliveriesRaw)), [deliveriesRaw]);
  const projectNameById = useMemo(() => nameMap(toNamedRows(projectsRaw)), [projectsRaw]);

  const listState = useListState<ApprovalSet>(sets, setFilters, setSorts, {
    // `.v2`: the 2026-08-04 bar dropped the `search` + `set` filters. applyFilters
    // ignores state with no matching definition, but activeFilterCount counts raw
    // state — a leftover key would strand "Clear filters" visible forever. A new
    // key abandons the old state instead of migrating it.
    persistKey: 'pcm.approvals.sets.v2',
    defaultSortId: DEFAULT_SET_SORT,
  });

  // Notification → Approvals: consume the one-shot focus request and open that
  // set's preview card directly (owner decision 2026-08-04 — it used to pin the
  // `set` filter, which no longer exists). Depends on the PENDING VALUE itself
  // (not just sets.length) so the jump also works when the board is already
  // mounted — clicking the panel button while ON the Approvals tab changes no
  // module and loads no new sets.
  const { consumePendingApprovalSetId, state: appState } = useApp();
  const pendingFocusId = appState.pendingApprovalSetId;
  useEffect(() => {
    if (pendingFocusId === null || sets.length === 0) return;
    const focusId = consumePendingApprovalSetId();
    if (focusId === null) return;
    // Number(): notification setIds arrive as strings (wpdb BIGINT JSON).
    const target = sets.find((s) => Number(s.id) === Number(focusId));
    if (target) openPreview(target);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pendingFocusId, sets.length]);

  const brandOptions = useMemo(
    () => linkOptions(sets, (s) => s.brandId, brandNameById, 'Brand'),
    [sets, brandNameById]
  );
  const deliveryOptions = useMemo(
    () => linkOptions(sets, (s) => s.deliveryId, deliveryNameById, 'Delivery'),
    [sets, deliveryNameById]
  );
  const projectOptions = useMemo(
    () => linkOptions(sets, (s) => s.projectId, projectNameById, 'Project'),
    [sets, projectNameById]
  );

  const brandValue = readSelect(listState.filterState, 'brand');
  const deliveryValue = readSelect(listState.filterState, 'delivery');
  const projectValue = readSelect(listState.filterState, 'project');
  const sortValue = listState.sortId ?? NO_SORT_VALUE;

  const handleSelectChange = useCallback(
    (filterId: string, value: string | null) => {
      listState.setFilterValue(
        filterId,
        value === null ? undefined : { kind: 'searchableSelect', selected: [value] }
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
        // Resolved from the brands registry: the list endpoint doesn't ship the
        // snapshot, so the card's own snapshot.brandName is always empty.
        brandName={s.brandId != null ? brandNameById.get(s.brandId) ?? null : null}
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
      brandNameById,
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
          <SearchableSelect
            options={brandOptions}
            value={brandValue}
            onChange={(v) => handleSelectChange('brand', v)}
            placeholder="Brand"
            allLabel="All brands"
            searchPlaceholder="Search brands…"
            emptyLabel="No brands match"
            className="w-[180px]"
          />

          <SearchableSelect
            options={deliveryOptions}
            value={deliveryValue}
            onChange={(v) => handleSelectChange('delivery', v)}
            placeholder="Delivery"
            allLabel="All deliveries"
            searchPlaceholder="Search deliveries…"
            emptyLabel="No deliveries match"
            className="w-[180px]"
          />

          <SearchableSelect
            options={projectOptions}
            value={projectValue}
            onChange={(v) => handleSelectChange('project', v)}
            placeholder="Project"
            allLabel="All projects"
            searchPlaceholder="Search projects…"
            emptyLabel="No projects match"
            className="w-[180px]"
          />

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
