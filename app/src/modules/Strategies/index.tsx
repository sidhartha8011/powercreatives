/**
 * STRATEGIES MODULE — Strategy List & Management
 *
 * Displays all content strategies with real-time status.
 * Allows creating, viewing, and managing strategy items.
 * Integrates with the Keywords module for strategy creation
 * and the Writer module for article viewing.
 *
 * Data source: trpc.strategy.list / trpc.strategy.get
 */

import { useState, useCallback, useMemo, useRef, useEffect } from 'react';
import { createPortal } from 'react-dom';
import {
  Layers, ChevronRight, ChevronDown, Play, Pause, Trash2, Zap, RefreshCw,
  CheckCircle2, Clock, AlertCircle, Loader2, FileText, ExternalLink,
  Link2, Send, Crown, X, List, CalendarClock, Copy, Settings2, SlidersHorizontal,
  Rss, Search, Share2, Pencil, Eye,
} from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { colors, typography, shadows, statusColors } from '@/components/shared/design-tokens';
import { trpc } from '@/lib/trpc';
import { useApp } from '@/contexts/AppContext';
import { InterlinkManagerModal } from './InterlinkManagerModal';
import { ScheduleView } from './ScheduleView';
import { ParentSettingsModal } from './ParentSettingsModal';
// THE create dialog, reused in edit mode — the row's settings button must expose the
// SAME fields you set at creation, and reusing it is the only way that stays true.
import { CreateStrategyDialog, type StrategyPayload } from '../Keywords/CreateStrategyDialog';
import { planBulkGenerate, isFinishedItemStatus } from './bulkGenerate';
import { POST_STATUSES, planBulkPostStatus, isDestructiveStatus } from './postStatus';
import { RecurrenceEditor, recurrenceFromConfig, recurrenceToConfig, type ScheduleRecurrence } from './RecurrenceEditor';
// THE SEO page editor, reused verbatim (mode="page"): it self-fetches the served
// content and saves through the existing dynamic-rule paths, so editing a strategy
// article here is byte-for-byte the same operation as editing it from the SEO table.
import { SectionModal } from '../SEO/SectionModal';

// ── Types ──
interface StrategyItem {
  id: number;
  keyword: string;
  title?: string;
  slug?: string;
  status: string;
  articleId?: number;
  position: number;
  errorMessage?: string;
  /** Where the linked article actually published, if it has (backend LEFT JOIN). */
  articlePublishedUrl?: string;
  /** The live post's ID on the connected site — non-null ONLY when the article really
   *  has a post there. Presence is what enables the row's post-status control. */
  articlePublishedPostId?: number | null;
  /** The linked article's local status ('published' | 'draft' | …). */
  articleStatus?: string | null;
  /** The LIVE WordPress status (publish|future|draft|pending|private), or null
   *  when the article is not on a site. Distinct from articleStatus, which is
   *  the local Writer workflow state. */
  articlePublishedStatus?: string | null;
  /** When the linked article actually went live (backend LEFT JOIN, articles.publishedAt). */
  articlePublishedAt?: string | null;
  /** The connected site the article was published to (backend LEFT JOIN) — with
   *  articlePublishedPostId this is what addresses the post for the SEO editor. */
  articleSiteId?: number | null;
  /** Due date for schedule-mode strategies (Y-m-d H:i:s), null otherwise. */
  scheduledDate?: string;
  /** JSON string — per-item overrides (templateId/publishingMode/approvalMode).
   *  Absent keys inherit the strategy's own value. Parse with parseStrategyConfig(). */
  config?: string;
  /** Display-only SEO metrics carried from the Keyword Explorer (F3). Ahrefs
   *  monthly search volume + keyword difficulty; null when never enriched. */
  volume?: number;
  difficulty?: number;
}

/** Item-level publishingMode override options (plus 'inherit' — no override key at all). */
const ITEM_MODE_LABELS: Record<string, string> = {
  inherit: 'Inherit',
  draft: 'Draft',
  publish: 'Auto-publish',
  schedule: 'Scheduled',
};

/** Item-level approvalMode override options (plus 'inherit'). */
const ITEM_APPROVAL_LABELS: Record<string, string> = {
  inherit: 'Inherit',
  none: 'None',
  internal: 'Internal',
  client: 'Client',
  both: 'Internal + Client',
};

interface Strategy {
  id: number;
  name: string;
  status: string;
  brandId?: number;
  templateId?: number;
  hierarchyMode: string;
  publishingMode: string;
  totalItems: number;
  completedItems: number;
  failedItems: number;
  createdAt: string;
  updatedAt: string;
  items?: StrategyItem[];
  /** JSON string — target site (siteId) + generation options. Parse with parseStrategyConfig(). */
  config?: string;
}

/** Compact display label for a feed URL — hostname (sans www) + path, for the
 *  strategy row so the user can tell WHICH feeds an RSS strategy watches. */
