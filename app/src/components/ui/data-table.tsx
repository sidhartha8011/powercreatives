/**
 * DataTable — a global, config-driven table for the whole app.
 *
 * Modules describe their table with a `columns` array (header + cell renderer +
 * optional sort accessor) and pass `data`; this component owns the markup,
 * sticky/gridline styling, and sorting. No more hand-rolled <table>/<tr>/<td>
 * per module.
 *
 * It composes the existing shared primitives — `useSortableTable` (sort state)
 * and `SortableTableHead` (clickable sort header) — so behaviour stays identical
 * to the tables already in SEO / Templates / Automations / Projects / Sites.
 *
 * Filtering stays in the consumer (each screen has its own filter bar): pass the
 * already-filtered rows as `data`.
 *
 * Accordion rows: pass `renderSubRows` (and optionally `rowCanExpand`) to get a
 * chevron column; the expanded content renders as NATIVE sibling <tr> rows of
 * the same table — same gridlines, same rhythm — never an embedded table.
 *
 * Example:
 *   const columns: DataTableColumn<Site>[] = [
 *     { key: 'name', header: 'Name', sortAccessor: (s) => s.name.toLowerCase(),
 *       cell: (s) => <strong>{s.name}</strong> },
 *     { key: 'actions', header: 'Actions', className: 'text-center',
 *       cell: (s) => <RowActions site={s} /> },
 *   ];
 *   <DataTable columns={columns} data={filtered} rowKey={(s) => s.id} />
 */

import {
  Fragment,
  useMemo,
  useState,
  type CSSProperties,
  type PointerEvent as ReactPointerEvent,
  type ReactNode,
} from 'react';
import { ChevronRight } from 'lucide-react';

import { cn } from '@/lib/utils';
import { ColumnHead } from '@/components/ui/column-head';
import { SortableTableHead } from '@/components/ui/sortable-table-head';
import { useColumnLayout } from '@/hooks/useColumnLayout';
import { useSortableTable, type SortDirection } from '@/hooks/useSortableTable';

/** Spreadsheet styling shared by every DataTable (matches the SEO table's look:
 *  table-fixed grid, gridlines on every cell, sticky bg-card header, compact rows).
 *  Cell selectors are scoped to the table's DIRECT rows (not `[&_td]`) so content
 *  rendered inside an expanded sub-row — including nested tables — never inherits
 *  the outer grid's borders/heights. Sub-rows (`data-subrow`) opt out entirely. */
const GRID_CLASS =
  'table-fixed w-full border-collapse text-xs bg-card ' +
  '[&>thead>tr>th]:border [&>thead>tr>th]:border-border/60 ' +
  '[&>tbody>tr:not([data-subrow])>td]:border [&>tbody>tr:not([data-subrow])>td]:border-border/60 ' +
  '[&>thead>tr>th]:px-2 [&>thead>tr>th]:h-9 [&>thead>tr>th]:font-normal [&>thead>tr>th]:text-foreground/80 ' +
  '[&>tbody>tr:not([data-subrow])>td]:px-2 [&>tbody>tr:not([data-subrow])>td]:h-9 ' +
  '[&>tbody>tr:not([data-subrow])>td]:py-0 [&>tbody>tr:not([data-subrow])>td]:align-middle ' +
  '[&>tbody>tr:not([data-subrow])>td]:whitespace-nowrap [&>tbody>tr:not([data-subrow])>td]:overflow-hidden ' +
  '[&>thead>tr>th]:sticky [&>thead>tr>th]:top-0 [&>thead>tr>th]:z-20 [&>thead>tr>th]:bg-card';

export interface DataTableColumn<T> {
  /** Stable column id — also the sort key. */
  key: string;
  /** Header content. */
  header: ReactNode;
  /** Cell renderer for a row. */
  cell: (row: T) => ReactNode;
  /**
   * When provided, the column is sortable: returns the comparable value for a
   * row. Omit for non-sortable columns (e.g. an "Actions" column).
   */
  sortAccessor?: (row: T) => string | number | null;
  /** className applied to BOTH the header and body cells (e.g. 'text-center'). */
  className?: string;
  /** className for the header cell only. */
  headClassName?: string;
  /** className for body cells only. */
  cellClassName?: string;
  /** Fixed column width (px number or any CSS width). */
  width?: number | string;
}

