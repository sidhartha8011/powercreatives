/**
 * Default loading state — minimal centered spinner. Consumers wanting
 * column-shaped skeletons can pass their own via KanbanBoardProps.loadingState.
 */

import { Spinner } from '@/components/ui/spinner';

import styles from './kanban.module.css';

export function DefaultLoadingState() {
  return (
    <div className={styles.loadingBoard} role="status" aria-live="polite">
      <Spinner className="h-5 w-5" />
      <span className={styles.loadingBoardLabel}>Loading…</span>
    </div>
  );
}
