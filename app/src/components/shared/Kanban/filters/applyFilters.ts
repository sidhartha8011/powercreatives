/**
 * Pure filter engine — no React, no side effects, fully testable.
 */

import type { FilterDefinition, FilterState, FilterValue } from './types';

/**
 * Returns the subset of items that satisfy every active filter. A filter is
 * "active" only when its FilterValue is non-default (e.g. selected set
 * non-empty, boolean true, text non-blank, date range partially set).
 *
 * Items are kept in input order — composing with applySort is intentional.
 */
export function applyFilters<T>(
  items: ReadonlyArray<T>,
  filters: ReadonlyArray<FilterDefinition<T>>,
  state: FilterState
): T[] {
  if (items.length === 0 || filters.length === 0) return [...items];

  const activeFilters = filters
    .map((f) => ({ def: f, value: state[f.id] }))
    .filter((pair): pair is { def: FilterDefinition<T>; value: FilterValue } =>
      isActive(pair.value)
    );

  if (activeFilters.length === 0) return [...items];

  return items.filter((item) =>
    activeFilters.every(({ def, value }) => matches(item, def, value))
  );
}

function isActive(value: FilterValue | undefined): boolean {
  if (!value) return false;
  switch (value.kind) {
    case 'searchableSelect':
      return value.selected.length > 0;
    case 'boolean':
      return value.value === true;
    case 'dateRange':
      return Boolean(value.from) || Boolean(value.to);
    case 'text':
      return value.query.trim().length > 0;
  }
}

function matches<T>(
  item: T,
  def: FilterDefinition<T>,
  value: FilterValue
): boolean {
  const raw = def.getValue(item);

  switch (value.kind) {
    case 'searchableSelect': {
      if (raw == null) return false;
      const haystack = Array.isArray(raw) ? raw : [raw];
      return value.selected.some((sel) => haystack.includes(sel));
    }
    case 'boolean': {
      // booleanFilter stores 'true' | 'false' strings via getValue
      return raw === 'true';
    }
    case 'dateRange': {
      if (raw == null) return false;
      const candidate = String(Array.isArray(raw) ? raw[0] : raw);
      if (value.from && candidate < value.from) return false;
      if (value.to && candidate > value.to) return false;
      return true;
    }
    case 'text': {
      const query = value.query.trim().toLowerCase();
      if (!query) return true;
      if (raw == null) return false;
      const haystack = Array.isArray(raw) ? raw.join(' ') : String(raw);
      return haystack.toLowerCase().includes(query);
    }
  }
}
