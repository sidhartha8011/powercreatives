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
import { Package, Plus, RotateCcw, Search, SquareKanban, Table2, X } from 'lucide-react';

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
  type KanbanColumn,
  type KanbanMoveEvent,
} from '@/components/shared/Kanban';
import { EmptyState } from '@/components/shared/EmptyState';

import { trpc } from '@/lib/trpc';

import { DELIVERIES_LAYOUT_KEY, DeliveriesTable, leadOf, typeColors } from '../table/DeliveriesTable';
import { DeliveryCard } from './DeliveryCard';
import { deliveryColumns } from './deliveryColumns';
import { buildDeliveryFilters } from './deliveryFilters';
import { DEFAULT_DELIVERY_SORT, deliverySorts } from './deliverySorts';
import { useDeliveries } from '../hooks/useDeliveries';
import { useTypePresets } from '../hooks/useTypePresets';
import {
  DELIVERY_STATUSES,
  type Delivery,
  type DeliveryStatus,
} from '../types';

/** Sentinel for "no filter selected" in the shadcn single-select dropdowns. */
const ALL_VALUE = '__all__';
/** Sentinel for "no explicit sort". */
const NO_SORT_VALUE = '__none__';

/** Kanban ⇄ Table — persisted per browser, like the SEO column layout. */
type DeliveriesView = 'kanban' | 'table';
const VIEW_STORAGE_KEY = 'pcm:deliveries:view';

/**
 * Dynamic lanes: which delivery field buckets the Kanban. All are
 * single-value fields with a write path, so a lane drop WRITES the value.
 * Modules (multi-value) and Updated (derived) are deliberately excluded.
 */
type LaneField = 'status' | 'type' | 'brand' | 'lead' | 'client';
const LANE_FIELD_OPTIONS: ReadonlyArray<{ value: LaneField; label: string }> = [
  { value: 'status', label: 'Status' },
  { value: 'type', label: 'Type' },
  { value: 'brand', label: 'Brand' },
  { value: 'lead', label: 'Lead' },
  { value: 'client', label: 'Client' },
];
const LANES_STORAGE_KEY = 'pcm:deliveries:lanes';
/** Per-lane-field custom lane ORDER (drag a lane header to reorder). */
const LANE_ORDER_KEY = 'pcm:deliveries:lane-order:v1';
/** Lane id for "field is empty" — dropping here clears the value. */
const NONE_LANE = '__none__';

function readLaneOrders(): Record<string, string[]> {
  try {
    const parsed = JSON.parse(localStorage.getItem(LANE_ORDER_KEY) ?? '{}') as unknown;
    return parsed && typeof parsed === 'object' ? (parsed as Record<string, string[]>) : {};
  } catch {
    return {};
  }
}

function readStoredLaneField(): LaneField {
  try {
    const raw = localStorage.getItem(LANES_STORAGE_KEY);
    return LANE_FIELD_OPTIONS.some((o) => o.value === raw) ? (raw as LaneField) : 'status';
  } catch {
    return 'status';
  }
}

