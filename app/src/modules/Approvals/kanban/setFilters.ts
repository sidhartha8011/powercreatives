/**
 * Approval-set filters.
 *
 * Three searchable dropdowns — Brand, Delivery, Project. One declaration each.
 * Add a new filter? Append one line. Remove? Delete one line.
 *
 * Filtering is by ID, never by display name: the set row carries `brandId`,
 * `deliveryId` and `projectId` (the last two derived live from the project chain
 * server-side), while the human-readable names come from the registries the board
 * already loads. Names are display data and can be renamed at any time — ids can't,
 * which is why the filter binds to them.
 *
 * The free-text search input and the "Approval set" dropdown were removed on
 * 2026-08-04 (owner order): a search box inside each dropdown replaced both. The
 * notification jump that used to drive the `set` filter now opens the set's
 * preview card directly — see SetsBoard.
 */

import { searchableSelect, type FilterDefinition } from '@/components/shared/Kanban';

import type { ApprovalSet } from '../types';

/** Numeric id → filter-state string, or null when the set has no such link. */
function idValue(id: number | null | undefined): string | null {
  return id != null ? String(id) : null;
}

export const setFilters: ReadonlyArray<FilterDefinition<ApprovalSet>> = [
  searchableSelect<ApprovalSet>('brand', 'Brand', (s) => idValue(s.brandId), {
    searchPlaceholder: 'Search brands…',
  }),
  searchableSelect<ApprovalSet>('delivery', 'Delivery', (s) => idValue(s.deliveryId), {
    searchPlaceholder: 'Search deliveries…',
  }),
  searchableSelect<ApprovalSet>('project', 'Project', (s) => idValue(s.projectId), {
    searchPlaceholder: 'Search projects…',
  }),
];
