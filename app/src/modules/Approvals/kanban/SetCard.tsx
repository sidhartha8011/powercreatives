/**
 * SetCard — Notion-style card body for a single approval set.
 *
 * Interaction model:
 *   - Click the card        → opens the public preview link in a new tab.
 *   - Drag the card         → triggers a column-to-column move (handled by
 *                             the board + onItemMove callback).
 *   - Click an action chip  → runs that action (stopPropagation prevents
 *                             the card's onClick from also firing).
 *
 * @hello-pangea/dnd distinguishes click from drag automatically by mouse
 * movement threshold, so onClick on the card body Just Works alongside the
 * drag handle props the board spreads on the wrapper.
 */

import { useCallback, useMemo, type KeyboardEvent, type MouseEvent } from 'react';
import { Copy, MessageSquare } from 'lucide-react';

import type { ApprovalSet } from '../types';

import styles from './setCard.module.css';

export interface SetCardProps {
  set: ApprovalSet;
  onCopyLink: (token: string) => void;
  onOpenFeedback: (set: ApprovalSet) => void;
  /** Opens the in-app preview dialog (iframe of the public board). */
  onOpenPreview: (set: ApprovalSet) => void;
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
  onCopyLink,
  onOpenFeedback,
  onOpenPreview,
}: SetCardProps) {
  const progress = useMemo(() => summarize(set), [set]);

  const openPreview = useCallback(() => onOpenPreview(set), [onOpenPreview, set]);

  // Card-wide click → in-app preview dialog. Keyboard via Enter / Space.
  const handleCardClick = useCallback(() => openPreview(), [openPreview]);
  const handleKeyDown = useCallback(
    (event: KeyboardEvent<HTMLElement>) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        openPreview();
      }
    },
    [openPreview]
  );

  // Action chips must not trigger the card-wide click.
  const stop = useCallback((e: MouseEvent) => e.stopPropagation(), []);

  const brand = set.snapshot.brandName?.trim();
  const project = set.snapshot.projectName?.trim();

  return (
    <article
      className={styles.card}
      role="link"
      tabIndex={0}
      aria-label={`Open preview for ${set.name}`}
      onClick={handleCardClick}
      onKeyDown={handleKeyDown}
    >
      <div className={styles.title}>
        {brand ? (
          <>
            <span>{brand}</span>
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
          onClick={(e) => { stop(e); onCopyLink(set.token); }}
          aria-label="Copy client link"
        >
          <Copy className={styles.actionIcon} aria-hidden="true" />
          Copy link
        </button>

        {progress.feedbackCount > 0 && (
          <button
            type="button"
            className={styles.actionBtn}
            onClick={(e) => { stop(e); onOpenFeedback(set); }}
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
