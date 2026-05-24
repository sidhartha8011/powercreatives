/**
 * useListState — combined filter + sort + URL-sync hook.
 *
 * Owns the FilterState and active sort id, applies them to the source items,
 * and optionally syncs to URLSearchParams for shareable / refresh-safe state.
 *
 * Design rules:
 * - Pure: items in → derived items out. No data fetching.
 * - Memoized: filtered+sorted list recomputes only when items / state / sort
 *   change.
 * - URL sync is opt-in via `persistKey`. When set, all filter ids are
 *   namespaced as `<persistKey>.<filterId>` to avoid collisions across
 *   multiple boards on the same page.
 * - SSR-safe: window access guarded.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import { applyFilters } from './applyFilters';
import { applySort } from './applySort';
import type {
  FilterDefinition,
  FilterState,
  FilterValue,
  SortDefinition,
} from './types';

export interface UseListStateOptions {
  /** When set, the hook reads/writes URL params namespaced by this key. */
  persistKey?: string;
  /** Initial sort id (must match a SortDefinition.id). */
  defaultSortId?: string | null;
}

export interface UseListStateResult<T> {
  filteredItems: T[];
  filterState: FilterState;
  setFilterValue: (filterId: string, value: FilterValue | undefined) => void;
  clearFilter: (filterId: string) => void;
  clearAll: () => void;
  activeFilterCount: number;
  sortId: string | null;
  setSortId: (id: string | null) => void;
}

const hasWindow = typeof window !== 'undefined';

export function useListState<T extends { id: string | number }>(
  items: ReadonlyArray<T>,
  filters: ReadonlyArray<FilterDefinition<T>>,
  sorts: ReadonlyArray<SortDefinition<T>>,
  options: UseListStateOptions = {}
): UseListStateResult<T> {
  const { persistKey, defaultSortId = null } = options;

  // ── Initial state from URL (if persist) ──
  const [filterState, setFilterState] = useState<FilterState>(() =>
    persistKey && hasWindow ? readFiltersFromUrl(persistKey, filters) : {}
  );
  const [sortId, setSortIdInternal] = useState<string | null>(() =>
    persistKey && hasWindow
      ? readSortFromUrl(persistKey) ?? defaultSortId
      : defaultSortId
  );

  // ── URL sync (write) ──
  // replaceState so filter changes don't pollute browser history.
  const isFirstRender = useRef(true);
  useEffect(() => {
    if (!persistKey || !hasWindow) return;
    if (isFirstRender.current) {
      isFirstRender.current = false;
      return;
    }
    writeStateToUrl(persistKey, filters, filterState, sortId);
  }, [persistKey, filters, filterState, sortId]);

  // ── Mutators ──
  const setFilterValue = useCallback(
    (filterId: string, value: FilterValue | undefined) => {
      setFilterState((prev) => {
        const next = { ...prev };
        if (value === undefined) delete next[filterId];
        else next[filterId] = value;
        return next;
      });
    },
    []
  );

  const clearFilter = useCallback((filterId: string) => {
    setFilterState((prev) => {
      if (!(filterId in prev)) return prev;
      const next = { ...prev };
      delete next[filterId];
      return next;
    });
  }, []);

  const clearAll = useCallback(() => {
    setFilterState({});
  }, []);

  const setSortId = useCallback((id: string | null) => {
    setSortIdInternal(id);
  }, []);

  // ── Derived: filtered + sorted ──
  const filteredItems = useMemo(() => {
    const filtered = applyFilters(items, filters, filterState);
    return applySort(filtered, sorts, sortId);
  }, [items, filters, sorts, filterState, sortId]);

  const activeFilterCount = useMemo(
    () => Object.values(filterState).filter(isActiveValue).length,
    [filterState]
  );

  return {
    filteredItems,
    filterState,
    setFilterValue,
    clearFilter,
    clearAll,
    activeFilterCount,
    sortId,
    setSortId,
  };
}

// ─── URL serialization ──────────────────────────────────────────

function ns(persistKey: string, id: string): string {
  return `${persistKey}.${id}`;
}

function isActiveValue(value: FilterValue | undefined): boolean {
  if (!value) return false;
  switch (value.kind) {
    case 'searchableSelect': return value.selected.length > 0;
    case 'boolean':          return value.value === true;
    case 'dateRange':        return Boolean(value.from) || Boolean(value.to);
    case 'text':             return value.query.trim().length > 0;
  }
}

function readFiltersFromUrl<T>(
  persistKey: string,
  filters: ReadonlyArray<FilterDefinition<T>>
): FilterState {
  const params = new URLSearchParams(window.location.search);
  const state: FilterState = {};

  for (const def of filters) {
    const key = ns(persistKey, def.id);
    const raw = params.get(key);
    if (raw == null) continue;

    switch (def.control.kind) {
      case 'searchableSelect': {
        const selected = raw.split(',').filter(Boolean);
        if (selected.length > 0) {
          state[def.id] = { kind: 'searchableSelect', selected };
        }
        break;
      }
      case 'boolean': {
        if (raw === 'true') state[def.id] = { kind: 'boolean', value: true };
        break;
      }
      case 'dateRange': {
        const [from, to] = raw.split('|');
        state[def.id] = { kind: 'dateRange', from: from || undefined, to: to || undefined };
        break;
      }
      case 'text': {
        if (raw.trim().length > 0) {
          state[def.id] = { kind: 'text', query: raw };
        }
        break;
      }
    }
  }
  return state;
}

function readSortFromUrl(persistKey: string): string | null {
  const params = new URLSearchParams(window.location.search);
  return params.get(`${persistKey}.sort`);
}

function writeStateToUrl<T>(
  persistKey: string,
  filters: ReadonlyArray<FilterDefinition<T>>,
  state: FilterState,
  sortId: string | null
): void {
  const params = new URLSearchParams(window.location.search);

  // Clear our keys first
  for (const def of filters) params.delete(ns(persistKey, def.id));
  params.delete(`${persistKey}.sort`);

  // Write active filters
  for (const def of filters) {
    const value = state[def.id];
    if (!isActiveValue(value)) continue;
    const key = ns(persistKey, def.id);
    switch (value!.kind) {
      case 'searchableSelect':
        params.set(key, value!.selected.join(','));
        break;
      case 'boolean':
        params.set(key, 'true');
        break;
      case 'dateRange':
        params.set(key, `${value!.from ?? ''}|${value!.to ?? ''}`);
        break;
      case 'text':
        params.set(key, value!.query);
        break;
    }
  }

  if (sortId) params.set(`${persistKey}.sort`, sortId);

  const next = params.toString();
  const url = `${window.location.pathname}${next ? '?' + next : ''}${window.location.hash}`;
  window.history.replaceState(null, '', url);
}
