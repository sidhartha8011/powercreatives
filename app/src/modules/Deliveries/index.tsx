/**
 * CREATIVE MACHINE — Deliveries Module
 *
 * Continual-fulfilment client deliveries organised as a Kanban pipeline
 * (Active / Paused / Completed). Reuses the shared Kanban primitive
 * (`@/components/shared/Kanban`) so visuals + DnD stay in sync with
 * Approvals.
 *
 * Architecture:
 *   - useDeliveries        — data + mutations (called inside the Board)
 *   - DeliveriesBoard      — filter/sort bar, Kanban, delete-confirm
 *   - DeliveryCard         — per-card chrome (click = edit, drag = move)
 *   - DeliveryDialog       — create + edit form (one dialog, two modes)
 *
 * Dialog state lives here at the module level (not inside useDeliveries)
 * so both the Board and the Module can request opens/closes without
 * state desync — React state is per-hook-instance, so two callers of
 * the same hook get two separate state slots.
 */

import { useCallback, useState } from 'react';
import { Plus } from 'lucide-react';

import { ModuleHeader } from '@/components/shared/ModuleHeader';
import { Button } from '@/components/ui/button';

import { DeliveryDialog } from './DeliveryDialog';
import { DeliveriesBoard } from './kanban/DeliveriesBoard';
import { useDeliveries } from './hooks/useDeliveries';
import type { Delivery } from './types';

export function DeliveriesModule() {
  const { createDelivery, updateDelivery } = useDeliveries();

  const [editDelivery, setEditDelivery] = useState<Delivery | null>(null);
  const [isCreateOpen, setIsCreateOpen] = useState(false);

  const openCreate = useCallback(() => {
    setEditDelivery(null);
    setIsCreateOpen(true);
  }, []);

  const openEdit = useCallback((delivery: Delivery) => {
    setIsCreateOpen(false);
    setEditDelivery(delivery);
  }, []);

  const closeDialog = useCallback(() => {
    setEditDelivery(null);
    setIsCreateOpen(false);
  }, []);

  const dialogOpen = isCreateOpen || editDelivery !== null;

  return (
    // Board-module convention: white inline bg + flex column layout,
    // same shape Approvals uses. Deliveries is a Kanban board, so it
    // follows the board-module pattern (not the .module-container grey
    // pattern used by Brands / Templates / Settings / Integrations).
    // The Kanban primitive's tokens.css explicitly assumes a white page
    // background for lane contrast.
    <div
      className="h-full flex flex-col p-4 animate-fade-in"
      style={{ background: '#ffffff' }}
    >
      <ModuleHeader
        title="Deliveries"
        description="Continual-fulfilment client deliveries organised as a Kanban pipeline."
        action={
          <Button onClick={openCreate} className="gap-2">
            <Plus className="w-4 h-4" />
            New Delivery
          </Button>
        }
      />

      <DeliveriesBoard onCreate={openCreate} onEdit={openEdit} />

      <DeliveryDialog
        open={dialogOpen}
        delivery={editDelivery}
        onClose={closeDialog}
        onCreate={createDelivery}
        onUpdate={updateDelivery}
      />
    </div>
  );
}
