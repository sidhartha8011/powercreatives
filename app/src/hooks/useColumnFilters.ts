/**
 * useColumnFilters — generic state + predicate for per-column table filters.
 *
 * Holds a { columnKey → value } map (empty value = inactive) and applies the
 * matching FilterDef predicate for each active column. Pure state + logic, generic
 * over the row type so ANY module can build a filterable spreadsheet table (pair
 * it with the shared `ColumnHead` + `useColumnLayout`). Each module supplies its
 * own `{ columnKey → FilterDef<Row> }` map (e.g. SEO's `buildFilterDefs`).
 */

import { useCallback, useMemo, useState } from 'react';

export type FilterKind = 'text' | 'choice';

export interface FilterOption {
  value: string;
  label: string;
}

export interface FilterDef<T = unknown> {
  key: string;
  kind: FilterKind;
  /** Choices for a 'choice' filter (omitted for 'text'). */
  options?: FilterOption[];
  /** True when the row passes this filter's active value. */
  match: (row: T, value: string) => boolean;
}

export interface UseColumnFiltersResult<T> {
  values: Record<string, string>;
  setFilter: (key: string, value: string) => void;
  /** Replace the entire filter set at once (e.g. when applying a saved View). */
  setAll: (values: Record<string, string>) => void;
  clearAll: () => void;
  apply: (rows: T[], defs: Record<string, FilterDef<T>>) => T[];
  activeCount: number;
}

export function useColumnFilters<T = unknown>(): UseColumnFiltersResult<T> {
  const [values, setValues] = useState<Record<string, string>>({});

  const setFilter = useCallback((key: string, value: string) => {
    setValues((prev) => {
      const next = { ...prev };
      if (value) next[key] = value;
      else delete next[key];
      return next;
    });
  }, []);

  const setAll = useCallback((next: Record<string, string>) => {
    // Keep only truthy values so activeCount / apply stay accurate.
    const clean: Record<string, string> = {};
    for (const [k, v] of Object.entries(next ?? {})) if (v) clean[k] = v;
    setValues(clean);
  }, []);

  const clearAll = useCallback(() => setValues({}), []);

  const apply = useCallback(
    (rows: T[], defs: Record<string, FilterDef<T>>): T[] => {
      const active = Object.entries(values).filter(([, v]) => v);
      if (active.length === 0) return rows;
      return rows.filter((row) => active.every(([key, value]) => defs[key]?.match(row, value) ?? true));
    },
    [values],
  );

  const activeCount = useMemo(() => Object.values(values).filter(Boolean).length, [values]);

  return { values, setFilter, setAll, clearAll, apply, activeCount };
}
