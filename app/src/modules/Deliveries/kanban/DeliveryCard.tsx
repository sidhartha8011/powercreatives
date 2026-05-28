/**
 * DeliveryCard — Kanban card body for a single delivery.
 *
 * Composes its visual chrome from the shared card primitive
 * (`@/components/shared/Kanban/card.module.css`) so it matches SetCard
 * pixel-for-pixel: single-line title, hover-anchored icon chips,
 * focus-ring, prefers-reduced-motion support — all from the same source.
 *
 * Interaction model:
 *   - Click the card body  → opens the edit dialog.
 *   - Drag the card        → triggers a column-to-column move (handled
 *                            by the shared KanbanBoard + onItemMove).
 *   - Hover                → reveals Edit (pencil) + Delete (trash) chips
 *                            anchored to the right edge.
 *
 * @hello-pangea/dnd distinguishes click from drag automatically by
 * mouse-movement threshold, so onClick on the card body Just Works
 * alongside the drag handle props the board spreads on the wrapper.
 */

import { useCallback, type KeyboardEvent, type MouseEvent } from 'react';
import { Pencil, Trash2 } from 'lucide-react';

import type { Delivery } from '../types';

import styles from './deliveryCard.module.css';

export interface DeliveryCardProps {
  delivery: Delivery;
  onEdit: (delivery: Delivery) => void;
  /** Request a single-card delete. Parent owns the confirmation dialog. */
  onRequestDelete: (delivery: Delivery) => void;
}

export function DeliveryCard({
  delivery,
  onEdit,
  onRequestDelete,
}: DeliveryCardProps) {
  const handleCardClick = useCallback(
    () => onEdit(delivery),
    [onEdit, delivery]
  );

  const handleKeyDown = useCallback(
    (event: KeyboardEvent<HTMLElement>) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        onEdit(delivery);
      }
    },
    [onEdit, delivery]
  );

  const stop = useCallback((e: MouseEvent) => e.stopPropagation(), []);

  const handleEdit = useCallback(
    (e: MouseEvent) => {
      stop(e);
      onEdit(delivery);
    },
    [stop, onEdit, delivery]
  );

  const handleDelete = useCallback(
    (e: MouseEvent) => {
      stop(e);
      onRequestDelete(delivery);
    },
    [stop, onRequestDelete, delivery]
  );

  const client = delivery.clientName?.trim();

  return (
    <article
      className={styles.card}
      role="link"
      tabIndex={0}
      aria-label={`Edit delivery ${delivery.name}`}
      onClick={handleCardClick}
      onKeyDown={handleKeyDown}
    >
      <div className={styles.title}>
        <span>{delivery.name}</span>
        {client ? (
          <>
            <span className={styles.titleSep}>/</span>
            <span>{client}</span>
          </>
        ) : null}
      </div>

      <div
        className={styles.actions}
        role="group"
        aria-label="Delivery actions"
      >
        <button
          type="button"
          className={styles.actionBtn}
          onClick={handleEdit}
          aria-label={`Edit ${delivery.name}`}
          title="Edit"
        >
          <Pencil className={styles.actionIcon} aria-hidden="true" />
        </button>

        <button
          type="button"
          className={`${styles.actionBtn} ${styles.actionBtnDanger}`}
          onClick={handleDelete}
          aria-label={`Delete ${delivery.name}`}
          title="Delete"
        >
          <Trash2 className={styles.actionIcon} aria-hidden="true" />
        </button>
      </div>
    </article>
  );
}
