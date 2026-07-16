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
  const map: Record<string, { ok: boolean; error: string | null }> =
    (query.data as any)?.health && typeof (query.data as any).health === 'object'
      ? (query.data as any).health
      : {};
  return (siteId: number) => {
    const rec = map[String(siteId)];
    return rec ? { ok: !!rec.ok, error: rec.error ?? null } : { ok: null, error: null };
  };
}