export interface DataTableProps<T> {
  columns: DataTableColumn<T>[];
  /** Rows to display (already filtered by the consumer). */
  data: T[];
  /** Stable key per row. */
  rowKey: (row: T) => string | number;
  /** Initial sort column (defaults to the first sortable column). */
  defaultSortKey?: string;
  defaultSortDir?: SortDirection;
  /** Optional row click handler (adds a pointer cursor). */
  onRowClick?: (row: T) => void;
  /** Shown when there are no rows. */
  emptyMessage?: ReactNode;
  /** Extra className for the scroll wrapper. */
  wrapperClassName?: string;
  /** Extra className merged onto the <table>. */
  className?: string;
  /**
   * Accordion rows: when set, a narrow chevron column is prepended and the
   * returned `<tr>` elements render as native siblings directly under the
   * expanded row — they inherit the grid cell styling (borders, heights), so
   * expanded content reads as extra rows of the SAME table. Remember the
   * chevron column: sub-rows have `columns.length + 1` cells to fill (or an
   * empty leading `<td>` + `colSpan`). A `<tr data-subrow>` opts a row out of
   * the grid cell styling entirely (escape hatch for panel-style content).
   */
  renderSubRows?: (row: T) => ReactNode;
  /** Whether a row can expand (default: every row, when renderSubRows is set). */
  rowCanExpand?: (row: T) => boolean;
  /**
   * SEO-style column layout: pass a unique localStorage key to enable
   * drag-to-reorder (drag a header) and drag-to-resize (drag its right edge),
   * persisted per browser via the shared useColumnLayout. Numeric `width`s
   * become the default px widths; the chevron column stays fixed. Sub-rows are
   * positional — they keep their own cell order under whatever column order
   * the user chooses.
   */
  layoutKey?: string;
}

