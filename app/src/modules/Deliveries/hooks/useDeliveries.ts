/**
 * useDeliveries — single owner of delivery data + side effects.
 *
 * Wraps the tRPC list query, derives counts, exposes typed handlers, and
 * owns the status-change mutation with optimistic update (same flushSync
 * pattern as useApprovalSets — required so @hello-pangea/dnd's post-drop
 * IDLE paint sees the new column before snapping the card back).
 *
 * Dialog state intentionally does NOT live here. The board and the
 * module entry both call this hook, and React state is per-instance —
 * a dialog opened by one instance would not be visible to the dialog
 * mounted by the other. Dialog state lives one level up, in the module
 * entry, and is passed down as props.
 *
 * No JSX. No global side effects. Pure data + actions.
 */

import { useCallback, useMemo } from 'react';
import { flushSync } from 'react-dom';
import { toast } from 'sonner';
import { useQueryClient } from '@tanstack/react-query';

import { trpc } from '@/lib/trpc';

import {
  DELIVERY_STATUSES,
  type Delivery,
  type DeliveryStatus,
} from '../types';

export type DeliveryCountsByStatus = Record<DeliveryStatus, number>;

export interface CreateDeliveryInput {
  name: string;
  clientName?: string;
  status?: DeliveryStatus;
  type?: string | null;
  brandId?: number | null;
  projectId?: number | null;
  modules?: string[];
  externalId?: string | null;
}

export interface UpdateDeliveryInput {
  id: number;
  name?: string;
  clientName?: string | null;
  status?: DeliveryStatus;
  type?: string | null;
  brandId?: number | null;
  projectId?: number | null;
  modules?: string[];
  externalId?: string | null;
}

export interface UseDeliveriesResult {
  /** Always an array; never undefined. */
  deliveries: Delivery[];
  isLoading: boolean;
  error: Error | null;
  refetch: () => void;

  /** Counts grouped by status — useful for tab headers / sidebar badges. */
  countsByStatus: DeliveryCountsByStatus;

  /** Create a new delivery. Resolves to the created delivery. */
  createDelivery: (input: CreateDeliveryInput) => Promise<Delivery>;
  /** Update a delivery's editable fields. Resolves to the updated delivery. */
  updateDelivery: (input: UpdateDeliveryInput) => Promise<Delivery>;
  /** Move a delivery to a new status (optimistic). Resolves on server ack. */
  updateStatus: (id: number, next: DeliveryStatus) => Promise<void>;
  /**
   * Optimistically patch editable fields (the dynamic-lane DnD path for
   * type/brand/client lanes). Same flushSync contract as updateStatus.
   */
  updateFieldsOptimistic: (
    id: number,
    fields: { type?: string | null; brandId?: number | null; clientName?: string | null }
  ) => Promise<void>;
  /**
   * Optimistically set the delivery's lead (the dynamic-lane DnD path for
   * lead lanes). Patches assignees in cache, then PATCH /deliveries/{id}/lead.
   */
  setLeadOptimistic: (id: number, userId: number | null, userName: string | null) => Promise<void>;
  /** Delete a single delivery (optimistic). Resolves on server ack. */
  deleteDelivery: (id: number) => Promise<void>;
}

function emptyCounts(): DeliveryCountsByStatus {
  return DELIVERY_STATUSES.reduce(
    (acc, status) => {
      acc[status] = 0;
      return acc;
    },
    {} as DeliveryCountsByStatus
  );
}

/**
 * Cache-key PREFIX for the list query. The tRPC adapter constructs the
 * full TanStack Query key as `[...path, input]`, which for a no-input
 * call produces `['deliveries', 'list', undefined]`. We operate via
 * prefix-matching so cache writes target every list slot regardless of
 * input shape.
 */
const LIST_QUERY_PREFIX = ['deliveries', 'list'] as const;

