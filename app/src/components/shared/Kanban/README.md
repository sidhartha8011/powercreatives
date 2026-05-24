# Shared Kanban Primitive

Domain-agnostic kanban board. Designed so any module can mount it with its
own data, columns, filters, and card body — without copy-pasting the board
or coupling to a specific schema.

## Contract

```ts
KANBAN_CONTRACT_VERSION === 1
```

Breaking changes bump the version. Pin against it if needed:

```ts
import { KANBAN_CONTRACT_VERSION } from '@/components/shared/Kanban';
if (KANBAN_CONTRACT_VERSION !== 1) throw new Error('Kanban contract drift');
```

## Three responsibilities, three owners

| Concern | Owner | File |
|---|---|---|
| Data fetching + state | Consumer hook | e.g. `useApprovalSets` |
| Domain card body | Consumer component | e.g. `SetCard` |
| Column chrome, layout, error boundary | Shared `KanbanBoard` | `KanbanBoard.tsx` |
| Filter / sort UI | Shared `KanbanToolbar` | `KanbanToolbar.tsx` |
| Filter / sort logic | Shared engine | `filters/` |

If you're adding a filter, you write **one entry** in a declaration file.
If you're adding a control kind, you write **one component + one entry in
the registry**. You should never touch `KanbanBoard.tsx` or the engine
files to add a domain feature.

## Minimal consumer (50 lines)

```tsx
import { useMemo } from 'react';
import {
  KanbanBoard,
  KanbanToolbar,
  searchableSelect,
  sortBy,
  useListState,
  type KanbanColumn,
} from '@/components/shared/Kanban';

interface Task { id: number; title: string; project: string; status: string; createdAt: string; }

const columns: KanbanColumn[] = [
  { id: 'todo',  label: 'To do',  accentColor: '#eef0f3', accentText: '#4a4a4a' },
  { id: 'doing', label: 'Doing',  accentColor: '#fef3c7', accentText: '#92400e' },
  { id: 'done',  label: 'Done',   accentColor: '#dcfce7', accentText: '#166534' },
];

const filters = [
  searchableSelect<Task>('project', 'Project', t => t.project),
];

const sorts = [
  sortBy<Task>('newest', 'Newest first', (a, b) => b.createdAt.localeCompare(a.createdAt)),
];

export function TaskBoard({ tasks }: { tasks: Task[] }) {
  const state = useListState(tasks, filters, sorts, { persistKey: 'tasks', defaultSortId: 'newest' });
  const getColumnId = useMemo(() => (t: Task) => t.status, []);

  return (
    <>
      <KanbanToolbar items={tasks} filters={filters} sorts={sorts} state={state} />
      <KanbanBoard
        columns={columns}
        items={state.filteredItems}
        getColumnId={getColumnId}
        renderCard={(t) => <div style={{ padding: 10 }}>{t.title}</div>}
      />
    </>
  );
}
```

## Adding a new filter (1 line)

```ts
// existing
searchableSelect<Task>('project', 'Project', t => t.project),
// new
searchableSelect<Task>('assignee', 'Assignee', t => t.assignee),
```

That's it. The toolbar renders the new pill, the engine handles the state
and matching, URL sync picks it up. No other file changes.

## Adding a new sort (1 line)

```ts
sortBy<Task>('priorityDesc', 'High priority first', (a, b) => b.priority - a.priority),
```

## Adding a new control kind (≈30 lines)

1. Add a variant to `FilterControlSpec` in `filters/types.ts`.
2. Add a matching variant to `FilterValue`.
3. Add a `case` in `applyFilters.ts → matches()`.
4. Create `filters/controls/MyControl.tsx`.
5. Export it from `filters/controls/index.ts`.
6. Add a `case` in `KanbanToolbar.tsx → FilterControl`.
7. Add a factory in `filters/factories.ts`.

The compiler will tell you exactly what's missing — every step is type-
checked. No runtime registry to keep in sync.

## URL synchronization

Pass `persistKey` to `useListState` to sync filter + sort state to the
URL. Keys are namespaced (`<persistKey>.<filterId>`) so two boards on the
same page don't collide.

```ts
useListState(items, filters, sorts, { persistKey: 'sets' });
// URL becomes: ?sets.brand=Nike,Adidas&sets.sort=newest
```

## CSS scoping

Everything is CSS Modules — class names are hashed at build time. The only
*global* surface is the `--pck-*` CSS custom properties declared on
`.pck-root`. Override them on any parent element to re-theme:

```css
.my-darker-board.pck-root {
  --pck-bg-column: #1e1e1e;
  --pck-text: #f4f4f4;
}
```

## Type safety policy

Files in `Kanban/` and `Kanban/filters/` MUST be written strict-clean:

- No `any`. Use `unknown` and narrow, or generics with constraints.
- No `as any` casts.
- Generic constraints on every reusable type (`T extends { id: string | number }`).

The repo-wide tsconfig is permissive (`strict: false`) for historical
reasons. New code in this folder ignores that and is written as if strict
mode were on. Reviewers reject anything that doesn't meet this bar.

## Accessibility

- `role="list"` on the board, `role="listitem"` on columns and cards.
- Status communicated via the column label *and* its pill color — color is
  never the only carrier.
- `aria-label` on every interactive control. Filter triggers expose
  `aria-haspopup` / `aria-expanded`.
- `prefers-reduced-motion` honored — all hover transitions disable.

## Out of scope (intentional)

- **Drag-and-drop.** The Card render context exposes `dragHandleProps` and
  `isDragging` slots so DnD can be added later without breaking card
  consumers, but no DnD wiring ships today.
- **Virtualization.** Boards expected to render < 500 cards at once; revisit
  with `@tanstack/react-virtual` if that changes.
- **Tests.** Repo has no test infrastructure. Engine functions
  (`applyFilters`, `applySort`) are written as pure functions so they're
  trivial to unit-test once infra exists.
