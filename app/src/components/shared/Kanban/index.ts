/**
 * Public surface of the shared Kanban primitive.
 *
 * Consumers should import from this barrel only — never reach into
 * internal files. The shape of the public API is the stability contract
 * (versioned via KANBAN_CONTRACT_VERSION).
 */

// ─── Core board + types ───
export { KanbanBoard } from './KanbanBoard';
export {
  KANBAN_CONTRACT_VERSION,
  type KanbanBoardProps,
  type KanbanColumn,
  type KanbanMoveEvent,
  type RenderCardContext,
} from './types';

// ─── Default state UIs (overrideable) ───
export { DefaultEmptyState } from './DefaultEmptyState';
export type { DefaultEmptyStateProps } from './DefaultEmptyState';
export { DefaultLoadingState } from './DefaultLoadingState';
export { DefaultErrorState } from './DefaultErrorState';
export type { DefaultErrorStateProps } from './DefaultErrorState';

// ─── Error boundary (consumers can wrap arbitrary subtrees) ───
export { KanbanErrorBoundary } from './KanbanErrorBoundary';

// ─── Toolbar ───
export { KanbanToolbar } from './KanbanToolbar';
export type { KanbanToolbarProps } from './KanbanToolbar';

// ─── Filter / sort engine ───
export type {
  FilterControlSpec,
  FilterDefinition,
  FilterOption,
  FilterState,
  FilterValue,
  SortDefinition,
} from './filters/types';
export {
  booleanFilter,
  dateRangeFilter,
  searchableSelect,
  sortBy,
  textFilter,
} from './filters/factories';
export { applyFilters } from './filters/applyFilters';
export { applySort } from './filters/applySort';
export { useListState } from './filters/useListState';
export type {
  UseListStateOptions,
  UseListStateResult,
} from './filters/useListState';
