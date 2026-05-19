/**
 * USE ASSET ACTIONS HOOK
 * 
 * Purpose: Centralized logic for asset operations
 * Features:
 * - Refine with multi-model parallel processing (uses image.edit endpoint)
 * - Variations creation
 * - Export in multiple formats
 * - Save to project
 * 
 * Design principles:
 * - Single source of truth for asset actions
 * - Works for both image and video assets
 * - Manages loading states per action
 * - Integrates with tRPC endpoints
 */

import { useState, useCallback, useEffect } from "react";
import { trpc } from "@/lib/trpc";
import { toast } from "sonner";
import type { Asset, Project, ExportFormat } from "@/components/AssetDetailView/types";
import type { EditResult } from "@/components/AssetDetailView/EditResults";
import { useImageModelsForEditing } from "@/hooks/useModelsForGeneration";

// Action types for loading state management
type ActionType = "refine" | "variations" | "export" | "save" | "createProject" | null;

interface UseAssetActionsProps {
  asset: Asset;
  onVariationsCreated?: (assets: Asset[]) => void;
  onAssetRefined?: (asset: Asset) => void;
  /** Called after a successful single-model edit — for edit history grid */
  onEditComplete?: (editedUrl: string, modelName: string) => void;
  onClose?: () => void;
}

interface UseAssetActionsReturn {
  projects: Project[];
  isLoading: boolean;
  loadingAction: ActionType;
  // Edit results state
  editResults: EditResult[];
  isEditingMultiple: boolean;
  // Actions
  handleRefine: (instruction: string, modelIds?: string[]) => Promise<void>;
  handleExport: (format: ExportFormat) => Promise<void>;
  handleSaveToProject: (projectId: number) => Promise<void>;
  handleCreateProject: (name: string) => Promise<number | void>;
  handleCreateVariations: () => Promise<void>;
  handleUseEditResult: (result: EditResult) => void;
  handleDownloadEditResult: (result: EditResult) => void;
  handleCloseEditResults: () => void;
}

