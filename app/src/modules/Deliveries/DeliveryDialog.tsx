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

import {
  DELIVERY_STATUSES,
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
  }) => Promise<unknown>;
  /** Update handler. Resolves on server ack so the dialog can close. */
  onUpdate: (data: {
    id: number;
    name?: string;
    clientName?: string | null;
    status?: DeliveryStatus;
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
  const [status, setStatus] = useState<DeliveryStatus>('active');
  const [submitting, setSubmitting] = useState(false);

  // Sync form to the supplied delivery whenever the dialog opens. Both
  // modes (create / edit) reset state here so users get a clean form
  // every time the dialog appears.
  useEffect(() => {
    if (!open) return;
    if (delivery) {
      setName(delivery.name);
      setClientName(delivery.clientName ?? '');
      setStatus(delivery.status);
    } else {
      setName('');
      setClientName('');
      setStatus('active');
    }
    setSubmitting(false);
  }, [open, delivery]);

  const canSubmit = name.trim().length > 0 && !submitting;

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!canSubmit) return;

    setSubmitting(true);
    try {
      if (isEdit && delivery) {
        const trimmedClient = clientName.trim();
        await onUpdate({
          id: delivery.id,
          name: name.trim(),
          clientName: trimmedClient.length > 0 ? trimmedClient : null,
          status,
        });
      } else {
        const trimmedClient = clientName.trim();
        await onCreate({
          name: name.trim(),
          ...(trimmedClient.length > 0 ? { clientName: trimmedClient } : {}),
          status,
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
      <DialogContent className="sm:max-w-md">
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
