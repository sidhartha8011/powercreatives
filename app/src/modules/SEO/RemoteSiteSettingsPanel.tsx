/**
 * RemoteSiteSettingsPanel — manage a CONNECTED site's Site SEO and one-click "Optimize" it.
 *
 * Generates Site Title + Tagline (WP core, via /wp/v2/settings), custom robots.txt + a
 * site-wide JSON-LD block (via the connector's /pcm-conn/v1/site) from your prompts
 * (Settings → Templates → SEO) and a chosen brand's business context, then saves to the
 * connected site. robots/JSON-LD require the connector v1.2.0+; Title/Tagline use core REST.
 */

import { useEffect, useMemo, useState, useCallback } from 'react';
import { Loader2, Save, Sparkles, AlertTriangle, Trash2, ArrowRight } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { trpc } from '@/lib/trpc';

interface RemoteSite {
  robots: string;
  jsonld: string;
  siteTitle: string;
  tagline: string;
}

export function RemoteSiteSettingsPanel({ siteId, siteName }: { siteId: number; siteName: string }) {
  const { data, isLoading, error, refetch } = trpc.seo.remoteSiteGet.useQuery(
    { siteId },
    { enabled: siteId != null, retry: false },
  ) as { data?: unknown; isLoading: boolean; error?: unknown; refetch: () => void };
  const saveMutation = trpc.seo.remoteSiteSave.useMutation();
  // Generates the TEXT from your prompts + a brand's business context, with the CONNECTED
  // site's url/name as the {{website.url}} / {{business.*}} context (so robots Sitemap +
  // schema url point to the remote site). We then save it to the connected site.
  const generateMutation = trpc.seo.remoteSiteGenerate.useMutation();
  // Cleanup C4: converts the site's legacy render-time heading overrides into
  // hub-managed heading instructions (verified on the connector, then cleared).
  const migrateMutation = trpc.seo.remoteMigrateOverrides.useMutation();

  const { data: brandsRaw } = trpc.brands.list.useQuery();
  const brands = useMemo(
    () => (Array.isArray(brandsRaw) ? (brandsRaw as any[]).map((b) => ({ id: Number(b.id), name: String(b.name) })) : []),
    [brandsRaw],
  );
  const [genBrandId, setGenBrandId] = useState<string>('');

  const [form, setForm] = useState<RemoteSite | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (data && typeof data === 'object') {
      const d = data as Partial<RemoteSite>;
      setForm({
        robots: String(d.robots ?? ''),
        jsonld: String(d.jsonld ?? ''),
        siteTitle: String(d.siteTitle ?? ''),
        tagline: String(d.tagline ?? ''),
      });
    }
  }, [data]);

  const patch = useCallback((k: keyof RemoteSite, v: string) => setForm((f) => (f ? { ...f, [k]: v } : f)), []);

  const genField = useCallback(async (field: 'site_title' | 'site_tagline' | 'robots' | 'schema', key: keyof RemoteSite): Promise<boolean> => {
    const brandId = genBrandId ? Number(genBrandId) : undefined;
    try {
      const res: any = await generateMutation.mutateAsync({ siteId, field, brandId });
      const v = String(res?.value ?? '');
      if (v) { setForm((f) => (f ? { ...f, [key]: v } : f)); return true; }
    } catch (err) {
      toast.error(err instanceof Error ? err.message : `${field} generation failed`);
    }
    return false;
  }, [genBrandId, generateMutation, siteId]);

  // One-click: generate Site Title, Tagline, robots.txt + JSON-LD; fill the form; user Saves.
  const handleOptimize = useCallback(async () => {
    setBusy(true);
    let ok = false;
    if (await genField('site_title', 'siteTitle')) ok = true;
    if (await genField('site_tagline', 'tagline')) ok = true;
    if (await genField('robots', 'robots')) ok = true;
    if (await genField('schema', 'jsonld')) ok = true;
    if (ok) toast.success('Generated — review and click Save');
    setBusy(false);
  }, [genField]);

  const genOne = useCallback(async (field: 'site_title' | 'site_tagline' | 'robots' | 'schema', key: keyof RemoteSite) => {
    setBusy(true);
    await genField(field, key);
    setBusy(false);
  }, [genField]);

  const handleSave = useCallback(async () => {
    if (!form) return;
    const trimmed = form.jsonld.trim();
    if (trimmed !== '') {
      try {
        JSON.parse(trimmed);
      } catch {
        toast.error('JSON-LD is not valid JSON — fix it before saving.');
        return;
      }
    }
    setBusy(true);
    try {
      await saveMutation.mutateAsync({ siteId, robots: form.robots, jsonld: form.jsonld, siteTitle: form.siteTitle, tagline: form.tagline });
      toast.success(`Site settings saved to ${siteName}`);
      refetch();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Failed to save');
    } finally {
      setBusy(false);
    }
  }, [form, saveMutation, siteId, siteName, refetch]);

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-16">
        <Loader2 className="w-6 h-6 animate-spin text-primary" />
      </div>
    );
  }

  if (error || !form) {
    const msg = error instanceof Error
      ? error.message
      : 'Could not load this site’s settings. Make sure the latest connector is installed on the remote site.';
    return (
      <div className="border border-dashed border-border rounded-xl p-8 text-center max-w-2xl">
        <AlertTriangle className="w-7 h-7 mx-auto mb-3 text-muted-foreground/60" />
        <p className="text-sm font-medium">
          Site settings unavailable for <span className="text-foreground">{siteName}</span>
        </p>
        <p className="mt-1.5 text-xs text-muted-foreground max-w-md mx-auto">{msg}</p>
      </div>
    );
  }

  return (
    <div className="space-y-6 max-w-2xl">
      {/* One-click optimize */}
      <section className="rounded-xl border border-primary/30 bg-accent/40 p-4 space-y-3">
        <div>
          <Label className="text-sm font-medium">One-click optimize</Label>
          <p className="text-xs text-muted-foreground">
            Generate <span className="font-medium text-foreground">{siteName}</span>’s Site Title, Tagline,
            robots.txt and a LocalBusiness JSON-LD block from your prompts. Pick a brand for the business
            details. Edit how these are written in Settings → Templates → SEO.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Select value={genBrandId} onValueChange={setGenBrandId}>
            <SelectTrigger className="w-[230px]" title="Brand for the business details">
              <SelectValue placeholder="Brand (business details)…" />
            </SelectTrigger>
            <SelectContent>
              {brands.length === 0
                ? <div className="px-2 py-1.5 text-xs text-muted-foreground">No brands yet</div>
                : brands.map((b) => <SelectItem key={b.id} value={String(b.id)}>{b.name}</SelectItem>)}
            </SelectContent>
          </Select>
          <Button onClick={handleOptimize} disabled={busy} className="gap-1.5">
            {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <Sparkles className="w-4 h-4" />}
            Optimize
          </Button>
        </div>
      </section>

      {/* Site identity (WP core Title + Tagline) */}
      <section className="rounded-xl border border-border bg-card p-4 space-y-3">
        <div>
          <Label className="text-sm font-medium">Site identity</Label>
          <p className="text-xs text-muted-foreground">The connected site’s WordPress Title &amp; Tagline.</p>
        </div>
        <div className="space-y-1.5">
          <Label className="text-xs text-muted-foreground">Site Title</Label>
          <div className="flex gap-1.5">
            <Input value={form.siteTitle} onChange={(e) => patch('siteTitle', e.target.value)} placeholder="e.g. Acme Plumbing" className="text-xs" />
            <Button variant="outline" size="icon" title="Generate site title" onClick={() => genOne('site_title', 'siteTitle')} disabled={busy}><Sparkles className="w-4 h-4" /></Button>
          </div>
        </div>
        <div className="space-y-1.5">
          <Label className="text-xs text-muted-foreground">Tagline</Label>
          <div className="flex gap-1.5">
            <Input value={form.tagline} onChange={(e) => patch('tagline', e.target.value)} placeholder="e.g. Trusted local plumbers since 2008" className="text-xs" />
            <Button variant="outline" size="icon" title="Generate tagline" onClick={() => genOne('site_tagline', 'tagline')} disabled={busy}><Sparkles className="w-4 h-4" /></Button>
          </div>
        </div>
      </section>

      {/* robots.txt (connector) */}
      <section className="rounded-xl border border-border bg-card p-4 space-y-3">
        <div className="flex items-center justify-between gap-2">
          <div>
            <Label className="text-sm font-medium">Custom robots.txt rules</Label>
            <p className="text-xs text-muted-foreground">Appended to the connected site’s robots.txt output (connector v1.2.0+).</p>
          </div>
          <Button variant="outline" size="icon" title="Generate robots.txt" onClick={() => genOne('robots', 'robots')} disabled={busy} className="shrink-0"><Sparkles className="w-4 h-4" /></Button>
        </div>
        <Textarea
          value={form.robots}
          onChange={(e) => patch('robots', e.target.value)}
          rows={5}
          className="font-mono text-xs"
          placeholder={'User-agent: *\nDisallow: /private/'}
        />
      </section>

      {/* Site-wide JSON-LD (connector) */}
      <section className="rounded-xl border border-border bg-card p-4 space-y-3">
        <div className="flex items-center justify-between gap-2">
          <div>
            <Label className="text-sm font-medium">Site-wide JSON-LD</Label>
            <p className="text-xs text-muted-foreground">Injected into every page’s &lt;head&gt; on the connected site. Must be valid JSON.</p>
          </div>
          <Button variant="outline" size="icon" title="Generate JSON-LD" onClick={() => genOne('schema', 'jsonld')} disabled={busy} className="shrink-0"><Sparkles className="w-4 h-4" /></Button>
        </div>
        <Textarea
          value={form.jsonld}
          onChange={(e) => patch('jsonld', e.target.value)}
          rows={6}
          placeholder='{"@context":"https://schema.org","@type":"LocalBusiness","name":"..."}'
          className="font-mono text-xs"
        />
      </section>

      {/* Redirects (connector 3.0.5+) — created from the slug-change offer; managed here. */}
      <RedirectsSection siteId={siteId} />

      {/* Legacy override migration (cleanup C4) — connector 3.0.0+ */}
      <section className="rounded-xl border border-border bg-card p-4 space-y-3">
        <div className="flex items-center justify-between gap-2">
          <div>
            <Label className="text-sm font-medium">Migrate legacy heading overrides</Label>
            <p className="text-xs text-muted-foreground">
              Converts this site’s render-time heading overrides into hub-managed heading
              instructions (connector 3.0.0+). Verified on the site before the old list is
              cleared — the pages serve identically. Safe to re-run.
            </p>
          </div>
          <Button
            variant="outline"
            className="shrink-0"
            disabled={migrateMutation.isPending}
            onClick={() => {
              migrateMutation.mutate({ siteId }, {
                onSuccess: (res: any) => {
                  const n = Number(res?.migrated ?? 0);
                  toast.success(n > 0
                    ? `Migrated ${n} override${n === 1 ? '' : 's'} — legacy list cleared.`
                    : 'No legacy overrides on this site — nothing to migrate.');
                },
                onError: (e: any) => { toast.error(e?.message ?? 'Migration failed — nothing was changed on the live site.'); },
              });
            }}
          >
            {migrateMutation.isPending ? <Loader2 className="w-4 h-4 mr-2 animate-spin" /> : null}
            Migrate
          </Button>
        </div>
      </section>

      <div className="flex justify-end">
        <Button onClick={handleSave} disabled={busy}>
          {busy ? <Loader2 className="w-4 h-4 mr-2 animate-spin" /> : <Save className="w-4 h-4 mr-2" />}
          Save
        </Button>
      </div>
    </div>
  );
}

