/**
 * VARIATIONS PANEL
 * 
 * Sidebar panel for configuring and triggering image-based variations.
 * Results are pushed up to the parent (index.tsx) and displayed in VariationsTab.
 * 
 * Approach: IMAGE-BASED variations
 * - Original image is PRIMARY input (passed as originalImageUrl)
 * - Prompt is OPTIONAL guidance text
 *
 * API key resolution is handled by the backend routing layer.
 * No frontend API key lookup is needed.
 */

import { useState } from "react";
import { Textarea } from "@/components/ui/textarea";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { Button } from "@/components/ui/button";
import { Copy, ChevronDown, ChevronUp, Minus, Plus, MessageSquare } from "lucide-react";
import { SectionPanel } from "./SectionPanel";
import { ActionButton } from "./ActionButton";
import { useImageModelsForGeneration } from "@/hooks/useModelsForGeneration";
import { trpc } from "@/lib/trpc";
import { toast } from "sonner";
import type { Asset, VariationResult } from "./types";

interface VariationsPanelProps {
  asset: Asset;
  isLoading?: boolean;
  onVariationCreated?: (url: string, model: string) => void;
  onVariationResults: (results: VariationResult[]) => void;
  onUpdateVariationResult: (id: string, update: Partial<VariationResult>) => void;
  onSwitchToVariations: () => void;
}

