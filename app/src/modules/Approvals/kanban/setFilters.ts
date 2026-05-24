/**
 * Approval-set filters.
 *
 * Three searchable multiselects — brand, set name, project. Each is a
 * single declaration. Add a new filter? Append one line. Remove? Delete
 * one line. The toolbar reads this list and generates the UI.
 */

import { searchableSelect, type FilterDefinition } from '@/components/shared/Kanban';

import type { ApprovalSet } from '../types';

export const setFilters: ReadonlyArray<FilterDefinition<ApprovalSet>> = [
  searchableSelect<ApprovalSet>(
    'brand',
    'Brand',
    (s) => s.snapshot.brandName ?? null,
    { searchPlaceholder: 'Search brands…' }
  ),
  searchableSelect<ApprovalSet>(
    'set',
    'Approval set',
    (s) => s.name,
    { searchPlaceholder: 'Search set name…' }
  ),
  searchableSelect<ApprovalSet>(
    'project',
    'Project',
    (s) => s.snapshot.projectName ?? null,
    { searchPlaceholder: 'Search projects…' }
  ),
];