interface RedirectRow {
  id: number;
  fromPath: string;
  toUrl: string;
  code: number;
}

/** The site's redirect list (from → to, type, delete) — every redirect the
 *  slug-change offer created is visible and removable here, never a black box. */
function RedirectsSection({ siteId }: { siteId: number }) {
  const { data, refetch } = trpc.seo.remoteRedirects.useQuery(
    { siteId },
    { retry: false },
  ) as { data?: { supported?: boolean; redirects?: RedirectRow[] }; refetch: () => void };
  const deleteMutation = trpc.seo.remoteDeleteRedirect.useMutation();
  const [deletingId, setDeletingId] = useState<number | null>(null);

  const supported = data?.supported === true;
  const redirects = Array.isArray(data?.redirects) ? data.redirects : [];

  const remove = (id: number) => {
    setDeletingId(id);
    deleteMutation
      .mutateAsync({ siteId, redirectId: id })
      .then(() => { toast.success('Redirect removed'); refetch(); })
      .catch((err: unknown) => { toast.error(err instanceof Error ? err.message : 'Failed to remove the redirect'); })
      .finally(() => setDeletingId(null));
  };

  return (
    <section className="rounded-xl border border-border bg-card p-4 space-y-3">
      <div>
        <Label className="text-sm font-medium">Redirects</Label>
        <p className="text-xs text-muted-foreground">
          {supported
            ? 'Served by the connected site before WordPress answers a missing URL (connector 3.0.5+).'
            : 'Needs connector 3.0.5+ — update the connector from the Sites module.'}
        </p>
      </div>
      {supported && (
        redirects.length === 0 ? (
          <p className="text-xs text-muted-foreground/70">No redirects yet — they’re offered when a published page’s slug changes.</p>
        ) : (
          <ul className="divide-y divide-border">
            {redirects.map((r) => (
              <li key={r.id} className="flex items-center gap-2 py-2 text-xs">
                <span className="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground">{Number(r.code)}</span>
                <span className="min-w-0 truncate font-mono" title={r.fromPath}>{r.fromPath}</span>
                <ArrowRight className="size-3 shrink-0 text-muted-foreground/60" />
                <span className="min-w-0 flex-1 truncate font-mono" title={r.toUrl}>{r.toUrl}</span>
                <Button
                  variant="ghost"
                  size="icon"
                  className="size-7 shrink-0 text-muted-foreground hover:text-destructive"
                  title="Remove redirect"
                  disabled={deletingId === Number(r.id)}
                  onClick={() => remove(Number(r.id))}
                >
                  {deletingId === Number(r.id) ? <Loader2 className="size-3.5 animate-spin" /> : <Trash2 className="size-3.5" />}
                </Button>
              </li>
            ))}
          </ul>
        )
      )}
    </section>
  );
}
