/**
 * APPROVALS MODULE — Content & Ad Sets Pipeline
 *
 * Thin orchestrator. Owns only the page chrome (header + view state) and
 * delegates everything else to the boards (`ArticlesBoard`, `SetsBoard`),
 * which read their own data, declarations, and render through the shared
 * Kanban primitive. The view switcher (PillTabBar) is injected into the
 * board's toolbar leading slot — one unified bar, no stacked rows.
 *
 * @package PowerCreatives
 */

import { useCallback, useMemo, useState } from 'react';
import { KanbanSquare } from 'lucide-react';

import { Spinner } from '@/components/ui/spinner';
import { PillTabBar } from '@/components/shared/PillTabBar';
import { colors, typography } from '@/components/shared/design-tokens';

import { ArticlesBoard } from './kanban/ArticlesBoard';
import { SetsBoard } from './kanban/SetsBoard';
import { useApprovalArticles } from './hooks/useApprovalArticles';
import { useApprovalSets } from './hooks/useApprovalSets';

type ApprovalView = 'articles' | 'sets';

const VIEW_TABS = [
  { id: 'articles', name: 'Written Articles' },
  { id: 'sets',     name: 'Ad Share Sets'   },
] as const;

export function ApprovalsModule() {
  // Counts only — TanStack Query dedupes; the boards read the same cache.
  const { articles, isLoading: articlesLoading } = useApprovalArticles();
  const { sets, isLoading: setsLoading } = useApprovalSets();

  const [view, setView] = useState<ApprovalView>('articles');

  const handleSelect = useCallback((id: string) => {
    if (id === 'articles' || id === 'sets') setView(id);
  }, []);

  const getCount = useCallback(
    (id: string) => (id === 'articles' ? articles.length : sets.length),
    [articles.length, sets.length]
  );

  // Build the view switcher once per render; passed to whichever board is
  // active so the toolbar row holds it inline with the filters.
  const viewSwitcher = useMemo(
    () => (
      <PillTabBar
        items={VIEW_TABS as unknown as { id: string; name: string }[]}
        activeId={view}
        onSelect={handleSelect}
        totalCount={articles.length + sets.length}
        getCount={getCount}
        showAllTab={false}
        className="flex items-center gap-1 rounded-lg shrink-0"
      />
    ),
    [view, handleSelect, getCount, articles.length, sets.length]
  );

  // First-load splash only when nothing is cached yet.
  const showSplash =
    articlesLoading && setsLoading && articles.length === 0 && sets.length === 0;

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

      {view === 'articles'
        ? <ArticlesBoard toolbarLeadingSlot={viewSwitcher} />
        : <SetsBoard     toolbarLeadingSlot={viewSwitcher} />}
    </div>
  );
}
