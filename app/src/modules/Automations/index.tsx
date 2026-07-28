import { useState, useMemo, useCallback } from 'react';
import { Plus, Trash2, Workflow, Zap, ArrowRight, Loader2, Check, ChevronsUpDown } from 'lucide-react';
import { toast } from 'sonner';

import { trpc } from '@/lib/trpc';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Spinner } from '@/components/ui/spinner';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { SortableTableHead } from '@/components/ui/sortable-table-head';
import { useSortableTable } from '@/hooks/useSortableTable';
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog';
import {
  Select, SelectTrigger, SelectValue, SelectContent, SelectItem,
} from '@/components/ui/select';
import { cn, copyToClipboard } from '@/lib/utils';
import {
  Command, CommandInput, CommandList, CommandEmpty, CommandGroup, CommandItem,
} from '@/components/ui/command';

/* ── Searchable Combobox (module-grouped, "coming soon" aware) ───────────────
 * Reusable for both the trigger and action dropdowns. Keeps grouping by
 * `module`, disables `implemented:false` entries, and supports filter-as-you-type
 * via shadcn Command (cmdk).
 */
interface ComboItem { id: string; module: string; label: string; description?: string; implemented?: boolean }
function ItemCombobox({
  items, value, onChange, placeholder, emptyText,
}: {
  items: ComboItem[]; value: string; onChange: (id: string) => void;
  placeholder: string; emptyText: string;
}) {
  const [open, setOpen] = useState(false);
  const selected = items.find((i) => i.id === value);
  const groups = useMemo(() => {
    const m: Record<string, ComboItem[]> = {};
    items.forEach((it) => { (m[it.module] ||= []).push(it); });
    return Object.entries(m);
  }, [items]);

  return (
    <>
      <Button
        type="button"
        variant="outline"
        role="combobox"
        aria-haspopup="dialog"
        aria-expanded={open}
        className="w-full justify-between font-normal"
        onClick={() => setOpen(true)}
      >
        <span className="truncate text-left">
          {selected ? selected.label : <span className="text-muted-foreground">{placeholder}</span>}
        </span>
        <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
      </Button>
      {/* A DIALOG, not a popover. As a popover this list had to be rendered WITHOUT
          a Portal — Radix Dialog's react-remove-scroll lock kills wheel scrolling on
          any portalled node outside the dialog subtree — which left it inline inside
          the scrollable form, where it was clipped by the form's overflow and needed
          an --radix-popover-content-available-height cap to half-cope. A nested
          Dialog portals cleanly, owns its own scroll lock, and is bounded by the
          VIEWPORT rather than the parent form, so the full list is always reachable
          and the trigger descriptions get room to breathe. */}
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="sm:max-w-[min(44rem,calc(100vw-4rem))] max-h-[80vh] flex flex-col overflow-hidden">
          <DialogHeader className="shrink-0">
            <DialogTitle>{placeholder}</DialogTitle>
            <DialogDescription>Search, then pick one. Grouped by module.</DialogDescription>
          </DialogHeader>
          <Command
          className="flex min-h-0 flex-1 flex-col"
          filter={(itemValue, search) => {
            // itemValue is the CommandItem `value` (we pack module+id+label there).
            return itemValue.toLowerCase().includes(search.toLowerCase()) ? 1 : 0;
          }}
        >
          <CommandInput placeholder="Search…" />
          {/* No max-h here any more: inside the dialog the height is bounded by the
              dialog's own max-h-[80vh], so the list should FILL what is available
              rather than stop at the old 18rem popover cap. */}
          {/* px-2 keeps rows off the border and the scrollbar; the group rules below
              give each module its own block instead of one undifferentiated column. */}
          <CommandList className="min-h-0 flex-1 overflow-y-auto px-2 py-2">
            <CommandEmpty>{emptyText}</CommandEmpty>
            {groups.map(([mod, list]) => (
              <CommandGroup
                key={mod}
                heading={mod}
                // shadcn's defaults (group p-1, heading py-1.5) are tuned for a
                // ONE-LINE command palette. These rows are two lines, so the whole
                // list read as a wall of text. Space the heading and separate groups.
                className="p-0 pb-2 [&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:pt-2 [&_[cmdk-group-heading]]:pb-1.5 [&_[cmdk-group-heading]]:uppercase [&_[cmdk-group-heading]]:tracking-wide"
              >
                {list.map((it) => {
                  const disabled = it.implemented === false;
                  return (
                    <CommandItem
                      key={it.id}
                      // include module + id + label so search matches across them
                      value={`${it.module} ${it.id} ${it.label}`}
                      disabled={disabled}
                      onSelect={() => { if (!disabled) { onChange(it.id); setOpen(false); } }}
                      // items-start so the check aligns with the LABEL, not the
                      // vertical centre of a two-line row; px-3 py-2.5 + rounded-md
                      // gives each row real breathing room and a proper hit target.
                      className={`items-start gap-3 rounded-md px-3 py-2.5 ${disabled ? 'opacity-50' : ''}`}
                    >
                      <Check className={`mt-0.5 h-4 w-4 shrink-0 ${value === it.id ? 'opacity-100' : 'opacity-0'}`} />
                      {/* Wrap instead of truncate: the description explains WHEN the
                          trigger fires — clipping it hid the one thing the user needs. */}
                      <div className="min-w-0 flex-1">
                        <div className="text-pretty font-medium">
                          {it.label}{disabled ? <span className="ml-1 text-[10px] uppercase tracking-wide text-muted-foreground">· coming soon</span> : null}
                        </div>
                        {it.description && (
                          <div className="mt-0.5 text-pretty text-xs leading-relaxed text-muted-foreground">{it.description}</div>
                        )}
                      </div>
                    </CommandItem>
                  );
                })}
              </CommandGroup>
            ))}
          </CommandList>
          </Command>
        </DialogContent>
      </Dialog>
    </>
  );
}