function readStoredView(): DeliveriesView {
  try {
    return localStorage.getItem(VIEW_STORAGE_KEY) === 'table' ? 'table' : 'kanban';
  } catch {
    return 'kanban';
  }
}

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
    updateFieldsOptimistic,
    setLeadOptimistic,
    deleteDelivery,
  } = useDeliveries();

  // Universal search: the filter walks every row value dynamically; brand
  // names and type labels (display-only, not on the row) come from these
  // live lookups so "everything in the table" is genuinely searchable.
  const { data: brandsRaw } = trpc.brands.list.useQuery();
  const brandNames = useMemo(() => {
    const map = new Map<number, string>();
    if (Array.isArray(brandsRaw)) {
      for (const b of brandsRaw as any[]) map.set(Number(b.id), String(b.name ?? ''));
    }
    return map;
  }, [brandsRaw]);
  const { presets: typePresets } = useTypePresets();

  const deliveryFilters = useMemo(
    () =>
      buildDeliveryFilters({
        brandName: (d) => (d.brandId != null ? brandNames.get(Number(d.brandId)) ?? null : null),
        typeLabel: (d) => (d.type ? typePresets[d.type]?.label ?? null : null),
      }),
    [brandNames, typePresets]
  );

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

  // ─── Dynamic lanes ───────────────────────────────────────────
  const [laneField, setLaneField] = useState<LaneField>(readStoredLaneField);
  const switchLaneField = useCallback((next: LaneField) => {
    setLaneField(next);
    try {
      localStorage.setItem(LANES_STORAGE_KEY, next);
    } catch {
      // Storage unavailable — works in-session.
    }
  }, []);

  // Lanes per field: Status keeps its fixed declaration; Type shows every
  // preset (so a card can be dragged INTO an empty type); Brand/Lead/Client
  // derive from the values present in the data (unfiltered, so lanes don't
  // flicker while searching) + a "No X" lane whose drop CLEARS the value.
  const laneColumns = useMemo((): ReadonlyArray<KanbanColumn> => {
    const neutral = { accentColor: '#eef0f3', accentText: '#4a4a45' };
    const noneLane = (label: string): KanbanColumn => ({
      id: NONE_LANE,
      label,
      accentColor: '#f4f4f2',
      accentText: '#8a8a84',
    });
    switch (laneField) {
      case 'status':
        return deliveryColumns;
      case 'type': {
        const lanes: KanbanColumn[] = Object.entries(typePresets).map(([key, preset]) => {
          const colors = typeColors(key);
          return { id: key, label: preset.label, accentColor: colors.bg, accentText: colors.text };
        });
        return [...lanes, noneLane('No type')];
      }
      case 'brand': {
        const present = new Map<string, string>();
        for (const d of deliveries) {
          if (d.brandId != null) {
            const key = String(Number(d.brandId));
            present.set(key, brandNames.get(Number(d.brandId)) ?? `Brand #${key}`);
          }
        }
        const lanes: KanbanColumn[] = [...present.entries()]
          .sort((a, b) => a[1].localeCompare(b[1]))
          .map(([id, label]) => ({ id, label, ...neutral }));
        return [...lanes, noneLane('No brand')];
      }
      case 'lead': {
        const present = new Map<string, string>();
        for (const d of deliveries) {
          const lead = leadOf(d);
          if (lead) present.set(String(lead.id), lead.name);
        }
        const lanes: KanbanColumn[] = [...present.entries()]
          .sort((a, b) => a[1].localeCompare(b[1]))
          .map(([id, label]) => ({ id, label, ...neutral }));
        return [...lanes, noneLane('No lead')];
      }
      case 'client': {
        const present = new Set<string>();
        for (const d of deliveries) {
          const client = d.clientName?.trim();
          if (client) present.add(client);
        }
        const lanes: KanbanColumn[] = [...present]
          .sort((a, b) => a.localeCompare(b))
          .map((client) => ({ id: client, label: client, ...neutral }));
        return [...lanes, noneLane('No client')];
      }
    }
  }, [laneField, deliveries, brandNames, typePresets]);

  // Custom lane order — persisted PER lane field (your Status order is not
  // your Brand order); reconciled so removed lanes drop out and new lanes
  // append, like the table's column layout.
  const [laneOrders, setLaneOrders] = useState<Record<string, string[]>>(readLaneOrders);
  const orderedLaneColumns = useMemo(() => {
    const stored = laneOrders[laneField] ?? [];
    const byId = new Map(laneColumns.map((c) => [c.id, c]));
    const ordered: KanbanColumn[] = [];
    for (const id of stored) {
      const col = byId.get(id);
      if (col) {
        ordered.push(col);
        byId.delete(id);
      }
    }
    for (const col of laneColumns) if (byId.has(col.id)) ordered.push(col);
    return ordered;
  }, [laneColumns, laneOrders, laneField]);

  const handleColumnReorder = useCallback(
    (fromId: string, toId: string) => {
      const ids = orderedLaneColumns.map((c) => c.id);
      const from = ids.indexOf(fromId);
      const to = ids.indexOf(toId);
      if (from < 0 || to < 0) return;
      ids.splice(from, 1);
      ids.splice(to, 0, fromId);
      setLaneOrders((prev) => {
        const next = { ...prev, [laneField]: ids };
        try {
          localStorage.setItem(LANE_ORDER_KEY, JSON.stringify(next));
        } catch {
          // Storage unavailable — order still applies in-session.
        }
        return next;
      });
    },
    [orderedLaneColumns, laneField]
  );

  const getColumnId = useCallback(
    (d: Delivery): string => {
      switch (laneField) {
        case 'status':
          return d.status;
        case 'type':
          return d.type ?? NONE_LANE;
        case 'brand':
          return d.brandId != null ? String(Number(d.brandId)) : NONE_LANE;
        case 'lead': {
          const lead = leadOf(d);
          return lead ? String(lead.id) : NONE_LANE;
        }
        case 'client':
          return d.clientName?.trim() || NONE_LANE;
      }
    },
    [laneField]
  );

  // ─── Kanban ⇄ Table view toggle ──────────────────────────────
  const [view, setView] = useState<DeliveriesView>(readStoredView);

  const switchView = useCallback((next: DeliveriesView) => {
    setView(next);
    try {
      localStorage.setItem(VIEW_STORAGE_KEY, next);
    } catch {
      // Storage unavailable (private mode) — the toggle still works in-session.
    }
  }, []);

  // Reset column layout = clear the stored layout + remount the table so
  // useColumnLayout reloads its defaults (that IS the semantic of "reset").
  const [tableEpoch, setTableEpoch] = useState(0);
  const resetColumns = useCallback(() => {
    try {
      localStorage.removeItem(DELIVERIES_LAYOUT_KEY);
    } catch {
      // Storage unavailable — remount still restores in-memory defaults.
    }
    setTableEpoch((epoch) => epoch + 1);
  }, []);

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

  // A lane drop WRITES the lane field's value (NONE_LANE clears it). Every
  // path is optimistic-with-revert — required by the dnd IDLE-paint contract.
  const handleMove = useCallback(
    (event: KanbanMoveEvent) => {
      const id = Number(event.itemId);
      if (!Number.isFinite(id)) return;
      const to = event.toColumnId;
      switch (laneField) {
        case 'status':
          if (isDeliveryStatus(to)) void updateStatus(id, to);
          return;
        case 'type':
          void updateFieldsOptimistic(id, { type: to === NONE_LANE ? null : to });
          return;
        case 'brand':
          void updateFieldsOptimistic(id, { brandId: to === NONE_LANE ? null : Number(to) });
          return;
        case 'client':
          void updateFieldsOptimistic(id, { clientName: to === NONE_LANE ? null : to });
          return;
        case 'lead': {
          const userId = to === NONE_LANE ? null : Number(to);
          const name = userId != null
            ? laneColumns.find((c) => c.id === to)?.label ?? null
            : null;
          void setLeadOptimistic(id, userId, name);
          return;
        }
      }
    },
    [laneField, laneColumns, updateStatus, updateFieldsOptimistic, setLeadOptimistic]
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
            className="w-[180px]"
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
          {/* Lanes-by: any single-value column can bucket the board; a lane
              drop writes that value. Kanban view only. */}
          {view === 'kanban' && (
            <Select value={laneField} onValueChange={(v) => switchLaneField(v as LaneField)}>
              <SelectTrigger className="w-[140px]" aria-label="Lanes by">
                <SelectValue placeholder="Lanes" />
              </SelectTrigger>
              <SelectContent>
                {LANE_FIELD_OPTIONS.map((o) => (
                  <SelectItem key={o.value} value={o.value}>
                    {o.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
          {/* Sort dropdown drives the Kanban only — in table view the column
              headers own sorting (two competing sort systems would fight). */}
          {view === 'kanban' && (
            <Select value={sortValue} onValueChange={handleSortChange}>
              <SelectTrigger className="w-[170px]" aria-label="Sort">
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
          )}
          {/* Table view: restore default column order + widths. */}
          {view === 'table' && (
            <Button
              variant="ghost"
              size="sm"
              onClick={resetColumns}
              className="h-9 px-2 text-slate-500"
              title="Reset column order and widths"
              aria-label="Reset column order and widths"
            >
              <RotateCcw className="w-4 h-4" aria-hidden="true" />
            </Button>
          )}
          {/* Kanban ⇄ Table — same segmented chrome as Projects' grid/list toggle. */}
          <div
            className="flex items-center gap-0.5 rounded-md bg-slate-100 p-0.5"
            role="group"
            aria-label="Board view"
          >
            <button
              type="button"
              title="Kanban view"
              aria-label="Kanban view"
              aria-pressed={view === 'kanban'}
              onClick={() => switchView('kanban')}
              className={`p-1.5 rounded-sm transition-all ${view === 'kanban' ? 'bg-white shadow-sm text-slate-900' : 'text-slate-500 hover:text-slate-700'}`}
            >
              <SquareKanban className="w-4 h-4" aria-hidden="true" />
            </button>
            <button
              type="button"
              title="Table view"
              aria-label="Table view"
              aria-pressed={view === 'table'}
              onClick={() => switchView('table')}
              className={`p-1.5 rounded-sm transition-all ${view === 'table' ? 'bg-white shadow-sm text-slate-900' : 'text-slate-500 hover:text-slate-700'}`}
            >
              <Table2 className="w-4 h-4" aria-hidden="true" />
            </button>
          </div>
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
          {view === 'table' ? (
            <DeliveriesTable
              key={tableEpoch}
              items={listState.filteredItems}
              onEdit={onEdit}
              onRequestDelete={requestDelete}
            />
          ) : (
            <KanbanBoard<Delivery>
              columns={orderedLaneColumns}
              items={listState.filteredItems}
              getColumnId={getColumnId}
              renderCard={renderCard}
              onItemMove={handleMove}
              onColumnReorder={handleColumnReorder}
              isLoading={isLoading}
              error={error}
              ariaLabel="Deliveries pipeline"
            />
          )}
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
