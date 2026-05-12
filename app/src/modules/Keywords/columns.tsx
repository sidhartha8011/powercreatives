/**
 * KEYWORD EXPLORER — TanStack Table Column Definitions
 *
 * Declarative column config following the shadcn data-table recipe.
 * All rendering uses existing ui/* components (Checkbox, Badge).
 *
 * @see https://ui.shadcn.com/docs/components/data-table
 */

import React from 'react';
import type { ColumnDef, Row, CellContext, FilterFn } from '@tanstack/react-table';
import { Checkbox } from '@/components/ui/checkbox';
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import { TableRow, TableCell } from '@/components/ui/table';
import { ExternalLink, ChevronDown, ChevronRight } from 'lucide-react';
import type { KeywordResult, SerpResult } from './types';
import { ColumnHeaderMenu } from './ColumnHeaderMenu';

/**
 * Check if a row is currently being enriched.
 * Reads from TanStack Table meta (set by index.tsx via DataTable).
 */
function isRowEnriching(ctx: CellContext<KeywordResult, unknown>): boolean {
  const meta = ctx.table.options.meta as { isEnriching?: boolean; enrichingKeywords?: Set<string> } | undefined;
  if (!meta?.isEnriching || !meta?.enrichingKeywords) return false;
  return meta.enrichingKeywords.has(ctx.row.original.keyword);
}

/**
 * Format a number with K/M suffix for compact display.
 * e.g. 12400 → "12.4K", 1500000 → "1.5M"
 */
function formatVolume(value?: number): string {
  if (value === undefined || value === null) return '—';
  if (value >= 1_000_000) return `${(value / 1_000_000).toFixed(1)}M`;
  if (value >= 1_000) return `${(value / 1_000).toFixed(1)}K`;
  return String(value);
}

/**
 * Get difficulty badge variant based on Ahrefs KD score.
 * Lower = easier (green), higher = harder (red).
 */
function getDifficultyVariant(kd?: number): 'default' | 'secondary' | 'destructive' | 'outline' {
  if (kd === undefined || kd === null) return 'outline';
  if (kd <= 30) return 'secondary';   // Easy — green-ish
  if (kd <= 60) return 'default';     // Medium — blue
  return 'destructive';                // Hard — red
}

/**
 * Get DR badge pastel style — uses CSS custom properties from design system.
 * Returns inline styles to apply pastel background/foreground colors
 * without modifying the shared Badge component variants.
 *
 * Scale: green (≤30, easy) → amber (31-60, medium) → pink (61+, hard)
 */
function getDRStyle(dr?: number): React.CSSProperties {
  if (dr === undefined || dr === null) return {};
  if (dr <= 30) return { backgroundColor: 'var(--dr-easy-bg)', color: 'var(--dr-easy-fg)', borderColor: 'transparent' };
  if (dr <= 60) return { backgroundColor: 'var(--dr-medium-bg)', color: 'var(--dr-medium-fg)', borderColor: 'transparent' };
  return { backgroundColor: 'var(--dr-hard-bg)', color: 'var(--dr-hard-fg)', borderColor: 'transparent' };
}

/** Custom filter for min/max range on numeric columns */
export const numberRangeFilter: FilterFn<KeywordResult> = (row, columnId, filterValue: [number?, number?]) => {
  const value = row.getValue<number>(columnId);
  if (value === undefined || value === null) return true;
  const [min, max] = filterValue;
  if (min !== undefined && value < min) return false;
  if (max !== undefined && value > max) return false;
  return true;
};

