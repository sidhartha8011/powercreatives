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

import { trpc, getConfig } from '@/lib/trpc';
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
import { StagedSuggestion } from './StagedSuggestion';
import { useColumnFilters } from '@/hooks/useColumnFilters';
import { useViews, type SeoView } from './hooks/useViews';
import { useColumnLayout } from '@/hooks/useColumnLayout';
import { ColumnHead } from '@/components/ui/column-head';
import { CELL_PILL_NEUTRAL } from '@/components/ui/table-cell-recipes';
import { ViewsToolbar } from './ViewsToolbar';
import { buildFilterDefs, type FilterDef, type FilterOption } from './seoFilters';
import { AiReadinessStudio } from './AiReadinessStudio';
import { SiteSettingsPanel } from './SiteSettingsPanel';
import { RemoteSiteSettingsPanel } from './RemoteSiteSettingsPanel';
import { BusinessPanel } from './BusinessPanel';
import { RemoteBusinessCard } from './RemoteBusinessCard';
import { SchemaCell } from './SchemaCell';
import { OptimizeModal } from './OptimizeModal';
import { LinksPopup, type LinkKind } from './LinksPopup';
import { RedirectPopup } from './RedirectPopup';
import { HeadingRows } from './HeadingsPanel';
import { SectionModal } from './SectionModal';
import { SiteStatusDot } from './SiteStatusDot';
import { useSiteHealth } from './hooks/useSiteHealth';
import { SEO_TABLE_GRID } from './seo-table';
import { Pill, type PillVariant } from '@/components/ui/pill';
import { SEO_TEXT_FIELDS, statusPillVariant, type SeoRow } from './types';

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
  onOpen,
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
  /** OPT-IN (title cell only, gap cad42df): single click OPENS (the page
   *  editor), double click enters the inline text edit. Absent = today's
   *  click-to-edit, byte-identical — the sensitive columns never change. */
  onOpen?: () => void;
}) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(value);
  // Single-vs-double click discriminator (only armed when onOpen exists):
  // a single click waits 220ms for a possible second click before opening.
  const openTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  useEffect(() => () => { if (openTimer.current) clearTimeout(openTimer.current); }, []);

  const commit = () => {
    setEditing(false);
    if (draft !== value) onSave(draft);
  };
  const onKey = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') { e.preventDefault(); commit(); }
    if (e.key === 'Escape') { setDraft(value); setEditing(false); }
  };

  // Staged AI suggestion → show new value with accept / reject / re-generate
  // (the shared StagedSuggestion — same component as the Headings panel).
  if (suggestion != null) {
    return (
      <StagedSuggestion
        suggestion={suggestion}
        busy={generating}
        onAccept={() => onAccept?.()}
        onReject={() => onReject?.()}
        onRegenerate={onGenerate}
      />
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
        onClick={() => {
          if (!onOpen) { setDraft(value); setEditing(true); return; }
          if (openTimer.current) clearTimeout(openTimer.current);
          openTimer.current = setTimeout(() => { openTimer.current = null; onOpen(); }, 220);
        }}
        onDoubleClick={() => {
          if (!onOpen) return; // default cells already edit on the first click
          if (openTimer.current) { clearTimeout(openTimer.current); openTimer.current = null; }
          setDraft(value); setEditing(true);
        }}
        className={`flex-1 min-w-0 text-left truncate text-xs leading-snug hover:underline decoration-dotted ${emphasis ? 'font-medium text-foreground' : ''}`}
        title={onOpen ? `${value || placeholder} — click to open the editor, double-click to edit the title` : (value || placeholder)}
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

// statusPillVariant lives in ./types — ONE source for the table cell and
// the editor header dropdown (owner order 2026-07-15).

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

/**
 * ONE definition of "this cell is empty" for every bulk/column generate path.
 * "Empty" = nothing STORED for the cell. A Meta Title/Description shown from the
 * rendered page / SEO plugin (row.metaFromHead) is a display fallback, not content —
 * the user sees a blank-ish cell and expects it to be generated. Counting it as
 * filled is exactly "it skips a field even if it should have generated in it"
 * (bugfix card 7). Hoisted so the column ✦ run and the floating-bar run cannot
 * disagree (they did: the column run ignored metaFromHead).
 */
/**
 * The Image cell's thumbnail — with an honest fallback when the picture cannot load.
 *
 * Owner: "the images are showing like this" — every row in the SEO table carried the
 * browser's torn broken-image glyph. The URLs are RIGHT; the client site refuses to
 * serve them to anyone but itself. Proven live on massagegoteborg.nu (2026-08-17):
 * its own thumbnail 403s a Cloudflare challenge for a plain fetch, a full Chrome
 * user-agent, a same-site Referer, AND a complete browser header set — while
 * /wp-json answers 200. So neither the browser nor a hub-side proxy can render it;
 * the only honest options are a broken glyph or a clean placeholder that says why.
 *
 * `referrerPolicy="no-referrer"` is sent because the OTHER common blocker — classic
 * hotlink protection — allows an empty Referer while rejecting a cross-site one, so
 * this genuinely rescues images on those sites. It cannot help a bot-challenge wall.
 */
/**
 * Hub-side thumbnail proxy URL for a CONNECTED post's image (GET /seo/sites/{id}/thumb):
 * the connector reads the file from disk and the hub serves the bytes from its own
 * origin — the one path a bot-challenge wall (massagegoteborg.nu) cannot block, since
 * the site's REST namespace answers while /wp-content does not. Cookie auth needs the
 * REST nonce on an <img> request, so it rides in the query. '' when there is nothing
 * to ask for.
 */
function remoteThumbUrl(siteId: number, attachmentId: number, imageUrl: string): string {
  if (!siteId || (!attachmentId && !imageUrl)) return '';
  const cfg = getConfig();
  const base = `${cfg.restUrl}seo/sites/${siteId}/thumb`;
  const q = new URLSearchParams();
  if (attachmentId) q.set('attachmentId', String(attachmentId));
  if (imageUrl) q.set('url', imageUrl);
  if (cfg.nonce) q.set('_wpnonce', cfg.nonce);
  // A plain-permalink restUrl already carries `?rest_route=`; then our params must join with `&`.
  return `${base}${base.includes('?') ? '&' : '?'}${q.toString()}`;
}

function FeaturedThumb({ src, hasImageId, proxySrc = '' }: { src: string; hasImageId: boolean; /** Hub proxy URL tried when the site refuses the direct load. */ proxySrc?: string }) {
  // 'direct' → the site's own URL; 'proxy' → the hub's byte proxy; 'failed' → placeholder.
  const [stage, setStage] = useState<'direct' | 'proxy' | 'failed'>('direct');
  // A new URL (image changed / different site) deserves a fresh attempt.
  useEffect(() => { setStage('direct'); }, [src, proxySrc]);

  const attempt = stage === 'direct' ? src : stage === 'proxy' ? proxySrc : '';
  if (attempt) {
    return (
      <img
        src={attempt}
        alt=""
        loading="lazy"
        referrerPolicy="no-referrer"
        onError={() => setStage((s) => (s === 'direct' && proxySrc ? 'proxy' : 'failed'))}
        className="h-8 w-8 rounded object-cover"
      />
    );
  }
  const blocked = src !== '';   // there IS an image; the site just won't serve it here
  return (
    <span
      title={
        blocked
          ? (proxySrc
              ? 'This page has a featured image, but neither the site nor its connector would serve it here. If the site is behind bot protection, update its connector (SEO → Update connector) so images load through it. Click to choose another.'
              : 'This page has a featured image, but the site refuses to serve it to other origins (bot protection or hotlink protection), so it cannot be previewed here. Click to choose another.')
          : hasImageId
            ? 'A featured image is set on this page, but its URL could not be read (the media may be restricted). Click to choose another.'
            : 'Set featured image'
      }
      className={`flex h-8 w-8 items-center justify-center rounded border text-muted-foreground ${
        blocked || hasImageId ? 'border-border bg-muted' : 'border-dashed border-border'
      }`}
    >
      <ImageIcon className="h-4 w-4" />
    </span>
  );
}

function rowCellIsEmpty(r: SeoRow | undefined, field: string): boolean {
  if (!r) return true;
  if (field === 'metaTitle' && r.metaFromHead?.title) return true;
  if (field === 'metaDescription' && r.metaFromHead?.description) return true;
  return !String(r[field as keyof SeoRow] ?? '').trim();
}

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
  // The template last chosen from a column's "Generate column with…" menu, per
  // field. The column run passed its pick to generateField(); the per-cell
  // Re-generate and the bulk runner did not, so those two silently fell back to
  // whatever default resolve_prompt() picked — which is why the star icon
  // honoured an edited template and Re-generate appeared to use a different one.
  // Remembering the choice makes all three paths agree.
  const [columnTemplate, setColumnTemplate] = useState<Record<string, number | undefined>>({});
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
  // Connector health for the tab dots — ONE batched call (gap 89ef71a).
  const siteHealth = useSiteHealth(sites.length > 0);
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

  // Fields stored as SEO-plugin META on a remote site (mirrors the hub's
  // remote_meta_keys map). Without an active connector, WordPress core REST
  // silently DROPS writes to these — the hub detects the phantom save and
  // 422s — so bulk flows treat them as known-doomed rather than firing N
  // requests that all fail identically.
  const REMOTE_META_FIELDS = useMemo(() => new Set([
    'metaTitle', 'metaDescription', 'metaKeywords', 'primaryKeyword', 'supportingKeyword', 'clusterLabel',
  ]), []);

  // Connector presence for the SELECTED connected site. Born from
  // massagegoteborg.nu: its SEO table sat silently blank because the site has
  // NO connector at all — stored SEO meta can't reach wp/v2 and the head-tags
  // fallback route doesn't exist there — and nothing anywhere said so.
  // 'unknown' (plugins list unreadable — usually a capability limit) stays
  // QUIET: never nag on what we cannot verify.
  const connectorStatusQuery = trpc.sites.connectorStatus.useQuery(
    { id: siteId },
    { enabled: !isLocal && typeof siteId === 'number', staleTime: 300_000, retry: false },
  ) as any;
  const connectorState: { status?: string; version?: string; outdated?: boolean } = connectorStatusQuery.data ?? {};
  // An ACTIVE but old connector answers everything it knows and 404s the rest, so a
  // degraded feature looked like a broken one. When the hub has seen it refuse
  // /head-tags, offer the self-update it already supports.
  const connectorUpdate = trpc.sites.updateConnector.useMutation({
    onSuccess: (res: any) => {
      // The hub answers { status: 'updated' | 'up-to-date', from, to, version } — the
      // connector's own contract, re-read version included. The first version of
      // this handler tested `res.updated || res.ok`, keys that do NOT exist, so a
      // SUCCESSFUL update fell through to the "could not self-update — reinstall"
      // toast (bugfix card 8: "Plugin update is not working… even if the version is
      // the version with a self-updating package"). Real failures never reach
      // onSuccess — the hub returns 409/502 and onError below handles them.
      const status = String(res?.status ?? '');
      const version = res?.version ? ` (v${res.version})` : '';
      if (status === 'updated') {
        toast.success(`Connector updated${res?.from && res?.to ? ` ${res.from} → ${res.to}` : ''}${version}. Reload the table to use the new build.`);
      } else if (status === 'up-to-date') {
        // Already the newest build. If the banner still called it outdated, that flag
        // was stale — the refetch below re-reads the site and clears it.
        toast.success(`The connector is already the newest build${version}.`);
      } else if (status === 'stale') {
        // The site BELIEVES it is current but the hub serves a newer build — its update
        // poll can't reach the hub. The hub's message names the exact remedy.
        toast.warning(res?.message || 'The connector could not fetch the update from this hub — check the site can reach it, or reinstall once via Download connector.', { duration: 12000 });
      } else {
        toast.success(`Connector update finished${version}.`);
      }
      void connectorStatusQuery.refetch();
    },
    onError: (e: any) => toast.error(e.message ?? 'Could not update the connector.'),
  }) as any;
  const connectorActivate = trpc.sites.connectorActivate.useMutation({
    onSuccess: (res: any) => {
      if (res?.switched) toast.success(`Connector activated (v${res?.to || '?'}).`);
      else toast.error(res?.message || 'Could not activate the connector.');
      void connectorStatusQuery.refetch();
    },
    onError: (e: any) => toast.error(e.message ?? 'Could not activate the connector.'),
  }) as any;
  const downloadConnector = useCallback(async () => {
    try {
      const cfg = getConfig();
      const res = await fetch(`${cfg.restUrl}seohub/connector-download`, { headers: { 'X-WP-Nonce': cfg.nonce } });
      if (!res.ok) throw new Error('Download failed');
      const blob = await res.blob();
      // Server names the versioned file via Content-Disposition (owner-caught 2026-07-17).
      const match = (res.headers.get('Content-Disposition') ?? '').match(/filename="([^"]+)"/);
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = match ? match[1] : 'pcm-connector.zip';
      a.click();
      URL.revokeObjectURL(a.href);
    } catch (e) { toast.error(e instanceof Error ? e.message : 'Download failed'); }
  }, []);

  // ── New author ──
  // `options.authors` comes from the useSeoContent hook, which exposes no refetch handle, so a
  // freshly created author is appended locally instead. The id is the REAL one the server
  // returned, so the row's saveCell writes a valid post_author either way — this only avoids a
  // full content reload to see the name in the list.
  const [newAuthorFor, setNewAuthorFor] = useState<number | null>(null);
  const [newAuthorName, setNewAuthorName] = useState('');
  const [newAuthorEmail, setNewAuthorEmail] = useState('');
  const [extraAuthors, setExtraAuthors] = useState<{ id: number; name: string }[]>([]);

  // The CONNECTED SITE's own authors. Hub user ids and remote user ids are
  // unrelated id spaces, so a remote row must be offered this list — assigning a
  // hub id would hand the client's post to whoever holds that id over there.
  // Which is why the column used to be read-only for remote rows.
  const remoteAuthorsQuery = trpc.seo.remoteAuthors.useQuery(
    { siteId },
    { enabled: !isLocal && typeof siteId === 'number', staleTime: 60_000, retry: false },
  ) as any;
  const remoteAuthors: { id: number; name: string }[] =
    Array.isArray(remoteAuthorsQuery.data) ? remoteAuthorsQuery.data : [];

  // Session-created authors belong to the site they were created on. Without this
  // reset, switching sites would leave a stale name in the list whose id means
  // someone else entirely on the new site.
  useEffect(() => { setExtraAuthors([]); }, [siteId]);

  /** Authors valid for the CURRENT scope, plus any created in this session. */
  const authorOptions = useMemo(
    () => [...(isLocal ? (options?.authors ?? []) : remoteAuthors), ...extraAuthors],
    [isLocal, options?.authors, remoteAuthors, extraAuthors],
  );

  const createAuthorMutation = trpc.seo.createAuthor.useMutation({
    onError: (e: any) => toast.error(e.message ?? 'Could not create the author'),
  }) as any;
  const createRemoteAuthorMutation = trpc.seo.remoteCreateAuthor.useMutation({
    onError: (e: any) => toast.error(e.message ?? 'Could not create the author on that site'),
  }) as any;
  // Either path may be in flight; the dialog must reflect whichever is running.
  const creatingAuthor = createAuthorMutation.isPending || createRemoteAuthorMutation.isPending;
  const submitNewAuthor = useCallback(() => {
    const rowId = newAuthorFor;
    if (rowId === null) return;
    const name = newAuthorName.trim();
    const email = newAuthorEmail.trim();
    const onSuccess = (created: any) => {
      const a = { id: Number(created?.id), name: String(created?.name ?? name) };
      if (!a.id) { toast.error('The server did not return a user id.'); return; }
      setExtraAuthors((prev) => [...prev, a]);
      saveCell(rowId, 'author', String(a.id), a.name);   // assign the new author to the row
      toast.success(`“${a.name}” created and assigned`);
      setNewAuthorFor(null); setNewAuthorName(''); setNewAuthorEmail('');
    };
    // Create the user WHERE the post lives, or the id would be meaningless there.
    if (isLocal) {
      createAuthorMutation.mutate({ name, email }, { onSuccess });
    } else {
      createRemoteAuthorMutation.mutate({ siteId, name, email }, { onSuccess });
    }
  }, [newAuthorFor, newAuthorName, newAuthorEmail, isLocal, siteId,
      createAuthorMutation, createRemoteAuthorMutation, saveCell]);
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
  // The QUERY STRING is part of the key (as on the hub): a draft's permalink is a preview link
  // (`/?page_id=9`) whose PATH is `/` — dropping the query made every draft collapse onto the
  // homepage's key and wear the homepage's clicks. Google has never seen those rows.
  const normGscUrl = useCallback((url: string) => {
    try {
      const u = new URL(url);
      const host = u.hostname.replace(/^www\./, '');
      let path = u.pathname;
      try { path = decodeURIComponent(path); } catch { /* leave as-is on malformed % */ }
      let search = u.search;
      try { search = decodeURIComponent(search); } catch { /* leave as-is on malformed % */ }
      return (host + path.replace(/\/+$/, '') + search).toLowerCase();
    } catch { return ''; }
  }, []);
  const handlePullGsc = useCallback(async (days: number) => {
    if (gscPulling) return;
    setGscPulling(true);
    try {
      const data: any = await gscStatsMutation.mutateAsync({ site: isLocal ? '' : (activeSite?.url ?? ''), days });
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
  // Types present in the loaded rows — lets the Type filter offer a connected site's
  // custom post types, which this hub's own options payload can't know about.
  const rowTypes = useMemo(() => Array.from(new Set(rows.map((r) => r.type).filter(Boolean))), [rows]);
  const filterDefs = useMemo(() => ({ ...buildFilterDefs(options, rowTypes), ...gscFilterDefs, ...prtFilterDefs }), [options, rowTypes, gscFilterDefs, prtFilterDefs]);
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
  const { views, saveView, removeView, setDefaultView, renameView, setPinnedView, updateView, reorderViews } = useViews();
  const [appliedViewId, setAppliedViewId] = useState<number | null>(null);
  // Drag-and-drop reordering of the pinned-view TABS (persisted; the dropdown
  // renders the same server-ordered list, so both places always agree).
  const [dragViewId, setDragViewId] = useState<number | null>(null);

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

  // Overwrite the APPLIED view with the current columns + filters — "update an
  // existing instead of always needing to save a new one for every change".
  const handleUpdateView = useCallback(() => {
    if (appliedViewId === null) return;
    void updateView(appliedViewId, { columns: cols, filters: filterValues });
  }, [appliedViewId, updateView, cols, filterValues]);

  // Drop a dragged tab onto another: rearrange within the pinned subsequence and
  // persist the FULL view order (unpinned views keep their relative places).
  const handleTabDrop = useCallback((targetId: number) => {
    if (dragViewId === null || dragViewId === targetId) return;
    const pinnedIds = views.filter((v) => v.isPinned).map((v) => v.id);
    const from = pinnedIds.indexOf(dragViewId);
    const to = pinnedIds.indexOf(targetId);
    if (from === -1 || to === -1) return;
    pinnedIds.splice(to, 0, ...pinnedIds.splice(from, 1));
    const pinnedSet = new Set(pinnedIds);
    let p = 0;
    const full = views.map((v) => (pinnedSet.has(v.id) ? pinnedIds[p++] : v.id));
    void reorderViews(full);
  }, [dragViewId, views, reorderViews]);

  const handleDeleteView = useCallback((id: number) => {
    void removeView(id);
    setAppliedViewId((cur) => (cur === id ? null : cur));
  }, [removeView]);

  const handlePinView = useCallback((id: number, isPinned: boolean) => {
    void setPinnedView(id, isPinned);
  }, [setPinnedView]);

  const handleRenameView = useCallback((id: number, name: string) => {
    void renameView(id, name);
  }, [renameView]);

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
  const [linksPopup, setLinksPopup] = useState<{ id: number; kind: LinkKind; title: string; type: string } | null>(null);
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
      // Same template the column menu last used for this field (reported: the
      // star icon obeyed the edited prompt, Re-generate did not — this argument
      // was simply missing here).
      const value = await generateField(id, field, genModelId || undefined, genProvider, columnTemplate[field]);
      // An empty answer is not a suggestion — say so rather than staging an invisible
      // pending value that looks like the click did nothing.
      if (value.trim() === '') { toast.error('The model returned nothing for this cell — try again or pick another model.'); }
      else { setStaged((s) => ({ ...s, [key]: value })); }
    } catch { /* toast in hook */ } finally {
      setGenKey(null);
    }
  }, [generateField, genModelId, genProvider, columnTemplate]);

  // Bulk AI: generate the given fields across every selected row (sequential —
  // gentle on the provider), staging each result for review. In 'empty' mode,
  // cells that already have content are skipped; 'overwrite' regenerates all.
  const runBulk = useCallback(async (fields: string[], mode: 'empty' | 'overwrite') => {
    const ids = Array.from(selected);
    if (ids.length === 0 || fields.length === 0) return;
    const rowById = new Map(rows.map((r) => [r.id, r]));
    const cellIsEmpty = (id: number, field: string) => rowCellIsEmpty(rowById.get(id), field);
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
    let staged = 0;
    const failed: string[] = [];
    setProgress({ done, total });
    for (const { id, field } of jobs) {
      const key = `${id}:${field}`;
      setGenKey(key);
      try {
        // Per-field template pick, same as the column run and the per-cell
        // Re-generate — all three must resolve the same prompt.
        const value = await generateField(id, field, genModelId || undefined, genProvider, columnTemplate[field]);
        // An empty answer is a FAILURE for this cell, not a suggestion — staging ''
        // painted an invisible "pending" that looked like the run just skipped it.
        if (value.trim() === '') { failed.push(key); }
        else { setStaged((s) => ({ ...s, [key]: value })); staged += 1; }
      } catch {
        failed.push(key); // the hook already toasted the reason once
      }
      done += 1;
      setProgress({ done, total });
    }
    setGenKey(null);
    setProgress(null);
    setBusy(false);
    // ONE honest summary. Bulk used to swallow every failure silently, so a run that
    // errored on half its cells reported nothing — "unstable feature".
    if (failed.length > 0) {
      const label = (k: string) => { const f = k.slice(k.indexOf(':') + 1); return GEN_FIELDS.find((g) => g.key === f)?.label ?? f; };
      const byField = Array.from(new Set(failed.map(label))).join(', ');
      toast.error(`Generated ${staged} of ${total}. ${failed.length} cell${failed.length === 1 ? '' : 's'} produced nothing (${byField}) — see the earlier error for the reason; those cells are still empty, not skipped.`);
    } else if (staged > 0) {
      toast.success(`Generated ${staged} suggestion${staged === 1 ? '' : 's'} — review and accept.`);
    }
  }, [selected, rows, generateField, genModelId, genProvider, columnTemplate]);

  // Accept / discard ALL staged AI suggestions (the source's bar).
  //
  // Rewritten 2026-08-13 (owner: "bulk save… doesn't work… for one title, two
  // descriptions, three keywords"). The old version fire-and-forgot every save
  // and cleared the staging map IMMEDIATELY — on a connector-less site every
  // meta-field save 422s ("the remote site didn't store this SEO field"), so
  // the generated content was DISCARDED while a wall of identical error toasts
  // scrolled by. Now:
  //   - fields that are KNOWN-DOOMED (remote site whose connector is missing/
  //     inactive + a meta-backed field) are never sent — they STAY STAGED and
  //     one summary toast points at the banner;
  //   - everything else saves sequentially (each remote save is a synchronous
  //     round trip to the client site — the optimizer-502 lesson);
  //   - a save that fails anyway is RE-STAGED, so generated content survives.
  //   'unknown' connector status does NOT block: never refuse work on a site
  //   we merely could not inspect — the server's own verdict decides.
  const acceptAllStaged = useCallback(async () => {
    const entries = Object.entries(staged);
    if (entries.length === 0) return;
    const connectorBlocked = !isLocal
      && (connectorState.status === 'missing' || connectorState.status === 'inactive');
    const blocked: [string, string][] = [];
    const sendable: [string, string][] = [];
    for (const e of entries) {
      const field = e[0].slice(e[0].indexOf(':') + 1);
      (connectorBlocked && REMOTE_META_FIELDS.has(field) ? blocked : sendable).push(e);
    }
    setStaged(Object.fromEntries(blocked)); // doomed fields keep their suggestions
    let ok = 0;
    const failed: [string, string][] = [];
    setBusy(true);
    try {
      for (const [key, value] of sendable) {
        const sep = key.indexOf(':');
        const id = Number(key.slice(0, sep));
        const field = key.slice(sep + 1);
        if (!id) continue;
        try {
          await saveCell(id, field, value);
          ok++;
        } catch {
          failed.push([key, value]); // saveCell already toasted the reason once
        }
      }
    } finally {
      setBusy(false);
    }
    if (failed.length > 0) {
      setStaged((s) => ({ ...Object.fromEntries(failed), ...s })); // survive, retryable
    }
    const parts: string[] = [];
    if (ok > 0) parts.push(`Saved ${ok} suggestion${ok === 1 ? '' : 's'}`);
    if (blocked.length > 0) parts.push(`${blocked.length} kept pending — this site's connector is ${connectorState.status} (see the banner above), and WordPress drops SEO meta without it`);
    if (failed.length > 0) parts.push(`${failed.length} failed and stay pending`);
    if (parts.length > 0) {
      (blocked.length > 0 || failed.length > 0 ? toast.error : toast.success)(parts.join('. ') + '.');
    }
  }, [staged, saveCell, isLocal, connectorState.status]);
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
        // "Has an image" includes one whose URL couldn't be resolved — the cell shows it as
        // set, so sorting must agree with what the user sees.
        featuredImage: (r) => (r.featuredImage || (r.featuredImageId ?? 0) > 0 ? 1 : 0),
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
      // Same emptiness law as the floating-bar run (rowCellIsEmpty) — a rendered-head
      // fallback is NOT content, so "where empty" no longer skips those cells here either.
      .filter((r) => mode === 'overwrite' || rowCellIsEmpty(r, field))
      .map((r) => r.id);
    if (ids.length === 0) { toast('Nothing to generate — those cells already have content.'); return; }
    setColumnGenerating(field);
    const total = ids.length;
    let done = 0;
    let staged = 0;
    let failed = 0;
    setProgress({ done, total });
    for (const id of ids) {
      const key = `${id}:${field}`;
      setGenKey(key);
      try {
        const value = await generateField(id, field, genModelId || undefined, genProvider, templateId);
        // Same rule as the floating-bar run: an empty answer is a failure, never an
        // invisible "pending" that reads as a skipped cell.
        if (value.trim() === '') failed += 1;
        else { setStaged((s) => ({ ...s, [key]: value })); staged += 1; }
      } catch { failed += 1; /* toast in hook */ }
      done += 1;
      setProgress({ done, total });
    }
    setGenKey(null);
    setProgress(null);
    setColumnGenerating(null);
    if (failed > 0) {
      toast.error(`Generated ${staged} of ${total}. ${failed} cell${failed === 1 ? '' : 's'} produced nothing — see the earlier error for the reason; those cells are still empty, not skipped.`);
    } else if (staged > 0) {
      toast.success(`Generated ${staged} suggestion${staged === 1 ? '' : 's'} — review and accept.`);
    }
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
              onGenerate: (tid?: number) => {
                // Remember the pick so a later per-cell Re-generate uses the SAME
                // template, instead of falling back to the resolved default.
                setColumnTemplate((prev) => ({ ...prev, [key]: tid }));
                setPendingGen({ run: (mode) => handleColumnGenerate(key, tid, mode) });
              },
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
                  // THE TITLE GESTURE (gap cad42df): click = edit the page
                  // (our editor on connected rows, the WP editor locally —
                  // same meaning, different destination); double-click =
                  // edit the title text. Title cell ONLY.
                  onOpen={isLocal
                    ? (row.editUrl ? () => window.open(row.editUrl as string, '_blank', 'noopener,noreferrer') : undefined)
                    : () => setPageEditRow(row)}
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
              <SelectTrigger variant="ghost" size="auto" className="h-full w-full">
                <Pill variant={statusPillVariant(row.status)} className="capitalize">{row.status}</Pill>
              </SelectTrigger>
              <SelectContent>
                {(options?.statuses ?? ['publish', 'draft', 'pending', 'private', 'future']).map((s) => (
                  <SelectItem key={s} value={s} className="capitalize">{s}</SelectItem>
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
      case 'author': {
        // Editable for BOTH scopes now. `authorOptions` resolves to the hub's users for
        // local rows and to the CONNECTED SITE's own users for remote ones — the two id
        // spaces are unrelated, and mixing them would reassign a client's post to
        // whoever holds that id over there. That hazard is why remote rows were
        // read-only before; it is handled by the list, not by disabling the control.
        // Falls back to read-only text when the list is empty — e.g. the site's
        // credentials cannot list users, so there is nothing safe to offer.
        const authors = authorOptions;
        if (authors.length === 0) {
          return <TableCell key={key} className="text-xs text-muted-foreground">{row.author}</TableCell>;
        }
        return (
          <TableCell key={key}>
            <Select
              value={row.authorId ? String(row.authorId) : ''}
              // Pass the picked option's NAME too: the row displays `author` (name) and
              // the id lives in `authorId` — without it the cell showed the raw id.
              onValueChange={(v) => saveCell(row.id, 'author', v, authors.find((a) => String(a.id) === v)?.name)}
            >
              <SelectTrigger variant="ghost" size="auto" className="h-full w-full">
                {/* Fall back to the stored NAME when the current author isn't in the list —
                    e.g. an author who has since lost edit_posts. Showing the raw id would be
                    worse than showing a name we already have. */}
                <SelectValue placeholder={row.author || '—'}>{row.author || '—'}</SelectValue>
              </SelectTrigger>
              <SelectContent>
                {authors.map((a: { id: number; name: string }) => (
                  <SelectItem key={a.id} value={String(a.id)}>{a.name}</SelectItem>
                ))}
                {/* Create a new WordPress author without leaving the table. onSelect is
                    prevented so opening the dialog doesn't also commit a bogus selection —
                    the new author is assigned only after the server returns its real id. */}
                <div className="border-t mt-1 pt-1">
                  <button
                    type="button"
                    className="flex w-full items-center gap-1.5 rounded-sm px-2 py-1.5 text-xs text-muted-foreground hover:bg-accent"
                    onClick={(e) => { e.preventDefault(); e.stopPropagation(); setNewAuthorFor(row.id); }}
                  >
                    <Plus className="h-3.5 w-3.5" /> New author…
                  </button>
                </div>
              </SelectContent>
            </Select>
          </TableCell>
        );
      }
      case 'slug': {
        const ckey = `${row.id}:slug`;
        // SHOW subpages as subpages (Filip: the table "needs to pull in and show the
        // subpages"): a child page or custom-type item lives under a path
        // (/services/lymphatic-drainage/), but the cell showed only the leaf slug, so
        // nothing distinguished it from a top-level page. Derive the prefix from the
        // permalink the row already carries — display-only; the editable value stays
        // the leaf slug, which is what WordPress actually lets you change.
        let pathPrefix = '';
        try {
          if (row.permalink) {
            const segs = new URL(row.permalink).pathname.split('/').filter(Boolean);
            if (segs.length > 1) pathPrefix = '/' + segs.slice(0, -1).join('/') + '/';
          }
        } catch { /* draft preview links etc. — no prefix */ }
        return (
          <TableCell key={key}>
            <div className="flex min-w-0 items-center">
              {pathPrefix && (
                <span
                  className="max-w-[45%] shrink-0 truncate text-xs text-muted-foreground/60"
                  title={`This is a subpage — it lives under ${pathPrefix}`}
                >
                  {pathPrefix}
                </span>
              )}
              <div className="min-w-0 flex-1">
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
              </div>
            </div>
          </TableCell>
        );
      }
      case 'supportingKeyword':
        return (
          <TableCell key={key}>
            <EditableCell value={row.supportingKeyword} placeholder="Supporting KW" onSave={(v) => saveCell(row.id, 'supportingKeyword', v)} />
          </TableCell>
        );
      case 'featuredImage': {
        // Three honest states: a thumbnail; "set but unreadable" (the post carries a
        // featured-media id whose URL we couldn't resolve — say so instead of showing the
        // empty box, which reads as "no image"); or genuinely none.
        const hasImageId = (row.featuredImageId ?? 0) > 0;
        return (
          <TableCell key={key} className="text-center">
            <button
              type="button"
              onClick={() => openFeaturedImage(row)}
              title={
                row.featuredImage
                  ? 'Change featured image'
                  : hasImageId
                    ? 'A featured image is set on this page, but its URL could not be read (the media may be restricted). Click to choose another.'
                    : 'Set featured image'
              }
              className="inline-flex items-center justify-center align-middle transition-opacity hover:opacity-80"
            >
              <FeaturedThumb
                src={row.featuredImage || ''}
                hasImageId={hasImageId}
                // Connected sites only: the hub proxy that survives a bot/hotlink wall.
                proxySrc={isLocal ? '' : remoteThumbUrl(siteId as number, row.featuredImageId ?? 0, row.featuredImage || '')}
              />
            </button>
          </TableCell>
        );
      }
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
        // Pass the row's REAL type: the hub resolves it to that type's REST route, so a
        // custom-post-type row (services, doctors…) edits the right endpoint.
        const linkType: string = row.type || 'post';
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
          {/* The hub is itself — never health-tested, always the quiet dot. */}
          <SiteStatusDot ok={null} /> This Site
        </button>
        {sites.map((s) => (
          <button
            key={s.id}
            type="button"
            onClick={() => setSiteId(Number(s.id))}
            title={s.url}
            className={`inline-flex items-center gap-1.5 px-3 py-1.5 text-sm -mb-px border-b-2 max-w-[220px] ${siteId === Number(s.id) ? 'border-primary text-primary font-medium' : 'border-transparent text-muted-foreground hover:text-foreground'}`}
          >
            <SiteStatusDot ok={siteHealth(Number(s.id)).ok} error={siteHealth(Number(s.id)).error} />
            <span className="truncate">{s.name || s.url || `Site #${s.id}`}</span>
          </button>
        ))}
      </div>

      {/* Section nav (left) + section content, side by side. The nav floats
          on the canvas — the blue pill IS the state, whitespace the border
          (one-canvas law). The content toolbar (Views/Columns/Post/Page/Model)
          lives INSIDE the content column (below) so switching sections never
          shifts the nav's position. */}
      <div className="flex gap-6 items-start">
        <nav className="flex w-44 shrink-0 flex-col gap-1 p-2">
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
        // ONE studio for this site and every connected site (card 17 remake): three
        // files (/llm-info/, llms.txt + page .md, robots.txt), each with Generate +
        // Publish, and a live check that fetches them as a crawler would.
        isLocal ? (
          <AiReadinessStudio siteName="this site" />
        ) : typeof siteId === 'number' ? (
          <AiReadinessStudio siteId={siteId} siteName={activeSite?.name || activeSite?.url || 'this site'} />
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
        // THE BUSINESS CARD (gap 616870f): a CONNECTED site gets its resolved
        // per-site record — mapped, inline-editable, source-labeled. The local
        // site keeps the brand-picker panel until P4 re-homes it.
        isLocal ? <BusinessPanel /> : <RemoteBusinessCard siteId={Number(activeSite?.id ?? 0)} />
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
            onUpdateView={handleUpdateView}
            onDeleteView={handleDeleteView}
            onRenameView={handleRenameView}
            onPinView={handlePinView}
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
          {/* Clicks / impressions / CTR / position / top queries. Clicking the button opens the
              period menu and the pull starts as soon as you pick one — so it's one gesture, and
              there's no second control parked in the toolbar advertising a number you rarely
              change. Values are whole days; the endpoint clamps to 1–180. */}
          <DropdownMenu>
            {/* asChild targets this SPAN, never the PillButton. PillButtonProps is a CLOSED
                interface — no forwardRef, no {...rest} — so Radix's cloned onClick/ref would be
                silently dropped and the button would do nothing at all (shipped exactly that
                once). The span is a real DOM node, so it receives the handler and ref, and the
                inner button's click bubbles up to it. PillButton is given NO onClick here. */}
            <DropdownMenuTrigger asChild disabled={busy || gscPulling}>
              <span className="inline-flex">
                <PillButton
                  icon={gscPulling ? <Loader2 className="animate-spin" /> : <TrendingUp />}
                  disabled={busy || gscPulling}
                >
                  {gscPulling ? 'Pulling…' : 'GSC stats'}
                </PillButton>
              </span>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-40">
              <DropdownMenuLabel className="text-xs text-muted-foreground">Pull period</DropdownMenuLabel>
              <DropdownMenuSeparator />
              {[7, 30, 60, 90, 120].map((d) => (
                <DropdownMenuItem
                  key={d}
                  className="text-xs"
                  onSelect={() => { void handlePullGsc(d); }}
                >
                  Last {d} days
                </DropdownMenuItem>
              ))}
            </DropdownMenuContent>
          </DropdownMenu>
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
              className="w-[190px] gap-1"
              title="Model used for AI generation"
            >
              <SelectValue
                placeholder={<span className="text-muted-foreground">Select model</span>}
              />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="__default__">Default model</SelectItem>
              {textModels.map((m) => (
                <SelectItem key={m.id} value={m.id}>
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
            onUpdateView={handleUpdateView}
            onDeleteView={handleDeleteView}
            onRenameView={handleRenameView}
            onPinView={handlePinView}
            onSetDefaultView={handleSetDefaultView}
            onResetLayout={resetColumnLayout}
          />
        </div>
      </div>

      {/* Pinned views — a tab strip directly above the table. Only renders when at least one
          view is pinned, so the layout is unchanged for anyone who never uses it. Clicking a
          tab applies that view exactly like picking it from the dropdown; "All" clears back to
          the default (all columns, no filters). The active tab is the applied view, so the
          strip always reflects what the table is actually showing. */}
      {views.some((v) => v.isPinned) && (
        <div className="mb-2 flex items-center gap-1 overflow-x-auto border-b border-border">
          <button
            type="button"
            onClick={resetView}
            className={`shrink-0 border-b-2 px-3 py-1.5 text-xs transition-colors ${
              appliedViewId === null
                ? 'border-primary text-primary font-medium'
                : 'border-transparent text-muted-foreground hover:text-foreground'
            }`}
          >
            All
          </button>
          {views.filter((v) => v.isPinned).map((v) => (
            // Draggable (native DnD, no new dependency): drop on another tab to
            // reorder; the order persists and the Views dropdown mirrors it.
            <button
              key={v.id}
              type="button"
              draggable
              onDragStart={(e) => { e.dataTransfer.effectAllowed = 'move'; setDragViewId(v.id); }}
              onDragOver={(e) => { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; }}
              onDrop={(e) => { e.preventDefault(); handleTabDrop(v.id); setDragViewId(null); }}
              onDragEnd={() => setDragViewId(null)}
              onClick={() => applyView(v)}
              title={`Apply “${v.name}” — drag to reorder`}
              // A regular pointer (owner: the drag hand "is not requested") — the tab is a
              // BUTTON you click; drag-to-reorder still works, it just isn't advertised by the cursor.
              className={`shrink-0 max-w-[12rem] cursor-pointer truncate border-b-2 px-3 py-1.5 text-xs transition-colors ${
                dragViewId === v.id ? 'opacity-40' : ''
              } ${
                appliedViewId === v.id
                  ? 'border-primary text-primary font-medium'
                  : 'border-transparent text-muted-foreground hover:text-foreground'
              }`}
            >
              {v.name}
            </button>
          ))}
        </div>
      )}

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

      {/* Connector missing/inactive on the selected site — the reason meta
          columns can sit blank and edits are limited. 'unknown' stays quiet. */}
      {!isLocal && (connectorState.status === 'missing' || connectorState.status === 'inactive') && (
        <div className="flex items-center flex-wrap gap-2 mb-3 rounded-lg border border-amber-500/40 bg-amber-500/10 px-4 py-2">
          <span className="text-sm text-foreground">
            {connectorState.status === 'inactive'
              ? 'The Power Creatives Connector is installed on this site but not active — SEO meta reading and editing are limited until it runs.'
              : 'This site has no Power Creatives Connector — stored SEO meta and reliable tag reading are unavailable without it.'}
          </span>
          <span className="flex-1" />
          {connectorState.status === 'inactive' ? (
            <Button size="sm" className="h-8" disabled={connectorActivate.isPending} onClick={() => connectorActivate.mutate({ id: siteId })}>
              {connectorActivate.isPending ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : null}
              Activate connector
            </Button>
          ) : (
            <Button size="sm" variant="outline" className="h-8" onClick={downloadConnector} title="Download the connector zip, then install + activate it on the site (Plugins → Add New → Upload)">
              Download connector
            </Button>
          )}
        </div>
      )}

      {/* Connector is ACTIVE but too old for /head-tags: meta still appears (the hub
          reads the rendered pages itself) but slowly, a page-batch per load. Say so,
          and offer the self-update that makes it instant. */}
      {!isLocal && connectorState.status === 'active' && connectorState.outdated && (
        <div className="flex items-center flex-wrap gap-2 mb-3 rounded-lg border border-amber-500/40 bg-amber-500/10 px-4 py-2">
          <span className="text-sm text-foreground">
            This site’s connector is an older build{connectorState.version ? ` (v${connectorState.version})` : ''} without the
            meta-tag reader, so Meta Title / Description fill in slowly, a batch per reload. Updating it makes them appear at once.
          </span>
          <span className="flex-1" />
          <Button size="sm" className="h-8 gap-1.5" disabled={connectorUpdate.isPending} onClick={() => connectorUpdate.mutate({ id: siteId })}>
            {connectorUpdate.isPending ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : null}
            Update connector
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
          <Button size="sm" variant="success" className="h-7 gap-1.5" onClick={acceptAllStaged}>
            <Check className="w-3.5 h-3.5" /> Accept all &amp; save
          </Button>
          <Button variant="ghost" size="sm" className="h-7 gap-1.5" onClick={discardAllStaged}>
            <X className="w-3.5 h-3.5" /> Discard all
          </Button>
        </div>
      )}

      {isLoading ? (
        <div className="flex flex-col items-center justify-center gap-2 py-20">
          <Loader2 className="w-6 h-6 animate-spin text-primary" />
          {/* The remote first load has no stored copy yet (gap 02d3cb7 D3) —
              SAY so; every later open serves the local copy instantly. */}
          {!isLocal && (
            <div className="text-xs text-muted-foreground">
              Fetching content from the site — the first load builds the local copy, next opens are instant…
            </div>
          )}
        </div>
      ) : sortedData.length === 0 ? (
        <div className="border border-dashed border-border rounded-xl p-12 text-center text-sm text-muted-foreground">
          No content yet — create a post or page to get started.
        </div>
      ) : (
        <div className="rounded-md border border-border overflow-auto max-h-[calc(100vh-300px)] bg-card">
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
                      type={row.type || 'post'}
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
      {/* New-author form. A plain overlay rather than the shared Dialog: this file already
          hand-rolls its overlays (see the portal above) and pulling in Dialog here would add an
          import for one small form. Creates a WP user server-side (role fixed to `author`). */}
      {newAuthorFor !== null && (
        <div
          className="fixed inset-0 z-[60] flex items-center justify-center bg-black/40"
          onClick={() => setNewAuthorFor(null)}
        >
          <div
            className="w-[22rem] rounded-lg border bg-card p-4 shadow-lg"
            onClick={(e) => e.stopPropagation()}
          >
            <h3 className="mb-1 text-sm font-medium">New author</h3>
            {/* Name WHERE the user is created — on a client's install this is not a
                detail the operator should have to infer. */}
            <p className="mb-3 text-xs text-muted-foreground">
              Creates a WordPress user with the <strong>Author</strong> role on{' '}
              <strong>{isLocal ? 'this site' : (activeSite?.name || activeSite?.url || 'the connected site')}</strong>
              {' '}and assigns them to this page.
            </p>
            <div className="space-y-2">
              <Input
                autoFocus
                value={newAuthorName}
                onChange={(e) => setNewAuthorName(e.target.value)}
                placeholder="Display name"
                className="h-8 text-sm"
              />
              <Input
                type="email"
                value={newAuthorEmail}
                onChange={(e) => setNewAuthorEmail(e.target.value)}
                onKeyDown={(e) => { if (e.key === 'Enter' && newAuthorName.trim() && newAuthorEmail.trim()) submitNewAuthor(); }}
                placeholder="Email address"
                className="h-8 text-sm"
              />
            </div>
            <div className="mt-3 flex justify-end gap-2">
              <Button variant="outline" size="sm" className="h-8" onClick={() => setNewAuthorFor(null)}>
                Cancel
              </Button>
              <Button
                size="sm"
                className="h-8"
                disabled={!newAuthorName.trim() || !newAuthorEmail.trim() || creatingAuthor}
                onClick={submitNewAuthor}
              >
                {creatingAuthor ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : 'Create'}
              </Button>
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
          // Anything edited inside the popup → rescan the row so the TABLE's
          // Int/Ext/Broken counts update too ("it's still saying 27 dead links").
          onChanged={() => { void scanLinks(linksPopup.id); }}
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
          type={pageEditRow.type || 'post'}
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
