/**
 * Filter / Sort Factories
 *
 * One-liners for declaring filters and sorts. Consumers call these instead
 * of writing the full FilterDefinition object, keeping declaration files
 * short and uniform.
 *
 * Example:
 *   const filters = [
 *     searchableSelect<Set>('brand', 'Brand', s => s.brandName),
 *     booleanFilter<Set>('feedback', 'Has feedback', s => s.feedback != null),
 *   ];
 */

import type { FilterDefinition, SortDefinition } from './types';

export function searchableSelect<T>(
  id: string,
  label: string,
  getValue: FilterDefinition<T>['getValue'],
  options?: { searchPlaceholder?: string; maxOptions?: number }
): FilterDefinition<T> {
  return {
    id,
    label,
    control: {
      kind: 'searchableSelect',
      searchPlaceholder: options?.searchPlaceholder,
      maxOptions: options?.maxOptions,
    },
    getValue,
  };
}

export function booleanFilter<T>(
  id: string,
  label: string,
  getValue: (item: T) => boolean,
  options?: { toggleLabel?: string }
): FilterDefinition<T> {
  return {
    id,
    label,
    control: { kind: 'boolean', toggleLabel: options?.toggleLabel },
    getValue: (item: T) => (getValue(item) ? 'true' : 'false'),
  };
}

export function dateRangeFilter<T>(
  id: string,
  label: string,
  getValue: (item: T) => string | null | undefined
): FilterDefinition<T> {
  return { id, label, control: { kind: 'dateRange' }, getValue };
}

export function textFilter<T>(
  id: string,
  label: string,
  getValue: (item: T) => string | null | undefined,
  options?: { placeholder?: string }
): FilterDefinition<T> {
  return {
    id,
    label,
    control: { kind: 'text', placeholder: options?.placeholder },
    getValue,
  };
}

export function sortBy<T>(
  id: string,
  label: string,
  compare: (a: T, b: T) => number
): SortDefinition<T> {
  return { id, label, compare };
}