/** Column definitions for the keyword results data table */
export const columns: ColumnDef<KeywordResult>[] = [
  // ── Select checkbox column ──
  {
    id: 'select',
    header: ({ table }) => (
      <Checkbox
        checked={
          table.getIsAllPageRowsSelected() ||
          (table.getIsSomePageRowsSelected() && 'indeterminate')
        }
        onCheckedChange={(value) => table.toggleAllPageRowsSelected(!!value)}
        aria-label="Select all"
      />
    ),
    cell: ({ row }) => (
      <Checkbox
        checked={row.getIsSelected()}
        onCheckedChange={(value) => row.toggleSelected(!!value)}
        aria-label="Select row"
      />
    ),
    enableSorting: false,
    enableHiding: false,
    size: 40,
  },

  {
    accessorKey: 'keyword',
    header: ({ column }) => <ColumnHeaderMenu column={column} label="Keyword" />,
    meta: { filterVariant: 'text' },
    enableSorting: true,
    cell: ({ row }) => (
      <span className="font-medium">{row.getValue('keyword')}</span>
    ),
  },

  {
    id: 'volume',
    accessorFn: (row) => row['volume'] ?? undefined,
    sortUndefined: 'last',
    header: ({ column }) => <ColumnHeaderMenu column={column} label="Volume" />,
    meta: { filterVariant: 'range' },
    filterFn: numberRangeFilter,
    cell: (ctx) => {
      if (isRowEnriching(ctx)) return <Skeleton className="h-4 w-12" />;
      return (
        <span className="text-muted-foreground tabular-nums">
          {formatVolume(ctx.row.getValue('volume'))}
        </span>
      );
    },
  },

  {
    id: 'difficulty',
    accessorFn: (row) => row['difficulty'] ?? undefined,
    sortUndefined: 'last',
    header: ({ column }) => <ColumnHeaderMenu column={column} label="KD" />,
    meta: { filterVariant: 'range' },
    filterFn: numberRangeFilter,
    cell: (ctx) => {
      if (isRowEnriching(ctx)) return <Skeleton className="h-4 w-8" />;
      const kd = ctx.row.getValue('difficulty') as number | undefined;
      if (kd === undefined || kd === null) {
        return <span className="text-muted-foreground">—</span>;
      }
      return (
        <Badge variant={getDifficultyVariant(kd)}>
          {kd}
        </Badge>
      );
    },
  },

  {
    id: 'cpc',
    accessorFn: (row) => row['cpc'] ?? undefined,
    sortUndefined: 'last',
    header: ({ column }) => <ColumnHeaderMenu column={column} label="CPC" />,
    meta: { filterVariant: 'range' },
    filterFn: numberRangeFilter,
    cell: (ctx) => {
      if (isRowEnriching(ctx)) return <Skeleton className="h-4 w-10" />;
      const cpc = ctx.row.getValue('cpc') as number | undefined;
      if (cpc === undefined || cpc === null) {
        return <span className="text-muted-foreground">—</span>;
      }
      return (
        <span className="text-muted-foreground tabular-nums">
          ${cpc.toFixed(2)}
        </span>
      );
    },
  },

  {
    id: 'serpAvgDR',
    accessorFn: (row) => (row['serpAvgDR'] === 0 && (!row.serpResults || row.serpResults.length === 0)) ? undefined : (row['serpAvgDR'] ?? undefined),
    sortUndefined: 'last',
    header: ({ column }) => <ColumnHeaderMenu column={column} label="Avg DR" />,
    meta: { filterVariant: 'range' },
    filterFn: numberRangeFilter,
    cell: (ctx) => {
      if (isRowEnriching(ctx)) return <Skeleton className="h-4 w-10" />;
      const avgDR = ctx.row.getValue('serpAvgDR') as number | undefined;
      const canExpand = ctx.row.getCanExpand();

      // No SERP data at all — show dash
      if (avgDR === undefined || avgDR === null || (avgDR === 0 && !canExpand)) {
        return <span className="text-muted-foreground">—</span>;
      }

      // Has SERP data — show badge, clickable only if expandable
      return (
        <button
          onClick={() => canExpand && ctx.row.toggleExpanded()}
          className={`flex items-center gap-1 transition-opacity ${canExpand ? 'cursor-pointer hover:opacity-80' : 'cursor-default'}`}
          title={canExpand ? 'Click to see top 5 SERP' : undefined}
        >
          <Badge variant="outline" style={getDRStyle(avgDR)}>
            {avgDR}
          </Badge>
          {canExpand && (
            ctx.row.getIsExpanded()
              ? <ChevronDown className="w-3 h-3 text-muted-foreground" />
              : <ChevronRight className="w-3 h-3 text-muted-foreground" />
          )}
        </button>
      );
    },
  },

  {
    id: 'serpLowDR',
    accessorFn: (row) => (row['serpLowDR'] === 0 && (!row.serpResults || row.serpResults.length === 0)) ? undefined : (row['serpLowDR'] ?? undefined),
    sortUndefined: 'last',
    header: ({ column }) => <ColumnHeaderMenu column={column} label="Low DR" />,
    meta: { filterVariant: 'range' },
    filterFn: numberRangeFilter,
    cell: (ctx) => {
      if (isRowEnriching(ctx)) return <Skeleton className="h-4 w-10" />;
      const lowDR = ctx.row.getValue('serpLowDR') as number | undefined;
      const canExpand = ctx.row.getCanExpand();

      // No SERP data — show dash
      if (lowDR === undefined || lowDR === null || (lowDR === 0 && !canExpand)) {
        return <span className="text-muted-foreground">—</span>;
      }
      return (
        <Badge variant="outline" style={getDRStyle(lowDR)}>
          {lowDR}
        </Badge>
      );
    },
  },

  {
    id: 'serpAvgUR',
    accessorFn: (row) => (row['serpAvgUR'] === 0 && (!row.serpResults || row.serpResults.length === 0)) ? undefined : (row['serpAvgUR'] ?? undefined),
    sortUndefined: 'last',
    header: ({ column }) => <ColumnHeaderMenu column={column} label="Avg UR" />,
    meta: { filterVariant: 'range' },
    filterFn: numberRangeFilter,
    cell: (ctx) => {
      if (isRowEnriching(ctx)) return <Skeleton className="h-4 w-10" />;
      const avgUR = ctx.row.getValue('serpAvgUR') as number | undefined;
      const canExpand = ctx.row.getCanExpand();

      if (avgUR === undefined || avgUR === null || (avgUR === 0 && !canExpand)) {
        return <span className="text-muted-foreground">—</span>;
      }
      return (
        <Badge variant="outline" style={getDRStyle(avgUR)}>
          {avgUR}
        </Badge>
      );
    },
  },

  {
    id: 'serpLowUR',
    accessorFn: (row) => (row['serpLowUR'] === 0 && (!row.serpResults || row.serpResults.length === 0)) ? undefined : (row['serpLowUR'] ?? undefined),
    sortUndefined: 'last',
    header: ({ column }) => <ColumnHeaderMenu column={column} label="Low UR" />,
    meta: { filterVariant: 'range' },
    filterFn: numberRangeFilter,
    cell: (ctx) => {
      if (isRowEnriching(ctx)) return <Skeleton className="h-4 w-10" />;
      const lowUR = ctx.row.getValue('serpLowUR') as number | undefined;
      const canExpand = ctx.row.getCanExpand();

      if (lowUR === undefined || lowUR === null || (lowUR === 0 && !canExpand)) {
        return <span className="text-muted-foreground">—</span>;
      }
      return (
        <Badge variant="outline" style={getDRStyle(lowUR)}>
          {lowUR}
        </Badge>
      );
    },
  },

  {
    accessorKey: 'category',
    header: ({ column }) => <ColumnHeaderMenu column={column} label="Category" />,
    meta: { filterVariant: 'text' },
    cell: ({ row }) => {
      const cat = row.getValue('category') as string | undefined;
      if (!cat) return <span className="text-muted-foreground">—</span>;
      return (
        <Badge variant="outline" className="capitalize text-xs">
          {cat}
        </Badge>
      );
    },
  },
];

