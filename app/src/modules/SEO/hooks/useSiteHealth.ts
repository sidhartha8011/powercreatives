/**
 * Site connector health — ONE batched call for every connected site
 * (gap 89ef71a). The hub runs the real connection tests sequentially
 * server-side (2-worker law); this hook just reads the map. Unknown
 * (still loading / call failed) is null — the dot stays quiet, a
 * failure is never invented client-side.
 */

import { trpc } from '@/lib/trpc';

export interface SiteHealth {
  ok: boolean | null;
  error: string | null;
}

export function useSiteHealth(enabled: boolean): (siteId: number) => SiteHealth {
  const query = trpc.sites.health.useQuery(undefined, { enabled, staleTime: 60_000 });
  const map: Record<string, { ok: boolean | null; error: string | null; ageS: number | null }> =
    (query.data as any)?.health && typeof (query.data as any).health === 'object'
      ? (query.data as any).health
      : {};
  return (siteId: number) => {
    const rec = map[String(siteId)];
    if (!rec || rec.ok === null) return { ok: null, error: null };
    // The stored read's age rides the tooltip — stored data is labeled.
    const age = rec.ageS !== null && rec.ageS >= 60 ? ` (checked ${Math.round(rec.ageS / 60)} min ago)` : '';
    return { ok: !!rec.ok, error: rec.error ? `${rec.error}${age}` : null };
  };
}
