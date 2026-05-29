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
 *
 * v1.11.0 collapsed the previous 'create' stage into 'launch' — client
 * approval now auto-advances straight to 'launch'. See
 * PCM_Approvals_Service::LEGACY_STATUS_MAP for the data-side migration.
 */
export type ApprovalStatus =
  | 'draft'
  | 'internal'
  | 'client'
  | 'launch'
  | 'live'
  | 'archived';

export const APPROVAL_STATUSES: ReadonlyArray<ApprovalStatus> = [
  'draft',
  'internal',
  'client',
  'launch',
  'live',
  'archived',
] as const;

/**
 * Statuses where the set has been submitted by the client and now lives in the
 * team's post-review pipeline. The client review surface MUST be read-only
 * when the server reports any of these — `submit_review` is not idempotent on
 * the server (re-fires the webhook on every call), so the client surface is
 * the last line of defence against accidental re-submission after refresh.
 */
export const POST_SUBMIT_STATUSES: ReadonlyArray<ApprovalStatus> = ['launch', 'live', 'archived'];

export function isPostSubmitStatus(status: ApprovalStatus | undefined | null): boolean {
  return status != null && POST_SUBMIT_STATUSES.includes(status);
}

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

/**
 * Valid statuses for a single comment in a review thread.
 */
export type CommentStatus = 'New' | 'Team reply' | 'Done';

/**
 * A single comment entry in a review thread.
 * Supports threading via parentId (flat array with references).
 */
export interface CommentEntry {
  id: string;
  author: string;
  text: string;
  createdAt: string;
  status: CommentStatus;
  parentId: string | null;
  attachments?: string[];
  readBy?: ('client' | 'team')[];
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
    comments: Record<string, CommentEntry[]>;
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
