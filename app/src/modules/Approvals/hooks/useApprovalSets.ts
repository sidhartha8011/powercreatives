/**
 * useApprovalSets — single owner of approval-set data + side effects.
 *
 * Wraps the tRPC list query, derives counts, exposes typed handlers, owns
 * the feedback-dialog open state, and owns the status-change mutation
 * with optimistic update. Cache key is preserved (trpc.approvals.listSets
 * .useQuery() is called verbatim) so any other consumer reading the same
 * query sees the same data.
 *
 * No JSX. No global side effects. Pure data + actions.
 */

import { useCallback, useMemo, useState } from 'react';
import { flushSync } from 'react-dom';
import { toast } from 'sonner';
import { useQueryClient } from '@tanstack/react-query';

import { trpc } from '@/lib/trpc';

import { APPROVAL_STATUSES, type ApprovalSet, type ApprovalStatus } from '../types';

export type ApprovalSetCountsByStatus = Record<ApprovalStatus, number>;

export interface UseApprovalSetsResult {
  /** Always an array; never undefined. */
  sets: ApprovalSet[];
  isLoading: boolean;
  /** Network or parse error from the tRPC query, or null. */
  error: Error | null;
  refetch: () => void;

  /** Counts grouped by status — useful for tab headers / sidebar badges. */
  countsByStatus: ApprovalSetCountsByStatus;

  /** Build the public client-facing review URL for a set's token. */
  getPublicBoardUrl: (token: string) => string;
  /** Copy the public URL to the clipboard with a success toast. */
  copyShareLink: (token: string) => void;

  /** Move a set to a new status (optimistic). Returns a promise. */
  updateStatus: (id: number, next: ApprovalStatus) => Promise<void>;

  /** Currently-open feedback set, or null when the dialog is closed. */
  feedbackSet: ApprovalSet | null;
  openFeedback: (set: ApprovalSet) => void;
  closeFeedback: () => void;

  /** Currently-previewed set (in-app iframe modal), or null when closed. */
  previewSet: ApprovalSet | null;
  openPreview: (set: ApprovalSet) => void;
  closePreview: () => void;
}

interface PcmConfig {
  shortcodePageUrl?: string;
}

function readShortcodePageUrl(): string {
  const cfg = (window as unknown as { pcmConfig?: PcmConfig }).pcmConfig;
  const explicit = cfg?.shortcodePageUrl;
  if (typeof explicit === 'string' && explicit.length > 0) return explicit;
  // Documented dev fallback — not a silent band-aid.
  return `${window.location.origin}/`;
}

function emptyCounts(): ApprovalSetCountsByStatus {
  return APPROVAL_STATUSES.reduce(
    (acc, status) => {
      acc[status] = 0;
      return acc;
    },
    {} as ApprovalSetCountsByStatus
  );
}

/**
 * Cache-key PREFIX for the list query. The tRPC adapter at
 * `lib/trpc.ts` constructs the full TanStack Query key as
 * `[...path, input]`, which means a no-input call ends up with a
 * trailing `undefined` slot. Rather than mirror that exact internal
 * shape (fragile — couples this hook to the adapter's implementation
 * details), we operate on the cache via partial-prefix matching
 * (`getQueriesData` / `setQueriesData` with `{ queryKey: prefix }`)
 * which targets every list-sets cache slot regardless of input shape.
 */
const LIST_QUERY_PREFIX = ['approvals', 'listSets'] as const;

