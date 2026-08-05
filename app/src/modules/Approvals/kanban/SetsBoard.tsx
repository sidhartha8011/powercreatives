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
import { CheckSquare, KanbanSquare, Trash2, X } from 'lucide-react';

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
import { useProjectPickerData } from '@/components/shared/ProjectPicker';
import {
  DefaultEmptyState,
  KanbanBoard,
  useListState,
  type FilterState,
  type KanbanMoveEvent,
} from '@/components/shared/Kanban';

import { CardDocumentView, type CardDocumentProperty } from '../components/CardDocumentView';
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

/**
 * A registry row reduced to what a dropdown needs.
 *
 * This file used to carry its own `toNamedRows()` normaliser for brands,
 * deliveries and projects — a second definition of "what a registry row is",
 * beside `useProjectPickerData()`. Two normalisers for the same three registries
 * is exactly how `deliveryId` went missing from the picker options once already,
 * so the board now consumes the shared hook and keeps no copy.
 */
type NamedRow = { id: number; name: string };

/**
 * Dropdown options for one link: the FULL registry, plus any id a set carries that
 * the registry no longer explains (a deleted brand, a project outside the caller's
 * scope) so that card stays reachable.
 *
 * This used to narrow to ids present on the board, on the reasoning that every
 * choice should return at least one card. That was wrong: on a sparse board it
 * empties the dropdowns entirely — with one set whose project has no delivery, the
 * Delivery and Brand lists came back empty while the registries held 3 deliveries
 * and 2 brands, so there was nothing to search and nothing to filter by. A filter
 * exists to answer "what is available"; a choice that matches nothing already has
 * an honest answer in the board's "No sets match your filters" notice.
 */
