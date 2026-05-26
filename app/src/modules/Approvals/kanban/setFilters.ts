/**
 * Approval-set filters.
 *
 * One instant search input (combined across brand, project, set name) +
 * three searchable multiselects. Each is a single declaration. Add a new
 * filter? Append one line. Remove? Delete one line. The toolbar reads this
 * list and generates the UI in order — search renders first.
 */

import {
  searchableSelect,
  textFilter,
  type FilterDefinition,
} from '@/components/shared/Kanban';

import type { ApprovalSet } from '../types';

export const setFilters: ReadonlyArray<FilterDefinition<ApprovalSet>> = [
  textFilter<ApprovalSet>(
    'search',
    'Search',
    (s) =>
      [s.snapshot.brandName, s.snapshot.projectName, s.name]
        .filter((v): v is string => Boolean(v))
        .join(' '),
    { placeholder: 'Search approval sets…' }
  ),
  searchableSelect<ApprovalSet>(
    'brand',
    'Brand',
    (s) => s.snapshot.brandName ?? null,
    { searchPlaceholder: 'Search brands…' }
  ),
  searchableSelect<ApprovalSet>(
    'project',
    'Project',
    (s) => s.snapshot.projectName ?? null,
    { searchPlaceholder: 'Search projects…' }
  ),
  searchableSelect<ApprovalSet>(
    'set',
    'Approval set',
    (s) => s.name,
    { searchPlaceholder: 'Search set name…' }
  ),
];
