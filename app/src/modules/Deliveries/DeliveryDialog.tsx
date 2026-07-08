/**
 * DeliveryDialog — the delivery detail card, built on the shared EntityCard
 * primitive (reference consumer).
 *
 * Composition:
 *   <EntityCard>                       ← shell: white, 5xl, one scroll, no footer
 *     <EntityCardTitle>                ← name, edited in place, "Saved ✓" whisper
 *     <PropertyTable>                  ← Status | Client | Type | Module access |
 *                                        Brand | External ID (declarative defs)
 *     <ProjectsSection> <LogSection>   ← Deliveries-owned domain sections
 *
 * Edit mode auto-saves per field, silently (errors toast AND restore the
 * previous value — the card never shows unsaved state as saved). Create mode
 * fills the same cells locally and submits with one button.
 *
 * Type drives the module preset; the Module access cell is the custom
 * override. Retired earlier (v1.35.0): seoSiteId + the single Project
 * access-grant select.
 */

import { useEffect, useRef, useState } from 'react';
import { Send } from 'lucide-react';
import { toast } from 'sonner';

import {
  CARD_TYPE,
  EntityCard,
  EntityCardSection,
  EntityCardTitle,
  PropertyTable,
  relTime,
  type PropertyDef,
} from '@/components/shared/EntityCard';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

import { trpc } from '@/lib/trpc';
import { DeliveryProjectsBody, ProjectAddActions, useDeliveryProjects } from './DeliveryProjects';
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

/** Status dot colors — matches the Kanban lane semantics. */
const STATUS_DOTS: Record<DeliveryStatus, string> = {
  active: '#16a34a',
  paused: '#d97706',
  completed: '#94a3b8',
};

