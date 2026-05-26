/**
 * APPROVALS MODULE — Ad Sets Pipeline
 *
 * Thin orchestrator. Owns the page header chrome and delegates everything
 * else to <SetsBoard>, which reads its own data, declarations, and renders
 * through the shared Kanban primitive (with drag-and-drop enabled).
 *
 * The Articles board lives in this folder (ArticlesBoard, useApprovalArticles
 * etc.) and is wired to the same primitive — it's just not surfaced from
 * this module's UI right now. Mount it from another route when needed.
 *
 * @package PowerCreatives
 */

import { KanbanSquare } from 'lucide-react';

import { Spinner } from '@/components/ui/spinner';
import { colors, typography } from '@/components/shared/design-tokens';

import { SetsBoard } from './kanban/SetsBoard';
import { useApprovalSets } from './hooks/useApprovalSets';

export function ApprovalsModule() {
  const { sets, isLoading } = useApprovalSets();

  // First-load splash only when nothing is cached yet.
  const showSplash = isLoading && sets.length === 0;

  if (showSplash) {
    return (
      <div className="flex items-center justify-center h-full">
        <Spinner className="w-6 h-6" />
      </div>
    );
  }

  return (
    <div className="h-full flex flex-col">
      <div className="flex items-center justify-between mb-4 shrink-0">
        <div className="flex items-center gap-2">
          <KanbanSquare className="w-5 h-5" style={{ color: colors.primary }} />
          <h1 style={{ fontSize: typography.title, fontWeight: typography.bold, color: colors.text }}>
            Approvals Pipeline
          </h1>
        </div>
      </div>

      <SetsBoard />
    </div>
  );
}
