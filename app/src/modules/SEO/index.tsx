/**
 * SEO Module — content-SEO workbench.
 *
 * A live table of the WordPress site's posts & pages with inline editing of
 * SEO meta (title, description, keywords) that read/write through whichever
 * SEO plugin is active (Yoast / Rank Math / SEOPress) plus an internal
 * backup, sortable columns, bulk delete, and quick-create. Backed by the
 * `pcm/v1/seo` REST module.
 */

import { useMemo, useState, useCallback, type KeyboardEvent } from 'react';
import { Plus, Trash2, ExternalLink, Loader2, Search, Sparkles, Check, X } from 'lucide-react';
import { toast } from 'sonner';

import { ModuleHeader } from '@/components/shared/ModuleHeader';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { SortableTableHead } from '@/components/ui/sortable-table-head';
import { useSortableTable } from '@/hooks/useSortableTable';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';

import { useSeoContent } from './hooks/useSeoContent';
import { AIReadinessPanel } from './AIReadinessPanel';
import { SiteSettingsPanel } from './SiteSettingsPanel';
import { BusinessPanel } from './BusinessPanel';
import { HubPanel } from './HubPanel';
import { SchemaCell } from './SchemaCell';
import { OptimizeModal } from './OptimizeModal';
import { SEO_TEXT_FIELDS, SEO_PLUGIN_LABELS, type SeoRow } from './types';