export function useApprovalSets(): UseApprovalSetsResult {
  const queryClient = useQueryClient();

  const query = trpc.approvals.listSets.useQuery() as {
    data?: unknown;
    isLoading: boolean;
    error: Error | null;
    refetch: () => void;
  };

  const sets = useMemo<ApprovalSet[]>(() => {
    return Array.isArray(query.data) ? (query.data as ApprovalSet[]) : [];
  }, [query.data]);

  const countsByStatus = useMemo<ApprovalSetCountsByStatus>(() => {
    const counts = emptyCounts();
    for (const set of sets) {
      if (set.status in counts) counts[set.status] += 1;
    }
    return counts;
  }, [sets]);

  const getPublicBoardUrl = useCallback((token: string): string => {
    const baseUrl = readShortcodePageUrl();
    const separator = baseUrl.includes('?') ? '&' : '?';
    return `${baseUrl}${separator}pcm_public_token=${token}`;
  }, []);

  const copyShareLink = useCallback(
    (token: string) => {
      const url = getPublicBoardUrl(token);
      void navigator.clipboard
        .writeText(url)
        .then(() => toast.success('Client link copied to clipboard!'))
        .catch(() => toast.error('Failed to copy link.'));
    },
    [getPublicBoardUrl]
  );

  // Server-call only. No onMutate/onError/onSettled — those would push
  // the cache write to a microtask after React Query's internal async
  // execute(), which is too late: @hello-pangea/dnd's onDragEnd has
  // already returned by then and the library reverts the card to its
  // source because it saw no state change in the same tick. The
  // optimistic update + rollback below run synchronously in the drag
  // handler's call stack, which is the only timing that makes DnD
  // libraries with controlled lists behave.
  const statusMutation = trpc.approvals.updateSetStatus.useMutation();

  const updateStatus = useCallback(
    (id: number, next: ApprovalStatus): Promise<void> => {
      const filter = { queryKey: LIST_QUERY_PREFIX } as const;

      // ── DIAGNOSTIC INSTRUMENTATION (DnD jump-back v1.4.10) ──
      // Temporary. Remove once root cause is confirmed and fixed.
      // Logs every boundary in the optimistic-update + mutation flow so
      // the next session has real data instead of theory.
      const t0 = performance.now();
      const allKeys = queryClient.getQueryCache().getAll().map((q) => q.queryKey);
      const snapshots = queryClient.getQueriesData<ApprovalSet[]>(filter);
      const beforeData = snapshots[0]?.[1];
      const beforeCard = beforeData?.find((s) => s.id === id);
      // eslint-disable-next-line no-console
      console.group(`[approvals-dnd] updateStatus id=${id} → ${next}  (t=${t0.toFixed(1)}ms)`);
      // eslint-disable-next-line no-console
      console.log('all queryKeys in cache:', allKeys);
      // eslint-disable-next-line no-console
      console.log('matched slots via prefix:', snapshots.length, 'snapshots:', snapshots);
      // eslint-disable-next-line no-console
      console.log('card BEFORE optimistic write:', beforeCard);
      // eslint-disable-next-line no-console
      console.groupEnd();

      // Optimistic write — flushSync forces synchronous commit so
      // @hello-pangea/dnd's post-drop IDLE paint sees the new items prop.
      flushSync(() => {
        queryClient.setQueriesData<ApprovalSet[]>(filter, (prev) => {
          if (!prev) return prev;
          return prev.map((s) => (s.id === id ? { ...s, status: next } : s));
        });
      });

      // ── DIAGNOSTIC: cache state immediately after flushSync ──
      const afterData = queryClient.getQueriesData<ApprovalSet[]>(filter)[0]?.[1];
      const afterCard = afterData?.find((s) => s.id === id);
      // eslint-disable-next-line no-console
      console.log(
        `[approvals-dnd] AFTER flushSync cache state — card status:`,
        afterCard?.status,
        `(expected ${next})  (t=${(performance.now() - t0).toFixed(1)}ms)`
      );

      return statusMutation
        .mutateAsync({ id, status: next })
        .then((res: unknown) => {
          // eslint-disable-next-line no-console
          console.log(
            `[approvals-dnd] mutateAsync RESOLVED for id=${id}  (t=${(performance.now() - t0).toFixed(1)}ms)`,
            res
          );
          // ── DIAGNOSTIC: cache state at resolution time ──
          const postRes = queryClient.getQueriesData<ApprovalSet[]>(filter)[0]?.[1];
          const postCard = postRes?.find((s) => s.id === id);
          // eslint-disable-next-line no-console
          console.log(
            `[approvals-dnd] cache state at resolve — card status:`,
            postCard?.status,
            `(expected still ${next})`
          );
        })
        .catch((err: unknown) => {
          // eslint-disable-next-line no-console
          console.error(
            `[approvals-dnd] mutateAsync REJECTED for id=${id}  (t=${(performance.now() - t0).toFixed(1)}ms)`,
            err
          );
          // Rollback wrapped in flushSync so the revert is a single frame.
          flushSync(() => {
            for (const [key, data] of snapshots) {
              if (data !== undefined) queryClient.setQueryData(key, data);
            }
          });
          const message = err instanceof Error ? err.message : 'Failed to move set';
          toast.error(message);
          throw err;
        });
    },
    [queryClient, statusMutation]
  );

  const [feedbackSet, setFeedbackSet] = useState<ApprovalSet | null>(null);
  const openFeedback = useCallback((set: ApprovalSet) => setFeedbackSet(set), []);
  const closeFeedback = useCallback(() => setFeedbackSet(null), []);

  const [previewSet, setPreviewSet] = useState<ApprovalSet | null>(null);
  const openPreview = useCallback((set: ApprovalSet) => setPreviewSet(set), []);
  const closePreview = useCallback(() => setPreviewSet(null), []);

  return {
    sets,
    isLoading: query.isLoading,
    error: query.error,
    refetch: query.refetch,
    countsByStatus,
    getPublicBoardUrl,
    copyShareLink,
    updateStatus,
    feedbackSet,
    openFeedback,
    closeFeedback,
    previewSet,
    openPreview,
    closePreview,
  };
}
