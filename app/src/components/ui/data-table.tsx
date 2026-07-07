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
 * Accordion rows: pass `renderExpanded` (and optionally `rowCanExpand`) to get a
 * chevron column + a full-width sub-row per expanded row.
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

import { Fragment, useMemo, useState, type ReactNode, type CSSProperties } from 'react';
import { ChevronRight } from 'lucide-react';

import { cn } from '@/lib/utils';
import { SortableTableHead } from '@/components/ui/sortable-table-head';
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
   * Accordion rows: when set, a narrow chevron column is prepended and this
   * renders in a full-width sub-row under the expanded row. Sub-row content is
   * exempt from the grid cell styling (borders/heights), so nested tables
   * style themselves freely.
   */
  renderExpanded?: (row: T) => ReactNode;
  /** Whether a row can expand (default: every row, when renderExpanded is set). */
  rowCanExpand?: (row: T) => boolean;
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
  renderExpanded,
  rowCanExpand,
}: DataTableProps<T>) {
  const expandable = renderExpanded != null;
  const [expandedKeys, setExpandedKeys] = useState<Set<string | number>>(new Set());
  const toggleExpanded = (key: string | number) => {
    setExpandedKeys((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
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

  const colCount = columns.length + (expandable ? 1 : 0);

  return (
    <div className={cn('rounded-md border border-border shadow-sm overflow-auto max-h-[calc(100vh-300px)] bg-card', wrapperClassName)}>
      <table className={cn(GRID_CLASS, className)}>
        <thead>
          <tr>
            {expandable && <th style={{ width: 36 }} aria-label="Expand" />}
            {columns.map((c) =>
              c.sortAccessor ? (
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
                    {columns.map((c) => (
                      <td key={c.key} className={cn(c.className, c.cellClassName)}>
                        {c.cell(row)}
                      </td>
                    ))}
                  </tr>
                  {isExpanded && (
                    <tr data-subrow>
                      <td colSpan={colCount} className="border-b border-border/60 bg-muted/20">
                        {renderExpanded(row)}
                      </td>
                    </tr>
                  )}
                </Fragment>
              );
            })
          )}
        </tbody>
      </table>
    </div>
  );
}
