/**
 * useSortableTable — Generic, reusable hook for client-side table sorting.
 *
 * Usage:
 *   const { sortKey, sortDir, toggleSort, sortedData } = useSortableTable(data, {
 *     defaultKey: "createdAt",
 *     defaultDir: "desc",
 *     accessors: {
 *       name: (row) => row.name.toLowerCase(),
 *       createdAt: (row) => new Date(row.createdAt).getTime(),
 *     },
 *   });
 *
 * Clicking a header calls toggleSort("columnKey"):
 *   - First click: asc
 *   - Second click: desc
 *   - Third click: clears sort (returns to default)
 */

import { useMemo, useState } from "react";

export type SortDirection = "asc" | "desc" | null;

interface SortableTableOptions<T, K extends string> {
  /** Column to sort by initially */
  defaultKey: K;
  /** Initial sort direction */
  defaultDir: SortDirection;
  /**
   * Accessor functions that extract a comparable value from each row.
   * If a column is not listed here, it falls back to string comparison
   * on `row[key]`.
   */
  accessors?: Partial<Record<K, (row: T) => string | number | null>>;
}

interface SortableTableResult<T, K extends string> {
  /** Currently active sort column (null = no sort) */
  sortKey: K | null;
  /** Current sort direction */
  sortDir: SortDirection;
  /** Call this from a header click to cycle through asc → desc → none */
  toggleSort: (key: K) => void;
  /** The sorted copy of the input data */
  sortedData: T[];
}

export function useSortableTable<T, K extends string>(
  data: T[],
  options: SortableTableOptions<T, K>
): SortableTableResult<T, K> {
  const { defaultKey, defaultDir, accessors = {} } = options;

  const [sortKey, setSortKey] = useState<K | null>(defaultKey);
  const [sortDir, setSortDir] = useState<SortDirection>(defaultDir);

  const toggleSort = (key: K) => {
    if (sortKey !== key) {
      // New column: start ascending
      setSortKey(key);
      setSortDir("asc");
    } else if (sortDir === "asc") {
      setSortDir("desc");
    } else if (sortDir === "desc") {
      // Third click: reset to default
      setSortKey(defaultKey);
      setSortDir(defaultDir);
    }
  };

  const sortedData = useMemo(() => {
    if (!sortKey || !sortDir) return [...data];

    const accessor = (accessors as Record<string, ((row: T) => string | number | null) | undefined>)[sortKey];

    return [...data].sort((a, b) => {
      const aVal = accessor ? accessor(a) : (a as Record<string, unknown>)[sortKey];
      const bVal = accessor ? accessor(b) : (b as Record<string, unknown>)[sortKey];

      // Nulls always sort last
      if (aVal == null && bVal == null) return 0;
      if (aVal == null) return 1;
      if (bVal == null) return -1;

      let cmp: number;
      if (typeof aVal === "number" && typeof bVal === "number") {
        cmp = aVal - bVal;
      } else {
        cmp = String(aVal).localeCompare(String(bVal), undefined, {
          sensitivity: "base",
          numeric: true,
        });
      }

      return sortDir === "asc" ? cmp : -cmp;
    });
  }, [data, sortKey, sortDir, accessors]);

  return { sortKey, sortDir, toggleSort, sortedData };
}
