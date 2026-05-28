/**
 * Deliveries — domain types.
 *
 * Keep in sync with `PCM_Deliveries_Service::STATUSES` (PHP). The order of
 * the array also drives the Kanban column order.
 */

export const DELIVERY_STATUSES = ['active', 'paused', 'completed'] as const;

export type DeliveryStatus = (typeof DELIVERY_STATUSES)[number];

export interface Delivery {
  id: number;
  userId: number;
  name: string;
  clientName: string | null;
  status: DeliveryStatus;
  createdAt: string;
  updatedAt: string;
}
