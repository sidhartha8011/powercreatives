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
 * A custom approval asset — a free-form, Notion-style document authored in the
 * Approvals board with the shared Tiptap editor. The fourth asset type alongside
 * media / copy / articles; reviewed through the exact same flow (comments keyed
 * by `id`, approval via `approvedCustomIds`).
 */
export interface CustomAsset {
  id: string;
  /** Discriminator so the merged review pipeline can branch on it. */
  type?: 'custom';
  title?: string;
  /** Tiptap HTML (rich text + inline uploaded images). */
  content: string;
  /** URLs of images embedded in the document (for previews / counts). */
  images?: string[];
  /**
   * Whole-card freehand draw layer (transparent PNG data-URL), rendered over the
   * document. Written by CreateCustomSetDialog at creation; it was carried at
   * runtime without being declared here.
   */
  overlay?: string;
  /**
   * Image-annotation metadata (Phase 2). Keyed by the embedded image's id: the
   * editable drawing doc + a flattened export rendered on the (runtime-free)
   * review page. Reserved now; populated when the annotation lib lands.
   */
  annotation?: Record<string, { doc?: unknown; exportUrl?: string }>;
  createdAt?: string;
  updatedAt?: string;
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
  /** Delivery picked in the share dialog (v1.20). */
  deliveryId?: number | null;
  /** Resolved client-side from deliveries.list for the board filter. */
  deliveryName?: string | null;
  name: string;
  token: string;
  status: ApprovalStatus;
  snapshot: {
    brandName?: string;
    brandLogoUrl?: string;
    projectName?: string;
    media: SnapshotAsset[];
    copy: SnapshotAsset[];
    /** Writer articles packaged into this approval set (v1.12.0+) */
    articles?: SnapshotAsset[];
    /** Custom Notion-style documents authored on the board (Custom card type). */
    custom?: CustomAsset[];
  };
  reviewFeedback?: {
    approvedVisualIds: string[];
    approvedCopyIds: string[];
    /** Article IDs approved by the client (v1.12.0+) */
    approvedArticleIds?: string[];
    /** Custom-card IDs approved by the client. */
    approvedCustomIds?: string[];
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
