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
import { Eye, Columns3, ChevronDown, Trash2, Check, Plus, Star, RotateCcw, Pencil, Pin } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuItem,
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
  onRenameView: (id: number, name: string) => void;
  onPinView: (id: number, isPinned: boolean) => void;
  /** Toggle a view as the user's default (auto-applied when the table loads). */
  onSetDefaultView: (id: number, isDefault: boolean) => void;
  /** Reset column widths + order back to defaults (spreadsheet layout). */
  onResetLayout?: () => void;
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
  onRenameView,
  onPinView,
  onSetDefaultView,
  onResetLayout,
  show = 'all',
}: ViewsToolbarProps) {
  const [viewOpen, setViewOpen] = useState(false);
  const [colsOpen, setColsOpen] = useState(false);
  const [newName, setNewName] = useState('');
  const [renamingId, setRenamingId] = useState<number | null>(null);
  const [renameValue, setRenameValue] = useState('');

  /** Commit an inline rename. No-ops on an empty name or when nothing actually changed, so
   *  blurring the field (which also commits) can never wipe a view's name or fire a pointless
   *  request. Always leaves edit mode. */
  const commitRename = (id: number) => {
    const next = renameValue.trim();
    const prev = views.find((v) => v.id === id)?.name ?? '';
    if (next && next !== prev) onRenameView(id, next);
    setRenamingId(null);
  };

  const appliedName = views.find((v) => v.id === appliedViewId)?.name;

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
          {/* Pill radius + neutral colours, matching the AI/model button and the PillButtons
              beside it. The applied/hidden state used to tint the trigger `border-primary
              text-primary`; the label already says which view is applied, so the colour was
              redundant emphasis that made this control louder than its neighbours. */}
          <Button variant="outline" size="sm" className="h-8 gap-1.5 rounded-full bg-card text-xs">
            <Eye className="h-3.5 w-3.5" />
            <span className="max-w-[120px] truncate">{appliedName ?? 'View'}</span>
            <ChevronDown className="h-3 w-3 opacity-60" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start" className="w-56">
          <DropdownMenuItem onSelect={() => { onResetView(); setViewOpen(false); }} className="gap-1.5">
            {appliedViewId === null ? <Check className="h-3.5 w-3.5 text-primary shrink-0" /> : <span className="w-3.5 shrink-0" />}
            Default (all columns)
          </DropdownMenuItem>
          <DropdownMenuSeparator />
          <DropdownMenuLabel className="text-xs text-muted-foreground">Saved views</DropdownMenuLabel>
          {views.length === 0 ? (
            <div className="px-2 py-1.5 text-xs text-muted-foreground/70">No saved views yet</div>
          ) : (
            views.map((v) => (
              <div key={v.id} className="flex items-center gap-1 pr-1">
                {renamingId === v.id ? (
                  // Inline rename, in place of the row label. Keystrokes are stopped for the
                  // same reason as the "new view name" field: Radix menu TYPEAHEAD would
                  // otherwise steal focus and jump to a matching item on every letter.
                  <Input
                    autoFocus
                    value={renameValue}
                    onChange={(e) => setRenameValue(e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter') { e.preventDefault(); commitRename(v.id); return; }
                      if (e.key === 'Escape') { e.preventDefault(); setRenamingId(null); return; }
                      e.stopPropagation();
                    }}
                    onBlur={() => commitRename(v.id)}
                    className="h-7 flex-1 text-sm"
                  />
                ) : (
                <DropdownMenuItem
                  onSelect={() => { onApplyView(v); setViewOpen(false); }}
                  className="flex-1 gap-1.5 min-w-0"
                >
                  {appliedViewId === v.id ? <Check className="h-3.5 w-3.5 text-primary shrink-0" /> : <span className="w-3.5 shrink-0" />}
                  <span className="truncate">{v.name}</span>
                  {v.isDefault && <span className="ml-auto shrink-0 text-[10px] text-muted-foreground">Default</span>}
                </DropdownMenuItem>
                )}
                <button
                  type="button"
                  title={v.isPinned ? 'Unpin from the tab strip' : 'Pin as a tab above the table'}
                  aria-pressed={v.isPinned}
                  onClick={() => onPinView(v.id, !v.isPinned)}
                  className={`shrink-0 rounded p-1 hover:bg-accent ${v.isPinned ? 'text-primary' : 'text-muted-foreground hover:text-foreground'}`}
                >
                  <Pin className={`h-3.5 w-3.5 ${v.isPinned ? 'fill-current' : ''}`} />
                </button>
                <button
                  type="button"
                  title="Rename view"
                  onClick={() => { setRenamingId(v.id); setRenameValue(v.name); }}
                  className="shrink-0 rounded p-1 text-muted-foreground hover:bg-accent hover:text-foreground"
                >
                  <Pencil className="h-3.5 w-3.5" />
                </button>
                <button
                  type="button"
                  title={v.isDefault ? 'Remove as default view' : 'Make default view'}
                  aria-pressed={v.isDefault}
                  onClick={() => onSetDefaultView(v.id, !v.isDefault)}
                  className={`shrink-0 rounded p-1 hover:bg-accent ${v.isDefault ? 'text-primary' : 'text-muted-foreground hover:text-foreground'}`}
                >
                  <Star className={`h-3.5 w-3.5 ${v.isDefault ? 'fill-current' : ''}`} />
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
          <Button variant="outline" size="sm" className="h-8 gap-1.5 rounded-full bg-card text-xs">
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
              // Keystrokes must NOT reach DropdownMenuContent. Radix menus implement
              // TYPEAHEAD on keydown: an unhandled letter moves focus to the menu item
              // starting with it — which here yanked focus out of this input and toggled
              // a column checkbox on every character. That is the "type one letter, then
              // wait" behaviour. The existing onClick stopPropagation could never help,
              // because typeahead is keydown-driven, not click-driven.
              // Escape is deliberately allowed through so it still closes the menu.
              onKeyDown={(e) => {
                if (e.key === 'Enter') { e.preventDefault(); save(); return; }
                if (e.key === 'Escape') { return; }
                e.stopPropagation();
              }}
              placeholder="New view name…"
              className="h-8 text-sm"
            />
            <Button size="sm" className="h-8 gap-1" disabled={!newName.trim()} onClick={save}>
              <Plus className="h-3.5 w-3.5" /> Save
            </Button>
          </div>
          <p className="px-2 pb-1.5 text-[10px] text-muted-foreground/70">Saves current columns + filters as a view.</p>
          {onResetLayout && (
            <>
              <DropdownMenuSeparator />
              <DropdownMenuItem
                onSelect={() => { onResetLayout(); setColsOpen(false); }}
                className="gap-1.5 text-muted-foreground"
              >
                <RotateCcw className="h-3.5 w-3.5" /> Reset column sizes &amp; order
              </DropdownMenuItem>
            </>
          )}
        </DropdownMenuContent>
      </DropdownMenu>
      )}
    </div>
  );
}
