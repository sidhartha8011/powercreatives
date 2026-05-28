/**
 * Delivery columns declaration.
 *
 * One line per column. Adding / removing / re-ordering happens here and
 * only here. Status IDs match PCM_Deliveries_Service::STATUSES (PHP).
 * Colors borrow from the same palette family Approvals uses (warm
 * neutrals + amber + greys) so the two pipelines feel like siblings.
 */

import type { KanbanColumn } from '@/components/shared/Kanban';

import type { DeliveryStatus } from '../types';

export interface DeliveryColumn extends KanbanColumn {
  id: DeliveryStatus;
}

export const deliveryColumns: ReadonlyArray<DeliveryColumn> = [
  {
    id: 'active',
    label: 'Active',
    accentColor: '#dcfce7',
    accentText:  '#166534',
    dotColor:    '#4a9d4a',
  },
  {
    id: 'paused',
    label: 'Paused',
    accentColor: '#fef3c7',
    accentText:  '#92400e',
  },
  {
    id: 'completed',
    label: 'Completed',
    accentColor: '#e7edf3',
    accentText:  '#6e7a8a',
  },
];
