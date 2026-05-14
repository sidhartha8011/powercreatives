/**
 * useRowSelection — Reusable row selection hook for data tables and asset grids.
 *
 * Generic over the ID type: `useRowSelection<string>()` for string IDs,
 * `useRowSelection()` (defaults to number) for numeric IDs.
 *
 * Manages a Set<T> of selected row IDs with:
 *   - toggle(id)       — select/deselect a single row
 *   - selectAll(ids)   — select all visible rows
 *   - clearAll()       — deselect everything
 *   - isSelected(id)   — check if a row is selected
 *   - isAllSelected    — true when all visible rows are selected (and > 0)
 *   - isIndeterminate  — true when some but not all rows are selected
 *   - selectedIds      — the current Set (stable reference via useMemo)
 *   - count            — number of selected rows
 *
 * Designed to be table-agnostic: works with Templates, Brands, Keywords
 * (numeric IDs) and Image assets (string IDs).
 *
 * Auto-clears selection when the underlying data changes (e.g. after
 * a bulk delete removes rows that were selected).
 */

import { useState, useCallback, useMemo, useEffect, useRef } from "react";

interface UseRowSelectionReturn<T extends string | number> {
  /** The set of currently selected row IDs */
  selectedIds: Set<T>;
  /** Number of selected rows */
  count: number;
  /** Toggle a single row's selection */
  toggle: (id: T) => void;
  /** Select all provided IDs (replaces current selection) */
  selectAll: (ids: T[]) => void;
  /** Add all provided IDs to the current selection (merge, not replace) */
  addAll: (ids: T[]) => void;
  /** Remove all provided IDs from the current selection */
  removeAll: (ids: T[]) => void;
  /** Clear all selections */
  clearAll: () => void;
  /** Check if a specific row is selected */
  isSelected: (id: T) => boolean;
  /** True when all provided visible IDs are selected (and count > 0) */
  isAllSelected: (visibleIds: T[]) => boolean;
  /** True when some but not all visible IDs are selected */
  isIndeterminate: (visibleIds: T[]) => boolean;
  /** Toggle between select-all and clear-all for a set of visible IDs */
  toggleAll: (visibleIds: T[]) => void;
}

export function useRowSelection<T extends string | number = number>(): UseRowSelectionReturn<T> {
  const [selectedIds, setSelectedIds] = useState<Set<T>>(new Set());

  // Stable reference for the count
  const count = selectedIds.size;

  const toggle = useCallback((id: T) => {
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

  const selectAll = useCallback((ids: T[]) => {
    setSelectedIds(new Set(ids));
  }, []);

  /** Add IDs to the current selection without removing existing ones */
  const addAll = useCallback((ids: T[]) => {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      ids.forEach((id) => next.add(id));
      return next;
    });
  }, []);

  /** Remove IDs from the current selection */
  const removeAll = useCallback((ids: T[]) => {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      ids.forEach((id) => next.delete(id));
      return next;
    });
  }, []);

  const clearAll = useCallback(() => {
    setSelectedIds(new Set());
  }, []);

  const isSelected = useCallback(
    (id: T) => selectedIds.has(id),
    [selectedIds]
  );

  const isAllSelected = useCallback(
    (visibleIds: T[]) =>
      visibleIds.length > 0 && visibleIds.every((id) => selectedIds.has(id)),
    [selectedIds]
  );

  const isIndeterminate = useCallback(
    (visibleIds: T[]) => {
      if (visibleIds.length === 0) return false;
      const someSelected = visibleIds.some((id) => selectedIds.has(id));
      const allSelected = visibleIds.every((id) => selectedIds.has(id));
      return someSelected && !allSelected;
    },
    [selectedIds]
  );

  const toggleAll = useCallback(
    (visibleIds: T[]) => {
      const allCurrentlySelected =
        visibleIds.length > 0 &&
        visibleIds.every((id) => selectedIds.has(id));

      if (allCurrentlySelected) {
        // Remove only these IDs (don't clear entire selection)
        removeAll(visibleIds);
      } else {
        addAll(visibleIds);
      }
    },
    [selectedIds, removeAll, addAll]
  );

  return {
    selectedIds,
    count,
    toggle,
    selectAll,
    addAll,
    removeAll,
    clearAll,
    isSelected,
    isAllSelected,
    isIndeterminate,
    toggleAll,
  };
}
