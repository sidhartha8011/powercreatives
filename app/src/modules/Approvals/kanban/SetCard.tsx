/**
 * SetCard — Notion-style card body for a single approval set.
 *
 * Renders inside the shared Kanban card wrapper (border / hover etc.
 * provided by KanbanBoard). Holds two text lines (brand · set name,
 * metadata) plus a hover-revealed action row (copy link, preview,
 * view feedback).
 *
 * Pure presentational. All side effects come through props.
 */

import { useMemo } from 'react';
import { Copy, ExternalLink, MessageSquare } from 'lucide-react';

import type { ApprovalSet } from '../types';

import styles from './setCard.module.css';

export interface SetCardProps {
  set: ApprovalSet;
  /** Build the public review URL (from useApprovalSets). */
  getPublicBoardUrl: (token: string) => string;
  /** Copy the public URL to clipboard with a toast. */
  onCopyLink: (token: string) => void;
  /** Open the feedback dialog for this set. */
  onOpenFeedback: (set: ApprovalSet) => void;
}

interface ProgressSummary {
  approved: number;
  total: number;
  feedbackCount: number;
}

function summarize(set: ApprovalSet): ProgressSummary {
  const media = set.snapshot.media ?? [];
  const copy = set.snapshot.copy ?? [];
  const fb = set.reviewFeedback;
  return {
    approved:
      (fb?.approvedVisualIds?.length ?? 0) + (fb?.approvedCopyIds?.length ?? 0),
    total: media.length + copy.length,
    feedbackCount: Object.keys(fb?.comments ?? {}).length,
  };
}

export function SetCard({
  set,
  getPublicBoardUrl,
  onCopyLink,
  onOpenFeedback,
}: SetCardProps) {
  const progress = useMemo(() => summarize(set), [set]);
  const previewUrl = useMemo(() => getPublicBoardUrl(set.token), [getPublicBoardUrl, set.token]);

  const brand = set.snapshot.brandName?.trim();
  const project = set.snapshot.projectName?.trim();

  return (
    <article className={styles.card}>
      <div className={styles.title}>
        {brand ? (
          <>
            <span className={styles.titleBrand}>{brand}</span>
            <span className={styles.titleSep}>/</span>
          </>
        ) : null}
        <span>{set.name}</span>
      </div>

      <div className={styles.meta}>
        {project && (
          <>
            <span className={styles.metaItem}>{project}</span>
            <span className={styles.metaSep}>·</span>
          </>
        )}
        <span className={styles.metaItem}>
          {progress.total === 0
            ? 'No assets'
            : progress.approved === progress.total
              ? <span className={styles.metaApproved}>{progress.total}/{progress.total} approved</span>
              : `${progress.approved}/${progress.total} approved`}
        </span>
        {progress.feedbackCount > 0 && (
          <>
            <span className={styles.metaSep}>·</span>
            <span className={`${styles.metaItem} ${styles.metaFeedback}`}>
              {progress.feedbackCount} comment{progress.feedbackCount === 1 ? '' : 's'}
            </span>
          </>
        )}
      </div>

      <div className={styles.actions} role="group" aria-label="Set actions">
        <button
          type="button"
          className={styles.actionBtn}
          onClick={() => onCopyLink(set.token)}
          aria-label="Copy client link"
        >
          <Copy className={styles.actionIcon} aria-hidden="true" />
          Copy link
        </button>

        <a
          href={previewUrl}
          target="_blank"
          rel="noopener noreferrer"
          className={styles.actionBtn}
          aria-label="Open public preview"
        >
          <ExternalLink className={styles.actionIcon} aria-hidden="true" />
          Preview
        </a>

        {progress.feedbackCount > 0 && (
          <button
            type="button"
            className={styles.actionBtn}
            onClick={() => onOpenFeedback(set)}
            aria-label="View client feedback"
          >
            <MessageSquare className={styles.actionIcon} aria-hidden="true" />
            View feedback
          </button>
        )}
      </div>
    </article>
  );
}
