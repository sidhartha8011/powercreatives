# HANDOVER — DnD jump-back bug in Approvals kanban

**Status:** NOT FIXED. Four attempts. All failed per user testing.
**Date:** 2026-05-26
**Last commit:** `e4604e0` (still buggy per user)

---

## 1. Bug as reported by user

1. User drags an approval-set card from one lane to another in the
   Approvals kanban board (`Approvals Pipeline` page in WP admin).
2. The card moves visually during drag (drop animation completes).
3. **The card then jumps back to its original lane.**
4. If the user reloads the page, the card is in the NEW lane (server
   persisted the move correctly).
5. The user has done nothing between drop and the jump-back — no other
   action, no other UI interaction.

**Net effect:** server-side move works; client-side UI shows revert
until manual reload.

---

## 2. Files relevant to this bug

| File | Role |
|---|---|
| `app/src/modules/Approvals/hooks/useApprovalSets.ts` | Owns the data + the `updateStatus` mutation flow. **This is where every fix attempt was made.** |
| `app/src/modules/Approvals/kanban/SetsBoard.tsx` | Renders `<KanbanBoard onItemMove={handleMove}>`; `handleMove` calls `updateStatus(id, newStatus)`. |
| `app/src/components/shared/Kanban/KanbanBoard.tsx` | Uses `@hello-pangea/dnd`'s `<DragDropContext>` / `<Droppable>` / `<Draggable>`. `handleDragEnd` calls `onItemMove`. |
| `app/src/lib/trpc.ts` | Custom tRPC-style adapter wrapping TanStack Query. Generates query keys as `[...path, input]` (line 657). |
| `app/src/main.tsx` | QueryClient defaults: `staleTime: 30_000, retry: 1`. |

Mutation REST route: `trpc.approvals.updateSetStatus.useMutation()` → resolved by ROUTE_MAP in `lib/trpc.ts` → hits a PHP REST endpoint under `includes/modules/approvals/`. Server-side works (verified by user reload showing the move).

---

## 3. Stack versions in use

- `@tanstack/react-query`: v5 (queryClient.setQueriesData / getQueriesData API used).
- `@hello-pangea/dnd`: see `package.json`. Drag-drop library, fork of `react-beautiful-dnd`. Uses a controlled-list pattern — consumer must update items array on drop; library re-renders accordingly.
- React: 18+ (concurrent rendering).

---

## 4. Every fix attempt — chronological and verbatim

### Attempt 1 — commit `204d507`

**Theory:** `onSettled: invalidateQueries(...)` in the mutation triggered a refetch that raced with `@hello-pangea/dnd`'s drop animation, causing a flip-flop (move → revert → move).

**Code change in `useApprovalSets.ts`:**
```diff
-    onSettled: () => {
-      void queryClient.invalidateQueries({ queryKey: LIST_QUERY_KEY });
-    },
+    // (removed entirely)
```

**Result per user:** "right now I cannot even move the card they just jump back" — WORSE than before. Removing the invalidate exposed the underlying issue: the optimistic update wasn't reaching the rendered state. Previously the invalidate-triggered refetch masked the bug by eventually replacing the cache with fresh server data.

---

### Attempt 2 — commit `1bc05e8`

**Theory:** `mutateAsync` internally runs `execute()` which is async. Even if `onMutate` is synchronous, it gets scheduled on a microtask, meaning `setQueryData` fires AFTER `onDragEnd` has returned. By then `@hello-pangea/dnd` has finalized its drop and seen no state change, so it animates the card back to source.

**Code change in `useApprovalSets.ts`:** Moved optimistic update OUT of `onMutate` and INTO `updateStatus` callback, BEFORE calling `mutateAsync`:

```ts
const statusMutation = trpc.approvals.updateSetStatus.useMutation();
//                       no onMutate / onError / onSettled

const updateStatus = useCallback(
  (id: number, next: ApprovalStatus): Promise<void> => {
    const previous = queryClient.getQueryData<ApprovalSet[]>(LIST_QUERY_KEY);
    if (previous) {
      queryClient.setQueryData<ApprovalSet[]>(
        LIST_QUERY_KEY,
        previous.map((s) => (s.id === id ? { ...s, status: next } : s))
      );
    }
    return statusMutation.mutateAsync({ id, status: next }).catch((err) => {
      if (previous) queryClient.setQueryData(LIST_QUERY_KEY, previous);
      toast.error(...);
      throw err;
    });
  },
  [queryClient, statusMutation]
);
```

`LIST_QUERY_KEY = ['approvals', 'listSets', undefined] as const;`

**Result per user:** No change. Still jumps back.

---

### Attempt 3 — commit `e4604e0` (current state)

