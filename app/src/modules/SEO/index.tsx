/**
 * SEO Module — content-SEO workbench.
 *
 * A live table of the WordPress site's posts & pages with inline editing of
 * SEO meta (title, description, keywords) that read/write through whichever
 * SEO plugin is active (Yoast / Rank Math / SEOPress) plus an internal
 * backup, sortable columns, bulk delete, and quick-create. Backed by the
 * `pcm/v1/seo` REST module.
 */

import {
  useMemo, useState, useCallback, useEffect, useRef, Fragment,
  type KeyboardEvent, type PointerEvent as ReactPointerEvent,
  type HTMLAttributes, type ThHTMLAttributes, type TdHTMLAttributes, type TableHTMLAttributes,
} from 'react';
import { createPortal } from 'react-dom';
import {
  Plus, Trash2, ExternalLink, SquarePen, Loader2, Sparkles, Check, X, Globe, ChevronDown, ChevronRight, RefreshCw, Copy,
  Type, AlignLeft, KeyRound, Tags, FileText, CircleDot, Braces, User, type LucideIcon,
  Image as ImageIcon, Link2, Calendar, TrendingUp, Eye, Unlink,
  MousePointerClick, Crosshair, Search as SearchIcon, Target,
} from 'lucide-react';
import { toast } from 'sonner';

import { trpc } from '@/lib/trpc';
import { ModuleHeader } from '@/components/shared/ModuleHeader';
import { PillButton } from '@/components/shared';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import { useSortableTable } from '@/hooks/useSortableTable';
import { useSettings } from '@/contexts/AppContext';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import {
  DropdownMenu, DropdownMenuTrigger, DropdownMenuContent,
  DropdownMenuCheckboxItem, DropdownMenuLabel, DropdownMenuSeparator,
  DropdownMenuItem, DropdownMenuSub, DropdownMenuSubTrigger, DropdownMenuSubContent,
} from '@/components/ui/dropdown-menu';

import { useSeoContent } from './hooks/useSeoContent';
import { useRemoteSeoContent } from './hooks/useRemoteSeoContent';
import { useColumnFilters } from '@/hooks/useColumnFilters';
import { useViews, type SeoView } from './hooks/useViews';
import { useColumnLayout } from '@/hooks/useColumnLayout';
import { ColumnHead } from '@/components/ui/column-head';
import { CELL_PILL_NEUTRAL } from '@/components/ui/table-cell-recipes';
import { ViewsToolbar } from './ViewsToolbar';
import { buildFilterDefs, type FilterDef, type FilterOption } from './seoFilters';
import { AIReadinessPanel } from './AIReadinessPanel';
import { RemoteAIReadinessPanel } from './RemoteAIReadinessPanel';
import { LlmInfoSection } from './LlmInfoEditor';
import { SiteSettingsPanel } from './SiteSettingsPanel';
import { RemoteSiteSettingsPanel } from './RemoteSiteSettingsPanel';
import { BusinessPanel } from './BusinessPanel';
import { SchemaCell } from './SchemaCell';
import { OptimizeModal } from './OptimizeModal';
import { LinksPopup, type LinkKind } from './LinksPopup';
import { RedirectPopup } from './RedirectPopup';
import { HeadingRows } from './HeadingsPanel';
import { SectionModal } from './SectionModal';
import { SEO_TABLE_GRID } from './seo-table';
import { Pill, type PillVariant } from '@/components/ui/pill';
import { SEO_TEXT_FIELDS, type SeoRow } from './types';

// WordPress media library global (wp_enqueue_media() is called in class-pcm-admin.php).
declare const wp: any;

// ── Plain spreadsheet primitives ──
// Bare <table> elements (NOT shadcn's Table, which forces h-12/p-4/border-b-only/
// row-hover + an extra overflow wrapper). All grid styling comes from the table's
// own className below, so this renders a true, fully-controlled spreadsheet grid.
const Table = (p: TableHTMLAttributes<HTMLTableElement>) => <table {...p} />;
const TableHeader = (p: HTMLAttributes<HTMLTableSectionElement>) => <thead {...p} />;
const TableBody = (p: HTMLAttributes<HTMLTableSectionElement>) => <tbody {...p} />;
const TableRow = (p: HTMLAttributes<HTMLTableRowElement>) => <tr {...p} />;
const TableHead = (p: ThHTMLAttributes<HTMLTableCellElement>) => <th {...p} />;
const TableCell = (p: TdHTMLAttributes<HTMLTableCellElement>) => <td {...p} />;

/**
 * Inline-editable text cell: click to edit (Enter/blur saves, Esc cancels).
 * AI generation is no longer per-cell — it runs from the bulk "Generate all"
 * split button; this cell only DISPLAYS a staged suggestion (accept / reject).
 */
function EditableCell({
  value,
  placeholder,
  onSave,
  onGenerate,
  generating,
  suggestion,
  onAccept,
  onReject,
  emphasis,
}: {
  value: string;
  placeholder?: string;
  onSave: (next: string) => void;
  /** Per-cell AI generate (✦). Omit to hide the icon (non-generatable cells). */
  onGenerate?: () => void;
  generating?: boolean;
  suggestion?: string | null;
  onAccept?: () => void;
  onReject?: () => void;
  /** Render as the primary field (Airtable-style: medium weight, darker). */
  emphasis?: boolean;
}) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(value);

  const commit = () => {
    setEditing(false);
    if (draft !== value) onSave(draft);
  };
  const onKey = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') { e.preventDefault(); commit(); }
    if (e.key === 'Escape') { setDraft(value); setEditing(false); }
  };

  // Staged AI suggestion → show new value with accept / reject / re-generate.
  if (suggestion != null) {
    return (
      <div className="space-y-1 rounded-md bg-accent border border-primary/20 p-1.5">
        <div className="text-xs text-foreground break-words whitespace-normal" title={suggestion}>{suggestion}</div>
        <div className="flex items-center gap-1">
          <button type="button" onClick={onAccept} disabled={generating} title="Accept" className="inline-flex items-center gap-0.5 rounded bg-green-600 px-1.5 py-0.5 text-[10px] font-medium text-white hover:bg-green-700 disabled:opacity-60">
            <Check className="w-3 h-3" /> Accept
          </button>
          <button type="button" onClick={onReject} disabled={generating} title="Reject" className="inline-flex items-center gap-0.5 rounded border border-border px-1.5 py-0.5 text-[10px] text-muted-foreground hover:bg-muted disabled:opacity-60">
            <X className="w-3 h-3" /> Reject
          </button>
          {onGenerate && (
            <button type="button" onClick={onGenerate} disabled={generating} title="Re-generate" className="inline-flex items-center gap-0.5 rounded border border-border px-1.5 py-0.5 text-[10px] text-muted-foreground hover:bg-muted disabled:opacity-60">
              {generating ? <Loader2 className="w-3 h-3 animate-spin text-primary" /> : <RefreshCw className="w-3 h-3" />} Re-generate
            </button>
          )}
        </div>
      </div>
    );
  }

  if (editing) {
    return (
      <Input
        autoFocus
        value={draft}
        onChange={(e) => setDraft(e.target.value)}
        onBlur={commit}
        onKeyDown={onKey}
        className="h-7 text-xs"
      />
    );
  }
  return (
    <div className="flex items-center gap-1 group">
      <button
        type="button"
        onClick={() => { setDraft(value); setEditing(true); }}
        className={`flex-1 min-w-0 text-left truncate text-xs leading-snug hover:underline decoration-dotted ${emphasis ? 'font-medium text-foreground' : ''}`}
        title={value || placeholder}
      >
        {value || <span className="text-muted-foreground/60">{placeholder ?? '—'}</span>}
      </button>
      {onGenerate && (
        <button
          type="button"
          onClick={onGenerate}
          disabled={generating}
          title="Generate with AI"
          className="shrink-0 text-muted-foreground/50 hover:text-primary opacity-0 group-hover:opacity-100 disabled:opacity-100"
        >
          {generating ? <Loader2 className="w-3.5 h-3.5 animate-spin text-primary" /> : <Sparkles className="w-3.5 h-3.5" />}
        </button>
      )}
    </div>
  );
}

/** Inner-tab id → human label, used by the remote-site placeholder. */
const SECTION_LABEL: Record<'content' | 'air' | 'site' | 'business', string> = {
  content: 'Content',
  air: 'AI Readiness',
  site: 'Site settings',
  business: 'Business',
};

/**
 * Shown when a REMOTE connected site is the active site tab. The SEO backend
 * (`pcm/v1/seo/*`) only operates on the local WordPress install today — remote
 * SEO management (proxying through the site connector) isn't wired up yet.
 */
function RemoteSitePlaceholder({ siteName, siteUrl, section }: { siteName: string; siteUrl?: string; section: string }) {
  const note = section === 'Business'
    ? 'The Business profile (Google Business Profile) is set per Brand and applies to all your content — manage it under SEO → Business on This Site.'
    : `${section} is served by the site itself (e.g. llms.txt, robots.txt, on-page schema), so it’s managed from ${siteName}’s own WordPress admin. From here you get the full Content workbench — list, edit, AI-generate, status, create, and link-scan.`;
  return (
    <div className="border border-dashed border-border rounded-xl p-12 text-center">
      <Globe className="w-8 h-8 mx-auto mb-3 text-muted-foreground/50" />
      <p className="text-sm font-medium">
        {section} for <span className="text-foreground">{siteName}</span>
      </p>
      <p className="mt-1.5 text-xs text-muted-foreground max-w-md mx-auto">{note}</p>
      {siteUrl && (
        <a href={siteUrl} target="_blank" rel="noopener noreferrer"
           className="mt-3 inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground">
          <ExternalLink className="w-3.5 h-3.5" /> {siteUrl}
        </a>
      )}
    </div>
  );
}

/** Cell fields that support AI generation (every editable text column). */
const GENERATABLE = new Set(['title', 'slug', 'metaTitle', 'metaDescription', 'primaryKeyword', 'metaKeywords']);
/** Column key → the prompt `use` base (templates are keyed `{use}_{generate|optimize}`). */
const SEO_USE_BY_COL: Record<string, string> = {
  title: 'page_title', slug: 'slug', metaTitle: 'meta_title', metaDescription: 'meta_description',
  primaryKeyword: 'primary_keyword', metaKeywords: 'meta_keywords',
};
/** Generatable fields for the bulk "Generate all" split button (key → short label). */
const GEN_FIELDS: { key: string; label: string }[] = [
  { key: 'title', label: 'Title' },
  { key: 'slug', label: 'Slug' },
  { key: 'metaTitle', label: 'Meta Title' },
  { key: 'metaDescription', label: 'Meta Desc' },
  { key: 'primaryKeyword', label: 'Primary KW' },
  { key: 'metaKeywords', label: 'Keywords' },
];

