/**
 * DeliveriesTable — the table view of the deliveries pipeline.
 *
 * Built on the global DataTable (`@/components/ui/data-table`) — the same
 * minimalist grid Sites and the SEO table use.
 *
 * Every cell is an in-place editor with the delivery card's auto-save
 * contract: optimistic apply → single-field PATCH via useDeliveries'
 * updateDelivery (silent success, error toast) → REVERT the cell on failure.
 * Updated is read-only (derived). Because cells own the click, the delivery
 * card opens via the dedicated affordance on the name cell — not row click.
 *
 * Expanding a row (chevron) shows the delivery's projects as NATIVE rows of
 * the same grid — same cells, same hairlines, indent = hierarchy — never an
 * embedded table. Data + mutations shared with the delivery card via
 * useDeliveryProjects; both caches are warmed at table mount so expansion is
 * instant client-side filtering, not a first-expand network wait.
 *
 * Receives the already-filtered rows from the board's shared list state, so
 * search / client filters apply identically in both views. Status pills reuse
 * the Kanban lane colors (deliveryColumns) — one palette, two views.
 */

import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { ChevronDown, ChevronRight, Maximize2, Plus, Trash2, X } from 'lucide-react';

import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import type { FilterDef } from '@/hooks/useColumnFilters';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { BulkActionBar } from '@/components/shared/BulkActionBar';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Input } from '@/components/ui/input';
import {
  CELL_EDIT_INPUT,
  CELL_EMPTY_TEXT,
  CELL_PILL,
  CELL_PILL_NEUTRAL,
  CELL_VIEW_TEXT,
} from '@/components/ui/table-cell-recipes';
import { relTime } from '@/components/shared/EntityCard';
import { cn } from '@/lib/utils';
import { trpc } from '@/lib/trpc';
import { getIsAdmin } from '@/lib/pcmConfig';
import { toast } from 'sonner';
import { useApp } from '@/contexts/AppContext';

import { AddProjectMenu, useDeliveryProjects, type DeliveryProject } from '../DeliveryProjects';
import { deliveryColumns, type DeliveryColumn } from '../kanban/deliveryColumns';
import { useDeliveries, type UpdateDeliveryInput } from '../hooks/useDeliveries';
import { useTypePresets } from '../hooks/useTypePresets';
import { DELIVERY_STATUSES, GRANTABLE_MODULES, type Delivery, type DeliveryStatus } from '../types';

/** Column layout storage — exported so the board's Reset button can clear it. */
export const DELIVERIES_LAYOUT_KEY = 'pcm:deliveries:col-layout:v1';

/** Lane declarations by status id — the pills borrow the Kanban accents. */
const STATUS_LANES: Partial<Record<DeliveryStatus, DeliveryColumn>> = Object.fromEntries(
  deliveryColumns.map((c) => [c.id, c])
);

function StatusPill({ status }: { status: DeliveryStatus }) {
  const lane = STATUS_LANES[status];
  if (!lane) return <span>{status}</span>;
  return (
    <span className={CELL_PILL} style={{ background: lane.accentColor, color: lane.accentText }}>
      {lane.label}
    </span>
  );
}

/**
 * Predefined tag palette for Type pills — soft backgrounds + readable ink,
 * the same family as the Kanban lane accents. A type KEY hashes to a stable
 * slot, so a given type always wears the same color across sessions and
 * label renames (admin-defined presets carry no color of their own).
 */
const TYPE_PALETTE: ReadonlyArray<{ bg: string; text: string }> = [
  { bg: '#e0f2fe', text: '#075985' }, // sky
  { bg: '#fef3c7', text: '#92400e' }, // amber
  { bg: '#dcfce7', text: '#166534' }, // green
  { bg: '#fae8ff', text: '#86198f' }, // fuchsia
  { bg: '#ffe4e6', text: '#9f1239' }, // rose
  { bg: '#e0e7ff', text: '#3730a3' }, // indigo
  { bg: '#f1f5f9', text: '#334155' }, // slate
];

export function typeColors(typeKey: string): { bg: string; text: string } {
  let h = 0;
  for (let i = 0; i < typeKey.length; i++) h = (h * 31 + typeKey.charCodeAt(i)) >>> 0;
  return TYPE_PALETTE[h % TYPE_PALETTE.length];
}

function TypePill({ typeKey, label }: { typeKey: string; label: string }) {
  const colors = typeColors(typeKey);
  return (
    <span className={CELL_PILL} style={{ background: colors.bg, color: colors.text }}>
      {label}
    </span>
  );
}

/** Sentinel for the "none" option in in-place selects. */
const NONE_VALUE = '__none__';

