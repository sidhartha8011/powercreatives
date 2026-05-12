/**
 * USE UNIFIED MODEL REGISTRY HOOK - EXPANDED
 * 
 * Purpose: Connect Model Registry UI to backend tRPC procedures
 * Provides all operations for managing models in the unified registry
 * 
 * Features:
 * - Full CRUD operations
 * - Sync from integrations
 * - AI auto-detect
 * - Bulk confirm all suggested
 * - Update model names
 * - Delete models
 */

import { useState, useCallback, useMemo } from "react";
import { trpc } from "@/lib/trpc";
import { toast } from "sonner";
import type { ModelCapability, CostTier, ModelData, ModuleType } from "@shared/types/models";

/**
 * Options for the hook. Allows parent components to react to bulk operation outcomes.
 */
interface UseUnifiedModelRegistryOptions {
  /** Called after any bulk mutation succeeds. Use to clear selection state. */
  onBulkSuccess?: () => void;
}

export function useUnifiedModelRegistry(options: UseUnifiedModelRegistryOptions = {}) {
  const { onBulkSuccess } = options;
  const [isSyncing, setIsSyncing] = useState(false);
  const [isAutoDetecting, setIsAutoDetecting] = useState(false);
  const [isConfirmingAll, setIsConfirmingAll] = useState(false);
  
  const utils = trpc.useUtils();
  
  // Fetch all models
  const { data: models = [], isLoading } = trpc.models.getAll.useQuery();
  
  // Mutations
  const updateMutation = trpc.models.update.useMutation({
    onSuccess: () => {
      utils.models.getAll.invalidate();
    },
    onError: (error) => {
      toast.error(`Failed to update model: ${error.message}`);
    },
  });
  
  const updateCapabilityMutation = trpc.models.updateCapability.useMutation({
    onSuccess: () => {
      utils.models.getAll.invalidate();
    },
    onError: (error) => {
      toast.error(`Failed to update capability: ${error.message}`);
    },
  });
  
  const confirmStatusMutation = trpc.models.confirmStatus.useMutation({
    onSuccess: () => {
      utils.models.getAll.invalidate();
      toast.success("Model configuration confirmed");
    },
    onError: (error) => {
      toast.error(`Failed to confirm status: ${error.message}`);
    },
  });
  
  const confirmAllSuggestedMutation = trpc.models.confirmAllSuggested.useMutation({
    onSuccess: () => {
      utils.models.getAll.invalidate();
      toast.success("All AI-suggested configurations confirmed");
    },
    onError: (error) => {
      toast.error(`Failed to confirm all: ${error.message}`);
    },
  });
  
  const deleteMutation = trpc.models.delete.useMutation({
    onSuccess: () => {
      utils.models.getAll.invalidate();
      toast.success("Model deleted");
    },
    onError: (error) => {
      toast.error(`Failed to delete model: ${error.message}`);
    },
  });
  
  const syncMutation = trpc.models.syncFromIntegrations.useMutation({
    onSuccess: (result) => {
      utils.models.getAll.invalidate();
      if (result.success) {
        toast.success(`Synced ${result.total} models (${result.created} new, ${result.updated} updated)`);
      } else {
        toast.error(result.error || "Failed to sync models");
      }
    },
    onError: (error) => {
      toast.error(`Failed to sync models: ${error.message}`);
    },
  });
  
  const toggleModuleMutation = trpc.models.toggleModule.useMutation({
    onSuccess: () => {
      utils.models.getAll.invalidate();
      // Also invalidate getForGeneration queries so module dropdowns update
      utils.models.getForGeneration.invalidate();
      utils.models.getForEditing.invalidate();
    },
    onError: (error) => {
      toast.error(`Failed to toggle module: ${error.message}`);
    },
  });

  // ============================================
  // BULK MUTATIONS
  // ============================================
  const bulkToggleEnabledMutation = trpc.models.bulkToggleEnabled.useMutation({
    onSuccess: (result) => {
      utils.models.getAll.invalidate();
      toast.success(`${result.count} model(s) updated`);
      onBulkSuccess?.();
    },
    onError: (error) => {
      toast.error(`Bulk enable/disable failed: ${error.message}`);
    },
  });

  const bulkDeleteMutation = trpc.models.bulkDelete.useMutation({
    onSuccess: (result) => {
      utils.models.getAll.invalidate();
      toast.success(`${result.count} model(s) deleted`);
      onBulkSuccess?.();
    },
    onError: (error) => {
      toast.error(`Bulk delete failed: ${error.message}`);
    },
  });

  const bulkChangeTierMutation = trpc.models.bulkChangeTier.useMutation({
    onSuccess: (result) => {
      utils.models.getAll.invalidate();
      toast.success(`Tier updated for ${result.count} model(s)`);
      onBulkSuccess?.();
    },
    onError: (error) => {
      toast.error(`Bulk tier change failed: ${error.message}`);
    },
  });

  const bulkToggleModuleMutation = trpc.models.bulkToggleModule.useMutation({
    onSuccess: (result) => {
      utils.models.getAll.invalidate();
      utils.models.getForGeneration.invalidate();
      utils.models.getForEditing.invalidate();
      toast.success(`Module updated for ${result.count} model(s)`);
      onBulkSuccess?.();
    },
    onError: (error) => {
      toast.error(`Bulk module toggle failed: ${error.message}`);
    },
  });
  
  const autoDetectMutation = trpc.models.autoDetectCapabilities.useMutation({
    onSuccess: (result) => {
      utils.models.getAll.invalidate();
      if (result.success) {
        toast.success(`AI analyzed ${result.analyzed} models`);
      } else {
        toast.error(result.error || "Failed to auto-detect capabilities");
      }
    },
    onError: (error) => {
      toast.error(`Failed to auto-detect: ${error.message}`);
    },
  });
  
  /**
   * Toggle a capability for a model
   */
  const toggleCapability = useCallback((
    modelId: number,
    capability: ModelCapability,
    enabled: boolean
  ) => {
    updateCapabilityMutation.mutate({ id: modelId, capability, enabled });
  }, [updateCapabilityMutation]);
  
  /**
   * Change cost tier for a model
   */
  const changeTier = useCallback((modelId: number, tier: CostTier) => {
    updateMutation.mutate({ id: modelId, costTier: tier });
  }, [updateMutation]);
  
  /**
   * Confirm a model's status
   */
  const confirmStatus = useCallback((modelId: number) => {
    confirmStatusMutation.mutate({ id: modelId });
  }, [confirmStatusMutation]);
  
  /**
   * Toggle model enabled/disabled
   */
  const toggleEnabled = useCallback((modelId: number, enabled: boolean) => {
    updateMutation.mutate({ id: modelId, isEnabled: enabled });
  }, [updateMutation]);
  
  /**
   * Update model custom name
   */
  const updateName = useCallback((modelId: number, name: string) => {
    updateMutation.mutate({ 
      id: modelId, 
      customName: name || null // null clears custom name, uses original
    });
  }, [updateMutation]);
  
  /**
   * Toggle per-module enablement for a model
   */
  const toggleModule = useCallback((
    modelId: number,
    module: ModuleType,
    enabled: boolean
  ) => {
    toggleModuleMutation.mutate({ id: modelId, module, enabled });
  }, [toggleModuleMutation]);
  
  /**
   * Delete a model
   */
  const deleteModel = useCallback((modelId: number) => {
    deleteMutation.mutate({ id: modelId });
  }, [deleteMutation]);

  // ============================================
  // BULK ACTION CALLBACKS
  // ============================================
  const bulkEnable = useCallback((ids: number[]) => {
    bulkToggleEnabledMutation.mutate({ ids, enabled: true });
  }, [bulkToggleEnabledMutation]);

  const bulkDisable = useCallback((ids: number[]) => {
    bulkToggleEnabledMutation.mutate({ ids, enabled: false });
  }, [bulkToggleEnabledMutation]);

  const bulkDelete = useCallback((ids: number[]) => {
    bulkDeleteMutation.mutate({ ids });
  }, [bulkDeleteMutation]);

  const bulkChangeTier = useCallback((ids: number[], tier: CostTier) => {
    bulkChangeTierMutation.mutate({ ids, tier });
  }, [bulkChangeTierMutation]);

  const bulkToggleModule = useCallback((ids: number[], module: ModuleType, enabled: boolean) => {
    bulkToggleModuleMutation.mutate({ ids, module, enabled });
  }, [bulkToggleModuleMutation]);

  const isBulkProcessing = bulkToggleEnabledMutation.isPending ||
    bulkDeleteMutation.isPending ||
    bulkChangeTierMutation.isPending ||
    bulkToggleModuleMutation.isPending;
  
  /**
   * Confirm all AI-suggested models at once
   */
  const confirmAllSuggested = useCallback(async () => {
    setIsConfirmingAll(true);
    try {
      await confirmAllSuggestedMutation.mutateAsync();
    } finally {
      setIsConfirmingAll(false);
    }
  }, [confirmAllSuggestedMutation]);
  
  /**
   * Sync models from a provider integration
   */
  const syncFromProvider = useCallback(async (provider: string, apiKey: string) => {
    setIsSyncing(true);
    try {
      await syncMutation.mutateAsync({ provider, apiKey });
    } finally {
      setIsSyncing(false);
    }
  }, [syncMutation]);
  
  /**
   * Run AI auto-detect on all models
   */
  const autoDetect = useCallback(async () => {
    setIsAutoDetecting(true);
    try {
      await autoDetectMutation.mutateAsync({});
    } finally {
      setIsAutoDetecting(false);
    }
  }, [autoDetectMutation]);
  
  /**
   * Get models filtered by capability for generation
   */
  const getModelsForGeneration = useCallback((type: "image" | "video" | "text"): ModelData[] => {
    return models.filter(m => {
      if (!m.isEnabled || !m.isAvailable) return false;
      switch (type) {
        case "image": return m.canGenerateImage;
        case "video": return m.canGenerateVideo;
        case "text": return m.canGenerateText;
        default: return false;
      }
    });
  }, [models]);
  
  /**
   * Get models filtered by capability for editing
   */
  const getModelsForEditing = useCallback((type: "image" | "video"): ModelData[] => {
    return models.filter(m => {
      if (!m.isEnabled || !m.isAvailable) return false;
      switch (type) {
        case "image": return m.canEditImage;
        case "video": return m.canEditVideo;
        default: return false;
      }
    });
  }, [models]);
  
  /**
   * Group models by cost tier
   */
  const modelsByTier = useMemo(() => ({
    budget: models.filter(m => m.costTier === "budget"),
    standard: models.filter(m => m.costTier === "standard"),
    premium: models.filter(m => m.costTier === "premium"),
  }), [models]);
  
  /**
   * Group models by provider
   */
  const modelsByProvider = useMemo(() => {
    const grouped: Record<string, ModelData[]> = {};
    models.forEach(m => {
      if (!grouped[m.provider]) {
        grouped[m.provider] = [];
      }
      grouped[m.provider].push(m);
    });
    return grouped;
  }, [models]);
  
  /**
   * Count of AI-suggested models
   */
  const aiSuggestedCount = useMemo(() => 
    models.filter(m => m.status === "ai_suggested").length
  , [models]);
  
  return {
    // All models
    models,
    isLoading,
    
    // State
    isSyncing,
    isAutoDetecting,
    isConfirmingAll,
    isBulkProcessing,
    
    // Counts
    aiSuggestedCount,
    
    // Actions
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
    
    // Bulk actions
    bulkEnable,
    bulkDisable,
    bulkDelete,
    bulkChangeTier,
    bulkToggleModule,
    
    // Filtered getters
    getModelsForGeneration,
    getModelsForEditing,
    
    // Grouped
    modelsByTier,
    modelsByProvider,
  };
}
