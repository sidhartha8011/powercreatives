/**
 * BusinessPanel — Google Business Profile (GBP) for a brand (Phase 7).
 *
 * Configure the GBP fetch webhook, search for a place, save its details onto
 * the selected brand, and edit manual overrides (which survive refresh). The
 * stored data enriches the SEO prompt {{business.*}} variables. The fetch
 * runs through a swappable provider (n8n today, direct Google later).
 */

import { useEffect, useMemo, useState, useCallback } from 'react';
import { Loader2, Search, Save, Building2 } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { trpc } from '@/lib/trpc';

interface Place { place_id: string; name: string; address: string; phone: string; category: string; website: string }
const OVERRIDE_FIELDS: { key: string; label: string }[] = [
  { key: 'name', label: 'Name' },
  { key: 'address', label: 'Address' },
  { key: 'phone', label: 'Phone' },
  { key: 'website', label: 'Website' },
  { key: 'category', label: 'Category' },
  { key: 'description', label: 'Description' },
];

export function BusinessPanel() {
  const { data: brandsRaw } = trpc.brands.list.useQuery();
  const brands: { id: number; name: string }[] = Array.isArray(brandsRaw)
    ? (brandsRaw as any[]).map((b) => ({ id: Number(b.id), name: String(b.name) }))
    : [];

  const [brandId, setBrandId] = useState<string>('');
  const [query, setQuery] = useState('');
  const [results, setResults] = useState<Place[]>([]);
  const [resolved, setResolved] = useState<Record<string, any>>({});
  const [overrides, setOverrides] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  const searchM = trpc.seo.gbpSearch.useMutation();
  const saveM = trpc.seo.gbpSave.useMutation();
  const overridesM = trpc.seo.gbpOverrides.useMutation();
  const getQuery = trpc.seo.gbpGet.useQuery(
    brandId ? { brand: Number(brandId) } : (undefined as any),
    { enabled: !!brandId },
  ) as { data?: any; refetch: () => void };

  useEffect(() => {
    const rec = getQuery.data;
    if (rec) {
      setResolved(rec.resolved ?? {});
      setOverrides(Object.fromEntries(Object.entries(rec.overrides ?? {}).map(([k, v]) => [k, String(v ?? '')])));
    }
  }, [getQuery.data]);

  const handleSearch = useCallback(async () => {
    if (!query.trim()) return;
    setBusy(true);
    try {
      const res: any = await searchM.mutateAsync({ query: query.trim() });
      setResults(Array.isArray(res?.results) ? res.results : []);
      if (!res?.results?.length) toast.info('No places found');
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Search failed');
    } finally { setBusy(false); }
  }, [query, searchM]);

  const handleSavePlace = useCallback(async (placeId: string) => {
    if (!brandId) { toast.error('Pick a brand first'); return; }
    setBusy(true);
    try {
      const rec: any = await saveM.mutateAsync({ brand: Number(brandId), placeId });
      setResolved(rec?.resolved ?? {});
      setResults([]);
      toast.success('Business saved to brand');
      getQuery.refetch();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Save failed');
    } finally { setBusy(false); }
  }, [brandId, saveM, getQuery]);

  const handleSaveOverrides = useCallback(async () => {
    if (!brandId) return;
    setBusy(true);
    try {
      const rec: any = await overridesM.mutateAsync({ brand: Number(brandId), overrides });
      setResolved(rec?.resolved ?? {});
      toast.success('Overrides saved');
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Save failed');
    } finally { setBusy(false); }
  }, [brandId, overrides, overridesM]);

  const hasRecord = useMemo(() => Object.keys(resolved).length > 0, [resolved]);

  return (
    <div className="space-y-6 max-w-2xl">
      {/* n8n webhook config REMOVED (Google Native Phase A, gap 670d0e0):
          the fetch runs through the native google_places Integrations key. */}
      {/* Brand + search */}
      <section className="rounded-xl border border-border bg-card p-4 space-y-3">
        <div className="grid grid-cols-[200px_1fr] gap-3">
          <div className="space-y-1.5">
            <Label className="text-xs text-muted-foreground">Brand (client)</Label>
            <Select value={brandId} onValueChange={setBrandId}>
              <SelectTrigger className="text-xs"><SelectValue placeholder="Select a brand" /></SelectTrigger>
              <SelectContent>
                {brands.map((b) => <SelectItem key={b.id} value={String(b.id)}>{b.name}</SelectItem>)}
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-1.5">
            <Label className="text-xs text-muted-foreground">Search a business</Label>
            <div className="flex gap-2">
              <Input value={query} onChange={(e) => setQuery(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') handleSearch(); }} placeholder="e.g. Smålands Tak Jönköping" className="text-xs" />
              <Button onClick={handleSearch} disabled={busy || !query.trim()} className="gap-1.5">
                {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <Search className="w-4 h-4" />}
              </Button>
            </div>
          </div>
        </div>

        {results.length > 0 && (
          <div className="space-y-1.5 pt-2">
            {results.map((p) => (
              <div key={p.place_id} className="flex items-center justify-between gap-3 rounded-lg border border-border px-3 py-2">
                <div className="min-w-0">
                  <div className="text-sm font-medium truncate">{p.name}</div>
                  <div className="text-xs text-muted-foreground truncate">{p.address} · {p.phone}</div>
                </div>
                <Button size="sm" variant="outline" disabled={!brandId || busy} onClick={() => handleSavePlace(p.place_id)}>Save</Button>
              </div>
            ))}
          </div>
        )}
      </section>

      {/* Stored record + overrides */}
      {brandId && hasRecord && (
        <section className="rounded-xl border border-border bg-card p-4 space-y-3">
          <Label className="text-sm font-medium flex items-center gap-1.5"><Building2 className="w-4 h-4" /> Business details (editable)</Label>
          <p className="text-xs text-muted-foreground">Edits override the fetched data and survive a refresh.</p>
          <div className="grid grid-cols-2 gap-3">
            {OVERRIDE_FIELDS.map((f) => (
              <div key={f.key} className="space-y-1">
                <Label className="text-xs text-muted-foreground">{f.label}</Label>
                <Input
                  value={overrides[f.key] ?? String(resolved[f.key] ?? '')}
                  onChange={(e) => setOverrides((o) => ({ ...o, [f.key]: e.target.value }))}
                  className="text-xs"
                />
              </div>
            ))}
          </div>
          <Button onClick={handleSaveOverrides} disabled={busy} className="gap-2">
            {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <Save className="w-4 h-4" />} Save overrides
          </Button>
        </section>
      )}
    </div>
  );
}