/**
 * Click-to-edit text cell — the SEO table's cell design (view text with
 * dotted-underline hover affordance; compact Input while editing), with the
 * delivery card's save contract kept: commit on blur/Enter (only when
 * changed), Esc cancels, revert the draft on failure.
 */
function InlineTextCell({
  value,
  ariaLabel,
  placeholder,
  required = false,
  className,
  onSave,
}: {
  value: string;
  ariaLabel: string;
  placeholder?: string;
  /** Refuse to save an empty value (e.g. the delivery name). */
  required?: boolean;
  className?: string;
  onSave: (next: string) => Promise<unknown>;
}) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(value);
  useEffect(() => {
    setDraft(value);
  }, [value]);

  const commit = () => {
    setEditing(false);
    const next = draft.trim();
    if (next === value) return;
    if (required && next.length === 0) {
      setDraft(value);
      return;
    }
    onSave(next).catch(() => setDraft(value));
  };

  if (editing) {
    return (
      <Input
        autoFocus
        value={draft}
        aria-label={ariaLabel}
        onChange={(e) => setDraft(e.target.value)}
        onBlur={commit}
        onKeyDown={(e) => {
          if (e.key === 'Enter') {
            e.preventDefault();
            commit();
          }
          if (e.key === 'Escape') {
            setDraft(value);
            setEditing(false);
          }
        }}
        className={cn(CELL_EDIT_INPUT, className)}
      />
    );
  }

  return (
    <button
      type="button"
      aria-label={ariaLabel}
      onClick={() => {
        setDraft(value);
        setEditing(true);
      }}
      className={cn(CELL_VIEW_TEXT, className)}
      title={value || placeholder}
    >
      {value || <span className={CELL_EMPTY_TEXT}>{placeholder ?? '—'}</span>}
    </button>
  );
}

/**
 * Borderless in-place select. Card auto-save contract: optimistic apply,
 * revert to the previous value on failure. `null` maps to the none option.
 */