/**
 * Sub-rows rendered when a keyword row is expanded (Avg DR clicked).
 *
 * Uses the same TableRow/TableCell primitives as the main table
 * to ensure identical padding, borders, hover effects, and typography.
 * Returns an array of <TableRow> elements — rendered inline in <TableBody>.
 */
export function SerpSubRow({ row }: { row: Row<KeywordResult> }) {
  const results: SerpResult[] = row.original.serpResults ?? [];
  const colCount = row.getVisibleCells().length;

  if (results.length === 0) {
    return (
      <TableRow>
        <TableCell colSpan={colCount} className="text-center text-muted-foreground">
          No SERP data available
        </TableCell>
      </TableRow>
    );
  }



  return (
    <>
      {results.map((serp) => (
        <TableRow key={`${row.id}-serp-${serp.position}`} className="bg-muted/30">
          {row.getVisibleCells().map((cell) => {
            const id = cell.column.id;
            
            if (id === 'keyword') {
              return (
                <TableCell key={id}>
                  <div className="flex items-center gap-2 pl-4">
                    <span className="text-xs font-mono text-muted-foreground shrink-0">#{serp.position}</span>
                    <a href={serp.url || '#'} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:underline truncate" style={{ color: 'inherit' }} title={serp.url}>
                      {serp.url || '—'} <ExternalLink className="w-3 h-3 shrink-0" />
                    </a>
                  </div>
                </TableCell>
              );
            }
            if (id === 'volume') {
              return <TableCell key={id}><span className="text-muted-foreground tabular-nums">{formatVolume(serp.traffic)}</span></TableCell>;
            }
            if (id === 'serpAvgDR') {
              return <TableCell key={id}><Badge variant="outline" style={getDRStyle(serp.domain_rating)}>{serp.domain_rating}</Badge></TableCell>;
            }
            if (id === 'serpAvgUR') {
              return (
                <TableCell key={id}>
                  {serp.url_rating !== undefined && serp.url_rating > 0 ? <Badge variant="outline" style={getDRStyle(serp.url_rating)}>{serp.url_rating}</Badge> : <span className="text-muted-foreground tabular-nums opacity-50 px-2">—</span>}
                </TableCell>
              );
            }
            if (id === 'category') {
              return <TableCell key={id}><span className="truncate text-xs text-muted-foreground" title={serp.title}>{serp.title || '(No title)'}</span></TableCell>;
            }
            
            // Empty cells for everything else (select, KD, CPC, Low DR, Low UR, etc) to maintain grid alignment
            return <TableCell key={id} />;
          })}
        </TableRow>
      ))}
    </>
  );
}

