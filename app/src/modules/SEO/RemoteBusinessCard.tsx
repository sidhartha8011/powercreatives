/**
 * THE BUSINESS CARD (Business Spine P2+P3, gap 616870f) — the connected
 * site's ONE business surface inside SEO. Ownership law: brands own brand
 * truth · sites own the connection · SEO consumes and owns exactly one
 * layer — the per-site SEO overrides (inline edits here).
 *
 * Malleable by construction: FIELD_REGISTRY drives the rows — adding a
 * field is one entry, zero backend change. Every value shows its source on
 * hover (site / manual / gbp / scrape / maps-paste / brand / site-basics).
 */

import { useEffect, useState } from 'react';
import { Building2, Loader2, MapPin, RefreshCw } from 'lucide-react';
import { toast } from 'sonner';

import { trpc } from '@/lib/trpc';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

/** THE FIELD REGISTRY — the card's rows in their two rooms (owner spec:
 *  Business = identity · Local SEO = the Google surface, tinted). Add a
 *  field = add a row; it lands in its room, zero backend change. */
type BizGroup = 'business' | 'local';
const FIELD_REGISTRY: Array<{ key: string; label: string; group: BizGroup; multiline?: boolean }> = [
  { key: 'name', label: 'Business name', group: 'business' },
  { key: 'website', label: 'Website', group: 'business' },
  { key: 'indexedurl', label: 'Indexed URL', group: 'business' },
  { key: 'email', label: 'Business email', group: 'business' },
  { key: 'phone', label: 'Phone', group: 'business' },
  { key: 'address', label: 'Address', group: 'business' },
  { key: 'postal', label: 'Postal number', group: 'business' },
  { key: 'city', label: 'City', group: 'business' },
  { key: 'region', label: 'Region', group: 'business' },
  { key: 'country', label: 'Country', group: 'business' },
  { key: 'hours', label: 'Hours', group: 'business', multiline: true },
  { key: 'language', label: 'Business language', group: 'business' },
  { key: 'description', label: 'Description', group: 'business', multiline: true },
  { key: 'socialprofiles', label: 'Social profiles', group: 'business', multiline: true },
  { key: 'niche', label: 'Niche', group: 'business' },
  { key: 'category', label: 'Category', group: 'local' },
  { key: 'serviceareas', label: 'Service areas', group: 'local' },
  { key: 'primarylocation', label: 'Primary location', group: 'local' },
  { key: 'kgid', label: 'Knowledge Graph ID', group: 'local' },
  { key: 'cid', label: 'Google CID', group: 'local' },
  { key: 'fid', label: 'Google FID', group: 'local' },
  { key: 'mapsShareUrl', label: 'Maps share URL', group: 'local' },
  { key: 'mapsEmbedUrl', label: 'Maps embed URL', group: 'local' },
  { key: 'lat', label: 'Latitude', group: 'local' },
  { key: 'lng', label: 'Longitude', group: 'local' },
];

/** Plain names for the source tags (hover captions). */
const SOURCE_LABEL: Record<string, string> = {
  'site': 'this site only',
  'manual': 'brand correction',
  'gbp': 'Google Business Profile',
  'scrape': 'scraped from the site',
  'maps-paste': 'from the pasted Maps link',
  'platform': 'from platform research',
  'brand': 'brand record',
  'site-basics': 'site defaults',
};

interface CardData {
  fields: Record<string, string>;
  sources: Record<string, string>;
  brandId: number;
  brandName: string;
  unitId: number;
  unitLabel: string;
  units: Array<{ unitId: number; label: string; isPrimary: boolean }>;
  suggestion: { brandId: number; name: string } | null;
}

