/**
 * Pure sort engine — no React, no side effects, fully testable.
 */

import type { SortDefinition } from './types';

/**
 * Returns a new array sorted by the active sort definition. When no sort is
 * active (id is null or not found), returns a shallow copy unchanged.
 */
export function applySort<T>(
  items: ReadonlyArray<T>,
  sorts: ReadonlyArray<SortDefinition<T>>,
  activeSortId: string | null
): T[] {
  if (!activeSortId) return [...items];
  const sort = sorts.find((s) => s.id === activeSortId);
  if (!sort) return [...items];
  return [...items].sort(sort.compare);
}
