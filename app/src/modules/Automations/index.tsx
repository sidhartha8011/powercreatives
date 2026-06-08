import { useState, useMemo, useCallback } from 'react';
import { Plus, Trash2, Workflow, Zap, ArrowRight, Loader2 } from 'lucide-react';
import { toast } from 'sonner';

import { trpc } from '@/lib/trpc';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Spinner } from '@/components/ui/spinner';
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog';
import {
  Select, SelectTrigger, SelectValue, SelectContent, SelectItem, SelectGroup, SelectLabel,
} from '@/components/ui/select';

/* ── Types (catalog is dynamic, kept loose like other modules) ── */
interface ConditionField { key: string; label: string; type: string; options?: { value: string; label: string }[]; default?: string; }
interface ConfigField { key: string; label: string; type: string; required?: boolean; placeholder?: string; }
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
  const [mapping, setMapping] = useState<Record<string, string>>({});

  const selectedTrigger = useMemo(() => triggers.find((t) => t.id === triggerId), [triggers, triggerId]);
  const selectedAction = useMemo(() => actions.find((a) => a.id === actionId), [actions, actionId]);

  // Group the catalog by module so the dropdowns show the cross-module surface.
  const triggerGroups = useMemo(() => {
    const m: Record<string, TriggerDef[]> = {};
    triggers.forEach((t) => { (m[t.module] ||= []).push(t); });
    return Object.entries(m);
  }, [triggers]);
  const actionGroups = useMemo(() => {
    const m: Record<string, ActionDef[]> = {};
    actions.forEach((a) => { (m[a.module] ||= []).push(a); });
    return Object.entries(m);
  }, [actions]);

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
    setMapping({});
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
    setMapping({ ...(rule.inputMapping ?? {}) });
    setDialogOpen(true);
  }, []);

  // When the user changes the trigger, reseed its condition defaults.
  const onTriggerChange = useCallback((id: string) => {
    setTriggerId(id);
    const t = triggers.find((x) => x.id === id);
    const cond: Record<string, string> = {};
    (t?.conditionFields ?? []).forEach((f) => { cond[f.key] = f.default ?? ''; });
    setConditions(cond);
  }, [triggers]);

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
    // Only send non-empty mapping entries — blank fields fall back to the
    // action handler's default payload (e.g. webhook = name + link).
    const inputMapping: Record<string, string> = {};
    Object.entries(mapping).forEach(([k, v]) => { if (v.trim()) inputMapping[k] = v.trim(); });

    const payload = { name: name.trim() || undefined, triggerId, conditions, actionId, config, inputMapping };

    if (editingId != null) {
      updateMutation.mutate({ id: editingId, ...payload }, {
        onSuccess: () => { toast.success('Automation updated'); setDialogOpen(false); setEditingId(null); refetch(); },
      });
    } else {
      createMutation.mutate({ ...payload, isActive: true });
    }
  }, [editingId, name, triggerId, conditions, actionId, config, mapping, selectedAction, createMutation, updateMutation, refetch]);

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
          <DialogContent className="sm:max-w-lg">
            <DialogHeader>
              <DialogTitle>{editingId != null ? 'Edit automation' : 'New automation'}</DialogTitle>
              <DialogDescription>Choose a trigger, narrow it with conditions, and pick an action.</DialogDescription>
            </DialogHeader>

            <div className="space-y-4 py-2">
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
                <Select value={triggerId} onValueChange={onTriggerChange}>
                  <SelectTrigger><SelectValue placeholder="Select a trigger" /></SelectTrigger>
                  <SelectContent>
                    {triggerGroups.map(([mod, items]) => (
                      <SelectGroup key={mod}>
                        <SelectLabel className="capitalize">{mod}</SelectLabel>
                        {items.map((t) => (
                          <SelectItem key={t.id} value={t.id} disabled={t.implemented === false}>
                            {t.label}{t.implemented === false ? ' · coming soon' : ''}
                          </SelectItem>
                        ))}
                      </SelectGroup>
                    ))}
                  </SelectContent>
                </Select>
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
                <Select value={actionId} onValueChange={setActionId}>
                  <SelectTrigger><SelectValue placeholder="Select an action" /></SelectTrigger>
                  <SelectContent>
                    {actionGroups.map(([mod, items]) => (
                      <SelectGroup key={mod}>
                        <SelectLabel className="capitalize">{mod}</SelectLabel>
                        {items.map((a) => (
                          <SelectItem key={a.id} value={a.id} disabled={a.implemented === false}>
                            {a.label}{a.implemented === false ? ' · coming soon' : ''}
                          </SelectItem>
                        ))}
                      </SelectGroup>
                    ))}
                  </SelectContent>
                </Select>
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
                  <Input
                    type={f.type === 'url' ? 'url' : 'text'}
                    value={config[f.key] ?? ''}
                    onChange={(e) => setConfig((c) => ({ ...c, [f.key]: e.target.value }))}
                    placeholder={f.placeholder}
                  />
                </div>
              ))}

              {/* Optional payload mapping — wires trigger context → action inputs.
                  Blank = the action's default payload (e.g. webhook = name + link). */}
              {(selectedAction?.inputSchema?.length ?? 0) > 0 && (
                <div className="space-y-2 pt-1">
                  <label className="text-xs font-semibold text-muted-foreground">Payload (optional)</label>
                  <p className="text-[11px] text-muted-foreground">
                    {'Leave blank for the default. Use tokens like {{name}} from the trigger.'}
                  </p>
                  {(selectedAction?.inputSchema ?? []).map((f) => (
                    <div key={f.key} className="flex items-center gap-2 pl-3 border-l-2 border-border">
                      <span className="text-xs text-muted-foreground w-16 shrink-0">{f.label}</span>
                      <Input
                        value={mapping[f.key] ?? ''}
                        onChange={(e) => setMapping((m) => ({ ...m, [f.key]: e.target.value }))}
                        placeholder={`{{${f.key}}}`}
                        className="text-sm"
                      />
                    </div>
                  ))}
                  {(selectedTrigger?.contextKeys?.length ?? 0) > 0 && (
                    <p className="text-[11px] text-muted-foreground">
                      Available: {(selectedTrigger?.contextKeys ?? []).map((k) => `{{${k}}}`).join(', ')}
                    </p>
                  )}
                </div>
              )}
            </div>

            <DialogFooter>
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
        <div className="space-y-3">
          {rules.map((rule) => {
            const d = describeRule(rule);
            return (
              <div key={rule.id} className="flex items-center justify-between gap-4 border border-border rounded-xl px-4 py-3 bg-card">
                <div
                  className="min-w-0 cursor-pointer flex-1"
                  role="button"
                  tabIndex={0}
                  title="Edit automation"
                  onClick={() => openEdit(rule)}
                  onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openEdit(rule); } }}
                >
                  <div className="flex items-center gap-2">
                    <span className="font-medium truncate">{rule.name || d.triggerLabel}</span>
                    {!rule.isActive && (
                      <span className="text-[10px] uppercase tracking-wide text-muted-foreground border border-border rounded px-1.5 py-0.5">Paused</span>
                    )}
                  </div>
                  <div className="text-xs text-muted-foreground mt-1 flex items-center gap-1.5 flex-wrap">
                    <Zap className="w-3 h-3" />
                    <span>{d.triggerLabel}{d.condText ? ` (${d.condText})` : ''}</span>
                    <ArrowRight className="w-3 h-3" />
                    <span>{d.actionLabel}</span>
                    {d.target && <span className="text-muted-foreground/70 truncate">· {d.target}</span>}
                  </div>
                </div>
                <div className="flex items-center gap-3 shrink-0">
                  <Switch checked={rule.isActive} onCheckedChange={() => toggleActive(rule)} />
                  <Button variant="ghost" size="icon" onClick={() => deleteMutation.mutate({ id: rule.id })} title="Delete">
                    <Trash2 className="w-4 h-4 text-muted-foreground" />
                  </Button>
                </div>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
