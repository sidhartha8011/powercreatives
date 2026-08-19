/**
 * AiReadinessStudio — the SEO → AI Readiness tab, for THIS site and for every connected
 * site alike (owner card 17, 2026-08-10: "it is not useable. It is too complex and I do
 * not understand it. Remake it properly").
 *
 * One idea, three files, each with the same two buttons:
 *
 *   1. /llm-info/  — the AI overview page: a keyword-optimised, positively framed (only
 *                    from facts), authoritative + local summary of the business.   Generate · Publish
 *   2. llms.txt    — the index of the site's pages for AI crawlers, with one Markdown
 *                    rendition per page at /{slug}.md served alongside it.           Generate · Publish
 *   3. robots.txt  — must let the AI crawlers in (GPTBot, ClaudeBot, PerplexityBot…).  Check
 *
 * "Generate" needs NO input — inputs are optional details behind a disclosure, and the
 * first generation publishes the file (the owner's spec: "It should be created at
 * /llm-info/"). "Check live" fetches every file from the PUBLIC url exactly as a crawler
 * would and says in plain words what came back (live / not served / blocked by the
 * site's bot wall / robots.txt blocks AI) — the honest answer to "check why it is not
 * creating any files".
 *
 * The prompts behind every AI step are Templates (module=seo, "AI Readiness — …") and are
 * pickable here; the text model is the shared SEO pick.
 */

import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';
import {
  Loader2, Sparkles, ExternalLink, ChevronDown, ChevronRight, ScanSearch, Save, FileText, RefreshCw,
  WandSparkles, ShieldCheck, CheckCircle2, CircleDashed, AlertTriangle, XCircle, Radar, Eye, EyeOff, Trash2,
} from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { trpc } from '@/lib/trpc';

// ───────────────────────────── shared bits ─────────────────────────────

const GEN_MODEL_KEY = 'pcm:seo:gen-model';
type TplOption = { id: number; name: string; isDefault?: boolean };
type ModelOption = { id: string; name: string; provider: string };

/** One page's row in the llms.txt list (local rows carry .md state; remote rows are links). */
interface PageRow { id: number; title: string; type: string; mdUrl: string; status?: string; wordCount?: number; hasSummary?: boolean; summary?: string; excluded?: boolean }
export interface VerifyFile { key: string; label: string; url: string; status: number; ok: boolean; state: 'live' | 'missing' | 'blocked' | 'warn' | 'error' | 'unreachable' | string; note: string }

export function errMsg(e: unknown, fallback: string): string {
  if (e instanceof Error && e.message) return e.message;
  if (e && typeof e === 'object' && typeof (e as any).message === 'string') return String((e as any).message);
  return fallback;
}

/** Templates (module=seo) whose type is exactly this section, default first. */
export function templatesForSection(all: any[], section: string): TplOption[] {
  return (Array.isArray(all) ? all : [])
    .filter((t: any) => typeof t?.type === 'string' && t.type === section)
    .map((t: any) => ({ id: Number(t.id), name: String(t.name), isDefault: !!t.isDefault }))
    .sort((a, b) => Number(b.isDefault) - Number(a.isDefault) || a.name.localeCompare(b.name));
}

function TemplatePick({ options, value, onChange, label = 'Template' }: { options: TplOption[]; value: number | undefined; onChange: (v: number | undefined) => void; label?: string }) {
  return (
    <div className="flex items-center gap-1.5">
      <span className="text-[11px] text-muted-foreground">{label}</span>
      <Select value={value ? String(value) : '__default__'} onValueChange={(v) => onChange(v === '__default__' ? undefined : Number(v))}>
        <SelectTrigger className="w-auto min-w-[9rem]"><SelectValue /></SelectTrigger>
        <SelectContent>
          <SelectItem value="__default__">Default template</SelectItem>
          {options.map((t) => (
            <SelectItem key={t.id} value={String(t.id)}>{t.name}{t.isDefault ? ' (default)' : ''}</SelectItem>
          ))}
        </SelectContent>
      </Select>
    </div>
  );
}

function Disclosure({ title, children, defaultOpen = false, right }: { title: ReactNode; children: ReactNode; defaultOpen?: boolean; right?: ReactNode }) {
  const [open, setOpen] = useState(defaultOpen);
  return (
    <div className="rounded-lg border border-border/70">
      <div className="flex items-center justify-between gap-2 px-3 py-2">
        <button type="button" onClick={() => setOpen((o) => !o)} className="inline-flex items-center gap-1.5 text-xs font-medium text-foreground/90 hover:text-foreground" aria-expanded={open}>
          {open ? <ChevronDown className="h-3.5 w-3.5" /> : <ChevronRight className="h-3.5 w-3.5" />}
          {title}
        </button>
        {right}
      </div>
      {open && <div className="border-t border-border/70 px-3 py-3 space-y-3">{children}</div>}
    </div>
  );
}

