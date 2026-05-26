# Shared Kanban Primitive

Domain-agnostic kanban board + a pure filter / sort engine. Any module can
mount it with its own data, columns, and card body — without copy-pasting
the board or coupling to a specific schema.

## Three responsibilities, three owners

| Concern | Owner | File |
|---|---|---|
| Data fetching + state | Consumer hook | e.g. `useApprovalSets` |
| Domain card body | Consumer component | e.g. `SetCard` |
| Lane chrome, DnD, layout, error boundary | Shared `KanbanBoard` | `KanbanBoard.tsx` |
| Filter / sort logic | Shared engine | `filters/` |
| **Toolbar / filter UI** | **Consumer** (shadcn primitives) | per-module |

The toolbar is intentionally owned by the consumer. Each module renders
its bar with `<Input>` / `<Select>` / `<Button>` from `components/ui` so
every bar in the platform looks identical without a parallel CSS system.
The engine exposes state-binding hooks (`useListState`,
`state.setFilterValue`, `state.setSortId`) the consumer wires to those
primitives.

## Minimal consumer (≈ 60 lines)

```tsx
import { useCallback, useMemo } from 'react';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import {
  KanbanBoard,
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

const filters = [searchableSelect<Task>('project', 'Project', (t) => t.project)];
const sorts   = [sortBy<Task>('newest', 'Newest first', (a, b) => b.createdAt.localeCompare(a.createdAt))];

export function TaskBoard({ tasks }: { tasks: Task[] }) {
  const state = useListState(tasks, filters, sorts, { persistKey: 'tasks', defaultSortId: 'newest' });
  const projectOptions = useMemo(() => Array.from(new Set(tasks.map(t => t.project))).sort(), [tasks]);
  const projectValue = state.filterState.project?.kind === 'searchableSelect'
    ? state.filterState.project.selected[0] ?? '__all__'
    : '__all__';
  const getColumnId = useCallback((t: Task) => t.status, []);

  return (
    <>
      <div className="flex flex-wrap items-center gap-3 mb-6 bg-slate-50/50 p-2 rounded-lg border border-slate-100">
        <Select
          value={projectValue}
          onValueChange={(v) =>
            state.setFilterValue('project', v === '__all__' ? undefined : { kind: 'searchableSelect', selected: [v] })
          }
        >
          <SelectTrigger className="w-[160px] h-9 bg-white"><SelectValue placeholder="Project" /></SelectTrigger>
          <SelectContent>
            <SelectItem value="__all__">All projects</SelectItem>
            {projectOptions.map(p => <SelectItem key={p} value={p}>{p}</SelectItem>)}
          </SelectContent>
        </Select>
      </div>
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

## Adding a new filter

1. Declare it via a factory (`searchableSelect`, `textFilter`,
   `booleanFilter`, `dateRangeFilter`):

   ```ts
   searchableSelect<Task>('assignee', 'Assignee', (t) => t.assignee),
   ```

2. Render the matching control in your bar and bind it to
   `state.filterState['assignee']` / `state.setFilterValue('assignee', ...)`.

That's all. The engine handles state, matching, and URL sync. No internal
registry to update.

## Adding a new sort (one line)

```ts
sortBy<Task>('priorityDesc', 'High priority first', (a, b) => b.priority - a.priority),
```

Then add a `<SelectItem value="priorityDesc">High priority first</SelectItem>`
to your bar's sort `<Select>`.

## Empty states are the consumer's job

`KanbanBoard` does not hide itself when `items.length === 0`. The lanes
always render with their per-column `emptyHint`. The consumer decides
whether to:

- Hide the board entirely (truly-empty data) and show an onboarding state.
- Keep the board visible (filter-empty data) and show a "no matches" notice.

See `modules/Approvals/kanban/SetsBoard.tsx` for the canonical example.

## URL synchronization

Pass `persistKey` to `useListState` to sync filter + sort state to the
URL. Keys are namespaced (`<persistKey>.<filterId>`) so two boards on the
same page don't collide.

```ts
useListState(items, filters, sorts, { persistKey: 'sets' });
// URL becomes: ?sets.project=Nike&sets.sort=newest
```

## CSS scoping

The board internals are CSS Modules — class names are hashed at build
time. The only *global* surface is the `--pck-*` CSS custom properties
declared on `.pck-root`. Override them on any parent element to re-theme:

```css
.my-darker-board.pck-root {
  --pck-bg-lane: #1e1e1e;
  --pck-text: #f4f4f4;
}
```

Toolbar styling lives outside this folder — that's the consumer's
Tailwind + shadcn surface, not the kanban primitive's concern.

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
- `aria-label` on every interactive control. Consumers must label their
  bar inputs (`<Input aria-label="...">`, `<SelectTrigger aria-label="...">`).
- `prefers-reduced-motion` honored — all hover transitions disable.

## Out of scope (intentional)

- **Toolbar component.** Each consumer renders its bar with platform
  primitives. Removed in favor of design-system consistency.
- **Virtualization.** Boards expected to render < 500 cards at once;
  revisit with `@tanstack/react-virtual` if that changes.
- **Tests.** Repo has no test infrastructure. Engine functions
  (`applyFilters`, `applySort`) are written as pure functions so they're
  trivial to unit-test once infra exists.
