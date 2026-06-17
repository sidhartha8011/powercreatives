/**
 * ViewsToolbar — saved-Views controls for the SEO content table (top-left).
 *
 *  - "View" dropdown  : the saved-view collection. Pick one to apply (its column
 *                       visibility + filters reflect on the table); trash to delete;
 *                       "Default" resets to all columns / no filters.
 *  - "Columns" dropdown: show/hide each column, plus a "Save as view" footer that
 *                       captures the CURRENT columns + filters as a new named view.
 *
 * Presentational: the parent owns visibility/filter state and the save/apply/delete
 * handlers (see useViews + index.tsx).
 */

import { useState } from 'react';
import { Eye, Columns3, ChevronDown, Trash2, Check, Plus } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { SeoView } from './hooks/useViews';

interface ViewsToolbarProps {
  columns: { key: string; label: string }[];
  visible: Record<string, boolean>;
  onToggleColumn: (key: string) => void;
  views: SeoView[];
  appliedViewId: number | null;
  onApplyView: (view: SeoView) => void;
  onResetView: () => void;
  onSaveView: (name: string) => void;
  onDeleteView: (id: number) => void;
  /** Which control(s) to render — lets the View selector and Columns menu sit apart. */
  show?: 'all' | 'views' | 'columns';
}

export function ViewsToolbar({
  columns,
  visible,
  onToggleColumn,
  views,
  appliedViewId,
  onApplyView,
  onResetView,
  onSaveView,
  onDeleteView,
  show = 'all',
}: ViewsToolbarProps) {
  const [viewOpen, setViewOpen] = useState(false);
  const [colsOpen, setColsOpen] = useState(false);
  const [newName, setNewName] = useState('');

  const appliedName = views.find((v) => v.id === appliedViewId)?.name;
  const anyHidden = columns.some((c) => visible[c.key] === false);

  const save = () => {
    const name = newName.trim();
    if (!name) return;
    onSaveView(name);
    setNewName('');
    setColsOpen(false);
  };

  return (
    <div className="flex items-center gap-2">
      {/* View selector — saved views collection */}
      {show !== 'columns' && (
      <DropdownMenu open={viewOpen} onOpenChange={setViewOpen}>
        <DropdownMenuTrigger asChild>
          <Button variant="outline" size="sm" className={`h-8 gap-1.5 text-xs ${appliedName ? 'border-primary text-primary' : ''}`}>
            <Eye className="h-3.5 w-3.5" />
            <span className="max-w-[120px] truncate">{appliedName ?? 'View'}</span>
            <ChevronDown className="h-3 w-3 opacity-60" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start" className="w-56">
          <button
            type="button"
            onClick={() => { onResetView(); setViewOpen(false); }}
            className="flex w-full items-center gap-1.5 rounded px-2 py-1.5 text-left text-sm hover:bg-accent"
          >
            {appliedViewId === null ? <Check className="h-3.5 w-3.5 text-primary shrink-0" /> : <span className="w-3.5 shrink-0" />}
            Default (all columns)
          </button>
          <DropdownMenuSeparator />
          <DropdownMenuLabel className="text-xs text-muted-foreground">Saved views</DropdownMenuLabel>
          {views.length === 0 ? (
            <div className="px-2 py-1.5 text-xs text-muted-foreground/70">No saved views yet</div>
          ) : (
            views.map((v) => (
              <div key={v.id} className="flex items-center gap-1 pr-1">
                <button
                  type="button"
                  onClick={() => { onApplyView(v); setViewOpen(false); }}
                  className="flex flex-1 items-center gap-1.5 rounded px-2 py-1.5 text-left text-sm hover:bg-accent min-w-0"
                >
                  {appliedViewId === v.id ? <Check className="h-3.5 w-3.5 text-primary shrink-0" /> : <span className="w-3.5 shrink-0" />}
                  <span className="truncate">{v.name}</span>
                </button>
                <button
                  type="button"
                  title="Delete view"
                  onClick={() => onDeleteView(v.id)}
                  className="shrink-0 rounded p-1 text-muted-foreground hover:bg-accent hover:text-destructive"
                >
                  <Trash2 className="h-3.5 w-3.5" />
                </button>
              </div>
            ))
          )}
        </DropdownMenuContent>
      </DropdownMenu>
      )}

      {/* Columns show/hide + Save as view */}
      {show !== 'views' && (
      <DropdownMenu open={colsOpen} onOpenChange={setColsOpen}>
        <DropdownMenuTrigger asChild>
          <Button variant="outline" size="sm" className={`h-8 gap-1.5 text-xs ${anyHidden ? 'border-primary text-primary' : ''}`}>
            <Columns3 className="h-3.5 w-3.5" /> Columns <ChevronDown className="h-3 w-3 opacity-60" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align={show === 'columns' ? 'end' : 'start'} className="w-56">
          <DropdownMenuLabel>Toggle columns</DropdownMenuLabel>
          <DropdownMenuSeparator />
          {columns.map((c) => (
            <DropdownMenuCheckboxItem
              key={c.key}
              checked={visible[c.key] !== false}
              onCheckedChange={() => onToggleColumn(c.key)}
              onSelect={(e) => e.preventDefault()}
            >
              {c.label}
            </DropdownMenuCheckboxItem>
          ))}
          <DropdownMenuSeparator />
          <div className="flex items-center gap-1.5 px-2 py-1.5" onClick={(e) => e.stopPropagation()}>
            <Input
              value={newName}
              onChange={(e) => setNewName(e.target.value)}
              onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); save(); } }}
              placeholder="New view name…"
              className="h-7 text-xs"
            />
            <Button size="sm" className="h-7 gap-1 text-xs" disabled={!newName.trim()} onClick={save}>
              <Plus className="h-3.5 w-3.5" /> Save
            </Button>
          </div>
          <p className="px-2 pb-1.5 text-[10px] text-muted-foreground/70">Saves current columns + filters as a view.</p>
        </DropdownMenuContent>
      </DropdownMenu>
      )}
    </div>
  );
}
