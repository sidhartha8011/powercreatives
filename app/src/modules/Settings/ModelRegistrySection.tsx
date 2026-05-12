/**
 * MODEL REGISTRY SECTION - WITH BULK ACTIONS
 * 
 * Purpose: Settings section for the unified Model Registry
 * Replaces ModelCapabilitiesSection and ModelManagement
 * 
 * Features:
 * - Full model management
 * - Sync from integrations (fetched from DB via tRPC)
 * - AI auto-detect
 * - Bulk confirm all
 * - Edit model names
 * - Delete models
 * - Bulk selection & bulk actions (enable/disable/delete/tier/module)
 * 
 * @example
 * <ModelRegistrySection />
 */

import { useCallback, useRef } from "react";
import { ModelRegistryTable } from "@/components/ModelRegistry";
import { useUnifiedModelRegistry } from "@/hooks/useUnifiedModelRegistry";
import { trpc } from "@/lib/trpc";
import { toast } from "sonner";
import type { ModelCapability, CostTier, ModuleType } from "@shared/types/models";

export function ModelRegistrySection() {
  // Ref to the table's clearSelection function, set via callback prop
  const clearSelectionRef = useRef<(() => void) | null>(null);
  
  const handleBulkSuccess = useCallback(() => {
    clearSelectionRef.current?.();
  }, []);
  
  const { 
    models, 
    isLoading, 
    isSyncing,
    isAutoDetecting,
    isConfirmingAll,
    isBulkProcessing,
    toggleCapability,
    changeTier,
    confirmStatus,
    toggleEnabled,
    toggleModule,
    updateName,
    deleteModel,
    confirmAllSuggested,
    syncFromProvider,
    autoDetect,
    bulkEnable,
    bulkDisable,
    bulkDelete,
    bulkChangeTier,
    bulkToggleModule,
  } = useUnifiedModelRegistry({ onBulkSuccess: handleBulkSuccess });
  
  // Fetch integrations from DB via tRPC (single source of truth)
  const { data: integrations = [] } = trpc.integrations.list.useQuery();
  
  /**
   * Sync models from all active integrations
   */
  const handleSyncModels = async () => {
    const activeIntegrations = integrations.filter(
      (i: { apiKey: string | null; isActive: boolean; provider: string }) => !!i.apiKey && i.isActive
    );
    
    if (activeIntegrations.length === 0) {
      toast.error("No active integrations configured. Add an integration first.");
      return;
    }
    
    for (const integration of activeIntegrations) {
      await syncFromProvider(integration.provider, integration.apiKey!);
    }
  };
  
  /**
   * Run AI auto-detect on all models
   */
  const handleAutoDetect = async () => {
    if (models.length === 0) {
      toast.error("No models to analyze. Sync models first.");
      return;
    }
    await autoDetect();
  };
  
  // Build integration info for status column
  const integrationInfos = integrations.map((i: { provider: string; isActive: boolean }) => ({
    provider: i.provider,
    isActive: i.isActive,
  }));

  return (
    <ModelRegistryTable
      models={models}
      isLoading={isLoading}
      onToggleCapability={(modelId: number, capability: ModelCapability, enabled: boolean) =>
        toggleCapability(modelId, capability, enabled)
      }
      onChangeTier={(modelId: number, tier: CostTier) => changeTier(modelId, tier)}
      onConfirmStatus={(modelId: number) => confirmStatus(modelId)}
      onToggleEnabled={(modelId: number, enabled: boolean) => toggleEnabled(modelId, enabled)}
      onSyncModels={handleSyncModels}
      isSyncing={isSyncing}
      onAutoDetect={handleAutoDetect}
      isAutoDetecting={isAutoDetecting}
      onUpdateName={(modelId: number, name: string) => updateName(modelId, name)}
      onConfirmAllSuggested={() => confirmAllSuggested()}
      onDeleteModel={(modelId: number) => deleteModel(modelId)}
      isConfirmingAll={isConfirmingAll}
      integrations={integrationInfos}
      onToggleModule={(modelId: number, module: ModuleType, enabled: boolean) =>
        toggleModule(modelId, module, enabled)
      }
      // Bulk action props
      onBulkEnable={bulkEnable}
      onBulkDisable={bulkDisable}
      onBulkDelete={bulkDelete}
      onBulkChangeTier={bulkChangeTier}
      onBulkToggleModule={bulkToggleModule}
      isBulkProcessing={isBulkProcessing}
      onClearSelectionRef={clearSelectionRef}
    />
  );
}
