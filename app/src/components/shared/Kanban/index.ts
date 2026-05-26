/**
 * Public surface of the shared Kanban primitive.
 *
 * Consumers should import from this barrel only — never reach into
 * internal files. Internal-only helpers (KanbanErrorBoundary, default
 * Loading/Error states, etc.) live inside the folder and aren't exposed.
 */

// ─── Core board + types ───
export { KanbanBoard } from './KanbanBoard';
export type {
  KanbanBoardProps,
  KanbanColumn,
  KanbanMoveEvent,
  RenderCardContext,
} from './types';

// ─── Default empty state (consumers may override or pass through) ───
export { DefaultEmptyState } from './DefaultEmptyState';
export type { DefaultEmptyStateProps } from './DefaultEmptyState';

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
export { useListState } from './filters/useListState';
export type {
  UseListStateOptions,
  UseListStateResult,
} from './filters/useListState';
