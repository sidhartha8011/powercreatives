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
  useMemo, useState, useCallback, useEffect, useRef,
  type KeyboardEvent, type PointerEvent as ReactPointerEvent,
  type HTMLAttributes, type ThHTMLAttributes, type TdHTMLAttributes, type TableHTMLAttributes,
} from 'react';
import {
  Plus, Trash2, ExternalLink, SquarePen, Loader2, Sparkles, Check, X, Globe, RefreshCw,
  Type, AlignLeft, KeyRound, Tags, FileText, CircleDot, Braces, User, type LucideIcon,
  Image as ImageIcon, Link2, Calendar, TrendingUp, Eye, Unlink,
} from 'lucide-react';
import { toast } from 'sonner';

import { trpc } from '@/lib/trpc';
import { ModuleHeader } from '@/components/shared/ModuleHeader';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import { useSortableTable } from '@/hooks/useSortableTable';
import { useSettings } from '@/contexts/AppContext';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';

import { useSeoContent } from './hooks/useSeoContent';
import { useRemoteSeoContent } from './hooks/useRemoteSeoContent';
import { useColumnFilters } from './hooks/useColumnFilters';
import { useViews, type SeoView } from './hooks/useViews';
import { useColumnLayout } from './hooks/useColumnLayout';
import { ColumnHead } from './ColumnHead';
import { ViewsToolbar } from './ViewsToolbar';
import { buildFilterDefs } from './seoFilters';
import { AIReadinessPanel } from './AIReadinessPanel';
import { RemoteAIReadinessPanel } from './RemoteAIReadinessPanel';
import { SiteSettingsPanel } from './SiteSettingsPanel';
import { RemoteSiteSettingsPanel } from './RemoteSiteSettingsPanel';
import { BusinessPanel } from './BusinessPanel';
import { SchemaCell } from './SchemaCell';
import { OptimizeModal } from './OptimizeModal';
import { SEO_TEXT_FIELDS, type SeoRow } from './types';

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
 * When `onGenerate` is supplied, shows an AI sparkle; a returned suggestion
 * is STAGED — the user accepts (saves) or rejects it.
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

  // Staged AI suggestion → show new value with accept / reject.
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
/** Generatable fields for the bulk toolbar (key → short label). */
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

