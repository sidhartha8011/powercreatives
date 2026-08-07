/**
 * LlmInfoEditor — the /llm-info/ AI-optimization summary editor (inputs → generate → edit
 * → publish at /llm-info/). `LlmInfoSection` wires it to either the local AI Readiness
 * endpoints or a connected site's (when `siteId` is given). The generated page is
 * keyword-optimized + positively framed (authority, years, local-to-area) — truthfully,
 * from facts you provide. Connected sites need the connector v1.3.0+.
 */

import { useEffect, useState, useCallback, useMemo, type ReactNode } from 'react';
import { Loader2, Save, Sparkles, ExternalLink, AlertTriangle, ScanSearch, FileText } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { trpc } from '@/lib/trpc';

interface ModelOption { id: string; name: string; provider: string }
const GEN_MODEL_KEY = 'pcm:seo:gen-model';

export interface LlmInfoData {
  enabled: boolean;
  keywords: string;
  years: string;
  area: string;
  strengths: string;
  content: string;
  url?: string;
}

interface LlmInfoInputs { keywords: string; years: string; area: string; strengths: string }
interface DetectedKeyword { term: string; count: number }

function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="space-y-1.5">
      <Label className="text-xs text-muted-foreground">{label}</Label>
      {children}
    </div>
  );
}