export interface DeliveryDialogProps {
  /** Open state — controlled by the parent (useDeliveries hook). */
  open: boolean;
  /** Existing delivery in edit mode; null in create mode. */
  delivery: Delivery | null;
  /** Called when the dialog wants to close (overlay click, X, Esc). */
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

export function DeliveryDialog({
  open,
  delivery,
  onClose,
  onCreate,
  onUpdate,
}: DeliveryDialogProps) {
  const isEdit = delivery !== null;

  const [name, setName] = useState('');
  const [externalId, setExternalId] = useState('');
  const [status, setStatus] = useState<DeliveryStatus>('active');
  const [type, setType] = useState('');
  const { presets: typePresets } = useTypePresets();
  const [brandId, setBrandId] = useState('');
  const [modules, setModules] = useState<string[]>([]);
  const [submitting, setSubmitting] = useState(false);

  // Transient "Saved ✓" whisper next to the title (spec: silent saves).
  const [saved, setSaved] = useState(false);
  const savedTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const flashSaved = () => {
    setSaved(true);
    if (savedTimer.current) clearTimeout(savedTimer.current);
    savedTimer.current = setTimeout(() => setSaved(false), 1500);
  };

  const { data: brandsRaw } = trpc.brands.list.useQuery();
  const brands: { id: number; name: string }[] = Array.isArray(brandsRaw)
    ? brandsRaw.map((b: any) => ({ id: Number(b.id), name: b.name }))
    : [];

  // Sync form state whenever the card opens (both modes reset here).
  useEffect(() => {
    if (!open) return;
    if (delivery) {
      setName(delivery.name);
      setExternalId(delivery.externalId ?? '');
      setStatus(delivery.status);
      setType(delivery.type ?? '');
      setBrandId(delivery.brandId ? String(delivery.brandId) : '');
      setModules(Array.isArray(delivery.modules) ? delivery.modules : []);
    } else {
      setName('');
      setExternalId('');
      setStatus('active');
      setType('');
      setBrandId('');
      setModules([]);
    }
    setSaved(false);
    setSubmitting(false);
  }, [open, delivery]);

  /**
   * Auto-save one field (edit mode): apply optimistically, whisper "Saved ✓"
   * on ack, RESTORE the previous state on failure — the card never displays
   * unsaved state as saved. Errors toast via the useDeliveries hook.
   */
  const persist = (patch: Record<string, unknown>, apply: () => void, revert: () => void) => {
    apply();
    if (!isEdit || !delivery) return;
    onUpdate({ id: delivery.id, ...patch }).then(flashSaved).catch(revert);
  };

  const handleTypeChange = (next: string | null) => {
    const cleaned = next ?? '';
    const prevType = type;
    const prevModules = modules;
    // Type applies the central preset; the Module access cell is the override.
    const presetModules = cleaned && typePresets[cleaned] ? [...typePresets[cleaned].modules] : null;
    persist(
      { type: cleaned || null, ...(presetModules ? { modules: presetModules } : {}) },
      () => { setType(cleaned); if (presetModules) setModules(presetModules); },
      () => { setType(prevType); setModules(prevModules); },
    );
  };

  // ── Declarative property row (order per spec: Status·Type·Brand·Modules·ID). ──
  const properties: PropertyDef[] = [
    {
      control: 'select', key: 'status', label: 'Status', width: '14%',
      value: status,
      noneLabel: '—',
      options: DELIVERY_STATUSES.map((s) => ({ value: s, label: STATUS_LABELS[s], dot: STATUS_DOTS[s] })),
      onSave: (v) => {
        if (!v) return; // status is never empty
        const prev = status;
        persist({ status: v as DeliveryStatus }, () => setStatus(v as DeliveryStatus), () => setStatus(prev));
      },
    },
    {
      control: 'select', key: 'type', label: 'Type', width: '20%',
      value: type || null,
      noneLabel: 'No type',
      options: Object.entries(typePresets).map(([id, preset]) => ({ value: id, label: preset.label })),
      onSave: handleTypeChange,
    },
    {
      control: 'select', key: 'brand', label: 'Brand', width: '20%',
      value: brandId || null,
      noneLabel: 'No brand',
      options: brands.map((b) => ({ value: String(b.id), label: b.name })),
      onSave: (v) => {
        const prev = brandId;
        persist({ brandId: v ? Number(v) : null }, () => setBrandId(v ?? ''), () => setBrandId(prev));
      },
    },
    {
      control: 'multiToggle', key: 'modules', label: 'Modules', width: '26%',
      values: modules,
      options: GRANTABLE_MODULES.map((m) => ({ id: m.id, label: m.label })),
      popoverLabel: 'Module access (Ads includes Copy + Image)',
      onSave: (next) => {
        const prev = modules;
        persist({ modules: next }, () => setModules(next), () => setModules(prev));
      },
    },
    {
      control: 'text', key: 'externalId', label: 'ID', width: '20%',
      value: externalId, placeholder: 'Empty',
      onSave: (v) => {
        const prev = externalId;
        persist({ externalId: v.length > 0 ? v : null }, () => setExternalId(v), () => setExternalId(prev));
      },
    },
  ];

  // ── Create mode: same cells, local state only, one primary action. ──
  const canSubmit = name.trim().length > 0 && !submitting;
  const handleCreate = async () => {
    if (!canSubmit) return;
    setSubmitting(true);
    try {
      const trimmedExternalId = externalId.trim();
      await onCreate({
        name: name.trim(),
        status,
        type: type || null,
        brandId: brandId ? Number(brandId) : null,
        modules,
        externalId: trimmedExternalId.length > 0 ? trimmedExternalId : null,
      });
      onClose();
    } catch {
      // Errors surface as toasts via the hook — keep the card open.
      setSubmitting(false);
    }
  };

  return (
    <EntityCard
      open={open}
      onClose={onClose}
      ariaTitle={isEdit ? 'Edit delivery' : 'New delivery'}
      ariaDescription={isEdit
        ? 'Delivery card — every field saves automatically.'
        : 'Create a delivery to organize continual-fulfilment work for a client.'}
    >
      <EntityCardTitle
        value={name}
        placeholder="Untitled delivery"
        meta={isEdit && delivery?.updatedAt ? `Edited ${relTime(delivery.updatedAt)}` : undefined}
        autoFocus={!isEdit}
        saved={saved}
        onSave={(next) => {
          const prev = name;
          if (isEdit) {
            persist({ name: next }, () => setName(next), () => setName(prev));
          } else {
            setName(next);
          }
        }}
      />

      <PropertyTable properties={properties} />

      {isEdit && delivery && <ProjectsSection delivery={delivery} onCardClose={onClose} />}
      {isEdit && delivery && <LogSection delivery={delivery} />}

      {!isEdit && (
        <div className="mt-6 flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose} disabled={submitting}>Cancel</Button>
          <Button type="button" onClick={() => void handleCreate()} disabled={!canSubmit}>
            {submitting ? 'Creating…' : 'Create delivery'}
          </Button>
        </div>
      )}
    </EntityCard>
  );
}

/**
 * Projects in this delivery — projects.deliveryId = this delivery. The whole
 * surface (data hook + add menu + table) lives in DeliveryProjects.tsx, shared
 * with the Deliveries table's accordion rows; this section is card glue only.
 * Mounted only while the card is open in edit mode.
 */
function ProjectsSection({ delivery, onCardClose }: { delivery: Delivery; onCardClose: () => void }) {
  const state = useDeliveryProjects(delivery);

  return (
    <EntityCardSection
      title="Projects"
      action={state.inDelivery.length > 0 ? <ProjectAddActions state={state} /> : undefined}
    >
      <DeliveryProjectsBody state={state} onNavigated={onCardClose} />
    </EntityCardSection>
  );
}

/** How many log entries show before the "Show all" expander. */
const LOG_PREVIEW_COUNT = 10;

/**
 * Work log — append-only "what was done" entries (pcm_delivery_logs), newest
 * first. The composer input is the empty state; Enter or the send affordance
 * submits. Mounted only while the card is open in edit mode.
 */
function LogSection({ delivery }: { delivery: Delivery }) {
  const [note, setNote] = useState('');
  const [showAll, setShowAll] = useState(false);
  const { data: logsRaw, refetch } = trpc.deliveries.logs.useQuery({ id: Number(delivery.id) });
  const logs: { id: number; note: string; userName: string; createdAt: string }[] = Array.isArray(logsRaw)
    ? (logsRaw as any[]).map((l) => ({
        id: Number(l.id),
        note: String(l.note ?? ''),
        userName: String(l.userName ?? ''),
        createdAt: String(l.createdAt ?? ''),
      }))
    : [];
  const visible = showAll ? logs : logs.slice(0, LOG_PREVIEW_COUNT);

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
    <EntityCardSection title="Log">
      {/* Composer — the input IS the empty state. */}
      <div className="relative mb-3">
        <Input
          value={note}
          onChange={(e) => setNote(e.target.value)}
          placeholder="Write what was done…"
          aria-label="Write what was done"
          maxLength={2000}
          className={`h-9 pr-10 ${CARD_TYPE.BODY}`}
          onKeyDown={(e) => {
            // Enter and ⌘/Ctrl+Enter both submit; never the surrounding form.
            if (e.key === 'Enter') {
              e.preventDefault();
              void addEntry();
            }
          }}
        />
        <Button
          type="button"
          variant="ghost"
          size="sm"
          aria-label="Add log entry"
          className="absolute right-1 top-1/2 h-7 w-7 -translate-y-1/2 p-0 text-muted-foreground disabled:opacity-30"
          disabled={addMutation.isPending || !note.trim()}
          onClick={() => void addEntry()}
        >
          <Send className="h-3.5 w-3.5" />
        </Button>
      </div>

      {visible.length > 0 && (
        <ul className="space-y-2">
          {visible.map((l) => (
            <li key={l.id} className="rounded-md border px-3 py-2">
              <p className={`whitespace-pre-wrap ${CARD_TYPE.BODY}`}>{l.note}</p>
              <p
                className={`mt-1 ${CARD_TYPE.LABEL}`}
                title={l.createdAt ? new Date(l.createdAt.replace(' ', 'T')).toLocaleString() : undefined}
              >
                {l.userName} · {relTime(l.createdAt)}
              </p>
            </li>
          ))}
        </ul>
      )}
      {!showAll && logs.length > LOG_PREVIEW_COUNT && (
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className={`mt-2 h-7 ${CARD_TYPE.LABEL}`}
          onClick={() => setShowAll(true)}
        >
          Show all ({logs.length})
        </Button>
      )}
    </EntityCardSection>
  );
}
