/**
 * APPROVALS MODULE — Content & Ad Sets Pipeline
 *
 * Thin orchestrator. Owns only the page chrome (header, tabs, loading
 * splash) and tab labels with counts. The boards themselves
 * (ArticlesBoard, SetsBoard) own their data, columns, filters, and
 * presentation through the shared Kanban primitive.
 *
 * @package PowerCreatives
 */

import { KanbanSquare } from 'lucide-react';

import { Spinner } from '@/components/ui/spinner';
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from '@/components/ui/tabs';
import { colors, typography } from '@/components/shared/design-tokens';

import { ArticlesBoard } from './kanban/ArticlesBoard';
import { SetsBoard } from './kanban/SetsBoard';
import { useApprovalArticles } from './hooks/useApprovalArticles';
import { useApprovalSets } from './hooks/useApprovalSets';

export function ApprovalsModule() {
  // Counts only — TanStack Query dedupes; the boards read the same cache.
  const { articles, isLoading: articlesLoading } = useApprovalArticles();
  const { sets, isLoading: setsLoading } = useApprovalSets();

  // First-load splash: only when BOTH queries are loading and nothing is
  // cached yet. Subsequent refetches keep the UI mounted.
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

      <Tabs defaultValue="articles" className="flex-1 flex flex-col min-h-0">
        <TabsList className="grid w-full grid-cols-2 max-w-[400px] mb-4">
          <TabsTrigger value="articles" className="text-xs">
            Written Articles ({articles.length})
          </TabsTrigger>
          <TabsTrigger value="adsets" className="text-xs">
            Ad Share Sets ({sets.length})
          </TabsTrigger>
        </TabsList>

        <TabsContent value="articles" className="flex-1 flex flex-col min-h-0 m-0">
          <ArticlesBoard />
        </TabsContent>

        <TabsContent value="adsets" className="flex-1 flex flex-col min-h-0 m-0">
          <SetsBoard />
        </TabsContent>
      </Tabs>
    </div>
  );
}
