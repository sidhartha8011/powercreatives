import React, { useState } from 'react';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Checkbox } from '@/components/ui/checkbox';
import { KeywordPicker, AccordionSection } from '@/components/shared';
import { cn } from '@/lib/utils';
import { trpc } from '@/lib/trpc';
import { Loader2, Plus, X } from 'lucide-react';
import {
  RecurrenceEditor,
  recurrenceFromConfig,
  recurrenceToConfig,
  type ScheduleRecurrence,
} from '../Strategies/RecurrenceEditor';

/**
 * Compact segmented radio — used for Filip's Source / Trigger / Publishing /
 * Duration section choices. Real focusable buttons with radio semantics keep it
 * keyboard-accessible while staying horizontal in the compact dialog.
 */
function Segmented<T extends string>({
  value,
  onChange,
  options,
  disabled = false,
}: {
  value: T;
  onChange: (v: T) => void;
  options: { value: T; label: string }[];
  disabled?: boolean;
}) {
  return (
    <div
      role="radiogroup"
      className={cn(
        'inline-flex flex-wrap gap-1 rounded-md border border-border bg-muted/20 p-1',
        disabled && 'opacity-60',
      )}
    >
      {options.map((opt) => {
        const active = value === opt.value;
        return (
          <button
            key={opt.value}
            type="button"
            role="radio"
            aria-checked={active}
            disabled={disabled}
            onClick={() => !disabled && onChange(opt.value)}
            className={cn(
              'px-3 py-1 text-xs font-medium rounded transition-colors',
              active ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
              disabled ? 'cursor-default' : 'cursor-pointer',
            )}
          >
            {opt.label}
          </button>
        );
      })}
    </div>
  );
}

export interface StrategyPayload {
  name: string;
  templateId: number;
  /** LLM the articles are generated with. Empty → backend default (Gemini 2.5 Flash). */
  model?: string;
  provider?: string;
  structure: string;
  hierarchyMode: string;
  parentTargetUrl?: string;
  parentKeyword?: string;
  publishingMode: string;
  /** Connected site the strategy targets. Stored on every strategy; auto-publish
   *  pushes each article here when publishingMode === 'publish'. */
  siteId?: number;
  approvalMode: string;
  /** AutoPress parity: generate a featured image per article at creation time. */
  featuredImages?: boolean;
  /** AutoPress parity: insert in-content images & charts ([IMAGE_N] media_assets). Default on. */
  inContentMedia?: boolean;
  /** Media type to insert when inContentMedia is on. Default 'both'. */
  mediaType?: string;
  /** Count of in-content media items to insert. Default 3. */
  mediaCount?: number;
  mediaGuidance?: string;
  /** Per-JOB models — each job runs on its own model, independent of the writer above.
   *  Empty/omitted → that job's historical server-side default. */
  imageModel?: string;
  imageProvider?: string;
  researchModel?: string;
  researchProvider?: string;
  /** Image PROMPT template (module 'image'); 0/omitted → the built-in default prompt. */
  imageTemplateId?: number;
  /** AutoPress parity: research the topic (Google-grounded Gemini) before writing. */
  research?: boolean;
  /** Research depth: off / grounded (single Google-grounded pass) / deep (3-pass workflow). */
  researchMode?: string;
  interlinksConfig?: any;
  scheduleConfig?: any;
  // ── Filip's simplified-model sections (FROZEN CONFIG CONTRACT) ──
  /** 'keywords' (default) | 'rss' | 'social'. */
  sourceMode?: string;
  /** RSS feed URLs (1–5) when sourceMode === 'rss'. */
  rssFeeds?: string[];
  /** Social post/account links (1–10) when sourceMode === 'social'. */
  socialLinks?: string[];
  /** Optional angle / primary keyword each RSS/social rewrite is tailored to. */
  rssAngle?: string;
  /** Backpressure cap: `perWeek` is the COUNT, `unit` the period it is measured
   *  over ('day'|'week'|'month'). The key name is historical. */
  rssCadence?: { perWeek: number; unit?: string };
  /** 'manual' | 'scheduled' | 'new_source_item' (RSS). */
  trigger?: string;
  /** 'draft' | 'auto' — the publishing split gate. */
  publishing?: string;
  /** { mode:'ongoing'|'until'|'limit', endDate?, maxArticles? }. */
  duration?: { mode: string; endDate?: string; maxArticles?: number };
  /** Subset of ['landscape','questions','gaps']. Replaces researchMode. */
  researchPasses?: string[];
  /** Keywords TYPED into the dialog (not picked in the table), primary first and
   *  already de-duped against the table selection. The parent appends these to the
   *  keywords it sends; empty/omitted when the user only used the table. */
  manualKeywords?: string[];
  /** The typed primary keyword, when one was given — the main target of the set. */
  manualPrimaryKeyword?: string;
}

/**
 * Which SOURCE a writer template is written for.
 *
 * There is no source column on templates — the reliable signal is the source-post
 * variables. A prompt that places {{ post_title }} / {{ post_content }} /
 * {{ post_link }} is written to rewrite a fetched RSS/social item; those variables
 * resolve to nothing for a keyword strategy (see SourceVarsHint), so such a template
 * would produce an empty-context article there. Everything else is keyword-shaped.
 */
const POST_VAR_RE = /\{\{\s*post_(title|content|link)\s*\}\}/;
export function templateSourceFit(template: any): 'source' | 'keyword' {
  const entries = Array.isArray(template?.entries) ? template.entries : [];
  for (const e of entries) {
    if ((e?.category ?? '') !== 'prompt') continue;
    if (POST_VAR_RE.test(String(e?.value ?? ''))) return 'source';
  }
  return 'keyword';
}

/** True when a template suits the chosen source mode. */
export function templateFitsSource(template: any, sourceMode: string): boolean {
  const wants = sourceMode === 'rss' || sourceMode === 'social' ? 'source' : 'keyword';
  return templateSourceFit(template) === wants;
}

type SocialLinkInfo =
  | { state: 'empty' }
  | { state: 'invalid' }
  | { state: 'ok'; platform: string; kind: 'post' | 'account'; needsApify: boolean };

const APIFY_PLATFORMS = new Set(['Instagram', 'TikTok', 'X', 'Facebook']);
const INSTAGRAM_RESERVED = new Set(['explore', 'accounts', 'stories', 'direct', 'about', 'developer', 'legal']);
const X_RESERVED = new Set(['home', 'explore', 'search', 'i', 'hashtag', 'notifications', 'messages', 'settings', 'login', 'signup', 'intent', 'share']);
const FACEBOOK_POST_MARKERS = new Set(['posts', 'videos', 'reel', 'reels', 'watch', 'photo', 'photo.php', 'story.php', 'permalink.php']);

/**
 * Lite mirror of the backend classifier (PCM_Social_Source::classify) — same
 * rules, so what the row preview says matches what create will actually do.
 * The backend stays authoritative; this only powers the per-link hint.
 */