/** Airtable-style field-type icon per text column (shown muted in the header). */
const FIELD_ICONS: Record<string, LucideIcon> = {
  metaTitle: Type,
  metaDescription: AlignLeft,
  primaryKeyword: KeyRound,
  metaKeywords: Tags,
};

/** Status → Pill variant (colors live in the global Pill, never here). */
function statusPillVariant(status: string): PillVariant {
  return (['publish', 'pending', 'private', 'future'] as const).includes(status as any)
    ? (status as PillVariant)
    : 'draft';
}

/** Columns that can be shown/hidden + saved in a View (selection col is fixed). */
const TOGGLE_COLUMNS: { key: string; label: string }[] = [
  { key: 'type', label: 'Type' },
  { key: 'title', label: 'Title' },
  { key: 'slug', label: 'Slug' },
  { key: 'featuredImage', label: 'Image' },
  { key: 'status', label: 'Status' },
  ...SEO_TEXT_FIELDS.map((f) => ({ key: f.key as string, label: f.label })),
  { key: 'supportingKeyword', label: 'Supporting KW' },
  { key: 'schema', label: 'Schema' },
  { key: 'internalLinks', label: 'Internal Links' },
  { key: 'externalLinks', label: 'External Links' },
  { key: 'brokenLinks', label: 'Broken Links' },
  { key: 'date', label: 'Date' },
  // Google Search Console block — filled by the "GSC stats" pull (last 28 days).
  { key: 'traffic', label: 'Clicks' },
  { key: 'impressions', label: 'Impressions' },
  { key: 'ctr', label: 'CTR' },
  { key: 'position', label: 'Pos (GSC)' },
  { key: 'prtPosition', label: 'Pos (PRT)' },
  { key: 'gscKeywords', label: 'Top Queries' },
  { key: 'author', label: 'Author' },
  { key: 'actions', label: 'Actions' },
];

// --- Column layout (resize + reorder) metadata -------------------------------
/** Reorderable/resizable column keys, in their natural default order. */
const COLUMN_KEYS = TOGGLE_COLUMNS.map((c) => c.key);
/** key → label, for header rendering. */
const COLUMN_LABELS: Record<string, string> = Object.fromEntries(TOGGLE_COLUMNS.map((c) => [c.key, c.label]));
/** key → text-field descriptor (the AI-editable meta columns). */
const TEXT_FIELD_BY_KEY = Object.fromEntries(SEO_TEXT_FIELDS.map((f) => [f.key as string, f]));
/** Columns the table can sort by — each must have an accessor in useSortableTable. */
type SeoSortKey =
  | 'title' | 'type' | 'status' | 'date'
  | 'slug' | 'supportingKeyword' | 'featuredImage'
  | 'internalLinks' | 'externalLinks' | 'brokenLinks'
  | 'metaTitle' | 'metaDescription' | 'primaryKeyword' | 'metaKeywords'
  | 'author' | 'schema'
  | 'traffic' | 'impressions' | 'ctr' | 'position' | 'prtPosition' | 'gscKeywords';
/** Columns that support click-to-sort. */
const SORTABLE_KEYS = new Set<string>([
  'type', 'title', 'status', 'date',
  'slug', 'supportingKeyword', 'featuredImage',
  'internalLinks', 'externalLinks', 'brokenLinks',
  'metaTitle', 'metaDescription', 'primaryKeyword', 'metaKeywords',
  'author', 'schema',
  'traffic', 'impressions', 'ctr', 'position', 'prtPosition', 'gscKeywords',
]);
/** Leading header icon per column. */
const HEAD_ICONS: Record<string, LucideIcon> = {
  type: FileText, title: Type, status: CircleDot, schema: Braces, author: User,
  slug: Link2, featuredImage: ImageIcon, supportingKeyword: KeyRound,
  date: Calendar, traffic: TrendingUp,
  impressions: Eye, ctr: MousePointerClick, position: Crosshair, prtPosition: Target, gscKeywords: SearchIcon,
  internalLinks: Link2, externalLinks: ExternalLink, brokenLinks: Unlink, ...FIELD_ICONS,
};
/** Default px width per column (seeds the spreadsheet layout on first use). */
const DEFAULT_COLUMN_WIDTHS: Record<string, number> = {
  type: 90, title: 240, slug: 160, featuredImage: 72, status: 120,
  metaTitle: 200, metaDescription: 260, primaryKeyword: 150, metaKeywords: 180,
  supportingKeyword: 150, schema: 150, date: 120, traffic: 90, author: 120,
  impressions: 110, ctr: 80, position: 95, prtPosition: 95, gscKeywords: 220,
  internalLinks: 110, externalLinks: 110, brokenLinks: 110,
  actions: 100,
};
/** Fixed leading selection/row-number column (not reorderable/resizable). */
const SELECT_COL_WIDTH = 44;

