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
import { Maximize2, Plus, X } from 'lucide-react';

import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { Button } from '@/components/ui/button';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { relTime } from '@/components/shared/EntityCard';
import { cn } from '@/lib/utils';
import { trpc } from '@/lib/trpc';
import { useApp } from '@/contexts/AppContext';

import { AddProjectMenu, useDeliveryProjects } from '../DeliveryProjects';
import { deliveryColumns, type DeliveryColumn } from '../kanban/deliveryColumns';
import { useDeliveries, type UpdateDeliveryInput } from '../hooks/useDeliveries';
import { useTypePresets } from '../hooks/useTypePresets';
import { DELIVERY_STATUSES, type Delivery, type DeliveryStatus } from '../types';

/** Lane declarations by status id — the pills borrow the Kanban accents. */
const STATUS_LANES: Partial<Record<DeliveryStatus, DeliveryColumn>> = Object.fromEntries(
  deliveryColumns.map((c) => [c.id, c])
);

function StatusPill({ status }: { status: DeliveryStatus }) {
  const lane = STATUS_LANES[status];
  if (!lane) return <span>{status}</span>;
  return (
    <span
      className="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] leading-none font-medium"
      style={{ background: lane.accentColor, color: lane.accentText }}
    >
      {lane.label}
    </span>
  );
}

/** Sentinel for the "none" option in in-place selects. */
const NONE_VALUE = '__none__';

/**
 * Borderless in-place text editor — the cell IS the input. Card auto-save
 * contract: commit on blur/Enter, Esc cancels, revert the draft on failure.
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
  const [draft, setDraft] = useState(value);
  // Esc must skip the commit that its own blur fires (blur reads a stale
  // closure otherwise) — flagged via ref, not state.
  const cancelled = useRef(false);
  useEffect(() => {
    setDraft(value);
  }, [value]);

  const commit = () => {
    if (cancelled.current) {
      cancelled.current = false;
      setDraft(value);
      return;
    }
    const next = draft.trim();
    if (next === value) return;
    if (required && next.length === 0) {
      setDraft(value);
      return;
    }
    onSave(next).catch(() => setDraft(value));
  };

  return (
    <input
      value={draft}
      placeholder={placeholder}
      aria-label={ariaLabel}
      onChange={(e) => setDraft(e.target.value)}
      onBlur={commit}
      onKeyDown={(e) => {
        if (e.key === 'Enter') e.currentTarget.blur();
        if (e.key === 'Escape') {
          cancelled.current = true;
          e.currentTarget.blur();
        }
      }}
      className={cn(
        'h-7 w-full rounded bg-transparent px-1 outline-none placeholder:text-muted-foreground/60 hover:bg-slate-50 focus:bg-white focus:ring-1 focus:ring-ring',
        className
      )}
    />
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
 * Accordion content: the delivery's projects as native rows of the parent
 * grid. Cell mapping under the delivery columns: chevron (empty) | project
 * name (indented) | site select | Images | Copy | Videos | ✕ — the labeled
 * jump buttons self-describe, so no second header row. Trailing row = the
 * same AddProjectMenu the delivery card has.
 */
