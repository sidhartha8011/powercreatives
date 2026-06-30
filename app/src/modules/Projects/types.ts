export interface Project {
  id: number;
  name: string;
  description?: string;
  status: string;
  type: string;
  assetCount: number;
  images: string[];
  /** The delivery this project belongs to (Brand → Delivery → Project). */
  deliveryId?: number | null;
  /** Brand derived live from the project's delivery (read-only; not stored on the project). */
  brandId?: number | null;
  /** Optional external identifier — used for webhook/automation mapping. */
  externalId?: string | null;
  createdAt: string;
}
