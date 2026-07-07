/**
 * DeliveriesTable — the table view of the deliveries pipeline.
 *
 * Built on the global DataTable (`@/components/ui/data-table`) — the same
 * minimalist grid Sites and the SEO table use. Every column is header-click
 * sortable; a row click opens the delivery card (exactly like clicking a
 * Kanban card); the chevron expands the row into the SAME projects list the
 * delivery card shows (DeliveryProjectsBody — one source, two surfaces).
 *
 * Receives the already-filtered rows from the board's shared list state, so
 * search / client filters apply identically in both views. Status pills reuse
 * the Kanban lane colors (deliveryColumns) — one palette, two views.
 */

import { useMemo } from 'react';

import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { relTime } from '@/components/shared/EntityCard';
import { trpc } from '@/lib/trpc';

import { DeliveryProjectsBody, useDeliveryProjects } from '../DeliveryProjects';
import { deliveryColumns, type DeliveryColumn } from '../kanban/deliveryColumns';
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

/** Accordion content: the delivery's projects, one source with the card. */
function ProjectsPanel({ delivery }: { delivery: Delivery }) {
  const state = useDeliveryProjects(delivery);
  return (
    <div className="px-10 py-3">
      <DeliveryProjectsBody state={state} />
    </div>
  );
}

export interface DeliveriesTableProps {
  /** Already-filtered rows (the board's shared list state). */
  items: ReadonlyArray<Delivery>;
  /** Open the delivery card — same action as clicking a Kanban card. */
  onEdit: (delivery: Delivery) => void;
}

export function DeliveriesTable({ items, onEdit }: DeliveriesTableProps) {
  const { data: brandsRaw } = trpc.brands.list.useQuery();
  const brandNames = useMemo(() => {
    const map = new Map<number, string>();
    if (Array.isArray(brandsRaw)) {
      for (const b of brandsRaw as any[]) map.set(Number(b.id), String(b.name ?? ''));
    }
    return map;
  }, [brandsRaw]);

  const { presets: typePresets } = useTypePresets();

  const columns: DataTableColumn<Delivery>[] = useMemo(
    () => [
      {
        key: 'name',
        header: 'Delivery',
        sortAccessor: (d) => d.name.toLowerCase(),
        cell: (d) => <span className="font-medium">{d.name}</span>,
      },
      {
        key: 'client',
        header: 'Client',
        sortAccessor: (d) => d.clientName?.toLowerCase() ?? null,
        cell: (d) => d.clientName ?? <span className="text-muted-foreground">—</span>,
      },
      {
        key: 'brand',
        header: 'Brand',
        sortAccessor: (d) => (d.brandId != null ? brandNames.get(Number(d.brandId))?.toLowerCase() ?? null : null),
        cell: (d) => {
          const name = d.brandId != null ? brandNames.get(Number(d.brandId)) : undefined;
          return name ?? <span className="text-muted-foreground">—</span>;
        },
      },
      {
        key: 'type',
        header: 'Type',
        sortAccessor: (d) => (d.type ? (typePresets[d.type]?.label ?? d.type).toLowerCase() : null),
        cell: (d) =>
          d.type ? (typePresets[d.type]?.label ?? d.type) : <span className="text-muted-foreground">—</span>,
      },
      {
        key: 'status',
        header: 'Status',
        width: 110,
        // Pipeline order (lane order), not alphabetical.
        sortAccessor: (d) => DELIVERY_STATUSES.indexOf(d.status),
        cell: (d) => <StatusPill status={d.status} />,
      },
      {
        key: 'updated',
        header: 'Updated',
        width: 120,
        sortAccessor: (d) => d.updatedAt,
        cell: (d) => <span className="text-muted-foreground">{relTime(d.updatedAt)}</span>,
      },
    ],
    [brandNames, typePresets]
  );

  return (
    <DataTable<Delivery>
      columns={columns}
      data={[...items]}
      rowKey={(d) => d.id}
      onRowClick={onEdit}
      defaultSortKey="updated"
      defaultSortDir="desc"
      renderExpanded={(d) => <ProjectsPanel delivery={d} />}
      emptyMessage="No deliveries match your filters."
    />
  );
}