/* ── Types (catalog is dynamic, kept loose like other modules) ── */
interface ConditionField { key: string; label: string; type: string; options?: { value: string; label: string }[]; default?: string; }
interface ConfigField { key: string; label: string; type: string; required?: boolean; placeholder?: string; options?: { value: string; label: string }[]; default?: string; }
interface InputField { key: string; label: string; }
interface TriggerDef { id: string; module: string; label: string; description?: string; implemented?: boolean; conditionFields?: ConditionField[]; contextKeys?: string[]; }
interface ActionDef { id: string; module: string; label: string; description?: string; implemented?: boolean; configFields?: ConfigField[]; inputSchema?: InputField[]; }
interface AutomationRule {
  id: number; name?: string | null; triggerId: string; actionId: string;
  conditions: Record<string, string>; config: Record<string, string>;
  inputMapping?: Record<string, string>; isActive: boolean;
}

export function AutomationsModule() {
  const { data: catalogRaw, isLoading: catalogLoading } = trpc.automations.catalog.useQuery() as any;
  const { data: listRaw, isLoading: listLoading, refetch } = trpc.automations.list.useQuery() as any;

  const triggers: TriggerDef[] = Array.isArray(catalogRaw?.triggers) ? catalogRaw.triggers : [];
  const actions: ActionDef[] = Array.isArray(catalogRaw?.actions) ? catalogRaw.actions : [];
  const rules: AutomationRule[] = Array.isArray(listRaw) ? listRaw : [];

  const [dialogOpen, setDialogOpen] = useState(false);
  // null = creating a new rule; a number = editing that rule's id.
  const [editingId, setEditingId] = useState<number | null>(null);

  /* ── Create/edit form state ── */
  const [name, setName] = useState('');
  const [triggerId, setTriggerId] = useState('');
  const [conditions, setConditions] = useState<Record<string, string>>({});
  const [actionId, setActionId] = useState('');
  const [config, setConfig] = useState<Record<string, string>>({});
  // Payload properties as ORDERED rows (not a Record) so renaming a property
  // mid-typing doesn't collapse or reorder entries. Users can rename, remove,
  // and add properties freely — the action's inputSchema only seeds starters.
  const [mappingRows, setMappingRows] = useState<{ key: string; value: string }[]>([]);

  const selectedTrigger = useMemo(() => triggers.find((t) => t.id === triggerId), [triggers, triggerId]);
  const selectedAction = useMemo(() => actions.find((a) => a.id === actionId), [actions, actionId]);

  // Click-to-copy for the available-variable chips. copyToClipboard() falls back
  // to execCommand, which matters here: the SPA runs inside wp-admin and the
  // async Clipboard API is unavailable on plain-HTTP installs.
  const [copiedToken, setCopiedToken] = useState<string | null>(null);
  const copyToken = useCallback(async (token: string) => {
    const ok = await copyToClipboard(token);
    if (!ok) {
      toast.error('Could not copy to clipboard');
      return;
    }
    setCopiedToken(token);
    toast.success(`Copied ${token}`);
    setTimeout(() => setCopiedToken((cur) => (cur === token ? null : cur)), 1500);
  }, []);

  // Suggested starter rows for an action: its inputSchema keys, blank values.
  const seedRows = (action?: ActionDef): { key: string; value: string }[] =>
    (action?.inputSchema ?? []).map((f) => ({ key: f.key, value: '' }));

  // The ItemCombobox (above) groups items by `module` internally; no extra memo
  // needed here. Keeping this comment as a breadcrumb for the previous Select-
  // based implementation that did grouping inline.

  const resetForm = useCallback(() => {
    // Default to the first *implemented* trigger/action (skip "coming soon").
    const firstTrigger = triggers.find((t) => t.implemented !== false) ?? triggers[0];
    const firstAction = actions.find((a) => a.implemented !== false) ?? actions[0];
    setName('');
    setTriggerId(firstTrigger?.id ?? '');
    setActionId(firstAction?.id ?? '');
    const cond: Record<string, string> = {};
    (firstTrigger?.conditionFields ?? []).forEach((f) => { cond[f.key] = f.default ?? ''; });
    setConditions(cond);
    setConfig({});
    setMappingRows(seedRows(firstAction));
  }, [triggers, actions]);

  // Open the dialog to create a new rule (blank, sensible defaults).
  const openCreate = useCallback(() => {
    setEditingId(null);
    resetForm();
    setDialogOpen(true);
  }, [resetForm]);

  // Open the dialog to edit an existing rule (pre-filled with its values).
  const openEdit = useCallback((rule: AutomationRule) => {
    setEditingId(rule.id);
    setName(rule.name ?? '');
    setTriggerId(rule.triggerId);
    setActionId(rule.actionId);
    setConditions({ ...(rule.conditions ?? {}) });
    setConfig({ ...(rule.config ?? {}) });
    const saved = Object.entries(rule.inputMapping ?? {}).map(([key, value]) => ({ key, value }));
    setMappingRows(saved.length > 0 ? saved : seedRows(actions.find((a) => a.id === rule.actionId)));
    setDialogOpen(true);
  }, [actions]);

  // When the user changes the trigger, reseed its condition defaults.
  const onTriggerChange = useCallback((id: string) => {
    setTriggerId(id);
    const t = triggers.find((x) => x.id === id);
    const cond: Record<string, string> = {};
    (t?.conditionFields ?? []).forEach((f) => { cond[f.key] = f.default ?? ''; });
    setConditions(cond);
  }, [triggers]);

  // When the user changes the action, reseed its config defaults (e.g. the
  // move_to_lane action's lane select defaults to 'client').
  const onActionChange = useCallback((id: string) => {
    setActionId(id);
    const a = actions.find((x) => x.id === id);
    const cfg: Record<string, string> = {};
    (a?.configFields ?? []).forEach((f) => { cfg[f.key] = f.default ?? ''; });
    setConfig(cfg);
    setMappingRows(seedRows(a));
  }, [actions]);

  const createMutation = trpc.automations.create.useMutation({
    onSuccess: () => { toast.success('Automation created'); setDialogOpen(false); refetch(); },
    onError: (err: any) => toast.error(err.message ?? 'Failed to create automation'),
  }) as any;

  const deleteMutation = trpc.automations.delete.useMutation({
    onSuccess: () => { toast.success('Automation deleted'); refetch(); },
    onError: (err: any) => toast.error(err.message ?? 'Failed to delete automation'),
  }) as any;

  const updateMutation = trpc.automations.update.useMutation({
    onSuccess: () => refetch(),
    onError: (err: any) => toast.error(err.message ?? 'Failed to update automation'),
  }) as any;

  const handleSave = useCallback(() => {
    if (!triggerId || !actionId) { toast.error('Pick a trigger and an action'); return; }
    // Validate required action config fields.
    for (const f of selectedAction?.configFields ?? []) {
      if (f.required && !(config[f.key] ?? '').trim()) {
        toast.error(`${f.label} is required`);
        return;
      }
    }
    // Only send rows with both a property name and a value — blank rows fall
    // back to the action handler's default payload (e.g. webhook = name + link).
    const inputMapping: Record<string, string> = {};
    for (const row of mappingRows) {
      const key = row.key.trim();
      const value = row.value.trim();
      if (!key || !value) continue;
      if (key in inputMapping) {
        toast.error(`Duplicate payload property "${key}"`);
        return;
      }
      inputMapping[key] = value;
    }

    const payload = { name: name.trim() || undefined, triggerId, conditions, actionId, config, inputMapping };

    if (editingId != null) {
      updateMutation.mutate({ id: editingId, ...payload }, {
        onSuccess: () => { toast.success('Automation updated'); setDialogOpen(false); setEditingId(null); refetch(); },
      });
    } else {
      createMutation.mutate({ ...payload, isActive: true });
    }
  }, [editingId, name, triggerId, conditions, actionId, config, mappingRows, selectedAction, createMutation, updateMutation, refetch]);

  const toggleActive = useCallback((rule: AutomationRule) => {
    updateMutation.mutate({ id: rule.id, isActive: !rule.isActive });
  }, [updateMutation]);

  /* ── Human-readable summary of a saved rule ── */
  const describeRule = useCallback((rule: AutomationRule) => {
    const t = triggers.find((x) => x.id === rule.triggerId);
    const a = actions.find((x) => x.id === rule.actionId);
    const condText = Object.entries(rule.conditions || {})
      .filter(([, v]) => v !== '' && v != null)
      .map(([k, v]) => {
        const field = t?.conditionFields?.find((f) => f.key === k);
        const optLabel = field?.options?.find((o) => o.value === v)?.label ?? v;
        return `${field?.label ?? k} = ${optLabel}`;
      })
      .join(', ');
    return {
      triggerLabel: t?.label ?? rule.triggerId,
      actionLabel: a?.label ?? rule.actionId,
      condText,
      target: rule.config?.url ?? '',
    };
  }, [triggers, actions]);

  /* ── Derived rows for the sortable table ── */
  const tableRows = useMemo(
    () =>
      rules.map((rule) => {
        const d = describeRule(rule);
        return {
          rule,
          name: rule.name || d.triggerLabel,
          triggerLabel: d.triggerLabel,
          actionLabel: d.actionLabel,
          condText: d.condText,
          target: d.target,
          // 1 = active sorts above 0 = paused on a descending click.
          statusValue: rule.isActive ? 1 : 0,
        };
      }),
    [rules, describeRule],
  );

  type RowKey = 'name' | 'triggerLabel' | 'actionLabel' | 'statusValue';
  const { sortKey, sortDir, toggleSort, sortedData } = useSortableTable<
    (typeof tableRows)[number],
    RowKey
  >(tableRows, {
    defaultKey: 'name',
    defaultDir: 'asc',
    accessors: {
      name: (r) => r.name.toLowerCase(),
      triggerLabel: (r) => r.triggerLabel.toLowerCase(),
      actionLabel: (r) => r.actionLabel.toLowerCase(),
      statusValue: (r) => r.statusValue,
    },
  });

  if (catalogLoading || listLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <Spinner className="w-7 h-7 text-primary" />
      </div>
    );
  }

  return (
    <div className="max-w-[1100px] mx-auto">
      {/* Header */}
      <div className="flex items-start justify-between mb-6">
        <div>
          <h1 className="text-xl font-semibold flex items-center gap-2">
            <Workflow className="w-5 h-5 text-primary" />
            Automations
          </h1>
          <p className="text-sm text-muted-foreground mt-1">
            Run an action when something happens in another module. <span className="font-medium">IF</span> a trigger fires and its conditions match, <span className="font-medium">THEN</span> the action runs.
          </p>
        </div>

        <Button className="gap-2" onClick={openCreate}><Plus className="w-4 h-4" />New automation</Button>
        <Dialog open={dialogOpen} onOpenChange={(o) => { setDialogOpen(o); if (!o) setEditingId(null); }}>
          {/* Sizing: the dialog is a flex COLUMN whose BODY scrolls — not the whole
              grid. Previously `max-h-[85vh] overflow-y-auto` sat on DialogContent
              itself, so header, body AND footer scrolled together and the Save/Cancel
              row simply left the screen on a tall form. It also produced a stray
              HORIZONTAL scrollbar: per CSS, setting overflow-y to a non-`visible`
              value forces overflow-x to compute as `auto`, so any child a pixel too
              wide (the combobox popover, a long trigger description) added a
              bottom scrollbar. Header/footer are now pinned and only the form body
              scrolls.

              Width is CLAMPED rather than a plain `sm:max-w-Nxl`: the base
              `max-w-[calc(100%-2rem)]` in dialog.tsx only guards BELOW the sm
              breakpoint, so a fixed rem width silently overflows any viewport
              between 640px and that width. `min(72rem, 100vw-4rem)` gives a big
              dialog on a desktop while staying inside a narrow laptop window.
              90vh tall for the same reason — the form is long. */}
          <DialogContent className="sm:max-w-[min(72rem,calc(100vw-4rem))] max-h-[90vh] flex flex-col overflow-hidden">
            <DialogHeader className="shrink-0">
              <DialogTitle>{editingId != null ? 'Edit automation' : 'New automation'}</DialogTitle>
              <DialogDescription>Choose a trigger, narrow it with conditions, and pick an action.</DialogDescription>
            </DialogHeader>

            {/* min-h-0 is what actually lets this shrink inside the flex column
                (flex items default to min-height:auto and refuse to shrink below
                their content). overflow-x-hidden kills the implicit horizontal
                scrollbar described above. -mx-1 px-1 keeps focus rings from being
                clipped at the scroll edge. */}
            <div className="space-y-4 py-2 min-h-0 flex-1 overflow-y-auto overflow-x-hidden -mx-1 px-1">
              {/* Name */}
              <div className="space-y-1.5">
                <label className="text-xs font-semibold text-muted-foreground">Name (optional)</label>
                <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Notify team on Launch" />
              </div>

              {/* IF — trigger */}
              <div className="space-y-1.5">
                <label className="text-xs font-semibold text-muted-foreground flex items-center gap-1.5">
                  <Zap className="w-3.5 h-3.5" /> IF — when this happens
                </label>
                <ItemCombobox
                  items={triggers}
                  value={triggerId}
                  onChange={onTriggerChange}
                  placeholder="Select a trigger"
                  emptyText="No matching triggers"
                />
                {selectedTrigger?.description && (
                  <p className="text-xs text-muted-foreground">{selectedTrigger.description}</p>
                )}
              </div>

              {/* Conditions for the selected trigger */}
              {(selectedTrigger?.conditionFields ?? []).map((f) => (
                <div key={f.key} className="space-y-1.5 pl-3 border-l-2 border-border">
                  <label className="text-xs font-semibold text-muted-foreground">{f.label}</label>
                  {f.type === 'select' && f.options ? (
                    <Select value={conditions[f.key] ?? ''} onValueChange={(v) => setConditions((c) => ({ ...c, [f.key]: v }))}>
                      <SelectTrigger><SelectValue placeholder={`Any ${f.label.toLowerCase()}`} /></SelectTrigger>
                      <SelectContent>
                        {f.options.map((o) => (
                          <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  ) : (
                    <Input value={conditions[f.key] ?? ''} onChange={(e) => setConditions((c) => ({ ...c, [f.key]: e.target.value }))} />
                  )}
                </div>
              ))}

              {/* THEN — action */}
              <div className="space-y-1.5">
                <label className="text-xs font-semibold text-muted-foreground flex items-center gap-1.5">
                  <ArrowRight className="w-3.5 h-3.5" /> THEN — do this
                </label>
                <ItemCombobox
                  items={actions}
                  value={actionId}
                  onChange={onActionChange}
                  placeholder="Select an action"
                  emptyText="No matching actions"
                />
                {selectedAction?.description && (
                  <p className="text-xs text-muted-foreground">{selectedAction.description}</p>
                )}
              </div>

              {/* Action config fields */}
              {(selectedAction?.configFields ?? []).map((f) => (
                <div key={f.key} className="space-y-1.5 pl-3 border-l-2 border-border">
                  <label className="text-xs font-semibold text-muted-foreground">
                    {f.label}{f.required ? ' *' : ''}
                  </label>
                  {f.type === 'select' && f.options ? (
                    <Select value={config[f.key] ?? f.default ?? ''} onValueChange={(v) => setConfig((c) => ({ ...c, [f.key]: v }))}>
                      <SelectTrigger><SelectValue placeholder={f.label} /></SelectTrigger>
                      <SelectContent>
                        {f.options.map((o) => (
                          <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  ) : (
                    <Input
                      type={f.type === 'url' ? 'url' : 'text'}
                      value={config[f.key] ?? ''}
                      onChange={(e) => setConfig((c) => ({ ...c, [f.key]: e.target.value }))}
                      placeholder={f.placeholder}
                    />
                  )}
                </div>
              ))}

              {/* Optional payload mapping — wires trigger context → action inputs.
                  Dynamic key/value rows: rename properties, remove them, or add
                  more. All-blank = the action's default payload. */}
              {(selectedAction?.inputSchema?.length ?? 0) > 0 && (
                <div className="space-y-2 pt-1">
                  <label className="text-xs font-semibold text-muted-foreground">Payload (optional)</label>
                  <p className="text-[11px] text-muted-foreground">
                    {'Name each property and set its value — rows without both are skipped (all blank = default payload). Use tokens like {{name}} from the trigger.'}
                  </p>
                  {mappingRows.map((row, i) => (
                    <div key={i} className="flex items-center gap-2 pl-3 border-l-2 border-border">
                      <Input
                        value={row.key}
                        onChange={(e) => setMappingRows((rows) => rows.map((r, j) => (j === i ? { ...r, key: e.target.value } : r)))}
                        placeholder="property"
                        className="text-sm w-36 shrink-0"
                        aria-label="Payload property name"
                      />
                      <Input
                        value={row.value}
                        onChange={(e) => setMappingRows((rows) => rows.map((r, j) => (j === i ? { ...r, value: e.target.value } : r)))}
                        placeholder={row.key.trim() ? `{{${row.key.trim()}}}` : 'value or {{token}}'}
                        className="text-sm"
                        aria-label="Payload property value"
                      />
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="shrink-0"
                        onClick={() => setMappingRows((rows) => rows.filter((_, j) => j !== i))}
                        aria-label="Remove payload property"
                      >
                        <Trash2 className="w-4 h-4 text-muted-foreground" />
                      </Button>
                    </div>
                  ))}
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="ml-3 gap-1.5"
                    onClick={() => setMappingRows((rows) => [...rows, { key: '', value: '' }])}
                  >
                    <Plus className="w-3.5 h-3.5" />
                    Add property
                  </Button>
                  {(selectedTrigger?.contextKeys?.length ?? 0) > 0 && (
                    <div className="space-y-1.5">
                      <p className="text-[11px] text-muted-foreground">
                        Available variables — click one to copy:
                      </p>
                      <div className="flex flex-wrap gap-1">
                        {(selectedTrigger?.contextKeys ?? []).map((k) => {
                          const token = `{{${k}}}`;
                          return (
                            <button
                              key={k}
                              type="button"
                              onClick={() => copyToken(token)}
                              title={`Copy ${token}`}
                              className={cn(
                                'rounded border px-1.5 py-0.5 font-mono text-[11px] transition-colors',
                                copiedToken === token
                                  ? 'border-emerald-500 bg-emerald-500/10 text-emerald-600'
                                  : 'border-border bg-muted/40 text-muted-foreground hover:bg-muted hover:text-foreground',
                              )}
                            >
                              {copiedToken === token ? 'Copied!' : token}
                            </button>
                          );
                        })}
                      </div>
                    </div>
                  )}
                </div>
              )}
            </div>

            <DialogFooter className="shrink-0">
              <Button variant="outline" onClick={() => setDialogOpen(false)}>Cancel</Button>
              <Button onClick={handleSave} disabled={createMutation.isPending || updateMutation.isPending} className="gap-2">
                {(createMutation.isPending || updateMutation.isPending) && <Loader2 className="w-4 h-4 animate-spin" />}
                {editingId != null ? 'Save changes' : 'Create automation'}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>

      {/* List */}
      {rules.length === 0 ? (
        <div className="border border-dashed border-border rounded-xl p-12 text-center">
          <Workflow className="w-8 h-8 text-muted-foreground/50 mx-auto mb-3" />
          <p className="text-sm font-medium">No automations yet</p>
          <p className="text-xs text-muted-foreground mt-1">
            Create your first rule — e.g. “When an approval set enters Launch, send a webhook.”
          </p>
        </div>
      ) : (
        <div className="border border-border rounded-xl overflow-hidden bg-card">
          <Table>
            <TableHeader>
              <TableRow className="bg-muted/60">
                <SortableTableHead
                  columnKey="name"
                  label="Name"
                  currentSortKey={sortKey}
                  currentSortDir={sortDir}
                  onToggle={toggleSort}
                  style={{ width: '30%' }}
                />
                <SortableTableHead
                  columnKey="triggerLabel"
                  label="Trigger (IF)"
                  currentSortKey={sortKey}
                  currentSortDir={sortDir}
                  onToggle={toggleSort}
                  style={{ width: '30%' }}
                />
                <SortableTableHead
                  columnKey="actionLabel"
                  label="Action (THEN)"
                  currentSortKey={sortKey}
                  currentSortDir={sortDir}
                  onToggle={toggleSort}
                  style={{ width: '22%' }}
                />
                <SortableTableHead
                  columnKey="statusValue"
                  label="Status"
                  currentSortKey={sortKey}
                  currentSortDir={sortDir}
                  onToggle={toggleSort}
                  className="text-center"
                  style={{ width: '10%' }}
                />
                <TableHead style={{ width: '8%' }} className="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {sortedData.map(({ rule, name, triggerLabel, actionLabel, condText, target }) => (
                <TableRow key={rule.id} className={rule.isActive ? '' : 'opacity-60'}>
                  <TableCell
                    className="font-medium cursor-pointer"
                    role="button"
                    tabIndex={0}
                    title="Edit automation"
                    onClick={() => openEdit(rule)}
                    onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openEdit(rule); } }}
                  >
                    <span className="truncate">{name}</span>
                  </TableCell>
                  <TableCell className="text-sm text-muted-foreground">
                    <span className="inline-flex items-center gap-1.5">
                      <Zap className="w-3 h-3 shrink-0" />
                      <span className="truncate">{triggerLabel}{condText ? ` (${condText})` : ''}</span>
                    </span>
                  </TableCell>
                  <TableCell className="text-sm text-muted-foreground">
                    <span className="inline-flex items-center gap-1.5">
                      <ArrowRight className="w-3 h-3 shrink-0" />
                      <span className="truncate">{actionLabel}</span>
                      {target && <span className="text-muted-foreground/70 truncate">· {target}</span>}
                    </span>
                  </TableCell>
                  <TableCell className="text-center">
                    <Switch
                      checked={rule.isActive}
                      onCheckedChange={() => toggleActive(rule)}
                      aria-label={rule.isActive ? 'Pause automation' : 'Activate automation'}
                    />
                  </TableCell>
                  <TableCell className="text-right">
                    <Button variant="ghost" size="icon" onClick={() => deleteMutation.mutate({ id: rule.id })} title="Delete">
                      <Trash2 className="w-4 h-4 text-muted-foreground" />
                    </Button>
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
