/**
 * Default empty state for the board (no items at all). Consumers override
 * via KanbanBoardProps.emptyState.
 */

import type { ReactNode } from 'react';
import { Inbox } from 'lucide-react';

import styles from './kanban.module.css';

export interface DefaultEmptyStateProps {
  title?: string;
  description?: string;
  icon?: ReactNode;
}

export function DefaultEmptyState({
  title = 'Nothing here yet',
  description,
  icon,
}: DefaultEmptyStateProps) {
  return (
    <div className={styles.emptyBoard} role="status">
      <div className={styles.emptyBoardIcon} aria-hidden="true">
        {icon ?? <Inbox className="h-8 w-8" />}
      </div>
      <p className={styles.emptyBoardTitle}>{title}</p>
      {description && <p className={styles.emptyBoardDescription}>{description}</p>}
    </div>
  );
}
