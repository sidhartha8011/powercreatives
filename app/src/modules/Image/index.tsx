/**
 * ImageModule — Orchestrator component
 *
 * Composes the Image module from focused, single-responsibility parts:
 * - useImageGeneration  — generation loop, model selection, status
 * - useImageSuggestions — AI prompt suggestions (brief + context)
 * - useImageAssets      — file uploads + asset pipeline + localStorage
 * - ImageSidebar        — all sidebar JSX (pure / presentational)
 * - ImageResultsGrid    — version tabs + per-model image grid (pure / presentational)
 *
 * This file owns only:
 * - Context state (contextData, sessionReferenceImages, productBrief)
 * - Detail view state (selectedAsset, isDetailViewOpen)
 * - Composition / wiring of the above parts
 */

import { useState, useCallback, useEffect } from 'react';
import { Image as ImageIcon, Play, RefreshCw, Download, Trash2, FolderOpen, FolderPlus, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { AssetDetailView } from '@/components/AssetDetailView';
import type { Asset } from '@/components/AssetDetailView';
import { toast } from 'sonner';
import { createEmptyContextData } from '@/components/shared/ContextPanel';
import type { ContextData } from '@/components/shared/ContextPanel';
import type { SessionReferenceImage } from '@shared/referenceImageIntents';
import type { BrandAsset } from '@shared/brandTypes';
import type { GeneratedAsset } from '@/types';
import { useApp } from '@/contexts/AppContext';
import { trpc } from '@/lib/trpc';

// Hooks
import { useImageGeneration } from './hooks/useImageGeneration';
import { useImageSuggestions } from './hooks/useImageSuggestions';
import { useImageAssets } from './hooks/useImageAssets';

// Components
import { ImageSidebar } from './components/ImageSidebar';
import { ImageResultsGrid } from './components/ImageResultsGrid';
import { BulkActionBar } from '@/components/shared/BulkActionBar';

// ============================================================================
// Component
// ============================================================================

export function ImageModule() {
  const { navigateToVideoWithImage } = useApp();

  // ── Shared state ──
  const [contextData, setContextData] = useState<ContextData>(createEmptyContextData);
  const [sessionReferenceImages, setSessionReferenceImages] = useState<SessionReferenceImage[]>([]);
  const [productBrief, setProductBrief] = useState('');

  // ── Detail view ──
  const [selectedAsset, setSelectedAsset] = useState<GeneratedAsset | null>(null);
  const [isDetailViewOpen, setIsDetailViewOpen] = useState(false);

  // ── Hooks ──
  const assetHook = useImageAssets();

  const genHook = useImageGeneration({
    contextData,
    sessionReferenceImages,
    assetPipelinePayload: assetHook.assetPipelinePayload,
  });

  const sugHook = useImageSuggestions();

  // ── Restore assets from localStorage on mount ──
  useEffect(() => {
    const { assets, versions } = assetHook.loadPersistedAssets();
    if (assets.length > 0) genHook.setAssets(assets);
    if (versions.length > 0) {
      genHook.setAdVersions(versions);
      genHook.setActiveTab(versions[0]?.id ?? null);
    }
    // Only run once on mount — intentionally omitting dependencies
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // ── Persist assets + versions to localStorage whenever they change ──
  useEffect(() => {
    assetHook.persistAssets(genHook.assets);
  }, [genHook.assets]);  // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    assetHook.persistVersions(genHook.adVersions);
  }, [genHook.adVersions]);  // eslint-disable-line react-hooks/exhaustive-deps

  // ── Detail view handlers ──
  const handleOpenDetailView = useCallback((asset: GeneratedAsset) => {
    if (asset.url && asset.status === 'complete') {
      setSelectedAsset(asset);
      setIsDetailViewOpen(true);
    }
  }, []);

  const handleCloseDetailView = useCallback(() => {
    setIsDetailViewOpen(false);
    setSelectedAsset(null);
  }, []);

  const handleNavigateToVideo = useCallback((prompt: string, assetUrl: string) => {
    navigateToVideoWithImage(prompt, assetUrl);
    toast.info('Opening Video module with your image as starting frame...');
  }, [navigateToVideoWithImage]);

  // ── Convert GeneratedAsset → Asset shape for AssetDetailView ──
  const convertToAsset = useCallback((asset: GeneratedAsset): Asset | null => {
    if (!asset.url) return null;
    return {
      // Use real DB ID from backend — parseInt works because useImageGeneration
      // now stores the DB autoincrement ID as a string (e.g. "42").
      // Fallback to 0 (not Date.now()) to avoid sending fake IDs to backend.
      id: parseInt(asset.id) || 0,
      userId: 0,
      type: 'image',
      url: asset.url,
      thumbnailUrl: asset.thumbnailUrl ?? null,
      prompt: asset.prompt,
      model: asset.modelName,
      projectId: asset.projectId ? parseInt(asset.projectId) : null,
      metadata: asset.metadata ? JSON.stringify(asset.metadata) : null,
      createdAt: asset.createdAt,
      updatedAt: asset.createdAt,
    };
  }, []);

  // ── Model info helper for the results grid ──
  const getModelInfo = useCallback(
    (modelId: string) =>
      genHook.displayModels.find((m) => m.id === modelId) ?? { id: modelId, name: modelId, provider: 'unknown' },
    [genHook.displayModels],
  );

  // ── Brand logo URL for AssetDetailView ──
  const brandLogoUrl = (() => {
    const assets = ((contextData.brand as any)?.assets as BrandAsset[] | null) ?? [];
    return assets.length > 0 ? assets[0].url : null;
  })();

  // ── Launch button state ──
  const canLaunch = !genHook.status.isGenerating && !!productBrief.trim() && genHook.selectedModels.length > 0;

  // ============================================================================
  // Render
  // ============================================================================

  return (
    <div className="h-full flex flex-col bg-background animate-fade-in">
      {/* Header */}
      <header className="shrink-0 border-b border-border px-6 py-3 flex items-center justify-between bg-background/95 backdrop-blur-sm">
        <div className="flex items-center gap-3">
          <div className="w-8 h-8 bg-primary rounded-lg flex items-center justify-center">
            <ImageIcon className="w-4 h-4 text-primary-foreground" />
          </div>
          <div>
            <h1 className="text-sm font-semibold">Image Generation</h1>
            <p className="text-xs text-muted-foreground">Multi-engine production suite</p>
          </div>
        </div>

        <div className="flex items-center gap-3">
          {genHook.status.isGenerating && (
            <div className="flex items-center gap-3 bg-muted/50 border border-border px-4 py-1.5 rounded-full">
              <RefreshCw className="w-3 h-3 text-primary animate-spin" />
              <span className="text-xs font-medium text-muted-foreground truncate max-w-[200px]">
                {genHook.status.message}
              </span>
              <div className="w-20 h-1.5 bg-muted rounded-full overflow-hidden">
                <div
                  className="h-full bg-primary transition-all duration-500 ease-out"
                  style={{ width: `${genHook.status.progress}%` }}
                />
              </div>
            </div>
          )}

          <Button
            onClick={() => genHook.handleGenerate(productBrief)}
            disabled={!canLaunch}
            className="gap-2"
          >
            <Play className="w-4 h-4" />
            Launch Production
          </Button>
        </div>
      </header>

      {/* Main content: Sidebar + Results */}
      <div className="flex-1 flex overflow-hidden">
        <ImageSidebar
          contextData={contextData}
          onContextChange={setContextData}
          sessionReferenceImages={sessionReferenceImages}
          onSessionReferenceImagesChange={setSessionReferenceImages}
          productBrief={productBrief}
          onProductBriefChange={setProductBrief}
          gen={{
            selectedModels: genHook.selectedModels,
            numVersions: genHook.numVersions,
            setNumVersions: genHook.setNumVersions,
            variationsPerModel: genHook.variationsPerModel,
            setVariationsPerModel: genHook.setVariationsPerModel,
            autoOptimizeBrief: genHook.autoOptimizeBrief,
            setAutoOptimizeBrief: genHook.setAutoOptimizeBrief,
            expandedTiers: genHook.expandedTiers,
            toggleModel: genHook.toggleModel,
            toggleTier: genHook.toggleTier,
            tierLabels: genHook.tierLabels,
            displayModels: genHook.displayModels,
            modelsByTier: genHook.modelsByTier,
          }}
          sug={{
            suggestions: sugHook.suggestions,
            contextSuggestions: sugHook.contextSuggestions,
            suggestionCount: sugHook.suggestionCount,
            setSuggestionCount: sugHook.setSuggestionCount,
            detailLevel: sugHook.detailLevel,
            setDetailLevel: sugHook.setDetailLevel,
            isLoadingSuggestions: sugHook.isLoadingSuggestions,
            isLoadingContextSuggestions: sugHook.isLoadingContextSuggestions,
            handleGenerateSuggestions: sugHook.handleGenerateSuggestions,
            handleGenerateContextSuggestions: sugHook.handleGenerateContextSuggestions,
            applySuggestion: sugHook.applySuggestion,
            applyContextSuggestion: sugHook.applyContextSuggestion,
          }}
          asset={{
            logoConfig: assetHook.logoConfig,
            logoInputRef: assetHook.logoInputRef,
            subjectConfig: assetHook.subjectConfig,
            subjectInputRef: assetHook.subjectInputRef,
            certifications: assetHook.certifications,
            certInputRef: assetHook.certInputRef,
            removeCertification: assetHook.removeCertification,
            textOverlay: assetHook.textOverlay,
            setTextOverlay: assetHook.setTextOverlay,
            handleFileUpload: assetHook.handleFileUpload,
            setLogoConfig: assetHook.setLogoConfig,
            setSubjectConfig: assetHook.setSubjectConfig,
          }}
        />

        <ImageResultsGrid
          adVersions={genHook.adVersions}
          activeTab={genHook.activeTab}
          onTabChange={genHook.setActiveTab}
          selectedModels={genHook.selectedModels}
          assetsByModel={genHook.assetsByModel}
          selectedAsset={selectedAsset}
          onAssetClick={handleOpenDetailView}
          onDownload={genHook.downloadAsset}
          getModelInfo={getModelInfo}
          selectedAssetIds={genHook.selectedAssetIds}
          onToggleAssetSelection={genHook.toggleAssetSelection}
          onSelectAllForModel={genHook.selectAllForModel}
          onSelectAllForTab={genHook.selectAllForTab}
          allAssets={genHook.assets}
        />
      </div>

      {/* Asset Detail View Modal */}
      {selectedAsset?.url && (
        <AssetDetailView
          asset={convertToAsset(selectedAsset)!}
          isOpen={isDetailViewOpen}
          onClose={handleCloseDetailView}
          onNavigateToVideo={handleNavigateToVideo}
          brandLogoUrl={brandLogoUrl}
          onVariationsCreated={() => toast.success('Variations created!')}
          onAssetRefined={(refinedAsset) => {
            genHook.setAssets((prev) =>
              prev.map((a) =>
                a.id === selectedAsset?.id
                  ? { ...a, id: String(refinedAsset.id || a.id), url: refinedAsset.url, thumbnailUrl: refinedAsset.url }
                  : a,
              ),
            );
            if (selectedAsset) {
              setSelectedAsset({ 
                ...selectedAsset, 
                id: refinedAsset.id ? String(refinedAsset.id) : selectedAsset.id,
                url: refinedAsset.url, 
                thumbnailUrl: refinedAsset.url 
              });
            }
            toast.success('Image edited successfully!');
          }}
        />
      )}

      {/* Bulk Action Bar — slides in from bottom when assets are selected */}
      <BulkActionBar count={genHook.selectedAssets.length} onClear={genHook.clearAssetSelection}>
        <BulkActionBar.Action icon={Download} label="Download" onClick={genHook.bulkDownload} />
        <BulkSaveToProject
          selectedAssets={genHook.selectedAssets}
          onComplete={genHook.clearAssetSelection}
        />
        <BulkActionBar.Action icon={Trash2} label="Remove" onClick={genHook.bulkRemove} variant="destructive" />
      </BulkActionBar>
    </div>
  );
}

// ============================================================================
// BulkSaveToProject — Popover-based project picker for bulk saving
// Reuses existing tRPC endpoints: assets.saveToProject, getProjects, createProject
// ============================================================================

interface BulkSaveToProjectProps {
  selectedAssets: GeneratedAsset[];
  onComplete: () => void;
}

function BulkSaveToProject({ selectedAssets, onComplete }: BulkSaveToProjectProps) {
  const [open, setOpen] = useState(false);
  const [selectedProjectId, setSelectedProjectId] = useState<string>('');
  const [showNew, setShowNew] = useState(false);
  const [newName, setNewName] = useState('');
  const [isSaving, setIsSaving] = useState(false);

  // Fetch projects list
  const projectsQuery = trpc.assets.getProjects.useQuery(undefined, { enabled: open });
  const projects = (projectsQuery.data as Array<{ id: number; name: string }>) ?? [];

  const saveToProjectMutation = trpc.assets.saveToProject.useMutation();
  const createProjectMutation = trpc.assets.createProject.useMutation();

  // Save all selected assets to the chosen project (sequential to avoid race conditions)
  const handleSave = useCallback(async () => {
    if (!selectedProjectId) return;
    const projectId = parseInt(selectedProjectId);
    const validAssets = selectedAssets.filter((a) => a.status === 'complete' && parseInt(a.id));

    if (validAssets.length === 0) {
      toast.error('No saved assets to assign. Try regenerating.');
      return;
    }

    setIsSaving(true);
    let saved = 0;
    for (const asset of validAssets) {
      try {
        await saveToProjectMutation.mutateAsync({
          assetId: parseInt(asset.id),
          projectId,
        });
        saved++;
      } catch (err) {
        console.warn(`Failed to save asset ${asset.id}:`, err);
      }
    }

    setIsSaving(false);
    setOpen(false);
    setSelectedProjectId('');

    if (saved > 0) {
      const projName = projects.find((p) => p.id === projectId)?.name ?? 'project';
      toast.success(`Saved ${saved} images to "${projName}"`);
      onComplete();
    } else {
      toast.error('Failed to save any images');
    }
  }, [selectedProjectId, selectedAssets, saveToProjectMutation, projects, onComplete]);

  // Create new project then auto-select it
  const handleCreate = useCallback(async () => {
    if (!newName.trim()) return;
    setIsSaving(true);
    try {
      const result = await createProjectMutation.mutateAsync({ name: newName, type: 'image' });
      await projectsQuery.refetch();
      setSelectedProjectId(String(result.id));
      setNewName('');
      setShowNew(false);
      toast.success(`Created project "${result.name}"`);
    } catch (err) {
      toast.error('Failed to create project');
    } finally {
      setIsSaving(false);
    }
  }, [newName, createProjectMutation, projectsQuery]);

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button size="sm" variant="secondary" className="gap-1.5 text-xs h-8">
          <FolderOpen className="w-3.5 h-3.5" />
          Save to Project
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-[260px] p-3" side="top" align="center">
        <div className="space-y-2">
          <p className="text-xs font-medium text-muted-foreground">
            Save {selectedAssets.length} image{selectedAssets.length !== 1 ? 's' : ''} to project
          </p>

          {showNew ? (
            <div className="space-y-2">
              <Input
                placeholder="Project name..."
                value={newName}
                onChange={(e) => setNewName(e.target.value)}
                disabled={isSaving}
                className="text-sm h-8"
                onKeyDown={(e) => e.key === 'Enter' && handleCreate()}
              />
              <div className="flex gap-2">
                <Button size="sm" onClick={handleCreate} disabled={!newName.trim() || isSaving} className="flex-1 h-7 text-xs">
                  {isSaving ? <Loader2 className="w-3 h-3 animate-spin" /> : <FolderPlus className="w-3 h-3" />}
                  Create
                </Button>
                <Button size="sm" variant="ghost" onClick={() => setShowNew(false)} disabled={isSaving} className="h-7 text-xs">
                  Cancel
                </Button>
              </div>
            </div>
          ) : (
            <div className="space-y-2">
              <div className="flex gap-2">
                <Select value={selectedProjectId} onValueChange={setSelectedProjectId} disabled={isSaving}>
                  <SelectTrigger className="flex-1 text-xs h-8">
                    <SelectValue placeholder="Select project..." />
                  </SelectTrigger>
                  <SelectContent>
                    {projects.length === 0 ? (
                      <SelectItem value="no-projects" disabled>No projects yet</SelectItem>
                    ) : (
                      projects.map((p) => (
                        <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>
                      ))
                    )}
                  </SelectContent>
                </Select>
                <Button
                  size="icon"
                  variant="outline"
                  onClick={() => setShowNew(true)}
                  disabled={isSaving}
                  title="New Project"
                  className="h-8 w-8 shrink-0"
                >
                  <FolderPlus className="w-3.5 h-3.5" />
                </Button>
              </div>
              <Button
                size="sm"
                onClick={handleSave}
                disabled={!selectedProjectId || isSaving}
                className="w-full h-7 text-xs gap-1"
              >
                {isSaving ? <Loader2 className="w-3 h-3 animate-spin" /> : <FolderOpen className="w-3 h-3" />}
                Save
              </Button>
            </div>
          )}
        </div>
      </PopoverContent>
    </Popover>
  );
}
