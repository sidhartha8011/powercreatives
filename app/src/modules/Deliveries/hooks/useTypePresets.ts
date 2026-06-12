/**
 * useTypePresets — live view of the central delivery-type → modules mapping.
 *
 * Reads GET /deliveries/type-presets (admin-customized via the Settings →
 * Delivery Types section, built-in defaults otherwise). Falls back to the
 * page-load snapshot in pcmConfig while the query is in flight so the
 * DeliveryDialog never renders an empty Type select.
 */

import { useQuery } from '@tanstack/react-query';

import { apiFetch } from '@/lib/trpc';
import { getDeliveryTypePresets, type DeliveryTypePreset } from '@/lib/pcmConfig';

/** Query-cache key — the Settings section invalidates this after saving. */
export const TYPE_PRESETS_QUERY_KEY = ['deliveries', 'type-presets'] as const;

interface TypePresetsResponse {
  /** PHP serializes an empty assoc array as [] — guard both shapes. */
  presets: Record<string, DeliveryTypePreset> | unknown[];
  grantableModules: string[];
}

export interface UseTypePresetsResult {
  /** Presets keyed by type id. Never undefined. */
  presets: Record<string, DeliveryTypePreset>;
  isLoading: boolean;
}

export function useTypePresets(): UseTypePresetsResult {
  const query = useQuery({
    queryKey: TYPE_PRESETS_QUERY_KEY,
    queryFn: () => apiFetch<TypePresetsResponse>('deliveries/type-presets'),
    staleTime: 60_000,
  });

  const raw = query.data?.presets;
  const presets =
    raw && !Array.isArray(raw) ? raw : query.data ? {} : getDeliveryTypePresets();

  return { presets, isLoading: query.isLoading };
}