function feedHost(url: string): string {
  try {
    const u = new URL(url);
    return `${u.hostname.replace(/^www\./, '')}${u.pathname}`.replace(/\/$/, '').slice(0, 48);
  } catch {
    return url.replace(/^https?:\/\//, '').slice(0, 48);
  }
}

/** Safely parse a strategy's stored config JSON (malformed/absent → {}). */
/** Parse a 'Y-m-d H:i:s' (or ISO) due date; null when absent/unparseable. */
function parseDue(raw?: string | null): Date | null {
  if (!raw) return null;
  const d = new Date(String(raw).replace(' ', 'T'));
  return Number.isNaN(d.getTime()) ? null : d;
}

/** Compact tag label: "Aug 12", plus the year only when it isn't the current one —
 *  a schedule can run into next year, and a bare "Jan 4" there would mislead. */
export function formatItemDue(raw?: string | null): string {
  const d = parseDue(raw);
  if (!d) return '';
  const opts: Intl.DateTimeFormatOptions = d.getFullYear() === new Date().getFullYear()
    ? { month: 'short', day: 'numeric' }
    : { month: 'short', day: 'numeric', year: 'numeric' };
  return d.toLocaleDateString(undefined, opts);
}

/**
 * Which date the row's tag should show, and whether it already happened.
 *
 * PUBLISHED WINS over scheduled: once an article is live, when it WENT live is the
 * fact worth showing; a stale future slot would be a lie. `scheduledDate` is only
 * ever stamped for schedule-mode strategies (calculate_recurrence_dates), which is
 * why draft/auto-publish rows previously showed no tag at all — the tag existed but
 * nothing ever populated it outside schedule mode.
 *
 * Returns null when neither date exists (a pending item in a non-scheduled
 * strategy genuinely has no publish date yet) so the caller renders nothing rather
 * than an empty pill.
 */
export function itemDueTag(item: {
  scheduledDate?: string;
  articlePublishedAt?: string | null;
}): { raw: string; published: boolean } | null {
  const live = String(item.articlePublishedAt ?? '').trim();
  if (live !== '' && parseDue(live)) return { raw: live, published: true };
  const due = String(item.scheduledDate ?? '').trim();
  if (due !== '' && parseDue(due)) return { raw: due, published: false };
  return null;
}

/** Full date + time for the tag's tooltip. */
export function formatItemDueFull(raw?: string | null): string {
  const d = parseDue(raw);
  if (!d) return '';
  return d.toLocaleString(undefined, {
    weekday: 'short', year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
  });
}

function parseStrategyConfig(raw?: string): Record<string, any> {
  if (!raw) return {};
  try {
    const parsed = JSON.parse(raw);
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch {
    return {};
  }
}

const PUBLISHING_MODE_LABELS: Record<string, string> = {
  draft: 'Draft',
  publish: 'Auto-publish',
  schedule: 'Scheduled',
};

/** 3-letter, Mon-first day names — index by ISO 1(Mon)–7(Sun). */
const DAY_SHORT_NAMES = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

/** Compact, truncatable summary of a ScheduleRecurrence for the row button label. */
function summarizeRecurrence(r: ScheduleRecurrence): string {
  let base: string;
  if (r.unit === 'day') base = r.interval === 1 ? 'Daily' : `Every ${r.interval} days`;
  else if (r.unit === 'week') base = r.interval === 1 ? 'Weekly' : `Every ${r.interval} weeks`;
  else base = r.interval === 1 ? 'Monthly' : `Every ${r.interval} months`;

  const parts = [base];
  if (r.byDays.length > 0) {
    parts.push(r.byDays.map((iso) => DAY_SHORT_NAMES[iso - 1]).join(', '));
  }
  if (r.ends.type === 'on') parts.push(`ends ${r.ends.date}`);
  else if (r.ends.type === 'after') parts.push(`×${r.ends.count}`);
  return parts.join(' · ');
}

// ── Status indicator — reusable across Strategies + Approvals ──
/**
 * Item/strategy status pill.
 *
 * "Done generating" is NOT the same as "live". An article that exists but hasn't been
 * pushed to the site reads **Written** (blue); only one that actually has a post on the
 * site reads **Published** (green). Callers pass `published` — derived from the linked
 * article's publish pointers — and it only affects the completed state.
 *
 * NB the design tokens are counter-intuitively named: `statusColors.ready` is GREEN and
 * `statusColors.published` is BLUE, so the mapping below is deliberate, not swapped.
 */
function StatusBadge({ status, published }: { status: string; published?: boolean }) {
  const config: Record<string, { icon: React.ReactNode; label: string; color: string; bg: string }> = {
    pending:      { icon: <Clock className="w-3 h-3" />, label: 'Pending', color: statusColors.draft.text, bg: statusColors.draft.bg },
    in_progress:  { icon: <Loader2 className="w-3 h-3 animate-spin" />, label: 'In Progress', color: colors.primary, bg: colors.primaryLight },
    completed:    { icon: <CheckCircle2 className="w-3 h-3" />, label: 'Completed', color: statusColors.ready.text, bg: statusColors.ready.bg },
    publishedOk:  { icon: <CheckCircle2 className="w-3 h-3" />, label: 'Published', color: statusColors.ready.text, bg: statusColors.ready.bg },
    written:      { icon: <FileText className="w-3 h-3" />, label: 'Written', color: statusColors.published.text, bg: statusColors.published.bg },
    paused:       { icon: <Pause className="w-3 h-3" />, label: 'Paused', color: colors.textSecondary, bg: colors.bgHover },
    error:        { icon: <AlertCircle className="w-3 h-3" />, label: 'Error', color: colors.danger, bg: colors.dangerLight },
    generating:   { icon: <Loader2 className="w-3 h-3 animate-spin" />, label: 'Generating...', color: colors.accent, bg: colors.accentLight },
  };

  // Only ITEM callers pass `published` — for them a finished article is "Written" until
  // it is actually live. Omitting the prop (the STRATEGY badge, where "published" is
  // meaningless) keeps the plain "Completed" label. Both stored spellings are handled.
  const isDone = status === 'completed' || status === 'complete';
  const key = isDone && published !== undefined
    ? (published ? 'publishedOk' : 'written')
    : status;
  const c = config[key] ?? config.pending;

  return (
    <span
      className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full"
      style={{ fontSize: typography.xs, fontWeight: typography.medium, color: c.color, backgroundColor: c.bg }}
    >
      {c.icon}
      {c.label}
    </span>
  );
}

/**
 * Is this item a generated article that is NOT yet live on the site — i.e. can it
 * still be published?
 *
 * Deliberately mirrors StatusBadge's own "published" derivation so the pill and the
 * Publish button can never disagree: whenever the pill reads **Written**, this is
 * true and the button is shown.
 *
 * BOTH finished statuses count. The backend stores 'written' for a generated-but-
 * unpublished item (service.php:937/1598) and promotes it to 'completed' ONLY when a
 * publish actually succeeded (service.php:961/1653/4048) — but items can sit in
 * 'completed' with no publish pointers (anything generated before the 'written' state
 * existed, and any path that completed without a site). Gating on 'written' ALONE
 * stranded exactly those items with no way to publish them; gating on 'completed'
 * alone was the original bug. It is an OR, not a swap.
 */
export function isPublishableItem(item: {
  status: string;
  articleId?: number;
  articlePublishedUrl?: string;
  articlePublishedPostId?: number | null;
}): boolean {
  const finished = isFinishedItemStatus(item.status);
  const live = !!item.articlePublishedPostId || !!item.articlePublishedUrl;
  return finished && !!item.articleId && !live;
}

// ── Main Component ──
export function StrategiesModule() {
  const { navigateToWriterArticle } = useApp();
  // Header toggle between the strategy list and the cross-strategy master
  // Content Schedule. The header/toolbar stays put; only the body swaps.
  const [view, setView] = useState<'list' | 'schedule'>('list');
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  // Quick filters (Approvals parity): narrow the list to one target site, or to the
  // site a delivery is bound to. 'all' = no narrowing.
  const [siteFilter, setSiteFilter] = useState('all');
  const [deliveryFilter, setDeliveryFilter] = useState('all');
  const [sortBy, setSortBy] = useState('newest');
  const [expandedId, setExpandedId] = useState<number | null>(null);

  // Deep-link expand — e.g. a notification/email link to ?strategy=123 opens
  // straight to that strategy's items. Read once on mount; never pushed back
  // to the URL (this isn't a controlled route param).
  useEffect(() => {
    const raw = new URLSearchParams(window.location.search).get('strategy');
    const id = raw ? parseInt(raw, 10) : NaN;
    if (!Number.isNaN(id)) setExpandedId(id);
  }, []);
  const [generatingItemId, setGeneratingItemId] = useState<number | null>(null);
  const [bulkStrategyId, setBulkStrategyId] = useState<number | null>(null);
  const [retryItemId, setRetryItemId] = useState<number | null>(null);
  // Bulk selection (AutoPress parity) — a Set of selected strategy ids drives the
  // floating action bar; bulkBusy gates the bar's buttons during a batch run.
  const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());
  const [bulkBusy, setBulkBusy] = useState(false);

  const toggleSelected = useCallback((id: number) => {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }, []);
  const clearSelection = useCallback(() => setSelectedIds(new Set()), []);
  // During a "Generate All" run, per-item toasts/refetch are suppressed so the loop
  // isn't noisy — a single summary toast + one refetch fire at the end instead.
  const bulkModeRef = useRef(false);

  // Data fetching via tRPC proxy (uses established apiFetch + react-query).
  // While anything is actively generating (here or in the background wp-cron
  // queue), the list re-polls so background completions appear on their own —
  // without this, cron-generated articles only show up on a manual refresh,
  // which reads as "it generated by itself".
  const { data: strategies = [], isLoading, refetch } = trpc.strategy.list.useQuery(undefined, {
    refetchInterval: (query: any) => {
      const list = query?.state?.data;
      const active = Array.isArray(list) && list.some((s: any) =>
        s?.status === 'in_progress'
        || (Array.isArray(s?.items) && s.items.some((it: any) => it?.status === 'generating')));
      return active ? 5000 : false;
    },
  }) as any;
  const { data: sitesRaw } = trpc.sites.list.useQuery();
  const sites = useMemo(() => (Array.isArray(sitesRaw) ? sitesRaw : []), [sitesRaw]) as any[];
  // Deliveries — for the toolbar's quick filter. A delivery is bound to an SEO site
  // (deliveries.seoSiteId), and a strategy targets a site (config.siteId), so a
  // strategy belongs to a delivery when those two match. Strategies carry no
  // deliveryId of their own, so this join is the only link available.
  const { data: deliveriesRaw } = trpc.deliveries.list.useQuery(undefined, { staleTime: 30_000 });
  const deliveries = useMemo(() => (Array.isArray(deliveriesRaw) ? deliveriesRaw : []), [deliveriesRaw]) as any[];
  const { data: templatesRaw } = trpc.templates.list.useQuery({ module: 'writer' });
  const templates = useMemo(() => (Array.isArray(templatesRaw) ? templatesRaw : []), [templatesRaw]) as any[];
  // Image-prompt templates (module 'image') — the wording used to generate each
  // article's featured image. Unset → the built-in default prompt.
  const { data: imageTemplatesRaw } = trpc.templates.list.useQuery({ module: 'image' });
  const imageTemplates = useMemo(() => (Array.isArray(imageTemplatesRaw) ? imageTemplatesRaw : []), [imageTemplatesRaw]) as any[];
  // Per-JOB models, changeable inline on an existing strategy (the create dialog sets
  // the same config keys). Text writes the article, image draws the featured image.
  const { data: textModelsRaw } = trpc.models.getForGeneration.useQuery({ type: 'text' }, { staleTime: 30_000 });
  const textModels = useMemo(() => (Array.isArray(textModelsRaw) ? textModelsRaw : []), [textModelsRaw]) as any[];
  const { data: imageModelsRaw } = trpc.models.getForGeneration.useQuery({ type: 'image' }, { staleTime: 30_000 });
  const imageModels = useMemo(() => (Array.isArray(imageModelsRaw) ? imageModelsRaw : []), [imageModelsRaw]) as any[];

  // Mutations
  const updateStrategyMutation = trpc.strategy.update.useMutation({
    onSuccess: () => { toast.success('Strategy updated'); refetch(); },
    onError: (err: any) => toast.error(err.message ?? 'Failed to update strategy'),
  }) as any;
  // Create — mirrors the Keyword Explorer's handler so both entry points behave
  // identically; the only difference is that there is no keyword TABLE here, so
  // every keyword comes from what the dialog collected.
  const [createOpen, setCreateOpen] = useState(false);
  const createStrategyMutation = (trpc as any).strategy.create.useMutation({
    onSuccess: (_data: any, variables: any) => {
      // RSS/social arm an immediate first source scan on the backend — say so,
      // otherwise a strategy that lands with zero items reads as broken.
      toast.success(variables?.sourceMode === 'rss' || variables?.sourceMode === 'social'
        ? 'Strategy created — pulling the latest posts now'
        : 'Strategy created successfully!');
      setCreateOpen(false);
      refetch();
    },
    onError: (err: any) => toast.error(err.message ?? 'Failed to create strategy'),
  });
  const handleCreateStrategy = useCallback((payload: StrategyPayload) => {
    const { manualKeywords, ...rest } = payload as any;
    // An RSS/social strategy's items come from its sources; seeding keyword
    // items would also consume the weekly backpressure window. Sources only.
    const isSourced = rest.sourceMode === 'rss' || rest.sourceMode === 'social';
    createStrategyMutation.mutate({
      ...rest,
      keywords: isSourced ? [] : ((manualKeywords ?? []) as string[]),
      // No Keyword Explorer rows behind this entry point, so there are no
      // Ahrefs volume/difficulty metrics to carry onto the items.
      keywordMeta: [],
    });
  }, [createStrategyMutation]);
  const publishItemMutation = trpc.strategy.publishItem.useMutation({
    onSuccess: (data: any) => {
      toast.success(data?.message ?? 'Published to site');
      refetch();
    },
    onError: (err: any) => toast.error(err.message ?? 'Publish failed'),
  }) as any;
  // Live post-status control: acts on the post ALREADY on the connected site —
  // unpublish it (draft), re-publish it, or trash it (recoverable from the site's
  // own Trash, never a permanent delete).
  const postStatusMutation = trpc.strategy.setItemPostStatus.useMutation({
    onSuccess: (data: any) => {
      toast.success(
        data?.trashed
          ? 'Deleted on the site (recoverable from its Trash)'
          : data?.status === 'publish'
            ? 'Published on the site'
            : 'Set to draft on the site',
      );
      refetch();
    },
    onError: (err: any) => toast.error(err.message ?? 'Could not change the post status'),
  }) as any;

  // ── Edit / Preview of the LIVE post, without leaving Strategies ──────────
  // Both address the post by (site, remote post id) — the same coordinates the
  // SEO table uses — so they're only offered once the item is actually on a site.
  type LivePost = { siteId: number; postId: number; title: string; permalink: string };
  const [editPost, setEditPost] = useState<LivePost | null>(null);
  const [previewPost, setPreviewPost] = useState<LivePost | null>(null);
  /** (site, postId, title, permalink) for an item, or null when it isn't live yet. */
  const livePostOf = useCallback((item: StrategyItem): LivePost | null => {
    const postId = Number(item.articlePublishedPostId ?? 0);
    const siteId = Number(item.articleSiteId ?? 0);
    if (!postId || !siteId) return null;
    return { siteId, postId, title: item.title || item.keyword, permalink: item.articlePublishedUrl ?? '' };
  }, []);

  // Authenticated preview HTML: a cross-site iframe can't carry the remote login
  // cookie, so fetch the page server-side through the connector and render it
  // same-origin (srcDoc) — mirrors the SEO table's preview. A failure falls back
  // to a direct iframe rather than a dead end.
  const [previewHtml, setPreviewHtml] = useState<string | null>(null);
  const [previewLoading, setPreviewLoading] = useState(false);
  const sitePreview = trpc.seo.sitePreview.useMutation();
  useEffect(() => {
    if (!previewPost || !previewPost.permalink) {
      setPreviewHtml(null);
      setPreviewLoading(false);
      return;
    }
    let cancelled = false;
    setPreviewLoading(true);
    setPreviewHtml(null);
    sitePreview.mutateAsync({ siteId: previewPost.siteId, url: previewPost.permalink })
      .then((r: any) => { if (!cancelled) setPreviewHtml(String(r?.html ?? '')); })
      .catch(() => { if (!cancelled) setPreviewHtml(null); })
      .finally(() => { if (!cancelled) setPreviewLoading(false); });
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [previewPost]);
  const generateMutation = trpc.strategy.generate.useMutation({
    onSuccess: (_data: any) => {
      // Surfaced regardless of bulk mode — the draft still saved, but a publish
      // failure to the external site is a distinct, actionable outcome that a
      // bulk-run's "N generated" summary wouldn't otherwise mention.
      if (_data?.publish?.success === false) {
        toast.error(_data.publish.message ?? 'Auto-publish failed for a generated article.');
      }
      if (bulkModeRef.current) return; // bulk run reports its own generation summary
      if (_data?.complete) {
        toast.success('All items have been generated!');
      } else {
        toast.success('Article generated successfully');
      }
      refetch();
    },
    onError: (err: any) => {
      if (bulkModeRef.current) return;
      // A gateway timeout on a slow LLM call aborts the REQUEST, not the
      // server-side generation — and the background queue keeps draining
      // pending items either way. Say so, or a timeout reads as total failure
      // right before articles "mysteriously" appear.
      toast.error(
        (err.message ?? 'Generation failed')
        + ' — generation may still be running in the background; this list refreshes automatically.'
      );
    },
    onSettled: () => setGeneratingItemId(null),
  }) as any;

  const deleteMutation = trpc.strategy.delete.useMutation({
    onSuccess: () => { toast.success('Strategy deleted'); refetch(); },
    onError: (err: any) => toast.error(err.message ?? 'Failed to delete'),
  }) as any;

  // Duplicate a strategy (Task E1) — the copy is created with fresh pending items
  // and does NOT auto-start generation (backend deviation from create's auto-arm).
  const duplicateMutation = trpc.strategy.duplicate.useMutation({
    onSuccess: () => { toast.success('Strategy duplicated'); refetch(); },
    onError: (err: any) => toast.error(err.message ?? 'Failed to duplicate strategy'),
  }) as any;
  const handleDuplicate = useCallback((strategyId: number) => {
    duplicateMutation.mutate({ id: strategyId });
  }, [duplicateMutation]);

  // Pull published items' status from WordPress (Task D3) — reconciles our
  // stored record when a post was deleted/unpublished directly on the site.
  const syncStatusMutation = trpc.strategy.syncStatus.useMutation({
    onSuccess: (data: any) => {
      const checked = data?.checked ?? 0;
      const updated = data?.updated ?? 0;
      const deleted = data?.deleted ?? 0;
      toast.success(`Checked ${checked}, updated ${updated}, cleared ${deleted}`);
      refetch();
    },
    onError: (err: any) => toast.error(err.message ?? 'Sync failed'),
  }) as any;
  const handleSyncStatus = useCallback((strategyId: number) => {
    syncStatusMutation.mutate({ id: strategyId });
  }, [syncStatusMutation]);

  // Manual "Scan now" — force an immediate watcher pass for one RSS/Social
  // strategy, bypassing the auto-scan 4h cadence. Synchronous: a social (Apify)
  // pass can take ~40-60s, so the button shows a spinner while it runs.
  const [scanningId, setScanningId] = useState<number | null>(null);
  const scanNowMutation = trpc.strategy.scanNow.useMutation({
    onSuccess: (data: any) => {
      const created = data?.created ?? 0;
      if (created > 0) {
        toast.success(`Pulled ${created} new post${created === 1 ? '' : 's'} — generating now`);
      } else {
        // Zero items — the backend says WHY, so a misconfiguration (missing
        // Apify key, broken source config, failed fetch) surfaces here
        // instead of hiding behind a generic "no new posts".
        switch (data?.reason) {
          case 'no_apify_key':
            toast.error('Scan ran, but no Apify API key is set for this account — add it under Integrations to watch Instagram/TikTok/X/Facebook accounts.');
            break;
          case 'fetch_failed':
            toast.error(`The source fetch failed: ${data?.detail ?? 'unknown error'}`);
            break;
          case 'no_sources':
            toast.error('This strategy has no watched accounts or feeds — edit it and re-add the source links.');
            break;
          case 'duration_complete':
            toast.success('This strategy reached its end date / article limit — no more posts will be pulled.');
            break;
          case 'volume_capped':
            toast.success('New posts found, but your weekly volume cap is reached — they are queued for the next free slot.');
            break;
          default:
            toast.success('Scan complete — no new posts to pull right now');
        }
      }
      refetch();
    },
    onError: (err: any) => toast.error(err.message ?? 'Scan failed'),
    onSettled: () => setScanningId(null),
  }) as any;
  const handleScanNow = useCallback((strategyId: number) => {
    setScanningId(strategyId);
    scanNowMutation.mutate({ id: strategyId });
  }, [scanNowMutation]);

  // Pause / resume generation (Task D2) — a status flip through the existing
  // PATCH /strategies/{id} whitelist. Paused strategies are skipped by the
  // background queue + daily scheduled scan; resuming re-opens them.
  const handleTogglePause = useCallback((strategyId: number, currentStatus: string) => {
    const next = currentStatus === 'paused' ? 'in_progress' : 'paused';
    updateStrategyMutation.mutate({ id: strategyId, status: next });
  }, [updateStrategyMutation]);

  // Expand/collapse to fetch items for a strategy
  const toggleExpand = useCallback(async (strategyId: number) => {
    if (expandedId === strategyId) {
      setExpandedId(null);
      return;
    }
    setExpandedId(strategyId);
    // Items ship with the list response (list_strategies attaches them), so
    // expanding just reveals strategy.items — no per-strategy fetch needed.
  }, [expandedId]);

  // Trigger generation for next pending item
  const handleGenerate = useCallback((strategyId: number) => {
    setGeneratingItemId(strategyId);
    generateMutation.mutate({ id: strategyId });
  }, [generateMutation]);

  // Generate EVERY remaining pending item, one at a time. Each call advances the
  // next pending item (completed → or error → both reduce the pending set), so the
  // loop terminates; the totalItems+1 cap is a belt-and-braces runaway guard.
  const handleGenerateAll = useCallback(async (strategy: Strategy) => {
    setBulkStrategyId(strategy.id);
    bulkModeRef.current = true;
    let ok = 0;
    let fail = 0;
    try {
      for (let i = 0; i < Number(strategy.totalItems) + 1; i++) {
        try {
          const res: any = await generateMutation.mutateAsync({ id: strategy.id });
          if (res?.complete) break;
          ok++;
        } catch {
          fail++; // item is marked 'error' server-side; continue to the next pending
        }
      }
    } finally {
      bulkModeRef.current = false;
      setBulkStrategyId(null);
      refetch();
      if (fail > 0) {
        toast.error(`Generated ${ok}, ${fail} failed — retry the failed item(s).`);
      } else {
        toast.success(ok > 0 ? `Generated ${ok} article${ok === 1 ? '' : 's'}` : 'Nothing left to generate');
      }
    }
  }, [generateMutation, refetch]);

  // Retry / regenerate a single item (e.g. one that errored).
  const handleRetry = useCallback(async (strategyId: number, itemId: number) => {
    setRetryItemId(itemId);
    try {
      await generateMutation.mutateAsync({ id: strategyId, itemId });
    } catch {
      // error toast already surfaced by the mutation's onError
    } finally {
      setRetryItemId(null);
    }
  }, [generateMutation]);

  // Delete strategy — confirmed first (AutoPress parity: it uses a confirm
  // modal; one accidental click here would otherwise drop the whole queue).
  const handleDelete = useCallback((strategyId: number, name: string) => {
    if (!window.confirm(`Delete strategy "${name}" and all its items? Generated articles stay in Writer.`)) return;
    deleteMutation.mutate({ id: strategyId });
  }, [deleteMutation]);

  // Change a strategy's target site — a PARTIAL config update (backend merges
  // this onto the existing config rather than replacing it, so approvalMode/
  // scheduleConfig/interlinksConfig/model survive untouched).
  const handleSiteChange = useCallback((strategyId: number, siteId: string) => {
    updateStrategyMutation.mutate({
      id: strategyId,
      config: { siteId: siteId ? parseInt(siteId, 10) : 0 },
    });
  }, [updateStrategyMutation]);

  // Change publishing mode inline (draft / publish / schedule) — top-level
  // whitelisted field on PATCH /strategies/{id}.
  const handlePublishingModeChange = useCallback((strategyId: number, mode: string) => {
    updateStrategyMutation.mutate({ id: strategyId, publishingMode: mode });
  }, [updateStrategyMutation]);

  // Inline approval-mode change — a partial config merge, like the site select.
  const handleApprovalChange = useCallback((strategyId: number, mode: string) => {
    updateStrategyMutation.mutate({ id: strategyId, config: { approvalMode: mode } });
  }, [updateStrategyMutation]);

  // Image-prompt template — partial config merge (backend sanitizes imageTemplateId).
  // '' = Default prompt, sent as 0 so the stored key is explicitly cleared rather
  // than left pointing at a template the user just deselected.
  const handleImageTemplateChange = useCallback((strategyId: number, templateId: string) => {
    updateStrategyMutation.mutate({ id: strategyId, config: { imageTemplateId: templateId ? parseInt(templateId, 10) : 0 } });
  }, [updateStrategyMutation]);

  // Per-job model pickers. The model's PROVIDER travels with it (the server routes on
  // both), and 'default' clears the pair back to empty so the job falls through to its
  // own server-side default rather than pointing at a model that may be gone.
  const handleTextModelChange = useCallback((strategyId: number, modelId: string) => {
    const picked = textModels.find((m) => m.modelId === modelId);
    updateStrategyMutation.mutate({
      id: strategyId,
      config: { model: modelId, provider: picked?.provider ?? '' },
    });
  }, [updateStrategyMutation, textModels]);

  const handleImageModelChange = useCallback((strategyId: number, modelId: string) => {
    const picked = imageModels.find((m) => m.modelId === modelId);
    updateStrategyMutation.mutate({
      id: strategyId,
      config: { imageModel: modelId, imageProvider: picked?.provider ?? '' },
    });
  }, [updateStrategyMutation, imageModels]);

  // Toggle whether generated articles reuse the source post's image as the
  // featured image (SOCIAL strategies — reuse is social-only server-side).
  // Partial config merge —
  // backend sanitizes featuredImages at controller.php:584. Defaults OFF so
  // existing strategies are unaffected until the user opts in.
  const handleFeaturedImagesChange = useCallback((strategyId: number, enabled: boolean) => {
    updateStrategyMutation.mutate({ id: strategyId, config: { featuredImages: enabled } });
  }, [updateStrategyMutation]);

  // Inline template (prompt) change — top-level whitelisted field.
  const handleTemplateChange = useCallback((strategyId: number, templateId: string) => {
    updateStrategyMutation.mutate({ id: strategyId, templateId: parseInt(templateId, 10) });
  }, [updateStrategyMutation]);

  // Recurrence dialog (replaces the old bare frequency Select) — scheduleConfig
  // is replaced WHOLE inside the config merge (the merge is shallow), so the
  // existing startDate must be carried along — the backend then redistributes
  // the PENDING items' due dates from the new recurrence.
  const [recurrenceDialog, setRecurrenceDialog] = useState<{ strategyId: number; value: ScheduleRecurrence } | null>(null);
  const handleRecurrenceSave = useCallback((
    strategyId: number,
    r: ScheduleRecurrence,
    existingStartDate: string,
    currentMode?: string,
  ) => {
    updateStrategyMutation.mutate({
      id: strategyId,
      // Saving a recurrence on a Draft/Auto-publish strategy also switches it to
      // 'schedule' — otherwise the schedule would be stored but never honoured, since
      // the engine only paces publishing in schedule mode. Already-scheduled
      // strategies are left alone (no needless mode write).
      ...(currentMode === 'schedule' ? {} : { publishingMode: 'schedule' }),
      config: { scheduleConfig: { ...recurrenceToConfig(r), startDate: existingStartDate || '' } },
    });
    setRecurrenceDialog(null);
  }, [updateStrategyMutation]);

  // Per-item due-date edit (pending items on schedule-mode strategies).
  const updateItemMutation = trpc.strategy.updateItem.useMutation({
    onSuccess: () => { toast.success('Due date updated'); refetch(); },
    onError: (err: any) => toast.error(err.message ?? 'Failed to update due date'),
  }) as any;
  const handleItemDateChange = useCallback((strategyId: number, itemId: number, date: string) => {
    if (!date) return;
    updateItemMutation.mutate({ id: strategyId, itemId, scheduledDate: date });
  }, [updateItemMutation]);

  // Per-item overrides row (Task F2) — templateId/publishingMode/approvalMode,
  // toggled open per item (any number of rows can be open at once).
  const [openOverridesItemId, setOpenOverridesItemId] = useState<number | null>(null);
  const itemOverridesMutation = trpc.strategy.updateItem.useMutation({
    onSuccess: () => { refetch(); },
    onError: (err: any) => toast.error(err.message ?? 'Failed to update item overrides'),
  }) as any;
  // Sends the FULL desired override set each time (only non-"Inherit" keys
  // present) — the backend's set_item_config() REPLACES (not merges) an
  // item's stored config, so this is the correct, simplest way to let a
  // Select going back to "Inherit" actually clear that key.
  const handleItemOverrideChange = useCallback((
    strategyId: number,
    itemId: number,
    currentConfig: Record<string, any>,
    key: 'templateId' | 'publishingMode' | 'approvalMode',
    value: string,
  ) => {
    const next = { ...currentConfig };
    if (value === 'inherit') {
      delete next[key];
    } else if (key === 'templateId') {
      next[key] = Number(value);
    } else {
      next[key] = value;
    }
    itemOverridesMutation.mutate({ id: strategyId, itemId, config: next });
  }, [itemOverridesMutation]);

  // Per-item delete (the article, if any, stays in Writer).
  const deleteItemMutation = trpc.strategy.deleteItem.useMutation({
    onSuccess: () => { toast.success('Item removed'); refetch(); },
    onError: (err: any) => toast.error(err.message ?? 'Failed to remove item'),
  }) as any;
  const handleDeleteItem = useCallback((strategyId: number, itemId: number, keyword: string) => {
    if (!window.confirm(`Remove "${keyword}" from this strategy? Its generated article (if any) stays in Writer.`)) return;
    deleteItemMutation.mutate({ id: strategyId, itemId });
  }, [deleteItemMutation]);

  // ── Item bulk selection ────────────────────────────────────────────────
  // Item ids are unique across strategies, so ONE set is enough; the bulk bar
  // only ever renders inside the strategy whose items are selected.
  const [selectedItemIds, setSelectedItemIds] = useState<Set<number>>(() => new Set());
  const toggleItemSelected = useCallback((itemId: number) => {
    setSelectedItemIds((prev) => {
      const next = new Set(prev);
      if (next.has(itemId)) next.delete(itemId); else next.add(itemId);
      return next;
    });
  }, []);
  const clearItemSelection = useCallback(() => setSelectedItemIds(new Set()), []);

  const duplicateItemMutation = trpc.strategy.duplicateItem.useMutation() as any;
  const [itemBulkBusy, setItemBulkBusy] = useState(false);

  /** Run one mutation across every selected item, then refetch once. Failures are
   *  counted rather than thrown so one bad item can't abort the rest of the batch. */
  const runItemBulk = useCallback(async (
    strategyId: number,
    ids: number[],
    verb: string,
    run: (itemId: number) => Promise<unknown>,
  ) => {
    setItemBulkBusy(true);
    let ok = 0; let failed = 0;
    for (const itemId of ids) {
      try { await run(itemId); ok++; } catch { failed++; }
    }
    setItemBulkBusy(false);
    clearItemSelection();
    refetch();
    if (failed > 0) toast.error(`${verb} ${ok}, ${failed} failed`);
    else toast.success(`${verb} ${ok} item${ok === 1 ? '' : 's'}`);
  }, [clearItemSelection, refetch]);

  // Generate ONLY the ticked items (bulk bar). No new endpoint: the backend has
  // always accepted a targeted generate — POST /strategies/{id}/generate with
  // `itemId` — which is the exact call the per-item Retry makes. This is that
  // call, looped over the selection.
  //
  // SEQUENTIAL on purpose. Firing these concurrently is what took the SEO
  // optimizer down with 502s (2026-08-07): each generate is a long synchronous
  // PHP request holding an LLM call, and N at once exhausts the pool. One at a
  // time also lets the server's atomic claim do its job.
  //
  // Two hazards the naive "loop over selected ids" version gets wrong:
  //  1. 'generating' items are already claimed by another worker — re-issuing
  //     would double-generate, so they are skipped.
  //  2. A finished item is REGENERATED, not skipped: the server explicitly
  //     allows any status for a targeted call ("any current status is
  //     allowed"), so it would silently overwrite a written — possibly
  //     published — article. That gets one confirm, and declining keeps the
  //     rest of the selection running instead of cancelling everything.
  const handleGenerateSelected = useCallback(async (strategy: Strategy, ids: number[]) => {
    const items = strategy.items ?? [];
    const consolidated = parseStrategyConfig(strategy.config)?.structure === 'consolidated';

    // Probe with regeneration ON to learn what a full run would destroy, ask
    // once, then re-plan with the real answer. The rules live in
    // planBulkGenerate (pure, unit-tested) — this callback only does I/O.
    const probe = planBulkGenerate(items, ids, consolidated, true);
    let regenerateFinished = true;
    if (probe.finished.length > 0) {
      const n = probe.finished.length;
      const rest = probe.targets.length - n;
      regenerateFinished = window.confirm(
        `${n} of the selected item${n === 1 ? ' already has an article' : 's already have articles'}. `
        + `Regenerate ${n === 1 ? 'it' : 'them'}? This overwrites the existing draft — anything already published stays live until you publish again.\n\n`
        + `Cancel to generate only the ${rest} remaining item${rest === 1 ? '' : 's'}.`,
      );
    }

    const plan = planBulkGenerate(items, ids, consolidated, regenerateFinished);
    if (plan.calls.length === 0) {
      toast.info(plan.inFlight > 0 ? 'Those items are already generating.' : 'Nothing to generate in this selection.');
      return;
    }

    setBulkStrategyId(strategy.id);
    bulkModeRef.current = true;
    let ok = 0;
    let fail = 0;
    try {
      for (const itemId of plan.calls) {
        try {
          await generateMutation.mutateAsync({ id: strategy.id, itemId });
          ok++;
        } catch {
          fail++; // marked 'error' server-side; keep going so one bad item can't abort the batch
        }
      }
    } finally {
      bulkModeRef.current = false;
      setBulkStrategyId(null);
      clearItemSelection();
      refetch();
      // Consolidated: one call finished every targeted item, so report items, not calls.
      const made = consolidated && ok > 0 ? plan.targets.length : ok;
      const skipped = plan.inFlight > 0 ? ` (${plan.inFlight} already generating)` : '';
      if (fail > 0) toast.error(`Generated ${made}, ${fail} failed — retry the failed item(s).${skipped}`);
      else toast.success(`Generated ${made} article${made === 1 ? '' : 's'}${skipped}`);
    }
  }, [generateMutation, refetch, clearItemSelection]);

  // Bulk WordPress status change for the ticked items. Same endpoint the row's
  // own dropdown uses, looped — no new route.
  //
  // SEQUENTIAL, like every other bulk action here: each call is a synchronous
  // round trip to the client's WordPress, and firing N at once is what took the
  // optimizer down with 502s.
  //
  // Items with no live post are FILTERED, not sent: the hub throws "not
  // published to a site yet" for those, so a mixed selection would otherwise
  // report failures for items that were never publishable. They are reported
  // as skipped instead.
  const handleBulkPostStatus = useCallback(async (strategy: Strategy, ids: number[], status: string) => {
    const plan = planBulkPostStatus(strategy.items ?? [], ids);
    if (plan.targets.length === 0) {
      toast.info('None of the selected items are published to a site yet.');
      return;
    }
    if (isDestructiveStatus(status)
      && !window.confirm(`Move ${plan.targets.length} post${plan.targets.length === 1 ? '' : 's'} to the site’s Trash? They can be restored there.`)) {
      return;
    }

    setItemBulkBusy(true);
    let ok = 0;
    let failed = 0;
    let lastError = '';
    for (const target of plan.targets) {
      try {
        await postStatusMutation.mutateAsync({ id: strategy.id, itemId: target.id, status });
        ok++;
      } catch (e: any) {
        failed++;
        lastError = e?.message ?? lastError;
      }
    }
    setItemBulkBusy(false);
    clearItemSelection();
    refetch();

    const skipped = plan.skipped > 0 ? ` (${plan.skipped} not on a site)` : '';
    if (failed > 0) {
      // Surface the server's reason — "Set a schedule date before choosing
      // Scheduled" is actionable, "3 failed" is not.
      toast.error(`Updated ${ok}, ${failed} failed${skipped}${lastError ? ` — ${lastError}` : ''}`);
    } else {
      toast.success(`Updated ${ok} post${ok === 1 ? '' : 's'}${skipped}`);
    }
  }, [postStatusMutation, refetch, clearItemSelection]);

  // "Select as Parent" (crown): the strategy's parent is identified by KEYWORD
  // (config.parentKeyword) and hierarchyMode must be parent_and_children for the
  // link engine to act. Setting both then re-running reapply-parent makes every
  // other item link to it; clearing parentKeyword and re-running strips those
  // links back out — reapply_parent_links() resolves an empty parent to "clear".
  const reapplyParentMutation = trpc.strategy.reapplyParent.useMutation() as any;
  const handleToggleParent = useCallback(async (strategy: Strategy, keyword: string, isParent: boolean) => {
    try {
      await updateStrategyMutation.mutateAsync({
        id: strategy.id,
        ...(isParent ? {} : { hierarchyMode: 'parent_and_children' }),
        config: { parentKeyword: isParent ? '' : keyword },
      });
      await reapplyParentMutation.mutateAsync({ id: strategy.id });
      toast.success(isParent ? 'Parent cleared — links removed' : 'Parent set — other items now link to it');
      refetch();
    } catch (e: any) {
      toast.error(e?.message ?? 'Could not update the parent');
    }
  }, [updateStrategyMutation, reapplyParentMutation, refetch]);

  // Manually publish one completed item's article to the strategy's site —
  // the retro-publish path for items generated in draft mode (or before a
  // site was set).
  const [publishingItemId, setPublishingItemId] = useState<number | null>(null);
  const handlePublishItem = useCallback(async (strategyId: number, itemId: number) => {
    setPublishingItemId(itemId);
    try {
      await publishItemMutation.mutateAsync({ id: strategyId, itemId });
    } catch {
      // toast handled by the mutation's onError
    } finally {
      setPublishingItemId(null);
    }
  }, [publishItemMutation]);

  // Interlink injection is configured & run in a modal (auto/manual modes).
  // The button below opens it; the modal owns the injectInterlinks mutation and
  // calls back onDone → refetch so completions land in the list.
  const [interlinkModalStrategy, setInterlinkModalStrategy] = useState<{ id: number; name: string; interlinksConfig?: any } | null>(null);

  // Parent-settings editor (Task G1) — edits hierarchyMode + parent-link/anchor
  // config for one strategy in a modal (opened from the row's Settings button).
  const [parentSettingsStrategy, setParentSettingsStrategy] = useState<Strategy | null>(null);
  /** Full settings editor (row cog) — the create dialog opened in edit mode. */
  const [settingsStrategy, setSettingsStrategy] = useState<Strategy | null>(null);

  // Cast to typed array (trpc proxy returns unknown)
  const strategyList: Strategy[] = Array.isArray(strategies) ? strategies : [];
  // Ticked ITEMS grouped by their owning strategy — feeds the floating bulk bar's
  // item mode (the handlers are per-strategy, so cross-card selections run per
  // group). Count comes from the groups, not the raw set, so an id whose strategy
  // left the list can't inflate the label.
  //
  // HOOK ORDER LAW: this useMemo MUST stay above the isLoading early return below.
  // It originally landed after it, so the loading render counted one fewer hook
  // than the loaded render — React #310, and the whole module crashed to the
  // error screen ("what is this?? the error occured in strategy module").
  const itemGroups = useMemo(() =>
    strategyList
      .map((s) => ({ strategy: s, ids: (s.items ?? []).filter((it) => selectedItemIds.has(it.id)).map((it) => it.id) }))
      .filter((g) => g.ids.length > 0),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [strategies, selectedItemIds]);
  const selectedItemCount = itemGroups.reduce((n, g) => n + g.ids.length, 0);

  // Client-side search + status filter (AutoPress parity) — name match (also
  // checked against item keywords when items are already attached) plus an
  // exact status match; both are optional and combine with AND.
  // NOTE: this useMemo must stay ABOVE the isLoading early return — a hook
  // after a conditional return changes the hook count between renders and
  // crashes with React error #310 the moment loading flips to false.
  const visibleList = useMemo(() => {
    const q = search.trim().toLowerCase();
    // A delivery narrows to ITS site — strategies have no deliveryId, so the
    // delivery's seoSiteId is what actually does the filtering.
    const deliverySiteId = deliveryFilter === 'all'
      ? null
      : String(deliveries.find((d) => String(d.id) === deliveryFilter)?.seoSiteId ?? '');
    const filtered = strategyList.filter((strategy) => {
      if (statusFilter !== 'all' && strategy.status !== statusFilter) return false;
      const strategySiteId = String(parseStrategyConfig(strategy.config).siteId ?? '');
      if (siteFilter !== 'all' && strategySiteId !== siteFilter) return false;
      // A delivery with no site bound matches nothing rather than everything.
      if (deliverySiteId !== null && (deliverySiteId === '' || strategySiteId !== deliverySiteId)) return false;
      if (!q) return true;
      const nameMatch = strategy.name?.toLowerCase().includes(q);
      const keywordMatch = Array.isArray(strategy.items)
        && strategy.items.some((it) => it.keyword?.toLowerCase().includes(q));
      return nameMatch || keywordMatch;
    });
    // Sort a COPY — filtered is a fresh array from .filter() already, but stay
    // explicit so a future refactor to .some()-based filtering can't mutate strategyList.
    const sorted = [...filtered];
    if (sortBy === 'name') {
      sorted.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
    } else if (sortBy === 'status') {
      sorted.sort((a, b) => (a.status || '').localeCompare(b.status || ''));
    }
    // 'newest' (default) — current order (createdAt desc, as returned by the API).
    return sorted;
  }, [strategyList, search, statusFilter, sortBy, siteFilter, deliveryFilter, deliveries]);

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-full">
        <Spinner className="w-6 h-6" />
      </div>
    );
  }

  // "Select all visible" — checked = every visible row selected. Toggling clears
  // the visible ids when already all-selected, otherwise adds them (leaving any
  // off-screen/ filtered selections untouched).
  const allVisibleSelected = visibleList.length > 0 && visibleList.every((s) => selectedIds.has(s.id));
  const toggleSelectAllVisible = () => {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (visibleList.length > 0 && visibleList.every((s) => prev.has(s.id))) {
        visibleList.forEach((s) => next.delete(s.id));
      } else {
        visibleList.forEach((s) => next.add(s.id));
      }
      return next;
    });
  };

  // Bulk actions operate only on selected ids still present in strategyList
  // (deleted/filtered-away selections are ignored, not errored on). One bulkBusy
  // flag disables the whole bar while any batch is in flight.
  const handleBulkGenerate = async () => {
    const targets = strategyList.filter((s) => selectedIds.has(s.id) && s.status !== 'completed');
    setBulkBusy(true);
    let done = 0;
    try {
      for (const s of targets) {
        await handleGenerateAll(s); // reuse per-strategy serial flow; iterate serially across strategies too
        done++;
      }
    } finally {
      setBulkBusy(false);
      toast.success(done > 0 ? `Generated all for ${done} strateg${done === 1 ? 'y' : 'ies'}` : 'Nothing to generate');
    }
  };

  const handleBulkPublish = async () => {
    const targets = strategyList.filter((s) => selectedIds.has(s.id));
    setBulkBusy(true);
    let published = 0;
    let failed = 0;
    try {
      for (const s of targets) {
        const cfg = parseStrategyConfig(s.config);
        if (!cfg.siteId) continue; // skip strategies without a configured site
        const items = Array.isArray(s.items) ? s.items : [];
        for (const it of items) {
          // Same predicate as the per-row Publish button, so the bulk sweep and the
          // single-item button can never disagree about what is publishable.
          if (isPublishableItem(it)) {
            try {
              await publishItemMutation.mutateAsync({ id: s.id, itemId: it.id });
              published++;
            } catch {
              failed++;
            }
          }
        }
      }
    } finally {
      setBulkBusy(false);
      if (failed > 0) toast.error(`Published ${published}, ${failed} failed`);
      else toast.success(published > 0 ? `Published ${published} article${published === 1 ? '' : 's'}` : 'Nothing to publish');
    }
  };

  const handleBulkDelete = async () => {
    const targets = strategyList.filter((s) => selectedIds.has(s.id));
    const n = targets.length;
    if (n === 0) return;
    if (!window.confirm(`Delete ${n} strateg${n === 1 ? 'y' : 'ies'} and all their items? Generated articles stay in Writer.`)) return;
    setBulkBusy(true);
    let deleted = 0;
    let failed = 0;
    try {
      for (const s of targets) {
        try {
          await deleteMutation.mutateAsync({ id: s.id });
          deleted++;
        } catch {
          failed++;
        }
      }
    } finally {
      setBulkBusy(false);
      clearSelection();
      if (failed > 0) toast.error(`Deleted ${deleted}, ${failed} failed`);
      else toast.success(`Deleted ${deleted} strateg${deleted === 1 ? 'y' : 'ies'}`);
    }
  };

  return (
    <div className="h-full flex flex-col">
      {/* Header */}
      <div className="flex items-center gap-2 mb-4 shrink-0">
        <Layers className="w-5 h-5" style={{ color: colors.primary }} />
        <h1 style={{ fontSize: typography.title, fontWeight: typography.bold, color: colors.text }}>
          Strategies
        </h1>
        <Badge variant="secondary" className="ml-2">
          {/* Any active filter must switch this to "N of M" — otherwise the badge
              claims the full count while the list below shows a subset. */}
          {search.trim() || statusFilter !== 'all' || siteFilter !== 'all' || deliveryFilter !== 'all'
            ? `${visibleList.length} of ${strategyList.length}`
            : `${strategyList.length} ${strategyList.length === 1 ? 'strategy' : 'strategies'}`}
        </Badge>

        <AutoScanStatus />

        {/* New Strategy — the SOURCE-AGNOSTIC entry point, and it belongs here.
            It used to live only on the Keyword Explorer header, which meant
            creating an RSS or Social strategy — neither of which involves a
            keyword at all — required visiting the Keywords page first. Flagged
            as a gap on 2026-07-16, moved 2026-08-08 on the owner's reminder.
            Keywords keeps its own bulk-bar "Create Strategy": that one seeds a
            strategy FROM the rows you selected, which is genuinely keyword work. */}
        <Button
          variant="outline"
          size="sm"
          className="ml-auto h-8"
          onClick={() => setCreateOpen(true)}
        >
          <Zap className="w-3.5 h-3.5 text-primary" />
          New Strategy
        </Button>
      </div>

      {/* Toolbar — its OWN row directly beneath the header. It used to be pinned to
          the header's top-right, which squeezed the view toggle, search, filters and
          sort into whatever space the title left over. */}
      <div className="flex flex-wrap items-center gap-2 mb-4 shrink-0">

        {/* List | Schedule view toggle */}
        <div className="flex items-center gap-1 rounded-md p-0.5" style={{ border: `1px solid ${colors.border}` }}>
          <Button
            variant={view === 'list' ? 'default' : 'ghost'}
            size="sm"
            className="h-7"
            onClick={() => setView('list')}
          >
            <List className="w-3.5 h-3.5" />
            List
          </Button>
          <Button
            variant={view === 'schedule' ? 'default' : 'ghost'}
            size="sm"
            className="h-7"
            onClick={() => setView('schedule')}
          >
            <CalendarClock className="w-3.5 h-3.5" />
            Schedule
          </Button>
        </div>

        {/* Search + status filter toolbar (AutoPress parity) — list view only */}
        {view === 'list' && (
        <div className="flex items-center gap-2">
          <span className="flex items-center pr-1" title="Select all visible">
            <Checkbox
              checked={allVisibleSelected}
              onCheckedChange={toggleSelectAllVisible}
              aria-label="Select all visible strategies"
            />
          </span>
          <Input
            placeholder="Search strategies…"
            className="h-8 w-56 text-xs bg-card"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
          {/* Quick filters (Approvals parity): site, then delivery. */}
          <Select value={siteFilter} onValueChange={setSiteFilter}>
            <SelectTrigger className="w-40">
              <SelectValue placeholder="All sites" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All sites</SelectItem>
              {sites.map((s: any) => (
                <SelectItem key={s.id} value={String(s.id)}>{s.name || s.url}</SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Select value={deliveryFilter} onValueChange={setDeliveryFilter}>
            <SelectTrigger className="w-40">
              <SelectValue placeholder="All deliveries" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All deliveries</SelectItem>
              {deliveries.map((d: any) => (
                <SelectItem key={d.id} value={String(d.id)}>{d.name}</SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Select value={statusFilter} onValueChange={setStatusFilter}>
            <SelectTrigger className="w-36">
              <SelectValue placeholder="All statuses" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All statuses</SelectItem>
              <SelectItem value="pending">Pending</SelectItem>
              <SelectItem value="in_progress">In Progress</SelectItem>
              <SelectItem value="completed">Completed</SelectItem>
              <SelectItem value="error">Error</SelectItem>
            </SelectContent>
          </Select>
          <Select value={sortBy} onValueChange={setSortBy}>
            <SelectTrigger className="w-28">
              <SelectValue placeholder="Sort" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="newest">Newest</SelectItem>
              <SelectItem value="name">Name (A–Z)</SelectItem>
              <SelectItem value="status">Status</SelectItem>
            </SelectContent>
          </Select>
        </div>
        )}
      </div>

      {/* Schedule view — cross-strategy master table (replaces the list body;
          the header/toolbar above stays). */}
      {view === 'schedule' ? (
        <ScheduleView />
      ) : strategyList.length === 0 ? (
        <div
          className="flex flex-col items-center justify-center flex-1 rounded-lg"
          style={{ border: `1px dashed ${colors.border}`, background: colors.bgSurface }}
        >
          <Layers className="w-12 h-12 mb-3" style={{ color: colors.textMuted }} />
          <p style={{ color: colors.textSecondary, fontSize: typography.body, fontWeight: typography.medium }}>
            No strategies yet
          </p>
          <p style={{ color: colors.textMuted, fontSize: typography.sm, marginTop: '4px' }}>
            Select keywords in the Keyword Explorer and create a strategy
          </p>
        </div>
      ) : visibleList.length === 0 ? (
        <div
          className="flex items-center justify-center flex-1 rounded-lg"
          style={{ border: `1px dashed ${colors.border}`, background: colors.bgSurface }}
        >
          <p style={{ color: colors.textMuted, fontSize: typography.sm }}>
            No strategies match.
          </p>
        </div>
      ) : (
        /* Strategy list */
        <div className="flex-1 overflow-y-auto space-y-3">
          {visibleList.map((strategy) => {
            const config = parseStrategyConfig(strategy.config);
            const siteIdValue = config.siteId ? String(config.siteId) : '';
            const isRss = config.sourceMode === 'rss';
            const isSocial = config.sourceMode === 'social';
            const rssFeeds: string[] = Array.isArray(config.rssFeeds) ? config.rssFeeds : [];
            const socialLinks: string[] = Array.isArray(config.socialLinks) ? config.socialLinks : [];
            // Social strategies may also carry converted rssFeeds (free-platform accounts);
            // show the de-duplicated union on the link line.
            const socialFeedLinks: string[] = isSocial ? Array.from(new Set([...socialLinks, ...rssFeeds])) : [];
            return (
            <div
              key={strategy.id}
              className="rounded-lg overflow-hidden"
              style={{ border: `1px solid ${colors.border}`, background: colors.bgSurface, boxShadow: shadows.card }}
            >
              {/* Strategy header. Collapsed: ONE clean identity row (name, badges, meta,
                  progress, icon utilities). Expanded: the day-to-day controls surface UP
                  HERE beside the title (design note 2026-08-08 — no padded strip below),
                  with the set-once GENERATION line tucked underneath. */}
              <div
                className="px-4 py-1.5 cursor-pointer"
                style={{ borderBottom: expandedId === strategy.id ? `1px solid ${colors.borderLight}` : 'none' }}
                onClick={() => toggleExpand(strategy.id)}
              >
              <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                {/* Bulk-select checkbox — leftmost; stops row-toggle propagation */}
                <span className="shrink-0" onClick={(e) => e.stopPropagation()}>
                  <Checkbox
                    checked={selectedIds.has(strategy.id)}
                    onCheckedChange={() => toggleSelected(strategy.id)}
                    aria-label={`Select ${strategy.name}`}
                  />
                </span>

                {/* Expand icon */}
                {expandedId === strategy.id
                  ? <ChevronDown className="w-4 h-4 shrink-0" style={{ color: colors.textMuted }} />
                  : <ChevronRight className="w-4 h-4 shrink-0" style={{ color: colors.textMuted }} />
                }

                {/* Name + meta */}
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2">
                    <span style={{ fontSize: typography.body, fontWeight: typography.semibold, color: colors.text }} className="truncate">
                      {strategy.name}
                    </span>
                    <StatusBadge status={strategy.status} />
                    {/* Source badge — tells RSS/Social from Keyword strategies at a glance;
                        RSS/Social carry their link list in the tooltip. */}
                    <span
                      className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full shrink-0"
                      style={{
                        fontSize: typography.xs,
                        fontWeight: typography.medium,
                        color: isRss ? colors.accent : isSocial ? colors.primary : colors.textSecondary,
                        backgroundColor: isRss ? colors.accentLight : isSocial ? colors.primaryLight : colors.bgHover,
                      }}
                      title={isRss
                        ? (rssFeeds.length ? `RSS feeds:\n${rssFeeds.join('\n')}` : 'RSS strategy')
                        : isSocial
                        ? (socialLinks.length ? socialLinks.join('\n') : 'Social strategy')
                        : 'Keyword strategy'}
                    >
                      {isRss ? <Rss className="w-3 h-3" /> : isSocial ? <Share2 className="w-3 h-3" /> : <Search className="w-3 h-3" />}
                      {isRss ? 'RSS' : isSocial ? 'Social' : 'Keywords'}
                    </span>
                    {config.parentKeyword && (
                      <span title="Has a parent (hub) article" className="shrink-0">
                        <Crown className="w-3.5 h-3.5 shrink-0 text-amber-500" />
                      </span>
                    )}
                    {strategy.hierarchyMode === 'children_only' && (
                      <span title="Children link to an external parent" className="shrink-0">
                        <Link2 className="w-3.5 h-3.5 shrink-0" style={{ color: colors.textMuted }} />
                      </span>
                    )}
                  </div>
                  {/* Meta line — counts, date, AND the RSS/Social source (owner,
                      2026-08-08: "put the source information on the same row as the
                      3/3 items"). The source used to occupy a third line of its own,
                      making every sourced card taller than a keyword one for a single
                      hostname. It is now the last `·` segment: flex so the source
                      keeps its icon, min-w-0 + truncate so a long feed list shortens
                      itself rather than pushing the counts out of the card. Full URLs
                      stay in the title tooltip. */}
                  <div
                    className="flex items-center gap-1 min-w-0"
                    style={{ fontSize: typography.xs, color: colors.textMuted, marginTop: '2px' }}
                  >
                    <span className="shrink-0">
                      {strategy.completedItems}/{strategy.totalItems} items completed
                      {strategy.failedItems > 0 && (
                        <span style={{ color: colors.danger }}> · {strategy.failedItems} failed</span>
                      )}
                      <span> · {new Date(strategy.createdAt).toLocaleDateString()}</span>
                    </span>
                    {isRss && rssFeeds.length > 0 && (
                      <span className="flex items-center gap-1 min-w-0" title={rssFeeds.join('\n')}>
                        <span className="shrink-0">·</span>
                        <Rss className="w-3 h-3 shrink-0" style={{ color: colors.accent }} />
                        <span className="truncate">{rssFeeds.map(feedHost).join(', ')}</span>
                      </span>
                    )}
                    {isSocial && socialFeedLinks.length > 0 && (
                      <span className="flex items-center gap-1 min-w-0" title={socialFeedLinks.join('\n')}>
                        <span className="shrink-0">·</span>
                        <Share2 className="w-3 h-3 shrink-0" style={{ color: colors.primary }} />
                        <span className="truncate">{socialFeedLinks.map(feedHost).join(', ')}</span>
                      </span>
                    )}
                  </div>
                </div>
                {/* Controls — UP in the header row (design note 2026-08-08): they fill
                    the empty space beside the title instead of a padded strip below, so
                    the expanded card stays short. ml-auto pushes them right; on narrower
                    screens the whole cluster wraps to its own line. Same h-7/text-xs
                    tokens as the per-item Inherit selects. Still expand-gated — a
                    collapsed card remains one clean identity row (owner pick (a)). */}
                {expandedId === strategy.id && (
                  <div className="flex flex-wrap items-center justify-end gap-x-2 gap-y-1.5 ml-auto min-w-0" onClick={(e) => e.stopPropagation()}>

                    {/* Publishing Mode — inline-editable (AutoPress row parity).
                        Switching an existing draft strategy to Auto-publish makes
                        FUTURE generations publish; already-completed items get the
                        per-item Publish button below. */}
                    <div className="w-28 shrink-0" onClick={(e) => e.stopPropagation()}>
                      <Select
                        value={strategy.publishingMode || 'draft'}
                        onValueChange={(value) => handlePublishingModeChange(strategy.id, value)}
                      >
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder="Draft" />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="draft">{PUBLISHING_MODE_LABELS.draft}</SelectItem>
                          <SelectItem value="publish">{PUBLISHING_MODE_LABELS.publish}</SelectItem>
                          <SelectItem value="schedule">{PUBLISHING_MODE_LABELS.schedule}</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>

                    {/* Target Site — inline-editable, merges onto the strategy's
                        existing config (see handleSiteChange) rather than replacing it.
                        w-40: hostnames like "massagegoteborg.nu" were truncating at w-32. */}
                    <div className="w-40 shrink-0" onClick={(e) => e.stopPropagation()}>
                      <Select value={siteIdValue} onValueChange={(value) => handleSiteChange(strategy.id, value)}>
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder="No site" />
                        </SelectTrigger>
                        <SelectContent>
                          {sites.length > 0 ? (
                            sites.map((s: any) => (
                              <SelectItem key={s.id} value={String(s.id)}>{s.name || s.url}</SelectItem>
                            ))
                          ) : (
                            <div className="p-2 text-xs text-muted-foreground text-center">
                              No sites — connect one in Sites
                            </div>
                          )}
                        </SelectContent>
                      </Select>
                    </div>

                    {/* Reuse source image as featured image — SOCIAL ONLY. Reuse is
                        implemented by social_source_image() (service.php), which
                        returns null unless the item config carries `social`; RSS
                        items never set it, so on an RSS strategy this control could
                        never reuse anything — it silently fell through to the AI
                        generator, making the label a lie. RSS (like keyword
                        strategies, which have never shown this checkbox) toggles
                        featured images from the strategy settings dialog instead.
                        Partial config merge; defaults OFF so existing strategies
                        are unaffected until the user opts in. */}
                    {isSocial && (
                      <label
                        className="shrink-0 flex items-center gap-1.5 cursor-pointer text-xs"
                        style={{ color: colors.textSecondary }}
                        onClick={(e) => e.stopPropagation()}
                        title="When enabled, the source post's image is reused as the blog article's featured image."
                      >
                        <Checkbox
                          checked={!!config.featuredImages}
                          onCheckedChange={(checked) => handleFeaturedImagesChange(strategy.id, checked === true)}
                          aria-label={`Reuse source image as featured image for ${strategy.name}`}
                        />
                        Reuse image
                      </label>
                    )}

                    {/* ── divider: destination │ workflow ── */}
                    <div className="h-5 w-px shrink-0" style={{ background: colors.border }} aria-hidden="true" />

                    {/* Approval mode — inline-editable (partial config merge).
                        Affects items generated AFTER the change. */}
                    <div className="w-32 shrink-0" onClick={(e) => e.stopPropagation()}>
                      <Select
                        value={config.approvalMode || 'none'}
                        onValueChange={(value) => handleApprovalChange(strategy.id, value)}
                      >
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder="Approvals" />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="none">No approval</SelectItem>
                          <SelectItem value="internal">Internal</SelectItem>
                          <SelectItem value="client">Client</SelectItem>
                          <SelectItem value="both">Internal + Client</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>

                    {/* Edit schedule — ALWAYS available. This used to render only when
                        publishingMode was already 'schedule', which was a catch-22: you
                        could not set a schedule on a Draft/Auto-publish strategy because
                        the only way in was hidden until it already had one. Saving a
                        recurrence now also switches the strategy into schedule mode (see
                        handleRecurrenceSave), so the button always does something. */}
                    <div className="w-32 shrink-0" onClick={(e) => e.stopPropagation()}>
                      <Button
                        variant="outline"
                        size="sm"
                        className="h-7 w-full justify-start text-xs font-normal bg-card overflow-hidden"
                        title={strategy.publishingMode === 'schedule'
                          ? 'Edit the posting schedule'
                          : 'Set a posting schedule — this switches the strategy to Scheduled'}
                        onClick={() => setRecurrenceDialog({
                          strategyId: strategy.id,
                          value: recurrenceFromConfig(config.scheduleConfig ?? {}),
                        })}
                      >
                        <CalendarClock className="w-3.5 h-3.5 shrink-0" />
                        <span className="truncate">
                          {strategy.publishingMode === 'schedule'
                            ? summarizeRecurrence(recurrenceFromConfig(config.scheduleConfig ?? {}))
                            : 'Set schedule'}
                        </span>
                      </Button>
                    </div>

                    {/* ── divider: workflow │ actions ── */}
                    <div className="h-5 w-px shrink-0" style={{ background: colors.border }} aria-hidden="true" />

                    {/* Actions */}
                    <div className="flex items-center gap-2 shrink-0">
                      {strategy.status !== 'completed' && (
                        <Button
                          variant="default"
                          size="sm"
                          className="h-7"
                          disabled={bulkStrategyId === strategy.id || generatingItemId === strategy.id}
                          onClick={() => handleGenerateAll(strategy)}
                        >
                          {bulkStrategyId === strategy.id ? (
                            <Loader2 className="w-3.5 h-3.5 animate-spin" />
                          ) : (
                            <Zap className="w-3.5 h-3.5" />
                          )}
                          Generate All
                        </Button>
                      )}
                      <Button
                        variant="outline"
                        size="sm"
                        className="h-7"
                        disabled={strategy.status === 'completed' || generatingItemId === strategy.id || bulkStrategyId === strategy.id}
                        onClick={() => handleGenerate(strategy.id)}
                      >
                        {generatingItemId === strategy.id ? (
                          <Loader2 className="w-3.5 h-3.5 animate-spin" />
                        ) : (
                          <Play className="w-3.5 h-3.5" />
                        )}
                        Generate
                      </Button>
                      {/* Scan now — force an immediate pull for RSS/Social strategies,
                          independent of the auto-scan 4h cadence. */}
                      {(isRss || isSocial) && (
                        <Button
                          variant="outline"
                          size="sm"
                          className="h-7"
                          title="Check the source for new posts right now, instead of waiting for the auto-scan"
                          disabled={scanningId === strategy.id}
                          onClick={() => handleScanNow(strategy.id)}
                        >
                          {scanningId === strategy.id ? (
                            <Loader2 className="w-3.5 h-3.5 animate-spin" />
                          ) : (
                            <RefreshCw className="w-3.5 h-3.5" />
                          )}
                          {scanningId === strategy.id ? 'Scanning…' : 'Scan now'}
                        </Button>
                      )}
                      {Number(strategy.completedItems) >= 2 && (
                        <Button
                          variant="outline"
                          size="sm"
                          className="h-7"
                          title="Insert internal links between this strategy's generated articles"
                          onClick={() => setInterlinkModalStrategy({ id: strategy.id, name: strategy.name, interlinksConfig: parseStrategyConfig(strategy.config)?.interlinksConfig })}
                        >
                          <Link2 className="w-3.5 h-3.5" />
                          Interlinks
                        </Button>
                      )}
                      {/* Pause / Resume generation (D2) — shown while the strategy is
                          actively working, queued, or already paused. */}
                      {['in_progress', 'pending', 'paused'].includes(strategy.status) && (
                        <Button
                          variant="ghost"
                          size="sm"
                          className="h-7"
                          title={strategy.status === 'paused' ? 'Resume generation' : 'Pause generation'}
                          onClick={() => handleTogglePause(strategy.id, strategy.status)}
                        >
                          {strategy.status === 'paused' ? (
                            <Play className="w-3.5 h-3.5" />
                          ) : (
                            <Pause className="w-3.5 h-3.5" />
                          )}
                          {strategy.status === 'paused' ? 'Resume' : 'Pause'}
                        </Button>
                      )}
                    </div>
                  </div>
                )}

                {/* Progress — belongs beside the item count, not in the
                    control line. */}
                <div className="w-24 shrink-0 hidden sm:block">
                  <div className="h-1.5 rounded-full overflow-hidden" style={{ background: colors.bgHover }}>
                    <div
                      className="h-full rounded-full transition-all duration-300"
                      style={{
                        width: `${strategy.totalItems > 0 ? (strategy.completedItems / strategy.totalItems) * 100 : 0}%`,
                        background: strategy.status === 'completed' ? statusColors.ready.text : colors.primary,
                      }}
                    />
                  </div>
                </div>

                {/* Icon utilities — rare, destructive-or-config actions stay
                    small and out of the main control line. */}
                <div className="flex items-center gap-1 shrink-0" onClick={(e) => e.stopPropagation()}>
                  {/* Parent / anchor settings editor (G1) */}
                  <Button
                    variant="ghost"
                    size="sm"
                    title="Strategy settings — every option from the create dialog"
                    onClick={() => setSettingsStrategy(strategy)}
                  >
                    <Settings2 className="w-3.5 h-3.5" />
                  </Button>
                  {/* Duplicate strategy (E1) */}
                  <Button
                    variant="ghost"
                    size="sm"
                    title="Duplicate this strategy"
                    disabled={duplicateMutation.isPending}
                    onClick={() => handleDuplicate(strategy.id)}
                  >
                    <Copy className="w-3.5 h-3.5" />
                  </Button>
                  {/* Sync published status from WordPress (D3) — only worth
                      offering once at least one item has actually published. */}
                  {(strategy.items ?? []).some((it) => !!it.articlePublishedUrl) && (
                    <Button
                      variant="ghost"
                      size="sm"
                      title="Check published items' status on WordPress"
                      disabled={syncStatusMutation.isPending}
                      onClick={() => handleSyncStatus(strategy.id)}
                    >
                      <RefreshCw className={`w-3.5 h-3.5 ${syncStatusMutation.isPending ? 'animate-spin' : ''}`} />
                    </Button>
                  )}
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => handleDelete(strategy.id, strategy.name)}
                    className="text-muted-foreground hover:text-destructive"
                  >
                    <Trash2 className="w-3.5 h-3.5" />
                  </Button>
                </div>
              </div>
              {expandedId === strategy.id && (<>
                {/* Line 3 — GENERATION settings, on their OWN indented line.
                    Nine identically-shaped boxes on one line read as an
                    undifferentiated clump, and no amount of gap/divider tuning fixed
                    that (tried, reported still cluttered). These four are
                    set-once-and-forget, unlike the day-to-day controls above, so they
                    drop to a secondary line marked by a left rule and a quiet caption.
                    Line 2 keeps what you touch often: destination, approval, schedule,
                    actions. */}
                <div
                  className="flex flex-wrap items-center gap-x-2 gap-y-1.5 mt-1.5 ml-1 pl-3"
                  style={{ borderLeft: `2px solid ${colors.borderLight}` }}
                  onClick={(e) => e.stopPropagation()}
                >
                  <span
                    className="shrink-0 mr-1 select-none"
                    style={{ fontSize: typography.xs, color: colors.textMuted }}
                  >
                    Generation
                  </span>

                  {/* NB every SelectTrigger in these two rows must carry `w-full`.
                      The shadcn trigger base is `w-fit whitespace-nowrap`, so without
                      it the trigger sizes to its TEXT and renders WIDER than its w-36
                      wrapper — "Default image prompt" overflowed by ~26px and
                      "Default image model" by ~20px, so the pair visually collided no
                      matter how much gap-x the row had (the spill simply ate it).
                      With w-full the trigger obeys the wrapper and the base's
                      `select-value:line-clamp-1` clips the label instead. */}

                  {/* Template (prompt) — inline-editable; drives generation for
                      items generated AFTER the change. */}
                  <div className="w-40 shrink-0" onClick={(e) => e.stopPropagation()}>
                    <Select
                      value={strategy.templateId ? String(strategy.templateId) : ''}
                      onValueChange={(value) => handleTemplateChange(strategy.id, value)}
                    >
                      <SelectTrigger className="w-full">
                        <SelectValue placeholder="Template" />
                      </SelectTrigger>
                      <SelectContent>
                        {templates.length > 0 ? (
                          templates.map((t: any) => (
                            <SelectItem key={t.id} value={String(t.id)}>{t.name}</SelectItem>
                          ))
                        ) : (
                          <div className="p-2 text-xs text-muted-foreground text-center">
                            No Writer templates
                          </div>
                        )}
                      </SelectContent>
                    </Select>
                  </div>

                  {/* Text AI model — which model WRITES each article. Pairs with the
                      content Template on its left. "Default" = the server's own default.
                      w-36: the "Default text model" option truncated at w-28. */}
                  <div className="w-40 shrink-0" onClick={(e) => e.stopPropagation()}>
                    <Select
                      value={config.model ? String(config.model) : 'default'}
                      onValueChange={(value) => handleTextModelChange(strategy.id, value === 'default' ? '' : value)}
                    >
                      <SelectTrigger className="w-full" title="Which AI model writes each article">
                        <SelectValue placeholder="Text model" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="default">Default text model</SelectItem>
                        {textModels.map((m: any) => (
                          <SelectItem key={m.modelId} value={m.modelId}>
                            {(m.customName || m.originalName || m.modelId)}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>

                  {/* Image prompt template (module 'image') — the wording used to
                      generate each article's featured image. "Default prompt" keeps the
                      built-in sentence, so this is purely opt-in.
                      w-36: the "Default image prompt" option truncated at w-32. */}
                  <div className="w-40 shrink-0" onClick={(e) => e.stopPropagation()}>
                    <Select
                      value={config.imageTemplateId ? String(config.imageTemplateId) : 'default'}
                      onValueChange={(value) => handleImageTemplateChange(strategy.id, value === 'default' ? '' : value)}
                    >
                      <SelectTrigger className="w-full" title="Which template writes the featured-image prompt">
                        <SelectValue placeholder="Image prompt" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="default">Default image prompt</SelectItem>
                        {imageTemplates.map((t: any) => (
                          <SelectItem key={t.id} value={String(t.id)}>{t.name}</SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>

                  {/* Image AI model — which model DRAWS the featured image. Sits beside
                      the Image prompt so the pair (wording + model) reads together.
                      w-36: the "Default image model" option truncated at w-28. */}
                  <div className="w-40 shrink-0" onClick={(e) => e.stopPropagation()}>
                    <Select
                      value={config.imageModel ? String(config.imageModel) : 'default'}
                      onValueChange={(value) => handleImageModelChange(strategy.id, value === 'default' ? '' : value)}
                    >
                      <SelectTrigger className="w-full" title="Which AI model generates the featured image">
                        <SelectValue placeholder="Image model" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="default">Default image model</SelectItem>
                        {imageModels.map((m: any) => (
                          <SelectItem key={m.modelId} value={m.modelId}>
                            {(m.customName || m.originalName || m.modelId)}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                </div>
              </>)}
              </div>

              {/* Expanded items list */}
              {expandedId === strategy.id && strategy.items && (
                <div style={{ background: colors.bgPage }}>
                  {strategy.items.map((item) => {
                    const itemConfig = parseStrategyConfig(item.config);
                    return (
                    <div key={item.id}>
                    <div
                      className="flex items-center gap-3 pl-7 pr-6 py-2"
                      style={{
                        borderBottom: `1px solid ${colors.borderLight}`,
                        borderLeft: `2px solid ${colors.borderLight}`,
                      }}
                    >
                      {/* Bulk-select checkbox — leftmost, mirrors the strategy rows. */}
                      <span className="shrink-0" onClick={(e) => e.stopPropagation()}>
                        <Checkbox
                          checked={selectedItemIds.has(item.id)}
                          onCheckedChange={() => toggleItemSelected(item.id)}
                          aria-label={`Select ${item.keyword}`}
                        />
                      </span>

                      {/* Position */}
                      <span
                        className="shrink-0 w-6 text-center"
                        style={{ fontSize: typography.xs, color: colors.textMuted, fontWeight: typography.medium }}
                      >
                        {Number(item.position) + 1}
                      </span>

                      {/* Select as Parent — the crown designates THIS item's keyword as
                          the pillar every other item links to; clicking it again clears
                          the parent and strips those links. */}
                      <Button
                        variant="ghost"
                        size="sm"
                        className="h-7 shrink-0 px-1.5"
                        title={config.parentKeyword === item.keyword
                          ? 'Unselect as parent — removes the links to it'
                          : 'Select as parent — the other items will link to this one'}
                        aria-pressed={config.parentKeyword === item.keyword}
                        onClick={(e) => {
                          e.stopPropagation();
                          handleToggleParent(strategy, item.keyword, config.parentKeyword === item.keyword);
                        }}
                      >
                        <Crown
                          className="w-3.5 h-3.5"
                          style={{ color: config.parentKeyword === item.keyword ? '#f59e0b' : colors.textMuted }}
                        />
                      </Button>

                      {/* Calculated publish date — a small leading tag, so the queue reads
                          chronologically at a glance before you read any names. Compact
                          (MMM d, + year only when it isn't this year) with the full
                          date/time on hover. Absent when the item has no due date. */}
                      {(() => {
                        const tag = itemDueTag(item);
                        if (!tag) return null;
                        return (
                          <span
                            className="shrink-0 rounded px-1.5 py-0.5 tabular-nums"
                            style={{
                              fontSize: typography.xs,
                              color: colors.textMuted,
                              background: colors.bgHover,
                              border: `1px solid ${colors.borderLight}`,
                            }}
                            title={tag.published
                              ? `Published ${formatItemDueFull(tag.raw)}`
                              : `Scheduled to publish ${formatItemDueFull(tag.raw)}`}
                          >
                            {formatItemDue(tag.raw)}
                          </span>
                        );
                      })()}

                      {/* TARGET KEYWORD on top, article title beneath it — the keyword is
                          what the row is FOR, so it leads; the generated title is
                          secondary. With no keyword the title takes the lead line (and
                          still repeats below, keeping the two-line shape consistent). */}
                      <div className="flex-1 min-w-0">
                        <span style={{ fontSize: typography.sm, color: colors.text }} className="truncate block">
                          {/* (Parent crown now lives in the row's own toggle button.) */}
                          {(item.keyword ?? '').trim() !== '' ? item.keyword : (item.title ?? '')}
                        </span>
                        {(item.title ?? '') !== '' && (
                          <span style={{ fontSize: typography.xs, color: colors.textMuted }} className="truncate block">
                            {item.title}
                          </span>
                        )}
                        {/* F3: compact display-only SEO metrics carried from the
                            Keyword Explorer — shown only when at least one is set. */}
                        {(item.volume != null || item.difficulty != null) && (
                          <span
                            className="ml-2"
                            style={{ fontSize: typography.xs, color: colors.textMuted }}
                          >
                            {item.volume != null && `Vol ${item.volume}`}
                            {item.volume != null && item.difficulty != null && ' · '}
                            {item.difficulty != null && `KD ${item.difficulty}`}
                          </span>
                        )}
                      </div>

                      {/* Due-date EDITOR — only while pending on a schedule-mode strategy.
                          The read-only display now lives in the leading date tag above. */}
                      {strategy.publishingMode === 'schedule' && item.status === 'pending' ? (
                        <Input
                          type="date"
                          className="h-7 w-36 shrink-0 text-xs bg-card"
                          defaultValue={item.scheduledDate ? item.scheduledDate.slice(0, 10) : ''}
                          onChange={(e) => handleItemDateChange(strategy.id, item.id, e.target.value)}
                          onClick={(e) => e.stopPropagation()}
                        />
                      ) : null}

                      {/* Status */}
                      <StatusBadge
                        status={item.status}
                        published={!!item.articlePublishedPostId || !!item.articlePublishedUrl}
                      />

                      {/* WordPress status of THIS post — on the row itself (owner,
                          2026-08-12). It used to live inside the per-item overrides
                          sub-panel, which is collapsed behind its own toggle, so in
                          practice the row had no status control at all.
                          Gated on a live post, exactly like Edit/Preview/See live
                          beside it: with no post on the site there is nothing to set.
                          The badge to its left is the ITEM's generation state
                          (Pending/Written/Published) — a different thing from the
                          post's WordPress status, which is why both are shown. */}
                      {livePostOf(item) && (
                        <Select
                          value={item.articlePublishedStatus
                            || (item.articleStatus === 'published' ? 'publish' : 'draft')}
                          disabled={postStatusMutation.isPending}
                          onValueChange={(value) => {
                            if (isDestructiveStatus(value)
                              && !window.confirm('Move this post to the site’s Trash? It can be restored there.')) return;
                            postStatusMutation.mutate({ id: strategy.id, itemId: item.id, status: value });
                          }}
                        >
                          <SelectTrigger
                            className="w-36 shrink-0"
                            title="Change this post’s status on the connected site"
                            onClick={(e) => e.stopPropagation()}
                          >
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent>
                            {POST_STATUSES.map((s) => (
                              <SelectItem key={s.value} value={s.value}>{s.label}</SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      )}

                      {/* Article link */}
                      {item.articleId && (
                        <Button
                          variant="ghost"
                          size="sm"
                          className="shrink-0"
                          onClick={() => navigateToWriterArticle(Number(item.articleId))}
                        >
                          <FileText className="w-3.5 h-3.5" />
                          View
                        </Button>
                      )}

                      {/* Edit + Preview the LIVE post without leaving Strategies.
                          Edit reuses THE SEO page editor (SectionModal mode="page");
                          Preview is the same popup the SEO table shows. Both need the
                          post to exist on a site, so they appear only once it does. */}
                      {livePostOf(item) && (
                        <>
                          <Button
                            variant="ghost"
                            size="sm"
                            className="shrink-0"
                            title="Edit this page in the SEO page editor"
                            onClick={() => setEditPost(livePostOf(item))}
                          >
                            <Pencil className="w-3.5 h-3.5" />
                            Edit
                          </Button>
                          <Button
                            variant="ghost"
                            size="sm"
                            className="shrink-0"
                            title="Preview the live page in a popup"
                            onClick={() => setPreviewPost(livePostOf(item))}
                          >
                            <Eye className="w-3.5 h-3.5" />
                            Preview
                          </Button>
                        </>
                      )}

                      {/* See live — opens the published page in another window */}
                      {item.articlePublishedUrl && (
                        <Button
                          variant="ghost"
                          size="sm"
                          className="shrink-0"
                          title="Open the published page in a new tab"
                          onClick={() => window.open(item.articlePublishedUrl, '_blank', 'noopener,noreferrer')}
                        >
                          <ExternalLink className="w-3.5 h-3.5" />
                          See live
                        </Button>
                      )}

                      {/* Publish a generated, not-yet-live item to the target site — the
                          retro-publish path for draft-mode articles. Uses the shared
                          predicate so this button appears for EXACTLY the items whose
                          pill reads "Written" (both 'written' and unpublished
                          'completed'), which is what a status-only gate got wrong. */}
                      {isPublishableItem(item) && (
                        <Button
                          variant="outline"
                          size="sm"
                          className="shrink-0"
                          disabled={publishingItemId === item.id || !siteIdValue}
                          title={siteIdValue ? 'Publish this article to the target site' : 'Select a Target Site on the strategy row first'}
                          onClick={() => handlePublishItem(strategy.id, item.id)}
                        >
                          {publishingItemId === item.id ? (
                            <Loader2 className="w-3.5 h-3.5 animate-spin" />
                          ) : (
                            <Send className="w-3.5 h-3.5" />
                          )}
                          Publish
                        </Button>
                      )}

                      {/* Retry a failed item */}
                      {item.status === 'error' && (
                        <Button
                          variant="ghost"
                          size="sm"
                          className="shrink-0"
                          disabled={retryItemId === item.id || bulkStrategyId === strategy.id}
                          onClick={() => handleRetry(strategy.id, item.id)}
                        >
                          {retryItemId === item.id ? (
                            <Loader2 className="w-3.5 h-3.5 animate-spin" />
                          ) : (
                            <RefreshCw className="w-3.5 h-3.5" />
                          )}
                          Retry
                        </Button>
                      )}

                      {/* Error message */}
                      {item.errorMessage && (
                        <span
                          className="text-xs truncate max-w-[200px]"
                          style={{ color: colors.danger }}
                          title={item.errorMessage}
                        >
                          {item.errorMessage}
                        </span>
                      )}

                      {/* Per-item overrides toggle (Task F1/F2) — templateId/
                          publishingMode/approvalMode, inline below this row. */}
                      <Button
                        variant="ghost"
                        size="sm"
                        className="shrink-0"
                        title="Per-item overrides"
                        onClick={() => setOpenOverridesItemId(openOverridesItemId === item.id ? null : item.id)}
                      >
                        <SlidersHorizontal className="w-3.5 h-3.5" />
                      </Button>

                      {/* Remove this item from the strategy (article stays in
                          Writer). Hidden while it's actively generating. */}
                      {item.status !== 'generating' && (
                        <Button
                          variant="ghost"
                          size="sm"
                          className="shrink-0 text-muted-foreground hover:text-destructive"
                          title="Remove this keyword from the strategy"
                          disabled={bulkStrategyId === strategy.id}
                          onClick={() => handleDeleteItem(strategy.id, item.id, item.keyword)}
                        >
                          <Trash2 className="w-3.5 h-3.5" />
                        </Button>
                      )}
                    </div>

                    {/* Inline overrides row (Task F2) — Template / Mode / Approval,
                        each defaulting to "Inherit" (absent key = strategy's own
                        value). Saves on change via the item PATCH endpoint, sending
                        the FULL desired override set each time (REPLACE semantics —
                        see handleItemOverrideChange()'s docblock). */}
                    {openOverridesItemId === item.id && (
                      <div
                        className="flex items-center gap-3 pl-16 pr-6 py-2"
                        style={{
                          background: colors.bgHover,
                          borderBottom: `1px solid ${colors.borderLight}`,
                          borderLeft: `2px solid ${colors.borderLight}`,
                        }}
                        onClick={(e) => e.stopPropagation()}
                      >
                        <span style={{ fontSize: typography.xs, color: colors.textMuted }}>Template</span>
                        <Select
                          value={itemConfig.templateId ? String(itemConfig.templateId) : 'inherit'}
                          onValueChange={(value) => handleItemOverrideChange(strategy.id, item.id, itemConfig, 'templateId', value)}
                        >
                          <SelectTrigger className="w-40"><SelectValue /></SelectTrigger>
                          <SelectContent>
                            <SelectItem value="inherit">Inherit</SelectItem>
                            {templates.map((t: any) => (
                              <SelectItem key={t.id} value={String(t.id)}>{t.name}</SelectItem>
                            ))}
                          </SelectContent>
                        </Select>

                        <span style={{ fontSize: typography.xs, color: colors.textMuted }}>Mode</span>
                        <Select
                          value={itemConfig.publishingMode ?? 'inherit'}
                          onValueChange={(value) => handleItemOverrideChange(strategy.id, item.id, itemConfig, 'publishingMode', value)}
                        >
                          <SelectTrigger className="w-36"><SelectValue /></SelectTrigger>
                          <SelectContent>
                            {Object.entries(ITEM_MODE_LABELS).map(([value, label]) => (
                              <SelectItem key={value} value={value}>{label}</SelectItem>
                            ))}
                          </SelectContent>
                        </Select>

                        <span style={{ fontSize: typography.xs, color: colors.textMuted }}>Approval</span>
                        <Select
                          value={itemConfig.approvalMode ?? 'inherit'}
                          onValueChange={(value) => handleItemOverrideChange(strategy.id, item.id, itemConfig, 'approvalMode', value)}
                        >
                          <SelectTrigger className="w-32"><SelectValue /></SelectTrigger>
                          <SelectContent>
                            {Object.entries(ITEM_APPROVAL_LABELS).map(([value, label]) => (
                              <SelectItem key={value} value={value}>{label}</SelectItem>
                            ))}
                          </SelectContent>
                        </Select>

                      </div>
                    )}
                    </div>
                    );
                  })}
                </div>
              )}
            </div>
            );
          })}
        </div>
      )}

      {/* Floating bulk action bar — bottom-center, THE one bulk surface (owner:
          "remove the top one, not the bottom bulk actions" — the card's inline
          strip is gone; its item actions live here now).

          Two modes, never both: while ITEMS are ticked it carries the item
          actions (per owning strategy — a cross-card selection runs per group);
          otherwise, while STRATEGIES are ticked, the strategy actions. The mode
          split keeps one "selected" count and one Delete with a clear blast
          radius on screen at a time. */}
      {/* Item bulk bar (floating). Linking opens the same interlink modal the row
          button uses — one strategy at a time, so it disables on a cross-card
          selection; Delete and Duplicate run per item and report a combined
          result so one failure can't abort the batch. */}
      {view === 'list' && (selectedItemCount > 0 || selectedIds.size > 0) && (
        <div
          className="fixed left-1/2 -translate-x-1/2 bottom-6 z-50 flex items-center gap-2 px-4 py-2.5 rounded-full"
          style={{ background: colors.bgSurface, border: `1px solid ${colors.border}`, boxShadow: shadows.dropdown }}
        >
          {selectedItemCount > 0 ? (
            <>
              <span style={{ fontSize: typography.sm, fontWeight: typography.medium, color: colors.text }}>
                {selectedItemCount} selected
              </span>
              <Button
                variant="default"
                size="sm"
                disabled={itemBulkBusy || bulkStrategyId !== null}
                title={`Generate the ${selectedItemCount} selected item${selectedItemCount === 1 ? '' : 's'}`}
                onClick={async () => {
                  for (const g of itemGroups) { await handleGenerateSelected(g.strategy, g.ids); }
                }}
              >
                {bulkStrategyId !== null
                  ? <Loader2 className="w-3.5 h-3.5 animate-spin" />
                  : <Zap className="w-3.5 h-3.5" />}
                Generate ({selectedItemCount})
              </Button>
              <Select
                value=""
                disabled={itemBulkBusy || bulkStrategyId !== null}
                onValueChange={async (value) => {
                  for (const g of itemGroups) { await handleBulkPostStatus(g.strategy, g.ids, value); }
                }}
              >
                <SelectTrigger
                  className="w-40"
                  title="Change the WordPress status of every selected item that is live on a site"
                >
                  <SelectValue placeholder="Set post status…" />
                </SelectTrigger>
                <SelectContent>
                  {POST_STATUSES.map((s) => (
                    <SelectItem key={s.value} value={s.value}>{s.label}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <Button
                variant="outline"
                size="sm"
                disabled={itemBulkBusy || itemGroups.length !== 1}
                title={itemGroups.length === 1 ? 'Configure interlinking for these items' : 'Select items within ONE strategy to configure linking'}
                onClick={() => {
                  const g = itemGroups[0];
                  const cfg = parseStrategyConfig(g.strategy.config);
                  setInterlinkModalStrategy({ id: g.strategy.id, name: g.strategy.name, interlinksConfig: cfg.interlinksConfig });
                }}
              >
                <Link2 className="w-3.5 h-3.5" /> Linking
              </Button>
              <Button
                variant="outline"
                size="sm"
                disabled={itemBulkBusy}
                onClick={async () => {
                  for (const g of itemGroups) {
                    await runItemBulk(g.strategy.id, g.ids, 'Duplicated', (itemId) =>
                      duplicateItemMutation.mutateAsync({ id: g.strategy.id, itemId }));
                  }
                }}
              >
                <Copy className="w-3.5 h-3.5" /> Duplicate
              </Button>
              <Button
                variant="outline"
                size="sm"
                className="text-destructive hover:text-destructive"
                disabled={itemBulkBusy}
                onClick={async () => {
                  if (!window.confirm(`Remove ${selectedItemCount} item${selectedItemCount === 1 ? '' : 's'}? Generated articles stay in Writer.`)) return;
                  for (const g of itemGroups) {
                    await runItemBulk(g.strategy.id, g.ids, 'Removed', (itemId) =>
                      deleteItemMutation.mutateAsync({ id: g.strategy.id, itemId }));
                  }
                }}
              >
                {itemBulkBusy ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Trash2 className="w-3.5 h-3.5" />} Delete
              </Button>
              <Button variant="ghost" size="sm" disabled={itemBulkBusy} onClick={clearItemSelection} title="Clear selection">
                <X className="w-3.5 h-3.5" />
              </Button>
            </>
          ) : (
            <>
              <span style={{ fontSize: typography.sm, fontWeight: typography.medium, color: colors.text }}>
                {selectedIds.size} selected
              </span>
              <Button variant="default" size="sm" disabled={bulkBusy} onClick={handleBulkGenerate}>
                {bulkBusy ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Zap className="w-3.5 h-3.5" />}
                Generate All
              </Button>
              <Button variant="outline" size="sm" disabled={bulkBusy} onClick={handleBulkPublish}>
                <Send className="w-3.5 h-3.5" />
                Publish completed
              </Button>
              <Button
                variant="ghost"
                size="sm"
                disabled={bulkBusy}
                onClick={handleBulkDelete}
                className="text-muted-foreground hover:text-destructive"
              >
                <Trash2 className="w-3.5 h-3.5" />
                Delete
              </Button>
              <Button variant="ghost" size="sm" disabled={bulkBusy} onClick={clearSelection} title="Clear selection">
                <X className="w-3.5 h-3.5" />
              </Button>
            </>
          )}
        </div>
      )}

      {/* Interlink configuration + run modal (auto / manual) */}
      <InterlinkManagerModal
        open={interlinkModalStrategy !== null}
        onOpenChange={(o) => { if (!o) setInterlinkModalStrategy(null); }}
        strategyId={interlinkModalStrategy?.id ?? 0}
        strategyName={interlinkModalStrategy?.name ?? ''}
        interlinksConfig={interlinkModalStrategy?.interlinksConfig}
        onDone={refetch}
      />

      {/* THE SEO page editor, opened on a strategy item's live post. Same
          component + same props shape the SEO table uses (mode="page"), so saving
          routes through the identical dynamic-rule paths. Keyed per post so
          switching items never bleeds editor state. */}
      {editPost && (
        <SectionModal
          key={`strategy-page-${editPost.postId}`}
          siteId={editPost.siteId}
          postId={editPost.postId}
          type="post"
          readOnly={false}
          mode="page"
          page={{
            title: editPost.title || 'Untitled',
            permalink: editPost.permalink || undefined,
            // Hand the editor's Preview control back to this page's popup.
            onPreview: editPost.permalink ? () => setPreviewPost(editPost) : undefined,
          }}
          onClose={() => setEditPost(null)}
          onSaved={refetch}
        />
      )}

      {/* Live-page preview popup. PORTALED to body for the same reason the SEO
          table does it: the editor is body-portaled too, and a preview trapped in
          the app tree's stacking context paints BEHIND it. */}
      {previewPost && previewPost.permalink && createPortal(
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-6"
          onClick={() => setPreviewPost(null)}
        >
          <div
            className="flex h-[85vh] w-full max-w-5xl flex-col overflow-hidden rounded-lg border border-border bg-card shadow-xl"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="flex items-center justify-between gap-2 border-b border-border px-4 py-2">
              <div className="min-w-0">
                <div className="truncate text-sm font-medium text-foreground">{previewPost.title || 'Preview'}</div>
                <a href={previewPost.permalink} target="_blank" rel="noopener noreferrer" className="truncate text-xs text-muted-foreground hover:text-primary">{previewPost.permalink}</a>
              </div>
              <div className="flex items-center gap-1 shrink-0">
                <a href={previewPost.permalink} target="_blank" rel="noopener noreferrer" className="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground" title="Open in new tab">
                  <ExternalLink className="h-4 w-4" />
                </a>
                <button type="button" onClick={() => setPreviewPost(null)} className="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground" title="Close">
                  <X className="h-4 w-4" />
                </button>
              </div>
            </div>
            {previewLoading ? (
              <div className="flex h-full w-full flex-1 items-center justify-center bg-white">
                <Loader2 className="h-6 w-6 animate-spin text-primary" />
              </div>
            ) : previewHtml ? (
              <iframe srcDoc={previewHtml} title="Page preview" className="h-full w-full flex-1 bg-white" />
            ) : (
              <iframe src={previewPost.permalink} title="Page preview" className="h-full w-full flex-1 bg-white" />
            )}
          </div>
        </div>,
        document.body,
      )}

      {/* CREATE mode — same dialog, no keyword selection behind it. selectedCount
          0 / selectedKeywords [] is what tells it to offer its own primary +
          supporting keyword inputs (and it blocks its own Create button until a
          keyword exists by SOME route, or the source is switched to RSS/social). */}
      <CreateStrategyDialog
        open={createOpen}
        onOpenChange={setCreateOpen}
        defaultName="New Strategy"
        onSave={handleCreateStrategy}
        isSaving={createStrategyMutation.isPending}
        selectedCount={0}
        selectedKeywords={[]}
      />

      {/* FULL settings editor — the create dialog in edit mode, pre-filled from this
          strategy. Split on save: the four whitelisted top-level columns go as
          columns, everything else rides `config`, which the backend sanitizes and
          MERGES (so keys this dialog doesn't manage survive untouched). */}
      {settingsStrategy && (
        <CreateStrategyDialog
          open
          onOpenChange={(o) => { if (!o) setSettingsStrategy(null); }}
          defaultName={settingsStrategy.name}
          isSaving={updateStrategyMutation.isPending}
          selectedCount={0}
          selectedKeywords={[]}
          editStrategy={{
            id: settingsStrategy.id,
            name: settingsStrategy.name,
            templateId: settingsStrategy.templateId,
            hierarchyMode: (settingsStrategy as any).hierarchyMode,
            publishingMode: settingsStrategy.publishingMode,
            config: parseStrategyConfig(settingsStrategy.config),
          }}
          onSave={(payload: StrategyPayload) => {
            const {
              name, templateId, hierarchyMode, publishingMode,
              // Not settings: these only describe how a NEW strategy seeds its items.
              manualKeywords: _mk, manualPrimaryKeyword: _mpk,
              ...config
            } = payload as any;
            updateStrategyMutation.mutate({
              id: settingsStrategy.id,
              name,
              templateId,
              hierarchyMode,
              publishingMode,
              config,
            });
            setSettingsStrategy(null);
          }}
        />
      )}

      {/* Parent / anchor settings editor (G1) */}
      <ParentSettingsModal
        open={parentSettingsStrategy !== null}
        onOpenChange={(o) => { if (!o) setParentSettingsStrategy(null); }}
        strategyId={parentSettingsStrategy?.id ?? 0}
        strategyName={parentSettingsStrategy?.name ?? ''}
        hierarchyMode={parentSettingsStrategy?.hierarchyMode ?? 'standalone'}
        config={parseStrategyConfig(parentSettingsStrategy?.config)}
        onDone={refetch}
      />

      {/* Posting schedule (recurrence) editor — replaces the old bare
          frequency Select on the strategy row. */}
      <Dialog open={recurrenceDialog !== null} onOpenChange={(o) => { if (!o) setRecurrenceDialog(null); }}>
        <DialogContent className="sm:max-w-[420px]">
          <DialogHeader>
            <DialogTitle>Posting schedule</DialogTitle>
          </DialogHeader>
          {recurrenceDialog && (
            <RecurrenceEditor
              value={recurrenceDialog.value}
              onChange={(next) => setRecurrenceDialog({ strategyId: recurrenceDialog.strategyId, value: next })}
            />
          )}
          <DialogFooter className="pt-4 border-t">
            <Button variant="outline" size="sm" onClick={() => setRecurrenceDialog(null)}>
              Cancel
            </Button>
            <Button
              variant="default"
              size="sm"
              onClick={() => {
                if (!recurrenceDialog) return;
                const strategy = strategyList.find((s) => s.id === recurrenceDialog.strategyId);
                const existingStartDate = parseStrategyConfig(strategy?.config).scheduleConfig?.startDate || '';
                handleRecurrenceSave(
                  recurrenceDialog.strategyId,
                  recurrenceDialog.value,
                  existingStartDate,
                  strategy?.publishingMode,
                );
              }}
            >
              Save
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

/**
 * Header chip: background-scanning health. Scanning is ALWAYS-ON — the
 * internal keep-alive chain bootstraps itself on any visit and re-spawns
 * itself in short links, so RSS + scheduled strategies run with zero setup
 * and zero external services. This dialog is pure status.
 */
function AutoScanStatus() {
  const [open, setOpen] = useState(false);
  const infoQuery = trpc.strategy.cronInfo.useQuery(undefined, { enabled: open }) as any;
  const info = infoQuery.data as
    | {
        lastScan?: string | null;
        nextScheduled?: string | null;
        keepalive?: { lastBeat: string | null; aliveNow: boolean };
      }
    | undefined;

  return (
    <>
      <Button
        variant="ghost"
        size="sm"
        className="h-7 text-xs"
        onClick={() => setOpen(true)}
        title="Background scanning status"
      >
        <Clock className="w-3.5 h-3.5 mr-1" />
        Auto-scan
      </Button>
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="sm:max-w-[480px]">
          <DialogHeader>
            <DialogTitle>Background scanning</DialogTitle>
          </DialogHeader>
          {infoQuery.isLoading ? (
            <div className="flex justify-center py-6">
              <Spinner />
            </div>
          ) : (
            <div className="space-y-4 py-1 text-sm">
              <div className="flex items-center gap-2">
                <Badge variant="secondary">
                  {info?.keepalive?.aliveNow ? 'Running' : 'Waking up…'}
                </Badge>
                <span className="text-xs text-muted-foreground">
                  Automatic — no setup needed.
                </span>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <div className="text-xs text-muted-foreground mb-0.5">Last feed scan</div>
                  <div>{info?.lastScan ?? 'Not yet run'}</div>
                </div>
                <div>
                  <div className="text-xs text-muted-foreground mb-0.5">Last heartbeat</div>
                  <div>{info?.keepalive?.lastBeat ? `${info.keepalive.lastBeat} UTC` : '—'}</div>
                </div>
              </div>
              <p className="text-xs text-muted-foreground">
                RSS feeds and scheduled strategies are watched continuously by a built-in
                background process. It starts on its own and restarts itself automatically —
                new feed items and due articles generate even when nobody has the site open.
              </p>
            </div>
          )}
        </DialogContent>
      </Dialog>
    </>
  );
}
