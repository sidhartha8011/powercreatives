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
import { Image as ImageIcon, Play, RefreshCw, Download, Trash2, Share2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { AssetDetailView } from '@/components/AssetDetailView';
import type { Asset } from '@/components/AssetDetailView';
import { toast } from 'sonner';
import { createEmptyContextData } from '@/components/shared/ContextPanel';
import type { ContextData, ScrapedBusinessData } from '@/components/shared/ContextPanel';
import type { SessionReferenceImage } from '@shared/referenceImageIntents';
import { mapBrandToFormValues, mapScrapedToFormValues } from '@shared/brandTypes';
import type { BrandAsset } from '@shared/brandTypes';
import { getBrandLogo } from '@shared/brandAssetResolver';
import type { GeneratedAsset } from '@/types';
import { useApp } from '@/contexts/AppContext';

// Hooks
import { useImageGeneration } from './hooks/useImageGeneration';
import { useImageSuggestions } from './hooks/useImageSuggestions';
import { useImageAssets } from './hooks/useImageAssets';

// Components
import { ImageSidebar } from './components/ImageSidebar';
import { ImageResultsGrid } from './components/ImageResultsGrid';
import { BulkSaveToProject } from './components/BulkSaveToProject';
import { BulkActionBar } from '@/components/shared/BulkActionBar';
import { SendToApprovalSetDialog } from '@/components/shared/SendToApprovalSetDialog';

// ============================================================================
// Component
// ============================================================================

export function ImageModule() {
  const { navigateToVideoWithImage } = useApp();

  // ── Shared state ──
  const [contextData, setContextData] = useState<ContextData>(createEmptyContextData);
  const [sessionReferenceImages, setSessionReferenceImages] = useState<SessionReferenceImage[]>([]);
  const [productBrief, setProductBrief] = useState('');

  // ── Form state for EnhancedBrandSection ──
  const [formValues, setFormValues] = useState<Record<string, string | number | undefined>>({});
  const handleFormChange = useCallback((fieldId: string, value: string | number) => {
    setFormValues((prev) => ({ ...prev, [fieldId]: value }));
  }, []);

  // ── Detail view ──
  const [selectedAsset, setSelectedAsset] = useState<GeneratedAsset | null>(null);
  const [isDetailViewOpen, setIsDetailViewOpen] = useState(false);

  // ── Send to Approval Set ──
  const [isApprovalDialogOpen, setIsApprovalDialogOpen] = useState(false);

  // ── Hooks ──
  const assetHook = useImageAssets();

  const genHook = useImageGeneration({
    contextData,
    formValues,
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

  // ── Auto-populate logo from brand assets when brand is selected ──
  // When a brand is chosen and it has assets, automatically set the first
  // asset as the logo. Clears logo when brand is deselected.
  useEffect(() => {
    const assets = ((contextData.brand as any)?.assets as BrandAsset[] | null) ?? [];
    if (assets.length > 0 && !assetHook.logoConfig.base64) {
      // Use resolver to find the logo asset by role (Brand Asset Role Contract)
      const logoAsset = getBrandLogo(contextData.brand as any);
      const logoUrl = logoAsset?.url ?? null;
      if (logoUrl) {
        fetch(logoUrl)
        .then((res) => res.blob())
        .then((blob) => {
          const reader = new FileReader();
          reader.onloadend = () => {
            if (typeof reader.result === 'string') {
              assetHook.setLogoConfig((prev) => ({ ...prev, base64: reader.result as string, isActive: true }));
            }
          };
          reader.readAsDataURL(blob);
        })
        .catch(() => { /* Non-fatal — user can still pick manually */ });
      }
    } else if (!contextData.brand) {
      // Brand deselected → clear logo
      assetHook.clearLogo();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [contextData.brand]);

  // ── Sync ContextPanel changes to Image form fields (brand → form mapping) ──
  const handleContextChange = useCallback((newCtx: ContextData) => {
    const prevCtx = contextData;
    setContextData(newCtx);

    // Brand changed or updated → map brand fields to form values
    const brandChanged = newCtx.brandId !== prevCtx.brandId;
    const brandDataUpdated = newCtx.brand !== prevCtx.brand;
    if ((brandChanged || brandDataUpdated) && newCtx.brand) {
      const mapped = mapBrandToFormValues(newCtx.brand as Record<string, any>);
      if (Object.keys(mapped).length > 0) {
        setFormValues((prev) => ({ ...prev, ...mapped }));
      }
    }
  }, [contextData]);

  // ── Sync URL fetch results to Image form fields ──
  const handleUrlFetched = useCallback((scraped: ScrapedBusinessData) => {
    const mapped = mapScrapedToFormValues(scraped);
    if (Object.keys(mapped).length > 0) {
      setFormValues((prev) => ({ ...prev, ...mapped }));
    }
  }, []);

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
    const logo = getBrandLogo(contextData.brand as any);
    return logo?.url ?? null;
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
          onContextChange={handleContextChange}
          onUrlFetched={handleUrlFetched}
          sessionReferenceImages={sessionReferenceImages}
          onSessionReferenceImagesChange={setSessionReferenceImages}
          productBrief={productBrief}
          onProductBriefChange={setProductBrief}
          formValues={formValues}
          onFormChange={handleFormChange}
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
            requiresImageInput: genHook.requiresImageInput,
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
        <BulkActionBar.Action icon={Share2} label="Send to Approval Set" onClick={() => setIsApprovalDialogOpen(true)} />
        <BulkActionBar.Action icon={Trash2} label="Remove" onClick={genHook.bulkRemove} variant="destructive" />
      </BulkActionBar>

      {/* Send to Approval Set — packages selected images into a client board.
          Mounted only while open so the media mapping isn't recomputed per render. */}
      {isApprovalDialogOpen && (
      <SendToApprovalSetDialog
        isOpen={isApprovalDialogOpen}
        onClose={() => setIsApprovalDialogOpen(false)}
        media={genHook.selectedAssets.map((asset) => ({
          id: String(asset.id),
          type: 'image',
          url: asset.url || '',
          prompt: asset.prompt,
          provider: genHook.displayModels.find((m) => m.id === asset.modelId)?.provider ?? '',
          modelId: asset.modelId,
        }))}
        defaultName={`Image Set — ${(contextData.brand as any)?.name || 'Draft'} (${new Date().toLocaleDateString(undefined, { month: 'short', day: 'numeric' })})`}
        brandId={contextData.brandId}
        projectId={(contextData.brand as any)?.projectId}
        brandName={(contextData.brand as any)?.name}
        brandLogoUrl={getBrandLogo(contextData.brand as any)?.url}
        brandClientEmail={(contextData.brand as any)?.clientEmail}
        itemSummary={`${genHook.selectedAssets.length} ${genHook.selectedAssets.length === 1 ? 'image' : 'images'}`}
      />
      )}
    </div>
  );
}
