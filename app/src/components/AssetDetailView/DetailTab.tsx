/**
 * DETAIL TAB
 * 
 * The original detail view: large preview on the left, actions sidebar on the right.
 * Extracted from index.tsx to keep the tab orchestrator clean.
 */

import { X, Video, ChevronDown, ChevronRight, FileText, Copy, Check } from "lucide-react";
import { useState } from "react";
import { useClipboard } from "@/hooks/useClipboard";
import { Button } from "@/components/ui/button";
import { AssetPreview } from "./AssetPreview";
import { RefinePanel } from "./RefinePanel";
import { ExportPanel } from "./ExportPanel";
import { SaveToProjectPanel } from "./SaveToProjectPanel";
import { VariationsPanel } from "./VariationsPanel";
import { LogoOverlayPanel } from "./LogoOverlayPanel";
import { ActionButton } from "./ActionButton";
import { EditResults } from "./EditResults";
import { useAssetActions } from "@/hooks/useAssetActions";
import type { Asset, AssetDetailViewProps, VariationResult } from "./types";

interface DetailTabProps {
  asset: Asset;
  onClose: () => void;
  onVariationsCreated?: AssetDetailViewProps["onVariationsCreated"];
  onAssetRefined?: AssetDetailViewProps["onAssetRefined"];
  onNavigateToVideo?: AssetDetailViewProps["onNavigateToVideo"];
  onVariationResults: (results: VariationResult[]) => void;
  onUpdateVariationResult: (id: string, update: Partial<VariationResult>) => void;
  onSwitchToVariations: () => void;
  /** Called after a successful single-model edit with the new URL and model name */
  onEditComplete?: (editedUrl: string, modelName: string) => void;
  brandLogoUrl?: string | null;
}

