/**
 * DeliveryDialog — Notion-style card for creating + editing a delivery.
 *
 * One wide card, three parts:
 *   1. Title (the delivery name) + a PROPERTIES TABLE — one header row, one
 *      row of inline-editable cells (Status | Client | Type | Module access |
 *      Brand | External ID). In edit mode every cell auto-saves on change/blur;
 *      in create mode the cells fill local state and a Create button submits.
 *      Picking a Type applies its module preset; the "Module access" cell opens
 *      a popover of toggles for per-delivery custom overrides.
 *   2. PROJECTS TABLE (edit mode) — every project belonging to this delivery
 *      (projects.deliveryId), each with its Site ↔ Project connection dropdown
 *      (projects.siteId — same link the Sites table and Projects module edit)
 *      and a media count. "+ Add project" assigns a project to this delivery;
 *      ✕ unassigns it. Changes apply immediately via the assets endpoints that
 *      own the projects table.
 *   3. WORK LOG (edit mode) — append-only "what was done" notes
 *      (pcm_delivery_logs), newest first.
 *
 * Retired here (v1.35.0): the "SEO module — site" select (deliveries.seoSiteId
 * was write-only, nothing read it) and the single "Project" access-grant select
 * (the projects table shows the relationship; stored projectId values are
 * untouched and grants keep working).
 */

import { useEffect, useState, type FormEvent } from 'react';
import { ChevronDown, Loader2, Plus, X } from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';

import { trpc } from '@/lib/trpc';
import { useTypePresets } from './hooks/useTypePresets';

import {
  DELIVERY_STATUSES,
  GRANTABLE_MODULES,
  type Delivery,
  type DeliveryStatus,
} from './types';

const STATUS_LABELS: Record<DeliveryStatus, string> = {
  active: 'Active',
  paused: 'Paused',
  completed: 'Completed',
};

export interface DeliveryDialogProps {
  /** Open state — controlled by the parent (useDeliveries hook). */
  open: boolean;
  /** Existing delivery in edit mode; null in create mode. */
  delivery: Delivery | null;
  /** Called when the dialog wants to close (overlay click, X, Cancel). */
  onClose: () => void;
  /** Create handler. Resolves on server ack so the dialog can close. */
  onCreate: (data: {
    name: string;
    clientName?: string;
    status?: DeliveryStatus;
    type?: string | null;
    brandId?: number | null;
    modules?: string[];
    externalId?: string | null;
  }) => Promise<unknown>;
  /** Update handler. Resolves on server ack (used per-field for auto-save). */
  onUpdate: (data: {
    id: number;
    name?: string;
    clientName?: string | null;
    status?: DeliveryStatus;
    type?: string | null;
    brandId?: number | null;
    modules?: string[];
    externalId?: string | null;
  }) => Promise<unknown>;
}

/** Compact summary for the Module access cell: "Ads, SEO +2" / "None". */
function moduleSummary(ids: string[]): string {
  if (ids.length === 0) return 'None';
  const labels = ids
    .map((id) => GRANTABLE_MODULES.find((m) => m.id === id)?.label ?? id);
  return labels.length <= 2
    ? labels.join(', ')
    : `${labels.slice(0, 2).join(', ')} +${labels.length - 2}`;
}

