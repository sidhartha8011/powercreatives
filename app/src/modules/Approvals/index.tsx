/**
 * APPROVALS MODULE — Ad Sets Pipeline
 *
 * Thin orchestrator. Owns the page header chrome and delegates everything
 * else to <SetsBoard>, which reads its own data, declarations, and renders
 * through the shared Kanban primitive (with drag-and-drop enabled).
 *
 * @package PowerCreatives
 */

import { useEffect, useState } from 'react';
import { KanbanSquare, Plus } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { colors, typography } from '@/components/shared/design-tokens';
import { useApp } from '@/contexts/AppContext';

import { SetsBoard } from './kanban/SetsBoard';
import { CreateCustomSetDialog } from './components/CreateCustomSetDialog';
import { useApprovalSets } from './hooks/useApprovalSets';

export function ApprovalsModule() {
  const { sets, isLoading } = useApprovalSets();
  const [showCreate, setShowCreate] = useState(false);

  // Create-from-delivery handover: a delivery project row's "+" lands here
  // with brand/project/delivery pre-selected in the create flow (one-shot).
  const { consumePendingCreate, state: appState } = useApp();
  const [createPreset, setCreatePreset] =
    useState<{ brandId: number | null; projectId: number; deliveryId: number } | null>(null);
  useEffect(() => {
    const ctx = consumePendingCreate('approvals');
    if (ctx) {
      setCreatePreset({ brandId: ctx.brandId, projectId: ctx.projectId, deliveryId: ctx.deliveryId });
      setShowCreate(true);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [appState.pendingCreate]);

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
    <div className="h-full flex flex-col p-4" style={{ background: '#ffffff' }}>
      <div className="flex items-center justify-between mb-4 shrink-0">
        <div className="flex items-center gap-2">
          <KanbanSquare className="w-5 h-5" style={{ color: colors.primary }} />
          <h1 style={{ fontSize: typography.title, fontWeight: typography.bold, color: colors.text }}>
            Approvals Pipeline
          </h1>
        </div>
        <Button type="button" onClick={() => setShowCreate(true)} className="gap-2">
          <Plus className="w-4 h-4" />
          Add Approval Set
        </Button>
      </div>

      <SetsBoard />

      {/* "Add Approval Set" → author a custom Notion-style card, then send it to the
          client through the shared SendToApprovalSetDialog (same flow as Copy). */}
      <CreateCustomSetDialog
        open={showCreate}
        onClose={() => {
          setShowCreate(false);
          setCreatePreset(null);
        }}
        preset={createPreset ?? undefined}
      />
    </div>
  );
}