export function DetailTab({
  asset,
  onClose,
  onVariationsCreated,
  onAssetRefined,
  onNavigateToVideo,
  onVariationResults,
  onUpdateVariationResult,
  onSwitchToVariations,
  onEditComplete,
  brandLogoUrl,
}: DetailTabProps) {
  // Prompt accordion state
  const [isPromptExpanded, setIsPromptExpanded] = useState(false);

  const {
    projects,
    isLoading,
    loadingAction,
    editResults,
    isEditingMultiple,
    handleRefine,
    handleExport,
    handleSaveToProject,
    handleCreateProject,
    handleUseEditResult,
    handleDownloadEditResult,
    handleCloseEditResults,
  } = useAssetActions({
    asset,
    onVariationsCreated,
    onAssetRefined,
    onEditComplete,
    onClose,
  });

  const { copy: copyPrompt, copied: promptCopied } = useClipboard();

  /** Copy prompt text to clipboard */
  const handleCopyPrompt = () => {
    if (!asset.prompt) return;
    copyPrompt(asset.prompt);
  };

  // DetailTab uses absolute positioning to fill its relative parent exactly.
  // This breaks out of the flex parent height collapse calculation and gives 
  // the sidebar a definite bounding box, enabling overflow-auto scrolling.
  return (
    <div className="absolute inset-0 flex">
      {/* Left: Premium Preview */}
      <div className="flex-1 min-w-0 relative bg-neutral-950">
        <Button
          variant="ghost"
          size="icon"
          onClick={onClose}
          className="absolute top-4 right-4 z-20 h-10 w-10 rounded-full bg-black/60 hover:bg-black/80 text-white/90 hover:text-white backdrop-blur-sm border border-white/10"
        >
          <X className="h-5 w-5" />
        </Button>
        <AssetPreview asset={asset} />
      </div>

      {/* Right: Actions Sidebar */}
      <div className="w-[280px] flex-shrink-0 bg-white border-l border-neutral-200 flex flex-col min-h-0">
        {/* Prompt Accordion — always rendered if prompt exists */}
        {asset.prompt && (
          <div className="border-b border-neutral-200">
            <button
              onClick={() => setIsPromptExpanded(!isPromptExpanded)}
              className="w-full flex items-center justify-between px-5 py-3 hover:bg-neutral-50 transition-colors"
            >
              <div className="flex items-center gap-2">
                {isPromptExpanded ? (
                  <ChevronDown className="h-4 w-4 text-neutral-400" />
                ) : (
                  <ChevronRight className="h-4 w-4 text-neutral-400" />
                )}
                <FileText className="h-4 w-4 text-neutral-500" />
                <span className="text-sm font-medium text-neutral-700">Prompt</span>
              </div>
              {/* Copy button — visible even when collapsed */}
              <button
                onClick={(e) => {
                  e.stopPropagation();
                  handleCopyPrompt();
                }}
                className="p-1 rounded hover:bg-neutral-200 transition-colors"
                title="Copy prompt"
              >
                {promptCopied ? (
                  <Check className="h-3.5 w-3.5 text-green-600" />
                ) : (
                  <Copy className="h-3.5 w-3.5 text-neutral-400" />
                )}
              </button>
            </button>
            {isPromptExpanded && (
              <div className="px-5 pb-4">
                <pre className="text-xs text-neutral-600 bg-neutral-50 border border-neutral-200 rounded-md p-3 whitespace-pre-wrap break-words max-h-64 overflow-y-auto font-mono leading-relaxed">
                  {asset.prompt}
                </pre>
              </div>
            )}
          </div>
        )}

        <div className="px-5 py-4 border-b border-neutral-200">
          <h3 className="text-base font-semibold text-neutral-900">Actions</h3>
        </div>

        <div className="flex-1 overflow-auto p-5 space-y-5">
          {/* Edit Results */}
          {isEditingMultiple && editResults.length > 0 && (
            <>
              <EditResults
                results={editResults}
                onDownload={handleDownloadEditResult}
                onUse={handleUseEditResult}
                onClose={handleCloseEditResults}
              />
              <div className="border-t border-neutral-200" />
            </>
          )}

          {/* Create Variations */}
          <VariationsPanel
            asset={asset}
            isLoading={loadingAction === "variations"}
            onVariationResults={onVariationResults}
            onUpdateVariationResult={onUpdateVariationResult}
            onSwitchToVariations={onSwitchToVariations}
            onVariationCreated={(url, model) => {
              if (onVariationsCreated) {
                onVariationsCreated([{
                  ...asset,
                  id: Date.now(),
                  url,
                  model,
                  createdAt: new Date(),
                  updatedAt: new Date(),
                }]);
              }
            }}
          />

          {/* Add Logo */}
          {asset.type === "image" && (
            <LogoOverlayPanel
              asset={asset}
              brandLogoUrl={brandLogoUrl}
              onAssetRefined={onAssetRefined}
            />
          )}

          {/* Make Into Video */}
          {asset.type === "image" && onNavigateToVideo && (
            <ActionButton
              onClick={() => onNavigateToVideo(asset.prompt, asset.url)}
              disabled={isLoading}
              variant="secondary"
              icon={<Video className="h-4 w-4" />}
            >
              Make Into Video
            </ActionButton>
          )}

          <div className="border-t border-neutral-200" />

          {/* Refine */}
          <RefinePanel
            asset={asset}
            isLoading={loadingAction === "refine"}
            onRefine={handleRefine}
          />

          <div className="border-t border-neutral-200" />

          {/* Export */}
          <ExportPanel
            asset={asset}
            isLoading={loadingAction === "export"}
            onExport={handleExport}
          />

          <div className="border-t border-neutral-200" />

          {/* Save to Project */}
          <SaveToProjectPanel
            asset={asset}
            projects={projects}
            isLoading={loadingAction === "save" || loadingAction === "createProject"}
            onSave={handleSaveToProject}
            onCreateProject={handleCreateProject}
          />
        </div>
      </div>
    </div>
  );
}