function classifySocialLink(raw: string): SocialLinkInfo {
  const url = raw.trim();
  if (!url) return { state: 'empty' };
  if (!/^https?:\/\//i.test(url)) return { state: 'invalid' };
  let host: string;
  let seg: string[];
  let query = '';
  try {
    const u = new URL(url);
    host = u.hostname.replace(/^www\./i, '').toLowerCase();
    seg = u.pathname.split('/').filter(Boolean);
    query = u.search;
  } catch {
    return { state: 'invalid' };
  }
  const is = (base: string) => host === base || host.endsWith('.' + base);
  const ok = (platform: string, kind: 'post' | 'account'): SocialLinkInfo => ({
    state: 'ok',
    platform,
    kind,
    needsApify: kind === 'account' && APIFY_PLATFORMS.has(platform),
  });

  if (host === 'youtu.be') return ok('YouTube', 'post');
  if (is('youtube.com')) {
    const first = seg[0] ?? '';
    if (first.startsWith('@') || ['channel', 'c', 'user'].includes(first)) return ok('YouTube', 'account');
    return ok('YouTube', 'post');
  }
  if (is('bsky.app')) {
    if (seg[0] === 'profile' && seg[1]) return ok('Bluesky', seg[2] === 'post' && seg[3] ? 'post' : 'account');
    return ok('Bluesky', 'post');
  }
  if (is('reddit.com') || host === 'redd.it') {
    if (host === 'redd.it' || seg.includes('comments')) return ok('Reddit', 'post');
    if (['r', 'user', 'u'].includes(seg[0] ?? '') && seg[1]) return ok('Reddit', 'account');
    return ok('Reddit', 'post');
  }
  if (is('instagram.com')) {
    if (['p', 'reel', 'reels', 'tv'].includes(seg[0] ?? '')) return ok('Instagram', 'post');
    if (seg.length === 1 && !INSTAGRAM_RESERVED.has(seg[0])) return ok('Instagram', 'account');
    return ok('Instagram', 'post');
  }
  if (host === 'vm.tiktok.com' || host === 'vt.tiktok.com') return ok('TikTok', 'post');
  if (is('tiktok.com')) {
    const first = seg[0] ?? '';
    if (first.startsWith('@')) return ok('TikTok', seg.length === 1 ? 'account' : 'post');
    return ok('TikTok', 'post');
  }
  if (is('x.com') || is('twitter.com')) {
    if (seg[1] === 'status') return ok('X', 'post');
    if (seg.length === 1 && !X_RESERVED.has(seg[0])) return ok('X', 'account');
    return ok('X', 'post');
  }
  if (host === 'fb.watch') return ok('Facebook', 'post');
  if (is('facebook.com') || is('fb.com')) {
    if (seg.some((s) => FACEBOOK_POST_MARKERS.has(s))) return ok('Facebook', 'post');
    if (seg[0] === 'profile.php' && query.includes('id=')) return ok('Facebook', 'account');
    if (seg.length === 1) return ok('Facebook', 'account');
    return ok('Facebook', 'post');
  }
  return ok('Link', 'post'); // unknown hosts = generic post link, never rejected
}

// ── Cadence helpers (presentational only — derive a plain-English summary of
//    the posting schedule from the existing dialog state; no new state, no new
//    payload keys. Kept module-level + pure so the summary stays in sync with
//    the controls below.) ──
const WEEKDAY_LABELS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

function formatStartLabel(iso: string): string {
  if (!iso) return 'today';
  const d = new Date(iso + 'T00:00:00');
  return isNaN(d.getTime()) ? 'today' : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}

function describeRecurrence(r: ScheduleRecurrence): string {
  const { interval, unit, byDays } = r;
  const freq =
    unit === 'day'
      ? interval === 1 ? 'daily' : interval === 2 ? 'every other day' : `every ${interval} days`
      : unit === 'month'
        ? interval === 1 ? 'monthly' : `every ${interval} months`
        : interval === 1 ? 'weekly' : `every ${interval} weeks`;
  let onDays = '';
  if (unit === 'week' && byDays.length > 0) {
    const labels = [...byDays].sort((a, b) => a - b).map((iso) => WEEKDAY_LABELS[iso - 1] ?? '');
    onDays = ' on ' + (labels.length > 2
      ? `${labels.slice(0, -1).join(', ')} and ${labels[labels.length - 1]}`
      : labels.join(' and '));
  }
  return freq + onDays;
}

/** Duration as a trailing clause (", until <date|N articles>") — '' for ongoing. */
function describeDurationSuffix(mode: string, endDate: string, maxArticles: number): string {
  if (mode === 'until' && endDate) return `, until ${formatStartLabel(endDate)}`;
  if (mode === 'limit') return `, until ${maxArticles} article${maxArticles === 1 ? '' : 's'} are published`;
  return '';
}

interface CreateStrategyDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  defaultName: string;
  onSave: (payload: StrategyPayload) => void;
  isSaving: boolean;
  selectedCount: number;
  selectedKeywords: string[];
  /** EDIT MODE — pass an existing strategy (with its config already parsed) and the
   *  dialog opens pre-filled with every setting instead of create defaults. The
   *  caller still owns persistence: onSave receives the same StrategyPayload and
   *  decides whether to create or PATCH. Omit/null for the normal create flow. */
  editStrategy?: {
    id: number;
    name?: string;
    templateId?: number;
    hierarchyMode?: string;
    publishingMode?: string;
    config?: Record<string, any>;
  } | null;
}