export function useAssetActions({
  asset,
  onVariationsCreated,
  onAssetRefined,
  onEditComplete,
  onClose,
}: UseAssetActionsProps): UseAssetActionsReturn {
  const [loadingAction, setLoadingAction] = useState<ActionType>(null);
  const [projects, setProjects] = useState<Project[]>([]);
  const [editResults, setEditResults] = useState<EditResult[]>([]);
  const [isEditingMultiple, setIsEditingMultiple] = useState(false);

  // Edit-capable models — used to resolve provider from model ID
  // Same pattern as useImageGeneration line 292-296
  const { editModels } = useImageModelsForEditing();

  // tRPC queries and mutations
  const projectsQuery = trpc.assets.getProjects.useQuery(undefined, {
    enabled: true,
  });

  // Use the new image.edit endpoint for actual image editing
  const imageEditMutation = trpc.image.edit.useMutation();
  const exportMutation = trpc.assets.export.useMutation();
  const saveToProjectMutation = trpc.assets.saveToProject.useMutation();
  const createProjectMutation = trpc.assets.createProject.useMutation();
  const createVariationsMutation = trpc.assets.createVariations.useMutation();

  /**
   * Resolve provider from model registry by model ID.
   * Same pattern as useImageGeneration (line 292-296).
   */
  const resolveProvider = useCallback((modelId: string): string => {
    return editModels.find((m: { id: string; provider: string }) => m.id === modelId)?.provider ?? '';
  }, [editModels]);

  // Update projects when query data changes
  useEffect(() => {
    if (projectsQuery.data) {
      setProjects(projectsQuery.data as Project[]);
    }
  }, [projectsQuery.data]);

  const isLoading = loadingAction !== null;

  /**
   * Refine asset with AI - supports multi-model parallel processing
   * Uses the image.edit endpoint to actually edit the image
   */
  const handleRefine = useCallback(async (instruction: string, modelIds?: string[], referenceImageUrl?: string) => {
    if (!instruction.trim()) {
      toast.error("Please enter refinement instructions");
      return;
    }

    if (!modelIds || modelIds.length === 0) {
      toast.error("Please select at least one model");
      return;
    }

    // If multiple models selected, use parallel processing
    if (modelIds.length > 1) {
      setIsEditingMultiple(true);
      setLoadingAction("refine");
      
      // Initialize results with loading state
      const initialResults: EditResult[] = modelIds.map((modelId) => ({
        modelId,
        modelName: modelId, // Will be updated with actual name
        provider: "",
        status: "loading" as const,
      }));
      setEditResults(initialResults);

      // Process each model in parallel using image.edit endpoint
      const promises = modelIds.map(async (modelId) => {
        try {
          const provider = resolveProvider(modelId);
          if (!provider) {
            throw new Error(`No provider found for model ${modelId}. Check the model registry.`);
          }
          const result = await imageEditMutation.mutateAsync({
            imageUrl: asset.url,
            prompt: instruction,
            model: modelId,
            provider,
            ...(referenceImageUrl ? { referenceImageUrl } : {}),
          });

          // Update result for this model
          setEditResults((prev) =>
            prev.map((r) =>
              r.modelId === modelId
                ? {
                    ...r,
                    status: "success" as const,
                    imageUrl: result.url,
                    modelName: result.modelId || modelId,
                    provider: result.provider || "",
                  }
                : r
            )
          );
        } catch (error) {
          console.error(`Edit error for model ${modelId}:`, error);
          setEditResults((prev) =>
            prev.map((r) =>
              r.modelId === modelId
                ? {
                    ...r,
                    status: "error" as const,
                    error: error instanceof Error ? error.message : "Failed",
                  }
                : r
            )
          );
        }
      });

      await Promise.allSettled(promises);
      setLoadingAction(null);
      toast.success("Edit processing complete");
      return;
    }

    // Single model processing
    setLoadingAction("refine");
    try {
      const provider = resolveProvider(modelIds[0]);
      if (!provider) {
        toast.error(`No provider found for model ${modelIds[0]}. Check the model registry.`);
        setLoadingAction(null);
        return;
      }
      const result = await imageEditMutation.mutateAsync({
        imageUrl: asset.url,
        prompt: instruction,
        model: modelIds[0],
        provider,
        ...(referenceImageUrl ? { referenceImageUrl } : {}),
      });

      toast.success("Image edited successfully!");

      // Update single-image view with latest edit result
      if (onAssetRefined && result.url) {
        onAssetRefined({
          ...asset,
          url: result.url,
        });
      }

      // Signal parent to add to edit history grid (original URL preserved via ref)
      if (onEditComplete && result.url) {
        onEditComplete(result.url, result.modelId || modelIds[0]);
      }
    } catch (error) {
      console.error("Edit error:", error);
      toast.error(error instanceof Error ? error.message : "Failed to edit image");
    } finally {
      setLoadingAction(null);
    }
  }, [asset, imageEditMutation, onAssetRefined, onEditComplete, resolveProvider]);

  /**
   * Use a specific edit result as the new asset
   */
  const handleUseEditResult = useCallback((result: EditResult) => {
    if (result.status !== "success" || !result.imageUrl) return;

    // Update the asset with the selected result
    if (onAssetRefined) {
      onAssetRefined({
        ...asset,
        url: result.imageUrl,
      });
    }

    // Close edit results
    setEditResults([]);
    setIsEditingMultiple(false);
    toast.success(`Applied edit from ${result.modelName}`);
  }, [asset, onAssetRefined]);

  /**
   * Download a specific edit result
   */
  const handleDownloadEditResult = useCallback((result: EditResult) => {
    if (result.status !== "success" || !result.imageUrl) return;

    const link = document.createElement("a");
    link.href = result.imageUrl;
    link.download = `edit-${result.modelName}-${Date.now()}.png`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    toast.success("Download started");
  }, []);

  /**
   * Close edit results panel
   */
  const handleCloseEditResults = useCallback(() => {
    setEditResults([]);
    setIsEditingMultiple(false);
  }, []);

  /**
   * Export asset in specified format
   */
  const handleExport = useCallback(async (format: ExportFormat) => {
    setLoadingAction("export");
    try {
      const result = await exportMutation.mutateAsync({
        assetId: asset.id,
        format,
      });

      // Trigger download
      const link = document.createElement("a");
      link.href = result.url;
      link.download = result.filename;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);

      toast.success(`Downloading ${result.filename}`);
    } catch (error) {
      console.error("Export error:", error);
      toast.error("Failed to export creative");
    } finally {
      setLoadingAction(null);
    }
  }, [asset.id, exportMutation]);

  /**
   * Save asset to project
   */
  const handleSaveToProject = useCallback(async (projectId: number) => {
    // Guard: asset must have a valid DB ID (assigned during generation)
    if (!asset.id || asset.id === 0) {
      toast.error("This asset hasn't been saved to the database yet. Try regenerating it.");
      return;
    }

    setLoadingAction("save");
    try {
      const result = await saveToProjectMutation.mutateAsync({
        assetId: asset.id,
        projectId,
      });

      toast.success(`Saved to "${result.projectName}"`);
    } catch (error) {
      console.error("Save to project error:", error);
      toast.error("Failed to save to project");
    } finally {
      setLoadingAction(null);
    }
  }, [asset.id, saveToProjectMutation]);

  /**
   * Create new project
   */
  const handleCreateProject = useCallback(async (name: string): Promise<number | void> => {
    if (!name.trim()) {
      toast.error("Please enter a project name");
      return;
    }

    setLoadingAction("createProject");
    try {
      const result = await createProjectMutation.mutateAsync({
        name,
        type: asset.type,
      });

      // Refresh projects list so the new project appears in dropdown
      await projectsQuery.refetch();

      toast.success(`Created project "${result.name}"`);
      return result.id;
    } catch (error) {
      console.error("Create project error:", error);
      toast.error("Failed to create project");
    } finally {
      setLoadingAction(null);
    }
  }, [asset.type, createProjectMutation, projectsQuery]);

  /**
   * Create variations of the asset
   */
  const handleCreateVariations = useCallback(async () => {
    setLoadingAction("variations");
    try {
      const result = await createVariationsMutation.mutateAsync({
        assetId: asset.id,
        count: 3,
      });

      toast.success("Creating variations...");
      
      if (onVariationsCreated) {
        onVariationsCreated([]);
      }
      
      if (onClose) {
        onClose();
      }
    } catch (error) {
      console.error("Create variations error:", error);
      toast.error("Failed to create variations");
    } finally {
      setLoadingAction(null);
    }
  }, [asset.id, createVariationsMutation, onVariationsCreated, onClose]);

  return {
    projects,
    isLoading,
    loadingAction,
    editResults,
    isEditingMultiple,
    handleRefine,
    handleExport,
    handleSaveToProject,
    handleCreateProject,
    handleCreateVariations,
    handleUseEditResult,
    handleDownloadEditResult,
    handleCloseEditResults,
  };
}