export function RemoteBusinessCard({ siteId }: { siteId: number }) {
  const utils = trpc.useUtils();
  const cardQuery = trpc.seo.businessCard.useQuery({ siteId }, { staleTime: 0 }) as {
    data?: CardData; isLoading: boolean; isError: boolean; error?: any; refetch: () => void;
  };
  const brandsQuery = trpc.brands.list.useQuery() as { data?: any[] };
  const overridesM = trpc.seo.businessOverrides.useMutation();
  const refreshM = trpc.seo.businessRefresh.useMutation();
  const mapsM = trpc.seo.businessMaps.useMutation();
  const searchM = trpc.seo.gbpSearch.useMutation();
  const placeM = trpc.seo.businessPlace.useMutation();
  const siteUpdateM = trpc.sites.update.useMutation();
  const scrapeM = trpc.brands.scrapeUrl.useMutation();
  const brandCreateM = trpc.brands.create.useMutation();

  const card = cardQuery.data;
  const [drafts, setDrafts] = useState<Record<string, string>>({});
  const [mapsUrl, setMapsUrl] = useState('');
  /** Find on Google (gap 972481e) — the share-link-independent fill. */
  const [findQuery, setFindQuery] = useState('');
  const [candidates, setCandidates] = useState<Array<{ place_id: string; name: string; address: string }>>([]);
  const [busy, setBusy] = useState<string | null>(null);
  /** ASK-FIRST refresh (gap 670d0e0): null = closed; string = the URL the
   *  user is confirming — what you see is what gets scraped, and the
   *  confirmed URL becomes this site's Indexed URL. */
  const [refreshUrl, setRefreshUrl] = useState<string | null>(null);
  useEffect(() => { setDrafts({}); }, [card?.brandId, card?.unitId, siteId]);

  const refresh = () => { void utils.seo.businessCard.invalidate(); cardQuery.refetch(); };

  const saveField = async (key: string) => {
    const value = drafts[key];
    if (value === undefined || value === (card?.fields[key] ?? '')) return;
    setBusy(key);
    try {
      await overridesM.mutateAsync({ siteId, fields: { [key]: value } });
      toast.success(value === '' ? 'Override cleared — back to the brand value.' : 'Saved for this site.');
      refresh();
    } catch (e: any) {
      toast.error(e?.message ?? 'Save failed');
    } finally { setBusy(null); }
  };

  const mapBrand = async (brandId: number, businessUnitId = 0) => {
    setBusy('map');
    try {
      await siteUpdateM.mutateAsync({ id: siteId, brandId, businessUnitId });
      refresh();
    } catch (e: any) {
      toast.error(e?.message ?? 'Linking failed');
    } finally { setBusy(null); }
  };

  const createFromSite = async () => {
    setBusy('create');
    try {
      const url = String(card?.fields['siteUrl'] ?? card?.fields['website'] ?? '');
      const scraped: any = await scrapeM.mutateAsync({ url });
      const info = scraped?.businessInfo ?? {};
      const created: any = await brandCreateM.mutateAsync({
        name: String(info.name ?? '') || url.replace(/^https?:\/\//, ''),
        website: url,
        businessSummary: String(info.business_summary ?? ''),
        niche: String(info.niche ?? ''),
        colors: scraped?.colors ?? [],
      });
      const newId = Number(created?.id ?? created?.brand?.id ?? 0);
      if (!newId) throw new Error('The brand was not created — check the Brands module.');
      await mapBrand(newId);
      toast.success('Brand created from this site and linked.');
    } catch (e: any) {
      toast.error(e?.message ?? 'Create from site failed');
    } finally { setBusy(null); }
  };

  if (cardQuery.isLoading) {
    return (
      <div className="flex items-center gap-2 p-6 text-sm text-slate-500">
        <Loader2 className="h-4 w-4 animate-spin" /> Loading the business record…
      </div>
    );
  }
  if (cardQuery.isError || !card) {
    return <div className="p-6 text-sm text-slate-500">{String(cardQuery.error?.message ?? 'The business record could not be loaded.')}</div>;
  }

  const brands: Array<{ id: number; name: string }> = Array.isArray(brandsQuery.data)
    ? (brandsQuery.data as any[]).map((b) => ({ id: Number(b.id), name: String(b.name) }))
    : [];

  return (
    <div className="max-w-3xl space-y-4 p-6">
      {/* ── Mapping header: the connection lives in Sites; edited here, written there. ── */}
      <div className="flex flex-wrap items-center gap-2">
        <Building2 className="h-4 w-4 text-slate-400" />
        {card.brandId > 0 ? (
          <>
            <span className="text-sm font-medium text-slate-800">{card.brandName}</span>
            {card.units.length > 1 && (
              <Select value={String(card.unitId || card.units.find((u) => u.isPrimary)?.unitId || '')} onValueChange={(v) => void mapBrand(card.brandId, Number(v))}>
                <SelectTrigger className="h-7 w-44 text-xs"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {card.units.map((u) => (
                    <SelectItem key={u.unitId} value={String(u.unitId)}>
                      {u.label || (u.isPrimary ? 'Primary location' : `Unit ${u.unitId}`)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
            <button
              type="button"
              onClick={() => void mapBrand(0)}
              className="text-[11px] text-slate-400 underline-offset-2 hover:underline"
              title="Disconnect this site from the brand"
            >
              Unlink
            </button>
            <span className="grow" />
            <button
              type="button"
              onClick={() => setRefreshUrl(String(card.fields['indexedurl'] ?? card.fields['website'] ?? card.fields['siteUrl'] ?? ''))}
              disabled={busy !== null}
              className="inline-flex items-center gap-1 rounded-full border border-slate-200 px-2.5 py-1 text-xs text-slate-600 hover:bg-slate-50 disabled:opacity-60"
            >
              <RefreshCw className="h-3 w-3" /> Refresh from site
            </button>
          </>
        ) : (
          <>
            <span className="text-sm text-slate-500">Not linked to a brand</span>
            {card.suggestion && (
              <button
                type="button"
                onClick={() => void mapBrand(card.suggestion!.brandId)}
                disabled={busy !== null}
                className="inline-flex items-center rounded-full border border-green-600 bg-white px-2.5 py-1 text-xs font-medium text-green-700 hover:bg-green-50 disabled:opacity-60"
                title="Domain match"
              >
                Link {card.suggestion.name}
              </button>
            )}
            <Select onValueChange={(v) => void mapBrand(Number(v))}>
              <SelectTrigger className="h-7 w-44 text-xs"><SelectValue placeholder="Pick a brand" /></SelectTrigger>
              <SelectContent>
                {brands.map((b) => <SelectItem key={b.id} value={String(b.id)}>{b.name}</SelectItem>)}
              </SelectContent>
            </Select>
            <button
              type="button"
              onClick={() => void createFromSite()}
              disabled={busy !== null}
              className="inline-flex items-center gap-1 rounded-full border border-primary/40 bg-white px-2.5 py-1 text-xs font-medium text-primary hover:bg-[#e7f5ff] disabled:opacity-60"
            >
              {busy === 'create' ? <Loader2 className="h-3 w-3 animate-spin" /> : null} Create from this site
            </button>
          </>
        )}
      </div>

      {/* ── ASK-FIRST refresh popover: what you see is what gets scraped. ── */}
      {refreshUrl !== null && (
        <div className="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
          <RefreshCw className="h-4 w-4 shrink-0 text-slate-400" />
          <Input
            value={refreshUrl}
            onChange={(e) => setRefreshUrl(e.target.value)}
            placeholder="The indexed URL to scrape"
            className="h-8 bg-white text-xs"
          />
          <button
            type="button"
            onClick={async () => { const u = (refreshUrl ?? '').trim(); if (!u) return; setBusy('refresh'); try { await refreshM.mutateAsync({ siteId, url: u }); toast.success('Refreshed — your corrections kept, the URL remembered as Indexed URL.'); setRefreshUrl(null); refresh(); } catch (e: any) { toast.error(e?.message ?? 'Refresh failed'); } finally { setBusy(null); } }}
            disabled={busy !== null || (refreshUrl ?? '').trim() === ''}
            className="shrink-0 rounded-full border border-primary/40 bg-white px-2.5 py-1 text-xs font-medium text-primary hover:bg-[#e7f5ff] disabled:opacity-60"
          >
            {busy === 'refresh' ? <Loader2 className="h-3 w-3 animate-spin" /> : 'Fetch'}
          </button>
          <button
            type="button"
            onClick={() => setRefreshUrl(null)}
            className="shrink-0 rounded-full border border-slate-200 px-2.5 py-1 text-xs text-slate-500 hover:bg-white"
          >
            Cancel
          </button>
        </div>
      )}

      {/* ── THE TWO ROOMS (owner spec): Business = identity, white ·
             Local SEO = the Google surface, whisper-tinted, the Maps-paste
             row living inside it. One registry, two rooms. ── */}
      {(['business', 'local'] as const).map((room) => (
        <div key={room} className={`rounded-xl border border-slate-200 ${room === 'local' ? 'bg-amber-50/40' : 'bg-white'}`}>
          <div className="px-4 pb-1 pt-3 text-[10px] font-semibold uppercase tracking-wide text-slate-400">
            {room === 'business' ? 'Business' : 'Local SEO'}
          </div>
          {room === 'local' && card.brandId > 0 && (
            <div className="space-y-2 px-4 pb-2">
              <div className="flex items-center gap-2">
                <Input
                  value={findQuery}
                  onChange={(e) => setFindQuery(e.target.value)}
                  onKeyDown={(e) => { if (e.key === 'Enter' && findQuery.trim()) e.currentTarget.blur(); }}
                  placeholder="Find on Google — business name, city"
                  className="h-8 bg-white text-xs"
                />
                <button
                  type="button"
                  onClick={async () => { if (!findQuery.trim()) return; setBusy('find'); setCandidates([]); try { const res: any = await searchM.mutateAsync({ query: findQuery.trim() }); const rows = (Array.isArray(res?.results) ? res.results : []).map((r: any) => ({ place_id: String(r.place_id ?? ''), name: String(r.name ?? ''), address: String(r.address ?? '') })).filter((r: any) => r.place_id); setCandidates(rows); if (rows.length === 0) toast.info('No places found for that search.'); } catch (e: any) { toast.error(e?.message ?? 'Search failed'); } finally { setBusy(null); } }}
                  disabled={busy !== null || findQuery.trim() === ''}
                  className="shrink-0 rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-600 hover:bg-slate-50 disabled:opacity-60"
                >
                  {busy === 'find' ? <Loader2 className="h-3 w-3 animate-spin" /> : 'Search'}
                </button>
              </div>
              {candidates.length > 0 && (
                <div className="divide-y divide-slate-100 rounded-lg border border-slate-200 bg-white">
                  {candidates.map((c) => (
                    <button
                      key={c.place_id}
                      type="button"
                      onClick={async () => { setBusy('place'); try { await placeM.mutateAsync({ siteId, placeId: c.place_id }); setCandidates([]); setFindQuery(''); toast.success(`${c.name} — the Google record filled in.`); refresh(); } catch (e: any) { toast.error(e?.message ?? 'Fill failed'); } finally { setBusy(null); } }}
                      disabled={busy !== null}
                      className="flex w-full items-baseline gap-2 px-3 py-1.5 text-left hover:bg-slate-50 disabled:opacity-60"
                    >
                      <span className="text-xs font-medium text-slate-700">{c.name}</span>
                      <span className="truncate text-[10px] text-slate-400">{c.address}</span>
                    </button>
                  ))}
                </div>
              )}
              <div className="flex items-center gap-2">
              <MapPin className="h-4 w-4 shrink-0 text-slate-400" />
              <Input
                value={mapsUrl}
                onChange={(e) => setMapsUrl(e.target.value)}
                placeholder="Paste the Google Maps share URL"
                className="h-8 bg-white text-xs"
              />
              <button
                type="button"
                onClick={async () => { if (!mapsUrl.trim()) return; setBusy('maps'); try { const res: any = await mapsM.mutateAsync({ siteId, url: mapsUrl.trim() }); setMapsUrl(''); if (res?.google?.filled) { toast.success('Place found — the full Google record filled in.'); } else { toast.success('Maps details saved.'); if (res?.google?.error) toast.info(String(res.google.error)); } refresh(); } catch (e: any) { toast.error(e?.message ?? 'Maps link not usable'); } finally { setBusy(null); } }}
                disabled={busy !== null || mapsUrl.trim() === ''}
                className="shrink-0 rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-600 hover:bg-slate-50 disabled:opacity-60"
              >
                {busy === 'maps' ? <Loader2 className="h-3 w-3 animate-spin" /> : 'Save'}
              </button>
              </div>
            </div>
          )}
          <div className="divide-y divide-slate-100">
            {FIELD_REGISTRY.filter((f) => f.group === room).map(({ key, label, multiline }) => {
              const value = drafts[key] ?? String(card.fields[key] ?? '');
              const source = card.sources[key] ?? '';
              return (
                <div key={key} className="flex items-start gap-3 px-4 py-2" title={source ? `Source: ${SOURCE_LABEL[source] ?? source}` : undefined}>
                  <div className="w-36 shrink-0 pt-1.5 text-[11px] font-medium text-slate-500">{label}</div>
                  <div className="min-w-0 grow">
                    {multiline ? (
                      <textarea
                        value={value}
                        onChange={(e) => setDrafts((d) => ({ ...d, [key]: e.target.value }))}
                        onBlur={() => void saveField(key)}
                        rows={2}
                        className="w-full resize-y rounded-md border border-transparent bg-transparent px-2 py-1 text-xs text-slate-800 hover:border-slate-200 focus:border-slate-300 focus:outline-none"
                      />
                    ) : (
                      <input
                        value={value}
                        onChange={(e) => setDrafts((d) => ({ ...d, [key]: e.target.value }))}
                        onBlur={() => void saveField(key)}
                        className="w-full rounded-md border border-transparent bg-transparent px-2 py-1 text-xs text-slate-800 hover:border-slate-200 focus:border-slate-300 focus:outline-none"
                      />
                    )}
                  </div>
                  <div className="w-24 shrink-0 pt-1.5 text-right text-[9px] text-slate-300">
                    {busy === key ? <Loader2 className="ml-auto h-3 w-3 animate-spin" /> : (source === 'site' ? 'this site' : '')}
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      ))}
    </div>
  );
}