/** The verdict pill for a file: live / not served / blocked / warn. */
function StatePill({ state, label }: { state: VerifyFile['state'] | 'on' | 'off' | 'unknown'; label: string }) {
  const map: Record<string, { cls: string; Icon: typeof CheckCircle2 }> = {
    live:        { cls: 'bg-emerald-50 text-emerald-800 border-emerald-200', Icon: CheckCircle2 },
    on:          { cls: 'bg-emerald-50 text-emerald-800 border-emerald-200', Icon: CheckCircle2 },
    warn:        { cls: 'bg-amber-50 text-amber-800 border-amber-200',       Icon: AlertTriangle },
    blocked:     { cls: 'bg-red-50 text-red-800 border-red-200',             Icon: XCircle },
    error:       { cls: 'bg-red-50 text-red-800 border-red-200',             Icon: XCircle },
    unreachable: { cls: 'bg-red-50 text-red-800 border-red-200',             Icon: XCircle },
    missing:     { cls: 'bg-muted text-muted-foreground border-border',      Icon: CircleDashed },
    off:         { cls: 'bg-muted text-muted-foreground border-border',      Icon: CircleDashed },
    unknown:     { cls: 'bg-muted text-muted-foreground border-border',      Icon: CircleDashed },
  };
  const { cls, Icon } = map[state] ?? map.unknown;
  return (
    <span className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-medium ${cls}`}>
      <Icon className="h-3 w-3" /> {label}
    </span>
  );
}

/** Plain-words status for a file from the live check, else from what we know locally. */
export function fileVerdict(check: VerifyFile | undefined, published: boolean, hasContent: boolean): { state: string; label: string } {
  if (check) {
    if (check.state === 'live') return { state: 'live', label: 'Live' };
    if (check.state === 'blocked') return { state: 'blocked', label: 'Blocked' };
    if (check.state === 'missing') return { state: 'missing', label: 'Not served' };
    if (check.state === 'unreachable') return { state: 'unreachable', label: 'Unreachable' };
    if (check.state === 'error') return { state: 'error', label: `Error ${check.status}` };
    return { state: 'warn', label: 'Check' };
  }
  if (!hasContent) return { state: 'off', label: 'Not generated' };
  return published ? { state: 'on', label: 'Published' } : { state: 'off', label: 'Not published' };
}

function InlineError({ text }: { text: string | null }) {
  if (!text) return null;
  return (
    <div className="flex items-start gap-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800">
      <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
      <span className="break-words">{text}</span>
    </div>
  );
}

// ───────────────────────────── the studio ─────────────────────────────

export function AiReadinessStudio({ siteId, siteName }: { siteId?: number; siteName: string }) {
  const remote = siteId != null;

  // Shared pick: text model (same key the SEO toolbar uses) + SEO templates.
  const { data: textModelsRaw = [] } = trpc.models.getForGeneration.useQuery({ type: 'text' }, { staleTime: 30_000 }) as { data?: any[] };
  const models = useMemo<ModelOption[]>(
    () => (textModelsRaw ?? []).map((m: any) => ({ id: String(m.modelId), name: String(m.customName || m.originalName || m.modelId), provider: String(m.provider) })),
    [textModelsRaw],
  );
  const [modelId, setModelId] = useState<string>(() => { try { return localStorage.getItem(GEN_MODEL_KEY) ?? ''; } catch { return ''; } });
  const onModelChange = useCallback((id: string) => { setModelId(id); try { localStorage.setItem(GEN_MODEL_KEY, id); } catch { /* ignore */ } }, []);
  const provider = useMemo(() => models.find((m) => m.id === modelId)?.provider, [models, modelId]);
  const { data: seoTemplatesRaw } = trpc.templates.list.useQuery({ module: 'seo' }, { staleTime: 30_000 }) as { data?: any[] };
  const tplLlmInfo = useMemo(() => templatesForSection(seoTemplatesRaw ?? [], 'llm_info_page_generate'), [seoTemplatesRaw]);
  const tplSiteDesc = useMemo(() => templatesForSection(seoTemplatesRaw ?? [], 'site_ai_description_generate'), [seoTemplatesRaw]);
  const tplPageSummary = useMemo(() => templatesForSection(seoTemplatesRaw ?? [], 'air_page_summary_generate'), [seoTemplatesRaw]);
  const [llmInfoTpl, setLlmInfoTpl] = useState<number | undefined>(undefined);
  const [siteDescTpl, setSiteDescTpl] = useState<number | undefined>(undefined);
  const [pageSummaryTpl, setPageSummaryTpl] = useState<number | undefined>(undefined);

  // ── Live check ──
  const airVerify = trpc.seo.airVerify.useMutation();
  const remoteVerify = trpc.seo.remoteAiVerify.useMutation();
  const [checks, setChecks] = useState<VerifyFile[] | null>(null);
  const [checkedAt, setCheckedAt] = useState<string>('');
  const [checking, setChecking] = useState(false);
  const runCheck = useCallback(async (silent = false) => {
    setChecking(true);
    try {
      const res = (remote ? await remoteVerify.mutateAsync({ siteId }) : await airVerify.mutateAsync({})) as { files?: VerifyFile[]; checkedAt?: string };
      setChecks(Array.isArray(res?.files) ? res.files : []);
      setCheckedAt(String(res?.checkedAt ?? ''));
      if (!silent) toast.success('Checked the live files');
    } catch (e) {
      if (!silent) toast.error(errMsg(e, 'Could not check the live files'));
    } finally {
      setChecking(false);
    }
  }, [remote, siteId, remoteVerify, airVerify]);
  const checkFor = useCallback((key: string) => checks?.find((c) => c.key === key), [checks]);

  // ── /llm-info/ ──
  const localLlmQ = trpc.seo.llmInfoGet.useQuery(undefined, { enabled: !remote, retry: false }) as { data?: any; isLoading: boolean; error?: unknown; refetch: () => void };
  const remoteLlmQ = trpc.seo.remoteLlmInfoGet.useQuery({ siteId: siteId ?? 0 }, { enabled: remote, retry: false }) as { data?: any; isLoading: boolean; error?: unknown; refetch: () => void };
  const llmQ = remote ? remoteLlmQ : localLlmQ;
  const localLlmBuild = trpc.seo.llmInfoBuild.useMutation();
  const localLlmSave = trpc.seo.llmInfoSave.useMutation();
  const localLlmKeywords = trpc.seo.llmInfoKeywords.useMutation();
  const remoteLlmBuild = trpc.seo.remoteLlmInfoBuild.useMutation();
  const remoteLlmSave = trpc.seo.remoteLlmInfoSave.useMutation();
  const remoteLlmKeywords = trpc.seo.remoteLlmInfoKeywords.useMutation();

  const [li, setLi] = useState<{ enabled: boolean; keywords: string; years: string; area: string; strengths: string; content: string; url: string }>({ enabled: false, keywords: '', years: '', area: '', strengths: '', content: '', url: '' });
  const [liDirty, setLiDirty] = useState(false);
  const [liBusy, setLiBusy] = useState<'' | 'generate' | 'save' | 'detect'>('');
  const [liError, setLiError] = useState<string | null>(null);
  const [liEditHtml, setLiEditHtml] = useState(false);
  const [detected, setDetected] = useState<{ term: string; count: number }[]>([]);
  // Hydrate from the server ONCE per server payload — never over unsaved edits.
  useEffect(() => {
    const d = llmQ.data;
    if (d && typeof d === 'object' && !liDirty) {
      setLi({
        enabled: !!d.enabled, keywords: String(d.keywords ?? ''), years: String(d.years ?? ''), area: String(d.area ?? ''),
        strengths: String(d.strengths ?? ''), content: String(d.content ?? ''), url: String(d.url ?? ''),
      });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [llmQ.data]);
  const patchLi = useCallback((k: keyof typeof li, v: string | boolean) => { setLi((f) => ({ ...f, [k]: v })); setLiDirty(true); }, []);

  const saveLlmInfo = useCallback(async (next: typeof li) => {
    if (remote) {
      await remoteLlmSave.mutateAsync({ siteId, content: next.content, enabled: next.enabled });
    } else {
      await localLlmSave.mutateAsync({ enabled: next.enabled, keywords: next.keywords, years: next.years, area: next.area, strengths: next.strengths, content: next.content });
    }
    setLiDirty(false);
    llmQ.refetch();
  }, [remote, siteId, remoteLlmSave, localLlmSave, llmQ]);

  const handleLlmGenerate = useCallback(async () => {
    setLiBusy('generate'); setLiError(null);
    try {
      const inputs = { keywords: li.keywords, years: li.years, area: li.area, strengths: li.strengths, model: modelId || undefined, provider, templateId: llmInfoTpl };
      const res = remote ? await remoteLlmBuild.mutateAsync({ siteId, ...inputs }) : await localLlmBuild.mutateAsync(inputs);
      const html = String((res as any)?.content ?? '').trim();
      if (!html) throw new Error('The model returned no text — try again (or pick another model).');
      // First generation PUBLISHES: the owner's spec is "it should be created at /llm-info/".
      const firstTime = li.content.trim() === '';
      const next = { ...li, content: html, enabled: li.enabled || firstTime };
      setLi(next);
      await saveLlmInfo(next);
      toast.success(firstTime && next.enabled ? 'Overview generated and published at /llm-info/' : 'Overview regenerated and saved');
      void runCheck(true);
    } catch (e) {
      const m = errMsg(e, 'Generation failed');
      setLiError(m); toast.error(m);
    } finally {
      setLiBusy('');
    }
  }, [li, modelId, provider, llmInfoTpl, remote, siteId, remoteLlmBuild, localLlmBuild, saveLlmInfo, runCheck]);

  const handleLlmPublish = useCallback(async (on: boolean) => {
    if (on && li.content.trim() === '') { toast.info('Generate the overview first — there is nothing to publish yet.'); return; }
    const next = { ...li, enabled: on };
    setLi(next); setLiBusy('save'); setLiError(null);
    try {
      await saveLlmInfo(next);
      toast.success(on ? 'Published at /llm-info/' : '/llm-info/ unpublished');
      void runCheck(true);
    } catch (e) {
      const m = errMsg(e, 'Could not save'); setLiError(m); toast.error(m);
    } finally { setLiBusy(''); }
  }, [li, saveLlmInfo, runCheck]);

  const handleLlmSave = useCallback(async () => {
    setLiBusy('save'); setLiError(null);
    try { await saveLlmInfo(li); toast.success('/llm-info/ saved'); } catch (e) { const m = errMsg(e, 'Could not save'); setLiError(m); toast.error(m); } finally { setLiBusy(''); }
  }, [li, saveLlmInfo]);

  const handleDetect = useCallback(async () => {
    setLiBusy('detect');
    try {
      const res = (remote ? await remoteLlmKeywords.mutateAsync({ siteId }) : await localLlmKeywords.mutateAsync({})) as { keywords?: string; list?: { term: string; count: number }[] };
      setDetected(Array.isArray(res?.list) ? res.list : []);
      if (res?.keywords) { patchLi('keywords', String(res.keywords)); toast.success('Most-used keywords detected from the site'); } else { toast.info('No prominent keywords found — type some, or leave blank'); }
    } catch (e) { toast.error(errMsg(e, 'Could not scan the site')); } finally { setLiBusy(''); }
  }, [remote, siteId, remoteLlmKeywords, localLlmKeywords, patchLi]);

  // ── llms.txt + page .md ──
  const airQ = trpc.seo.airStatus.useQuery(undefined, { enabled: !remote, retry: false }) as { data?: any; isLoading: boolean; error?: unknown; refetch: () => Promise<unknown> };
  const remoteAiQ = trpc.seo.remoteAiGet.useQuery({ siteId: siteId ?? 0 }, { enabled: remote, retry: false }) as { data?: any; isLoading: boolean; error?: unknown; refetch: () => Promise<unknown> };
  const remotePostsQ = trpc.seo.remoteAiPosts.useQuery({ siteId: siteId ?? 0 }, { enabled: remote, retry: false }) as { data?: { posts?: PageRow[] }; isLoading: boolean; error?: unknown };
  const airBuild = trpc.seo.airBuild.useMutation();
  const airPublish = trpc.seo.airPublish.useMutation();
  const airSettings = trpc.seo.airSettings.useMutation();
  const airSaveLlms = trpc.seo.airSaveLlms.useMutation();
  const airSiteDesc = trpc.seo.airGenSiteDesc.useMutation();
  const airGenerate = trpc.seo.airGenerate.useMutation();
  const airSummarize = trpc.seo.airSummarize.useMutation();
  const airDeleteAll = trpc.seo.airDeleteAll.useMutation();
  const remoteAiSave = trpc.seo.remoteAiSave.useMutation();
  const remoteAiBuild = trpc.seo.remoteAiBuild.useMutation();
  const remoteAiSiteDesc = trpc.seo.remoteAiSiteDesc.useMutation();

  const [llms, setLlms] = useState<{ text: string; enabled: boolean; url: string; fullUrl: string }>({ text: '', enabled: false, url: '', fullUrl: '' });
  const [llmsDirty, setLlmsDirty] = useState(false);
  const [llmsBusy, setLlmsBusy] = useState<'' | 'generate' | 'save' | 'desc' | 'publish' | 'summarize'>('');
  const [llmsError, setLlmsError] = useState<string | null>(null);
  const [desc, setDesc] = useState('');
  const [settings, setSettings] = useState<{ post_types: string[]; max_posts: number; excluded_ids: number[] }>({ post_types: ['page', 'post'], max_posts: 50, excluded_ids: [] });
  const [rowBusy, setRowBusy] = useState<string | null>(null);
  const [summarizeProgress, setSummarizeProgress] = useState<string>('');

  useEffect(() => {
    if (remote) {
      const d = remoteAiQ.data;
      if (d && typeof d === 'object' && !llmsDirty) setLlms({ text: String(d.llms ?? ''), enabled: !!d.enabled, url: String(d.url ?? ''), fullUrl: '' });
    } else {
      const d = airQ.data;
      if (d && typeof d === 'object') {
        if (!llmsDirty) setLlms({ text: String(d.llmsTxt ?? ''), enabled: !!d.published, url: String(d.llmsUrl ?? ''), fullUrl: String(d.llmsFullUrl ?? '') });
        if (d.settings) {
          setSettings({ post_types: Array.isArray(d.settings.post_types) ? d.settings.post_types : ['page', 'post'], max_posts: Number(d.settings.max_posts ?? 50) || 50, excluded_ids: Array.isArray(d.settings.excluded_ids) ? d.settings.excluded_ids.map(Number) : [] });
          setDesc(String(d.settings.site_description ?? ''));
        }
      }
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [remote, airQ.data, remoteAiQ.data]);

  const pages = useMemo<PageRow[]>(() => {
    if (remote) return Array.isArray(remotePostsQ.data?.posts) ? remotePostsQ.data!.posts! : [];
    return Array.isArray(airQ.data?.posts) ? (airQ.data.posts as PageRow[]) : [];
  }, [remote, remotePostsQ.data, airQ.data]);
  const pagesLoading = remote ? remotePostsQ.isLoading : airQ.isLoading;

  const refreshLlms = useCallback(async () => { if (remote) { await remoteAiQ.refetch(); } else { await airQ.refetch(); } }, [remote, remoteAiQ, airQ]);

  const handleSiteDesc = useCallback(async (): Promise<string> => {
    const res = (remote
      ? await remoteAiSiteDesc.mutateAsync({ siteId, model: modelId || undefined, provider, templateId: siteDescTpl })
      : await airSiteDesc.mutateAsync({ model: modelId || undefined, provider, templateId: siteDescTpl })) as { description?: string };
    const d = String(res?.description ?? '').trim();
    if (d) setDesc(d);
    return d;
  }, [remote, siteId, modelId, provider, siteDescTpl, remoteAiSiteDesc, airSiteDesc]);

  const handleLlmsGenerate = useCallback(async () => {
    setLlmsBusy('generate'); setLlmsError(null);
    try {
      const firstTime = llms.text.trim() === '';
      if (remote) {
        let d = desc.trim();
        if (d === '') { try { d = await handleSiteDesc(); } catch { /* the index still builds without a description */ } }
        const built = (await remoteAiBuild.mutateAsync({ siteId, desc: d })) as { llms?: string };
        const text = String(built?.llms ?? '');
        const enabled = llms.enabled || firstTime;
        await remoteAiSave.mutateAsync({ siteId, llms: text, enabled });
        setLlms((l) => ({ ...l, text, enabled })); setLlmsDirty(false);
        await refreshLlms();
        toast.success(firstTime ? `llms.txt generated and published on ${siteName} — page .md files are served with it` : 'llms.txt regenerated and saved');
      } else {
        // Save the description + settings first (they shape the index), then build + publish.
        if (desc.trim() === '') { try { await handleSiteDesc(); } catch { /* optional */ } }
        await airSettings.mutateAsync({ ...settings, site_description: desc });
        const built = (await airBuild.mutateAsync({})) as { posts?: number };
        if (firstTime || !llms.enabled) await airPublish.mutateAsync({ published: true });
        setLlmsDirty(false);
        await refreshLlms();
        toast.success(`llms.txt + ${built?.posts ?? 0} page .md files generated${firstTime || !llms.enabled ? ' and published' : ''}`);
      }
      void runCheck(true);
    } catch (e) {
      const m = errMsg(e, 'Generation failed'); setLlmsError(m); toast.error(m);
    } finally { setLlmsBusy(''); }
  }, [remote, siteId, siteName, llms, desc, settings, handleSiteDesc, remoteAiBuild, remoteAiSave, airSettings, airBuild, airPublish, refreshLlms, runCheck]);

  const handleLlmsPublish = useCallback(async (on: boolean) => {
    if (on && llms.text.trim() === '') { toast.info('Generate llms.txt first — there is nothing to publish yet.'); return; }
    setLlmsBusy('publish'); setLlmsError(null);
    try {
      if (remote) await remoteAiSave.mutateAsync({ siteId, llms: llms.text, enabled: on });
      else await airPublish.mutateAsync({ published: on });
      setLlms((l) => ({ ...l, enabled: on }));
      await refreshLlms();
      toast.success(on ? 'llms.txt + page .md files published' : 'llms.txt unpublished');
      void runCheck(true);
    } catch (e) { const m = errMsg(e, 'Could not save'); setLlmsError(m); toast.error(m); } finally { setLlmsBusy(''); }
  }, [remote, siteId, llms.text, remoteAiSave, airPublish, refreshLlms, runCheck]);

  const handleLlmsSave = useCallback(async () => {
    setLlmsBusy('save'); setLlmsError(null);
    try {
      if (remote) await remoteAiSave.mutateAsync({ siteId, llms: llms.text, enabled: llms.enabled });
      else { await airSaveLlms.mutateAsync({ llms: llms.text }); await airSettings.mutateAsync({ ...settings, site_description: desc }); }
      setLlmsDirty(false);
      await refreshLlms();
      toast.success('Saved');
    } catch (e) { const m = errMsg(e, 'Could not save'); setLlmsError(m); toast.error(m); } finally { setLlmsBusy(''); }
  }, [remote, siteId, llms, settings, desc, remoteAiSave, airSaveLlms, airSettings, refreshLlms]);

  const handleGenDescClick = useCallback(async () => {
    setLlmsBusy('desc'); setLlmsError(null);
    try { await handleSiteDesc(); setLlmsDirty(true); toast.success('Site description generated — it is added on the next Generate / Save'); }
    catch (e) { const m = errMsg(e, 'Generation failed'); setLlmsError(m); toast.error(m); }
    finally { setLlmsBusy(''); }
  }, [handleSiteDesc]);

  // Local-only: per-page .md + AI summary, summarize-all, exclude, reset.
  const handleRowGenerate = useCallback(async (id: number) => {
    setRowBusy(`${id}:gen`);
    try { await airGenerate.mutateAsync({ id }); await refreshLlms(); } catch (e) { toast.error(errMsg(e, 'Could not generate')); } finally { setRowBusy(null); }
  }, [airGenerate, refreshLlms]);
  const handleRowSummarize = useCallback(async (id: number) => {
    setRowBusy(`${id}:sum`);
    try { await airSummarize.mutateAsync({ id, model: modelId || undefined, provider, templateId: pageSummaryTpl }); await refreshLlms(); toast.success('Summary written'); }
    catch (e) { toast.error(errMsg(e, 'Could not summarize')); } finally { setRowBusy(null); }
  }, [airSummarize, modelId, provider, pageSummaryTpl, refreshLlms]);
  const handleSummarizeAll = useCallback(async () => {
    const todo = pages.filter((p) => !p.excluded && !p.hasSummary);
    if (todo.length === 0) { toast.info('Every page already has a summary'); return; }
    setLlmsBusy('summarize'); setLlmsError(null);
    let done = 0; let failed = 0;
    for (const p of todo) {
      setSummarizeProgress(`${done + failed + 1}/${todo.length} · ${p.title}`);
      try { await airSummarize.mutateAsync({ id: p.id, model: modelId || undefined, provider, templateId: pageSummaryTpl }); done++; } catch { failed++; }
    }
    setSummarizeProgress('');
    try { await airBuild.mutateAsync({}); } catch { /* the index rebuild is best-effort here */ }
    await refreshLlms();
    setLlmsBusy('');
    if (failed) toast.warning(`${done} summarised, ${failed} failed — llms.txt rebuilt`); else toast.success(`${done} page summaries written — llms.txt rebuilt`);
  }, [pages, airSummarize, airBuild, modelId, provider, pageSummaryTpl, refreshLlms]);
  const toggleExclude = useCallback(async (id: number) => {
    const ex = settings.excluded_ids.includes(id) ? settings.excluded_ids.filter((x) => x !== id) : [...settings.excluded_ids, id];
    const next = { ...settings, excluded_ids: ex };
    setSettings(next);
    try { await airSettings.mutateAsync({ ...next, site_description: desc }); await refreshLlms(); } catch (e) { toast.error(errMsg(e, 'Could not save')); }
  }, [settings, desc, airSettings, refreshLlms]);
  const handleReset = useCallback(async () => {
    if (!window.confirm('Reset AI Readiness on this site — llms.txt, every page .md and summary, the site description? /llm-info/ is kept. This cannot be undone.')) return;
    setLlmsBusy('save');
    try { await airDeleteAll.mutateAsync({}); setLlmsDirty(false); await refreshLlms(); toast.success('AI Readiness reset'); } catch (e) { toast.error(errMsg(e, 'Reset failed')); } finally { setLlmsBusy(''); }
  }, [airDeleteAll, refreshLlms]);

  // ── derived ──
  const liCheck = checkFor('llminfo');
  const llmsCheck = checkFor('llms');
  const mdCheck = checkFor('md');
  const robotsCheck = checkFor('robots');
  const liVerdict = fileVerdict(liCheck, li.enabled, li.content.trim() !== '');
  const llmsVerdict = fileVerdict(llmsCheck, llms.enabled, llms.text.trim() !== '');
  const llmLoadError = llmQ.error ? errMsg(llmQ.error, 'Could not read /llm-info/ from the site.') : null;
  const llmsLoadError = (remote ? remoteAiQ.error : airQ.error) ? errMsg(remote ? remoteAiQ.error : airQ.error, 'Could not read llms.txt from the site.') : null;
  const anyBusy = liBusy !== '' || llmsBusy !== '';
  const ready = !(llmQ.isLoading && (remote ? remoteAiQ.isLoading : airQ.isLoading));

  if (!ready) {
    return <div className="flex items-center justify-center py-16"><Loader2 className="h-6 w-6 animate-spin text-primary" /></div>;
  }

  return (
    <div className="max-w-3xl space-y-5" data-testid="ai-readiness-studio">
      {/* Header */}
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="text-base font-semibold">AI Readiness</h2>
          <p className="mt-0.5 text-xs text-muted-foreground">
            Make <span className="font-medium text-foreground">{siteName}</span> readable and recommendable by AI search (ChatGPT, Perplexity, Google AI Overviews). Three files, generated from the site's own pages — each one has <span className="font-medium">Generate</span> and <span className="font-medium">Publish</span>.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Select value={modelId || '__default__'} onValueChange={(v) => onModelChange(v === '__default__' ? '' : v)}>
            <SelectTrigger className="w-auto min-w-[10rem]" title="The text model used for every Generate on this screen"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="__default__">Default model</SelectItem>
              {models.map((m) => <SelectItem key={m.id} value={m.id}>{m.name} <span className="text-muted-foreground">({m.provider})</span></SelectItem>)}
            </SelectContent>
          </Select>
          <Button variant="outline" size="sm" onClick={() => runCheck()} disabled={checking} title="Fetch robots.txt, llms.txt, /llm-info/ and one page .md from the public site — exactly as an AI crawler would — and report what came back">
            {checking ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <Radar className="mr-1.5 h-3.5 w-3.5" />} Check live
          </Button>
        </div>
      </div>

      {/* Status strip */}
      <div className="grid grid-cols-2 gap-2 sm:grid-cols-4" data-testid="air-status-strip">
        {[
          { key: 'llminfo', label: '/llm-info/', v: liVerdict, c: liCheck },
          { key: 'llms', label: 'llms.txt', v: llmsVerdict, c: llmsCheck },
          { key: 'md', label: `page .md${pages.length ? ` · ${pages.length}` : ''}`, v: mdCheck ? fileVerdict(mdCheck, llms.enabled, true) : (llms.enabled ? { state: 'on', label: 'Served' } : { state: 'off', label: 'Off' }), c: mdCheck },
          { key: 'robots', label: 'robots.txt', v: robotsCheck ? (robotsCheck.state === 'live' ? { state: 'live', label: 'AI allowed' } : robotsCheck.state === 'warn' ? { state: 'warn', label: 'Blocks AI' } : fileVerdict(robotsCheck, true, true)) : { state: 'unknown', label: 'Not checked' }, c: robotsCheck },
        ].map((f) => (
          <div key={f.key} className="rounded-lg border border-border bg-card px-3 py-2" title={f.c?.note || ''}>
            <div className="text-[11px] text-muted-foreground">{f.label}</div>
            <div className="mt-1"><StatePill state={f.v.state as any} label={f.v.label} /></div>
          </div>
        ))}
      </div>
      {checks && (
        <p className="-mt-3 text-[11px] text-muted-foreground">
          Checked live{checkedAt ? ` at ${checkedAt}` : ''} — each file fetched from its public URL like a crawler would.
        </p>
      )}
      {checks?.some((c) => c.state === 'blocked') && (
        <div className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800">
          {checks.filter((c) => c.state === 'blocked')[0].note}
        </div>
      )}

      {/* ── 1. /llm-info/ ── */}
      <section className="rounded-xl border border-border bg-card p-4 space-y-3" data-testid="air-card-llminfo">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <div className="flex items-center gap-2">
              <FileText className="h-4 w-4 text-primary" />
              <h3 className="text-sm font-semibold">1 · /llm-info/ <span className="font-normal text-muted-foreground">— the AI overview page</span></h3>
              <StatePill state={liVerdict.state as any} label={liVerdict.label} />
            </div>
            <p className="mt-1 text-xs text-muted-foreground">
              A short, factual overview of the business written for AI search: optimised for the keywords you want to rank for, positively framed (only from real facts), establishing authority in the niche, years in business and how local it is to the area it serves.
            </p>
            {li.url && li.enabled && (
              <a href={li.url} target="_blank" rel="noopener noreferrer" className="mt-1 inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"><ExternalLink className="h-3.5 w-3.5" /> {li.url}</a>
            )}
          </div>
          <div className="flex shrink-0 items-center gap-2">
            <Button size="sm" onClick={handleLlmGenerate} disabled={anyBusy} title={li.content.trim() ? 'Regenerate the overview from the site and your details' : 'Generate the overview from the site — no details needed; it publishes at /llm-info/'}>
              {liBusy === 'generate' ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <Sparkles className="mr-1.5 h-3.5 w-3.5" />}
              {li.content.trim() ? 'Regenerate' : 'Generate'}
            </Button>
            <label className="flex items-center gap-1.5 text-xs text-muted-foreground" title="Serve the overview at /llm-info/ on the site">
              <Switch checked={li.enabled} onCheckedChange={handleLlmPublish} disabled={anyBusy} aria-label="Publish /llm-info/" /> Publish
            </label>
          </div>
        </div>
        <InlineError text={liError} />
        {llmLoadError && !liError && (
          <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
            Couldn't read the current /llm-info/ from {siteName}: {llmLoadError} — Generate still works; if saving fails too, update the Power Creatives connector on that site.
          </div>
        )}
        {liCheck && liCheck.state !== 'live' && <p className="text-[11px] text-muted-foreground">{liCheck.note}</p>}

        <Disclosure title="Details (optional) — keywords, area, years, strengths, template" right={<TemplatePick options={tplLlmInfo} value={llmInfoTpl} onChange={setLlmInfoTpl} />}>
          <p className="text-[11px] text-muted-foreground">Only the facts you give are used — nothing is invented. Leave keywords blank and the most-used keywords on the site are used automatically.</p>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div className="space-y-1">
              <Label className="text-[11px] text-muted-foreground">Target keywords</Label>
              <div className="flex gap-1.5">
                <Input value={li.keywords} onChange={(e) => patchLi('keywords', e.target.value)} placeholder="auto-detected from the site" className="h-8 text-xs" />
                <Button type="button" variant="outline" size="icon" className="h-8 w-8 shrink-0" title="Detect the most-used keywords on the site" onClick={handleDetect} disabled={anyBusy}>
                  {liBusy === 'detect' ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <ScanSearch className="h-3.5 w-3.5" />}
                </Button>
              </div>
            </div>
            <div className="space-y-1"><Label className="text-[11px] text-muted-foreground">Service area</Label><Input value={li.area} onChange={(e) => patchLi('area', e.target.value)} placeholder="Göteborg, Sweden" className="h-8 text-xs" /></div>
            <div className="space-y-1"><Label className="text-[11px] text-muted-foreground">Years in business</Label><Input value={li.years} onChange={(e) => patchLi('years', e.target.value)} placeholder="since 2008 / 15+ years" className="h-8 text-xs" /></div>
            <div className="space-y-1"><Label className="text-[11px] text-muted-foreground">Strengths / recommendations</Label><Input value={li.strengths} onChange={(e) => patchLi('strengths', e.target.value)} placeholder="4.9★ Google, certified therapists" className="h-8 text-xs" /></div>
          </div>
          {detected.length > 0 && (
            <div className="flex flex-wrap gap-1">
              {detected.map((k) => (
                <button key={k.term} type="button" title={`Appears ${k.count}×`} onClick={() => patchLi('keywords', li.keywords.trim() === '' ? k.term : (li.keywords.split(',').map((s) => s.trim()).includes(k.term) ? li.keywords : `${li.keywords.replace(/,\s*$/, '')}, ${k.term}`))} className="rounded-full border border-border bg-muted/50 px-2 py-0.5 text-[11px] text-muted-foreground hover:border-primary hover:text-foreground">
                  {k.term} <span className="text-muted-foreground/60">{k.count}</span>
                </button>
              ))}
            </div>
          )}
        </Disclosure>

        {li.content.trim() !== '' && (
          <Disclosure title={liEditHtml ? 'Overview — editing HTML' : 'Overview — preview'} defaultOpen right={
            <div className="flex items-center gap-2">
              <button type="button" className="text-[11px] text-muted-foreground hover:text-foreground" onClick={() => setLiEditHtml((v) => !v)}>{liEditHtml ? 'Preview' : 'Edit HTML'}</button>
              {liDirty && (
                <Button size="sm" variant="outline" className="h-7" onClick={handleLlmSave} disabled={anyBusy}>{liBusy === 'save' ? <Loader2 className="mr-1 h-3 w-3 animate-spin" /> : <Save className="mr-1 h-3 w-3" />} Save</Button>
              )}
            </div>
          }>
            {liEditHtml ? (
              <Textarea value={li.content} onChange={(e) => patchLi('content', e.target.value)} rows={14} className="font-mono text-xs" />
            ) : (
              <div className="prose prose-sm max-w-none rounded-md border border-border/60 bg-background px-4 py-3 text-[13px] leading-relaxed [&_h1]:text-base [&_h2]:text-sm [&_h3]:text-sm" dangerouslySetInnerHTML={{ __html: li.content }} />
            )}
          </Disclosure>
        )}
      </section>

      {/* ── 2. llms.txt + page .md ── */}
      <section className="rounded-xl border border-border bg-card p-4 space-y-3" data-testid="air-card-llms">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <div className="flex items-center gap-2">
              <FileText className="h-4 w-4 text-primary" />
              <h3 className="text-sm font-semibold">2 · llms.txt <span className="font-normal text-muted-foreground">— the index of your pages, plus a .md copy of each page</span></h3>
              <StatePill state={llmsVerdict.state as any} label={llmsVerdict.label} />
            </div>
            <p className="mt-1 text-xs text-muted-foreground">
              The llmstxt.org index AI crawlers read first: a one-line site description and every published page with a one-line summary, linked to its Markdown version at <code>/{'{page}'}.md</code> (served with it).
            </p>
            {llms.enabled && llms.url && (
              <div className="mt-1 flex flex-wrap gap-3">
                <a href={llms.url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"><ExternalLink className="h-3.5 w-3.5" /> {llms.url}</a>
                {llms.fullUrl && <a href={llms.fullUrl} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"><ExternalLink className="h-3.5 w-3.5" /> llms-full.txt</a>}
              </div>
            )}
          </div>
          <div className="flex shrink-0 items-center gap-2">
            <Button size="sm" onClick={handleLlmsGenerate} disabled={anyBusy} title={llms.text.trim() ? 'Rebuild llms.txt (and the page .md files) from the site' : 'Build llms.txt from the site — the site description is written automatically; it publishes with the page .md files'}>
              {llmsBusy === 'generate' ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <Sparkles className="mr-1.5 h-3.5 w-3.5" />}
              {llms.text.trim() ? 'Regenerate' : 'Generate'}
            </Button>
            <label className="flex items-center gap-1.5 text-xs text-muted-foreground" title="Serve llms.txt and the page .md files on the site">
              <Switch checked={llms.enabled} onCheckedChange={handleLlmsPublish} disabled={anyBusy} aria-label="Publish llms.txt" /> Publish
            </label>
          </div>
        </div>
        <InlineError text={llmsError} />
        {llmsLoadError && !llmsError && (
          <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
            Couldn't read llms.txt from {siteName}: {llmsLoadError}{remote ? ' — update the Power Creatives connector on that site (Sites → the site → Update).' : ''}
          </div>
        )}
        {llmsCheck && llmsCheck.state !== 'live' && <p className="text-[11px] text-muted-foreground">{llmsCheck.note}</p>}
        {mdCheck && mdCheck.state !== 'live' && <p className="text-[11px] text-muted-foreground">page .md: {mdCheck.note}</p>}
        {summarizeProgress && <p className="text-[11px] text-muted-foreground">Summarising {summarizeProgress}…</p>}

        <Disclosure title="Details (optional) — site description, what to include, templates">
          <div className="space-y-1">
            <Label className="text-[11px] text-muted-foreground">Site description (the line under the title in llms.txt)</Label>
            <div className="flex gap-1.5">
              <Input value={desc} onChange={(e) => { setDesc(e.target.value); setLlmsDirty(true); }} placeholder="Written automatically on Generate when empty" className="h-8 text-xs" />
              <Button type="button" variant="outline" size="icon" className="h-8 w-8 shrink-0" title="Write the site description with AI" onClick={handleGenDescClick} disabled={anyBusy}>
                {llmsBusy === 'desc' ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <WandSparkles className="h-3.5 w-3.5" />}
              </Button>
            </div>
            <div className="flex flex-wrap items-center gap-3 pt-1">
              <TemplatePick options={tplSiteDesc} value={siteDescTpl} onChange={setSiteDescTpl} label="Description template" />
              {!remote && <TemplatePick options={tplPageSummary} value={pageSummaryTpl} onChange={setPageSummaryTpl} label="Page summary template" />}
            </div>
          </div>
          {!remote && (
            <div className="flex flex-wrap items-center gap-4">
              <div className="flex items-center gap-3">
                <span className="text-[11px] text-muted-foreground">Include:</span>
                {['page', 'post'].map((t) => (
                  <label key={t} className="flex cursor-pointer items-center gap-1.5 text-xs capitalize">
                    <Checkbox checked={settings.post_types.includes(t)} onCheckedChange={() => { setSettings((s) => ({ ...s, post_types: s.post_types.includes(t) ? s.post_types.filter((x) => x !== t) : [...s.post_types, t] })); setLlmsDirty(true); }} /> {t}s
                  </label>
                ))}
              </div>
              <div className="flex items-center gap-1.5">
                <span className="text-[11px] text-muted-foreground">Max posts</span>
                <Input type="number" value={settings.max_posts} onChange={(e) => { setSettings((s) => ({ ...s, max_posts: Math.max(1, Math.min(200, Number(e.target.value) || 1)) })); setLlmsDirty(true); }} className="h-7 w-20 text-xs" />
              </div>
              <Button variant="ghost" size="sm" onClick={handleReset} disabled={anyBusy} className="ml-auto h-7 gap-1.5 text-destructive hover:text-destructive"><Trash2 className="h-3.5 w-3.5" /> Reset</Button>
            </div>
          )}
          {remote && <p className="text-[11px] text-muted-foreground">On a connected site the page .md files are rendered live by the connector from each page's content, so every published page and post is included.</p>}
        </Disclosure>

        <Disclosure title={`Pages${pages.length ? ` (${pages.length})` : ''}${!remote && pages.length ? ` — ${pages.filter((p) => p.hasSummary).length} with a summary` : ''}`} right={!remote && pages.length > 0 ? (
          <Button size="sm" variant="outline" className="h-7" onClick={handleSummarizeAll} disabled={anyBusy} title="Write the one-line summary for every page that has none, then rebuild llms.txt">
            {llmsBusy === 'summarize' ? <Loader2 className="mr-1 h-3 w-3 animate-spin" /> : <WandSparkles className="mr-1 h-3 w-3" />} Summarise all
          </Button>
        ) : undefined}>
          {pagesLoading ? (
            <div className="flex items-center gap-2 text-xs text-muted-foreground"><Loader2 className="h-3.5 w-3.5 animate-spin" /> Reading the site's pages…</div>
          ) : pages.length === 0 ? (
            <p className="text-xs text-muted-foreground">{remote && remotePostsQ.error ? `Couldn't list the site's pages: ${errMsg(remotePostsQ.error, '')}` : 'No published pages or posts found.'}</p>
          ) : (
            <div className="overflow-hidden rounded-md border border-border/70">
              <div className={`grid ${remote ? 'grid-cols-[1fr_4rem_2.5rem]' : 'grid-cols-[1fr_4rem_5rem_6.5rem]'} gap-2 bg-muted/60 px-3 py-1.5 text-[11px] font-medium text-muted-foreground`}>
                <span>Page</span><span>Type</span>{!remote && <span>.md</span>}<span className="text-center">{remote ? '.md' : 'Actions'}</span>
              </div>
              <div className="max-h-72 overflow-y-auto">
                {pages.map((p) => (
                  <div key={`${p.type}-${p.id}`} className={`grid ${remote ? 'grid-cols-[1fr_4rem_2.5rem]' : 'grid-cols-[1fr_4rem_5rem_6.5rem]'} items-center gap-2 border-t border-border/70 px-3 py-1.5 text-xs ${p.excluded ? 'opacity-45' : ''}`}>
                    <div className="min-w-0">
                      <div className="truncate" title={p.title}>{p.title || '(untitled)'}</div>
                      {p.summary && <div className="truncate text-[11px] text-muted-foreground/80" title={p.summary}>{p.summary}</div>}
                    </div>
                    <span className="capitalize text-muted-foreground">{p.type}</span>
                    {!remote && (
                      <span>
                        <span className={`inline-block rounded px-1.5 py-0.5 text-[11px] capitalize ${p.status === 'ready' ? 'bg-emerald-100 text-emerald-800' : p.status === 'stale' ? 'bg-amber-100 text-amber-800' : 'bg-muted text-muted-foreground'}`}>{p.status ?? 'none'}</span>
                      </span>
                    )}
                    <div className="flex items-center justify-center gap-1.5">
                      {!remote && (
                        <>
                          <button type="button" onClick={() => toggleExclude(p.id)} disabled={anyBusy} title={p.excluded ? 'Excluded — click to include' : 'Exclude from llms.txt'} className="text-muted-foreground hover:text-foreground disabled:opacity-50">{p.excluded ? <EyeOff className="h-3.5 w-3.5" /> : <Eye className="h-3.5 w-3.5" />}</button>
                          <button type="button" onClick={() => handleRowGenerate(p.id)} disabled={rowBusy !== null || anyBusy} title="Regenerate this page's .md" className="text-muted-foreground hover:text-primary disabled:opacity-50">{rowBusy === `${p.id}:gen` ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <RefreshCw className="h-3.5 w-3.5" />}</button>
                          <button type="button" onClick={() => handleRowSummarize(p.id)} disabled={rowBusy !== null || anyBusy} title="Write this page's one-line summary with AI" className="text-muted-foreground hover:text-primary disabled:opacity-50">{rowBusy === `${p.id}:sum` ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <WandSparkles className="h-3.5 w-3.5" />}</button>
                        </>
                      )}
                      {p.mdUrl ? <a href={p.mdUrl} target="_blank" rel="noopener noreferrer" className="inline-flex text-muted-foreground hover:text-foreground" title={`Open ${p.mdUrl}`}><FileText className="h-3.5 w-3.5" /></a> : <span className="text-muted-foreground/50">—</span>}
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}
        </Disclosure>

        {llms.text.trim() !== '' && (
          <Disclosure title="Edit llms.txt" right={llmsDirty ? (
            <Button size="sm" variant="outline" className="h-7" onClick={handleLlmsSave} disabled={anyBusy}>{llmsBusy === 'save' ? <Loader2 className="mr-1 h-3 w-3 animate-spin" /> : <Save className="mr-1 h-3 w-3" />} Save</Button>
          ) : undefined}>
            <Textarea value={llms.text} onChange={(e) => { setLlms((l) => ({ ...l, text: e.target.value })); setLlmsDirty(true); }} rows={12} className="font-mono text-xs" />
          </Disclosure>
        )}
      </section>

      {/* ── 3. robots.txt ── */}
      <section className="rounded-xl border border-border bg-card p-4 space-y-2" data-testid="air-card-robots">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <div className="flex items-center gap-2">
              <ShieldCheck className="h-4 w-4 text-primary" />
              <h3 className="text-sm font-semibold">3 · robots.txt <span className="font-normal text-muted-foreground">— let the AI crawlers in</span></h3>
              {robotsCheck ? <StatePill state={(robotsCheck.state === 'live' ? 'live' : robotsCheck.state === 'warn' ? 'warn' : robotsCheck.state) as any} label={robotsCheck.state === 'live' ? 'AI allowed' : robotsCheck.state === 'warn' ? 'Blocks AI' : fileVerdict(robotsCheck, true, true).label} /> : <StatePill state="unknown" label="Not checked" />}
            </div>
            <p className="mt-1 text-xs text-muted-foreground">
              None of the above helps if robots.txt turns GPTBot, ClaudeBot, PerplexityBot or Google-Extended away. "Check live" reads the site's robots.txt and flags any AI crawler it blocks. Custom robots rules live under the <span className="font-medium">Site</span> section.
            </p>
            {robotsCheck && <p className="mt-1 text-[11px] text-muted-foreground">{robotsCheck.note}</p>}
            {robotsCheck?.url && <a href={robotsCheck.url} target="_blank" rel="noopener noreferrer" className="mt-1 inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"><ExternalLink className="h-3.5 w-3.5" /> {robotsCheck.url}</a>}
          </div>
          <Button variant="outline" size="sm" onClick={() => runCheck()} disabled={checking} className="shrink-0">
            {checking ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <Radar className="mr-1.5 h-3.5 w-3.5" />} Check live
          </Button>
        </div>
      </section>
    </div>
  );
}
