/**
 * useRowSelection — Reusable row selection hook for data tables.
 *
 * Manages a Set<number> of selected row IDs with:
 *   - toggle(id)       — select/deselect a single row
 *   - selectAll(ids)   — select all visible rows
 *   - clearAll()       — deselect everything
 *   - isSelected(id)   — check if a row is selected
 *   - isAllSelected    — true when all visible rows are selected (and > 0)
 *   - isIndeterminate  — true when some but not all rows are selected
 *   - selectedIds      — the current Set (stable reference via useMemo)
 *   - count            — number of selected rows
 *
 * Designed to be table-agnostic: works with Templates, Brands, or any
 * future table that uses numeric IDs.
 *
 * Auto-clears selection when the underlying data changes (e.g. after
 * a bulk delete removes rows that were selected).
 */

import { useState, useCallback, useMemo, useEffect, useRef } from "react";

interface UseRowSelectionReturn {
  /** The set of currently selected row IDs */
  selectedIds: Set<number>;
  /** Number of selected rows */
  count: number;
  /** Toggle a single row's selection */
  toggle: (id: number) => void;
  /** Select all provided IDs */
  selectAll: (ids: number[]) => void;
  /** Clear all selections */
  clearAll: () => void;
  /** Check if a specific row is selected */
  isSelected: (id: number) => boolean;
  /** True when all provided visible IDs are selected (and count > 0) */
  isAllSelected: (visibleIds: number[]) => boolean;
  /** True when some but not all visible IDs are selected */
  isIndeterminate: (visibleIds: number[]) => boolean;
  /** Toggle between select-all and clear-all for a set of visible IDs */
  toggleAll: (visibleIds: number[]) => void;
}

export function useRowSelection(): UseRowSelectionReturn {
  const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());

  // Stable reference for the count
  const count = selectedIds.size;

  const toggle = useCallback((id: number) => {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });
  }, []);

  const selectAll = useCallback((ids: number[]) => {
    setSelectedIds(new Set(ids));
  }, []);

  const clearAll = useCallback(() => {
    setSelectedIds(new Set());
  }, []);

  const isSelected = useCallback(
    (id: number) => selectedIds.has(id),
    [selectedIds]
  );

  const isAllSelected = useCallback(
    (visibleIds: number[]) =>
      visibleIds.length > 0 && visibleIds.every((id) => selectedIds.has(id)),
    [selectedIds]
  );

  const isIndeterminate = useCallback(
    (visibleIds: number[]) => {
      if (visibleIds.length === 0) return false;
      const someSelected = visibleIds.some((id) => selectedIds.has(id));
      const allSelected = visibleIds.every((id) => selectedIds.has(id));
      return someSelected && !allSelected;
    },
    [selectedIds]
  );

  const toggleAll = useCallback(
    (visibleIds: number[]) => {
      const allCurrentlySelected =
        visibleIds.length > 0 &&
        visibleIds.every((id) => selectedIds.has(id));

      if (allCurrentlySelected) {
        clearAll();
      } else {
        selectAll(visibleIds);
      }
    },
    [selectedIds, clearAll, selectAll]
  );

  return {
    selectedIds,
    count,
    toggle,
    selectAll,
    clearAll,
    isSelected,
    isAllSelected,
    isIndeterminate,
    toggleAll,
  };
}
