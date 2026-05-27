/**
 * SetCard — Notion-style card body for a single approval set.
 *
 * Interaction model:
 *   - Click the card body   → opens the in-app preview dialog.
 *   - Drag the card         → triggers a column-to-column move (handled
 *                             by the board + onItemMove callback).
 *   - Hover                 → reveals checkbox (left) and action chips
 *                             including a Delete chip on the right.
 *   - Click the checkbox    → toggles selection. When any card is
 *                             selected, the board enters "select-mode":
 *                             checkboxes stay visible on every card and
 *                             a floating bulk-action bar appears.
 *
 * @hello-pangea/dnd distinguishes click from drag automatically by mouse
 * movement threshold, so onClick on the card body Just Works alongside
 * the drag handle props the board spreads on the wrapper.
 */

import { useCallback, useMemo, type KeyboardEvent, type MouseEvent } from 'react';
import { Check, Copy, MessageSquare, Trash2 } from 'lucide-react';

import type { ApprovalSet } from '../types';

import styles from './setCard.module.css';

export interface SetCardProps {
  set: ApprovalSet;
  onCopyLink: (token: string) => void;
  onOpenFeedback: (set: ApprovalSet) => void;
  /** Opens the in-app preview dialog (iframe of the public board). */
  onOpenPreview: (set: ApprovalSet) => void;
  /** Request a single-card delete. Parent owns the confirmation dialog. */
  onRequestDelete: (set: ApprovalSet) => void;
  /** Toggle this card's selection state. */
  onToggleSelect: (set: ApprovalSet) => void;
  /** Whether this specific card is currently selected. */
  isSelected: boolean;
  /** True when ANY card is selected — drives select-mode visuals
   *  (checkbox stays visible on every card, hover-only chips hidden). */
  selectMode: boolean;
}

export function SetCard({
  set,
  onCopyLink,
  onOpenFeedback,
  onOpenPreview,
  onRequestDelete,
  onToggleSelect,
  isSelected,
  selectMode,
}: SetCardProps) {
  // Total feedback = sum of all comment entries across all asset threads
  // (comments are Record<assetId, CommentEntry[]>). Only used to gate the
  // "View feedback" action chip.
  const feedbackCount = useMemo(
    () =>
      Object.values(set.reviewFeedback?.comments ?? {}).reduce(
        (sum, thread) => sum + thread.length,
        0
      ),
    [set]
  );

  const openPreview = useCallback(() => onOpenPreview(set), [onOpenPreview, set]);

  // Card-wide click — opens preview UNLESS we're in select-mode, in
  // which case clicking the body toggles selection (matches the
  // Gmail/Linear convention: once you start selecting, the whole row
  // becomes a selection target).
  const handleCardClick = useCallback(() => {
    if (selectMode) onToggleSelect(set);
    else openPreview();
  }, [selectMode, onToggleSelect, set, openPreview]);

  const handleKeyDown = useCallback(
    (event: KeyboardEvent<HTMLElement>) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        if (selectMode) onToggleSelect(set);
        else openPreview();
      }
    },
    [selectMode, onToggleSelect, set, openPreview]
  );

  // Action chips + checkbox must not trigger the card-wide click.
  const stop = useCallback((e: MouseEvent) => e.stopPropagation(), []);

  const handleToggleSelect = useCallback(
    (e: MouseEvent) => {
      stop(e);
      onToggleSelect(set);
    },
    [stop, onToggleSelect, set]
  );

  const handleDelete = useCallback(
    (e: MouseEvent) => {
      stop(e);
      onRequestDelete(set);
    },
    [stop, onRequestDelete, set]
  );

  const brand = set.snapshot.brandName?.trim();

  return (
    <article
      className={styles.card}
      data-selected={isSelected || undefined}
      data-select-mode={selectMode || undefined}
      role="link"
      tabIndex={0}
      aria-label={
        selectMode
          ? `${isSelected ? 'Deselect' : 'Select'} ${set.name}`
          : `Open preview for ${set.name}`
      }
      aria-pressed={selectMode ? isSelected : undefined}
      onClick={handleCardClick}
      onKeyDown={handleKeyDown}
    >
      <button
        type="button"
        className={styles.checkbox}
        onClick={handleToggleSelect}
        aria-label={isSelected ? `Deselect ${set.name}` : `Select ${set.name}`}
        aria-pressed={isSelected}
        tabIndex={-1}
      >
        {isSelected && <Check className={styles.checkboxIcon} aria-hidden="true" />}
      </button>

      <div className={styles.title}>
        {brand ? (
          <>
            <span>{brand}</span>
            <span className={styles.titleSep}>/</span>
          </>
        ) : null}
        <span>{set.name}</span>
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

        {feedbackCount > 0 && (
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

        <button
          type="button"
          className={`${styles.actionBtn} ${styles.actionBtnDanger}`}
          onClick={handleDelete}
          aria-label={`Delete ${set.name}`}
        >
          <Trash2 className={styles.actionIcon} aria-hidden="true" />
          Delete
        </button>
      </div>
    </article>
  );
}
