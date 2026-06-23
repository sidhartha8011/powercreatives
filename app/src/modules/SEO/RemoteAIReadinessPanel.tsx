/**
 * RemoteAIReadinessPanel — manage a CONNECTED site's AI Readiness (llms.txt) via
 * the connector's /pcm-conn/v1/ai route: build an index from the site's published
 * content, edit it, and toggle serving at /llms.txt. Requires the Power Creatives
 * connector v1.2.0+ on the remote site (re-download from Add Site if older).
 */

import { useEffect, useState, useCallback } from 'react';
import { Loader2, Save, AlertTriangle, Sparkles, ExternalLink } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { trpc } from '@/lib/trpc';
import { LlmInfoSection } from './LlmInfoEditor';

interface RemoteAi {
  llms: string;
  enabled: boolean;
  url: string;
}

export function RemoteAIReadinessPanel({ siteId, siteName }: { siteId: number; siteName: string }) {
  const { data, isLoading, error, refetch } = trpc.seo.remoteAiGet.useQuery(
    { siteId },
    { enabled: siteId != null, retry: false },
  ) as { data?: unknown; isLoading: boolean; error?: unknown; refetch: () => void };
  const saveMutation = trpc.seo.remoteAiSave.useMutation();
  const buildMutation = trpc.seo.remoteAiBuild.useMutation();

  const [form, setForm] = useState<RemoteAi | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (data && typeof data === 'object') {
      const d = data as Partial<RemoteAi>;
      setForm({ llms: String(d.llms ?? ''), enabled: !!d.enabled, url: String(d.url ?? '') });
    }
  }, [data]);

  const handleBuild = useCallback(async () => {
    setBusy(true);
    try {
      const res = (await buildMutation.mutateAsync({ siteId })) as { llms?: string };
      setForm((f) => (f ? { ...f, llms: String(res?.llms ?? '') } : f));
      toast.success('Built from published content — review, then Save');
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Build failed');
    } finally {
      setBusy(false);
    }
  }, [buildMutation, siteId]);

  const handleSave = useCallback(async () => {
    if (!form) return;
    setBusy(true);
    try {
      await saveMutation.mutateAsync({ siteId, llms: form.llms, enabled: form.enabled });
      toast.success(`AI Readiness saved to ${siteName}`);
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
      : 'Could not load AI Readiness. Make sure the latest connector is installed on the remote site.';
    return (
      <div className="border border-dashed border-border rounded-xl p-8 text-center max-w-2xl">
        <AlertTriangle className="w-7 h-7 mx-auto mb-3 text-muted-foreground/60" />
        <p className="text-sm font-medium">
          AI Readiness unavailable for <span className="text-foreground">{siteName}</span>
        </p>
        <p className="mt-1.5 text-xs text-muted-foreground max-w-md mx-auto">{msg}</p>
      </div>
    );
  }

  return (
    <div className="space-y-6 max-w-2xl">
      <section className="rounded-xl border border-border bg-card p-4 space-y-3">
        <div className="flex items-center justify-between">
          <div>
            <Label className="text-sm font-medium">Serve /llms.txt + per-page .md</Label>
            <p className="text-xs text-muted-foreground">
              Publish an LLM-facing index at <code>/llms.txt</code> and per-page Markdown at{' '}
              <code>/{'{slug}'}.md</code> on the connected site.
            </p>
          </div>
          <Switch checked={form.enabled} onCheckedChange={(v) => setForm((f) => (f ? { ...f, enabled: v } : f))} />
        </div>
        {form.enabled && form.url !== '' && (
          <a
            href={form.url}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
          >
            <ExternalLink className="w-3.5 h-3.5" /> {form.url}
          </a>
        )}
      </section>

      <section className="rounded-xl border border-border bg-card p-4 space-y-3">
        <div className="flex items-center justify-between gap-3">
          <div>
            <Label className="text-sm font-medium">llms.txt</Label>
            <p className="text-xs text-muted-foreground">
              Markdown index of the site’s content. Build from published posts/pages, then edit freely.
            </p>
          </div>
          <Button variant="outline" size="sm" onClick={handleBuild} disabled={busy} className="shrink-0">
            {busy ? <Loader2 className="w-4 h-4 mr-2 animate-spin" /> : <Sparkles className="w-4 h-4 mr-2" />}
            Build from content
          </Button>
        </div>
        <Textarea
          value={form.llms}
          onChange={(e) => setForm((f) => (f ? { ...f, llms: e.target.value } : f))}
          rows={14}
          className="font-mono text-xs"
          placeholder={'# Site name\n\n## Pages\n- [Title](https://...)'}
        />
      </section>

      <div className="flex justify-end">
        <Button onClick={handleSave} disabled={busy}>
          {busy ? <Loader2 className="w-4 h-4 mr-2 animate-spin" /> : <Save className="w-4 h-4 mr-2" />}
          Save
        </Button>
      </div>

      <div className="pt-4 border-t border-border">
        <h3 className="text-sm font-semibold">AI Search Optimization</h3>
        <p className="text-xs text-muted-foreground mb-4">A persuasive, keyword-optimized overview served at <code>/llm-info/</code>.</p>
        <LlmInfoSection siteId={siteId} />
      </div>
    </div>
  );
}
