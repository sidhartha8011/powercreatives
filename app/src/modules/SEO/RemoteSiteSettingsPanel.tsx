/**
 * RemoteSiteSettingsPanel — manage a CONNECTED site's hub-managed Site SEO
 * (custom robots.txt rules + a site-wide JSON-LD block) through the connector's
 * /pcm-conn/v1/site route. Requires the Power Creatives connector v1.2.0+ on the
 * remote site (re-download from Add Site if older).
 */

import { useEffect, useState, useCallback } from 'react';
import { Loader2, Save, AlertTriangle } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { trpc } from '@/lib/trpc';

interface RemoteSite {
  robots: string;
  jsonld: string;
}

export function RemoteSiteSettingsPanel({ siteId, siteName }: { siteId: number; siteName: string }) {
  const { data, isLoading, error, refetch } = trpc.seo.remoteSiteGet.useQuery(
    { siteId },
    { enabled: siteId != null, retry: false },
  ) as { data?: unknown; isLoading: boolean; error?: unknown; refetch: () => void };
  const saveMutation = trpc.seo.remoteSiteSave.useMutation();

  const [form, setForm] = useState<RemoteSite | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (data && typeof data === 'object') {
      const d = data as Partial<RemoteSite>;
      setForm({ robots: String(d.robots ?? ''), jsonld: String(d.jsonld ?? '') });
    }
  }, [data]);

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
      await saveMutation.mutateAsync({ siteId, robots: form.robots, jsonld: form.jsonld });
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
      <p className="text-xs text-muted-foreground">
        These apply to <span className="font-medium text-foreground">{siteName}</span> through the connector
        (requires connector v1.2.0+).
      </p>

      <section className="rounded-xl border border-border bg-card p-4 space-y-3">
        <div>
          <Label className="text-sm font-medium">Custom robots.txt rules</Label>
          <p className="text-xs text-muted-foreground">Appended to the connected site’s robots.txt output.</p>
        </div>
        <Textarea
          value={form.robots}
          onChange={(e) => setForm((f) => (f ? { ...f, robots: e.target.value } : f))}
          rows={5}
          className="font-mono text-xs"
          placeholder={'User-agent: *\nDisallow: /private/'}
        />
      </section>

      <section className="rounded-xl border border-border bg-card p-4 space-y-3">
        <div>
          <Label className="text-sm font-medium">Site-wide JSON-LD</Label>
          <p className="text-xs text-muted-foreground">
            Injected into every page’s &lt;head&gt; on the connected site. Must be valid JSON.
          </p>
        </div>
        <Textarea
          value={form.jsonld}
          onChange={(e) => setForm((f) => (f ? { ...f, jsonld: e.target.value } : f))}
          rows={6}
          placeholder='{"@context":"https://schema.org","@type":"LocalBusiness","name":"..."}'
          className="font-mono text-xs"
        />
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