export function DeliveryDialog({
  open,
  delivery,
  onClose,
  onCreate,
  onUpdate,
}: DeliveryDialogProps) {
  const isEdit = delivery !== null;

  const [name, setName] = useState('');
  const [clientName, setClientName] = useState('');
  const [externalId, setExternalId] = useState('');
  const [status, setStatus] = useState<DeliveryStatus>('active');
  // '' = none. Picking a type applies the central preset's modules
  // (Settings → Delivery Types); the Module access cell stays editable.
  const [type, setType] = useState('');
  const { presets: typePresets } = useTypePresets();
  const [brandId, setBrandId] = useState('');
  const [modules, setModules] = useState<string[]>([]);
  const [submitting, setSubmitting] = useState(false);

  const { data: brandsRaw } = trpc.brands.list.useQuery();
  const brands: { id: number; name: string }[] = Array.isArray(brandsRaw)
    ? brandsRaw.map((b: any) => ({ id: Number(b.id), name: b.name }))
    : [];

  // Sync form to the supplied delivery whenever the dialog opens.
  useEffect(() => {
    if (!open) return;
    if (delivery) {
      setName(delivery.name);
      setClientName(delivery.clientName ?? '');
      setExternalId(delivery.externalId ?? '');
      setStatus(delivery.status);
      setType(delivery.type ?? '');
      setBrandId(delivery.brandId ? String(delivery.brandId) : '');
      setModules(Array.isArray(delivery.modules) ? delivery.modules : []);
    } else {
      setName('');
      setClientName('');
      setExternalId('');
      setStatus('active');
      setType('');
      setBrandId('');
      setModules([]);
    }
    setSubmitting(false);
  }, [open, delivery]);

  // ── Auto-save (edit mode): each cell persists itself on change/blur. ──
  const saveField = (patch: Record<string, unknown>) => {
    if (!isEdit || !delivery) return;
    void onUpdate({ id: delivery.id, ...patch });
  };

  const handleStatusChange = (v: string) => {
    const next = v as DeliveryStatus;
    setStatus(next);
    saveField({ status: next });
  };

  const handleTypeChange = (next: string) => {
    const cleaned = next === 'none' ? '' : next;
    setType(cleaned);
    // Type drives the module preset; custom tweaks happen in the Module access cell.
    const presetModules = cleaned && typePresets[cleaned] ? [...typePresets[cleaned].modules] : modules;
    if (cleaned && typePresets[cleaned]) setModules(presetModules);
    saveField({ type: cleaned || null, ...(cleaned && typePresets[cleaned] ? { modules: presetModules } : {}) });
  };

  const handleBrandChange = (v: string) => {
    const cleaned = v === 'none' ? '' : v;
    setBrandId(cleaned);
    saveField({ brandId: cleaned ? Number(cleaned) : null });
  };

  const toggleModule = (id: string) => {
    const next = modules.includes(id) ? modules.filter((m) => m !== id) : [...modules, id];
    setModules(next);
    saveField({ modules: next });
  };

  const handleNameBlur = () => {
    if (!isEdit || !delivery) return;
    const trimmed = name.trim();
    if (trimmed.length === 0) {
      setName(delivery.name); // name is required — restore instead of saving empty
      return;
    }
    if (trimmed !== delivery.name) saveField({ name: trimmed });
  };

  const handleClientBlur = () => {
    if (!isEdit || !delivery) return;
    const trimmed = clientName.trim();
    if (trimmed !== (delivery.clientName ?? '')) saveField({ clientName: trimmed.length > 0 ? trimmed : null });
  };

  const handleExternalIdBlur = () => {
    if (!isEdit || !delivery) return;
    const trimmed = externalId.trim();
    if (trimmed !== (delivery.externalId ?? '')) saveField({ externalId: trimmed.length > 0 ? trimmed : null });
  };

  // ── Create mode: classic submit. ──
  const canSubmit = name.trim().length > 0 && !submitting;
  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (isEdit || !canSubmit) return;
    setSubmitting(true);
    try {
      const trimmedClient = clientName.trim();
      const trimmedExternalId = externalId.trim();
      await onCreate({
        name: name.trim(),
        ...(trimmedClient.length > 0 ? { clientName: trimmedClient } : {}),
        status,
        type: type || null,
        brandId: brandId ? Number(brandId) : null,
        modules,
        externalId: trimmedExternalId.length > 0 ? trimmedExternalId : null,
      });
      onClose();
    } catch {
      // Errors surface as toasts via the hook — keep the dialog open.
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={(o) => { if (!o) onClose(); }}>
      <DialogContent className="sm:max-w-5xl bg-white max-h-[90vh] overflow-y-auto">
        <form onSubmit={handleSubmit}>
          <DialogHeader>
            <DialogTitle className="sr-only">
              {isEdit ? 'Edit delivery' : 'New delivery'}
            </DialogTitle>
            <DialogDescription className="sr-only">
              {isEdit
                ? 'Delivery card — every field saves automatically.'
                : 'Create a delivery to organize continual-fulfilment work for a client.'}
            </DialogDescription>
            {/* Title = the delivery name, edited in place like a document title. */}
            <Input
              value={name}
              onChange={(e) => setName(e.target.value)}
              onBlur={handleNameBlur}
              placeholder="Delivery name…"
              autoFocus={!isEdit}
              maxLength={256}
              required
              className="border-none shadow-none px-0 !text-2xl font-bold tracking-tight focus-visible:ring-0 bg-transparent"
            />
          </DialogHeader>

          {/* ── Properties table: one header row, one row of editable cells. ── */}
          <div className="rounded-md border mt-2">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="text-xs">Status</TableHead>
                  <TableHead className="text-xs">Client</TableHead>
                  <TableHead className="text-xs">Type</TableHead>
                  <TableHead className="text-xs">Module access</TableHead>
                  <TableHead className="text-xs">Brand</TableHead>
                  <TableHead className="text-xs">External ID</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                <TableRow>
                  <TableCell className="p-1.5">
                    <Select value={status} onValueChange={handleStatusChange}>
                      <SelectTrigger className="h-8 w-full text-xs border-none shadow-none"><SelectValue /></SelectTrigger>
                      <SelectContent>
                        {DELIVERY_STATUSES.map((s) => (
                          <SelectItem key={s} value={s}>{STATUS_LABELS[s]}</SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </TableCell>
                  <TableCell className="p-1.5">
                    <Input
                      value={clientName}
                      onChange={(e) => setClientName(e.target.value)}
                      onBlur={handleClientBlur}
                      placeholder="Client name"
                      maxLength={256}
                      className="h-8 text-xs border-none shadow-none focus-visible:ring-1"
                    />
                  </TableCell>
                  <TableCell className="p-1.5">
                    <Select value={type || 'none'} onValueChange={handleTypeChange}>
                      <SelectTrigger className="h-8 w-full text-xs border-none shadow-none"><SelectValue placeholder="No type" /></SelectTrigger>
                      <SelectContent>
                        <SelectItem value="none">No type</SelectItem>
                        {Object.entries(typePresets).map(([id, preset]) => (
                          <SelectItem key={id} value={id}>{preset.label}</SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </TableCell>
                  <TableCell className="p-1.5">
                    {/* Summary cell; click for the custom-override toggles. */}
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <Button type="button" variant="ghost" size="sm" className="h-8 w-full justify-between gap-1 text-xs font-normal">
                          <span className="truncate">{moduleSummary(modules)}</span>
                          <ChevronDown className="w-3 h-3 shrink-0 text-muted-foreground" />
                        </Button>
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="start" className="w-56">
                        <DropdownMenuLabel className="text-xs">
                          Module access (Ads includes Copy + Image)
                        </DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        {GRANTABLE_MODULES.map((m) => (
                          <DropdownMenuCheckboxItem
                            key={m.id}
                            className="text-xs"
                            checked={modules.includes(m.id)}
                            onCheckedChange={() => toggleModule(m.id)}
                            onSelect={(e) => e.preventDefault()}
                          >
                            {m.label}
                          </DropdownMenuCheckboxItem>
                        ))}
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </TableCell>
                  <TableCell className="p-1.5">
                    <Select value={brandId || 'none'} onValueChange={handleBrandChange}>
                      <SelectTrigger className="h-8 w-full text-xs border-none shadow-none"><SelectValue placeholder="No brand" /></SelectTrigger>
                      <SelectContent>
                        <SelectItem value="none">No brand</SelectItem>
                        {brands.map((b) => (
                          <SelectItem key={b.id} value={String(b.id)}>{b.name}</SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </TableCell>
                  <TableCell className="p-1.5">
                    <Input
                      value={externalId}
                      onChange={(e) => setExternalId(e.target.value)}
                      onBlur={handleExternalIdBlur}
                      placeholder="e.g. Airtable ID"
                      maxLength={256}
                      className="h-8 text-xs border-none shadow-none focus-visible:ring-1"
                    />
                  </TableCell>
                </TableRow>
              </TableBody>
            </Table>
          </div>

          {/* ── Projects + Log: live sections, edit mode only. ── */}
          {isEdit && delivery && <ProjectsSection delivery={delivery} />}
          {isEdit && delivery && <LogSection delivery={delivery} />}

          <DialogFooter className="mt-4">
            {isEdit ? (
              <Button type="button" variant="outline" onClick={onClose}>Done</Button>
            ) : (
              <>
                <Button type="button" variant="ghost" onClick={onClose} disabled={submitting}>Cancel</Button>
                <Button type="submit" disabled={!canSubmit}>
                  {submitting ? 'Creating…' : 'Create delivery'}
                </Button>
              </>
            )}
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

/**
 * Projects in this delivery — table of projects with projects.deliveryId =
 * this delivery, each with its Site ↔ Project connection. Mounted only while
 * the card is open in edit mode, so the queries only run when needed.
 */
function ProjectsSection({ delivery }: { delivery: Delivery }) {
  const { data: projectsRaw, refetch: refetchProjects } = trpc.assets.getProjects.useQuery();
  const { data: sitesRaw } = trpc.sites.list.useQuery();

  // Number()-normalize ids — wpdb returns strings.
  const projects: { id: number; name: string; deliveryId: number | null; siteId: number | null; assetCount: number }[] =
    Array.isArray(projectsRaw)
      ? projectsRaw.map((p: any) => ({
          id: Number(p.id),
          name: String(p.name ?? ''),
          deliveryId: p.deliveryId != null ? Number(p.deliveryId) : null,
          siteId: p.siteId != null ? Number(p.siteId) : null,
          assetCount: Number(p.assetCount ?? 0),
        }))
      : [];
  const sites: { id: number; name: string }[] = Array.isArray(sitesRaw)
    ? (sitesRaw as any[]).map((s) => ({ id: Number(s.id), name: String(s.name || s.url || `Site #${s.id}`) }))
    : [];

  const inDelivery = projects.filter((p) => p.deliveryId === Number(delivery.id));
  const available = projects.filter((p) => p.deliveryId !== Number(delivery.id));

  const setDeliveryMutation = trpc.assets.setProjectDelivery.useMutation() as any;
  const setSiteMutation = trpc.assets.setProjectSite.useMutation() as any;

  const assignProject = async (projectId: number, name: string) => {
    try {
      await setDeliveryMutation.mutateAsync({ id: projectId, deliveryId: Number(delivery.id) });
      toast.success(`“${name}” added to this delivery`);
      refetchProjects();
    } catch (e: any) {
      toast.error(e?.message || 'Failed to add the project');
    }
  };

  const unassignProject = async (projectId: number, name: string) => {
    try {
      await setDeliveryMutation.mutateAsync({ id: projectId, deliveryId: null });
      toast.success(`“${name}” removed from this delivery`);
      refetchProjects();
    } catch (e: any) {
      toast.error(e?.message || 'Failed to remove the project');
    }
  };

  const setProjectSite = async (project: { id: number; name: string }, siteId: number | null) => {
    try {
      await setSiteMutation.mutateAsync({ id: project.id, siteId });
      toast.success(siteId ? `“${project.name}” connected to site` : `“${project.name}” disconnected from site`);
      refetchProjects();
    } catch (e: any) {
      toast.error(e?.message || 'Failed to update the site connection');
    }
  };

  return (
    <div className="mt-5">
      <div className="flex items-center justify-between mb-2">
        <h3 className="text-sm font-semibold">Projects in this delivery</h3>
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button type="button" variant="outline" size="sm" className="h-7 gap-1 text-xs">
              <Plus className="w-3.5 h-3.5" /> Add project
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-64">
            <DropdownMenuLabel className="text-xs">Assign a project to this delivery</DropdownMenuLabel>
            <DropdownMenuSeparator />
            {available.length === 0 && (
              <div className="px-2 py-1.5 text-xs text-muted-foreground">No unassigned projects</div>
            )}
            {available.map((p) => (
              <DropdownMenuItem key={p.id} className="text-xs" onSelect={() => void assignProject(p.id, p.name)}>
                <span className="truncate">{p.name}</span>
                {p.deliveryId !== null && (
                  <span className="ml-auto pl-2 text-[10px] text-muted-foreground shrink-0">in another delivery</span>
                )}
              </DropdownMenuItem>
            ))}
          </DropdownMenuContent>
        </DropdownMenu>
      </div>

      {inDelivery.length === 0 ? (
        <p className="rounded-md border border-dashed px-3 py-4 text-center text-xs text-muted-foreground">
          No projects in this delivery yet — use “Add project”.
        </p>
      ) : (
        <div className="rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="text-xs">Project</TableHead>
                <TableHead className="text-xs">Connected</TableHead>
                <TableHead className="w-10" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {inDelivery.map((p) => (
                <TableRow key={p.id}>
                  <TableCell className="text-sm font-medium">{p.name}</TableCell>
                  <TableCell className="p-1.5">
                    <div className="flex items-center gap-2">
                      <Select
                        value={p.siteId != null ? String(p.siteId) : 'none'}
                        onValueChange={(v) => void setProjectSite(p, v === 'none' ? null : Number(v))}
                        disabled={setSiteMutation.isPending}
                      >
                        <SelectTrigger className="h-8 w-[190px] text-xs">
                          <SelectValue placeholder="Not connected" />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="none">Not connected</SelectItem>
                          {sites.map((s) => (
                            <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                      <Badge variant="outline" className="text-[10px] shrink-0">
                        {p.assetCount} media
                      </Badge>
                    </div>
                  </TableCell>
                  <TableCell className="p-1.5 text-right">
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      className="h-7 w-7 p-0 text-muted-foreground hover:text-destructive"
                      title="Remove from this delivery"
                      onClick={() => void unassignProject(p.id, p.name)}
                    >
                      <X className="w-3.5 h-3.5" />
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

/**
 * Work log — append-only "what was done" entries (pcm_delivery_logs),
 * newest first. Mounted only while the card is open in edit mode.
 */
function LogSection({ delivery }: { delivery: Delivery }) {
  const [note, setNote] = useState('');
  const { data: logsRaw, refetch } = trpc.deliveries.logs.useQuery({ id: Number(delivery.id) });
  const logs: { id: number; note: string; userName: string; createdAt: string }[] = Array.isArray(logsRaw)
    ? (logsRaw as any[]).map((l) => ({
        id: Number(l.id),
        note: String(l.note ?? ''),
        userName: String(l.userName ?? ''),
        createdAt: String(l.createdAt ?? ''),
      }))
    : [];

  const addMutation = trpc.deliveries.addLog.useMutation() as any;
  const addEntry = async () => {
    const trimmed = note.trim();
    if (!trimmed) return;
    try {
      await addMutation.mutateAsync({ id: Number(delivery.id), note: trimmed });
      setNote('');
      refetch();
    } catch (e: any) {
      toast.error(e?.message || 'Failed to save the log entry');
    }
  };

  return (
    <div className="mt-5">
      <h3 className="text-sm font-semibold mb-2">Log</h3>
      <div className="flex items-center gap-2 mb-3">
        <Input
          value={note}
          onChange={(e) => setNote(e.target.value)}
          placeholder="Write what was done…"
          maxLength={2000}
          className="h-9 text-xs"
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              e.preventDefault(); // don't submit the surrounding form
              void addEntry();
            }
          }}
        />
        <Button type="button" size="sm" className="h-9 shrink-0" disabled={addMutation.isPending || !note.trim()} onClick={() => void addEntry()}>
          {addMutation.isPending ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Plus className="w-3.5 h-3.5" />} Add
        </Button>
      </div>
      {logs.length === 0 ? (
        <p className="text-xs text-muted-foreground">No log entries yet.</p>
      ) : (
        <ul className="space-y-2">
          {logs.map((l) => (
            <li key={l.id} className="rounded-md border px-3 py-2">
              <p className="text-sm whitespace-pre-wrap">{l.note}</p>
              <p className="mt-1 text-[11px] text-muted-foreground">
                {l.userName} — {l.createdAt ? new Date(l.createdAt.replace(' ', 'T')).toLocaleString() : ''}
              </p>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