/** Status → single-select chip colors (Airtable-style). */
function statusBadgeClass(status: string): string {
  switch (status) {
    case 'publish': return 'bg-green-100 text-green-700';
    case 'pending': return 'bg-amber-100 text-amber-700';
    case 'private': return 'bg-purple-100 text-purple-700';
    case 'future': return 'bg-blue-100 text-blue-700';
    case 'draft':
    default: return 'bg-muted text-muted-foreground';
  }
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
  { key: 'traffic', label: 'Traffic' },
  { key: 'author', label: 'Author' },
  { key: 'preview', label: 'Preview' },
  { key: 'open', label: 'Open' },
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
  | 'internalLinks' | 'externalLinks' | 'brokenLinks';
/** Columns that support click-to-sort. */
const SORTABLE_KEYS = new Set<string>([
  'type', 'title', 'status', 'date',
  'slug', 'supportingKeyword', 'featuredImage',
  'internalLinks', 'externalLinks', 'brokenLinks',
]);
/** Leading header icon per column. */
const HEAD_ICONS: Record<string, LucideIcon> = {
  type: FileText, title: Type, status: CircleDot, schema: Braces, author: User,
  slug: Link2, featuredImage: ImageIcon, supportingKeyword: KeyRound,
  date: Calendar, traffic: TrendingUp, preview: Eye,
  internalLinks: Link2, externalLinks: ExternalLink, brokenLinks: Unlink, ...FIELD_ICONS,
};
/** Default px width per column (seeds the spreadsheet layout on first use). */
const DEFAULT_COLUMN_WIDTHS: Record<string, number> = {
  type: 90, title: 240, slug: 160, featuredImage: 72, status: 120,
  metaTitle: 200, metaDescription: 260, primaryKeyword: 150, metaKeywords: 180,
  supportingKeyword: 150, schema: 150, date: 120, traffic: 90, author: 120,
  internalLinks: 110, externalLinks: 110, brokenLinks: 110,
  preview: 72, open: 80,
};
/** Fixed leading selection/row-number column (not reorderable/resizable). */
const SELECT_COL_WIDTH = 44;

export function SEOModule() {
  const { rows: localRows, options, isLoading: localLoading, saveCell: localSaveCell, quickCreate: localQuickCreate, bulkDelete, generateField: localGenerateField, scanLinks: localScanLinks } = useSeoContent();

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
  // Per-column filters (funnel icon in each column header).
  const filterDefs = useMemo(() => buildFilterDefs(options), [options]);
  const { values: filterValues, setFilter, setAll, clearAll, apply, activeCount } = useColumnFilters();
  // Column visibility (Columns menu) — missing/true = visible, false = hidden.
  const [cols, setCols] = useState<Record<string, boolean>>(
    () => Object.fromEntries(TOGGLE_COLUMNS.map((c) => [c.key, true])),
  );
  const toggleCol = useCallback((key: string) => setCols((c) => ({ ...c, [key]: c[key] === false })), []);
  const vis = (key: string) => cols[key] !== false;
  // Spreadsheet-style column order + widths (drag to reorder / resize; persisted
  // to localStorage). Selection column stays fixed and is not part of this.
  const { order: colOrder, width: colWidth, setWidth: setColWidth, moveColumn, reset: resetColumnLayout } =
    useColumnLayout(COLUMN_KEYS, DEFAULT_COLUMN_WIDTHS);
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
  const [scanningRows, setScanningRows] = useState<Set<number>>(() => new Set());
  const handleScan = useCallback((id: number) => {
    setScanningRows((s) => { if (s.has(id)) return s; const n = new Set(s); n.add(id); return n; });
    scanLinks(id).finally(() =>
      setScanningRows((s) => { const n = new Set(s); n.delete(id); return n; }),
    );
  }, [scanLinks]);
  // AI staging: suggestions keyed `${id}:${field}`, plus the in-flight key.
  const [staged, setStaged] = useState<Record<string, string>>({});
  const [genKey, setGenKey] = useState<string | null>(null);
  // Aggregate progress for bulk runs ({done}/{total} cells).
  const [progress, setProgress] = useState<{ done: number; total: number } | null>(null);

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

  const acceptStaged = useCallback((id: number, field: string) => {
    const key = `${id}:${field}`;
    setStaged((s) => {
      const v = s[key];
      if (v !== undefined) void saveCell(id, field, v);
      const next = { ...s };
      delete next[key];
      return next;
    });
  }, [saveCell]);

  const rejectStaged = useCallback((id: number, field: string) => {
    setStaged((s) => { const next = { ...s }; delete next[`${id}:${field}`]; return next; });
  }, []);

  // Bulk AI: generate one or more fields across every selected row (sequential
  // — gentle on the provider), staging each result for review. Drives both the
  // per-field buttons and "Generate all".
  const runBulk = useCallback(async (fields: string[]) => {
    const ids = Array.from(selected);
    if (ids.length === 0 || fields.length === 0) return;
    setBusy(true);
    const total = ids.length * fields.length;
    let done = 0;
    setProgress({ done, total });
    for (const id of ids) {
      for (const field of fields) {
        const key = `${id}:${field}`;
        setGenKey(key);
        try {
          const value = await generateField(id, field, genModelId || undefined, genProvider);
          setStaged((s) => ({ ...s, [key]: value }));
        } catch { /* toast in hook */ }
        done += 1;
        setProgress({ done, total });
      }
    }
    setGenKey(null);
    setProgress(null);
    setBusy(false);
  }, [selected, generateField, genModelId, genProvider]);

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
      },
    },
  );

  const visibleIds = sortedData.map((r) => r.id);
  const allSelected = visibleIds.length > 0 && visibleIds.every((id) => selected.has(id));
  const someSelected = selected.size > 0 && !allSelected;

  // Header ✦ → pick a template → generate the ENTIRE column (every visible row)
  // with that template, staging each result for review (Optimizer behavior).
  const handleColumnGenerate = useCallback(async (field: string, templateId?: number) => {
    if (columnGenerating) return;
    const ids = sortedData.map((r) => r.id);
    if (ids.length === 0) return;
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
  }, [columnGenerating, sortedData, generateField, genModelId, genProvider]);

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

  const handleCreate = useCallback(async (type: 'post' | 'page') => {
    setBusy(true);
    try { await quickCreate(type); } catch { /* surfaced */ } finally { setBusy(false); }
  }, [quickCreate]);

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
        className={key === 'open' ? 'text-center' : undefined}
        sort={sortable
          ? { active: sortKey === key, dir: sortDir, onToggle: () => toggleSort(key as SeoSortKey) }
          : undefined}
        filter={key !== 'open' && def
          ? { def, value: filterValues[key] ?? '', onChange: (v) => setFilter(key, v) }
          : undefined}
        generate={GENERATABLE.has(key)
          ? {
              templates: templatesForCol(key),
              busy: columnGenerating === key,
              onGenerate: (tid?: number) => handleColumnGenerate(key, tid),
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
            <span className="inline-flex items-center rounded px-1.5 py-0.5 text-[11px] capitalize bg-muted/60 text-muted-foreground">{row.type}</span>
          </TableCell>
        );
      case 'title':
        return (
          <TableCell key={key}>
            <div className="flex items-center gap-1">
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
              {row.editUrl && (
                <a
                  href={row.editUrl}
                  target="_blank"
                  rel="noopener noreferrer"
                  title="Edit on site"
                  className="shrink-0 text-muted-foreground/50 hover:text-foreground"
                >
                  <SquarePen className="w-3.5 h-3.5" />
                </a>
              )}
            </div>
          </TableCell>
        );
      case 'status':
        return (
          <TableCell key={key}>
            <Select value={row.status} onValueChange={(v) => saveCell(row.id, 'status', v)}>
              <SelectTrigger className="h-full w-full border-0 rounded-none bg-transparent px-0 text-xs shadow-none focus:ring-0 focus:ring-offset-0">
                <span className={`inline-flex items-center rounded-full px-1.5 py-0 text-[9px] font-medium capitalize ${statusBadgeClass(row.status)}`}>{row.status}</span>
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
              onSave={(v) => saveCell(row.id, 'slug', v)}
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
            {row.featuredImage
              ? <img src={row.featuredImage} alt="" loading="lazy" className="inline-block h-8 w-8 rounded object-cover align-middle" />
              : <span className="text-xs text-muted-foreground">—</span>}
          </TableCell>
        );
      case 'date':
        return (
          <TableCell key={key} className="whitespace-nowrap text-xs text-muted-foreground">
            {row.date ? new Date(row.date.replace(' ', 'T')).toLocaleDateString() : '—'}
          </TableCell>
        );
      case 'traffic':
        // Placeholder — real data arrives with the Google Search Console connector.
        return (
          <TableCell key={key} className="text-center text-xs text-muted-foreground" title="Search traffic — connect Google Search Console (coming soon)">—</TableCell>
        );
      case 'internalLinks':
      case 'externalLinks':
      case 'brokenLinks': {
        const scanning = scanningRows.has(row.id);
        const scanned = !!row.linksScannedAt;
        const value = key === 'internalLinks' ? row.internalLinks : key === 'externalLinks' ? row.externalLinks : row.brokenLinks;
        const isBroken = key === 'brokenLinks';
        return (
          <TableCell key={key} className="text-center text-xs">
            {scanning ? (
              <Loader2 className="inline-block w-3.5 h-3.5 animate-spin text-primary" />
            ) : scanned ? (
              <span className="inline-flex items-center justify-center gap-1">
                <span className={isBroken && (value ?? 0) > 0 ? 'font-semibold text-destructive' : 'text-muted-foreground'}>{value ?? 0}</span>
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
      case 'preview':
        return (
          <TableCell key={key} className="text-center">
            <button
              type="button"
              onClick={() => setPreviewRow(row)}
              disabled={!row.permalink}
              className="text-muted-foreground hover:text-primary disabled:opacity-40"
              title="Preview page"
            >
              <Eye className="w-3.5 h-3.5" />
            </button>
          </TableCell>
        );
      case 'open':
        return (
          <TableCell key={key} className="text-center">
            <div className="inline-flex items-center gap-2">
              <button
                type="button"
                onClick={() => setOptimizeRow(row)}
                className="text-muted-foreground hover:text-primary"
                title="Optimize content with AI"
              >
                <Sparkles className="w-3.5 h-3.5" />
              </button>
              {row.permalink && (
                <a href={row.permalink} target="_blank" rel="noopener noreferrer" className="inline-flex text-muted-foreground hover:text-foreground" title="View page">
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
        const canGen = GENERATABLE.has(key);
        const ckey = `${row.id}:${key}`;
        return (
          <TableCell key={key}>
            <EditableCell
              value={String(row[key as keyof SeoRow] ?? '')}
              placeholder={field.label}
              onSave={(v) => saveCell(row.id, key, v)}
              onGenerate={canGen ? () => handleGenerate(row.id, key) : undefined}
              generating={genKey === ckey}
              suggestion={canGen ? (staged[ckey] ?? null) : null}
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

      {/* Content toolbar (content tab only): Views (left) · Post / Page / Model +
          Columns (right). Sits above the nav+table so the section nav and table
          start at the same height. */}
      {tab === 'content' && (
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
            <Button size="sm" onClick={() => handleCreate('post')} disabled={busy} className="h-8 gap-1.5 text-xs">
              <Plus className="w-3.5 h-3.5" /> Post
            </Button>
            <Button size="sm" onClick={() => handleCreate('page')} disabled={busy} className="h-8 gap-1.5 text-xs">
              <Plus className="w-3.5 h-3.5" /> Page
            </Button>
            <Select
              value={genModelId || '__default__'}
              onValueChange={(v) => setGenModel(v === '__default__' ? '' : v)}
            >
              <SelectTrigger className="h-8 w-[190px] bg-card text-xs" title="Model used for AI generation">
                <Sparkles className="w-3.5 h-3.5 shrink-0 text-muted-foreground" />
                <SelectValue placeholder="Model" />
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
      )}

      {/* Section nav (left) + section content, side by side. The nav lives in a
          card surface so it reads as a panel instead of floating. */}
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
        isLocal
          ? <AIReadinessPanel />
          : typeof siteId === 'number'
            ? <RemoteAIReadinessPanel siteId={siteId} siteName={activeSite?.name || activeSite?.url || 'this site'} />
            : <RemoteSitePlaceholder siteName={activeSite?.name || activeSite?.url || 'this site'} siteUrl={activeSite?.url} section={SECTION_LABEL[tab]} />
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
      {/* Bulk actions bar — generate any/all fields across the selected rows (local only). */}
      {selected.size > 0 && (
        <div className="flex items-center flex-wrap gap-2 mb-3 rounded-lg border border-border bg-muted/40 px-4 py-2">
          <span className="text-sm font-medium mr-1">{selected.size} selected</span>
          <span className="text-xs text-muted-foreground inline-flex items-center gap-1"><Sparkles className="w-3.5 h-3.5" /> AI generate:</span>
          <Button size="sm" className="h-7 text-xs gap-1.5" disabled={busy} onClick={() => runBulk(GEN_FIELDS.map((f) => f.key))}>
            <Sparkles className="w-3.5 h-3.5" /> Generate all
          </Button>
          {GEN_FIELDS.map((f) => (
            <Button key={f.key} variant="outline" size="sm" className="h-7 text-xs" disabled={busy} onClick={() => runBulk([f.key])}>
              {f.label}
            </Button>
          ))}
          {progress && (
            <span className="text-xs text-muted-foreground inline-flex items-center gap-1.5" aria-live="polite">
              <Loader2 className="w-3.5 h-3.5 animate-spin" /> Generating {progress.done}/{progress.total}…
            </span>
          )}
          <span className="mx-1 h-4 w-px bg-border" />
          <Button variant="ghost" size="sm" className="h-7 text-xs" disabled={busy} onClick={() => setSelected(new Set())}>
            Clear
          </Button>
          {isLocal && (
            <Button variant="ghost" size="sm" onClick={handleDelete} disabled={busy} className="gap-1.5 text-destructive">
              <Trash2 className="w-4 h-4" /> Trash
            </Button>
          )}
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
            className="table-fixed border-collapse text-xs bg-card
            [&_th]:border [&_th]:border-border/60 [&_td]:border [&_td]:border-border/60
            [&_th]:px-2 [&_th]:h-9 [&_th]:font-normal [&_th]:text-foreground/80
            [&_td]:px-2 [&_td]:h-9 [&_td]:py-0 [&_td]:align-middle
            [&_td]:whitespace-nowrap [&_td]:overflow-hidden
            [&_thead_th]:sticky [&_thead_th]:top-0 [&_thead_th]:z-20 [&_thead_th]:bg-card">

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
                <TableRow
                  key={row.id}
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
              ))}
            </TableBody>
          </Table>
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

      {/* Inline page preview — popup (not a new tab). */}
      {previewRow && previewRow.permalink && (
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
            <iframe src={previewRow.permalink} title="Page preview" className="h-full w-full flex-1 bg-white" />
          </div>
        </div>
      )}
      </>
      )}
        </div>
      </div>
    </div>
  );
}