function ProjectSubRows({ delivery }: { delivery: Delivery }) {
  const { navigateToProjectTab } = useApp();
  const {
    inDelivery,
    available,
    sites,
    assignProject,
    unassignProject,
    setProjectSite,
    sitePending,
    isLoading,
  } = useDeliveryProjects(delivery);

  if (isLoading) {
    return (
      <tr>
        <td />
        <td colSpan={6} className="text-muted-foreground">
          Loading projects…
        </td>
      </tr>
    );
  }

  const jumpCell = (projectId: number, label: string, tab: 'media' | 'copy') => (
    <Button
      type="button"
      variant="ghost"
      size="sm"
      className="h-7 px-2 text-xs text-muted-foreground hover:text-primary"
      onClick={() => navigateToProjectTab({ projectId, tab })}
    >
      {label}
    </Button>
  );

  return (
    <>
      {inDelivery.map((p) => (
        <tr key={`project-${p.id}`}>
          <td />
          <td>
            {/* Indent = hierarchy; same row anatomy as the parent otherwise. */}
            <span className="block truncate pl-4">{p.name}</span>
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
          <td>{jumpCell(p.id, 'Images', 'media')}</td>
          <td>{jumpCell(p.id, 'Copy', 'copy')}</td>
          <td>{jumpCell(p.id, 'Videos', 'media')}</td>
          <td className="text-right">
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="h-7 w-7 p-0 text-muted-foreground/60 hover:text-destructive"
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
        <td />
        <td colSpan={6}>
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
}

export function DeliveriesTable({ items, onEdit }: DeliveriesTableProps) {
  // Warm the shared project/site caches at table mount so expanding a row is
  // instant client-side filtering, not a first-expand network wait (the
  // sub-row hook reads these exact query keys).
  trpc.assets.getProjects.useQuery();
  trpc.sites.list.useQuery();

  const { updateDelivery } = useDeliveries();
  const patch = (id: number, input: Omit<UpdateDeliveryInput, 'id'>) =>
    updateDelivery({ id, ...input });

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
        sortAccessor: (d) => d.name.toLowerCase(),
        cell: (d) => (
          <div className="flex items-center gap-1">
            <InlineTextCell
              value={d.name}
              required
              ariaLabel={`Name of ${d.name}`}
              className="font-medium"
              onSave={(next) => patch(d.id, { name: next })}
            />
            {/* Always visible (group-hover is a no-op on coarse pointers) — quiet at rest. */}
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="h-6 w-6 shrink-0 p-0 text-muted-foreground/50 hover:text-foreground"
              title="Open delivery card"
              aria-label={`Open ${d.name}`}
              onClick={() => onEdit(d)}
            >
              <Maximize2 className="h-3.5 w-3.5" />
            </Button>
          </div>
        ),
      },
      {
        key: 'client',
        header: 'Client',
        sortAccessor: (d) => d.clientName?.toLowerCase() ?? null,
        cell: (d) => (
          <InlineTextCell
            value={d.clientName ?? ''}
            placeholder="—"
            ariaLabel={`Client of ${d.name}`}
            onSave={(next) => patch(d.id, { clientName: next.length > 0 ? next : null })}
          />
        ),
      },
      {
        key: 'brand',
        header: 'Brand',
        sortAccessor: (d) =>
          d.brandId != null ? brandLabel.get(String(Number(d.brandId)))?.toLowerCase() ?? null : null,
        cell: (d) => (
          <InlineSelectCell
            value={d.brandId != null ? String(Number(d.brandId)) : null}
            options={brandOptions}
            noneLabel="No brand"
            ariaLabel={`Brand of ${d.name}`}
            onSave={(v) => patch(d.id, { brandId: v != null ? Number(v) : null })}
          />
        ),
      },
      {
        key: 'type',
        header: 'Type',
        sortAccessor: (d) => (d.type ? (typePresets[d.type]?.label ?? d.type).toLowerCase() : null),
        cell: (d) => (
          <InlineSelectCell
            value={d.type ?? null}
            options={typeOptions}
            noneLabel="No type"
            ariaLabel={`Type of ${d.name}`}
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
        key: 'status',
        header: 'Status',
        width: 120,
        // Pipeline order (lane order), not alphabetical.
        sortAccessor: (d) => DELIVERY_STATUSES.indexOf(d.status),
        cell: (d) => (
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
        ),
      },
      {
        key: 'updated',
        header: 'Updated',
        width: 110,
        sortAccessor: (d) => d.updatedAt,
        cell: (d) => <span className="text-muted-foreground">{relTime(d.updatedAt)}</span>,
      },
    ],
    // eslint-disable-next-line react-hooks/exhaustive-deps — patch/onEdit are stable enough per render
    [brandOptions, brandLabel, typeOptions, typePresets, onEdit]
  );

  return (
    <DataTable<Delivery>
      columns={columns}
      data={[...items]}
      rowKey={(d) => d.id}
      defaultSortKey="updated"
      defaultSortDir="desc"
      renderSubRows={(d) => <ProjectSubRows delivery={d} />}
      emptyMessage="No deliveries match your filters."
    />
  );
}
