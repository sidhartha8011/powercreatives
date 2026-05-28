/**
 * DeliveriesBoard — Deliveries pipeline orchestrator.
 *
 * Owns:
 *   - The filter / sort bar (shadcn primitives — matches every other
 *     module bar pixel-for-pixel, see Approvals' SetsBoard).
 *   - The two distinct empty states:
 *       * truly-empty data  → primary-action onboarding ("Create your
 *         first delivery"), board hidden.
 *       * filter-empty data → notice + Clear-filters, board kept visible.
 *   - Delete confirmation via shadcn AlertDialog.
 *   - DnD column moves → status mutation via useDeliveries.
 *
 * Data + side effects live in useDeliveries — this component is pure
 * presentation + interaction glue. Same pattern as SetsBoard.
 */

import {
  useCallback,
  useMemo,
  useState,
  type ChangeEvent,
} from 'react';
import { Package, Plus, Search, X } from 'lucide-react';

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
  KanbanBoard,
  useListState,
  type FilterState,
  type KanbanMoveEvent,
} from '@/components/shared/Kanban';
import { EmptyState } from '@/components/shared/EmptyState';

import { DeliveryCard } from './DeliveryCard';
import { deliveryColumns } from './deliveryColumns';
import { deliveryFilters } from './deliveryFilters';
import { DEFAULT_DELIVERY_SORT, deliverySorts } from './deliverySorts';
import { useDeliveries } from '../hooks/useDeliveries';
import {
  DELIVERY_STATUSES,
  type Delivery,
  type DeliveryStatus,
} from '../types';

/** Sentinel for "no filter selected" in the shadcn single-select dropdowns. */
const ALL_VALUE = '__all__';
/** Sentinel for "no explicit sort". */
const NO_SORT_VALUE = '__none__';

function isDeliveryStatus(value: string): value is DeliveryStatus {
  return (DELIVERY_STATUSES as ReadonlyArray<string>).includes(value);
}

function uniqueValues(
  items: ReadonlyArray<Delivery>,
  accessor: (d: Delivery) => string | null | undefined
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

export interface DeliveriesBoardProps {
  /** Open the create-delivery dialog. Wired up by the module entry. */
  onCreate: () => void;
  /** Open the edit-delivery dialog for the given row. */
  onEdit: (delivery: Delivery) => void;
}

export function DeliveriesBoard({ onCreate, onEdit }: DeliveriesBoardProps) {
  const {
    deliveries,
    isLoading,
    error,
    updateStatus,
    deleteDelivery,
  } = useDeliveries();

  const listState = useListState<Delivery>(
    deliveries,
    deliveryFilters,
    deliverySorts,
    {
      persistKey: 'pcm.deliveries',
      defaultSortId: DEFAULT_DELIVERY_SORT,
    }
  );

  const clientOptions = useMemo(
    () => uniqueValues(deliveries, (d) => d.clientName),
    [deliveries]
  );

  const searchQuery = readSearch(listState.filterState);
  const clientValue = readSelect(listState.filterState, 'client');
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

  const getColumnId = useCallback((d: Delivery) => d.status, []);

  // ─── Delete confirmation flow ────────────────────────────────
  const [pendingDelete, setPendingDelete] = useState<Delivery | null>(null);

  const requestDelete = useCallback((d: Delivery) => {
    setPendingDelete(d);
  }, []);

  const cancelDelete = useCallback(() => setPendingDelete(null), []);

  const confirmDelete = useCallback(() => {
    if (!pendingDelete) return;
    void deleteDelivery(pendingDelete.id);
    setPendingDelete(null);
  }, [pendingDelete, deleteDelivery]);

  const renderCard = useCallback(
    (d: Delivery) => (
      <DeliveryCard
        delivery={d}
        onEdit={onEdit}
        onRequestDelete={requestDelete}
      />
    ),
    [onEdit, requestDelete]
  );

  const handleMove = useCallback(
    (event: KanbanMoveEvent) => {
      if (!isDeliveryStatus(event.toColumnId)) return;
      const id = Number(event.itemId);
      if (!Number.isFinite(id)) return;
      void updateStatus(id, event.toColumnId);
    },
    [updateStatus]
  );

  const hasActiveFilter = listState.activeFilterCount > 0;
  const isDataEmpty = !isLoading && deliveries.length === 0;
  const isFilterEmpty =
    !isLoading &&
    deliveries.length > 0 &&
    listState.filteredItems.length === 0;

  return (
    <div className="flex-1 flex flex-col min-h-0">
      {/* Filter / sort bar. Same chrome as Approvals' SetsBoard
          (bg-slate-50/50 + border-slate-100) so the two pipelines look
          identical pixel-for-pixel. */}
      <div className="flex flex-wrap items-center gap-3 mb-6 bg-slate-50/50 p-2 rounded-lg border border-slate-100">
        <div className="relative flex-1 min-w-[200px] max-w-[300px]">
          <Search
            className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground"
            aria-hidden="true"
          />
          <Input
            placeholder="Search deliveries…"
            value={searchQuery}
            onChange={handleSearchChange}
            className="pl-9 h-9 bg-white"
            aria-label="Search deliveries"
          />
        </div>

        <Select
          value={clientValue}
          onValueChange={(v) => handleSelectChange('client', v)}
        >
          <SelectTrigger
            className="w-[180px] h-9 bg-white"
            aria-label="Client"
          >
            <SelectValue placeholder="Client" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL_VALUE}>All clients</SelectItem>
            {clientOptions.map((c) => (
              <SelectItem key={c} value={c}>
                {c}
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
            Showing {listState.filteredItems.length} deliver
            {listState.filteredItems.length === 1 ? 'y' : 'ies'}
          </div>
          <Select value={sortValue} onValueChange={handleSortChange}>
            <SelectTrigger className="w-[170px] h-9 bg-white" aria-label="Sort">
              <SelectValue placeholder="Sort" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={NO_SORT_VALUE}>No sort</SelectItem>
              {deliverySorts.map((s) => (
                <SelectItem key={s.id} value={s.id}>
                  {s.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </div>

      {/* Body */}
      {isDataEmpty ? (
        <EmptyState
          icon={<Package className="h-12 w-12" />}
          title="No deliveries yet"
          description="Create your first delivery to start organizing client fulfilment in a Kanban pipeline."
          action={
            <Button onClick={onCreate} className="gap-2">
              <Plus className="w-4 h-4" />
              Create Delivery
            </Button>
          }
        />
      ) : (
        <div className="flex-1 min-h-0 overflow-auto flex flex-col">
          {isFilterEmpty && (
            <div className="flex items-center gap-2 text-sm text-slate-500 mb-3 shrink-0">
              <span>No deliveries match your filters.</span>
              <button
                type="button"
                onClick={listState.clearAll}
                className="text-slate-700 underline underline-offset-2 hover:text-slate-900"
              >
                Clear filters
              </button>
            </div>
          )}
          <KanbanBoard<Delivery>
            columns={deliveryColumns}
            items={listState.filteredItems}
            getColumnId={getColumnId}
            renderCard={renderCard}
            onItemMove={handleMove}
            isLoading={isLoading}
            error={error}
            ariaLabel="Deliveries pipeline"
          />
        </div>
      )}

      {/* Delete confirmation */}
      <AlertDialog
        open={pendingDelete !== null}
        onOpenChange={(open) => { if (!open) cancelDelete(); }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              Delete "{pendingDelete?.name ?? ''}"?
            </AlertDialogTitle>
            <AlertDialogDescription>
              This action cannot be undone. The delivery will be permanently
              removed.
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