export function VariationsPanel({
  asset,
  isLoading: externalLoading,
  onVariationCreated,
  onVariationResults,
  onUpdateVariationResult,
  onSwitchToVariations,
}: VariationsPanelProps) {
  const [isExpanded, setIsExpanded] = useState(false);
  const [showPromptField, setShowPromptField] = useState(false);
  const [prompt, setPrompt] = useState(asset.prompt || "");
  const [count, setCount] = useState(3);
  const [selectedModelIds, setSelectedModelIds] = useState<string[]>([]);
  const [isGenerating, setIsGenerating] = useState(false);

  const { imageModels: genModelsList, isLoading: isLoadingModels } = useImageModelsForGeneration();
  const genModels = genModelsList.map((m: { id: string; name: string; provider: string }) => ({ modelId: m.id, displayName: m.name, provider: m.provider }));
  const generateImageMutation = trpc.image.generate.useMutation();

  const handleToggleModel = (modelId: string) => {
    setSelectedModelIds(prev =>
      prev.includes(modelId) ? prev.filter(id => id !== modelId) : [...prev, modelId]
    );
  };

  const handleToggleAll = () => {
    setSelectedModelIds(
      selectedModelIds.length === genModels.length ? [] : genModels.map(m => m.modelId)
    );
  };

  const incrementCount = () => setCount(c => Math.min(c + 1, 10));
  const decrementCount = () => setCount(c => Math.max(c - 1, 1));

  const handleGenerate = async () => {
    if (selectedModelIds.length === 0) {
      toast.error("Please select at least one model");
      return;
    }
    if (!asset.url) {
      toast.error("No source image available for variations");
      return;
    }

    setIsGenerating(true);

    // Resolve prompt — user guidance takes priority, then original asset prompt, then descriptive fallback
    const resolvedPrompt = prompt.trim() || asset.prompt || "Create a visually related variation of this image";

    // Create placeholders and push to parent
    const placeholders: VariationResult[] = [];
    for (const modelId of selectedModelIds) {
      const model = genModels.find(m => m.modelId === modelId);
      for (let i = 0; i < count; i++) {
        placeholders.push({
          id: `${modelId}-${i}-${Date.now()}`,
          modelId,
          modelName: model?.displayName || modelId,
          provider: model?.provider || "",
          status: "loading",
        });
      }
    }

    onVariationResults(placeholders);
    onSwitchToVariations();

    // Generate in parallel, updating parent as each completes
    // Provider is resolved from model registry — same pattern as useAssetActions.ts
    const promises = placeholders.map(async (placeholder) => {
      try {
        // Resolve provider from the model list (already loaded from registry)
        const model = genModels.find(m => m.modelId === placeholder.modelId);
        const provider = model?.provider || "";

        if (!provider) {
          throw new Error(`No provider found for model ${placeholder.modelId}. Check the model registry.`);
        }

        const generated = await generateImageMutation.mutateAsync({
          prompt: resolvedPrompt,
          model: placeholder.modelId,
          provider,
          originalImageUrl: asset.url,
        });

        onUpdateVariationResult(placeholder.id, {
          status: "success",
          imageUrl: generated.url,
        });

        if (onVariationCreated && generated.url) {
          onVariationCreated(generated.url, placeholder.modelId);
        }
      } catch (error) {
        onUpdateVariationResult(placeholder.id, {
          status: "error",
          error: error instanceof Error ? error.message : "Failed",
        });
      }
    });

    await Promise.allSettled(promises);
    setIsGenerating(false);
    toast.success("Variations complete!");
  };

  const selectedCount = selectedModelIds.length;
  const hasModels = genModels.length > 0;
  const totalVariations = selectedCount * count;

  return (
    <SectionPanel>
      <button
        type="button"
        onClick={() => setIsExpanded(!isExpanded)}
        className="flex items-center gap-2 w-full text-left"
        disabled={isGenerating}
      >
        <Copy className="h-4 w-4 text-muted-foreground" />
        <span className="text-sm font-medium flex-1">Create Variations</span>
        {isExpanded ? (
          <ChevronUp className="h-4 w-4 text-muted-foreground" />
        ) : (
          <ChevronDown className="h-4 w-4 text-muted-foreground" />
        )}
      </button>

      {isExpanded && (
        <div className="mt-3 space-y-3">
          {/* Source image indicator */}
          <div className="flex items-center gap-2 p-2 rounded-md bg-muted/50 border border-border/50">
            {asset.url && (
              <img src={asset.url} alt="Source" className="w-8 h-8 rounded object-cover flex-shrink-0" />
            )}
            <div className="flex-1 min-w-0">
              <p className="text-xs font-medium text-foreground">Based on this image</p>
              <p className="text-[10px] text-muted-foreground truncate">
                Variations will be visually related to the original
              </p>
            </div>
          </div>

          {/* Optional prompt */}
          <div>
            <button
              type="button"
              onClick={() => setShowPromptField(!showPromptField)}
              className="flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground transition-colors"
              disabled={isGenerating}
            >
              <MessageSquare className="h-3 w-3" />
              <span>{showPromptField ? "Hide" : "Add"} prompt guidance</span>
              <span className="text-[10px] opacity-60">(optional)</span>
            </button>
            {showPromptField && (
              <Textarea
                value={prompt}
                onChange={(e) => setPrompt(e.target.value)}
                className="min-h-[50px] resize-none text-sm mt-1.5"
                disabled={isGenerating}
                placeholder="Optional: guide the variation style, mood, or changes..."
              />
            )}
          </div>

          {/* Count selector */}
          <div className="flex items-center justify-between">
            <Label className="text-xs text-muted-foreground">Variations per model</Label>
            <div className="flex items-center gap-2">
              <Button variant="outline" size="icon" className="h-7 w-7" onClick={decrementCount} disabled={count <= 1 || isGenerating}>
                <Minus className="h-3 w-3" />
              </Button>
              <span className="text-sm font-medium w-6 text-center">{count}</span>
              <Button variant="outline" size="icon" className="h-7 w-7" onClick={incrementCount} disabled={count >= 10 || isGenerating}>
                <Plus className="h-3 w-3" />
              </Button>
            </div>
          </div>

          {/* Model selection */}
          <div>
            <Label className="text-xs text-muted-foreground mb-2 block">
              Models {selectedCount > 0 && `(${selectedCount} selected)`}
            </Label>

            {isLoadingModels ? (
              <p className="text-xs text-muted-foreground">Loading models...</p>
            ) : !hasModels ? (
              <p className="text-xs text-muted-foreground">
                No image generation models available. Add an integration first.
              </p>
            ) : (
              <div className="space-y-1.5">
                <div className="flex items-center gap-2 pb-2 border-b">
                  <Checkbox
                    id="var-select-all"
                    checked={selectedCount === genModels.length && genModels.length > 0}
                    onCheckedChange={handleToggleAll}
                    disabled={isGenerating}
                  />
                  <Label htmlFor="var-select-all" className="text-xs cursor-pointer">Select all</Label>
                </div>
                <div className="space-y-1.5 max-h-[120px] overflow-y-auto">
                  {genModels.map((model) => (
                    <div key={model.modelId} className="flex items-center gap-2">
                      <Checkbox
                        id={`var-model-${model.modelId}`}
                        checked={selectedModelIds.includes(model.modelId)}
                        onCheckedChange={() => handleToggleModel(model.modelId)}
                        disabled={isGenerating}
                      />
                      <Label htmlFor={`var-model-${model.modelId}`} className="text-xs cursor-pointer flex-1 truncate">
                        {model.displayName}
                      </Label>
                      <span className="text-[10px] text-muted-foreground">{model.provider}</span>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>

          {/* Summary */}
          {selectedCount > 0 && (
            <p className="text-xs text-muted-foreground">
              Will generate {totalVariations} variation{totalVariations > 1 ? "s" : ""} ({count} × {selectedCount} model{selectedCount > 1 ? "s" : ""})
            </p>
          )}

          <ActionButton
            onClick={handleGenerate}
            disabled={selectedCount === 0 || isGenerating || !asset.url}
            loading={isGenerating}
            icon={<Copy className="h-4 w-4" />}
          >
            {isGenerating
              ? "Generating..."
              : `Generate ${totalVariations > 0 ? totalVariations : ""} Variation${totalVariations !== 1 ? "s" : ""}`}
          </ActionButton>
        </div>
      )}
    </SectionPanel>
  );
}