export function DataTable<T>({
  columns,
  data,
  rowKey,
  defaultSortKey,
  defaultSortDir = 'asc',
  onRowClick,
  emptyMessage = 'No results.',
  wrapperClassName,
  className,
  renderSubRows,
  rowCanExpand,
  layoutKey,
}: DataTableProps<T>) {
  const expandable = renderSubRows != null;
  const [expandedKeys, setExpandedKeys] = useState<Set<string | number>>(new Set());
  const toggleExpanded = (key: string | number) => {
    setExpandedKeys((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
  };

  // ── Optional column layout (reorder + resize, persisted) ──────
  const layoutEnabled = layoutKey != null;
  const columnKeys = useMemo(() => columns.map((c) => c.key), [columns]);
  const defaultWidths = useMemo(() => {
    const w: Record<string, number> = {};
    for (const c of columns) if (typeof c.width === 'number') w[c.key] = c.width;
    return w;
  }, [columns]);
  // Hook is called unconditionally (rules of hooks); without a layoutKey the
  // layout is never read or mutated, so nothing is persisted.
  const layout = useColumnLayout(columnKeys, defaultWidths, layoutKey ?? 'pcm:datatable:layout-unused');

  const orderedColumns = useMemo(() => {
    if (!layoutEnabled) return columns;
    const byKey = new Map(columns.map((c) => [c.key, c]));
    return layout.order
      .map((k) => byKey.get(k))
      .filter((c): c is DataTableColumn<T> => c != null);
  }, [columns, layout.order, layoutEnabled]);

  const [dragKey, setDragKey] = useState<string | null>(null);
  const [dragOverKey, setDragOverKey] = useState<string | null>(null);

  // Drag-to-resize — same pointer wiring as the SEO spreadsheet.
  const startResize = (key: string) => (e: ReactPointerEvent<HTMLSpanElement>) => {
    e.preventDefault();
    const startX = e.clientX;
    const startW = layout.width(key);
    const onMove = (ev: PointerEvent) => layout.setWidth(key, startW + (ev.clientX - startX));
    const onUp = () => {
      window.removeEventListener('pointermove', onMove);
      window.removeEventListener('pointerup', onUp);
      document.body.style.cursor = '';
    };
    document.body.style.cursor = 'col-resize';
    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', onUp);
  };

  const accessors = useMemo(() => {
    const acc: Record<string, (row: T) => string | number | null> = {};
    for (const c of columns) if (c.sortAccessor) acc[c.key] = c.sortAccessor;
    return acc;
  }, [columns]);

  const firstSortable = columns.find((c) => c.sortAccessor)?.key ?? '';

  const { sortKey, sortDir, toggleSort, sortedData } = useSortableTable<T, string>(data, {
    defaultKey: defaultSortKey ?? firstSortable,
    defaultDir: defaultSortDir,
    accessors,
  });

  const widthStyle = (w?: number | string): CSSProperties | undefined =>
    w == null ? undefined : { width: w };

  const colCount = orderedColumns.length + (expandable ? 1 : 0);

  return (
    <div className={cn('rounded-md border border-border shadow-sm overflow-auto max-h-[calc(100vh-300px)] bg-card', wrapperClassName)}>
      <table className={cn(GRID_CLASS, className)}>
        {layoutEnabled && (
          <colgroup>
            {expandable && <col style={{ width: 36 }} />}
            {orderedColumns.map((c) => (
              <col key={c.key} style={{ width: layout.width(c.key) }} />
            ))}
          </colgroup>
        )}
        <thead>
          <tr>
            {expandable && <th style={{ width: 36 }} aria-label="Expand" />}
            {orderedColumns.map((c) =>
              layoutEnabled ? (
                <ColumnHead
                  key={c.key}
                  label={typeof c.header === 'string' ? c.header : c.key}
                  className={cn(c.className, c.headClassName)}
                  sort={
                    c.sortAccessor
                      ? { active: sortKey === c.key, dir: sortDir, onToggle: () => toggleSort(c.key) }
                      : undefined
                  }
                  draggable
                  onDragStart={(e) => {
                    setDragKey(c.key);
                    e.dataTransfer.effectAllowed = 'move';
                    try { e.dataTransfer.setData('text/plain', c.key); } catch { /* IE */ }
                  }}
                  onDragOver={(e) => { e.preventDefault(); if (dragKey && dragKey !== c.key) setDragOverKey(c.key); }}
                  onDragLeave={() => setDragOverKey((cur) => (cur === c.key ? null : cur))}
                  onDrop={(e) => { e.preventDefault(); if (dragKey) layout.moveColumn(dragKey, c.key); setDragKey(null); setDragOverKey(null); }}
                  onDragEnd={() => { setDragKey(null); setDragOverKey(null); }}
                  isDropTarget={dragOverKey === c.key && dragKey !== c.key}
                  onResizeStart={startResize(c.key)}
                />
              ) : c.sortAccessor ? (
                <SortableTableHead
                  key={c.key}
                  columnKey={c.key}
                  label={c.header}
                  currentSortKey={sortKey}
                  currentSortDir={sortDir}
                  onToggle={toggleSort}
                  className={cn(c.className, c.headClassName)}
                  style={widthStyle(c.width)}
                />
              ) : (
                <th key={c.key} className={cn(c.className, c.headClassName)} style={widthStyle(c.width)}>
                  {c.header}
                </th>
              ),
            )}
          </tr>
        </thead>
        <tbody>
          {sortedData.length === 0 ? (
            <tr>
              <td colSpan={colCount} className="text-center text-muted-foreground">
                {emptyMessage}
              </td>
            </tr>
          ) : (
            sortedData.map((row) => {
              const key = rowKey(row);
              const canExpand = expandable && (rowCanExpand ? rowCanExpand(row) : true);
              const isExpanded = canExpand && expandedKeys.has(key);
              return (
                <Fragment key={key}>
                  <tr
                    className={cn('hover:bg-muted/60', onRowClick && 'cursor-pointer')}
                    onClick={onRowClick ? () => onRowClick(row) : undefined}
                  >
                    {expandable && (
                      <td className="text-center">
                        {canExpand && (
                          <button
                            type="button"
                            aria-expanded={isExpanded}
                            aria-label={isExpanded ? 'Collapse row' : 'Expand row'}
                            className="inline-flex h-6 w-6 items-center justify-center rounded text-muted-foreground hover:bg-muted hover:text-foreground"
                            onClick={(e) => {
                              // The row itself may navigate/edit — the chevron only expands.
                              e.stopPropagation();
                              toggleExpanded(key);
                            }}
                          >
                            <ChevronRight
                              className={cn('h-3.5 w-3.5 transition-transform', isExpanded && 'rotate-90')}
                            />
                          </button>
                        )}
                      </td>
                    )}
                    {orderedColumns.map((c) => (
                      <td key={c.key} className={cn(c.className, c.cellClassName)}>
                        {c.cell(row)}
                      </td>
                    ))}
                  </tr>
                  {isExpanded && renderSubRows(row)}
                </Fragment>
              );
            })
          )}
        </tbody>
      </table>
    </div>
  );
}
