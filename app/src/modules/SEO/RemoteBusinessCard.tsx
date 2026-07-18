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

/** THE FIELD REGISTRY — the card's rows. Add a field = add a row. */
const FIELD_REGISTRY: Array<{ key: string; label: string; multiline?: boolean }> = [
  { key: 'name', label: 'Business name' },
  { key: 'address', label: 'Address' },
  { key: 'postal', label: 'Postal number' },
  { key: 'city', label: 'City' },
  { key: 'region', label: 'Region' },
  { key: 'country', label: 'Country' },
  { key: 'phone', label: 'Phone' },
  { key: 'email', label: 'Business email' },
  { key: 'website', label: 'Website' },
  { key: 'hours', label: 'Hours', multiline: true },
  { key: 'category', label: 'Category' },
  { key: 'niche', label: 'Niche' },
  { key: 'serviceareas', label: 'Service areas' },
  { key: 'primarylocation', label: 'Primary location' },
  { key: 'language', label: 'Business language' },
  { key: 'description', label: 'Description', multiline: true },
  { key: 'socialprofiles', label: 'Social profiles', multiline: true },
  { key: 'kgid', label: 'Knowledge Graph ID' },
  { key: 'cid', label: 'Google CID' },
  { key: 'mapsShareUrl', label: 'Maps share URL' },
  { key: 'mapsEmbedUrl', label: 'Maps embed URL' },
  { key: 'lat', label: 'Latitude' },
  { key: 'lng', label: 'Longitude' },
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
  const siteUpdateM = trpc.sites.update.useMutation();
  const scrapeM = trpc.brands.scrapeUrl.useMutation();
  const brandCreateM = trpc.brands.create.useMutation();

  const card = cardQuery.data;
  const [drafts, setDrafts] = useState<Record<string, string>>({});
  const [mapsUrl, setMapsUrl] = useState('');
  const [busy, setBusy] = useState<string | null>(null);
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
              onClick={async () => { setBusy('refresh'); try { await refreshM.mutateAsync({ siteId }); toast.success('Refreshed from the site — your corrections kept.'); refresh(); } catch (e: any) { toast.error(e?.message ?? 'Refresh failed'); } finally { setBusy(null); } }}
              disabled={busy !== null}
              className="inline-flex items-center gap-1 rounded-full border border-slate-200 px-2.5 py-1 text-xs text-slate-600 hover:bg-slate-50 disabled:opacity-60"
            >
              {busy === 'refresh' ? <Loader2 className="h-3 w-3 animate-spin" /> : <RefreshCw className="h-3 w-3" />} Refresh from site
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

      {/* ── Maps paste: one Share URL → CID + coordinates + embed, zero API. ── */}
      {card.brandId > 0 && (
        <div className="flex items-center gap-2">
          <MapPin className="h-4 w-4 shrink-0 text-slate-400" />
          <Input
            value={mapsUrl}
            onChange={(e) => setMapsUrl(e.target.value)}
            placeholder="Paste the Google Maps share URL"
            className="h-8 text-xs"
          />
          <button
            type="button"
            onClick={async () => { if (!mapsUrl.trim()) return; setBusy('maps'); try { await mapsM.mutateAsync({ siteId, url: mapsUrl.trim() }); setMapsUrl(''); toast.success('Maps details saved to the brand.'); refresh(); } catch (e: any) { toast.error(e?.message ?? 'Maps link not usable'); } finally { setBusy(null); } }}
            disabled={busy !== null || mapsUrl.trim() === ''}
            className="shrink-0 rounded-full border border-slate-200 px-2.5 py-1 text-xs text-slate-600 hover:bg-slate-50 disabled:opacity-60"
          >
            {busy === 'maps' ? <Loader2 className="h-3 w-3 animate-spin" /> : 'Save'}
          </button>
        </div>
      )}

      {/* ── The registry-driven card: value + source; edits = this-site layer. ── */}
      <div className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
        {FIELD_REGISTRY.map(({ key, label, multiline }) => {
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
                    className="w-full resize-y rounded-md border border-transparent px-2 py-1 text-xs text-slate-800 hover:border-slate-200 focus:border-slate-300 focus:outline-none"
                  />
                ) : (
                  <input
                    value={value}
                    onChange={(e) => setDrafts((d) => ({ ...d, [key]: e.target.value }))}
                    onBlur={() => void saveField(key)}
                    className="w-full rounded-md border border-transparent px-2 py-1 text-xs text-slate-800 hover:border-slate-200 focus:border-slate-300 focus:outline-none"
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
  );
}