export function CreateStrategyDialog({
  open,
  onOpenChange,
  defaultName,
  onSave,
  isSaving,
  selectedCount,
  selectedKeywords,
  editStrategy = null,
}: CreateStrategyDialogProps) {
  const isEdit = !!editStrategy;
  const [name, setName] = useState(selectedKeywords?.[0] || '');
  const [templateId, setTemplateId] = useState<string>('');
  const [modelId, setModelId] = useState<string>('');
  // Per-JOB models: text (modelId above) writes the article, imageModelId draws the
  // images, researchModelId runs the grounded/deep research passes. Each is optional —
  // empty falls back to that job's own historical default on the server.
  const [imageModelId, setImageModelId] = useState<string>('');
  const [researchModelId, setResearchModelId] = useState<string>('');
  /** Image PROMPT template (module 'image'); '' = the built-in default prompt. */
  const [imageTemplateId, setImageTemplateId] = useState<string>('');
  // Typed-in keywords — an alternative to picking rows in the Keyword Explorer, so a
  // strategy can be started without touching the table at all. ONE primary (the main
  // target, always first in the resulting list) plus any number of supporting terms.
  const [manualPrimary, setManualPrimary] = useState<string>('');
  const [manualSupporting, setManualSupporting] = useState<string[]>([]);
  const [supportingDraft, setSupportingDraft] = useState<string>('');
  const [structure, setStructure] = useState('individual');

  const [hierarchyMode, setHierarchyMode] = useState('parent_and_children');
  const [parentTargetType, setParentTargetType] = useState('custom');
  const [parentTargetUrl, setParentTargetUrl] = useState('');
  const [parentKeywordIndex, setParentKeywordIndex] = useState(0);

  const [autoInterlink, setAutoInterlink] = useState(false);
  const [interlinkAnchorMode, setInterlinkAnchorMode] = useState('keyword');
  const [interlinkQuantity, setInterlinkQuantity] = useState(3);
  const [interlinkMode, setInterlinkMode] = useState('auto');
  const [featuredImages, setFeaturedImages] = useState(true);
  const [inContentMedia, setInContentMedia] = useState(true);
  const [mediaType, setMediaType] = useState('both');
  const [mediaCount, setMediaCount] = useState(3);
  const [mediaGuidance, setMediaGuidance] = useState('');

  // ── Filip's sections ──
  const [sourceMode, setSourceMode] = useState('keywords');
  const [rssFeeds, setRssFeeds] = useState<string[]>(['']);
  const [socialLinks, setSocialLinks] = useState<string[]>(['']);
  const [rssAngle, setRssAngle] = useState('');
  const [rssPerWeek, setRssPerWeek] = useState(3);
  /** Period the cadence cap is measured over — 'week' keeps the historical behaviour. */
  const [rssCadenceUnit, setRssCadenceUnit] = useState<string>('week');
  // RSS/Social publish cadence: false = publish as posts arrive (capped by
  // rssPerWeek); true = drip-publish each generated article on the recurrence
  // below (the recurrence replaces posts-per-week).
  const [socialScheduled, setSocialScheduled] = useState(false);
  const [trigger, setTrigger] = useState('manual');
  const [publishing, setPublishing] = useState('draft');
  const [durationMode, setDurationMode] = useState('ongoing');
  const [durationEndDate, setDurationEndDate] = useState('');
  const [durationMaxArticles, setDurationMaxArticles] = useState(10);
  // Research checklist — default = Search landscape only (mirrors the old
  // "grounded" single-pass default, now the 'landscape' pass).
  const [researchLandscape, setResearchLandscape] = useState(true);
  const [researchQuestions, setResearchQuestions] = useState(false);
  const [researchGaps, setResearchGaps] = useState(false);

  const [siteId, setSiteId] = useState<string>('');
  const [approvalMode, setApprovalMode] = useState('none');
  const [recurrence, setRecurrence] = useState<ScheduleRecurrence>(() => recurrenceFromConfig({}));
  const [startDate, setStartDate] = useState('');

  React.useEffect(() => {
    if (open) {
      setName(selectedKeywords?.[0] || '');
      setTemplateId('');
      setModelId('');
      setImageModelId('');
      setResearchModelId('');
      setImageTemplateId('');
      setManualPrimary('');
      setManualSupporting([]);
      setSupportingDraft('');
      setStructure('individual');
      setHierarchyMode('parent_and_children');
      setParentTargetType('custom');
      setParentTargetUrl('');
      setParentKeywordIndex(0);
      setAutoInterlink(false);
      setInterlinkQuantity(3);
      setInterlinkMode('auto');
      setFeaturedImages(true);
      setInContentMedia(true);
      setMediaType('both');
      setMediaCount(3);
    setMediaGuidance('');
    setInterlinkAnchorMode('keyword');
      // Opened with no keywords selected (header "New Strategy" button) →
      // the keyword source is a dead end, so start on Social media.
      setSourceMode(selectedCount === 0 ? 'social' : 'keywords');
      setRssFeeds(['']);
      setSocialLinks(['']);
      setRssAngle('');
      setRssPerWeek(3);
      setRssCadenceUnit('week');
      setSocialScheduled(false);
      setTrigger('manual');
      setPublishing('draft');
      setDurationMode('ongoing');
      setDurationEndDate('');
      setDurationMaxArticles(10);
      setResearchLandscape(true);
      setResearchQuestions(false);
      setResearchGaps(false);
      setSiteId('');
      setApprovalMode('none');
      setRecurrence(recurrenceFromConfig({}));
      setStartDate('');

      // ── EDIT MODE: overlay the strategy's SAVED values on top of the defaults
      //    above. Overlaying (rather than replacing the whole block) means any key
      //    a strategy doesn't carry keeps its sane default instead of going
      //    undefined — and create mode is untouched by construction. ──
      if (editStrategy) {
        const cfg = editStrategy.config ?? {};
        const s = (v: unknown) => (v === undefined || v === null ? '' : String(v));
        setName(s(editStrategy.name));
        if (editStrategy.templateId) setTemplateId(String(editStrategy.templateId));
        if (editStrategy.hierarchyMode) setHierarchyMode(String(editStrategy.hierarchyMode));

        if (cfg.model !== undefined) setModelId(s(cfg.model));
        if (cfg.imageModel !== undefined) setImageModelId(s(cfg.imageModel));
        if (cfg.researchModel !== undefined) setResearchModelId(s(cfg.researchModel));
        if (cfg.imageTemplateId) setImageTemplateId(String(cfg.imageTemplateId));
        if (cfg.structure) setStructure(s(cfg.structure));
        if (cfg.parentTargetUrl !== undefined) {
          setParentTargetUrl(s(cfg.parentTargetUrl));
          if (cfg.parentTargetUrl) setParentTargetType('custom');
        }
        if (cfg.approvalMode) setApprovalMode(s(cfg.approvalMode));
        if (cfg.siteId) setSiteId(String(cfg.siteId));
        if (cfg.featuredImages !== undefined) setFeaturedImages(!!cfg.featuredImages);
        if (cfg.inContentMedia !== undefined) setInContentMedia(!!cfg.inContentMedia);
        if (cfg.mediaType) setMediaType(s(cfg.mediaType));
        if (cfg.mediaCount !== undefined) setMediaCount(Number(cfg.mediaCount) || 3);
        if (cfg.mediaGuidance !== undefined) setMediaGuidance(s(cfg.mediaGuidance));
        if (cfg.sourceMode) setSourceMode(s(cfg.sourceMode));
        if (Array.isArray(cfg.rssFeeds) && cfg.rssFeeds.length) setRssFeeds(cfg.rssFeeds.map(String));
        if (Array.isArray(cfg.socialLinks) && cfg.socialLinks.length) setSocialLinks(cfg.socialLinks.map(String));
        if (cfg.rssAngle !== undefined) setRssAngle(s(cfg.rssAngle));
        if (cfg.rssCadence?.perWeek) setRssPerWeek(Number(cfg.rssCadence.perWeek) || 3);
        if (cfg.rssCadence?.unit) setRssCadenceUnit(s(cfg.rssCadence.unit));
        if (cfg.trigger) setTrigger(s(cfg.trigger));
        if (cfg.publishing) setPublishing(s(cfg.publishing));
        if (editStrategy.publishingMode === 'schedule') setSocialScheduled(true);
        if (cfg.duration?.mode) {
          setDurationMode(s(cfg.duration.mode));
          if (cfg.duration.endDate) setDurationEndDate(s(cfg.duration.endDate));
          if (cfg.duration.maxArticles) setDurationMaxArticles(Number(cfg.duration.maxArticles) || 10);
        }
        if (Array.isArray(cfg.researchPasses)) {
          setResearchLandscape(cfg.researchPasses.includes('landscape'));
          setResearchQuestions(cfg.researchPasses.includes('questions'));
          setResearchGaps(cfg.researchPasses.includes('gaps'));
        }
        if (cfg.interlinksConfig) {
          setAutoInterlink(true);
          if (cfg.interlinksConfig.mode) setInterlinkMode(s(cfg.interlinksConfig.mode));
          if (cfg.interlinksConfig.quantity) setInterlinkQuantity(Number(cfg.interlinksConfig.quantity) || 3);
          if (cfg.interlinksConfig.anchorMode) setInterlinkAnchorMode(s(cfg.interlinksConfig.anchorMode));
        }
        if (cfg.scheduleConfig) {
          setRecurrence(recurrenceFromConfig(cfg.scheduleConfig));
          if (cfg.scheduleConfig.startDate) setStartDate(String(cfg.scheduleConfig.startDate).slice(0, 10));
        }
      }
    }
    // editStrategy is read only while opening — re-running on its identity would
    // stomp edits the user is mid-way through making.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const { data: templates, isLoading: templatesLoading } = trpc.templates.list.useQuery({ module: 'writer' });
  /** Writer templates written for the CURRENT source, best-fit first. */
  const sourceTemplates = React.useMemo(() => {
    const all = Array.isArray(templates) ? (templates as any[]) : [];
    return all.filter((t) => templateFitsSource(t, sourceMode));
  }, [templates, sourceMode]);

  // Map the proper template when the source changes: if the current pick isn't written
  // for this source (e.g. an RSS template left selected after switching to Keywords,
  // whose {{ post_* }} variables would resolve to nothing), swap to the first template
  // that IS. An explicit pick that already fits is never touched.
  React.useEffect(() => {
    if (!open) return;
    const all = Array.isArray(templates) ? (templates as any[]) : [];
    if (all.length === 0) return;
    const current = all.find((t) => String(t.id) === templateId);
    if (current && templateFitsSource(current, sourceMode)) return;
    const best = sourceTemplates[0];
    if (best) setTemplateId(String(best.id));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, sourceMode, templates]);
  // Image-PROMPT templates (module 'image') — the wording used for the featured image.
  const { data: imageTemplates } = trpc.templates.list.useQuery({ module: 'image' });
  const { data: genModels = [], isLoading: modelsLoading } = trpc.models.getForGeneration.useQuery(
    { type: 'text' },
    { staleTime: 30_000 },
  );
  // Image-capable models for the per-job Image model picker (same registry, image type).
  const { data: imgModels = [], isLoading: imgModelsLoading } = trpc.models.getForGeneration.useQuery(
    { type: 'image' },
    { staleTime: 30_000 },
  );
  const { data: sites = [], isLoading: sitesLoading } = trpc.sites.list.useQuery(undefined, {
    staleTime: 30_000,
  });
  // Is Apify actually set up? Watched Instagram/TikTok/X/Facebook ACCOUNTS need it.
  // When it IS configured, saying "API key required" in warning colours is just noise —
  // the thing works. Only the missing case is a real problem worth flagging.
  const { data: integrations = [] } = trpc.integrations.list.useQuery(undefined, { staleTime: 30_000 });
  const hasApifyKey = React.useMemo(
    () => (integrations as any[]).some((i) => i?.provider === 'apify' && !!i?.apiKey && !!i?.isActive),
    [integrations],
  );

  // Default the Target Site to the first connected site (mirrors AutoPress, where a
  // strategy is always bound to a site). Functional updater only fills an EMPTY
  // selection, so it never clobbers an explicit choice nor races the open-reset effect.
  React.useEffect(() => {
    if (!open) return;
    const list = sites as any[];
    if (list.length > 0) {
      setSiteId((cur) => cur || String(list[0].id));
    }
  }, [open, sites]);

  // RSS/Social reposting has its own seeded template ("RSS Reposting" /
  // "Social Media Reposting") that carries the {{ post_* }} variables — the
  // full prompt is IN the template, no hidden code rider. Auto-select it when
  // the source is a feed so the visible reposting prompt is the default. Only
  // fills an empty selection or swaps between the two reposting templates, so a
  // deliberate manual pick is never clobbered.
  React.useEffect(() => {
    if (!open) return;
    const list = (templates as any[]) ?? [];
    if (list.length === 0) return;
    const repostIds = new Set(
      list.filter((t) => t.name === 'Social Media Reposting' || t.name === 'RSS Reposting').map((t) => String(t.id)),
    );
    const wanted =
      sourceMode === 'social' ? 'Social Media Reposting'
      : sourceMode === 'rss' ? 'RSS Reposting'
      : null;
    if (!wanted) {
      // Back to Keywords: a reposting template renders empty {{ post_* }} tokens,
      // so drop it (only if it was one of the auto-selected reposting ones).
      setTemplateId((cur) => (repostIds.has(cur) ? '' : cur));
      return;
    }
    const match = list.find((t) => t.name === wanted);
    if (!match) return;
    setTemplateId((cur) => (cur === '' || repostIds.has(cur) ? String(match.id) : cur));
  }, [open, sourceMode, templates]);

  // Consolidated = one article for all keywords, so it can't have separate
  // parent + children articles — its single article can only be the pillar
  // itself ('parent_only') or a child of an existing page ('children_only').
  // Switching to consolidated auto-corrects an in-flight "Parent + Children"
  // pick to "Parent (this is the pillar)"; switching back to individual
  // auto-corrects "parent_only" (not offered there) back to "Parent + Children".
  const handleStructureChange = (value: string) => {
    setStructure(value);
    if (value === 'consolidated' && hierarchyMode === 'parent_and_children') {
      setHierarchyMode('parent_only');
    } else if (value === 'individual' && hierarchyMode === 'parent_only') {
      setHierarchyMode('parent_and_children');
    }
  };

  // RSS needs at least one feed URL the backend will accept — mirror its
  // http(s) scheme check so a typo'd feed can't create a strategy the
  // watcher would silently skip forever.
  const validFeeds = rssFeeds.map((f) => f.trim()).filter((f) => /^https?:\/\//i.test(f));
  // Social mirrors the same scheme check: post/account links the backend
  // classifier can't fetch would otherwise create a strategy that never fires.
  const validSocialLinks = socialLinks.map((l) => l.trim()).filter((l) => /^https?:\/\//i.test(l));
  const missingSite = publishing === 'auto' && !siteId;
  const missingFeeds = sourceMode === 'rss' && validFeeds.length === 0;
  const missingSocialLinks = sourceMode === 'social' && validSocialLinks.length === 0;
  // 'Until date' with no date would store an inert duration (backend drops the
  // invalid endDate but keeps mode 'until', which then never blocks).
  const missingEndDate = durationMode === 'until' && !durationEndDate;

  const handleSave = () => {
    if (!templateId) return;

    const finalName = name.trim() ? name.trim() : defaultName;
    const selectedModel = (genModels as any[]).find((m) => m.modelId === modelId);
    // Each job carries its own model + that model's provider, so the server can route
    // them independently. Left undefined when unset → server-side default applies.
    const selectedImageModel = (imgModels as any[]).find((m) => m.modelId === imageModelId);
    const selectedResearchModel = (genModels as any[]).find((m) => m.modelId === researchModelId);
    // Belt-and-braces: the UI shouldn't allow parent_and_children under consolidated
    // (the option isn't offered + auto-corrected on structure change), but guard the
    // payload anyway in case state gets here some other way.
    const effectiveHierarchy =
      structure === 'consolidated' && hierarchyMode === 'parent_and_children'
        ? 'parent_only'
        : hierarchyMode;

    // ── Filip's sections → FROZEN CONFIG CONTRACT ──
    // RSS/social lock the GENERATION trigger to 'new_source_item' (the watcher
    // pulls on new source items); keywords use manual/scheduled.
    const isSourceFeed = sourceMode === 'rss' || sourceMode === 'social';
    const effectiveTrigger = isSourceFeed ? 'new_source_item' : trigger;
    // publishingMode 'schedule' when the user picked a recurrence:
    //   keywords → trigger==='scheduled'; rss/social → socialScheduled (drip).
    // Otherwise draft/publish per the Publishing section.
    const wantsSchedule = isSourceFeed ? socialScheduled : effectiveTrigger === 'scheduled';
    const publishingMode =
      wantsSchedule ? 'schedule' : publishing === 'auto' ? 'publish' : 'draft';

    const researchPasses = [
      researchLandscape ? 'landscape' : null,
      researchQuestions ? 'questions' : null,
      researchGaps ? 'gaps' : null,
    ].filter(Boolean) as string[];

    const duration =
      durationMode === 'until'
        ? { mode: 'until', endDate: durationEndDate }
        : durationMode === 'limit'
          ? { mode: 'limit', maxArticles: durationMaxArticles }
          : { mode: 'ongoing' };

    // Duration drives the schedule too: for a scheduled strategy the pick here
    // overrides the recurrence's own `ends` (the plan's "scheduled maps onto
    // recurrence ends") — the engine's date spreader already honors ends caps.
    const scheduledRecurrence =
      durationMode === 'until' && durationEndDate
        ? { ...recurrence, ends: { type: 'on' as const, date: durationEndDate } }
        : durationMode === 'limit'
          ? { ...recurrence, ends: { type: 'after' as const, count: durationMaxArticles } }
          : recurrence;

    onSave({
      name: finalName,
      templateId: parseInt(templateId, 10),
      model: modelId || undefined,
      provider: selectedModel?.provider || undefined,
      imageModel: imageModelId || undefined,
      imageProvider: selectedImageModel?.provider || undefined,
      researchModel: researchModelId || undefined,
      imageTemplateId: imageTemplateId ? parseInt(imageTemplateId, 10) : undefined,
      manualKeywords: manualKeywords.length > 0 ? manualKeywords : undefined,
      manualPrimaryKeyword: manualPrimary.trim() || undefined,
      researchProvider: selectedResearchModel?.provider || undefined,
      structure,
      hierarchyMode: effectiveHierarchy,
      parentTargetUrl: effectiveHierarchy === 'children_only' ? parentTargetUrl : undefined,
      parentKeyword: effectiveHierarchy === 'parent_and_children' ? selectedKeywords[parentKeywordIndex] : undefined,
      publishingMode,
      siteId: siteId ? parseInt(siteId, 10) : undefined,
      approvalMode,
      featuredImages,
      inContentMedia,
      ...(inContentMedia ? { mediaType, mediaCount, ...(mediaGuidance.trim() ? { mediaGuidance: mediaGuidance.trim() } : {}) } : {}),
      // researchPasses is the source of truth now; `research` stays for back-compat
      // display paths. researchMode is intentionally NOT sent.
      research: researchPasses.length > 0,
      researchPasses,
      interlinksConfig: autoInterlink ? { mode: interlinkMode, quantity: interlinkQuantity, anchorMode: interlinkAnchorMode } : undefined,
      scheduleConfig: publishingMode === 'schedule' ? { ...recurrenceToConfig(scheduledRecurrence), startDate } : undefined,
      // Filip's sections
      sourceMode,
      ...(sourceMode === 'rss'
        ? { rssFeeds: validFeeds, ...(rssAngle.trim() ? { rssAngle: rssAngle.trim() } : {}), rssCadence: { perWeek: rssPerWeek, unit: rssCadenceUnit } }
        : {}),
      // Social rides the rssAngle/rssCadence keys on purpose — the backend rider
      // and backpressure read those regardless of sourceMode.
      ...(sourceMode === 'social'
        ? { socialLinks: validSocialLinks, ...(rssAngle.trim() ? { rssAngle: rssAngle.trim() } : {}), rssCadence: { perWeek: rssPerWeek, unit: rssCadenceUnit } }
        : {}),
      trigger: effectiveTrigger,
      publishing,
      duration,
    });
  };

  // Typed keywords, primary FIRST, trimmed + de-duped case-insensitively against
  // each other and the table selection (typing a term you already picked must not
  // create a second article for it).
  const manualKeywords = React.useMemo(() => {
    const seen = new Set((selectedKeywords ?? []).map((k) => k.trim().toLowerCase()));
    const out: string[] = [];
    for (const raw of [manualPrimary, ...manualSupporting]) {
      const kw = raw.trim();
      const key = kw.toLowerCase();
      if (kw === '' || seen.has(key)) continue;
      seen.add(key);
      out.push(kw);
    }
    return out;
  }, [manualPrimary, manualSupporting, selectedKeywords]);

  /** Commit the draft as one or more supporting keywords. Splits on commas so a
   *  pasted "a, b, c" lands as three chips, trims, and drops case-insensitive
   *  duplicates (incl. the primary) so the same term never yields two articles. */
  const addSupporting = React.useCallback((raw: string) => {
    const parts = raw.split(',').map((p) => p.trim()).filter(Boolean);
    if (parts.length === 0) { setSupportingDraft(''); return; }
    setManualSupporting((prev) => {
      const seen = new Set([...prev, manualPrimary].map((k) => k.trim().toLowerCase()).filter(Boolean));
      const next = [...prev];
      for (const p of parts) {
        const key = p.toLowerCase();
        if (seen.has(key)) continue;
        seen.add(key);
        next.push(p);
      }
      return next;
    });
    setSupportingDraft('');
  }, [manualPrimary]);

  const articleCount = (selectedKeywords?.length ?? 0) + manualKeywords.length;
  const missingKeywords = !isEdit && sourceMode === 'keywords' && articleCount === 0;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[550px] max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>
            {isEdit ? 'Strategy settings' : (selectedCount > 0 ? `Create Strategy (${selectedCount} Keywords)` : 'Create Strategy')}
          </DialogTitle>
        </DialogHeader>

        <div className="py-2 space-y-3">

          {/* ── Basics (top strip — NOT an accordion) ── */}
          <div className="space-y-3">
            <div className="space-y-1.5">
              <Label htmlFor="strategy-name">Strategy Name (Optional)</Label>
              <Input
                id="strategy-name"
                placeholder={defaultName}
                value={name}
                onChange={(e) => setName(e.target.value)}
                className="w-full bg-card"
              />
            </div>
            {/* Prompt + model pickers used to live here; they now sit together in
                CONTENT (one prompt + one model per job: text and image). */}
          </div>

          {/* ① SOURCE */}
          <AccordionSection title="Source" defaultOpen>
            <Segmented
              value={sourceMode}
              onChange={setSourceMode}
              options={[
                { value: 'keywords', label: 'Keywords' },
                { value: 'rss', label: 'RSS feeds' },
                { value: 'social', label: 'Social media' },
              ]}
            />

            {/* The TEMPLATE + MODEL that this source maps to — the real pickers, not
                a read-only summary pointing at another accordion (which is what used
                to be here). Placed directly under the source tabs so choosing a
                source and choosing what writes it is ONE motion.
                The list is ordered fit-first from `sourceTemplates`: templates written
                for the current source lead, and the rest stay selectable but labelled,
                so a deliberate mismatch is still possible while an accidental one is
                obvious. The auto-map effect above already swaps the pick when the
                source changes; this just makes that visible and overridable. */}
            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1.5">
                <Label htmlFor="source-template">Template</Label>
                <Select value={templateId} onValueChange={setTemplateId}>
                  <SelectTrigger id="source-template" className="w-full bg-card">
                    <SelectValue placeholder="Select Template..." />
                  </SelectTrigger>
                  <SelectContent>
                    {templatesLoading ? (
                      <div className="flex items-center p-2 text-sm text-muted-foreground">
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" /> Fetching...
                      </div>
                    ) : templates && (templates as any[]).length > 0 ? (
                      [...(templates as any[])]
                        .sort((a, b) => Number(templateFitsSource(b, sourceMode)) - Number(templateFitsSource(a, sourceMode)))
                        .map((t: any) => (
                          <SelectItem key={t.id} value={t.id.toString()}>
                            {t.name}
                            {!templateFitsSource(t, sourceMode) && (
                              <span className="text-muted-foreground">
                                {sourceMode === 'keywords' ? ' — for RSS/Social' : ' — for Keywords'}
                              </span>
                            )}
                          </SelectItem>
                        ))
                    ) : (
                      <div className="p-2 text-sm text-muted-foreground text-center">
                        No Writer templates found.
                      </div>
                    )}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="source-model" title="Which model writes each article. Leave unset to use the default.">
                  Model
                </Label>
                <Select value={modelId} onValueChange={setModelId}>
                  <SelectTrigger id="source-model" className="w-full bg-card">
                    <SelectValue placeholder={modelsLoading ? 'Loading models…' : 'Default (Gemini 2.5 Flash)'} />
                  </SelectTrigger>
                  <SelectContent>
                    {(genModels as any[]).length > 0 ? (
                      (genModels as any[]).map((m) => (
                        <SelectItem key={m.modelId} value={m.modelId}>
                          {(m.customName || m.originalName || m.modelId)} ({m.provider})
                        </SelectItem>
                      ))
                    ) : (
                      <div className="p-2 text-sm text-muted-foreground text-center">
                        No text models registered — add one in Settings → Models.
                      </div>
                    )}
                  </SelectContent>
                </Select>
              </div>
            </div>

            {/* Kept from the old summary line: when NOTHING fits this source the
                Create button is blocked (it requires templateId), so say why. */}
            {!templatesLoading && sourceTemplates.length === 0 && (
              <div className="text-xs text-destructive">
                No writer template is written for this source
                {sourceMode === 'keywords'
                  ? ' — keyword templates must not rely on the {{ post_* }} variables.'
                  : ' — add one that uses {{ post_title }} / {{ post_content }} / {{ post_link }}.'}
              </div>
            )}

            {sourceMode === 'keywords' ? (
              <div className="space-y-3">
                {(selectedKeywords?.length ?? 0) > 0 && (
                  <p className="text-xs text-muted-foreground">
                    {selectedKeywords.length} keyword{selectedKeywords.length === 1 ? '' : 's'} selected in the table
                  </p>
                )}

                {/* Type keywords directly — no table selection required. */}
                <div className="space-y-1.5">
                  <Label htmlFor="manual-primary">Primary keyword</Label>
                  <Input
                    id="manual-primary"
                    placeholder="e.g. tandimplantat"
                    value={manualPrimary}
                    onChange={(e) => setManualPrimary(e.target.value)}
                  />
                </div>

                <div className="space-y-1.5">
                  <Label htmlFor="manual-supporting">Supporting keywords</Label>
                  <div className="flex gap-2">
                    <Input
                      id="manual-supporting"
                      placeholder="Type a keyword and press Enter"
                      value={supportingDraft}
                      onChange={(e) => setSupportingDraft(e.target.value)}
                      onKeyDown={(e) => {
                        // Enter OR comma commits the term; comma also lets a pasted
                        // "a, b, c" be split apart below.
                        if (e.key === 'Enter' || e.key === ',') {
                          e.preventDefault();
                          addSupporting(supportingDraft);
                        }
                      }}
                      onBlur={() => addSupporting(supportingDraft)}
                    />
                    <Button type="button" variant="outline" onClick={() => addSupporting(supportingDraft)}>
                      <Plus className="h-4 w-4" />
                    </Button>
                  </div>
                  {manualSupporting.length > 0 && (
                    <div className="flex flex-wrap gap-1.5 pt-1">
                      {manualSupporting.map((kw) => (
                        <span
                          key={kw}
                          className="inline-flex items-center gap-1 rounded border border-border bg-muted/40 px-2 py-0.5 text-xs"
                        >
                          {kw}
                          <button
                            type="button"
                            aria-label={`Remove ${kw}`}
                            className="text-muted-foreground hover:text-foreground"
                            onClick={() => setManualSupporting((prev) => prev.filter((k) => k !== kw))}
                          >
                            <X className="h-3 w-3" />
                          </button>
                        </span>
                      ))}
                    </div>
                  )}
                </div>

                {articleCount > 0 ? (
                  <p className="text-xs text-muted-foreground">
                    {articleCount} keyword{articleCount === 1 ? '' : 's'} total
                    {manualKeywords.length > 0 && ` (${manualKeywords.length} typed)`}
                  </p>
                ) : (
                  <p className="text-xs text-destructive">
                    Add a primary keyword above, pick keywords in the table, or switch the source
                    to RSS feeds or Social media.
                  </p>
                )}
              </div>
            ) : sourceMode === 'social' ? (
              <div className="space-y-2">
                <Label>Post or account links</Label>
                {socialLinks.map((link, i) => {
                  const info = classifySocialLink(link);
                  return (
                    <div key={i} className="space-y-0.5">
                      <div className="flex items-center gap-2">
                        <Input
                          type="url"
                          value={link}
                          onChange={(e) =>
                            setSocialLinks((prev) => prev.map((l, idx) => (idx === i ? e.target.value : l)))
                          }
                          placeholder="https://instagram.com/... or any post/account link"
                          className="flex-1 h-8 text-xs bg-card"
                        />
                        {socialLinks.length > 1 && (
                          <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="h-8 w-8 shrink-0"
                            aria-label="Remove link"
                            onClick={() => setSocialLinks((prev) => prev.filter((_, idx) => idx !== i))}
                          >
                            <X className="h-4 w-4" />
                          </Button>
                        )}
                      </div>
                      {info.state === 'invalid' && (
                        <p className="pl-1 text-[0.7rem] text-destructive">
                          Enter a full link starting with https://
                        </p>
                      )}
                      {info.state === 'ok' && (
                        <p
                          className={cn(
                            'pl-1 text-[0.7rem]',
                            // Amber ONLY when this link genuinely can't work: an account
                            // that needs Apify while no Apify key is configured. With a
                            // key present it is an ordinary informational line.
                            info.needsApify && !hasApifyKey
                              ? 'text-amber-600 dark:text-amber-500'
                              : 'text-muted-foreground',
                          )}
                        >
                          {info.kind === 'post'
                            ? `${info.platform === 'Link' ? 'Post link' : `${info.platform} post`} — one article will be created from it`
                            : info.needsApify
                              ? (hasApifyKey
                                  ? `${info.platform} account — watched for new posts via Apify`
                                  : `${info.platform} account — needs an Apify API key to be watched (add it under Integrations)`)
                              : `${info.platform} account — watched for new posts automatically (free)`}
                        </p>
                      )}
                    </div>
                  );
                })}
                {socialLinks.length < 10 && (
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-8 text-xs"
                    onClick={() => setSocialLinks((prev) => (prev.length < 10 ? [...prev, ''] : prev))}
                  >
                    <Plus className="mr-1 h-3.5 w-3.5" /> Add link
                  </Button>
                )}

                <div className="space-y-1.5 pt-1">
                  <Label htmlFor="social-angle">Angle / primary keyword (optional)</Label>
                  <Input
                    id="social-angle"
                    value={rssAngle}
                    onChange={(e) => setRssAngle(e.target.value)}
                    placeholder="e.g. sustainable packaging"
                    className="h-8 text-xs bg-card"
                  />
                </div>

                <p className="text-xs text-muted-foreground text-pretty">
                  Every article embeds a link back to the original post.
                  {!hasApifyKey && ' Watched accounts on Instagram/TikTok/X/Facebook need your Apify API key (add it under Integrations).'}
                </p>
                {missingSocialLinks && (
                  <p className="text-[0.8rem] text-destructive">
                    Add at least one post or account link.
                  </p>
                )}
              </div>
            ) : (
              <div className="space-y-2">
                <Label>Feed URLs</Label>
                {rssFeeds.map((feed, i) => (
                  <div key={i} className="flex items-center gap-2">
                    <Input
                      type="url"
                      value={feed}
                      onChange={(e) =>
                        setRssFeeds((prev) => prev.map((f, idx) => (idx === i ? e.target.value : f)))
                      }
                      placeholder="https://example.com/feed"
                      className="flex-1 h-8 text-xs bg-card"
                    />
                    {rssFeeds.length > 1 && (
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8 shrink-0"
                        aria-label="Remove feed"
                        onClick={() => setRssFeeds((prev) => prev.filter((_, idx) => idx !== i))}
                      >
                        <X className="h-4 w-4" />
                      </Button>
                    )}
                  </div>
                ))}
                {rssFeeds.length < 5 && (
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-8 text-xs"
                    onClick={() => setRssFeeds((prev) => (prev.length < 5 ? [...prev, ''] : prev))}
                  >
                    <Plus className="mr-1 h-3.5 w-3.5" /> Add feed
                  </Button>
                )}

                <div className="space-y-1.5 pt-1">
                  <Label htmlFor="rss-angle">Angle / primary keyword (optional)</Label>
                  <Input
                    id="rss-angle"
                    value={rssAngle}
                    onChange={(e) => setRssAngle(e.target.value)}
                    placeholder="e.g. sustainable packaging"
                    className="h-8 text-xs bg-card"
                  />
                </div>

                <p className="text-xs text-muted-foreground">
                  Watches these feeds and writes a better article on each new item.
                </p>
                {missingFeeds && (
                  <p className="text-[0.8rem] text-destructive">
                    Add at least one feed URL to create an RSS strategy.
                  </p>
                )}
              </div>
            )}
          </AccordionSection>

          {/* ② PUBLISHING — where finished articles go */}
          <AccordionSection title="Publishing" defaultOpen>
            <div className="space-y-3">
              <Segmented
                value={publishing}
                onChange={setPublishing}
                options={[
                  { value: 'draft', label: 'Draft' },
                  { value: 'auto', label: 'Automatic' },
                ]}
              />

              <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1.5">
                  <Label
                    htmlFor="target-site"
                    title="The site this strategy is for. When Publishing is Automatic, each generated article is published here."
                  >
                    Target Site
                  </Label>
                  <Select value={siteId} onValueChange={setSiteId}>
                    <SelectTrigger id="target-site" className="w-full bg-card">
                      <SelectValue placeholder={sitesLoading ? 'Loading sites…' : 'Select a connected site...'} />
                    </SelectTrigger>
                    <SelectContent>
                      {(sites as any[]).length > 0 ? (
                        (sites as any[]).map((s) => (
                          <SelectItem key={s.id} value={s.id.toString()}>
                            {s.name || s.url}
                          </SelectItem>
                        ))
                      ) : (
                        <div className="p-2 text-sm text-muted-foreground text-center">
                          No connected sites — add one in the Sites module.
                        </div>
                      )}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-1.5">
                  <Label>Approvals</Label>
                  <Select value={approvalMode} onValueChange={setApprovalMode}>
                    <SelectTrigger className="w-full bg-card">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">None</SelectItem>
                      <SelectItem value="internal">Internal Only</SelectItem>
                      <SelectItem value="client">Client Only</SelectItem>
                      <SelectItem value="both" title="Both your team and the client must approve before the post publishes.">
                        Internal + Client
                      </SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>

              {missingSite && (
                <p className="text-[0.8rem] text-destructive">
                  {(sites as any[]).length > 0
                    ? 'Select a Target Site above to publish automatically.'
                    : 'Connect a site in the Sites module to publish automatically.'}
                </p>
              )}
            </div>
          </AccordionSection>

          {/* ③ SCHEDULE & CADENCE — how often finished articles publish */}
          <AccordionSection title="Schedule & cadence" defaultOpen>
            <div className="space-y-3">
              {sourceMode === 'keywords' ? (
                <>
                  <div className="space-y-1.5">
                    <Label className="text-[0.7rem] font-semibold uppercase tracking-wider text-muted-foreground">
                      When to publish
                    </Label>
                    <Segmented
                      value={trigger}
                      onChange={setTrigger}
                      options={[
                        { value: 'manual', label: 'On demand' },
                        { value: 'scheduled', label: 'On a schedule' },
                      ]}
                    />
                  </div>

                  {trigger === 'scheduled' && (
                    <div className="grid grid-cols-2 gap-3">
                      <div className="space-y-1.5">
                        <Label>Frequency</Label>
                        <RecurrenceEditor value={recurrence} onChange={setRecurrence} />
                      </div>
                      <div className="space-y-1.5">
                        <Label>Start date</Label>
                        <Input
                          type="date"
                          value={startDate}
                          onChange={(e) => setStartDate(e.target.value)}
                          className="w-full bg-card"
                        />
                        <p className="text-[0.7rem] text-muted-foreground">Leave blank to start today.</p>
                      </div>
                    </div>
                  )}

                  <div className="rounded-md border border-border bg-muted/20 p-2.5 text-xs text-foreground text-pretty">
                    {trigger === 'manual'
                      ? `${articleCount} article${articleCount === 1 ? '' : 's'} generate on demand — click Generate in the Strategies list to write each one.`
                      : <>
                          Articles publish{' '}
                          <span className="font-medium">{describeRecurrence(recurrence)}</span>, starting{' '}
                          <span className="font-medium">{formatStartLabel(startDate)}</span>
                          {describeDurationSuffix(durationMode, durationEndDate, durationMaxArticles)}.
                          {articleCount > 0 && (
                            <> Your {articleCount} keyword{articleCount === 1 ? '' : 's'} publish one per slot.</>
                          )}
                        </>
                    }
                  </div>
                </>
              ) : (
                <>
                  <div className="space-y-1.5">
                    <Label className="text-[0.7rem] font-semibold uppercase tracking-wider text-muted-foreground">
                      When to publish
                    </Label>
                    <Segmented
                      value={socialScheduled ? 'scheduled' : 'immediate'}
                      onChange={(v) => setSocialScheduled(v === 'scheduled')}
                      options={[
                        { value: 'immediate', label: 'As posts arrive' },
                        { value: 'scheduled', label: 'On a schedule' },
                      ]}
                    />
                  </div>

                  {!socialScheduled ? (
                    <>
                      <div className="space-y-1.5">
                        <Label htmlFor="rss-per-week">Posting cadence</Label>
                        <div className="flex items-center gap-2">
                          <Input
                            id="rss-per-week"
                            type="number"
                            min={1}
                            max={21}
                            value={rssPerWeek}
                            onChange={(e) => setRssPerWeek(Math.min(21, Math.max(1, parseInt(e.target.value, 10) || 1)))}
                            className="w-20 h-8 text-xs bg-card"
                          />
                          <span className="text-xs text-muted-foreground">posts per</span>
                          <Select value={rssCadenceUnit} onValueChange={setRssCadenceUnit}>
                            <SelectTrigger className="h-8 w-24 text-xs bg-card">
                              <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                              <SelectItem value="day">day</SelectItem>
                              <SelectItem value="week">week</SelectItem>
                              <SelectItem value="month">month</SelectItem>
                            </SelectContent>
                          </Select>
                        </div>
                      </div>
                      <div className="rounded-md border border-border bg-muted/20 p-2.5 text-xs text-foreground text-pretty">
                        New {sourceMode === 'social' ? 'posts' : 'feed items'} publish up to{' '}
                        <span className="font-medium">{rssPerWeek}</span> per {rssCadenceUnit}. Anything extra queues for the
                        next free slot — your site is never flooded.
                      </div>
                    </>
                  ) : (
                    <>
                      <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1.5">
                          <Label>Frequency</Label>
                          <RecurrenceEditor value={recurrence} onChange={setRecurrence} />
                        </div>
                        <div className="space-y-1.5">
                          <Label>Start date</Label>
                          <Input
                            type="date"
                            value={startDate}
                            onChange={(e) => setStartDate(e.target.value)}
                            className="w-full bg-card"
                          />
                          <p className="text-[0.7rem] text-muted-foreground">Leave blank to start today.</p>
                        </div>
                      </div>
                      <div className="rounded-md border border-border bg-muted/20 p-2.5 text-xs text-foreground text-pretty">
                        New {sourceMode === 'social' ? 'posts' : 'feed items'} are pulled automatically, then each
                        article publishes{' '}
                        <span className="font-medium">{describeRecurrence(recurrence)}</span>, starting{' '}
                        <span className="font-medium">{formatStartLabel(startDate)}</span>
                        {describeDurationSuffix(durationMode, durationEndDate, durationMaxArticles)} — one per slot.
                      </div>
                    </>
                  )}
                </>
              )}
            </div>
          </AccordionSection>

          {/* ④ DURATION — how long it keeps running */}
          <AccordionSection title="Duration">
            <div className="space-y-3">
              <Segmented
                value={durationMode}
                onChange={setDurationMode}
                options={[
                  { value: 'ongoing', label: 'Ongoing' },
                  { value: 'until', label: 'Until date' },
                  { value: 'limit', label: 'Article limit' },
                ]}
              />
              {durationMode === 'until' && (
                <div className="space-y-1.5">
                  <Label htmlFor="duration-end">End date</Label>
                  <Input
                    id="duration-end"
                    type="date"
                    value={durationEndDate}
                    onChange={(e) => setDurationEndDate(e.target.value)}
                    className="w-full bg-card"
                  />
                  {missingEndDate && (
                    <p className="text-[0.75rem] text-destructive">Pick an end date to use “Until date”.</p>
                  )}
                </div>
              )}
              {durationMode === 'limit' && (
                <div className="space-y-1.5">
                  <Label htmlFor="duration-max">Maximum articles</Label>
                  <Input
                    id="duration-max"
                    type="number"
                    min={1}
                    value={durationMaxArticles}
                    onChange={(e) => setDurationMaxArticles(Math.max(1, parseInt(e.target.value, 10) || 1))}
                    className="w-24 h-8 text-xs bg-card"
                  />
                </div>
              )}
            </div>
          </AccordionSection>

          {/* ⑤ CONTENT — how each article is written (incl. research) */}
          {/* defaultOpen: the REQUIRED content prompt lives in here now, and the
              Create button is disabled until it is picked — a collapsed section
              would hide the only thing blocking submission. */}
          <AccordionSection title="Content" defaultOpen>
            <div className="space-y-4">
              {/* IMAGE prompt + IMAGE model. The TEXT pair (Template + Model) now
                  lives in SOURCE, where choosing a source and choosing what writes it
                  is one motion — keeping a second copy here would be the same state in
                  two accordions, free to disagree. */}
              <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1.5">
                  <Label htmlFor="image-prompt" title="Template that writes the featured-image prompt. Leave as Default to use the built-in wording.">
                    Image prompt
                  </Label>
                  <Select value={imageTemplateId || 'default'} onValueChange={(v) => setImageTemplateId(v === 'default' ? '' : v)}>
                    <SelectTrigger id="image-prompt" className="w-full bg-card">
                      <SelectValue placeholder="Default image prompt" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="default">Default image prompt</SelectItem>
                      {((imageTemplates as any[]) ?? []).map((t: any) => (
                        <SelectItem key={t.id} value={String(t.id)}>{t.name}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>

                <div className="space-y-1.5">
                  <Label htmlFor="image-model" title="Which model generates the images. Leave unset to use the default.">
                    Image AI model
                  </Label>
                  <Select value={imageModelId} onValueChange={setImageModelId}>
                    <SelectTrigger id="image-model" className="w-full bg-card">
                      <SelectValue placeholder={imgModelsLoading ? 'Loading models…' : 'Default (DALL·E 3)'} />
                    </SelectTrigger>
                    <SelectContent>
                      {(imgModels as any[]).length > 0 ? (
                        (imgModels as any[]).map((m) => (
                          <SelectItem key={m.modelId} value={m.modelId}>
                            {(m.customName || m.originalName || m.modelId)} ({m.provider})
                          </SelectItem>
                        ))
                      ) : (
                        <div className="p-2 text-sm text-muted-foreground text-center">
                          No image models registered — add one in Settings → Models.
                        </div>
                      )}
                    </SelectContent>
                  </Select>
                </div>
              </div>

              {sourceMode === 'keywords' ? (
              <>
              <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1.5">
                  <Label>Content per Keyword</Label>
                  <Select value={structure} onValueChange={handleStructureChange}>
                    <SelectTrigger className="w-full bg-card">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="individual">1 per Keyword</SelectItem>
                      <SelectItem value="consolidated">Consolidated</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-1.5">
                  <Label>Content Hierarchy</Label>
                  <Select value={hierarchyMode} onValueChange={setHierarchyMode}>
                    <SelectTrigger className="w-full bg-card">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="children_only">Children of an Existing Page</SelectItem>
                      {structure === 'consolidated' ? (
                        <SelectItem value="parent_only">Parent (this is the pillar)</SelectItem>
                      ) : (
                        <SelectItem value="parent_and_children">Parent + Children</SelectItem>
                      )}
                    </SelectContent>
                  </Select>
                  {structure === 'consolidated' && (
                    <p className="text-xs text-muted-foreground">
                      A consolidated strategy produces one article — it can be a child of an existing page, but not a parent.
                    </p>
                  )}
                </div>
              </div>

              {hierarchyMode === 'children_only' && (
                <div className="space-y-1.5">
                  <Label htmlFor="parent-url" title="All generated content will link to this parent.">
                    Target Parent URL
                  </Label>
                  <Input
                     id="parent-url"
                     type="url"
                     value={parentTargetUrl}
                     onChange={(e) => setParentTargetUrl(e.target.value)}
                     placeholder="https://example.com/parent-page"
                     className="w-full bg-card"
                  />
                </div>
              )}

              {structure !== 'consolidated' && hierarchyMode === 'parent_and_children' && selectedKeywords?.length > 0 && (
                <div className="space-y-1.5">
                  <Label title="First article becomes parent, others link to it.">Select Parent Keyword</Label>
                  <KeywordPicker
                    keywords={selectedKeywords}
                    selectedIndex={parentKeywordIndex}
                    onSelect={setParentKeywordIndex}
                    selectedLabel="Parent"
                    unselectedLabel={null}
                    maxHeight="8rem"
                  />
                </div>
              )}

              </>
              ) : (
                <p className="text-xs text-muted-foreground">
                  One article is written per {sourceMode === 'social' ? 'post' : 'feed item'} — there is no keyword hierarchy to set.
                </p>
              )}

              <div className="space-y-2">
                <Label className="text-xs text-muted-foreground">Generation options</Label>
                <div className="space-y-2">
                  <div
                    className="flex items-center space-x-2"
                    title="Automatically generate a featured image for each article when content is generated."
                  >
                    <Checkbox
                      id="featured-images"
                      checked={featuredImages}
                      onCheckedChange={(c) => setFeaturedImages(c as boolean)}
                    />
                    <Label htmlFor="featured-images" className="cursor-pointer font-medium leading-none">
                      Generate featured images
                    </Label>
                  </div>

                  <div
                    className="flex items-center space-x-2"
                    title="Let the writer place supporting images and charts inline within each article body."
                  >
                    <Checkbox
                      id="in-content-media"
                      checked={inContentMedia}
                      onCheckedChange={(c) => setInContentMedia(c as boolean)}
                    />
                    <Label htmlFor="in-content-media" className="cursor-pointer font-medium leading-none">
                      In-content images &amp; charts
                    </Label>
                  </div>

                  {/* (Image model moved up to the prompt/model grid at the top of Content.) */}

                  {inContentMedia && (
                    <div className="ml-6 flex items-center gap-3 text-sm">
                      <span className="font-medium text-muted-foreground">Type:</span>
                      <Select value={mediaType} onValueChange={setMediaType}>
                        <SelectTrigger className="h-8 w-32 text-xs bg-card">
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="images">Images</SelectItem>
                          <SelectItem value="charts">Charts</SelectItem>
                          <SelectItem value="both">Images + Charts</SelectItem>
                        </SelectContent>
                      </Select>
                      <span className="font-medium text-muted-foreground">Count:</span>
                      <Input
                        type="number"
                        min={1}
                        max={8}
                        value={mediaCount}
                        onChange={(e) => setMediaCount(parseInt(e.target.value, 10) || 3)}
                        className="w-16 h-8 text-xs bg-card"
                      />
                    </div>
                  )}

                  {inContentMedia && (
                    <div className="ml-6">
                      <Input
                        value={mediaGuidance}
                        onChange={(e) => setMediaGuidance(e.target.value)}
                        maxLength={500}
                        placeholder="Optional: what should the images show / what data should the charts present? e.g. “clean product photos; charts comparing yearly market growth”"
                        className="h-8 text-xs bg-card"
                        title="Creative direction passed to the writer: image subjects/style and the data charts should visualize."
                      />
                    </div>
                  )}

                  <div
                    className="flex items-center space-x-2"
                    title="Automatically inject internal links between articles when content is generated."
                  >
                    <Checkbox
                      id="auto-interlink"
                      checked={autoInterlink}
                      onCheckedChange={(c) => setAutoInterlink(c as boolean)}
                    />
                    <Label htmlFor="auto-interlink" className="cursor-pointer font-medium leading-none">
                      Auto-Interlink after generation
                    </Label>
                  </div>

                  {autoInterlink && (
                    <div className="ml-6 flex items-center justify-between gap-4 p-3 bg-muted/20 border rounded-md text-sm">
                      <div className="flex items-center space-x-3">
                        <span className="font-medium text-muted-foreground">Max Links:</span>
                        <Input
                          type="number"
                          min={1}
                          max={10}
                          value={interlinkQuantity}
                          onChange={(e) => setInterlinkQuantity(parseInt(e.target.value) || 3)}
                          className="w-16 h-8 bg-card"
                        />
                      </div>
                      <div className="flex items-center space-x-2">
                        <span className="font-medium text-muted-foreground">Anchor:</span>
                        <Select value={interlinkAnchorMode} onValueChange={setInterlinkAnchorMode}>
                          <SelectTrigger className="h-8 w-40 text-xs bg-card">
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent>
                            <SelectItem value="keyword" title="Link the exact keyword of the destination article where it already appears.">Destination keyword</SelectItem>
                            <SelectItem value="synonym" title="AI finds a synonym of the destination keyword already present in the text and links that.">Synonym</SelectItem>
                            <SelectItem value="ai" title="AI picks the most natural existing phrase to link.">AI decides</SelectItem>
                          </SelectContent>
                        </Select>
                      </div>
                    </div>
                  )}
                </div>
              </div>

              <div className="space-y-2">
                <Label className="text-xs text-muted-foreground">Research depth</Label>
            <div className="space-y-2.5">
              <div
                className="flex items-start space-x-2"
                title="Summarize what currently ranks for the topic before writing."
              >
                <Checkbox
                  id="research-landscape"
                  className="mt-0.5"
                  checked={researchLandscape}
                  onCheckedChange={(c) => setResearchLandscape(c as boolean)}
                />
                <Label htmlFor="research-landscape" className="cursor-pointer font-medium leading-tight">
                  Search landscape
                  <span className="block text-xs font-normal text-muted-foreground">what currently ranks</span>
                </Label>
              </div>

              <div
                className="flex items-start space-x-2"
                title="Pull real questions people ask plus chart-ready statistics."
              >
                <Checkbox
                  id="research-questions"
                  className="mt-0.5"
                  checked={researchQuestions}
                  onCheckedChange={(c) => setResearchQuestions(c as boolean)}
                />
                <Label htmlFor="research-questions" className="cursor-pointer font-medium leading-tight">
                  Questions &amp; data
                  <span className="block text-xs font-normal text-muted-foreground">real questions + chart-ready statistics</span>
                </Label>
              </div>

              <div
                className="flex items-start space-x-2"
                title="Find what rival articles miss so this one covers more."
              >
                <Checkbox
                  id="research-gaps"
                  className="mt-0.5"
                  checked={researchGaps}
                  onCheckedChange={(c) => setResearchGaps(c as boolean)}
                />
                <Label htmlFor="research-gaps" className="cursor-pointer font-medium leading-tight">
                  Competitor gaps
                  <span className="block text-xs font-normal text-muted-foreground">what rivals miss</span>
                </Label>
              </div>

              {/* Per-job model: which model RUNS the research passes — separate from the
                  writer, so a cheap grounded model can feed an expensive one (or vice versa).
                  Only meaningful while at least one research pass is selected. */}
              {(researchLandscape || researchQuestions || researchGaps) && (
                <div className="space-y-1.5 pt-1">
                  <Label
                    htmlFor="research-model"
                    title="Which model runs the research passes. Leave unset to use the default."
                  >
                    Research Model
                  </Label>
                  <Select value={researchModelId} onValueChange={setResearchModelId}>
                    <SelectTrigger id="research-model" className="w-full bg-card">
                      <SelectValue placeholder={modelsLoading ? 'Loading models…' : 'Default (Gemini 2.5 Flash)'} />
                    </SelectTrigger>
                    <SelectContent>
                      {(genModels as any[]).length > 0 ? (
                        (genModels as any[]).map((m) => (
                          <SelectItem key={m.modelId} value={m.modelId}>
                            {(m.customName || m.originalName || m.modelId)} ({m.provider})
                          </SelectItem>
                        ))
                      ) : (
                        <div className="p-2 text-sm text-muted-foreground text-center">
                          No text models registered — add one in Settings → Models.
                        </div>
                      )}
                    </SelectContent>
                  </Select>
                </div>
              )}
            </div>
              </div>
            </div>
          </AccordionSection>


        </div>

        <p className="text-[0.8rem] text-muted-foreground pt-2">
          Generation starts automatically in the background after creation.
        </p>

        <DialogFooter className="pt-4 border-t">
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={isSaving}>
            Cancel
          </Button>
          <Button
            onClick={handleSave}
            disabled={isSaving || !templateId || missingKeywords || missingSite || missingFeeds || missingSocialLinks || missingEndDate}
          >
            {isSaving ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : null}
            {isEdit ? 'Save changes' : 'Create Strategy'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
