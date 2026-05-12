/**
 * ASSET DETAIL VIEW
 * 
 * Tab orchestrator for the asset detail modal.
 * Owns: activeTab state, variationResults state.
 * Renders: TabBar, DetailTab, VariationsTab.
 */

import { useState, useCallback, useRef } from "react";
import {
  Dialog,
  DialogContent,
  DialogTitle,
} from "@/components/ui/dialog";
import { VisuallyHidden } from "@radix-ui/react-visually-hidden";
import { TabBar } from "./TabBar";
import { DetailTab } from "./DetailTab";
import { VariationsTab } from "./VariationsTab";
import type { AssetDetailViewProps, DetailViewTab, VariationResult, EditHistoryItem } from "./types";

export function AssetDetailView({
  asset,
  isOpen,
  onClose,
  onVariationsCreated,
  onAssetRefined,
  onNavigateToVideo,
  brandLogoUrl,
}: AssetDetailViewProps) {
  const [activeTab, setActiveTab] = useState<DetailViewTab>("detail");
  const [variationResults, setVariationResults] = useState<VariationResult[]>([]);
  // Edit history — accumulates each successful refine result for the grid view
  const [editHistory, setEditHistory] = useState<EditHistoryItem[]>([]);
  // Capture original URL at mount — never mutated, even when single-image view updates
  const originalUrlRef = useRef(asset.url);
  const originalModelRef = useRef(asset.model);

  // Append new batch of results (called when generation starts)
  const handleVariationResults = useCallback((newResults: VariationResult[]) => {
    setVariationResults(prev => [...prev, ...newResults]);
  }, []);

  // Update a single result (called as each generation completes)
  const handleUpdateVariationResult = useCallback((id: string, update: Partial<VariationResult>) => {
    setVariationResults(prev =>
      prev.map(r => r.id === id ? { ...r, ...update } : r)
    );
  }, []);

  // Switch to variations tab
  const handleSwitchToVariations = useCallback(() => {
    setActiveTab("variations");
  }, []);

  /**
   * Called after a successful single-model edit.
   * Appends the result to editHistory (grid populates in background).
   * User stays on detail tab — can switch to grid manually via tab bar.
   */
  const handleEditComplete = useCallback((editedUrl: string, modelName: string) => {
    setEditHistory(prev => [...prev, {
      id: crypto.randomUUID(),
      url: editedUrl,
      modelName,
      createdAt: new Date(),
    }]);
  }, []);

  // "Use This" from VariationsTab — update single-image view with selected image
  const handleUseVariation = useCallback((url: string, _model: string) => {
    if (onAssetRefined) {
      onAssetRefined({
        ...asset,
        url,
      });
    }
    setActiveTab("detail");
  }, [asset, onAssetRefined]);

  const successCount = variationResults.filter(r => r.status === "success").length + editHistory.length;

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <DialogContent
        className="max-w-[70vw] w-auto h-[85vh] max-h-[85vh] p-0 gap-0 overflow-hidden bg-neutral-950 border-neutral-800 rounded-2xl flex flex-col"
        style={{ maxWidth: '1100px', minWidth: '700px' }}
      >
        <VisuallyHidden><DialogTitle>Asset Detail</DialogTitle></VisuallyHidden>
        {/* Tab Bar — pill-shaped icons, centered */}
        <div className="bg-white border-b border-neutral-200">
          <TabBar
            activeTab={activeTab}
            onTabChange={setActiveTab}
            variationCount={successCount}
          />
        </div>

        {/* Tab Content — relative container is crucial for the absolute bounds of the sidebar scroll */}
        {activeTab === "detail" ? (
          <div className="flex-1 min-h-0 relative">
          <DetailTab
            asset={asset}
            onClose={onClose}
            onVariationsCreated={onVariationsCreated}
            onAssetRefined={onAssetRefined}
            onNavigateToVideo={onNavigateToVideo}
            onVariationResults={handleVariationResults}
            onUpdateVariationResult={handleUpdateVariationResult}
            onSwitchToVariations={handleSwitchToVariations}
            onEditComplete={handleEditComplete}
            brandLogoUrl={brandLogoUrl}
          />
          </div>
        ) : (
          <div className="flex-1 min-h-0 overflow-y-auto">
          <VariationsTab
            results={variationResults}
            originalUrl={originalUrlRef.current}
            originalModel={originalModelRef.current}
            editHistory={editHistory}
            onUseVariation={handleUseVariation}
          />
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}

// Re-export types for convenience
export type { Asset, AssetDetailViewProps } from "./types";
