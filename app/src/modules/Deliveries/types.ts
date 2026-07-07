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
  /** Delivery type (preset key from pcmConfig.deliveryTypePresets) or null. */
  type?: string | null;
  /** Linked brand/project — assigning this delivery grants access to both. */
  brandId?: number | null;
  projectId?: number | null;
  /** Module grants for assignees (frontend nav ids, e.g. 'copy', 'ads'). */
  modules?: string[];
  /** Optional external identifier — used for webhook/automation mapping. */
  externalId?: string | null;
  /** Assigned team members (read-only enrichment on the list route; assigning
   *  itself lives in the Users module). */
  assignees?: { id: number; name: string }[];
  createdAt: string;
  updatedAt: string;
}

/**
 * Modules a delivery can grant (keep in sync with
 * PCM_Deliveries_Service::GRANTABLE_MODULES). 'ads' implies the copy+image
 * backends — Ads is a frontend over both.
 */
export const GRANTABLE_MODULES: ReadonlyArray<{ id: string; label: string }> = [
  { id: 'copy', label: 'Copy' },
  { id: 'ads', label: 'Ads' },
  { id: 'image', label: 'Image' },
  { id: 'video', label: 'Video' },
  { id: 'writer', label: 'Writer' },
  { id: 'keywords', label: 'Keywords' },
  { id: 'strategies', label: 'Strategies' },
  { id: 'sites', label: 'Sites' },
];
