/**
 * Approvals Module — Domain Types
 *
 * Single source of truth for the shapes the Approvals UI consumes.
 * Mirrors the PHP side (PCM_Approvals_Service::STATUSES).
 */

import type { StatusKey } from '@/components/shared/design-tokens';

/**
 * Canonical 6-value approval set lifecycle. Order matches the kanban
 * left-to-right flow. Kept in sync with PCM_Approvals_Service::STATUSES.
 */
export type ApprovalStatus =
  | 'draft'
  | 'internal'
  | 'client'
  | 'approved'
  | 'live'
  | 'archived';

export const APPROVAL_STATUSES: ReadonlyArray<ApprovalStatus> = [
  'draft',
  'internal',
  'client',
  'approved',
  'live',
  'archived',
] as const;

/**
 * A single creative asset inside an approval-set snapshot. The exact shape
 * depends on whether it's media or copy — both share the id + type fields.
 */
export interface SnapshotAsset {
  id: string;
  type?: string;
  url?: string;
  headline?: string;
  body?: string;
  audienceName?: string;
  angleName?: string;
  [key: string]: unknown;
}

export interface ApprovalSet {
  id: number;
  userId: number;
  brandId?: number | null;
  projectId?: number | null;
  name: string;
  token: string;
  status: ApprovalStatus;
  snapshot: {
    brandName?: string;
    brandLogoUrl?: string;
    projectName?: string;
    media: SnapshotAsset[];
    copy: SnapshotAsset[];
  };
  reviewFeedback?: {
    approvedVisualIds: string[];
    approvedCopyIds: string[];
    comments: Record<string, string>;
  } | null;
  createdAt: string;
  updatedAt: string;
}

/**
 * Article shape consumed by the Articles kanban. Mirrors the trpc.articles
 * response. `status` reuses the existing StatusKey design-token taxonomy
 * (draft / review / ready / published) — different lifecycle from sets.
 */
export interface Article {
  id: number;
  title: string;
  slug?: string;
  status: StatusKey;
  strategyId?: number;
  brandId?: number;
  publishedUrl?: string;
  createdAt: string;
  updatedAt: string;
}
