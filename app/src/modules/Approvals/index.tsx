/**
 * APPROVALS MODULE — Ad Sets Pipeline
 *
 * Thin orchestrator. Owns the page header chrome and delegates everything
 * else to <SetsBoard>, which reads its own data, declarations, and renders
 * through the shared Kanban primitive (with drag-and-drop enabled).
 *
 * @package PowerCreatives
 */

import { useCallback, useEffect, useState } from 'react';
import { KanbanSquare, Plus } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { colors, typography } from '@/components/shared/design-tokens';
import { useApp } from '@/contexts/AppContext';

import { SetsBoard, type CreateInLaneContext } from './kanban/SetsBoard';
import { CreateCustomSetDialog } from './components/CreateCustomSetDialog';
import { useApprovalSets } from './hooks/useApprovalSets';

/** Everything a create entry point can pre-fill. All fields are hints. */
type CreatePreset = {
  brandId?: number | null;
  projectId?: number | null;
  deliveryId?: number | null;
  status?: string | null;
};

export function ApprovalsModule() {
  const { sets, isLoading } = useApprovalSets();
  const [showCreate, setShowCreate] = useState(false);

  // ONE dialog, two entry points — the header button and each lane's "+" —
  // so they can never drift apart. Both funnel through this preset.
  const [createPreset, setCreatePreset] = useState<CreatePreset | null>(null);

  // Create-from-delivery handover: a delivery project row's "+" lands here
  // with brand/project/delivery pre-selected in the create flow (one-shot).
  const { consumePendingCreate, state: appState } = useApp();
  useEffect(() => {
    const ctx = consumePendingCreate('approvals');
    if (ctx) {
      setCreatePreset({ brandId: ctx.brandId, projectId: ctx.projectId, deliveryId: ctx.deliveryId });
      setShowCreate(true);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [appState.pendingCreate]);

  // Lane "+": born in that lane, pre-filled from whatever the board is filtered to.
  const handleCreateInLane = useCallback((ctx: CreateInLaneContext) => {
    setCreatePreset({
      brandId: ctx.brandId,
      projectId: ctx.projectId,
      deliveryId: ctx.deliveryId,
      status: ctx.status,
    });
    setShowCreate(true);
  }, []);

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

      <SetsBoard onCreateInLane={handleCreateInLane} />

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
