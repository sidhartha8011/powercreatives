/**
 * AIReadinessPanel — manage the site's LLM-facing surface (Optimizer-Simple parity).
 *
 * Settings (post types / max pages / site description + AI-generate), Build per-page Markdown +
 * llms.txt / llms-full.txt, publish the virtual routes (/llms.txt, /{slug}.md), per-post
 * Generate (.md) + Summarize (AI) with word counts, an editable llms.txt, per-page exclude
 * toggles, and a full reset. Backed by the `seo/ai-readiness` REST endpoints (admin-only).
 */

import { useEffect, useMemo, useState, useCallback } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { Loader2, ExternalLink, RefreshCw, FileText, Save, WandSparkles, Eye, EyeOff, Trash2 } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import { Textarea } from '@/components/ui/textarea';
import { trpc } from '@/lib/trpc';

interface AirPost { id: number; title: string; type: string; status: string; mdUrl: string; wordCount: number; hasSummary: boolean; summary: string; generated: string; excluded: boolean }
interface AirSettings { max_posts: number; site_description: string; post_types: string[]; excluded_ids: number[] }
interface AirStatus { published: boolean; settings: AirSettings; llmsUrl: string; llmsFullUrl: string; llmsTxt: string; posts: AirPost[] }

const STATUS_STYLES: Record<string, string> = {
  ready: 'bg-green-100 text-green-800',
  stale: 'bg-amber-100 text-amber-800',
  none: 'bg-muted text-muted-foreground',
};
const POST_TYPE_OPTIONS = ['post', 'page'];
const AIR_KEY = ['seo', 'airStatus'] as const;

/** The model/provider the user picked in the SEO toolbar (shared localStorage key). */
function pickModel(models: any[]): { model?: string; provider?: string } {
  let model = '';
  try { model = localStorage.getItem('pcm:seo:gen-model') ?? ''; } catch { /* ignore */ }
  const provider = (models ?? []).find((m: any) => String(m.modelId) === model)?.provider;
  return { model: model || undefined, provider };
}

