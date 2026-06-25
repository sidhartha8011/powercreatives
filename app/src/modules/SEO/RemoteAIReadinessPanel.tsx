/**
 * RemoteAIReadinessPanel — manage a CONNECTED site's AI Readiness (llms.txt + per-page .md)
 * via the connector's /pcm-conn/v1/ai route. Mirrors the local AI Readiness panel: build an
 * index from the site's published content (with an AI-generated site description), edit it,
 * toggle serving at /llms.txt, and review the connected site's published pages (each served
 * live at /{slug}.md by the connector). Requires the Power Creatives connector v1.2.0+.
 */

import { useEffect, useMemo, useState, useCallback } from 'react';
import { Loader2, Save, AlertTriangle, Sparkles, ExternalLink, FileText, WandSparkles } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { trpc } from '@/lib/trpc';

interface RemoteAi { llms: string; enabled: boolean; url: string }
interface RemotePost { id: number; title: string; type: string; status: string; mdUrl: string }

/** The model/provider the user picked in the SEO toolbar (shared localStorage key). */
function pickModel(models: any[]): { model?: string; provider?: string } {
  let model = '';
  try { model = localStorage.getItem('pcm:seo:gen-model') ?? ''; } catch { /* ignore */ }
  const provider = (models ?? []).find((m: any) => String(m.modelId) === model)?.provider;
  return { model: model || undefined, provider };
}

export function RemoteAIReadinessPanel({ siteId, siteName }: { siteId: number; siteName: string }) {
  const { data, isLoading, error, refetch } = trpc.seo.remoteAiGet.useQuery(
    { siteId },
    { enabled: siteId != null, retry: false },
  ) as { data?: unknown; isLoading: boolean; error?: unknown; refetch: () => void };
  const { data: postsData } = trpc.seo.remoteAiPosts.useQuery(
    { siteId },
    { enabled: siteId != null, retry: false },
  ) as { data?: { posts?: RemotePost[] } };
  const { data: modelsRaw = [] } = trpc.models.getForGeneration.useQuery({ type: 'text' }, { staleTime: 30_000 }) as { data?: any[] };

  const saveMutation = trpc.seo.remoteAiSave.useMutation();
  const buildMutation = trpc.seo.remoteAiBuild.useMutation();
  const descMutation = trpc.seo.remoteAiSiteDesc.useMutation();

  const [form, setForm] = useState<RemoteAi | null>(null);
  const [desc, setDesc] = useState('');
  const [busy, setBusy] = useState(false);
  const posts = useMemo(() => (Array.isArray(postsData?.posts) ? postsData!.posts! : []), [postsData]);

  useEffect(() => {
    if (data && typeof data === 'object') {
      const d = data as Partial<RemoteAi>;
      setForm({ llms: String(d.llms ?? ''), enabled: !!d.enabled, url: String(d.url ?? '') });
    }
  }, [data]);

  const handleGenDesc = useCallback(async () => {
    setBusy(true);
    try {
      const { model, provider } = pickModel(modelsRaw ?? []);
      const res = (await descMutation.mutateAsync({ siteId, model, provider })) as { description?: string };
      const d = String(res?.description ?? '');
      if (d) setDesc(d);
      toast.success('Site description generated — it’s added on the next Build');
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Generation failed');
    } finally {
      setBusy(false);
    }
  }, [descMutation, siteId, modelsRaw]);

  const handleBuild = useCallback(async () => {
    setBusy(true);
    try {
      const res = (await buildMutation.mutateAsync({ siteId, desc })) as { llms?: string };
      setForm((f) => (f ? { ...f, llms: String(res?.llms ?? '') } : f));
      toast.success('Built from published content — review, then Save');
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Build failed');
    } finally {
      setBusy(false);
    }
  }, [buildMutation, siteId, desc]);

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
      {/* Publish */}
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

      {/* Site description (added to the built llms.txt) */}
      <section className="rounded-xl border border-border bg-card p-4 space-y-2">
        <Label className="text-sm font-medium">Site description</Label>
        <p className="text-xs text-muted-foreground">A one-line summary added to the top of the built llms.txt.</p>
        <div className="flex gap-1.5">
          <Input
            value={desc}
            onChange={(e) => setDesc(e.target.value)}
            placeholder="Short description of this site for AI crawlers…"
            className="text-xs"
          />
          <Button variant="outline" size="icon" title="AI-generate site description" onClick={handleGenDesc} disabled={busy}><WandSparkles className="w-4 h-4" /></Button>
        </div>
      </section>

      {/* llms.txt */}
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

      {/* Per-page list (connector serves each at /{slug}.md) */}
      <div className="rounded-xl border border-border bg-card overflow-hidden">
        <div className="grid grid-cols-[1fr_5rem_3rem] gap-2 bg-muted/60 px-4 py-2 text-xs font-medium text-muted-foreground">
          <span>Page</span><span>Type</span><span className="text-center">.md</span>
        </div>
        {posts.length === 0 ? (
          <div className="px-4 py-6 text-sm text-muted-foreground text-center">No published content found on the connected site.</div>
        ) : (
          posts.map((p) => (
            <div key={`${p.type}-${p.id}`} className="grid grid-cols-[1fr_5rem_3rem] items-center gap-2 px-4 py-2 border-t border-border text-xs">
              <span className="truncate" title={p.title}>{p.title || '(untitled)'}</span>
              <span className="capitalize text-muted-foreground">{p.type}</span>
              <span className="text-center">
                {p.mdUrl
                  ? <a href={p.mdUrl} target="_blank" rel="noopener noreferrer" className="inline-flex text-muted-foreground hover:text-foreground" title="View .md"><FileText className="w-3.5 h-3.5" /></a>
                  : <span className="text-muted-foreground/50">—</span>}
              </span>
            </div>
          ))
        )}
      </div>

      <div className="flex justify-end">
        <Button onClick={handleSave} disabled={busy}>
          {busy ? <Loader2 className="w-4 h-4 mr-2 animate-spin" /> : <Save className="w-4 h-4 mr-2" />}
          Save
        </Button>
      </div>
    </div>
  );
}