export function SEOModule() {
  const { rows: localRows, options, isLoading: localLoading, saveCell: localSaveCell, quickCreate: localQuickCreate, bulkDelete: localBulkDelete, bulkDuplicate: localBulkDuplicate, generateField: localGenerateField, scanLinks: localScanLinks } = useSeoContent();

  // Text models available for AI generation (registry). The user picks one in the
  // header dropdown; its id+provider is sent with every generate call so that
  // model produces the content (else the backend default is used).
  const { data: textModelsRaw = [] } = trpc.models.getForGeneration.useQuery(
    { type: 'text' },
    { staleTime: 30_000 },
  ) as { data?: any[] };
  const textModels = useMemo(
    () => (textModelsRaw ?? []).map((m) => ({
      id: String(m.modelId),
      name: String(m.customName || m.originalName || m.modelId),
      provider: String(m.provider),
    })),
    [textModelsRaw],
  );
  const [genModelId, setGenModelId] = useState<string>(() => {
    try { return localStorage.getItem('pcm:seo:gen-model') ?? ''; } catch { return ''; }
  });
  const setGenModel = useCallback((id: string) => {
    setGenModelId(id);
    try { localStorage.setItem('pcm:seo:gen-model', id); } catch { /* ignore */ }
  }, []);
  const genProvider = textModels.find((m) => m.id === genModelId)?.provider;

  // SEO prompt templates (module=seo) → the per-column "generate with template" picker.
  const { data: seoTemplatesRaw } = trpc.templates.list.useQuery({ module: 'seo' }, { staleTime: 30_000 }) as { data?: any[] };
  const seoTemplates = useMemo(() => (Array.isArray(seoTemplatesRaw) ? seoTemplatesRaw : []), [seoTemplatesRaw]);
  /** Which column is currently bulk-generating (header ✦ → pick template → whole column). */
  const [columnGenerating, setColumnGenerating] = useState<string | null>(null);
  const templatesForCol = useCallback((col: string) => {
    const use = SEO_USE_BY_COL[col];
    if (!use) return [] as { id: number; name: string; sectionIsDefault?: boolean }[];
    return seoTemplates
      .filter((t: any) => typeof t.type === 'string' && t.type.startsWith(use + '_'))
      .map((t: any) => ({ id: Number(t.id), name: String(t.name), sectionIsDefault: !!t.isDefault }));
  }, [seoTemplates]);

  // Default generation model comes from Settings → Module Defaults → "SEO Module"
  // (mirrors Writer/Copy). A configured default is applied on load; the header
  // dropdown still lets the user override it per session.
  const { settings } = useSettings();
  useEffect(() => {
    if (settings.defaultSeoModel) setGenModel(settings.defaultSeoModel);
  }, [settings.defaultSeoModel, setGenModel]);

  // Site scope: the local WP install ('local') or a connected remote site (id).
  // Remote-site SEO isn't wired in the backend yet — those tabs show a placeholder.
  const { data: sitesRaw } = trpc.sites.list.useQuery() as { data?: any[] };
  const sites: { id: number; name?: string; url?: string }[] = Array.isArray(sitesRaw) ? sitesRaw : [];
  const [siteId, setSiteId] = useState<number | 'local'>('local');
  const isLocal = siteId === 'local';
  // Expandable heading editor: which page rows have their H1–H6 outline open.
  const [expandedRows, setExpandedRows] = useState<Set<number>>(new Set());
  const toggleExpanded = useCallback((id: number) => {
    setExpandedRows((prev) => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  }, []);
  // Collapse all open heading panels when switching between local/remote sites (row
  // ids aren't comparable across scopes).
  useEffect(() => { setExpandedRows(new Set()); }, [siteId]);
  const activeSite = isLocal ? null : sites.find((s) => Number(s.id) === siteId) ?? null;
  // Remote site (Phase 1): read + inline-edit its SEO via the connector proxy.
  // Generation/scanning/creation stay local-only and are gated on isLocal below.
  const remote = useRemoteSeoContent(isLocal || typeof siteId !== 'number' ? null : siteId);
  const rows = isLocal ? localRows : remote.rows;
  const saveCell = isLocal ? localSaveCell : remote.saveCell;
  const isLoading = isLocal ? localLoading : remote.isLoading;
  // Generation works for both: the hub runs the LLM, then writes back via saveCell.
  const generateField = isLocal ? localGenerateField : remote.generateField;
  // Create a draft post/page — local or on the connected remote site.
  const quickCreate = isLocal ? localQuickCreate : remote.quickCreate;
  // Link scanning — local or on the connected remote site (analysis runs on the hub).
  const scanLinks = isLocal ? localScanLinks : remote.scanLinks;
  // Bulk delete — local hook or the connected site's proxy (trash). Status uses the
  // effective saveCell (works remote); duplicate stays local-only.
  const bulkDelete = isLocal ? localBulkDelete : remote.deleteRows;
  const bulkDuplicate = isLocal ? localBulkDuplicate : remote.bulkDuplicate;

  // ── Google Search Console stats (Traffic / Impressions / CTR / Position / Top Queries) ──
  // Pulled on demand via the "GSC stats" button — one property-wide query (last 28 days),
  // results keyed by normalized page URL and matched to rows via their permalink. Works for
  // the local site ('' → home_url on the backend) and connected sites (their URL).
  const [gscPages, setGscPages] = useState<Record<string, { clicks: number; impressions: number; ctr: number; position: number; keywords: string[] }>>({});
  const [gscRange, setGscRange] = useState<{ start: string; end: string } | null>(null);
  const [gscPulling, setGscPulling] = useState(false);
  const gscStatsMutation = trpc.integrations.gscStats.useMutation();
  // Mirrors PCM_GSC::norm_url — strip protocol/www/trailing slash, PERCENT-DECODE the path, and
  // lowercase. Decoding + lowercasing is what lets non-ASCII slugs (Swedish å/ä/ö) match: GSC
  // returns `/tandv%C3%A5rd` while a permalink may be raw `/tandvård` with different hex case.
  const normGscUrl = useCallback((url: string) => {
    try {
      const u = new URL(url);
      const host = u.hostname.replace(/^www\./, '');
      let path = u.pathname;
      try { path = decodeURIComponent(path); } catch { /* leave as-is on malformed % */ }
      return (host + path.replace(/\/+$/, '')).toLowerCase();
    } catch { return ''; }
  }, []);
  const handlePullGsc = useCallback(async () => {
    if (gscPulling) return;
    setGscPulling(true);
    try {
      const data: any = await gscStatsMutation.mutateAsync({ site: isLocal ? '' : (activeSite?.url ?? ''), days: 28 });
      const pages = data?.pages && typeof data.pages === 'object' ? data.pages : {};
      setGscPages(pages);
      setGscRange(data?.range ?? null);
      const property = String(data?.property ?? '');
      const returned = Object.keys(pages).length;
      // How many CURRENT rows actually matched a returned page — this is what fills the columns.
      // Reporting it turns "sometimes nothing shows" into a clear message (data vs URL-match issue).
      const matched = rows.reduce((c, r) => (pages[normGscUrl(r.permalink ?? '')] ? c + 1 : c), 0);
      if (returned === 0) {
        // Connected-but-empty is NORMAL for a freshly added property — say so instead of
        // sounding like an error. Real Google errors surface via the catch below with
        // Google's actual message.
        toast.info(`Search Console: ${property} is connected, but has no data yet for this date range — new sites can take a few days to show stats.`);
      } else if (matched === 0) {
        toast.warning(`Search Console returned ${returned} page${returned === 1 ? '' : 's'}, but none matched this table’s URLs (${property}). Those pages may not be posts/pages listed here, or the site address differs.`);
      } else {
        toast.success(`Search Console: filled ${matched} of ${returned} page${returned === 1 ? '' : 's'} — ${property}`);
      }
    } catch (e: any) {
      toast.error(e?.message ?? 'Could not pull Search Console stats');
    } finally {
      setGscPulling(false);
    }
  }, [gscPulling, gscStatsMutation, isLocal, activeSite, rows, normGscUrl]);

  // ── ProRankTracker ranks ("Pos (PRT)" column) — the rank of each row's PRIMARY KEYWORD in the
  // PRT project auto-matched to this site. PRT is more accurate than GSC's average position, so the
  // two live side-by-side. Pulled on demand via the "PRT ranks" button; keyed by lowercased keyword.
  const [prtRanks, setPrtRanks] = useState<Record<string, { rank: number; matchedUrl: string; engine: string }>>({});
  const [prtPulling, setPrtPulling] = useState(false);
  const prtPageRanksMutation = trpc.integrations.prtPageRanks.useMutation();
  // Keyword key for matching a row's Primary KW to a PRT-tracked term. Collapse ALL runs of
  // whitespace (incl. non-breaking spaces from copy-paste — JS \s matches U+00A0) to one space,
  // then trim + lowercase. MUST stay byte-identical to the backend's key in prt_page_ranks so
  // "best  shoes" / "best shoes" / "Best Shoes" all match the same tracked term.
  const normKw = useCallback((s: string) => s.replace(/\s+/g, ' ').trim().toLowerCase(), []);
  const handlePullPrt = useCallback(async () => {
    if (prtPulling) return;
    setPrtPulling(true);
    try {
      const data: any = await prtPageRanksMutation.mutateAsync({ site: isLocal ? '' : (activeSite?.url ?? '') });
      const ranks = data?.ranks && typeof data.ranks === 'object' ? data.ranks : {};
      setPrtRanks(ranks);
      const project = String(data?.project ?? '');
      const tracked = Object.keys(ranks).length;
      // How many rows have a Primary Keyword that PRT tracks — that's what fills "Pos (PRT)".
      const matched = rows.reduce((c, r) => (r.primaryKeyword && ranks[normKw(r.primaryKeyword)] ? c + 1 : c), 0);
      if (tracked === 0) {
        toast.info(`ProRankTracker: no tracked keywords for ${project}.`);
      } else if (matched === 0) {
        toast.warning(`ProRankTracker tracks ${tracked} keyword${tracked === 1 ? '' : 's'} for ${project}, but none match a Primary Keyword in this table. Set each row’s Primary KW to a tracked term.`);
      } else {
        toast.success(`ProRankTracker: filled ${matched} row${matched === 1 ? '' : 's'} from ${tracked} tracked keyword${tracked === 1 ? '' : 's'} — ${project}`);
      }
    } catch (e: any) {
      toast.error(e?.message ?? 'Could not pull ProRankTracker ranks');
    } finally {
      setPrtPulling(false);
    }
  }, [prtPulling, prtPageRanksMutation, isLocal, activeSite, rows, normKw]);
  useEffect(() => { setPrtRanks({}); }, [siteId]);
  // Stats belong to one property — drop them when the user switches site tabs.
  useEffect(() => { setGscPages({}); setGscRange(null); }, [siteId]);
  // GSC filter defs — built here (not in buildFilterDefs) because the data lives in gscPages,
  // not on the row. Numeric columns get "No data / Zero / Has value" choices; position gets
  // SEO-meaningful rank buckets; Top Queries is a free-text "contains".
  const gscFilterDefs = useMemo(() => {
    const stat = (r: SeoRow) => gscPages[normGscUrl(r.permalink ?? '')];
    const numOpts: FilterOption[] = [
      { value: 'nodata', label: 'No GSC data' },
      { value: 'zero', label: 'Zero' },
      { value: 'has', label: 'Has value' },
    ];
    const numMatch = (pick: (g: { clicks: number; impressions: number; ctr: number; position: number }) => number) =>
      (r: SeoRow, v: string): boolean => {
        const g = stat(r);
        if (v === 'nodata') return !g;
        if (!g) return false;
        if (v === 'zero') return pick(g) === 0;
        if (v === 'has') return pick(g) > 0;
        return true;
      };
    return {
      traffic: { key: 'traffic', kind: 'choice', options: numOpts, match: numMatch((g) => g.clicks) },
      impressions: { key: 'impressions', kind: 'choice', options: numOpts, match: numMatch((g) => g.impressions) },
      ctr: { key: 'ctr', kind: 'choice', options: numOpts, match: numMatch((g) => g.ctr) },
      position: {
        key: 'position',
        kind: 'choice',
        options: [
          { value: 'nodata', label: 'No GSC data' },
          { value: 'top3', label: 'Top 3 (≤3)' },
          { value: 'top10', label: '4–10' },
          { value: 'beyond', label: 'Beyond 10' },
        ],
        match: (r: SeoRow, v: string): boolean => {
          const g = stat(r);
          if (v === 'nodata') return !g;
          if (!g) return false;
          const p = g.position;
          if (v === 'top3') return p > 0 && p <= 3;
          if (v === 'top10') return p > 3 && p <= 10;
          if (v === 'beyond') return p > 10;
          return true;
        },
      },
      gscKeywords: {
        key: 'gscKeywords',
        kind: 'text',
        match: (r: SeoRow, v: string): boolean => {
          const g = stat(r);
          return !!g && g.keywords.join(' ').toLowerCase().includes(v.toLowerCase());
        },
      },
    } as Record<string, FilterDef>;
  }, [gscPages, normGscUrl]);
  // PRT filter def — rank of the row's primary keyword (data in prtRanks, not on the row).
  const prtFilterDefs = useMemo(() => {
    const rankOf = (r: SeoRow): number | null => {
      const p = r.primaryKeyword ? prtRanks[normKw(r.primaryKeyword)] : undefined;
      return p ? p.rank : null; // null = not tracked; 0 = tracked but not ranking
    };
    return {
      prtPosition: {
        key: 'prtPosition',
        kind: 'choice',
        options: [
          { value: 'untracked', label: 'Not tracked' },
          { value: 'unranked', label: 'Not ranking' },
          { value: 'top3', label: 'Top 3 (≤3)' },
          { value: 'top10', label: '4–10' },
          { value: 'beyond', label: 'Beyond 10' },
        ],
        match: (r: SeoRow, v: string): boolean => {
          const rank = rankOf(r);
          if (v === 'untracked') return rank == null;
          if (rank == null) return false;
          if (v === 'unranked') return rank === 0;
          if (v === 'top3') return rank > 0 && rank <= 3;
          if (v === 'top10') return rank > 3 && rank <= 10;
          if (v === 'beyond') return rank > 10;
          return true;
        },
      },
    } as Record<string, FilterDef>;
  }, [prtRanks, normKw]);
  // Per-column filters (funnel icon in each column header).
  const filterDefs = useMemo(() => ({ ...buildFilterDefs(options), ...gscFilterDefs, ...prtFilterDefs }), [options, gscFilterDefs, prtFilterDefs]);
  const { values: filterValues, setFilter, setAll, clearAll, apply, activeCount } = useColumnFilters<SeoRow>();
  // Column visibility (Columns menu) — missing/true = visible, false = hidden.
  const [cols, setCols] = useState<Record<string, boolean>>(
    () => Object.fromEntries(TOGGLE_COLUMNS.map((c) => [c.key, true])),
  );
  const toggleCol = useCallback((key: string) => setCols((c) => ({ ...c, [key]: c[key] === false })), []);
  const vis = (key: string) => cols[key] !== false;
  // Spreadsheet-style column order + widths (drag to reorder / resize; persisted
  // to localStorage). Selection column stays fixed and is not part of this.
  const { order: colOrder, width: colWidth, setWidth: setColWidth, moveColumn, reset: resetColumnLayout } =
    useColumnLayout(COLUMN_KEYS, DEFAULT_COLUMN_WIDTHS, 'pcm:seo:col-layout:v1');
  const [dragKey, setDragKey] = useState<string | null>(null);
  const [dragOverKey, setDragOverKey] = useState<string | null>(null);
  // Saved Views (per-user, persisted via the seo REST API).
  const { views, saveView, removeView, setDefaultView } = useViews();
  const [appliedViewId, setAppliedViewId] = useState<number | null>(null);

  const applyView = useCallback((view: SeoView) => {
    const allTrue = Object.fromEntries(TOGGLE_COLUMNS.map((c) => [c.key, true]));
    setCols({ ...allTrue, ...(view.config?.columns ?? {}) });
    setAll(view.config?.filters ?? {});
    setAppliedViewId(view.id);
  }, [setAll]);

  const resetView = useCallback(() => {
    setCols(Object.fromEntries(TOGGLE_COLUMNS.map((c) => [c.key, true])));
    clearAll();
    setAppliedViewId(null);
  }, [clearAll]);

  const handleSaveView = useCallback((name: string) => {
    void saveView(name, { columns: cols, filters: filterValues });
  }, [saveView, cols, filterValues]);

  const handleDeleteView = useCallback((id: number) => {
    void removeView(id);
    setAppliedViewId((cur) => (cur === id ? null : cur));
  }, [removeView]);

  const handleSetDefaultView = useCallback((id: number, isDefault: boolean) => {
    void setDefaultView(id, isDefault);
  }, [setDefaultView]);

  // Auto-apply the default view once, on first load (before the user picks one).
  const defaultApplied = useRef(false);
  useEffect(() => {
    if (defaultApplied.current || appliedViewId !== null) return;
    const def = views.find((v) => v.isDefault);
    if (def) {
      defaultApplied.current = true;
      applyView(def);
    }
  }, [views, appliedViewId, applyView]);

  const [selected, setSelected] = useState<Set<number>>(new Set());
  // Drives the row-number → checkbox swap in JS rather than Tailwind's
  // `group-hover:` (which Tailwind v4 gates behind `@media (hover: hover)`, so
  // it never fires on touch-capable / coarse-pointer devices).
  const [hoveredId, setHoveredId] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);
  const [tab, setTab] = useState<'content' | 'air' | 'site' | 'business'>('content');
  // Optimistic per-row schema-type overrides (SchemaCell persists via REST).
  const [schemaOverrides, setSchemaOverrides] = useState<Record<number, string[]>>({});
  const [optimizeRow, setOptimizeRow] = useState<SeoRow | null>(null);
  const [previewRow, setPreviewRow] = useState<SeoRow | null>(null);
  // Full-page editor (dynamic rules) — the repurposed row pen. Connected sites
  // only; local rows keep the plain WP-editor link (no rule engine locally).
  const [pageEditRow, setPageEditRow] = useState<SeoRow | null>(null);
  // Connected-site preview: a direct cross-origin iframe renders logged-out (the
  // remote login cookie is a blocked third-party cookie) → no admin bar. So for a
  // connected site we fetch the page AUTHENTICATED via the connector (server-side)
  // and render it same-origin (srcDoc) — the admin bar shows. Local stays a direct src.
  const [previewHtml, setPreviewHtml] = useState<string | null>(null);
  const [previewLoading, setPreviewLoading] = useState(false);
  const sitePreview = trpc.seo.sitePreview.useMutation();
  useEffect(() => {
    if (!previewRow || !previewRow.permalink || isLocal || typeof siteId !== 'number') {
      setPreviewHtml(null);
      setPreviewLoading(false);
      return;
    }
    let cancelled = false;
    setPreviewLoading(true);
    setPreviewHtml(null);
    sitePreview.mutateAsync({ siteId, url: previewRow.permalink })
      .then((r: any) => { if (!cancelled) setPreviewHtml(String(r?.html ?? '')); })
      .catch(() => { if (!cancelled) setPreviewHtml(null); })
      .finally(() => { if (!cancelled) setPreviewLoading(false); });
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [previewRow, isLocal, siteId]);
  const [scanningRows, setScanningRows] = useState<Set<number>>(() => new Set());
  const handleScan = useCallback((id: number) => {
    setScanningRows((s) => { if (s.has(id)) return s; const n = new Set(s); n.add(id); return n; });
    scanLinks(id).finally(() =>
      setScanningRows((s) => { const n = new Set(s); n.delete(id); return n; }),
    );
  }, [scanLinks]);
  // Bulk "Scan links" — scans every visible row's links (internal/external/dead).
  const [scanningAll, setScanningAll] = useState(false);
  // Per-post link inspector popup (clicked from an Internal/External/Dead count cell).
  const [linksPopup, setLinksPopup] = useState<{ id: number; kind: LinkKind; title: string; type: 'post' | 'page' } | null>(null);
  // AI staging: suggestions keyed `${id}:${field}`, plus the in-flight key.
  const [staged, setStaged] = useState<Record<string, string>>({});
  const [genKey, setGenKey] = useState<string | null>(null);
  // Aggregate progress for bulk runs ({done}/{total} cells).
  const [progress, setProgress] = useState<{ done: number; total: number } | null>(null);
  // Generation fill mode: 'empty' skips cells that already have content (safe
  // default); 'overwrite' regenerates every selected cell. Applies to ALL bulk
  // generation (Generate all + Generate selected).
  // Asked via a dialog on each Generate trigger (all / selected / column ✦) so a bulk
  // run never silently overwrites existing content. Holds the staged run() until answered.
  const [pendingGen, setPendingGen] = useState<{ run: (mode: 'empty' | 'overwrite') => void } | null>(null);
  // Which columns the "Generate all" split-button dropdown will generate (default: all).
  const [genCols, setGenCols] = useState<Set<string>>(() => new Set(GEN_FIELDS.map((f) => f.key)));
  const toggleGenCol = useCallback((key: string) => setGenCols((cur) => {
    const next = new Set(cur);
    next.has(key) ? next.delete(key) : next.add(key);
    return next;
  }), []);

  // Slug saves on CONNECTED published pages offer a redirect from the old
  // permalink (the popup writes nothing unless the user says yes). Drafts
  // have no public URL to preserve; the local tab has no serving engine.
  const [redirectOffer, setRedirectOffer] = useState<{ fromUrl: string; toUrl: string } | null>(null);
  const saveSlug = useCallback((id: number, value: string | number): Promise<void> => {
    const row = rows.find((r) => Number(r.id) === id);
    const oldSlug = String(row?.slug ?? '');
    const oldPermalink = String(row?.permalink ?? '');
    return saveCell(id, 'slug', value).then(() => {
      const newSlug = String(value).trim();
      if (isLocal || typeof siteId !== 'number' || !row || row.status !== 'publish'
        || oldPermalink === '' || newSlug === '' || newSlug === oldSlug) return;
      try {
        const u = new URL(oldPermalink);
        const parts = u.pathname.split('/').filter(Boolean);
        if (parts.length === 0) return;
        parts[parts.length - 1] = newSlug;
        setRedirectOffer({ fromUrl: oldPermalink, toUrl: `${u.origin}/${parts.join('/')}/` });
      } catch { /* malformed permalink — nothing to offer */ }
    });
  }, [rows, saveCell, isLocal, siteId]);

  const acceptStaged = useCallback((id: number, field: string) => {
    const key = `${id}:${field}`;
    setStaged((s) => {
      const v = s[key];
      if (v !== undefined) void (field === 'slug' ? saveSlug(id, v) : saveCell(id, field, v));
      const next = { ...s };
      delete next[key];
      return next;
    });
  }, [saveCell, saveSlug]);

  const rejectStaged = useCallback((id: number, field: string) => {
    setStaged((s) => { const next = { ...s }; delete next[`${id}:${field}`]; return next; });
  }, []);

  // Per-cell AI: generate ONE field for ONE row (the cell ✦ icon), staging the
  // result for review. Uses the same effective generateField as bulk (local or remote).
  const handleGenerate = useCallback(async (id: number, field: string) => {
    const key = `${id}:${field}`;
    setGenKey(key);
    try {
      const value = await generateField(id, field, genModelId || undefined, genProvider);
      setStaged((s) => ({ ...s, [key]: value }));
    } catch { /* toast in hook */ } finally {
      setGenKey(null);
    }
  }, [generateField, genModelId, genProvider]);

  // Bulk AI: generate the given fields across every selected row (sequential —
  // gentle on the provider), staging each result for review. In 'empty' mode,
  // cells that already have content are skipped; 'overwrite' regenerates all.
  const runBulk = useCallback(async (fields: string[], mode: 'empty' | 'overwrite') => {
    const ids = Array.from(selected);
    if (ids.length === 0 || fields.length === 0) return;
    const rowById = new Map(rows.map((r) => [r.id, r]));
    const cellIsEmpty = (id: number, field: string) => {
      const r = rowById.get(id);
      return !String(r?.[field as keyof SeoRow] ?? '').trim();
    };
    // Build the work list, honoring the fill mode.
    const jobs: { id: number; field: string }[] = [];
    for (const id of ids) {
      for (const field of fields) {
        if (mode === 'empty' && !cellIsEmpty(id, field)) continue;
        jobs.push({ id, field });
      }
    }
    if (jobs.length === 0) {
      toast('Nothing to generate — selected cells already have content.');
      return;
    }
    setBusy(true);
    const total = jobs.length;
    let done = 0;
    setProgress({ done, total });
    for (const { id, field } of jobs) {
      const key = `${id}:${field}`;
      setGenKey(key);
      try {
        const value = await generateField(id, field, genModelId || undefined, genProvider);
        setStaged((s) => ({ ...s, [key]: value }));
      } catch { /* toast in hook */ }
      done += 1;
      setProgress({ done, total });
    }
    setGenKey(null);
    setProgress(null);
    setBusy(false);
  }, [selected, rows, generateField, genModelId, genProvider]);

  // Accept / discard ALL staged AI suggestions (the source's bar).
  const acceptAllStaged = useCallback(() => {
    setStaged((s) => {
      for (const [key, value] of Object.entries(s)) {
        const sep = key.indexOf(':');
        const id = Number(key.slice(0, sep));
        const field = key.slice(sep + 1);
        if (id) void saveCell(id, field, value);
      }
      return {};
    });
  }, [saveCell]);
  const discardAllStaged = useCallback(() => setStaged({}), []);
  const pendingCount = Object.keys(staged).length;

  const filtered = useMemo(() => apply(rows, filterDefs), [rows, apply, filterDefs]);

  const { sortKey, sortDir, toggleSort, sortedData } = useSortableTable<SeoRow, SeoSortKey>(
    filtered,
    {
      defaultKey: 'date',
      defaultDir: 'desc',
      accessors: {
        title: (r) => r.title.toLowerCase(),
        type: (r) => r.type,
        status: (r) => r.status,
        date: (r) => new Date(r.date).getTime(),
        slug: (r) => r.slug.toLowerCase(),
        supportingKeyword: (r) => r.supportingKeyword.toLowerCase(),
        featuredImage: (r) => (r.featuredImage ? 1 : 0),
        // Unscanned (null) sorts below 0 so scanned rows group together.
        internalLinks: (r) => r.internalLinks ?? -1,
        externalLinks: (r) => r.externalLinks ?? -1,
        brokenLinks: (r) => r.brokenLinks ?? -1,
        metaTitle: (r) => r.metaTitle.toLowerCase(),
        metaDescription: (r) => r.metaDescription.toLowerCase(),
        primaryKeyword: (r) => r.primaryKeyword.toLowerCase(),
        metaKeywords: (r) => r.metaKeywords.toLowerCase(),
        author: (r) => r.author.toLowerCase(),
        schema: (r) => (r.schemaTypes ?? []).join(',').toLowerCase(),
        // GSC columns read the pulled stats map; no-data rows return null → always sort last.
        traffic: (r) => gscPages[normGscUrl(r.permalink ?? '')]?.clicks ?? null,
        impressions: (r) => gscPages[normGscUrl(r.permalink ?? '')]?.impressions ?? null,
        ctr: (r) => gscPages[normGscUrl(r.permalink ?? '')]?.ctr ?? null,
        position: (r) => { const g = gscPages[normGscUrl(r.permalink ?? '')]; return g ? g.position : null; },
        gscKeywords: (r) => { const g = gscPages[normGscUrl(r.permalink ?? '')]; return g ? g.keywords.join(', ').toLowerCase() : null; },
        // PRT rank of the row's primary keyword; not-tracked / rank 0 (not ranking) → null → sorts last.
        prtPosition: (r) => { const p = r.primaryKeyword ? prtRanks[normKw(r.primaryKeyword)] : undefined; return p && p.rank > 0 ? p.rank : null; },
      },
    },
  );

  const visibleIds = sortedData.map((r) => r.id);
  const allSelected = visibleIds.length > 0 && visibleIds.every((id) => selected.has(id));
  const someSelected = selected.size > 0 && !allSelected;

  // Bulk scan — scan every visible row's links sequentially (each does HTTP checks).
  const handleScanAll = useCallback(async () => {
    if (scanningAll) return;
    setScanningAll(true);
    for (const id of sortedData.map((r) => r.id)) {
      setScanningRows((s) => { const n = new Set(s); n.add(id); return n; });
      try { await scanLinks(id); } catch { /* per-row toast in hook */ }
      setScanningRows((s) => { const n = new Set(s); n.delete(id); return n; });
    }
    setScanningAll(false);
  }, [scanningAll, sortedData, scanLinks]);

  // Header ✦ → pick a template → generate the column with that template, staging
  // each result for review. Scope: the SELECTED rows when any are selected,
  // otherwise every visible row. (Selection narrows; preserves visible order.)
  const handleColumnGenerate = useCallback(async (field: string, templateId: number | undefined, mode: 'empty' | 'overwrite') => {
    if (columnGenerating) return;
    const ids = (selected.size > 0
      ? sortedData.filter((r) => selected.has(r.id))
      : sortedData
    )
      .filter((r) => mode === 'overwrite' || !String(r[field as keyof SeoRow] ?? '').trim())
      .map((r) => r.id);
    if (ids.length === 0) { toast('Nothing to generate — those cells already have content.'); return; }
    setColumnGenerating(field);
    const total = ids.length;
    let done = 0;
    setProgress({ done, total });
    for (const id of ids) {
      const key = `${id}:${field}`;
      setGenKey(key);
      try {
        const value = await generateField(id, field, genModelId || undefined, genProvider, templateId);
        setStaged((s) => ({ ...s, [key]: value }));
      } catch { /* toast in hook */ }
      done += 1;
      setProgress({ done, total });
    }
    setGenKey(null);
    setProgress(null);
    setColumnGenerating(null);
  }, [columnGenerating, sortedData, selected, generateField, genModelId, genProvider]);

  const toggleAll = () =>
    setSelected((prev) => {
      if (allSelected) return new Set();
      return new Set(visibleIds);
    });
  const toggleOne = (id: number) =>
    setSelected((prev) => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });

  const handleDelete = useCallback(async () => {
    const ids = Array.from(selected);
    if (ids.length === 0) return;
    setBusy(true);
    try {
      await bulkDelete(ids);
      setSelected(new Set());
    } catch { /* toast surfaced in hook */ } finally {
      setBusy(false);
    }
  }, [selected, bulkDelete]);

  // Bulk: set status on every selected row (sequential saveCell), then refresh.
  const handleBulkStatus = useCallback(async (status: string) => {
    const ids = Array.from(selected);
    if (ids.length === 0) return;
    setBusy(true);
    const total = ids.length;
    let done = 0;
    setProgress({ done, total });
    for (const id of ids) {
      try { await saveCell(id, 'status', status); } catch { /* toast in hook */ }
      done += 1;
      setProgress({ done, total });
    }
    setProgress(null);
    setBusy(false);
  }, [selected, saveCell]);

  // Bulk: duplicate every selected row (local only), then clear selection.
  const handleBulkDuplicate = useCallback(async () => {
    const ids = Array.from(selected);
    if (ids.length === 0) return;
    setBusy(true);
    try {
      await bulkDuplicate(ids);
      setSelected(new Set());
    } catch { /* toast in hook */ } finally {
      setBusy(false);
    }
  }, [selected, bulkDuplicate]);

  const handleCreate = useCallback(async (type: 'post' | 'page') => {
    setBusy(true);
    try { await quickCreate(type); } catch { /* surfaced */ } finally { setBusy(false); }
  }, [quickCreate]);

  // Featured image — open the WP media library, preselect the current image, and
  // set/clear the post thumbnail (same as WordPress' "Featured image"). Local only.
  const openFeaturedImage = useCallback((row: SeoRow) => {
    if (typeof wp === 'undefined' || !wp.media) {
      toast.error('WordPress media library is unavailable.');
      return;
    }
    const frame = wp.media({
      title: 'Featured image',
      button: { text: 'Use image' },
      library: { type: 'image' },
      multiple: false,
    });
    if (isLocal && row.featuredImageId) {
      frame.on('open', () => {
        const selection = frame.state().get('selection');
        const att = wp.media.attachment(row.featuredImageId);
        if (att) { att.fetch(); selection.add([att]); }
      });
    }
    frame.on('select', () => {
      const att = frame.state().get('selection').first()?.toJSON();
      if (isLocal) {
        // Local: set the post thumbnail by attachment id.
        void saveCell(row.id, 'featuredImage', String(att?.id ?? 0));
      } else {
        // Remote: upload the chosen image into the connected site's media library, then
        // set it as the featured image (a local attachment id is meaningless there).
        void remote.setFeaturedImage(row.id, String(att?.url ?? ''));
      }
    });
    frame.open();
  }, [saveCell, isLocal, remote]);

  // Columns in saved order, minus any hidden via the Columns menu.
  const orderedCols = colOrder.filter((k) => vis(k));
  const tableWidth = SELECT_COL_WIDTH + orderedCols.reduce((sum, k) => sum + colWidth(k), 0);

  // Drag the right edge of a header to resize that column (px, persisted).
  const startResize = (key: string) => (e: ReactPointerEvent<HTMLSpanElement>) => {
    e.preventDefault();
    e.stopPropagation();
    const startX = e.clientX;
    const startW = colWidth(key);
    const onMove = (ev: PointerEvent) => setColWidth(key, startW + (ev.clientX - startX));
    const onUp = () => {
      window.removeEventListener('pointermove', onMove);
      window.removeEventListener('pointerup', onUp);
      document.body.style.cursor = '';
    };
    document.body.style.cursor = 'col-resize';
    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', onUp);
  };

  // Header cell for a column, with sort/filter + drag-to-reorder + resize wiring.
  const renderHeader = (key: string) => {
    const sortable = SORTABLE_KEYS.has(key);
    const def = filterDefs[key];
    return (
      <ColumnHead
        key={key}
        label={COLUMN_LABELS[key] ?? key}
        icon={HEAD_ICONS[key]}
        className={key === 'actions' ? 'text-center' : undefined}
        sort={sortable
          ? { active: sortKey === key, dir: sortDir, onToggle: () => toggleSort(key as SeoSortKey) }
          : undefined}
        filter={key !== 'actions' && def
          ? { def, value: filterValues[key] ?? '', onChange: (v) => setFilter(key, v) }
          : undefined}
        generate={GENERATABLE.has(key)
          ? {
              templates: templatesForCol(key),
              busy: columnGenerating === key,
              onGenerate: (tid?: number) => setPendingGen({ run: (mode) => handleColumnGenerate(key, tid, mode) }),
            }
          : undefined}
        draggable
        onDragStart={(e) => { setDragKey(key); e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', key); } catch { /* IE */ } }}
        onDragOver={(e) => { e.preventDefault(); if (dragKey && dragKey !== key) setDragOverKey(key); }}
        onDragLeave={() => setDragOverKey((cur) => (cur === key ? null : cur))}
        onDrop={(e) => { e.preventDefault(); if (dragKey) moveColumn(dragKey, key); setDragKey(null); setDragOverKey(null); }}
        onDragEnd={() => { setDragKey(null); setDragOverKey(null); }}
        isDropTarget={dragOverKey === key && dragKey !== key}
        onResizeStart={startResize(key)}
      />
    );
  };

  // Body cell for a column (bespoke per column key).
  const renderCell = (key: string, row: SeoRow) => {
    switch (key) {
      case 'type':
        return (
          <TableCell key={key}>
            <span className={`${CELL_PILL_NEUTRAL} capitalize`}>{row.type}</span>
          </TableCell>
        );
      case 'title':
        return (
          <TableCell key={key}>
            <div className="flex items-center gap-1">
              <button
                type="button"
                onClick={() => toggleExpanded(row.id)}
                title={expandedRows.has(row.id) ? 'Hide headings' : 'Show heading structure'}
                aria-label={expandedRows.has(row.id) ? 'Hide headings' : 'Show heading structure'}
                aria-expanded={expandedRows.has(row.id)}
                className="shrink-0 text-muted-foreground/60 hover:text-foreground"
              >
                {expandedRows.has(row.id)
                  ? <ChevronDown className="h-3.5 w-3.5" />
                  : <ChevronRight className="h-3.5 w-3.5" />}
              </button>
              <div className="min-w-0 flex-1">
                <EditableCell
                  value={row.title}
                  placeholder="Untitled"
                  emphasis
                  onSave={(v) => saveCell(row.id, 'title', v)}
                  onGenerate={() => handleGenerate(row.id, 'title')}
                  generating={genKey === `${row.id}:title`}
                  suggestion={staged[`${row.id}:title`] ?? null}
                  onAccept={() => acceptStaged(row.id, 'title')}
                  onReject={() => rejectStaged(row.id, 'title')}
                />
              </div>
              {/* Row-hover-revealed quick actions (rest = clean title; the TableRow
                  carries `group`). Eye = the existing preview modal (authenticated
                  remote fetch, shows served dynamic rules, has open-in-new-tab). */}
              {row.permalink && (
                <button
                  type="button"
                  onClick={() => setPreviewRow(row)}
                  title="Preview page"
                  className="shrink-0 text-muted-foreground/50 opacity-0 transition-opacity group-hover:opacity-100 hover:text-primary"
                >
                  <Eye className="w-3.5 h-3.5" />
                </button>
              )}
              {/* Connected sites: the pen opens the full-page DYNAMIC editor
                  (page-level editing, section-level rules). Local rows keep
                  the plain WP-editor link — no rule engine on the hub itself. */}
              {isLocal ? (row.editUrl && (
                <a
                  href={row.editUrl}
                  target="_blank"
                  rel="noopener noreferrer"
                  title="Edit on site (WP editor)"
                  className="shrink-0 text-muted-foreground/50 opacity-0 transition-opacity group-hover:opacity-100 hover:text-foreground"
                >
                  <SquarePen className="w-3.5 h-3.5" />
                </a>
              )) : (
                <button
                  type="button"
                  onClick={() => setPageEditRow(row)}
                  title="Edit this page (dynamic rules — nothing is rewritten on the site)"
                  className="shrink-0 text-muted-foreground/50 opacity-0 transition-opacity group-hover:opacity-100 hover:text-primary"
                >
                  <SquarePen className="w-3.5 h-3.5" />
                </button>
              )}
            </div>
          </TableCell>
        );
      case 'status':
        return (
          <TableCell key={key}>
            <Select value={row.status} onValueChange={(v) => saveCell(row.id, 'status', v)}>
              <SelectTrigger className="h-full w-full border-0 rounded-none bg-transparent px-0 text-xs shadow-none focus:ring-0 focus:ring-offset-0">
                <Pill variant={statusPillVariant(row.status)} className="capitalize">{row.status}</Pill>
              </SelectTrigger>
              <SelectContent>
                {(options?.statuses ?? ['publish', 'draft', 'pending', 'private', 'future']).map((s) => (
                  <SelectItem key={s} value={s} className="text-xs capitalize">{s}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </TableCell>
        );
      case 'schema':
        return (
          <TableCell key={key}>
            <SchemaCell
              postId={row.id}
              types={schemaOverrides[row.id] ?? row.schemaTypes ?? []}
              onChange={(next) => setSchemaOverrides((o) => ({ ...o, [row.id]: next }))}
              onPersist={isLocal ? undefined : (next) => { void remote.setSchema(row.id, next); }}
            />
          </TableCell>
        );
      case 'author':
        return <TableCell key={key} className="text-xs text-muted-foreground">{row.author}</TableCell>;
      case 'slug': {
        const ckey = `${row.id}:slug`;
        return (
          <TableCell key={key}>
            <EditableCell
              value={row.slug}
              placeholder="slug"
              onSave={(v) => saveSlug(row.id, v)}
              onGenerate={() => handleGenerate(row.id, 'slug')}
              generating={genKey === ckey}
              suggestion={staged[ckey] ?? null}
              onAccept={() => acceptStaged(row.id, 'slug')}
              onReject={() => rejectStaged(row.id, 'slug')}
            />
          </TableCell>
        );
      }
      case 'supportingKeyword':
        return (
          <TableCell key={key}>
            <EditableCell value={row.supportingKeyword} placeholder="Supporting KW" onSave={(v) => saveCell(row.id, 'supportingKeyword', v)} />
          </TableCell>
        );
      case 'featuredImage':
        return (
          <TableCell key={key} className="text-center">
            <button
              type="button"
              onClick={() => openFeaturedImage(row)}
              title={row.featuredImage ? 'Change featured image' : 'Set featured image'}
              className="inline-flex items-center justify-center align-middle transition-opacity hover:opacity-80"
            >
              {row.featuredImage
                ? <img src={row.featuredImage} alt="" loading="lazy" className="h-8 w-8 rounded object-cover" />
                : <span className="flex h-8 w-8 items-center justify-center rounded border border-dashed border-border text-muted-foreground"><ImageIcon className="h-4 w-4" /></span>}
            </button>
          </TableCell>
        );
      case 'date':
        return (
          <TableCell key={key} className="whitespace-nowrap text-xs text-muted-foreground">
            {row.date ? new Date(row.date.replace(' ', 'T')).toLocaleDateString() : '—'}
          </TableCell>
        );
      case 'traffic':
      case 'impressions':
      case 'ctr':
      case 'position':
      case 'gscKeywords': {
        // Google Search Console block — filled by the "GSC stats" pull.
        const g = gscPages[normGscUrl(row.permalink ?? '')];
        const hint = gscRange
          ? `Search Console, ${gscRange.start} → ${gscRange.end}`
          : 'Click "GSC stats" to pull Search Console data';
        if (!g) {
          return <TableCell key={key} className="text-center text-xs text-muted-foreground" title={hint}>—</TableCell>;
        }
        if (key === 'gscKeywords') {
          const kw = g.keywords.join(', ');
          return (
            <TableCell key={key} className="text-xs text-muted-foreground truncate" title={kw ? `Top queries (${hint}): ${kw}` : hint}>
              {kw || '—'}
            </TableCell>
          );
        }
        const value =
          key === 'traffic' ? g.clicks.toLocaleString()
          : key === 'impressions' ? g.impressions.toLocaleString()
          : key === 'ctr' ? `${g.ctr}%`
          : g.position.toFixed(1);
        return (
          <TableCell key={key} className="text-center text-xs tabular-nums" title={hint}>{value}</TableCell>
        );
      }
      case 'prtPosition': {
        // ProRankTracker rank of the row's PRIMARY KEYWORD (filled by the "PRT ranks" pull).
        const kw = row.primaryKeyword?.trim();
        if (!kw) {
          return <TableCell key={key} className="text-center text-xs text-muted-foreground/60" title="Set a Primary Keyword to track its ProRankTracker rank">—</TableCell>;
        }
        const p = prtRanks[normKw(kw)];
        if (!p) {
          return <TableCell key={key} className="text-center text-xs text-muted-foreground" title={`"${kw}" — click "PRT ranks" to pull ProRankTracker (or this keyword isn't tracked there)`}>—</TableCell>;
        }
        if (p.rank <= 0) {
          return <TableCell key={key} className="text-center text-xs text-muted-foreground" title={`"${kw}" — tracked but not ranking (ProRankTracker${p.engine ? `, ${p.engine}` : ''})`}>—</TableCell>;
        }
        return (
          <TableCell key={key} className="text-center text-xs font-medium tabular-nums" title={`"${kw}" ranks #${p.rank} (ProRankTracker${p.engine ? `, ${p.engine}` : ''})${p.matchedUrl ? ` → ${p.matchedUrl}` : ''}`}>{p.rank}</TableCell>
        );
      }
      case 'internalLinks':
      case 'externalLinks':
      case 'brokenLinks': {
        const scanning = scanningRows.has(row.id);
        const scanned = !!row.linksScannedAt;
        const value = key === 'internalLinks' ? row.internalLinks : key === 'externalLinks' ? row.externalLinks : row.brokenLinks;
        const isBroken = key === 'brokenLinks';
        const popupKind: LinkKind = key === 'internalLinks' ? 'internal' : key === 'externalLinks' ? 'external' : 'broken';
        const linkType: 'post' | 'page' = row.type === 'page' ? 'page' : 'post';
        return (
          <TableCell key={key} className="text-center text-xs">
            {scanning ? (
              <Loader2 className="inline-block w-3.5 h-3.5 animate-spin text-primary" />
            ) : scanned ? (
              <span className="inline-flex items-center justify-center gap-1">
                <button
                  type="button"
                  onClick={() => setLinksPopup({ id: row.id, kind: popupKind, title: row.title || `#${row.id}`, type: linkType })}
                  title={`Click to view & edit the ${popupKind === 'broken' ? 'dead' : popupKind} links`}
                  className={`cursor-pointer underline decoration-dotted underline-offset-2 ${isBroken && (value ?? 0) > 0 ? 'font-semibold text-destructive hover:text-destructive/80' : 'text-primary hover:text-primary/80'}`}
                >
                  {value ?? 0}
                </button>
                <button
                  type="button"
                  onClick={() => handleScan(row.id)}
                  title="Re-scan links (internal, external & broken)"
                  className="shrink-0 text-muted-foreground/40 transition-colors hover:text-primary"
                >
                  <RefreshCw className="w-3 h-3" />
                </button>
              </span>
            ) : (
              <button type="button" onClick={() => handleScan(row.id)} className="text-[11px] text-primary hover:underline" title="Scan all links (internal, external & broken)">Scan</button>
            )}
          </TableCell>
        );
      }
      case 'actions':
        return (
          <TableCell key={key} className="text-center">
            <div className="inline-flex items-center gap-2">
              <button
                type="button"
                onClick={() => setPreviewRow(row)}
                disabled={!row.permalink}
                className="text-muted-foreground hover:text-primary disabled:opacity-40"
                title="Preview page"
              >
                <Eye className="w-3.5 h-3.5" />
              </button>
              <button
                type="button"
                onClick={() => setOptimizeRow(row)}
                className="text-muted-foreground hover:text-primary"
                title="Optimize content with AI"
              >
                <Sparkles className="w-3.5 h-3.5" />
              </button>
              {row.permalink && (
                <a href={row.permalink} target="_blank" rel="noopener noreferrer" className="inline-flex text-muted-foreground! hover:text-foreground!" title="View page">
                  <ExternalLink className="w-3.5 h-3.5" />
                </a>
              )}
            </div>
          </TableCell>
        );
      default: {
        // Editable AI meta field (metaTitle / metaDescription / primaryKeyword / metaKeywords).
        const field = TEXT_FIELD_BY_KEY[key];
        if (!field) return null;
        const ckey = `${row.id}:${key}`;
        const canGen = GEN_FIELDS.some((f) => f.key === key);
        return (
          <TableCell key={key}>
            <EditableCell
              value={String(row[key as keyof SeoRow] ?? '')}
              placeholder={field.label}
              onSave={(v) => saveCell(row.id, key, v)}
              onGenerate={canGen ? () => handleGenerate(row.id, key) : undefined}
              generating={genKey === ckey}
              suggestion={staged[ckey] ?? null}
              onAccept={() => acceptStaged(row.id, key)}
              onReject={() => rejectStaged(row.id, key)}
            />
          </TableCell>
        );
      }
    }
  };

  return (
    <div className="module-container animate-fade-in">
      {/* Title + description at the top. */}
      <ModuleHeader
        title="SEO"
        description="Optimize the SEO meta of your site's posts and pages — inline, across Yoast / Rank Math / SEOPress."
      />

      {/* Site tabs — the local install + every connected site (below the title).
          Each tab is its own SEO workbench. */}
      <div className="flex gap-1 mb-4 border-b border-border overflow-x-auto">
        <button
          type="button"
          onClick={() => setSiteId('local')}
          className={`inline-flex items-center gap-1.5 px-3 py-1.5 text-sm -mb-px border-b-2 whitespace-nowrap ${isLocal ? 'border-primary text-primary font-medium' : 'border-transparent text-muted-foreground hover:text-foreground'}`}
        >
          <Globe className="w-3.5 h-3.5" /> This Site
        </button>
        {sites.map((s) => (
          <button
            key={s.id}
            type="button"
            onClick={() => setSiteId(Number(s.id))}
            title={s.url}
            className={`inline-flex items-center gap-1.5 px-3 py-1.5 text-sm -mb-px border-b-2 max-w-[220px] ${siteId === Number(s.id) ? 'border-primary text-primary font-medium' : 'border-transparent text-muted-foreground hover:text-foreground'}`}
          >
            <Globe className="w-3.5 h-3.5 shrink-0" />
            <span className="truncate">{s.name || s.url || `Site #${s.id}`}</span>
          </button>
        ))}
      </div>

      {/* Section nav (left) + section content, side by side. The nav lives in a
          card surface so it reads as a panel instead of floating. The content
          toolbar (Views/Columns/Post/Page/Model) lives INSIDE the content column
          (below) so switching sections never shifts the nav's position. */}
      <div className="flex gap-6 items-start">
        <nav className="flex w-44 shrink-0 flex-col gap-1 rounded-lg border border-border bg-card p-2">
          {([['content', 'Content'], ['air', 'AI Readiness'], ['site', 'Site'], ['business', 'Business']] as const).map(([id, label]) => (
            <button
              key={id}
              type="button"
              onClick={() => setTab(id)}
              className={`rounded-md px-3 py-2 text-left text-sm transition-colors ${tab === id ? 'bg-accent font-medium text-accent-foreground' : 'text-muted-foreground hover:bg-muted hover:text-foreground'}`}
            >
              {label}
            </button>
          ))}
        </nav>

        {/* Section content */}
        <div className="min-w-0 flex-1">
      {tab === 'air' ? (
        // /llm-info/ (AI overview page) renders FIRST and independently of the AI-Readiness
        // status load, so it stays usable even when llms.txt/status fails (e.g. older connector).
        isLocal ? (
          <div className="space-y-8">
            <LlmInfoSection />
            <AIReadinessPanel />
          </div>
        ) : typeof siteId === 'number' ? (
          <div className="space-y-8">
            <LlmInfoSection siteId={siteId} />
            <RemoteAIReadinessPanel siteId={siteId} siteName={activeSite?.name || activeSite?.url || 'this site'} />
          </div>
        ) : (
          <RemoteSitePlaceholder siteName={activeSite?.name || activeSite?.url || 'this site'} siteUrl={activeSite?.url} section={SECTION_LABEL[tab]} />
        )
      ) : tab === 'site' ? (
        isLocal
          ? <SiteSettingsPanel />
          : typeof siteId === 'number'
            ? <RemoteSiteSettingsPanel siteId={siteId} siteName={activeSite?.name || activeSite?.url || 'this site'} />
            : <RemoteSitePlaceholder siteName={activeSite?.name || activeSite?.url || 'this site'} siteUrl={activeSite?.url} section={SECTION_LABEL[tab]} />
      ) : tab === 'business' ? (
        isLocal ? <BusinessPanel /> : <RemoteSitePlaceholder siteName={activeSite?.name || activeSite?.url || 'this site'} siteUrl={activeSite?.url} section={SECTION_LABEL[tab]} />
      ) : (
      <>
      {/* Content toolbar: Views (left) · Post / Page / Model + Columns (right). */}
      <div className="flex items-center justify-between gap-4 mb-4">
        <div className="flex items-center gap-3">
          <ViewsToolbar
            show="views"
            columns={TOGGLE_COLUMNS}
            visible={cols}
            onToggleColumn={toggleCol}
            views={views}
            appliedViewId={appliedViewId}
            onApplyView={applyView}
            onResetView={resetView}
            onSaveView={handleSaveView}
            onDeleteView={handleDeleteView}
            onSetDefaultView={handleSetDefaultView}
          />
          <div className="flex items-center gap-2 text-xs text-muted-foreground">
            {activeCount > 0 ? (
              <>
                <span>{sortedData.length} of {rows.length} shown</span>
                <button type="button" onClick={clearAll} className="inline-flex items-center gap-1 text-primary hover:underline">
                  <X className="w-3.5 h-3.5" /> Clear filters ({activeCount})
                </button>
              </>
            ) : (
              <span>{rows.length} item{rows.length === 1 ? '' : 's'}</span>
            )}
          </div>
        </div>
        <div className="flex items-center gap-2">
          {/* Toolbar buttons rest GRAY, hover BLUE (PO 2026-07-08) — default
              variant; 'active' (blue at rest) is reserved for pressed states. */}
          <PillButton
            icon={scanningAll ? <Loader2 className="animate-spin" /> : <Link2 />}
            onClick={handleScanAll}
            disabled={busy || scanningAll || sortedData.length === 0}
          >
            {scanningAll ? 'Scanning…' : 'Scan links'}
          </PillButton>
          {/* Pulls clicks / impressions / CTR / position / top queries (last 28 days). */}
          <PillButton
            icon={gscPulling ? <Loader2 className="animate-spin" /> : <TrendingUp />}
            onClick={handlePullGsc}
            disabled={busy || gscPulling}
          >
            {gscPulling ? 'Pulling…' : 'GSC stats'}
          </PillButton>
          {/* Pulls ProRankTracker rank of each row's Primary Keyword → "Pos (PRT)" column. */}
          <PillButton
            icon={prtPulling ? <Loader2 className="animate-spin" /> : <Target />}
            onClick={handlePullPrt}
            disabled={busy || prtPulling}
          >
            {prtPulling ? 'Pulling…' : 'PRT ranks'}
          </PillButton>
          <PillButton icon={<Plus />} onClick={() => handleCreate('post')} disabled={busy}>
            Post
          </PillButton>
          <PillButton icon={<Plus />} onClick={() => handleCreate('page')} disabled={busy}>
            Page
          </PillButton>
          <Select
            value={genModelId}
            onValueChange={(v) => setGenModel(v === '__default__' ? '' : v)}
          >
            <SelectTrigger
              className="h-9 w-[190px] gap-1 rounded-full border border-border bg-card px-4 text-xs shadow-sm hover:bg-muted/50"
              title="Model used for AI generation"
            >
              <SelectValue
                placeholder={<span className="text-muted-foreground">Select model</span>}
              />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="__default__" className="text-xs">Default model</SelectItem>
              {textModels.map((m) => (
                <SelectItem key={m.id} value={m.id} className="text-xs">
                  {m.name} <span className="text-muted-foreground">({m.provider})</span>
                </SelectItem>
              ))}
              {textModels.length === 0 && (
                <div className="px-2 py-1.5 text-[11px] text-muted-foreground">
                  No text models yet — add one in the Models tab.
                </div>
              )}
            </SelectContent>
          </Select>
          <ViewsToolbar
            show="columns"
            columns={TOGGLE_COLUMNS}
            visible={cols}
            onToggleColumn={toggleCol}
            views={views}
            appliedViewId={appliedViewId}
            onApplyView={applyView}
            onResetView={resetView}
            onSaveView={handleSaveView}
            onDeleteView={handleDeleteView}
            onSetDefaultView={handleSetDefaultView}
            onResetLayout={resetColumnLayout}
          />
        </div>
      </div>

      {/* Bulk actions — a compact FLOATING bar (fixed, so the table never reflows /
          "pops down" on select). The "Generate all" split button generates the
          chosen columns across the SELECTED rows. */}
      {selected.size > 0 && (
        <div className="fixed bottom-6 left-1/2 z-50 flex -translate-x-1/2 items-center gap-3 rounded-full border border-border bg-card/95 px-4 py-2 shadow-lg ring-1 ring-black/5 backdrop-blur">
          <span className="inline-flex items-center rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium whitespace-nowrap">
            {selected.size} selected
          </span>
          {/* Split button: left = generate all columns; right ▾ = pick columns. */}
          <div className="inline-flex items-stretch overflow-hidden rounded-md shadow-sm">
            <Button
              size="sm"
              className="h-8 gap-1.5 rounded-none rounded-l-md px-3 text-xs font-medium"
              disabled={busy}
              onClick={() => setPendingGen({ run: (mode) => runBulk(GEN_FIELDS.map((f) => f.key), mode) })}
            >
              <Sparkles className="w-3.5 h-3.5" /> Generate all
            </Button>
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button size="sm" className="h-8 rounded-none rounded-r-md border-l border-primary-foreground/25 px-2" disabled={busy} title="Choose columns">
                  <ChevronDown className="w-3.5 h-3.5" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="w-56">
                <DropdownMenuLabel className="text-xs">Columns to generate</DropdownMenuLabel>
                <DropdownMenuSeparator />
                {GEN_FIELDS.map((f) => (
                  <DropdownMenuCheckboxItem
                    key={f.key}
                    checked={genCols.has(f.key)}
                    onCheckedChange={() => toggleGenCol(f.key)}
                    onSelect={(e) => e.preventDefault()}
                    className="text-xs"
                  >
                    {f.label}
                  </DropdownMenuCheckboxItem>
                ))}
                <DropdownMenuSeparator />
                <div className="p-1.5">
                  <Button
                    size="sm"
                    className="h-8 w-full justify-center gap-1.5 px-3 text-xs"
                    disabled={busy || genCols.size === 0}
                    onClick={() => setPendingGen({ run: (mode) => runBulk(GEN_FIELDS.filter((f) => genCols.has(f.key)).map((f) => f.key), mode) })}
                  >
                    <Sparkles className="w-3.5 h-3.5" /> Generate selected ({genCols.size})
                  </Button>
                </div>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
          {/* Bulk actions — change status, duplicate, delete (all remote-aware). */}
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="outline" size="sm" className="h-8 gap-1.5 px-3 text-xs" disabled={busy}>
                  Bulk actions <ChevronDown className="w-3.5 h-3.5 opacity-60" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="w-48">
                <DropdownMenuSub>
                  <DropdownMenuSubTrigger className="text-xs">
                    <CircleDot className="mr-2 h-3.5 w-3.5" /> Change status
                  </DropdownMenuSubTrigger>
                  <DropdownMenuSubContent>
                    {(options?.statuses ?? ['publish', 'draft', 'pending', 'private', 'future']).map((s) => (
                      <DropdownMenuItem key={s} className="text-xs capitalize" onClick={() => handleBulkStatus(s)}>
                        {s}
                      </DropdownMenuItem>
                    ))}
                  </DropdownMenuSubContent>
                </DropdownMenuSub>
                <DropdownMenuItem className="text-xs" onClick={handleBulkDuplicate}>
                  <Copy className="mr-2 h-3.5 w-3.5" /> Duplicate
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem className="text-xs text-destructive focus:text-destructive" onClick={handleDelete}>
                  <Trash2 className="mr-2 h-3.5 w-3.5" /> Delete
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          {progress && (
            <span className="text-xs text-muted-foreground inline-flex items-center gap-1.5 whitespace-nowrap" aria-live="polite">
              <Loader2 className="w-3.5 h-3.5 animate-spin" /> {progress.done}/{progress.total}
            </span>
          )}
          <span className="h-5 w-px bg-border" />
          <Button variant="ghost" size="sm" className="h-8 px-2.5 text-xs" disabled={busy} onClick={() => setSelected(new Set())}>
            Clear
          </Button>
        </div>
      )}

      {/* Pending AI suggestions — accept/discard everything at once. */}
      {pendingCount > 0 && (
        <div className="flex items-center flex-wrap gap-2 mb-3 rounded-lg border border-primary/20 bg-accent/60 px-4 py-2">
          <span className="text-sm font-medium text-foreground inline-flex items-center gap-1.5">
            {busy && genKey ? <Loader2 className="w-4 h-4 animate-spin" /> : <Sparkles className="w-4 h-4" />}
            {pendingCount} AI suggestion{pendingCount > 1 ? 's' : ''} pending
          </span>
          <span className="flex-1" />
          <Button size="sm" className="h-7 gap-1.5 bg-green-600 hover:bg-green-700" onClick={acceptAllStaged}>
            <Check className="w-3.5 h-3.5" /> Accept all &amp; save
          </Button>
          <Button variant="ghost" size="sm" className="h-7 gap-1.5" onClick={discardAllStaged}>
            <X className="w-3.5 h-3.5" /> Discard all
          </Button>
        </div>
      )}

      {isLoading ? (
        <div className="flex items-center justify-center py-20">
          <Loader2 className="w-6 h-6 animate-spin text-primary" />
        </div>
      ) : sortedData.length === 0 ? (
        <div className="border border-dashed border-border rounded-xl p-12 text-center text-sm text-muted-foreground">
          No content yet — create a post or page to get started.
        </div>
      ) : (
        <div className="rounded-md border border-border shadow-sm overflow-auto max-h-[calc(100vh-300px)] bg-card">
          {/* Spreadsheet-style grid: gridlines on every cell, a sticky header
              row, and compact single-line cells. Long values truncate with an
              ellipsis — click a cell to edit (and see) the full value. */}
          <Table
            style={{ width: tableWidth, minWidth: '100%' }}
            className={`table-fixed ${SEO_TABLE_GRID}`}>

            <colgroup>
              <col style={{ width: SELECT_COL_WIDTH }} />
              {orderedCols.map((key) => <col key={key} style={{ width: colWidth(key) }} />)}
            </colgroup>

            <TableHeader>
              <TableRow>
                <TableHead className="px-2">
                  <Checkbox
                    checked={allSelected}
                    onCheckedChange={toggleAll}
                    aria-label="Select all"
                    {...(someSelected ? { 'data-state': 'indeterminate' as const } : {})}
                  />
                </TableHead>
                {orderedCols.map((key) => renderHeader(key))}
              </TableRow>
            </TableHeader>
            <TableBody>
              {sortedData.map((row, idx) => (
                <Fragment key={row.id}>
                  <TableRow
                    onMouseEnter={() => setHoveredId(row.id)}
                    onMouseLeave={() => setHoveredId((cur) => (cur === row.id ? null : cur))}
                    className={`group ${selected.has(row.id) ? 'bg-accent/60' : 'hover:bg-muted/60'}`}
                  >
                    <TableCell className="px-2 text-center">
                      {/* Airtable-style: row number by default; checkbox on hover or when
                          selected. Hover is tracked in JS (see hoveredId) because Tailwind v4
                          gates `group-hover:` behind `@media (hover: hover)`. */}
                      {selected.has(row.id) || hoveredId === row.id ? (
                        <span className="inline-flex items-center justify-center">
                          <Checkbox
                            checked={selected.has(row.id)}
                            onCheckedChange={() => toggleOne(row.id)}
                            aria-label={`Select ${row.title}`}
                          />
                        </span>
                      ) : (
                        <span className="text-[11px] tabular-nums text-muted-foreground">{idx + 1}</span>
                      )}
                    </TableCell>
                    {orderedCols.map((key) => renderCell(key, row))}
                  </TableRow>
                  {expandedRows.has(row.id) && (
                    <HeadingRows
                      postId={row.id}
                      type={row.type === 'page' ? 'page' : 'post'}
                      siteId={siteId}
                      model={genModelId || undefined}
                      provider={genProvider}
                      orderedCols={orderedCols}
                    />
                  )}
                </Fragment>
              ))}
            </TableBody>
          </Table>
        </div>
      )}
      {/* Generate mode prompt — asked before any bulk / column generate so existing
          content is never silently overwritten. */}
      {pendingGen && (
        <div
          className="fixed inset-0 z-[60] flex items-center justify-center bg-black/40"
          onClick={() => setPendingGen(null)}
        >
          <div className="w-[340px] rounded-xl border border-border bg-card p-5 shadow-xl" onClick={(e) => e.stopPropagation()}>
            <h3 className="text-sm font-semibold">Generate content</h3>
            <p className="mt-1 text-xs text-muted-foreground">
              Some of these cells may already have content. What should the generator do?
            </p>
            <div className="mt-4 flex flex-col gap-2">
              <Button size="sm" className="justify-center gap-1.5" onClick={() => { const r = pendingGen.run; setPendingGen(null); r('empty'); }}>
                <Sparkles className="w-3.5 h-3.5" /> Only where empty
              </Button>
              <Button size="sm" variant="outline" className="justify-center" onClick={() => { const r = pendingGen.run; setPendingGen(null); r('overwrite'); }}>
                Overwrite existing
              </Button>
              <Button size="sm" variant="ghost" className="justify-center" onClick={() => setPendingGen(null)}>Cancel</Button>
            </div>
          </div>
        </div>
      )}
      {optimizeRow && (
        <OptimizeModal
          postId={optimizeRow.id}
          title={optimizeRow.title}
          keyword={optimizeRow.primaryKeyword}
          open={!!optimizeRow}
          onClose={() => setOptimizeRow(null)}
        />
      )}

      {/* Link inspector — opened from an Internal / External / Dead count cell. */}
      {linksPopup && (
        <LinksPopup
          open={!!linksPopup}
          onClose={() => setLinksPopup(null)}
          postId={linksPopup.id}
          kind={linksPopup.kind}
          title={linksPopup.title}
          type={linksPopup.type}
          isLocal={isLocal}
          siteId={typeof siteId === 'number' ? siteId : null}
        />
      )}

      {/* Redirect offer — appears once after a real slug change on a connected
          published page; writes nothing unless confirmed. */}
      {redirectOffer && typeof siteId === 'number' && (
        <RedirectPopup
          open
          onClose={() => setRedirectOffer(null)}
          siteId={siteId}
          fromUrl={redirectOffer.fromUrl}
          toUrl={redirectOffer.toUrl}
        />
      )}

      {/* Full-page editor (dynamic rules) — the repurposed row pen. Keyed per
          post so switching pages never bleeds editor state. */}
      {pageEditRow && typeof siteId === 'number' && (
        <SectionModal
          key={`page-${pageEditRow.id}`}
          siteId={siteId}
          postId={pageEditRow.id}
          type={pageEditRow.type === 'page' ? 'page' : 'post'}
          readOnly={false}
          mode="page"
          page={{
            title: pageEditRow.title || 'Untitled',
            editUrl: pageEditRow.editUrl || undefined,
            date: pageEditRow.date || undefined,
            permalink: pageEditRow.permalink || undefined,
            // The table's own inline preview window (renders above the editor).
            onPreview: pageEditRow.permalink ? () => setPreviewRow(pageEditRow) : undefined,
            // The row's keyword fields seed the editor's keyword drawer.
            primaryKeyword: pageEditRow.primaryKeyword || undefined,
            supportingKeyword: pageEditRow.supportingKeyword || undefined,
            // Drafts have no public URL — Open needs to know (preview=true).
            status: pageEditRow.status || undefined,
          }}
          // The drawer's page picker chooses which page's GSC data to read.
          sitePages={rows
            .filter((r) => (r.permalink ?? '') !== '')
            .map((r) => ({ id: Number(r.id), title: r.title || r.slug || String(r.id), permalink: r.permalink ?? '' }))}
          onClose={() => setPageEditRow(null)}
          onSaved={() => {}}
        />
      )}

      {/* Inline page preview — popup (not a new tab). PORTALED to body:
          the page editor is body-portaled too, and a preview trapped in
          the app tree's stacking context painted BEHIND it (owner find
          2026-07-14) — at body level its z-50 wins over the editor's z-40. */}
      {previewRow && previewRow.permalink && createPortal(
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-6"
          onClick={() => setPreviewRow(null)}
        >
          <div
            className="flex h-[85vh] w-full max-w-5xl flex-col overflow-hidden rounded-lg border border-border bg-card shadow-xl"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="flex items-center justify-between gap-2 border-b border-border px-4 py-2">
              <div className="min-w-0">
                <div className="truncate text-sm font-medium text-foreground">{previewRow.title || 'Preview'}</div>
                <a href={previewRow.permalink} target="_blank" rel="noopener noreferrer" className="truncate text-xs text-muted-foreground hover:text-primary">{previewRow.permalink}</a>
              </div>
              <div className="flex items-center gap-1 shrink-0">
                <a href={previewRow.permalink} target="_blank" rel="noopener noreferrer" className="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground" title="Open in new tab">
                  <ExternalLink className="h-4 w-4" />
                </a>
                <button type="button" onClick={() => setPreviewRow(null)} className="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground" title="Close">
                  <X className="h-4 w-4" />
                </button>
              </div>
            </div>
            {/* Local: direct same-origin iframe. Connected site: prefer the AUTHENTICATED
                HTML fetched server-side via the connector (srcDoc) so the WP admin bar
                shows. If that fetch fails (e.g. a draft on a connector without front-end
                auth, or a transient error), FALL BACK to a direct iframe so the preview is
                still shown rather than a dead-end. */}
            {isLocal ? (
              <iframe src={previewRow.permalink} title="Page preview" className="h-full w-full flex-1 bg-white" />
            ) : previewLoading ? (
              <div className="flex h-full w-full flex-1 items-center justify-center bg-white">
                <Loader2 className="h-6 w-6 animate-spin text-primary" />
              </div>
            ) : previewHtml ? (
              <iframe srcDoc={previewHtml} title="Page preview" className="h-full w-full flex-1 bg-white" />
            ) : (
              <iframe src={previewRow.permalink} title="Page preview" className="h-full w-full flex-1 bg-white" />
            )}
          </div>
        </div>,
        document.body,
      )}
      </>
      )}
        </div>
      </div>
    </div>
  );
}