export function LlmInfoEditor({ data, isLoading, error, onBuild, onSave, onDetectKeywords, models, modelId, onModelChange }: {
  data: LlmInfoData | null;
  isLoading: boolean;
  error?: unknown;
  onBuild: (inputs: LlmInfoInputs) => Promise<string>;
  onSave: (next: LlmInfoData) => Promise<void>;
  onDetectKeywords: () => Promise<{ keywords: string; list: DetectedKeyword[] }>;
  models: ModelOption[];
  modelId: string;
  onModelChange: (id: string) => void;
}) {
  const [form, setForm] = useState<LlmInfoData | null>(null);
  const [detected, setDetected] = useState<DetectedKeyword[]>([]);
  const [detecting, setDetecting] = useState(false);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (data) {
      setForm({
        enabled: !!data.enabled,
        keywords: String(data.keywords ?? ''),
        years: String(data.years ?? ''),
        area: String(data.area ?? ''),
        strengths: String(data.strengths ?? ''),
        content: String(data.content ?? ''),
        url: data.url,
      });
    }
  }, [data]);

  const patch = useCallback((k: keyof LlmInfoData, v: string | boolean) => {
    setForm((f) => (f ? { ...f, [k]: v } : f));
  }, []);

  const handleBuild = useCallback(async () => {
    if (!form) return;
    setBusy(true);
    try {
      const html = await onBuild({ keywords: form.keywords, years: form.years, area: form.area, strengths: form.strengths });
      if (!html.trim()) {
        toast.error('The model returned no text — try again.');
        return;
      }
      setForm((f) => (f ? { ...f, content: html } : f));
      toast.success('Summary generated — review, then Save');
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Generation failed');
    } finally {
      setBusy(false);
    }
  }, [form, onBuild]);

  const handleDetect = useCallback(async () => {
    setDetecting(true);
    try {
      const res = await onDetectKeywords();
      setDetected(Array.isArray(res.list) ? res.list : []);
      if (res.keywords) {
        setForm((f) => (f ? { ...f, keywords: res.keywords } : f));
        toast.success('Top keywords detected from your site');
      } else {
        toast.info('No prominent keywords found — add some content first, or type keywords manually');
      }
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Could not scan site keywords');
    } finally {
      setDetecting(false);
    }
  }, [onDetectKeywords]);

  const handleSave = useCallback(async () => {
    if (!form) return;
    setBusy(true);
    try {
      await onSave(form);
      toast.success('/llm-info/ saved');
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Failed to save');
    } finally {
      setBusy(false);
    }
  }, [form, onSave]);

  if (isLoading) {
    return <div className="flex items-center justify-center py-12"><Loader2 className="w-6 h-6 animate-spin text-primary" /></div>;
  }
  if (error || !form) {
    const msg = error instanceof Error ? error.message : 'Could not load /llm-info/.';
    return (
      <div className="border border-dashed border-border rounded-xl p-8 text-center max-w-2xl">
        <AlertTriangle className="w-7 h-7 mx-auto mb-3 text-muted-foreground/60" />
        <p className="text-sm font-medium">/llm-info/ unavailable</p>
        <p className="mt-1.5 text-xs text-muted-foreground max-w-md mx-auto">{msg}</p>
      </div>
    );
  }

  return (
    <div className="space-y-6 max-w-2xl">
      <div className="flex items-center gap-2">
        <FileText className="w-4 h-4 text-primary" />
        <h3 className="text-base font-semibold">LLM Info <span className="font-normal text-muted-foreground">— AI overview page</span></h3>
      </div>

      <section className="rounded-xl border border-border bg-card p-4 space-y-3">
        <div className="flex items-center justify-between">
          <div>
            <Label className="text-sm font-medium">Serve /llm-info/</Label>
            <p className="text-xs text-muted-foreground">
              Publish an AI-search-optimized overview at <code>/llm-info/</code> for ChatGPT, Perplexity &amp; AI Overviews.
            </p>
          </div>
          <Switch checked={form.enabled} onCheckedChange={(v) => patch('enabled', v)} />
        </div>
        {form.enabled && form.url && (
          <a href={form.url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground">
            <ExternalLink className="w-3.5 h-3.5" /> {form.url}
          </a>
        )}
      </section>

      <section className="rounded-xl border border-border bg-card p-4 space-y-3">
        <div>
          <Label className="text-sm font-medium">Inputs</Label>
          <p className="text-xs text-muted-foreground">Used to frame the summary — only facts you provide are used (no invented claims). Leave <span className="font-medium">Target keywords</span> blank and the summary is optimized for your site's most-used keywords automatically.</p>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Target keywords">
            <div className="flex gap-1.5">
              <Input value={form.keywords} onChange={(e) => patch('keywords', e.target.value)} placeholder="auto-detected from your site" className="text-xs" />
              <Button type="button" variant="outline" size="icon" title="Detect the most-used keywords from your site content" onClick={handleDetect} disabled={detecting || busy} className="shrink-0">
                {detecting ? <Loader2 className="w-4 h-4 animate-spin" /> : <ScanSearch className="w-4 h-4" />}
              </Button>
            </div>
          </Field>
          <Field label="Service area"><Input value={form.area} onChange={(e) => patch('area', e.target.value)} placeholder="Manchester, UK" className="text-xs" /></Field>
          <Field label="Years in business"><Input value={form.years} onChange={(e) => patch('years', e.target.value)} placeholder="since 2008 / 15+ years" className="text-xs" /></Field>
          <Field label="Strengths / recommendations"><Input value={form.strengths} onChange={(e) => patch('strengths', e.target.value)} placeholder="4.9★ Google, Gas Safe registered" className="text-xs" /></Field>
        </div>
        {detected.length > 0 && (
          <div className="space-y-1">
            <span className="text-[11px] text-muted-foreground">Most-used on your site (click to add):</span>
            <div className="flex flex-wrap gap-1">
              {detected.map((k) => (
                <button
                  key={k.term}
                  type="button"
                  onClick={() => patch('keywords', form.keywords.trim() === '' ? k.term : (form.keywords.split(',').map((s) => s.trim()).includes(k.term) ? form.keywords : `${form.keywords.replace(/,\s*$/, '')}, ${k.term}`))}
                  title={`Appears ${k.count}×`}
                  className="rounded-full border border-border bg-muted/50 px-2 py-0.5 text-[11px] text-muted-foreground hover:border-primary hover:text-foreground"
                >
                  {k.term} <span className="text-muted-foreground/60">{k.count}</span>
                </button>
              ))}
            </div>
          </div>
        )}
        <div className="flex items-center gap-2 flex-wrap">
          <Select value={modelId || '__default__'} onValueChange={(v) => onModelChange(v === '__default__' ? '' : v)}>
            <SelectTrigger className="w-auto min-w-[11rem]"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="__default__">Default model</SelectItem>
              {models.map((m) => (
                <SelectItem key={m.id} value={m.id}>
                  {m.name} <span className="text-muted-foreground">({m.provider})</span>
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Button variant="outline" size="sm" onClick={handleBuild} disabled={busy}>
            {busy ? <Loader2 className="w-4 h-4 mr-2 animate-spin" /> : <Sparkles className="w-4 h-4 mr-2" />} Generate summary
          </Button>
        </div>
      </section>

      <section className="rounded-xl border border-border bg-card p-4 space-y-3">
        <div>
          <Label className="text-sm font-medium">Summary (HTML)</Label>
          <p className="text-xs text-muted-foreground">Served at /llm-info/. Edit freely before saving.</p>
        </div>
        <Textarea
          value={form.content}
          onChange={(e) => patch('content', e.target.value)}
          rows={14}
          className="font-mono text-xs"
          placeholder="Click “Generate summary” to draft this from your inputs."
        />
      </section>

      <div className="flex justify-end">
        <Button onClick={handleSave} disabled={busy}>
          {busy ? <Loader2 className="w-4 h-4 mr-2 animate-spin" /> : <Save className="w-4 h-4 mr-2" />} Save
        </Button>
      </div>
    </div>
  );
}

/**
 * Self-contained wiring: local AI Readiness when `siteId` is undefined, otherwise a
 * connected site. Remote stores only the generated HTML (inputs are entered per session);
 * local persists the inputs too.
 */
export function LlmInfoSection({ siteId }: { siteId?: number }) {
  const remote = siteId != null;

  // Model selection — shared with the SEO toolbar picker (same localStorage key), so a
  // model chosen anywhere drives /llm-info/ generation with its provider's key.
  const { data: textModelsRaw = [] } = trpc.models.getForGeneration.useQuery({ type: 'text' }, { staleTime: 30_000 }) as { data?: any[] };
  const models = useMemo<ModelOption[]>(
    () => (textModelsRaw ?? []).map((m: any) => ({ id: String(m.modelId), name: String(m.customName || m.originalName || m.modelId), provider: String(m.provider) })),
    [textModelsRaw],
  );
  const [modelId, setModelId] = useState<string>(() => { try { return localStorage.getItem(GEN_MODEL_KEY) ?? ''; } catch { return ''; } });
  const onModelChange = useCallback((id: string) => { setModelId(id); try { localStorage.setItem(GEN_MODEL_KEY, id); } catch { /* ignore */ } }, []);
  const provider = useMemo(() => models.find((m) => m.id === modelId)?.provider, [models, modelId]);

  const localQ = trpc.seo.llmInfoGet.useQuery(undefined, { enabled: !remote, retry: false }) as { data?: unknown; isLoading: boolean; error?: unknown; refetch: () => void };
  const remoteQ = trpc.seo.remoteLlmInfoGet.useQuery({ siteId: siteId ?? 0 }, { enabled: remote, retry: false }) as { data?: unknown; isLoading: boolean; error?: unknown; refetch: () => void };
  const localBuild = trpc.seo.llmInfoBuild.useMutation();
  const localSave = trpc.seo.llmInfoSave.useMutation();
  const localKeywords = trpc.seo.llmInfoKeywords.useMutation();
  const remoteBuild = trpc.seo.remoteLlmInfoBuild.useMutation();
  const remoteSave = trpc.seo.remoteLlmInfoSave.useMutation();
  const remoteKeywords = trpc.seo.remoteLlmInfoKeywords.useMutation();

  const q = remote ? remoteQ : localQ;
  // MEMOIZED on q.data: this object feeds the editor's hydrate effect. Rebuilding it on
  // every render (e.g. the re-render when the build mutation resolves) gave the effect a
  // fresh reference each time, so it re-hydrated from the stored server data and WIPED
  // unsaved local edits — including a just-generated summary ("generated but not visible").
  const data = useMemo<LlmInfoData | null>(
    () => (q.data && typeof q.data === 'object'
      ? { keywords: '', years: '', area: '', strengths: '', enabled: false, content: '', ...(q.data as Record<string, unknown>) } as LlmInfoData
      : null),
    [q.data],
  );

  const onBuild = useCallback(async (inputs: LlmInfoInputs): Promise<string> => {
    const model = modelId || undefined;
    const res = remote
      ? await remoteBuild.mutateAsync({ siteId, ...inputs, model, provider })
      : await localBuild.mutateAsync({ ...inputs, model, provider });
    return String((res as { content?: string } | undefined)?.content ?? '');
  }, [remote, siteId, remoteBuild, localBuild, modelId, provider]);

  const onDetectKeywords = useCallback(async (): Promise<{ keywords: string; list: DetectedKeyword[] }> => {
    const res = remote
      ? await remoteKeywords.mutateAsync({ siteId })
      : await localKeywords.mutateAsync({});
    const r = (res ?? {}) as { keywords?: string; list?: DetectedKeyword[] };
    return { keywords: String(r.keywords ?? ''), list: Array.isArray(r.list) ? r.list : [] };
  }, [remote, siteId, remoteKeywords, localKeywords]);

  const onSave = useCallback(async (next: LlmInfoData): Promise<void> => {
    if (remote) {
      await remoteSave.mutateAsync({ siteId, content: next.content, enabled: next.enabled });
    } else {
      await localSave.mutateAsync({
        enabled: next.enabled, keywords: next.keywords, years: next.years,
        area: next.area, strengths: next.strengths, content: next.content,
      });
    }
    q.refetch();
  }, [remote, siteId, remoteSave, localSave, q]);

  return <LlmInfoEditor data={data} isLoading={!!q.isLoading} error={q.error} onBuild={onBuild} onSave={onSave} onDetectKeywords={onDetectKeywords} models={models} modelId={modelId} onModelChange={onModelChange} />;
}