function linkOptions(
  registry: ReadonlyArray<NamedRow>,
  sets: ReadonlyArray<ApprovalSet>,
  accessor: (s: ApprovalSet) => number | null | undefined,
  unknownLabel: string
): SearchableSelectOption[] {
  const known = new Set(registry.map((r) => r.id));
  const options = registry.map((r) => ({ value: String(r.id), label: r.name }));

  for (const s of sets) {
    const id = accessor(s);
    if (id != null && !known.has(Number(id))) {
      known.add(Number(id));
      options.push({ value: String(id), label: `${unknownLabel} #${id}` });
    }
  }
  return options.sort((a, b) => a.label.localeCompare(b.label));
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

/**
 * What a lane's "+" carries into the create dialog: the lane it was clicked in,
 * plus whatever the filters are currently narrowed to.
 *
 * `deliveryId` is deliberately NOT a set field — it only seeds the
 * project→delivery link inside the dialog. The set maps to a project and
 * nothing else, and `brandId` is overwritten by the live chain the moment a
 * project is chosen.
 */
export interface CreateInLaneContext {
  status: ApprovalStatus;
  brandId: number | null;
  deliveryId: number | null;
  projectId: number | null;
}

export interface SetsBoardProps {
  /** Called by a lane's "+" with the lane + active filters. */
  onCreateInLane?: (ctx: CreateInLaneContext) => void;
}

export function SetsBoard({ onCreateInLane }: SetsBoardProps) {
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
    isSelected,
    toggleSelection,
    clearSelection,
  } = useApprovalSets();

  // The three dropdowns filter by ID and read their labels from the registries
  // that own those names — the set row carries only the ids. ONE normaliser,
  // shared with the pickers: the board keeps no copy of that boundary code.
  const {
    projects: projectRows,
    deliveries: deliveryRows,
    brands: brandRows,
  } = useProjectPickerData();

  const brandNameById = useMemo(
    () => new Map(brandRows.map((b) => [b.id, b.name])),
    [brandRows]
  );

  const projectNameById = useMemo(
    () => new Map(projectRows.map((p) => [p.id, p.name])),
    [projectRows]
  );

  /**
   * The opened set, WHEN IT IS A DOCUMENT.
   *
   * A set authored through "Add Approval Set" carries its Notion document in
   * `snapshot.custom`. Opening such a set used to show `PreviewDialog` — an
   * iframe of the whole public client board — so the document the owner had just
   * written was never reachable as a document from the admin at all.
   *
   * The branch is read off the set's own data. There is no list of "kinds of set
   * that open as documents" to keep in step with anything.
   *
   * Property rows are built from the registries the board already loaded, and
   * only from values that actually resolve — an unresolved id renders no row
   * rather than an id or a placeholder.
   */
  const openDocument = useMemo(() => {
    const doc = previewSet?.snapshot?.custom?.[0];
    if (!previewSet || !doc) return null;

    const properties: CardDocumentProperty[] = [];
    const push = (label: string, value?: string | null) => {
      const clean = typeof value === 'string' ? value.trim() : '';
      if (clean) properties.push({ label, value: clean });
    };

    push('Brand', previewSet.brandId != null ? brandNameById.get(Number(previewSet.brandId)) : null);
    push('Project', previewSet.projectId != null ? projectNameById.get(Number(previewSet.projectId)) : null);
    push('Lane', setColumns.find((c) => c.id === previewSet.status)?.label);

    const created = doc.createdAt || previewSet.createdAt;
    if (created) {
      const d = new Date(String(created).replace(' ', 'T'));
      if (!Number.isNaN(d.getTime())) {
        push('Created', new Intl.DateTimeFormat('en-GB', {
          year: 'numeric', month: 'short', day: 'numeric',
        }).format(d));
      }
    }

    return { set: previewSet, doc, properties };
  }, [previewSet, brandNameById, projectNameById]);

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
    () => linkOptions(brandRows, sets, (s) => s.brandId, 'Brand'),
    [brandRows, sets]
  );
  const deliveryOptions = useMemo(
    () => linkOptions(deliveryRows, sets, (s) => s.deliveryId, 'Delivery'),
    [deliveryRows, sets]
  );
  const projectOptions = useMemo(
    () => linkOptions(projectRows, sets, (s) => s.projectId, 'Project'),
    [projectRows, sets]
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

  // ─── Select mode ─────────────────────────────────────────────
  // An explicit mode, toggled from the toolbar. It used to be inferred from
  // "is anything selected", which meant the only way in was a checkbox that
  // appeared on hover — selection was offered before it was asked for.
  const [selectMode, setSelectMode] = useState(false);
  const toggleSelectMode = useCallback(() => {
    setSelectMode((on) => {
      if (on) clearSelection(); // leaving the mode drops the selection with it
      return !on;
    });
  }, [clearSelection]);

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
        selectMode={selectMode}
      />
    ),
    [
      copyShareLink,
      openFeedback,
      openPreview,
      requestSingleDelete,
      handleToggleSelect,
      isSelected,
      selectMode,
      brandNameById,
    ]
  );

  // Lane "+" → hand the lane and the live filter values up to the dialog owner.
  const handleColumnCreate = useCallback(
    (columnId: string) => {
      if (!onCreateInLane || !isApprovalStatus(columnId)) return;
      const asId = (v: string | null) => (v != null ? Number(v) : null);
      onCreateInLane({
        status: columnId,
        brandId: asId(brandValue),
        deliveryId: asId(deliveryValue),
        projectId: asId(projectValue),
      });
    },
    [onCreateInLane, brandValue, deliveryValue, projectValue]
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
      {selectMode ? (
        <div
          className="flex flex-wrap items-center gap-3 mb-6 bg-accent p-2 rounded-lg border border-border"
          role="region"
          aria-label="Bulk actions"
        >
          <div className="text-sm font-medium text-accent-foreground pl-2">
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
            onClick={toggleSelectMode}
            className="h-9 ml-auto text-muted-foreground"
          >
            <X className="w-4 h-4 mr-1" aria-hidden="true" />
            Done
          </Button>
        </div>
      ) : (
        <div className="flex flex-wrap items-center gap-3 mb-6 bg-muted/40 p-2 rounded-lg border border-border">
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

          {/* Clear-filters lives in the RIGHT-HAND group, not mid-row. Rendering
              it between the dropdowns inserted an element into a flex-wrap row,
              which could wrap the bar to a second line and push the board down
              the moment a filter was chosen. Here the bar's height is constant
              whether or not a filter is active. */}
          <div className="ml-auto flex items-center gap-3">
            {hasActiveFilter && (
              <Button
                variant="ghost"
                size="sm"
                onClick={listState.clearAll}
                className="h-9 px-2 text-muted-foreground"
              >
                <X className="w-4 h-4 mr-1" aria-hidden="true" />
                Clear filters
              </Button>
            )}
            {/* Always-present slot: it reports the count, or says nothing matched.
                Either way it is the SAME element in the SAME row, so the board
                below never moves because of filter state. */}
            <div className="text-xs text-muted-foreground font-medium">
              {isFilterEmpty ? (
                'No sets match your filters'
              ) : (
                <>
                  Showing {listState.filteredItems.length} set
                  {listState.filteredItems.length === 1 ? '' : 's'}
                </>
              )}
            </div>
            <Select value={sortValue} onValueChange={handleSortChange}>
              <SelectTrigger className="w-[170px] h-9 bg-card" aria-label="Sort">
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

            {/* Selection is a mode you turn ON here — cards no longer offer a
                checkbox just because the cursor passed over them. */}
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={toggleSelectMode}
              className="h-9 gap-1.5"
              aria-pressed={selectMode}
            >
              <CheckSquare className="w-4 h-4" aria-hidden="true" />
              Select
            </Button>
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
          {/* No "nothing matched" row here. It used to render above the board and
              pushed every lane down the moment a filter matched nothing — the
              layout must not move because of filter state. The same message now
              occupies the bar's existing counter slot, which is always present. */}
          <KanbanBoard<ApprovalSet>
            columns={setColumns}
            items={listState.filteredItems}
            getColumnId={getColumnId}
            renderCard={renderCard}
            onItemMove={handleMove}
            onColumnCreate={onCreateInLane ? handleColumnCreate : undefined}
            isLoading={isLoading}
            error={error}
            ariaLabel="Approval sets pipeline"
          />
        </div>
      )}

      <FeedbackDialog set={feedbackSet} onClose={closeFeedback} />

      {/* A set authored as a document opens AS that document — the Notion page,
          in the admin, at full size. Everything else keeps the iframe preview of
          the client board, unchanged. The branch is read off the set's own data
          (does it hold a custom document?), never a maintained list of set kinds.
          Read-only: approving belongs to the client, and the admin holds no
          public token, so no Approve action is passed. */}
      {openDocument ? (
        <CardDocumentView
          content={openDocument.doc.content || ''}
          title={openDocument.doc.title || openDocument.set.name || 'Untitled Document'}
          properties={openDocument.properties}
          overlay={openDocument.doc.overlay}
          onClose={closePreview}
        />
      ) : (
        <PreviewDialog
          set={previewSet}
          url={previewSet ? getPublicBoardUrl(previewSet.token) : null}
          onClose={closePreview}
        />
      )}

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
