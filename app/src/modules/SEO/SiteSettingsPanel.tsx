/**
 * SiteSettingsPanel — site-wide SEO settings (Phase 6).
 *
 * Custom robots.txt, a site-wide LocalBusiness JSON-LD block, and
 * language/timezone overrides (with restore-from-backup). Backed by the
 * `seo/site` REST endpoints (admin only).
 */

import { useEffect, useRef, useState, useCallback } from 'react';
import { Loader2, Save, Undo2, Download, Upload } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { trpc, apiFetch } from '@/lib/trpc';

interface SiteSettings {
  schemaEnabled: boolean;
  schemaJson: string;
  robotsEnabled: boolean;
  robotsText: string;
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

  const [form, setForm] = useState<SiteSettings | null>(null);
  const [busy, setBusy] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);

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
      {/* robots.txt */}
      <section className="rounded-xl border border-border p-4 space-y-3">
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
      <section className="rounded-xl border border-border p-4 space-y-3">
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
      <section className="rounded-xl border border-border p-4 space-y-3">
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
      <section className="rounded-xl border border-border p-4 space-y-2">
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