export function useDeliveries(): UseDeliveriesResult {
  const queryClient = useQueryClient();

  const query = trpc.deliveries.list.useQuery() as {
    data?: unknown;
    isLoading: boolean;
    error: Error | null;
    refetch: () => void;
  };

  const deliveries = useMemo<Delivery[]>(() => {
    if (!Array.isArray(query.data)) return [];
    // Boundary normalization. WordPress / MySQL serializes BIGINT
    // columns as JSON strings (`{"id":"6","userId":"1"}`). Coerce to
    // numbers here so the declared TS types are honest and every
    // downstream `d.id === id` strict-equality predicate actually
    // matches. Same defensive pattern as useApprovalSets.
    return (query.data as Delivery[]).map((raw) => ({
      ...raw,
      id: Number(raw.id),
      userId: Number(raw.userId),
    }));
  }, [query.data]);

  const countsByStatus = useMemo<DeliveryCountsByStatus>(() => {
    const counts = emptyCounts();
    for (const d of deliveries) {
      if (d.status in counts) counts[d.status] += 1;
    }
    return counts;
  }, [deliveries]);

  // ─── Mutations ───────────────────────────────────────────────
  const createMutation = trpc.deliveries.create.useMutation();
  const updateMutation = trpc.deliveries.update.useMutation();
  const statusMutation = trpc.deliveries.update.useMutation();
  const deleteMutation = trpc.deliveries.delete.useMutation();

  const invalidateList = useCallback(
    () => queryClient.invalidateQueries({ queryKey: LIST_QUERY_PREFIX }),
    [queryClient]
  );

  const createDelivery = useCallback(
    (input: CreateDeliveryInput): Promise<Delivery> =>
      createMutation
        .mutateAsync(input)
        .then((created: unknown) => {
          void invalidateList();
          toast.success('Delivery created');
          return created as Delivery;
        })
        .catch((err: unknown) => {
          toast.error(
            err instanceof Error ? err.message : 'Failed to create delivery'
          );
          throw err;
        }),
    [createMutation, invalidateList]
  );

  const updateDelivery = useCallback(
    // Silent on success — the card auto-saves per field and shows its own
    // transient "Saved ✓" whisper; a toast per blurred field is spam.
    // Errors still toast (and the card restores the previous value).
    (input: UpdateDeliveryInput): Promise<Delivery> =>
      updateMutation
        .mutateAsync(input)
        .then((updated: unknown) => {
          void invalidateList();
          return updated as Delivery;
        })
        .catch((err: unknown) => {
          toast.error(
            err instanceof Error ? err.message : 'Failed to update delivery'
          );
          throw err;
        }),
    [updateMutation, invalidateList]
  );

  // Status mutation — optimistic. No onMutate/onError on the mutation
  // itself; the optimistic write runs inside DnD's onDragEnd call stack
  // (synchronously via flushSync) so @hello-pangea/dnd's IDLE paint sees
  // the new items prop and does NOT snap the card back. Same pattern as
  // useApprovalSets.updateStatus — see CHANGELOG-20260527-0425.md for
  // the autopsy of the four-attempts-to-fix bug this avoids.
  const updateStatus = useCallback(
    (id: number, next: DeliveryStatus): Promise<void> => {
      const filter = { queryKey: LIST_QUERY_PREFIX } as const;
      const snapshots = queryClient.getQueriesData<Delivery[]>(filter);

      flushSync(() => {
        queryClient.setQueriesData<Delivery[]>(filter, (prev) => {
          if (!prev) return prev;
          return prev.map((d) =>
            Number(d.id) === id ? { ...d, status: next } : d
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
          toast.error(
            err instanceof Error ? err.message : 'Failed to move delivery'
          );
          throw err;
        });
    },
    [queryClient, statusMutation]
  );

  // Dynamic-lane drops (type/brand/client) — same synchronous optimistic
  // write as updateStatus so @hello-pangea/dnd's IDLE paint never snaps the
  // card back; the server re-normalizes (e.g. a type change re-applies the
  // module preset), so ack is followed by an invalidate.
  const fieldsMutation = trpc.deliveries.update.useMutation();
  const updateFieldsOptimistic = useCallback(
    (
      id: number,
      fields: { type?: string | null; brandId?: number | null; clientName?: string | null }
    ): Promise<void> => {
      const filter = { queryKey: LIST_QUERY_PREFIX } as const;
      const snapshots = queryClient.getQueriesData<Delivery[]>(filter);

      flushSync(() => {
        queryClient.setQueriesData<Delivery[]>(filter, (prev) => {
          if (!prev) return prev;
          return prev.map((d) => (Number(d.id) === id ? { ...d, ...fields } : d));
        });
      });

      return fieldsMutation
        .mutateAsync({ id, ...fields })
        .then(() => {
          void invalidateList();
        })
        .catch((err: unknown) => {
          flushSync(() => {
            for (const [key, data] of snapshots) {
              if (data !== undefined) queryClient.setQueryData(key, data);
            }
          });
          toast.error(err instanceof Error ? err.message : 'Failed to move delivery');
          throw err;
        });
    },
    [queryClient, fieldsMutation, invalidateList]
  );

  // Lead-lane drops — optimistic assignees patch (demote current lead, mark
  // the target user lead; append if not yet assigned), then the lead route.
  const leadMutation = trpc.deliveries.setLead.useMutation();
  const setLeadOptimistic = useCallback(
    (id: number, userId: number | null, userName: string | null): Promise<void> => {
      const filter = { queryKey: LIST_QUERY_PREFIX } as const;
      const snapshots = queryClient.getQueriesData<Delivery[]>(filter);

      flushSync(() => {
        queryClient.setQueriesData<Delivery[]>(filter, (prev) => {
          if (!prev) return prev;
          return prev.map((d) => {
            if (Number(d.id) !== id) return d;
            const demoted = (d.assignees ?? []).map((a) =>
              a.role === 'lead' ? { ...a, role: 'member' } : a
            );
            if (userId == null) return { ...d, assignees: demoted };
            const existing = demoted.find((a) => Number(a.id) === userId);
            const assignees = existing
              ? demoted.map((a) => (Number(a.id) === userId ? { ...a, role: 'lead' } : a))
              : [...demoted, { id: userId, name: userName ?? `User #${userId}`, role: 'lead' }];
            return { ...d, assignees };
          });
        });
      });

      return leadMutation
        .mutateAsync({ id, userId })
        .then(() => {
          void invalidateList();
        })
        .catch((err: unknown) => {
          flushSync(() => {
            for (const [key, data] of snapshots) {
              if (data !== undefined) queryClient.setQueryData(key, data);
            }
          });
          toast.error(err instanceof Error ? err.message : 'Failed to set the lead');
          throw err;
        });
    },
    [queryClient, leadMutation, invalidateList]
  );

  const deleteDelivery = useCallback(
    (id: number): Promise<void> => {
      const filter = { queryKey: LIST_QUERY_PREFIX } as const;
      const snapshots = queryClient.getQueriesData<Delivery[]>(filter);

      // Optimistic filter — drop the deleted delivery from every cache slot.
      queryClient.setQueriesData<Delivery[]>(filter, (prev) => {
        if (!prev) return prev;
        return prev.filter((d) => Number(d.id) !== id);
      });

      return deleteMutation
        .mutateAsync({ id })
        .then(() => {
          toast.success('Delivery deleted');
        })
        .catch((err: unknown) => {
          for (const [key, data] of snapshots) {
            if (data !== undefined) queryClient.setQueryData(key, data);
          }
          toast.error(
            err instanceof Error ? err.message : 'Failed to delete delivery'
          );
          throw err;
        });
    },
    [queryClient, deleteMutation]
  );

  return {
    deliveries,
    isLoading: query.isLoading,
    error: query.error,
    refetch: query.refetch,
    countsByStatus,
    createDelivery,
    updateDelivery,
    updateStatus,
    updateFieldsOptimistic,
    setLeadOptimistic,
    deleteDelivery,
  };
}
