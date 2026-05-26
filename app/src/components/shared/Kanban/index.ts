/**
 * Public surface of the shared Kanban primitive.
 *
 * Consumers should import from this barrel only — never reach into
 * internal files. Internal-only helpers (KanbanErrorBoundary, default
 * Loading/Error states, etc.) live inside the folder and aren't exposed.
 *
 * Scope:
 *   - Board rendering (lanes, card wrappers, DnD, default loading/error)
 *   - Filter / sort engine (pure, framework-free)
 *
 * Out of scope:
 *   - Toolbar / filter UI controls. Each consumer renders its own bar
 *     with the design-system primitives (shadcn Input/Select/Button) and
 *     binds the state via useListState. This keeps the bar consistent
 *     with the rest of the platform and avoids a parallel CSS system.
 */

// ─── Core board + types ───
export { KanbanBoard } from './KanbanBoard';
export type {
  KanbanBoardProps,
  KanbanColumn,
  KanbanMoveEvent,
  RenderCardContext,
} from './types';

// ─── Default empty state (consumers render it themselves now) ───
export { DefaultEmptyState } from './DefaultEmptyState';
export type { DefaultEmptyStateProps } from './DefaultEmptyState';

// ─── Filter / sort engine ───
export type {
  FilterControlSpec,
  FilterDefinition,
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