export function AIReadinessPanel() {
  const queryClient = useQueryClient();
  const { data, isLoading } = trpc.seo.airStatus.useQuery() as { data?: unknown; isLoading: boolean };
  const status = useMemo<AirStatus | null>(() => (data ? (data as AirStatus) : null), [data]);
  const { data: modelsRaw = [] } = trpc.models.getForGeneration.useQuery({ type: 'text' }, { staleTime: 30_000 }) as { data?: any[] };

  const buildMutation = trpc.seo.airBuild.useMutation();
  const publishMutation = trpc.seo.airPublish.useMutation();
  const generateMutation = trpc.seo.airGenerate.useMutation();
  const summarizeMutation = trpc.seo.airSummarize.useMutation();
  const settingsMutation = trpc.seo.airSettings.useMutation();
  const saveLlmsMutation = trpc.seo.airSaveLlms.useMutation();
  const genSiteDescMutation = trpc.seo.airGenSiteDesc.useMutation();
  const deleteAllMutation = trpc.seo.airDeleteAll.useMutation();
  const [busy, setBusy] = useState(false);
  const [rowBusy, setRowBusy] = useState<string | null>(null);

  const [settings, setSettings] = useState<AirSettings | null>(null);
  const [llmsDraft, setLlmsDraft] = useState('');
  useEffect(() => { if (status) { setSettings(status.settings); setLlmsDraft(status.llmsTxt ?? ''); } }, [status]);

  const refresh = useCallback(() => queryClient.invalidateQueries({ queryKey: AIR_KEY }), [queryClient]);

  const handleBuild = useCallback(async () => {
    setBusy(true);
    try {
      const res: any = await buildMutation.mutateAsync({});
      toast.success(`Built llms.txt from ${res?.posts ?? 0} page(s)`);
      await refresh();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Build failed');
    } finally { setBusy(false); }
  }, [buildMutation, refresh]);

  const handlePublish = useCallback(async (next: boolean) => {
    setBusy(true);
    try {
      await publishMutation.mutateAsync({ published: next });
      toast.success(next ? 'Virtual routes published' : 'Virtual routes unpublished');
      await refresh();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Failed to update');
    } finally { setBusy(false); }
  }, [publishMutation, refresh]);

  const handleSaveSettings = useCallback(async () => {
    if (!settings) return;
    setBusy(true);
    try {
      await settingsMutation.mutateAsync(settings);
      toast.success('AI Readiness settings saved');
      await refresh();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Failed to save settings');
    } finally { setBusy(false); }
  }, [settings, settingsMutation, refresh]);

  const handleGenSiteDesc = useCallback(async () => {
    setBusy(true);
    try {
      const { model, provider } = pickModel(modelsRaw ?? []);
      const res: any = await genSiteDescMutation.mutateAsync({ model, provider });
      const d = String(res?.description ?? '');
      if (d) setSettings((s) => (s ? { ...s, site_description: d } : s));
      toast.success('Site description generated — Save settings to keep it');
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Generation failed');
    } finally { setBusy(false); }
  }, [genSiteDescMutation, modelsRaw]);

  const handleSaveLlms = useCallback(async () => {
    setBusy(true);
    try {
      await saveLlmsMutation.mutateAsync({ llms: llmsDraft });
      toast.success('llms.txt saved');
      await refresh();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Failed to save');
    } finally { setBusy(false); }
  }, [saveLlmsMutation, llmsDraft, refresh]);

  const handleDeleteAll = useCallback(async () => {
    if (!window.confirm('Reset all AI Readiness data — llms files, per-page Markdown, summaries and the site description? This cannot be undone.')) return;
    setBusy(true);
    try {
      await deleteAllMutation.mutateAsync({});
      toast.success('AI Readiness reset');
      await refresh();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Reset failed');
    } finally { setBusy(false); }
  }, [deleteAllMutation, refresh]);

  const handleGenerateOne = useCallback(async (id: number) => {
    setRowBusy(`${id}:gen`);
    try {
      await generateMutation.mutateAsync({ id });
      await refresh();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Generation failed');
    } finally { setRowBusy(null); }
  }, [generateMutation, refresh]);

  const handleSummarizeOne = useCallback(async (id: number) => {
    setRowBusy(`${id}:sum`);
    try {
      const { model, provider } = pickModel(modelsRaw ?? []);
      await summarizeMutation.mutateAsync({ id, model, provider });
      toast.success('Summary generated');
      await refresh();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Summarize failed');
    } finally { setRowBusy(null); }
  }, [summarizeMutation, refresh, modelsRaw]);

  if (isLoading) {
    return <div className="flex items-center justify-center py-16"><Loader2 className="w-6 h-6 animate-spin text-primary" /></div>;
  }
  if (!status || !settings) {
    return <div className="text-sm text-muted-foreground py-8">Couldn't load AI Readiness status.</div>;
  }

  const counts = status.posts.reduce(
    (acc, p) => { acc[p.status] = (acc[p.status] ?? 0) + 1; return acc; },
    {} as Record<string, number>,
  );
  const excludedIds = settings.excluded_ids ?? [];
  const toggleType = (t: string) => setSettings((s) => (s ? { ...s, post_types: s.post_types.includes(t) ? s.post_types.filter((x) => x !== t) : [...s.post_types, t] } : s));
  const toggleExclude = (id: number) => setSettings((s) => {
    if (!s) return s;
    const ex = s.excluded_ids ?? [];
    return { ...s, excluded_ids: ex.includes(id) ? ex.filter((x) => x !== id) : [...ex, id] };
  });

  return (
    <div className="space-y-6 max-w-3xl">
      {/* Settings */}
      <section className="rounded-xl border border-border bg-card p-4 space-y-3">
        <Label className="text-sm font-medium">Settings</Label>
        <div className="flex flex-wrap items-center gap-4">
          <div className="flex items-center gap-3">
            <span className="text-xs text-muted-foreground">Include:</span>
            {POST_TYPE_OPTIONS.map((t) => (
              <label key={t} className="flex items-center gap-1.5 text-xs capitalize cursor-pointer">
                <Checkbox checked={settings.post_types.includes(t)} onCheckedChange={() => toggleType(t)} /> {t}s
              </label>
            ))}
          </div>
          <div className="flex items-center gap-1.5">
            <span className="text-xs text-muted-foreground">Max pages</span>
            <Input
              type="number"
              value={settings.max_posts}
              onChange={(e) => setSettings((s) => (s ? { ...s, max_posts: Math.max(1, Math.min(200, Number(e.target.value) || 1)) } : s))}
              className="h-8 w-20 text-xs"
            />
          </div>
        </div>
        <div className="space-y-1.5">
          <span className="text-xs text-muted-foreground">Site description (used in llms.txt; falls back to the WP tagline)</span>
          <div className="flex gap-1.5">
            <Input
              value={settings.site_description}
              onChange={(e) => setSettings((s) => (s ? { ...s, site_description: e.target.value } : s))}
              placeholder="Short description of this site for AI crawlers…"
              className="text-xs"
            />
            <Button variant="outline" size="icon" title="AI-generate site description" onClick={handleGenSiteDesc} disabled={busy}><WandSparkles className="w-4 h-4" /></Button>
          </div>
        </div>
        <Button onClick={handleSaveSettings} disabled={busy} size="sm" className="gap-1.5">
          {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <Save className="w-4 h-4" />} Save settings
        </Button>
      </section>

      {/* Publish + build controls */}
      <section className="rounded-xl border border-border bg-card p-4 space-y-4">
        <div className="flex items-center justify-between gap-4">
          <div>
            <Label className="text-sm font-medium">Publish virtual routes</Label>
            <p className="text-xs text-muted-foreground">
              Serves <code>/llms.txt</code>, <code>/llms-full.txt</code>, and <code>/&#123;slug&#125;.md</code> to LLM crawlers.
            </p>
          </div>
          <Switch checked={status.published} disabled={busy} onCheckedChange={handlePublish} />
        </div>
        <div className="flex items-center gap-2 flex-wrap">
          <Button onClick={handleBuild} disabled={busy} className="gap-2">
            {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <RefreshCw className="w-4 h-4" />}
            Build / Regenerate
          </Button>
          {status.published && (
            <>
              <Button asChild variant="outline" size="sm" className="gap-1.5">
                <a href={status.llmsUrl} target="_blank" rel="noopener noreferrer"><ExternalLink className="w-3.5 h-3.5" /> llms.txt</a>
              </Button>
              <Button asChild variant="outline" size="sm" className="gap-1.5">
                <a href={status.llmsFullUrl} target="_blank" rel="noopener noreferrer"><ExternalLink className="w-3.5 h-3.5" /> llms-full.txt</a>
              </Button>
            </>
          )}
        </div>
        <div className="flex items-center justify-between gap-3">
          <div className="flex gap-3 text-xs text-muted-foreground">
            <span><span className="font-medium text-foreground">{counts.ready ?? 0}</span> ready</span>
            <span><span className="font-medium text-amber-700">{counts.stale ?? 0}</span> stale</span>
            <span><span className="font-medium">{counts.none ?? 0}</span> not generated</span>
          </div>
          <Button variant="ghost" size="sm" onClick={handleDeleteAll} disabled={busy} className="gap-1.5 text-destructive hover:text-destructive">
            <Trash2 className="w-3.5 h-3.5" /> Reset
          </Button>
        </div>
      </section>

      {/* Editable llms.txt */}
      <section className="rounded-xl border border-border bg-card p-4 space-y-3">
        <div className="flex items-center justify-between gap-3">
          <div>
            <Label className="text-sm font-medium">llms.txt</Label>
            <p className="text-xs text-muted-foreground">The generated index — edit freely, then Save.</p>
          </div>
          <Button onClick={handleSaveLlms} disabled={busy} size="sm" variant="outline" className="gap-1.5">
            {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <Save className="w-4 h-4" />} Save llms.txt
          </Button>
        </div>
        <Textarea
          value={llmsDraft}
          onChange={(e) => setLlmsDraft(e.target.value)}
          rows={12}
          className="font-mono text-xs"
          placeholder={'# Site name\n\n## Pages\n- [Title](https://…/page.md): summary'}
        />
      </section>

      {/* Per-post status + per-post Generate / Summarize / exclude */}
      <div className="rounded-xl border border-border bg-card overflow-hidden">
        <div className="grid grid-cols-[1fr_3.5rem_5rem_7rem] gap-2 bg-muted/60 px-4 py-2 text-xs font-medium text-muted-foreground">
          <span>Page</span><span className="text-right">Words</span><span>Status</span><span className="text-center">Actions</span>
        </div>
        {status.posts.length === 0 ? (
          <div className="px-4 py-6 text-sm text-muted-foreground text-center">No published content in the configured types.</div>
        ) : (
          status.posts.map((p) => {
            const excluded = excludedIds.includes(p.id);
            return (
              <div key={p.id} className={`grid grid-cols-[1fr_3.5rem_5rem_7rem] items-center gap-2 px-4 py-2 border-t border-border text-xs ${excluded ? 'opacity-45' : ''}`}>
                <div className="min-w-0">
                  <div className="truncate" title={p.title}>{p.title || '(untitled)'}</div>
                  {p.summary && <div className="truncate text-muted-foreground/80" title={p.summary}>{p.summary}</div>}
                </div>
                <span className="text-right tabular-nums text-muted-foreground">{p.wordCount || '—'}</span>
                <span><span className={`inline-block rounded px-1.5 py-0.5 capitalize ${STATUS_STYLES[p.status] ?? STATUS_STYLES.none}`}>{p.status}</span></span>
                <div className="flex items-center justify-center gap-1.5">
                  <button type="button" onClick={() => toggleExclude(p.id)} disabled={busy} title={excluded ? 'Excluded — click to include (Save settings)' : 'Exclude from llms.txt (Save settings)'} className="text-muted-foreground hover:text-foreground disabled:opacity-50">
                    {excluded ? <EyeOff className="w-3.5 h-3.5" /> : <Eye className="w-3.5 h-3.5" />}
                  </button>
                  <button type="button" onClick={() => handleGenerateOne(p.id)} disabled={rowBusy !== null} title="Generate Markdown" className="text-muted-foreground hover:text-primary disabled:opacity-50">
                    {rowBusy === `${p.id}:gen` ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <RefreshCw className="w-3.5 h-3.5" />}
                  </button>
                  <button type="button" onClick={() => handleSummarizeOne(p.id)} disabled={rowBusy !== null} title="AI summarize" className="text-muted-foreground hover:text-primary disabled:opacity-50">
                    {rowBusy === `${p.id}:sum` ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <WandSparkles className="w-3.5 h-3.5" />}
                  </button>
                  <a href={p.mdUrl} target="_blank" rel="noopener noreferrer" className="inline-flex text-muted-foreground hover:text-foreground" title="View .md">
                    <FileText className="w-3.5 h-3.5" />
                  </a>
                </div>
              </div>
            );
          })
        )}
      </div>
    </div>
  );
}