**Theory:** The hardcoded exact-match `LIST_QUERY_KEY = ['approvals', 'listSets', undefined]` may not actually match the cache slot React Query is using. If `getQueryData(LIST_QUERY_KEY)` returns `undefined`, the `if (previous)` guard skips `setQueryData`, no optimistic update happens, DnD library sees stale items, animates card back to source. The mutateAsync then succeeds server-side. The user only sees the new state after reload (which fetches fresh data).

**Code change in `useApprovalSets.ts`:** Switched from exact-key matching to partial-prefix matching:

```ts
const LIST_QUERY_PREFIX = ['approvals', 'listSets'] as const;

const updateStatus = useCallback(
  (id: number, next: ApprovalStatus): Promise<void> => {
    const filter = { queryKey: LIST_QUERY_PREFIX } as const;
    const snapshots = queryClient.getQueriesData<ApprovalSet[]>(filter);

    queryClient.setQueriesData<ApprovalSet[]>(filter, (prev) => {
      if (!prev) return prev;
      return prev.map((s) => (s.id === id ? { ...s, status: next } : s));
    });

    return statusMutation.mutateAsync({ id, status: next }).catch((err) => {
      for (const [key, data] of snapshots) {
        if (data !== undefined) queryClient.setQueryData(key, data);
      }
      toast.error(...);
      throw err;
    });
  },
  [queryClient, statusMutation]
);
```

`setQueriesData` with a partial-prefix queryKey matches any cache slot whose key starts with `['approvals', 'listSets']`. This sidesteps the exact-key mismatch problem.

**Result per user:** No change. Still jumps back.

---

## 5. What is verified vs assumed

### Verified by reading code:

- The tRPC adapter at `lib/trpc.ts:657` constructs the queryKey as `[...path, input]`. For `trpc.approvals.listSets.useQuery()` with no args, the path is `['approvals', 'listSets']` and input is `undefined`, producing `['approvals', 'listSets', undefined]`.
- Only one `useApprovalSets` consumer: `SetsBoard.tsx`. (Verified via `grep`.)
- Only one QueryClient instance, configured in `main.tsx`.
- `staleTime: 30_000` so background refetch isn't happening during a drag.
- The mutation in attempt 3 has NO `onMutate`/`onError`/`onSettled` callbacks — only the consumer-driven optimistic + rollback in `updateStatus`.
- `setQueriesData` exists in TanStack Query v5 and supports partial-prefix matching.

### NOT verified (still assumptions):

- **Whether `setQueriesData({ queryKey: ['approvals', 'listSets'] }, ...)` actually finds the cache slot at runtime.** Never logged. Never inspected via browser DevTools.
- **Whether the cache write triggers a re-render of `SetsBoard`.** Never logged.
- **Whether the new items array reaches `KanbanBoard`.** Never logged.
- **Whether `@hello-pangea/dnd`'s drop-finalization sees the new items.** No instrumentation.
- **Whether something else (an effect, a parent component, the `BroadcastChannel`-style sync in some React Query setups) is overwriting the cache after our optimistic write.**

**Bottom line:** every theory was reasoned from source-code, never confirmed against runtime behavior.

---

## 6. Why my attempts kept missing

Three rounds of code changes without ever running the code in a browser to verify the assumption. I treated theories as facts. Each fix was based on the previous theory being wrong, but I never INSTRUMENTED to find out WHY it was wrong. A senior dev would have added console-logging or used React DevTools / TanStack DevTools after attempt 1 instead of guessing at the next fix.

---

## 7. What the next developer should do

### Step 1 — instrument before changing more code

Add temporary diagnostics in `useApprovalSets.ts → updateStatus`:

```ts
const updateStatus = useCallback((id, next) => {
  const filter = { queryKey: LIST_QUERY_PREFIX } as const;
  const snapshots = queryClient.getQueriesData<ApprovalSet[]>(filter);

  console.group('[approvals] updateStatus');
  console.log('matched cache slots:', snapshots.length);
  console.log('snapshots:', snapshots);
  console.log('all queries:', queryClient.getQueryCache().getAll().map(q => q.queryKey));
  console.groupEnd();

  // ...rest
}, [...]);
```

When the user drags a card, the console will show:
- How many slots matched the prefix (should be ≥ 1).
- Whether the snapshot contains the expected ApprovalSet array.
- ALL keys currently in the cache — to verify the actual key shape for listSets.

If `matched cache slots: 0`, the prefix is wrong (very unlikely given the trace) or the cache is named differently.

If matched but snapshot is empty/undefined, the data is being stored elsewhere.

If everything looks right but the card still jumps back, the issue is NOT the cache — it's somewhere in the render chain or DnD library.