function InlineSelectCell({
  value,
  options,
  noneLabel,
  ariaLabel,
  disabled = false,
  renderValue,
  onSave,
}: {
  value: string | null;
  options: { value: string; label: string }[];
  /** When set, a "none" option is offered (and null is a legal value). */
  noneLabel?: string;
  ariaLabel: string;
  disabled?: boolean;
  /** Custom trigger content (e.g. the status pill); default = selected label. */
  renderValue?: (current: string | null) => ReactNode;
  onSave: (next: string | null) => Promise<unknown>;
}) {
  const [current, setCurrent] = useState<string | null>(value);
  useEffect(() => {
    setCurrent(value);
  }, [value]);

  const handleChange = (v: string) => {
    const next = v === NONE_VALUE ? null : v;
    if (next === current) return;
    const prev = current;
    setCurrent(next);
    onSave(next).catch(() => setCurrent(prev));
  };

  return (
    <Select value={current ?? NONE_VALUE} onValueChange={handleChange} disabled={disabled}>
      <SelectTrigger
        aria-label={ariaLabel}
        className={cn(
          'h-7 w-full rounded border-none bg-transparent px-1 text-xs shadow-none hover:bg-slate-50 focus-visible:ring-1',
          current == null && 'text-muted-foreground'
        )}
      >
        {renderValue ? renderValue(current) : <SelectValue placeholder={noneLabel} />}
      </SelectTrigger>
      <SelectContent>
        {noneLabel != null && (
          <SelectItem value={NONE_VALUE} className="text-muted-foreground">
            {noneLabel}
          </SelectItem>
        )}
        {options.map((o) => (
          <SelectItem key={o.value} value={o.value}>
            {o.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}

/**
 * Borderless in-place multi-toggle — the card's Modules control (chips +
 * checkbox dropdown), with the table's optimistic-revert save contract.
 */
function InlineModulesCell({
  values,
  ariaLabel,
  onSave,
}: {
  values: string[];
  ariaLabel: string;
  onSave: (next: string[]) => Promise<unknown>;
}) {
  const [current, setCurrent] = useState<string[]>(values);
  useEffect(() => {
    setCurrent(values);
  }, [values]);

  const toggle = (id: string) => {
    const prev = current;
    const next = prev.includes(id) ? prev.filter((v) => v !== id) : [...prev, id];
    setCurrent(next);
    onSave(next).catch(() => setCurrent(prev));
  };

  const selected = GRANTABLE_MODULES.filter((o) => current.includes(o.id));

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          type="button"
          variant="ghost"
          aria-label={ariaLabel}
          className="h-7 w-full justify-between gap-1 rounded px-1 text-xs font-normal hover:bg-slate-50"
        >
          {/* Chips, not a sentence — same 2-chips-then-overflow rule as the card. */}
          <span className="flex min-w-0 items-center gap-1">
            {selected.length === 0 && <span className={CELL_EMPTY_TEXT}>None</span>}
            {selected.slice(0, 2).map((o) => (
              <span key={o.id} className={CELL_PILL_NEUTRAL}>
                {o.label}
              </span>
            ))}
            {selected.length > 2 && <span className={CELL_PILL_NEUTRAL}>+{selected.length - 2}</span>}
          </span>
          <ChevronDown className="h-3 w-3 shrink-0 text-muted-foreground" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start" className="w-56">
        <DropdownMenuLabel className="text-xs">Module access (Ads includes Copy + Image)</DropdownMenuLabel>
        <DropdownMenuSeparator />
        {GRANTABLE_MODULES.map((o) => (
          <DropdownMenuCheckboxItem
            key={o.id}
            className="text-xs"
            checked={current.includes(o.id)}
            onCheckedChange={() => toggle(o.id)}
            onSelect={(e) => e.preventDefault()}
          >
            {o.label}
          </DropdownMenuCheckboxItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

/** Read-only module chips — the non-admin rendering of the Modules cell. */
function ModulesChips({ values }: { values: string[] }) {
  const selected = GRANTABLE_MODULES.filter((o) => values.includes(o.id));
  if (selected.length === 0) return <span className={`px-1 ${CELL_EMPTY_TEXT}`}>None</span>;
  return (
    <span className="flex min-w-0 items-center gap-1 px-1">
      {selected.slice(0, 2).map((o) => (
        <span key={o.id} className={CELL_PILL_NEUTRAL}>
          {o.label}
        </span>
      ))}
      {selected.length > 2 && <span className={CELL_PILL_NEUTRAL}>+{selected.length - 2}</span>}
    </span>
  );
}

/** The delivery's lead among its assignees (role='lead'; single by contract). */
export function leadOf(d: Delivery): { id: number; name: string } | null {
  const lead = d.assignees?.find((a) => a.role === 'lead');
  return lead ? { id: lead.id, name: lead.name } : null;
}

/** Tooltip listing the non-lead team members, if any. */
function teamTitle(d: Delivery): string | undefined {
  const others = (d.assignees ?? []).filter((a) => a.role !== 'lead');
  return others.length > 0 ? `Team: ${others.map((o) => o.name).join(', ')}` : undefined;
}

/**
 * Editable Lead select — mounted ONLY for admins (GET /users is admin-only;
 * non-admins get the read-only name instead). Picking a user assigns them as
 * the delivery's lead via PATCH /deliveries/{id}/lead: a new assignee gains
 * the delivery's module access + notifications through the existing
 * assignment mechanics; a previous lead is demoted to member, never removed.
 */
function LeadSelect({ delivery, onSaved }: { delivery: Delivery; onSaved: () => void }) {
  const { data: usersRaw } = trpc.users.list.useQuery();
  const users = useMemo(() => {
    if (!Array.isArray(usersRaw)) return [] as { value: string; label: string }[];
    return (usersRaw as any[]).map((u) => ({
      value: String(Number(u.id)),
      label: String(u.name || u.username || `User #${u.id}`),
    }));
  }, [usersRaw]);

  const setLeadMutation = trpc.deliveries.setLead.useMutation() as any;
  const lead = leadOf(delivery);

  return (
    <InlineSelectCell
      value={lead ? String(lead.id) : null}
      options={users}
      noneLabel="No lead"
      ariaLabel={`Lead of ${delivery.name}`}
      renderValue={(v) =>
        v ? (
          <span className="truncate" title={teamTitle(delivery)}>
            {users.find((u) => u.value === v)?.label ?? lead?.name ?? ''}
          </span>
        ) : (
          <span className="text-muted-foreground">—</span>
        )
      }
      onSave={async (v) => {
        try {
          await setLeadMutation.mutateAsync({ id: delivery.id, userId: v != null ? Number(v) : null });
          onSaved();
        } catch (e: any) {
          toast.error(e?.message || 'Failed to set the lead');
          throw e; // InlineSelectCell reverts on rethrow
        }
      }}
    />
  );
}

/**
 * Accordion content: the delivery's projects as native rows of the parent
 * grid. Cell mapping is POSITIONAL (independent of the parent's column order):
 * chevron (empty) | project name (indented, inline-renames the real project) |
 * site select | Images | Copy | Videos | Approvals counts | spacer | ✕. The
 * sub-header row above labels these cells. Count cells carry a "+" that opens
 * the matching module with brand/delivery/project pre-selected
 * (AppContext.navigateToCreate). Trailing row = the same AddProjectMenu the
 * delivery card has.
 */
function ProjectSubRows({
  delivery,
  hasSelectionCol,
  selectedProjects,
  onToggleProject,
}: {
  delivery: Delivery;
  /** True when the parent table renders the selection column (admins). */
  hasSelectionCol: boolean;
  selectedProjects: ReadonlySet<number>;
  onToggleProject: (projectId: number) => void;
}) {
  const { navigateToProjectTab, navigateToCreate } = useApp();
  // Approvals count = frontend join on the sets list (approval_sets.projectId)
  // — the assets module must never read the approvals tables server-side.
  const { data: setsRaw } = trpc.approvals.listSets.useQuery();
  const setCountByProject = useMemo(() => {
    const counts = new Map<number, number>();
    if (Array.isArray(setsRaw)) {
      for (const s of setsRaw as any[]) {
        if (s?.projectId != null) {
          const pid = Number(s.projectId);
          counts.set(pid, (counts.get(pid) ?? 0) + 1);
        }
      }
    }
    return counts;
  }, [setsRaw]);

  const {
    inDelivery,
    available,
    sites,
    assignProject,
    unassignProject,
    setProjectSite,
    renameProject,
    sitePending,
    isLoading,
  } = useDeliveryProjects(delivery);

  if (isLoading) {
    return (
      <tr>
        {hasSelectionCol && <td />}
        <td colSpan={9} className="text-muted-foreground">
          Loading projects…
        </td>
      </tr>
    );
  }

  /** The "+" beside a count: open the target module with brand / delivery /
   *  project pre-selected — whatever gets produced is born correctly mapped. */
  const createPlus = (p: DeliveryProject, module: 'image' | 'copy' | 'video' | 'approvals', label: string) => (
    <Button
      type="button"
      variant="ghost"
      size="sm"
      className="h-6 w-6 shrink-0 p-0 text-muted-foreground/40 hover:text-primary"
      title={`Create ${label.toLowerCase()} for ${p.name}`}
      aria-label={`Create ${label.toLowerCase()} for ${p.name} — pre-mapped to this delivery and project`}
      onClick={() =>
        navigateToCreate({
          module,
          brandId: delivery.brandId != null ? Number(delivery.brandId) : null,
          deliveryId: Number(delivery.id),
          projectId: p.id,
        })
      }
    >
      <Plus className="h-3.5 w-3.5" />
    </Button>
  );

  // Count cell: the sub-header labels the column, so the cell is just the
  // number (click = jump into the project on that tab) plus the create "+".
  const countCell = (
    p: DeliveryProject,
    count: number,
    label: string,
    tab: 'media' | 'copy',
    module: 'image' | 'copy' | 'video'
  ) => (
    <span className="flex items-center">
      <Button
        type="button"
        variant="ghost"
        size="sm"
        className={`h-7 px-2 text-xs tabular-nums hover:text-primary ${count === 0 ? 'text-muted-foreground/50' : ''}`}
        title={`Open ${label.toLowerCase()} in ${p.name}`}
        aria-label={`${count} ${label.toLowerCase()} in ${p.name} — open`}
        onClick={() => navigateToProjectTab({ projectId: p.id, tab })}
      >
        {count}
      </Button>
      {createPlus(p, module, label)}
    </span>
  );

  // Whisper-gray band + 10px label ink: reads as a section header, one level
  // quieter than the main bg-slate-50 header band. Labels match each
  // column's content indent so header and value ink align optically.
  const subHead = 'bg-slate-50/60 text-[10px] font-medium text-muted-foreground';

  return (
    <>
      <tr aria-hidden="true">
        {hasSelectionCol && <td className={subHead} />}
        <td className={subHead}>
          <span className="block pl-4">Project</span>
        </td>
        <td className={subHead}>
          <span className="block pl-1">Site</span>
        </td>
        <td className={subHead}>
          <span className="block pl-2">Images</span>
        </td>
        <td className={subHead}>
          <span className="block pl-2">Copy</span>
        </td>
        <td className={subHead}>
          <span className="block pl-2">Videos</span>
        </td>
        <td className={subHead}>
          <span className="block pl-2">Approvals</span>
        </td>
        <td className={subHead} />
        <td className={subHead} />
        <td className={subHead} />
      </tr>
      {inDelivery.map((p) => (
        <tr key={`project-${p.id}`} className={`group ${selectedProjects.has(p.id) ? 'bg-primary/5' : ''}`}>
          {hasSelectionCol && (
            <td className="text-center">
              <Checkbox
                checked={selectedProjects.has(p.id)}
                onCheckedChange={() => onToggleProject(p.id)}
                aria-label={`Select project ${p.name}`}
              />
            </td>
          )}
          <td>
            {/* Indent = hierarchy; edits rename the ACTUAL project (card
                auto-save contract — revert on failure via rethrow). */}
            <div className="pl-3">
              <InlineTextCell
                value={p.name}
                required
                ariaLabel={`Name of project ${p.name}`}
                onSave={(next) => renameProject(p.id, next)}
              />
            </div>
          </td>
          <td>
            <InlineSelectCell
              value={p.siteId != null ? String(p.siteId) : null}
              options={sites.map((s) => ({ value: String(s.id), label: s.name }))}
              noneLabel="Not connected"
              ariaLabel={`Site for ${p.name}`}
              disabled={sitePending}
              onSave={(v) => setProjectSite(p, v != null ? Number(v) : null)}
            />
          </td>
          <td>{countCell(p, p.imageCount, 'Images', 'media', 'image')}</td>
          <td>{countCell(p, p.copyCount, 'Copy', 'copy', 'copy')}</td>
          <td>{countCell(p, p.videoCount, 'Videos', 'media', 'video')}</td>
          <td>
            {/* No approvals tab in the project detail to jump to — the count
                is informational, the + creates a pre-mapped approval set. */}
            <span className="flex items-center">
              <span className={`px-2 text-xs tabular-nums ${(setCountByProject.get(p.id) ?? 0) === 0 ? 'text-muted-foreground/50' : ''}`}>
                {setCountByProject.get(p.id) ?? 0}
              </span>
              {createPlus(p, 'approvals', 'approval set')}
            </span>
          </td>
          <td />
          <td />
          <td className="text-right">
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="h-6 w-6 p-0 text-muted-foreground/50 opacity-0 transition-opacity hover:text-destructive focus-visible:opacity-100 group-hover:opacity-100"
              title="Remove from this delivery"
              aria-label={`Remove ${p.name} from this delivery`}
              onClick={() => void unassignProject(p.id, p.name)}
            >
              <X className="h-3.5 w-3.5" />
            </Button>
          </td>
        </tr>
      ))}
      <tr>
        {hasSelectionCol && <td />}
        <td colSpan={9}>
          <AddProjectMenu
            available={available}
            onAssign={(id, name) => void assignProject(id, name)}
            trigger={
              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-7 gap-1 pl-4 text-xs text-muted-foreground"
              >
                <Plus className="h-3.5 w-3.5" /> Add project
              </Button>
            }
          />
        </td>
      </tr>
    </>
  );
}

export interface DeliveriesTableProps {
  /** Already-filtered rows (the board's shared list state). */
  items: ReadonlyArray<Delivery>;
  /** Open the delivery card — via the name cell's open affordance. */
  onEdit: (delivery: Delivery) => void;
  /** Ask the board for its delete-confirm flow (same dialog as the Kanban). */
  onRequestDelete: (delivery: Delivery) => void;
}

export function DeliveriesTable({ items, onEdit, onRequestDelete }: DeliveriesTableProps) {
  // Warm the shared project/site/sets caches at table mount so expanding a
  // row is instant client-side filtering, not a first-expand network wait
  // (the sub-row hooks read these exact query keys).
  trpc.assets.getProjects.useQuery();
  trpc.sites.list.useQuery();
  trpc.approvals.listSets.useQuery();

  const isAdmin = getIsAdmin();
  const { updateDelivery, deleteDelivery, refetch } = useDeliveries();
  const patch = (id: number, input: Omit<UpdateDeliveryInput, 'id'>) =>
    updateDelivery({ id, ...input });

  // ── Multi-select + bulk actions (admin-only — all bulk ops are writes) ──
  const [selectedIds, setSelectedIds] = useState<Set<string | number>>(new Set());
  const [selectedProjectIds, setSelectedProjectIds] = useState<Set<number>>(new Set());
  const [bulkDeleteOpen, setBulkDeleteOpen] = useState(false);
  const [bulkBusy, setBulkBusy] = useState(false);

  const toggleProject = (projectId: number) => {
    setSelectedProjectIds((prev) => {
      const next = new Set(prev);
      if (next.has(projectId)) next.delete(projectId);
      else next.add(projectId);
      return next;
    });
  };

  const clearSelection = () => {
    setSelectedIds(new Set());
    setSelectedProjectIds(new Set());
  };

  const bulkSetStatus = async (status: DeliveryStatus) => {
    setBulkBusy(true);
    try {
      await Promise.all([...selectedIds].map((id) => updateDelivery({ id: Number(id), status })));
      toast.success(`Status updated for ${selectedIds.size} deliver${selectedIds.size === 1 ? 'y' : 'ies'}`);
      setSelectedIds(new Set());
    } catch {
      // Per-call errors already toast via useDeliveries.
    } finally {
      setBulkBusy(false);
    }
  };

  const bulkDelete = async () => {
    setBulkDeleteOpen(false);
    setBulkBusy(true);
    try {
      // Sequential: deleteDelivery is optimistic per-row; parallel deletes
      // would race the shared cache snapshots it restores on failure.
      for (const id of selectedIds) {
        await deleteDelivery(Number(id));
      }
      setSelectedIds(new Set());
    } catch {
      // Per-call errors already toast + roll back via useDeliveries.
    } finally {
      setBulkBusy(false);
    }
  };

  const setProjectDeliveryMutation = trpc.assets.setProjectDelivery.useMutation() as any;
  const projectsUtils = trpc.assets.getProjects.useUtils();
  const bulkRemoveProjects = async () => {
    setBulkBusy(true);
    try {
      for (const projectId of selectedProjectIds) {
        await setProjectDeliveryMutation.mutateAsync({ id: projectId, deliveryId: null });
      }
      toast.success(`${selectedProjectIds.size} project${selectedProjectIds.size === 1 ? '' : 's'} removed from their delivery`);
      setSelectedProjectIds(new Set());
      projectsUtils.invalidate();
    } catch (e: any) {
      toast.error(e?.message || 'Failed to remove some projects');
      projectsUtils.invalidate();
    } finally {
      setBulkBusy(false);
    }
  };

  const { data: brandsRaw } = trpc.brands.list.useQuery();
  const brandOptions = useMemo(() => {
    if (!Array.isArray(brandsRaw)) return [] as { value: string; label: string }[];
    return (brandsRaw as any[]).map((b) => ({ value: String(Number(b.id)), label: String(b.name ?? '') }));
  }, [brandsRaw]);
  const brandLabel = useMemo(() => {
    const map = new Map<string, string>();
    for (const b of brandOptions) map.set(b.value, b.label);
    return map;
  }, [brandOptions]);

  const { presets: typePresets } = useTypePresets();
  const typeOptions = useMemo(
    () => Object.entries(typePresets).map(([id, preset]) => ({ value: id, label: preset.label })),
    [typePresets]
  );

  const columns: DataTableColumn<Delivery>[] = useMemo(
    () => [
      {
        key: 'name',
        header: 'Delivery',
        width: 220,
        sortAccessor: (d) => d.name.toLowerCase(),
        cell: (d, ctx) => (
          <div className="flex items-center gap-1">
            {/* Expand lives INSIDE the name cell (SEO-table pattern). */}
            {ctx?.canExpand && (
              <button
                type="button"
                onClick={(e) => {
                  e.stopPropagation();
                  ctx.toggleExpanded();
                }}
                title={ctx.isExpanded ? 'Hide projects' : 'Show projects'}
                aria-label={ctx.isExpanded ? `Hide projects of ${d.name}` : `Show projects of ${d.name}`}
                aria-expanded={ctx.isExpanded}
                className="shrink-0 text-muted-foreground/60 hover:text-foreground"
              >
                {ctx.isExpanded ? <ChevronDown className="h-3.5 w-3.5" /> : <ChevronRight className="h-3.5 w-3.5" />}
              </button>
            )}
            {isAdmin ? (
              <InlineTextCell
                value={d.name}
                required
                ariaLabel={`Name of ${d.name}`}
                className="font-medium"
                onSave={(next) => patch(d.id, { name: next })}
              />
            ) : (
              <span className="block min-w-0 flex-1 truncate px-1 font-medium">{d.name}</span>
            )}
            {/* Hover-only (PO 2026-07-07; CSS hover accepted) — row carries `group`. */}
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="h-5 w-5 shrink-0 p-0 text-muted-foreground/50 opacity-0 transition-opacity hover:text-foreground focus-visible:opacity-100 group-hover:opacity-100"
              title="Open delivery card"
              aria-label={`Open ${d.name}`}
              onClick={() => onEdit(d)}
            >
              <Maximize2 className="h-3 w-3" />
            </Button>
          </div>
        ),
      },
      {
        key: 'status',
        header: 'Status',
        width: 120,
        // Pipeline order (lane order), not alphabetical.
        sortAccessor: (d) => DELIVERY_STATUSES.indexOf(d.status),
        cell: (d) =>
          isAdmin ? (
            <InlineSelectCell
              value={d.status}
              options={DELIVERY_STATUSES.map((s) => ({
                value: s,
                label: STATUS_LANES[s]?.label ?? s,
              }))}
              ariaLabel={`Status of ${d.name}`}
              renderValue={(v) => <StatusPill status={(v ?? d.status) as DeliveryStatus} />}
              onSave={(v) => patch(d.id, { status: v as DeliveryStatus })}
            />
          ) : (
            <span className="px-1">
              <StatusPill status={d.status} />
            </span>
          ),
      },
      {
        key: 'type',
        header: 'Type',
        width: 130,
        sortAccessor: (d) => (d.type ? (typePresets[d.type]?.label ?? d.type).toLowerCase() : null),
        cell: (d) =>
          !isAdmin ? (
            <span className="px-1">
              {d.type ? (
                <TypePill typeKey={d.type} label={typePresets[d.type]?.label ?? d.type} />
              ) : (
                <span className="text-muted-foreground">—</span>
              )}
            </span>
          ) : (
          <InlineSelectCell
            value={d.type ?? null}
            options={typeOptions}
            noneLabel="No type"
            ariaLabel={`Type of ${d.name}`}
            renderValue={(v) =>
              v ? (
                <TypePill typeKey={v} label={typePresets[v]?.label ?? v} />
              ) : (
                <span className="text-muted-foreground">No type</span>
              )
            }
            onSave={(v) => {
              // Type applies the central preset — same semantics as the card.
              const presetModules = v && typePresets[v] ? [...typePresets[v].modules] : null;
              return patch(d.id, {
                type: v,
                ...(presetModules ? { modules: presetModules } : {}),
              });
            }}
          />
        ),
      },
      {
        key: 'brand',
        header: 'Brand',
        width: 150,
        sortAccessor: (d) =>
          d.brandId != null ? brandLabel.get(String(Number(d.brandId)))?.toLowerCase() ?? null : null,
        cell: (d) =>
          isAdmin ? (
            <InlineSelectCell
              value={d.brandId != null ? String(Number(d.brandId)) : null}
              options={brandOptions}
              noneLabel="No brand"
              ariaLabel={`Brand of ${d.name}`}
              onSave={(v) => patch(d.id, { brandId: v != null ? Number(v) : null })}
            />
          ) : (
            <span className="block truncate px-1">
              {(d.brandId != null && brandLabel.get(String(Number(d.brandId)))) || (
                <span className="text-muted-foreground">—</span>
              )}
            </span>
          ),
      },
      {
        key: 'modules',
        header: 'Modules',
        width: 170,
        // Sortable by how much access the delivery grants.
        sortAccessor: (d) => (Array.isArray(d.modules) ? d.modules.length : 0),
        cell: (d) =>
          isAdmin ? (
            <InlineModulesCell
              values={Array.isArray(d.modules) ? d.modules : []}
              ariaLabel={`Modules of ${d.name}`}
              onSave={(next) => patch(d.id, { modules: next })}
            />
          ) : (
            <ModulesChips values={Array.isArray(d.modules) ? d.modules : []} />
          ),
      },
      {
        key: 'lead',
        header: 'Lead',
        width: 160,
        sortAccessor: (d) => leadOf(d)?.name.toLowerCase() ?? null,
        cell: (d) =>
          isAdmin ? (
            <LeadSelect delivery={d} onSaved={refetch} />
          ) : (
            <span className="block truncate" title={teamTitle(d)}>
              {leadOf(d)?.name ?? <span className="text-muted-foreground">—</span>}
            </span>
          ),
      },
      {
        key: 'externalId',
        header: 'ID',
        width: 110,
        sortAccessor: (d) => d.externalId?.toLowerCase() ?? null,
        cell: (d) =>
          isAdmin ? (
            <InlineTextCell
              value={d.externalId ?? ''}
              placeholder="—"
              ariaLabel={`External ID of ${d.name}`}
              onSave={(next) => patch(d.id, { externalId: next.length > 0 ? next : null })}
            />
          ) : (
            <span className="block truncate px-1">
              {d.externalId || <span className="text-muted-foreground">—</span>}
            </span>
          ),
      },
      {
        key: 'updated',
        header: 'Updated',
        width: 110,
        sortAccessor: (d) => d.updatedAt,
        cell: (d) => <span className="text-muted-foreground">{relTime(d.updatedAt)}</span>,
      },
      {
        key: 'actions',
        header: '',
        width: 44,
        // Row actions live in their own far-right column (PO 2026-07-07);
        // hover-revealed via the row's `group`.
        cell: (d) =>
          isAdmin ? (
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="h-6 w-6 p-0 text-muted-foreground/50 opacity-0 transition-opacity hover:text-destructive focus-visible:opacity-100 group-hover:opacity-100"
              title="Delete delivery"
              aria-label={`Delete ${d.name}`}
              onClick={() => onRequestDelete(d)}
            >
              <Trash2 className="h-3.5 w-3.5" />
            </Button>
          ) : null,
      },
    ],
    // eslint-disable-next-line react-hooks/exhaustive-deps — patch/onEdit are stable enough per render
    [brandOptions, brandLabel, typeOptions, typePresets, onEdit, onRequestDelete, isAdmin, refetch]
  );

  // Per-column header filters (the SEO table's filter dot) — text search for
  // free-text columns, option lists for enum-ish ones. Brand matches by id
  // (options carry the names); Lead searches every assignee name.
  const filterDefs = useMemo<Record<string, FilterDef<Delivery>>>(
    () => ({
      name: {
        key: 'name',
        kind: 'text',
        match: (d, v) => d.name.toLowerCase().includes(v.toLowerCase()),
      },
      status: {
        key: 'status',
        kind: 'choice',
        options: DELIVERY_STATUSES.map((s) => ({ value: s, label: STATUS_LANES[s]?.label ?? s })),
        match: (d, v) => d.status === v,
      },
      type: {
        key: 'type',
        kind: 'choice',
        options: typeOptions,
        match: (d, v) => (d.type ?? '') === v,
      },
      brand: {
        key: 'brand',
        kind: 'choice',
        options: brandOptions,
        match: (d, v) => String(d.brandId != null ? Number(d.brandId) : '') === v,
      },
      lead: {
        key: 'lead',
        kind: 'text',
        match: (d, v) =>
          (d.assignees ?? []).some((a) => a.name.toLowerCase().includes(v.toLowerCase())),
      },
      externalId: {
        key: 'externalId',
        kind: 'text',
        match: (d, v) => (d.externalId ?? '').toLowerCase().includes(v.toLowerCase()),
      },
    }),
    [typeOptions, brandOptions]
  );

  return (
    <>
      <DataTable<Delivery>
        columns={columns}
        data={[...items]}
        rowKey={(d) => d.id}
        defaultSortKey="updated"
        defaultSortDir="desc"
        inlineExpand
        renderSubRows={(d) => (
          <ProjectSubRows
            delivery={d}
            hasSelectionCol={isAdmin}
            selectedProjects={selectedProjectIds}
            onToggleProject={toggleProject}
          />
        )}
        emptyMessage="No deliveries match your filters."
        // SEO-style column layout: drag headers to reorder, drag edges to
        // resize; persisted per browser. Header filter dots per filterDefs.
        layoutKey={DELIVERIES_LAYOUT_KEY}
        filterDefs={filterDefs}
        selection={isAdmin ? { selected: selectedIds, onChange: setSelectedIds } : undefined}
        // Deliveries-scoped chrome: hairline border does the separation (no
        // shadow), slightly rounder corners, light-gray header band so the
        // header reads as distinct from the white body rows. Global DataTable
        // default untouched.
        wrapperClassName="shadow-none rounded-lg"
        className="[&>thead>tr>th]:bg-slate-50"
      />

      {/* Bulk bar — one bar for both selection kinds; actions render per kind. */}
      <BulkActionBar count={selectedIds.size + selectedProjectIds.size} onClear={clearSelection}>
        {selectedIds.size > 0 && (
          <>
            <Select onValueChange={(v) => void bulkSetStatus(v as DeliveryStatus)} disabled={bulkBusy}>
              <SelectTrigger className="h-8 w-[150px] text-xs" aria-label="Set status for selected deliveries">
                <SelectValue placeholder="Set status…" />
              </SelectTrigger>
              <SelectContent>
                {DELIVERY_STATUSES.map((s) => (
                  <SelectItem key={s} value={s}>
                    {STATUS_LANES[s]?.label ?? s}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <BulkActionBar.Action
              icon={Trash2}
              label={`Delete ${selectedIds.size}`}
              variant="destructive"
              loading={bulkBusy}
              onClick={() => setBulkDeleteOpen(true)}
            />
          </>
        )}
        {selectedProjectIds.size > 0 && (
          <BulkActionBar.Action
            icon={X}
            label={`Remove ${selectedProjectIds.size} from delivery`}
            loading={bulkBusy}
            onClick={() => void bulkRemoveProjects()}
          />
        )}
      </BulkActionBar>

      {/* Bulk delete confirmation — mirrors the board's single-delete dialog. */}
      <AlertDialog open={bulkDeleteOpen} onOpenChange={(open) => { if (!open) setBulkDeleteOpen(false); }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              Delete {selectedIds.size} deliver{selectedIds.size === 1 ? 'y' : 'ies'}?
            </AlertDialogTitle>
            <AlertDialogDescription>
              This action cannot be undone. The selected deliveries will be permanently removed.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel onClick={() => setBulkDeleteOpen(false)}>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => void bulkDelete()}
              className="bg-destructive text-white hover:bg-destructive/90"
            >
              Delete
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}