type TypeFilter = 'all' | 'post' | 'page';

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
}: {
  value: string;
  placeholder?: string;
  onSave: (next: string) => void;
  onGenerate?: () => void;
  generating?: boolean;
  suggestion?: string | null;
  onAccept?: () => void;
  onReject?: () => void;
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
      <div className="space-y-1 rounded-md bg-blue-50/70 border border-blue-200 p-1.5">
        <div className="text-xs text-blue-900 break-words" title={suggestion}>{suggestion}</div>
        <div className="flex items-center gap-1">
          <button type="button" onClick={onAccept} title="Accept" className="inline-flex items-center gap-0.5 rounded bg-green-600 px-1.5 py-0.5 text-[10px] font-medium text-white hover:bg-green-700">
            <Check className="w-3 h-3" /> Accept
          </button>
          <button type="button" onClick={onReject} title="Reject" className="inline-flex items-center gap-0.5 rounded border border-border px-1.5 py-0.5 text-[10px] text-muted-foreground hover:bg-muted">
            <X className="w-3 h-3" /> Reject
          </button>
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
    <div className="flex items-start gap-1 group">
      <button
        type="button"
        onClick={() => { setDraft(value); setEditing(true); }}
        className="flex-1 min-w-0 text-left whitespace-normal break-words text-xs leading-snug hover:underline decoration-dotted min-h-[1.25rem]"
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

/** Cell fields that support AI generation. */
const GENERATABLE = new Set(['title', 'metaTitle', 'metaDescription', 'metaKeywords']);
/** Generatable fields for the bulk toolbar (key → short label). */
const GEN_FIELDS: { key: string; label: string }[] = [
  { key: 'title', label: 'Title' },
  { key: 'metaTitle', label: 'Meta Title' },
  { key: 'metaDescription', label: 'Meta Desc' },
  { key: 'metaKeywords', label: 'Keywords' },
];

export function SEOModule() {
  const { rows, options, isLoading, saveCell, quickCreate, bulkDelete, generateField } = useSeoContent();
  const [typeFilter, setTypeFilter] = useState<TypeFilter>('all');
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [busy, setBusy] = useState(false);
  const [tab, setTab] = useState<'content' | 'air' | 'site' | 'business' | 'hub'>('content');
  // Optimistic per-row schema-type overrides (SchemaCell persists via REST).
  const [schemaOverrides, setSchemaOverrides] = useState<Record<number, string[]>>({});
  const [optimizeRow, setOptimizeRow] = useState<SeoRow | null>(null);
  // AI staging: suggestions keyed `${id}:${field}`, plus the in-flight key.
  const [staged, setStaged] = useState<Record<string, string>>({});
  const [genKey, setGenKey] = useState<string | null>(null);

  const handleGenerate = useCallback(async (id: number, field: string) => {
    const key = `${id}:${field}`;
    setGenKey(key);
    try {
      const value = await generateField(id, field);
      setStaged((s) => ({ ...s, [key]: value }));
    } catch { /* toast in hook */ } finally {
      setGenKey(null);
    }
  }, [generateField]);

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

  // Bulk AI: generate one field for every selected row (sequential — gentle on
  // the provider), staging each result for review.
  const bulkGenerate = useCallback(async (field: string) => {
    const ids = Array.from(selected);
    if (ids.length === 0) return;
    setBusy(true);
    for (const id of ids) {
      const key = `${id}:${field}`;
      setGenKey(key);
      try {
        const value = await generateField(id, field);
        setStaged((s) => ({ ...s, [key]: value }));
      } catch { /* toast in hook */ }
    }
    setGenKey(null);
    setBusy(false);
  }, [selected, generateField]);

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

  const filtered = useMemo(
    () => (typeFilter === 'all' ? rows : rows.filter((r) => r.type === typeFilter)),
    [rows, typeFilter],
  );

  const { sortKey, sortDir, toggleSort, sortedData } = useSortableTable<SeoRow, 'title' | 'type' | 'status' | 'date'>(
    filtered,
    {
      defaultKey: 'date',
      defaultDir: 'desc',
      accessors: {
        title: (r) => r.title.toLowerCase(),
        type: (r) => r.type,
        status: (r) => r.status,
        date: (r) => new Date(r.date).getTime(),
      },
    },
  );

  const visibleIds = sortedData.map((r) => r.id);
  const allSelected = visibleIds.length > 0 && visibleIds.every((id) => selected.has(id));
  const someSelected = selected.size > 0 && !allSelected;

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

  const pluginLabel = options ? (SEO_PLUGIN_LABELS[options.seoPlugin] ?? options.seoPlugin) : '';

  return (
    <div className="module-container animate-fade-in">
      <ModuleHeader
        title="SEO"
        description="Optimize the SEO meta of your site's posts and pages — inline, across Yoast / Rank Math / SEOPress."
        action={
          tab === 'content' ? (
            <div className="flex items-center gap-2">
              <Button variant="outline" onClick={() => handleCreate('post')} disabled={busy} className="gap-1.5">
                <Plus className="w-4 h-4" /> Post
              </Button>
              <Button variant="outline" onClick={() => handleCreate('page')} disabled={busy} className="gap-1.5">
                <Plus className="w-4 h-4" /> Page
              </Button>
            </div>
          ) : undefined
        }
      />

      {/* Tabs: Content / AI Readiness / Site */}
      <div className="flex gap-1 mb-4 border-b border-border">
        {([['content', 'Content'], ['air', 'AI Readiness'], ['site', 'Site'], ['business', 'Business'], ['hub', 'Hub']] as const).map(([id, label]) => (
          <button
            key={id}
            type="button"
            onClick={() => setTab(id)}
            className={`px-3 py-1.5 text-sm -mb-px border-b-2 ${tab === id ? 'border-primary text-primary font-medium' : 'border-transparent text-muted-foreground hover:text-foreground'}`}
          >
            {label}
          </button>
        ))}
      </div>

      {tab === 'air' ? (
        <AIReadinessPanel />
      ) : tab === 'site' ? (
        <SiteSettingsPanel />
      ) : tab === 'business' ? (
        <BusinessPanel />
      ) : tab === 'hub' ? (
        <HubPanel />
      ) : (
      <>
      {/* Toolbar: type filter + detected SEO plugin */}
      <div className="flex items-center justify-between gap-4 mb-4">
        <Select value={typeFilter} onValueChange={(v) => setTypeFilter(v as TypeFilter)}>
          <SelectTrigger className="h-8 w-[160px] text-xs">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All content</SelectItem>
            <SelectItem value="post">Posts</SelectItem>
            <SelectItem value="page">Pages</SelectItem>
          </SelectContent>
        </Select>
        {pluginLabel && (
          <span className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
            <Search className="w-3.5 h-3.5" /> SEO source: <span className="font-medium text-foreground">{pluginLabel}</span>
          </span>
        )}
      </div>

      {/* Bulk actions bar — generate any field across the selected rows. */}
      {selected.size > 0 && (
        <div className="flex items-center flex-wrap gap-2 mb-3 rounded-lg border border-border bg-muted/40 px-4 py-2">
          <span className="text-sm font-medium mr-1">{selected.size} selected</span>
          <span className="text-xs text-muted-foreground inline-flex items-center gap-1"><Sparkles className="w-3.5 h-3.5" /> AI generate:</span>
          {GEN_FIELDS.map((f) => (
            <Button key={f.key} variant="outline" size="sm" className="h-7 text-xs" disabled={busy} onClick={() => bulkGenerate(f.key)}>
              {f.label}
            </Button>
          ))}
          <span className="mx-1 h-4 w-px bg-border" />
          <Button variant="ghost" size="sm" onClick={handleDelete} disabled={busy} className="gap-1.5 text-destructive">
            <Trash2 className="w-4 h-4" /> Trash
          </Button>
        </div>
      )}

      {/* Pending AI suggestions — accept/discard everything at once. */}
      {pendingCount > 0 && (
        <div className="flex items-center flex-wrap gap-2 mb-3 rounded-lg border border-blue-200 bg-blue-50/60 px-4 py-2">
          <span className="text-sm font-medium text-blue-900 inline-flex items-center gap-1.5">
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
        <div className="card-powerkeys overflow-hidden">
          {/* table-fixed pins columns to their % widths; the [&_td] overrides
              defeat shadcn's default `whitespace-nowrap` on cells so long
              values WRAP onto multiple lines within the row instead of
              widening the table. */}
          <Table className="table-fixed w-full [&_td]:align-top [&_td]:whitespace-normal [&_td]:break-words">

            <TableHeader>
              <TableRow className="bg-muted/60">
                <TableHead style={{ width: '3%' }} className="px-2">
                  <Checkbox
                    checked={allSelected}
                    onCheckedChange={toggleAll}
                    aria-label="Select all"
                    {...(someSelected ? { 'data-state': 'indeterminate' as const } : {})}
                  />
                </TableHead>
                <SortableTableHead columnKey="type" label="Type" currentSortKey={sortKey} currentSortDir={sortDir} onToggle={toggleSort} style={{ width: '5%' }} />
                <SortableTableHead columnKey="title" label="Title" currentSortKey={sortKey} currentSortDir={sortDir} onToggle={toggleSort} style={{ width: '14%' }} />
                <SortableTableHead columnKey="status" label="Status" currentSortKey={sortKey} currentSortDir={sortDir} onToggle={toggleSort} style={{ width: '8%' }} />
                {SEO_TEXT_FIELDS.map((f) => (
                  <TableHead key={f.key} style={{ width: f.width }}>{f.label}</TableHead>
                ))}
                <TableHead style={{ width: '9%' }}>Schema</TableHead>
                <TableHead style={{ width: '6%' }}>Author</TableHead>
                <TableHead style={{ width: '4%' }} className="text-center">Open</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {sortedData.map((row) => (
                <TableRow key={row.id}>
                  <TableCell className="px-2">
                    <Checkbox
                      checked={selected.has(row.id)}
                      onCheckedChange={() => toggleOne(row.id)}
                      aria-label={`Select ${row.title}`}
                    />
                  </TableCell>
                  <TableCell className="text-xs capitalize text-muted-foreground">{row.type}</TableCell>
                  <TableCell>
                    <EditableCell
                      value={row.title}
                      placeholder="Untitled"
                      onSave={(v) => saveCell(row.id, 'title', v)}
                      onGenerate={() => handleGenerate(row.id, 'title')}
                      generating={genKey === `${row.id}:title`}
                      suggestion={staged[`${row.id}:title`] ?? null}
                      onAccept={() => acceptStaged(row.id, 'title')}
                      onReject={() => rejectStaged(row.id, 'title')}
                    />
                  </TableCell>
                  <TableCell>
                    <Select value={row.status} onValueChange={(v) => saveCell(row.id, 'status', v)}>
                      <SelectTrigger className="h-7 text-xs"><SelectValue /></SelectTrigger>
                      <SelectContent>
                        {(options?.statuses ?? ['publish', 'draft', 'pending', 'private', 'future']).map((s) => (
                          <SelectItem key={s} value={s} className="text-xs capitalize">{s}</SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </TableCell>
                  {SEO_TEXT_FIELDS.map((f) => {
                    const canGen = GENERATABLE.has(f.key);
                    const key = `${row.id}:${f.key}`;
                    return (
                      <TableCell key={f.key}>
                        <EditableCell
                          value={String(row[f.key] ?? '')}
                          placeholder={f.label}
                          onSave={(v) => saveCell(row.id, f.key, v)}
                          onGenerate={canGen ? () => handleGenerate(row.id, f.key) : undefined}
                          generating={genKey === key}
                          suggestion={canGen ? (staged[key] ?? null) : null}
                          onAccept={() => acceptStaged(row.id, f.key)}
                          onReject={() => rejectStaged(row.id, f.key)}
                        />
                      </TableCell>
                    );
                  })}
                  <TableCell>
                    <SchemaCell
                      postId={row.id}
                      types={schemaOverrides[row.id] ?? row.schemaTypes ?? []}
                      onChange={(next) => setSchemaOverrides((o) => ({ ...o, [row.id]: next }))}
                    />
                  </TableCell>
                  <TableCell className="text-xs text-muted-foreground">{row.author}</TableCell>
                  <TableCell className="text-center">
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
      </>
      )}
    </div>
  );
}
