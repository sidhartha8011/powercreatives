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
 * The cache key TanStack Query uses for the list query, derived by the
 * tRPC-style adapter at lib/trpc.ts (line ~652): `[...path, input]`.
 * For `trpc.approvals.listSets.useQuery()` with no input → the segments
 * become `['approvals', 'listSets', undefined]`. Mirrored here for
 * optimistic updates / invalidations.
 */
const LIST_QUERY_KEY = ['approvals', 'listSets', undefined] as const;

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

  // ── Status mutation with optimistic update ──
  const statusMutation = trpc.approvals.updateSetStatus.useMutation({
    onMutate: async (vars: { id: number; status: ApprovalStatus }) => {
      // Optimistic: write the new status into the cached list right away,
      // remember the previous list so onError can revert if the server
      // rejects (network error, ownership mismatch, invalid status).
      await queryClient.cancelQueries({ queryKey: LIST_QUERY_KEY });
      const previous = queryClient.getQueryData<ApprovalSet[]>(LIST_QUERY_KEY);

      if (previous) {
        queryClient.setQueryData<ApprovalSet[]>(
          LIST_QUERY_KEY,
          previous.map((s) => (s.id === vars.id ? { ...s, status: vars.status } : s))
        );
      }

      return { previous };
    },
    onError: (err: unknown, _vars, context: unknown) => {
      const ctx = context as { previous?: ApprovalSet[] } | undefined;
      if (ctx?.previous) {
        queryClient.setQueryData(LIST_QUERY_KEY, ctx.previous);
      }
      const message = err instanceof Error ? err.message : 'Failed to move set';
      toast.error(message);
    },
    onSettled: () => {
      // Refetch to reconcile with server truth (updatedAt etc.).
      void queryClient.invalidateQueries({ queryKey: LIST_QUERY_KEY });
    },
  });

  const updateStatus = useCallback(
    async (id: number, next: ApprovalStatus): Promise<void> => {
      await statusMutation.mutateAsync({ id, status: next });
    },
    [statusMutation]
  );

  const [feedbackSet, setFeedbackSet] = useState<ApprovalSet | null>(null);
  const openFeedback = useCallback((set: ApprovalSet) => setFeedbackSet(set), []);
  const closeFeedback = useCallback(() => setFeedbackSet(null), []);

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
  };
}
