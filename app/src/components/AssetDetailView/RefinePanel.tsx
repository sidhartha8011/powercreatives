/**
 * REFINE PANEL
 * 
 * Purpose: Text input for refining creative assets with AI
 * Features:
 * - Single-model dropdown selection (edit/I2I capable models only)
 * - Uses the existing Select UI component (Radix)
 * 
 * Used by: AssetDetailView
 */

import { useState } from "react";
import { Textarea } from "@/components/ui/textarea";
import { Wand2 } from "lucide-react";
import { SectionPanel } from "./SectionPanel";
import { ActionButton } from "./ActionButton";
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from "@/components/ui/select";
import { useImageModelsForEditing } from "@/hooks/useModelsForGeneration";
import type { RefinePanelProps } from "./types";

export function RefinePanel({ asset, isLoading, onRefine }: RefinePanelProps) {
  const [instruction, setInstruction] = useState("");
  const [selectedModelId, setSelectedModelId] = useState<string>("");

  // Get models that can edit images from unified models table
  const { editModels: editModelsList, isLoading: isLoadingModels } = useImageModelsForEditing();
  const editModels = editModelsList.map(m => ({ modelId: m.id, displayName: m.name, provider: m.provider }));

  // Auto-select first model when models load and nothing is selected
  if (!selectedModelId && editModels.length > 0) {
    setSelectedModelId(editModels[0].modelId);
  }

  /**
   * Handle refine action — wraps single model ID in array
   * for backward compatibility with the parent's onRefine(instruction, modelIds[]) interface.
   */
  const handleRefine = async () => {
    if (!instruction.trim() || !selectedModelId) return;
    await onRefine(instruction, [selectedModelId]);
    setInstruction("");
  };

  return (
    <SectionPanel title="Refine Creative">
      {/* Instruction input */}
      <Textarea
        placeholder="Describe how you want to change this creative..."
        value={instruction}
        onChange={(e) => setInstruction(e.target.value)}
        className="min-h-[70px] resize-none text-sm"
        disabled={isLoading}
      />

      {/* Model dropdown — single select, edit/I2I capable models only */}
      <div className="mt-3">
        <label className="text-xs font-medium text-muted-foreground mb-1.5 block">
          Model
        </label>
        {isLoadingModels ? (
          <p className="text-xs text-muted-foreground">Loading models...</p>
        ) : editModels.length === 0 ? (
          <p className="text-xs text-muted-foreground">
            No models with edit capability. Configure in Settings → Model Registry.
          </p>
        ) : (
          <Select value={selectedModelId} onValueChange={setSelectedModelId}>
            <SelectTrigger className="w-full text-sm">
              <SelectValue placeholder="Select model..." />
            </SelectTrigger>
            <SelectContent>
              {editModels.map((model) => (
                <SelectItem key={model.modelId} value={model.modelId}>
                  <span>{model.displayName}</span>
                  <span className="ml-2 text-[10px] text-muted-foreground">{model.provider}</span>
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        )}
      </div>

      {/* Apply button */}
      <ActionButton
        onClick={handleRefine}
        disabled={!instruction.trim() || !selectedModelId}
        loading={isLoading}
        icon={<Wand2 className="h-4 w-4" />}
        className="mt-3"
      >
        Apply Changes
      </ActionButton>
    </SectionPanel>
  );
}
