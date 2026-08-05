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

import { useCallback, useMemo, useState, type KeyboardEvent, type MouseEvent } from 'react';
import { Check, ChevronRight, Copy, MessageSquare, Trash2 } from 'lucide-react';

import type { ApprovalSet } from '../types';
import { assetType } from '../assetTypes';

import styles from './setCard.module.css';

export interface SetCardProps {
  set: ApprovalSet;
  /**
   * Brand name resolved by the board from the brands registry. The list endpoint
   * deliberately doesn't ship the (longtext) snapshot, so `set.snapshot.brandName`
   * is empty here — this prop is the card's only source for the brand prefix.
   */
  brandName?: string | null;
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
  brandName,
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

  // Board rows carry NO snapshot — the list query omits it (approvals/service.php)
  // and no longer ships a misleading empty one, so the old
  // `?? set.snapshot.brandName` fallback was dead here and would now throw.
  const brand = brandName?.trim();

  /**
   * Sub-assets, straight off the row.
   *
   * The list query ships `items[]` as an SQL-extracted summary, so expanding
   * costs ZERO network — which is the whole point: on this host a per-card
   * fetch measures seconds, and a disclosure that stalls is worse than none.
   */
  const [expanded, setExpanded] = useState(false);
  const items = set.items ?? [];
  const itemCount = set.itemCount ?? items.length;

  const toggleExpanded = useCallback(
    (e: MouseEvent) => { stop(e); setExpanded((v) => !v); },
    [stop]
  );

  return (
    <article
      className={styles.card}
      data-selected={isSelected || undefined}
      data-select-mode={selectMode || undefined}
      data-expanded={expanded || undefined}
    >
      <div
        className={styles.row}
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

        {/* What is inside, and the way in. Always present when the card holds
            anything, because it is information rather than an action — the
            hover-only chips to the right are the actions. */}
        {itemCount > 0 && (
          <button
            type="button"
            className={styles.disclosure}
            onClick={toggleExpanded}
            aria-expanded={expanded}
            aria-label={`${expanded ? 'Hide' : 'Show'} the ${itemCount} item${itemCount === 1 ? '' : 's'} in ${set.name}`}
            tabIndex={-1}
          >
            <ChevronRight className={styles.disclosureIcon} aria-hidden="true" />
            <span className={styles.disclosureCount}>{itemCount}</span>
          </button>
        )}
      </div>

      <div className={styles.actions} role="group" aria-label="Set actions">
        <button
          type="button"
          className={styles.actionBtn}
          onClick={(e) => { stop(e); onCopyLink(set.token); }}
          aria-label="Copy client link"
          title="Copy client link"
        >
          <Copy className={styles.actionIcon} aria-hidden="true" />
        </button>

        {feedbackCount > 0 && (
          <button
            type="button"
            className={styles.actionBtn}
            onClick={(e) => { stop(e); onOpenFeedback(set); }}
            aria-label="View client feedback"
            title="View client feedback"
          >
            <MessageSquare className={styles.actionIcon} aria-hidden="true" />
          </button>
        )}

        <button
          type="button"
          className={`${styles.actionBtn} ${styles.actionBtnDanger}`}
          onClick={handleDelete}
          aria-label={`Delete ${set.name}`}
          title="Delete"
        >
          <Trash2 className={styles.actionIcon} aria-hidden="true" />
        </button>
      </div>
      </div>

      {expanded && items.length > 0 && (
        <ul className={styles.items}>
          {items.map((item) => {
            // The type LABEL is what distinguishes items; a per-type glyph beside
            // it was noise, and the generic document icon read as an artefact.
            const def = assetType(item.type);
            return (
              <li key={item.id} className={styles.item}>
                <span className={styles.itemLabel}>{def.label}</span>
                {item.title ? (
                  <span className={styles.itemTitle}>{item.title}</span>
                ) : null}
              </li>
            );
          })}
        </ul>
      )}
    </article>
  );
}
