/**
 * useApprovalSets — single owner of approval-set data + side effects.
 *
 * Wraps the tRPC list query, derives counts, exposes typed handlers, owns
 * the feedback/preview dialog open state, owns the status-change
 * mutation with optimistic update, owns single + bulk delete mutations,
 * and owns the multi-select selection state for the bulk-action UI.
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
  /** Delete a single set (optimistic). Returns a promise. */
  deleteSet: (id: number) => Promise<void>;
  /** Delete many sets in one server call (optimistic). Returns a promise. */
  bulkDeleteSets: (ids: ReadonlyArray<number>) => Promise<void>;

  /** Currently-open feedback set, or null when the dialog is closed. */
  feedbackSet: ApprovalSet | null;
  openFeedback: (set: ApprovalSet) => void;
  closeFeedback: () => void;

  /** Currently-previewed set (in-app iframe modal), or null when closed. */
  previewSet: ApprovalSet | null;
  openPreview: (set: ApprovalSet) => void;
  closePreview: () => void;

  // ─── Multi-select for bulk actions ────────────────────────────
  /** Numeric ids of currently-selected cards. */
  selectedIds: ReadonlySet<number>;
  /** Whether any card is currently selected (drives select-mode UI). */
  hasSelection: boolean;
  /** True if the given id is currently selected. */
  isSelected: (id: number) => boolean;
  /** Toggle a single id's selection state. */
  toggleSelection: (id: number) => void;
  /** Replace the entire selection with the given ids. */
  setSelection: (ids: ReadonlyArray<number>) => void;
  /** Clear all selection (exits select-mode). */
  clearSelection: () => void;
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
 * Cache-key PREFIX for the list query. The tRPC adapter at `lib/trpc.ts`
 * constructs the full TanStack Query key as `[...path, input]`, which
 * for a no-input call produces `['approvals', 'listSets', undefined]`.
 * We operate via prefix-matching (`setQueriesData({ queryKey: prefix })`)
 * so cache writes target every list-sets slot regardless of input shape.
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
    if (!Array.isArray(query.data)) return [];
    // Boundary normalization. WordPress / MySQL serializes BIGINT
    // columns as JSON strings (`{"id":"6","userId":"1","brandId":"11"}`).
    // Coerce to numbers here so the declared TS types are honest and
    // every downstream `s.id === id` strict-equality predicate actually
    // matches (the root cause of the v1.4.x DnD jump-back bug).
    return (query.data as ApprovalSet[]).map((raw) => ({
      ...raw,
      id: Number(raw.id),
      userId: Number(raw.userId),
      brandId: raw.brandId != null ? Number(raw.brandId) : null,
      projectId: raw.projectId != null ? Number(raw.projectId) : null,
      deliveryId: raw.deliveryId != null ? Number(raw.deliveryId) : null,
    }));
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

  // ─── Status mutation (DnD column moves) ──────────────────────
  // No onMutate/onError/onSettled on the useMutation itself — the
  // optimistic write below runs inside DnD's `onDragEnd` call stack
  // (synchronously via flushSync) so @hello-pangea/dnd's post-drop
  // IDLE paint sees the new items prop and does not snap back.
  const statusMutation = trpc.approvals.updateSetStatus.useMutation();

  const updateStatus = useCallback(
    (id: number, next: ApprovalStatus): Promise<void> => {
      const filter = { queryKey: LIST_QUERY_PREFIX } as const;
      const snapshots = queryClient.getQueriesData<ApprovalSet[]>(filter);

      flushSync(() => {
        queryClient.setQueriesData<ApprovalSet[]>(filter, (prev) => {
          if (!prev) return prev;
          return prev.map((s) =>
            Number(s.id) === id ? { ...s, status: next } : s
          );
        });
      });

      return statusMutation
        .mutateAsync({ id, status: next })
        .then(() => undefined)
        .catch((err: unknown) => {
          flushSync(() => {
            for (const [key, data] of snapshots) {
              if (data !== undefined) queryClient.setQueryData(key, data);
            }
          });
          toast.error(err instanceof Error ? err.message : 'Failed to move set');
          throw err;
        });
    },
    [queryClient, statusMutation]
  );

  // ─── Delete mutations (single + bulk) ────────────────────────
  const deleteMutation = trpc.approvals.deleteSet.useMutation();
  const bulkDeleteMutation = trpc.approvals.bulkDeleteSets.useMutation();

  const deleteSet = useCallback(
    (id: number): Promise<void> => {
      const filter = { queryKey: LIST_QUERY_PREFIX } as const;
      const snapshots = queryClient.getQueriesData<ApprovalSet[]>(filter);

      // Optimistic filter — drop the deleted set from every cache slot.
      queryClient.setQueriesData<ApprovalSet[]>(filter, (prev) => {
        if (!prev) return prev;
        return prev.filter((s) => Number(s.id) !== id);
      });

      return deleteMutation
        .mutateAsync({ id })
        .then(() => {
          toast.success('Set deleted');
        })
        .catch((err: unknown) => {
          for (const [key, data] of snapshots) {
            if (data !== undefined) queryClient.setQueryData(key, data);
          }
          toast.error(err instanceof Error ? err.message : 'Failed to delete set');
          throw err;
        });
    },
    [queryClient, deleteMutation]
  );

  const bulkDeleteSets = useCallback(
    (ids: ReadonlyArray<number>): Promise<void> => {
      if (ids.length === 0) return Promise.resolve();

      const filter = { queryKey: LIST_QUERY_PREFIX } as const;
      const snapshots = queryClient.getQueriesData<ApprovalSet[]>(filter);
      const idSet = new Set(ids.map((n) => Number(n)));

      queryClient.setQueriesData<ApprovalSet[]>(filter, (prev) => {
        if (!prev) return prev;
        return prev.filter((s) => !idSet.has(Number(s.id)));
      });

      return bulkDeleteMutation
        .mutateAsync({ ids: Array.from(idSet) })
        .then((res: unknown) => {
          const deleted =
            res && typeof res === 'object' && 'deleted' in res
              ? Number((res as { deleted: unknown }).deleted) || ids.length
              : ids.length;
          toast.success(`Deleted ${deleted} set${deleted === 1 ? '' : 's'}`);
        })
        .catch((err: unknown) => {
          for (const [key, data] of snapshots) {
            if (data !== undefined) queryClient.setQueryData(key, data);
          }
          toast.error(err instanceof Error ? err.message : 'Failed to delete sets');
          throw err;
        });
    },
    [queryClient, bulkDeleteMutation]
  );

  // ─── Selection state for bulk actions ────────────────────────
  const [selectedIds, setSelectedIdsInternal] = useState<ReadonlySet<number>>(
    () => new Set()
  );

  const toggleSelection = useCallback((id: number) => {
    setSelectedIdsInternal((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }, []);

  const setSelection = useCallback((ids: ReadonlyArray<number>) => {
    setSelectedIdsInternal(new Set(ids));
  }, []);

  const clearSelection = useCallback(() => {
    setSelectedIdsInternal(new Set());
  }, []);

  const isSelected = useCallback(
    (id: number) => selectedIds.has(id),
    [selectedIds]
  );

  // ─── Dialog open-state ───────────────────────────────────────
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
    deleteSet,
    bulkDeleteSets,
    feedbackSet,
    openFeedback,
    closeFeedback,
    previewSet,
    openPreview,
    closePreview,
    selectedIds,
    hasSelection: selectedIds.size > 0,
    isSelected,
    toggleSelection,
    setSelection,
    clearSelection,
  };
}
