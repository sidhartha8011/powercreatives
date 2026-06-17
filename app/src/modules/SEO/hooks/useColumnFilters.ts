/**
 * useColumnFilters — state + predicate for the SEO table's per-column filters.
 *
 * Holds a { columnKey → value } map (empty value = inactive) and applies the
 * matching FilterDef predicate for each active column. Pure state + logic.
 */

import { useCallback, useMemo, useState } from 'react';
import type { SeoRow } from '../types';
import type { FilterDef } from '../seoFilters';

export interface UseColumnFiltersResult {
  values: Record<string, string>;
  setFilter: (key: string, value: string) => void;
  /** Replace the entire filter set at once (used when applying a saved View). */
  setAll: (values: Record<string, string>) => void;
  clearAll: () => void;
  apply: (rows: SeoRow[], defs: Record<string, FilterDef>) => SeoRow[];
  activeCount: number;
}

export function useColumnFilters(): UseColumnFiltersResult {
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
    (rows: SeoRow[], defs: Record<string, FilterDef>): SeoRow[] => {
      const active = Object.entries(values).filter(([, v]) => v);
      if (active.length === 0) return rows;
      return rows.filter((row) => active.every(([key, value]) => defs[key]?.match(row, value) ?? true));
    },
    [values],
  );

  const activeCount = useMemo(() => Object.values(values).filter(Boolean).length, [values]);

  return { values, setFilter, setAll, clearAll, apply, activeCount };
}