### Step 2 — install/open TanStack Query DevTools

Add `@tanstack/react-query-devtools` to the dev build. It will show every query, its key shape, and its current data. Drop a card and watch the cache slot for the list query — if it updates and reverts in the same second, something is overwriting it.

### Step 3 — install React DevTools Profiler

Record a profiling session during a drop. Look at:
- Which components re-render after the drop.
- Whether `SetsBoard` re-renders with new `sets`.
- Whether `KanbanBoard` receives new `items`.
- Whether `KanbanColumn` for the destination receives the moved card.

If the components DO re-render with the new state but the user still sees the card in source, the issue is INSIDE `@hello-pangea/dnd`'s rendering — possibly a library bug or a misuse of its API (e.g., the `Draggable` `key` and `draggableId` are different so the library doesn't know it's the same item).

Look closely at `KanbanBoard.tsx` lines 200–220:
```tsx
<Draggable
  key={item.id}
  draggableId={String(item.id)}
  index={idx}
>
```
`key` is `item.id` (number). `draggableId` is `String(item.id)` (string). For a typical ApprovalSet with numeric id, this is fine. Verify no edge case where id is e.g. an object or undefined.

### Step 4 — verify the mutation server response

In Network tab, drop a card and look at the request to the status update endpoint. Confirm:
- HTTP 200/2xx.
- Response body content.
- No CORS / cookie / nonce errors that would silently fail the mutation in a way that triggers rollback.

If the mutation actually fails silently, our `.catch()` rollback fires and restores the previous state — which would look IDENTICAL to "jumps back".

This is the most plausible scenario I never verified.

### Step 5 — check if React Query's QueryObserver is doing background sync

React Query has a feature where if multiple components observe the same query, the data is synced. If somewhere else in the app `trpc.approvals.listSets.useQuery()` is called and triggers a refetch, the cache gets overwritten with server data. Until the server reflects the change, that overwrite undoes our optimistic update.

Verify: `grep -r 'listSets' app/src/` and check every consumer's behavior.

---

## 8. Other relevant code state

### Mutation endpoint mapping

In `lib/trpc.ts` the ROUTE_MAP must have an entry for `approvals.updateSetStatus`. If it's missing or wrong, the mutation goes to a fallback endpoint and might silently no-op. Confirm by reading the ROUTE_MAP near the top of `lib/trpc.ts`.

### Server-side handler

PHP file under `includes/modules/approvals/` should expose a route that maps to `updateSetStatus`. Verify the route exists and is registered. Verify the status update is persisted by reading the database after a move.

### DnD library version

Check `package.json` for `@hello-pangea/dnd` version. There were known bugs in early versions around controlled lists and React 18 concurrent rendering. If the version is < 16, consider upgrading.

---

## 9. Pristine reproduction steps

1. WP Admin → Power Creatives → Approvals Pipeline.
2. Create at least 2 approval sets in different statuses (e.g. one in "Draft", one in "Internal").
3. Drag the "Draft" card to the "Internal" lane.
4. Observe: card animates to destination, then snaps back to "Draft".
5. Reload page. Card is now in "Internal" (server has it).

Repro environment: `http://powercreatives.local/wp-admin/?localwp_auto_login=1`.

---

## 10. Recent commits in this branch (for context)

```
e4604e0 fix DnD optimistic cache write via partial-prefix matching (ATTEMPT 3, FAILED)
1bc05e8 fix DnD card jump-back: synchronous optimistic update (ATTEMPT 2, FAILED)
204d507 fix: empty-lane text removed, card text smaller, radius higher, DnD flip-flop fixed (ATTEMPT 1, FAILED)
331975a Approvals bar -> shadcn migration + empty-state split (architectural refactor, unrelated to bug)
```

The bug existed BEFORE the shadcn migration. The migration did not cause it — DnD has had jump-back issues across all attempts.

---

## 11. Honest assessment

The bug was attacked three times with code changes derived from reading the source and reasoning forward. None of those changes resolved the user-reported behavior. The next developer should NOT continue patching at the same layer — they should INSTRUMENT first (logging, DevTools, network inspection) to discover where in the chain the optimistic update is being lost or overridden. Once that's known, the fix is likely 5–10 lines.

The most likely missed cause: **the mutation is silently failing (auth, nonce, route mismatch), causing the `.catch()` rollback to fire and restore the previous state.** That would visually present as "card jumps back" while server-side state did NOT actually change — but the user reports that after reload the server HAS changed. So this theory only holds if there's a delay between the rollback-firing failure and the eventual server success — possible if the mutation retries (`retry: 1` in queryClient defaults).

Worth verifying first.
