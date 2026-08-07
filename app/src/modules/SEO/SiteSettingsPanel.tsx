/**
 * SiteSettingsPanel — site-wide SEO settings (Phase 6).
 *
 * Custom robots.txt, a site-wide LocalBusiness JSON-LD block, and
 * language/timezone overrides (with restore-from-backup). Backed by the
 * `seo/site` REST endpoints (admin only).
 */

import { useEffect, useMemo, useRef, useState, useCallback } from 'react';
import { Loader2, Save, Undo2, Download, Upload, Sparkles } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { trpc, apiFetch } from '@/lib/trpc';

interface SiteSettings {
  schemaEnabled: boolean;
  schemaJson: string;
  robotsEnabled: boolean;
  robotsText: string;
  siteTitle: string;
  tagline: string;
  language: string;
  timezone: string;
  hasLangBackup: boolean;
  hasTzBackup: boolean;
}

export function SiteSettingsPanel() {
  const { data, isLoading, refetch } = trpc.seo.siteGet.useQuery() as {
    data?: unknown; isLoading: boolean; refetch: () => void;
  };
  const saveMutation = trpc.seo.siteSave.useMutation();
  const restoreMutation = trpc.seo.siteRestore.useMutation();
  const generateMutation = trpc.seo.siteGenerate.useMutation();

  // Brands → business context for the LocalBusiness schema (robots needs none).
  const { data: brandsRaw } = trpc.brands.list.useQuery();
  const brands = useMemo(
    () => (Array.isArray(brandsRaw) ? (brandsRaw as any[]).map((b) => ({ id: Number(b.id), name: String(b.name) })) : []),
    [brandsRaw],
  );
  const [genBrandId, setGenBrandId] = useState<string>('');

  const [form, setForm] = useState<SiteSettings | null>(null);
  const [busy, setBusy] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);

  // One-click: AI-generate robots.txt + LocalBusiness schema from the editable
  // prompts, fill the form, and enable both. The user reviews then Saves.
  const handleOptimize = useCallback(async () => {
    setBusy(true);
    const brandId = genBrandId ? Number(genBrandId) : undefined;
    let ok = false;
    try {
      const robots: any = await generateMutation.mutateAsync({ field: 'robots' });
      const v = String(robots?.value ?? '');
      if (v) { setForm((f) => (f ? { ...f, robotsText: v, robotsEnabled: true } : f)); ok = true; }
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'robots.txt generation failed');
    }
    try {
      const schema: any = await generateMutation.mutateAsync({ field: 'schema', brandId });
      const v = String(schema?.value ?? '');
      if (v) { setForm((f) => (f ? { ...f, schemaJson: v, schemaEnabled: true } : f)); ok = true; }
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Schema generation failed');
    }
    try {
      const title: any = await generateMutation.mutateAsync({ field: 'site_title', brandId });
      const v = String(title?.value ?? '');
      if (v) { setForm((f) => (f ? { ...f, siteTitle: v } : f)); ok = true; }
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Site title generation failed');
    }
    try {
      const tagline: any = await generateMutation.mutateAsync({ field: 'site_tagline', brandId });
      const v = String(tagline?.value ?? '');
      if (v) { setForm((f) => (f ? { ...f, tagline: v } : f)); ok = true; }
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Tagline generation failed');
    }
    if (ok) toast.success('Generated — review and click Save site settings');
    setBusy(false);
  }, [genBrandId, generateMutation]);

  // Generate a single Site-identity field (Site Title / Tagline) from the editable prompt.
  const genSite = useCallback(async (field: 'site_title' | 'site_tagline', key: 'siteTitle' | 'tagline') => {
    setBusy(true);
    try {
      const brandId = genBrandId ? Number(genBrandId) : undefined;
      const res: any = await generateMutation.mutateAsync({ field, brandId });
      const v = String(res?.value ?? '');
      if (v) setForm((f) => (f ? { ...f, [key]: v } : f));
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Generation failed');
    } finally {
      setBusy(false);
    }
  }, [genBrandId, generateMutation]);

  const handleExport = useCallback(async () => {
    try {
      const bundle = await apiFetch<unknown>('seo/export');
      const blob = new Blob([JSON.stringify(bundle, null, 2)], { type: 'application/json' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'power-creatives-seo-config.json';
      a.click();
      URL.revokeObjectURL(url);
      toast.success('SEO config exported');
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Export failed');
    }
  }, []);

  const handleImportFile = useCallback(async (file: File) => {
    try {
      const config = JSON.parse(await file.text());
      const res: any = await apiFetch('seo/import', { method: 'POST', body: JSON.stringify({ config }) });
      toast.success(`Imported ${res?.applied ?? 0} setting(s)`);
      window.location.reload();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Invalid config file');
    }
  }, []);

  useEffect(() => {
    if (data) setForm(data as SiteSettings);
  }, [data]);

  const patch = useCallback(<K extends keyof SiteSettings>(key: K, value: SiteSettings[K]) => {
    setForm((f) => (f ? { ...f, [key]: value } : f));
  }, []);

  const handleSave = useCallback(async () => {
    if (!form) return;
    setBusy(true);
    try {
      await saveMutation.mutateAsync({
        schemaEnabled: form.schemaEnabled,
        schemaJson: form.schemaJson,
        robotsEnabled: form.robotsEnabled,
        robotsText: form.robotsText,
        siteTitle: form.siteTitle,
        tagline: form.tagline,
        ...(form.language ? { language: form.language } : {}),
        ...(form.timezone ? { timezone: form.timezone } : {}),
      });
      toast.success('Site SEO settings saved');
      refetch();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Save failed');
    } finally {
      setBusy(false);
    }
  }, [form, saveMutation, refetch]);

  const handleRestore = useCallback(async (what: 'language' | 'timezone') => {
    setBusy(true);
    try {
      await restoreMutation.mutateAsync({ what });
      toast.success(`Restored ${what} from backup`);
      refetch();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Restore failed');
    } finally {
      setBusy(false);
    }
  }, [restoreMutation, refetch]);

  if (isLoading || !form) {
    return <div className="flex items-center justify-center py-16"><Loader2 className="w-6 h-6 animate-spin text-primary" /></div>;
  }

  return (
    <div className="space-y-6 max-w-2xl">
      {/* One-click optimize */}
      <section className="rounded-xl border border-primary/30 bg-accent/40 p-4 space-y-3">
        <div>
          <Label className="text-sm font-medium">One-click optimize</Label>
          <p className="text-xs text-muted-foreground">
            Generate your Site Title, Tagline, robots.txt, and a LocalBusiness JSON-LD block
            from your prompts. Pick a brand for the business details. Edit how these are
            written in Settings → Templates → SEO.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Select value={genBrandId} onValueChange={setGenBrandId}>
            <SelectTrigger className="w-[230px]" title="Brand for the schema's business details">
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

      {/* Site identity (WP Site Title + Tagline → blogname / blogdescription) */}
      <section className="rounded-xl border border-border bg-card p-4 space-y-3">
        <div>
          <Label className="text-sm font-medium">Site identity</Label>
          <p className="text-xs text-muted-foreground">Your WordPress Site Title &amp; Tagline. Generate from your brand, edit, then Save.</p>
        </div>
        <div className="space-y-1.5">
          <Label className="text-xs text-muted-foreground">Site Title</Label>
          <div className="flex gap-1.5">
            <Input value={form.siteTitle} onChange={(e) => patch('siteTitle', e.target.value)} placeholder="e.g. Acme Plumbing" className="text-xs" />
            <Button variant="outline" size="icon" title="Generate site title" onClick={() => genSite('site_title', 'siteTitle')} disabled={busy}>
              <Sparkles className="w-4 h-4" />
            </Button>
          </div>
        </div>
        <div className="space-y-1.5">
          <Label className="text-xs text-muted-foreground">Tagline</Label>
          <div className="flex gap-1.5">
            <Input value={form.tagline} onChange={(e) => patch('tagline', e.target.value)} placeholder="e.g. Trusted local plumbers since 2008" className="text-xs" />
            <Button variant="outline" size="icon" title="Generate tagline" onClick={() => genSite('site_tagline', 'tagline')} disabled={busy}>
              <Sparkles className="w-4 h-4" />
            </Button>
          </div>
        </div>
      </section>

      {/* robots.txt */}
      <section className="rounded-xl border border-border bg-card p-4 space-y-3">
        <div className="flex items-center justify-between">
          <div>
            <Label className="text-sm font-medium">Custom robots.txt</Label>
            <p className="text-xs text-muted-foreground">Override the site's robots.txt output.</p>
          </div>
          <Switch checked={form.robotsEnabled} onCheckedChange={(v) => patch('robotsEnabled', v)} />
        </div>
        <Textarea
          value={form.robotsText}
          onChange={(e) => patch('robotsText', e.target.value)}
          rows={5}
          className="font-mono text-xs"
          disabled={!form.robotsEnabled}
        />
      </section>

      {/* Site-wide schema */}
      <section className="rounded-xl border border-border bg-card p-4 space-y-3">
        <div className="flex items-center justify-between">
          <div>
            <Label className="text-sm font-medium">Site-wide JSON-LD (LocalBusiness)</Label>
            <p className="text-xs text-muted-foreground">Injected into every page's &lt;head&gt;.</p>
          </div>
          <Switch checked={form.schemaEnabled} onCheckedChange={(v) => patch('schemaEnabled', v)} />
        </div>
        <Textarea
          value={form.schemaJson}
          onChange={(e) => patch('schemaJson', e.target.value)}
          rows={6}
          placeholder='{"@context":"https://schema.org","@type":"LocalBusiness","name":"..."}'
          className="font-mono text-xs"
          disabled={!form.schemaEnabled}
        />
      </section>

      {/* Language + timezone */}
      <section className="rounded-xl border border-border bg-card p-4 space-y-3">
        <Label className="text-sm font-medium">Locale</Label>
        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-1.5">
            <Label className="text-xs text-muted-foreground">Language (WPLANG)</Label>
            <div className="flex gap-1.5">
              <Input value={form.language} onChange={(e) => patch('language', e.target.value)} placeholder="e.g. en_US" className="text-xs" />
              {form.hasLangBackup && (
                <Button variant="ghost" size="icon" title="Restore" onClick={() => handleRestore('language')} disabled={busy}>
                  <Undo2 className="w-4 h-4" />
                </Button>
              )}
            </div>
          </div>
          <div className="space-y-1.5">
            <Label className="text-xs text-muted-foreground">Timezone</Label>
            <div className="flex gap-1.5">
              <Input value={form.timezone} onChange={(e) => patch('timezone', e.target.value)} placeholder="e.g. Europe/Stockholm" className="text-xs" />
              {form.hasTzBackup && (
                <Button variant="ghost" size="icon" title="Restore" onClick={() => handleRestore('timezone')} disabled={busy}>
                  <Undo2 className="w-4 h-4" />
                </Button>
              )}
            </div>
          </div>
        </div>
      </section>

      <Button onClick={handleSave} disabled={busy} className="gap-2">
        {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <Save className="w-4 h-4" />}
        Save site settings
      </Button>

      {/* Export / Import (agency-reusable config) */}
      <section className="rounded-xl border border-border bg-card p-4 space-y-2">
        <Label className="text-sm font-medium">Export / Import config</Label>
        <p className="text-xs text-muted-foreground">Portable JSON of the SEO config (schema, robots, AI-readiness, GBP provider) to replicate across client sites.</p>
        <div className="flex gap-2">
          <Button variant="outline" size="sm" onClick={handleExport} className="gap-1.5"><Download className="w-3.5 h-3.5" /> Export</Button>
          <Button variant="outline" size="sm" onClick={() => fileRef.current?.click()} className="gap-1.5"><Upload className="w-3.5 h-3.5" /> Import</Button>
          <input
            ref={fileRef}
            type="file"
            accept="application/json"
            className="hidden"
            onChange={(e) => { const f = e.target.files?.[0]; if (f) void handleImportFile(f); e.target.value = ''; }}
          />
        </div>
      </section>
    </div>
  );
}
