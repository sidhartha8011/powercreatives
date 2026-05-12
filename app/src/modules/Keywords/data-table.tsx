/**
 * KEYWORD EXPLORER — Data Table Component
 *
 * Generic data table wrapper following the shadcn data-table recipe.
 * Uses @tanstack/react-table for headless logic (sorting, filtering, selection)
 * and renders with existing ui/table.tsx primitives.
 *
 * Supports expandable rows via renderSubComponent prop.
 *
 * @see https://ui.shadcn.com/docs/components/data-table
 */

import { Fragment, useState } from 'react';
import {
  type ColumnDef,
  type SortingState,
  type ColumnFiltersState,
  type RowSelectionState,
  type ExpandedState,
  type Row,
  flexRender,
  getCoreRowModel,
  getSortedRowModel,
  getFilteredRowModel,
  getExpandedRowModel,
  useReactTable,
} from '@tanstack/react-table';

import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';

// ============================================
// Generic DataTable Component
// ============================================

interface DataTableProps<TData, TValue> {
  /** Column definitions from columns.tsx */
  columns: ColumnDef<TData, TValue>[];
  /** Data rows to display */
  data: TData[];
  /** Controlled row selection state (lifted to parent for BulkActionBar) */
  rowSelection: RowSelectionState;
  /** Callback when row selection changes */
  onRowSelectionChange: (selection: RowSelectionState) => void;
  /** Global filter value (search query) */
  globalFilter?: string;
  /** Optional sub-component to render when a row is expanded */
  renderSubComponent?: (props: { row: Row<TData> }) => React.ReactNode;
  /** Determine if a row can be expanded (default: false) */
  getRowCanExpand?: (row: Row<TData>) => boolean;
  /** Extra metadata accessible from column cells via table.options.meta */
  tableMeta?: Record<string, any>;
  /** Optional render function for a custom toolbar above the table */
  renderToolbar?: (table: ReturnType<typeof useReactTable<TData>>) => React.ReactNode;
}

export function DataTable<TData, TValue>({
  columns,
  data,
  rowSelection,
  onRowSelectionChange,
  globalFilter = '',
  renderSubComponent,
  getRowCanExpand,
  tableMeta,
  renderToolbar,
}: DataTableProps<TData, TValue>) {
  // ── Local sorting state ──
  const [sorting, setSorting] = useState<SortingState>([]);
  const [columnFilters, setColumnFilters] = useState<ColumnFiltersState>([]);
  const [expanded, setExpanded] = useState<ExpandedState>({});

  // ── Table instance ──
  const table = useReactTable({
    data,
    columns,
    state: {
      sorting,
      columnFilters,
      rowSelection,
      globalFilter,
      expanded,
      // columnVisibility state is natively managed unless overridden here
    },
    onSortingChange: setSorting,
    onColumnFiltersChange: setColumnFilters,
    onExpandedChange: setExpanded,
    onRowSelectionChange: (updater) => {
      const next = typeof updater === 'function' ? updater(rowSelection) : updater;
      onRowSelectionChange(next);
    },
    getCoreRowModel: getCoreRowModel(),
    getSortedRowModel: getSortedRowModel(),
    getFilteredRowModel: getFilteredRowModel(),
    getExpandedRowModel: getExpandedRowModel(),
    getRowCanExpand: getRowCanExpand ?? (() => false),
    enableRowSelection: true,
    meta: tableMeta,
  });

  return (
    <div className="flex flex-col gap-4 h-full min-h-0 w-full relative">
      {/* Top Toolbar floats purely on the page background, separated from the table */}
      {renderToolbar && (
        <div className="flex-shrink-0 mt-1 mr-1">
          {renderToolbar(table)}
        </div>
      )}

      {/* The solid Table container, completely opaque (non-transparent) */}
      <div className="border border-border/70 rounded-lg bg-card shadow-sm flex-1 flex flex-col overflow-hidden relative">
        <Table wrapperClassName="flex-1 overflow-auto">
          <TableHeader className="sticky top-0 z-10 bg-muted/50 backdrop-blur-md after:absolute after:inset-x-0 after:bottom-0 after:border-b after:border-border">
            {table.getHeaderGroups().map((headerGroup) => (
              <TableRow key={headerGroup.id} className="border-none hover:bg-transparent">
                {headerGroup.headers.map((header) => (
                  <TableHead
                    key={header.id}
                    style={{ width: header.getSize() !== 150 ? header.getSize() : undefined }}
                    className="font-semibold text-foreground/80 h-11"
                  >
                    {header.isPlaceholder
                      ? null
                      : flexRender(header.column.columnDef.header, header.getContext())}
                  </TableHead>
                ))}
              </TableRow>
            ))}
          </TableHeader>
          <TableBody>
            {table.getRowModel().rows.length > 0 ? (
              table.getRowModel().rows.map((row) => (
                <Fragment key={row.id}>
                  <TableRow
                    data-state={row.getIsSelected() ? 'selected' : undefined}
                    className="group border-b border-border/50"
                  >
                    {row.getVisibleCells().map((cell) => (
                      <TableCell key={cell.id} className="transition-colors group-hover:bg-accent/40">
                        {flexRender(cell.column.columnDef.cell, cell.getContext())}
                      </TableCell>
                    ))}
                  </TableRow>
                  {/* Expanded sub-rows — rendered as real table rows */}
                  {row.getIsExpanded() && renderSubComponent && renderSubComponent({ row })}
                </Fragment>
              ))
            ) : (
              <TableRow>
                <TableCell colSpan={columns.length} className="h-24 text-center text-muted-foreground">
                  No results.
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
      </div>
    </div>
  );
}

