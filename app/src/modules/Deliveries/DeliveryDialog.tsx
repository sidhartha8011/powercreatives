/**
 * DeliveryDialog — create + edit form for a single delivery.
 *
 * One dialog handles both modes. The parent passes either a `delivery`
 * (edit mode) or null (create mode); the dialog reads its open state
 * from the same useDeliveries hook that drives the board.
 *
 * Fields:
 *   - name        (required)
 *   - clientName  (optional)
 *   - status      (active / paused / completed — required, defaults to active)
 *
 * Submit is disabled while the relevant mutation is pending so users
 * cannot double-submit.
 */

import { useEffect, useState, type FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';

import { trpc } from '@/lib/trpc';
import { Checkbox } from '@/components/ui/checkbox';
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
    projectId?: number | null;
    seoSiteId?: number | null;
    modules?: string[];
    externalId?: string | null;
  }) => Promise<unknown>;
  /** Update handler. Resolves on server ack so the dialog can close. */
  onUpdate: (data: {
    id: number;
    name?: string;
    clientName?: string | null;
    status?: DeliveryStatus;
    type?: string | null;
    brandId?: number | null;
    projectId?: number | null;
    seoSiteId?: number | null;
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
  const [clientName, setClientName] = useState('');
  const [externalId, setExternalId] = useState('');
  const [status, setStatus] = useState<DeliveryStatus>('active');
  // '' = none. Picking a type pre-fills the module checkboxes from the
  // central preset (Settings → Delivery Types); checkboxes stay editable.
  const [type, setType] = useState('');
  const { presets: typePresets } = useTypePresets();
  // '' = none — Select values are strings; converted to number|null on submit.
  const [brandId, setBrandId] = useState('');
  const [projectId, setProjectId] = useState('');
  // SEO module — a connected site (Sites/SEO tab) chosen for this delivery.
  const [seoSiteId, setSeoSiteId] = useState('');
  // Module grants for assignees (nav ids).
  const [modules, setModules] = useState<string[]>([]);
  const [submitting, setSubmitting] = useState(false);

  const toggleModule = (id: string) =>
    setModules((prev) => (prev.includes(id) ? prev.filter((m) => m !== id) : [...prev, id]));

  const handleTypeChange = (next: string) => {
    const cleaned = next === 'none' ? '' : next;
    setType(cleaned);
    if (cleaned && typePresets[cleaned]) {
      setModules([...typePresets[cleaned].modules]);
    }
  };

  // Options for the brand/project linkage — the delivery carries these so
  // assigning it grants access to both.
  const { data: brandsRaw } = trpc.brands.list.useQuery();
  const { data: projectsRaw } = trpc.assets.getProjects.useQuery();
  // Connected sites for the SEO module — the same list shown in the SEO tab.
  const { data: sitesRaw } = trpc.sites.list.useQuery();
  const brands: { id: number; name: string }[] = Array.isArray(brandsRaw)
    ? brandsRaw.map((b: any) => ({ id: Number(b.id), name: b.name }))
    : [];
  const projects: { id: number; name: string }[] = Array.isArray(projectsRaw)
    ? projectsRaw.map((p: any) => ({ id: Number(p.id), name: p.name }))
    : [];
  const sites: { id: number; name: string }[] = Array.isArray(sitesRaw)
    ? sitesRaw.map((s: any) => ({ id: Number(s.id), name: String(s.name || s.url || `Site #${s.id}`) }))
    : [];

  // Sync form to the supplied delivery whenever the dialog opens. Both
  // modes (create / edit) reset state here so users get a clean form
  // every time the dialog appears.
  useEffect(() => {
    if (!open) return;
    if (delivery) {
      setName(delivery.name);
      setClientName(delivery.clientName ?? '');
      setExternalId(delivery.externalId ?? '');
      setStatus(delivery.status);
      setType(delivery.type ?? '');
      setBrandId(delivery.brandId ? String(delivery.brandId) : '');
      setProjectId(delivery.projectId ? String(delivery.projectId) : '');
      setSeoSiteId(delivery.seoSiteId ? String(delivery.seoSiteId) : '');
      setModules(Array.isArray(delivery.modules) ? delivery.modules : []);
    } else {
      setName('');
      setClientName('');
      setExternalId('');
      setStatus('active');
      setType('');
      setBrandId('');
      setProjectId('');
      setSeoSiteId('');
      setModules([]);
    }
    setSubmitting(false);
  }, [open, delivery]);

  const canSubmit = name.trim().length > 0 && !submitting;

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!canSubmit) return;

    setSubmitting(true);
    try {
      const trimmedExternalId = externalId.trim();
      if (isEdit && delivery) {
        const trimmedClient = clientName.trim();
        await onUpdate({
          id: delivery.id,
          name: name.trim(),
          clientName: trimmedClient.length > 0 ? trimmedClient : null,
          status,
          type: type || null,
          brandId: brandId ? Number(brandId) : null,
          projectId: projectId ? Number(projectId) : null,
          seoSiteId: seoSiteId ? Number(seoSiteId) : null,
          modules,
          externalId: trimmedExternalId.length > 0 ? trimmedExternalId : null,
        });
      } else {
        const trimmedClient = clientName.trim();
        await onCreate({
          name: name.trim(),
          ...(trimmedClient.length > 0 ? { clientName: trimmedClient } : {}),
          status,
          type: type || null,
          brandId: brandId ? Number(brandId) : null,
          projectId: projectId ? Number(projectId) : null,
          seoSiteId: seoSiteId ? Number(seoSiteId) : null,
          modules,
          externalId: trimmedExternalId.length > 0 ? trimmedExternalId : null,
        });
      }
      onClose();
    } catch {
      // Errors surface as toasts via the hook — keep the dialog open so
      // the user can adjust input and retry.
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={(o) => { if (!o) onClose(); }}>
      <DialogContent className="sm:max-w-md max-h-[90vh] overflow-y-auto">
        <form onSubmit={handleSubmit}>
          <DialogHeader>
            <DialogTitle>
              {isEdit ? 'Edit delivery' : 'New delivery'}
            </DialogTitle>
            <DialogDescription>
              {isEdit
                ? 'Update the name, client, or pipeline stage.'
                : 'Create a delivery to organize continual-fulfilment work for a client.'}
            </DialogDescription>
          </DialogHeader>

          <div className="grid gap-4 py-4">
            <div className="grid gap-2">
              <Label htmlFor="delivery-name">
                Name <span className="text-destructive">*</span>
              </Label>
              <Input
                id="delivery-name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="e.g. ACME — May campaign"
                autoFocus
                maxLength={256}
                required
              />
            </div>

            <div className="grid gap-2">
              <Label htmlFor="delivery-client">Client</Label>
              <Input
                id="delivery-client"
                value={clientName}
                onChange={(e) => setClientName(e.target.value)}
                placeholder="Client / account name (optional)"
                maxLength={256}
              />
            </div>

            <div className="grid gap-2">
              <Label htmlFor="delivery-external-id">External ID (eg. airtable or other PM tool)</Label>
              <Input
                id="delivery-external-id"
                value={externalId}
                onChange={(e) => setExternalId(e.target.value)}
                placeholder="Optional — used for webhook/automation mapping"
                maxLength={256}
              />
            </div>

            <div className="grid gap-2">
              <Label htmlFor="delivery-type">Type</Label>
              <p className="text-xs text-muted-foreground -mt-1">
                Picking a type pre-fills the modules it needs — you can still
                adjust the checkboxes below.
              </p>
              <Select
                value={type || 'none'}
                onValueChange={handleTypeChange}
              >
                <SelectTrigger id="delivery-type">
                  <SelectValue placeholder="No type" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">No type</SelectItem>
                  {Object.entries(typePresets).map(([id, preset]) => (
                    <SelectItem key={id} value={id}>{preset.label}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="grid gap-2">
              <Label htmlFor="delivery-brand">Brand</Label>
              <Select
                value={brandId || 'none'}
                onValueChange={(v) => setBrandId(v === 'none' ? '' : v)}
              >
                <SelectTrigger id="delivery-brand">
                  <SelectValue placeholder="No brand linked" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">No brand</SelectItem>
                  {brands.map((b) => (
                    <SelectItem key={b.id} value={String(b.id)}>{b.name}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="grid gap-2">
              <Label htmlFor="delivery-project">Project</Label>
              <Select
                value={projectId || 'none'}
                onValueChange={(v) => setProjectId(v === 'none' ? '' : v)}
              >
                <SelectTrigger id="delivery-project">
                  <SelectValue placeholder="No project linked" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">No project</SelectItem>
                  {projects.map((p) => (
                    <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="grid gap-2">
              <Label>Modules needed</Label>
              <p className="text-xs text-muted-foreground -mt-1">
                Assignees of this delivery get access to the checked modules
                (Ads includes the Copy + Image backends).
              </p>
              <div className="grid grid-cols-2 gap-1.5">
                {GRANTABLE_MODULES.map((m) => (
                  <div key={m.id} className="flex items-center gap-2">
                    <Checkbox
                      id={`delivery-module-${m.id}`}
                      checked={modules.includes(m.id)}
                      onCheckedChange={() => toggleModule(m.id)}
                    />
                    <Label htmlFor={`delivery-module-${m.id}`} className="cursor-pointer font-normal">
                      {m.label}
                    </Label>
                  </div>
                ))}
              </div>

              {/* SEO module — a dropdown of connected sites (the SEO tab's site
                  list), scoped to this caller. The chosen site is saved on the
                  delivery (seoSiteId). */}
              <div className="mt-2 grid gap-1.5">
                <Label htmlFor="delivery-seo-site" className="font-normal">SEO module — site</Label>
                <Select
                  value={seoSiteId || 'none'}
                  onValueChange={(v) => setSeoSiteId(v === 'none' ? '' : v)}
                >
                  <SelectTrigger id="delivery-seo-site">
                    <SelectValue placeholder="No site selected" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">No site</SelectItem>
                    {sites.map((s) => (
                      <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                    ))}
                    {sites.length === 0 && (
                      <div className="px-2 py-1.5 text-xs text-muted-foreground">No connected sites yet</div>
                    )}
                  </SelectContent>
                </Select>
              </div>
            </div>

            <div className="grid gap-2">
              <Label htmlFor="delivery-status">Status</Label>
              <Select
                value={status}
                onValueChange={(v) => setStatus(v as DeliveryStatus)}
              >
                <SelectTrigger id="delivery-status">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {DELIVERY_STATUSES.map((s) => (
                    <SelectItem key={s} value={s}>
                      {STATUS_LABELS[s]}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>

          <DialogFooter>
            <Button
              type="button"
              variant="ghost"
              onClick={onClose}
              disabled={submitting}
            >
              Cancel
            </Button>
            <Button type="submit" disabled={!canSubmit}>
              {submitting
                ? isEdit
                  ? 'Saving…'
                  : 'Creating…'
                : isEdit
                  ? 'Save changes'
                  : 'Create delivery'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
