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
import { Plus, Trash2, ExternalLink, Loader2, Search } from 'lucide-react';
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
import { SEO_TEXT_FIELDS, SEO_PLUGIN_LABELS, type SeoRow } from './types';

type TypeFilter = 'all' | 'post' | 'page';

/** Inline-editable text cell: click to edit, Enter/blur saves, Esc cancels. */
function EditableCell({
  value,
  placeholder,
  onSave,
}: {
  value: string;
  placeholder?: string;
  onSave: (next: string) => void;
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
    <button
      type="button"
      onClick={() => { setDraft(value); setEditing(true); }}
      className="w-full text-left truncate text-xs hover:underline decoration-dotted min-h-[1.25rem]"
      title={value || placeholder}
    >
      {value || <span className="text-muted-foreground/60">{placeholder ?? '—'}</span>}
    </button>
  );
}

export function SEOModule() {
  const { rows, options, isLoading, saveCell, quickCreate, bulkDelete } = useSeoContent();
  const [typeFilter, setTypeFilter] = useState<TypeFilter>('all');
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [busy, setBusy] = useState(false);

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
          <div className="flex items-center gap-2">
            <Button variant="outline" onClick={() => handleCreate('post')} disabled={busy} className="gap-1.5">
              <Plus className="w-4 h-4" /> Post
            </Button>
            <Button variant="outline" onClick={() => handleCreate('page')} disabled={busy} className="gap-1.5">
              <Plus className="w-4 h-4" /> Page
            </Button>
          </div>
        }
      />

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

      {selected.size > 0 && (
        <div className="flex items-center gap-3 mb-3 rounded-lg border border-border bg-muted/40 px-4 py-2">
          <span className="text-sm font-medium">{selected.size} selected</span>
          <Button variant="ghost" size="sm" onClick={handleDelete} disabled={busy} className="gap-1.5 text-destructive">
            {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <Trash2 className="w-4 h-4" />} Move to Trash
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
          <Table>
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
                <SortableTableHead columnKey="type" label="Type" currentSortKey={sortKey} currentSortDir={sortDir} onToggle={toggleSort} style={{ width: '6%' }} />
                <SortableTableHead columnKey="title" label="Title" currentSortKey={sortKey} currentSortDir={sortDir} onToggle={toggleSort} style={{ width: '16%' }} />
                <SortableTableHead columnKey="status" label="Status" currentSortKey={sortKey} currentSortDir={sortDir} onToggle={toggleSort} style={{ width: '9%' }} />
                {SEO_TEXT_FIELDS.map((f) => (
                  <TableHead key={f.key} style={{ width: f.width }}>{f.label}</TableHead>
                ))}
                <TableHead style={{ width: '8%' }}>Author</TableHead>
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
                    <EditableCell value={row.title} placeholder="Untitled" onSave={(v) => saveCell(row.id, 'title', v)} />
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
                  {SEO_TEXT_FIELDS.map((f) => (
                    <TableCell key={f.key}>
                      <EditableCell
                        value={String(row[f.key] ?? '')}
                        placeholder={f.label}
                        onSave={(v) => saveCell(row.id, f.key, v)}
                      />
                    </TableCell>
                  ))}
                  <TableCell className="text-xs text-muted-foreground truncate">{row.author}</TableCell>
                  <TableCell className="text-center">
                    {row.permalink && (
                      <a href={row.permalink} target="_blank" rel="noopener noreferrer" className="inline-flex text-muted-foreground hover:text-foreground" title="View page">
                        <ExternalLink className="w-3.5 h-3.5" />
                      </a>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}
    </div>
  );
}
