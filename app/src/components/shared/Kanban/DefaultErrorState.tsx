/**
 * Default error state for failed data loads. Used when KanbanBoardProps.error
 * is set and no custom errorState is provided.
 */

import { AlertTriangle } from 'lucide-react';

import styles from './kanban.module.css';

export interface DefaultErrorStateProps {
  error: Error;
}

export function DefaultErrorState({ error }: DefaultErrorStateProps) {
  return (
    <div className={styles.errorBoard} role="alert">
      <AlertTriangle className={styles.errorBoardIcon} aria-hidden="true" />
      <p className={styles.errorBoardTitle}>Couldn’t load the board.</p>
      <p className={styles.errorBoardMessage}>{error.message}</p>
    </div>
  );
}
